<?php

namespace App\Providers;

use App\Models\ActivityLog;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
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
