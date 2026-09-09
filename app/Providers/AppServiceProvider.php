<?php

namespace App\Providers;

use App\Models\ActivityLog;
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
        // Throttle OTP sends by phone number, not IP — a whole campus or office
        // can sit behind one NAT address, so an IP limit would lock them out of
        // each other's registrations. Falls back to IP if no phone was sent.
        RateLimiter::for('otp-send', function (Request $request) {
            $key = preg_replace('/[^0-9+]/', '', (string) $request->input('phone')) ?: $request->ip();

            return Limit::perMinute(4)->by($key);
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
