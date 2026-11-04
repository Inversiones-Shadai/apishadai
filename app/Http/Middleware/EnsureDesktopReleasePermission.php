<?php

namespace App\Http\Middleware;

use App\Services\DesktopUpdates\DesktopReleasePermissions;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureDesktopReleasePermission
{
    public function handle(Request $request, Closure $next, string $action): Response
    {
        $allowed = $request->header('Action-Btn') === $action
            && app(DesktopReleasePermissions::class)->allows((int) $request->user()?->getAuthIdentifier(), $action);
        abort_unless($allowed, 403, 'No tienes permiso para esta acción de actualizaciones.');

        return $next($request);
    }
}
