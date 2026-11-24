# Place order button label: "Pay securely" (WooCommerce 11.3.1)

All paths below are relative to `plugins/woocommerce/` at tag `11.3.1`
(https://raw.githubusercontent.com/woocommerce/woocommerce/11.3.1/plugins/woocommerce/...).
Local copies: (a local working copy, not included here).

## Block comment to use

Replace the existing `woocommerce/checkout-actions-block` in page 887 (there must be only one; `supports.multiple` is `false`, block.json line 9) with:

```html
<!-- wp:woocommerce/checkout-actions-block {"placeOrderButtonLabel":"Pay securely"} -->
<div class="wp-block-woocommerce-checkout-actions-block"></div>
<!-- /wp:woocommerce/checkout-actions-block -->
```

If the block in page 887 already has attributes (for example `"showReturnToCart":true`, `"cartPageId":886` or `"className":"is-style-with-price"`), keep them and add `"placeOrderButtonLabel":"Pay securely"` to the same JSON object. If `className` is set, the class also has to be in the `<div class="...">` (that is what `useBlockProps.save()` writes). Optional variant that also shows the total on the button. With the ledgerplus-shop plugin active (its F1 filter shows "AED" as the currency symbol) and Currency position "Left with space", it renders as "Pay securely · AED 1,999.00". Without the plugin, WooCommerce prints its default AED symbol "د.إ" (`includes/wc-core-functions.php:537`), so the button would read "Pay securely · د.إ 1,999.00". The "·" is the default `priceSeparator` (`.../checkout-actions-block/block.json:34-37`):

```html
<!-- wp:woocommerce/checkout-actions-block {"placeOrderButtonLabel":"Pay securely","className":"is-style-with-price"} -->
<div class="wp-block-woocommerce-checkout-actions-block is-style-with-price"></div>
<!-- /wp:woocommerce/checkout-actions-block -->
```

## What the source confirms

| Fact | Source (file:line) |
|---|---|
| Block name is `woocommerce/checkout-actions-block`; its parent is `woocommerce/checkout-fields-block` | `client/blocks/assets/js/blocks/checkout/inner-blocks/checkout-actions-block/block.json:2`, `:39` |
| `block.json` does **not** declare `placeOrderButtonLabel`. It declares only `lock`, `cartPageId`, `showReturnToCart`, `className`, `priceSeparator` | same `block.json:14-38` |
| `placeOrderButtonLabel` (type `string`) and `returnToCartButtonLabel` are declared in `attributes.tsx` | `.../checkout-actions-block/attributes.tsx:9-17` |
| They are merged into the registered block type: `attributes: { ...attributes, ...metadata.attributes }` | `.../checkout-actions-block/index.tsx:33` |
| Default label is `__( 'Place Order', 'woocommerce' )` (msgid `Place Order`, text domain `woocommerce`) | `.../checkout-actions-block/constants.tsx:6` |
| Save output is an empty wrapper: `<div { ...useBlockProps.save() } />`, so the label is stored only in the block comment JSON. The core serializer leaves out attributes that equal their default, which is why the stock page has no JSON at all | `.../checkout-actions-block/edit.tsx:161-163`; stock markup in `includes/class-wc-install.php:3341-3343` |
| In the editor the label is edited inline with RichText and saved through `setAttributes( { placeOrderButtonLabel: content } )` | `.../checkout-actions-block/edit.tsx:138-150` |
| On render, PHP copies every block attribute onto the wrapper as a `data-` attribute (camelCase to kebab-case), skipping `className` and `align`, and adds `data-block-name`. Result: `data-place-order-button-label="Pay securely"` | `src/Blocks/BlockTypesController.php:87` (render_block filter), `:421-459` (`add_data_attributes`) |
| The frontend reads each inner block node `dataset` (plus `className`) as props | `client/blocks/assets/js/utils/render-parent-block.tsx:166-170` |
| The frontend component keeps only declared attributes (`withFilteredAttributes( { ...attributes, ...metadata.attributes } )`) | `.../checkout-actions-block/frontend.tsx:13-16` |
| Final label: `applyCheckoutFilter( { filterName: "placeOrderButtonLabel", defaultValue: paymentMethodButtonLabel \|\| placeOrderButtonLabel \|\| defaultPlaceOrderButtonLabel } )` | `.../checkout-actions-block/block.tsx:55-61` |
| `paymentMethodButtonLabel` is the active payment method `placeOrderButtonLabel` property; a method can also replace the whole button through `placeOrderButton` | `client/blocks/assets/js/base/context/hooks/use-checkout-submit.js:50-56` |
| Price on the button only when the block class contains `is-style-with-price` (style variations registered as `without-price` default and `with-price`) | `.../checkout-actions-block/block.tsx:67`; `src/Blocks/BlockTypes/CheckoutActionsBlock.php:30-45` |

## Caveats

1. **A payment gateway can override the label.** If the gateway chosen later (WooCommerce Stripe extension or WooPayments) registers a `placeOrderButtonLabel` for its payment method, that text wins over "Pay securely" (block.tsx:57-60). A gateway can also swap in its own button component (`placeOrderButton`). After the gateway is installed, check the live button text again.
2. Another plugin could change it through the `placeOrderButtonLabel` checkout filter (block.tsx:55-56).
3. Check after saving: view the page source of /checkout/ and look for `data-block-name="woocommerce/checkout-actions-block"` with `data-place-order-button-label="Pay securely"` on the same div.
4. Edit the page in the block editor, or replace the raw comment exactly as shown. Do not wrap the block in Elementor. The checkout page must keep the `woocommerce/checkout` block (WooCommerce checks `has_block( "woocommerce/checkout" )`, `src/Blocks/Utils/CartCheckoutUtils.php:180-181`).
