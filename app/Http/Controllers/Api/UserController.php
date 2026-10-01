<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function index()
    {
        return User::orderBy('name')->get(['id', 'name', 'email', 'role', 'phone', 'created_at']);
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->isAdmin(), 403, 'Huna ruhusa ya kuongeza watumiaji.');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:30'],
            'role' => ['required', 'in:admin,clerk'],
            'password' => ['required', 'string', 'min:8'],
        ], ['email.unique' => 'Barua pepe hii tayari inatumika.']);

        return response()->json(User::create($data)->only(['id', 'name', 'email', 'role', 'phone']), 201);
    }

    public function destroy(Request $request, User $user)
    {
        abort_unless($request->user()->isAdmin(), 403, 'Huna ruhusa ya kufuta watumiaji.');
        abort_if($user->is($request->user()), 422, 'Huwezi kujifuta mwenyewe.');

        $user->tokens()->delete();
        $user->delete();

        return ['message' => 'Mtumiaji amefutwa.'];
    }
}
