<?php

namespace App\Services\DesktopUpdates;

use App\Services\Licensing\LicenseAuthority;
use Illuminate\Support\Facades\DB;

class DesktopUpdateInstallationReport
{
    public function record(array $proof, LicenseAuthority $authority): array
    {
        return DB::transaction(function () use ($proof, $authority): array {
            $grant = DB::table('desktop_update_events')->where('authorization_id', $proof['authorization_id'])->lockForUpdate()->first();
            $device = DB::table('desktop_license_devices')->where('id', $proof['activation_id'])->first();
            $license = DB::table('desktop_licenses')->where('id', $device?->license_id)->first();
            abort_unless($grant && $device && $license && $license->product === $proof['product']
                && $grant->action === 'updates.apply' && $grant->status === 'authorized'
                && $grant->activation_id === $device->id && $grant->product === $proof['product']
                && $grant->release_id === $proof['release_id'] && (int) $grant->release_revision === (int) $proof['release_revision']
                && hash_equals($device->key_hash, $proof['key_hash']) && hash_equals($device->device_hash, $proof['device_hash']), 403,
                'No se pudo vincular el informe a una autorización de instalación de este equipo.');
            $release = DB::table('desktop_releases')->where('id', $grant->release_id)->first();
            abort_unless($release, 422, 'No se encontró la versión autorizada.');
            abort_if($proof['result'] === 'installed' && $proof['current_version'] !== $release->version, 422,
                'La versión informada no coincide con el paquete autorizado.');
            $data = ['authorization_id' => $grant->authorization_id, 'activation_id' => $device->id,
                'release_id' => $grant->release_id, 'release_revision' => $grant->release_revision, 'product' => $proof['product'],
                'result' => $proof['result'], 'reported_version' => $proof['current_version'], 'failure_code' => $proof['failure_code']];
            DB::table('desktop_update_installation_reports')->insertOrIgnore($data + ['created_at' => now('UTC')]);
            $stored = DB::table('desktop_update_installation_reports')->where('authorization_id', $grant->authorization_id)->first();
            abort_unless($stored && $stored->result === $data['result'] && $stored->reported_version === $data['reported_version']
                && $stored->failure_code === $data['failure_code'], 409, 'Esta instalación ya tiene otro resultado informado.');

            return ['envelope' => $authority->sign(['protocol' => 'shadai-desktop-updates', 'version' => 1, 'action' => 'updates.report',
                'authorization_id' => $grant->authorization_id, 'status' => 'recorded', 'reason' => '', 'product' => $proof['product'],
                'activation_id' => $device->id, 'device_hash' => $proof['device_hash'], 'key_hash' => $proof['key_hash'],
                'request_nonce' => $proof['nonce'], 'architecture' => $proof['architecture'], 'current_version' => $proof['current_version'],
                'api_contract' => (int) $proof['api_contract'], 'schema_version' => (int) $proof['schema_version'],
                'issued_at' => now('UTC')->timestamp, 'expires_at' => now('UTC')->addSeconds(60)->timestamp,
                'release' => null, 'download' => null]), 'status_code' => 200];
        }, 3);
    }
}
