<?php

namespace App\Console\Commands;

use App\Services\Images\ImageStorage;
use App\Services\Images\InvoiceStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MigrateImagesToStorage extends Command
{
    protected $signature = 'images:migrate {--dry-run : Contar sin subir ni modificar registros}
        {--documents-only : Migrar solo facturas y comprobantes a invoices}';

    protected $description = 'Migra imágenes base64 a objetos UUID, sin reemplazar actualizaciones concurrentes';

    public function handle(ImageStorage $images, InvoiceStorage $invoices): int
    {
        if (! config('images.enabled')) {
            $this->error('Activa IMAGE_STORAGE_ENABLED antes de migrar.');

            return self::FAILURE;
        }
        $count = 0;
        $failed = 0;
        $backup = null;
        if (! $this->option('dry-run')) {
            $directory = storage_path('app/private/image-migrations');
            File::ensureDirectoryExists($directory);
            $backup = $directory.'/'.now()->format('Ymd-His').'-'.Str::uuid().'.jsonl';
            $this->info('Respaldo de imágenes originales: '.$backup);
        }
        foreach (['productos' => 'imagen', 'proveedores' => 'imagen', 'laboratorios' => 'imagen', 'compras' => 'img', 'compra_transc' => 'img'] as $table => $column) {
            if ($this->option('documents-only') && $column !== 'img') {
                continue;
            }
            $target = $column === 'img' ? $invoices : $images;
            DB::table($table)->whereNotNull($column)->orderBy('id')->chunkById(100, function ($rows) use ($table, $column, $images, $target, $backup, &$count, &$failed): void {
                foreach ($rows as $row) {
                    $old = $row->{$column};
                    if ($old === '' || ($column !== 'img' && Str::isUuid($old))) {
                        continue;
                    }
                    try {
                        $existingUuid = Str::isUuid($old);
                        if ($existingUuid) {
                            $disk = Storage::disk(config('images.disk'));
                            if ($disk->exists($target->path($old))) {
                                continue;
                            }
                        }
                        if ($this->option('dry-run')) {
                            $count++;

                            continue;
                        }
                        $line = json_encode(['table' => $table, 'column' => $column, 'id' => $row->id, 'original' => $old], JSON_THROW_ON_ERROR).PHP_EOL;
                        if (file_put_contents($backup, $line, FILE_APPEND | LOCK_EX) === false) {
                            throw new \RuntimeException('No se pudo respaldar la imagen original.');
                        }
                        if ($existingUuid) {
                            // Preserve the UUID and the original object for existing clients.
                            $source = $images->path($old);
                            $destination = $target->path($old);
                            if (! $disk->copy($source, $destination)
                                || hash('sha256', $disk->get($source)) !== hash('sha256', $disk->get($destination))) {
                                $disk->delete($destination);
                                throw new \RuntimeException('No se pudo verificar la copia del documento.');
                            }
                            $count++;

                            continue;
                        }
                        $uuid = $target->store($old);
                        // The object is uploaded before changing the DB. Retry safely after a failure.
                        $updated = DB::table($table)->where('id', $row->id)->where($column, $old)->update([$column => $uuid]);
                        if ($updated) {
                            $count++;
                        } else {
                            Storage::disk(config('images.disk'))->delete($target->path($uuid));
                        }
                    } catch (\Throwable $exception) {
                        $failed++;
                        $this->error("{$table} #{$row->id}: ".$exception->getMessage());
                    }
                }
            }, 'id');
        }
        $this->info("Imágenes: {$count}; errores: {$failed}.");

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
