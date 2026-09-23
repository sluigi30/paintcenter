<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OtpVerification;
use App\Models\User;
use App\Services\SmsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Minutes a verified phone stays good for. Long enough to finish the form
     * that follows the code (the register wizard, the new-password screen),
     * short enough that a verified number left on a borrowed phone goes stale.
     */
    private const VERIFIED_WINDOW = 10;

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
                ->where('verified_at', '>=', now()->subMinutes(self::VERIFIED_WINDOW))
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

        return $this->issueCode(SmsService::normalizePhone($validated['phone']), $sms);
    }

    /**
     * Mint a code for a canonical phone, text it, and answer with the resend
     * cooldown. Shared by registration and password reset so the two cannot
     * drift on code length, TTL, the one-row-per-phone rule or the cooldown —
     * reset is the same machinery pointed at an account that already exists.
     *
     * $extra overrides the response payload: its wording, and the masked number
     * the reset screen shows because it is never told the real one.
     */
    private function issueCode(string $canonical, SmsService $sms, array $extra = []): JsonResponse
    {
        $recent = OtpVerification::where('phone', $canonical)
            ->where('created_at', '>=', now()->subSeconds(60))
            ->first();

        if ($recent) {
            $elapsed = (int) abs(now()->diffInSeconds($recent->created_at));
            $wait    = max(1, 60 - $elapsed);

            // The masked number still rides along (the wording does not): a
            // code is already in flight, so the screen that shows which handset
            // it went to is exactly where the customer should be sent.
            return response()->json(array_replace([
                'message'  => 'Please wait a moment before requesting another code.',
                'cooldown' => max(1, $wait),
            ], Arr::except($extra, 'message')), 429);
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

        return response()->json(array_filter(array_replace([
            'message'  => 'Verification code sent.',
            'cooldown' => 60,
            // Local-only convenience so the flow is testable without a real SIM.
            'dev_code' => (app()->environment('local') && blank(config('services.sms_gateway.url')))
                ? $code
                : null,
        ], $extra), fn ($value) => $value !== null));
    }

    /**
     * Check a code against the row held for a phone and, on success, stamp the
     * phone verified for self::VERIFIED_WINDOW. Every refusal throws a 422 on
     * `code`, so both callers report it on the same field.
     */
    private function assertCode(string $canonical, string $code): void
    {
        $otp = OtpVerification::where('phone', $canonical)->first();

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

        if (! Hash::check($code, $otp->code_hash)) {
            $otp->increment('attempts');

            throw ValidationException::withMessages([
                'code' => ['That code is incorrect.'],
            ]);
        }

        $otp->update(['verified_at' => now()]);
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

        $this->assertCode(
            SmsService::normalizePhone($validated['phone']),
            $validated['code'],
        );

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

    // ── Password recovery (customers only) ────────────────────────────

    /**
     * Step 1 of a forgotten password: text a code to the number on the account.
     *
     * The customer types the address they sign in with, NOT their phone. Email
     * is unique, so it names exactly one account — a phone is not unique in this
     * table, and a reset that cannot say whose password it is resetting is not a
     * reset. It also means the phone is never typed by whoever is at the
     * keyboard: the code goes to the number already on file, which is the whole
     * proof the flow rests on. The response carries only a MASKED number, enough
     * to recognise your own handset and not enough to read one off a screen.
     *
     * This discloses that an address has an account — the same thing register()
     * already discloses through `unique:users,email`, so nothing new is leaked.
     */
    public function forgotPassword(Request $request, SmsService $sms)
    {
        $validated = $request->validate([
            'email' => 'required|email',
        ]);

        $user = $this->resettableCustomer($validated['email']);

        return $this->issueCode(SmsService::normalizePhone($user->phone), $sms, [
            'message'    => 'We texted a code to the mobile number on your account.',
            'phone_hint' => SmsService::maskPhone($user->phone),
        ]);
    }

    /**
     * Step 2: confirm the code, before asking for a new password.
     *
     * Deliberately NOT /auth/verify-otp, which is keyed by phone: the app only
     * ever sees a masked number, so it has nothing to send. Same account gate,
     * same code rules — only the way the phone is arrived at differs.
     *
     * Checking the code on its own screen means a wrong digit is reported
     * before the customer has typed a password twice.
     */
    public function verifyResetCode(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'code'  => ['required', 'string', 'regex:/^\d{6}$/'],
        ], [
            'code.regex' => 'Enter the 6-digit code we texted you.',
        ]);

        $user = $this->resettableCustomer($validated['email']);

        $this->assertCode(SmsService::normalizePhone($user->phone), $validated['code']);

        return response()->json([
            'verified' => true,
            'message'  => 'Code verified.',
        ]);
    }

    /**
     * Step 3: set the new password, on the strength of the code just verified.
     *
     * Same verify-before-write shape as register(): the code is not re-sent up
     * here, the freshly stamped verification is. Every existing token is then
     * revoked — someone resetting a password may be doing it BECAUSE another
     * device has it — and a new one issued, so the app lands signed in rather
     * than dropping the customer back at a login form they just proved.
     */
    public function resetPassword(Request $request)
    {
        $validated = $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user      = $this->resettableCustomer($validated['email']);
        $canonical = SmsService::normalizePhone($user->phone);

        $verified = OtpVerification::where('phone', $canonical)
            ->whereNotNull('verified_at')
            ->where('verified_at', '>=', now()->subMinutes(self::VERIFIED_WINDOW))
            ->exists();

        if (! $verified) {
            throw ValidationException::withMessages([
                'code' => ['Please verify the code we texted you before setting a new password.'],
            ]);
        }

        $user->update(['password' => Hash::make($validated['password'])]);

        // Consume the code so one text cannot set two passwords.
        OtpVerification::where('phone', $canonical)->delete();

        $user->tokens()->delete();

        $token = $user->createToken('mobile_app')->plainTextToken;

        return response()->json([
            'message' => 'Your password has been changed.',
            'user'    => $user,
            'token'   => $token,
        ]);
    }

    /**
     * Change the password of the signed-in account.
     *
     * Open to any role that holds an API token, drivers included — proving the
     * current password is proof enough to change your own, and it is no way
     * into somebody else's. The token making the request survives (that phone
     * is in the owner's hand); every other one is dropped, which is what turns
     * "I think someone has my password" into a fix the customer can apply.
     */
    public function changePassword(Request $request)
    {
        $validated = $request->validate([
            'current_password' => 'required|string',
            'password'         => 'required|string|min:8|confirmed|different:current_password',
        ], [
            'password.different' => 'Your new password must be different from your current one.',
        ]);

        $user = $request->user();

        if (! Hash::check($validated['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['That is not your current password.'],
            ]);
        }

        $user->update(['password' => Hash::make($validated['password'])]);

        $current = $request->user()->currentAccessToken();
        $user->tokens()->where('id', '!=', $current->getKey())->delete();

        return response()->json([
            'message' => 'Your password has been changed.',
        ]);
    }

    /**
     * The one account gate every reset step goes through.
     *
     * Staff are refused with the SAME words as an address with no account at
     * all. The users table is shared, so a recovery flow written for shoppers
     * is a second door into /admin and /driver unless it is shut here — staff
     * passwords move through Laravel's broker at the panel, which
     * User::sendPasswordResetNotification already restricts to them. Answering
     * identically also means the endpoint cannot be used to find out which
     * addresses belong to staff.
     */
    private function resettableCustomer(string $email): User
    {
        $user = User::where('email', $email)->first();

        if (! $user || $user->isStaff() || $user->is_archived) {
            throw ValidationException::withMessages([
                'email' => ['We could not find a customer account with that email address.'],
            ]);
        }

        // Accounts predating the phone requirement can still have none, and SMS
        // is the only recovery channel there is. Say so plainly rather than
        // texting into the void.
        if (blank($user->phone)) {
            throw ValidationException::withMessages([
                'email' => ['That account has no mobile number on file, so we cannot text a code. Please contact the store.'],
            ]);
        }

        return $user;
    }
}