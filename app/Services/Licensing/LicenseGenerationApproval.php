<?php

namespace App\Services\Licensing;

use App\Mail\DesktopLicenseGenerationCodeMail;
use App\Models\AppConfig;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

class LicenseGenerationApproval
{
    public function requestCode(array $data, int $actor, string $sessionHash): array
    {
        $payload = $this->payload($data);
        $recipient = $this->recipient();

        return DB::transaction(function () use ($payload, $actor, $sessionHash, $recipient): array {
            $id = (string) Str::uuid();
            $code = (string) random_int(100000, 999999);
            $expires = now('UTC')->addMinutes(10);
            $values = [
                'id' => $id, 'payload_hash' => $this->fingerprint($payload), 'session_hash' => $sessionHash,
                'recipient_hash' => hash('sha256', $recipient), 'code_hash' => Hash::make($code),
                'attempts' => 0, 'consumed_at' => null, 'created_at' => now('UTC'), 'expires_at' => $expires,
            ];
            // A single row per actor serializes initial requests, resends and confirmations.
            DB::table('desktop_license_generation_challenges')->insertOrIgnore(['actor_id' => $actor] + $values);
            $previous = DB::table('desktop_license_generation_challenges')->where('actor_id', $actor)->lockForUpdate()->first();
            if ($previous->id !== $id) {
                abort_if(CarbonImmutable::parse($previous->created_at, 'UTC')->addMinute()->isFuture(),
                    429, 'Espera un minuto antes de solicitar otro código.');
                DB::table('desktop_license_generation_challenges')->where('actor_id', $actor)->update($values);
            }
            $customer = DB::table('desktop_license_customers')->where('id', $payload['customer_id'])->value('name');
            abort_unless($customer, 422, 'El cliente de licencias ya no está disponible.');
            try {
                Mail::mailer('smtp')->to($recipient)->send(new DesktopLicenseGenerationCodeMail(
                    $customer, $payload['label'], $payload['product'], $payload['seats'], $payload['term_months'], $actor, $code,
                ));
            } catch (Throwable $exception) {
                Log::warning('No se pudo enviar el código de creación de licencia.', ['exception' => $exception::class]);
                abort(503, 'No se pudo enviar el código por correo. Intenta nuevamente.');
            }
            $this->event(null, $actor, 'generate_code', 'sent');

            return ['challenge_id' => $id, 'expires_at' => $expires->toIso8601String()];
        });
    }

    public function issue(array $data, int $actor, string $sessionHash, string $challengeId, string $code, LicenseManager $manager): array
    {
        $payload = $this->payload($data);
        $recipient = $this->recipient();
        $license = DB::transaction(function () use ($payload, $actor, $sessionHash, $challengeId, $code, $recipient, $manager): ?array {
            $challenge = DB::table('desktop_license_generation_challenges')->where('actor_id', $actor)->lockForUpdate()->first();
            if (! $challenge || $challenge->id !== $challengeId || $challenge->consumed_at || $challenge->attempts >= 5
                || CarbonImmutable::parse($challenge->expires_at, 'UTC')->lessThanOrEqualTo(now('UTC'))
                || ! hash_equals($challenge->session_hash, $sessionHash)
                || ! hash_equals($challenge->recipient_hash, hash('sha256', $recipient))
                || ! hash_equals($challenge->payload_hash, $this->fingerprint($payload))) {
                return null;
            }
            DB::table('desktop_license_generation_challenges')->where('actor_id', $actor)->increment('attempts');
            if (! Hash::check($code, $challenge->code_hash)) {
                $this->event(null, $actor, 'generate_approval', 'invalid_code');

                return null;
            }
            // Issue and consume together: an error rolls both operations back.
            $license = $manager->issue($payload, $actor);
            DB::table('desktop_license_generation_challenges')->where('actor_id', $actor)->update(['consumed_at' => now('UTC')]);
            $this->event($license['id'], $actor, 'generate_approval', 'authorized');

            return $license;
        });
        abort_if($license === null, 422, 'El código es incorrecto, venció, ya fue utilizado o los datos cambiaron. Solicita otro código si es necesario.');

        return $license;
    }

    private function payload(array $data): array
    {
        $payload = ['customer_id' => Str::lower($data['customer_id']), 'label' => trim($data['label']),
            'product' => $data['product'], 'seats' => (int) $data['seats'], 'term_months' => (int) $data['term_months']];
        abort_if($payload['label'] === '', 422, 'El nombre de la licencia es obligatorio.');

        return $payload;
    }

    private function fingerprint(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function recipient(): string
    {
        $recipient = trim((string) AppConfig::query()->value('mail_cc1'));
        abort_unless(filter_var($recipient, FILTER_VALIDATE_EMAIL), 503, 'Configura un correo administrador válido en appconfig.mail_cc1.');

        return $recipient;
    }

    private function event(?string $licenseId, int $actor, string $action, string $result): void
    {
        DB::table('desktop_license_events')->insert(['license_id' => $licenseId, 'activation_id' => null,
            'actor_id' => $actor, 'action' => $action, 'result' => $result, 'created_at' => now('UTC')]);
    }
}
