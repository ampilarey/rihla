{{--
    The way to a human, on every portal page.

    This operator's customers reach it on WhatsApp, and the portal is a place
    to look things up rather than a replacement for that. Saying so is more
    useful than a support form nobody monitors.
--}}
<p dir="auto" class="text-center text-sm text-ink-muted">
    {{ __('messages.Anything wrong here? Message us and we will sort it out.') }}
    <a class="font-medium text-wine-700 underline"
       href="{{ \App\Support\Contact::whatsappUrl() }}"
       target="_blank" rel="noopener noreferrer">
        {{ __('messages.Message us on WhatsApp') }}
    </a>
</p>
