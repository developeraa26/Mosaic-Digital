#!/bin/bash
# Backend checks for the Ledger Plus Package Calculator plugin.
# Usage: bash calculator/test/php/run.sh   (exits non-zero on any failure)
cd "$(dirname "$0")" || exit 1
P=../../plugin/ledgerplus-calculator
OUT=../out
mkdir -p "$OUT"
rc=0

echo "== php -l (plugin backend + tests) =="
for f in "$P/ledgerplus-calculator.php" "$P/uninstall.php" "$P"/includes/*.php ./*.php; do
  php -l "$f" > /dev/null 2>&1 && echo "  ok    $f" || { php -l "$f"; rc=1; }
done
[ -f "$P/templates/calculator.php" ] && { php -l "$P/templates/calculator.php" > /dev/null && echo "  ok    $P/templates/calculator.php" || rc=1; }

echo
echo "== PHP unit tests =="
php run-tests.php || rc=1

echo
echo "== Sample PDFs and emails (pdftotext, pdfinfo, pdftoppm -r 110) =="
php make-samples.php "$OUT" || rc=1

echo
echo "== compute-cli (JS parity helper) =="
res=$(echo '[{"business":"Trading / Wholesale","transactions":"1000","vat_status":"registered","ct_status":"not_registered","employees":"up_to_20","services":"accounting,vat,ct,payroll"}]' | php compute-cli.php)
echo "$res" | grep -q '"total":7000' && echo "  PASS  Excel example via compute-cli = 7000" || { echo "  FAIL  compute-cli: $res"; rc=1; }

if command -v python3.13 > /dev/null; then
  echo
  echo "== PDF structure (pypdf strict) =="
  python3.13 -I - "$OUT" <<'PY' || rc=1
import glob, os, sys, warnings
warnings.simplefilter("error")
try:
    import pypdf
except ImportError:
    print("  NOTE  pypdf not installed: skipped"); sys.exit(0)
bad = 0
for f in sorted(glob.glob(os.path.join(sys.argv[1], "quote-*.pdf"))):
    try:
        r = pypdf.PdfReader(f, strict=True)
        fonts = sorted({str(v["/BaseFont"]) for p in r.pages for v in p["/Resources"]["/Font"].values()})
        ok = fonts == ["/Helvetica", "/Helvetica-Bold"] and r.metadata.producer == "Ledger Plus"
        print(("  PASS" if ok else "  FAIL") + f"  {os.path.basename(f)}: {len(r.pages)} page(s), fonts {fonts}")
        bad += 0 if ok else 1
    except Exception as e:
        print(f"  FAIL  {os.path.basename(f)}: {e}"); bad += 1
sys.exit(1 if bad else 0)
PY
fi

echo
echo "== Client email screenshots (Playwright, 600 and 375) =="
if command -v node > /dev/null; then
  NODE_PATH="${NODE_PATH:-$(npm root -g 2>/dev/null)}" node shot-email.js "$OUT" || rc=1
else
  echo "  NOTE  node not found: skipped"
fi

echo
[ $rc -eq 0 ] && echo "BACKEND CHECKS PASSED" || echo "SOME BACKEND CHECKS FAILED"
exit $rc
