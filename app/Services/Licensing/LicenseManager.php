<?php

namespace App\Services\Licensing;

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class LicenseManager
{
    public function issue(array $data, ?int $actor): array
    {
        $period = Validator::make($data, [
            'customer_id' => ['required', 'uuid', 'exists:desktop_license_customers,id'],
            'term_months' => ['required', 'integer', Rule::in(config('licensing.term_months'))],
            'expires_at' => ['prohibited'],
        ])->validate();
        $period['customer_id'] = Str::lower($period['customer_id']);
        $months = (int) $period['term_months'];
        $startsAt = CarbonImmutable::now('America/Tegucigalpa');
        $expiresAt = $startsAt->addMonthsNoOverflow($months)->utc();
        $data['label'] = trim($data['label']);
        abort_if($data['label'] === '', 422, 'El nombre de la licencia es obligatorio.');
        $key = 'SHD-'.strtoupper(bin2hex(random_bytes(24)));
        $id = (string) Str::uuid();
        DB::transaction(function () use ($data, $key, $id, $actor, $months, $startsAt, $expiresAt, $period): void {
            DB::table('desktop_licenses')->insert([
                'id' => $id, 'customer_id' => $period['customer_id'], 'label' => $data['label'], 'product' => $data['product'],
                'key_hash' => hash('sha256', $key), 'key_suffix' => substr($key, -8), 'key_encrypted' => Crypt::encryptString($key),
                'seats' => $data['seats'], 'enabled' => true, 'expires_at' => $expiresAt,
                'term_months' => $months, 'term_started_at' => $startsAt->utc(),
                'created_by' => $actor, 'created_at' => now('UTC'), 'updated_at' => now('UTC'),
            ]);
            $this->event($id, null, 'generate', 'active', $actor);
        });

        return ['id' => $id, 'license_key' => $key, 'customer_id' => $period['customer_id'], 'label' => $data['label'], 'product' => $data['product'], 'term_months' => $months, 'expires_at' => $expiresAt->toIso8601String()];
    }

    public function exchange(array $proof, LicenseAuthority $authority): array
    {
        // Fail before consuming a seat when signing has not been provisioned.
        $authority->sign(['probe' => true]);
        try {
            return DB::transaction(function () use ($proof, $authority): array {
                DB::table('desktop_license_nonces')->where('expires_at', '<', now('UTC'))->delete();
                DB::table('desktop_license_nonces')->insert(['nonce' => $proof['nonce'], 'expires_at' => now('UTC')->addMinutes(10)]);
                $device = $proof['action'] === 'validate'
                    ? DB::table('desktop_license_devices')->where('id', $proof['activation_id'])->first() : null;
                $query = DB::table('desktop_licenses');
                $license = ($proof['action'] === 'activate'
                    ? $query->where('key_hash', hash('sha256', strtoupper(trim($proof['license_key']))))
                    : $query->where('id', $device?->license_id))->lockForUpdate()->first();
                $reason = 'license_invalid';
                if ($license && $license->product === $proof['product']) {
                    $reason = ! $license->enabled ? 'license_disabled'
                        : (! CarbonImmutable::parse($license->expires_at, 'UTC')->isFuture() ? 'license_expired' : '');
                    $device = DB::table('desktop_license_devices')->where('license_id', $license->id)
                        ->where($proof['action'] === 'validate' ? 'id' : 'key_hash', $proof['action'] === 'validate' ? $proof['activation_id'] : $proof['key_hash'])
                        ->lockForUpdate()->first();
                    if ($reason === '' && $device && ($device->revoked_at || ! hash_equals($device->key_hash, $proof['key_hash']) || ! hash_equals($device->device_hash, $proof['device_hash']))) {
                        $reason = 'device_revoked_or_mismatch';
                    }
                    if ($reason === '' && ! $device) {
                        if ($proof['action'] === 'validate') {
                            $reason = 'activation_invalid';
                        } else {
                            $identity = hash('sha256', $proof['product'].'|'.$proof['device_hash']);
                            $occupied = DB::table('desktop_license_devices')->where('active_identity', $identity)->exists();
                            $used = DB::table('desktop_license_devices')->where('license_id', $license->id)->where(fn ($q) => $q->whereNull('revoked_at')->orWhere('reserved_until', '>', now('UTC')))->count();
                            $reason = $occupied ? 'device_already_linked' : ($used >= $license->seats ? 'seats_exhausted' : '');
                            if ($reason === '') {
                                $id = (string) Str::uuid();
                                DB::table('desktop_license_devices')->insert([
                                    'id' => $id, 'license_id' => $license->id, 'device_name' => $proof['device_name'],
                                    'device_hash' => $proof['device_hash'], 'key_hash' => $proof['key_hash'], 'public_key' => $proof['public_key'],
                                    'active_identity' => $identity, 'last_validated_at' => now('UTC'), 'reserved_until' => now('UTC'), 'created_at' => now('UTC'), 'updated_at' => now('UTC'),
                                ]);
                                $device = DB::table('desktop_license_devices')->where('id', $id)->first();
                            }
                        }
                    }
                }
                $issuedAt = now('UTC')->timestamp;
                $deadline = $reason === '' ? min($issuedAt + 72 * 3600, CarbonImmutable::parse($license->expires_at, 'UTC')->timestamp) : $issuedAt;
                if ($reason === '' && $device) {
                    DB::table('desktop_license_devices')->where('id', $device->id)->update([
                        'last_validated_at' => CarbonImmutable::createFromTimestamp($issuedAt),
                        'reserved_until' => CarbonImmutable::createFromTimestamp($deadline), 'updated_at' => now('UTC'),
                    ]);
                }
                $this->event($license?->id, $device?->id, $proof['action'], $reason ?: 'valid');

                return $authority->sign([
                    'version' => 1, 'status' => $reason === '' ? 'valid' : 'denied', 'reason' => $reason,
                    'product' => $proof['product'], 'activation_id' => $device?->id,
                    'device_hash' => $proof['device_hash'], 'key_hash' => $proof['key_hash'],
                    'request_nonce' => $proof['nonce'], 'issued_at' => $issuedAt, 'offline_until' => $deadline,
                ]);
            }, 3);
        } catch (QueryException $exception) {
            if (in_array((string) $exception->getCode(), ['23000', '23505'], true)) {
                abort(409, 'La solicitud ya fue utilizada o el dispositivo ya está vinculado. Reintenta la validación.');
            }
            throw $exception;
        }
    }

    public function update(string $id, array $data, ?int $actor): void
    {
        $period = Validator::make($data, [
            'customer_id' => ['required', 'uuid', 'exists:desktop_license_customers,id'],
            'renewal_months' => ['sometimes', 'required', 'integer', Rule::in(config('licensing.term_months'))],
            'expected_expires_at' => ['required_with:renewal_months', 'date'],
            'product' => ['prohibited'], 'term_months' => ['prohibited'], 'expires_at' => ['prohibited'],
        ])->validate();
        $period['customer_id'] = Str::lower($period['customer_id']);
        $data['label'] = trim($data['label']);
        abort_if($data['label'] === '', 422, 'El nombre de la licencia es obligatorio.');
        DB::transaction(function () use ($id, $data, $actor, $period): void {
            $license = DB::table('desktop_licenses')->where('id', $id)->lockForUpdate()->first();
            abort_unless($license, 404);
            $used = DB::table('desktop_license_devices')->where('license_id', $id)->where(fn ($q) => $q->whereNull('revoked_at')->orWhere('reserved_until', '>', now('UTC')))->count();
            abort_if($data['seats'] < $used, 422, 'No puedes reducir los cupos por debajo de los equipos vinculados.');
            $changes = ['customer_id' => $period['customer_id'], 'label' => $data['label'], 'seats' => $data['seats'], 'enabled' => $data['enabled'], 'updated_at' => now('UTC')];
            if (isset($period['renewal_months'])) {
                abort_unless(CarbonImmutable::parse($period['expected_expires_at'])->equalTo(CarbonImmutable::parse($license->expires_at, 'UTC')),
                    409, 'La licencia cambió o la renovación ya se aplicó. Actualiza la página y vuelve a abrir la licencia.');
                $months = (int) $period['renewal_months'];
                $expiresAt = CarbonImmutable::parse($license->expires_at, 'UTC')->setTimezone('America/Tegucigalpa');
                $startsAt = $expiresAt->isFuture() ? $expiresAt : CarbonImmutable::now('America/Tegucigalpa');
                $changes += ['term_months' => $months, 'term_started_at' => $startsAt->utc(),
                    'expires_at' => $startsAt->addMonthsNoOverflow($months)->utc()];
                $this->event($id, null, 'renew', $months.'_months', $actor);
            }
            DB::table('desktop_licenses')->where('id', $id)->update($changes);
            if ($license->customer_id !== $period['customer_id']) {
                $this->event($id, null, 'customer_change', $period['customer_id'], $actor);
            }
            $this->event($id, null, 'update', $data['enabled'] ? 'enabled' : 'disabled', $actor);
        }, 3);
    }

    public function unlink(string $licenseId, string $activationId, ?int $actor): void
    {
        DB::transaction(function () use ($licenseId, $activationId, $actor): void {
            abort_unless(DB::table('desktop_licenses')->where('id', $licenseId)->lockForUpdate()->first(), 404);
            $device = DB::table('desktop_license_devices')->where('license_id', $licenseId)->where('id', $activationId)->lockForUpdate()->first();
            abort_unless($device, 404);
            if (! $device->revoked_at) {
                DB::table('desktop_license_devices')->where('id', $activationId)->update(['revoked_at' => now('UTC'), 'active_identity' => null, 'updated_at' => now('UTC')]);
                $this->event($licenseId, $activationId, 'unlink', 'revoked', $actor);
            }
        }, 3);
    }

    public function delete(string $id, ?int $actor): void
    {
        DB::transaction(function () use ($id, $actor): void {
            abort_unless(DB::table('desktop_licenses')->where('id', $id)->lockForUpdate()->first(), 404);
            abort_if(DB::table('desktop_license_devices')->where('license_id', $id)->whereNull('revoked_at')->exists(),
                422, 'Desvincula todos los equipos antes de eliminar la licencia.');

            $this->event($id, null, 'delete', 'deleted', $actor);
            DB::table('desktop_license_devices')->where('license_id', $id)->delete();
            DB::table('desktop_licenses')->where('id', $id)->delete();
        }, 3);
    }

    private function event(?string $license, ?string $activation, string $action, string $result, ?int $actor = null): void
    {
        DB::table('desktop_license_events')->insert([
            'license_id' => $license, 'activation_id' => $activation, 'action' => $action,
            'result' => $result, 'actor_id' => $actor, 'created_at' => now('UTC'),
        ]);
    }
}
