<?php

namespace App\Http\Controllers\API\DesktopUpdates;

use App\Http\Controllers\Controller;
use App\Services\DesktopUpdates\DesktopUpdateAuthorization;
use App\Services\Licensing\LicenseAuthority;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DesktopUpdateController extends Controller
{
    public function handle(Request $request, string $action, LicenseAuthority $authority, DesktopUpdateAuthorization $updates): JsonResponse
    {
        abort_if(strlen($request->getContent()) > 24000, 413);
        abort_if(array_diff(array_keys($request->all()), ['payload', 'signature']) !== [], 422, 'Solo se admite la prueba firmada del equipo.');
        $proof = $authority->readProof($request->all(), 'updates.'.$action, [
            'current_version' => ['required', 'string', 'regex:/^[1-9][0-9]{0,4}\.(0|[1-9][0-9]{0,4})\.(0|[1-9][0-9]{0,4})\.(0|[1-9][0-9]{0,4})$/D',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (is_string($value) && max(array_map('intval', explode('.', $value))) > 65535) {
                        $fail('La versión instalada no es válida.');
                    }
                }],
            'architecture' => ['required', Rule::in(['x64', 'x86', 'arm64'])],
            'api_contract' => ['required', 'integer', 'min:1', 'max:65535'],
            'schema_version' => ['required', 'integer', 'min:0', 'max:65535'],
            'release_id' => [$action === 'check' ? 'prohibited' : 'required', 'uuid'],
            'release_revision' => [$action === 'check' ? 'prohibited' : 'required', 'integer', 'min:1'],
            'package_sha256' => [$action === 'apply' ? 'required' : 'prohibited', 'regex:/^[a-f0-9]{64}$/D'],
            'authorization_id' => [$action === 'report' ? 'required' : 'prohibited', 'uuid'],
            'result' => [$action === 'report' ? 'required' : 'prohibited', Rule::in(['installed', 'failed'])],
            'failure_code' => [$action === 'report' ? 'present' : 'prohibited', 'string', Rule::in(['', 'update_not_completed'])],
        ]);
        if ($action === 'report') {
            abort_if(($proof['result'] === 'installed' && $proof['failure_code'] !== '')
                || ($proof['result'] === 'failed' && $proof['failure_code'] === ''), 422, 'El motivo no corresponde al resultado informado.');
        }
        foreach (['nonce', 'activation_id', 'release_id', 'authorization_id'] as $field) {
            if (isset($proof[$field])) {
                $proof[$field] = strtolower($proof[$field]);
            }
        }
        $result = $updates->exchange($proof);

        return response()->json(['message' => 'Consulta de actualización procesada.', 'code' => $result['status_code'], 'data' => $result['envelope']], $result['status_code'])
            ->header('Cache-Control', 'no-store, private');
    }
}
