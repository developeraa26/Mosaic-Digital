# Company and phone fields in the block checkout (WooCommerce 11.3.1)

Paths are relative to `plugins/woocommerce/` at tag `11.3.1`. Local copies are in
(a local working copy, not included here)
(`cf-11.3.1.php` is `src/Blocks/Domain/Services/CheckoutFields.php`).

## Answer

Yes. In 11.3.1 the **options** `woocommerce_checkout_company_field` and `woocommerce_checkout_phone_field` decide whether the block checkout shows the Company and Phone fields and whether they are required. The block attributes for this (`showCompanyField`, `requireCompanyField`, `showPhoneField`, `requirePhoneField`) are deprecated. WooCommerce reads them only once, to migrate old pages, and only when the options are not set yet.

## Exact values to set

| Option | Value | Effect in the block checkout |
|---|---|---|
| `woocommerce_checkout_company_field` | `required` | Company field shown in the billing address, label "Company", must be filled in (checked in the browser and on the server) |
| `woocommerce_checkout_phone_field` | `required` | Phone field shown in the billing address, label "Phone", must be filled in |

Allowed values are exactly `hidden`, `optional`, `required`. Store the plain string, no array and no JSON.

WP-CLI equivalent, for reference only (the operator sets these):

```
wp option update woocommerce_checkout_company_field required
wp option update woocommerce_checkout_phone_field required
```

Do not change `woocommerce_checkout_address_2_field` unless asked. It works the same way.

## Source trail

| Step | Source (file:line) |
|---|---|
| Options are registered with `show_in_rest` and the enum `optional`, `required`, `hidden`. Defaults come from `CartCheckoutUtils::get_phone_field_visibility()` and `get_company_field_visibility()` | `src/Blocks/BlockTypes/Checkout.php:94-146` |
| Getter: returns the stored option if it is set. Only when it is empty and the checkout page uses the block does it run the one-time migration | `src/Blocks/Utils/CartCheckoutUtils.php:249-261` (company), `:289-301` (phone) |
| One-time migration: first writes phone `optional`, company `hidden`, address_2 `optional`, then converts the old block attributes (`showCompanyField`, `requireCompanyField`, `showPhoneField`, `requirePhoneField`) into the options | `src/Blocks/Utils/CartCheckoutUtils.php:184-243` |
| The old attributes now live only in `deprecatedAttributes` ("here for v1 migration support") | `client/blocks/assets/js/blocks/checkout/attributes.ts:18-62` |
| The current checkout `block.json` has no field visibility attributes (only `align`, `showFormStepNumbers`) | `client/blocks/assets/js/blocks/checkout/block.json:18-27` |
| Block field definitions read the options: company `required` and `hidden` (labels "Company" and "Company (optional)") | `src/Blocks/Domain/Services/CoreCheckoutFields.php:89-98` |
| Phone `required` and `hidden` (labels "Phone" and "Phone (optional)", `type` tel) | `src/Blocks/Domain/Services/CoreCheckoutFields.php:161-173` |
| These core fields are sent to the block frontend as the `defaultFields` setting | `cf-11.3.1.php` (CheckoutFields.php) `:127` and `:489-491` |
| Server side, the classic address fields read the same getters (company `required`, phone `required`, and both removed when `hidden`) | `includes/class-wc-countries.php:943-949`, `:1000-1017` (removal when hidden at `:1011-1017`) |
| Store API order validation loops over those locale fields and rejects empty required ones ("%s is required") | `src/StoreApi/Utilities/OrderController.php:847-886` |
| The Customizer still has these three controls, but WooCommerce greys out its Checkout section when the checkout page holds the Checkout block, and points to the editor instead | `includes/customizer/class-wc-shop-customizer.php:740-770` (controls), `:64-80` and `:279-290` (disabled with block checkout), `:947-950` (detection) |
| Customizer sanitizer accepts only `hidden`, `optional`, `required` | `includes/customizer/class-wc-shop-customizer.php:919-920` |

## Notes for the operator

1. With shipping disabled the block shows the **billing** address step, so Company and Phone appear there, not under Contact information.
2. Changing the options affects the classic checkout and My Account address forms too, because the classic address fields read the same getters (`includes/class-wc-countries.php:943-1017`).
3. In the block editor, the Billing address block sidebar has the same toggles. They write these options directly: `editEntityRecord( "root", "site", undefined, { woocommerce_checkout_<field>_field: value } )` for `phone`, `company`, `address_2` with `optional`, `required` or `hidden` (`client/blocks/assets/js/blocks/checkout/address-field-controls.tsx:18-31`, used in `.../checkout-billing-address-block/edit.tsx:22`, `:60`). The value is saved when the page is saved, through the site settings REST route (the options are `show_in_rest`). Whichever way you set it, the stored option is what counts.
4. The "Company" field label comes from `CoreCheckoutFields.php:90` (msgid `Company`, text domain `woocommerce`). If you want "Company name" in the block checkout, that needs a translation filter. It is not a setting.
