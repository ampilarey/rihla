<?php

namespace App\Filament\Resources\Enquiries\Tables;

use App\Models\Customer;
use App\Models\Enquiry;
use App\Models\EnquiryNote;
use App\Models\User;
use App\Support\Access;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class EnquiriesTable
{
    public const COLOURS = [
        Enquiry::NEW => 'warning',
        Enquiry::WORKING => 'info',
        Enquiry::WON => 'success',
        Enquiry::LOST => 'gray',
    ];

    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->description(fn (Enquiry $record): ?string => $record->phone ?: $record->email),

                TextColumn::make('source')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (string $state): string => ucfirst(str_replace('_', ' ', $state))),

                TextColumn::make('package.title')
                    ->label('About')
                    // A follow-up from a lost stay (§15.7) has no package;
                    // it has a guesthouse. Left as it was it read "Not sure
                    // yet", which is the one thing it is not — the person
                    // named the property, the dates and the party size, and
                    // then heard nothing.
                    ->state(fn (Enquiry $record): ?string => $record->package?->title
                        ?: $record->property?->name)
                    ->description(fn (Enquiry $record): ?string => $record->isFromALostStay()
                        ? 'A guesthouse ask that fell through'
                        : null)
                    ->placeholder('Not sure yet')
                    ->toggleable(),

                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                    ->color(fn (string $state): string => self::COLOURS[$state] ?? 'gray')
                    ->sortable(),

                TextColumn::make('owner.name')
                    ->label('Owner')
                    // The thing the screen is for. An unowned enquiry is not
                    // "—", it is a problem, and it says so.
                    ->placeholder('Nobody')
                    ->color(fn (Enquiry $record): string => $record->isOpen() && $record->assigned_to === null
                        ? 'danger'
                        : 'gray')
                    ->sortable(),

                TextColumn::make('next_action')
                    ->label('Next')
                    ->placeholder('Nothing planned')
                    ->wrap()
                    ->color(fn (Enquiry $record): string => match (true) {
                        $record->isOverdue() => 'danger',
                        $record->isOpen() && $record->next_action_at === null => 'danger',
                        default => 'gray',
                    })
                    ->description(fn (Enquiry $record): ?string => $record->next_action_at?->format('j M Y')),

                TextColumn::make('created_at')->label('Asked')->since()->sortable()->toggleable(),
            ])
            // Oldest first, deliberately: a queue is worked from the front,
            // and newest-first is how the message from four days ago never
            // gets answered.
            ->defaultSort('created_at', 'asc')
            // Eager-loaded: the About column reads both, and a queue worked
            // from the front is exactly where N+1 shows up first.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['package', 'property']))
            ->filters([
                SelectFilter::make('status')
                    ->options(array_combine(Enquiry::STATUSES, array_map('ucfirst', Enquiry::STATUSES)))
                    ->multiple(),

                SelectFilter::make('source')
                    ->options(array_combine(
                        Enquiry::SOURCES,
                        array_map(fn (string $s): string => ucfirst(str_replace('_', ' ', $s)), Enquiry::SOURCES),
                    )),

                SelectFilter::make('assigned_to')
                    ->label('Owner')
                    ->options(fn (): array => self::staff()),

                Filter::make('mine')
                    ->label('Mine')
                    ->query(fn (Builder $query): Builder => $query->where('assigned_to', auth()->id())),
            ])
            ->recordActions([
                ActionGroup::make([
                    self::noteAction(),
                    self::planAction(),
                    self::assignAction(),
                    self::wonAction(),
                    self::lostAction(),
                ]),
            ])
            ->emptyStateHeading('No enquiries')
            ->emptyStateDescription('Messages from the contact form land here, and so does anything staff write down from a phone call or WhatsApp.');
    }

    /**
     * Who an enquiry can belong to.
     *
     * Named roles rather than a permission query, for the reason the permits
     * screen gives: Super Admin holds no permission rows at all and would be
     * silently absent.
     *
     * @return array<int|string, string>
     */
    private static function staff(): array
    {
        return User::role([Access::BOOKING_STAFF, Access::PILGRIM_SUPPORT, Access::OPERATIONS_MANAGER])
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /** What was said. The history is the point of a CRM at this size. */
    private static function noteAction(): Action
    {
        return Action::make('note')
            ->label('Add a note')
            ->icon('heroicon-o-pencil-square')
            ->visible(fn (): bool => auth()->user()?->can('enquiry.update') === true)
            ->schema([
                Textarea::make('body')->label('What happened')->required()->rows(3)
                    ->placeholder('Rang, no answer. Sent the Ramadan prices on WhatsApp.'),
            ])
            ->action(function (Enquiry $record, array $data): void {
                $record->record($data['body']);

                Notification::make()->success()->title('Noted')->send();
            });
    }

    /**
     * The next action, which is the column this table exists for.
     *
     * Both fields required together: a next action with no date is a wish,
     * and a date with no action is a reminder to do nothing in particular.
     */
    private static function planAction(): Action
    {
        return Action::make('plan')
            ->label('Set the next action')
            ->icon('heroicon-o-calendar-days')
            ->color('primary')
            ->visible(fn (Enquiry $record): bool => $record->isOpen()
                && auth()->user()?->can('enquiry.update') === true)
            ->schema([
                TextInput::make('next_action')
                    ->label('What happens next')
                    ->required()
                    ->maxLength(255)
                    ->placeholder('Call back with the Shawwal price'),

                DatePicker::make('next_action_at')
                    ->label('By when')
                    ->required()
                    ->native(false)
                    ->default(now()->addDay()),
            ])
            ->action(function (Enquiry $record, array $data): void {
                $record->forceFill([
                    'next_action' => $data['next_action'],
                    'next_action_at' => $data['next_action_at'],
                    // Planning something is working on it. Leaving it at
                    // "new" would keep it in the untouched queue for ever.
                    'status' => $record->status === Enquiry::NEW ? Enquiry::WORKING : $record->status,
                ])->save();

                $record->record(
                    sprintf('Next: %s, by %s.', $data['next_action'], $record->next_action_at->format('j M Y')),
                    EnquiryNote::STATUS,
                );

                Notification::make()->success()->title('Planned')->send();
            });
    }

    private static function assignAction(): Action
    {
        return Action::make('assign')
            ->label('Hand it to somebody')
            ->icon('heroicon-o-user')
            ->visible(fn (Enquiry $record): bool => $record->isOpen()
                && auth()->user()?->can('enquiry.assign') === true)
            ->schema([
                Select::make('assigned_to')->label('Owner')->options(fn (): array => self::staff())->required(),
            ])
            ->action(function (Enquiry $record, array $data): void {
                $record->forceFill([
                    'assigned_to' => $data['assigned_to'],
                    'status' => $record->status === Enquiry::NEW ? Enquiry::WORKING : $record->status,
                ])->save();

                $record->record('Given to '.($record->fresh()->owner->name ?? 'somebody'), EnquiryNote::STATUS);

                Notification::make()->success()->title('Assigned')->send();
            });
    }

    /**
     * It became a booking.
     *
     * Creates the customer if there is not already one with that number —
     * matched the way the import matches, so `7712345` and `+960 771 2345`
     * are the same person and nobody has to notice.
     */
    private static function wonAction(): Action
    {
        return Action::make('won')
            ->label('They booked')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->visible(fn (Enquiry $record): bool => $record->isOpen()
                && auth()->user()?->can('enquiry.update') === true)
            ->requiresConfirmation()
            ->modalDescription(fn (Enquiry $record): string => $record->possibleCustomers()->isNotEmpty()
                ? 'There is already a customer with that phone number, and this enquiry will be attached to them rather than creating a second one.'
                : 'A customer will be created from these details, and the enquiry attached to them.')
            ->action(function (Enquiry $record): void {
                $customer = $record->possibleCustomers()->first()
                    ?? Customer::create([
                        'name' => $record->name,
                        'phone' => $record->phone,
                        'email' => $record->email,
                        'notes' => 'From enquiry '.$record->reference,
                    ]);

                $record->forceFill([
                    'status' => Enquiry::WON,
                    'customer_id' => $customer->getKey(),
                    'closed_at' => now(),
                ])->save();

                $record->record('They booked. Customer: '.$customer->name, EnquiryNote::STATUS);

                Notification::make()->success()->title('Marked as booked')->send();
            });
    }

    /**
     * It did not, and why.
     *
     * The reason is required. "Lost" with no reason is a row that tells
     * nobody anything, and the whole value of keeping lost enquiries is
     * being able to read back why people did not book.
     */
    private static function lostAction(): Action
    {
        return Action::make('lost')
            ->label('They did not book')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(fn (Enquiry $record): bool => $record->isOpen()
                && auth()->user()?->can('enquiry.update') === true)
            ->schema([
                Textarea::make('lost_reason')
                    ->label('Why')
                    ->required()
                    ->rows(2)
                    ->helperText('"Too expensive" and "went with a competitor" are different problems. Reading these back in a year is the point of keeping them.'),
            ])
            ->action(function (Enquiry $record, array $data): void {
                $record->forceFill([
                    'status' => Enquiry::LOST,
                    'lost_reason' => $data['lost_reason'],
                    'closed_at' => now(),
                ])->save();

                $record->record('Did not book: '.$data['lost_reason'], EnquiryNote::STATUS);

                Notification::make()->warning()->title('Closed')->send();
            });
    }
}
