{{--
    A red bar across the top of the test site only: test.rihla.mv, which
    updates itself from main once CI passes. It names the commit the site is
    running, so anybody can watch a merge arrive without opening a terminal.
    rihla.mv never shows it, because the rule is the one the deploy webhook
    uses to refuse production (App\Support\TestSite).
--}}
@if (\App\Support\TestSite::is())
    @php($build = \App\Support\TestSite::build())
    <div class="bg-error text-white text-center text-sm font-semibold px-4 py-2" data-test-site-banner>
        TEST SITE · updated automatically from main{{ $build !== null ? ' · build '.$build : '' }}
    </div>
@endif
