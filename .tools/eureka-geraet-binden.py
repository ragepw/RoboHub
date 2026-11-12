#!/usr/bin/env python3
"""Lokaler Kennungsabgleich. Keine Cloud, keine Ausgabe individueller Kennungen."""
import argparse
import base64
import getpass
import hmac
import json
import os
from pathlib import Path
import re
import resource
import stat
import tempfile
import warnings


def pruefen(cloud_id, dock, informationen):
    if not isinstance(cloud_id, str) or not re.fullmatch(r"[A-Za-z0-9_-]{1,128}", cloud_id):
        raise ValueError()
    # Historische Hülle nur zum Kennungsabgleich dekodieren. Keine neue
    # Genehmigung; insbesondere keine aktuelle Gültigkeit oder Signatur behaupten.
    if "nutzlast" in dock:
        dock = json.loads(base64.b64decode(dock["nutzlast"], validate=True))
    dock = dock.get("genehmigung", dock)
    if (dock.get("konto") != 45174 or dock.get("instanz") != 22708
            or dock.get("modell") != "E20 Evo Plus"
            or dock.get("zweck") not in ("robohub/eureka/dock/runtime/v1", "robohub/eureka/dock/runtime/v2")
            or dock.get("befehl") != "AA0DB80000000000000222010016"
            or not isinstance(dock.get("cloudGeraetId"), str)
            or not hmac.compare_digest(cloud_id, dock["cloudGeraetId"])):
        raise ValueError()
    if informationen.get("hersteller") != "eureka":
        raise ValueError()
    geraete = informationen["geraete"]
    if not isinstance(geraete, list):
        raise ValueError()
    treffer = [g for g in geraete if isinstance(g, dict) and g.get("id") == cloud_id]
    if len(treffer) != 1:
        raise ValueError()
    g = treffer[0]
    roh = g["roh"]
    produkt = roh["product"]
    plugin = {"name": "T0xB8_750Y0015.zip", "versionName": "2.5.4"}
    profil = {"productModel": "E20 Evo Plus", "sn8": "750Y0015",
              "protoVersion": 0, "platform": "robozone", "stationType": "dust_station"}
    if g.get("modell") != "E20 Evo Plus" or roh.get("deviceId") != cloud_id:
        raise ValueError()
    for key, wert in profil.items():
        # Keine boolesche Protokollversion als Integer akzeptieren.
        if type(produkt.get(key)) is not type(wert) or produkt[key] != wert:
            raise ValueError()
        if key in roh and (type(roh[key]) is not type(wert) or roh[key] != wert):
            raise ValueError()
    for kandidat in [produkt.get("plugin")] + ([roh["panel"]] if "panel" in roh else []):
        if not isinstance(kandidat, dict) or any(kandidat.get(k) != v for k, v in plugin.items()):
            raise ValueError()
    if "id" in roh and roh["id"] != cloud_id:
        raise ValueError()


def binden(ziel, cloud_id, dock, informationen):
    pruefen(cloud_id, dock, informationen)
    if ziel.is_symlink() or not ziel.is_file():
        raise ValueError()
    quelle = ziel.read_text()
    if quelle.count("private const BEFEHLE_FREIGEGEBEN = false;") != 1:
        raise ValueError()
    muster = r"private const BEFEHLS_GERAET_ID = '([A-Za-z0-9_-]{0,128})';"
    treffer = list(re.finditer(muster, quelle))
    if len(treffer) != 1 or treffer[0][1] not in ("", cloud_id):
        raise ValueError()
    neu = re.sub(muster, "private const BEFEHLS_GERAET_ID = '" + cloud_id + "';", quelle)
    # Atomarer Dateitausch, keine Sicherung mit individueller Kennung in /tmp.
    fd, pfad = tempfile.mkstemp(prefix=".eureka-bindung-", dir=ziel.parent)
    try:
        os.fchmod(fd, stat.S_IMODE(ziel.stat().st_mode))
        with os.fdopen(fd, "w") as datei:
            datei.write(neu)
            datei.flush()
            os.fsync(datei.fileno())
        if ziel.is_symlink() or ziel.read_text() != quelle:
            raise ValueError()
        os.replace(pfad, ziel)
    finally:
        if os.path.exists(pfad):
            os.unlink(pfad)


def main():
    resource.setrlimit(resource.RLIMIT_CORE, (0, 0))
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--dock-nachweis", type=Path, required=True)
    parser.add_argument("--geraeteinformationen", type=Path, required=True)
    args = parser.parse_args()
    try:
        # Kein getpass-Fallback auf sichtbare Eingabe oder umgeleitetes stdin.
        with open("/dev/tty", "w") as terminal, warnings.catch_warnings():
            warnings.simplefilter("error", getpass.GetPassWarning)
            cloud_id = getpass.getpass("Individuell bestätigte Cloud-ID (verdeckt): ", stream=terminal)
            wiederholung = getpass.getpass("Cloud-ID erneut (verdeckt): ", stream=terminal)
        if not hmac.compare_digest(cloud_id, wiederholung):
            raise ValueError()
        binden(Path(__file__).resolve().parents[1] / "libs/RoboHubEureka.php", cloud_id,
               json.loads(args.dock_nachweis.read_text()), json.loads(args.geraeteinformationen.read_text()))
        print("Gerätebindung lokal abgeglichen und eingetragen; Produktivfreigabe bleibt false.")
        return 0
    except (Exception, KeyboardInterrupt):
        print("Gerätebindung abgebrochen: Eingabe oder lokale Vergleichsbefunde nicht bestätigt.")
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
