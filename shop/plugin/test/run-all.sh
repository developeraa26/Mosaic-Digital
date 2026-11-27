#!/bin/bash
# Runs every Ledger Plus Shop local check. Usage: bash run-all.sh
cd "$(dirname "$0")" || exit 1
B=..
rc=0
echo "== php -l =="
php -l "$B/ledgerplus-shop/ledgerplus-shop.php" || rc=1
php -l "$B/ledgerplus-shop-wpcode.php" || rc=1
# The snippet has no opening tag, so lint it again with one prepended (what eval() sees).
tmp=$(mktemp --suffix=.php)
{ echo '<?php'; cat "$B/ledgerplus-shop-wpcode.php"; } > "$tmp"
php -l "$tmp" > /dev/null && echo "No syntax errors detected in ledgerplus-shop-wpcode.php (with <?php prepended)" || rc=1
rm -f "$tmp"

php run-tests.php || rc=1
node test-inline.js || rc=1
for m in eval-twice plugin-then-snippet snippet-then-plugin no-wc old-wc no-abspath blocks-api twin-sync; do
  php run-variants.php "$m" || rc=1
done
# Concurrency (Fibers + WordPress 7.1.5 option model), against the plugin and the snippet.
tmp_snip=$(mktemp --suffix=.php)
{ echo '<?php'; cat "$B/ledgerplus-shop-wpcode.php"; } > "$tmp_snip"
for s in same-second next-second stale-takeover stale-worst webhook-thankyou geidea-callback-thankyou geidea-thankyou-scheduled; do
  php run-race.php "$s" || rc=1
done
php run-race.php stale-worst "$tmp_snip" > /dev/null && echo "  PASS  stale-worst also with the WPCode snippet" || { echo "  FAIL  stale-worst with the WPCode snippet"; rc=1; }
php run-race.php geidea-callback-thankyou "$tmp_snip" > /dev/null && echo "  PASS  geidea-callback-thankyou also with the WPCode snippet" || { echo "  FAIL  geidea-callback-thankyou with the WPCode snippet"; rc=1; }

# F12 Geidea payment safety net: in-process (stubs, mocked Geidea API) and end to end through PHP's built-in
# server (real php://input, output buffer, die() and shutdown order), against the plugin and the snippet.
php run-geidea.php || rc=1
php run-geidea.php "$tmp_snip" > /dev/null && echo "  PASS  run-geidea.php also with the WPCode snippet" || { echo "  FAIL  run-geidea.php with the WPCode snippet"; rc=1; }
php run-geidea-http.php || rc=1
php run-geidea-http.php "$tmp_snip" > /dev/null && echo "  PASS  run-geidea-http.php also with the WPCode snippet" || { echo "  FAIL  run-geidea-http.php with the WPCode snippet"; rc=1; }

# F13 (1.1.2): reward wording, shop archive redirect, "What happens next" in the Completed order email,
# against the plugin and the snippet (each run also checks the wp-config constants in a second process).
php run-f13.php || rc=1
php run-f13.php "$tmp_snip" > /dev/null && echo "  PASS  run-f13.php also with the WPCode snippet" || { echo "  FAIL  run-f13.php with the WPCode snippet"; rc=1; }
rm -f "$tmp_snip"

echo
[ $rc -eq 0 ] && echo "ALL CHECKS PASSED" || echo "SOME CHECKS FAILED"
exit $rc
