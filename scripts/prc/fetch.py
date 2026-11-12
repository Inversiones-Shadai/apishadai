"""Cache public ARSA registers and source product images. Safe to resume."""
import argparse
import concurrent.futures
import json
import pathlib
import time
import urllib.error
import urllib.parse
import urllib.request

from sources import product_image_url, source_url


def request(url, ajax=False):
    headers = {"User-Agent": "Shadai-catalog-import/1.0", "Accept": "application/json" if ajax else "image/*"}
    if ajax:
        headers["X-Requested-With"] = "XMLHttpRequest"
    for attempt in range(3):
        try:
            with urllib.request.urlopen(urllib.request.Request(url, headers=headers), timeout=35) as response:
                limit = (80 if ajax else 3) * 1024 * 1024
                body = response.read(limit + 1)
                if len(body) > limit:
                    raise ValueError("Response exceeds the expected source size")
                return body, response.headers.get("Content-Type", "")
        except urllib.error.HTTPError as error:
            if error.code not in (429, 500, 502, 503, 504):
                raise
        except (OSError, TimeoutError):
            if attempt == 2:
                raise
        time.sleep(2 ** attempt)
    raise RuntimeError("Public source remained unavailable after three attempts")


def registers(directory):
    register_url = source_url("PRC_ARSA_REGISTER_URL")
    for tab in ["MRS", "RMH", "BIRS", "btrs", "pnrs", "rpnh"]:
        target = directory / f"arsa-{tab}.json"
        if target.exists():
            print(f"Cached {tab}", flush=True)
            continue
        url = register_url + "?" + urllib.parse.urlencode({
            "NombrePestania": tab, "pagina": 1, "tamanioPagina": 100000,
        })
        body, _ = request(url, True)
        result = json.loads(body)
        if len(result["datos"]) != result["total"]:
            raise RuntimeError(f"Incomplete ARSA register {tab}")
        target.write_bytes(body)
        print(f"ARSA {tab}: {result['total']} records", flush=True)


def images(source, directory, workers):
    image_base_url = source_url("PRC_PRODUCT_IMAGE_BASE_URL")
    products = json.loads(pathlib.Path(source).read_text(encoding="utf-8-sig"))
    image_dir = directory / "images"
    image_dir.mkdir(exist_ok=True)
    products = [p for p in products if str(p.get("barcode") or "").strip().isdigit()
                and len(str(p.get("barcode") or "").strip()) >= 5]

    def download(product):
        target = image_dir / f"{int(product['id'])}.img"
        if target.exists():
            return {"id": product["id"], "status": "cached", "bytes": target.stat().st_size}
        url = product_image_url(image_base_url, product["id"])
        try:
            body, mime = request(url)
            if not (body.startswith(b"\xff\xd8\xff") or body.startswith(b"\x89PNG\r\n\x1a\n")):
                raise ValueError(f"Response is not a JPEG or PNG ({mime})")
            if len(body) > 2 * 1024 * 1024:
                raise ValueError("Image exceeds apishadai's 2 MB limit")
            target.write_bytes(body)
            return {"id": product["id"], "status": "downloaded", "bytes": len(body)}
        except Exception as error:
            return {"id": product["id"], "status": "error", "error": str(error), "url": url}

    failed = 0
    with (directory / "image-downloads.jsonl").open("w", encoding="utf8") as log:
        with concurrent.futures.ThreadPoolExecutor(max_workers=workers) as executor:
            for index, result in enumerate(executor.map(download, products), 1):
                log.write(json.dumps(result, ensure_ascii=False) + "\n")
                failed += result["status"] == "error"
                if index % 100 == 0 or index == len(products):
                    log.flush()
                    print(f"Images {index}/{len(products)}; errors {failed}", flush=True)


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("mode", choices=["registers", "images"])
    parser.add_argument("--source")
    parser.add_argument("--directory", required=True)
    parser.add_argument("--workers", type=int, default=4)
    args = parser.parse_args()
    destination = pathlib.Path(args.directory)
    destination.mkdir(parents=True, exist_ok=True)
    if args.mode == "registers":
        registers(destination)
    else:
        images(args.source, destination, args.workers)
