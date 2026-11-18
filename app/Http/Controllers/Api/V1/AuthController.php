<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ForgotPasswordRequest;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Requests\Api\V1\RegisterRequest;
use App\Http\Requests\Api\V1\ResetPasswordRequest;
use App\Http\Resources\V1\UserResource;
use App\Services\Auth\AuthService;
use App\Services\Auth\TokenService;
use App\Services\Auth\VerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Endpoints #1–#10 (backend-handoff D.2–D.6). */
class AuthController extends Controller
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly TokenService $tokens,
        private readonly VerificationService $verifications,
    ) {}

    /** #1 POST /auth/register → 201 challenge */
    public function register(RegisterRequest $request): JsonResponse
    {
        $channel = $request->validated('channel');
        $challenge = $this->auth->startRegistration($channel, (string) $request->validated($channel), $request->validated('password'), $request);

        return $this->data($challenge, 201);
    }

    /** #2 POST /auth/register/verify → 201 auth result */
    public function verifyRegistration(Request $request): JsonResponse
    {
        $data = $request->validate([
            'verification_id' => ['required', 'string', 'max:64'],
            'code' => ['required', 'string', 'digits:'.config('uec.otp.length')],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $result = $this->auth->completeVerification($data['verification_id'], $data['code'], $data['device_name'] ?? null, $request);

        return $this->authResult($result, 201);
    }

    /** #3 POST /auth/otp/resend */
    public function resendOtp(Request $request): JsonResponse
    {
        $data = $request->validate(['verification_id' => ['required', 'string', 'max:64']]);

        return $this->data($this->verifications->resend($data['verification_id'], $request->ip()));
    }

    /** #4 POST /auth/login */
    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->auth->login(
            $request->validated('channel'),
            $request->validated('identifier'),
            $request->validated('password'),
            $request->validated('device_name'),
            $request,
        );

        return $this->authResult($result);
    }

    /** #5 POST /auth/google */
    public function google(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id_token' => ['required', 'string', 'max:4096'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        return $this->authResult($this->auth->loginWithGoogle($data['id_token'], $data['device_name'] ?? null, $request));
    }

    /** #6 POST /auth/refresh → rotated tokens */
    public function refresh(Request $request): JsonResponse
    {
        $data = $request->validate(['refresh_token' => ['required', 'string', 'max:200']]);

        return $this->data($this->tokens->refresh($data['refresh_token'], $request));
    }

    /** #7 POST /auth/logout → 204 */
    public function logout(Request $request): Response
    {
        $data = $request->validate(['refresh_token' => ['nullable', 'string', 'max:200']]);
        $this->tokens->logout($request->user(), $data['refresh_token'] ?? null);

        return $this->noContent();
    }

    /** #8 POST /auth/password/forgot → always 200 with a challenge */
    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        return $this->data($this->auth->forgotPassword($request->validated('channel'), $request->validated('identifier'), $request));
    }

    /** #9 POST /auth/password/verify → reset token */
    public function verifyPasswordReset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'verification_id' => ['required', 'string', 'max:64'],
            'code' => ['required', 'string', 'digits:'.config('uec.otp.length')],
        ]);

        return $this->data(['reset_token' => $this->auth->verifyPasswordReset($data['verification_id'], $data['code'])]);
    }

    /** #10 POST /auth/password/reset → 204 */
    public function resetPassword(ResetPasswordRequest $request): Response
    {
        $this->auth->resetPassword($request->validated('reset_token'), $request->validated('password'));

        return $this->noContent();
    }

    private function authResult(array $result, int $status = 200): JsonResponse
    {
        return $this->data([
            'user' => (new UserResource($result['user']))->resolve(),
            'tokens' => $result['tokens'],
        ], $status);
    }
}
