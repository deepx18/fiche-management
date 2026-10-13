<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Gestion des administrateurs (réservée aux administrateurs) : liste,
 * création, mise à jour (nom, email, mot de passe, activation) et
 * suppression, avec protection du dernier administrateur actif.
 */
class AdminController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = User::query()->orderBy('id');

        if ($search = trim((string) $request->query('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $admins = $query->paginate(min(100, max(1, (int) $request->query('per_page', 50))));

        return response()->json([
            'data' => collect($admins->items())->map(fn (User $u) => $u->toSessionArray()),
            'meta' => ['current_page' => $admins->currentPage(), 'last_page' => $admins->lastPage(), 'total' => $admins->total(), 'per_page' => $admins->perPage()],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $admin = User::create([
            'name' => $data['name'],
            'email' => mb_strtolower(trim($data['email'])),
            'password' => $data['password'],
            'role' => 'admin',
            'active' => true,
        ]);

        return response()->json(['data' => $admin->toSessionArray()], 201);
    }

    public function update(Request $request, User $admin): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($admin->id)],
            'active' => ['sometimes', 'boolean'],
            'password' => ['sometimes', 'nullable', 'string', 'min:8', 'confirmed'],
        ]);

        // Le dernier administrateur actif ne peut être désactivé.
        if (array_key_exists('active', $data) && $data['active'] === false) {
            self::assertNotLastActiveAdmin($admin, 'Impossible de désactiver le dernier administrateur actif.');
        }

        // La suspension d'un compte révoque ses tokens d'accès.
        if (($data['active'] ?? true) === false) {
            $admin->tokens()->delete();
        }

        $admin->fill(collect($data)->only(['name', 'email', 'active'])->all());
        if ($data['email'] ?? null) {
            $admin->email = mb_strtolower(trim($admin->email));
        }
        if ($data['password'] ?? null) {
            $admin->password = $data['password']; // cast hashed
        }
        $admin->save();

        return response()->json(['data' => $admin->toSessionArray()]);
    }

    public function destroy(Request $request, User $admin): JsonResponse
    {
        self::assertNotLastActiveAdmin($admin, 'Impossible de supprimer le dernier administrateur actif.');

        // Prudence : ne pas se supprimer soi-même.
        if ($request->user()->id === $admin->id) {
            throw ValidationException::withMessages([
                'admin' => ['Vous ne pouvez pas supprimer votre propre compte.'],
            ]);
        }

        $admin->tokens()->delete();
        $admin->delete();

        return response()->json(['message' => 'Administrateur supprimé.']);
    }

    /**
     * Refuse l'opération si l'administrateur est actif, de rôle "admin", et
     * qu'aucun autre administrateur actif n'existe.
     */
    private static function assertNotLastActiveAdmin(User $admin, string $message): void
    {
        $others = User::where('active', true)
            ->where('role', 'admin')
            ->where('id', '!=', $admin->id)
            ->count();

        if ($admin->role === 'admin' && $admin->active && $others === 0) {
            throw ValidationException::withMessages(['admin' => [$message]]);
        }
    }
}
