# Almacenamiento privado de actualizaciones Shadai

## Estado de la fase 2

Implementada y verificada contra Cellar. Se creó `shadai-updates` en el servicio
existente, con ACL del propietario y sin política pública. El bucket de imágenes,
sus objetos y CORS se conservan. La configuración local del backend habilita solo
el servicio de almacenamiento al cerrar esta fase. La API posterior está documentada
en [fase 4](desktop-update-api.md).

Prueba real con un objeto sintético pequeño, eliminado al terminar:

| Comprobación | Resultado |
| --- | --- |
| Bucket y objeto privados | Confirmado |
| GET y PUT anónimos | Denegados |
| HEAD firmado, Content-Length y application/msix | Correctos |
| GET firmado y SHA-256 | Correctos |
| Range bytes=0-15 | HTTP 206 y bytes correctos |
| URL vencida | HTTP 403 |
| Limpieza del objeto propio de prueba | Completada |

Los instaladores de validación de la fase 1 siguen sin firma: no se subieron como
releases. La primera carga de producción requiere un MSIX firmado y aprobado.
La consulta final del namespace de actualizaciones confirmó cero objetos restantes.

## Configuración

El disco `desktop_updates` es independiente del disco `s3` de imágenes. Reutiliza
las credenciales de Cellar del backend cuando no se configuran otras, pero siempre
exige un bucket diferente. No cambiar `AWS_BUCKET` para habilitar actualizaciones.

```dotenv
DESKTOP_UPDATE_STORAGE_ENABLED=true
DESKTOP_UPDATE_BUCKET=shadai-updates
DESKTOP_UPDATE_PREFIX=desktop-updates
DESKTOP_UPDATE_URL_SECONDS=3600
DESKTOP_UPDATE_MAX_BYTES=1073741824
```

La plantilla `.env.example` mantiene la función deshabilitada por defecto; aplicar
esta configuración en cada entorno después de verificar acceso y privacidad.
HTTPS conserva la validación CA existente. Las URLs duran una hora por defecto;
la política admite entre 300 y 21600 segundos. El límite inicial de archivo es 1 GiB.
No se configura URL pública ni se alteran reglas CORS: el cliente Windows es nativo.

Se pueden suministrar `DESKTOP_UPDATE_ACCESS_KEY_ID`, `DESKTOP_UPDATE_SECRET_ACCESS_KEY`,
`DESKTOP_UPDATE_ENDPOINT` y `DESKTOP_UPDATE_REGION` para otra identidad del servidor.
Cellar no ofrece usuarios con permisos distintos dentro del mismo addon: sus claves
maestras acceden a todos sus buckets. El aislamiento mediante otra identidad requiere
otro addon y una política de acceso cruzado. No entregar las claves maestras al
cliente ni al futuro CI; definir esa identidad antes de automatizar publicación.

## Direcciones nuevas para cada carga

```text
desktop-updates/releases/{producto}/{versión}/{arquitectura}/{sha256}/{object_id}.msix
```

`object_id` es un identificador aleatorio de 256 bits generado por el servidor en cada
carga. Nunca se toma de una solicitud de cliente para subir un archivo. El servicio
comprueba ausencia antes del PUT y no reutiliza direcciones; una repetición genera
otro objeto y deja intacto el anterior. El catálogo de fase 3 guarda el `object_id`
y la clave exacta devueltos al subir, y autorizar esa referencia concreta al descargar.

La prueba real encontró una particularidad: el PUT duplicado con `If-None-Match: *`
respondió HTTP 200 aunque conservó los bytes originales. No interpretar ese HTTP 200
como una carga nueva ni depender de recibir 412. La estrategia de producción usa
identificadores nuevos y hash, sin depender del condicional del proveedor.

Tras subir se verifica HEAD, MIME, tamaño y metadatos; además se lee el contenido
remoto en bloques y se compara su SHA-256 con el archivo local. Se envía Content-MD5
para integridad de transporte. Las descargas llevan `private, no-store`.

La cuenta propietaria conserva administración sobre objetos. Esta estrategia no es
Object Lock ni retención WORM. No se activaron versionado, lifecycle ni retenciones
irreversibles. Conservar los paquetes referenciados por releases; no hay borrado
automático de instaladores. Revisar capacidad y costos del plan antes de la primera
carga real. La herramienta solo elimina probes de su propio espacio aleatorio.

## Herramientas

Desde `backend/apishadai`, con PHP del proyecto:

```powershell
# Plan local: sin red ni escrituras.
php artisan desktop-updates:storage

# Inspección de propiedad, ACL y política; no escribe.
php artisan desktop-updates:storage --check

# Creación solo si falta y prueba sintética; no cambia un bucket público existente.
php artisan desktop-updates:storage --apply --create-bucket --probe

# Verificar metadatos y paquete local sin subir.
php artisan desktop-updates:upload RUTA_RELEASE_JSON RUTA_MSIX_FIRMADO

# Cargar un paquete aprobado en una dirección nueva.
php artisan desktop-updates:upload RUTA_RELEASE_JSON RUTA_MSIX_FIRMADO --apply
```

El comando de carga requiere `SchemaVersion=1`, producto/identidad/arquitectura válidos,
`HasSignature=true` y `DistributionEligible=true` del proceso de empaquetado. Comprueba
tamaño, hash, manifiesto y presencia de firma del MSIX. La verificación criptográfica
de confianza de la firma corresponde al proceso Windows/SignTool de la fase 1; no
se sustituye por la inspección estructural de PHP. Los metadatos de entrada son
artefactos locales de un proceso de publicación confiable, no datos de una API pública.

Preparar o subir no publica la versión ni concede una licencia. El catálogo con sus
permisos está implementado en [fase 3](desktop-release-catalog.md). La autorización por licencia, aplicación y equipo,
con solicitudes firmadas, corresponde a fase 4. Solo después de esa autorización
se invocará `temporaryDownload($artifact, $objectId)` desde el servidor.

El enlace temporal es una capacidad de lectura compartible hasta vencer; el servidor
no registra URLs ni secretos. Una revocación impide emitir nuevas autorizaciones,
pero no retira copias descargadas ni convierte el enlace en una identidad de equipo.

## Validaciones

Las pruebas de `DesktopUpdateStorageTest` usan SDK simulado y paquetes sintéticos;
no leen SQL ni Cellar. Cubren rechazo de salidas sin firma, metadatos falsos, producto
ajeno, arquitectura, bucket de imágenes, acceso público, namespace, escritura pública,
colisiones, nuevas direcciones, contenido remoto corrupto, expiración y modo preparación.
Se ejecutaron además regresiones de imágenes y licencias sobre sus datos aislados.
Resultado final: **72 pruebas y 244 verificaciones aprobadas**, con formato Pint aprobado.

El reporte operativo sin URLs queda en
`storage/app/private/desktop-updates/storage-verification.json`.

Referencia: [documentación oficial de Cellar](https://www.clever.cloud/developers/doc/deploy/storage/cellar),
incluidas las secciones Policies, User access y presigned URLs.
