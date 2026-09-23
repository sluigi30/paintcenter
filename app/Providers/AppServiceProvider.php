<?php

namespace App\Providers;

use App\Models\ActivityLog;
use Filament\Support\Assets\AlpineComponent;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // The "pick colour from an image" tool on the product form. Registering
        // it here means `php artisan filament:assets` publishes the module to
        // public/js/app/ — that command already runs on every composer install
        // via filament:upgrade, so deploys need no extra step.
        FilamentAsset::register([
            AlpineComponent::make('color-from-image', resource_path('js/filament/color-from-image.js')),
        ], package: 'app');

        // Throttle OTP sends by phone number, not IP — a whole campus or office
        // can sit behind one NAT address, so an IP limit would lock them out of
        // each other's registrations. Falls back to IP if no phone was sent.
        RateLimiter::for('otp-send', function (Request $request) {
            $key = preg_replace('/[^0-9+]/', '', (string) $request->input('phone')) ?: $request->ip();

            return Limit::perMinute(4)->by($key);
        });

        // Same reasoning for password-reset codes, keyed by the email typed —
        // that is what the endpoint resolves the phone from, and an IP limit
        // would let one shared connection lock out a whole household.
        RateLimiter::for('password-reset', function (Request $request) {
            $key = strtolower(trim((string) $request->input('email'))) ?: $request->ip();

            return Limit::perMinute(4)->by('pwreset:' . $key);
        });

        // Record admin / super admin sign-ins in the audit trail so a
        // super admin can see session activity, not just data changes.
        Event::listen(Login::class, function (Login $event) {
            $user = $event->user;

            if (! method_exists($user, 'isAdmin')
                || ! ($user->isAdmin() || $user->isSuperAdmin())) {
                return;
            }

            ActivityLog::create([
                'user_id'       => $user->getKey(),
                'event'         => 'login',
                'subject_type'  => $user::class,
                'subject_id'    => $user->getKey(),
                'subject_label' => method_exists($user, 'activityTitle') ? $user->activityTitle() : null,
                'description'   => $user->name . ' signed in to the admin panel.',
                'ip_address'    => request()->ip(),
                'user_agent'    => request()->userAgent(),
            ]);
        });
    }
}
