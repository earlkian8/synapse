<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminLoginRequest;
use App\Http\Requests\Admin\AdminTwoFactorRequest;
use App\Http\Resources\Admin\AdminResource;
use App\Support\Admin\AdminAudit;
use App\Support\Admin\AdminSession;
use App\Support\Admin\AdminSignIn;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Signing in to and out of the admin portal (ADR 0085). The steps themselves
 * live in {@see AdminSignIn}; the token in {@see AdminSession}.
 */
class AdminSessionController extends Controller
{
    public function __construct(
        private readonly AdminSignIn $signIn,
        private readonly AdminSession $session,
    ) {}

    /**
     * Step one: the password. Answers with a challenge for the code, never a session.
     */
    public function login(AdminLoginRequest $request): JsonResponse
    {
        $admin = $this->signIn->verifyPassword((string) $request->input('email'), (string) $request->input('password'));

        return response()->json($this->signIn->begin($admin));
    }

    /**
     * Step two: the authenticator code. Answers with the session.
     */
    public function twoFactor(AdminTwoFactorRequest $request): JsonResponse
    {
        $admin = $this->signIn->complete((string) $request->input('challenge'), (string) $request->input('code'));

        return response()->json($this->session->open($admin, $request));
    }

    /**
     * The signed-in admin, and how long this session has left.
     */
    public function me(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();

        return response()->json([
            'admin' => new AdminResource($request->user()),
            'expires_at' => $token->expires_at?->toIso8601String(),
            'idle_minutes' => (int) config('admin.idle_timeout'),
        ]);
    }

    /**
     * End this session (the token that carried the request).
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        AdminAudit::record('sign_out', 'Signed out of the admin portal');

        return response()->json(['message' => 'Signed out.']);
    }
}
