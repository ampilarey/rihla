{{--
    Whether this page is working from a local copy, and whether anything is
    still waiting to be sent.

    Hidden until the script says otherwise, so a page rendered by the server
    — which by definition had a connection — never flashes an offline
    warning on the way in.
--}}
<div
    data-leader-status
    hidden
    class="card mb-6 border-s-4 border-s-gold"
    role="status"
    aria-live="polite"
>
    <p class="font-medium text-ink" data-leader-status-headline></p>
    <p class="text-sm text-ink-muted" data-leader-status-detail></p>
</div>
