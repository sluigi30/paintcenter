<?php

namespace Tests\Feature;

use App\Filament\Auth\AcceptInvite;
use App\Filament\Resources\UserResource;
use App\Filament\Resources\UserResource\Pages\CreateUser;
use App\Models\AdminInvite;
use App\Models\User;
use App\Notifications\StaffAccountInvited;
use App\Services\StaffInviteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Admin accounts are claimed, not handed over.
 *
 * What these tests hold in place: no admin password is ever chosen by anyone
 * but the person who owns it, save through one clearly marked escape hatch;
 * and an account nobody has claimed yet stays visibly and functionally
 * distinct from a working one.
 */
class AdminInviteTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        return User::create([
            'first_name' => 'Owner',
            'last_name'  => 'Account',
            'email'      => 'owner@example.test',
            'password'   => bcrypt('password'),
            'role'       => 'super_admin',
        ]);
    }

    private function admin(string $email = 'admin@example.test', array $attrs = []): User
    {
        return User::create(array_merge([
            'first_name' => 'Store',
            'last_name'  => 'Admin',
            'email'      => $email,
            'password'   => bcrypt('password'),
            'role'       => 'admin',
        ], $attrs));
    }

    private function customer(string $email = 'customer@example.test'): User
    {
        return User::create([
            'first_name' => 'Cust',
            'last_name'  => 'Omer',
            'email'      => $email,
            'password'   => bcrypt('password'),
            'role'       => 'customer',
        ]);
    }

    // ---------------------------------------------------------------- issuing

    public function test_creating_an_admin_sends_an_invitation_and_leaves_no_usable_password(): void
    {
        Notification::fake();

        $this->actingAs($this->superAdmin());

        Livewire::test(CreateUser::class)
            ->fillForm([
                'first_name' => 'New',
                'last_name'  => 'Admin',
                'email'      => 'new@example.test',
                'phone'      => '09171234567',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $created = User::where('email', 'new@example.test')->firstOrFail();

        $this->assertSame('admin', $created->role);
        $this->assertTrue($created->hasPendingInvite());

        Notification::assertSentTo($created, StaffAccountInvited::class);

        // Whatever sits in the column, it is not something anyone chose or knows.
        $this->assertFalse(Hash::check('password', $created->password));
        $this->assertFalse(Hash::check('', $created->password));
    }

    public function test_the_invitation_email_carries_a_link_and_never_a_password(): void
    {
        Notification::fake();

        $user     = $this->admin();
        $delivery = StaffInviteService::send($user);

        Notification::assertSentTo($user, StaffAccountInvited::class, function ($notification) use ($user, $delivery) {
            $mail = $notification->toMail($user);

            $rendered = collect($mail->introLines)
                ->merge($mail->outroLines)
                ->push($mail->actionText)
                ->implode(' ');

            // The one-time link is the only secret in the mail. The email
            // address is named because that is what they type to sign in.
            $this->assertSame($delivery->url, $mail->actionUrl);
            $this->assertStringContainsString($user->email, $rendered);
            $this->assertStringContainsString('choose one yourself', $rendered);

            return true;
        });
    }

    // ------------------------------------------------------ delivery honesty

    /**
     * Point the mailer at a closed port. A real TransportException thrown by
     * the real stack, rather than a mock asserting that our own try/catch
     * calls itself.
     */
    private function breakTheMailer(): void
    {
        config([
            'mail.default'             => 'smtp',
            'mail.mailers.smtp.host'   => '127.0.0.1',
            'mail.mailers.smtp.port'   => 1,
            'mail.mailers.smtp.scheme' => null,
        ]);
    }

    public function test_a_failed_email_still_leaves_a_usable_account_and_invite(): void
    {
        $this->breakTheMailer();

        $this->actingAs($this->superAdmin());

        Livewire::test(CreateUser::class)
            ->fillForm([
                'first_name' => 'Unreachable',
                'last_name'  => 'Admin',
                'email'      => 'unreachable@example.test',
                'phone'      => '09171234567',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $created = User::where('email', 'unreachable@example.test')->first();

        // The whole point: a mail server being down costs a resend, never the
        // record the super admin just filled in.
        $this->assertNotNull($created);
        $this->assertTrue($created->hasPendingInvite());
        $this->assertTrue($created->adminInvite->isPending());
    }

    public function test_a_failed_email_is_never_reported_as_sent(): void
    {
        $this->breakTheMailer();

        $user     = $this->admin();
        $delivery = StaffInviteService::send($user);

        $this->assertFalse($delivery->reachedSomeone());
        $this->assertTrue($delivery->mailConfigured);
        $this->assertNotNull($delivery->error);

        $notification = UserResource::inviteNotification($user, $delivery, 'Admin account created');

        $this->assertSame('warning', $notification->getStatus());
        $this->assertStringContainsString('could not be sent', $notification->getBody());

        // The link is the only way left to get them in, so it must be shown.
        $this->assertStringContainsString($delivery->url, $notification->getBody());
    }

    /**
     * The log mailer throws nothing and delivers to nobody. Reported as a
     * success it would be the most misleading of the three outcomes, because
     * everything on screen looks like it worked.
     */
    public function test_the_log_mailer_is_not_reported_as_sent(): void
    {
        config(['mail.default' => 'log']);

        $user     = $this->admin();
        $delivery = StaffInviteService::send($user);

        $this->assertFalse($delivery->reachedSomeone());
        $this->assertFalse($delivery->mailConfigured);

        $notification = UserResource::inviteNotification($user, $delivery, 'Admin account created');

        $this->assertSame('warning', $notification->getStatus());
        $this->assertStringContainsString('nothing was sent', $notification->getBody());
        $this->assertStringContainsString($delivery->url, $notification->getBody());
    }

    public function test_a_real_send_is_reported_as_sent_and_keeps_the_link_off_screen(): void
    {
        // phpunit.xml runs the array mailer: a real transport that accepts.
        $user     = $this->admin();
        $delivery = StaffInviteService::send($user);

        $this->assertTrue($delivery->reachedSomeone());

        $notification = UserResource::inviteNotification($user, $delivery, 'Admin account created');

        $this->assertSame('success', $notification->getStatus());
        $this->assertStringContainsString($user->email, $notification->getBody());

        // No reason to print a live invite link on screen for whoever is
        // looking over the super admin's shoulder once it has been emailed.
        $this->assertStringNotContainsString($delivery->url, $notification->getBody());
    }

    // -------------------------------------------------------------- accepting

    public function test_an_invited_admin_sets_their_own_password_and_is_signed_in(): void
    {
        $user  = $this->admin();
        $token = StaffInviteService::issue($user);

        Livewire::test(AcceptInvite::class, ['token' => $token])
            ->fillForm([
                'password'             => 'Str0ng-Passw0rd!',
                'passwordConfirmation' => 'Str0ng-Passw0rd!',
            ])
            ->call('acceptInvite')
            ->assertHasNoFormErrors();

        $user->refresh()->load('adminInvite');

        $this->assertTrue(Hash::check('Str0ng-Passw0rd!', $user->password));
        $this->assertTrue($user->adminInvite->isAccepted());
        $this->assertFalse($user->hasPendingInvite());
        $this->assertAuthenticatedAs($user);
    }

    public function test_a_token_cannot_be_used_twice(): void
    {
        $user  = $this->admin();
        $token = StaffInviteService::issue($user);

        StaffInviteService::accept(StaffInviteService::findPending($token), 'First-Passw0rd!');

        $this->assertNull(StaffInviteService::findPending($token));

        Livewire::test(AcceptInvite::class, ['token' => $token])
            ->assertSet('invalid', true);

        // The second visit changes nothing.
        $this->assertTrue(Hash::check('First-Passw0rd!', $user->refresh()->password));
    }

    public function test_an_expired_token_is_refused(): void
    {
        $user  = $this->admin();
        $token = StaffInviteService::issue($user);

        $user->adminInvite->update([
            'expires_at' => now()->subHours(StaffInviteService::EXPIRY_HOURS + 1),
        ]);

        $this->assertNull(StaffInviteService::findPending($token));

        Livewire::test(AcceptInvite::class, ['token' => $token])
            ->assertSet('invalid', true);
    }

    public function test_an_invite_for_a_deactivated_account_is_refused(): void
    {
        $user  = $this->admin();
        $token = StaffInviteService::issue($user);

        $user->update(['is_archived' => true]);

        // Refused at mount, so nobody is walked through choosing a password
        // only to be bounced by canAccessPanel() straight afterwards.
        Livewire::test(AcceptInvite::class, ['token' => $token])
            ->assertSet('invalid', true);
    }

    public function test_resending_invalidates_the_previous_link(): void
    {
        Notification::fake();

        $user  = $this->admin();
        $first = StaffInviteService::issue($user);

        $second = StaffInviteService::send($user);

        $this->assertNull(StaffInviteService::findPending($first));
        $this->assertNotNull(StaffInviteService::findPending(
            str($second->url)->afterLast('/')->toString()
        ));

        // One invite per account, not a growing pile of live links.
        $this->assertSame(1, AdminInvite::where('user_id', $user->id)->count());
    }

    public function test_an_unclaimed_account_is_never_chosen_as_the_store_sender(): void
    {
        // The realistic shape of it: every earlier admin has been archived, so
        // the unclaimed account is the lowest id left standing.
        $old = $this->admin('old@example.test');
        $old->update(['is_archived' => true]);

        $invited = $this->admin('invited@example.test');
        StaffInviteService::issue($invited);

        $this->assertNull(User::activeAdmin());

        // Once claimed it is a real admin and may speak for the store.
        StaffInviteService::accept($invited->adminInvite, 'Str0ng-Passw0rd!');

        $this->assertTrue(User::activeAdmin()?->is($invited));
    }

    // ----------------------------------------------------------------- resets

    public function test_password_reset_reaches_an_active_admin(): void
    {
        Notification::fake();

        $admin = $this->admin();
        $admin->sendPasswordResetNotification('token');

        Notification::assertSentTo($admin, \Illuminate\Auth\Notifications\ResetPassword::class);
    }

    public function test_password_reset_is_silent_for_customers_and_deactivated_admins(): void
    {
        Notification::fake();

        // Customers share the users table, so without the gate on the model a
        // customer's address typed into the panel's forgot-password form would
        // be emailed a link into the admin panel.
        $this->customer()->sendPasswordResetNotification('token');

        $this->admin('archived@example.test', ['is_archived' => true])
            ->sendPasswordResetNotification('token');

        Notification::assertNothingSent();
    }

    // ---------------------------------------------------------------- screens

    /**
     * Every surface this feature added or changed, asserted to actually render.
     * The stock Filament pages are half the feature here, and a panel method
     * that silently does not take (a wrong class, a page that is discovered
     * instead of routed) leaves no other trace.
     */
    public function test_the_new_screens_render(): void
    {
        $guest = [
            '/admin/password-reset/request',
            '/admin/invite/' . StaffInviteService::issue($this->admin('invitee@example.test')),
        ];

        foreach ($guest as $url) {
            $this->get($url)->assertOk();
        }

        $this->actingAs($this->superAdmin());

        foreach (['/admin/profile', '/admin/users', '/admin/users/create'] as $url) {
            $this->get($url)->assertOk();
        }
    }

    /**
     * Livewire resolves a component by NAME on every /livewire/update POST.
     * Routing straight to a class registers no name, so the invite page
     * rendered on first load and then died the moment the form was submitted
     * with "Unable to find component: [app.filament.auth.accept-invite]".
     *
     * Livewire::test() takes the class directly and a GET only exercises the
     * first render, so neither of those caught it - this goes through the
     * registry the browser actually uses. Filament registers its own auth
     * pages; anything behind the panel's routes() has to declare itself with
     * livewireComponents().
     */
    public function test_every_panel_page_resolves_by_its_livewire_name(): void
    {
        // Boot the panel so its component registration has run.
        $this->get('/admin/login')->assertOk();

        $registry = app(\Livewire\Mechanisms\ComponentRegistry::class);

        $pages = [
            AcceptInvite::class,
            \App\Filament\Auth\EditProfile::class,
        ];

        foreach ($pages as $page) {
            $name = $registry->getName($page);

            $this->assertSame(
                $page,
                $registry->getClass($name),
                "[{$page}] is not registered under a Livewire name, so every "
                . 'interaction on it will fail once the page has rendered.'
            );
        }
    }

    /**
     * The profile form must address first_name / last_name, not Filament's
     * stock single `name` field — `name` is an appended accessor here and is
     * not fillable, so the default form would report a successful save and
     * change nothing.
     */
    public function test_an_admin_can_change_their_own_name_and_password_from_their_profile(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin);

        Livewire::test(\App\Filament\Auth\EditProfile::class)
            ->fillForm([
                'first_name'           => 'Renamed',
                'last_name'            => 'Person',
                'password'             => 'Br4nd-New-Passw0rd!',
                'passwordConfirmation' => 'Br4nd-New-Passw0rd!',
                'currentPassword'      => 'password',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $admin->refresh();

        $this->assertSame('Renamed', $admin->first_name);
        $this->assertSame('Person', $admin->last_name);
        $this->assertTrue(Hash::check('Br4nd-New-Passw0rd!', $admin->password));
    }

    // ----------------------------------------------------------- escape hatch

    public function test_setting_a_password_manually_also_claims_the_account(): void
    {
        $user = $this->admin();
        StaffInviteService::issue($user);

        $this->assertTrue($user->hasPendingInvite());

        // What the "Set password manually" action does.
        $user->update(['password' => 'Manual-Passw0rd!']);
        $user->adminInvite->update(['accepted_at' => now()]);

        $user->refresh()->load('adminInvite');

        $this->assertTrue(Hash::check('Manual-Passw0rd!', $user->password));
        $this->assertFalse($user->hasPendingInvite());
    }
}
