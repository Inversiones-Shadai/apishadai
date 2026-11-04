# Catálogo de actualizaciones Shadai — fase 3

## Estado y alcance

Implementado en el backend central y ShAdmin. Las dos migraciones nuevas se aplicaron
exclusivamente a `shadaidb` local (`127.0.0.1`); no se desplegó a producción. El catálogo
inicia vacío, con canales preparados por producto y arquitectura. No se crearon
licencias, activaciones, paquetes o publicaciones de prueba en la base real.

ShAdmin ofrece **Config → Actualizaciones de escritorio**, ruta
`/config/desktop-releases`: listado, filtros, notas, requisitos de compatibilidad,
borradores, selección de equipos piloto, promoción, retiro y auditoría.
Solo muestra la entrada a quienes tienen permiso de listado.

Esta fase administra publicaciones. La API de consulta y autorización por licencia
está implementada en [fase 4](desktop-update-api.md); la instalación automática
corresponde a la fase 5. El indicador de actualización requerida se almacena como
política; todavía no obliga a actualizar ninguna aplicación instalada.

La firma y el piloto de instalación de fase 1 siguen pendientes de identificar el
certificado existente. Los paquetes de validación sin firma no son distribuibles.

## Permisos individuales

Vista `upd`, módulo `cfg`. La migración registra los códigos y no concede permisos
a usuarios ni roles. Asignarlos mediante el flujo existente de permisos y renovar
la sesión de ShAdmin si su catálogo de permisos estaba en caché.

| Código | Acción |
| --- | --- |
| `updbtn01` | Listar versiones y ver notas/requisitos |
| `updbtn02` | Consultar paquetes verificados y crear borradores |
| `updbtn03` | Editar un borrador |
| `updbtn04` | Consultar y asignar activaciones piloto |
| `updbtn05` | Publicar un borrador en piloto |
| `updbtn06` | Promover el piloto vigente a estable |
| `updbtn07` | Retirar una versión, indicando motivo |
| `updbtn08` | Consultar auditoría |

Cada endpoint vuelve a comprobar el usuario autenticado, la asignación individual
vigente, la jerarquía módulo/vista/acción y los estados activos. Exige `Action-Btn`
con el código propio de la operación. Listar, crear, publicar y auditar son permisos
distintos. El permiso de listado por sí solo no habilita ninguna escritura.

## Preparar un paquete y crear el borrador

1. Generar el MSIX y `release.json` con las herramientas Windows de fase 1, firma
   válida y `DistributionEligible=true`. El JSON proviene del proceso de publicación
   confiable y no se recibe de una aplicación cliente ni de un formulario web.
2. Desde el backend autorizado, ejecutar la carga de fase 2 y conservar el
   `object_id` exacto devuelto. Cada carga genera una dirección nueva; no sobrescribir
   objetos publicados.
3. Registrar esa referencia, verificando privacidad, MIME, tamaño y SHA-256 completo
   del objeto remoto:

   ```powershell
   php artisan desktop-updates:register-artifact "RUTA/release.json" OBJECT_ID --apply
   ```

   Sin `--apply` solo valida la entrada y no consulta Cellar ni escribe el catálogo.
   El comando no publica una versión. Repetirlo con el mismo objeto y metadatos
   devuelve el mismo `artifact_id`. No registrar rutas de archivos ni URLs temporales
   como origen permanente. No entregar credenciales maestras de Cellar al futuro CI.
4. En ShAdmin, seleccionar el paquete verificado para crear un borrador, ingresar
   notas, contrato API mínimo y rango de versión de esquema SQL compatible. Un máximo
   vacío significa sin límite superior adicional. Esos requisitos deben probarse
   antes de promover. Elegir recomendada o requerida como política del release.
5. Asignar al menos una activación piloto con licencia vigente del mismo producto,
   publicar en piloto, verificarlo y promover a estable con el permiso correspondiente.

La ruta privada, objeto, identidad MSIX, arquitectura, versión, hash y tamaño quedan
fijos en el artefacto. La web solo envía su UUID; no puede introducir otra ruta,
versión, producto o estado. Solo se ofrece un artefacto todavía no usado en un release.
Producto/versión/arquitectura son únicos, incluso para versiones retiradas.

El registro conserva únicamente metadatos necesarios del proceso de empaquetado.
No guarda rutas del equipo que compiló ni URLs firmadas. La verificación criptográfica
de confianza y sello de tiempo continúa en Windows/SignTool; Laravel no sustituye
esa validación con la mera presencia de `AppxSignature.p7x`.

## Canales y transiciones

`draft → pilot → stable`; cualquier estado todavía no retirado puede pasar a
`withdrawn`. Retirado es terminal. Solo se editan notas, compatibilidad y política
de un borrador. Los equipos piloto se pueden modificar en borrador o piloto.

Solo se asignan activaciones no revocadas de licencias habilitadas, no vencidas y
del mismo producto. Se comprueban de nuevo al publicar en piloto y promover a
estable, bloqueando las filas elegibles dentro de la transacción. Una selección
revocada o vencida impide la promoción; no se ignora silenciosamente.

Cada combinación producto/arquitectura tiene un piloto y una estable vigentes.
Las versiones anteriores conservan historial y se muestran como históricas.
Solo el piloto vigente puede promoverse a estable. La versión debe superar las
ya promovidas al canal; los máximos se conservan después de un retiro. Comparación
numérica de cuatro componentes, no orden lexicográfico de la versión visible.

Antes de promover se vuelve a verificar la referencia privada y el SHA-256 completo.
La lectura de Cellar ocurre antes de abrir la transacción SQL para no mantener
bloqueos durante una transferencia. Los límites de tiempo y tamaño de un entorno
deben permitir verificar sus paquetes; la prueba con MSIX real forma parte del piloto.

Retirar limpia los punteros vigentes que apuntaban a ese release. No promueve de
forma automática otra versión, no hace downgrade, no borra el paquete y no revoca
una copia descargada ni un enlace emitido. La fase 4 consulta los punteros
vigentes y estado, junto con licencia, activación, arquitectura y compatibilidad;
no basta con buscar cualquier registro cuyo estado sea `stable` o `pilot`.

## Concurrencia, reintentos y auditoría

Toda mutación lleva `request_id` UUID; edición y transiciones también llevan
`expected_revision`. El diario persistente vincula UUID, actor y hash canónico del
contenido: ordenar claves JSON de otra manera no crea una operación distinta.
Una repetición idéntica devuelve el resultado confirmado sin repetir cambios ni
eventos. Reutilizar el UUID para otro actor o contenido da HTTP 409. Una revisión
desactualizada también da 409 y exige recargar el registro.

Las rutas nuevas excluyen el middleware genérico de idempotencia, cuya respuesta
en caché precede a la autorización específica. Utilizan su propio diario después
de comprobar permisos actuales: revocar un permiso bloquea también el reintento.
El orden de bloqueo es operación, canal, release y activaciones/licencias elegibles.

ShAdmin conserva en la sesión del usuario el UUID y contenido exactos de una
operación cuyo resultado no se confirmó. Reintentar o recargar mantiene ese mismo
contenido; no permite iniciar otro cambio mientras está pendiente. Los errores
definitivos 400/404/409/422 liberan esa solicitud. En conflicto 409 cierra el
formulario desactualizado y recarga el listado. Un 403 conserva el pendiente.

Cada mutación confirmada registra actor, fecha UTC, revisión, acción, activaciones,
motivo de retiro y snapshot del release, en la misma transacción que el cambio.
No hay endpoints para editar o borrar esos eventos. Las migraciones rechazan un
rollback destructivo del historial; no equivalen a almacenamiento WORM frente a
un administrador con acceso directo a la base de datos.

## Tablas y despliegue explícito

Seis tablas InnoDB: `desktop_release_artifacts`, `desktop_releases`,
`desktop_release_tracks`, `desktop_release_pilot_devices`, `desktop_release_events`
y `desktop_release_operations`. No se ejecutan migraciones al arrancar una app.

```powershell
php artisan migrate --path=database/migrations/2026_10_28_000001_create_desktop_release_catalog.php --path=database/migrations/2026_10_28_000002_register_desktop_release_permissions.php --force
```

Aplicar a cada entorno como despliegue controlado. La simulación `migrate --pretend`
no puede validar el seeder de permisos: sus lecturas son simuladas y devuelven vacío.
Comprobar previamente módulo `cfg`, estado `ACTIVO`, códigos libres y tablas de
licencias. No ejecutar el resto de migraciones pendientes para instalar esta fase.

## Evidencia de validación

- Backend: **100 pruebas, 445 verificaciones** de catálogo, permisos, registro,
  almacenamiento privado, licencias e imágenes. SQLite aislado y SDK simulado.
- ShAdmin: **37 pruebas, 291 verificaciones** de pantalla, permisos y licencias;
  incluye selección piloto entre páginas, auditoría escapada y recuperación de
  solicitudes tras un corte de conexión. API simulada, sin usuarios reales de prueba.
- Pint de los archivos nuevos/cambiados, compilación Vite y vistas Blade aprobados.
- Dos migraciones aplicadas al MySQL local. Catálogo y auditoría iniciales vacíos;
  ocho acciones registradas y ninguna asignación automática de permisos.

Referencias del proyecto: [almacenamiento privado](desktop-update-storage.md) y
[plan general](../../../headquaters/hq-client/HQ-Client/docs/desktop-updates.md).
