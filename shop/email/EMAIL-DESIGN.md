# Ledger Plus order emails: new design (Ledger Plus Shop 1.1.0, feature F11)

Request: "email design is not good can you change that, include logo".

The order emails now have a clear Ledger Plus look. The logo sits at the top of a white card with a blue top edge. Headings are navy, the order table is easier to read and shows the VAT, and the plugin adds three cards: "What happens next", a "Your referral code" card with the code in large type, and a "Ledger Plus checks" table for the team. A navy footer carries the company line and clickable phone, WhatsApp, email and website links.

The change has three parts, and all three are needed:

1. **Plugin 1.1.0** (`../build/ledgerplus-shop/ledgerplus-shop.php`, zipped as `../build/ledgerplus-shop-1.1.0.zip`, or the WPCode variant `../build/ledgerplus-shop-wpcode.php`). It is a drop-in replacement for 1.0.0, with the same folder and file name, and every 1.0.0 behaviour is kept. The design can be switched off with the setting `email_branding => false`, which brings back the 1.0.0 emails exactly (checked byte for byte, see section 8).
2. **WooCommerce email settings** (`settings.json` and `settings-per-email.json`, section 4). They set a larger logo, the footer text with contact links, and remove a wrong "contact us at no-reply@" sentence.
3. **Three checks before launch** (section 4c-4e and step 0 of section 7):
   - WooCommerce's **new email templates must be on**. The design is built for them and is not added otherwise. This could not be confirmed from here.
   - **Upload a copy of the logo flattened onto white.** Without it, the dark logo can disappear in dark mode.
   - **Set the real referral reward terms.** The emails now hide the placeholder, but the thank-you page still shows it.

The design only uses WooCommerce hooks. **No WooCommerce template is overridden**, so WooCommerce updates to its email templates keep working.

**Open question for the agency:** the current email already has the logo in its header (the Logo setting is set), so "include logo" probably means it did not show where you read the email. Which mail app was it, and was dark mode on? The likely causes and their fixes:

| Likely cause | Fix |
|---|---|
| **Dark mode** (dark logo on a dark background) | 4c |
| **Pictures not downloaded**, common in Outlook for Windows | The new alt text now shows "Ledger Plus UAE" as a navy wordmark |
| **New templates off**: the legacy blue header band with a full-size logo | Section 7, step 0 |

---

## 1. What was wrong (BEFORE: current live settings + plugin 1.0.0)

These points come from rendering the real WooCommerce 11.3.1 templates with the current settings (see section 8). The BEFORE screenshots assume that WooCommerce's new email templates are on. If they are off, the current email instead looks like `shots/extra/before-legacy-processing-640.png` (blue header band, full-size logo).

- **Logo**: it is in the header, but at 180 px wide on a plain white page the email still looks like the generic WooCommerce template. Apart from the link colour, nothing on the page says Ledger Plus. In dark mode or with pictures blocked, the logo can disappear (see the open question above).
- **Referral code**: it is buried in a 40-word sentence, the code itself is only bold, and the sentence ends with the placeholder "[Reward terms: to confirm]".
- **No "What happens next"**: a buyer of an onboarding service only reads "it is now being processed".
- **Wrong contact address**: the default closing text of the Processing and Completed emails is "If you need any help with your order, please contact us at {store_email}". WooCommerce fills `{store_email}` with the **From** address (`includes/emails/class-wc-email.php:333`), so customers read "contact us at **no-reply@ledgerplus.ae**".
- **Completed order email**: with the new templates, the defaults are the heading "Good things are heading your way!" and the subject "Your order from {site_title} is on its way!" (`includes/emails/class-wc-email-customer-completed-order.php:89-105`). This is shipping wording, and Ledger Plus sells a software package.
- **Placeholder image**: a grey placeholder thumbnail appears next to the product. This assumes product 890 has no featured image, in which case WooCommerce prints its placeholder (`templates/emails/email-order-items.php:45-49, 68-81`).
- **Layout details**: two divider lines sit between the item and the totals. The "Additional information" box has a hard grey border. On phones, the WooCommerce media query shrinks the body text to **12 px** (`templates/emails/email-styles.php:542-544`). The footer text and the order date are #787c82 at 12 px, about 4.2:1 contrast, below the WCAG AA 4.5:1 minimum.
- **Footer**: one grey 12 px line, and none of the phone, WhatsApp, email or website links can be tapped.

## 2. What changed (AFTER)

| Area | Change | How |
|---|---|---|
| Card and header | White card with a 1 px #dde5f4 border, a 4 px #0f5cd8 top edge and 16 px corners. The logo is 200 px wide, left-aligned, with 32 px gutters. The H1 is navy #041950, 28 px (24 px on phones). | Plugin CSS + logo width setting |
| Logo when pictures are blocked | The alt text ("Ledger Plus UAE", the store name) shows as a navy, bold, 22 px sans-serif wordmark instead of a small blue serif link (`shots/extra/images-blocked-processing-*.png`). | Plugin CSS |
| Logo in dark mode | **Required:** a logo file flattened onto white (4c). As a secondary measure, the logo link has a white background colour and a one-colour white background image. Some apps that invert colours leave that alone, but Outlook for Windows ignores it, and apps that darken colours themselves recolour it. | Logo file + plugin CSS |
| Order table | Small uppercase muted column labels, the product name in bold navy, and the Total at 20 px bold navy. "(includes AED 95.19 VAT)" sits on its own line at 14 px in the body text colour. The payment method is muted. There is **one** divider per section: the line under the totals is gone, because the next card or WooCommerce's divider already separates them. Cell padding is narrower, so the product name has room on phones. | Plugin CSS |
| Customer note (when the buyer leaves one) | One divider above the note and none below it (WooCommerce draws two lines and a darker grey rule), with a navy "Customer note" label (`shots/extra/note-processing-*.png`). | Plugin CSS |
| Product thumbnail | Hidden when no ordered product has an image. If product 890 gets an image, the thumbnail comes back. | `woocommerce_email_order_items_args` |
| "What happens next" (Processing order email) | A card with the three numbered steps (the same text as the thank-you page) and "Need help? **Call** or **WhatsApp** **+971 50 342 0832**." with `tel:` and `wa.me` links. The plain-text email gets the same steps as text. | Plugin card |
| "Your referral code" (Processing and Completed emails) | A light-blue card with the label "YOUR REFERRAL CODE" and the code at 26 px bold in a white box with a dashed blue border. Below it: "Share it with another business. When they buy Ledger Plus with your code, you get AED 999 cash back after their payment is confirmed." The reward terms follow in muted text once real terms are set. The placeholder "[Reward terms: to confirm]", or any text wholly in [brackets], is left out (HTML and plain text). | Plugin card |
| "Ledger Plus checks" (New order and "Payment confirmed" admin emails) | A label/value table: trade licence, VAT TRN, referral code used and its status, possible existing customer, buyer's own code. A possible existing customer is shown in **red**. The plain text is unchanged. | Plugin card |
| Card spacing | The gap below each card is padding in a full-width spacer cell, not a table margin, so it also holds in Outlook for Windows. | Plugin cards |
| Billing address / Additional information | Section titles have the same size and colour as the other headings. The billing phone link stays **underlined**, so it does not rely on colour alone (WCAG 1.4.1). The trade licence box has a soft #f6f8fd background. | Plugin CSS |
| Closing text | Left-aligned instead of centred. The recommended per-email text drops the no-reply address. | Plugin CSS + per-email settings |
| Footer | Navy #041950 band with rounded bottom corners, on four lines: "**Ledger Plus UAE**", "DIFC, Dubai, United Arab Emirates", "+971 50 342 0832 · WhatsApp" and "info@ledgerplus.ae · ledgerplus.ae". The links are bold light blue #8fb8ff. | Plugin CSS + footer text setting |
| Mobile (≤ 600 px) | Body text 15 px instead of WooCommerce's 12 px, 20 px gutters, smaller H1 and Total, tighter cards. | Plugin CSS, media query |
| Line length | Every line of the sent message stays within the 998 characters that RFC 5322 allows. Emogrifier writes the mobile CSS on one line, so the plugin breaks it, and also breaks over-long lines between block-level tags. Checked to make no visual change. | `woocommerce_mail_content` |
| WooCommerce email preview | The preview's dummy order has no Ledger Plus product, so the cards show the sample code `LP-SAMPLE`. This way the design can be checked in WooCommerce → Settings → Emails. | `woocommerce_is_email_preview` |
| Settings → Emails warning | If WooCommerce's new email templates are off, the plugin shows a warning on that screen, because the design is not added then. | `admin_notices` |

**Font:** the plugin uses WooCommerce's **Font family** setting, mapped as WooCommerce does (`templates/emails/email-styles.php:36, 68`; `src/Internal/Email/EmailFont.php:22-33`). It applies to the CSS and to every card cell. The default and recommended value is "Helvetica": `'Helvetica Neue', Helvetica, Roboto, Arial, sans-serif` (`EmailFont.php:27`).

DM Sans is **not** used. Most email clients cannot load web fonts, and Outlook for Windows shows Times New Roman when the first font of a stack is a web font it cannot load. The cards set the font on every cell, because Outlook for Windows does not pass it down into tables.

**Colours:** the brand colours are set by the plugin, not by the WooCommerce colour pickers (see 4a for what the pickers still control). This also protects the design from WooCommerce's "Auto-sync with theme" reset (4a).

**VAT:** the totals keep WooCommerce's standard rows for prices that include VAT: Subtotal, Total "(includes AED 95.19 VAT)" and Payment method (`includes/class-wc-order.php:219-261`). The plugin does not add "excl. VAT" or "VAT 5%" rows, for three reasons:

- Rewriting WooCommerce's totals for every order email would need fragile request-level switching.
- "Total minus VAT" is not the subtotal once discounts or fees exist.
- The legal VAT breakdown belongs on the tax invoice.

Two WooCommerce settings come close (WooCommerce → Settings → Tax), with trade-offs:

- **"Display tax totals: Itemized"** shows the tax rate's name, e.g. "(includes AED 95.19 VAT 5%)" if the rate is named "VAT 5%" (`class-wc-order.php:230-234`).
- **"Display prices during cart and checkout: Excluding tax"** adds a separate "VAT" row (`includes/abstracts/abstract-wc-order.php:3072-3091`). It also changes the prices shown at checkout (AED 1,903.81 + VAT instead of AED 1,999).

## 3. How the plugin does it (F11 in `../src/core.php`)

All hooks are registered in `LP_Shop::init()` (core.php:202-214):

| Hook (WooCommerce 11.3.1) | Plugin method | Notes |
|---|---|---|
| filter `woocommerce_email_styles` (`includes/emails/class-wc-email.php:979`) | `filter_email_styles()` (core.php:2317) and `email_css()` (:2396) | WooCommerce builds the CSS from `templates/emails/email-styles.php`, runs this filter, and inlines the result with Pelago Emogrifier `CssInliner` (`class-wc-email.php:972-1013`). Rules that cannot be inlined, such as the media query, stay in a `<style>` element. The CSS is appended once (marker comment), and only when the setting is on, the new templates are on, and the email is not a block-editor email. The filter `lp_shop_email_css` can extend it. Selectors are ids, classes, element names and `:first-child`/`:last-child` only: no `:is()`, `:has()`, CSS variables, flex, grid, web font, `@import` or `url()`. |
| action `woocommerce_email_order_meta` (`templates/emails/customer-processing-order.php:64`, `customer-completed-order.php:61`, `admin-new-order.php:54`) | `render_email_block()` (:1972) | The same hook and priority (20) as 1.0.0. It adds the cards for the email ids `customer_processing_order`, `customer_completed_order` and `new_order`. Each card is a one-cell table with `role="presentation"` and inline styles, inside a spacer table (`email_card_open()`, :2531). WordPress 7.1.5's real `wp_kses_post()` keeps every tag, attribute and style (`render/kses-check.php`). |
| filter `woocommerce_email_order_items_args` (`includes/wc-template-functions.php:4019-4031`) | `filter_email_order_items_args()` (:2475) | `show_image` becomes false only when no ordered product has an image. |
| filter `woocommerce_mail_content` (`includes/emails/class-wc-email.php:1275`) | `filter_mail_content()` (:2346) | Line breaks after each `}` in the `<style>` element (Emogrifier joins the leftover CSS into one line, `pelago/emogrifier src/Utilities/CssConcatenator.php:100-103`). In a line still over 998 characters, it also adds a break before each block-level opening tag that directly follows another tag. Only HTML messages, with the new templates and F11 on. |
| action `admin_notices` | `admin_notice_email_templates()` (:2288) | Shown only on WooCommerce → Settings → Emails, to users with `manage_woocommerce`, when F11 is on but the new templates are off. |
| filter `woocommerce_is_email_preview` (`src/Internal/Admin/EmailPreview/EmailPreview.php:644, 353-371`) | `is_email_preview()` (:2186) | Sample cards in the admin preview only. |

**About "new email templates" (email improvements).** This is the WooCommerce feature `email_improvements` (`src/Internal/Features/FeaturesController.php:513`; option `woocommerce_feature_email_improvements_enabled`). It is opt-in (no `enabled_by_default`), but WooCommerce switches it on for every store installed with WooCommerce 9.8.0 or newer (`includes/class-wc-install.php:1349-1353`). The live site's uploads date from 2026, so it is probably on.

**It is not proven.** Logo width, Header alignment and Font family appear in the settings either way; with the feature off they are only greyed out (`'row_class' => 'disabled'`, `includes/admin/settings/class-wc-settings-emails.php:233-261`). Secondary text exists in both modes (:337). Section 7, step 0 tells how to check.

The templates read the feature at `email-header.php:24`, `email-styles.php:25`, `email-order-details.php:22`, `email-order-items.php:24`, `email-addresses.php:27` and the three email templates (`:22/:24`). With the feature off, the F11 CSS is not added. The emails then keep the legacy WooCommerce design, and only the plugin's cards (inline styles) are new (`shots/extra/legacy-processing-640.png`).

**Why no template override:** the header template has no hook between the logo and the H1, but nothing in the design needs one. The logo comes from the WooCommerce setting (`email-header.php:55-86`; width and alignment from `email-styles.php:34-35, 159-171`) and the footer from the footer text setting (`email-footer.php:47-70`). Everything else comes from CSS and the order-meta hook.

## 4. Settings to apply

### 4a. `settings.json`: WooCommerce → Settings → Emails (REST group `email`)

| Option id | Current | New | Why |
|---|---|---|---|
| `woocommerce_email_header_image` | Logo-Assests-01-edited.png | **a flattened copy (4c)** | `settings.json` keeps the current URL, because the new file has no URL until it is uploaded. This option cannot be set through REST (type `email_image_url` is not in `WC_REST_Setting_Options_V2_Controller::is_setting_type_valid()`, `class-wc-rest-setting-options-v2-controller.php:528-546`), so choose the logo in the admin. |
| `woocommerce_email_header_image_width` | 180 | **200** | The logo shows more clearly. It still fits a phone (`max-width: 100%`). |
| `woocommerce_email_header_alignment` | left | left | |
| `woocommerce_email_base_color` (Accent) | #0f5cd8 | #0f5cd8 | |
| `woocommerce_email_background_color` | #f6f8fd | #f6f8fd | |
| `woocommerce_email_body_background_color` | #ffffff | #ffffff | |
| `woocommerce_email_text_color` | #2c3651 | #2c3651 | |
| `woocommerce_email_footer_text_color` (Secondary text) | #787c82 | **#5b6684** | With F11 on, it only changes item meta lines, such as product options (`email-styles.php:184`), because the plugin sets the date and footer colours itself. With F11 off it colours the order date and footer: #5b6684 is the brand muted colour, about 5.9:1 contrast instead of 4.2:1. Typing it in the admin also switches Auto-sync off (see below). |
| `woocommerce_email_footer_text` | one plain line | **4 lines with links** (see below) | Contact details people can tap. |
| `woocommerce_email_reply_to_name` | (not given) | **Ledger Plus UAE** | |
| `woocommerce_email_from_name` / `_from_address` / `_reply_to_enabled` / `_reply_to_address` | Ledger Plus UAE / no-reply@ / yes / info@ | unchanged | Replies go to info@ledgerplus.ae. |

**What the WooCommerce colour pickers still do while F11 is on.**

- **Email background** colours the page around the card.
- **Accent** colours the links that F11 does not style itself.
- **Secondary text** colours item meta (see the table above).
- **Text and Body background** have almost no visible effect.

The plugin fixes the headings (navy), links, body text, table, cards and footer band in the brand colours. To change those, use the `lp_shop_email_css` filter, or switch F11 off. **Font family** does apply (section 2).

Footer text: the line breaks are real new lines. In HTML emails WooCommerce turns them into `<br />` (`wpautop`, `email-footer.php:57-70`), and plain-text emails keep them. Writing `<br>` instead would run the lines together in plain text. The allowed HTML is whatever `wp_kses_post` allows (`includes/rest-api/Controllers/Version3/class-wc-rest-controller.php:489-492`).
```
<strong>Ledger Plus UAE</strong>
DIFC, Dubai, United Arab Emirates
<a href="tel:+971503420832">+971 50 342 0832</a> · <a href="https://wa.me/971503420832">WhatsApp</a>
<a href="mailto:info@ledgerplus.ae">info@ledgerplus.ae</a> · <a href="https://ledgerplus.ae">ledgerplus.ae</a>
```
The plugin CSS styles the footer (navy band, white company name, light-blue links), so this text needs no inline styles.

Leave **Font family** on Helvetica (`woocommerce_email_font_family`, which REST cannot set either).

**Auto-sync with theme: make sure it is off.** `woocommerce_email_auto_sync_with_theme` is a hidden option. It has no row of its own in the settings and cannot be set through REST (type `hidden`, `class-wc-settings-emails.php:349-355`). Stores installed with WooCommerce 9.8.0 or newer start with it set to **yes** (`class-wc-install.php:1349-1353`).

- **What it does when on:** if the theme has a theme.json, then saving the Customizer, switching themes or saving global styles makes WooCommerce overwrite the five email colours (`src/Internal/Email/EmailStyleSync.php:30-46, 91-133`). Hello Elementor ships a theme.json. Because it is a classic theme, the colours become WooCommerce's defaults: a purple accent #8526ff, text #1e1e1e and a white background (`src/Internal/Email/EmailColors.php:30-41, 106-110`).
- **How to switch it off:** the colour palette in the admin switches it off as soon as a colour is changed by hand (`client/admin/client/settings-email/settings-email-color-palette-control.tsx:45-51`). The hidden field is saved with the form (`includes/admin/class-wc-admin-settings.php:1010-1012`). So apply the colours of 4a **in the admin**, not through REST: typing the new Secondary text value and saving is enough. The palette only shows an "Auto-sync with theme changes" toggle while the colours match the theme's (`:85`).

Because F11 fixes its own colours, a reset would only change the page background and a few WooCommerce details. It is still worth avoiding.

### 4b. `settings-per-email.json`: per-email settings (recommended)

| Email (REST group) | Subject | Email heading | Additional content |
|---|---|---|---|
| Processing order (`email_customer_processing_order`) | Your Ledger Plus order #{order_number} is confirmed | Thank you for your order | Thank you for choosing Ledger Plus. Just reply to this email if you have any questions. |
| Completed order (`email_customer_completed_order`) | Your Ledger Plus order #{order_number} is complete | Your order is complete | (same) |

The additional content replaces the default "...contact us at {store_email}", which shows no-reply@ledgerplus.ae. The New order email can keep its defaults.

### 4c. Required before launch: a logo file flattened onto white

The email logo (`Logo-Assests-01-edited.png`, 622×210) is assumed to be a dark wordmark on a **transparent** background. It could not be downloaded from here, so check this first. Open the file in an image editor, or put it on a dark background in a browser: if the background goes dark, the file has transparency.

**Why it matters:** in dark mode, clients recolour the white header. The dark wordmark then sits on a near-black background and almost disappears (`shots/dark-approx/after-processing-375.png`). The CSS white plate only helps in apps that invert colours but leave background images alone. Outlook for Windows ignores it, and apps that darken colours themselves (WebView or Chromium-style automatic darkening) recolour it. Only a logo file with its own white background stays visible everywhere.

**What to do:**
1. Make a copy of the logo with the transparency replaced by solid white **#ffffff**. Keep the same pixel size (622×210 is already about 3× the 200 px display width), and add **no extra margin**. Then Logo width (200) and the left alignment with the heading stay exactly as they are. You can use any image editor (export as PNG or JPG on a white background) or ImageMagick:
   ```bash
   convert Logo-Assests-01-edited.png -background '#ffffff' -alpha remove -alpha off Logo-email-white.png
   ```
2. Upload it under Media, then choose it in WooCommerce → Settings → Emails → **Logo**, and Save. In light mode it looks identical. In dark mode it shows as a white badge, which is the usual result for logos in emails.
3. If you prefer a white margin around the wordmark in dark mode, add it, but raise Logo width by the same proportion. The wordmark then moves right by the margin.

The plugin's CSS plate stays as a secondary measure.

Do **not** use the white-on-dark square logo (Logo-Assests-04.png). Its artwork and padding are unknown here, and it would be invisible on the white header in light mode.

### 4d. Required before launch: the referral reward terms

The plugin's default reward terms are the placeholder "[Reward terms: to confirm]" (`core.php:47`). **The 1.1.0 emails now leave it out.** Any text wholly in [brackets] is treated as a placeholder (`email_reward_terms()`, core.php:2253). But the thank-you page and the My Account dashboard still show it, as in 1.0.0.

Set the agreed short terms, or a link to a terms page, in `wp-config.php`:
```php
define( 'LP_SHOP_REWARD_TERMS', 'Terms apply: ledgerplus.ae/referral-terms' );
```
Or use the `lp_shop_settings` filter (`$s['reward_terms'] = '...';`). Once set, the terms appear in muted text in the referral card and in the plain-text email.

### 4e. How to apply

**In the admin (recommended, because it also switches Auto-sync off, 4a):**
1. Go to WooCommerce → Settings → Emails.
2. Set Logo width to 200, choose the flattened logo (4c), type #5b6684 into Secondary text, paste the footer text above, and set the "Reply-to" name. Save.
3. Open **Processing order** and **Completed order** in the email list, and paste the subject, heading and additional content from 4b.

**Or with the REST API** (a user with `manage_woocommerce` and an application password). The logo and Auto-sync still need the admin.
```bash
# 4a: one call (woocommerce_email_header_image is not accepted by REST)
curl -u USER:APP_PASSWORD -X POST https://ledgerplus.ae/wp-json/wc/v3/settings/email/batch \
  -H 'Content-Type: application/json' \
  -d "$(jq '{update: [to_entries[] | select(.key != "woocommerce_email_header_image") | {id: .key, value: .value}]}' settings.json)"
# 4b: one call per email
for g in email_customer_processing_order email_customer_completed_order; do
  curl -u USER:APP_PASSWORD -X POST https://ledgerplus.ae/wp-json/wc/v3/settings/$g/batch \
    -H 'Content-Type: application/json' \
    -d "$(jq --arg g $g '{update: [.[$g] | to_entries[] | {id: .key, value: .value}]}' settings-per-email.json)"
done
```

## 5. Before / after screenshots

All screenshots are full page, at deviceScaleFactor 1, in Chromium (Playwright). Paths are relative to this folder. The logo is a placeholder of the same size (section 8).

| Email | Before (640 / 375) | After (640 / 375) |
|---|---|---|
| Customer: Processing order | `shots/before-processing-640.png`, `shots/before-processing-375.png` | `shots/after-processing-640.png`, `shots/after-processing-375.png` |
| Customer: Completed order | `shots/before-completed-640.png`, `shots/before-completed-375.png` | `shots/after-completed-640.png`, `shots/after-completed-375.png` |
| Admin: New order | `shots/before-admin-640.png`, `shots/before-admin-375.png` | `shots/after-admin-640.png`, `shots/after-admin-375.png` |

Extra checks (`render/render-extras.sh`):
- `shots/extra/note-processing-*.png`: an order with a customer note. There is one divider above the note and none below it.
- `shots/extra/images-blocked-processing-*.png`: no pictures loaded. The navy "Ledger Plus UAE" wordmark replaces the logo. The broken-image icon is Chromium's; mail apps usually show only the text.
- `shots/extra/legacy-processing-640.png`: plugin 1.1.0 with WooCommerce's **legacy** templates (new templates off). Only the cards are branded. This is the case the Settings → Emails warning catches.
- `shots/extra/before-legacy-processing-640.png`: the **current** email (plugin 1.0.0) if the live store uses the legacy templates. Compare it with the live email in step 0 of section 7.
- `shots/extra/admin-flags-*.png`: the New order email with a referral code used (pending review), a TRN, and a possible existing customer shown in red.
- `shots/extra/preview-processing-*.png`: the WooCommerce admin preview, with a dummy product and the `LP-SAMPLE` cards.
- `shots/extra/paid-notice-640.png`: the plugin's "Payment confirmed: Ledger Plus checks" admin email. It goes through the real `WC_Emails::wrap_message()` (`wpautop`, `wptexturize` and `wp_kses_post`) and `style_inline()`.
- `shots/extra/plugin-only-processing-*.png`: plugin 1.1.0 with the **current** settings, before 4a and 4b are applied.
- `shots/no-style/after-*-375.png`: the `<style>` element removed, so only the inlined styles remain. This is what Gmail shows for the id-based rules (section 6).
- `shots/dark-approx/*.png`: the whole page colour-inverted, with images left alone. This is a rough stand-in for "full invert" dark modes, **not** a client render. Text and cards stay readable. The logo is nearly invisible, which is the reason for 4c.

The final HTML is in `html/after-*.html` and `html/before-*.html`. It is exactly what `WC_Email::send()` passes to `wp_mail()`: after `style_inline()` and the `woocommerce_mail_content` filter (`class-wc-email.php:1275`). The un-inlined template output is in `html/*.raw.html`, and the plain-text versions are in `html/after-*.txt`. The messages are 18-23 KB, far below Gmail's 102 KB clipping limit. The longest line is 976 characters.

## 6. Email client compatibility

None of this was tested in a real mail app. It is based on the markup and on known client support, so do step 7 with real mailboxes.

| Client | What to expect |
|---|---|
| **Gmail** (web, iOS, Android app) | Inline styles are what counts. Gmail keeps the `<style>` block but ignores **id selectors** in it. WooCommerce's own mobile rules use ids (`#header_wrapper`...), and so do the plugin rules for WooCommerce's elements, so in Gmail they do not apply. The inlined desktop values were chosen to work on a phone too (`shots/no-style/`). The plugin's class-based mobile rules for its cards do apply. Rounded corners, the dashed border and the colours work. Dark mode in the Gmail apps: untested; plan on 4c. |
| **Apple Mail / iOS Mail** | Full support, including the `<style>` media query: 15 px text, 20 px gutters, smaller H1 and Total. The emails set their own background colours, which usually keeps them light in dark mode. Untested on a device. |
| **Outlook (new) / Outlook.com / Outlook mobile** | Modern CSS support, so the layout should look like the screenshots. Their dark mode recolours backgrounds and text. Whether the CSS logo plate survives is **untested**; 4c is the reliable fix. |
| **Outlook classic for Windows (Word engine)** | It degrades without breaking:<br>- No rounded corners: square cards and square number badges.<br>- `max-width` is ignored, but WooCommerce's 600 px table cell (`email-header.php:49`) keeps the width.<br>- Emogrifier turns background colours, widths and alignment into `bgcolor`/`width`/`align` attributes (`CssToAttributeConverter`, `class-wc-email.php:1004-1006`).<br>- The gaps between cards are spacer-cell padding, and the font is set on every cell.<br>- **The CSS logo plate does not exist here.** In its dark mode, the white header is recoloured and a transparent dark logo disappears; only 4c prevents that.<br>- Pictures are often blocked until the reader allows them; the styled alt text then stands in for the logo. |
| **Dark mode in general** | All text has an explicit colour and stays readable when inverted. The navy footer and blue elements keep enough contrast. The weak point is the logo: with a transparent dark logo, it is lost in Outlook for Windows and in apps that darken colours themselves. 4c fixes it in all clients. |
| **Phones (375 px)** | No horizontal scrolling (checked: `scrollWidth` equals the viewport for every render). The product name wraps to two lines, and the totals stay right-aligned. |

## 7. How to verify on the site

0. **Check the new email templates** (before anything else). Open WooCommerce → Settings → Emails. They are **off** if any of these is true:
   - "Try our new email templates!" appears below the email colours (`class-wc-settings-emails.php:932-947`).
   - Logo width, Header alignment and Font family are greyed out (`:233-261`).
   - Once 1.1.0 is installed, the plugin shows its warning there.

   If they are off, click the link, check the preview and save. Switching over also changes how WooCommerce's other emails look. Also compare an email you received with `shots/before-processing-640.png` (new templates) and `shots/extra/before-legacy-processing-640.png` (legacy).
1. **Install** plugin 1.1.0 over 1.0.0: Plugins → Add New → Upload Plugin, choose `../build/ledgerplus-shop-1.1.0.zip`, then "Replace current with uploaded". If the WPCode snippet is used instead, replace its code with `../build/ledgerplus-shop-wpcode.php`. Then apply section 4: settings, flattened logo and reward terms.
2. **Preview:** in WooCommerce → Settings → Emails, open the preview pane. Pick "Processing order", "Completed order" and "New order" in the email type list. The CSS, logo and footer show, and the cards appear with `LP-SAMPLE`. The preview's dummy order has no Ledger Plus product, so this checks only the design. The preview applies the same `style_inline()` as a real send (`EmailPreview.php:353-371`).
3. **Real mail apps, in light and dark mode:** use the preview's "send a test email" option (REST `settings/email/send-preview`, `src/Internal/Admin/EmailPreview/EmailPreviewRestController.php:65, 277`). Send to a Gmail account and an Outlook account, and open it in:
   - the Gmail app (iOS or Android) with dark mode on;
   - Outlook for Windows with dark mode on, once **before** allowing pictures and once after;
   - Apple Mail or iOS Mail.

   The logo must be visible in each.
4. **Real emails** (staging, or a test order on live):
   - **Processing** (steps + code): place a test order for product 890 with a test card. Or, on an existing order, set the status to *On hold* and save, then set it to *Processing* and save. WooCommerce sends the Processing email on on-hold → processing (`class-wc-email-customer-processing-order.php:43-46`). The admin also gets the "Payment confirmed: Ledger Plus checks" email, with the new card.
   - **Completed** (code card): set the order to *Completed*.
   - **New order** (checks card): on the order screen, choose Order actions → **Resend new order notification** (`includes/admin/meta-boxes/class-wc-meta-box-order-actions.php:161-171, 213`). Note: "Send order details to customer" (:135-156, 212) sends the **Invoice / order details** email, which never had the plugin cards. It does get the new CSS.
5. Check that:
   - the code in the email matches the order's `_lp_referral_code`;
   - the phone and WhatsApp links open;
   - the plain-text version (for a customer who chose plain text) shows the steps;
   - no "[Reward terms...]" text appears in the emails.
6. To go back to the 1.0.0 emails at any time:
   ```php
   add_filter( 'lp_shop_settings', function ( $s ) { $s['email_branding'] = false; return $s; } );
   ```

## 8. The local renderer (how the screenshots were made)

`render/render.php` runs the **real** WooCommerce 11.3.1 code paths:
- **Templates:** `emails/email-header.php`, `email-footer.php`, `email-styles.php`, `customer-processing-order.php`, `customer-completed-order.php`, `admin-new-order.php`, `email-order-details.php`, `email-order-items.php`, `email-addresses.php`, `email-mobile-messaging.php`, and the plain-text templates.
- **Email classes:** `WC_Email`, `WC_Settings_API`, `WC_Email_Customer_Processing_Order`, `WC_Email_Customer_Completed_Order` and `WC_Email_New_Order`, run unchanged with their constructors and their `get_content()` and `style_inline()`. Then `woocommerce_mail_content`, as `send()` does.
- **WC_Emails content methods**, hooked exactly as its constructor does (`class-wc-emails.php:243-266`).
- **Order and price code:** the `WC_Order` totals methods (`get_order_item_totals()` etc.), `wc_price()`, `wc_get_email_order_items()` and the colour helpers, copied byte for byte from the 11.3.1 source by `render/extract.php`.
- **WordPress 7.1.5:** `plugin.php`, `formatting.php` and `kses.php` (the real `wp_kses_post`, `wpautop` and `wptexturize`), and the HTML API.
- **Pelago Emogrifier 7.3.0** with sabberworm/php-css-parser 8.9.0 and symfony/css-selector 5.4.45. These are the exact versions in WooCommerce 11.3.1's `lib/composer.lock`. They were installed from source into `../../emogrifier-vendor` and aliased into WooCommerce's `Automattic\WooCommerce\Vendor\` namespace.

Only data and environment are stubbed (`render/bootstrap.php`, `render/fixtures.php`, `render/stubs-wc-namespaced.php`): options, the order data, the CheckoutFields service (the trade licence field), and `FeaturesUtil` (new templates on, unless a settings file sets `woocommerce_feature_email_improvements_enabled` to `no`).

Commands (from `render/`):
```bash
bash render-all.sh            # before + after: HTML, plain text, screenshots. Exits 1 on any PHP notice,
                              # horizontal overflow, or an AFTER line over 998 characters
bash render-extras.sh         # the extra checks of section 5
LP_NOTE="..." php render.php processing ../../build/ledgerplus-shop/ledgerplus-shop.php ../settings.json /tmp/x.html   # customer note
LP_IMG=1 php render.php ...   # product 890 with a featured image
php kses-check.php ../../build/ledgerplus-shop/ledgerplus-shop.php
php render.php processing ../../build/ledgerplus-shop/ledgerplus-shop.php settings-before.json /tmp/x.html --branding-off
```
Plugin 1.1.0 with `email_branding` off produced **byte-identical** HTML and plain text to plugin 1.0.0 for all three emails.

Order data used:
- **Order:** #909, 24 Nov 2026 10:42 (Asia/Dubai).
- **Item:** *E-Invoicing Starter Package* ×1. Prices include tax: AED 1,903.81 + VAT 5% AED 95.19 = **AED 1,999.00** (a single tax total, "AED" on the left with a space).
- **Billing:** Aisha Rahman, Example Trading LLC, Office 1203, Bay Square, Business Bay, Dubai, aisha@exampletrading.ae, +971 50 123 4567. Trade licence 1111111, no TRN.
- **Payment:** "Credit / Debit Card (Geidea)".
- **Referral code:** LP-HG4HG7.

**Limitations**
- The logo in the screenshots is a **placeholder** PNG of the same size (622×210, a navy "Ledger Plus" wordmark on transparent, `assets/logo-placeholder-622x210.png`), because ledgerplus.ae cannot be reached from the sandbox. The real logo appears in its place at the same width.
- The product is assumed to have no image, so the BEFORE shows WooCommerce's placeholder (`assets/woocommerce-placeholder-100x100.png`, a look-alike).
- Also assumed: the site title "Ledger Plus UAE", the date format `F j, Y`, no SKU on product 890, the new templates on (section 7, step 0), and no theme overrides of the email templates. Hello Elementor ships none; a child theme's overrides would be listed under WooCommerce → Status → Templates.
- Chromium is not an email client, and the sandbox has no Helvetica (a metric-compatible sans-serif is used). Outlook, Gmail and Apple Mail were not rendered here.

## 9. Files

- `../src/core.php`: F11 source (core.php:2143-2651, plus `render_email_block()`, `render_admin_email_block()` and the paid notice body). `../src/build.php` takes the version from `LP_Shop::VERSION`.
- `../build/ledgerplus-shop/ledgerplus-shop.php` (1.1.0; zipped for upload as `../build/ledgerplus-shop-1.1.0.zip`) and `../build/ledgerplus-shop-wpcode.php` (1.1.0, the same core).
- `../build/test/run-tests.php`: 328 checks, 81 of them for F11. `bash ../build/test/run-all.sh` runs everything, and all checks pass.
- `settings.json`, `settings-per-email.json`, `shots/`, `html/`, `render/` (`render-all.sh`, `render-extras.sh`), `assets/`, and `plugin-1.0.0/` (the 1.0.0 build, kept for the BEFORE renders and for rollback).
