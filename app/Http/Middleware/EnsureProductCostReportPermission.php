<?php

namespace App\Http\Middleware;

use App\Services\Selectors\SelectorCatalog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureProductCostReportPermission
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! app(SelectorCatalog::class)->allows($request, ['permissions' => [['frm', 'rct', 'rctbtn01']]])) {
            return response()->json(['message' => 'No tienes permiso para consultar el reporte de costeo.', 'code' => 403, 'data' => null], 403);
        }

        return $next($request);
    }
}
