{{-- One passenger row on the booking form; $i is its index. --}}
    <div class="grid items-end gap-3 sm:grid-cols-4">
        <div class="sm:col-span-3">
            <label for="passenger-{{ $i }}" class="mb-1 block text-sm font-medium text-ink">{{ __('messages.Passenger :number, full name', ['number' => $i + 1]) }}</label>
            <input id="passenger-{{ $i }}" name="passengers[{{ $i }}][full_name]" type="text" maxlength="120" dir="auto"
                   value="{{ old('passengers.'.$i.'.full_name') }}" @if($i === 0) required @endif
                   class="w-full rounded-lg border border-gray-500 px-3 py-2 text-ink">
        </div>
        <label class="inline-flex items-center gap-2 pb-2 text-sm text-ink">
            <input type="checkbox" name="passengers[{{ $i }}][is_child]" value="1" @checked(old('passengers.'.$i.'.is_child'))>
            {{ __('messages.Child') }}
        </label>
    </div>
