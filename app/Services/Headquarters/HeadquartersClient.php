<?php

namespace App\Services\Headquarters;

use App\Exceptions\HeadquartersException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class HeadquartersClient
{
    public function send(string $method, string $path, array $data = []): array
    {
        $base = rtrim((string) config('headquarters.url'), '/');
        $secret = (string) config('headquarters.secret');
        $scheme = parse_url($base, PHP_URL_SCHEME);
        $local = in_array(parse_url($base, PHP_URL_HOST), ['127.0.0.1', 'localhost', '::1'], true);
        if (strlen($secret) < 64 || ! $base || ($scheme !== 'https' && ! ($scheme === 'http' && $local && app()->environment(['local', 'testing'])))) {
            throw new HeadquartersException('La conexión segura con hq-api no está configurada.', 503);
        }
        $url = $base.'/internal/apishadai/'.$path;
        $body = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $timestamp = (string) now()->timestamp;
        $nonce = (string) Str::uuid();
        $uri = parse_url($url, PHP_URL_PATH);
        $canonical = strtoupper($method)."\n".$uri."\n".$timestamp."\n".$nonce."\n".hash('sha256', $body);
        try {
            $response = Http::acceptJson()->withOptions(['allow_redirects' => false])->connectTimeout(2)->timeout(30)->withHeaders([
                'X-Apishadai-Key-Id' => config('headquarters.key_id'), 'X-Apishadai-Timestamp' => $timestamp,
                'X-Apishadai-Nonce' => $nonce, 'X-Apishadai-Signature' => hash_hmac('sha256', $canonical, $secret),
            ])->withBody($body, 'application/json')->send(strtoupper($method), $url);
        } catch (ConnectionException $exception) {
            throw new HeadquartersException('No se pudo conectar con hq-api.', 503);
        }
        if (! $response->successful()) {
            $code = $response->status();
            $message = in_array($code, [401, 409, 410, 422], true) ? $response->json('message') : null;
            throw new HeadquartersException(is_string($message) ? $message : 'hq-api no pudo procesar la solicitud.', $code >= 500 ? 503 : $code);
        }
        $result = $response->json('data');
        if (! is_array($result) && $result !== null) {
            throw new HeadquartersException('Respuesta inválida de hq-api.', 503);
        }

        return $result ?? [];
    }
}
