<?php

namespace App\Services\Telegram;

use App\Models\Booking;
use App\Models\BusGroup;
use App\Models\Departure;
use App\Models\DepartureHotel;
use App\Models\Payment;
use App\Models\TelegramLink;
use App\Models\TelegramUpdate;
use App\Models\Traveller;
use App\Models\User;
use App\Services\Invoices\Documents;
use App\Services\Operations\Lists;
use App\Services\Payments\Drivers\BankTransfer;
use App\Services\Payments\SlipVault;
use App\Support\AuditContext;
use App\Support\DepartureReadiness;
use App\Support\Money;
use App\Support\Rates;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

/**
 * Turns one Telegram update into one reply — batch A4 from Hijaz.
 *
 * A linked chat acts as its staff account: the account is signed in for
 * the duration of the update, so policies, permissions and the audit trail
 * see a person, not a bot, and the channel is `telegram`. An unknown chat
 * is told how to link, once a day, and nothing else. Button taps go to
 * {@see Callbacks}; a chat that owes an answer (a refusal's reason) has
 * its next message read as that answer.
 *
 * A departure has no code, so commands take its number from /departures
 * and default to the next one to leave.
 */
class Router
{
    public function __construct(
        private readonly Bot $bot,
        private readonly Linker $linker,
        private readonly DailySummary $summary,
        private readonly Callbacks $callbacks,
    ) {}

    /** @var array<string, mixed> */
    private array $currentMessage = [];

    /** @param  array<string, mixed>  $update */
    public function handle(array $update, TelegramUpdate $record): void
    {
        if (isset($update['callback_query']) && is_array($update['callback_query'])) {
            $this->handleCallback($update['callback_query'], $record);

            return;
        }

        $message = $update['message'] ?? null;
        $this->currentMessage = is_array($message) ? $message : [];

        if (! is_array($message) || ! isset($message['chat']['id'])) {
            $record->forceFill(['kind' => 'ignored', 'handled_at' => now()])->save();

            return;
        }

        $chatId = (int) $message['chat']['id'];
        $text = trim((string) ($message['text'] ?? $message['caption'] ?? ''));
        [$command, $argument] = self::parse($text);

        if (isset($message['photo']) || isset($message['document'])) {
            $command = '/upload';
            $argument = $text;
        }

        $record->forceFill(['chat_id' => $chatId, 'kind' => 'message', 'command' => $command])->save();

        if ($command === '/start' && $argument !== '') {
            $this->link($chatId, $argument, is_array($message['from'] ?? null) ? $message['from'] : []);
            $record->forceFill(['handled_at' => now()])->save();

            return;
        }

        $link = $this->linker->linkForChat($chatId);

        if ($link === null) {
            $this->replyToStranger($chatId, $record);

            return;
        }

        $link->forceFill(['last_seen_at' => now()])->save();

        $this->as($link, function () use ($command, $argument, $link, $text): void {
            if ($command === '' && $link->pending !== null) {
                $this->callbacks->resume($link, $text);

                return;
            }

            $this->dispatch($command, $argument, $link);
        });

        $record->forceFill(['handled_at' => now()])->save();
    }

    /** @param  array<string, mixed>  $callback */
    private function handleCallback(array $callback, TelegramUpdate $record): void
    {
        $chatId = (int) ($callback['message']['chat']['id'] ?? $callback['from']['id'] ?? 0);
        $data = (string) ($callback['data'] ?? '');
        $record->forceFill(['chat_id' => $chatId, 'kind' => 'callback', 'command' => mb_substr(strtok($data, ':') ?: $data, 0, 40)])->save();

        $link = $chatId > 0 ? $this->linker->linkForChat($chatId) : null;

        if ($link === null) {
            $this->bot->answerCallbackQuery((string) ($callback['id'] ?? ''), 'This chat is not linked to the staff panel.');
            $record->forceFill(['handled_at' => now()])->save();

            return;
        }

        $link->forceFill(['last_seen_at' => now()])->save();
        $this->as($link, fn () => $this->callbacks->handle($data, $link, $callback));
        $record->forceFill(['handled_at' => now()])->save();
    }

    /** Run something as the linked person, with the audit trail knowing it was by chat. */
    private function as(TelegramLink $link, Closure $work): void
    {
        AuditContext::use(AuditContext::TELEGRAM);
        Auth::setUser($link->user);

        try {
            $work();
        } finally {
            Auth::forgetUser();
            AuditContext::reset();
        }
    }

    private function dispatch(string $command, string $argument, TelegramLink $link): void
    {
        $user = $link->user;
        $chat = $link->chat_id;

        match ($command) {
            '/start', '/help' => $this->bot->sendMessage($chat, $this->help($user)),
            '/me' => $this->bot->sendMessage($chat, implode("\n", [
                '<b>'.e($user->name).'</b>',
                e($user->email),
                'Role: '.e($user->getRoleNames()->implode(', ') ?: 'none'),
                'Linked '.e($link->linked_at->diffForHumans()).'.',
            ])),
            '/today' => $this->bot->sendMessage($chat, $this->summary->for($user)),
            '/departures', '/departure' => $this->bot->sendMessage($chat, $this->departures($argument, $user)),
            '/find' => $this->bot->sendMessage($chat, $this->find($argument, $user)),
            '/balance' => $this->bot->sendMessage($chat, $this->balance($argument, $user)),
            '/slips', '/payments' => $this->slips($user, $chat),
            '/ready' => $this->bot->sendMessage($chat, $this->ready($argument, $user)),
            '/checklist' => $this->checklist($argument, $user, $chat),
            '/bus' => $this->busList($argument, $user, $chat),
            '/rooms', '/room' => $this->roomList($argument, $user, $chat),
            '/manifest', '/list' => $this->manifest($argument, $user, $chat),
            '/invoice' => $this->invoice($argument, $user, $chat),
            '/receipt' => $this->receipt($argument, $user, $chat),
            '/upload' => $this->upload($link, $argument, $this->currentMessage),
            '/unlink' => $this->unlink($link),
            default => $this->bot->sendMessage($chat, "I don't know that one yet. Send /help for what I can do."),
        };
    }

    /** @param  array<string, mixed>  $from */
    private function link(int $chatId, string $code, array $from): void
    {
        $link = $this->linker->redeem($code, $chatId, [
            'username' => isset($from['username']) ? (string) $from['username'] : null,
            'name' => trim((string) ($from['first_name'] ?? '').' '.(string) ($from['last_name'] ?? '')) ?: null,
        ]);

        if ($link === null) {
            $this->bot->sendMessage($chatId, 'That code is not valid any more. Open the staff panel, go to Telegram, and get a new one.');

            return;
        }

        $this->bot->sendMessage($chatId, 'Linked to <b>'.e($link->user->name).'</b>. '.$this->help($link->user, greeting: false));
    }

    private function unlink(TelegramLink $link): void
    {
        $link->forceFill(['revoked_at' => now()])->save();
        $this->bot->sendMessage($link->chat_id, 'Unlinked. This chat no longer acts as '.e($link->user->name).'. Link again from the staff panel whenever you like.');
    }

    private function replyToStranger(int $chatId, TelegramUpdate $record): void
    {
        // One explanation per chat per day; a stranger does not get a conversation.
        $alreadyTold = TelegramUpdate::query()
            ->where('chat_id', $chatId)
            ->where('kind', 'stranger')
            ->where('received_at', '>=', now()->subDay())
            ->exists();

        $record->forceFill(['kind' => 'stranger', 'handled_at' => now()])->save();

        if ($alreadyTold) {
            return;
        }

        $this->bot->sendMessage($chatId, 'This is the Rihla staff bot. To use it, sign in to the staff panel, open Telegram in the menu, and tap the link it gives you.');
    }

    private function help(User $user, bool $greeting = true): string
    {
        $lines = $greeting ? ['Hello, '.e($user->name).'. Here is what I can do:', ''] : [''];

        $commands = [
            ['/today', 'what is waiting for you', null],
            ['/departures', 'the departures ahead; /departure 12 for one', 'departures'],
            ['/find Aminath', 'a traveller, by name, passport or phone; or a booking by reference', 'booking.viewAny'],
            ['/balance RIH-B-2026-0001', 'what a booking owes', 'payment.viewAny'],
            ['/slips', 'payments waiting to be verified', 'payment.viewAny'],
            ['/ready 12', 'what stops a departure travelling', 'departure.board'],
            ['/checklist 12', 'open checklist items, ticked by tap', 'checklist.viewAny'],
            ['/bus 1 12', 'the bus list as PDF', 'list.view'],
            ['/rooms makkah 12', 'a rooming list as PDF; add "hotel" for the hotel copy', 'list.view'],
            ['/manifest airline 12', 'the manifest as PDF: office, airline, hotel or ministry', 'list.view'],
            ['/invoice RIH-B-2026-0001', 'the invoice as PDF', 'booking.view'],
            ['/receipt RIH-P-2026-0001', 'a receipt as PDF, to forward', 'payment.download'],
            ['a photo with "RIH-B-2026-0001 10000"', 'records a bank slip for Finance to verify', 'payment.create'],
            ['/me', 'who this chat acts as', null],
            ['/unlink', 'stop this chat acting as you', null],
        ];

        foreach ($commands as [$command, $what, $permission]) {
            $may = $permission === null || ($permission === 'departures' ? self::seesDepartures($user) : $user->can($permission));

            if ($may) {
                $lines[] = '<b>'.e($command).'</b> — '.e($what);
            }
        }

        return implode("\n", $lines);
    }

    // ── Asking ───────────────────────────────────────────────────────────

    private function departures(string $argument, User $user): string
    {
        if (! self::seesDepartures($user)) {
            return 'Your account cannot see departures.';
        }

        if (trim($argument) !== '') {
            $departure = self::departureFor($argument);

            if ($departure === null) {
                return 'No departure matches "'.e($argument).'". Send /departures for the list.';
            }

            $lines = [
                '<b>'.e(DailySummary::label($departure)).'</b>',
                e($departure->date_start->format('j M')).' – '.e($departure->date_end->format('j M Y')).' · '.e(ucfirst((string) $departure->status)),
                'Seats: '.($departure->capacity_total - $departure->seats_remaining).' of '.$departure->capacity_total.' taken',
            ];

            foreach ($departure->hotels as $hotel) {
                $lines[] = e($hotel->cityLabel()).': '.e((string) $hotel->name).($hotel->nights ? ' · '.$hotel->nights.' nights' : '');
            }

            if ($user->can('booking.viewAny')) {
                $bookings = $departure->bookings()->whereIn('status', Lists::LISTED)->get();
                $line = 'Bookings: '.$bookings->count().' live';

                if ($user->can('payment.viewAny') && $bookings->isNotEmpty()) {
                    $total = $bookings->sum(fn (Booking $booking): int => $booking->total()->minor);
                    $paid = $bookings->sum(fn (Booking $booking): int => $booking->paid()->minor);
                    $currency = (string) $bookings->first()->currency;
                    $line .= ' · '.Money::ofMinor((int) $paid, $currency)->format().' of '.Money::ofMinor((int) $total, $currency)->format().' received';
                }

                $lines[] = $line;
            }

            if ($user->can('departure.board')) {
                $blockers = array_values(array_filter(
                    DepartureReadiness::concerns($departure),
                    fn (array $concern): bool => $concern['severity'] === DepartureReadiness::BLOCKING,
                ));
                $lines[] = $blockers === []
                    ? 'Readiness: nothing blocks.'
                    : 'Readiness: ⛔ '.implode('; ', array_map(fn (array $concern): string => e($concern['headline']), $blockers)).' (/ready '.$departure->getKey().')';
            }

            if ($user->can('checklist.viewAny')) {
                $open = $departure->checklist()->open()->count();

                if ($open > 0) {
                    $lines[] = $open.' checklist item'.($open === 1 ? '' : 's').' still open (/checklist '.$departure->getKey().').';
                }
            }

            return implode("\n", $lines);
        }

        $departures = DailySummary::upcoming()->take(10);

        if ($departures->isEmpty()) {
            return 'No departure is still to leave. Add one in the staff panel under Packages.';
        }

        return implode("\n", array_merge(
            ['<b>Departures ahead</b>'],
            $departures->map(fn (Departure $departure): string => e(DailySummary::label($departure)).' · '.($departure->capacity_total - $departure->seats_remaining).'/'.$departure->capacity_total.' seats')->all(),
            ['', 'Send /departure 12 for one of them, by its number.'],
        ));
    }

    /** A traveller by name, passport number or the booker's phone; a booking by reference. */
    private function find(string $argument, User $user): string
    {
        if (! $user->can('booking.viewAny')) {
            return 'Your account cannot look people up.';
        }

        $term = trim($argument);

        if ($term === '') {
            return 'Send /find Aminath, /find P1234567 (a passport number) or /find RIH-B-2026-0001.';
        }

        if (self::looksLikeBooking($term)) {
            $booking = Booking::query()->with(['customer', 'departure.package', 'travellers.traveller'])->where('reference', self::reference($term))->first();

            return $booking === null ? 'No booking has the reference '.e(self::reference($term)).'.' : $this->bookingCard($booking, $user);
        }

        $travellers = Traveller::query()
            ->with('customer')
            ->where(fn ($query) => $query
                ->where('full_name', 'like', "%{$term}%")
                ->orWhere('passport_number', $term)
                ->orWhereHas('customer', fn ($customer) => $customer->where('phone', 'like', "%{$term}%")->orWhere('name', 'like', "%{$term}%")))
            ->limit(5)
            ->get();

        if ($travellers->isEmpty()) {
            return 'Nobody matches "'.e($term).'".';
        }

        return $travellers->map(function (Traveller $traveller) use ($user): string {
            $booking = Booking::query()
                ->with(['customer', 'departure.package'])
                ->whereIn('status', Lists::LISTED)
                ->whereHas('travellers', fn ($line) => $line->where('traveller_id', $traveller->getKey()))
                ->latest('id')
                ->first();

            return implode("\n", array_filter([
                '<b>'.e($traveller->full_name).'</b>'.($traveller->passport_number ? ' · passport '.e((string) $traveller->passport_number) : ''),
                'Booked by '.e((string) $traveller->customer->name).($traveller->customer->phone ? ' · '.e((string) $traveller->customer->phone) : ''),
                $booking === null ? 'No live booking.' : $this->bookingLine($booking, $user),
            ]));
        })->implode("\n\n");
    }

    /** What a booking owes, by reference or by who booked it. */
    private function balance(string $argument, User $user): string
    {
        if (! $user->can('payment.viewAny')) {
            return 'Your account cannot see money.';
        }

        $term = trim($argument);

        if ($term === '') {
            return 'Send /balance RIH-B-2026-0001 or /balance part of a name or phone.';
        }

        $query = Booking::query()->with(['customer', 'departure.package'])->whereIn('status', Lists::LISTED);

        if (self::looksLikeBooking($term)) {
            $query->where('reference', self::reference($term));
        } else {
            $query->whereHas('customer', fn ($customer) => $customer->where('name', 'like', "%{$term}%")->orWhere('phone', 'like', "%{$term}%"));
        }

        $bookings = $query->orderByDesc('id')->limit(5)->get();

        if ($bookings->isEmpty()) {
            return 'No live booking matches "'.e($term).'".';
        }

        return $bookings->map(fn (Booking $booking): string => $this->bookingCard($booking, $user))->implode("\n\n");
    }

    /** The payments waiting to be verified, each with its buttons. */
    private function slips(User $user, int $chat): void
    {
        if (! $user->can('payment.viewAny')) {
            $this->bot->sendMessage($chat, 'Your account cannot see payments.');

            return;
        }

        $waiting = Payment::query()->awaitingReview()->orderBy('id')->limit(10)->get();

        if ($waiting->isEmpty()) {
            $this->bot->sendMessage($chat, 'Nothing waiting. Every recorded payment has been checked.');

            return;
        }

        $this->bot->sendMessage($chat, '<b>'.$waiting->count().' payment'.($waiting->count() === 1 ? '' : 's').' to verify</b>'.($waiting->count() === 10 ? ' (first ten)' : ''));

        foreach ($waiting as $payment) {
            $buttons = $user->can('payment.reconcile')
                ? Bot::keyboard([['pay.reconcile:'.$payment->getKey() => 'Verify', 'pay.refuse:'.$payment->getKey() => 'Not received']])
                : [];
            $this->bot->sendMessage($chat, self::paymentLine($payment), $buttons);
        }
    }

    /** The readiness board for one departure, or the next one. */
    private function ready(string $argument, User $user): string
    {
        if (! $user->can('departure.board')) {
            return 'Your account cannot see the departure board.';
        }

        $departure = self::departureFor($argument);

        if ($departure === null) {
            return trim($argument) === '' ? 'No departure is still to leave.' : 'No departure matches "'.e($argument).'".';
        }

        $concerns = DepartureReadiness::concerns($departure);
        $lines = ['<b>'.e(DailySummary::label($departure)).'</b> · leaves '.e($departure->date_start->diffForHumans())];

        if ($concerns === []) {
            $lines[] = 'Ready. Nothing stands in the way.';
        }

        foreach ($concerns as $concern) {
            $lines[] = ($concern['severity'] === DepartureReadiness::BLOCKING ? '⛔ ' : '⚠ ').e($concern['headline']);
        }

        $lines[] = '';
        $lines[] = 'Details: '.e(Notifier::panelUrl('departure-board'));

        return implode("\n", $lines);
    }

    /** Open checklist items on a departure, each with a tick button. */
    private function checklist(string $argument, User $user, int $chat): void
    {
        if (! $user->can('checklist.viewAny')) {
            $this->bot->sendMessage($chat, 'Your account cannot see checklists.');

            return;
        }

        $departure = self::departureFor($argument);

        if ($departure === null) {
            $this->bot->sendMessage($chat, trim($argument) === '' ? 'No departure is still to leave.' : 'No departure matches "'.e($argument).'".');

            return;
        }

        $items = $departure->checklist()->open()->orderBy('due_on')->limit(10)->get();

        if ($items->isEmpty()) {
            $this->bot->sendMessage($chat, 'Nothing open on the checklist of '.e(DailySummary::label($departure)).'.');

            return;
        }

        $rows = $items->map(fn ($item): array => [
            'check.tick:'.$item->getKey() => ($item->isOverdue() ? '⚠ ' : '☐ ').mb_substr((string) $item->title, 0, 40).($item->due_on ? ' · '.$item->due_on->format('j M') : ''),
        ])->all();

        $this->bot->sendMessage(
            $chat,
            '<b>Checklist · '.e(DailySummary::label($departure)).'</b>'."\n".$items->count().' open.'.($user->can('checklist.tick') ? ' Tap one to tick it.' : ''),
            $user->can('checklist.tick') ? Bot::keyboard($rows) : [],
        );
    }

    // ── PDFs by chat ─────────────────────────────────────────────────────

    private function busList(string $argument, User $user, int $chat): void
    {
        if (! $user->can('list.view')) {
            $this->bot->sendMessage($chat, 'Your account cannot see lists.');

            return;
        }

        [$which, $number] = array_pad(preg_split('/\s+/', trim($argument)) ?: [], 2, '');
        $departure = self::departureFor($number);

        if ($departure === null || $which === '') {
            $this->bot->sendMessage($chat, 'Send /bus 1, or /bus 1 12 for departure 12.');

            return;
        }

        $bus = $departure->busGroups()
            ->where(fn ($query) => $query->where('name', 'Bus '.$which)->orWhere('name', 'like', '%'.$which.'%'))
            ->first();

        if (! $bus instanceof BusGroup) {
            $this->bot->sendMessage($chat, 'No bus "'.e($which).'" on '.e(DailySummary::label($departure)).'. Buses: '.e($departure->busGroups()->pluck('name')->implode(', ') ?: 'none yet').'.');

            return;
        }

        $lists = app(Lists::class);
        $list = $lists->busList($bus);
        $this->bot->sendDocument($chat, $list->filename.'.pdf', $lists->pdf($list)->output(), $bus->name.' · '.DailySummary::label($departure).' · '.count($list->rows).' on board');
    }

    private function roomList(string $argument, User $user, int $chat): void
    {
        if (! $user->can('list.view')) {
            $this->bot->sendMessage($chat, 'Your account cannot see lists.');

            return;
        }

        $forHotel = (bool) preg_match('/\bhotel\b/i', $argument);
        [$city, $number] = array_pad(preg_split('/\s+/', trim((string) preg_replace('/\bhotel\b/i', '', $argument))) ?: [], 2, '');
        $departure = self::departureFor((string) $number);

        if ($departure === null || $city === '') {
            $this->bot->sendMessage($chat, 'Send /rooms makkah or /rooms madinah, with a departure number after it for another departure. Add "hotel" for the hotel copy.');

            return;
        }

        $hotel = $departure->hotels()
            ->where(fn ($query) => $query->where('city', strtolower($city))->orWhere('name', 'like', '%'.$city.'%'))
            ->first();

        if (! $hotel instanceof DepartureHotel) {
            $this->bot->sendMessage($chat, 'No hotel "'.e($city).'" on '.e(DailySummary::label($departure)).'.');

            return;
        }

        $lists = app(Lists::class);
        $list = $lists->roomList($hotel, $forHotel);
        $this->bot->sendDocument($chat, $list->filename.'.pdf', $lists->pdf($list)->output(), $list->title.' · '.DailySummary::label($departure));
    }

    private function manifest(string $argument, User $user, int $chat): void
    {
        if (! $user->can('list.view')) {
            $this->bot->sendMessage($chat, 'Your account cannot see lists.');

            return;
        }

        $preset = 'office';
        $number = '';

        foreach (preg_split('/\s+/', trim($argument)) ?: [] as $word) {
            if (array_key_exists(strtolower($word), Lists::PRESETS)) {
                $preset = strtolower($word);
            } elseif ($word !== '') {
                $number = $word;
            }
        }

        $departure = self::departureFor($number);

        if ($departure === null) {
            $this->bot->sendMessage($chat, 'Send /manifest, or /manifest airline 12. Shapes: '.implode(', ', array_keys(Lists::PRESETS)).'.');

            return;
        }

        $lists = app(Lists::class);
        $list = $lists->manifest($departure, $preset, null, $user->can('payment.viewAny'));
        $this->bot->sendDocument($chat, $list->filename.'.pdf', $lists->pdf($list)->output(), $list->title.' · '.DailySummary::label($departure).' · '.count($list->rows).' travellers');
    }

    private function invoice(string $argument, User $user, int $chat): void
    {
        if (! $user->can('booking.view')) {
            $this->bot->sendMessage($chat, 'Your account cannot see bookings.');

            return;
        }

        $booking = Booking::query()->with('customer')->where('reference', self::reference($argument))->first();

        if ($booking === null) {
            $this->bot->sendMessage($chat, 'Send /invoice RIH-B-2026-0001.');

            return;
        }

        $documents = app(Documents::class);
        $this->bot->sendDocument($chat, $documents->invoiceFilename($booking), $documents->invoice($booking)->output(), $booking->reference.' · '.$booking->customer->name.' · '.self::balanceWords($booking));
    }

    private function receipt(string $argument, User $user, int $chat): void
    {
        if (! $user->can('payment.download')) {
            $this->bot->sendMessage($chat, 'Your account cannot send receipts.');

            return;
        }

        $payment = Payment::query()->where('reference', self::reference($argument))->first();

        if (! $payment instanceof Payment) {
            $this->bot->sendMessage($chat, 'Send /receipt RIH-P-2026-0001.');

            return;
        }

        if ($payment->status !== Payment::SUCCEEDED) {
            $this->bot->sendMessage($chat, e((string) $payment->reference).' is '.e(str_replace('_', ' ', (string) $payment->status)).'; a receipt exists only for money that is in.');

            return;
        }

        $documents = app(Documents::class);
        $this->bot->sendDocument($chat, $documents->receiptFilename($payment), $documents->receipt($payment)->output(), $payment->reference.' · '.$payment->money()->format().' · forward this to the customer');
    }

    // ── Uploading ────────────────────────────────────────────────────────

    /**
     * A photo or file sent to the bot with a booking reference in the
     * caption ("RIH-B-2026-0001 10000") is a bank slip: recorded as a
     * transfer claim with the slip attached, exactly as the panel's
     * "Record payment" does, and so waiting for Finance.
     *
     * @param  array<string, mixed>  $message
     */
    private function upload(TelegramLink $link, string $caption, array $message): void
    {
        $chat = $link->chat_id;

        if (! $link->user->can('payment.create')) {
            $this->bot->sendMessage($chat, 'Your account cannot record payments.');

            return;
        }

        if (! preg_match('/RIH-?B-?\d{4}-?\d{4}/i', $caption, $matches)) {
            $this->bot->sendMessage($chat, 'Put the booking reference in the caption, like <b>RIH-B-2026-0001 10000</b>, and I will record the slip for Finance.');

            return;
        }

        $booking = Booking::query()->with('customer')->where('reference', self::reference($matches[0]))->first();

        if ($booking === null) {
            $this->bot->sendMessage($chat, 'No booking has the reference '.e(self::reference($matches[0])).'.');

            return;
        }

        if (isset($message['photo']) && is_array($message['photo'])) {
            $largest = end($message['photo']);
            $fileId = (string) ($largest['file_id'] ?? '');
            $name = 'telegram-'.(string) ($largest['file_unique_id'] ?? 'photo').'.jpg';
            $mime = 'image/jpeg';
        } else {
            $fileId = (string) ($message['document']['file_id'] ?? '');
            $name = (string) ($message['document']['file_name'] ?? 'telegram-file');
            $mime = (string) ($message['document']['mime_type'] ?? 'application/octet-stream');
        }

        if (! in_array($mime, (array) config('payments.slips.mime_types'), true)) {
            $this->bot->sendMessage($chat, 'Send the slip as a photo, or as a PDF or JPEG file.');

            return;
        }

        $rest = (string) preg_replace('/RIH-?B-?\d{4}-?\d{4}/i', '', $caption);
        $amount = null;
        $details = ['notes' => 'Slip sent to the Telegram bot by '.$link->user->name.'.'];

        // "RIH-B-2026-0001 650 USD": money in another currency is credited
        // at the day's rate from the table — never a rate made up here.
        if (preg_match('/\b(\d[\d,]*)\b\s*([A-Za-z]{3})?/', $rest, $digits) === 1) {
            $receivedIn = strtoupper((string) ($digits[2] ?? '')) ?: $booking->currency;

            if (! in_array($receivedIn, Rates::currencies(), true)) {
                $this->bot->sendMessage($chat, e($receivedIn).' is not a currency this office takes. '.e(implode(', ', Rates::currencies())).'.');

                return;
            }

            $received = Money::ofMajor((int) str_replace(',', '', $digits[1]), $receivedIn);

            if ($receivedIn === $booking->currency) {
                $amount = $received;
            } else {
                try {
                    $amount = Rates::toBase($received);
                } catch (InvalidArgumentException $e) {
                    $this->bot->sendMessage($chat, 'Not recorded: '.e($e->getMessage()));

                    return;
                }

                $details += ['received_currency' => $receivedIn, 'received_minor' => $received->minor, 'exchange_rate' => Rates::rate($receivedIn)];
            }
        } elseif ($booking->balance()->minor > 0) {
            $amount = $booking->balance();
        }

        if ($amount === null || $amount->minor < 1) {
            $this->bot->sendMessage($chat, 'How much was sent? Put it after the reference, like <b>'.e((string) $booking->reference).' 10000</b> (or <b>650 USD</b>).');

            return;
        }

        $bytes = $this->bot->download((string) ($this->bot->getFile($fileId)['file_path'] ?? ''));

        if ($bytes === '') {
            $this->bot->sendMessage($chat, 'Telegram did not hand over the file. Send it again.');

            return;
        }

        $temporary = tempnam(sys_get_temp_dir(), 'slip');

        if ($temporary === false) {
            $this->bot->sendMessage($chat, 'Could not keep the file. Try again, or record it in the panel.');

            return;
        }

        file_put_contents($temporary, $bytes);

        try {
            $payment = app(BankTransfer::class)->start($booking, $amount, $details);
            app(SlipVault::class)->attach($payment, new UploadedFile($temporary, $name, $mime, null, true));
        } catch (InvalidArgumentException $e) {
            $this->bot->sendMessage($chat, 'Not recorded: '.e($e->getMessage()));

            return;
        } finally {
            @unlink($temporary);
        }

        $this->bot->sendMessage($chat, implode("\n", [
            '✅ <b>'.e((string) $payment->fresh()?->reference).'</b> recorded: '.e($amount->format()).($payment->receivedWords() ? ' (received '.e($payment->receivedWords()).')' : '').' for '.e((string) $booking->reference).' · '.e((string) $booking->customer->name).'.',
            'Finance verifies it next.',
        ]));
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /**
     * Finance holds the board and not the listing, and either is reason
     * enough to be told what is ahead.
     */
    public static function seesDepartures(User $user): bool
    {
        return $user->can('departure.viewAny') || $user->can('departure.board');
    }

    /** The departure numbered, named, or — with nothing said — the next one to leave. */
    public static function departureFor(string $argument): ?Departure
    {
        $argument = trim($argument);

        if ($argument === '') {
            return DailySummary::upcoming()->first();
        }

        if (ctype_digit(ltrim($argument, '#'))) {
            return Departure::query()->with('package')->find((int) ltrim($argument, '#'));
        }

        return Departure::query()
            ->with('package')
            ->whereHas('package', fn ($package) => $package->where('title', 'like', '%'.$argument.'%'))
            ->orderByDesc('date_start')
            ->first();
    }

    /** @return array{0: string, 1: string} the command, lower-cased and without the bot's name, and the rest */
    public static function parse(string $text): array
    {
        if (! str_starts_with($text, '/')) {
            return ['', $text];
        }

        [$command, $argument] = array_pad(preg_split('/\s+/', $text, 2) ?: [], 2, '');

        return [strtolower((string) strtok((string) $command, '@')), trim((string) $argument)];
    }

    private static function looksLikeBooking(string $term): bool
    {
        return (bool) preg_match('/^RIH-?B-?\d{4}-?\d{4}$/i', trim($term));
    }

    /** "rih-b-2026-0001", "RIHB20260001" and "RIH-B-2026-0001" are the same reference. */
    private static function reference(string $term): string
    {
        $compact = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', trim($term)));

        return (string) preg_replace('/^RIH([A-Z])(\d{4})(\d{4,})$/', 'RIH-$1-$2-$3', $compact);
    }

    private function bookingCard(Booking $booking, User $user): string
    {
        $waiting = $user->can('payment.viewAny') ? $booking->payments()->awaitingReview()->count() : 0;

        return implode("\n", array_filter([
            '<b>'.e((string) $booking->reference).'</b> · '.e((string) $booking->customer->name).($booking->customer->phone ? ' · '.e((string) $booking->customer->phone) : ''),
            e(DailySummary::label($booking->departure)).' · '.e(ucfirst((string) $booking->status)).' · '.$booking->seats.' seat'.($booking->seats === 1 ? '' : 's'),
            $user->can('payment.viewAny') ? e($booking->total()->format()).' total · '.e($booking->paid()->format()).' received · <b>'.e(self::balanceWords($booking)).'</b>' : null,
            $waiting > 0 ? $waiting.' payment'.($waiting === 1 ? '' : 's').' waiting for Finance.' : null,
            $user->can('booking.view') ? '/invoice '.e((string) $booking->reference).' sends the invoice.' : null,
        ]));
    }

    private function bookingLine(Booking $booking, User $user): string
    {
        return e((string) $booking->reference).' · '.e(DailySummary::label($booking->departure)).' · '.e(ucfirst((string) $booking->status))
            .($user->can('payment.viewAny') ? ' · '.e(self::balanceWords($booking)) : '');
    }

    private static function balanceWords(Booking $booking): string
    {
        return $booking->balance()->minor > 0 ? $booking->balance()->format().' due' : 'paid in full';
    }

    private static function paymentLine(Payment $payment): string
    {
        $booking = $payment->booking();

        return e((string) $payment->reference).' · '.e($payment->money()->format()).' · '.e(str_replace('_', ' ', (string) $payment->method))
            ."\n".'For '.($booking instanceof Booking ? e((string) $booking->reference).' · '.e((string) $booking->customer->name) : 'a guesthouse stay')
            .($payment->hasSlip() ? '' : "\nNo slip attached.");
    }
}
