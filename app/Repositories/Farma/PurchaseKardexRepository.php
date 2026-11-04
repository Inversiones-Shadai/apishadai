<?php

namespace App\Repositories\Farma;

use App\Interfaces\Farma\PurchaseKardexService;
use App\Interfaces\Farma\PurchaseService;
use App\Models\Farma\Compra;
use App\Models\Farma\Lote;
use DomainException;
use Illuminate\Support\Facades\DB;

class PurchaseKardexRepository implements PurchaseKardexService
{
    public function __construct(
        private readonly Compra $purchase,
        private readonly PurchaseService $purchases,
    ) {}

    public function send(int $purchaseId, int $userId): ?Compra
    {
        return DB::transaction(function () use ($purchaseId, $userId): ?Compra {
            $purchase = $this->purchase->newQuery()
                ->with('detalles')
                ->lockForUpdate()
                ->find($purchaseId);

            if ($purchase === null) {
                return null;
            }

            if ($purchase->kardex_enviado_at !== null) {
                throw new DomainException('La compra ya fue enviada al Kardex.');
            }

            if ($purchase->estado === 'borrador') {
                throw new DomainException('Finaliza la compra antes de enviarla al Kardex.');
            }

            if ($purchase->detalles->isEmpty()) {
                throw new DomainException('La compra no tiene productos para enviar al Kardex.');
            }

            $timestamp = now();
            $rows = [];

            foreach ($purchase->detalles as $detail) {
                if ((int) $detail->producto < 1 || (int) $detail->cantidad < 1
                    || (float) $detail->costo < 0 || ((float) $detail->costo == 0 && ! $detail->es_bonificacion)
                    || trim((string) $detail->lote) === ''
                    || $detail->fecha_elaboracion === null || $detail->fecha_expiracion === null) {
                    throw new DomainException('Todos los productos deben tener lote, fechas, cantidad y costo válidos antes de enviarse al Kardex.');
                }

                $rows[] = [
                    'compra' => (int) $purchase->getKey(),
                    'producto' => (int) $detail->producto,
                    'lote' => (string) $detail->lote,
                    'fecha_elab' => $detail->fecha_elaboracion->toDateString(),
                    'fecha_exp' => $detail->fecha_expiracion->toDateString(),
                    'cantidad' => (int) $detail->cantidad,
                    'costo' => (float) $detail->costo,
                    'isv' => (bool) $detail->isv,
                    'es_bonificacion' => (bool) $detail->es_bonificacion,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];
            }

            Lote::query()->insert($rows);
            $purchase->forceFill([
                'kardex_enviado_at' => $timestamp,
                'kardex_usuario' => $userId,
            ])->save();

            return $this->purchases->find($purchaseId);
        });
    }
}
