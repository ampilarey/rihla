{{--
    Customer cards: eight per A4, credit-card sized, cut along the dashed
    lines. No Tailwind, no hex — see list.blade.php.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Customer cards</title>
    <style>
        @page { margin: 10mm 12mm; }
        body { font-family: 'DejaVu Sans', sans-serif; color: {{ \App\Support\Brand::INK }}; font-size: 7.5pt; line-height: 1.3; margin: 0; }
        table.sheet { border-collapse: collapse; width: 100%; }
        table.sheet td.cell { width: 50%; padding: 2.5mm; border: 0.3px dashed {{ \App\Support\Brand::INK_MUTED }}; vertical-align: top; }
        table.card { width: 100%; border-collapse: collapse; }
        table.card td { vertical-align: top; padding: 0; height: 50mm; }
        .photo { width: 22mm; height: 28mm; border: 0.4px solid {{ \App\Support\Brand::BORDER }}; }
        table.nophoto { width: 22mm; border-collapse: collapse; }
        table.nophoto td { width: 22mm; height: 28mm; border: 0.4px dashed {{ \App\Support\Brand::BORDER }}; color: {{ \App\Support\Brand::INK_MUTED }}; font-size: 6pt; text-align: center; vertical-align: middle; }
        .brand { font-size: 9pt; font-weight: bold; color: {{ \App\Support\Brand::WINE }}; }
        .trip { font-size: 7pt; color: {{ \App\Support\Brand::INK_MUTED }}; }
        .name { font-size: 10pt; font-weight: bold; margin-top: 1.5mm; }
        .line { margin-top: 0.8mm; }
        .k { color: {{ \App\Support\Brand::INK_MUTED }}; }
        .qr { width: 20mm; height: 20mm; }
        .serial { font-size: 6pt; color: {{ \App\Support\Brand::INK_MUTED }}; text-align: center; }
        .phones { font-size: 6.5pt; color: {{ \App\Support\Brand::WINE }}; margin-top: 1.5mm; }
    </style>
</head>
<body>
    <table class="sheet">
        @foreach (array_chunk($items, 2) as $pair)
            <tr>
                @foreach ($pair as $item)
                    <td class="cell">
                        <table class="card">
                            <tr>
                                <td style="width: 24mm;">
                                    @if ($item['photo'])
                                        <img class="photo" src="{{ $item['photo'] }}" alt="">
                                    @else
                                        <table class="nophoto"><tr><td>no photo</td></tr></table>
                                    @endif
                                </td>
                                <td>
                                    <div class="brand">{{ $issuer['name'] }}</div>
                                    <div class="trip">{{ $item['departure']->package->title ?? 'Departure' }} · {{ $item['departure']->date_start->format('j M Y') }}</div>
                                    <div class="name">{{ $item['traveller']->full_name }}</div>
                                    <div class="line"><span class="k">Bus</span> {{ $item['member']->busGroup?->name ?? '—' }} <span class="k">· Booking</span> {{ $item['member']->booking->reference }}</div>
                                    <div class="line">
                                        @foreach ($item['rooms'] as $room)
                                            <span class="k">{{ $room['city'] }}</span> {{ $room['room'] ?? '—' }}@if (! $loop->last) · @endif
                                        @endforeach
                                    </div>
                                    <div class="line"><span class="k">Booked by</span> {{ $item['booked_by'] }}</div>
                                    <div class="phones">{{ $issuer['name'] }} · {{ $issuer['phone'] }}</div>
                                </td>
                                <td style="width: 21mm; text-align: center; vertical-align: top; padding-top: 12mm;">
                                    <img class="qr" src="{{ $item['qr'] }}" alt="">
                                    <div class="serial">{{ $item['card']->serial }}</div>
                                </td>
                            </tr>
                        </table>
                    </td>
                @endforeach
                @if (count($pair) === 1)<td class="cell"></td>@endif
            </tr>
        @endforeach
    </table>
</body>
</html>
