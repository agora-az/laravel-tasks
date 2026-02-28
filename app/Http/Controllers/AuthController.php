<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class AuthController extends Controller
{
    /**
     * Show the login form
     */
    public function showLogin()
    {
        return view('auth.login');
    }

    /**
     * Handle login request
     */
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        // Hardcoded credentials
        $validUsername = 'agora-admin';
        $validPassword = '@g0r@2026';

        if ($credentials['username'] === $validUsername && $credentials['password'] === $validPassword) {
            // Store authentication in session
            session(['authenticated' => true, 'user' => $validUsername]);
            
            return redirect()->route('dashboard')->with('success', 'Welcome back!');
        }

        return back()->withErrors(['credentials' => 'Invalid credentials'])->withInput();
    }

    /**
     * Handle logout request
     */
    public function logout()
    {
        session()->flush();
        return redirect()->route('login')->with('success', 'You have been logged out.');
    }
}
