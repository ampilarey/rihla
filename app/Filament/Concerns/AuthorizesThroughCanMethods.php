<?php

namespace App\Filament\Concerns;

use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * Make a resource's create, edit and delete buttons obey its own
 * `canCreate()`, `canEdit()` and `canDelete()`.
 *
 * Filament 5's resource pages authorize a `CreateAction` or `EditAction`
 * through `getCreateAuthorizationResponse()` and friends, which consult the
 * model's policy and never call an overridden `canCreate()`. A resource
 * gated by permission name with no policy behind it therefore showed — and
 * ran — "Add a boat" for Booking Staff, who may not manage the timetable,
 * and would have for an operator's crew. Nothing failed: the screen looked
 * right to the people allowed to use it.
 *
 * Only for a resource that overrides all three `can*` methods: Filament's
 * defaults call back into these responses, so using this without them
 * would recurse.
 */
trait AuthorizesThroughCanMethods
{
    public static function getCreateAuthorizationResponse(): Response
    {
        return static::canCreate() ? Response::allow() : Response::deny();
    }

    public static function getEditAuthorizationResponse(Model $record): Response
    {
        return static::canEdit($record) ? Response::allow() : Response::deny();
    }

    public static function getDeleteAuthorizationResponse(Model $record): Response
    {
        return static::canDelete($record) ? Response::allow() : Response::deny();
    }
}
