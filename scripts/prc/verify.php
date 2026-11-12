<?php

use App\Http\Controllers\API\Farma\Productos\ProductController;
use App\Utils\Services\Base64UtilityService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
require __DIR__.'/target.php';

function comparable(string $value): string
{
    return str_replace(',', '.', mb_strtoupper(preg_replace('/\s+/u', '', Str::ascii($value))));
}

$directory = prcDirectory($argv[1] ?? null);
assertLocalPrcDatabase();
$plan = json_decode(file_get_contents($directory.'/plan.json'), true, flags: JSON_THROW_ON_ERROR);
$bySource = array_column($plan['products'], null, 'source_id');
$owned = [];
foreach (file($directory.'/database-events.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $event = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
    if ($event['status'] === 'created') {
        $owned[$event['source_id']] = $event['product_id'];
    }
}
$failures = [];
$missingImages = [];
$savedImages = 0;
$verifiedApi = 0;
$needsClassification = 0;
foreach ($owned as $sourceId => $productId) {
    $product = DB::table('productos')->where('id', $productId)->first();
    $item = $bySource[$sourceId];
    if (! $product || $product->codigobar !== $item['barcode'] || $product->descripcion !== $item['descripcion']) {
        $failures[] = ['id' => $productId, 'reason' => 'product_identity_mismatch'];

        continue;
    }
    foreach (['categoria' => 'prod_categorias', 'laboratorio' => 'laboratorios', 'familia' => 'familia', 'unidad' => 'prod_unidades', 'estado' => 'prod_estado'] as $field => $table) {
        if (! DB::table($table)->where('id', $product->$field)->exists()) {
            $failures[] = ['id' => $productId, 'reason' => 'missing_'.$field];
        }
    }
    $family = DB::table('familia')->join('fam_presentacion', 'fam_presentacion.id', '=', 'familia.presentacion')
        ->join('fam_administracion', 'fam_administracion.id', '=', 'familia.administracion')
        ->where('familia.id', $product->familia)->first(['familia.descripcion as family', 'fam_presentacion.descripcion as form', 'fam_administracion.descripcion as route']);
    if (! $family || comparable($family->family) !== comparable($item['familia'])
        || comparable($family->form) !== comparable($item['presentacion']) || comparable($family->route) !== comparable($item['administracion'])) {
        $failures[] = ['id' => $productId, 'reason' => 'family_form_route_mismatch'];
    }
    $expectedActives = array_unique(array_map(fn ($active) => comparable($active['nombre']).'|'.comparable($active['concentracion']), $item['actives']));
    $actualActives = DB::table('prod_activo')->join('principal_activos', 'principal_activos.id', '=', 'prod_activo.pactivo')
        ->join('concentraciones', 'concentraciones.id', '=', 'principal_activos.concentracion')
        ->where('prod_activo.producto', $productId)->get(['principal_activos.nombre', 'concentraciones.valor'])
        ->map(fn ($active) => comparable($active->nombre).'|'.comparable($active->valor))->unique()->all();
    sort($expectedActives);
    sort($actualActives);
    if ($actualActives !== $expectedActives) {
        $failures[] = ['id' => $productId, 'reason' => 'active_ingredients_mismatch'];
    }
    if ($item['familia'] === 'PENDIENTE DE CLASIFICACION') {
        $needsClassification++;
    }
    if ($product->imagen === null || $product->imagen === '') {
        $missingImages[] = ['source_id' => $sourceId, 'product_id' => $productId, 'nombre' => $item['descripcion'], 'url' => $item['image_url']];

        continue;
    }
    $valid = app(Base64UtilityService::class)->validate($product->imagen);
    if ($valid === null || ! is_file($directory.'/images/'.$sourceId.'.img') || hash('sha256', base64_decode($valid)) !== hash_file('sha256', $directory.'/images/'.$sourceId.'.img')) {
        $failures[] = ['id' => $productId, 'reason' => 'image_content_mismatch_or_invalid'];
    } else {
        $savedImages++;
    }
    if ($verifiedApi < 3 && $valid !== null) {
        $response = app(ProductController::class)->getImage($productId);
        $json = $response->getData(true);
        if ($response->getStatusCode() !== 200 || ($json['data']['image'] ?? null) !== $valid) {
            $failures[] = ['id' => $productId, 'reason' => 'image_endpoint_failure'];
        }
        $verifiedApi++;
    }
}
$before = json_decode(file_get_contents($directory.'/before.json'), true, flags: JSON_THROW_ON_ERROR);
$existingPreserved = true;
foreach ($before['productos'] as $original) {
    $current = (array) DB::table('productos')->where('id', $original['id'])->first();
    if (isset($original['imagen_backup_base64'])) {
        $original['imagen'] = base64_decode($original['imagen_backup_base64']);
        unset($original['imagen_backup_base64']);
    }
    if ($current != $original) {
        $existingPreserved = false;
        $failures[] = ['id' => $original['id'], 'reason' => 'preexisting_product_changed'];
    }
}
$summary = ['database' => DB::connection()->getDatabaseName(), 'source_count' => $plan['source_count'],
    'imported' => count($owned), 'images_saved_in_database' => $savedImages, 'images_unavailable' => count($missingImages),
    'pending_products' => count($plan['pending']), 'imported_pending_therapeutic_classification' => $needsClassification,
    'original_products_preserved' => $existingPreserved, 'image_endpoint_checks' => $verifiedApi, 'verification_failures' => count($failures),
    'products_total' => DB::table('productos')->count()];
file_put_contents($directory.'/verification.json', json_encode(['summary' => $summary, 'failures' => $failures,
    'missing_images' => $missingImages], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
echo json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)."\n";
exit($failures === [] ? 0 : 1);
