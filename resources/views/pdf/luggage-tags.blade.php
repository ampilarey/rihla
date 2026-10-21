{{--
    Luggage tags: two per A4, large print, a cut line between. The name
    and the bus are what a porter reads from across a lobby; the QR is
    what staff scan. No Tailwind, no hex — see list.blade.php.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Luggage tags</title>
    <style>
        @page { margin: 10mm 12mm; }
        body { font-family: 'DejaVu Sans', sans-serif; color: {{ \App\Support\Brand::INK }}; margin: 0; }
        .tag { height: 116mm; border: 0.5px dashed {{ \App\Support\Brand::INK_MUTED }}; padding: 6mm; page-break-inside: avoid; }
        .tag + .tag { margin-top: 6mm; }
        table { width: 100%; border-collapse: collapse; }
        td { vertical-align: top; padding: 0; }
        .brand { font-size: 16pt; font-weight: bold; color: {{ \App\Support\Brand::WINE }}; }
        .trip { font-size: 11pt; color: {{ \App\Support\Brand::INK_MUTED }}; margin-top: 1mm; }
        .name { font-size: 26pt; font-weight: bold; margin-top: 8mm; line-height: 1.1; }
        .bus { font-size: 20pt; font-weight: bold; color: {{ \App\Support\Brand::WINE }}; margin-top: 6mm; }
        .family { font-size: 11pt; margin-top: 3mm; }
        .phones { font-size: 12pt; margin-top: 8mm; }
        .k { color: {{ \App\Support\Brand::INK_MUTED }}; }
        .qr { width: 38mm; height: 38mm; }
        .serial { font-size: 8pt; color: {{ \App\Support\Brand::INK_MUTED }}; text-align: center; margin-top: 1mm; }
    </style>
</head>
<body>
    @foreach ($items as $item)
        <div class="tag">
            <table>
                <tr>
                    <td>
                        <div class="brand">{{ $issuer['name'] }}</div>
                        <div class="trip">{{ $item['departure']->package->title ?? 'Departure' }} · {{ $item['departure']->date_start->format('j M Y') }}</div>
                    </td>
                    <td style="width: 42mm; text-align: center;">
                        <img class="qr" src="{{ $item['qr'] }}" alt="">
                        <div class="serial">{{ $item['card']->serial }}</div>
                    </td>
                </tr>
            </table>
            <div class="name">{{ $item['traveller']->full_name }}</div>
            <div class="bus">{{ $item['member']->busGroup?->name ?? 'Bus —' }}</div>
            <div class="family"><span class="k">Booked by</span> {{ $item['booked_by'] }}
                @foreach ($item['rooms'] as $room)
                    <span class="k">· {{ $room['city'] }}</span> {{ $room['room'] ?? '—' }}
                @endforeach
            </div>
            <div class="phones"><span class="k">If found, please call</span> {{ $issuer['phone'] }}</div>
        </div>
    @endforeach
</body>
</html>
