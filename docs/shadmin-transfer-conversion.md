# Conversión padre a hijo en transferencias ShAdmin

En la preparación de una transferencia, seleccionar el producto padre y su lote,
usar **Convertir a hijo**, elegir un hijo relacionado y las presentaciones padre
a abrir, y confirmar **Convertir y agregar hijo**. El hijo se agrega al borrador
con todas las unidades generadas; su cantidad de envío se puede editar.

La conversión afecta el inventario al confirmar y se conserva aunque se cierre
la transferencia sin enviarla. Los padres ya incluidos en el borrador se excluyen
de la cantidad disponible para convertir. Los lotes vencidos se rechazan.

Reglas de presentación v2, iguales a ShPOS:

- Unidades generadas = padres abiertos × `cantidad_c` del padre.
- Costo por unidad base hijo = costo padre / `cantidad_c` padre.
- Precio de venta hijo = precio padre × `cantidad_v` hijo / `cantidad_c` padre.
- `cantidad_c` del hijo no define el rendimiento.
- Se conservan lote, fechas, referencia de compra, bonificación, ISV y descuentos
  de distribución DTO1–DTO4. Cada conversión crea un lote separado para conservar
  su costo y condiciones comerciales.

API: `GET farma/transferencias/conversion/{lote}` ofrece los hijos compatibles;
`POST farma/transferencias/convertir` recibe `request_id` UUID, `lote_padre`,
`producto_hijo` y `cantidad` (1–999). Ambas rutas exigen el permiso individual
existente `trfbtn04`. El stock se obtiene del dispositivo autenticado.

La conversión, el descuento del padre, la distribución del hijo y el resultado
se guardan en una sola transacción. `transferencia_conversiones` conserva la
respuesta por UUID, usuario, stock y datos de solicitud. Un reintento idéntico
devuelve el resultado original; cambiar los datos del UUID se rechaza. ShAdmin
conserva la solicitud incierta en sesión y permite recuperarla al reabrir la
preparación de transferencias.

Migraciones específicas:

```powershell
php artisan migrate --path=database/migrations/2026_10_24_000001_allow_shared_product_barcodes.php --path=database/migrations/2026_10_24_000002_transfer_product_conversions.php --force
```

La primera permite códigos de barras compartidos en productos, incluidos los
registrados desde compras, y mantiene un índice de búsqueda normal. El código
interno del producto continúa siendo único. Su reversión se rechaza cuando ya
existen barras compartidas; la segunda protege el historial de conversiones.

Verificación: `TransferDomainTest` y `ProductDomainTest` en la API;
`TransferManagementTest`, `TransferApiServiceTest`, `InventoryMatrixTransferTest`
y `PurchaseManagementTest` en ShAdmin. Las pruebas usan SQLite en memoria y APIs
simuladas, sin operaciones reales de inventario.
