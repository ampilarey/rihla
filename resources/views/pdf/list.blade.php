{{--
    A printable list: compact header, one table, page numbers. Landscape
    when the columns need it.

    **No Tailwind, no hex.** dompdf never loads the stylesheet, and every
    colour here is App\Support\Brand so a palette change reaches it —
    BrandColourTest refuses a hex literal in this folder.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $list->title }}</title>
    <style>
        @page { margin: 12mm 10mm 14mm 10mm; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 8pt; line-height: 1.35; color: {{ \App\Support\Brand::INK }}; margin: 0; }
        table.head { width: 100%; border-collapse: collapse; margin-bottom: 3mm; }
        table.head td { vertical-align: top; padding: 0 0 2mm; border-bottom: 1.5px solid {{ \App\Support\Brand::GOLD_ON_LIGHT }}; }
        h1 { font-size: 14pt; color: {{ \App\Support\Brand::WINE }}; margin: 0; }
        .sub { color: {{ \App\Support\Brand::INK_MUTED }}; font-size: 8.5pt; margin-top: 1mm; }
        .issuer { text-align: right; font-size: 7.5pt; color: {{ \App\Support\Brand::INK_MUTED }}; }
        .issuer .name { font-size: 9.5pt; font-weight: bold; color: {{ \App\Support\Brand::WINE }}; }
        table.list { width: 100%; border-collapse: collapse; }
        table.list th { text-align: left; font-size: 7pt; text-transform: uppercase; letter-spacing: .04em; color: {{ \App\Support\Brand::INK_MUTED }}; border-bottom: 1.2px solid {{ \App\Support\Brand::INK }}; padding: 1.2mm; }
        table.list td { padding: 1.4mm 1.2mm; border-bottom: 0.4px solid {{ \App\Support\Brand::BORDER }}; vertical-align: top; }
        table.list tr.alt td { background: {{ \App\Support\Brand::CREAM }}; }
        .empty { color: {{ \App\Support\Brand::INK_MUTED }}; }
        .notes { margin-top: 3mm; font-size: 7.5pt; color: {{ \App\Support\Brand::INK_MUTED }}; }
        .foot { position: fixed; bottom: -9mm; left: 0; right: 0; font-size: 7pt; color: {{ \App\Support\Brand::INK_MUTED }}; }
        .foot .page:after { content: counter(page); }
    </style>
</head>
<body>
    <table class="head">
        <tr>
            <td>
                <h1>{{ $list->title }}</h1>
                <div class="sub">{{ $list->subtitle }}</div>
            </td>
            <td class="issuer">
                <div class="name">{{ $issuer['name'] }}</div>
                {{ $issuer['phone'] }}<br>
                {{ now()->format('j M Y H:i') }}
            </td>
        </tr>
    </table>

    <table class="list">
        <thead>
            <tr>
                @foreach ($list->columns as $key => $heading)
                    <th>{{ $heading }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($list->rows as $row)
                <tr @if ($loop->even) class="alt" @endif>
                    @foreach ($list->columns as $key => $heading)
                        <td>{{ $row[$key] ?? '' }}</td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="{{ count($list->columns) }}" class="empty">Nobody on this list yet.</td></tr>
            @endforelse
        </tbody>
    </table>

    @if ($list->notes !== [])
        <div class="notes">
            @foreach ($list->notes as $note)
                <div>{{ $note }}</div>
            @endforeach
        </div>
    @endif

    <div class="foot">{{ $issuer['name'] }} · {{ $list->title }} · {{ $list->subtitle }} · page <span class="page"></span></div>
</body>
</html>
