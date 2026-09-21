<?php

namespace App\Providers\Filament;

use App\Http\Controllers\ReportExportController;
use App\Http\Controllers\MessageAttachmentController;
use App\Http\Controllers\ReportPrintController;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->homeUrl('/admin')
            ->brandName('NCM Paint Center')
            ->colors([
                'primary' => Color::hex('#b91c1c'), // NCM brand red
                'danger' => Color::Rose, // keep destructive actions distinguishable from the red primary
            ])
            ->font('Inter')
            ->login()
            ->spa()
            ->databaseNotifications()
            ->databaseNotificationsPolling('30s')
            ->sidebarCollapsibleOnDesktop()
            ->globalSearchKeyBindings(['ctrl+k', 'command+k'])
            ->navigationGroups([
                NavigationGroup::make('Store Management')
                    ->icon('heroicon-o-shopping-bag'),
                NavigationGroup::make('Operations')
                    ->icon('heroicon-o-cog-6-tooth'),
                NavigationGroup::make('Administration')
                    ->icon('heroicon-o-shield-check'),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
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
            ])
            /**
             * The printable report is a plain document, not a Livewire page, so
             * it cannot be a Filament Page - but it must sit behind exactly the
             * same gate. Registered here rather than in routes/web.php so it
             * inherits the panel's session middleware and its auth redirect; on
             * the global `auth` middleware a logged-out admin got a 500 for an
             * undefined `login` route, because the panel names its own.
             */
            ->authenticatedRoutes(function (): void {
                Route::get('reports/print', ReportPrintController::class)
                    ->name('reports.print');

                Route::get('reports/export/{section}', ReportExportController::class)
                    ->name('reports.export');

                // Same controller the API serves attachments from, so the
                // panel cannot end up with a softer rule than the app about
                // who may open a customer's photo. Here for the same reason
                // the print route is: it inherits the panel's session
                // middleware and the panel's own login redirect.
                Route::get('messages/attachments/{attachment}', MessageAttachmentController::class)
                    ->name('messages.attachment');
            });
    }
}
