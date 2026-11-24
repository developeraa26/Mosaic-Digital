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
for m in eval-twice plugin-then-snippet snippet-then-plugin no-wc old-wc no-abspath blocks-api; do
  php run-variants.php "$m" || rc=1
done
# Concurrency (Fibers + WordPress 7.1.5 option model), against the plugin and the snippet.
tmp_snip=$(mktemp --suffix=.php)
{ echo '<?php'; cat "$B/ledgerplus-shop-wpcode.php"; } > "$tmp_snip"
for s in same-second next-second stale-takeover stale-worst webhook-thankyou; do
  php run-race.php "$s" || rc=1
done
php run-race.php stale-worst "$tmp_snip" > /dev/null && echo "  PASS  stale-worst also with the WPCode snippet" || { echo "  FAIL  stale-worst with the WPCode snippet"; rc=1; }
rm -f "$tmp_snip"

echo
[ $rc -eq 0 ] && echo "ALL CHECKS PASSED" || echo "SOME CHECKS FAILED"
exit $rc
