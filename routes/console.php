<?php

use App\Models\Farma\Distribucion;
use App\Models\Farma\Lote;
use App\Models\Farma\Transferencia;
use App\Models\Pos\CatalogoPos;
use App\Services\Headquarters\ChangeRecorder;
use App\Services\Headquarters\EntityContract;
use App\Services\Headquarters\HeadquartersClient;
use App\Services\Headquarters\OutboxSender;
use App\Services\Headquarters\PosAttendanceAlerts;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

Artisan::command('hq:push {--batches=100}', function (OutboxSender $sender): int {
    $result = $sender->send(max(1, min(1000, (int) $this->option('batches'))));
    $this->info("Enviados: {$result['sent']}; pendientes: {$result['pending']}.");

    return $result['pending'] === 0 ? 0 : 1;
})->purpose('Reintentar el envío de cambios pendientes a hq-api');

Artisan::command('hq:seed', function (ChangeRecorder $recorder): int {
    config(['headquarters.send_immediately' => false]);
    $count = 0;
    foreach (EntityContract::orderedEntities() as $entity) {
        $class = ChangeRecorder::MODELS[$entity];
        $class::query()->select('id')->chunkById(100, function ($records) use ($recorder, &$count, $class): void {
            foreach ($records as $record) {
                DB::transaction(function () use ($record, $class, $recorder, &$count): void {
                    $model = $class::whereKey($record->id)->lockForUpdate()->first();
                    if (! $model || DB::table('sync_entity_versions')->where('entity', $model->getTable())->where('entity_id', $model->getKey())->exists()) {
                        return;
                    }
                    $recorder->record($model, 'CREATE');
                    $count++;
                }, 3);
            }
        });
    }
    $this->info("Registros iniciales en cola: $count.");

    return 0;
})->purpose('Preparar una carga inicial idempotente de los datos existentes');

// Republish existing catalog payloads after extending optional nested lot metadata.
// Business tables, inventory and original transfer amounts remain unchanged.
Artisan::command('hq:refresh-lot-costs {stock}', function (ChangeRecorder $recorder): int {
    $stock = filter_var($this->argument('stock'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($stock === false || ! DB::table('stocks')->where('id', $stock)->exists()) {
        $this->error('Selecciona un stock existente.');

        return 1;
    }
    if (! $recorder->enabled()) {
        $this->error('La publicación HQ debe estar habilitada para actualizar sus catálogos.');

        return 1;
    }
    config(['headquarters.send_immediately' => false]);
    $queries = [
        Distribucion::query(),
        CatalogoPos::query()->where('stock_id', $stock)->where('tipo', 'PRECIO'),
        Transferencia::query()->where(fn ($query) => $query->where('stock_de', $stock)->orWhere('stock_para', $stock)),
    ];
    $count = 0;
    foreach ($queries as $query) {
        $class = $query->getModel()::class;
        $query->select('id')->chunkById(100, function ($records) use ($class, $recorder, &$count): void {
            foreach ($records as $record) {
                DB::transaction(function () use ($record, $class, $recorder, &$count): void {
                    $model = $class::whereKey($record->id)->lockForUpdate()->first();
                    if (! $model) {
                        return;
                    }
                    $recorder->record($model, 'UPDATE');
                    $count++;
                }, 3);
            }
        });
    }
    $this->info("Catálogos con costo y datos de venta por lote en cola: $count.");

    return 0;
})->purpose('Actualizar costo y datos de venta por lote en HQ sin modificar operaciones de negocio');

Schedule::command('hq:push --batches=20')->everyMinute()->withoutOverlapping(5);

Artisan::command('hq:revoke {uuid}', function (HeadquartersClient $client): int {
    $uuid = (string) $this->argument('uuid');
    if (! Str::isUuid($uuid)) {
        $this->error('UUID inválido.');

        return 1;
    }
    $client->send('DELETE', 'devices/'.$uuid);
    $this->info('Cliente revocado en hq-api.');

    return 0;
})->purpose('Revocar un hq-client exclusivamente desde apishadai');

Artisan::command('hq:status', function (): int {
    $this->info('Cambios: '.DB::table('sync_changes')->count().'; enviados: '.DB::table('hq_outbox')->whereNotNull('sent_at')->count().'; pendientes: '.DB::table('hq_outbox')->whereNull('sent_at')->count());
    foreach (DB::table('hq_outbox')->whereNull('sent_at')->whereNotNull('last_status')->selectRaw('last_status, COUNT(*) as total')->groupBy('last_status')->get() as $status) {
        $this->line("HTTP {$status->last_status}: {$status->total} pendientes.");
    }

    return 0;
})->purpose('Mostrar el estado del envío sin revelar datos ni claves');

Artisan::command('pos:attendance-mail {--fecha=} {--enqueue}', function (PosAttendanceAlerts $alerts): int {
    $date = $this->option('fecha') ?: now('America/Tegucigalpa')->toDateString();
    if (! Validator::make(['fecha' => $date], ['fecha' => ['required', 'date_format:Y-m-d']])->passes()) {
        $this->error('Fecha inválida; usa YYYY-MM-DD.');

        return 1;
    }
    $enqueued = 0;
    $missing = 0;
    $sent = 0;
    $waiting = 0;
    DB::table('pos_asistencia_solicitudes')->orderBy('request_id')->chunkById(100, function ($records) use ($date, $alerts, &$enqueued, &$missing, &$sent, &$waiting): void {
        foreach ($records as $record) {
            $payload = json_decode($record->datos, true, flags: JSON_THROW_ON_ERROR);
            $response = json_decode($record->respuesta, true, flags: JSON_THROW_ON_ERROR);
            if ($payload['fecha'] !== $date || $response['aceptada'] !== true) {
                continue;
            }
            if ($record->correo_enviado_at !== null) {
                $sent++;
            } elseif ($record->correo_encolado_at !== null) {
                $waiting++;
            } else {
                $missing++;
                if ($this->option('enqueue') && $alerts->enqueue($record->request_id)) {
                    $enqueued++;
                }
            }
        }
    }, 'request_id');
    $this->info("Fecha $date; aceptados sin aviso: $missing; avisos en cola: $waiting; entregados al transporte: $sent; encolados ahora: $enqueued.");

    return 0;
})->purpose('Consultar o recuperar correos de asistencia POS sin modificar marcas ni duplicar tareas');

Artisan::command('hq:seed-lots', function (ChangeRecorder $recorder): int {
    if (! $recorder->enabled()) {
        $this->error('La publicación HQ debe estar habilitada.');

        return 1;
    }
    config(['headquarters.send_immediately' => false]);
    $count = 0;
    Lote::query()->select('id')->chunkById(100, function ($rows) use ($recorder, &$count): void {
        foreach ($rows as $row) {
            DB::transaction(function () use ($row, $recorder, &$count): void {
                $lot = Lote::whereKey($row->id)->lockForUpdate()->first();
                if (! $lot || DB::table('sync_entity_versions')->where('entity', 'lotes')->where('entity_id', $lot->id)->exists()) {
                    return;
                }
                $recorder->record($lot, 'CREATE');
                $count++;
            }, 3);
        }
    });
    $this->info("Lotes maestros preparados: $count.");

    return 0;
})->purpose('Publicar inicialmente los lotes maestros sin modificar sus datos ni crear precios');

Artisan::command('hq:push-lots {--batches=100}', function (OutboxSender $sender): int {
    $result = $sender->sendLots(max(1, min(1000, (int) $this->option('batches'))));
    $this->info("Lotes enviados: {$result['sent']}; lotes pendientes: {$result['pending']}.");

    return $result['pending'] === 0 ? 0 : 1;
})->purpose('Enviar exclusivamente lotes maestros; conservar otros eventos pendientes');

Artisan::command('hq:refresh-lot-valuations', function (ChangeRecorder $recorder): int {
    if (! $recorder->enabled()) {
        $this->error('La publicación HQ debe estar habilitada.');

        return 1;
    }
    config(['headquarters.send_immediately' => false]);
    $count = 0;
    Lote::query()->select('id')->chunkById(100, function ($rows) use ($recorder, &$count): void {
        foreach ($rows as $row) {
            DB::transaction(function () use ($row, $recorder, &$count): void {
                $lot = Lote::whereKey($row->id)->lockForUpdate()->first();
                if ($lot !== null) {
                    $recorder->record($lot, 'UPDATE');
                    $count++;
                }
            }, 3);
        }
    });
    $this->info("Metadatos de valoración de lotes preparados: $count. Sin modificar costos ni existencias.");

    return 0;
})->purpose('Volver a publicar costo e ISV propios de lotes sin modificar operaciones');
