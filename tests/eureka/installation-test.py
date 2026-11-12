"""Offline fixtures for duplicate library/module registrations; no Symcon access."""
import importlib.util
import json
from pathlib import Path
import tempfile

spec = importlib.util.spec_from_file_location(
    'installation', Path(__file__).resolve().parents[2] / '.tools/check-robohub-installation.py')
installation = importlib.util.module_from_spec(spec)
spec.loader.exec_module(installation)
library_id = '{F4D2E22D-E5F0-444D-A2FF-359C105E74FB}'
module_id = '{DB269BEC-0CA6-4E6A-83B8-78FF8CFB2DB0}'

with tempfile.TemporaryDirectory() as directory:
    root = Path(directory)
    for name in ['RoboHub', '.store/eu.dg.robohub']:
        path = root / name
        (path / 'RoboHubKonto').mkdir(parents=True)
        (path / 'library.json').write_text(json.dumps({'id': library_id.lower()}))
        (path / 'RoboHubKonto/module.json').write_text(json.dumps({'id': module_id}))
        duplicates = installation.conflicts(root)
        if name == 'RoboHub':
            assert duplicates == [], 'Single checkout must pass'
        else:
            assert len(duplicates) == 2, 'Hidden Store library and module must conflict'
            assert all(len(paths) == 2 for paths in duplicates)
    # Checking must never move or modify registrations.
    assert len(list(root.glob('**/library.json'))) == 2
    assert installation.conflicts(root) == duplicates
print('Installationsprüfung: Einzelinstallation, versteckte Doppelregistrierung und Lesezugriff bestanden.')
