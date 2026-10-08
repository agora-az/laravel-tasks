<?php

namespace App\Support;

use App\Models\User;

class ApplicationAdmin
{
    public function allows(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return in_array(strtolower(trim($user->email)), config('app.admin_access_emails', []), true);
    }
}