{{--
    The shared shell for the invoice and the receipt.

    **No Tailwind.** dompdf parses a small, old subset of CSS and none of the
    utility classes exist in it anyway — the stylesheet is never loaded. Every
    rule here is inline in this file, which is also why it is one file rather
    than a set of components.

    The Dhivehi font is loaded explicitly. dompdf falls back silently to a
    font with no Thaana glyphs, which is how the Umrah guide once downloaded
    as a page of boxes: a broken web page can be reloaded, and a broken PDF
    is what somebody carries to the airport.
--}}
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $locale === 'dv' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="UTF-8">
    <title>{{ $title }}</title>
    <style>
        @if($locale === 'dv')
            {{-- Two faces from one file, and the second one is the point.
                 A_Faruma ships Regular only. Without a bold face declared,
                 dompdf resolves <strong> and any font-weight:bold to a
                 *different family* — Helvetica-Bold — which has no Thaana
                 glyphs, and every bold Dhivehi word renders as a row of
                 question marks. It is silent: the document downloads, the
                 body text is perfect, and only the headings are ruined.

                 Thaana has no true bold, so pointing bold at the regular
                 file loses nothing that exists. --}}
            @font-face {
                font-family: 'A_Faruma';
                font-weight: normal;
                src: url('{{ public_path('fonts/A_faruma.ttf') }}') format('truetype');
            }
            @font-face {
                font-family: 'A_Faruma';
                font-weight: bold;
                src: url('{{ public_path('fonts/A_faruma.ttf') }}') format('truetype');
            }
            body { font-family: 'A_Faruma', sans-serif; }
        @else
            body { font-family: 'DejaVu Sans', sans-serif; }
        @endif

        body { margin: 0; padding: 28px; font-size: 11px; line-height: 1.5; color: #2E2621; }

        .head { border-bottom: 2px solid #D2A03C; padding-bottom: 14px; margin-bottom: 18px; }
        .issuer { font-size: 16px; font-weight: bold; color: #8E2653; }
        .muted { color: #6B6259; }
        .doc-title { font-size: 20px; font-weight: bold; margin: 0 0 2px; }

        table { width: 100%; border-collapse: collapse; }
        .parties td { vertical-align: top; width: 50%; padding: 0 0 14px; }
        .lines th { text-align: start; border-bottom: 1px solid #E5DED4; padding: 6px 4px; font-size: 10px; text-transform: uppercase; color: #6B6259; }
        .lines td { border-bottom: 1px solid #F1EBE3; padding: 7px 4px; }
        .num { text-align: end; }

        .totals { margin-top: 14px; width: 55%; }
        .totals td { padding: 4px; }
        .totals .grand td { border-top: 2px solid #2E2621; font-weight: bold; font-size: 13px; }

        .note { margin-top: 22px; padding: 10px; background: #FAF6F0; font-size: 10px; }
        .foot { margin-top: 26px; border-top: 1px solid #E5DED4; padding-top: 10px; font-size: 10px; color: #6B6259; }

        /* Money and references are Latin in both languages. Without this the
           RTL page drags the currency code and the trailing punctuation to
           the wrong end — the same bidi rule that put ".Rihla Travels" in the
           website footer. */
        .ltr { direction: ltr; unicode-bidi: embed; }
    </style>
</head>
<body>

<div class="head">
    <table>
        <tr>
            <td>
                <div class="issuer">{{ $issuer['name'] }}</div>
                @if($issuer['address'])
                    <div class="muted">{{ $issuer['address'] }}</div>
                @endif
                @if($issuer['registration'])
                    <div class="muted ltr">REG NO: {{ $issuer['registration'] }}</div>
                @endif
                <div class="muted ltr">{{ $issuer['phone'] }}</div>
                @if($issuer['email'])
                    <div class="muted ltr">{{ $issuer['email'] }}</div>
                @endif
            </td>
            <td class="num">
                <p class="doc-title">{{ $title }}</p>
                <div class="muted ltr">{{ $number }}</div>
                <div class="muted ltr">{{ $issuedOn->format('j M Y') }}</div>
            </td>
        </tr>
    </table>
</div>

{{ $slot }}

<div class="foot">
    {{-- Only what somebody has actually stated. Both notes ship empty: no
         tax claim, because nobody has said whether Maldivian GST applies to
         an outbound Umrah package, and no terms, because the terms document
         does not exist. An invoice referencing terms nobody can produce
         implies a customer agreed to something. --}}
    @if(config('invoices.tax.note'))
        <p>{{ config('invoices.tax.note') }}</p>
    @endif
    @if(config('invoices.terms.note'))
        <p>{{ config('invoices.terms.note') }}</p>
    @endif
    <p>{{ __('messages.Questions about this document? Message us on :number.', ['number' => $issuer['phone']]) }}</p>
</div>

</body>
</html>
