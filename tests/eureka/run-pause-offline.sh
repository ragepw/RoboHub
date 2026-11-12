#!/usr/bin/env bash
set -euo pipefail
cd -- "$(dirname -- "$0")/../.."
# Kein Fallback ohne Netzisolierung. Keine Genehmigungen, Importe oder Signaturgeneratoren.
export ROBOHUB_TEST_OUTER_NETNS="$(readlink /proc/self/ns/net)"
exec bwrap --unshare-net --dev-bind / / -- bash -euo pipefail -c '
php_flags="sodium_crypto_sign_detached,sodium_crypto_sign_keypair,sodium_crypto_sign_seed_keypair"
php -d disable_functions="$php_flags,curl_init,curl_exec" tests/eureka/pause-test.php
php -d disable_functions="$php_flags" tests/structure-test.php
php -d disable_functions="$php_flags,curl_init,curl_exec" tests/eureka/dock-test.php
php -d disable_functions="$php_flags,curl_init,curl_setopt_array,curl_exec,curl_getinfo,curl_close" tests/eureka/diagnostic-http-test.php
php -d disable_functions="$php_flags" -r '\''define("ROBOHUB_TEST_OHNE_SIGNATUREN", true); require "tests/eureka/diagnostic-test.php";'\''
python3 tests/eureka/diagnostic-native-test.py
php -d disable_functions="$php_flags" tests/eureka/command-test.php
php -d disable_functions="$php_flags" tests/eureka/state-test.php
php -d disable_functions="$php_flags" tests/eureka/token-test.php
php -d disable_functions="$php_flags" .tools/test-robohub.php
rg --files --hidden --no-ignore -g "*.php" -0 | xargs -0 -n1 php -l
git diff --check
'
