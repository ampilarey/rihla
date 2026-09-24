{{--
    A one-page fact sheet for a guesthouse — §15.4 (Phase 9.5).

    The other half of the share kit: a link opens the page, this is what
    gets attached when somebody wants the details in their hand, on a ferry
    with no signal, or forwarded to whoever is actually paying.

    **No Tailwind.** dompdf parses a small, old subset of CSS and the
    utility classes do not exist in it — every rule here is in this file,
    the same way the invoice shell does it.

    Arabic is deliberately rendered with the Latin body font and NOT with
    the Dhivehi face. A_Faruma carries Thaana, not Arabic, and pointing
    Arabic text at it produces the same silent row of boxes the guide once
    downloaded as. DejaVu Sans covers Arabic and ships with dompdf.
--}}
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ \App\Http\Middleware\SetLocale::isRtl($locale) ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="UTF-8">
    <title>{{ $property->name }}</title>
    <style>
        @if($locale === 'dv')
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

        body { margin: 0; padding: 28px; font-size: 11px; line-height: 1.5; color: #2E2245; }

        .head { border-bottom: 2px solid #A88C1F; padding-bottom: 12px; margin-bottom: 16px; }
        .issuer { font-size: 15px; font-weight: bold; color: #5F498A; }
        .muted { color: #6B6259; }
        .title { font-size: 21px; font-weight: bold; margin: 0 0 2px; }

        table { width: 100%; border-collapse: collapse; }
        .num { text-align: end; }

        .cover { width: 100%; height: 190px; margin: 0 0 16px; }

        h2 { font-size: 13px; margin: 16px 0 6px; color: #5F498A; }
        ul { margin: 0; padding-inline-start: 16px; }
        li { margin-bottom: 3px; }

        .rooms th { text-align: start; border-bottom: 1px solid #E5DED4; padding: 6px 4px; font-size: 10px; text-transform: uppercase; color: #6B6259; }
        .rooms td { border-bottom: 1px solid #F1EBE3; padding: 7px 4px; }

        .policy { margin-top: 14px; padding: 10px; background: #FAF6F0; font-size: 10px; }
        .foot { margin-top: 20px; border-top: 1px solid #E5DED4; padding-top: 10px; font-size: 10px; color: #6B6259; }

        /* Money, phone numbers and the URL are Latin in every language.
           Without this the RTL page drags the currency code and the
           trailing punctuation to the wrong end — the same bidi rule that
           once put ".Rihla Travels" in the website footer. */
        .ltr { direction: ltr; unicode-bidi: embed; }
    </style>
</head>
<body>

<div class="head">
    <table>
        <tr>
            <td>
                <div class="issuer">{{ $issuer['name'] }}</div>
                @if($issuer['registration'])
                    <div class="muted ltr">REG NO: {{ $issuer['registration'] }}</div>
                @endif
                <div class="muted ltr">{{ $issuer['phone'] }}</div>
            </td>
            <td class="num">
                <p class="title">{{ $property->name }}</p>
                @if($property->island)
                    <div class="muted">{{ $property->island }}</div>
                @endif
            </td>
        </tr>
    </table>
</div>

@if($cover)
    <img src="{{ $cover }}" alt="" class="cover">
@endif

@if(filled($property->summary))
    <p>{{ $property->summary }}</p>
@endif

@if(filled($property->description))
    <p>{{ $property->description }}</p>
@endif

@if($rooms->isNotEmpty())
    <h2>{{ __('messages.Rooms') }}</h2>
    <table class="rooms">
        <thead>
            <tr>
                <th>{{ __('messages.Room') }}</th>
                <th>{{ __('messages.Sleeps') }}</th>
                <th class="num">{{ __('messages.a night') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($rooms as $room)
                <tr>
                    <td>{{ $room->name }}@if($room->beds) <span class="muted">· {{ $room->beds }}</span>@endif</td>
                    <td>{{ $room->sleeps }}</td>
                    <td class="num ltr">{{ $room->baseRate()->format() }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

@if($property->amenity_list !== [])
    <h2>{{ __('messages.What is here') }}</h2>
    <ul>
        @foreach($property->amenity_list as $amenity)
            <li>{{ $amenity }}</li>
        @endforeach
    </ul>
@endif

@if(filled($property->house_rules))
    <h2>{{ __('messages.House rules') }}</h2>
    <p>{{ $property->house_rules }}</p>
@endif

<div class="policy">
    <strong>{{ __('messages.Paying and cancelling') }}</strong>
    <ul>
        <li>{{ __('messages.:percent% deposit when the guesthouse confirms.', ['percent' => $property->deposit_pct]) }}</li>
        <li>{{ __('messages.The rest is due :days days before you arrive.', ['days' => $property->balance_days_before]) }}</li>
        <li>{{ __('messages.Cancel more than :days days before and the deposit comes back.', ['days' => $property->free_cancel_days]) }}</li>
        @if($property->partner?->green_tax_mode === \App\Models\Partner::GREEN_TAX_AT_PROPERTY)
            <li>{{ __('messages.Green tax is paid at the guesthouse, not here.') }}</li>
        @endif
    </ul>
</div>

<div class="foot">
    {{-- The link, in full and in Latin. This sheet gets printed and
         forwarded, and a fact sheet nobody can get back to the site from
         is a dead end. --}}
    <p class="ltr">{{ $url }}</p>
    <p>{{ __('messages.Questions about this document? Message us on :number.', ['number' => $issuer['phone']]) }}</p>
</div>

</body>
</html>
