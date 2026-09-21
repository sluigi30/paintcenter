<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        /*
         * Where an unauthenticated visitor is sent.
         *
         * `null` for the API, so the Authenticate middleware raises the
         * exception instead of building a redirect. Laravel's default only
         * skips the redirect when the request `expectsJson()`, and a photo is
         * fetched by the phone's image component asking for `image/*` - so the
         * default reached for `route('login')`, which this application does not
         * define, and a missing token came back as 500 rather than 401.
         *
         * Everything else goes to the panel's own login. There is no `login`
         * route here; the admin panel names its own.
         */
        $middleware->redirectGuestsTo(fn ($request) => $request->is('api/*')
            ? null
            : route('filament.admin.auth.login'));
    })
    ->withExceptions(function (Exceptions $exceptions) {
        /*
         * Anything under /api answers with JSON, including its failures.
         *
         * Without this, an unauthenticated API request that does not say
         * `Accept: application/json` gets the web behaviour - a redirect to a
         * `login` route this application does not define - and so returns 500
         * instead of 401.
         *
         * It is the attachment route that makes this worth fixing. Every other
         * endpoint is called by fetch(), which asks for JSON; a photo is loaded
         * by the phone's own image component, which asks for `image/*`. An
         * expired token on a photo would otherwise surface as a server error.
         */
        $exceptions->shouldRenderJsonWhen(
            fn ($request, $throwable) => $request->is('api/*') || $request->expectsJson()
        );
    })->create();