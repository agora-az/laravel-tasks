<?php

namespace App\Services;

use App\Models\User;
use App\Support\ApplicationAdmin;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class UserImpersonation
{
    private const ORIGINAL_USER_ID = 'impersonation.original_user_id';

    public function __construct(private readonly ApplicationAdmin $applicationAdmin) {}

    public function canManage(Request $request): bool
    {
        if ($this->isActive($request)) {
            return $this->originalAdmin($request) !== null;
        }

        return $this->applicationAdmin->allows($request->user());
    }

    public function isActive(Request $request): bool
    {
        return $request->session()->has(self::ORIGINAL_USER_ID);
    }

    public function selectedUserId(Request $request): ?int
    {
        return $this->isActive($request) ? (int) $request->user()?->id : null;
    }

    /** @return Collection<int, User> */
    public function availableUsers(Request $request): Collection
    {
        if (!$this->canManage($request)) {
            return new Collection();
        }

        return User::query()
            ->orderBy('name')
            ->orderBy('email')
            ->get()
            ->reject(fn(User $user) => $this->applicationAdmin->allows($user))
            ->values();
    }

    public function start(Request $request, User $target): void
    {
        $originalAdmin = $this->isActive($request)
            ? $this->originalAdmin($request)
            : $request->user();
        if (!$this->applicationAdmin->allows($originalAdmin)) {
            throw new AuthorizationException('Only application administrators may impersonate users.');
        }
        if ($this->applicationAdmin->allows($target)) {
            throw ValidationException::withMessages([
                'user_id' => 'Administrator accounts cannot be impersonated.',
            ]);
        }

        $request->session()->put(self::ORIGINAL_USER_ID, $originalAdmin->id);
        Auth::login($target);
        $request->session()->regenerate();

        Log::notice('Application administrator started user impersonation.', [
            'administrator_user_id' => $originalAdmin->id,
            'impersonated_user_id' => $target->id,
        ]);
    }

    public function stop(Request $request): void
    {
        if (!$this->isActive($request)) {
            if (!$this->applicationAdmin->allows($request->user())) {
                throw new AuthorizationException('Only application administrators may manage impersonation.');
            }

            return;
        }

        $originalAdmin = $this->originalAdmin($request);
        if ($originalAdmin === null) {
            throw new AuthorizationException('The original administrator account is no longer available.');
        }
        $impersonatedUserId = $request->user()?->id;

        Auth::login($originalAdmin);
        $request->session()->forget(self::ORIGINAL_USER_ID);
        $request->session()->regenerate();

        Log::notice('Application administrator stopped user impersonation.', [
            'administrator_user_id' => $originalAdmin->id,
            'impersonated_user_id' => $impersonatedUserId,
        ]);
    }

    private function originalAdmin(Request $request): ?User
    {
        $userId = (int) $request->session()->get(self::ORIGINAL_USER_ID, 0);
        if ($userId <= 0) {
            return null;
        }

        $user = User::query()->find($userId);

        return $this->applicationAdmin->allows($user) ? $user : null;
    }
}
