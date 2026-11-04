# Reporte de compras de Farma

En Shadmin: **Farma > Reportes > Reporte de compras**, ruta `/farma/reportes/compras`.

La vista abre sin consultar compras. Pulsa **Consultar** o cambia los filtros para cargar resultados. **Limpiar filtros** vuelve al estado vacío. La columna **Pagos** abre los movimientos de la factura en un modal paginado, con fecha, tipo, comprobante, valor, total aplicado y saldo.

Permisos del módulo `frm`, vista `rcp`:

| Código | Acción |
| --- | --- |
| `rcpbtn01` | Consultar y filtrar el reporte |
| `rcpbtn02` | Buscar y seleccionar proveedores |
| `rcpbtn03` | Ver los pagos de cada compra |
| `rcpbtn04` | Exportar compras con sus pagos a PDF o Excel |

Las migraciones `2026_10_17_000005_register_purchase_report_permissions.php`, `2026_10_17_000006_register_purchase_report_payments_permission.php` y `2026_10_17_000007_register_purchase_report_export_permission.php` registran el catálogo sin conceder accesos automáticamente. Los permisos se asignan desde la administración de permisos de Shadmin. No se necesitan permisos de creación, modificación ni eliminación de compras para consultar el reporte.

API:

- `GET /api/farma/reporte-compras/listar`, encabezado `Action-Btn: rcpbtn01`. Parámetros opcionales: `proveedor`, `tipo` (`1` contado, `2` crédito), `search`, `page` y `per_page` (máximo 100).
- `GET /api/farma/reporte-compras/proveedores`, encabezado `Action-Btn: rcpbtn02`. Búsqueda paginada por `search` y `page`.
- `GET /api/farma/reporte-compras/pagos/{id}`, encabezado `Action-Btn: rcpbtn03`. Movimientos de una factura, paginados con `page`; los totales de la factura incluyen todas sus páginas.
- `GET /api/farma/reporte-compras/exportar`, encabezado `Action-Btn: rcpbtn04`. Filtros `proveedor`, `tipo` y `search`; continuación por `antes_de` y `next_cursor`. Cada lote contiene hasta 100 compras y **todos** sus pagos, sin imágenes. El cursor evita repetir compras si se insertan facturas mientras se exporta.

El botón **Exportar** permite elegir PDF o Excel (.xlsx) después de consultar resultados. Exporta todas las compras de los filtros aplicados, no sólo la página visible. Cada compra incluye proveedor, factura, tipo, fecha, subtotal, ISV, descuento, total, abonos, pagos aplicados y saldo, además del detalle completo de los pagos. Los grandes totales aparecen al final. Los totales exportados se calculan desde las mismas facturas y pagos incluidos en el archivo; las notas de crédito cuentan como aplicado, no como abono. Excel conserva importes numéricos y fórmulas para pagos, saldos y totales generales; los textos de proveedores y comprobantes se escriben explícitamente como texto. Si falla cualquier lote, no se descarga un archivo parcial.

La respuesta del reporte contiene `items`, `meta` y `totals` dentro de `data`. Los totales siempre abarcan **todas** las facturas que cumplen los filtros, sin depender de la página visible. Se incluyen los mismos estados de compra que en Compras, incluidos borradores.

El subtotal, ISV, descuento y total se suman desde los importes almacenados de las facturas. El gran total es la suma de sus totales netos; los abonos se muestran por separado y no se descuentan de este importe. Al seleccionar crédito se muestran los abonos por factura y su suma global. Solamente se contabiliza `compra_transc.tipo = 2` (Abono), excluyendo pagos al contado y notas de crédito. La columna Aplicado conserva su significado habitual: todas las transacciones aplicadas a la factura.

Después del total de abonos se muestra el saldo pendiente global: suma de los saldos de cada factura filtrada, descontando todas sus transacciones aplicadas (incluidas notas de crédito) y limitando cada saldo a cero para que una factura sobrepagada no reduzca la deuda de otra. El cálculo incluye todas las páginas.

Aplicar las migraciones del reporte:

```powershell
php artisan migrate --path=database/migrations/2026_10_17_000005_register_purchase_report_permissions.php --force
php artisan migrate --path=database/migrations/2026_10_17_000006_register_purchase_report_payments_permission.php --force
php artisan migrate --path=database/migrations/2026_10_17_000007_register_purchase_report_export_permission.php --force
```
