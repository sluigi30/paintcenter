<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OtpVerification;
use App\Models\User;
use App\Services\SmsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        // People type mobile numbers with spaces, dashes and brackets. Strip
        // those before validating rather than rejecting a number that is
        // perfectly valid to a human.
        if (is_string($request->input('phone'))) {
            $request->merge([
                'phone' => preg_replace('/[^0-9+]/', '', $request->input('phone')),
            ]);
        }

        $validated = $request->validate([
            'first_name'  => 'required|string|max:255',
            'last_name'   => 'required|string|max:255',
            // Required, not nullable: order updates go out by SMS, and
            // OrderController::store skips sending when phone is null — so a
            // customer without one silently never hears about their order.
            'phone'       => ['required', 'string', 'max:20', 'regex:/^(\+?63|0)9\d{9}$/'],
            'email'       => 'required|email|max:255|unique:users,email',
            'password'    => 'required|string|min:8|confirmed',
            'address'     => 'nullable|string',
        ], [
            'phone.regex' => 'Enter a valid PH mobile number, e.g. 09171234567.',
        ]);

        // Verify-before-create: when OTP is on, the phone must already carry a
        // fresh, verified code (sent via /auth/send-otp, confirmed via
        // /auth/verify-otp) before an account exists. The 10-minute window lets
        // a user finish the wizard after verifying without re-sending. A 422 on
        // `phone` routes the mobile wizard back to the Contact Details step.
        $canonical = SmsService::normalizePhone($validated['phone']);

        if (config('services.otp.enabled')) {
            $verified = OtpVerification::where('phone', $canonical)
                ->whereNotNull('verified_at')
                ->where('verified_at', '>=', now()->subMinutes(10))
                ->exists();

            if (! $verified) {
                // A distinct signal (not a field error): the app routes to the
                // OTP screen on this, sends a code, and retries register once
                // verified. Kept separate from a bad-format phone error so the
                // wizard can tell "verify your number" from "fix your number".
                return response()->json([
                    'message'      => 'Please verify your phone number to continue.',
                    'otp_required' => true,
                ], 422);
            }
        }

        $user = User::create([
            'role'       => 'customer',
            'first_name' => $validated['first_name'],
            'last_name'  => $validated['last_name'],
            'phone'      => $validated['phone'] ?? null,
            'email'      => $validated['email'],
            'password'   => Hash::make($validated['password']),
            'address'    => $validated['address'] ?? null,
        ]);

        // Consume the code so it can't seed a second registration.
        OtpVerification::where('phone', $canonical)->delete();

        $token = $user->createToken('mobile_app')->plainTextToken;

        return response()->json([
            'message' => 'Registration successful.',
            'user'    => $user,
            'token'   => $token,
        ], 201);
    }

    /**
     * Send a verification code to a phone number, ahead of registration.
     *
     * Route-throttled per phone (see the 'otp-send' limiter). A second, tighter
     * per-phone cooldown here stops a rapid re-tap from spending a fresh SMS
     * while the last code is still young.
     */
    public function sendOtp(Request $request, SmsService $sms)
    {
        $request->merge([
            'phone' => preg_replace('/[^0-9+]/', '', (string) $request->input('phone')),
        ]);

        $validated = $request->validate([
            'phone' => ['required', 'string', 'max:20', 'regex:/^(\+?63|0)9\d{9}$/'],
        ], [
            'phone.regex' => 'Enter a valid PH mobile number, e.g. 09171234567.',
        ]);

        $canonical = SmsService::normalizePhone($validated['phone']);

        $recent = OtpVerification::where('phone', $canonical)
            ->where('created_at', '>=', now()->subSeconds(60))
            ->first();

        if ($recent) {
            $elapsed = (int) abs(now()->diffInSeconds($recent->created_at));
            $wait    = max(1, 60 - $elapsed);

            return response()->json([
                'message'  => 'Please wait a moment before requesting another code.',
                'cooldown' => max(1, $wait),
            ], 429);
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        // One row per phone: replace any prior code so created_at tracks the
        // latest send (the cooldown above depends on that).
        OtpVerification::where('phone', $canonical)->delete();
        OtpVerification::create([
            'phone'       => $canonical,
            'code_hash'   => Hash::make($code),
            'expires_at'  => now()->addMinutes(config('services.otp.ttl', 5)),
            'verified_at' => null,
            'attempts'    => 0,
        ]);

        if (! $sms->sendOtp($canonical, $code)) {
            return response()->json([
                'message' => 'We could not send the code right now. Please try again.',
            ], 502);
        }

        return response()->json(array_filter([
            'message'  => 'Verification code sent.',
            'cooldown' => 60,
            // Local-only convenience so the flow is testable without a real SIM.
            'dev_code' => (app()->environment('local') && blank(config('services.sms_gateway.url')))
                ? $code
                : null,
        ], fn ($value) => $value !== null));
    }

    /**
     * Confirm a code. On success the phone is marked verified for the short
     * window register() checks. Wrong guesses are capped so a code can't be
     * brute-forced; an exhausted or expired code forces a fresh send.
     */
    public function verifyOtp(Request $request)
    {
        $request->merge([
            'phone' => preg_replace('/[^0-9+]/', '', (string) $request->input('phone')),
        ]);

        $validated = $request->validate([
            'phone' => ['required', 'string', 'max:20', 'regex:/^(\+?63|0)9\d{9}$/'],
            'code'  => ['required', 'string', 'regex:/^\d{6}$/'],
        ], [
            'phone.regex' => 'Enter a valid PH mobile number, e.g. 09171234567.',
            'code.regex'  => 'Enter the 6-digit code we texted you.',
        ]);

        $canonical = SmsService::normalizePhone($validated['phone']);
        $otp       = OtpVerification::where('phone', $canonical)->first();

        if (! $otp || $otp->expires_at->isPast()) {
            throw ValidationException::withMessages([
                'code' => ['Your code has expired. Please request a new one.'],
            ]);
        }

        if ($otp->attempts >= config('services.otp.max_attempts', 5)) {
            throw ValidationException::withMessages([
                'code' => ['Too many attempts. Please request a new code.'],
            ]);
        }

        if (! Hash::check($validated['code'], $otp->code_hash)) {
            $otp->increment('attempts');

            throw ValidationException::withMessages([
                'code' => ['That code is incorrect.'],
            ]);
        }

        $otp->update(['verified_at' => now()]);

        return response()->json([
            'verified' => true,
            'message'  => 'Phone number verified.',
        ]);
    }

    public function login(Request $request)
    {
        $validated = $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $validated['email'])->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $token = $user->createToken('mobile_app')->plainTextToken;

        return response()->json([
            'message' => 'Login successful.',
            'user'    => $user,
            'token'   => $token,
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out successfully.',
        ]);
    }

    public function me(Request $request)
    {
        return response()->json($request->user());
    }
}