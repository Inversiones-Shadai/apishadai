# Lotes próximos a vencer y reglas de proveedores

ShAdmin incluye **Farma > Productos > Próximos a vencer** (`/farma/vencimientos`) y una vista independiente **Farma > Reglas de proveedores** (`/farma/reglas-proveedores`).

## Uso

1. Configurar las condiciones reales del proveedor: meses de anticipación para enviar, aceptación de **productos hijos / empezados** y devolución directa, con autorización o no aceptada. Las observaciones se muestran al verificar.
2. Al registrar una compra, marcar **Bonificación** en cada renglón de producto y lote que corresponda. Se puede agregar el mismo producto en renglones normales y bonificados, incluso con el mismo código de lote. El indicador se guarda en `compra_detalle.es_bonificacion` y se copia a `lotes.es_bonificacion` al enviar la compra al Kardex, manteniendo separados los renglones. Se admite costo cero únicamente para bonificaciones; los importes se calculan con los valores ingresados. Los costos bonificados no sustituyen el último costo de compra normal.
3. Buscar por código, código de barras o descripción; las imágenes usan el permiso existente `prdbtn06`. Opcionalmente filtrar vencidos y próximos 3, 6, 12 o 24 meses.
4. Abrir los lotes y agregar los deseados. La lista permite mezclar productos y compras y evita repetir el mismo ID de lote.
5. Pulsar **Verificar lista de lotes**. La presentación se obtiene del catálogo: padre = completo; hijo = fraccionado o empezado. No hay un selector manual de estado físico.

El proveedor siempre procede de `lotes.compra -> compras.proveedor`. La API vuelve a consultar el producto, el lote y las reglas al verificar; no acepta proveedor, bonificación ni reglas enviados desde el navegador de consulta. Los hijos se identifican por `productos.producto_padre_id`. La vista de vencimientos muestra proveedor, fecha límite de envío, vencimiento, aceptación de hijos/empezados, bonificación y modalidad de devolución, sin exponer el número de factura.

**Hijo y empezado son una sola condición de negocio.** La regla canónica es `acepta_hijos`: un padre no puede marcarse como empezado y un hijo no puede declararse completo para eludir esta regla. La columna anterior `acepta_empezados` se conserva como reflejo de `acepta_hijos`; la migración de unificación corrige discrepancias conservando el valor de `acepta_hijos` e incrementa la revisión sólo de las reglas corregidas. El formulario y el resumen muestran una sola opción.

**Las bonificaciones no aceptan devolución**, independientemente de las reglas del proveedor, incluso si todavía falta configurar sus reglas. No existe una opción para permitir devolverlas.

## Interpretación

- **Apto para devolución directa:** cumple todas las condiciones evaluables y el proveedor acepta devolución directa.
- **Requiere autorización:** cumple las condiciones pero el proveedor exige gestión previa.
- **No cumple las reglas:** hay una condición que rechaza el proveedor, está vencido o pasó la fecha límite de envío.
- **Pendiente de confirmar:** faltan reglas, proveedor, fecha o un dato necesario para decidir.

La fecha límite es vencimiento menos los meses de anticipación configurados. Se usan meses de calendario sin desbordamiento (31 de marzo menos un mes = 28/29 de febrero) y fecha local `America/Tegucigalpa`. La fecha límite está incluida; el día del vencimiento ya se considera vencido. El plazo `compras.plazo` sigue siendo exclusivamente de pago y no interviene en esta evaluación.

La marca de escala por factura fue reemplazada por bonificación por producto/lote. Los campos antiguos `compras.en_escala` y `proveedor_reglas_devolucion.acepta_escala` permanecen en la base por compatibilidad, pero ya no intervienen en este flujo. No se convierten escalas antiguas a bonificaciones: los registros existentes y las nuevas líneas sin marca quedan con `es_bonificacion = false`. Sólo un lote registrado como bonificación se trata como tal. Los lotes sin trazabilidad de compra no reciben un proveedor inferido.

La verificación es una consulta de condiciones; no registra una devolución, no reserva ni descuenta inventario y no calcula existencia actual desde la cantidad original del lote. La selección y sus resultados duran mientras permanece abierto el componente. Cambiar la selección invalida los resultados anteriores. Las reglas se guardan por proveedor con revisión para evitar sobrescribir cambios concurrentes; las observaciones requieren revisión humana.

## Permisos

Módulo `frm`; registrar catálogo no asigna permisos automáticamente.

| Vista | Acción | Uso |
| --- | --- | --- |
| `vnc` | `vncbtn01` | Buscar productos y abrir Próximos a vencer |
| `vnc` | `vncbtn02` | Consultar y seleccionar lotes |
| `vnc` | `vncbtn03` | Verificar lista de lotes |
| `rdp` | `rdpbtn01` | Consultar proveedores y sus reglas |
| `rdp` | `rdpbtn02` | Guardar reglas |

ShAdmin exige las capacidades por ruta y acción Livewire; la API valida el usuario, `Action-Btn`, la jerarquía módulo/vista/acción, estados activos y vigencia del permiso. El check de bonificación usa los permisos existentes de creación/modificación de compras.

## API

| Método y ruta bajo `/api/farma` | `Action-Btn` |
| --- | --- |
| `GET vencimientos/productos` | `vncbtn01` |
| `GET vencimientos/producto/{product}/lotes` | `vncbtn02` |
| `POST vencimientos/verificar` | `vncbtn03` |
| `GET reglas-proveedores/listar` | `rdpbtn01` |
| `GET reglas-proveedores/{provider}` | `rdpbtn01` |
| `PUT reglas-proveedores/{provider}` | `rdpbtn02` |

Consultas paginadas con `page`, `per_page` (hasta 100), `search` (100 caracteres) y, para productos/lotes, `meses` opcional. Verificación: `{"lotes":[{"id":123}]}`, máximo 100 IDs distintos. El antiguo campo opcional `producto_empezado` se admite por compatibilidad pero se ignora: la clasificación procede exclusivamente de `producto_padre_id`. La respuesta incluye `verificado_el` y `resultados` con procedencia, estado, motivos, reglas y fecha límite.

Reglas: `meses_anticipacion` (0–60), `acepta_hijos` (hijos / empezados), `tipo_devolucion` (`directa`, `autorizacion`, `no_acepta`), `notas` y `revision` (0 al crear; revisión consultada al editar). Un conflicto devuelve 409 y exige recargar. Si un cliente antiguo envía también `acepta_empezados`, debe coincidir con `acepta_hijos`; valores contradictorios devuelven 422. La respuesta publica únicamente `acepta_hijos`.

Las solicitudes de creación/edición de compras incluyen `detalles[].es_bonificacion` (booleano, predeterminado `false`). El guardado de detalles de borrador admite el mismo campo. La respuesta de cada detalle y cada lote consultado en vencimientos conserva esta marca. El indicador global `en_escala` no se usa.

Aplicar únicamente estas migraciones para habilitar este flujo:

```powershell
php artisan migrate --path=database/migrations/2026_10_20_000002_supplier_return_rules.php --path=database/migrations/2026_10_20_000003_supplier_return_permissions.php
php artisan migrate --path=database/migrations/2026_10_20_000004_purchase_line_bonuses.php
php artisan migrate --path=database/migrations/2026_10_20_000005_unify_supplier_child_rule.php
```

Validación: `tests/Feature/Farma/ExpiryWorkflowTest.php` en ambos proyectos y pruebas de compras existentes. El backend usa SQLite en memoria en esas pruebas.
