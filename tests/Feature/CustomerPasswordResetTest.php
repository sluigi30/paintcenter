<?php

namespace Tests\Feature;

use App\Models\OtpVerification;
use App\Models\SmsLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Password recovery for customers, and the password change behind a login.
 *
 * Customers had NO recovery on any channel: a forgotten password locked the
 * account permanently. The flow added here rides the phone-OTP machinery the
 * registration wizard already uses, and the thing most worth locking down is
 * the shared users table — a reset written for shoppers must never become a
 * second way into /admin or /driver.
 */
class CustomerPasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The gateway is an HTTP call; point it somewhere fake so a send
        // succeeds without a SIM. With no URL at all SmsService records a
        // failure outside local, and every send here would answer 502.
        config(['services.sms_gateway.url' => 'http://sms-gateway.test/message']);
        Http::fake(['sms-gateway.test/*' => Http::response(['id' => 'queued'], 200)]);
    }

    private function customer(array $attributes = []): User
    {
        return User::create(array_merge([
            'role'       => 'customer',
            'first_name' => 'Juan',
            'last_name'  => 'Dela Cruz',
            'email'      => 'juan@example.test',
            'phone'      => '09171234233',
            'password'   => Hash::make('old-password'),
        ], $attributes));
    }

    /** The code as the customer received it — read back out of the SMS log. */
    private function textedCode(): string
    {
        $message = SmsLog::latest('id')->firstOrFail()->message_content;

        preg_match('/\b(\d{6})\b/', $message, $m);

        return $m[1];
    }

    /**
     * Sanctum's guard is resolved once per application instance, and the test
     * app is NOT rebuilt between calls — so a token that authenticated earlier
     * in the same test keeps answering 200 after its row is deleted. Anything
     * asserting that a token died has to drop the cached guard first.
     */
    private function forgetAuth(): void
    {
        $this->app['auth']->forgetGuards();
    }

    /** Walk the whole flow up to (not including) the new password. */
    private function verifiedCodeFor(User $user): void
    {
        $this->postJson('/api/auth/forgot-password', ['email' => $user->email])
            ->assertOk();

        $this->postJson('/api/auth/verify-reset-code', [
            'email' => $user->email,
            'code'  => $this->textedCode(),
        ])->assertOk();
    }

    public function test_forgot_password_texts_a_code_and_names_the_number_only_in_masked_form(): void
    {
        $user = $this->customer();

        $response = $this->postJson('/api/auth/forgot-password', ['email' => $user->email]);

        $response->assertOk()
            ->assertJsonPath('phone_hint', '0917 ••• 233')
            ->assertJsonPath('cooldown', 60);

        // The real number never travels to the app.
        $this->assertStringNotContainsString('09171234233', $response->getContent());

        $this->assertDatabaseHas('otp_verifications', ['phone' => '09171234233']);
        $this->assertStringContainsString($this->textedCode(), SmsLog::latest('id')->first()->message_content);
    }

    public function test_the_email_is_matched_however_the_phone_was_typed(): void
    {
        $user = $this->customer(['phone' => '+639171234233']);

        $this->postJson('/api/auth/forgot-password', ['email' => $user->email])->assertOk();

        // Stored canonically, so verify and reset find the same row.
        $this->assertDatabaseHas('otp_verifications', ['phone' => '09171234233']);
    }

    public function test_asking_twice_in_a_minute_still_says_which_handset_to_look_at(): void
    {
        $user = $this->customer();

        $this->postJson('/api/auth/forgot-password', ['email' => $user->email])->assertOk();

        // A code is already in flight, so the app sends them to the code screen
        // either way — which needs the masked number the cooldown reply carries.
        $this->postJson('/api/auth/forgot-password', ['email' => $user->email])
            ->assertStatus(429)
            ->assertJsonPath('phone_hint', '0917 ••• 233')
            ->assertJsonPath('message', 'Please wait a moment before requesting another code.');

        $this->assertSame(1, SmsLog::count());
    }

    public function test_staff_cannot_reset_through_the_customer_flow(): void
    {
        $customerMessage = null;

        foreach (['admin', 'super_admin', 'driver'] as $role) {
            $staff = User::create([
                'role'       => $role,
                'first_name' => 'Store',
                'last_name'  => ucfirst($role),
                'email'      => "{$role}@example.test",
                'phone'      => '09179999999',
                'password'   => Hash::make('secret-password'),
            ]);

            $response = $this->postJson('/api/auth/forgot-password', ['email' => $staff->email]);

            $response->assertStatus(422);
            $customerMessage ??= $response->json('errors.email.0');

            // Identical wording to an address with no account at all, so the
            // endpoint cannot be used to find out who works here.
            $this->assertSame($customerMessage, $response->json('errors.email.0'));
        }

        $stranger = $this->postJson('/api/auth/forgot-password', ['email' => 'nobody@example.test']);
        $stranger->assertStatus(422);
        $this->assertSame($customerMessage, $stranger->json('errors.email.0'));

        // Nothing was sent and no code exists to be guessed at.
        $this->assertSame(0, OtpVerification::count());
        $this->assertSame(0, SmsLog::count());
    }

    public function test_an_archived_customer_is_refused(): void
    {
        $user = $this->customer(['is_archived' => true]);

        $this->postJson('/api/auth/forgot-password', ['email' => $user->email])
            ->assertStatus(422);

        $this->assertSame(0, OtpVerification::count());
    }

    public function test_a_customer_with_no_number_on_file_is_told_where_to_go(): void
    {
        $user = $this->customer(['phone' => null]);

        $response = $this->postJson('/api/auth/forgot-password', ['email' => $user->email]);

        $response->assertStatus(422);
        $this->assertStringContainsString('contact the store', $response->json('errors.email.0'));
        $this->assertSame(0, SmsLog::count());
    }

    public function test_a_verified_code_sets_the_new_password_and_signs_the_customer_in(): void
    {
        $user = $this->customer();
        $this->verifiedCodeFor($user);

        $response = $this->postJson('/api/auth/reset-password', [
            'email'                 => $user->email,
            'password'              => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ]);

        $response->assertOk()->assertJsonStructure(['message', 'user', 'token']);

        $this->assertTrue(Hash::check('brand-new-password', $user->fresh()->password));

        // The returned token works, so the app does not bounce back to login.
        $this->forgetAuth();
        $this->withToken($response->json('token'))
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('id', $user->id);
    }

    public function test_resetting_revokes_the_sessions_that_were_already_open(): void
    {
        $user  = $this->customer();
        $stale = $user->createToken('mobile_app')->plainTextToken;

        $this->verifiedCodeFor($user);

        $this->postJson('/api/auth/reset-password', [
            'email'                 => $user->email,
            'password'              => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertOk();

        // Someone resetting a password may be doing it because another device
        // has the old one.
        $this->forgetAuth();
        $this->withToken($stale)->getJson('/api/auth/me')->assertStatus(401);
    }

    public function test_the_code_is_spent_by_the_reset(): void
    {
        $user = $this->customer();
        $this->verifiedCodeFor($user);

        $payload = [
            'email'                 => $user->email,
            'password'              => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ];

        $this->postJson('/api/auth/reset-password', $payload)->assertOk();

        $this->assertSame(0, OtpVerification::count());

        // One text, one password.
        $this->postJson('/api/auth/reset-password', array_merge($payload, [
            'password'              => 'second-attempt-password',
            'password_confirmation' => 'second-attempt-password',
        ]))->assertStatus(422)->assertJsonValidationErrors('code');

        $this->assertTrue(Hash::check('brand-new-password', $user->fresh()->password));
    }

    public function test_a_password_cannot_be_set_without_verifying_the_code(): void
    {
        $user = $this->customer();

        $this->postJson('/api/auth/forgot-password', ['email' => $user->email])->assertOk();

        // Code texted but never confirmed: the account keeps the old password.
        $this->postJson('/api/auth/reset-password', [
            'email'                 => $user->email,
            'password'              => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertStatus(422)->assertJsonValidationErrors('code');

        $this->assertTrue(Hash::check('old-password', $user->fresh()->password));
    }

    public function test_a_stale_verification_no_longer_opens_the_reset(): void
    {
        $user = $this->customer();
        $this->verifiedCodeFor($user);

        $this->travel(11)->minutes();

        $this->postJson('/api/auth/reset-password', [
            'email'                 => $user->email,
            'password'              => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertStatus(422)->assertJsonValidationErrors('code');
    }

    public function test_a_wrong_code_is_refused_and_counted(): void
    {
        $user = $this->customer();

        $this->postJson('/api/auth/forgot-password', ['email' => $user->email])->assertOk();

        $wrong = $this->textedCode() === '000000' ? '111111' : '000000';

        $this->postJson('/api/auth/verify-reset-code', [
            'email' => $user->email,
            'code'  => $wrong,
        ])->assertStatus(422)->assertJsonValidationErrors('code');

        $otp = OtpVerification::firstOrFail();
        $this->assertSame(1, $otp->attempts);
        $this->assertNull($otp->verified_at);
    }

    public function test_change_password_needs_the_current_one(): void
    {
        $user = $this->customer();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/auth/change-password', [
                'current_password'      => 'not-my-password',
                'password'              => 'brand-new-password',
                'password_confirmation' => 'brand-new-password',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('current_password');

        $this->assertTrue(Hash::check('old-password', $user->fresh()->password));
    }

    public function test_change_password_keeps_this_session_and_drops_the_others(): void
    {
        $user  = $this->customer();
        $mine  = $user->createToken('mobile_app')->plainTextToken;
        $other = $user->createToken('mobile_app')->plainTextToken;

        $this->withToken($mine)
            ->postJson('/api/auth/change-password', [
                'current_password'      => 'old-password',
                'password'              => 'brand-new-password',
                'password_confirmation' => 'brand-new-password',
            ])
            ->assertOk();

        $this->assertTrue(Hash::check('brand-new-password', $user->fresh()->password));

        // The phone in the owner's hand stays signed in; everything else does not.
        $this->forgetAuth();
        $this->withToken($mine)->getJson('/api/auth/me')->assertOk();

        $this->forgetAuth();
        $this->withToken($other)->getJson('/api/auth/me')->assertStatus(401);
    }

    public function test_the_new_password_has_to_be_a_new_password(): void
    {
        $user = $this->customer();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/auth/change-password', [
                'current_password'      => 'old-password',
                'password'              => 'old-password',
                'password_confirmation' => 'old-password',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }
}
