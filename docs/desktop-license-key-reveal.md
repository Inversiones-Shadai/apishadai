# Consulta autorizada de claves de escritorio

ShAdmin → Configuración → Licencias de escritorio → seleccionar cliente → **Ver clave completa**.
Requiere el permiso de detalle `cfg / lic / licbtn04`, además del acceso habitual a la vista.

La API envía un código de seis dígitos por SMTP al correo administrador de `appconfig.mail_cc1`.
El destinatario no se recibe del navegador ni se toma del correo del solicitante.
El correo identifica la licencia, aplicación, terminación de la clave y usuario solicitante;
nunca incluye la clave completa. Se utiliza el SMTP ya configurado en apishadai.

El código vence a los diez minutos, admite cinco intentos, se consume con la primera
consulta correcta y queda vinculado a licencia, usuario y token de sesión de API.
Cambiar el correo administrador invalida los códigos anteriores. Cada reenvío reemplaza
el código y la solicitud anterior; hay un minuto de espera entre envíos por usuario/licencia
y un máximo de cinco solicitudes de envío por usuario cada diez minutos.

Los endpoints autenticados son `POST /api/config/licenses/{id}/key-code` y
`POST /api/config/licenses/{id}/reveal-key`. Ambos exigen `Action-Btn: licbtn04`.
El primero devuelve `challenge_id` y `expires_at`; el segundo recibe `challenge_id`
y `code`, y devuelve `license_key` únicamente tras la validación.
Las respuestas usan `Cache-Control: no-store` y estos endpoints excluyen la caché de
idempotencia para evitar almacenar o repetir una clave revelada.
El historial registra envíos, intentos incorrectos y consultas autorizadas, sin códigos ni claves.

Las nuevas licencias conservan su hash de activación y guardan además la clave cifrada
con el cifrado de Laravel (`APP_KEY`). Los listados solo incluyen la terminación y
`can_reveal_key`; nunca devuelven el hash ni el contenido cifrado.
ShAdmin muestra la clave autorizada durante un minuto, permite cerrarla antes y no
la incluye como propiedad pública en el estado serializado de Livewire.

## Creación autorizada de nuevas licencias

El formulario de **Generar licencia** exige también autorización por correo. Al completar
cliente, nombre, aplicación, cupos y vigencia, **Solicitar código de creación** envía un
código al administrador. **Autorizar y generar clave** crea la licencia únicamente al
validarlo. Se conserva el permiso `cfg / lic / licbtn02` (Generar licencias).

`POST /api/config/licenses/generation-code` valida los datos y devuelve `challenge_id`
y `expires_at`, sin crear una licencia. `POST /api/config/licenses` exige los mismos
datos más `challenge_id` y `code`, con `Action-Btn: licbtn02` en ambos casos.
El código queda vinculado a los datos normalizados (cliente, nombre, aplicación, cupos,
vigencia), usuario, token de sesión y correo administrador. Cambiar cualquiera de los
datos exige solicitar un nuevo código. Se almacena solamente la huella de los datos.

La autorización de creación usa su propia tabla y códigos independientes de los de
consulta de clave. Vence en diez minutos, permite cinco intentos y una sola creación.
Hay un minuto de espera entre solicitudes por usuario y cinco envíos como máximo cada
diez minutos. Reenviar invalida la solicitud anterior. La creación y el consumo del
código se confirman en una transacción, para impedir duplicados o consumirlo si falla
la creación. Ambos endpoints excluyen la caché de idempotencia y usan `no-store`.

El correo muestra los datos de la licencia pendiente y el usuario solicitante. Los
envíos e intentos fallidos se auditan sin licencia asociada; al crearla, su historial
registra `generate_approval / authorized` además de la generación habitual.

## Instalación

Ambas migraciones aplicadas en `shadaidb` local (`127.0.0.1`) el 7 de noviembre de 2026.
Se verificó que el correo administrador configurado es válido. Las pruebas usaron
correo simulado; no se enviaron códigos reales ni se desplegó a producción.
Validación de creación, consulta y compatibilidad: 206 pruebas de escritorio del backend, 27 de ShAdmin,
Pint en los archivos modificados y PHPStan en el componente y servicio de ShAdmin.

Aplicar en apishadai la migración `2026_11_07_000001_add_desktop_license_key_reveal.php`
antes de desplegar el código del backend y ShAdmin:

```powershell
php artisan migrate --path=database/migrations/2026_11_07_000001_add_desktop_license_key_reveal.php
php artisan migrate --path=database/migrations/2026_11_07_000002_create_desktop_license_generation_challenges.php
```

Mantener una copia segura de `APP_KEY` y conservar las claves de cifrado anteriores si
se realiza una rotación mediante `APP_PREVIOUS_KEYS`.
Verificar que `appconfig.mail_cc1` contenga el correo administrador y que SMTP funcione.
No se necesita un permiso nuevo ni un worker de cola para esta consulta.

Las licencias anteriores a esta migración solo tienen un hash irreversible. Su clave
original no puede recuperarse; ShAdmin muestra **Clave anterior no recuperable**.
La migración deja esas claves sin cambios y no reemplaza licencias ni vinculaciones.
