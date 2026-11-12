#!/usr/bin/env bash
set -euo pipefail
cd -- "$(dirname -- "$0")/../.."
# Kein Fallback ohne Netzisolierung. Loopback dient nur lokalen Hersteller-Nachbauten.
export ROBOHUB_TEST_OUTER_NETNS="$(readlink /proc/self/ns/net)"
exec bwrap --unshare-net --dev-bind / / -- bash -euo pipefail -c '
php tests/structure-test.php
php tests/eureka/diagnostic-test.php
php tests/eureka/signer-test.php
php tests/eureka/import-test.php
php tests/eureka/root-import-test.php
php -d disable_functions=curl_init,curl_setopt_array,curl_exec,curl_getinfo,curl_close tests/eureka/diagnostic-http-test.php
python3 tests/eureka/diagnostic-native-test.py
php tests/eureka/command-test.php
php tests/eureka/state-test.php
php tests/eureka/token-test.php
php .tools/test-robohub.php
rg --files --hidden --no-ignore -g "*.php" -0 | xargs -0 -n1 php -l
git diff --check
'
