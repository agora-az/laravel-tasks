<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\UserImpersonation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class UserImpersonationController extends Controller
{
    public function update(Request $request, UserImpersonation $impersonation): RedirectResponse
    {
        abort_unless($impersonation->canManage($request), 403);

        $validated = $request->validate([
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);
        $userId = isset($validated['user_id']) ? (int) $validated['user_id'] : 0;
        if ($userId === 0) {
            $impersonation->stop($request);

            return redirect()
                ->route('dashboard')
                ->with('success', 'User impersonation stopped.');
        }

        $target = User::query()->findOrFail($userId);
        $impersonation->start($request, $target);

        return redirect()
            ->route('dashboard')
            ->with('success', "You are now viewing the application as {$target->name}.");
    }
}
