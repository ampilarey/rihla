<?php

namespace App\Policies;

use App\Policies\Concerns\ChecksPermissions;

class ArticlePolicy
{
    use ChecksPermissions;

    protected function resource(): string
    {
        return 'article';
    }
}
