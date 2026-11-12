#!/usr/bin/env python3
"""Read-only check of RoboHub registrations, including the hidden Symcon Store."""
import json
from pathlib import Path
import sys


def conflicts(modules):
    registrations = {}
    for library in sorted(modules.glob('**/library.json')):
        metadata = json.loads(library.read_text())
        if metadata.get('id', '').upper() != '{F4D2E22D-E5F0-444D-A2FF-359C105E74FB}':
            continue
        for file in [library, *sorted(library.parent.glob('*/module.json'))]:
            identity = json.loads(file.read_text())['id'].upper()
            registrations.setdefault(identity, []).append(str(file.relative_to(modules)))
    return [paths for paths in registrations.values() if len(paths) > 1]


if __name__ == '__main__':
    root = Path(sys.argv[1] if len(sys.argv) > 1 else '/var/lib/symcon/modules')
    if not root.is_dir():
        sys.exit('Modulverzeichnis fehlt; keine Prüfung erfolgt.')
    try:
        duplicates = conflicts(root)
    except (OSError, ValueError, KeyError):
        sys.exit('Registrierungsprüfung unvollständig; Abbruch.')
    for paths in duplicates:
        print('Doppelte RoboHub-ID: ' + ' | '.join(paths))
    if duplicates:
        sys.exit(1)
    print('Keine doppelten RoboHub-IDs im Modulverzeichnis.')
