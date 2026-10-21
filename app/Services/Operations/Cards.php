<?php

namespace App\Services\Operations;

use App\Models\BookingTraveller;
use App\Models\Card;
use App\Models\DepartureHotel;
use App\Models\Document;
use App\Models\RoomAssignment;
use App\Models\Traveller;
use App\Services\Invoices\Documents;
use App\Support\EncryptedFile;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Support\Collection;

/**
 * Customer cards and luggage tags — Hijaz's, brought across.
 *
 * Issued once per traveller and kind, printed as many times as needed,
 * each carrying a QR with a random token that the Scan page turns back
 * into the traveller. The QR holds the token and nothing else, so a tag
 * that falls off a suitcase gives nothing away.
 *
 * The QR is drawn by chillerlan/php-qrcode, which is already here as a
 * dependency of the panel, as a PNG: dompdf draws a PNG without
 * ceremony, where an SVG depends on which attributes its renderer reads.
 */
final class Cards
{
    public function __construct(private readonly Documents $documents) {}

    public function issue(BookingTraveller $member, string $kind): Card
    {
        $existing = $member->cards()->active()->where('kind', $kind)->first();

        return $existing instanceof Card ? $existing : $member->cards()->create(['kind' => $kind]);
    }

    /** Lost or wrong: the old token stops working and a fresh card is issued. */
    public function reissue(BookingTraveller $member, string $kind): Card
    {
        foreach ($member->cards()->active()->where('kind', $kind)->get() as $card) {
            $card->forceFill(['revoked_at' => now()])->save();
        }

        return $this->issue($member, $kind);
    }

    /** The card a scanned QR, a pasted link or a typed serial names, if it is still live. */
    public function resolve(string $tokenOrSerial): ?Card
    {
        $value = trim($tokenOrSerial);

        if ($value === '') {
            return null;
        }

        if (preg_match('#[?&]t=([A-Za-z0-9]+)#', $value, $matches) === 1) {
            $value = $matches[1];
        }

        return Card::query()
            ->active()
            ->where(fn ($query) => $query->where('qr_token', $value)->orWhere('serial', strtoupper($value)))
            ->with('bookingTraveller')
            ->first();
    }

    /**
     * A sheet of cards or tags for these people, issuing any that do not
     * exist yet and counting the print.
     *
     * @param  Collection<int, BookingTraveller>  $members
     */
    public function sheet(Collection $members, string $kind): PdfDocument
    {
        $items = [];

        foreach ($members as $member) {
            $member->loadMissing(['traveller', 'booking.departure.hotels', 'booking.departure.package', 'booking.customer', 'busGroup']);
            $card = $this->issue($member, $kind);
            $card->increment('printed_count');

            $departure = $member->booking->departure;

            $items[] = [
                'card' => $card,
                'member' => $member,
                'traveller' => $member->traveller,
                'departure' => $departure,
                'booked_by' => (string) $member->booking->customer->name,
                'qr' => $this->qrDataUri($card->url()),
                'photo' => $kind === Card::CUSTOMER ? $this->photoDataUri($member->traveller) : null,
                'rooms' => $departure->hotels
                    ->map(fn (DepartureHotel $hotel): array => ['city' => $hotel->cityLabel(), 'room' => $this->roomIn($hotel, $member->traveller)])
                    ->all(),
            ];
        }

        $view = $kind === Card::LUGGAGE ? 'pdf.luggage-tags' : 'pdf.cards';

        return Pdf::loadView($view, ['items' => $items, 'issuer' => $this->documents->issuer()])
            ->setPaper('a4', 'portrait');
    }

    /** A PNG data URI of the QR, sized for print. */
    public function qrDataUri(string $contents): string
    {
        // `outputType`, not `outputInterface`: the library reads the class
        // only when the type says "custom", and then labels the data URI
        // `image/custom`. Named by type it draws a PNG and says so.
        $options = new QROptions([
            'outputType' => QROutputInterface::GDIMAGE_PNG,
            'outputBase64' => true,
            'eccLevel' => EccLevel::M,
            'scale' => 8,
            'addQuietzone' => true,
            'imageTransparent' => false,
        ]);

        return (string) (new QRCode($options))->render($contents);
    }

    /** The traveller's photograph from the wallet as a data URI dompdf can draw, or null. */
    public function photoDataUri(Traveller $traveller): ?string
    {
        $version = Document::query()
            ->where('traveller_id', $traveller->getKey())
            ->where('type', 'photo')
            ->where('status', '!=', Document::REJECTED)
            ->latest('id')
            ->first()
            ?->currentVersion();

        if ($version === null || ! in_array($version->mime_type, ['image/jpeg', 'image/png'], true)) {
            return null;
        }

        $bytes = EncryptedFile::contents($version->disk, $version->path);

        return $bytes === null ? null : 'data:'.$version->mime_type.';base64,'.base64_encode($bytes);
    }

    private function roomIn(DepartureHotel $hotel, Traveller $traveller): ?string
    {
        return RoomAssignment::query()
            ->where('traveller_id', $traveller->getKey())
            ->whereHas('room', fn ($query) => $query->where('departure_hotel_id', $hotel->getKey()))
            ->with('room')
            ->first()
            ?->room
            ?->label;
    }
}
