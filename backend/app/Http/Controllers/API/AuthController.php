<?php

namespace App\Http\Controllers\API;

use App\Actions\Auth\AuthenticateUser;
use App\Actions\Auth\ChangeUserPassword;
use App\Actions\Auth\RegisterFirstAdmin;
use App\Actions\Auth\StopImpersonating;
use App\Actions\Auth\UpdateProfile;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\UpdatePasswordRequest;
use App\Http\Requests\Auth\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public function register(RegisterRequest $request, RegisterFirstAdmin $action): JsonResponse
    {
        $user = $action->execute($request->validated());

        $token = $user->createToken('default', ['*'], $this->tokenExpiry())->plainTextToken;

        $this->establishSession($request, $user);

        return response()->json([
            'user' => UserResource::make($user->load('roles:id,name'))->resolve(),
            'token' => $token,
        ], 201);
    }

    public function login(LoginRequest $request, AuthenticateUser $action): JsonResponse
    {
        $user = $action->execute($request->string('username'), $request->string('password'));

        if (! $user) {
            throw ValidationException::withMessages([
                'username' => [__('auth.invalid_credentials')],
            ]);
        }

        $token = $user->createToken('default', ['*'], $this->tokenExpiry())->plainTextToken;

        $this->establishSession($request, $user);

        return response()->json([
            'user' => UserResource::make($user->load('roles:id,name'))->resolve(),
            'token' => $token,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        // currentAccessToken() is a TransientToken (no delete()) when the
        // request was authenticated via the session cookie rather than a
        // real Bearer token — only revoke when it's an actual DB-backed one.
        if ($request->user()->currentAccessToken() instanceof PersonalAccessToken) {
            $request->user()->currentAccessToken()->delete();
        }

        if ($request->hasSession()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(null, 204);
    }

    /**
     * Also authenticate the "web" session guard, so cookie-based requests
     * from a stateful frontend domain work without needing the Bearer
     * token — session fixation mitigated via regenerate(). Bearer tokens
     * keep working unaffected for non-browser clients (sv-central, etc).
     */
    private function establishSession(Request $request, User $user): void
    {
        if (! $request->hasSession()) {
            return;
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => UserResource::make($request->user()->load('roles:id,name'))->resolve(),
            'impersonated_by' => $this->impersonator($request),
        ]);
    }

    public function stopImpersonating(Request $request, StopImpersonating $action): JsonResponse
    {
        $impersonatorId = session('impersonator_id');

        $admin = $impersonatorId ? User::find($impersonatorId) : null;

        if (! $admin) {
            session()->forget('impersonator_id');

            throw ValidationException::withMessages([
                'session' => [__('auth.not_impersonating')],
            ]);
        }

        $action->execute($request->user(), $admin);

        return response()->json(null, 204);
    }

    /**
     * When the current request is an impersonated session, return the
     * impersonating admin's {id, username} so the frontend can show a
     * banner; otherwise null.
     *
     * @return array{id: int, username: string}|null
     */
    private function impersonator(Request $request): ?array
    {
        $impersonatorId = session('impersonator_id');

        if (! $impersonatorId) {
            return null;
        }

        $admin = User::find($impersonatorId);

        return $admin ? ['id' => $admin->id, 'username' => $admin->username] : null;
    }

    public function updateProfile(UpdateProfileRequest $request, UpdateProfile $action): JsonResponse
    {
        $user = $action->execute($request->user(), $request->validated());

        return response()->json([
            'user' => UserResource::make($user->load('roles:id,name'))->resolve(),
        ]);
    }

    public function updatePassword(UpdatePasswordRequest $request, ChangeUserPassword $action): JsonResponse
    {
        $action->execute($request->user(), $request->validated('password'));

        $token = $request->user()->createToken('default', ['*'], $this->tokenExpiry())->plainTextToken;

        return response()->json(['token' => $token]);
    }

    private function tokenExpiry(): Carbon
    {
        return now()->addDays((int) config('app.token_expiration_days'));
    }
}
