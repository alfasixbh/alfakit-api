<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitApi\Tests\Fixtures\Models;

use AlfaSix\Alfakit\Tests\Fixtures\Models\TenantUser;

class ApiUser extends TenantUser
{
    protected $table = 'users';

    protected function casts(): array
    {
        return [...parent::casts(), 'api_admin' => 'boolean'];
    }
}
