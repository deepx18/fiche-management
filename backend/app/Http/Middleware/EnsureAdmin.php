<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Toutes les routes du module exigent un compte actif de rôle "admin".
 * L'application est restreinte aux administrateurs (aucune inscription
 * publique, aucun droit spectateur).
 */
class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $user->role !== 'admin' || ! $user->active) {
            abort(403, 'Accès réservé aux administrateurs actifs.');
        }

        return $next($request);
    }
}
