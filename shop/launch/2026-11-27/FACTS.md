# Ledger Plus shop launch, 27 Nov 2026: confirmed facts and current state

## Confirmed by the client (answers given today)
- Price basis: AED 1,999 is a YEARLY price, VAT included, renewed MANUALLY (Ledger Plus sends a renewal invoice; no automatic card charge; the shop has no subscriptions plugin).
- Refund policy: full refund within 14 days of payment if no e-invoices have been sent.
- Terms of Sale: publish a SHORT version with only confirmed facts; leave out unconfirmed clauses (seller legal name/licence/TRN are NOT known; referral payout rules not confirmed; no lawyer-written liability clause yet).
- Backup done; delete all test orders and test coupons.

## Facts already known
- Product: E-Invoicing Starter package, AED 1,999 incl. 5% VAT (AED 1,903.81 + AED 95.19). Includes Ledger Plus software, up to 1,000 e-invoices, data migration, unlimited users, free onboarding.
- Payment: card through Geidea (hosted payment form); Geidea "Order status on success" = Completed, so the customer gets WooCommerce's "Completed order" email as soon as the payment is confirmed.
- Account setup is manual: the team creates the Ledger Plus login by hand after payment. No set-up times are confirmed.
- Thank-you page and order email "What happens next" (plugin, unchanged): 1) Our team contacts you to create your Ledger Plus login. 2) We move your data into Ledger Plus. 3) We book your free onboarding.
- Referral programme: buyer gets a personal referral code after payment; when another business buys with the code and pays, the referrer can receive AED 999 cash back; buyer's price stays AED 1,999; self-referral and same-group referrals are blocked; no reward if the referred order is refunded, cancelled or disputed. Payout method, timing, count limits and VAT treatment were NOT confirmed by the client.
- Who issues tax invoices was NOT confirmed (options: Ledger Plus software, Zoho Books, WooCommerce PDF).
- Shop sells to the UAE only (WooCommerce allowed countries).
- Contact: info@ledgerplus.ae, +971 50 342 0832 (call / WhatsApp).
- Compliance plans price is inconsistent on the site (plan said "from AED 5,999 a year", Packages page says AED 499 a month), so no price should be quoted.

## Site state found today (before changes)
- WooCommerce in Coming soon mode (store pages only). Purchase page 892 /e-invoicing-starter/ already published, with 10 visible "[... to confirm]" placeholders.
- Checkout page note: "Prices include 5% VAT. [Tax invoice by email: to confirm]".
- Terms of Sale page 895 draft with 19 bracketed placeholders; WooCommerce terms page not set.
- Refund and Returns Policy page 889 is WooCommerce's sample text, draft.
- Cash on delivery ENABLED; Stripe disabled; Geidea enabled.
- Coupon TEST90 (99.95% off) published; 6 referral coupons (amount 0) from test orders.
- 10 test orders (5 COD, 5 Geidea).
- Header (Elementor Pro template 32): logo + nav menu "Invoice Page Header" only. No cart or account icon. Footer quick links: Home, About Us, Blog, Contact Us, Privacy Policy.
- My Account page exists; registration on My Account off; guest checkout on; account creation at checkout off.
- Shop page 885 /shop/ is WooCommerce's shop archive (empty page); Elementor Pro archive template 570 applies to all archives; product page 890 redirects to the purchase page (plugin).
- Pricing page 129 Starter box button: "Enquire About Starter" -> contact form; launch plan: "Add to cart" -> /cart/?add-to-cart=890.

## Planned changes (being made now)
- Header: account icon (-> /my-account/) and Elementor Pro Menu Cart (side cart, count bubble, no subtotal) after the menu.
- Menu: "Shop" item -> purchase page 892, after Pricing.
- Footer: add "Terms of Sale".
- COD off; delete test orders and coupons; enable "create account during checkout" and "log in at checkout" reminder; set Terms page = 895.
- Purchase page placeholder replacements and checkout note: see edits-readable.md (stored Elementor JSON: ’ = right single quote, · = middle dot, \/ = /).
- Terms of Sale: terms-of-sale.html (block markup).
- Pricing button patch; then switch WooCommerce from Coming soon to Live.
