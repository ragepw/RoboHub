#!/usr/bin/env bash
set -euo pipefail
cd -- "$(dirname -- "$0")/../.."
# Alle Steuerungs-, Einmal- und Lesepfade; nur Doubles und isoliertes Loopback.
# Keine Ausführung ohne Netzisolierung. Schlüssel entstehen ausschließlich in Tests.
export ROBOHUB_TEST_OUTER_NETNS="$(readlink /proc/self/ns/net)"
exec bwrap --unshare-net --dev-bind / / -- bash -euo pipefail -c '
php tests/structure-test.php
python3 -B tests/eureka/installation-test.py
python3 -B tests/eureka/binding-test.py
php -d disable_functions=curl_init,curl_exec tests/eureka/pause-test.php
php -d disable_functions=curl_init,curl_exec tests/eureka/dock-test.php
php -d disable_functions=curl_init,curl_setopt_array,curl_exec,curl_getinfo,curl_close tests/eureka/diagnostic-http-test.php
php tests/eureka/diagnostic-test.php
php tests/eureka/signer-test.php
php tests/eureka/import-test.php
php tests/eureka/root-import-test.php
php -d disable_functions=curl_init,curl_setopt_array,curl_exec,curl_getinfo,curl_close tests/eureka/dock-signer-test.php
python3 tests/eureka/diagnostic-native-test.py
php tests/eureka/command-test.php
php tests/eureka/state-test.php
php tests/eureka/token-test.php
php .tools/test-robohub.php
rg --files --hidden --no-ignore -g "*.php" -0 | xargs -0 -n1 php -l
git diff --check
'
