# Selectores de ShAdmin

Los catálogos de los formularios se consultan al abrir el selector, con búsqueda en servidor y páginas de diez registros. La consulta de una selección existente usa su ID exacto. Cambiar la búsqueda o la página conserva el valor y el resto del formulario. Cada apertura consulta datos actuales, también cuando un catálogo se modifica en otra pestaña.

`GET /api/selectors/{source}` admite `page`, `search`, `selected` y filtros de dependencia `filters[parent]` o `filters[product]`. `per_page` y `all` no amplían el límite. La respuesta contiene `data` con `{id, label, record}` y `meta` de paginación. Los IDs del selector son cadenas.

`config/selector_sources.php` define las tablas, columnas públicas, permisos y dependencias permitidos. El servidor comprueba usuario, módulo, vista, acción, vigencia y encabezado `Action-Btn`. Los ámbitos especiales conservan saldos de compras, costos/impuestos de lotes, stocks habilitados y compatibilidad de conversiones. Tickets distingue listar clientes de buscar clientes; buscar requiere al menos dos caracteres.

ShAdmin mantiene una copia del registro de fuentes y usa un proxy autenticado `/ui/selectors/{source}`. El token permanece en PHP. `config/selector_bindings.php` permite a cada componente guardar únicamente elecciones válidas en sus propias propiedades; el servidor vuelve a consultar el registro antes de almacenarlo. La dependencia proviene del estado del componente.

El componente Blade `x-remote-select` y `resources/js/remote-select.js` comparten la interfaz. Cancelan búsquedas anteriores, ignoran respuestas atrasadas, permiten reintentar y conservan la elección si falla la conexión. Las listas dependientes cambian de identidad al cambiar su padre. Los selectores especializados de productos, proveedores, clientes de licencias, paquetes y dispositivos conservan sus flujos de búsqueda y paginación. Las opciones fijas de estados o modos siguen siendo listas locales.

Validación: `tests/Feature/SelectorCatalogTest.php` en la API; `tests/Feature/RemoteSelectorsTest.php`, pruebas de cada módulo y `node --test tests/JavaScript/remote-select.test.js` en ShAdmin. Las pruebas de dominio de la API usan SQLite en memoria; la configuración general de PHPUnit de la API no debe asumirse aislada de MySQL.
