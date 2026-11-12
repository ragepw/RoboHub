"""Synthetischer lokaler Kennungsabgleich; keine realen Geräteinformationen."""
import base64
import copy
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest

spec = importlib.util.spec_from_file_location("bindung", Path(__file__).resolve().parents[2] / ".tools/eureka-geraet-binden.py")
bindung = importlib.util.module_from_spec(spec)
spec.loader.exec_module(bindung)


class BindungTest(unittest.TestCase):
    def setUp(self):
        self.dock = dict(konto=45174, instanz=22708, modell="E20 Evo Plus",
                         zweck="robohub/eureka/dock/runtime/v2", cloudGeraetId="synthetic-device",
                         befehl="AA0DB80000000000000222010016")
        self.info = {"hersteller": "eureka", "geraete": [{"id": "synthetic-device", "modell": "E20 Evo Plus", "roh": {
            "deviceId": "synthetic-device", "product": {"productModel": "E20 Evo Plus", "sn8": "750Y0015",
            "protoVersion": 0, "platform": "robozone", "stationType": "dust_station",
            "plugin": {"name": "T0xB8_750Y0015.zip", "versionName": "2.5.4"}}}}]}
        self.source = "private const BEFEHLE_FREIGEGEBEN = false;\nprivate const BEFEHLS_GERAET_ID = '';\n"

    def test_binding_changes_only_id(self):
        with tempfile.TemporaryDirectory() as directory:
            target = Path(directory) / "module.php"
            target.write_text(self.source)
            bindung.binden(target, "synthetic-device", self.dock, self.info)
            self.assertEqual(target.read_text(), self.source.replace("= '';", "= 'synthetic-device';"))
            bindung.binden(target, "synthetic-device", self.dock, self.info)

    def test_dock_formats(self):
        for doc in [self.dock, {"genehmigung": self.dock},
                    {"nutzlast": base64.b64encode(json.dumps(self.dock).encode()).decode(), "signatur": "historical"}]:
            bindung.pruefen("synthetic-device", doc, self.info)

    def test_mismatches_leave_file_unchanged(self):
        cases = []
        for field in self.dock:
            dock = copy.deepcopy(self.dock)
            dock[field] = "different"
            cases.append(("synthetic-device", dock, self.info))
        for field in self.info["geraete"][0]["roh"]["product"]:
            info = copy.deepcopy(self.info)
            info["geraete"][0]["roh"]["product"][field] = "different"
            cases.append(("synthetic-device", self.dock, info))
        for value in ["", "different", "bad\n", "'", "x" * 129]:
            cases.append((value, self.dock, self.info))
        duplicate = copy.deepcopy(self.info)
        duplicate["geraete"] *= 2
        cases.append(("synthetic-device", self.dock, duplicate))
        for value, dock, info in cases:
            with self.subTest(case=len(cases)), tempfile.TemporaryDirectory() as directory:
                target = Path(directory) / "module.php"
                target.write_text(self.source)
                with self.assertRaises(ValueError):
                    bindung.binden(target, value, dock, info)
                self.assertEqual(target.read_text(), self.source)

    def test_preexisting_release_or_foreign_binding_blocked(self):
        for source in [self.source.replace("false", "true"), self.source.replace("= '';", "= 'different';")]:
            with tempfile.TemporaryDirectory() as directory:
                target = Path(directory) / "module.php"
                target.write_text(source)
                with self.assertRaises(ValueError):
                    bindung.binden(target, "synthetic-device", self.dock, self.info)
                self.assertEqual(target.read_text(), source)


if __name__ == "__main__":
    unittest.main()
