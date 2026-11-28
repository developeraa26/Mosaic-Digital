#!/usr/bin/env bash
# Every check for the Ledger Plus package calculator. Exits non-zero if any stage fails.
#
#   bash calculator/test/run-all.sh
#
# Stages:
#   1. page      python3 page/build_page.py (writes page/out/page.json, self-checks the form widget,
#                Zoho mapping and team email) and page/preview.py (hero/overlap/team email screenshots)
#   2. php       test/php/run.sh: php -l, backend unit tests (pricing, browser_config without prices,
#                normalize, validation hook, rate limit, the quote fields on every server path,
#                new_record, mailer, PDF writer, shortcode output without amounts, uninstall)
#                plus the sample PDFs (pdftotext, pdfinfo, pdftoppm -r 110, pypdf) and email shots
#   3. no-prices test/ui/no-prices.test.js: the page source (shortcode, config JSON, form), calculator.js
#                and calculator.css carry no amount, also with lp_calc_config filters that write prices
#                into the config and its texts; the browser's "Your package" lines equal
#                LP_Calc_Pricing::package_lines() for every selection
#   4. ui        test/ui/ui.test.js: Playwright on the real shortcode output + the page.json form; no
#                amount anywhere before the result screen, at 1440-320 px (LP_UI_SHOTS=0 skips the
#                screenshot matrix)
#   5. e2e       test/e2e/run.sh: php -S with the real plugin, Elementor Pro's submission flow
#                emulated from page.json, a full submission with empty figure fields, the server paths
#                without actions_before, tampering, validation, rate limit, failed actions; each run
#                keeps its state in its own test/out/e2e-state-* folder, so two runs can overlap
#
# Outputs (screenshots, PDFs, e2e state) go to calculator/test/out/ (git-ignored).
set -u
TEST="$(cd "$(dirname "$0")" && pwd)"
CALC="$(dirname "$TEST")"
OUT="$TEST/out"
mkdir -p "$OUT"
cd "$TEST" || exit 1

if [ ! -d node_modules/jquery ] || [ ! -d node_modules/intl-tel-input ]; then
	echo "== npm install (jquery, intl-tel-input) =="
	npm install --no-audit --no-fund >/dev/null || { echo "npm install failed"; exit 1; }
fi
# Playwright comes from the global install (NODE_PATH); calculator/test/node_modules has jquery and intl-tel-input.
export NODE_PATH="$TEST/node_modules${NODE_PATH:+:$NODE_PATH}:$(npm root -g 2>/dev/null)"

declare -a NAMES=() RESULTS=()
stage() {
	local name="$1"
	shift
	echo
	echo "################ $name ################"
	local t0=$SECONDS
	if "$@"; then
		RESULTS+=("PASS  $name ($((SECONDS - t0))s)")
	else
		RESULTS+=("FAIL  $name ($((SECONDS - t0))s)")
	fi
	NAMES+=("$name")
}

stage "page: build_page.py (page.json + self-checks)" python3 "$CALC/page/build_page.py"
stage "page: preview.py (hero, overlap, team email)" python3 "$CALC/page/preview.py"
stage "php: unit tests, sample PDFs, email checks" bash "$TEST/php/run.sh"
stage "js: syntax" node --check "$CALC/plugin/ledgerplus-calculator/assets/calculator.js"
stage "js: no prices in the page + package lines = LP_Calc_Pricing" node "$TEST/ui/no-prices.test.js"
stage "ui: Playwright on the real shortcode + page.json form" node "$TEST/ui/ui.test.js"
stage "e2e: php -S + emulated Elementor Pro submission" bash "$TEST/e2e/run.sh"

echo
echo "################ summary ################"
rc=0
for r in "${RESULTS[@]}"; do
	echo "  $r"
	case "$r" in FAIL*) rc=1 ;; esac
done
echo
[ $rc -eq 0 ] && echo "ALL CHECKS PASSED" || echo "SOME CHECKS FAILED"
exit $rc
