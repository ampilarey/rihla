<?php

namespace App\Filament;

use App\Support\InitialsAvatar;
use Filament\AvatarProviders\Contracts\AvatarProvider;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Filament's avatar provider, drawing the initials locally.
 *
 * The drawing lives in App\Support\InitialsAvatar, because Pulse needs the
 * same thing for the same reason — its default sends a hash of every staff
 * email to gravatar.com. This class is only the adapter to Filament's
 * contract.
 */
class InitialsAvatarProvider implements AvatarProvider
{
    public function get(Model|Authenticatable $record): string
    {
        return InitialsAvatar::forName(Filament::getNameForDefaultAvatar($record));
    }
}
