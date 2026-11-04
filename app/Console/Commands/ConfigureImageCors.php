<?php

namespace App\Console\Commands;

use Aws\S3\Exception\S3Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class ConfigureImageCors extends Command
{
    protected $signature = 'images:configure-cors {origins* : Orígenes HTTP o HTTPS exactos de Shadmin}';

    protected $description = 'Configura CORS de lectura de imágenes sin eliminar otras reglas del bucket';

    public function handle(): int
    {
        foreach ($this->argument('origins') as $origin) {
            $parts = parse_url($origin);
            if (! is_array($parts) || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true)
                || empty($parts['host']) || str_contains($origin, '*')
                || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
                || ! in_array($parts['path'] ?? '', ['', '/'], true)) {
                $this->error('Indica orígenes exactos, por ejemplo https://shadmin.example.com, sin comodines.');

                return self::FAILURE;
            }
        }
        $disk = config('images.disk');
        $client = Storage::disk($disk)->getClient();
        $bucket = config('filesystems.disks.'.$disk.'.bucket');
        try {
            $rules = $client->getBucketCors(['Bucket' => $bucket])->get('CORSRules') ?? [];
        } catch (S3Exception $exception) {
            if (! in_array($exception->getAwsErrorCode(), ['NoSuchCORSConfiguration', 'NoSuchCORS'], true)) {
                throw $exception;
            }
            $rules = [];
        }
        $rules = array_values(array_filter($rules, fn ($rule) => ($rule['ID'] ?? '') !== 'shadmin-images'));
        $rules[] = ['ID' => 'shadmin-images', 'AllowedMethods' => ['GET', 'HEAD'],
            'AllowedOrigins' => $this->argument('origins'), 'AllowedHeaders' => ['*'],
            'ExposeHeaders' => ['ETag', 'Content-Type'], 'MaxAgeSeconds' => 3600];
        $client->putBucketCors(['Bucket' => $bucket, 'CORSConfiguration' => ['CORSRules' => $rules]]);
        $this->info('CORS de lectura configurado para '.$bucket.'.');

        return self::SUCCESS;
    }
}
