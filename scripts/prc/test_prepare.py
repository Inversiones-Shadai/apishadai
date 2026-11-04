import importlib.util
import json
import pathlib
import tempfile
import unittest

spec = importlib.util.spec_from_file_location("prc_prepare", pathlib.Path(__file__).with_name("prepare.py"))
prepare = importlib.util.module_from_spec(spec)
spec.loader.exec_module(prepare)


class CatalogSafetyTest(unittest.TestCase):
    def test_same_brand_with_different_ingredient_is_not_a_match(self):
        product = {"nombre": "Marca 10mg, 30 tabletas", "tipo_presentacion": "Tabletas", "principios_activos": ["Loratadina"]}
        wrong = {"name": "MARCA 10 MG", "form": "TABLETA", "active": "BISOPROLOL", "family": "ANTIHIPERTENSIVO"}
        rows, _ = prepare.match_registry(product, {"MARCA": [wrong]}, {})
        self.assertEqual([], rows)

    def test_different_routes_are_not_collapsed_to_one(self):
        self.assertIsNone(prepare.consensus([{"route": "ORAL"}, {"route": "INTRAVENOSA"}], "route"))

    def test_missing_data_does_not_count_as_agreement(self):
        self.assertIsNone(prepare.consensus([{"family": "ANTIBIOTICO"}, {"family": ""}], "family"))

    def test_packaging_is_not_part_of_concentration(self):
        self.assertEqual("10MG/5ML", prepare.dose("10 mg/5ml en 120ml"))
        self.assertEqual("0.7G/100ML", prepare.dose("0.7 g/100ml"))

    def test_vaginal_tablets_do_not_become_oral(self):
        self.assertEqual(("OVULOS", "VAGINAL"), prepare.source_form({"nombre": "Marca comprimidos vaginales", "tipo_presentacion": "Comprimidos"}))

    def test_shifted_arsa_columns_cannot_become_false_medical_data(self):
        temp_root = pathlib.Path(__file__).resolve().parents[2] / "tmp"
        with tempfile.TemporaryDirectory(dir=temp_root) as directory:
            path = pathlib.Path(directory)
            self.assertTrue(path.resolve().is_relative_to(temp_root.resolve()))
            row = {"Nombre del producto:": "MARCA", "Nombre de sustancias activas / Principio activo:": "MONTELUKAST",
                   "Grupo terapéutico:": "TABLETA RECUBIERTA", "Forma farmacéutica:": "ORAL",
                   "Vía de administración (3):": "CAJA X 30 TABLETAS"}
            (path / "arsa-RMH.json").write_text(json.dumps({"datos": [row]}), encoding="utf8")
            rows, _, _ = prepare.registry_index(path)
            self.assertEqual("TABLETA RECUBIERTA", rows[0]["form"])
            self.assertEqual("ORAL", rows[0]["route"])
            self.assertEqual("", rows[0]["family"])


if __name__ == "__main__":
    unittest.main()
