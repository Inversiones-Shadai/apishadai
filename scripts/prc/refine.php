<?php

// Apply only reviewed plan refinements to records created by this import.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Models\Farma\Concentracion;
use App\Models\Farma\FamAdministracion;
use App\Models\Farma\Familia;
use App\Models\Farma\FamPresentacion;
use App\Models\Farma\PrincipalActivo;
use App\Models\Farma\Producto;
use App\Services\Headquarters\ChangeRecorder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

$directory = realpath($argv[1] ?? storage_path('app/private/prc-import-20261022'));
$lock = fopen($directory.'/import.lock', 'c');
if (! flock($lock, LOCK_EX | LOCK_NB)) {
    throw new RuntimeException('Another import is running.');
}
config(['headquarters.send_immediately' => false]);
$old = array_column(json_decode(file_get_contents($directory.'/plan-applied.json'), true, flags: JSON_THROW_ON_ERROR)['products'], null, 'source_id');
$new = array_column(json_decode(file_get_contents($directory.'/plan.json'), true, flags: JSON_THROW_ON_ERROR)['products'], null, 'source_id');
$owned = [];
foreach (file($directory.'/database-events.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $event = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
    if ($event['status'] === 'created') {
        $owned[$event['source_id']] = $event['product_id'];
    }
}
function normalized(string $value): string
{
    return mb_strtoupper(preg_replace('/\s+/u', '', Str::ascii($value)));
}
function lookupCatalog(string $class, string $column, string $value): int
{
    foreach ($class::all() as $record) {
        if (normalized($record->$column) === normalized($value)) {
            return (int) $record->id;
        }
    }

    return (int) $class::create([$column => mb_strtoupper($value)])->id;
}
$journal = fopen($directory.'/refinements.jsonl', 'a');
$changed = 0;
foreach ($owned as $sourceId => $id) {
    $a = $old[$sourceId];
    $b = $new[$sourceId];
    if (array_intersect_key($a, array_flip(['familia', 'presentacion', 'administracion', 'actives'])) === array_intersect_key($b, array_flip(['familia', 'presentacion', 'administracion', 'actives']))) {
        continue;
    }
    DB::transaction(function () use ($a, $b, $id): void {
        $product = Producto::whereKey($id)->lockForUpdate()->firstOrFail();
        if ($a['familia'] !== $b['familia'] || $a['presentacion'] !== $b['presentacion'] || $a['administracion'] !== $b['administracion']) {
            $form = lookupCatalog(FamPresentacion::class, 'descripcion', $b['presentacion']);
            $route = lookupCatalog(FamAdministracion::class, 'descripcion', $b['administracion']);
            $family = Familia::firstOrCreate(['presentacion' => $form, 'administracion' => $route, 'descripcion' => $b['familia']]);
            DB::table('productos')->where('id', $id)->update(['familia' => $family->id, 'updated_at' => now()]);
        }
        if ($a['actives'] !== $b['actives']) {
            $ids = [];
            foreach ($b['actives'] as $active) {
                $strength = lookupCatalog(Concentracion::class, 'valor', $active['concentracion']);
                $record = PrincipalActivo::firstOrCreate(['nombre' => mb_strtoupper($active['nombre']), 'concentracion' => $strength]);
                $ids[] = (int) $record->id;
            }
            $product->principiosActivos()->sync(array_unique($ids));
        }
        app(ChangeRecorder::class)->record(Producto::findOrFail($id), 'UPDATE');
    });
    fwrite($journal, json_encode(['source_id' => $sourceId, 'product_id' => $id,
        'before' => array_intersect_key($a, array_flip(['familia', 'presentacion', 'administracion', 'actives'])),
        'after' => array_intersect_key($b, array_flip(['familia', 'presentacion', 'administracion', 'actives']))], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n");
    fflush($journal);
    $changed++;
}
echo json_encode(['refined_products' => $changed], JSON_PRETTY_PRINT)."\n";
