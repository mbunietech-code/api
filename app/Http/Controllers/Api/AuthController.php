<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device' => ['nullable', 'string', 'max:100'],
        ]);

        $user = User::where('email', $data['email'])->first();
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => 'Barua pepe au nenosiri si sahihi.']);
        }

        return [
            'token' => $user->createToken($data['device'] ?? 'michango-app')->plainTextToken,
            'user' => $this->userPayload($user),
        ];
    }

    public function me(Request $request)
    {
        return $this->userPayload($request->user());
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();

        return ['message' => 'Umetoka kwenye mfumo.'];
    }

    public function changePassword(Request $request)
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ], ['current_password.current_password' => 'Nenosiri la sasa si sahihi.']);

        $request->user()->update(['password' => $data['password']]);

        return ['message' => 'Nenosiri limebadilishwa.'];
    }

    private function userPayload(User $user): array
    {
        return $user->only(['id', 'name', 'email', 'role', 'phone']);
    }
}
