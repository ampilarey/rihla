@props(['date', 'format' => 'j M Y'])

{{--
    A date that survives a Dhivehi page.

    `{{ $date->translatedFormat('j M Y') }}` inside `dir="ltr"` looks like it
    should be safe. It is not, and the result was on the live package page
    before it was on this one: "19 ނޮވެންބަރު 2026" rendered as
    "19 2026 ނޮވެންބަރު", with the year jumping in front of the month.

    Why, exactly: Thaana letters carry the Unicode bidi class **AL**, and rule
    W2 of the bidirectional algorithm retypes any European number following an
    AL character as an *Arabic* number. The year after the month therefore
    stops behaving like Latin digits, rule N1 then pulls every neutral space
    and dash around it into the right-to-left run, and the whole line ends up
    reordered — inside an element explicitly marked `dir="ltr"`, because the
    base direction was never the problem.

    The fix is isolation, not direction: the month goes in a `<bdi>`, which is
    `unicode-bidi: isolate`, so the digits on either side of it never see an
    AL character as the preceding strong type and stay European. The day, the
    month and the year are formatted separately for that reason and for no
    other.

    The format is split rather than parsed, so a caller asking for something
    this cannot isolate gets the plain translated string instead of a subtly
    wrong one.
--}}
@php($parts = match ($format) {
    'j M Y' => ['j', 'M', 'Y'],
    'j F Y' => ['j', 'F', 'Y'],
    // Day and month with no year. Still isolated, and for the same reason
    // even though nothing follows the month inside the component: these sit
    // in a sentence, and the next number along the line — "· about 6
    // minutes" — is retyped by rule W2 exactly as a year would be.
    'j M' => ['j', 'M', null],
    'j F' => ['j', 'F', null],
    default => null,
})

@if($parts === null)
    <span dir="ltr" {{ $attributes }}>{{ $date->translatedFormat($format) }}</span>
@elseif($parts[2] === null)
    <span dir="ltr" {{ $attributes }}>{{ $date->translatedFormat($parts[0]) }} <bdi>{{ $date->translatedFormat($parts[1]) }}</bdi></span>
@else
    <span dir="ltr" {{ $attributes }}>{{ $date->translatedFormat($parts[0]) }} <bdi>{{ $date->translatedFormat($parts[1]) }}</bdi> {{ $date->translatedFormat($parts[2]) }}</span>
@endif
