# Importación PRC (22 de octubre de 2026)

Herramientas de la carga solicitada de `prc-gbl.txt` a la conexión MySQL local
`shadaidb` de apishadai. Los datos y el respaldo permanecen fuera de Git en
`storage/app/private/prc-import-20261022/`.

El código es el primer carácter del nombre, en mayúsculas, seguido por los
últimos cinco dígitos de las barras. Cuando hay colisión se agrega `-<id_origen>`.
Se conservan los ceros iniciales. No se inventan barras ni se fusionan registros
que compartan barras con distintas descripciones/presentaciones. Los productos
que ya existen se omiten sin modificarlos.

Las imágenes se descargan del endpoint indicado por el usuario y se guardan
como contenido base64 en `productos.imagen`, compatible con el endpoint existente.
Esta importación evita deliberadamente el mutador que guarda un UUID en Cellar,
por instrucción explícita del usuario. Las respuestas vacías, archivos truncados
y formatos no admitidos quedan pendientes; nunca se guarda HTML ni una URL como
imagen. Los bytes descargados se conservan y se verifican por SHA-256.

ARSA aporta seis tablas públicas y las verificaciones adicionales incluyen
fuentes del fabricante/distribuidor. El plan conserva el método y las referencias
de cada coincidencia. Se exige acuerdo entre variantes de una marca y compatibilidad
de ingredientes/presentación. Las columnas desplazadas detectadas en ARSA RMH se
tratan explícitamente. Las clasificaciones o vías sin evidencia suficiente quedan
como `PENDIENTE DE CLASIFICACION` o `NO ESPECIFICADA`; nunca se convierten en datos
médicos supuestamente verificados. Los marcadores `nm`, categorías como «Cosmético»
y valores mal formados no se convierten en principios activos o concentraciones.

Las conversiones compra/venta se extraen solamente de un `1 de N` explícito en el
nombre; el resto se ingresa como presentación completa (1:1). No se confunden
`unid` y `unid_frac` del sistema externo con las unidades locales. No se crean
lotes, existencias, costos ni precios a partir de los campos cifrados del archivo.

Ejecutar desde la raíz de apishadai:

```powershell
python scripts/prc/fetch.py registers --directory storage/app/private/prc-import-20261022
python scripts/prc/prepare.py --source C:/Users/USER/Desktop/prc-gbl.txt --directory storage/app/private/prc-import-20261022
python scripts/prc/test_prepare.py
php scripts/prc/import.php --directory=storage/app/private/prc-import-20261022
# Aplicar el plan revisado; cada producto se confirma con su outbox en una transacción.
php scripts/prc/import.php --directory=storage/app/private/prc-import-20261022 --apply
python scripts/prc/fetch.py images --source C:/Users/USER/Desktop/prc-gbl.txt --directory storage/app/private/prc-import-20261022 --workers 8
php scripts/prc/import.php --directory=storage/app/private/prc-import-20261022 --apply --images-only
php scripts/prc/verify.php storage/app/private/prc-import-20261022
python scripts/prc/report.py --directory storage/app/private/prc-import-20261022
```

`import.php` usa un bloqueo de ejecución, hace respaldo antes de escribir y deja
un diario con IDs de origen/destino. Una repetición omite productos ya existentes.
`--images-only` modifica únicamente las imágenes vacías de productos que el diario
acredita como creados por esta carga. Los cambios se registran en el outbox local
de HQ; el proceso no realiza envíos sincrónicos a HQ.

`refine.php` se utilizó para aplicar las diferencias revisadas entre el primer
plan aplicado (`plan-applied.json`) y el definitivo. Registra el antes y después
de las relaciones corregidas. No ejecutarlo como paso rutinario: una ejecución
posterior podría volver a publicar esas correcciones.

`verify.php` comprueba identidad, claves foráneas, familias/formas/vías,
principios/concentraciones, hash de imágenes, tres respuestas del controlador
de imágenes y preservación de todos los productos previos. `RESULTADO.md`,
`pending-products.md`, `needs-review.json` y `verification.json` documentan
los resultados y lo que requiere revisión humana.
