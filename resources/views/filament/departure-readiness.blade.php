{{--
    Who on this departure cannot travel yet, and why.

    Computed on open (§5.4a) and never stored — see App\Support\TravelReadiness.
    A modal rather than a table column: answering it costs a handful of
    queries per traveller, and paying that on every row of every departure
    list to show a tick almost nobody reads is the wrong trade.

    **No Tailwind utility classes.** This panel has no custom Filament theme,
    so its stylesheet carries Filament's own classes and nothing else:
    `rounded-lg`, `text-sm`, `space-y-4` and the rest silently do nothing
    here. The first draft was written with them and rendered as unstyled
    running text — which a passing test could not have shown, because the
    markup was there and only the styling was missing. Everything below is a
    Filament component.

    The callouts take `description` rather than a slot. A callout renders
    its heading, description and footer and **ignores its slot entirely**,
    so body text written between the tags disappears without an error.

    Staff-only and English-only, like the rest of /staff.
--}}
<div>
    @if ($blockers === [])
        <x-filament::callout
            color="success"
            icon="heroicon-o-check-circle"
            heading="Everybody confirmed on this departure can travel"
            description="They each have a passport, a visa and an Umrah permit. Rawdah slots are not counted here: missing one is a disappointment, while missing an Umrah permit is a wasted journey, and showing them together would hide the difference."
        />
    @else
        <x-filament::callout
            color="danger"
            icon="heroicon-o-exclamation-triangle"
            :heading="trans_choice(
                '{1}:count confirmed booking is not ready|[2,*]:count confirmed bookings are not ready',
                count($blockers),
                ['count' => count($blockers)],
            )"
            description="Draft bookings and lapsed holds are not counted — they are not people who are going."
        />

        @foreach ($blockers as $reference => $travellers)
            <x-filament::section :heading="$reference" compact>
                <ul>
                    @foreach ($travellers as $name => $missing)
                        <li>
                            {{ $name }} — still needs {{ implode(', ', array_map(fn (string $r): string => $labels[$r] ?? $r, $missing)) }}
                        </li>
                    @endforeach
                </ul>
            </x-filament::section>
        @endforeach
    @endif

    @if ($missingPrerequisites !== [])
        <x-filament::callout
            color="warning"
            icon="heroicon-o-lock-closed"
            heading="Nusuk has nothing recorded for this departure"
            :description="'No '.implode(' or ', $missingPrerequisites).' is recorded, so no permit can be requested for anybody on it. That is set on the departure itself.'"
        />
    @endif
</div>
