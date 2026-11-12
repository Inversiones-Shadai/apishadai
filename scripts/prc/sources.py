import os
import shlex
import urllib.parse
from pathlib import Path


def source_url(name):
    value = os.environ.get(name)
    if value is None:
        path = Path(__file__).resolve().parents[2] / ".env"
        if path.is_file():
            for line in path.read_text(encoding="utf-8-sig").splitlines():
                key, separator, raw_value = line.partition("=")
                if separator and key.strip().removeprefix("export ") == name:
                    parts = shlex.split(raw_value, comments=True)
                    value = parts[0] if len(parts) == 1 else ""

    url = urllib.parse.urlsplit((value or "").strip())
    if url.scheme != "https" or not url.hostname or url.username is not None or url.query or url.fragment:
        raise ValueError(f"Configura una URL HTTPS sin credenciales ni parámetros para {name} en .env.")
    return url.geturl().rstrip("/")


def product_image_url(base_url, product_id):
    return f"{base_url}/{int(product_id)}/imagen"
