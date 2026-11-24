# Ledger Plus Shop (v1.0.0)

These are the WooCommerce add-ons for selling the **E-Invoicing Starter Package** (product 890, AED 1,999 including 5% VAT) on ledgerplus.ae.

You can install the same code two ways. Use only one:

| File | What it is |
|---|---|
| `ledgerplus-shop/ledgerplus-shop.php` | The WordPress plugin. It is a single file. Put it in a folder called `ledgerplus-shop`, or zip that folder and upload it. |
| `ledgerplus-shop-wpcode.php` | The WPCode snippet. It is the same code, minus the plugin header and the HPOS compatibility declaration (the declaration needs a plugin file). It has no opening `<?php` tag. |
| `test/` | Local test harness: pure PHP and Node, with stubs of the WordPress and WooCommerce functions. |

Requirements: WordPress 6.5+, PHP 8.1+ (the site runs 8.4), WooCommerce 9.0+ (the site runs 11.3.1), and a **classic theme** (Hello Elementor). The source references below are to WooCommerce **11.3.1**.

---

## 1. What it does

| # | Feature | Where you see it |
|---|---|---|
| F1 | Prices show **AED** instead of the Arabic dirham sign. | Everywhere WooCommerce prints the currency symbol, including the cart and checkout blocks (they read it through the Store API). |
| F2 | Two checkout fields: **Trade licence number** (required) and **VAT TRN (if VAT registered)** (optional). The trade licence is saved in upper case with single spaces and must be 3-40 characters of A-Z, 0-9, space, `-`, `/` or `.`. The TRN is saved as digits only and must be empty or exactly 15 digits. Input without any digit (for example "N/A") is saved as empty, which means "not VAT registered". | Block checkout (Contact information section), admin order screen (Billing column), order emails ("Additional information"), thank-you page, My Account → Account details. |
| F3a | When an order containing product 890 is **paid** (processing or completed), the buyer gets one personal referral code, `LP-XXXXXX`. It is an AED 0 WooCommerce coupon: fixed cart, no usage limit, product 890 only, not individual use. The code is saved on the order with an order note. An order never keeps a second code. | Marketing → Coupons; order notes. |
| F3b | The buyer's code is shown with this wording: "Your referral code: LP-XXXXXX. Share it with another business. When they buy Ledger Plus with your code, you get AED 999 cash back after their payment is confirmed. [Reward terms: to confirm]" | Thank-you page, the customer "Processing order" and "Completed order" emails (HTML and plain text), My Account dashboard. |
| F3c | When a paid order used a referral code, the plugin saves which code it was and which order it came from. The status is `pending_review`, or `ineligible_self` when the buyer's billing email or trade licence matches the referrer's. Both orders get a note. If that order is later **cancelled or refunded**, the status becomes `void_cancelled` or `void_refunded` and both orders get a note; a partial refund only adds notes. If the referrer's own order is cancelled or refunded, it gets a note listing the referred orders still pending review, and those orders get a note too. **Nothing is paid or promised automatically.** | Order notes and order meta. |
| F3d | A buyer cannot apply their **own** code: the plugin compares the referrer's email and trade licence with the shopper's (account email, billing email, trade licence). It also blocks a second referral code on the same order, and codes whose referrer order is no longer paid (refunded or cancelled). Common errors use referral wording: a mistyped code gives "Referral code "LP-XXXXXX" was not found. Please check it and try again." and a code used without product 890 gives "Referral code "LP-XXXXXX" only works with the E-Invoicing Starter Package." | Error under the referral code field. |
| F3e | **Possible existing customer:** when another paid order has the same billing email or trade licence, the order gets the note "Possible existing customer: order #X" and the meta `_lp_possible_repeat=yes`. | Order notes. |
| F3f | The **admin "New order" email** gets a short "Ledger Plus checks" block: trade licence, TRN, referral code used (with the referrer order number and status), the possible repeat flag, and the buyer's own new code. The same summary appears on the admin order screen under the billing address. WooCommerce sends "New order" for an **on-hold** order before it is paid, so when an on-hold order becomes processing or completed, the plugin sends the "New order" recipient one short "Payment confirmed: Ledger Plus checks" email with the final values (once per order, only while the "New order" email is enabled). | Admin emails, order screen. |
| F3g | The success message for a referral code becomes "Referral code accepted. Your price stays AED 1,999." | Classic notice, and the block checkout snackbar (via F4). |
| F4 | The coupon wording on the **block** cart and checkout changes to referral wording: "Add coupons" → "Have a referral code?" and "Enter code" → "Referral code". The "Apply" button keeps its label, as in the client plan. There is also a fallback for classic cart and checkout templates. | Cart page 886, checkout page 887. |
| F5 | Opening `/checkout/?add-to-cart=890` when product 890 is already in the cart shows **no** "You cannot add another…" error. The checkout simply opens. | Buy buttons on the sales page. |
| F6 | The product 890 page redirects (302) to sales page 892, but **only once page 892 is published**. | `/product/...` URL of product 890. |
| F7 | A "What happens next" block on the thank-you page, with three steps and the help line "Need help? Call or WhatsApp +971 50 342 0832.". It makes no time promises. What shows depends on the order status: **paid**: the steps and the buyer's code; **pending or on-hold**: the steps introduced by "Once your payment is confirmed:" and "Your referral code will appear here and in your confirmation email once your payment is confirmed."; **failed, cancelled or refunded**: only the help line. | Thank-you page. |

It does **not** do the following: Zoho CRM sync, reward payouts, or anything else that moves money (F10). There is a marked `TODO - Phase 2` section at the end of the class.

---

## 2. Settings (F8)

The defaults live at the top of the `LP_Shop` class. There are two ways to change them without editing the code.

**A. Constants in `wp-config.php`** (above "That's all, stop editing"):

| Constant | Default |
|---|---|
| `LP_SHOP_PRODUCT_ID` | `890` |
| `LP_SHOP_SALES_PAGE_ID` | `892` |
| `LP_SHOP_ADDON_PRODUCT_ID` | `891` |
| `LP_SHOP_PHONE_DISPLAY` | `+971 50 342 0832` |
| `LP_SHOP_PHONE_TEL` | `+971503420832` |
| `LP_SHOP_WHATSAPP_URL` | `https://wa.me/971503420832` |
| `LP_SHOP_REWARD_TEXT` | `you get AED 999 cash back after their payment is confirmed.` |
| `LP_SHOP_REWARD_TERMS` | `[Reward terms: to confirm]` |

**B. The `lp_shop_settings` filter** (for example in a small WPCode snippet):

```php
add_filter( 'lp_shop_settings', function ( $s ) {
    $s['reward_terms']          = 'One reward per referred company. Paid by bank transfer within 14 days.';
    $s['redirect_product_page'] = true;   // F6 on/off
    $s['relabel_coupons']       = true;   // F4 + referral wording of coupon errors on/off
    $s['require_paid_referrer'] = true;   // F3d: refuse codes whose referrer order is refunded/cancelled
    $s['admin_paid_notice']     = true;   // F3f: "Payment confirmed" admin email for on-hold orders
    $s['lock_wait_seconds']     = 5;      // F3a: how long a payment hook waits for another request (0-10)
    $s['buy_now_product_ids']   = array( 890, 891 ); // F5 applies to these (only if "Sold individually")
    return $s;
} );
```

The other keys are `product_id`, `sales_page_id`, `addon_product_id`, `phone_display`, `phone_tel`, `whatsapp_url`, `support_email` and `price_text` (`AED 1,999`). In reward texts, write apostrophes as ’ and not `'`: WooCommerce plain-text emails drop `&#039;`.

There are two extra filters:
- `lp_shop_block_coupon_labels`: the msgid → new text map for the block cart and checkout (F4).
- `lp_shop_settings`: described above.

---

## 3. Install

### Option 1: plugin (recommended)
1. Zip the folder `ledgerplus-shop/` (it contains `ledgerplus-shop.php`).
2. Go to **Plugins → Add New → Upload Plugin**, upload the zip and click **Activate**. The header has `Requires Plugins: woocommerce`, so WordPress only lets you activate it while WooCommerce is active.
3. The plugin declares itself compatible with HPOS and the cart and checkout blocks, so WooCommerce shows no compatibility warning.

### Option 2: WPCode snippet
1. Go to **Code Snippets → Add Snippet → Add Your Custom Code (New Snippet)** and choose Code Type **PHP Snippet**.
2. Paste the **whole** content of `ledgerplus-shop-wpcode.php` as it is. It deliberately has no `<?php` tag.
3. Set Insert Method to **Auto Insert** and Location to **Run Everywhere**, then save and **Activate**.
4. The snippet cannot declare HPOS compatibility. WooCommerce may list it as an "unknown" extension, which is harmless.

If both the plugin and the snippet are active, the second one to load does nothing (there is a `class_exists` guard) and nothing breaks. Still, deactivate the snippet once the plugin is installed.

---

## 4. Operator steps on the live site

Do these in this order. Steps 1-9 can be done while the store is still in "coming soon" mode.

1. **Install** the plugin (section 3, option 1). Then check **WooCommerce → Status → Logs**: there should be no `ledgerplus-shop` entries.
2. **WooCommerce → Settings → General → Currency position: "Left with space"**. This shows "AED 1,999.00" instead of "AED1,999.00".
3. **Checkout field options** (WooCommerce 11.3.1 reads these options, not block attributes; see `blocks/checkout-options-note.md`). Set both to the plain string `required`:
   - `woocommerce_checkout_company_field` = `required`
   - `woocommerce_checkout_phone_field` = `required`
4. **Product 890 → Inventory → "Sold individually"** must stay ticked (F5 relies on it). If product 891 is published later, tick it there too, or F5 does nothing for it.
5. **Checkout page 887** (block editor, or the code editor). Keep the `woocommerce/checkout` block and add:
   - **above** `<!-- wp:woocommerce/checkout ...-->`: a Custom HTML block with the content of `blocks/checkout-head.html` (heading "Complete your order", "It takes about 2 minutes.", the referral help text and the help line), written as `<!-- wp:html -->` + the file content + `<!-- /wp:html -->`;
   - **below** `<!-- /wp:woocommerce/checkout -->`: a Custom HTML block with the content of `blocks/checkout-foot.html` (the trust lines; keep "[payment gateway]" until the gateway is chosen);
   - **inside** the Checkout block: replace the `woocommerce/checkout-actions-block` comment with `<!-- wp:woocommerce/checkout-actions-block {"placeOrderButtonLabel":"Pay securely"} -->` `<div class="wp-block-woocommerce-checkout-actions-block"></div>` `<!-- /wp:woocommerce/checkout-actions-block -->`, keeping any attributes it already has (details in `blocks/checkout-actions-note.md`).
6. **Cart page 886**: a Custom HTML block with `blocks/cart-head.html` **above** the `woocommerce/cart` block.
7. **My Account page 888**: a Custom HTML block with `blocks/account-head.html` **above** the `[woocommerce_my_account]` shortcode block.
8. **Appearance → Customize → Additional CSS**: add `store.css` after any CSS already there (saving replaces the whole Additional CSS). It also styles the plugin's thank-you and My Account classes (store.css section 15). The Customizer checker (CSSLint 1.0.4) reports `:is()`, `:has()` and `@container` as errors although the CSS is valid; tick "Update anyway" to save, or save it with `wp_update_custom_css_post()` from WP-CLI.
9. **WooCommerce → Settings → Emails**: keep **New order** enabled with the right recipient (F3f and the "Payment confirmed" notice use it), and keep **Processing order** and **Completed order** enabled (they carry the buyer's code). Install an SMTP plugin before launch so these emails arrive.
10. **Payment gateway**: install the WooCommerce Stripe extension or WooPayments, test it in test mode, then replace "[payment gateway]" in the checkout foot block with its name, and check that the gateway does not replace the "Pay securely" label.
11. **Reward terms**: when agreed, set `LP_SHOP_REWARD_TERMS` in `wp-config.php` (or the `lp_shop_settings` filter) to replace "[Reward terms: to confirm]".
12. **Coupons**: keep coupons enabled (they are). Do not create other coupons: the cart and checkout call every coupon a "referral code". Do not edit the `LP-` coupons by hand.
13. **Publish page 892** when ready. The product page redirect starts by itself. A shop manager can still see the product page at `…/product/…/?lp_stay=1`.
14. Run the staging test plan (section 7) with a test gateway, then switch the store from "coming soon" to live (**WooCommerce → Settings → Site visibility → Live**).
15. Keep a **classic theme**. On a block theme, the order-received page uses the Order Confirmation template instead of `checkout/thankyou.php`, so the F3b and F7 thank-you blocks would not show.

---

## 5. How it works (checked against WooCommerce 11.3.1 source)

| Topic | What the plugin relies on | Source |
|---|---|---|
| F1 | `woocommerce_currency_symbol( $symbol, $currency )` | `includes/wc-core-functions.php:726` |
| F2 registration hook | `woocommerce_init` (fires on `init` priority 0). Registering earlier triggers "Additional checkout fields should be registered on the woocommerce_init action or later." `woocommerce_register_additional_checkout_field()` defers itself until `woocommerce_blocks_loaded`. | `includes/class-woocommerce.php:345,1042`; `src/Blocks/Domain/Services/CheckoutFields.php:211-212`; `src/Blocks/Domain/Services/functions.php:13-31` |
| F2 options | `id`, `label`, `optionalLabel` (default "%s (optional)"), `location`, `type`, `required`, `attributes` (only `maxLength`, `readOnly`, `pattern`, `autocomplete`, `autocapitalize`, `title`, `data-*`, `aria-*`), `sanitize_callback( $value, $field )`, `validate_callback( $value, $field )` → `WP_Error`/`true` | `CheckoutFields.php:224-236,534,600-609`; `CheckoutFieldTypes/AbstractFieldType.php:118-123` |
| F2 location: **contact** | Contact fields: admin Billing column, emails, order confirmation, saved to the customer. Order fields would land in the Shipping column, which reads "No shipping address set." for virtual Store API orders. | `CheckoutFieldsAdmin.php:33-35,123-151`; `includes/class-wc-emails.php:250,845-858`; `CheckoutFieldsFrontend.php:42,100-103,161-173`; `admin/meta-boxes/class-wc-meta-box-order-data.php:668-687` |
| F2 meta keys | Order (and customer): `_wc_other/ledgerplus/trade-licence`, `_wc_other/ledgerplus/trn` (prefix `CheckoutFields::OTHER_FIELDS_PREFIX`). Read with `CheckoutFields::get_field_from_object( $key, $wc_object, 'other' )` via `Automattic\WooCommerce\Blocks\Package::container()`, with a raw-meta fallback. | `CheckoutFields.php:88,1026-1038`; `CheckoutFieldsStorage.php:63-79,96`; `src/Blocks/Package.php:83` |
| F2 validation flow | The validate callback only ever receives the **sanitized** value, and only when it is not empty: the Store API runs our sanitize callback first, then `Checkout::validate_callback()` reports "%s is required" itself for an empty required field and skips empty optional ones. My Account does the same. So the plugin's own "required" message is a fallback for direct calls only, and a TRN typed without any digit is saved as empty. | `src/StoreApi/Schemas/V1/CheckoutSchema.php:200,410-428,444-446`; `src/StoreApi/Routes/V1/Checkout.php:322-341`; `CheckoutFieldsFrontend.php:286-291,328-344` |
| F3a timing | `woocommerce_order_status_{to}` fires **before** `woocommerce_order_status_{from}_to_{to}` (the processing emails), and `woocommerce_order_status_completed` is itself an email action at priority 10. The plugin runs at priority **5**, on the same order object the emails receive. Deferred emails re-read the order, and the plugin saves it. | `includes/class-wc-order.php:435-503`; `class-wc-emails.php:98-147,205-220`; `class-wc-email-customer-processing-order.php:43-46,88-104`; `class-wc-email-customer-completed-order.php:42`; `src/Internal/Email/DeferredEmailQueue.php:375-380` |
| F3a lock | One lock per order through the options API. The value is always `1`, so `add_option()`'s `INSERT … ON DUPLICATE KEY UPDATE` changes no row for a second request and returns false: the database decides. The time is kept in `lp_shop_lock_{id}_at`. A busy lock makes the payment hook wait (up to `lock_wait_seconds`, default 5) and then re-read the order's meta from the database (`read_meta_data( true )`), so its email still gets the code. After saving a new coupon the plugin keeps the oldest referral coupon of the order and trashes any other. | WordPress 7.1.5 `wp-includes/option.php:1116-1125,1142-1145,1248`; `includes/class-wc-order-factory.php:34-51`; `includes/abstracts/abstract-wc-data.php:698-730`; `includes/data-stores/class-wc-coupon-data-store-cpt.php:270-291` |
| F3a coupon | `WC_Coupon` setters (`set_code`, `set_status`, `set_discount_type('fixed_cart')`, `set_amount(0)`, `set_individual_use`, `set_product_ids`, `set_usage_limit`, `set_usage_limit_per_user`, `set_description`). `wc_get_coupon_id_by_code()` is used for the uniqueness check. | `includes/class-wc-coupon.php:555-739`; `includes/wc-coupon-functions.php:110` |
| F3b thank-you | On a classic theme, the checkout block renders `[woocommerce_checkout]` on the order-received endpoint → `checkout/thankyou.php` → `woocommerce_thankyou` (priority 5, before the order details table at 10). The hook fires for every status, so the plugin picks the content by status. A missing code is only created there when the order was paid at least 60 seconds ago (`get_date_paid()`), so the page never races the payment hook. | `src/Blocks/BlockTypes/Checkout.php:231-236`; `includes/shortcodes/class-wc-shortcode-checkout.php:307-388`; `templates/checkout/thankyou.php:31,97`; `includes/wc-template-hooks.php:276`; `includes/class-wc-order.php:334,355-376,960` |
| F3b/F3f emails | `woocommerce_email_order_meta( $order, $sent_to_admin, $plain_text, $email )`, with email ids `customer_processing_order`, `customer_completed_order` and `new_order`. It also fires in the block email template. | `templates/emails/customer-processing-order.php:64` (+plain:51, completed:61/48, admin-new-order:54/49); `templates/emails/block/general-block-email.php:148` |
| F3f follow-up | "New order" fires on pending/failed/cancelled → on-hold, but never on on-hold → processing. The plugin listens on `woocommerce_order_status_changed( $id, $from, $to, $order )` and sends through `WC()->mailer()`: `get_emails()['WC_Email_New_Order']`, `is_enabled()`, `get_recipient()`, `wrap_message()`, `send()`. | `includes/emails/class-wc-email-new-order.php:43-51`; `includes/class-wc-order.php:480`; `includes/class-wc-emails.php:287,347,454,493`; `includes/emails/class-wc-email.php:635,868` |
| F3c cancel/refund | `woocommerce_order_status_cancelled` / `_refunded` ( `$order_id`, `$order` ), and `woocommerce_order_fully_refunded` / `woocommerce_order_partially_refunded` ( `$order_id`, `$refund_id` ) from `wc_create_refund()`. The plugin works on a copy read from the database and is idempotent, so the two refund hooks give one note. | `includes/class-wc-order.php:459`; `includes/wc-order-functions.php:735,737,748` |
| F3b My Account | `woocommerce_account_dashboard` | `templates/myaccount/dashboard.php:65` |
| F3d | `woocommerce_coupon_is_valid( $valid, $coupon, $discounts )` returns false. The generic "Coupon is not valid." (code 100) is then swapped for the real reason via `woocommerce_coupon_error`. **Block checkout re-validation:** on "Place order", the Store API first saves the posted billing email and contact fields to `WC()->customer`, then re-validates every applied coupon and removes invalid ones with an error. So the email and trade licence check runs on the final data. | `includes/class-wc-discounts.php:1265-1311,1189-1196`; `src/StoreApi/Routes/V1/Checkout.php:600,613,952-1021`; `src/StoreApi/Utilities/CartController.php:480-482,762-788,1073-1088`; `class-wc-coupon.php:993-1003` |
| F3d error wording | A mistyped code reaches `WC_Discounts::validate_coupon_exists()` (code 105) because `WC_Coupon` keeps the typed code; a code without product 890 gives code 109. Both go through `woocommerce_coupon_error`, which the plugin rewrites on the front end only (`! is_admin()`), with the code in upper case (WooCommerce stores codes in lower case). | `includes/class-wc-coupon.php:132-139,1216-1221`; `CartController.php:955-985`; `class-wc-discounts.php:624-635,848-856,1291`; `includes/wc-core-functions.php:46` |
| F3e query | `wc_get_orders()` with `billing_email`, `status`, `exclude`, `meta_query` (HPOS supports all of them). Posts storage ignores `meta_query`, so matches are always re-checked in PHP. | `src/Internal/DataStores/Orders/OrdersTableQuery.php:275,314,706,810,1154,1178`; `includes/data-stores/class-wc-data-store-wp.php:285` |
| F3g | `woocommerce_coupon_message( $msg, $msg_code, $coupon )`, code 200. The Store API's `apply_coupon()` adds no notice: the block shows its own snackbar text, which F4 relabels. | `class-wc-coupon.php:1097-1109`; `CartController.php:951-1061`; `client/blocks/assets/js/base/context/hooks/cart/use-store-cart-coupons.ts:69-71` |
| F4 mechanism | `@wordpress/i18n`'s `__()` runs `i18n.gettext` and then `i18n.gettext_{domain}` on the default `wp.hooks` instance. The block bundles import `@wordpress/i18n` as the external `wp.i18n`, which depends on `wp-hooks`. The plugin adds an inline script **after `wp-hooks`**, so it runs before any block script renders. Cart and checkout pages only. | gutenberg `packages/i18n/src/create-i18n.ts:169-195`, `default-i18n.ts:10`, `packages/hooks/src/index.ts:5`; `client/blocks/bin/webpack-configs.js:75-80` |
| F4 msgids (domain `woocommerce`) | "Add coupons", "Applying coupon…", "Enter code", "Apply" (kept); "Removing coupon…", "Coupon: %s", "Remove coupon \"%s\"", "Discount" (**not** changed), "Coupons"; "Coupon code \"%s\" has been applied to your cart.", "Coupon code \"%s\" has been removed from your cart." | `client/blocks/assets/js/base/components/cart-checkout/totals/coupon/index.tsx:90,94,107,131`; `.../totals/discount/index.tsx:79-81,95,105-107,120-121`; `use-store-cart-coupons.ts:69-71,113-115` |
| F4 classic | The `gettext_woocommerce` filter changes "Have a coupon?", "Coupon code", "Apply coupon", "Coupon:", "Coupon: %s" and "Enter your coupon code", on front-end cart and checkout pages only. Store API PHP messages are also changed, only when `WC()->is_store_api_request()`: `"%s" is an invalid coupon code.`, `Coupon code "%s" has already been applied.`, `The "%1$s" coupon has been removed from your cart: %2$s`. | `templates/cart/cart.php:202`; `templates/checkout/form-coupon.php:35,42-47`; `includes/wc-cart-functions.php:307`; `CartController.php:961,973,1082`; `includes/class-woocommerce.php:682` |
| F5 | `woocommerce_add_to_cart_validation` returns false only inside `WC_Form_Handler::add_to_cart_action` (on `wp_loaded`), for a sold-individually product already in the cart. That skips `WC()->cart->add_to_cart()`, where the "You cannot add another" exception is thrown. It adds no notice and does no redirect. The Store API path is left alone, because there false would become an error. | `includes/class-wc-form-handler.php:61,1026-1101`; `includes/class-wc-cart.php:1356-1380`; `CartController.php:337-353` |
| F9 HPOS | `FeaturesUtil::declare_compatibility( 'custom_order_tables' \| 'cart_checkout_blocks', __FILE__, true )` inside `before_woocommerce_init` (plugin variant only) | `src/Utilities/FeaturesUtil.php:87`; `src/Internal/Features/FeaturesController.php:361,1019`; `CustomOrdersTableController.php:624` |

**Why "Discount" is not relabelled:** the block shows "Discount" only when the discount amount is not zero. A referral code is always AED 0, so in that case the block shows "Coupons", which the plugin changes to "Referral code" (`discount/index.tsx:118-121`). Relabelling "Discount" would mislabel a real discount.

### CSS hooks (styles are supplied separately)
The plugin prints plain, semantic markup with these classes and adds no front-end CSS of its own. `store.css` section 15 styles them (brand card for the steps, tinted box for the referral code).

```
.lp-shop.lp-shop-thankyou                 wrapper on the thank-you page, plus one of:
  .lp-shop-thankyou--paid                 paid order
  .lp-shop-thankyou--awaiting             pending or on-hold order
  .lp-shop-thankyou--closed               failed, cancelled or refunded order (help line only)
  section.lp-shop-next                    "What happens next"
    h2.lp-shop-next__title
    p.lp-shop-next__intro                 "Once your payment is confirmed:" (awaiting only)
    ol.lp-shop-next__steps > li.lp-shop-next__step > span.lp-shop-next__num + span.lp-shop-next__text
    p.lp-shop-help > a.lp-shop-help__link (Call, WhatsApp) + a.lp-shop-help__phone
  section.lp-shop-referral                referral code box
    p.lp-shop-referral__text[data-lp-code] > strong.lp-shop-referral__code
  p.lp-shop-referral.lp-shop-referral--pending   code not shown yet (awaiting payment, or being prepared)
.lp-shop.lp-shop-account > section.lp-shop-referral.lp-shop-referral--account   My Account dashboard
.lp-shop-admin-box                        admin order screen (under billing address)
```
The emails use inline styles (brand colours #dde5f4, #eaf1fd, #f6f8fd, #030922, #041950), because email clients ignore stylesheets.

---

## 6. Data the plugin stores

| Where | Key | Value |
|---|---|---|
| Order | `_lp_referral_code` / `_lp_referral_coupon_id` | The buyer's own code and its coupon ID |
| Order | `_lp_referral_code_inactive` | `cancelled` or `refunded` while the order's own code is off because the order is no longer paid; removed when it is paid again |
| Order | `_lp_referred_by_code` / `_lp_referrer_order_id` | The referral code used and the referrer's order ID |
| Order | `_lp_referral_status` | `pending_review`, `ineligible_self`, `void_cancelled` or `void_refunded` |
| Order | `_lp_possible_repeat` / `_lp_possible_repeat_orders` / `_lp_repeat_checked` | `yes` / comma-separated order IDs / `yes` |
| Order | `_lp_trade_licence_norm` | Trade licence with only A-Z and 0-9 (used to find repeats) |
| Order | `_lp_admin_paid_notice_sent` | `yes` once the "Payment confirmed" admin email was sent |
| Coupon | `_lp_referral`, `_lp_referrer_order_id`, `_lp_referrer_email` (lower case), `_lp_referrer_trade_licence` | |
| Option (temporary) | `lp_shop_lock_{order_id}` (value `1`) and `lp_shop_lock_{order_id}_at` (time) | Lock while an order is processed. Deleted afterwards; a lock older than 120 s is cleared. |

**Which referrals can lead to a reward:** only an order whose `_lp_referral_status` is `pending_review` **and** whose order status is still processing or completed, after a person has reviewed it. `ineligible_self`, `void_cancelled` and `void_refunded` never do. A partly refunded order stays `pending_review` with a note, so a person decides.

Everything is written through `WC_Order` and `WC_Coupon` CRUD and the options API. There are no SQL queries. Referral coupons are `shop_coupon` posts, which HPOS does not affect, so finding a coupon by its order uses `get_posts()` with a meta query.

---

## 7. How to test

### Local harness (no WordPress needed)
```bash
cd test
bash run-all.sh
```
This runs the following (331 checks, all passing on PHP 8.3.6 CLI):
- `php -l` on both files. The snippet is linted a second time with `<?php` added, because that is what `eval()` sees.
- `run-tests.php`: 247 checks against stubs. They cover code generation (alphabet, length, spread, collisions, the uniqueness retry loop), trade licence and TRN sanitize/validate (including what the Store API really passes), self-referral matching, the buy-now logic (pure function and real filter inside `wp_loaded`), the simulated paid-order flow (one code per order, stale objects, lost meta, busy, stale and timestamp-less locks, waiting for a busy lock, referral status, notes on both orders, repeat by email and by trade licence), coupon validity and error messages (including the referral wording for codes 105 and 109), the success message, the thank-you page for every status, the email and My Account output, cancelled, refunded, partly refunded and re-paid orders on both sides of a referral, the "Payment confirmed" admin email, duplicate coupon reconciliation, the classic and Store API wording, the product redirect rules (including `?lp_stay[]=1`), the settings filter, and zero PHP notices or deprecations.
- `test-inline.js` (Node): runs the real inline script against a fake `wp.hooks` and checks every label.
- `run-variants.php`: the snippet evaluated twice, plugin + snippet in both orders, WooCommerce missing, WooCommerce 8.9, no `ABSPATH`, and the Blocks field API path.
- `run-race.php`: two simulated requests on the same paid order, interleaved with PHP Fibers over a model of WordPress 7.1.5's option functions (`stubs-options-wp715.php`): both past the existence check in the same second and in different seconds, a stale lock cleared by both, the worst case where both end up holding the lock, and webhook versus thank-you page. Each must end with one live coupon, the same code in every email and page, and no lock left behind.

### On a staging copy (recommended before launch)
1. Install the plugin. Check that **WooCommerce → Status → Logs** has no `ledgerplus-shop` entries.
2. Open `/checkout/?add-to-cart=890` twice. You should see no error and exactly one item.
3. Checkout: `ab` as trade licence shows an error. `cn-1234  567` is saved as `CN-1234 567`. TRN `123` shows an error. `100-123-456-789-003` is saved as `100123456789003`. `N/A` is saved as empty.
4. Place an order with a test gateway that confirms the payment straight away (Stripe or WooPayments in test mode: the order goes from pending to processing). Check:
   - The thank-you page shows "What happens next" and the code.
   - The customer "Processing order" email shows the code.
   - The admin "New order" email has the "Ledger Plus checks" block with the buyer's new code.
   - Marketing → Coupons has the new `LP-` code, and the order notes mention it.
5. Bank transfer (on-hold) path: place an order with **Direct bank transfer**. The thank-you page shows the steps under "Once your payment is confirmed:" and no code. The admin "New order" email is sent now, before payment, so it shows "Created when payment is confirmed". Then set the order to **Processing**: the customer email has the code, the admin gets one "Payment confirmed for order #N (Ledger Plus checks)" email with the code, and reloading the thank-you page shows the code.
6. Use the code from step 4 on a second order with a **different** email and trade licence. Both orders get notes, and the status is `pending_review`. Cancel that second order: its status becomes `void_cancelled` and both orders get a note.
7. Try the code with the **same** email. The field shows "This is your own referral code…". Then enter the referrer's email only at checkout: the code is removed when you click "Place order". Type a wrong code such as `LP-ZZZZ22`: the message says the referral code was not found.
8. My Account dashboard (logged in as the buyer) shows the code.
9. Publish page 892. The product page now redirects, and `?lp_stay=1` (as a shop manager) does not.

---

## 8. Limits and things to know

- The repeat customer check matches the trade licence on HPOS (the site has HPOS on). On posts storage, it only checks the 20 most recent paid orders.
- The per-order lock is decided by the database (constant lock value), so two requests cannot both take a free lock. One case remains: a lock left by a request that died is cleared after 120 s, and if two requests clear it at the very same moment, both can end up working. For that case the plugin keeps the oldest referral coupon of the order, trashes the other, notes it, and both requests' emails show the kept code (tested in `run-race.php stale-worst`). Order notes can then appear twice.
- A payment hook waits up to 5 s (`lock_wait_seconds`) when another request is working on the same order. If it still cannot get the lock, it logs a warning and its email goes out without the code; the thank-you page and My Account still show it.
- The block wording (F4) and the referral error wording apply to **all** coupon codes, not only referral codes. This is fine because the shop uses coupons only for referrals. "Coupons" → "Referral code" was checked in the totals discount component only. Remove it through `lp_shop_block_coupon_labels` if the label ever shows up somewhere unexpected.
- WooCommerce stores coupon codes in lower case, so the applied-code chip in the cart and checkout shows `lp-xxxxxx`. The plugin's own messages, emails and pages show `LP-XXXXXX`. A CSS `text-transform: uppercase` on the chip would make them match.
- A TRN typed without any digit ("N/A", "pending") is saved as empty without an error message, because WooCommerce does not call the validator for an empty optional value.
- The trade licence is a required **contact** field, so logged-in customers must also fill it in under My Account → Account details (WooCommerce adds contact fields there).
- If a payment is confirmed only after the buyer has seen the thank-you page (for example a late webhook), the page says the code will follow. The code is created when the order becomes Processing and is in the Processing email.
- Local tests use PHP **8.3** CLI and stubs. They do not replace a staging test on WordPress 7.1.5, PHP 8.4 and WooCommerce 11.3.1. The code has no dynamic properties and no implicitly nullable typed parameters.
- When exactly WPCode runs "Run Everywhere" snippets was not checked. The code works whether it loads before or after `plugins_loaded` and `woocommerce_init`. It must load before `wp_loaded` for F5.
- Deactivating or deleting the plugin leaves the coupons and order meta in place, on purpose.

## 9. Phase 2 (not built)

There is a marked `TODO - Phase 2` comment at the end of the class. It plans a Zoho CRM Deal sync on paid orders: an Action Scheduler job, idempotent through `_lp_zoho_deal_id`, with credentials kept server-side and never in this code. The Deal must carry the referral status and be updated again when an order is cancelled or refunded, so a void referral never shows as payable. Referral rewards stay a manual step for the sales team, and only for `pending_review` orders that are still processing or completed.
