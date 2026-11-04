# Actualización a Laravel 13

Actualización realizada el 16 de octubre de 2026 siguiendo la
[guía oficial de Laravel](https://laravel.com/docs/13.x/upgrade).

## Versiones

| Componente | Antes | Después |
| --- | --- | --- |
| Laravel | 12.70.0 | 13.37.0 |
| Tinker | 2.11.1 | 3.0.2 |
| PHPUnit | 11.5.57 | 12.5.40 |

El proyecto requiere PHP `^8.5.12` y utiliza la plataforma Composer
`8.5.12`. La validación original de la migración a Laravel 13 se realizó
con PHP 8.4.18 y Composer 2.9.5.
JWT Auth 2.9.3, Sanctum 4.3.3 y DomPDF 3.1.2 se mantienen porque ya
declaran compatibilidad con Laravel 13.

## Adaptaciones

- Sanctum utiliza `PreventRequestForgery`, el middleware de Laravel 13.
- La caché configura `serializable_classes => false`, conforme al nuevo
  valor predeterminado de seguridad. La aplicación no guarda objetos PHP
  en caché; si se añade esa funcionalidad, se deberá definir una lista de
  clases permitidas.
- Se conservan los prefijos de caché, Redis y cookies definidos por la
  aplicación, así como la serialización de sesiones existente.
- Las pruebas de transferencias comparten ahora la tabla `distribucion`
  requerida por Headquarters y esperan el cast decimal del modelo `Lote`.
  Esos tres fallos se reprodujeron antes de actualizar, en Laravel 12.
- Las pruebas de autenticación verifican la emisión y lectura de un JWT
  firmado, su vigencia configurada y el rechazo de tokens malformados.

## Verificación local

Resultado: 371 pruebas y 4,147 aserciones correctas con PHPUnit 12.5.40.
Composer valida el manifiesto y los requisitos de plataforma; la auditoría
no detectó avisos de vulnerabilidad. Laravel arranca y registra 274 rutas
de la aplicación.

En PowerShell, ejecutar las pruebas con una base SQLite en memoria:

```powershell
$env:DB_CONNECTION = 'sqlite'
$env:DB_DATABASE = ':memory:'
php vendor/phpunit/phpunit/phpunit --no-progress --colors=never
```

Comprobaciones adicionales:

```powershell
composer validate --strict
composer check-platform-reqs
composer audit --locked
php artisan --version
php artisan route:list --except-vendor
```

## Despliegue

En el servidor, confirmar primero PHP 8.5.12 o superior dentro de la rama 8.x
y sus extensiones con `composer check-platform-reqs --no-dev`.
Instalar las versiones exactas de `composer.lock`:

```sh
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
php artisan config:clear
php artisan route:clear
php artisan view:clear
```

Si el despliegue utiliza cachés de configuración y rutas, regenerarlas con
`php artisan config:cache` y `php artisan route:cache`. Reiniciar los workers
de colas con `php artisan queue:restart` para cargar el framework actualizado.

Esta actualización no añade migraciones de base de datos. El despliegue y
las comprobaciones contra MySQL del servidor quedan fuera de la validación
local con SQLite.
