"""Build an auditable catalog plan. Never guess a barcode or ingredient strength."""
import argparse
import collections
import hashlib
import json
import pathlib
import re
import unicodedata

from sources import product_image_url, source_url


def norm(value):
    value = unicodedata.normalize("NFKD", str(value or "")).encode("ascii", "ignore").decode().upper()
    return re.sub(r"[^A-Z0-9]+", " ", value).strip()


def compact(value):
    return re.sub(r"\s+", " ", str(value or "")).strip()


def dose(value):
    value = compact(value).upper().replace("Μ", "U").replace("µ", "U")
    value = re.sub(r"(?<=\d),(?=\d)", ".", value)
    # Older source records append the container size: '10mg/5ml en 120ml'.
    # Keep the explicitly supplied concentration, not the packaging annotation.
    value = re.sub(r"\s+EN\s+\d.*$", "", value)
    return re.sub(r"\s+", "", value)


NON_ACTIVE = {norm(x) for x in [
    "Complemento Medico", "Cosmetico", "Consumo", "Dermatologico", "Formula Infantiles",
    "Suplemento Alimenticio", "Zona Ahorropuntos", "Filtro solar", "Aceites y Otros",
    "Medicamento", "Productos Naturales", "No Aplica", "Multivitaminico", "Complejo B",
]}

FORMS = [
    (r"COMPRIMIDOS? VAGINALES?|OVULOS?", "OVULOS", "VAGINAL"),
    (r"SUPOSITOR", "SUPOSITORIOS", "RECTAL"),
    (r"SUBLINGUAL|SUB LINGUAL", "TABLETAS", "SUBLINGUAL"),
    (r"BUCODISPERS|ORODISPERS", "COMPRIMIDOS BUCODISPERSABLES", "ORAL"),
    (r"TABLET|COMPRIMID|DIVITAB|GRAGEA", "TABLETAS", "ORAL"),
    (r"CAPSUL|CASPSUL", "CAPSULA", "ORAL"),
    (r"JARABE", "JARABE", "ORAL"),
    (r"OFTALM|OFTENO|OFTEN|COLIRIO", None, "OFTALMICA"),
    (r"OTIC[OA]|OTICO", None, "OTICA"),
    (r"NASAL", None, "NASAL"),
    (r"ENJUAGUE BUCAL|PASTA DENTAL|DENTIFRICO|GEL ORAL", None, "BUCAL"),
    (r"INHALADOR|INHALACION|NEBULIZ", None, "INHALATORIA"),
    (r"CREMA|UNGUENTO|POMADA|LOCION|CHAMPU|SHAMPOO|JABON|FILTRO SOLAR", None, "TOPICO"),
    (r"AMPOLLAS? BEBIBLES?", "AMPOLLAS BEBIBLES", "ORAL"),
]


def source_form(product):
    name = norm(product["nombre"])
    form = norm(product["tipo_presentacion"])
    route = None
    for pattern, presentation, administration in FORMS:
        if re.search(pattern, name):
            form = presentation or form
            route = administration
            break
    if route is None:
        form_key = norm(product["tipo_presentacion"])
        for pattern, presentation, administration in FORMS[:7]:
            if re.search(pattern, form_key):
                form, route = presentation or form, administration
                break
    if route is None:
        if re.search(r"\b(?:IM IV|IV IM)\b", name):
            route = "IM/IV"
        elif re.search(r"\bIV\b", name):
            route = "IV"
        elif re.search(r"\bIM\b", name):
            route = "IM"
    return form, route


def form_group(value):
    value = norm(value)
    for pattern, group in [
        (r"TABLET|COMPRIMID|GRAGEA", "SOLIDO ORAL"), (r"CAPSUL", "CAPSULA"),
        (r"JARABE", "JARABE"), (r"SUSPENSION", "SUSPENSION"),
        (r"CREMA", "CREMA"), (r"GEL\b", "GEL"), (r"UNGUENTO|POMADA", "UNGUENTO"),
        (r"SOLUCION|GOTAS", "SOLUCION"), (r"POLVO", "POLVO"),
    ]:
        if re.search(pattern, value):
            return group
    return None


def registry_index(directory):
    by_first, by_registration = collections.defaultdict(list), collections.defaultdict(list)
    rows = []
    for path in sorted(directory.glob("arsa-*.json")):
        for entry in json.loads(path.read_text(encoding="utf8"))["datos"]:
            name = norm(entry.get("Nombre del producto:"))
            if not name:
                continue
            route = sorted({norm(entry.get(f"Vía de administración ({i}):")) for i in (1, 2, 3)} - {""})
            form, group = norm(entry.get("Forma farmacéutica:")), norm(entry.get("Grupo terapéutico:"))
            # The RMH public spreadsheet contains shifted columns in many rows.
            # Detect the explicit route in the form column, never use packaging as a route.
            valid_routes = {"ORAL", "TOPICA", "TOPICO", "OFTALMICA", "OTICA", "NASAL", "VAGINAL", "RECTAL",
                            "INTRAVENOSA", "INTRAMUSCULAR", "SUBCUTANEA", "INHALATORIA", "SUBLINGUAL", "BUCAL",
                            "INTRADERMICA", "PARENTERAL", "INTRAVENOSA INTRAMUSCULAR", "INTRAMUSCULAR INTRAVENOSA"}
            if form in valid_routes and group and path.stem == "arsa-RMH":
                form, route, group = group, [form], ""
            else:
                route = [x for x in route if x in valid_routes]
            item = {"name": name, "form": form,
                    "route": " / ".join(route), "family": group,
                    "active": norm(entry.get("Nombre de sustancias activas / Principio activo:")),
                    "registration": compact(entry.get("Numero de Registro Sanitario")),
                    "composition": compact(entry.get("Concentracion por unidad de dosis:")),
                    "tab": path.stem.removeprefix("arsa-")}
            if len(item["family"]) > 180:
                item["family"] = ""  # Never truncate a medical classification silently.
            rows.append(item)
            by_first[name.split()[0]].append(item)
            if item["registration"]:
                by_registration[norm(item["registration"])].append(item)
    return rows, by_first, by_registration


def broad_family(value):
    """Normalize synonymous ARSA group labels, without deriving a treatment indication."""
    value = norm(value)
    for pattern, group in [
        (r"ANTIBIOT|ANTIBACTER|PENICILIN|CEFALOSPOR|MACROLID|QUINOLON|CARBAPENEM|TETRACICLIN|AMINOGLUCOSID", "ANTIBIOTICOS"),
        (r"ANTIHIPERTENS|ANGIOTENSINA|BLOQUEANTES|BETABLOQUE|BLOQUEADORES DE.*CALCIO|IECA", "ANTIHIPERTENSIVOS"),
        (r"HIPOGLUC|ANTIDIABET|INSULINA|BIGUANIDA|SULFONILUREA", "ANTIDIABETICOS"),
        (r"ANALGES|ANTIPIRET|ANTIRREUM|ANTIINFLAMATORIO.*NO ESTERO|NO ESTEROIDEO", "ANALGESICOS Y ANTIINFLAMATORIOS"),
        (r"ANTIHISTAM|ANTIALERG", "ANTIHISTAMINICOS"),
        (r"ANTIEPILE|ANTICONVUL", "ANTIEPILEPTICOS"),
        (r"ANTIDEPRES|RECAPTACION.*SEROTONINA", "ANTIDEPRESIVOS"),
        (r"ANTIPSICO|NEUROLEPT", "ANTIPSICOTICOS"),
        (r"HIPOLIPEM|HMG COA|REDUCTASA|HIPOLIPIDEM|HMGCOA", "HIPOLIPEMIANTES"),
        (r"BOMBA DE PROTON|ANTIULCER|ANTIACID", "ANTIULCEROSOS Y ANTIACIDOS"),
        (r"ANTINEOPLAS|CITOSTATIC|ANTICANCER", "ANTINEOPLASICOS"),
        (r"CORTICOSTERO|GLUCOCORTICO|CORTICOESTERO", "CORTICOSTEROIDES"),
        (r"ANTIFUNG|ANTIMICOT", "ANTIMICOTICOS"),
        (r"ANTIVIR", "ANTIVIRALES"),
        (r"ANTIPARASI|ANTIPROTOZO|ANTIHELMINT", "ANTIPARASITARIOS"),
        (r"HORMONAS TIROIDE|HORMONA TIROIDE", "HORMONAS TIROIDEAS"),
        (r"ANTIANEM|HIERRO", "ANTIANEMICOS"),
        (r"MUCOLIT|EXPECTOR|ANTITUS", "ANTITUSIVOS Y EXPECTORANTES"),
        (r"VITAMIN|MINERALES|SUPLEMENTO|COMPLEMENTO ALIMENT", "VITAMINAS Y SUPLEMENTOS"),
    ]:
        if re.search(pattern, value):
            return group
    return value


def match_registry(product, by_first, by_registration):
    registration = norm(product.get("barcode2"))
    if registration in by_registration:
        return by_registration[registration], "registro_sanitario_exacto"
    name = norm(product["nombre"].split(",")[0])
    first = name.split()[0]
    # A brand prefix is accepted only with the same suffix (D, AM, PLUS, etc.).
    # Generic/single-token prefixes cannot be used to infer composition or dose.
    candidates = []
    form, _ = source_form(product)
    source_actives = {norm(x) for x in product.get("principios_activos", []) if norm(x) not in NON_ACTIVE}
    for row in by_first.get(first, []):
        if len(first) < 4 or not re.match(r"[A-Z]", first):
            continue
        if source_actives and not all(re.search(r"\b" + re.escape(active) + r"\b", row["active"]) for active in source_actives):
            continue
        fg, rg = form_group(form), form_group(row["form"])
        if fg and rg and fg != rg:
            continue
        # Reject brand variants whose second token disagrees (e.g. EUROCOR AM vs EUROCOR D).
        a = name.split()[1:2]
        b = row["name"].split()[1:2]
        variants = {"AM", "D", "H", "PLUS", "FORTE", "DUO", "COMPUESTO", "HCT", "CO", "XR", "SR"}
        if (set(a) | set(b)) & variants and a != b:
            continue
        candidates.append(row)
    # Use a brand only to obtain attributes for which ALL registry variants agree.
    return candidates, "consenso_marca_forma_arsa" if candidates else None


def consensus(rows, field):
    values = {row[field] for row in rows}
    return next(iter(values)) if len(values) == 1 and "" not in values else None


def category(product):
    active_names = {norm(x) for x in product.get("principios_activos", [])}
    name = norm(product["nombre"])
    if product.get("controlado") == 1:
        return "CONTROLADOS"
    if "FORMULA INFANTILES" in active_names or re.search(r"\b(?:ENFAMIL|ENFAGROW|SIMILAC|PEDIASURE|NAN OPTIPRO|NIDO)\b", name):
        return "LECHES"
    if active_names & {"COSMETICO", "FILTRO SOLAR"}:
        return "CUIDADO PERSONAL"
    if "COMPLEMENTO MEDICO" in active_names:
        return "INSUMOS Y EQUIPO MEDICO"
    if active_names & {"CONSUMO", "ZONA AHORROPUNTOS"}:
        return "CONSUMO"
    return "FARMACIA"


def prepare(source, directory):
    image_base_url = source_url("PRC_PRODUCT_IMAGE_BASE_URL")
    raw = source.read_bytes()
    products = json.loads(raw.decode("utf-8-sig"))
    snapshot = directory / "source.json"
    if snapshot.exists() and snapshot.read_bytes() != raw:
        raise RuntimeError("Source changed; use a new import directory")
    snapshot.write_bytes(raw)
    registry_rows, by_first, by_registration = registry_index(directory)
    by_active = collections.defaultdict(list)
    for entry in registry_rows:
        if entry["active"]:
            by_active[entry["active"]].append(entry)
    barcode_counts = collections.Counter(compact(p.get("barcode")) for p in products)
    overrides_path = directory / "verified-overrides.json"
    overrides = json.loads(overrides_path.read_text(encoding="utf8")) if overrides_path.exists() else {}
    plan, pending, review = [], [], []
    for product in products:
        barcode = compact(product.get("barcode"))
        name = compact(product["nombre"])
        item = {"source_id": int(product["id"]), "descripcion": name, "barcode": barcode,
                "image_url": product_image_url(image_base_url, product["id"])}
        issues = []
        if not re.fullmatch(r"[0-9]{5,50}", barcode):
            pending.append({**item, "reason": "codigo_barras_no_verificable"})
            continue
        if barcode_counts[barcode] > 1:
            pending.append({**item, "reason": "barras_repetidas_en_productos_o_presentaciones_distintos"})
            continue
        if not name or len(name) > 150:
            pending.append({**item, "reason": "descripcion_fuera_del_contrato"})
            continue
        matches, method = match_registry(product, by_first, by_registration)
        form, route = source_form(product)
        registry_form = consensus(matches, "form")
        family = consensus(matches, "family")
        registry_route = consensus(matches, "route")
        active_names = [norm(x) for x in product.get("principios_activos", []) if norm(x) not in NON_ACTIVE]
        ingredient_matches = by_active.get(active_names[0], []) if len(active_names) == 1 else []
        ingredient_groups = {broad_family(r["family"]) for r in ingredient_matches if r["family"]}
        if not family and len(ingredient_groups) == 1:
            family = next(iter(ingredient_groups))
            matches = matches + ingredient_matches
            method = "grupo_por_principio_activo_exacto_arsa"
        if family:
            family = broad_family(family)
        if not registry_route and ingredient_matches:
            fg = form_group(form)
            compatible = [r for r in ingredient_matches if fg and form_group(r["form"]) == fg]
            registry_route = consensus(compatible, "route")
        if registry_form:
            form = registry_form
        if registry_route:
            route = registry_route
        cat = category(product)
        if cat in {"CONSUMO", "CUIDADO PERSONAL", "LECHES", "INSUMOS Y EQUIPO MEDICO"}:
            family = cat
            if cat in {"CONSUMO", "INSUMOS Y EQUIPO MEDICO"}:
                route = "NO APLICA"
            elif cat == "LECHES":
                route = "ORAL"
        if not family:
            family = "PENDIENTE DE CLASIFICACION"
            issues.append("grupo_terapeutico_sin_coincidencia_verificable")
        if not route:
            route = "NO ESPECIFICADA"
            issues.append("via_no_verificada")
        active_pairs = []
        for entry in product.get("principios_activos_con_dosaje", []):
            active = compact(entry.get("principio_activo"))
            strength = dose(entry.get("dosaje"))
            if norm(active) in NON_ACTIVE:
                continue
            if not active or not re.search(r"[A-Za-zÀ-ÿ]{3}", active) or re.match(r"^[\d\s.,/%]+(?:MG|G|ML|UI|MCG)\b", norm(active)):
                issues.append("principio_activo_mal_formado:" + active)
                continue
            if not strength or strength in {"NM", "N/A", "NA", "NO APLICA"} or not re.search(r"\d", strength):
                issues.append("concentracion_no_verificada:" + active)
                continue
            if re.search(r"^\d+(?:\.\d+)?ML/\d+(?:\.\d+)?ML", strength):
                issues.append("concentracion_dimensionalmente_inconsistente:" + active)
                continue
            if len(strength) > 100 or len(active) > 255:
                issues.append("principio_activo_fuera_del_contrato:" + active)
                continue
            active_pairs.append({"nombre": active, "concentracion": strength})
        override = overrides.get(str(product["id"]), {})
        if override:
            if not override.get("source_url"):
                raise ValueError("Web overrides require a supporting source URL")
            active_pairs.extend(override.get("actives", []))
            family = override.get("family", family)
            form = override.get("form", form)
            route = override.get("route", route)
        if family != "PENDIENTE DE CLASIFICACION":
            issues = [x for x in issues if x != "grupo_terapeutico_sin_coincidencia_verificable"]
        if route != "NO ESPECIFICADA":
            issues = [x for x in issues if x != "via_no_verificada"]
        # The source's unid/unid_frac refer to different selling modes. Only the
        # explicit '1 de N' title establishes a purchase-to-sale conversion.
        fraction = re.search(r"\b1\s+de\s+(\d+)\b", name, re.I)
        count = int(fraction.group(1)) if fraction else 1
        unit = {"abreviatura_c": "CC", "abreviatura_v": "CV" if count > 1 else "CC",
                "cantidad_c": count, "cantidad_v": 1}
        item.update({"codigo": name[0].upper() + barcode[-5:], "laboratorio": compact(product["laboratorio"]),
                     "categoria": cat, "presentacion": form, "administracion": route,
                     "familia": family, "unidad": unit,
                     "estado": "ACTIVO" if product.get("activo") == 1 else "INACTIVO",
                     "actives": active_pairs,
                     "evidence": {"registry_method": method,
                                  "registry_refs": sorted({r["registration"] for r in matches}),
                                  "registry_tabs": sorted({r["tab"] for r in matches}),
                                  "override": override.get("source_url")}, "review": sorted(set(issues))})
        plan.append(item)
        if issues:
            review.append({"source_id": item["source_id"], "nombre": name, "issues": sorted(set(issues))})
    codes = collections.Counter(p["codigo"] for p in plan)
    for item in plan:
        if codes[item["codigo"]] > 1:
            item["codigo"] += "-" + str(item["source_id"])
    assert len({x["codigo"] for x in plan}) == len(plan)
    assert len({x["barcode"] for x in plan}) == len(plan)
    output = {"source_sha256": hashlib.sha256(raw).hexdigest(), "source_count": len(products),
              "products": plan, "pending": pending, "review": review}
    (directory / "plan.json").write_text(json.dumps(output, ensure_ascii=False, indent=2), encoding="utf8")
    summary = {"source_count": len(products), "eligible": len(plan), "pending": len(pending),
               "pending_reasons": dict(collections.Counter(x["reason"] for x in pending)),
               "needs_review": len(review), "unclassified": sum(x["familia"] == "PENDIENTE DE CLASIFICACION" for x in plan),
               "unknown_route": sum(x["administracion"] == "NO ESPECIFICADA" for x in plan),
               "with_arsa_evidence": sum(bool(x["evidence"]["registry_refs"]) for x in plan),
               "code_collisions_resolved": sum("-" in x["codigo"][1:] for x in plan),
               "active_links": sum(len(x["actives"]) for x in plan)}
    (directory / "preparation-summary.json").write_text(json.dumps(summary, ensure_ascii=False, indent=2), encoding="utf8")
    print(json.dumps(summary, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--source", type=pathlib.Path, required=True)
    parser.add_argument("--directory", type=pathlib.Path, required=True)
    args = parser.parse_args()
    args.directory.mkdir(parents=True, exist_ok=True)
    prepare(args.source, args.directory)
