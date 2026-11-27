# Ledger Plus online shop (ledgerplus.ae)

Selling the **E-Invoicing Starter package (AED 1,999 including 5% VAT)** online with WooCommerce, based on the client plan "Ledger-Plus-purchase-page-content-v4".

Status on 27 November 2026: **live**. WooCommerce site visibility is "Live", Geidea takes card payments, and the shop is linked from the header (Shop menu item, account icon, cart icon). See "Launch on 27 November 2026" below for what was changed and what is still open.

## Purchase flow

1. **Purchase page:** the visitor arrives on `/e-invoicing-starter/` (Elementor page 892). Every "Buy now for AED 1,999" button opens `/checkout/?add-to-cart=890`.
2. **Pricing page:** on `/pricing/` the Starter box has an "Add to cart" button that opens `/cart/?add-to-cart=890` (applied at launch from `launch/pricing_patch_launch.json`).
   **Header:** the main menu has a "Shop" item (to `/e-invoicing-starter/`), and the header shows an account icon (to `/my-account/`) and an Elementor Pro Menu Cart icon with a count bubble and a side cart.
3. **Cart:** shows the package with VAT included and a "Have a referral code?" box.
4. **Checkout:** the buyer enters name, company, work email, mobile, billing address, trade licence number, an optional VAT TRN and an optional referral code. Payment then runs through Geidea.
5. **After payment:** the thank-you page shows "What happens next" and the buyer's own referral code (`LP-XXXXXX`). The same code is in the order email. The team gets the new-order email with the trade licence, TRN and referral checks.
6. **Account set-up:** the team creates the Ledger Plus account by hand. This step is unchanged from today.

## What is on the site now

| Item | Where | State |
|---|---|---|
| Product "E-Invoicing Starter Package" | product 890, SKU `LP-EINV-STARTER` | published, AED 1,999 incl. VAT, virtual, sold individually |
| Product "Additional 1,000 E-Invoices" | product 891, SKU `LP-EINV-1000` | draft, AED 349 (price to confirm) |
| Purchase page | page 892 `/e-invoicing-starter/` | **published** (Elementor); all 9 plan sections, brand colours, mobile layout; no placeholders left (27 Nov 2026) |
| Terms of Sale | page 895 `/terms-of-sale/` | **published**, short version with confirmed facts only (source: `pages/terms-of-sale.html`); set as the WooCommerce terms page; linked in the footer and the purchase page FAQ |
| Cart / Checkout / My Account | pages 886 / 887 / 888 | heading, help line and trust notes added; checkout button reads "Pay securely"; cart and checkout say the price is for one year and does not renew automatically; My Account says it is not the Ledger Plus software login |
| Header / footer | Elementor templates 32 / 36 | Shop menu item, account icon and Menu Cart in the header; "Terms of Sale" in the footer quick links |
| Accounts | WooCommerce settings | guest checkout on; buyers can create a shop account at checkout and log in from checkout; registration on My Account stays off |
| Payments | WooCommerce settings | Geidea only; Cash on delivery and Stripe off |
| Store styling | Appearance > Customize > Additional CSS | live, scoped to store pages only (source: `store-css/store.css`) |
| Checkout fields | WooCommerce settings | Company and Phone are **required** |
| Tax and currency | WooCommerce settings | 5% VAT, prices entered incl. VAT, UAE only, prices shown as "AED 1,999.00" |
| Order emails | WooCommerce settings + plugin 1.1 | logo header, branded layout, referral-code card, contact footer; replies go to info@ledgerplus.ae; new-order alerts go to the sales team (see `email/EMAIL-DESIGN.md`) |

The copy follows the client plan. Two kinds of change were made, both to be approved by the client:

- **Fact-checked deadline wording:** section 4 and the top banner were reworded against the Ministry of Finance decisions:
  - revenue under AED 50m: provider by 31 Mar 2027, live 1 Jul 2027;
  - larger businesses: live 1 Jan 2027;
  - fines: AED 5,000 a month, capped at AED 50,000 a year, plus AED 100 per invoice, capped at AED 5,000 a month.
- **Promises marked unconfirmed:** until launch, promises that were not confirmed were marked `[to confirm]`. At launch they were replaced with the decided wording or with wording that promises nothing unconfirmed (no times, no payout method, no tax-invoice method).

## Still to do on the site (needs the client or the site owner)

1. **Ledger Plus Shop plugin:** upload version **1.2.0**: `plugin/ledgerplus-shop-1.2.0.zip`, then "Replace current with uploaded" (1.1.0 was active on 27 Nov 2026; 1.2.0 replaces 1.1.0, 1.1.1 or 1.1.2 and contains everything from them, including the launch fixes of 1.1.2, the Geidea safety net of item 2 and the Zoho CRM sync of Phase 2). Use one route only, not both:
   - **Plugin zip (recommended):** Plugins > Add New > Upload Plugin > `plugin/ledgerplus-shop-1.2.0.zip` > Activate.
   - **WPCode:** install the free WPCode plugin. The snippet `plugin/ledgerplus-shop-wpcode.php` (v1.2.0) is then added for you, switched off, and the site owner switches it on.

   **New in 1.2.0 (F14, Zoho CRM sync):** every buyer and order goes to the Zoho CRM module of the website forms (Ledgers Plus): Lead Status **Prospecting** for an order that is not paid, **Closed Opportunity** once it is paid, set before the thank-you page is shown. Details under "Phase 2" below.

   **New in 1.1.2 (F13, launch fixes):**
   - the referral wording uses the confirmed rules: "… you can receive AED 999 cash back after their payment is confirmed. No cash back is due if their order is refunded, cancelled or disputed." on the thank-you page, the My Account dashboard and the order emails (until 1.1.1 the thank-you page and My Account showed "[Reward terms: to confirm]" to every buyer; a [bracketed] placeholder in the reward terms is now never shown). Payout method and timing are not promised, because they are not confirmed;
   - `/shop/` and the product category and tag pages redirect to the sales page `/e-invoicing-starter/` (they showed the blog's Elementor archive template); cart, checkout and My Account are not affected;
   - the **Completed order** email now has "What happens next" (the 3 steps and the help line). With Geidea set to "Completed" on success, buyers never get the Processing order email, which was the only one with the steps.

   The plugin adds:
   - the "AED" symbol;
   - the trade licence and TRN fields;
   - referral codes, with self-referral and repeat-customer checks;
   - referral wording on the cart and checkout;
   - a fix for the "you cannot add another" error;
   - a redirect from the product page, `/shop/` and the product archives to the purchase page;
   - the thank-you page content;
   - the branded order emails (1.1.0), with "What happens next" in the Processing and Completed emails (1.1.2);
   - the Geidea payment safety net (1.1.1, item 2);
   - the Zoho CRM sync of buyers and orders (1.2.0).

   After uploading, follow the check lists "After uploading 1.2.0" (Zoho CRM: an unpaid and a paid test order) and, if the site still ran 1.1.0 or 1.1.1, "After uploading 1.1.2" (shop redirect, email preview, an AED 1.00 test payment) in `plugin/PLUGIN-README.md` section 7. Details and a full test plan are in `plugin/PLUGIN-README.md`.
2. **Payment gateway (Geidea):** Geidea Online Payments 3.5.2 is installed and enabled (live UAE account); it supports the block checkout. Stripe is installed but switched off.
   - **Problem found on 27 Nov 2026:** an AED 1.00 test card payment succeeded at Geidea, but the orders (939, 940) stayed "Pending payment" and the thank-you page offered "Pay" again. Geidea's plugin only marks an order paid when Geidea's server-to-server callback (`https://ledgerplus.ae/?wc-api=geidea`) reaches the site and is accepted; nothing from it was recorded for these orders.
   - **Fix:** plugin 1.1.1 (feature F12, also in 1.1.2). It checks the callback and records what the site answered Geidea (and refuses a signed "paid" callback that does not fit the order, for example a different amount), and when the callback does not come it asks Geidea's API itself (on the thank-you page, 2 to 120 minutes after checkout, and from the new order action "Check payment with Geidea"). Paid orders then move to **Completed** (Geidea's "success" setting on this site), so refunds through Geidea keep working.
   - **After uploading 1.1.2 on a site that still ran 1.1.0:** also follow the 1.1.1 client test plan in `plugin/PLUGIN-README.md` section 7: re-check orders 939, 940 and 937 with the order action, make a new AED 1.00 test payment (the thank-you page should show the paid page with the referral code; if not, reload after 20 seconds and check the order again after 2 minutes), read the callback diagnosis in the order's "Ledger Plus" box, and refund the test order from WooCommerce. Emergency off switch: `define( 'LP_SHOP_GEIDEA_SAFETY_NET', false );` in `wp-config.php`.
   - **Still open with Geidea:** if the diagnosis shows the callback never arrives, or arrives with an invalid signature ("Unverified Geidea callback … Signature INVALID"), ask Geidea support to check the callback URL and the merchant key/API password for the UAE account, and ask the host whether a firewall blocks Geidea's POSTs.
3. **Email delivery:** WP Mail SMTP is installed. Send a test email (WP Mail SMTP > Tools > Email Test) and make sure SPF and DKIM for ledgerplus.ae pass, so order emails do not land in spam.
4. **Policy pages:** done at launch (short Terms of Sale with the refund rule, set as the terms page). Later: have a lawyer add the seller's legal details and a liability clause. Keep page 889 (WooCommerce's sample refund policy) unpublished or delete it: it contradicts the 14-day rule.
5. **Launch:** done on 27 November 2026 (see below).

## Launch on 27 November 2026

Done on the site:
- **Clean-up:** Cash on delivery switched off; the TEST90 coupon (99.95% off) and the 7 referral codes from test orders moved to the bin; the 10 test orders deleted for good (the AED 1.00 test charges can be refunded in the Geidea portal).
- **Wording:** the 10 "[… to confirm]" placeholders on page 892 replaced (yearly price, 14-day refund, no times, no payout method); "a year" added to the price note, the final section and the referral example; checkout and cart say the price is for one year and does not renew automatically; the My Account heading no longer promises tax invoices.
- **Terms of Sale** published and set as the WooCommerce terms page; linked in the footer and the FAQ.
- **Header:** Shop menu item, account icon and Menu Cart (template 32); Elementor CSS flushed.
- **Pricing page** button changed to "Add to cart".
- **Accounts:** account creation and log-in at checkout switched on.
- **Store** switched from Coming soon to **Live**.

Still to do (site owner):
1. **Upload Ledger Plus Shop 1.2.0** (item 1; it contains 1.1.2) as soon as possible: until then the thank-you page shows "[Reward terms: to confirm]" after a purchase, `/shop/` shows the blog archive layout, and shop buyers do not reach Zoho CRM.
2. **Email delivery** (item 3): send a WP Mail SMTP test and check SPF, DKIM and DMARC for ledgerplus.ae; check that the "New order" email reaches the sales team.
3. **Test purchase:** one AED 1.00 live test (a 99.95% coupon, then delete it), then refund it from the order with "Refund via Geidea".
4. **SEO:** check that Rank Math keeps cart, checkout and My Account out of search (noindex) and leaves product 890 and the product archives out of the sitemap.
5. **Server cron:** ask the host for a real cron that calls `wp-cron.php` every 5 minutes, so the Geidea safety-net checks run on time.
6. **Nice to have:** a product image for product 890 (shown in the cart and side cart); style the side cart buttons in the Menu Cart widget; replace the "New in store" grid in the empty cart with a link to `/e-invoicing-starter/`; remove the empty "Downloads" tab from My Account (WooCommerce > Settings > Advanced > Account endpoints).

## Decided by the client (27 Nov 2026)

- **Price basis:** AED 1,999 is a **yearly** price, renewed by hand (no automatic renewal).
- **Refund policy:** a refund within **14 days** if no e-invoices were sent.
- **Referral rules (confirmed part):**
  - the referrer can receive AED 999 cash back when another business buys with their code and pays;
  - no reward if the referred order is refunded, cancelled or disputed;
  - no self-referral and no same-group referral.

These are on the Terms of Sale (page 895), the sales page 892, the cart and the checkout since the launch.

## Decisions needed from the client

- **Seller details:** which legal entity sells (DIFC company name, licence number, VAT TRN)? Is it VAT registered?
- **Gateway:** Stripe or Mamo? Start the account checks (KYC) now; acceptance of a DIFC company still has to be confirmed.
- **Tax invoices:** who issues them? Options are the Ledger Plus software, Zoho Books, or a WooCommerce PDF stop-gap. From the client's own e-invoicing date, invoices to businesses must be e-invoices.
- **Set-up times:** how many working hours to create the login, and how many working days for data migration and onboarding? (The pages promise no times until this is decided.)
- **Referral rules still open:**
  - payout method and timing (for example a bank transfer after the refund window); until then no page or email promises either;
  - reward per referred company or one time only, and any cap;
  - who counts as the "same group" (the plugin only catches the same billing email or trade licence; a person reviews the rest);
  - VAT treatment, to be confirmed by a tax adviser.
- **More e-invoices:** what happens after 1,000 e-invoices? Confirm the AED 349 add-on.
- **Demo link:** is the free demo ready? The demo buttons currently link to `https://setup.ledgerplus.ae/accounting-software-in-uae/`.
- **Compliance price:** the plan says "from AED 5,999 a year", but the Packages page says AED 499 a month.
- **Deadline wording:** approve the corrected statements, and do not say Ledger Plus is a Ministry of Finance accredited provider unless it is (or name its partner).

## Phase 2

- **Zoho CRM sync: built in plugin 1.2.0 (F14), to be uploaded** (item 1: `plugin/ledgerplus-shop-1.2.0.zip`, "Replace current with uploaded", then the check list "After uploading 1.2.0" in `plugin/PLUGIN-README.md` section 7). What it does:
  - it uses the site's existing Zoho connection (the "Elementor Pro Form Widget - Zoho CRM - Integration" add-on, which must stay active and connected) and writes to the module the website forms use, **Ledgers Plus**; no new keys;
  - **one record per person**, found by the buyer's email: a new buyer gets a record (name, email, mobile, Lead Source "Website", Lead Form "Ledger Plus Shop", the usual owner, utm values, and a description with company, product, amount, order number, trade licence, VAT TRN and referral details); a buyer already in Zoho keeps their record and gets a note per order;
  - Lead Status **Prospecting** while the order is not paid, **Closed Opportunity** once it is paid (an existing record is never set back to Prospecting, and nothing else of it is changed); Zoho's workflow rules run on every create and update;
  - a Zoho note with the order and product details at checkout and one when the payment is confirmed;
  - checkout and payment only queue a background job, so "Place order" and the Geidea payment are not slowed down; on the thank-you page of a paid order the sync runs first (at most 8 seconds), so the record is Closed Opportunity before the page is shown; if Zoho is slow or down, the page is shown as usual and the job finishes it, with retries after 5, 30 and 120 minutes;
  - each order shows its Zoho record id, the Lead Status sent and any error in its "Ledger Plus" box, and the order action "Send to Zoho CRM again" sends an order now (also orders from before 1.2.0);
  - at most 60 unpaid orders an hour are sent to Zoho (setting `zoho_open_per_hour`), so bots placing unpaid orders cannot fill the CRM or use up the Zoho API credits the website forms share; the others follow in the next hour, and paid orders are never held back;
  - not sent to Zoho: later refunds and cancellations (change the record by hand); no Account, Contact or Deal is created.
- **Automatic account creation:** needs an API in the Ledger Plus software.
- **Tax invoices from the books:** issue them from the accounting system, as e-invoices from the go-live date.

## Folder contents

- `plugin/`: the plugin (`ledgerplus-shop/ledgerplus-shop.php`, zip, WPCode version), its README, and the local test harness (`bash test/run-all.sh`).
- `store-css/`: Additional CSS source and the list of every selector with its WooCommerce 11.3.1 source reference.
- `pages/`:
  - HTML blocks used on the cart, checkout and account pages;
  - notes on the checkout options;
  - the Python builder for the purchase page (Elementor JSON).
- `launch/`: the pricing-page button change to apply on launch day.
