<?php

use App\Http\Middleware\EnsureActiveSubscription;
use App\Http\Middleware\EnsureCompanySetup;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\SetLocale;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Global, so it runs before the route's auth middleware and even an "Unauthenticated." answer speaks the right language.
        $middleware->prepend(SetLocale::class);

        $middleware->alias([
            'role' => EnsureRole::class,
            'subscription.active' => EnsureActiveSubscription::class,
            'company.setup' => EnsureCompanySetup::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => __($e->getMessage())], 401);
            }

            return null;
        });

        // The framework writes some messages itself ("Unauthenticated.", "Too Many Attempts."). Put them
        // through the translator so a Spanish request gets a Spanish answer.
        $exceptions->respond(function (Response $response, \Throwable $e, Request $request) {
            if (! $request->is('api/*') || ! $response instanceof JsonResponse) {
                return $response;
            }

            $data = $response->getData(true);

            if (isset($data['message']) && is_string($data['message'])) {
                $translated = __($data['message']);

                if ($translated !== $data['message']) {
                    $data['message'] = $translated;
                    $response->setData($data);
                }
            }

            return $response;
        });
    })->create();
