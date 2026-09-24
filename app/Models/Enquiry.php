<?php

namespace App\Models;

use App\Services\Stays\LostStayFollowUp;
use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * Somebody who asked — §8.1's minimal CRM.
 *
 * The plan's first version is "every enquiry becomes a tracked lead with an
 * owner and a next action", and this is built around those two things
 * rather than around a pipeline nobody has described.
 *
 * An enquiry is not a customer. It becomes one when somebody books;
 * creating a {@see Customer} for everybody who asked a price once fills that
 * table with people who never travelled, and the import work has just
 * finished proving what that costs.
 */
class Enquiry extends Model
{
    use HasFactory;

    protected $fillable = [
        'source', 'name', 'phone', 'email', 'message',
        'package_id', 'departure_id', 'party_size',
    ];

    /**
     * `stay_id` and `property_id` are deliberately **not** fillable.
     *
     * They are written by {@see LostStayFollowUp}
     * and by nothing else. A public enquiry form that could set them would
     * let somebody attach their own message to a stranger's stay.
     */
    protected $casts = [
        'next_action_at' => 'date',
        'closed_at' => 'datetime',
        'party_size' => 'integer',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => self::NEW];

    public const WEB = 'web';

    public const WHATSAPP = 'whatsapp';

    public const PHONE = 'phone';

    public const WALK_IN = 'walk_in';

    /** @var list<string> */
    public const SOURCES = [self::WEB, self::WHATSAPP, self::PHONE, self::WALK_IN];

    /** Nobody has looked at it. */
    public const NEW = 'new';

    /** Somebody owns it and is doing something about it. */
    public const WORKING = 'working';

    /** It became a booking. */
    public const WON = 'won';

    /** It did not, and somebody said why. */
    public const LOST = 'lost';

    /**
     * Four states, and that is deliberate.
     *
     * A five-stage qualification funnel is a description of how a sales team
     * works, and nobody has described this one. These four can be observed
     * without asking anybody.
     *
     * @var list<string>
     */
    public const STATUSES = [self::NEW, self::WORKING, self::WON, self::LOST];

    protected static function booted(): void
    {
        static::created(function (self $enquiry): void {
            if ($enquiry->reference === null) {
                $enquiry->forceFill([
                    'reference' => sprintf(
                        'RIH-E-%s-%s',
                        ($enquiry->created_at ?? now())->format('Y'),
                        str_pad((string) $enquiry->getKey(), 4, '0', STR_PAD_LEFT),
                    ),
                ])->saveQuietly();
            }
        });
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** @return BelongsTo<Package, $this> */
    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    /** @return BelongsTo<Departure, $this> */
    public function departure(): BelongsTo
    {
        return $this->belongsTo(Departure::class);
    }

    /**
     * The stay that fell through and produced this — §15.7.
     *
     * The mirror of {@see booking()}: that records what an enquiry became,
     * this records what it was.
     *
     * @return BelongsTo<Stay, $this>
     */
    public function stay(): BelongsTo
    {
        return $this->belongsTo(Stay::class);
    }

    /** @return BelongsTo<Property, $this> */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /** Did this enquiry come from a guesthouse ask that did not work out? */
    public function isFromALostStay(): bool
    {
        return $this->stay_id !== null;
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * The priced offers sent for this lead — §8.1.
     *
     * Newest first: "what did we quote them?" means the current price,
     * and the older ones are the trail behind it.
     *
     * @return HasMany<Quotation, $this>
     */
    public function quotations(): HasMany
    {
        return $this->hasMany(Quotation::class)->orderByDesc('created_at');
    }

    /** @return MorphMany<CrmTask, $this> */
    public function tasks(): MorphMany
    {
        return $this->morphMany(CrmTask::class, 'about')->orderBy('due_on');
    }

    /**
     * The offer that still stands, if one does.
     *
     * Not simply the newest: a superseded or declined quotation is not an
     * offer, and showing one as the current price is how somebody gets
     * quoted a number that was withdrawn a month ago.
     */
    public function currentQuotation(): ?Quotation
    {
        return $this->quotations()
            ->get()
            ->first(fn (Quotation $quotation): bool => $quotation->isOpen());
    }

    /**
     * Everything that has happened to it, oldest first. Append-only.
     *
     * @return HasMany<EnquiryNote, $this>
     */
    public function notes(): HasMany
    {
        return $this->hasMany(EnquiryNote::class)->orderBy('created_at')->orderBy('id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::NEW, self::WORKING], true);
    }

    /**
     * An enquiry nobody has promised to do anything about.
     *
     * This is the message that sits unanswered in a shared inbox for four
     * days, and finding them is the entire value of the minimal version.
     */
    public function isAdrift(): bool
    {
        return $this->isOpen() && ($this->assigned_to === null || $this->next_action_at === null);
    }

    /** A promise whose date has passed. */
    public function isOverdue(): bool
    {
        return $this->isOpen()
            && $this->next_action_at !== null
            && $this->next_action_at->isPast();
    }

    /** Write a line into the history. */
    public function record(string $body, string $type = EnquiryNote::NOTE, ?User $actor = null): EnquiryNote
    {
        return $this->notes()->create([
            'user_id' => ($actor ?? Auth::user())?->getKey(),
            'type' => $type,
            'body' => $body,
            'created_at' => now(),
        ]);
    }

    /**
     * Customers who might already be this person.
     *
     * Matched on the phone number, normalised the way the import normalises
     * it — `7712345` and `+960 771 2345` are the same person, and a staff
     * member converting an enquiry should not have to notice that.
     *
     * @return Collection<int, Customer>
     */
    public function possibleCustomers(): Collection
    {
        $key = PhoneNumber::key($this->phone);

        if ($key === null) {
            return collect();
        }

        return Customer::query()
            ->get(['id', 'name', 'phone', 'email'])
            ->filter(fn (Customer $customer): bool => PhoneNumber::key($customer->phone) === $key)
            ->values();
    }

    /** @param  Builder<$this>  $query */
    public function scopeOpen($query)
    {
        return $query->whereIn('status', [self::NEW, self::WORKING]);
    }

    /** @param  Builder<$this>  $query */
    public function scopeAdrift($query)
    {
        return $query->open()->where(function ($query): void {
            $query->whereNull('assigned_to')->orWhereNull('next_action_at');
        });
    }

    /** @param  Builder<$this>  $query */
    public function scopeOverdue($query)
    {
        return $query->open()->whereNotNull('next_action_at')->whereDate('next_action_at', '<', now());
    }
}
