# Imágenes en Cellar

Shadmin utiliza el bucket `shadaibucket` de Cellar en desarrollo y producción.
Las credenciales están únicamente en los `.env` locales de `apishadai` y `ShAdmin`;
los `.env.example` no contienen secretos. El navegador nunca recibe las claves S3.

Cada carga genera un UUID nuevo. La API valida la imagen, sube sus bytes a
`shadmin/images/<uuid>` para productos, proveedores, laboratorios y logos, o a
`invoices/<uuid>` para facturas y comprobantes de pagos, abonos y notas de crédito.
Ambos directorios usan el mismo servidor, bucket y disco. Guarda únicamente ese
UUID en `imagen` (o `img` para documentos). La columna actual admite el UUID y no requiere cambiar
su tipo. Una carga fallida no reemplaza la referencia anterior. Los objetos
anteriores se conservan para permitir recuperación y compatibilidad con clientes.

Los listados entregan `imagen_uuid` y `imagen_url`, o `documento_url`. Las URLs
firmadas se generan localmente, sin consultar S3 por cada registro. Las URLs de
imágenes y documentos respetan los permisos de visualización correspondientes.
Las opciones de compra mantienen el acceso a la imagen del proveedor autorizado
por el permiso de opciones de compra, como el endpoint anterior.

El navegador inicia las descargas directamente contra Cellar en paralelo.
Productos, proveedores, laboratorios, compras, comprobantes, lotes, distribución,
vencimientos, búsqueda avanzada, descuentos y logos usan la misma caché.
Las peticiones simultáneas al mismo UUID se comparten. CacheStorage permite
reutilizar las imágenes al recargar la página durante la misma sesión; si el
navegador no lo admite, se mantiene la caché en memoria. Al cambiar de sesión se
elimina la caché anterior. Reemplazar una imagen genera otro UUID y evita mostrar
la versión vieja. Las impresiones reutilizan esta caché para sus logos.

Los endpoints antiguos de imagen y la publicación hacia HQ conservan su contrato
base64 para los clientes existentes. Shadmin utiliza las URLs del listado; el
fallback antiguo solo se aplica a registros o APIs que todavía no tienen URL.

## Configuración

En ambas aplicaciones:

```dotenv
AWS_ACCESS_KEY_ID=<clave del addon>
AWS_SECRET_ACCESS_KEY=<secreto del addon>
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=shadaibucket
AWS_ENDPOINT=https://cellar-c2.services.clever-cloud.com
AWS_USE_PATH_STYLE_ENDPOINT=false
AWS_CA_BUNDLE=
IMAGE_STORAGE_ENABLED=true
IMAGE_STORAGE_DISK=s3
IMAGE_STORAGE_PREFIX=shadmin/images
INVOICE_STORAGE_PREFIX=invoices
IMAGE_STORAGE_URL_MINUTES=720
```

`AWS_CA_BUNDLE` puede quedar vacío en producción si el sistema ya tiene una cadena
CA válida. El `.env` local usa el certificado disponible en Wamp; no se desactiva
la verificación HTTPS. Las URLs firmadas duran 12 horas. Mantener los mismos
valores de bucket y prefijo de imágenes en ambas aplicaciones. El prefijo de
documentos se configura en la API; Shadmin recibe la URL firmada de cada documento.
Instalar dependencias con `composer install` en ambas aplicaciones.

Los UUID actuales de los logos están configurados en el `.env` local de Shadmin.
Para producción, copiar esos tres valores del entorno local o publicar los logos
en el bucket de destino y configurar el resultado:

```powershell
# En frontend/ShAdmin:
php artisan images:publish-logos
# Guardar IMAGE_LOGO_NAVIGATION_UUID, IMAGE_LOGO_TICKET_UUID,
# IMAGE_LOGO_TRANSFER_UUID en el entorno y limpiar caché de configuración.
php artisan config:clear
npm run build
```

El comando omite logos que ya tienen UUID configurado. Mientras esos valores no
estén configurados, se conserva el logo local para permitir el arranque inicial.

## CORS y producción

Actualmente CORS admite `http://localhost:8000`, `http://127.0.0.1:8001` y
`http://localhost:8001`; todavía no hay URL pública de producción. El host y el
puerto deben coincidir exactamente con los de la barra del navegador.
Cuando se conozca la URL pública, actualizar la regla con todos los
orígenes que deban seguir permitidos, sin comodines:

```powershell
# En backend/apishadai; sustituir por el dominio real:
php artisan images:configure-cors 'http://localhost:8000' 'http://127.0.0.1:8001' 'http://localhost:8001' 'https://dominio-real.example'
```

Solo se permiten GET y HEAD. El comando conserva las otras reglas del bucket y
reemplaza únicamente la regla `shadmin-images`. No se vuelve público el bucket.
Si el servidor local se ejecuta en otro origen o puerto, incluirlo explícitamente.

## Migración de imágenes existentes

En la base configurada localmente se migraron 29 imágenes y los tres logos.
Cada otra base debe migrarse una vez después de configurar y comprobar Cellar:

```powershell
# En backend/apishadai:
php artisan config:clear
php artisan images:migrate --dry-run
php artisan images:migrate
```

El comando procesa productos, proveedores, laboratorios, compras y transacciones
en bloques de 100. Antes de subir cada imagen respalda el original en
`storage/app/private/image-migrations/<fecha>-<uuid>.jsonl`. No modifica imágenes
que ya tienen UUID. Solo actualiza la referencia si no cambió concurrentemente.
Si una carga falla conserva el valor original y devuelve un estado de error;
puede ejecutarse de nuevo para reintentar los pendientes. Conservar los respaldos
privados hasta validar el despliegue. No vaciar el bucket mientras la DB lo use.

Para trasladar únicamente documentos al directorio `invoices`:

```powershell
php artisan images:migrate --documents-only --dry-run
php artisan images:migrate --documents-only
```

Ejecutarlo al desplegar este cambio, antes de habilitar las consultas de documentos.
Las facturas y comprobantes que ya tienen UUID se copian desde `shadmin/images`
a `invoices` conservando el UUID de la DB y el original. Se verifica que ambas
copias tengan el mismo SHA-256. Los documentos base64 se respaldan y suben al
nuevo directorio. El proceso puede repetirse y omite documentos ya trasladados.
La opción `--documents-only` no consulta ni migra imágenes del catálogo.
Las URLs y endpoints base64 de documentos leen del nuevo directorio y conservan
los permisos de facturas (`cmpbtn06`) y transacciones (`ctrbtn06`).

## Validación

```powershell
# Backend
php vendor/phpunit/phpunit/phpunit --filter 'ImageStorageTest|ProductDomainTest|PurchaseDomainTest'
# Frontend
php vendor/phpunit/phpunit/phpunit --filter 'StorageImageSourceTest|LotManagementTest'
node --test tests/js/session-image-cache.test.mjs
npm run build
```
