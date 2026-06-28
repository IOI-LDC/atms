<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Trust the Cloudflare -> Caddy -> nginx (Docker) -> PHP-FPM proxy chain.
        // Without this, request()->ip() (used for rate-limit keys + audit logs),
        // request()->isSecure(), and the session/XSRF cookie "Secure" attribute
        // are all wrong behind the proxies, which breaks cookie-based SPA auth.
        // Safe because the only public ingress is Caddy; the container's :8080
        // is bound to 127.0.0.1 on the host and not exposed publicly.
        $middleware->trustProxies(at: '*');

        // Makes first-party SPA requests stateful (session + CSRF) via Sanctum.
        // EnsureFrontendRequestsAreStateful injects StartSession (+ cookies/CSRF)
        // for requests whose Origin/Referer matches sanctum.stateful, so the API
        // auth routes (login/logout/me) get a session exactly once per request.
        // /sanctum/csrf-cookie already gets StartSession from the web group, so
        // NO global StartSession append is added — that would double-run it and
        // race the shared SessionManager, producing un-persisted session IDs.
        $middleware->statefulApi();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
