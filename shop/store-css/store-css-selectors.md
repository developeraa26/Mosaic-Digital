# store.css selector register (WooCommerce 11.3.1, Hello Elementor 3.5.1)

Every class that `store.css` targets, with the file and line where it is confirmed.

- WooCommerce paths are relative to `plugins/woocommerce/` at tag `11.3.1`, fetched from `https://raw.githubusercontent.com/woocommerce/woocommerce/11.3.1/plugins/woocommerce/<path>` and cached in `(local working copy)`.
- `C/` = `client/blocks/`, `PK/` = `client/blocks/packages/public-api/blocks-components/`, `BC/` = `client/blocks/assets/js/base/components/`, `CO/` = `client/blocks/assets/js/blocks/checkout/inner-blocks/`, `CA/` = `client/blocks/assets/js/blocks/cart/inner-blocks/`.
- Hello Elementor paths are relative to `https://raw.githubusercontent.com/elementor/hello-theme/v3.5.1/` (cached in `wcsrc/hello/`).

I removed every selector I could not confirm. The express payment area is not styled. The "+ Add apartment, suite, etc." toggle is not styled either: it is an Ariakit `Button` rendered as `<span>` (`BC/cart-checkout/form/address-line-2-field.tsx:67-80`), so it inherits the text colour. The general focus ring in store.css section 3 covers it through `[role="button"]` / `[tabindex]`, which Ariakit adds to a non-native button (Ariakit behaviour, assumed, not in WooCommerce source). The mock now uses that `<span>`, not a `<button>`.

## How the rules win

- WooCommerce block CSS is enqueued during block render, so on a classic theme it can print **after** the Customizer CSS. Every block rule therefore starts with `body.<store class>` plus the block wrapper class (`.wp-block-woocommerce-checkout` or `.wp-block-woocommerce-cart`). That gives it more weight than the WooCommerce rule it replaces, whatever the print order. I checked each case against the compiled 11.3.1 SCSS (`(local working copy)`, built from the downloaded SCSS with Dart Sass and the WooCommerce prelude from `C/bin/webpack-configs.js:707-738`).
- Container queries (`@container (min-width: 920px)` and `(max-width: 919px)`) mirror WooCommerce own breakpoints `cart-checkout-large-container` and `cart-checkout-below-large-container` (`C/assets/css/abstracts/_mixins.scss:316-326`). The query container is the block wrapper (`container-type: inline-size`, `C/assets/js/blocks/checkout/styles/style.scss:1-5`, `C/assets/js/blocks/cart/style.scss:1-5`).
- No width, flex, grid or position property of a WooCommerce layout container is changed. One margin is reset: `margin-inline: 0` on the `.alignwide` Cart and Checkout wrappers. Hello sets `.alignwide { margin-inline: -80px }`, but WooCommerce fixes both wrappers at `width: 100%` (`C/assets/js/blocks/checkout/styles/style.scss:1-6`, `C/assets/js/blocks/cart/style.scss:1-5`), so the negative margin only shifts them 80px to the left (off the content edge on desktop, partly clipped off screen on phones, with no sideways scroll). The reset restores WooCommerce's own position (the checkout already sets `margin: 0`, `checkout/styles/style.scss:2`); width and the 920px column switch do not change. Inside the cart totals card and the checkout summary card the only spacing added is padding.
- Classic list layout changed in one place only: on phones (below 576px) the order-received overview items are unfloated and stacked (section D). The `.lp-shop-next__step` flex row is our own plugin markup, not WooCommerce.
- **Customizer checker.** WordPress 7.1.5 lints Additional CSS with CSSLint 1.0.4 with parsing errors on (`wp-includes/general-template.php:4382-4389`). That parser accepts only identifiers, numbers and strings inside a functional pseudo-class (`csslint.js:2364-2386`), so `:is(.class, ...)`, `:has(...)` and `:not(.a, .b)` raise errors, and `@container` raises "Unknown @ rule" (`csslint.js:902`). The Customizer then shows "There are N errors which must be fixed before you can save." with an "Update anyway, even though it might break your site?" box (`script-loader.php:1346-1350`, `class-wp-customize-manager.php:4357-4366`). The CSS is valid in all current browsers (Chromium CSSOM: 0 invalid selectors, 0 dropped declarations). store.css carries an editor note about this at the top. Installing with `wp_update_custom_css_post()` from WP-CLI bypasses the checker; it only matters when someone later edits Additional CSS in the Customizer with syntax highlighting on. Rewriting every `:is()` as expanded lists was rejected: the `@container` blocks would still be flagged, so the "Update anyway" box would still appear, and the file would roughly triple in size.

## A. Body classes (scope)

| Class | Source |
|---|---|
| `woocommerce-checkout`, `woocommerce-cart`, `woocommerce-account` (each with `woocommerce-page`) | `includes/wc-template-functions.php:343-357` (`wc_body_class`), hooked at `includes/wc-template-hooks.php:13` |
| `woocommerce-order-received`, `woocommerce-order-pay` (`woocommerce-` + endpoint key) | `includes/wc-template-functions.php:369-373`; keys `order-pay` and `order-received` at `includes/class-wc-query.php:94-95` |
| `woocommerce` (body class on product pages) | `includes/wc-template-functions.php:338-341` |
| `single-product` | WordPress core `body_class()` (`single-{post_type}`), not WooCommerce. Assumed from core behaviour, not fetched |
| `woocommerce-block-theme-has-button-styles` (not used; explains why WooCommerce `body:not(...)` button rules apply on Hello) | `includes/wc-template-functions.php:381-384`, `includes/wc-conditional-functions.php:642-661` (false for classic themes) |

## B. Theme markup (Hello Elementor 3.5.1)

| Selector | Source |
|---|---|
| `.page-header`, `.page-header .entry-title` (title hidden on cart, checkout and the account dashboard; shown and styled on order received and on the My Account sections) | `template-parts/single.php:17-21` (filter `hello_elementor_page_title`; `the_title()` runs inside the loop, line 11-12, so WooCommerce's endpoint title filter applies) |
| `.page-header .entry-title` gets its own 10px side padding below 576px (`padding-inline: 0` in store.css) | `dev/scss/theme/_layout.scss:7` (`$containers` includes `.page-header .entry-title`), `:36-41` |
| Heading weight 500 from the reset (store.css sets 700 on h1-h4 in `.site-main`) | `dev/scss/reset/_reset.scss:46-53` |
| Table reset: 1px border on all four sides of every cell, grey odd-row and hover backgrounds (store.css keeps only row rules and clears the backgrounds) | `dev/scss/reset/_table.scss:20-26`, `:55-62` |
| Button `transition: all .3s` (why focus rings fade in on classic `button` elements) | `dev/scss/reset/_forms.scss:102` |
| `.site-main` (page wrapper) | `template-parts/single.php:15` (`<main id="content" <?php post_class( "site-main" ); ?>>`). Product pages use WooCommerce default wrapper `<main id="main" class="site-main">`, `templates/global/wrapper-start.php:47` |
| `.alignwide` (Hello sets `margin-inline: -80px`) | `dev/scss/theme/_general.scss` (`.alignwide { margin-inline: -80px; }`); the default Cart and Checkout block markup has `alignwide`: `includes/class-wc-install.php:3205`, `:3296` |
| Hello `.site-main` max widths (500/600/800/1140px) and 10px gutter below 576px, which decide when the checkout stacks | `dev/scss/theme/_layout.scss` |

## C. Block Cart and block Checkout (all under `client/blocks/`)

| Selector used in store.css | Source (file:line) |
|---|---|
| `.wp-block-woocommerce-checkout` (wrapper, query container) | `includes/class-wc-install.php:3296`; `C/assets/js/blocks/checkout/styles/style.scss:1-5`; frontend selector `C/assets/js/blocks/checkout/frontend.tsx` (`.wp-block-woocommerce-checkout[data-block-name="woocommerce/checkout"]`) |
| `.wp-block-woocommerce-cart` (wrapper, query container) | `includes/class-wc-install.php:3205`; `C/assets/js/blocks/cart/style.scss:1-5` |
| `.wp-block-woocommerce-checkout.alignwide`, `.wp-block-woocommerce-cart.alignwide` (`margin-inline: 0`) | as above; WooCommerce itself sets `.wp-block-woocommerce-checkout { margin: 0 }` (`C/assets/js/blocks/checkout/styles/style.scss:2`) |
| `--wc-separator-color` (custom property set on the wrapper) | defined `C/assets/css/style.scss:1-3`; read by `$universal-border-light` in `C/assets/css/abstracts/_colors.scss` |
| `.wc-block-components-title.wc-block-components-checkout-step__title` | `PK/title/index.tsx:25`; `PK/form-step/index.tsx:19-21`; `C/assets/js/blocks/checkout/form-step/form-step-heading.tsx:16-18` |
| `.wc-block-components-checkout-step__description` | `PK/form-step/index.tsx:84` |
| `.wc-block-cart__totals-title` | `CA/cart-order-summary-heading/block.tsx:14` |
| `.wc-block-cart-items`, `.wc-block-cart-items__header` | `BC/cart-checkout/cart-line-items-table/index.tsx:86`, `:94` |
| `.wc-block-components-text-input`, `.is-active`, `label` inside it | `PK/text-input/text-input.tsx:108-110` (wrapper), `:97` (`wrapperElement="label"`) |
| `.wc-block-components-text-input.has-error` | `PK/text-input/validated-text-input.tsx:278-279` |
| `input[type="text|email|tel|number|password|url|date"]` inside the text input | `PK/text-input/style.scss` (input type list, lines 37-43) |
| `.wc-blocks-components-select`, `__container`, `__label`, `__select`; `.has-error` on its wrapper | `BC/select/index.tsx:133-134` (`has-error`), `:137`, `:138`, `:141`, `:146` |
| `textarea.wc-block-components-textarea` | `PK/textarea/index.tsx:26-27` |
| `.wc-block-components-validation-error` | `PK/validation-input-error/index.tsx:45` |
| `.wc-block-components-checkbox`, `__input[type="checkbox"]`, `__mark` | `PK/checkbox-control/index.tsx:63`, `:74`, `:86` |
| `.wc-block-components-checkbox.has-error` | `PK/checkbox-control/style.scss:82` |
| `.wc-block-components-radio-control`, `--highlight-checked` (and its `::after` frame) | `PK/radio-control/index.tsx:39-46`; `PK/radio-control-accordion/index.tsx:51-53`; frame in `PK/radio-control/style.scss:52-65` |
| `.wc-block-components-radio-control-accordion-option--checked-option-highlighted` (the payment method option; the accordion sets `highlightChecked`) | `PK/radio-control-accordion/index.tsx:70-76`; payment methods use the accordion with `highlightChecked={ true }`: `C/assets/js/blocks/cart-checkout-shared/payment-methods/payment-method-options.js:87-88` |
| `label.wc-block-components-radio-control__option--checked-option-highlighted` (plain RadioControl with `highlightChecked`, for example shipping rates). Inside the payment accordion the label never gets this class: the accordion renders `RadioControlOption` without `highlightChecked` (`PK/radio-control-accordion/index.tsx:81-91`), whose default is `false` (`PK/radio-control/option.tsx:18`, `:33-38`). The former rule that targeted the label inside an accordion option was removed as dead code. | `PK/radio-control/option.tsx:33-38` |
| `.wc-block-components-radio-control__input` | `PK/radio-control/option.tsx:43` |
| `.wc-block-components-radio-control__label` | `PK/radio-control/option-layout.tsx:25` |
| `.wc-block-components-order-summary` | `BC/cart-checkout/order-summary/index.tsx:41` |
| `.wc-block-components-order-summary-item__quantity` | `BC/cart-checkout/order-summary/order-summary-item.tsx:209` |
| `.wc-block-components-product-name` | `BC/product-name/index.tsx:54` |
| `.wc-block-components-product-metadata__description` | `BC/cart-checkout/product-metadata/index.tsx:29` |
| `.wc-block-components-totals-wrapper` | `PK/totals-wrapper/index.tsx:27` |
| `.wc-block-components-totals-item`, `__label`, `__value`, `__description` | `PK/totals/item/index.tsx:59`, `:60`, `:35` and `:43`, `:81` |
| `.wc-block-components-totals-footer-item` | `BC/cart-checkout/totals/footer-item/index.tsx:157-158` |
| `.wc-block-components-totals-footer-item-tax` | `BC/cart-checkout/totals/footer-item/index.tsx:167` |
| `.wc-block-components-totals-coupon` | `BC/cart-checkout/totals/coupon/index.tsx:86` |
| `.wc-block-components-panel__button` (coupon toggle, a `div` with role button) | `PK/panel/index.tsx:60-64` |
| `.wc-block-components-totals-coupon .wc-block-components-panel__button::before` (invisible 12px hit area above and below the 20px toggle; the toggle is `position: relative` and WooCommerce uses no `::before` on it) | `PK/panel/style.scss:14-25`; toggle `padding-top: 0` in `BC/cart-checkout/totals/coupon/style.scss:1-5`; open toggle has a 16px bottom margin (`PK/panel/style.scss:26-28`) and the coupon input wrapper is `position: relative`, so the layer never covers the input (checked with `elementFromPoint` in the mock) |
| `.wc-block-components-button` | `BC/button/index.tsx:57-62` (`wc-block-components-button`, `wp-element-button`, variant) |
| `.wc-block-components-totals-coupon__button` | `BC/cart-checkout/totals/coupon/index.tsx:119-121` |
| `.wc-block-components-checkout-place-order-button` | `BC/cart-checkout/place-order-button/index.tsx:78-80` |
| `.wc-block-cart__submit-button` (Proceed to checkout, rendered as `a`) | `CA/proceed-to-checkout-block/block.tsx:86-88` |
| `.wp-block-woocommerce-checkout-order-summary-block` (the card) | `includes/class-wc-install.php:3348`; class passed through as `className` (`C/assets/js/utils/render-parent-block.tsx:167-170`) and used in `CO/checkout-order-summary-block/frontend.tsx:149` and `:189-194` |
| `.checkout-order-summary-block-fill` (summary box next to the Pay button on one column) | `CO/checkout-order-summary-block/frontend.tsx:204` |
| `.wc-block-components-checkout-order-summary__title-text`, `__title-price` | `CO/checkout-order-summary-block/frontend.tsx:161`, `:170` |
| `.wc-block-checkout__sidebar` | `CO/checkout-totals-block/frontend.tsx:25` |
| `.wc-block-checkout__terms` | `CO/checkout-terms-block/frontend.tsx:118-119` |
| `.wc-block-cart` | `CA/filled-cart-block/frontend.tsx:26` |
| `.wc-block-cart__sidebar` (the cart totals card) | `CA/cart-totals-block/frontend.tsx:20` |
| `.wp-block-woocommerce-cart-order-summary-block > .wc-block-components-totals-wrapper:first-of-type` | `includes/class-wc-install.php:3231` (block markup: heading `h2`, then the coupon wrapper `div`); `CA/cart-order-summary-block/frontend.tsx:24`; `CA/cart-order-summary-coupon-form/block.tsx:19` (TotalsWrapper) |
| `.wp-block-woocommerce-cart:not(.is-loading) .wp-block-woocommerce-empty-cart-block` inside `.site-main:has(...)` (hides the price line of our cart heading when the cart is empty) | empty cart markup `includes/class-wc-install.php:3275`; rendered only when the cart is empty: `CA/empty-cart-block/frontend.tsx:31-34`; `is-loading` is in the saved markup (`includes/class-wc-install.php:3205`) and removed after render (`C/assets/js/base/utils/render-frontend.tsx:67-68`). Checked in Chromium: loading and filled keep the line, empty hides it |
| `.wp-block-woocommerce-empty-cart-block` (empty cart wrapper) | `includes/class-wc-install.php:3274-3284` (default Cart page content, contains `wp:woocommerce/product-new`); rendered with its `className` only when the cart is empty: `CA/empty-cart-block/frontend.tsx:31-34` |
| `.wc-block-grid__product-add-to-cart .wp-block-button__link` (the "New in store" Add to cart link) | `src/Blocks/BlockTypes/AbstractProductGrid.php:645` (`div.wp-block-button.wc-block-grid__product-add-to-cart`), `:662` (`a.wp-block-button__link` + element class + `add_to_cart_button`). The element class is empty on a classic theme (`includes/wc-conditional-functions.php:622-628`). WordPress core global styles colour `.wp-block-button__link` white on `#32373c` (`wp-includes/theme.json:407-412`, applied because Hello ships a `theme.json`); the old `.site-main a` colour rule turned its text blue (2.03:1). Fixed by excluding button classes from the link rule and giving this link brand button colours |
| `a.wc-block-grid__product-link` (product name link in that grid) | `src/Blocks/BlockTypes/AbstractProductGrid.php:518-523` |
| `.wc-block-components-notice-banner`, `.is-error`, `.is-info`, `.is-success`, `> svg` | `BC/notice-banner/index.tsx:63-72`; classic PHP templates for block themes use the same classes (`templates/block-notices/error.php:30`, `notice.php:29`, `success.php:29`) |

## D. Classic templates (My Account, product, order received, notices)

| Selector | Source |
|---|---|
| `.woocommerce` (shortcode wrapper div) | `includes/class-wc-shortcodes.php:66`, `:74` |
| `.woocommerce-message`, `.woocommerce-info`, `.woocommerce-error` (and `::before` icon colour) | `templates/notices/success.php:29`, `notice.php:29`, `error.php:28`; icon rules in `client/legacy/css/woocommerce.scss` |
| `a.button`, `button.button`, `input.button`, `.button.alt`, `.disabled` | `client/legacy/css/woocommerce.scss` (compiled: `.woocommerce:where(body:not(.woocommerce-block-theme-has-button-styles)) a.button.alt` etc.) |
| `--wc-form-border-color`, `--wc-form-border-radius`, `--wc-form-color-text` | `client/legacy/css/_variables.scss:50-71` (`:root` block; `--wc-form-border-color` at `:64`; `--wc-form-color-text` is not defined there, WooCommerce reads it with a fallback, `var(--wc-form-color-text, #000)`) and used by `.woocommerce form .form-row .input-text` |
| `.woocommerce form .form-row .input-text`, `select`, `label` | `templates/myaccount/form-login.php:38-44`; `client/legacy/css/woocommerce.scss` form rules |
| `form.woocommerce-form-login`, `.woocommerce-form-login__submit` | `templates/myaccount/form-login.php:34`, `:54` |
| `.woocommerce-MyAccount-navigation`, `.woocommerce-MyAccount-navigation-link`, `.is-active` | `templates/myaccount/navigation.php:25-28`; classes built in `includes/wc-account-functions.php:172-177` |
| `.woocommerce-MyAccount-content` | `templates/myaccount/my-account.php:27` |
| `table.shop_table` (`thead th`, `tbody`, `tfoot`) | `templates/order/order-details.php:60`; WooCommerce row rules and `border-collapse: separate` in `client/legacy/css/woocommerce.scss:1026-1072` |
| `table.shop_table_responsive.my_account_orders tbody th` (order number cell, `th scope="row"` with `data-title`) shown on phones | `templates/myaccount/orders.php:26` (table classes), `:46` (the `th`); WooCommerce hides every `tbody th` of responsive tables in `client/legacy/css/woocommerce-smallscreen.scss:33-35`, a stylesheet loaded with `media="only screen and (max-width: 768px)"` (`includes/class-wc-frontend-scripts.php:87-91`, filter `woocommerce_style_smallscreen_breakpoint`); the `::before` label copies WooCommerce's own `td::before` (`woocommerce-smallscreen.scss:49-53`) |
| Body classes of the My Account sections: `woocommerce-orders`, `woocommerce-view-order`, `woocommerce-downloads`, `woocommerce-edit-account`, `woocommerce-edit-address`, `woocommerce-payment-methods`, `woocommerce-add-payment-method`, `woocommerce-lost-password` | `includes/wc-template-functions.php:369-373` (`woocommerce-` + query var key), keys at `includes/class-wc-query.php:96-104`; section titles ("Orders", "Order #%s", "Downloads", "Account details", "Addresses", "Payment methods", "Add payment method", "Lost password", "Set password") at `class-wc-query.php:120-161`, swapped into the page title by `wc_page_endpoint_title()` on `the_title` (`includes/wc-page-functions.php:20-39`) |
| `.woocommerce-order`, `.woocommerce-order-overview` | `templates/checkout/thankyou.php:23`, `:61` |
| `.woocommerce-thankyou-order-received` | `templates/checkout/order-received.php:23` |
| `ul.woocommerce-order-overview` and its `li` (floated by `ul.order_details li`, with dashed right borders) | `templates/checkout/thankyou.php:61-90`; float rules in `client/legacy/css/woocommerce.scss:1367-1383` |
| `.woocommerce-customer-details address`, `.woocommerce-customer-details p` (long e-mail wrap) | `templates/order/order-details-customer.php:22`, `:37`, `:41` |
| `.product` on `div.product` | `templates/content-single-product.php:34` (`wc_product_class`), class list at `includes/wc-template-functions.php:693-697` |
| `.product_title` | `templates/single-product/title.php:22` |
| `p.price`, `span.price` | `templates/single-product/price.php:25` (filter `woocommerce_product_price_class`, default `price`) |
| `.woocommerce-product-details__short-description` | `templates/single-product/short-description.php:31` |
| `form.cart`, `.single_add_to_cart_button` | `templates/single-product/add-to-cart/simple.php:32`, `:49` |
| `.woocommerce-tabs`, `ul.tabs`, `ul.tabs::before`, `li`, `li::before`, `li::after`, `li.active` | `templates/single-product/tabs/tabs.php:33-36`; lavender `$secondary: #e9e6ed` (`client/legacy/css/_variables.scss:18`) used for the `li` background and border (`woocommerce.scss:343-347`), the curved corner pieces `li::before` / `li::after` (`:384-410`) and the strip line `ul.tabs::before` (`:413-420`); `li.active` in `woocommerce.scss:368-383`, the class is set by `client/legacy/js/frontend/single-product.js:34`, `:41` |
| `.product_meta` | `templates/single-product/meta.php:26` |
| `span.onsale` | `templates/single-product/sale-flash.php:27` |
| `.woocommerce-breadcrumb` | `includes/wc-template-functions.php:2880` |

## E. Our own classes

From the HTML blocks in `build/blocks/`: `lp-shop-head`, `lp-shop-head--checkout`, `lp-shop-head--cart`, `lp-shop-head--account`, `lp-shop-eyebrow`, `lp-shop-title`, `lp-shop-sub`, `lp-shop-note`, `lp-shop-help`, `lp-shop-foot`, `lp-shop-foot--checkout`, `lp-shop-trust`, `lp-shop-tbc`.

From the ledgerplus-shop plugin (`build/ledgerplus-shop/ledgerplus-shop.php`, same markup in the WPCode variant), section 15 of store.css: `lp-shop`, `lp-shop-thankyou` (+ `--paid`, `--awaiting`, `--closed`, line 1900-1908), `lp-shop-next`, `lp-shop-next__title`, `lp-shop-next__intro`, `lp-shop-next__steps`, `lp-shop-next__step`, `lp-shop-next__num`, `lp-shop-next__text` (lines 1835-1842), `lp-shop-referral` (+ `--pending`, `--account`), `lp-shop-referral__text`, `lp-shop-referral__code` (lines 1785, 1862-1865, 1903-1906, 1963), `lp-shop-help__link`, `lp-shop-help__phone` (lines 1816-1820, styled through `.lp-shop-help a`), `lp-shop-account` (line 1963). The thank-you block prints on `woocommerce_thankyou` priority 5, between the order overview and the order details (`templates/checkout/thankyou.php:97`).

All of these are always under a store body class.

## Contrast checks (WCAG 2.x, computed)

| Pair | Ratio |
|---|---|
| White on blue `#0f5cd8` (Pay securely, Proceed to checkout, Add to cart) | 5.93 |
| White on hover blue `#0b4cb8` | 7.66 |
| White on navy `#041950` (Apply, quantity badge) | 16.67 |
| Body text `#2c3651` on white | 11.98 |
| Muted `#5b6684` on white / on `#f6f8fd` / on `#eaf1fd` | 5.71 / 5.37 / 5.03 |
| Blue `#0f5cd8` links on white / on `#eaf1fd` (eyebrow, active nav) | 5.93 / 5.22 |
| Navy on tint `#eaf1fd` (secondary buttons) | 14.68 |
| Input border `#7a86a3` on white (non-text, 3:1 needed) | 3.64 |
| Error `#b42318` on white | 6.57 |
| Muted `#5b6684` on inactive tab `#f6f8fd` (was WooCommerce `#e9e6ed`) | 5.37 |
| White on blue `#0f5cd8`, empty cart "Add to cart" (was blue on `#32373c`, 2.03) | 5.93 |
| Blue `#0f5cd8` order number link on tint `#eaf1fd` (phone orders table) | 5.22 |
| Ink `#030922` on tint `#eaf1fd` (referral code box) | 17.35 |

Focus: inputs get a 2px blue outline (offset 1px) and a blue border. Buttons (block buttons, classic `.button`, the empty cart grid button) get a 3px navy outline with a white gap; the classic button rule now includes `.site-main`, so it outweighs the general focus rule (before the fix the classic buttons got the 2px blue ring). Under `prefers-reduced-motion: reduce` the transitions store.css adds (inputs, Place order, Proceed to checkout) are switched off with the same selectors, so the media rule outweighs them. The coupon toggle and links get a 2px blue outline. All of these are visible in `mock/shots/checkout-focus-input-1440.png`, `checkout-focus-coupon-1440.png` and `checkout-states-*.png`.
