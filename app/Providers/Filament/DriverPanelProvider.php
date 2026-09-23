<?php

namespace App\Providers\Filament;

use App\Filament\Auth\EditProfile;
use App\Filament\Auth\Login;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * The delivery driver's panel.
 *
 * A SEPARATE panel, not a corner of /admin. A driver is not a weaker admin:
 * sharing the admin panel would mean an ever-growing pile of "hide this button
 * / page / field for drivers", and what the driver ends up with is an awkward
 * copy of somebody else's tool. Here they get three screens and nothing else
 * exists to hide.
 *
 * Access is decided by User::canAccessPanel(), which branches on the panel id —
 * an admin cannot enter here either, and that is deliberate. An admin who needs
 * to intervene does it from the Orders table, where the override lives.
 *
 * Resources are discovered from app/Filament/Driver/ only, so nothing in the
 * admin panel's Resources directory can ever appear in this navigation by
 * accident. There is no global search for the same reason.
 */
class DriverPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('driver')
            ->path('driver')
            ->homeUrl('/driver')
            ->brandName('NCM Deliveries')
            ->colors([
                'primary' => Color::hex('#b91c1c'), // same NCM red as the admin panel
                'danger'  => Color::Rose,
            ])
            ->font('Inter')
            // Custom only in that it stops reporting a CORRECT password as wrong
            // when the person is simply at the other panel's door.
            ->login(Login::class)
            // Drivers are staff with a panel login, so the vanilla reset flow
            // applies to them — User::sendPasswordResetNotification() lets a
            // driver's through and still silently drops a customer's.
            ->passwordReset()
            ->profile(EditProfile::class, isSimple: false)
            ->spa()
            ->databaseNotifications()
            ->databaseNotificationsPolling('30s')
            // A driver is holding a phone, one-handed, often outdoors. The
            // sidebar is three items; collapsing it buys nothing and costs a tap.
            ->sidebarFullyCollapsibleOnDesktop()
            ->discoverResources(in: app_path('Filament/Driver/Resources'), for: 'App\\Filament\\Driver\\Resources')
            ->discoverPages(in: app_path('Filament/Driver/Pages'), for: 'App\\Filament\\Driver\\Pages')
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
