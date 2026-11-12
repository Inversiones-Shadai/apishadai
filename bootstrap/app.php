<?php

use App\Exceptions\RepositoryException;
use App\Http\Middleware\DeviceSecurityMiddleware;
use App\Http\Middleware\IdempotencyMiddleware;
use App\Http\Middleware\JWTAuthenticationMiddleware;
use App\Http\Middleware\MatchTokenMiddleware;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        //  web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        //   health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->prepend(SecurityHeaders::class);
        $middleware->append(ThrottleRequests::using('http-requests'));

        $middleware->alias([
            'idempotency' => IdempotencyMiddleware::class,
        ]);

        $middleware->group(
            'api',
            [
                DeviceSecurityMiddleware::class,
                //   \App\Http\Middleware\AppVersionSecurityMiddleware::class
            ]
        );

        $middleware->group(
            'auth:api',
            [
                JWTAuthenticationMiddleware::class,
                MatchTokenMiddleware::class,
                //  \App\Http\Middleware\ModuleSecurityMiddleware::class,
                DeviceSecurityMiddleware::class,
                // \App\Http\Middleware\AppVersionSecurityMiddleware::class
            ]
        );
    })
    ->withExceptions(function (Exceptions $exceptions) {

        $exceptions->shouldRenderJsonWhen(fn (Request $request): bool => $request->is('api/*') || $request->expectsJson());

        $exceptions->render(function (HttpResponseException $exception, Request $request) {
            if ($request->is('api/*')) {
                return $exception->getResponse();
            }
        });

        $exceptions->render(function (ThrottleRequestsException $exception, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => 'Demasiadas solicitudes. Intente nuevamente más tarde.',
                    'code' => 429,
                    'data' => null,
                ], 429, $exception->getHeaders());
            }
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                $response = [
                    'message' => 'Error de autenticación',
                    'code' => 401,
                    'data' => null,
                ];

                return response()->json($response, 401);
            }
        });

        $exceptions->render(function (MethodNotAllowedHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => 'El método HTTP utilizado no está permitido.',
                    'code' => 405,
                    'data' => null,
                ], 405);
            }
        });
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => 'Ruta no encontrada',
                    'code' => 404,
                    'data' => null,
                ], 404);
            }
        });
        $exceptions->render(function (QueryException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => 'Error en la base de datos',
                    'code' => 500,
                    'data' => null,
                ], 500);
            }
        });
        $exceptions->render(function (ConnectionException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => 'Error de conexión',
                    'code' => 503,
                    'data' => null,
                ], 503);
            }
        });
        $exceptions->render(function (Exception $e, Request $request) {
            if ($request->is('api/headquarters/pos/ventas-perdidas', 'api/farma/reportes/ventas-perdidas')) {
                if ($e instanceof ValidationException) {
                    return response()->json(['message' => 'Revisa los datos de ventas perdidas.', 'code' => 422, 'data' => null, 'errors' => $e->errors()], 422);
                }
                if ($e instanceof HttpExceptionInterface) {
                    return response()->json(['message' => $e->getMessage() ?: 'No se pudo completar la operación de ventas perdidas.', 'code' => $e->getStatusCode(), 'data' => null], $e->getStatusCode(), $e->getHeaders());
                }
            }
            if ($request->is('api/config/desktop-releases', 'api/config/desktop-releases/*', 'api/headquarters/updates/*')) {
                if ($e instanceof ValidationException) {
                    return response()->json(['message' => 'Revisa los datos de la versión.', 'code' => 422, 'data' => null, 'errors' => $e->errors()], 422);
                }
                if ($e instanceof HttpExceptionInterface) {
                    return response()->json(['message' => $e->getMessage() ?: 'No se pudo completar la operación de versiones.', 'code' => $e->getStatusCode(), 'data' => null], $e->getStatusCode(), $e->getHeaders());
                }
            }
            if ($request->is('api/headquarters/licenses/*', 'api/config/licenses', 'api/config/licenses/*')) {
                if ($e instanceof ValidationException) {
                    return response()->json(['message' => 'Revisa los datos de la licencia.', 'code' => 422, 'data' => null, 'errors' => $e->errors()], 422);
                }
                if ($e instanceof HttpExceptionInterface) {
                    return response()->json(['message' => $e->getMessage() ?: 'No se pudo completar la operación de licencia.', 'code' => $e->getStatusCode(), 'data' => null], $e->getStatusCode(), $e->getHeaders());
                }
            }
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => 'Error General',
                    'code' => 500,
                    'data' => null,
                ], 500);
            }
        });
        // RepositoryException

        $exceptions->render(function (RepositoryException $e, Request $request) {
            if ($request->is('api/*')) {
                return $e->render();
            }
        });
    })->create();
