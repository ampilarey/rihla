<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * An inhabited island, by atoll (companies plan, Phase 3d, from Hijaz).
 *
 * Reference data shared by every company, so it carries no `company_id`:
 * Kulhudhuffushi is the same island whoever's customer lives there. An
 * island is switched off rather than deleted, because customers and
 * travellers point at it by a plain column with no foreign key.
 *
 * @property string $atoll_code
 * @property string $atoll_name
 * @property string $name
 * @property string|null $name_dv
 * @property bool $is_active
 */
class Island extends Model
{
    protected $fillable = ['atoll_code', 'atoll_name', 'name', 'name_dv', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /** "HDh. Kulhudhuffushi" — how an address writes it. */
    public function label(): string
    {
        return $this->atoll_code.'. '.$this->name;
    }
}
