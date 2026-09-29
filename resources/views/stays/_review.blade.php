{{--
    A guest's review of their stay — §16.11. The form while there is none;
    the review itself afterwards, with Rihla's reason when it was hidden —
    the author is told, the public never is.

    Expects $stay and $action (where the form posts).
--}}
@php($review = $stay->review)
<section class="card mb-6" aria-labelledby="stay-review-heading">
    <h2 id="stay-review-heading" dir="auto" class="mb-2 text-lg font-bold text-ink">{{ __('messages.How was your stay?') }}</h2>

    @if($review)
        <p dir="auto" class="text-sm text-brand-body">
            {{ __('messages.Thank you — you gave :rating out of 5.', ['rating' => $review->rating]) }}
        </p>
        @if($review->body)
            <blockquote dir="auto" class="mt-2 whitespace-pre-line border-s-4 border-s-gold ps-3 text-sm text-ink">{{ $review->body }}</blockquote>
        @endif
        @if($review->isHidden())
            <p dir="auto" class="mt-3 rounded-xl bg-cream-deep px-4 py-3 text-sm text-ink">
                {{ __('messages.We have not shown this review on the site:') }} {{ $review->hidden_reason }}
            </p>
        @elseif(! $review->isVisible())
            <p dir="auto" class="mt-3 text-sm text-ink-muted">{{ __('messages.It will appear on the guesthouse page shortly.') }}</p>
        @endif
        @if($review->host_reply)
            <p dir="auto" class="mt-3 text-sm text-ink"><strong>{{ __('messages.The host replied:') }}</strong> {{ $review->host_reply }}</p>
        @endif
    @else
        <p dir="auto" class="mb-4 text-sm text-brand-body">{{ __('messages.Tell the next guest what it was like. Your first name is shown with your review.') }}</p>

        @if($errors->any())
            <div role="alert" class="mb-4 rounded-xl border-s-4 border-s-error bg-cream-deep px-4 py-3 text-sm text-ink">
                @foreach($errors->all() as $error)
                    <p dir="auto">{{ $error }}</p>
                @endforeach
            </div>
        @endif

        <form method="POST" action="{{ $action }}" class="space-y-4">
            @csrf
            <fieldset>
                <legend dir="auto" class="mb-2 text-sm font-medium text-ink">{{ __('messages.Overall') }}</legend>
                <div class="flex flex-wrap gap-3">
                    @foreach([5, 4, 3, 2, 1] as $stars)
                        <label class="inline-flex items-center gap-2 text-sm text-ink">
                            <input type="radio" name="rating" value="{{ $stars }}" required @checked((int) old('rating') === $stars)
                                   class="rounded border border-gray-500 text-wine-600 focus:ring-wine-500">
                            <span dir="auto">{{ trans_choice('messages.:count star|:count stars', $stars, ['count' => $stars]) }}</span>
                        </label>
                    @endforeach
                </div>
            </fieldset>

            <div class="grid gap-4 sm:grid-cols-2">
                @foreach(['cleanliness' => __('messages.Cleanliness'), 'accuracy' => __('messages.As described'), 'communication' => __('messages.Communication'), 'value' => __('messages.Value')] as $aspect => $label)
                    <div>
                        <label for="review-{{ $aspect }}" dir="auto" class="mb-1 block text-sm font-medium text-ink">{{ $label }}</label>
                        <select id="review-{{ $aspect }}" name="{{ $aspect }}"
                                class="w-full rounded-lg border border-gray-500 px-3 py-2 text-ink focus:border-wine-500 focus:ring-wine-500">
                            <option value="">{{ __('messages.Skip') }}</option>
                            @foreach([5, 4, 3, 2, 1] as $stars)
                                <option value="{{ $stars }}" @selected((int) old($aspect) === $stars)>{{ $stars }}</option>
                            @endforeach
                        </select>
                    </div>
                @endforeach
            </div>

            <div>
                <label for="review-body" dir="auto" class="mb-1 block text-sm font-medium text-ink">{{ __('messages.In your own words (optional)') }}</label>
                <textarea id="review-body" name="body" rows="4" maxlength="2000" dir="auto"
                          class="w-full rounded-lg border border-gray-500 px-3 py-2 text-ink focus:border-wine-500 focus:ring-wine-500">{{ old('body') }}</textarea>
            </div>

            <button type="submit" class="btn-primary">{{ __('messages.Send my review') }}</button>
        </form>
    @endif
</section>
