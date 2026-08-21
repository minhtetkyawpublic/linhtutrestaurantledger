<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $request->merge([
            'email' => strtolower(trim((string) $request->input('email'))),
        ]);
        $credentials = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'remember' => ['nullable', 'boolean'],
        ]);

        if (! Auth::attempt([
            'email' => $credentials['email'],
            'password' => $credentials['password'],
        ], $credentials['remember'] ?? false)) {
            throw ValidationException::withMessages([
                'email' => ['Invalid credentials'],
            ]);
        }

        $user = $request->user();

        if ($user->is_disabled) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return response()->json([
                'message' => 'Account disabled',
            ], 403);
        }

        $request->session()->regenerate();

        return response()->json([
            'user' => $user,
            'permissions' => $user->allPermissions()->pluck('name'),
            'csrf_token' => $request->session()->token(),
        ]);
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json([
            'message' => 'Logged out',
            'csrf_token' => $request->session()->token(),
        ]);
    }

    public function session(Request $request)
    {
        $user = $request->user();
        if ($user?->is_disabled) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            $user = null;
        }

        return response()->json([
            'authenticated' => (bool) $user,
            'user' => $user,
            'permissions' => $user ? $user->allPermissions()->pluck('name') : [],
            'csrf_token' => $request->session()->token(),
        ]);
    }

    public function setLocale(Request $request)
    {
        $request->validate([
            'ui_locale' => ['required', 'string', 'in:en,my'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $user->ui_locale = $request->string('ui_locale');
        $user->save();

        return response()->json([
            'message' => 'Locale updated',
            'ui_locale' => $user->ui_locale,
        ]);
    }
}
