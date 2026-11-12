import argparse
import collections
import json
import pathlib


def build(directory):
    plan = json.loads((directory / "plan.json").read_text(encoding="utf8"))
    verification = json.loads((directory / "verification.json").read_text(encoding="utf8"))
    result = verification["summary"]
    reasons = collections.Counter(x["reason"] for x in plan["pending"])
    labels = {"codigo_barras_no_verificable": "Barras ausentes, marcadores NT o formato no verificable",
              "barras_repetidas_en_productos_o_presentaciones_distintos": "Barras repetidas con descripciones o presentaciones distintas"}
    pending = ["# Productos pendientes de importar", "", "No se generaron barras ficticias ni se fusionaron las presentaciones.", "",
               "| ID origen | Código de barras original | Producto | Motivo |", "|---|---|---|---|"]
    for item in plan["pending"]:
        values = [str(item["source_id"]), item["barcode"], item["descripcion"], labels.get(item["reason"], item["reason"])]
        pending.append("| " + " | ".join(x.replace("|", "\\|").replace("\n", " ") for x in values) + " |")
    (directory / "pending-products.md").write_text("\n".join(pending) + "\n", encoding="utf8")
    (directory / "needs-review.json").write_text(json.dumps(plan["review"], ensure_ascii=False, indent=2), encoding="utf8")
    (directory / "pending-products.json").write_text(json.dumps(plan["pending"], ensure_ascii=False, indent=2), encoding="utf8")
    (directory / "missing-images.json").write_text(json.dumps(verification["missing_images"], ensure_ascii=False, indent=2), encoding="utf8")
    text = f"""# Resultado de importación — 22 de octubre de 2026

Base: **{result['database']}**, MySQL local configurado en apishadai.

| Resultado | Cantidad |
|---|---:|
| Productos en el archivo | {result['source_count']:,} |
| Productos nuevos creados | {result['imported']:,} |
| Productos del archivo ya existentes, conservados | {len(plan['products']) - result['imported']:,} |
| Productos pendientes por barras | {result['pending_products']:,} |
| Total de productos en la base después de la carga | {result['products_total']:,} |
| Imágenes descargadas y guardadas como contenido en DB | {result['images_saved_in_database']:,} |
| Productos nuevos sin imagen válida en el origen | {result['images_unavailable']:,} |
| Productos nuevos con grupo terapéutico por revisar | {result['imported_pending_therapeutic_classification']:,} |
| Errores de verificación | {result['verification_failures']:,} |

Código: primer carácter del nombre en mayúsculas y últimos cinco dígitos de barras.
Las colisiones llevan el sufijo `-ID_ORIGEN`, autorizado por el usuario.
Las barras se conservaron como texto, incluyendo ceros iniciales.

Las imágenes están **dentro de `productos.imagen` como base64**. Cada URL de origen
se conserva en el campo `image_url` de `plan.json`.
Se comprobaron su formato y sus bytes por SHA-256; el controlador de la API entrega
las imágenes guardadas. Las respuestas vacías o archivos corruptos del proveedor
quedan listados en `missing-images.json`. No se sustituyeron por imágenes ficticias.

Los {len(json.loads((directory / 'before.json').read_text(encoding='utf8'))['productos'])} productos previos se preservaron sin cambios:
**{'verificado' if result['original_products_preserved'] else 'REVISAR diferencias en verification.json'}**.
Se verificaron claves foráneas, asociaciones con principios activos y concentraciones,
familias, presentaciones y vías, además de tres respuestas del controlador de imágenes.

## Pendientes y límites

"""
    for reason, count in reasons.items():
        text += f"- **{count:,}**: {labels.get(reason, reason)}.\n"
    text += f"""
Se consultaron seis registros públicos de ARSA Honduras (13,366 registros) y
fuentes adicionales del fabricante/distribuidor. El plan conserva las referencias
y el método de cada coincidencia. No todas las marcas, presentaciones o concentraciones
pudieron verificarse: **{result['imported_pending_therapeutic_classification']:,} productos nuevos**
quedaron como `PENDIENTE DE CLASIFICACION`, y las vías no verificadas como
`NO ESPECIFICADA`. Las concentraciones faltantes o principios mal formados no se inventaron.
El archivo `needs-review.json` detalla estas revisiones por producto.

Las unidades se interpretaron como presentación completa (1:1), salvo los nombres
con `1 de N`, que aportan la conversión explícita. Se conservó el archivo original
para comprobar otras políticas de fraccionamiento antes de modificarlas.

No se crearon lotes ni existencias, ni precios/costos a partir de campos cifrados.
Teléfonos, direcciones e imágenes de laboratorios no son obligatorios para crear
productos y quedaron sin inventar cuando no existían.

## Archivos de auditoría

- `source.json`: copia original y hash del archivo suministrado.
- `before.json`: respaldo de los catálogos antes de la primera escritura.
- `plan.json`: datos preparados y evidencia por producto.
- `database-events.jsonl`: IDs de origen/destino y resultados de cada operación.
- `refinements.jsonl`: antes/después de 114 normalizaciones y verificaciones adicionales.
- `images/`: archivos descargados, cuyos hashes se comparan con la DB.
- `pending-products.md`: registros que no se importaron y motivo.
- `needs-review.json`: datos incompletos que necesitan revisión.
- `missing-images.json`: imágenes que la fuente no entregó válidamente.
- `verification.json`: resultados de la verificación final.

Las herramientas están en `scripts/prc/`. No se modificaron controladores ni modelos
de la aplicación para esta importación. Los cambios de catálogo conservan el outbox
local de HQ; el importador no ejecuta envíos sincrónicos.
"""
    (directory / "RESULTADO.md").write_text(text, encoding="utf8")
    print(json.dumps(result, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--directory", type=pathlib.Path, required=True)
    build(parser.parse_args().directory)
