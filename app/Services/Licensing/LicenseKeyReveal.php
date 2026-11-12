<?php

namespace App\Services\Licensing;

use App\Mail\DesktopLicenseKeyCodeMail;
use App\Models\AppConfig;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

class LicenseKeyReveal
{
    public function requestCode(string $licenseId, int $actor, string $sessionHash): array
    {
        $recipient = $this->recipient();

        return DB::transaction(function () use ($licenseId, $actor, $sessionHash, $recipient): array {
            $license = DB::table('desktop_licenses')->where('id', $licenseId)->lockForUpdate()->first();
            abort_unless($license, 404);
            abort_unless($license->key_encrypted, 422, 'Esta licencia se generó antes de guardar claves cifradas. Su clave original no se puede recuperar.');
            $previous = DB::table('desktop_license_key_challenges')->where('license_id', $licenseId)->where('actor_id', $actor)->first();
            abort_if($previous && CarbonImmutable::parse($previous->created_at, 'UTC')->addMinute()->isFuture(),
                429, 'Espera un minuto antes de solicitar otro código.');

            $id = (string) Str::uuid();
            $code = (string) random_int(100000, 999999);
            $expires = now('UTC')->addMinutes(10);
            DB::table('desktop_license_key_challenges')->updateOrInsert(['license_id' => $licenseId, 'actor_id' => $actor], [
                'id' => $id, 'session_hash' => $sessionHash, 'recipient_hash' => hash('sha256', $recipient),
                'code_hash' => Hash::make($code), 'attempts' => 0, 'consumed_at' => null,
                'expires_at' => $expires, 'created_at' => now('UTC'),
            ]);
            try {
                Mail::mailer('smtp')->to($recipient)->send(new DesktopLicenseKeyCodeMail(
                    $license->label, $license->product, $license->key_suffix, $actor, $code,
                ));
            } catch (Throwable $exception) {
                Log::warning('No se pudo enviar el código de consulta de licencia.', ['exception' => $exception::class]);
                abort(503, 'No se pudo enviar el código por correo. Intenta nuevamente.');
            }
            $this->event($licenseId, $actor, 'key_code', 'sent');

            return ['challenge_id' => $id, 'expires_at' => $expires->toIso8601String()];
        });
    }

    public function reveal(string $licenseId, int $actor, string $sessionHash, string $challengeId, string $code): string
    {
        $recipient = $this->recipient();
        $key = DB::transaction(function () use ($licenseId, $actor, $sessionHash, $challengeId, $code, $recipient): ?string {
            // Match the request-code lock order to serialize resends and concurrent confirmations.
            $license = DB::table('desktop_licenses')->where('id', $licenseId)->lockForUpdate()->first();
            abort_unless($license, 404);
            $challenge = DB::table('desktop_license_key_challenges')->where('id', $challengeId)
                ->where('license_id', $licenseId)->where('actor_id', $actor)->lockForUpdate()->first();
            if (! $challenge || $challenge->consumed_at || $challenge->attempts >= 5
                || CarbonImmutable::parse($challenge->expires_at, 'UTC')->lessThanOrEqualTo(now('UTC'))
                || ! hash_equals($challenge->session_hash, $sessionHash)
                || ! hash_equals($challenge->recipient_hash, hash('sha256', $recipient))) {
                return null;
            }
            DB::table('desktop_license_key_challenges')->where('id', $challengeId)->increment('attempts');
            if (! Hash::check($code, $challenge->code_hash)) {
                $this->event($licenseId, $actor, 'key_reveal', 'invalid_code');

                return null;
            }
            abort_unless($license->key_encrypted, 422, 'La clave original de esta licencia no está disponible.');
            $key = Crypt::decryptString($license->key_encrypted);
            abort_unless(hash_equals($license->key_hash, hash('sha256', $key)), 503, 'No se pudo recuperar la clave de esta licencia.');
            DB::table('desktop_license_key_challenges')->where('id', $challengeId)->update(['consumed_at' => now('UTC')]);
            $this->event($licenseId, $actor, 'key_reveal', 'authorized');

            return $key;
        });
        // Reject outside the transaction so failed attempts remain committed.
        abort_if($key === null, 422, 'El código es incorrecto, venció o ya fue utilizado. Solicita otro código si agotaste los cinco intentos.');

        return $key;
    }

    private function recipient(): string
    {
        $recipient = trim((string) AppConfig::query()->value('mail_cc1'));
        abort_unless(filter_var($recipient, FILTER_VALIDATE_EMAIL), 503, 'Configura un correo administrador válido en appconfig.mail_cc1.');

        return $recipient;
    }

    private function event(string $licenseId, int $actor, string $action, string $result): void
    {
        DB::table('desktop_license_events')->insert([
            'license_id' => $licenseId, 'activation_id' => null, 'actor_id' => $actor,
            'action' => $action, 'result' => $result, 'created_at' => now('UTC'),
        ]);
    }
}
