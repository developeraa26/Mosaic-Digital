# Ledger Plus package cost calculator: build spec

This is the contract shared by everyone building the calculator. Follow it exactly. If anything here is impossible, say so in your result. Do not silently change it.

## Goal

A draft page on ledgerplus.ae where a visitor builds a service package and then leaves their contact details in an Elementor Pro form. The price shows only after that: the page has no prices before a successful submission (1.1.0, the client's request of 27-28 Nov 2026: "no need to show the cost in every step. need to show only after submit personal details."). That form:

1. creates a lead in Zoho CRM through the site's existing "Elementor Pro Form Widget - Zoho CRM - Integration" plugin, using the form action `zoho_Ledgers_Plus`;
2. notifies the team by email (Elementor "email" action, to info@ledgerplus.ae);
3. emails the **client** a branded HTML email with a **PDF quotation attached**. This is done by our new plugin.

There is no PDF download on the page. After a successful submit, the page shows the confirmation and the server-calculated breakdown: this is the first time a price appears.

## Inputs (read them)

- Sample design and flow from the client: `/tmp/claude-0/-home-user-Mosaic-Digital/11279077-d7c0-513e-8725-04117a99ad61/scratchpad/calc-sample.html`. Keep its structure: hero, overlapping card, steps rail, five steps. Use Ledger Plus brand colours (below), not the sample's.
- Pricing workbook: `/tmp/claude-0/-home-user-Mosaic-Digital/11279077-d7c0-513e-8725-04117a99ad61/scratchpad/calc-pricing.xlsx`. Read it with `python3.13`, which has openpyxl. The client chose the **Calculator sheet rules**, and **prices include VAT**. The rules are restated below, so the workbook is reference only.

## Pricing rules (annual, AED, VAT included, whole dirhams)

| key | Service label | Price |
|---|---|---|
| `accounting` | Accounting and bookkeeping | 2,000 |
| `reports` | Quarterly financial reports | 500 × reports per year (1-4) |
| `vat` | VAT returns filing and compliance | 2,000, +500 if VAT status is `not_registered` (VAT registration included) |
| `ct` | Corporate Tax registration and filing | 1,500 if CT status is `registered`, 2,000 if `not_registered` |
| `payroll` | Payroll and WPS support | 1,000, +1,000 if employees is `over_20` |
| `expense` | Expense management | 500 |
| `invoicing` | Invoicing and payment tracking | 1,000 |
| `einvoicing` | UAE e-invoicing | 500 × ceil(annual transactions / 1,000) |
| `account_manager` | Dedicated account manager | 0 (show "Included") |

- Service order everywhere (UI, quote lines, PDF, email) is the table order.
- Transaction volume affects only `einvoicing`.
- Total = sum of the selected lines. No VAT line. Label it "Estimated annual total (incl. 5% VAT)".
- Money format: `AED 7,000` (no decimals).
- Excel default example: `accounting` + `vat` (registered) + `ct` (not registered) + `payroll` (up to 20). The total is **AED 7,000**. Use this as a test case.

### Selection values (whitelists, enforced on the server)

- `business`, exactly one of: `Trading / Wholesale`, `Retail`, `Restaurant / Hospitality`, `E-commerce`, `Construction / Contracting`, `Real Estate`, `Professional Services`, `Other`.
  - With `Other`, `business_other` is required: 1-80 characters, plain text.
- `transactions`: one of 1000, 2000, 3000, 4000, 5000, 10000, 20000, 30000, 40000, 50000. The UI labels them "1,000 transactions a year" and so on. The default is 1000.
- `vat_status`, `ct_status`: `registered` ("Already registered") or `not_registered` ("Not registered"). Both are asked in step 3 and are always required.
- `employees`: `up_to_20` ("Up to 20 employees") or `over_20` ("More than 20 employees"). Required only when `payroll` is selected; the default is `up_to_20`.
- `reports`: 1-4, required only when `reports` is selected; the default is 4.
- `services`: a non-empty subset of the nine keys, sent as a comma-separated string.

## Plugin: `ledgerplus-calculator`

- Location: `/home/user/Mosaic-Digital/calculator/plugin/ledgerplus-calculator/`.
- Main file: `ledgerplus-calculator.php`.
- Header:
  - Plugin Name: Ledger Plus Package Calculator
  - Version: 1.1.0
  - Requires at least: 6.0
  - Requires PHP: 7.4
  - Text Domain: ledgerplus-calculator
- Code must run on PHP 7.4 to 8.4. The live site runs WordPress 7.1, PHP 8.4, Elementor 4.4.1 and Elementor Pro 4.3.1.
- Class prefix: `LP_Calc_`. Constants: `LP_CALC_VERSION`, `LP_CALC_FILE`, `LP_CALC_DIR`, `LP_CALC_URL`.
- The plugin has no settings page and creates no tables or options. It writes transients for rate limiting only. `uninstall.php` deletes `lp_calc_%` transients.

### Files and owners

**Backend:**
- `ledgerplus-calculator.php`
- `includes/class-lp-calc-pricing.php`
- `includes/class-lp-calc-elementor.php`
- `includes/class-lp-calc-pdf.php` (generic PDF writer)
- `includes/class-lp-calc-quote-pdf.php` (quotation layout)
- `includes/class-lp-calc-mailer.php`
- `includes/class-lp-calc-shortcode.php` (registers the shortcode, enqueues the assets, includes the template)
- `uninstall.php`
- `readme.txt`

**Frontend:**
- `templates/calculator.php` (markup)
- `assets/calculator.js`
- `assets/calculator.css`

### `LP_Calc_Pricing` (single source of truth)

- `LP_Calc_Pricing::config(): array` returns everything the UI needs:
  ```
  [
    'currency' => 'AED',
    'vat_included' => true,
    'vat_rate' => 5,
    'business_types' => [...],
    'transactions' => [...],
    'reports_options' => [1, 2, 3, 4],
    'employees' => [
      ['value' => 'up_to_20', 'label' => ...],
      ['value' => 'over_20', 'label' => ...],
    ],
    'statuses' => [
      ['value' => 'registered', 'label' => 'Already registered'],
      ['value' => 'not_registered', 'label' => 'Not registered'],
    ],
    'services' => [
      ['key', 'label', 'hint'],
      ...
    ],
    'prices' => [
      'accounting' => 2000,
      'report_each' => 500,
      'vat' => 2000,
      'vat_registration' => 500,
      'ct_registered' => 1500,
      'ct_not_registered' => 2000,
      'payroll' => 1000,
      'payroll_over_20' => 1000,
      'expense' => 500,
      'invoicing' => 1000,
      'einvoicing_per_1000' => 500,
      'account_manager' => 0,
    ],
  ]
  ```
  - `hint` is short price text, for example "AED 2,000 a year", "AED 500 per report", "From AED 1,500 a year", "AED 500 per 1,000 transactions" or "Included". Since 1.1.0 the page does not show it; it stays in `config()` for filters.
  - Each service also has a `description` with no price ("One point of contact for your account.").
  - The whole array is filterable with `lp_calc_config`. Prices come only from `prices`. The server, the PDF and the emails use them; the page never gets them.
- `LP_Calc_Pricing::browser_config(): array` is the part of the filtered `config()` the page gets:
  - `business_types`, `transactions`, `employees` and `statuses` with `value` and `label` only; `reports_options` as numbers;
  - `services` with `key`, `label` and `description` only;
  - `defaults` (`transactions`, `employees`, `reports`, `services`), each only if the page offers that value;
  - `total_label`;
  - never `prices`, `currency`, `vat_rate`, `vat_included`, a `hint`, or any key a filter adds.
  - Every text it copies is checked with `LP_Calc_Pricing::mentions_price($text, $prices)`. A text states a price when it has a currency ("AED", also "AED2,150", "dirham", "Dh"/"Dhs", "د.إ", USD, EUR, GBP, or $, €, £ before a number), a price word ("extra charge", "free of charge", "no charge", "per report", "Included" with a capital I), or a non-zero default or filtered price as "1,234" or "1234". A number followed by "%", "transactions", "employees" or "reports" is a count.
    - A priced description is left out; the template shows its own text.
    - A priced label falls back to the plugin's own label for that value or service.
    - An option or service the plugin does not know, with a priced label or value, is left out. The server accepts only its own choices, so it could not be sent anyway.
    - A priced `total_label` falls back to "Estimated annual total (incl. 5% VAT)".
- `LP_Calc_Pricing::package_lines(array $selection, ?array $config): array` returns the step 5 "Your package" lines, `['key', 'label', 'detail']`, with no amount:
  - "Annual service" (accounting, expense, invoicing);
  - "4 reports a year" / "1 report a year";
  - "Includes VAT registration" or "Already VAT registered";
  - "Includes Corporate Tax registration" or "Already registered for Corporate Tax";
  - "Up to 20 employees" or "More than 20 employees";
  - "10,000 transactions a year";
  - "One point of contact" (account manager).
  - calculator.js `packageLines()` returns the same lines.
- `LP_Calc_Pricing::normalize(array $raw): array{selection, errors}` validates and normalizes the raw strings against the whitelists. Inputs that don't apply are set to their defaults, never trusted. Each error is a short message a visitor can read.
- `LP_Calc_Pricing::compute(array $selection): array` returns:
  ```
  [
    'lines' => [
      ['key', 'label', 'detail', 'amount' => int],
      ...
    ],
    'total' => int,
  ]
  ```
  `detail` examples:
  - "Annual service"
  - "4 reports a year × AED 500"
  - "Includes VAT registration (AED 500)" or "Already VAT registered"
  - "Includes Corporate Tax registration" or "Already registered for Corporate Tax"
  - "Up to 20 employees" or "More than 20 employees"
  - "10,000 transactions a year (AED 500 per 1,000)"
  - "Included" (for the account manager)
- `LP_Calc_Pricing::money(int): string` returns `AED 7,000`.
- `LP_Calc_Pricing::summary_text(array $selection, array $quote, string $ref): string` returns plain multi-line text for Zoho and the team email:
  ```
  Quote LPQ-261127-4F2A9
  Business: …
  Annual transactions: …
  VAT: …
  Corporate Tax: …
  Employees: … (only if payroll)
  Reports a year: … (only if reports)

  Services:
  - Accounting and bookkeeping: AED 2,000
  …

  Estimated annual total (incl. 5% VAT): AED 7,000
  ```

### Elementor Pro integration (`LP_Calc_Elementor`)

Facts from Elementor Pro 4.3.1 on the site:
- Submission order in `Ajax_Handler::ajax_send_form()`:
  1. `$record->validate()`, which fires `elementor_pro/forms/validation`.
  2. `$record->process_fields()`.
  3. The filter `elementor_pro/forms/record/actions_before`, with `($record, $ajax_handler)`.
  4. Each action's `run()` in turn: save-to-database, email, zoho_Ledgers_Plus.
  5. `do_action('elementor_pro/forms/new_record', $record, $ajax_handler)`.
  6. `send()` returns `{success, data: {message, data: $ajax_handler->data}}`.
- `Form_Record` methods:
  - `get('fields')` returns an array keyed by custom_id, with id, type, title, value, raw_value and required.
  - `get_form_settings('form_name')`.
  - `update_field($id, 'value', $v)` only works if the field exists. Hidden fields must therefore exist in the form.
  - `replace_setting_shortcodes()` reads the field values when the actions run.
- `Ajax_Handler` methods: `add_error($field_id, $msg)`, `add_error_message($msg)` and `add_response_data($key, $data)`.
- The Elementor Pro classes are `ElementorPro\Modules\Forms\Classes\Form_Record` and `...\Ajax_Handler`. Never type-hint them, because the plugin must load even without Elementor Pro.

Behaviour:
1. **Form detection:** `form_name === 'Ledger Plus package calculator'` **and** an `lpc_version` field is present. The result is filterable with `lp_calc_is_calculator_form`. Every other form is left untouched.
2. **`elementor_pro/forms/validation`** (priority 10):
   - Normalize the selection from fields `lpc_business`, `lpc_business_other`, `lpc_transactions`, `lpc_vat_status`, `lpc_ct_status`, `lpc_employees`, `lpc_reports` and `lpc_services`. On any error, call `add_error_message()` with the message.
   - Validate the visible fields:
     - `name`: 2-80 characters;
     - `company`: 2-120 characters;
     - `email`: `is_email`;
     - `phone`: 6-25 characters of `0-9 +()-.` and spaces;
     - `message`: at most 1,000 characters.
     
     Use `add_error($id, $msg)` for each.
   - **Rate limit:** at most 5 calculator submissions per IP per hour, and 3 per email address per 24 hours. Use transients keyed by `lp_calc_rl_` plus the md5 of the IP or of the lowercased email. Count a submission only after validation passes. The limit message is "You have requested several quotations already. Please try again later or email info@ledgerplus.ae." The limits are filterable.
     - *Review addition:* the IP key is the IPv6 /64 network (IPv4 unchanged), and the email key is the canonical mailbox (lowercased, without a "+tag", Gmail dots removed). A site-wide cap (`global`, 30 client emails per hour, same filter) skips only the client email (`email_sent` false); the lead still goes to Zoho and the team.
   - *Review addition:* `name`, `company` and `business_other` must not contain `://`, `www.`, `@` or `mailto:` (PDF viewers turn them into links). Messages: "Please enter your name without a web or email address.", "Please enter your company name without a web or email address.", "Please describe your business activity without a web or email address." calculator.js applies the same rule, and strips spaces from the phone before submit (Elementor Pro's Tel field rejects them on the server).
3. **`elementor_pro/forms/process`** (action at the end of `process_fields()`, priority 10), *1.1.0*: the same work as `actions_before` below, a first time. The page sends the figure fields empty, so the figures must be written before the actions run even if one of the two hooks does not run. `actions_before` then writes them again, with the same selection, quote and ref: it does not normalize the plugin's own output a second time (`clean_text()` decodes HTML entities on each pass, so an "Other" activity such as `&amp;lt;b&amp;gt;` would pass once and fail twice).
4. **`elementor_pro/forms/record/actions_before`** (filter, priority 10):
   - Recompute the quote on the server and make a ref with the format `LPQ-YYMMDD-XXXXX` (UTC date plus 5 uppercase hex characters from `random_bytes`). A ref the visitor posts is never used.
   - Overwrite these field values with `update_field`:
     - `quote_ref`;
     - `quote_total` (for example `AED 7,000`);
     - `quote_summary` (`summary_text`);
     - `field_lead_form`, forced to `Ledgerplus Package Calculator`;
     - `lead_source`, forced to `Website`;
     - `lpc_services`, rewritten as the human-readable labels joined with ", ";
     - *review addition:* `lpc_business` (canonical type), `lpc_business_other` ('' unless Other), `lpc_employees` (the label, or '' without payroll) and `lpc_reports` (the count, or '' without reports), so the saved submission matches the quote.
   - Keep the normalized selection and the quote for this request in a private static property (reset by the validation hook at the start of each submission).
   - Return `$record`.
5. **`elementor_pro/forms/new_record`** (priority 20):
   - If neither hook above priced the record, price it now with a new server ref and write the fields into the record. The actions have run without the figures by then, so log `[lp-calc] quote fields written after the form actions for <ref>; ...`.
   - Build the PDF and email the client. Wrap everything in try/catch: a failure must never break the response.
   - Call `$ajax_handler->add_response_data('lp_quote', [...])` with:
     ```
     [
       'ref',
       'lines' => [
         ['key', 'label', 'detail', 'amount' => 'AED …'],
         ...
       ],
       'total' => 'AED …',
       'email' => <client email>,
       'email_sent' => bool,
     ]
     ```
   - *1.1.0 review:* when a form action failed (Elementor answers "Something went wrong. Please try again, or email info@ledgerplus.ae.") and the client email was not sent, `lines` is empty and `total` is '': the page shows that error, so the response carries no figures either. With the client emailed, the figures stay and the page shows the result (a retry would send a second lead and email).
   - Delete the temporary PDF file afterwards.
   - Log failures with `error_log('[lp-calc] …')` with no personal data.

### PDF quotation (`LP_Calc_PDF` + `LP_Calc_Quote_PDF`)

**Writer:**
- Pure PHP, no external libraries, PDF 1.4.
- A4 page (595.28 × 841.89 pt).
- Core fonts Helvetica and Helvetica-Bold with WinAnsiEncoding. Include the standard AFM glyph widths for both fonts (chars 32-255) so text can be measured and word-wrapped.
- Escape `( ) \`.
- Convert UTF-8 to Windows-1252 with `iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE')`. If a user-supplied value converts to nothing useful (for example Arabic text), show "—" in the PDF; the email still shows the real value.
- Primitives: text (with colour, size and alignment), wrapped text box, filled rectangle, line, rounded rectangle (optional), JPEG image (DCTDecode).
- FlateDecode content streams, a correct xref table, and `/Info` (Title, Producer "Ledger Plus").
- Must support more than one page.

**Logo:**
- Use the `lp_calc_logo_path` filter if set. Otherwise use the uploads file `2026/09/Logo-Assests-01-edited.png` (via `wp_upload_dir()['basedir']`).
- Convert it with GD: flatten the PNG alpha onto white, then output JPEG at quality 90 in memory.
- If GD or the file is missing, draw a text wordmark instead: "LEDGER" in navy plus "PLUS" in blue, bold.

**Layout (A4, 40pt margins, brand colours):**
1. Header: logo left (about 130pt wide). Right-aligned: "QUOTATION ESTIMATE", the ref and the date (`j F Y`, site timezone). Then a 2pt blue rule.
2. "Prepared for": a two-column block with name, company, email, phone and business activity (with the Other text if given).
3. "Business profile": annual transactions, VAT status, CT status, plus employees and reports when relevant.
4. "Selected services and annual charges": a table with columns Service (label, with the detail as a smaller grey line underneath) and Annual fee (AED), right-aligned. Light zebra rows.
5. Total bar: navy fill, white text, "Estimated annual total (incl. 5% VAT)" and the amount.
6. "Notes", in small grey text:
   - "This is an indicative estimate based on the information you provided. Prices are annual and include 5% VAT."
   - "Any services outside the agreed scope will be quoted separately."
   - "Annual payment terms, onboarding and service commencement are subject to confirmation and a final service agreement."
7. "Quotation acceptance": "To proceed, reply to the email this quotation came with, or contact us on +971 50 342 0832." Then two signature lines: "For Ledger Plus" and "For the customer: name / signature / date".
8. Footer on every page: "Ledger Plus UAE · DIFC, Dubai, United Arab Emirates · +971 50 342 0832 · info@ledgerplus.ae · ledgerplus.ae" and "Page n of N".

The file name is `Ledger-Plus-Quotation-<ref>.pdf`.

### Client email (`LP_Calc_Mailer`)

- Sent with `wp_mail(to, subject, html, headers, [pdf_path])`.
  - Subject: `Your Ledger Plus package estimate (<ref>)`.
  - Headers: `Content-Type: text/html; charset=UTF-8` and `Reply-To: Ledger Plus UAE <info@ledgerplus.ae>`. Do not set From: WP Mail SMTP decides it.
  - The to, subject, body and headers are filterable through `lp_calc_client_email`.
- Body:
  - Table-based and inline-styled, max-width 600, readable in Gmail, Outlook and Apple Mail.
  - Header: logo image from the URL in option `woocommerce_email_header_image`, falling back to `https://ledgerplus.ae/wp-content/uploads/2026/09/Logo-Assests-01-edited.png`, 180px wide, alt "Ledger Plus".
  - Greeting: "Hi {first name}".
  - Intro: "Thank you for using the Ledger Plus package calculator. Your estimate {ref} is below, and the full quotation is attached as a PDF."
  - The line table, then a navy total row.
  - "What happens next": "A Ledger Plus advisor will review your selections and contact you within one business day to confirm scope and pricing."
  - Buttons: "Reply to this email" (mailto:info@ledgerplus.ae?subject=Quotation <ref>) and "WhatsApp us" (https://wa.me/971503420832).
  - The small notes, then the footer with contact details.
- Escape every user value with `esc_html`. User text must never become a link.

### Shortcode `[lp_package_calculator]`

- Enqueues `assets/calculator.css` and `assets/calculator.js` (version `LP_CALC_VERSION`, footer, dependency `jquery`).
- Outputs `templates/calculator.php` with `$config = LP_Calc_Pricing::browser_config()` (no prices), printed as `<script type="application/json" id="lp-calc-config">` using `wp_json_encode`.
- The output has no amount: no price, no "AED", no total, in the markup, the attributes or the config JSON. Transaction counts ("1,000 transactions a year", option value `1000`) are not prices.
- Passes `$is_editor = current_user_can('edit_posts')`, so the missing-form notice is shown only to editors.

## Frontend: markup, JS, CSS

- Root: `<div class="lp-calc" data-lp-calc>`. Everything is scoped under `.lp-calc`, with class prefix `lp-calc__`.
- **No amount before a successful submission** (1.1.0), at every width and in every state: steps 1-5, validation errors, a failed submission, the Elementor editor, without JavaScript, after "Start again". An amount is any currency figure or price label: "AED x", "Included" as a price, "From AED x", "x per report", totals, breakdowns. Counts are fine: "1,000 transactions a year", "4 reports a year", "Up to 20 employees".
- Layout follows the sample:
  - **Rail:** steps 1-5 and a "Need a hand?" help box (phone and email), sticky together beside the card on desktop. No estimate box. When the card scrolls into view, its top goes 16px below a fixed or sticky site header and never above the rail's sticky top (`--lp-sticky-top`, 104px); calculator.js sets `--lp-sticky-top` to that same offset, so the steps card and the main card stay level.
  - **Main card:** kicker, "STEP 0n / 05", progress bar, step panels and Back/Continue actions.
- **Step 1, Business:** radio cards for the 8 types. "Other" reveals a required text input.
- **Step 2, Volumes:** an "Annual transactions" select. Hint: "We use this to price your UAE e-invoicing."
- **Step 3, Tax status:** VAT registered? Yes/No. Corporate Tax registered? Yes/No. Map Yes to `registered` and No to `not_registered`.
- **Step 4, Services:** "Select one or more services." Nine checkbox cards, each with label and description; no price hint.
  - Ticking `payroll` reveals the employees choice.
  - Ticking `reports` reveals a reports-per-year select (1-4, default 4).
  - At least one service is required.
  - Default ticked: `einvoicing`.
- **Step 5, Your details:**
  - Intro: "Leave your details to see your price."
  - Above the form: "Your package", a plain list of the selected services with a detail each (`package_lines()`), no amounts and no total. It follows every change, also after Back. The template prints the default list; calculator.js renders the same markup.
  - Then "We'll also email your quotation to you as a PDF." (mail icon).
  - The Elementor form is **moved into the slot** `[data-lp-calc-form-slot]`. Find it with `document.querySelector('.lp-calc-form')`, the widget wrapper with that CSS class.
  - The Continue button is hidden on step 5; the form's own submit button is the action.
- **Inline validation:** messages appear under the fields, never through `alert()`. Focus moves to the step heading on step change, and the card scrolls into view (smooth, honouring reduced motion).
- **No live total** (1.1.0): no `aria-live` region, no phone estimate bar. The browser prices nothing.
- **Hidden fields:** before submit and on every change, write `form_fields[lpc_version]=1` and all the `lpc_*` values, by `name` inside the moved form. Use the raw values: services as a comma list of keys, statuses as `registered` / `not_registered`, `employees` and `reports`. The figure fields `quote_ref`, `quote_total` and `quote_summary` are sent empty; the server writes them.
- **Success:**
  - Listen for `submit_success` on the form (jQuery event, with `response.data`; our data is at `.data.lp_quote`). As a fallback, use `jQuery(document).ajaxComplete` for `action=elementor_pro_forms_send_form` responses.
  - Replace the step-5 content with a result panel: "Thank you, {first name}. Your quotation {ref} is on its way to {email}." (with the "could not email your quotation just now" wording when `email_sent` is false). Then "Your quotation {ref}", the **server** total (navy, above the lines) and the server lines, "What happens next" and a "Start again" button. The heading gets the focus and the card scrolls into view; on a 390 x 844 phone the total is above the fold.
  - `email_sent` false (for example past the 30-an-hour cap): the price still shows, with that wording.
  - `lp_quote` missing or without lines: no figures. "We have received your request. A Ledger Plus advisor will email your quotation to {email} shortly." (with the ref after "request" when `lp_quote` has one), the "Your package" list, and the header "REQUEST RECEIVED".
  - A failed response (`success` false): the result shows only if `lp_quote.email_sent` is true (a later action failed after the client was emailed). Otherwise the page shows Elementor's error and no figures.
  - "Start again" empties the result, resets the choices, rebuilds the package list and goes to step 1, with no amount anywhere.
  - No PDF download button.
- **No form on the page:** editors see a notice that the "Ledger Plus package calculator" form widget is missing. Visitors see "Email info@ledgerplus.ae for a quotation".
- **Brand:**
  - DM Sans (already loaded by the site).
  - Colours: blue `#0f5cd8` (hover `#0b4fc0`), navy `#041950`, ink `#030922`, text `#2c3651`, muted `#5b6684`, border `#dde5f4`, soft background `#f6f8fd`, accent light `#8fb8ff`.
  - Radius 18 for cards and 10 for inputs. White cards with a soft shadow.
- **Style the moved Elementor form** inside `.lp-calc`: labels, inputs, textarea, the tel field with its country-code UI, the submit button (blue, full width, 48px) and the messages. Use selectors strong enough to beat Elementor's `.elementor-widget-form .elementor-field-group > label` and `.elementor-field-textual` rules. Labels must show (the form uses `show_labels`).
- **Responsive:**
  - 850px and narrower: the rail turns into a 5-dot step indicator above the card; the help box moves below the card. Nothing is sticky inside the calculator.
  - 560px and narrower: single column everywhere.
  - No horizontal scroll from 320px up.
  - Tap targets at least 44px.
- **Accessibility:** fieldset/legend for radio and checkbox groups, visible focus, colour contrast at least 4.5:1, and `prefers-reduced-motion`. No `aria-live` total. Every `aria-controls`, `aria-describedby`, `aria-labelledby` and `for` points at an existing id.

## Page (Elementor, draft)

The builder is `/home/user/Mosaic-Digital/calculator/page/build_page.py`. It writes `calculator/page/out/page.json`: the save-page body with keys title, status `draft`, post_type `page`, template_type `wp-page`, page_template `elementor_header_footer` and data. Follow the helpers in `shop/pages/purchase-page-builder.py` (ids, `enc()`, containers).
- Title: "Build Your Package". Slug: `package-calculator`.
- **Hero container:**
  - Navy (`#041950` to `#0a2a7a` gradient) with white text.
  - Eyebrow "LEDGER PLUS UAE · PACKAGE CALCULATOR".
  - H1 "Build a package that works for your business."
  - Sub text from the sample.
  - Three tags: "AI-powered accounting", "VAT & Corporate Tax", "E-invoicing".
  - Bottom padding of about 120px so the calculator card overlaps it.
- **Calculator container** (boxed 1160px, negative top margin of about -80px, `#f6f8fd` background below the hero) holding:
  1. a `shortcode` widget `[lp_package_calculator]`, with `_element_cache` "no";
  2. a `form` widget.
- **Form widget settings:**
  - `form_name` "Ledger Plus package calculator", `_css_classes` "lp-calc-form", `_element_cache` "no", `show_labels` "yes", `mark_required` "yes".
  - `button_text` "Email me my quotation", `button_size` "md", `button_width` "100".
  - Style controls stay at their defaults; the plugin CSS styles the form.
  - **Fields** (custom_id, type, label, width, width_mobile, required):
    - `name`: text, "Full name", 50 / 100, required
    - `company`: text, "Company name", 50 / 100, required
    - `email`: email, "Business email", 50 / 100, required
    - `phone`: tel, "Contact number", 50 / 100, required, `ccfef-country-code-default` "ae"
    - `message`: textarea, "Additional requirements or notes (optional)", rows 3
    - `hp`: honeypot
    - Hidden fields: `lpc_version` (value "1"), `lpc_business`, `lpc_business_other`, `lpc_transactions`, `lpc_vat_status`, `lpc_ct_status`, `lpc_employees`, `lpc_reports`, `lpc_services`, `quote_ref`, `quote_total`, `quote_summary`.
    - Hidden fields copied exactly from the site's hero form: `field_page_title` (dynamic `post-title`), `page_url` (dynamic shortcode `[geturl]`), `lead_source` "Website", `utm_source`, `utm_medium`, `utm_campaign`, `utm_term`, `utm_keyword` (dynamic `request-arg`), and `field_lead_form` "Ledgerplus Package Calculator".
    - The `__dynamic__` strings are given below.
  - **Actions:** `submit_actions` `["save-to-database","email","zoho_Ledgers_Plus"]`.
  - **Team email:**
    - `email_to` "info@ledgerplus.ae"
    - `email_subject` `Package calculator lead: [field id="company"] ([field id="quote_ref"])`
    - `email_content`: branded HTML in the style of the site's team emails (navy header `#041950` with a 4px `#0f5cd8` bottom border, a "Ledger Plus UAE" eyebrow in `#8fb8ff`, a "New package calculator lead" title, and two-column tables with `#f6f8fd` label cells and `#dde5f4` borders). It shows the contact details, `quote_ref`, `quote_total`, `quote_summary` (in a `white-space:pre-wrap` cell), the message, and the source/tracking fields.
    - `email_from` "no-reply@ledgerplus.ae", `email_from_name` "Ledger Plus UAE website", `email_reply_to` "email", `email_content_type` "html".
  - **Zoho mapping** (exact keys, as on the hero form):
    ```
    el_pro_zoho_zoho_Ledgers_Plus_Name               [field id="name"]
    el_pro_zoho_zoho_Ledgers_Plus_Owner              794156085
    el_pro_zoho_zoho_Ledgers_Plus_Email              [field id="email"]
    el_pro_zoho_zoho_Ledgers_Plus_Mobile             [field id="phone"]
    el_pro_zoho_zoho_Ledgers_Plus_Lead_Source        [field id="lead_source"]
    el_pro_zoho_zoho_Ledgers_Plus_Lead_Status        Prospecting
    el_pro_zoho_zoho_Ledgers_Plus_Description        Client Company Name: [field id="company"]\n\nPackage calculator estimate:\n[field id="quote_summary"]
    el_pro_zoho_zoho_Ledgers_Plus_Lead_Form          [field id="field_lead_form"]
    el_pro_zoho_zoho_Ledgers_Plus_utm_keyword        [field id="utm_keyword"]
    el_pro_zoho_zoho_Ledgers_Plus_utm_campaign       [field id="utm_campaign"]
    el_pro_zoho_zoho_Ledgers_Plus_utm_term           [field id="utm_term"]
    el_pro_zoho_zoho_Ledgers_Plus_Page_Name          [field id="field_page_title"]
    el_pro_zoho_zoho_Ledgers_Plus_utm_source         [field id="utm_source"]
    el_pro_zoho_zoho_Ledgers_Plus_Landing_page_URL   [field id="page_url"]
    el_pro_zoho_zoho_Ledgers_Plus_utm_medium         [field id="utm_medium"]
    el_pro_zoho_zoho_Ledgers_Plus_Client_s_message   [field id="message"]
    ```
  - **Messages:** `custom_messages` "yes".
    - `success_message`: "Thank you. Your quotation is on its way to your inbox."
    - `error_message`: "Something went wrong. Please try again, or email info@ledgerplus.ae."
    - `required_field_message`: "This field is required."
    - `invalid_message`: "Please check the highlighted fields and try again."
  - **`__dynamic__` values from the live hero form** (JSON, inside each field object):
    - page title: `{"field_value":"[elementor-tag id=\"df148ed\" name=\"post-title\" settings=\"%7B%7D\"]"}`
    - page url: `{"field_value":"[elementor-tag id=\"03d1df1\" name=\"shortcode\" settings=\"%7B%22shortcode%22%3A%22%5Bgeturl%5D%22%7D\"]"}`
    - utm_source: `{"field_value":"[elementor-tag id=\"b94c60d\" name=\"request-arg\" settings=\"%7B%22param_name%22%3A%22utm_source%22%7D\"]"}`. The others are the same with the param name changed.
  - Every field object also carries `"_id"`: a unique 7-character hex string.

## Tests and evidence

Everything lives under `/home/user/Mosaic-Digital/calculator/test/`. It is runnable with `bash calculator/test/run-all.sh` and exits non-zero on failure.
- **PHP:** stubbed WordPress functions (in the style of `shop/plugin/test/stubs-wp.php`) and fake Elementor `Form_Record` and `Ajax_Handler` classes that follow the real API above. Cover:
  - pricing for every rule, the Excel example (7,000) and the maximum (all services, over 20 employees, 4 reports, not registered, 50,000 transactions);
  - normalize rejections;
  - the validation hook;
  - the rate limit;
  - `actions_before` overwriting the fields;
  - `browser_config()` without prices, `config()` unchanged, and the shortcode output without amounts;
  - the quote fields on every server path when the page sends them empty: normal, without `actions_before`, without the process hook, and without both (`state_from_record`);
  - the second pass (`actions_before` after `process`) reusing the record's quote, also for an "Other" activity with double-encoded entities;
  - `new_record` producing response data, a captured `wp_mail` call with the PDF attachment, and temp-file cleanup;
  - a failed form action: `lp_quote` keeps the figures only when the client was emailed;
  - other forms left untouched.
- **PDF:** generate sample PDFs (short, maximum, non-Latin name). `pdftotext` must show the expected strings, and `pdftoppm -r 110` PNGs are saved under `calculator/test/out/` for visual review.
- **No prices** (replaces the 1.0.0 JS pricing parity): the real shortcode output, its config JSON, the form and the assets carry no amount (search for "AED" in any spelling and every price of `default_prices()` as `1,234` and `1234`; the only allowance is transaction counts: their labels, `value="N"` on the step 2 options and the hidden `lpc_transactions` field, and the config's `"value":N`), also with an `lp_calc_config` filter that adds prices and one that writes them into every label and description. For every selection, calculator.js `packageLines()` equals `package_lines()` and lists the services of the server quote in the same order.
- **UI:** a Playwright harness page that renders the real template through PHP CLI with stubs, plus markup that copies a real Elementor Pro form (use the live hero form markup in `/tmp/claude-0/-home-user-Mosaic-Digital/11279077-d7c0-513e-8725-04117a99ad61/scratchpad/faithful/body-to-hero.html` as the structural reference) and jQuery (`npm i jquery` inside `calculator/test`).
  - Drive all steps, validation errors, hidden-field values and a simulated `submit_success` with `lp_quote`.
  - At every width, scan the visible text and the whole DOM (attributes, the config script) for amounts on steps 1-5, after errors, a failed submission, in the editor, without JavaScript and after "Start again".
  - Screenshots at 1440, 1280, 1024, 850, 768, 560, 390, 375, 360 and 320 widths under `calculator/test/out/`.
  - For realism, the site's real CSS is in `/tmp/claude-0/-home-user-Mosaic-Digital/11279077-d7c0-513e-8725-04117a99ad61/scratchpad/faithful/css/` (Elementor frontend, kit, Hello reset, header). DM Sans woff2 files are in `/tmp/claude-0/-home-user-Mosaic-Digital/11279077-d7c0-513e-8725-04117a99ad61/scratchpad/vendor/dm/package/files/`.
- Do not commit anything under `calculator/test/out/` or `node_modules/`; add a `.gitignore`.

## Hard rules for every builder

- Do not call any WordPress, WPVibe or other MCP site tools. Work only on local files.
- Do not put real customer data anywhere. Use test names such as "Test Customer".
- Do not write the email address of the session owner anywhere.
