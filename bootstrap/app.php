<?php

use App\Http\Middleware\EnsureActiveSubscription;
use App\Http\Middleware\EnsureCompanySetup;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\DemoCap;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\ExpireIdleToken;
use App\Http\Middleware\NotInDemo;
use App\Http\Middleware\SetLocale;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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

        // Even before that: a token idle for 30 minutes is deleted (see ExpireIdleToken for why it must be first).
        $middleware->prepend(ExpireIdleToken::class);

        $middleware->alias([
            'role' => EnsureRole::class,
            'permission' => EnsurePermission::class,
            'subscription.active' => EnsureActiveSubscription::class,
            'company.setup' => EnsureCompanySetup::class,
            'not.demo' => NotInDemo::class,
            'demo.cap' => DemoCap::class,
        ]);

        // Who may call it is decided before the record in the URL is looked up. Otherwise someone without the role
        // gets 404 for a missing record and 403 for an existing one, and can tell which records exist.
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: EnsureRole::class);
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: EnsurePermission::class);
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

        // In production an answer never describes the code: no model class names ("No query results for model
        // [App\Models\SalesDocument] 5"), no route paths, no list of allowed methods. With APP_DEBUG on, the
        // framework's own detailed answer is kept for developers. 500s already say only "Server Error" when debug is
        // off, and every one of them is written in full to the server log by the default reporter.
        $generic = fn (Request $request): bool => ! config('app.debug') && ($request->is('api/*') || $request->expectsJson());

        $exceptions->render(function (NotFoundHttpException $e, Request $request) use ($generic) {
            return $generic($request)
                ? response()->json(['success' => false, 'message' => __('Not found.'), 'data' => [], 'code' => 'NOT_FOUND'], 404)
                : null;
        });

        $exceptions->render(function (MethodNotAllowedHttpException $e, Request $request) use ($generic) {
            return $generic($request)
                ? response()->json(['success' => false, 'message' => __('This action is not available.'), 'data' => [], 'code' => 'METHOD_NOT_ALLOWED'], 405, $e->getHeaders())
                : null;
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
