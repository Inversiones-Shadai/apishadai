<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class HttpSecurityServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        RateLimiter::for('http-requests', function (Request $request): array {
            $response = fn (Request $request, array $headers): JsonResponse => response()->json([
                'message' => 'Demasiadas solicitudes. Intente nuevamente más tarde.',
                'code' => 429,
                'data' => null,
            ], 429, $headers);

            $limits = [
                Limit::perMinute(config('security.rate_limits.requests_per_minute'))
                    ->by('apishadai:requests:'.$request->ip())->response($response),
            ];

            if ($request->isMethod('POST') && $request->is('api/auth/session/login')) {
                $name = $request->input('name');
                $identity = hash('sha256', is_string($name) ? Str::lower(trim($name)) : '');

                $limits[] = Limit::perMinute(config('security.rate_limits.login_ip_per_minute'))
                    ->by('apishadai:login-ip:'.$request->ip())->response($response);
                $limits[] = Limit::perMinute(config('security.rate_limits.login_per_minute'))
                    ->by('apishadai:login-account:'.$identity.'|'.$request->ip())->response($response);
            }

            return $limits;
        });
    }
}
