# Compatibilidad con PHP 8.5.12

Corrección verificada el 21 de octubre de 2026.

## Conexión MySQL

El error `could not find driver` después de actualizar PHP se debía a que
`pdo_mysql` estaba comentado en el `php.ini` de la nueva instalación. PDO
por sí solo no incluye el controlador de MySQL. Composer ahora requiere
explícitamente `ext-pdo` y `ext-pdo_mysql` para detectar esta condición.

En el entorno local se habilitaron `pdo_mysql`, `pdo_sqlite`, `mbstring` y
`curl` en `C:\wamp64\bin\php\php8.5.12\php.ini`, con respaldo del archivo.
SQLite permite ejecutar las pruebas sin modificar MySQL. Se recargó el
servidor de la API iniciado con `php artisan serve` en el puerto 8000.

Al cambiar de versión o desplegar, revisar el archivo realmente cargado:

```sh
php --ini
php -m
composer check-platform-reqs
```

Si se usa Apache o PHP-FPM, revisar también su configuración de PHP y
reiniciar ese servicio después de habilitar extensiones. Su `php.ini`
puede ser diferente al de CLI.

## Deprecaciones corregidas

- MySQL y MariaDB usan `Pdo\Mysql::ATTR_SSL_CA`, conservando la opción
  `MYSQL_ATTR_SSL_CA` para certificados SSL.
- La limpieza de imágenes elimina las referencias a `GdImage` con
  `unset()`; se retiraron las llamadas deprecadas a `imagedestroy()` en la
  aplicación y en las pruebas.

Referencia: [guía oficial de deprecaciones de PHP 8.5](https://www.php.net/manual/en/migration85.deprecated.php).

## Validación

- Consulta `SELECT 1` correcta contra la conexión MySQL configurada.
- API local recargada: devuelve HTTP 401 al intentar iniciar sesión sin
  información de dispositivo, conforme a su middleware.
- 448 pruebas y 4646 aserciones correctas en PHP 8.5.12, sin deprecaciones.
- Pruebas de configuración SSL para MySQL y MariaDB. La prueba existente
  de laboratorios ahora inicializa Laravel, necesario para el mutador de
  imágenes del modelo.
- Manifiesto y requisitos de plataforma de Composer válidos.

En PowerShell:

```powershell
$env:DB_CONNECTION = 'sqlite'
$env:DB_DATABASE = ':memory:'
php -d error_reporting=32767 vendor/phpunit/phpunit/phpunit --no-progress --colors=never --fail-on-deprecation --display-deprecations
```
