=== Ledger Plus Package Calculator ===
Contributors: ledgerplus
Tags: calculator, quotation, elementor, pdf
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Package cost calculator for Ledger Plus UAE. Visitors build a service package and send their details; then they see the price and receive a PDF quotation by email.

== Description ==

* The `[lp_package_calculator]` shortcode shows the five-step calculator (business, volumes, tax status, services, your details). Step 5 lists the chosen services without prices.
* The page has no prices. The price shows only after the visitor sends their details, from the server's answer. The page source holds the choices and labels only (`LP_Calc_Pricing::browser_config()`), so prices cannot be read from it.
* Step 5 holds the page's Elementor Pro form: the form widget with the CSS class `lp-calc-form` and the form name "Ledger Plus package calculator". It must contain a hidden field `lpc_version`.
* When the form is sent, the plugin:
  1. checks the selection and the contact details on the server and limits how often quotations can be requested;
  2. prices the selection on the server and writes `quote_ref`, `quote_total`, `quote_summary`, `field_lead_form` and `lead_source` into the form, so the team email and the Zoho CRM lead get the server's figures (the page sends these fields empty);
  3. emails the client a branded estimate with the PDF quotation `Ledger-Plus-Quotation-<ref>.pdf` attached, and returns the breakdown to the page, which shows the price.

Prices are annual and include 5% VAT. They are set in `LP_Calc_Pricing::default_prices()` and can be changed with the `lp_calc_config` filter. This readme does not list them: it is a public file on most sites, and the calculator shows prices only after the visitor sends their details.

The plugin has no settings page and creates no tables or options. It only stores short-lived transients for the rate limit.

== Requirements ==

* Elementor Pro (form widget). Without it, the calculator shows but no quotation can be sent.
* An SMTP plugin such as WP Mail SMTP is recommended. The client email sets no From address, so the SMTP plugin decides it. Replies go to info@ledgerplus.ae.
* The PHP GD extension, for the logo in the PDF. Without GD, the PDF shows a "LEDGER PLUS" text wordmark.

== Installation ==

1. Upload the `ledgerplus-calculator` folder to `/wp-content/plugins/` (or upload the zip in Plugins > Add New) and activate it.
2. Build the page with a Shortcode widget `[lp_package_calculator]` and the Elementor Pro form described above.
3. Send a test quotation to an address you can read, and check the team email and the Zoho CRM lead.

== Filters ==

* `lp_calc_config` (array): the whole calculator config, prices included. The server prices quotes with it. If you change `prices`, change the matching service `hint` too. The page gets only the choices and labels from it (`LP_Calc_Pricing::browser_config()`): never `prices`, `currency`, `vat_rate` or the `hint` texts, and no service description that states a price (see `LP_Calc_Pricing::mentions_price()`). Do not put prices in labels.
* `lp_calc_is_calculator_form` (bool, $record): whether a submitted form is the calculator form.
* `lp_calc_rate_limits` (array): `ip` (5), `ip_window` (3600 s), `email` (3), `email_window` (86400 s), `global` (30), `global_window` (3600 s). Each price needs a submission, so a visitor who compares several packages reaches these limits sooner; the limit message points them to info@ledgerplus.ae. A limit of 0 switches that check off. The IP limit counts an IPv6 address by its /64 network. The email limit counts a mailbox: case, a "+tag" and Gmail dots are ignored. `global` caps the client emails the whole site sends; past it the lead still reaches Zoho and the team, and only the client email is skipped. For stronger protection add Cloudflare Turnstile or reCAPTCHA v3 to the form.
* `lp_calc_client_ip` (string): the visitor's IP address. The default is `REMOTE_ADDR`. Behind a proxy or CDN that does not restore the real address, return the right header here, or every visitor shares one limit.
* `lp_calc_logo_path` (string): path of the logo file used in the PDF. The default is `uploads/2026/09/Logo-Assests-01-edited.png`.
* `lp_calc_client_email` (array, $data): `to`, `subject`, `body`, `headers` (and `text`, the plain-text part) of the client email.

== Privacy ==

The plugin does not store submissions. Elementor Pro's "Collect submissions" action and the Zoho CRM integration do. The PDF is written to the server's temporary folder only while the email is sent, then deleted (also at shutdown after a fatal error; folders older than an hour are swept). Rate-limit transients hold an md5 hash of the IP address (IPv6: its /64 network) or mailbox and a counter, and expire after one hour or 24 hours. Error log lines start with `[lp-calc]` and contain no personal data.

== Changelog ==

= 1.1.0 =
* Prices show only after the visitor sends their details; the page no longer contains prices.
* Step 5 lists the chosen services ("Your package") without amounts. The live estimate box, the phone estimate bar and the price hints on the service cards are gone.
* This readme no longer lists the prices: it is a public file on most sites.
* The server writes the quote fields at `elementor_pro/forms/process` as well as in `elementor_pro/forms/record/actions_before`, so Zoho and the team email get the figures even if one of the two hooks does not run.

= 1.0.0 =
* First release.
