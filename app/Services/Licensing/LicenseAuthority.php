<?php

namespace App\Services\Licensing;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class LicenseAuthority
{
    public function readProof(array $envelope, string $action, array $additionalRules = []): array
    {
        $validated = Validator::make($envelope, [
            'payload' => ['required', 'string', 'max:16000'],
            'signature' => ['required', 'string', 'max:1500'],
        ])->validate();
        $json = base64_decode($validated['payload'], true);
        $signature = base64_decode($validated['signature'], true);
        $payload = $json === false ? null : json_decode($json, true);
        abort_unless(is_array($payload) && is_string($signature), 422, 'Solicitud de licencia inválida.');
        $rules = [
            'action' => ['required', Rule::in([$action])],
            'product' => ['required', Rule::in(config('licensing.products'))],
            'device_hash' => ['required', 'regex:/\A[a-f0-9]{64}\z/'],
            'device_name' => ['required', 'string', 'max:100'],
            'public_key' => ['required', 'string', 'max:2000'],
            'timestamp' => ['required', 'integer', 'min:1'],
            'nonce' => ['required', 'uuid'],
            'license_key' => [$action === 'activate' ? 'required' : 'prohibited', 'string', 'max:100'],
            'activation_id' => [$action !== 'activate' ? 'required' : 'prohibited', 'uuid'],
        ] + $additionalRules;
        if ($additionalRules !== []) {
            abort_if(array_diff(array_keys($payload), array_keys($rules)) !== [], 422, 'La solicitud contiene campos no permitidos.');
        }
        $data = Validator::make($payload, $rules)->validate();
        $key = openssl_pkey_get_public($data['public_key']);
        $details = $key ? openssl_pkey_get_details($key) : false;
        abort_unless($details && $details['type'] === OPENSSL_KEYTYPE_RSA && $details['bits'] >= 3072
            && $details['bits'] <= 4096 && openssl_verify($json, $signature, $key, OPENSSL_ALGO_SHA256) === 1,
            403, 'No se pudo comprobar la identidad del equipo.');
        abort_unless(abs(now()->timestamp - $data['timestamp']) <= 300, 422, 'Revisa la fecha y hora del equipo.');
        $data['key_hash'] = hash('sha256', $details['key']);

        return $data;
    }

    public function sign(array $claims): array
    {
        $path = config('licensing.private_key_path');
        $key = is_string($path) && is_file($path) ? openssl_pkey_get_private(file_get_contents($path)) : false;
        if (! $key) {
            throw new HttpResponseException(response()->json(['message' => 'La firma de licencias no está configurada.', 'code' => 503, 'data' => null], 503));
        }
        $payload = base64_encode(json_encode($claims, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        abort_unless(openssl_sign($payload, $signature, $key, OPENSSL_ALGO_SHA256), 503);

        return ['payload' => $payload, 'signature' => base64_encode($signature)];
    }
}
