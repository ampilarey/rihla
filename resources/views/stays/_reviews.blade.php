{{--
    What guests said — §16.11. Visible reviews only (published, not hidden),
    newest first, the host's reply under each. Nothing at all before the
    first one: an empty "0 reviews" block reads as a warning.

    Expects $reviews (a paginator of visible reviews) and $rating
    (['average' => float, 'count' => int] or null).
--}}
@if($rating)
    <section class="mb-10" aria-labelledby="stay-reviews-heading">
        <h2 id="stay-reviews-heading" dir="auto" class="mb-1 text-2xl font-bold text-ink" @isset($headingColour) style="color: {{ $headingColour }};" @endisset>
            {{ __('messages.What guests said') }}
        </h2>
        <p dir="auto" class="mb-4 text-ink-muted">
            <span aria-hidden="true" class="text-gold-600">★</span>
            <span class="font-semibold text-ink">{{ number_format($rating['average'], 1) }}</span>
            · {{ trans_choice('messages.:count review|:count reviews', $rating['count'], ['count' => $rating['count']]) }}
        </p>

        <ul class="space-y-4">
            @foreach($reviews as $review)
                <li class="card">
                    <p class="text-sm text-ink">
                        <span aria-label="{{ trans_choice('messages.:count star|:count stars', $review->rating, ['count' => $review->rating]) }}">
                            <span aria-hidden="true" class="text-gold-600">{{ str_repeat('★', $review->rating) }}</span><span aria-hidden="true" class="text-gray-300">{{ str_repeat('★', 5 - $review->rating) }}</span>
                        </span>
                        <span dir="auto" class="ms-2 font-medium">{{ $review->authorName() }}</span>
                        <span class="text-ink-muted">· {{ $review->submitted_at->isoFormat('MMM YYYY') }}</span>
                    </p>
                    @if($review->body)
                        <p dir="auto" class="mt-2 whitespace-pre-line text-ink">{{ $review->body }}</p>
                    @endif
                    @if($review->host_reply)
                        <div class="mt-3 border-s-4 border-s-gold ps-3">
                            <p dir="auto" class="text-xs font-semibold text-ink-muted">{{ __('messages.The host replied:') }}</p>
                            <p dir="auto" class="whitespace-pre-line text-sm text-ink">{{ $review->host_reply }}</p>
                        </div>
                    @endif
                </li>
            @endforeach
        </ul>

        <div class="mt-4">{{ $reviews->links() }}</div>
    </section>
@endif
