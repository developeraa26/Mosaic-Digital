# Ledger Plus package cost calculator

A five-step calculator: business, volumes, tax status, services, then your details.

- Visitors see a live annual estimate.
- When they send the form:
  - the lead goes to Zoho CRM;
  - the team gets an email;
  - the client gets an email with the PDF quotation attached.
- There is no PDF download on the page.

Prices come from the "Calculator" sheet of `Ledger_Plus_Package_Cost_Calculator.xlsx`. They are annual and include 5% VAT. The full contract is in `SPEC.md`.

## What is on the site (27 Nov 2026)

- **Draft page 941, "Build Your Package"**, slug `package-calculator`, template Elementor Full Width.
  - Preview, logged in: https://ledgerplus.ae/?page_id=941&preview=true
  - Edit: https://ledgerplus.ae/wp-admin/post.php?post=941&action=elementor
  - It holds the navy hero, a Shortcode widget `[lp_package_calculator]`, and an Elementor Pro form "Ledger Plus package calculator" (CSS class `lp-calc-form`). The plugin moves that form into step 5.
  - The stored `_elementor_data` matches `page/out/page.json` exactly: MD5 `1c5a855244fee8a34abc0c0e86d4e7d9`, 20,273 characters.
- **Plugin `ledgerplus-calculator` 1.0.0: not installed yet.** Until it is active, the draft shows the raw shortcode and a plain form.
  - The zip is `plugin/ledgerplus-calculator-1.0.0.zip`, SHA-256 `35db876766efc26321ce4a2f4f1939bc56d1da28deb2cfee8dad8a64578d9b03`.
  - The source is in `plugin/ledgerplus-calculator/`.

## Go-live steps

1. **Install the plugin:** Plugins > Add New > Upload Plugin, choose `ledgerplus-calculator-1.0.0.zip`, then Activate.
2. **Preview the draft** (link above) at desktop and phone width.
3. **Send one test quotation** to an inbox you can read, with phone `050 123 4567`. Then check:
   - The client email arrives with the PDF attached.
   - The team email reaches info@ledgerplus.ae.
   - In Zoho CRM, the new lead has:
     - Lead Form "Ledgerplus Package Calculator";
     - Lead Source "Website";
     - Owner, the same as the hero form's leads;
     - a Description with the company and the estimate breakdown on separate lines.
   - The phone in Zoho keeps its +971 country code.
4. **Zoho picklist:** if "Lead Form" is a picklist in Zoho, add the value `Ledgerplus Package Calculator`. Otherwise Zoho may reject the value or leave it blank.
5. **Publish** the page and link to it, for example from Pricing or the menu.
6. Delete the test lead from Zoho, and the test entry from Elementor > Submissions.

## How it works

- **Pricing runs twice.**
  - The browser prices the selection for the live estimate.
  - On submit, the server prices it again (`LP_Calc_Pricing`). It overwrites the hidden fields `quote_ref`, `quote_total`, `quote_summary`, `field_lead_form`, `lead_source` and the `lpc_*` fields before Zoho and the team email run. A visitor cannot change the figures that reach the CRM.
- **Client email:**
  - sent through `wp_mail`, so it uses WP Mail SMTP;
  - Reply-To is info@ledgerplus.ae;
  - the attachment is `Ledger-Plus-Quotation-<ref>.pdf`;
  - the PDF is built in PHP (no library), written to a temp folder while the email is sent, then deleted.
- **Rate limits:**
  - 5 quotations per IP per hour;
  - 3 per mailbox per 24 hours (case, "+tag" and Gmail dots ignored);
  - 30 client emails per hour across the site. Past that cap the lead still reaches Zoho and the team; only the client email is skipped.
  - Change the limits with the `lp_calc_rate_limits` filter.
- **Validation:** names, company and the "Other" activity reject web or email addresses, so the PDF cannot be used to send links to strangers. The phone needs 6-25 characters and at least 6 digits.
- **Prices:** to change them, use the `lp_calc_config` filter (see `plugin/ledgerplus-calculator/readme.txt`). The browser and the server read the same config.

## Things to know

- **Behind a CDN or proxy:** the IP limit uses `REMOTE_ADDR`. If the site sits behind a CDN that does not restore the visitor IP, all visitors share one limit; fix it with the `lp_calc_client_ip` filter.
- **Arabic and other non-Latin names:** the PDF uses the standard Helvetica font. Names in Arabic script show as "—" in the PDF; the email shows them correctly.
- **No BCC:** the team email goes to info@ledgerplus.ae only. The hero form also BCCs a second address; add it here under Actions After Submit > Email if wanted.
- **Phone default country:** UAE (+971). The hero form defaults to India.
- **"What happens next":** the email and the result screen promise contact "within one business day". Change the wording if that is not the team's standard.
- **Separate price lists:** the calculator's prices are not the shop's AED 1,999 E-Invoicing Starter package.

## Tests

`bash calculator/test/run-all.sh` runs everything below and prints a summary. Generated files go to `test/out/` (git-ignored). The last run, on 27 Nov 2026, was all green.

- **Page:** the page builder and its checks.
- **PHP:** 247 unit tests; seven sample PDFs checked with pdftotext, pdfinfo and pypdf; client emails at 600/375/320 px, also without styles and in an Outlook simulation.
- **Pricing parity:** the browser and the PHP pricing agree on all 76,760 combinations.
- **UI:** 416 Playwright checks on the real shortcode plus the page's form, at 1440-320 px.
- **End to end:** 159 checks. A local server emulates the Elementor Pro submission, including Zoho, the team email, the client email with the PDF, tampering, validation, rate limits and a Zoho failure.

The tests emulate Elementor Pro and the Zoho add-on; one real submission on the draft (go-live step 3) is still needed.

## Previews (`previews/`)

From the test harness with the site's fonts and colours.

- `calculator-desktop-1440.png`: step 4 (services) on desktop.
- `calculator-phone-390-details.png`: step 5 on a phone.
- `calculator-result-1440.png`: the result after sending.
- `client-email-600.png`: the client email.
- `team-email-640.png`: the team email.
- `sample-quotation.pdf`: the PDF attached to the client email (the Excel example, AED 7,000).
