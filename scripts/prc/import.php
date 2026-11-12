<?php

// One-off, resumable PRC import. No credentials are accepted or written here.
use App\Models\Farma\Concentracion;
use App\Models\Farma\FamAdministracion;
use App\Models\Farma\Familia;
use App\Models\Farma\FamPresentacion;
use App\Models\Farma\PrincipalActivo;
use App\Models\Farma\ProdCategoria;
use App\Models\Farma\ProdEstado;
use App\Models\Farma\Producto;
use App\Models\Farma\ProdUnidad;
use App\Models\Laboratorio;
use App\Services\Headquarters\ChangeRecorder;
use App\Utils\Services\Base64UtilityService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
require __DIR__.'/target.php';

$options = getopt('', ['directory:', 'apply', 'images-only', 'limit:']);
$directory = prcDirectory($options['directory'] ?? null);
assertLocalPrcDatabase();
$lock = fopen($directory.'/import.lock', 'c');
if (! flock($lock, LOCK_EX | LOCK_NB)) {
    throw new RuntimeException('Another import is running.');
}
$plan = json_decode(file_get_contents($directory.'/plan.json'), true, flags: JSON_THROW_ON_ERROR);
if (hash_file('sha256', $directory.'/source.json') !== $plan['source_sha256']) {
    throw new RuntimeException('Source snapshot does not match the plan.');
}
// Retain atomic outbox recording, but do not perform thousands of synchronous HQ requests.
config(['headquarters.send_immediately' => false]);
$apply = array_key_exists('apply', $options);
$imagesOnly = array_key_exists('images-only', $options);
$limit = isset($options['limit']) ? max(1, (int) $options['limit']) : PHP_INT_MAX;
$beforeTables = ['productos', 'laboratorios', 'familia', 'fam_presentacion', 'fam_administracion',
    'prod_unidades', 'prod_estado', 'prod_categorias', 'concentraciones', 'principal_activos', 'prod_activo'];
if ($apply && ! is_file($directory.'/before.json')) {
    $backup = ['database' => DB::connection()->getDatabaseName(), 'source_sha256' => $plan['source_sha256'], 'at' => now()->toIso8601String()];
    foreach ($beforeTables as $table) {
        $backup[$table] = DB::table($table)->get()->map(function ($row) {
            $data = (array) $row;
            if (isset($data['imagen'])) {
                $data['imagen_backup_base64'] = base64_encode($data['imagen']);
                unset($data['imagen']);
            }

            return $data;
        })->all();
    }
    if (file_put_contents($directory.'/before.json', json_encode($backup, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) {
        throw new RuntimeException('Cannot write backup.');
    }
}

function keyOf(mixed $value): string
{
    return mb_strtoupper(preg_replace('/\s+/u', ' ', trim(Str::ascii((string) $value))));
}

function strengthKey(string $value): string
{
    return str_replace([' ', ','], ['', '.'], keyOf($value));
}

$cache = [];
foreach ([Laboratorio::class => 'nombre', FamPresentacion::class => 'descripcion', FamAdministracion::class => 'descripcion',
    ProdCategoria::class => 'nombre', ProdEstado::class => 'descripcion', Concentracion::class => 'valor'] as $class => $column) {
    foreach ($class::query()->get() as $model) {
        $key = $class === Concentracion::class ? strengthKey($model->$column) : keyOf($model->$column);
        $cache[$class][$key] ??= (int) $model->id;
    }
}
foreach (ProdUnidad::all() as $model) {
    $cache[ProdUnidad::class][implode('|', [$model->abreviatura_c, $model->abreviatura_v, $model->cantidad_c, $model->cantidad_v])] = (int) $model->id;
}
foreach (Familia::all() as $model) {
    $cache[Familia::class][implode('|', [$model->presentacion, $model->administracion, keyOf($model->descripcion)])] = (int) $model->id;
}
foreach (PrincipalActivo::all() as $model) {
    $cache[PrincipalActivo::class][keyOf($model->nombre).'|'.$model->concentracion] = (int) $model->id;
}

function catalog(string $class, array $attributes, string $key): int
{
    global $cache;
    if (isset($cache[$class][$key])) {
        return $cache[$class][$key];
    }
    $model = $class::query()->create($attributes);

    return $cache[$class][$key] = (int) $model->id;
}

function singleCatalog(string $class, string $column, string $value): int
{
    return catalog($class, [$column => mb_strtoupper($value)], keyOf($value));
}

function productImage(string $directory, int $sourceId): ?string
{
    $path = $directory.'/images/'.$sourceId.'.img';
    if (! is_file($path)) {
        return null;
    }
    $binary = file_get_contents($path);
    $encoded = base64_encode($binary);
    $validated = app(Base64UtilityService::class)->validate($encoded);
    if ($validated === null) {
        $validated = app(Base64UtilityService::class)->sanitize($encoded);
        if ($validated === null) {
            file_put_contents($directory.'/image-validation-errors.jsonl', json_encode([
                'source_id' => $sourceId, 'reason' => 'source_image_invalid_or_truncated',
                'sha256' => hash('sha256', $binary),
            ], JSON_THROW_ON_ERROR)."\n", FILE_APPEND | LOCK_EX);

            return null;
        }
        // Retain the exact download and cache the validated representation for audit.
        if (! is_file($path.'.original')) {
            copy($path, $path.'.original');
        }
        file_put_contents($path, base64_decode($validated));
    }

    return $validated;
}

$journalFile = $directory.'/database-events.jsonl';
$owned = [];
if (is_file($journalFile)) {
    foreach (file($journalFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $event = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        if ($event['status'] === 'created') {
            $owned[$event['source_id']] = $event['product_id'];
        }
    }
}
$stats = ['mode' => $apply ? ($imagesOnly ? 'images-only' : 'apply') : 'dry-run', 'database' => DB::connection()->getDatabaseName(),
    'source_count' => $plan['source_count'], 'eligible' => count($plan['products']), 'pending' => count($plan['pending']),
    'created' => 0, 'existing' => 0, 'images_updated' => 0, 'images_missing' => 0, 'conflicts' => 0, 'errors' => 0];
$results = [];
$journal = $apply ? fopen($journalFile, 'a') : null;
foreach (array_slice($plan['products'], 0, $limit) as $index => $item) {
    $event = ['source_id' => $item['source_id'], 'codigo' => $item['codigo'], 'barcode' => $item['barcode']];
    // The cache must also roll back if a product transaction fails.
    $cacheBefore = $cache;
    try {
        $existing = DB::table('productos')->where('codigobar', $item['barcode'])->first(['id', 'codigo', 'descripcion', 'imagen']);
        if ($existing && (! isset($owned[$item['source_id']]) || $owned[$item['source_id']] !== (int) $existing->id)) {
            $event += ['status' => 'existing', 'product_id' => (int) $existing->id];
            $stats['existing']++;
        } elseif ($imagesOnly) {
            if (! $existing) {
                $event += ['status' => 'not_imported'];
            } elseif ($existing->imagen !== null && $existing->imagen !== '') {
                $event += ['status' => 'image_present', 'product_id' => (int) $existing->id];
            } else {
                $image = productImage($directory, $item['source_id']);
                if ($image === null) {
                    $event += ['status' => 'image_missing', 'product_id' => (int) $existing->id];
                    $stats['images_missing']++;
                } elseif ($apply) {
                    DB::transaction(function () use ($existing, $image): void {
                        DB::table('productos')->where('id', $existing->id)->whereNull('imagen')->update(['imagen' => $image, 'updated_at' => now()]);
                        app(ChangeRecorder::class)->record(Producto::findOrFail($existing->id), 'UPDATE');
                    });
                    $stats['images_updated']++;
                    $event += ['status' => 'image_updated', 'product_id' => (int) $existing->id, 'image_sha256' => hash('sha256', base64_decode($image))];
                } else {
                    $event += ['status' => 'image_ready'];
                }
            }
        } elseif ($existing) {
            $event += ['status' => 'existing', 'product_id' => (int) $existing->id];
            $stats['existing']++;
        } else {
            $code = $item['codigo'];
            if (DB::table('productos')->where('codigo', $code)->exists()) {
                $code .= '-'.$item['source_id'];
            }
            if (mb_strlen($code) > 20 || DB::table('productos')->where('codigo', $code)->exists()) {
                throw new RuntimeException('Product code conflict requiring review.');
            }
            $event['codigo'] = $code;
            if (! $apply) {
                $event += ['status' => 'ready', 'image_cached' => is_file($directory.'/images/'.$item['source_id'].'.img')];
            } else {
                $image = productImage($directory, $item['source_id']);
                $id = DB::transaction(function () use ($item, $code, $image): int {
                    $laboratory = singleCatalog(Laboratorio::class, 'nombre', $item['laboratorio']);
                    $category = singleCatalog(ProdCategoria::class, 'nombre', $item['categoria']);
                    $state = singleCatalog(ProdEstado::class, 'descripcion', $item['estado']);
                    $form = singleCatalog(FamPresentacion::class, 'descripcion', $item['presentacion']);
                    $route = singleCatalog(FamAdministracion::class, 'descripcion', $item['administracion']);
                    $family = catalog(Familia::class, ['presentacion' => $form, 'administracion' => $route, 'descripcion' => $item['familia']],
                        implode('|', [$form, $route, keyOf($item['familia'])]));
                    $unit = catalog(ProdUnidad::class, $item['unidad'], implode('|', array_values($item['unidad'])));
                    $activeIds = [];
                    foreach ($item['actives'] as $active) {
                        $strength = catalog(Concentracion::class, ['valor' => $active['concentracion']], strengthKey($active['concentracion']));
                        $activeIds[] = catalog(PrincipalActivo::class, ['nombre' => mb_strtoupper($active['nombre']), 'concentracion' => $strength], keyOf($active['nombre']).'|'.$strength);
                    }
                    // Explicit user requirement: downloaded base64 CONTENT in DB. Do not call the image UUID mutator.
                    $id = DB::table('productos')->insertGetId(['categoria' => $category, 'laboratorio' => $laboratory,
                        'unidad' => $unit, 'familia' => $family, 'codigo' => $code, 'codigobar' => $item['barcode'],
                        'descripcion' => $item['descripcion'], 'imagen' => $image, 'estado' => $state,
                        'producto_padre_id' => null, 'created_at' => now(), 'updated_at' => now()]);
                    Producto::findOrFail($id)->principiosActivos()->sync(array_values(array_unique($activeIds)));
                    app(ChangeRecorder::class)->record(Producto::findOrFail($id), 'CREATE');

                    return $id;
                });
                $owned[$item['source_id']] = $id;
                $stats['created']++;
                $stats['images_missing'] += $image === null ? 1 : 0;
                $event += ['status' => 'created', 'product_id' => $id, 'image_saved' => $image !== null,
                    'image_sha256' => $image === null ? null : hash('sha256', base64_decode($image))];
            }
        }
    } catch (Throwable $error) {
        $cache = $cacheBefore;
        $event += ['status' => 'error', 'error' => $error->getMessage()];
        $stats['errors']++;
        fwrite(STDERR, json_encode($event, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)."\n");
    }
    if ($apply) {
        if (fwrite($journal, json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n") === false || ! fflush($journal)) {
            throw new RuntimeException('Cannot persist import journal; stopping.');
        }
    }
    $results[] = $event;
    if (($index + 1) % 200 === 0) {
        echo json_encode(['processed' => $index + 1] + $stats, JSON_UNESCAPED_UNICODE)."\n";
    }
}
$stats['products_in_database'] = DB::table('productos')->count();
$report = ['summary' => $stats, 'results' => $results, 'pending' => $plan['pending'], 'review' => $plan['review']];
$reportName = $apply ? ($imagesOnly ? 'images-report.json' : 'import-report.json') : 'dry-run.json';
file_put_contents($directory.'/'.$reportName, json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n";
exit($stats['errors'] > 0 ? 1 : 0);
