<?php

namespace App\Http\Controllers;

use App\Models\LoginAttempt;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    /**
     * Show the login form
     */
    public function showLogin()
    {
        if (Auth::check()) {
            return redirect()->route('dashboard');
        }

        return view('auth.login');
    }

    /**
     * Handle login request
     */
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string'],
        ]);

        $email = Str::lower(trim($credentials['email']));
        $rateLimitKey = 'application-login:' . hash('sha256', $email . '|' . (string) $request->ip());
        if (RateLimiter::tooManyAttempts($rateLimitKey, 5)) {
            return back()
                ->withErrors([
                    'credentials' => 'Too many sign-in attempts. Please try again in ' . RateLimiter::availableIn($rateLimitKey) . ' seconds.',
                ])
                ->onlyInput('email');
        }

        $user = User::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->first();
        $successful = $user !== null && Hash::check($credentials['password'], $user->password);

        try {
            LoginAttempt::create([
                'user_id' => $user?->id,
                'email' => $email,
                'successful' => $successful,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'attempted_at' => now(),
            ]);
        } catch (\Throwable $exception) {
            Log::warning('Unable to record application login attempt.', [
                'user_id' => $user?->id,
                'successful' => $successful,
                'exception' => $exception->getMessage(),
            ]);
        }

        if (!$successful) {
            RateLimiter::hit($rateLimitKey, 60);

            return back()
                ->withErrors(['credentials' => 'The email address or password is incorrect.'])
                ->onlyInput('email');
        }

        RateLimiter::clear($rateLimitKey);
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard')->with('success', 'Welcome back, ' . $user->name . '!');
    }

    /**
     * Handle logout request
     */
    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('success', 'You have been logged out.');
    }
}
