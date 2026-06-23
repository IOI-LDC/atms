<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\ActivateUser;
use App\Actions\Auth\ResetUserPassword;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ActivateRequest;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Models\User;
use App\Notifications\PasswordResetNotification;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $key = 'login:'.$request->ip().':'.$request->input('email');

        if (RateLimiter::tooManyAttempts($key, 5)) {
            return response()->json(['message' => 'Too many login attempts.'], 429);
        }

        $user = User::where('email', $request->input('email'))->first();

        if (! $user || ! Hash::check($request->input('password'), $user->password)) {
            RateLimiter::hit($key, 60);

            app(AuditLogger::class)->log('auth.login_failed', null, [], [], ['attempted_email' => $request->input('email')]);

            return response()->json(['message' => 'Invalid credentials.'], 401);
        }

        if (! $user->is_active) {
            app(AuditLogger::class)->log('auth.login_failed', $user, [], [], ['reason' => 'inactive']);

            return response()->json(['message' => 'Account is not active.'], 401);
        }

        RateLimiter::clear($key);

        auth()->login($user);
        $request->session()->regenerate();

        app(AuditLogger::class)->log('auth.login', $user);

        return response()->json(['user' => $user->load('role')]);
    }

    public function logout(Request $request): Response
    {
        $user = auth()->user();

        auth()->guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($user) {
            app(AuditLogger::class)->log('auth.logout', $user);
        }

        return response()->noContent();
    }

    public function me(): JsonResponse
    {
        return response()->json(['user' => auth()->user()->load('role')]);
    }

    public function activate(ActivateRequest $request, ActivateUser $action): JsonResponse
    {
        $action->execute($request->input('token'), $request->input('password'));

        return response()->json(['message' => 'Account activated.']);
    }

    public function forgotPassword(ForgotPasswordRequest $request, ResetUserPassword $action): JsonResponse
    {
        $user = User::where('email', $request->input('email'))->first();

        if ($user) {
            $token = $action->issueToken($user);
            $url = url('/reset-password?token='.$token);
            $user->notify(new PasswordResetNotification($url));
        }

        return response()->json(['message' => 'If the email exists, a reset link has been sent.']);
    }

    public function resetPassword(ResetPasswordRequest $request, ResetUserPassword $action): JsonResponse
    {
        $action->execute($request->input('token'), $request->input('password'));

        return response()->json(['message' => 'Password reset successful.']);
    }
}
