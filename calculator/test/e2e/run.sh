#!/usr/bin/env bash
# End-to-end test: php -S with the real plugin and an emulated Elementor Pro submission flow,
# driven by Playwright. Usage: bash calculator/test/e2e/run.sh   (exits non-zero on failure)
set -euo pipefail
cd "$(dirname "$0")"
if [ ! -d ../node_modules/jquery ] || [ ! -d ../node_modules/intl-tel-input ]; then
	(cd .. && npm install --no-audit --no-fund >/dev/null)
fi
if [ ! -f ../../page/out/page.json ]; then
	python3 ../../page/build_page.py >/dev/null
fi
php -l router.php >/dev/null
php -l ../wp/env.php >/dev/null
php -l ../wp/elementor-form.php >/dev/null
node --check e2e.test.js
node e2e.test.js
