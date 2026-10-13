<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Authentification par tokens Sanctum (application à frontends séparés).
 * Aucune inscription publique : seuls les comptes créés par un administrateur
 * ou la commande artisan admin:create existent.
 */
class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $key = 'login:'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);

            return response()->json([
                'message' => "Trop de tentatives. Réessayez dans {$seconds} secondes.",
            ], 429);
        }

        $user = User::where('email', mb_strtolower(trim($request->email)))->first();

        if (! $user || ! Hash::check($request->password, $user->password) || ! $user->active) {
            RateLimiter::hit($key, 60);

            throw ValidationException::withMessages([
                'email' => ['Identifiants incorrects ou compte suspendu.'],
            ]);
        }

        RateLimiter::clear($key);

        $token = $user->createToken('session')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $user->toSessionArray(),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Déconnecté.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $request->user()->toSessionArray()]);
    }

    /** Changement du mot de passe du compte connecté. */
    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = $request->user();

        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Mot de passe actuel incorrect.'],
            ]);
        }

        $user->update(['password' => $data['password']]);

        return response()->json(['message' => 'Mot de passe modifié.']);
    }
}
