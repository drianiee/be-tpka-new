<?php

use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\RefreshSessionExpiry;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['role' => EnsureRole::class]);
        $middleware->api(append: [RefreshSessionExpiry::class]);

        // Backend ini murni API dan tidak punya rute "login". Tanpa baris ini Laravel
        // mengalihkan tamu ke route('login') yang tidak ada, sehingga request tanpa
        // header Accept: application/json berbalas 500, bukan 401.
        $middleware->redirectGuestsTo(null);

        // IP gateway nginx->container beda tiap docker network (dev vs prod punya
        // network terpisah, tpka_shared_dev vs tpka_shared_prod), jadi nilainya
        // diambil dari .env (TRUSTED_PROXY_IP), bukan di-hardcode di sini — biar kode
        // ini sama persis dipakai dev maupun prod, cuma beda config. Cek IP gateway
        // aktual per environment: `docker network inspect <nama_network> | grep -i
        // gateway`.
        $middleware->trustProxies(at: [env('TRUSTED_PROXY_IP', '127.0.0.1')]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
