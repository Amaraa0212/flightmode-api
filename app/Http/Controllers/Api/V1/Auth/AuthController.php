<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\PermissionKey;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /** Issue a Bearer token for the back office, agency portal, DCS and gate app. */
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::query()->with('role')->where('email', $request->string('email'))->first();

        if (! $user || ! Hash::check($request->string('password'), $user->password)) {
            throw ValidationException::withMessages(['email' => 'The e-mail or password is incorrect.']);
        }

        if (! $user->is_active) {
            return response()->json(['message' => 'Your account is disabled.', 'code' => 'account_disabled'], 403);
        }

        $user->forceFill(['last_login_at' => now()])->save();
        $token = $user->createToken($request->input('device_name', 'web'), ['*'])->plainTextToken;

        return response()->json([
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => $this->profile($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->profile($request->user())]);
    }

    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $user = $request->user();
        if (! Hash::check($request->string('current_password'), $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'The current password is incorrect.']);
        }

        $user->update(['password' => $request->string('password')]);
        $user->tokens()->where('id', '!=', $user->currentAccessToken()?->id)->delete();

        return response()->json(['message' => 'Password changed.']);
    }

    /** @return array<string, mixed> */
    private function profile(User $user): array
    {
        $user->loadMissing(['role.permissions', 'airline', 'agency', 'airport']);

        return (new UserResource($user))->resolve() + [
            'permissions' => $user->isSystemAdmin()
                ? array_map(fn ($p) => $p->value, PermissionKey::cases())
                : $user->permissionKeys(),
            'is_system_admin' => $user->isSystemAdmin(),
        ];
    }
}
