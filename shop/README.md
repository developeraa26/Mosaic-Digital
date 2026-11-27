# Ledger Plus online shop (ledgerplus.ae)

Selling the **E-Invoicing Starter package (AED 1,999 including 5% VAT)** online with WooCommerce, based on the client plan "Ledger-Plus-purchase-page-content-v4".

Status on 24 November 2026: built and saved on the site, **not launched**. The store pages stay in WooCommerce "Coming soon" mode until the client decisions below are made and a payment gateway is connected.

## Purchase flow

1. **Purchase page:** the visitor arrives on `/e-invoicing-starter/` (Elementor page 892). Every "Buy now for AED 1,999" button opens `/checkout/?add-to-cart=890`.
2. **Pricing page:** on `/pricing/` the Starter box gets an "Add to cart" button that opens `/cart/?add-to-cart=890`. This is prepared but not applied until launch; see `launch/pricing_patch_launch.json`.
3. **Cart:** shows the package with VAT included and a "Have a referral code?" box.
4. **Checkout:** the buyer enters name, company, work email, mobile, billing address, trade licence number, an optional VAT TRN and an optional referral code. Payment then runs through Geidea.
5. **After payment:** the thank-you page shows "What happens next" and the buyer's own referral code (`LP-XXXXXX`). The same code is in the order email. The team gets the new-order email with the trade licence, TRN and referral checks.
6. **Account set-up:** the team creates the Ledger Plus account by hand. This step is unchanged from today.

## What is on the site now

| Item | Where | State |
|---|---|---|
| Product "E-Invoicing Starter Package" | product 890, SKU `LP-EINV-STARTER` | published, AED 1,999 incl. VAT, virtual, sold individually |
| Product "Additional 1,000 E-Invoices" | product 891, SKU `LP-EINV-1000` | draft, AED 349 (price to confirm) |
| Purchase page | page 892 `/e-invoicing-starter/` | **draft** (Elementor); all 9 plan sections, brand colours, mobile layout |
| Terms of Sale | page 895 `/terms-of-sale/` | **draft** skeleton; every open point in [brackets] |
| Cart / Checkout / My Account | pages 886 / 887 / 888 | heading, help line and trust notes added; checkout button reads "Pay securely" |
| Store styling | Appearance > Customize > Additional CSS | live, scoped to store pages only (source: `store-css/store.css`) |
| Checkout fields | WooCommerce settings | Company and Phone are **required** |
| Tax and currency | WooCommerce settings | 5% VAT, prices entered incl. VAT, UAE only, prices shown as "AED 1,999.00" |
| Order emails | WooCommerce settings + plugin 1.1 | logo header, branded layout, referral-code card, contact footer; replies go to info@ledgerplus.ae; new-order alerts go to the sales team (see `email/EMAIL-DESIGN.md`) |

The copy follows the client plan. Two kinds of change were made, both to be approved by the client:

- **Fact-checked deadline wording:** section 4 and the top banner were reworded against the Ministry of Finance decisions:
  - revenue under AED 50m: provider by 31 Mar 2027, live 1 Jul 2027;
  - larger businesses: live 1 Jan 2027;
  - fines: AED 5,000 a month, capped at AED 50,000 a year, plus AED 100 per invoice, capped at AED 5,000 a month.
- **Promises marked unconfirmed:** promises that are not true yet are marked `[to confirm]`, for example "login straight away" (accounts are still created by hand).

## Still to do on the site (needs the client or the site owner)

1. **Ledger Plus Shop plugin:** 1.1.0 is active on the site (27 Nov 2026). Version **1.1.1** adds the Geidea payment safety net (item 2): upload `plugin/ledgerplus-shop-1.1.1.zip` and choose "Replace current with uploaded". It also contains everything from 1.1.0 (branded emails). Use one route only, not both:
   - **Plugin zip (recommended):** Plugins > Add New > Upload Plugin > `plugin/ledgerplus-shop-1.1.1.zip` > Activate.
   - **WPCode:** install the free WPCode plugin. The snippet `plugin/ledgerplus-shop-wpcode.php` is then added for you, switched off, and the site owner switches it on.

   The plugin adds:
   - the "AED" symbol;
   - the trade licence and TRN fields;
   - referral codes, with self-referral and repeat-customer checks;
   - referral wording on the cart and checkout;
   - a fix for the "you cannot add another" error;
   - a redirect from the product page to the purchase page;
   - the thank-you page content;
   - the branded order emails (1.1.0);
   - the Geidea payment safety net (1.1.1, item 2).

   Details and a full test plan are in `plugin/PLUGIN-README.md`.
2. **Payment gateway (Geidea):** Geidea Online Payments 3.5.2 is installed and enabled (live UAE account); it supports the block checkout. Stripe is installed but switched off.
   - **Problem found on 27 Nov 2026:** an AED 1.00 test card payment succeeded at Geidea, but the orders (939, 940) stayed "Pending payment" and the thank-you page offered "Pay" again. Geidea's plugin only marks an order paid when Geidea's server-to-server callback (`https://ledgerplus.ae/?wc-api=geidea`) reaches the site and is accepted; nothing from it was recorded for these orders.
   - **Fix:** plugin 1.1.1 (feature F12). It checks the callback and records what the site answered Geidea, and when the callback does not come it asks Geidea's API itself (on the thank-you page, 2 to 120 minutes after checkout, and from the new order action "Check payment with Geidea"). Paid orders then move to **Completed** (Geidea's "success" setting on this site), so refunds through Geidea keep working.
   - **After uploading 1.1.1:** follow the client test plan in `plugin/PLUGIN-README.md` section 7: re-check orders 939, 940 and 937 with the order action, make a new AED 1.00 test payment (the thank-you page must show the paid page with the referral code), read the callback diagnosis in the order's "Ledger Plus" box, and refund the test order from WooCommerce.
   - **Still open with Geidea:** if the diagnosis shows the callback never arrives, or is refused ("Signature INVALID"), ask Geidea support to check the callback URL and the merchant key/API password for the UAE account, and ask the host whether a firewall blocks Geidea's POSTs.
3. **Email delivery:** WP Mail SMTP is installed. Send a test email (WP Mail SMTP > Tools > Email Test) and make sure SPF and DKIM for ledgerplus.ae pass, so order emails do not land in spam.
4. **Policy pages:** finish and publish the Terms of Sale and refund policy, then set them in WooCommerce > Settings > Advanced > Terms and conditions.
5. **Test on staging, then launch:**
   - place a test order on staging;
   - publish page 892;
   - apply the pricing-page patch;
   - switch WooCommerce > Settings > Site visibility to **Live**.

## Decisions needed from the client

- **Price basis:** is AED 1,999 one-time or yearly? If yearly, should it renew automatically?
- **Seller details:** which legal entity sells (DIFC company name, licence number, VAT TRN)? Is it VAT registered?
- **Gateway:** Stripe or Mamo? Start the account checks (KYC) now; acceptance of a DIFC company still has to be confirmed.
- **Tax invoices:** who issues them? Options are the Ledger Plus software, Zoho Books, or a WooCommerce PDF stop-gap. From the client's own e-invoicing date, invoices to businesses must be e-invoices.
- **Set-up times:** how many working hours to create the login, and how many working days for data migration and onboarding?
- **Refund policy:** the refund window and conditions.
- **Referral rules:**
  - reward per referred company or one time only, and any cap;
  - payment method and timing (bank transfer after the refund window);
  - who counts as the "same company";
  - VAT treatment, to be confirmed by a tax adviser.
- **More e-invoices:** what happens after 1,000 e-invoices? Confirm the AED 349 add-on.
- **Demo link:** is the free demo ready? The demo buttons currently link to `https://setup.ledgerplus.ae/accounting-software-in-uae/`.
- **Compliance price:** the plan says "from AED 5,999 a year", but the Packages page says AED 499 a month.
- **Deadline wording:** approve the corrected statements, and do not say Ledger Plus is a Ministry of Finance accredited provider unless it is (or name its partner).

## Phase 2 (not built)

- **Zoho CRM sync:** convert the demo lead, or create the Account, Contact and Closed Won Deal on each paid order.
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
