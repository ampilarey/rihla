<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Spatie\Translatable\HasTranslations;

/**
 * A group leader or a scholar who travels with a party.
 *
 * Not a `User`. A scholar who travels once a year has no business holding a
 * login to the admin panel, and a member of staff who administers the site is
 * not necessarily anybody a pilgrim should read about.
 */
class Person extends Model
{
    use HasFactory, HasTranslations;

    /** Laravel would otherwise guess "persons". */
    protected $table = 'people';

    protected $fillable = [
        'user_id',
        'slug', 'name', 'role', 'title', 'bio', 'photo_path',
        'languages', 'groups_led', 'is_published', 'sort_order',
    ];

    /**
     * The staff login this profile belongs to, when it has one.
     *
     * Most people here are not staff — a scholar who writes for the site is
     * a profile with no account — so this is null far more often than not.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The name is deliberately absent. A person's name is their name, and
     * transliterating it into Thaana automatically is how machine-generated
     * Dhivehi got onto this site before.
     *
     * @var array<int, string>
     */
    public array $translatable = ['title', 'bio', 'languages'];

    /** @var array<string, string> */
    protected $casts = [
        'languages' => 'array',
        'groups_led' => 'integer',
        'is_published' => 'boolean',
        'sort_order' => 'integer',
    ];

    public const ROLE_TOUR_LEADER = 'tour_leader';

    public const ROLE_SCHOLAR = 'scholar';

    /** @var list<string> */
    public const ROLES = [self::ROLE_TOUR_LEADER, self::ROLE_SCHOLAR];

    protected static function booted(): void
    {
        static::creating(function (self $person): void {
            if (blank($person->slug)) {
                $person->slug = Str::slug($person->name);
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** @return HasMany<Departure, $this> */
    public function ledDepartures(): HasMany
    {
        return $this->hasMany(Departure::class, 'tour_leader_id');
    }

    /** @return HasMany<Departure, $this> */
    public function scholarDepartures(): HasMany
    {
        return $this->hasMany(Departure::class, 'scholar_id');
    }

    /** @return list<string> */
    public function getLanguageListAttribute(): array
    {
        return is_array($this->languages) ? array_values($this->languages) : [];
    }

    public function getRoleLabelAttribute(): string
    {
        return match ($this->role) {
            self::ROLE_SCHOLAR => (string) __('messages.Scholar'),
            self::ROLE_TOUR_LEADER => (string) __('messages.Group leader'),
            default => $this->role,
        };
    }

    /** @param Builder<$this> $query */
    public function scopePublished($query)
    {
        return $query->where('is_published', true);
    }
}
