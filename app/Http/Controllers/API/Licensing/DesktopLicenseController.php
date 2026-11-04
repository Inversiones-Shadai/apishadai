<?php

namespace App\Http\Controllers\API\Licensing;

use App\Http\Controllers\Controller;
use App\Services\Licensing\LicenseAuthority;
use App\Services\Licensing\LicenseManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DesktopLicenseController extends Controller
{
    public function handle(Request $request, string $action, LicenseAuthority $authority, LicenseManager $manager): JsonResponse
    {
        abort_if(strlen($request->getContent()) > 24000, 413);

        return $this->sendResponse($manager->exchange($authority->readProof($request->all(), $action), $authority))->header('Cache-Control', 'no-store');
    }
}
