<?php

namespace App\Services\Images;

use App\Utils\Services\Base64UtilityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class ImageStorage
{
    public function store(?string $value): ?string
    {
        if ($value === null || $value === '' || Str::isUuid($value)) {
            return $value;
        }
        if (! config('images.enabled')) {
            return $value;
        }

        $clean = app(Base64UtilityService::class)->sanitize($value);
        if ($clean === null) {
            throw new InvalidArgumentException('La imagen no es válida.');
        }
        $binary = base64_decode($clean, true);
        $id = (string) Str::uuid();
        $mime = getimagesizefromstring($binary)['mime'];
        if (! Storage::disk(config('images.disk'))->put($this->path($id), $binary, [
            'visibility' => 'private',
            'ContentType' => $mime,
            'CacheControl' => 'private, max-age=31536000, immutable',
        ])) {
            throw new RuntimeException('No fue posible subir la imagen al storage.');
        }

        return $id;
    }

    public function path(string $id): string
    {
        if (! Str::isUuid($id)) {
            throw new InvalidArgumentException('UUID de imagen inválido.');
        }

        return $this->prefix().'/'.$id;
    }

    protected function prefix(): string
    {
        return trim(config('images.prefix'), '/');
    }

    public function url(?string $id, ?string $action = null): ?string
    {
        if (! is_string($id) || ! Str::isUuid($id)) {
            return null;
        }
        if ($action !== null && ! $this->canView($action)) {
            return null;
        }

        return Storage::disk(config('images.disk'))->temporaryUrl(
            $this->path($id), now()->addMinutes(max(5, config('images.url_minutes'))),
        );
    }

    private function canView(string $action): bool
    {
        $request = request();
        $key = 'images.permission.'.$action;
        if (! $request->attributes->has($key)) {
            $user = $request->user()?->getAuthIdentifier();
            $allowed = $user !== null && DB::table('permisos')
                ->join('modulos', 'permisos.modulo', '=', 'modulos.id')
                ->join('vistas', 'permisos.vista', '=', 'vistas.id')
                ->join('actionsvistas', 'permisos.actionvista', '=', 'actionsvistas.id')
                ->join('modulo_estados', 'modulos.estado', '=', 'modulo_estados.id')
                ->join('vista_estados', 'vistas.estado', '=', 'vista_estados.id')
                ->where('permisos.usuario', $user)
                ->whereColumn('vistas.modulo', 'modulos.id')
                ->whereColumn('actionsvistas.vista', 'vistas.id')
                ->where('modulo_estados.descripcion', 'ACTIVO')
                ->where('vista_estados.descripcion', 'ACTIVO')
                ->where('actionsvistas.codigo', $action)
                ->where(fn ($query) => $query->whereNull('permisos.lifetime')->orWhere('permisos.lifetime', '>=', now()))
                ->exists();
            $request->attributes->set($key, $allowed);
        }

        return $request->attributes->get($key);
    }

    // Keep older HQ/POS consumers compatible while their contract still uses base64.
    public function base64(?string $value): ?string
    {
        if (! is_string($value) || ! Str::isUuid($value)) {
            return $value;
        }

        return base64_encode(Storage::disk(config('images.disk'))->get($this->path($value)));
    }
}
