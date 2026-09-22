{{-- Floating action buttons.

     Two buttons: message us on WhatsApp, or call. The WhatsApp Business
     catalog link has gone — the site carries the trips now, so sending people
     back into a chat app to browse them was sending them the wrong way.

     WhatsApp gets its own mark on its own green (#25D366), because a
     recoloured WhatsApp glyph is a worse button: people recognise that green
     circle without reading anything. Noted honestly: white on #25D366 is
     1.98:1, under the 3:1 that icons should meet. That is WhatsApp's own
     brand pairing, not a choice made here, and the alternative is their
     darker official green #128C7E at 4.14:1.

     Call keeps the wine fill with a cream handset, solid rather than a
     hairline outline so it reads as a telephone at 28px.

     These are fixed, so they pass over every section of every page, and the
     fill and the ring cover different grounds:

       on cream   wine fill  7.64:1   (the ring is invisible here)
       on wine    cream ring 7.64:1   (the fill is invisible here)
       on ink     cream ring 13.77:1

     Neither alone is enough, which is how the buttons came to be floating
     glyphs with no circle on the wine hero.

     Icons are 28px in a 56px circle; 24px left them looking lost, and the
     outline weight is 1.75 so they sit beside the solid WhatsApp mark without
     looking thinner than it. --}}
{{-- Side by side on a phone, stacked on desktop. As a column the two
     buttons occupy the bottom 140px of a phone viewport, and the footer
     has to reserve every pixel of that so the copyright is not sat on —
     which read as the page ending into a slab of nothing, because only
     the right edge of that slab had anything in it. In a row the stack
     is 72px and the reserved space is mostly the buttons themselves.
     flex-row-reverse keeps WhatsApp in the corner the thumb reaches.
     FloatingButtonClearanceTest reads this line to derive the footer's
     padding, direction included. --}}
<div class="fixed right-4 bottom-4 md:right-6 md:bottom-6 z-50 flex flex-row-reverse gap-3 md:flex-col">
    <!-- WhatsApp Button -->
    <a href="{{ \App\Support\Contact::whatsappUrl() }}"
       target="_blank" 
       rel="noopener"
       class="bg-whatsapp hover:brightness-95 text-white shadow-soft ring-2 ring-cream w-14 h-14 rounded-full flex items-center justify-center transition-all duration-200 transform hover:scale-105"
       aria-label="{{ __('messages.cta_whatsapp') }}"
       title="{{ __('messages.cta_whatsapp') }}">
        <svg aria-hidden="true" focusable="false" class="w-7 h-7" fill="currentColor" viewBox="0 0 24 24">
            <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893A11.821 11.821 0 0020.885 3.488"/>
        </svg>
    </a>

    <!-- Call Button -->
    <a href="{{ \App\Support\Contact::telUrl() }}"
       class="bg-wine-500 hover:bg-wine-600 text-cream shadow-soft ring-2 ring-cream w-14 h-14 rounded-full flex items-center justify-center transition-all duration-200 transform hover:scale-105"
       aria-label="{{ __('Call us') }}"
       title="{{ __('Call us') }}">
        <svg aria-hidden="true" focusable="false" class="w-7 h-7" fill="currentColor" viewBox="0 0 20 20">
            <path d="M2 3a1 1 0 011-1h2.153a1 1 0 01.986.836l.74 4.435a1 1 0 01-.54 1.06l-1.548.773a11.037 11.037 0 006.105 6.105l.774-1.548a1 1 0 011.059-.54l4.435.74a1 1 0 01.836.986V17a1 1 0 01-1 1h-2C7.82 18 2 12.18 2 5V3z"/>
        </svg>
    </a>

</div>
