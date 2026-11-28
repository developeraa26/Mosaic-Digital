#!/usr/bin/env bash
# Front-end tests: no prices in the page (Node) and the Playwright UI suite with screenshots.
# Usage: bash calculator/test/ui/run.sh   (LP_UI_SHOTS=0 to skip screenshots)
set -euo pipefail
cd "$(dirname "$0")"
if [ ! -d ../node_modules/jquery ] || [ ! -d ../node_modules/intl-tel-input ]; then
	(cd .. && npm install --no-audit --no-fund >/dev/null)
fi
php -l ../../plugin/ledgerplus-calculator/templates/calculator.php >/dev/null
node --check ../../plugin/ledgerplus-calculator/assets/calculator.js
node no-prices.test.js
node ui.test.js
