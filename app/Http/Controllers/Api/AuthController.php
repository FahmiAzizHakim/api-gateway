<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Tymon\JWTAuth\Exceptions\JWTException;

/**
 * Sign-in, and the token everything else runs on.
 *
 * The gateway is the only holder of the `users` table, so this is the only
 * place a password is ever checked. The services never see one: they read the
 * caller's identity and website from the token's claims (see
 * App\Models\User::getJWTCustomClaims).
 *
 * The token is a bearer token, not a cookie -- there is no session and no CSRF
 * to think about, and the frontend can live on any origin. Every endpoint but
 * login needs `Authorization: Bearer <token>`.
 */
class AuthController extends ApiController
{
    /**
     * Exchange a password for a token.
     *
     * Rate limited in routes/api.php, because this is the one endpoint where
     * guessing repeatedly is the attack.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $token = auth('api')->attempt($request->credentials());

        if (!$token) {
            // Deliberately the same answer for an unknown email and a wrong
            // password, so this cannot be used to enumerate accounts.
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        $user = auth('api')->user();

        // A disabled account can still have the right password. Checking after
        // the attempt (rather than folding is_active into the credentials)
        // keeps "wrong password" and "account disabled" as distinct answers.
        if (!$user->is_active) {
            auth('api')->invalidate(true);

            return response()->json(['message' => 'This account is not active'], 403);
        }

        return $this->tokenResponse($token, 'Signed in');
    }

    /**
     * Who the current token belongs to. The frontend calls this on reload to
     * restore a session without storing anything but the token.
     */
    public function me(): JsonResponse
    {
        return response()->json(['data' => new UserResource(auth('api')->user())]);
    }

    /**
     * Trade a still-valid token for a fresh one, so a working session is not
     * interrupted by the TTL. The old token is blacklisted.
     */
    public function refresh(): JsonResponse
    {
        try {
            $token = auth('api')->refresh();
        } catch (JWTException $e) {
            return response()->json(['message' => 'Token cannot be refreshed'], 401);
        }

        return $this->tokenResponse($token, 'Token refreshed');
    }

    /**
     * Sign out. The token is blacklisted, so it stops working immediately
     * rather than lingering until it expires (config/jwt.php:
     * blacklist_enabled).
     */
    public function logout(): JsonResponse
    {
        auth('api')->logout(true);

        return response()->json(['message' => 'Signed out']);
    }

    /**
     * The one shape a token comes back in, so login and refresh agree.
     */
    protected function tokenResponse(string $token, string $message): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'data'    => [
                'access_token' => $token,
                'token_type'   => 'bearer',
                // Seconds, not minutes: what a client needs to time a refresh.
                'expires_in'   => auth('api')->factory()->getTTL() * 60,
                'user'         => new UserResource(auth('api')->user()),
            ],
        ]);
    }
}
