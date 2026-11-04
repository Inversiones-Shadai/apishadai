# API de actualizaciones Shadai — fases 4 y 5

## Estado

Implementada en la autoridad central y HQ API, con pruebas aisladas de firmas,
licencias, canales, compatibilidad, revocaciones y fallas de red. Aplicada únicamente
la migración nueva de auditoría en `shadaidb` local (`127.0.0.1`), habilitada la API
local y comprobada la firma de la autoridad contra la clave pública que comparten
las tres aplicaciones.
No se cambiaron claves ni credenciales. No se desplegó a producción.

El catálogo y la auditoría locales siguen vacíos: no se emitieron autorizaciones
reales ni se publicaron paquetes sintéticos. Se creó un certificado MSIX autofirmado
de piloto y tres copias para comprobar firma, sin publicarlas. El primer release
necesita habilitar confianza en el equipo piloto y completar la instalación real de fase 1. Ese
certificado de instaladores es distinto de la clave RSA existente de la autoridad.

Las tres aplicaciones consumen este protocolo mediante el actualizador común de
fase 5. La integración y las pruebas aisladas están listas; la instalación real
firmada sigue pendiente del certificado y del piloto. No se migra SQL Server.

## Recorrido y endpoints

Aplicación → HQ API → autoridad central. El equipo presenta su prueba RSA existente;
HQ agrega el HMAC del canal existente y retransmite el envelope firmado de respuesta.
La aplicación deberá verificar esa firma con la clave pública confiable de licencias.

| Operación | HQ API | Autoridad central |
| --- | --- | --- |
| Consultar actualización | `POST /api/desktop/updates/check` | `POST /api/headquarters/updates/check` |
| Emitir o renovar enlace | `POST /api/desktop/updates/download` | `POST /api/headquarters/updates/download` |
| Revalidar antes de instalar | `POST /api/desktop/updates/apply` | `POST /api/headquarters/updates/apply` |
| Informar resultado al reiniciar | `POST /api/desktop/updates/report` | `POST /api/headquarters/updates/report` |

No requieren login de un usuario. La autoridad central exige el HMAC del HQ
autorizado, su IP permitida y nonce; después comprueba la prueba individual del
equipo. HTTPS es obligatorio en producción. El relay permite HTTP únicamente a
loopback en local/testing, no sigue redirecciones y no autoriza localmente si la
autoridad central falla. Credenciales de Cellar y claves privadas quedan en servidores.

## Prueba firmada de solicitud

Se conserva el envelope de licencias: `payload` contiene Base64 del JSON UTF-8;
`signature` contiene Base64 de la firma RSA/SHA-256 de esos bytes JSON originales.
Claves RSA de 3072 a 4096 bits, del equipo activado. Cada intento lleva un nonce
UUID nuevo y timestamp UTC reciente, incluso después de un timeout o HTTP 503.
No reutilizar aquí la política de UUID de las mutaciones administrativas de ShAdmin.

Contenido del JSON antes de codificar y firmar:

```json
{
  "action": "updates.check",
  "product": "shpos",
  "activation_id": "UUID_DE_ACTIVACION",
  "device_hash": "HASH_SHA256_DEL_EQUIPO",
  "device_name": "Caja principal",
  "public_key": "CLAVE_PUBLICA_RSA_PEM",
  "timestamp": 1793275200,
  "nonce": "UUID_NUEVO",
  "current_version": "1.9.0.0",
  "architecture": "x64",
  "api_contract": 1,
  "schema_version": 258
}
```

Para `download` y `apply`, cambiar `action` respectivamente a `updates.download`
y `updates.apply`, e incluir `release_id` y `release_revision` obtenidos de la
consulta. Para `apply`, incluir además `package_sha256` del paquete verificado
localmente. La API compara esa referencia con la versión que selecciona nuevamente;
un UUID enviado por el cliente no permite pedir otro paquete.

Se rechazan campos desconocidos, rutas, bucket, canal elegido por el cliente y
claves de licencia. `check` no admite seleccionar un release. La versión instalada
usa cuatro componentes numéricos canónicos, hasta 65535 cada uno; arquitecturas
`x64`, `x86` o `arm64`. Contrato API: 1–65535; esquema: 0–65535.

La firma prueba posesión de la clave vinculada y liga el contenido a ese equipo;
no constituye una atestación remota de su versión o esquema. El actualizador deberá
leer esos valores localmente y volver a verificar compatibilidad, hash e identidad.

## Informe de instalación (fase 5)

`updates.report` usa la misma prueba RSA, HMAC, protección contra replay y límites.
Además de los campos comunes incluye `authorization_id` del permiso `updates.apply`,
`release_id`, `release_revision`, `result` (`installed` o `failed`) y `failure_code`
(`""` para éxito; `update_not_completed` para fallo). `current_version` describe la
versión observada al confirmar el reinicio (un recibo pendiente puede llegar después
de otra actualización). El cliente confirma identidad MSIX y FileVersion del
binario antes de informar éxito; el servidor valida que coincida con la versión autorizada.
Es un resultado reportado por el equipo, no una atestación remota de Windows.

La autoridad exige el permiso original autorizado del mismo producto y activación,
clave y equipo. Puede recibir ese resultado aunque la licencia haya vencido o sido
revocada posteriormente: solo registra auditoría; nunca concede acceso, URL o permiso
nuevo. El almacenamiento puede estar deshabilitado; la API y la firma deben seguir
habilitadas. La tabla `desktop_update_installation_reports` conserva un resultado por
autorización. Reintentar el mismo resultado con nonce nuevo devuelve el mismo acuse;
cambiar el resultado o la versión de una autorización ya informada devuelve HTTP 409.

El acuse firmado tiene `action=updates.report`, `status=recorded`, el mismo
`authorization_id`, `release=null`, `download=null` y caducidad máxima de 60 segundos.
El cliente rechaza un acuse de otra autorización o con permisos/paquete/enlace.
Si no llega el acuse se conserva el resultado local protegido y se reintenta en el
siguiente arranque, conservando recibos por intento aunque se actualice otra vez;
no se bloquea el inicio por una falla del informe. Una autorización
emitida sin recibo sigue siendo una instalación sin confirmar.

La migración `2026_10_29_000002_create_desktop_update_installation_reports.php` se aplicó
solo en `shadaidb`, `127.0.0.1`. Catálogo, artefactos, eventos e informes locales: cero.
Validación actual: 142 pruebas / 910 verificaciones de backend (escritorio e imágenes)
y 26 / 157 de HQ API (relays y seguridad HTTP). No se publicaron paquetes reales.

## Selección y autorización

- Licencia habilitada, vigente y exclusiva del producto solicitado; activación no
  revocada, `device_hash` y hash de clave pública iguales a los registrados.
- Selección entre la estable vigente y el piloto vigente asignado a esa activación,
  por producto y arquitectura. Se elige la más nueva compatible con versión instalada,
  contrato API y rango de esquema. No se entregan borradores, retiradas ni históricas.
- No se ofrecen versiones iguales o inferiores a la instalada. Si no existe una
  opción compatible, se devuelve `no_update`, sin paquete ni enlace.
- Se valida la referencia fija del catálogo: identidad, producto, versión, arquitectura,
  objeto, bucket, SHA-256 y tamaño. No se construye una ruta desde datos del cliente.
- `check` devuelve metadatos firmados, sin URL. `download` confirma privacidad del
  bucket y HEAD del objeto registrado antes de firmar su lectura. La verificación
  completa de bytes ocurrió al registrar/promover; el cliente comprobará SHA-256.
- Las lecturas de Cellar y la preparación del enlace suceden fuera de transacciones
  SQL. Después se bloquean canal, releases vigentes, licencia y activación, y se
  revalida todo antes de devolver la autorización. Una revocación, cambio de release,
  asignación piloto o vencimiento durante la espera descarta el enlace preparado.

El enlace dura como máximo el TTL de almacenamiento, una hora por defecto, limitado
también por el vencimiento de la licencia. Renovarlo exige otra prueba y otra
validación online; nunca se renueva por haber descargado anteriormente.

## Respuesta y uso por el actualizador

Se conserva el envelope firmado de licencias. **La firma de respuesta se comprueba
sobre el texto Base64 de `payload`**, no sobre el JSON decodificado. El payload
decodificado incluye:

- `protocol=shadai-desktop-updates`, `version=1`, `action`, `authorization_id`,
  `status` y `reason`.
- `request_nonce`, producto, activación, hashes de equipo/clave, arquitectura,
  versión instalada y compatibilidad de la solicitud.
- `issued_at` y `expires_at`, segundos Unix UTC.
- `release`: UUID/revisión, producto, versión, arquitectura, canal, SHA-256,
  tamaño, identidad MSIX, publicador, notas, requisitos y política requerida.
- `download`: solo en una descarga autorizada; `url`, `expires_at` Unix y
  `expires_in_seconds`. En el resto de respuestas es `null`.

| Estado firmado | Tratamiento |
| --- | --- |
| `available` | Metadatos disponibles; todavía no hay enlace ni permiso de instalación |
| `authorized` | Permiso para la acción firmada, dentro de su vigencia |
| `no_update` | Sin actualización compatible; no instalar otro release |
| `denied` | Sin autorización ni URL; atender el motivo de licencia, equipo o paquete |
| `retry_required` | Cambió el estado; consultar otra vez y generar una prueba nueva |
| `unavailable` | HTTP 503, sin autorización utilizable; no sustituir por permisos offline |

HTTP 200 por sí solo no autoriza: verificar la firma, protocolo, acción, solicitud,
identidades, tiempos y estado. Errores de prueba/autenticación usan HTTP 401/403/422;
nonce ya usado, 409; límites, 429. Respuestas de API sin caché y sin URLs en mensajes
de error. El relay devuelve 503 ante respuesta incompleta, excesiva o redirigida.

`apply` no genera un enlace. Emite un permiso ligado al release, revisión y hash,
por un máximo de **60 segundos**, limitado por la vigencia de licencia. No concede
las 72 horas offline del funcionamiento normal ni renueva el cupo de activación.
El actualizador oficial deberá solicitarlo online justo antes de aplicar y comprobar
su vigencia antes de delegar en Windows/MSIX. La aplicación conserva su validación
normal de licencia después del reinicio.

Un enlace ya entregado puede utilizarse hasta vencer; retirar un release o revocar
la activación bloquea autorizaciones nuevas, pero no elimina archivos descargados
ni invalida instantáneamente permisos ya emitidos. Firma MSIX y licencia en ejecución
siguen siendo controles necesarios.

## Auditoría y límites

`desktop_update_events` registra cada decisión de una prueba válida: autorización,
nonce, activación/licencia, acción, resultado, motivo, compatibilidad, release y
revisión entregados y tiempos. No almacena URL temporal, envelope, firma, clave
pública/privada ni credenciales. No registra una descarga como instalación exitosa.
Sin endpoints de edición o borrado de este historial.

Los nonces se consumen en una transacción independiente antes de consultar Cellar,
compartiendo el registro antirrepetición de licencias. Una falla posterior no deja
la prueba reutilizable. La ventana de timestamp es de cinco minutos y el nonce
se retiene diez. Se usan UUID canónicos en minúsculas.

Límites iniciales: 30 solicitudes/minuto por clave de equipo validada en la autoridad,
120/minuto por IP en la ruta central y 30/minuto por IP en HQ API, además de los
límites globales existentes. Ajustarlos durante el piloto según tamaño y distribución
de la flota; los límites de red no reemplazan la autorización por dispositivo.

## Despliegue y comprobaciones

```powershell
php artisan migrate --path=database/migrations/2026_10_29_000001_create_desktop_update_events.php --force --no-interaction
php artisan config:clear --no-interaction
```

Después de preparar almacenamiento, firma de autoridad y canal HQ existentes,
habilitar `DESKTOP_UPDATE_API_ENABLED=true` en el backend del entorno deseado.
`.env.example` lo mantiene en `false`; almacenamiento usa su propio interruptor.
La migración es explícita y preserva auditoría frente a rollback destructivo.

Evidencia de esta entrega:

- Backend: **138 pruebas y 849 verificaciones** de autorización, catálogo, registro,
  almacenamiento, imágenes y licencias, con SQLite y dobles del SDK.
- HQ API: **25 pruebas y 151 verificaciones** de relay, licencias y seguridad HTTP,
  con tráfico simulado. HMAC del canal probado también en el endpoint central real
  dentro de la aplicación de pruebas, sin desactivar ese middleware.
- Formato Pint aprobado. El checkout HQ no usa Git; `--dirty` no está disponible
  y se aplicó el formateador a los cuatro archivos modificados de forma explícita.
- Al cerrar inicialmente esta fase, la migración de auditoría estaba aplicada solo
  a MySQL local, con firma compatible y sin releases ni autorizaciones reales.
  Ese estado histórico cambió durante el piloto firmado del 29/10/2026.

El piloto autorizado en este equipo usa releases Release 1.0.2.0, privados y
asignados únicamente a sus tres activaciones existentes. El publicador CLI exige
entorno y base locales, producto y versión exactos y certificado piloto aprobado;
registra al actor técnico 0 sin suplantar cuentas ni conceder permisos HTTP.
Las tres apps actualizaron 1.0.1.0 → 1.0.2.0 y el backend recibió tres resultados
`installed` tras sus relanzamientos. Se rechazaron doce pruebas negativas por las
APIs reales y la instalación de HQ al retirar su acceso después de descargar; se
restauró la asignación y el flujo concluyó correctamente. La evidencia y la matriz
ampliada pendiente se detallan en
[resultados del piloto](../../../headquaters/hq-client/HQ-Client/docs/desktop-update-pilot-results.md).
No se promovieron releases a estable ni se desplegó el backend a producción.

La prueba de red con Cellar real de fase 2 se conserva como evidencia de almacenamiento.
Esta etapa no reemplaza el piloto completo con las tres aplicaciones instaladas.

Referencias: [catálogo administrativo](desktop-release-catalog.md),
[almacenamiento privado](desktop-update-storage.md) y
[plan general](../../../headquaters/hq-client/HQ-Client/docs/desktop-updates.md).
