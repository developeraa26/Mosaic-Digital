<?php
/**
 * Plugin Name:          Ledger Plus Shop
 * Plugin URI:           https://ledgerplus.ae/
 * Description:          WooCommerce add-ons for the Ledger Plus E-Invoicing Starter Package: AED symbol, trade licence and VAT TRN checkout fields, referral codes, referral wording on the cart and checkout, buy-now link safety, product page redirect, the thank-you "What happens next" block and Ledger Plus branded order emails.
 * Version:              1.1.0
 * Requires at least:    6.5
 * Requires PHP:         8.1
 * Requires Plugins:     woocommerce
 * Author:               Ledger Plus
 * Text Domain:          ledgerplus-shop
 * Domain Path:          /languages
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * WC requires at least: 9.0
 * WC tested up to:      11.3
 *
 * @package LedgerPlusShop
 */

defined( 'ABSPATH' ) || exit;

/*
 * F9. Plugin-only: declare HPOS and cart/checkout blocks compatibility.
 * FeaturesUtil::declare_compatibility( $feature_id, $plugin_file, $positive )
 * (src/Utilities/FeaturesUtil.php:87) must run inside before_woocommerce_init
 * (src/Internal/Features/FeaturesController.php:1019). Feature ids:
 * 'custom_order_tables' (src/Internal/DataStores/Orders/CustomOrdersTableController.php:624),
 * 'cart_checkout_blocks' (FeaturesController.php:361).
 * Not in the WPCode variant: it needs this plugin's file path.
 */
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

/*
 * Ledger Plus Shop - shared core.
 *
 * This block is shared, unchanged, by the plugin file (ledgerplus-shop.php) and
 * the WPCode snippet (ledgerplus-shop-wpcode.php). It must stay safe to run
 * inside eval() on every request:
 *   - no namespace or `use` statements, no __FILE__ / __DIR__,
 *   - nothing runs at load time except LP_Shop::bootstrap(), which only adds hooks,
 *   - the class is only declared once (class_exists guard), so loading the plugin
 *     and the snippet together cannot cause a "cannot redeclare" fatal error.
 *
 * WooCommerce source references in comments are to WooCommerce 11.3.1
 * (plugins/woocommerce/...).
 */

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

if ( ! class_exists( 'LP_Shop', false ) ) {

	/**
	 * Ledger Plus Shop customisations.
	 */
	final class LP_Shop {

		const VERSION = '1.1.0';

		/* ------------------------------------------------------------------
		 * F8. Settings. Defaults here; override with a constant in
		 * wp-config.php (LP_SHOP_PRODUCT_ID, LP_SHOP_SALES_PAGE_ID,
		 * LP_SHOP_ADDON_PRODUCT_ID, LP_SHOP_PHONE_DISPLAY, LP_SHOP_PHONE_TEL,
		 * LP_SHOP_WHATSAPP_URL, LP_SHOP_REWARD_TEXT, LP_SHOP_REWARD_TERMS) or
		 * with the `lp_shop_settings` filter. See LP_Shop::settings().
		 * ------------------------------------------------------------------ */
		const DEFAULT_PRODUCT_ID       = 890;
		const DEFAULT_SALES_PAGE_ID    = 892;
		const DEFAULT_ADDON_PRODUCT_ID = 891;
		const DEFAULT_PHONE_DISPLAY    = '+971 50 342 0832';
		const DEFAULT_PHONE_TEL        = '+971503420832';
		const DEFAULT_WHATSAPP_URL     = 'https://wa.me/971503420832';
		const DEFAULT_SUPPORT_EMAIL    = 'info@ledgerplus.ae';
		const DEFAULT_PRICE_TEXT       = 'AED 1,999';
		const DEFAULT_REWARD_TEXT      = 'you get AED 999 cash back after their payment is confirmed.';
		const DEFAULT_REWARD_TERMS     = '[Reward terms: to confirm]';

		/* Additional checkout fields (F2). Location "contact", group "other". */
		const FIELD_TRADE_LICENCE = 'ledgerplus/trade-licence';
		const FIELD_TRN           = 'ledgerplus/trn';
		/* CheckoutFields::OTHER_FIELDS_PREFIX (src/Blocks/Domain/Services/CheckoutFields.php:88). */
		const FIELD_META_PREFIX = '_wc_other/';

		/* Referral codes (F3). */
		const CODE_PREFIX   = 'LP-';
		const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
		const CODE_LENGTH   = 6;

		/* Order meta. */
		const META_CODE           = '_lp_referral_code';
		const META_CODE_COUPON_ID = '_lp_referral_coupon_id';
		const META_REFERRED_BY    = '_lp_referred_by_code';
		const META_REFERRER_ORDER = '_lp_referrer_order_id';
		const META_STATUS         = '_lp_referral_status';
		const META_REPEAT         = '_lp_possible_repeat';
		const META_REPEAT_ORDERS  = '_lp_possible_repeat_orders';
		const META_REPEAT_CHECKED = '_lp_repeat_checked';
		const META_TL_NORM        = '_lp_trade_licence_norm';
		const META_CODE_INACTIVE  = '_lp_referral_code_inactive';
		const META_ADMIN_NOTICE   = '_lp_admin_paid_notice_sent';

		/* Coupon meta. */
		const CMETA_REFERRAL       = '_lp_referral';
		const CMETA_REFERRER_ORDER = '_lp_referrer_order_id';
		const CMETA_REFERRER_EMAIL = '_lp_referrer_email';
		const CMETA_REFERRER_TL    = '_lp_referrer_trade_licence';

		/*
		 * Referral status on the referred order (_lp_referral_status). Only pending_review on an
		 * order that is still processing or completed can lead to a reward, and only after a
		 * person has reviewed it. Nothing is ever paid automatically.
		 */
		const STATUS_PENDING        = 'pending_review';
		const STATUS_SELF           = 'ineligible_self';
		const STATUS_VOID_CANCELLED = 'void_cancelled';
		const STATUS_VOID_REFUNDED  = 'void_refunded';

		const MIN_WC_VERSION = '9.0';

		/* Per-order lock (options API). */
		const LOCK_PREFIX    = 'lp_shop_lock_';
		const LOCK_AT_SUFFIX = '_at';
		const LOCK_TTL       = 120;

		/* The thank-you page only creates a missing code once the order has been paid this long (seconds). */
		const THANKYOU_FALLBACK_AFTER = 60;

		/** @var bool */
		private static $booted = false;

		/** @var bool */
		private static $hooks_added = false;

		/** @var bool */
		private static $fields_registered = false;

		/** @var bool */
		private static $inline_added = false;

		/** @var array|null */
		private static $settings = null;

		/** @var array<string,string> Pending self-referral messages keyed by lower-case coupon code. */
		private static $coupon_errors = array();

		/* ==================================================================
		 * Boot
		 * ================================================================== */

		/**
		 * Adds the boot hook. Safe to call more than once.
		 */
		public static function bootstrap() {
			if ( self::$booted ) {
				return;
			}
			self::$booted = true;

			if ( did_action( 'plugins_loaded' ) ) {
				self::init();
			} else {
				add_action( 'plugins_loaded', array( __CLASS__, 'init' ), 20 );
			}
		}

		/**
		 * True when a supported WooCommerce is loaded.
		 *
		 * @return bool
		 */
		public static function wc_ok() {
			return class_exists( 'WooCommerce', false )
				&& function_exists( 'WC' )
				&& defined( 'WC_VERSION' )
				&& version_compare( (string) WC_VERSION, self::MIN_WC_VERSION, '>=' );
		}

		/**
		 * Registers every hook, or only an admin notice when WooCommerce is missing.
		 */
		public static function init() {
			if ( self::$hooks_added ) {
				return;
			}
			if ( ! self::wc_ok() ) {
				add_action( 'admin_notices', array( __CLASS__, 'admin_notice_wc_missing' ) );
				return;
			}
			self::$hooks_added = true;

			// F1. AED instead of the dirham sign (includes/wc-core-functions.php:726).
			add_filter( 'woocommerce_currency_symbol', array( __CLASS__, 'filter_currency_symbol' ), 20, 2 );

			// F2. Additional checkout fields. CheckoutFields::register_checkout_field() warns when
			// called before after_setup_theme and asks for woocommerce_init or later
			// (src/Blocks/Domain/Services/CheckoutFields.php:211-212); woocommerce_init fires on
			// init priority 0 (includes/class-woocommerce.php:345, 1042).
			if ( did_action( 'woocommerce_init' ) ) {
				self::register_checkout_fields();
			} else {
				add_action( 'woocommerce_init', array( __CLASS__, 'register_checkout_fields' ) );
			}

			// F3a/c/e. Paid-order processing. woocommerce_order_status_{to} fires before
			// woocommerce_order_status_{from}_to_{to} (includes/class-wc-order.php:459 vs 479), and
			// the completed email listens on woocommerce_order_status_completed at priority 10
			// (includes/class-wc-emails.php:119,146). Priority 5 runs before any email is built.
			add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'on_order_paid' ), 5, 2 );
			add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'on_order_paid' ), 5, 2 );

			// F3c follow-up. Cancelled / refunded orders void a pending referral (no reward is due for
			// them) and add notes. woocommerce_order_status_{to} passes ( $order_id, $order, $transition )
			// (includes/class-wc-order.php:459). wc_create_refund() fires woocommerce_order_fully_refunded /
			// woocommerce_order_partially_refunded ( $order_id, $refund_id ) (includes/wc-order-functions.php:735,737).
			add_action( 'woocommerce_order_status_cancelled', array( __CLASS__, 'on_order_cancelled' ), 20, 2 );
			add_action( 'woocommerce_order_status_refunded', array( __CLASS__, 'on_order_refunded' ), 20, 2 );
			add_action( 'woocommerce_order_fully_refunded', array( __CLASS__, 'on_order_fully_refunded' ), 20, 2 );
			add_action( 'woocommerce_order_partially_refunded', array( __CLASS__, 'on_order_partially_refunded' ), 20, 2 );

			// F3f follow-up. The admin "New order" email goes out on pending -> on-hold, before payment
			// (includes/emails/class-wc-email-new-order.php:43-51), and is not sent again on
			// on-hold -> processing. woocommerce_order_status_changed ( $id, $from, $to, $order )
			// (includes/class-wc-order.php:480).
			add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'maybe_send_admin_paid_notice' ), 20, 4 );

			// F3b + F7. Thank-you page (templates/checkout/thankyou.php:97). Priority 5 = before
			// the order details table (includes/wc-template-hooks.php:276).
			add_action( 'woocommerce_thankyou', array( __CLASS__, 'render_thankyou' ), 5 );

			// F3b + F3f. Emails (templates/emails/*.php and emails/block/general-block-email.php:148).
			add_action( 'woocommerce_email_order_meta', array( __CLASS__, 'render_email_block' ), 20, 4 );

			// F11. Email branding. WC_Email::apply_inline_style() passes the template CSS through
			// woocommerce_email_styles before Emogrifier inlines it (includes/emails/class-wc-email.php:972-1004);
			// wc_get_email_order_items() filters its template args (includes/wc-template-functions.php:4019-4031).
			add_filter( 'woocommerce_email_styles', array( __CLASS__, 'filter_email_styles' ), 20, 2 );
			add_filter( 'woocommerce_email_order_items_args', array( __CLASS__, 'filter_email_order_items_args' ), 20 );
			// F11. WC_Email::send() passes the inlined message through woocommerce_mail_content
			// (includes/emails/class-wc-email.php:1275); Emogrifier writes the CSS it cannot inline as one line.
			add_filter( 'woocommerce_mail_content', array( __CLASS__, 'filter_mail_content' ), 20 );
			// F11. WooCommerce > Settings > Emails: say so when the new email templates are off (the F11 CSS
			// needs them; includes/admin/settings/class-wc-settings-emails.php:932-947).
			add_action( 'admin_notices', array( __CLASS__, 'admin_notice_email_templates' ) );

			// F3b. My Account dashboard (templates/myaccount/dashboard.php:65).
			add_action( 'woocommerce_account_dashboard', array( __CLASS__, 'render_account_codes' ), 20 );

			// Admin order screen summary (includes/admin/meta-boxes/class-wc-meta-box-order-data.php:650).
			add_action( 'woocommerce_admin_order_data_after_billing_address', array( __CLASS__, 'render_admin_order_box' ), 20 );

			// F3d. Early self-referral block (includes/class-wc-discounts.php:1280, 1291).
			add_filter( 'woocommerce_coupon_is_valid', array( __CLASS__, 'filter_coupon_is_valid' ), 20, 3 );
			add_filter( 'woocommerce_coupon_error', array( __CLASS__, 'filter_coupon_error' ), 20, 3 );

			// F3g. Classic success message (includes/class-wc-coupon.php:1097-1109).
			add_filter( 'woocommerce_coupon_message', array( __CLASS__, 'filter_coupon_message' ), 20, 3 );

			// F4. Coupon wording: block cart/checkout (JS) and classic/Store API (PHP gettext).
			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_block_labels' ), 20 );
			add_filter( 'gettext_woocommerce', array( __CLASS__, 'filter_gettext_woocommerce' ), 20, 3 );

			// F5. Buy-now link safety (includes/class-wc-form-handler.php:61, 1094).
			add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'filter_buy_now_validation' ), 20, 3 );

			// F6. Product page redirect.
			add_action( 'template_redirect', array( __CLASS__, 'maybe_redirect_product_page' ), 5 );
		}

		/**
		 * Admin notice when WooCommerce is missing or too old.
		 */
		public static function admin_notice_wc_missing() {
			if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Ledger Plus Shop needs WooCommerce 9.0 or newer. Its features are switched off until WooCommerce is active.', 'ledgerplus-shop' ) . '</p></div>';
		}

		/* ==================================================================
		 * F8. Settings
		 * ================================================================== */

		/**
		 * Returns the settings array (filter: lp_shop_settings).
		 *
		 * Only call this from hook callbacks that run at `init` or later, so the
		 * theme and other plugins have had a chance to add the filter.
		 *
		 * @return array
		 */
		public static function settings() {
			if ( null !== self::$settings ) {
				return self::$settings;
			}

			$product_id = absint( self::constant_or( 'LP_SHOP_PRODUCT_ID', self::DEFAULT_PRODUCT_ID ) );
			$addon_id   = absint( self::constant_or( 'LP_SHOP_ADDON_PRODUCT_ID', self::DEFAULT_ADDON_PRODUCT_ID ) );

			$defaults = array(
				'product_id'            => $product_id,
				'sales_page_id'         => absint( self::constant_or( 'LP_SHOP_SALES_PAGE_ID', self::DEFAULT_SALES_PAGE_ID ) ),
				'addon_product_id'      => $addon_id,
				'phone_display'         => (string) self::constant_or( 'LP_SHOP_PHONE_DISPLAY', self::DEFAULT_PHONE_DISPLAY ),
				'phone_tel'             => (string) self::constant_or( 'LP_SHOP_PHONE_TEL', self::DEFAULT_PHONE_TEL ),
				'whatsapp_url'          => (string) self::constant_or( 'LP_SHOP_WHATSAPP_URL', self::DEFAULT_WHATSAPP_URL ),
				'support_email'         => self::DEFAULT_SUPPORT_EMAIL,
				'price_text'            => self::DEFAULT_PRICE_TEXT,
				'reward_text'           => (string) self::constant_or( 'LP_SHOP_REWARD_TEXT', self::DEFAULT_REWARD_TEXT ),
				'reward_terms'          => (string) self::constant_or( 'LP_SHOP_REWARD_TERMS', self::DEFAULT_REWARD_TERMS ),
				// Products whose buy-now link must not show "You cannot add another" (F5).
				'buy_now_product_ids'   => array( $product_id, $addon_id ),
				// Turn single features off without editing code.
				'relabel_coupons'       => true,
				'redirect_product_page' => true,
				'require_paid_referrer' => true,
				// Short admin email with the "Ledger Plus checks" when an on-hold order is paid (F3f follow-up).
				'admin_paid_notice'     => true,
				// How long a paid-order hook waits for another request that is working on the same order.
				'lock_wait_seconds'     => 5,
				// F11: Ledger Plus look for the WooCommerce emails (CSS + the plugin's own email cards).
				// false = the v1.0.0 emails, byte for byte.
				'email_branding'        => true,
			);

			$settings = function_exists( 'apply_filters' ) ? apply_filters( 'lp_shop_settings', $defaults ) : $defaults;
			$settings = is_array( $settings ) ? array_merge( $defaults, $settings ) : $defaults;

			$settings['product_id']          = absint( $settings['product_id'] );
			$settings['sales_page_id']       = absint( $settings['sales_page_id'] );
			$settings['addon_product_id']    = absint( $settings['addon_product_id'] );
			$settings['buy_now_product_ids'] = array_values( array_filter( array_map( 'absint', (array) $settings['buy_now_product_ids'] ) ) );
			foreach ( array( 'phone_display', 'phone_tel', 'whatsapp_url', 'support_email', 'price_text', 'reward_text', 'reward_terms' ) as $key ) {
				$settings[ $key ] = is_scalar( $settings[ $key ] ) ? (string) $settings[ $key ] : (string) $defaults[ $key ];
			}
			$wait                          = is_numeric( $settings['lock_wait_seconds'] ) ? (float) $settings['lock_wait_seconds'] : (float) $defaults['lock_wait_seconds'];
			$settings['lock_wait_seconds'] = max( 0.0, min( 10.0, $wait ) );

			self::$settings = $settings;
			return $settings;
		}

		/**
		 * Clears the cached settings (used by tests).
		 */
		public static function reset_settings() {
			self::$settings = null;
		}

		/**
		 * Value of a constant if defined, otherwise the default.
		 *
		 * @param string $name    Constant name.
		 * @param mixed  $default Default.
		 * @return mixed
		 */
		private static function constant_or( $name, $default ) {
			return defined( $name ) ? constant( $name ) : $default;
		}

		/**
		 * Main product ID (890).
		 *
		 * @return int
		 */
		public static function product_id() {
			$s = self::settings();
			return (int) $s['product_id'];
		}

		/* ==================================================================
		 * F1. Currency symbol
		 * ================================================================== */

		/**
		 * Shows "AED" for the UAE dirham.
		 *
		 * @param string $symbol   Symbol.
		 * @param string $currency Currency code.
		 * @return string
		 */
		public static function filter_currency_symbol( $symbol, $currency = '' ) {
			return ( 'AED' === $currency ) ? 'AED' : $symbol;
		}

		/* ==================================================================
		 * F2. Additional checkout fields
		 * ================================================================== */

		/**
		 * Registers the trade licence and TRN fields in the "contact" location.
		 *
		 * Why "contact": contact fields are shown in the Billing column of the admin order
		 * screen (CheckoutFieldsAdmin.php:33,123-128), in emails under "Additional information"
		 * (class-wc-emails.php:250,845-858), on the order confirmation (CheckoutFieldsFrontend.php:100-103)
		 * and are saved to the customer account (CheckoutFieldsFrontend.php:42,161-173). "order"
		 * fields go to the Shipping column (CheckoutFieldsAdmin.php:35,146), which for a virtual
		 * Store API order reads "No shipping address set." (class-wc-meta-box-order-data.php:668-687).
		 */
		public static function register_checkout_fields() {
			if ( self::$fields_registered || ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
				return;
			}
			self::$fields_registered = true;

			$fields = array(
				array(
					'id'                => self::FIELD_TRADE_LICENCE,
					'label'             => __( 'Trade licence number', 'ledgerplus-shop' ),
					'location'          => 'contact',
					'type'              => 'text',
					'required'          => true,
					'attributes'        => array(
						'autocomplete'   => 'off',
						'autocapitalize' => 'characters',
						'maxLength'      => 40,
						'data-lp-field'  => 'trade-licence',
					),
					'sanitize_callback' => array( __CLASS__, 'field_sanitize_trade_licence' ),
					'validate_callback' => array( __CLASS__, 'field_validate_trade_licence' ),
				),
				array(
					'id'                => self::FIELD_TRN,
					'label'             => __( 'VAT TRN (if VAT registered)', 'ledgerplus-shop' ),
					// The default optional label is "%s (optional)" (CheckoutFields.php:229); the label already says when to fill it.
					'optionalLabel'     => __( 'VAT TRN (if VAT registered)', 'ledgerplus-shop' ),
					'location'          => 'contact',
					'type'              => 'text',
					'required'          => false,
					'attributes'        => array(
						'autocomplete'  => 'off',
						'maxLength'     => 20,
						'data-lp-field' => 'trn',
					),
					'sanitize_callback' => array( __CLASS__, 'field_sanitize_trn' ),
					'validate_callback' => array( __CLASS__, 'field_validate_trn' ),
				),
			);

			foreach ( $fields as $field ) {
				try {
					woocommerce_register_additional_checkout_field( $field );
				} catch ( \Throwable $e ) {
					self::log( 'Could not register checkout field ' . $field['id'] . ': ' . $e->getMessage() );
				}
			}
		}

		/**
		 * Field sanitize callback: ( $value, $field ) (CheckoutFields.php:534).
		 *
		 * @param mixed $value Value.
		 * @param array $field Field.
		 * @return string
		 */
		public static function field_sanitize_trade_licence( $value, $field = array() ) {
			return self::sanitize_trade_licence( $value );
		}

		/**
		 * Field validate callback: ( $value, $field ), returns WP_Error or true (CheckoutFields.php:600-609).
		 *
		 * WooCommerce 11.3.1 only calls it with the ALREADY SANITIZED value, and only when that value is
		 * not empty: the Store API runs our sanitize_callback first (CheckoutSchema.php:200, 410-428) and
		 * Checkout::validate_callback() reports "%s is required" itself for empty required fields
		 * (Checkout.php:322-338); My Account does the same (CheckoutFieldsFrontend.php:286-291, 328-338).
		 * The "required" branch below is therefore never reached through WooCommerce; it only covers
		 * direct calls. Sanitizing again here is harmless (idempotent).
		 *
		 * @param mixed $value Value.
		 * @param array $field Field.
		 * @return true|\WP_Error
		 */
		public static function field_validate_trade_licence( $value, $field = array() ) {
			$clean = self::sanitize_trade_licence( $value );
			if ( '' === $clean ) {
				return new \WP_Error( 'lp_trade_licence_required', __( 'Please enter your trade licence number.', 'ledgerplus-shop' ) );
			}
			if ( ! self::is_valid_trade_licence( $clean ) ) {
				return new \WP_Error( 'lp_trade_licence_invalid', __( 'Please check your trade licence number. Use 3 to 40 letters or numbers; spaces, dashes (-), slashes (/) and dots (.) are allowed.', 'ledgerplus-shop' ) );
			}
			return true;
		}

		/**
		 * TRN sanitize callback.
		 *
		 * @param mixed $value Value.
		 * @param array $field Field.
		 * @return string
		 */
		public static function field_sanitize_trn( $value, $field = array() ) {
			return self::sanitize_trn( $value );
		}

		/**
		 * TRN validate callback: the digits-only value must be empty or exactly 15 digits.
		 *
		 * As for the trade licence, WooCommerce passes the value AFTER our sanitize callback (digits
		 * only) and skips this callback when that value is empty (see field_validate_trade_licence()).
		 * So input without any digit, such as "N/A", is saved as empty, which means "not VAT
		 * registered"; input with letters and 15 digits, such as "TRN 100-123-456-789-003", is
		 * saved as the 15 digits.
		 *
		 * @param mixed $value Value.
		 * @param array $field Field.
		 * @return true|\WP_Error
		 */
		public static function field_validate_trn( $value, $field = array() ) {
			if ( ( null !== $value && ! is_scalar( $value ) ) || ! self::is_valid_trn( self::sanitize_trn( $value ) ) ) {
				return new \WP_Error( 'lp_trn_invalid', __( 'Your VAT TRN must be exactly 15 digits. Leave it empty if you are not VAT registered.', 'ledgerplus-shop' ) );
			}
			return true;
		}

		/**
		 * Trimmed, upper case, single spaces, no tags.
		 *
		 * @param mixed $value Raw value.
		 * @return string
		 */
		public static function sanitize_trade_licence( $value ) {
			if ( ! is_scalar( $value ) ) {
				return '';
			}
			$value = (string) $value;
			$value = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $value ) : strip_tags( $value );
			$value = (string) preg_replace( '/\s+/u', ' ', $value );
			return strtoupper( trim( $value ) );
		}

		/**
		 * 3-40 characters of A-Z 0-9 space - / . with at least one letter or digit.
		 *
		 * @param string $value Sanitized value.
		 * @return bool
		 */
		public static function is_valid_trade_licence( $value ) {
			$value = (string) $value;
			return 1 === preg_match( '/^[A-Z0-9 \/.\-]{3,40}$/', $value ) && 1 === preg_match( '/[A-Z0-9]/', $value );
		}

		/**
		 * Comparison form of a trade licence: only A-Z and 0-9.
		 *
		 * @param mixed $value Value.
		 * @return string
		 */
		public static function normalise_trade_licence( $value ) {
			return (string) preg_replace( '/[^A-Z0-9]/', '', self::sanitize_trade_licence( $value ) );
		}

		/**
		 * Digits only.
		 *
		 * @param mixed $value Raw value.
		 * @return string
		 */
		public static function sanitize_trn( $value ) {
			if ( ! is_scalar( $value ) ) {
				return '';
			}
			return (string) preg_replace( '/\D+/', '', (string) $value );
		}

		/**
		 * Sanitized TRN is valid when empty or exactly 15 digits.
		 *
		 * @param string $value Sanitized value.
		 * @return bool
		 */
		public static function is_valid_trn( $value ) {
			$value = (string) $value;
			return '' === $value || 1 === preg_match( '/^\d{15}$/', $value );
		}

		/**
		 * Reads an additional checkout field from an order or customer.
		 *
		 * Uses CheckoutFields::get_field_from_object( $key, $wc_object, $group = 'other' )
		 * (src/Blocks/Domain/Services/CheckoutFieldsStorage.php:96), and falls back to the
		 * raw meta key "_wc_other/{$key}" (CheckoutFieldsStorage.php:63-79, CheckoutFields.php:1026-1038).
		 *
		 * @param object $wc_object WC_Order or WC_Customer.
		 * @param string $key       Field id.
		 * @return string
		 */
		public static function get_field_value( $wc_object, $key ) {
			if ( ! is_object( $wc_object ) || ! method_exists( $wc_object, 'get_meta' ) ) {
				return '';
			}

			$value = null;
			$api   = self::checkout_fields_api();
			if ( $api && $wc_object instanceof \WC_Data ) {
				try {
					$value = $api->get_field_from_object( $key, $wc_object, 'other' );
				} catch ( \Throwable $e ) {
					$value = null;
				}
			}

			if ( null === $value || '' === $value ) {
				$value = $wc_object->get_meta( self::FIELD_META_PREFIX . $key, true );
			}

			return is_scalar( $value ) ? (string) $value : '';
		}

		/**
		 * The CheckoutFields service, or null.
		 *
		 * @return object|null
		 */
		private static function checkout_fields_api() {
			$package = '\Automattic\WooCommerce\Blocks\Package';
			$service = '\Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFields';
			if ( ! class_exists( $package ) || ! class_exists( $service ) || ! method_exists( $package, 'container' ) ) {
				return null;
			}
			try {
				$api = call_user_func( array( $package, 'container' ) )->get( ltrim( $service, '\\' ) );
			} catch ( \Throwable $e ) {
				return null;
			}
			return ( is_object( $api ) && method_exists( $api, 'get_field_from_object' ) ) ? $api : null;
		}

		/* ==================================================================
		 * F3. Referral codes
		 * ================================================================== */

		/**
		 * One random code: LP- plus 6 characters from the alphabet, using random_int().
		 *
		 * @return string
		 */
		public static function random_code() {
			$alphabet = self::CODE_ALPHABET;
			$max      = strlen( $alphabet ) - 1;
			$code     = '';
			for ( $i = 0; $i < self::CODE_LENGTH; $i++ ) {
				$code .= $alphabet[ random_int( 0, $max ) ];
			}
			return self::CODE_PREFIX . $code;
		}

		/**
		 * A code that no published coupon uses yet, or '' after $max_attempts tries.
		 *
		 * @param callable|null $exists       fn( string $code ): bool. Defaults to wc_get_coupon_id_by_code().
		 * @param int           $max_attempts Attempts.
		 * @return string
		 */
		public static function generate_unique_code( $exists = null, $max_attempts = 10 ) {
			if ( ! is_callable( $exists ) ) {
				$exists = static function ( $code ) {
					// includes/wc-coupon-functions.php:110 (published coupons, case-insensitive).
					return function_exists( 'wc_get_coupon_id_by_code' ) && wc_get_coupon_id_by_code( $code ) > 0;
				};
			}
			for ( $i = 0; $i < max( 1, (int) $max_attempts ); $i++ ) {
				$code = self::random_code();
				if ( ! call_user_func( $exists, $code ) ) {
					return $code;
				}
			}
			return '';
		}

		/**
		 * Matches a referral code shape (any case).
		 *
		 * @param string $code Code.
		 * @return bool
		 */
		public static function looks_like_code( $code ) {
			$pattern = '/^' . preg_quote( self::CODE_PREFIX, '/' ) . '[' . self::CODE_ALPHABET . ']{' . self::CODE_LENGTH . '}$/';
			return 1 === preg_match( $pattern, strtoupper( trim( (string) $code ) ) );
		}

		/**
		 * Lower case, trimmed, "+tag" removed from the local part (comparison only).
		 *
		 * @param mixed $email Email.
		 * @return string
		 */
		public static function normalise_email( $email ) {
			if ( ! is_scalar( $email ) ) {
				return '';
			}
			$email = strtolower( trim( (string) $email ) );
			if ( function_exists( 'sanitize_email' ) ) {
				$email = strtolower( sanitize_email( $email ) );
			}
			$at = strrpos( $email, '@' );
			if ( false === $at || 0 === $at ) {
				return $email;
			}
			$local  = substr( $email, 0, $at );
			$domain = substr( $email, $at + 1 );
			$plus   = strpos( $local, '+' );
			if ( false !== $plus && $plus > 0 ) {
				$local = substr( $local, 0, $plus );
			}
			return $local . '@' . $domain;
		}

		/**
		 * True when the buyer is the referrer: same normalised email OR same normalised trade licence.
		 *
		 * @param string $buyer_email    Buyer email.
		 * @param string $buyer_tl       Buyer trade licence.
		 * @param string $referrer_email Referrer email.
		 * @param string $referrer_tl    Referrer trade licence.
		 * @return bool
		 */
		public static function is_self_referral( $buyer_email, $buyer_tl, $referrer_email, $referrer_tl ) {
			$be = self::normalise_email( $buyer_email );
			$re = self::normalise_email( $referrer_email );
			if ( '' !== $be && '' !== $re && hash_equals( $re, $be ) ) {
				return true;
			}
			$bt = self::normalise_trade_licence( $buyer_tl );
			$rt = self::normalise_trade_licence( $referrer_tl );
			return '' !== $bt && '' !== $rt && hash_equals( $rt, $bt );
		}

		/**
		 * True for coupons this plugin created.
		 *
		 * @param mixed $coupon WC_Coupon.
		 * @return bool
		 */
		public static function is_referral_coupon( $coupon ) {
			return is_object( $coupon )
				&& method_exists( $coupon, 'get_id' )
				&& method_exists( $coupon, 'get_meta' )
				&& $coupon->get_id() > 0
				&& 'yes' === $coupon->get_meta( self::CMETA_REFERRAL, true );
		}

		/**
		 * True when the order contains the product.
		 *
		 * @param \WC_Order $order      Order.
		 * @param int       $product_id Product ID (defaults to the main product).
		 * @return bool
		 */
		public static function order_has_product( $order, $product_id = 0 ) {
			$product_id = $product_id ? (int) $product_id : self::product_id();
			if ( ! $product_id || ! is_object( $order ) || ! method_exists( $order, 'get_items' ) ) {
				return false;
			}
			foreach ( $order->get_items() as $item ) {
				if ( is_object( $item ) && method_exists( $item, 'get_product_id' ) && (int) $item->get_product_id() === $product_id ) {
					return true;
				}
			}
			return false;
		}

		/**
		 * F3a/c/e entry point: woocommerce_order_status_processing / _completed ( $order_id, $order, $transition ).
		 * Never throws: WC_Order::status_transition() only catches Exception (class-wc-order.php:503).
		 *
		 * @param int            $order_id Order ID.
		 * @param \WC_Order|null $order    Order (same instance WooCommerce passes to the emails).
		 */
		public static function on_order_paid( $order_id, $order = null ) {
			try {
				if ( ! ( $order instanceof \WC_Order ) ) {
					$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
				}
				if ( ! ( $order instanceof \WC_Order ) || ! self::order_has_product( $order ) ) {
					return;
				}
				$s = self::settings();
				// Wait (briefly) when another request is working on this order, so the email that this
				// request builds next still gets the code.
				if ( ! self::process_paid_order( $order, (float) $s['lock_wait_seconds'] ) ) {
					self::log( 'Order ' . absint( $order_id ) . ': another request kept the referral lock; the code was not added in this request.' );
				}
			} catch ( \Throwable $e ) {
				self::log( 'Paid-order processing failed for order ' . absint( $order_id ) . ': ' . $e->getMessage() );
			}
		}

		/**
		 * Idempotent paid-order work. Updates the passed order object so emails built in the
		 * same request see the new meta, and saves it so deferred emails (which re-read the order,
		 * src/Internal/Email/DeferredEmailQueue.php:375-380) see it too.
		 *
		 * @param \WC_Order $order        Order.
		 * @param float     $wait_seconds How long to wait for the per-order lock (0 = do not wait).
		 * @return bool True when the work ran.
		 */
		public static function process_paid_order( $order, $wait_seconds = 0 ) {
			if ( ! ( $order instanceof \WC_Order ) ) {
				return false;
			}
			$order_id = (int) $order->get_id();
			if ( $order_id <= 0 || ! self::acquire_lock_wait( $order_id, (float) $wait_seconds ) ) {
				return false;
			}

			try {
				self::sync_from_storage( $order );

				$dirty = false;

				// F3c. Referral code used on this order (again after a cancelled/refunded order is paid again).
				$status = (string) $order->get_meta( self::META_STATUS, true );
				if ( '' === $status || self::is_void_status( $status ) ) {
					$dirty = self::record_referral_use( $order ) || $dirty;
				}

				// The order's own code works again once the order is paid again.
				$inactive = (string) $order->get_meta( self::META_CODE_INACTIVE, true );
				if ( '' !== $inactive ) {
					$order->delete_meta_data( self::META_CODE_INACTIVE );
					$own = (string) $order->get_meta( self::META_CODE, true );
					if ( '' !== $own ) {
						/* translators: %s: referral code. */
						$order->add_order_note( sprintf( __( 'Ledger Plus: this order is paid again, so its referral code %s works again.', 'ledgerplus-shop' ), $own ) );
					}
					$dirty = true;
				}

				// F3e. Possible existing customer.
				if ( 'yes' !== $order->get_meta( self::META_REPEAT_CHECKED, true ) ) {
					self::flag_possible_repeat( $order );
					$dirty = true;
				}

				// F3a. The buyer's own code (never a second one).
				if ( '' === (string) $order->get_meta( self::META_CODE, true ) ) {
					$dirty = ( '' !== self::issue_referral_code( $order ) ) || $dirty;
				}

				if ( $dirty ) {
					$order->save();
				}
			} finally {
				self::release_lock( $order_id );
			}

			return true;
		}

		/**
		 * Copies flags already stored by another request into this order object.
		 *
		 * @param \WC_Order $order Order.
		 */
		private static function sync_from_storage( $order ) {
			$fresh = self::fresh_order( $order->get_id(), $order );
			if ( ! $fresh ) {
				return;
			}
			$keys = array( self::META_CODE, self::META_CODE_COUPON_ID, self::META_STATUS, self::META_REFERRED_BY, self::META_REFERRER_ORDER, self::META_REPEAT_CHECKED, self::META_REPEAT, self::META_REPEAT_ORDERS );
			foreach ( $keys as $key ) {
				$stored = $fresh->get_meta( $key, true );
				if ( '' !== $stored && null !== $stored && '' === (string) $order->get_meta( $key, true ) ) {
					$order->update_meta_data( $key, $stored );
				}
			}
		}

		/**
		 * The order as stored now, with its meta read from the database.
		 *
		 * wc_get_order() can answer from this request's order cache (class-wc-order-factory.php:34-51,
		 * on with HPOS) and from the meta cache (abstract-wc-data.php:698-730), both filled before
		 * another request saved. read_meta_data( true ) forces a database read of the meta
		 * (abstract-wc-data.php:698-730). Never used on the caller's own object: that would drop its
		 * unsaved changes.
		 *
		 * @param int            $order_id Order ID.
		 * @param \WC_Order|null $not      The caller's object (skipped if wc_get_order() returns it).
		 * @return \WC_Order|null
		 */
		private static function fresh_order( $order_id, $not = null ) {
			$order_id = absint( $order_id );
			if ( ! $order_id || ! function_exists( 'wc_get_order' ) ) {
				return null;
			}
			$fresh = wc_get_order( $order_id );
			if ( ! ( $fresh instanceof \WC_Order ) || ( null !== $not && $fresh === $not ) ) {
				return null;
			}
			if ( method_exists( $fresh, 'read_meta_data' ) ) {
				$fresh->read_meta_data( true );
			}
			return $fresh;
		}

		/**
		 * Per-order lock so two requests (e.g. payment return + webhook, or the thank-you page) do not
		 * work on the same order at once. Uses the options API, not SQL.
		 *
		 * The database decides: add_option() runs INSERT ... ON DUPLICATE KEY UPDATE and returns false
		 * when no row changed (WordPress 7.1.5 wp-includes/option.php:1142-1145). The lock value is
		 * always '1', so a second INSERT for an existing lock changes nothing and fails, even when the
		 * existence check before it was skipped or raced (option.php:1116-1125). The time it was taken
		 * is kept in a separate option for the stale-lock check.
		 *
		 * @param int $order_id Order ID.
		 * @return bool
		 */
		private static function acquire_lock( $order_id ) {
			$key = self::LOCK_PREFIX . absint( $order_id );
			$at  = $key . self::LOCK_AT_SUFFIX;
			// add_option() first asks get_option(), which may answer from this request's cache.
			self::forget_cached_option( $key );
			if ( add_option( $key, '1', '', false ) ) {
				update_option( $at, (string) time(), false );
				return true;
			}

			// Lock held. If its request died (fatal error, time-out) it is never released: clear it
			// once it is older than LOCK_TTL. It is not taken over in the same call; the caller's next
			// attempt competes for it through add_option() like everyone else.
			self::forget_cached_option( $at );
			$since = (int) get_option( $at, 0 );
			if ( $since <= 0 ) {
				// Holder is between its add_option() and update_option(), or died there: start the clock.
				update_option( $at, (string) time(), false );
			} elseif ( ( time() - $since ) > self::LOCK_TTL ) {
				delete_option( $key );
				delete_option( $at );
			}
			return false;
		}

		/**
		 * acquire_lock(), retried every 250 ms for up to $seconds.
		 *
		 * @param int   $order_id Order ID.
		 * @param float $seconds  Maximum wait.
		 * @return bool
		 */
		private static function acquire_lock_wait( $order_id, $seconds ) {
			$deadline = microtime( true ) + max( 0.0, (float) $seconds );
			while ( true ) {
				if ( self::acquire_lock( $order_id ) ) {
					return true;
				}
				if ( microtime( true ) >= $deadline ) {
					return false;
				}
				usleep( 250000 );
			}
		}

		/**
		 * Releases the lock.
		 *
		 * @param int $order_id Order ID.
		 */
		private static function release_lock( $order_id ) {
			$key = self::LOCK_PREFIX . absint( $order_id );
			delete_option( $key );
			delete_option( $key . self::LOCK_AT_SUFFIX );
		}

		/**
		 * Drops this request's cached copy of a (not autoloaded) option, so the next read asks the database.
		 *
		 * @param string $key Option name.
		 */
		private static function forget_cached_option( $key ) {
			if ( function_exists( 'wp_cache_delete' ) ) {
				wp_cache_delete( $key, 'options' );
			}
		}

		/**
		 * F3a. Creates the buyer's own AED 0 referral coupon and stores the code on the order.
		 *
		 * @param \WC_Order $order Order.
		 * @return string The code, or '' on failure.
		 */
		public static function issue_referral_code( $order ) {
			$existing = (string) $order->get_meta( self::META_CODE, true );
			if ( '' !== $existing ) {
				return $existing;
			}

			$order_id = (int) $order->get_id();

			// Never a second code: reuse a coupon already made for this order.
			$found = self::find_code_for_order( $order_id );
			if ( $found ) {
				$order->update_meta_data( self::META_CODE, $found['code'] );
				$order->update_meta_data( self::META_CODE_COUPON_ID, $found['id'] );
				return $found['code'];
			}

			$code = self::generate_unique_code();
			if ( '' === $code ) {
				$order->add_order_note( __( 'Ledger Plus: a referral code could not be created (no unique code found). Please create one by hand.', 'ledgerplus-shop' ) );
				return '';
			}

			$product_id = self::product_id();

			$coupon = new \WC_Coupon();
			$coupon->set_code( $code );
			$coupon->set_status( 'publish' );
			$coupon->set_discount_type( 'fixed_cart' );
			$coupon->set_amount( 0 );
			$coupon->set_individual_use( false );
			$coupon->set_product_ids( array( $product_id ) );
			$coupon->set_usage_limit( 0 );
			$coupon->set_usage_limit_per_user( 0 );
			/* translators: %s: order number. */
			$coupon->set_description( sprintf( __( 'Ledger Plus referral code for order #%s', 'ledgerplus-shop' ), $order->get_order_number() ) );
			$coupon->update_meta_data( self::CMETA_REFERRAL, 'yes' );
			$coupon->update_meta_data( self::CMETA_REFERRER_ORDER, $order_id );
			$coupon->update_meta_data( self::CMETA_REFERRER_EMAIL, strtolower( trim( (string) $order->get_billing_email() ) ) );
			$coupon->update_meta_data( self::CMETA_REFERRER_TL, self::sanitize_trade_licence( self::get_field_value( $order, self::FIELD_TRADE_LICENCE ) ) );
			$coupon_id = (int) $coupon->save();

			if ( $coupon_id <= 0 ) {
				$order->add_order_note( __( 'Ledger Plus: the referral coupon could not be saved. Please create one by hand.', 'ledgerplus-shop' ) );
				return '';
			}

			// Last guard against duplicates (e.g. a stale lock cleared by two requests at once): if this
			// order now has more than one referral coupon, keep the oldest and move the others to the
			// trash. WC_Coupon::delete( false ) trashes (class-wc-coupon-data-store-cpt.php:270-291);
			// wc_get_coupon_id_by_code() and WC_Discounts ignore trashed coupons
			// (wc-coupon-functions.php:110, class-wc-discounts.php:624-635).
			$all = self::find_codes_for_order( $order_id, 10 );
			if ( count( $all ) > 1 ) {
				$keep = array_shift( $all );
				foreach ( $all as $dup ) {
					$dup_coupon = new \WC_Coupon( (int) $dup['id'] );
					if ( $dup_coupon->get_id() > 0 ) {
						$dup_coupon->delete( false );
					}
				}
				/* translators: 1: kept code, 2: number of duplicates. */
				$order->add_order_note( sprintf( __( 'Ledger Plus: a second referral code was created for this order at the same moment. Kept %1$s and moved %2$d duplicate(s) to the trash.', 'ledgerplus-shop' ), $keep['code'], count( $all ) ) );
				$code      = $keep['code'];
				$coupon_id = (int) $keep['id'];
				if ( $coupon_id !== (int) $coupon->get_id() ) {
					// Ours was trashed: this request's emails show the kept code.
					$order->update_meta_data( self::META_CODE, $code );
					$order->update_meta_data( self::META_CODE_COUPON_ID, $coupon_id );
					return $code;
				}
			}

			$order->update_meta_data( self::META_CODE, $code );
			$order->update_meta_data( self::META_CODE_COUPON_ID, $coupon_id );
			$order->add_order_note(
				/* translators: 1: referral code, 2: coupon ID. */
				sprintf( __( 'Ledger Plus referral code %1$s created for this customer (coupon #%2$d, AED 0, product-restricted).', 'ledgerplus-shop' ), $code, $coupon_id )
			);

			return $code;
		}

		/**
		 * The oldest referral coupon already created for this order, if any.
		 *
		 * @param int $order_id Order ID.
		 * @return array|null { id: int, code: string }
		 */
		private static function find_code_for_order( $order_id ) {
			$all = self::find_codes_for_order( $order_id, 1 );
			return $all ? $all[0] : null;
		}

		/**
		 * Referral coupons made for this order, oldest first (shop_coupon posts; not affected by HPOS).
		 * Trashed coupons are left out.
		 *
		 * @param int $order_id Order ID.
		 * @param int $limit    Maximum number.
		 * @return array<int,array{id:int,code:string}>
		 */
		private static function find_codes_for_order( $order_id, $limit = 10 ) {
			if ( ! function_exists( 'get_posts' ) ) {
				return array();
			}
			$ids = get_posts(
				array(
					'post_type'        => 'shop_coupon',
					'post_status'      => array( 'publish', 'draft', 'pending', 'private', 'future' ),
					'fields'           => 'ids',
					'posts_per_page'   => max( 1, (int) $limit ),
					'orderby'          => 'ID',
					'order'            => 'ASC',
					'no_found_rows'    => true,
					'suppress_filters' => true,
					'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						'relation' => 'AND',
						array(
							'key'   => self::CMETA_REFERRAL,
							'value' => 'yes',
						),
						array(
							'key'   => self::CMETA_REFERRER_ORDER,
							'value' => (string) absint( $order_id ),
						),
					),
				)
			);
			$out = array();
			foreach ( (array) $ids as $id ) {
				$coupon = new \WC_Coupon( (int) $id );
				$code   = strtoupper( (string) $coupon->get_code() );
				if ( '' !== $code ) {
					$out[] = array(
						'id'   => (int) $id,
						'code' => $code,
					);
				}
			}
			return $out;
		}

		/**
		 * The first referral coupon used on an order.
		 *
		 * @param \WC_Order $order Order.
		 * @return array|null { code: string, coupon: WC_Coupon }
		 */
		public static function find_referral_coupon_in_order( $order ) {
			if ( ! is_object( $order ) || ! method_exists( $order, 'get_coupon_codes' ) ) {
				return null;
			}
			$own = strtoupper( (string) $order->get_meta( self::META_CODE, true ) );
			foreach ( (array) $order->get_coupon_codes() as $code ) {
				$code = (string) $code;
				if ( '' === $code || ( '' !== $own && strtoupper( $code ) === $own ) ) {
					continue;
				}
				$coupon = new \WC_Coupon( $code );
				if ( self::is_referral_coupon( $coupon ) ) {
					return array(
						'code'   => strtoupper( $code ),
						'coupon' => $coupon,
					);
				}
			}
			return null;
		}

		/**
		 * F3c. Records which referral code a paid order used. Never pays or promises anything.
		 *
		 * @param \WC_Order $order Order.
		 * @return bool True when meta was added.
		 */
		public static function record_referral_use( $order ) {
			$ref = self::find_referral_coupon_in_order( $order );
			if ( ! $ref ) {
				return false;
			}

			$coupon            = $ref['coupon'];
			$code              = $ref['code'];
			$referrer_order_id = absint( $coupon->get_meta( self::CMETA_REFERRER_ORDER, true ) );
			$referrer_email    = (string) $coupon->get_meta( self::CMETA_REFERRER_EMAIL, true );
			$referrer_tl       = (string) $coupon->get_meta( self::CMETA_REFERRER_TL, true );
			$buyer_email       = (string) $order->get_billing_email();
			$buyer_tl          = self::get_field_value( $order, self::FIELD_TRADE_LICENCE );

			$is_self = self::is_self_referral( $buyer_email, $buyer_tl, $referrer_email, $referrer_tl )
				|| ( $referrer_order_id > 0 && $referrer_order_id === (int) $order->get_id() );
			$status  = $is_self ? self::STATUS_SELF : self::STATUS_PENDING;

			$order->update_meta_data( self::META_REFERRED_BY, $code );
			$order->update_meta_data( self::META_REFERRER_ORDER, $referrer_order_id );
			$order->update_meta_data( self::META_STATUS, $status );

			$referrer_order  = ( $referrer_order_id && function_exists( 'wc_get_order' ) ) ? wc_get_order( $referrer_order_id ) : null;
			$referrer_number = ( $referrer_order instanceof \WC_Order ) ? (string) $referrer_order->get_order_number() : (string) $referrer_order_id;

			if ( $is_self ) {
				/* translators: 1: referral code, 2: referrer order number. */
				$note = sprintf( __( 'Ledger Plus referral: code %1$s (from order #%2$s) was used, but the buyer has the same billing email or trade licence as the referrer. Status: ineligible_self. No reward is due.', 'ledgerplus-shop' ), $code, $referrer_number );
			} else {
				/* translators: 1: referral code, 2: referrer order number. */
				$note = sprintf( __( 'Ledger Plus referral: code %1$s (from order #%2$s) was used. Status: pending_review. Check the referral before any reward is paid. Nothing is paid automatically.', 'ledgerplus-shop' ), $code, $referrer_number );
			}
			$order->add_order_note( $note );

			if ( $referrer_order instanceof \WC_Order ) {
				$referrer_order->add_order_note(
					/* translators: 1: referral code, 2: new order number, 3: status. */
					sprintf( __( 'Ledger Plus referral: this customer’s code %1$s was used on order #%2$s. Status: %3$s. Review it before paying any reward; nothing is paid automatically.', 'ledgerplus-shop' ), $code, $order->get_order_number(), $status )
				);
			}

			return true;
		}

		/**
		 * F3e. Flags a possible existing customer (same billing email or trade licence on another paid order).
		 *
		 * @param \WC_Order $order Order.
		 * @return int[] Matching order IDs.
		 */
		public static function flag_possible_repeat( $order ) {
			$tl   = self::get_field_value( $order, self::FIELD_TRADE_LICENCE );
			$norm = self::normalise_trade_licence( $tl );
			if ( '' !== $norm ) {
				$order->update_meta_data( self::META_TL_NORM, $norm );
			}

			$matches = self::find_other_paid_orders( $order, (string) $order->get_billing_email(), $tl );
			$order->update_meta_data( self::META_REPEAT_CHECKED, 'yes' );

			if ( $matches ) {
				$numbers = array();
				foreach ( array_slice( $matches, 0, 5 ) as $match_id ) {
					$match     = wc_get_order( $match_id );
					$numbers[] = ( $match instanceof \WC_Order ) ? (string) $match->get_order_number() : (string) $match_id;
				}
				$order->update_meta_data( self::META_REPEAT, 'yes' );
				$order->update_meta_data( self::META_REPEAT_ORDERS, implode( ',', $matches ) );
				/* translators: %s: order number(s). */
				$order->add_order_note( sprintf( __( 'Possible existing customer: order #%s', 'ledgerplus-shop' ), implode( ', #', $numbers ) ) );
			}

			return $matches;
		}

		/**
		 * Other paid orders with the same billing email or trade licence. HPOS-safe (wc_get_orders only).
		 *
		 * HPOS: billing_email, exclude, status and meta_query are supported
		 * (src/Internal/DataStores/Orders/OrdersTableQuery.php:275,314,706,810,1154,1178).
		 * Posts storage ignores a meta_query argument (includes/data-stores/class-wc-data-store-wp.php:285),
		 * so trade licence matches are always re-checked in PHP through the field API.
		 *
		 * @param \WC_Order $order Order.
		 * @param string    $email Billing email.
		 * @param string    $tl    Trade licence.
		 * @return int[]
		 */
		public static function find_other_paid_orders( $order, $email, $tl ) {
			if ( ! function_exists( 'wc_get_orders' ) ) {
				return array();
			}
			$ids      = array();
			$base     = array(
				'type'    => 'shop_order',
				'status'  => array( 'wc-processing', 'wc-completed' ),
				'exclude' => array( (int) $order->get_id() ),
				'orderby' => 'date',
				'order'   => 'ASC',
			);
			$email    = trim( (string) $email );
			$tl_clean = self::sanitize_trade_licence( $tl );
			$norm     = self::normalise_trade_licence( $tl );

			if ( '' !== $email ) {
				$found = wc_get_orders( array_merge( $base, array( 'billing_email' => $email, 'limit' => 5, 'return' => 'ids' ) ) );
				foreach ( (array) $found as $id ) {
					$ids[] = (int) ( is_object( $id ) && method_exists( $id, 'get_id' ) ? $id->get_id() : $id );
				}
			}

			if ( '' !== $norm ) {
				$found = wc_get_orders(
					array_merge(
						$base,
						array(
							'limit'      => 20,
							'return'     => 'objects',
							'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
								'relation' => 'OR',
								array(
									'key'   => self::FIELD_META_PREFIX . self::FIELD_TRADE_LICENCE,
									'value' => $tl_clean,
								),
								array(
									'key'   => self::META_TL_NORM,
									'value' => $norm,
								),
							),
						)
					)
				);
				foreach ( (array) $found as $other ) {
					if ( $other instanceof \WC_Order && (int) $other->get_id() !== (int) $order->get_id()
						&& self::normalise_trade_licence( self::get_field_value( $other, self::FIELD_TRADE_LICENCE ) ) === $norm ) {
						$ids[] = (int) $other->get_id();
					}
				}
			}

			$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
			return array_values( array_diff( $ids, array( (int) $order->get_id() ) ) );
		}

		/* ---------- F3c follow-up. Cancelled and refunded orders ---------- */

		/**
		 * True for void_cancelled / void_refunded.
		 *
		 * @param string $status Referral status.
		 * @return bool
		 */
		public static function is_void_status( $status ) {
			return in_array( (string) $status, array( self::STATUS_VOID_CANCELLED, self::STATUS_VOID_REFUNDED ), true );
		}

		/**
		 * woocommerce_order_status_cancelled ( $order_id, $order ).
		 *
		 * @param int            $order_id Order ID.
		 * @param \WC_Order|null $order    Order.
		 */
		public static function on_order_cancelled( $order_id, $order = null ) {
			self::void_referral( $order_id, $order, 'cancelled' );
		}

		/**
		 * woocommerce_order_status_refunded ( $order_id, $order ).
		 *
		 * @param int            $order_id Order ID.
		 * @param \WC_Order|null $order    Order.
		 */
		public static function on_order_refunded( $order_id, $order = null ) {
			self::void_referral( $order_id, $order, 'refunded' );
		}

		/**
		 * woocommerce_order_fully_refunded ( $order_id, $refund_id ). Also covers shops that filter
		 * woocommerce_order_fully_refunded_status so the status does not become "refunded"
		 * (includes/wc-order-functions.php:748).
		 *
		 * @param int $order_id  Order ID.
		 * @param int $refund_id Refund ID.
		 */
		public static function on_order_fully_refunded( $order_id, $refund_id = 0 ) {
			self::void_referral( $order_id, null, 'refunded' );
		}

		/**
		 * woocommerce_order_partially_refunded ( $order_id, $refund_id ): note only, the referral
		 * status is not changed (a person decides).
		 *
		 * @param int $order_id  Order ID.
		 * @param int $refund_id Refund ID.
		 */
		public static function on_order_partially_refunded( $order_id, $refund_id = 0 ) {
			try {
				$order = self::fresh_order( $order_id );
				if ( ! $order || ! self::order_has_product( $order ) ) {
					return;
				}
				$status = (string) $order->get_meta( self::META_STATUS, true );
				$code   = (string) $order->get_meta( self::META_REFERRED_BY, true );
				if ( self::STATUS_PENDING === $status && '' !== $code ) {
					$order->add_order_note( __( 'Ledger Plus referral: this order was partly refunded. The referral stays pending_review; check the refund before any reward is paid.', 'ledgerplus-shop' ) );
					$referrer = self::fresh_order( absint( $order->get_meta( self::META_REFERRER_ORDER, true ) ) );
					if ( $referrer ) {
						/* translators: %s: order number. */
						$referrer->add_order_note( sprintf( __( 'Ledger Plus referral: order #%s, which used this customer’s code, was partly refunded. Check it before any reward is paid.', 'ledgerplus-shop' ), $order->get_order_number() ) );
					}
				}
			} catch ( \Throwable $e ) {
				self::log( 'Partial refund check failed for order ' . absint( $order_id ) . ': ' . $e->getMessage() );
			}
		}

		/**
		 * Marks the referral of a cancelled or refunded order as void and notes it on both orders.
		 * When the order is itself a referrer, notes that its code no longer works and lists the
		 * referred orders still pending review. Never pays or reverses anything.
		 *
		 * Works on a fresh copy read from the database (the hook's object can be older than a save
		 * made earlier in the same refund, wc-order-functions.php:735-750), then mirrors the meta onto
		 * the hook's object.
		 *
		 * @param int            $order_id Order ID.
		 * @param \WC_Order|null $order    Order passed by the hook, if any.
		 * @param string         $reason   "cancelled" or "refunded".
		 */
		public static function void_referral( $order_id, $order, $reason ) {
			try {
				$reason = ( 'refunded' === $reason ) ? 'refunded' : 'cancelled';
				$fresh  = self::fresh_order( $order_id, $order );
				$target = $fresh ? $fresh : ( ( $order instanceof \WC_Order ) ? $order : null );
				if ( ! $target || ! self::order_has_product( $target ) ) {
					return;
				}

				$changed = array();
				$label   = ( 'refunded' === $reason ) ? __( 'refunded', 'ledgerplus-shop' ) : __( 'cancelled', 'ledgerplus-shop' );

				// This order used someone's code.
				$status = (string) $target->get_meta( self::META_STATUS, true );
				$used   = (string) $target->get_meta( self::META_REFERRED_BY, true );
				if ( self::STATUS_PENDING === $status ) {
					$new = ( 'refunded' === $reason ) ? self::STATUS_VOID_REFUNDED : self::STATUS_VOID_CANCELLED;
					$target->update_meta_data( self::META_STATUS, $new );
					$changed[ self::META_STATUS ] = $new;

					$referrer        = self::fresh_order( absint( $target->get_meta( self::META_REFERRER_ORDER, true ) ) );
					$referrer_number = $referrer ? (string) $referrer->get_order_number() : (string) absint( $target->get_meta( self::META_REFERRER_ORDER, true ) );

					$target->add_order_note(
						/* translators: 1: cancelled/refunded, 2: referral code, 3: referrer order number, 4: new status. */
						sprintf( __( 'Ledger Plus referral: this order was %1$s, so the referral from code %2$s (order #%3$s) is void. Status: %4$s. No reward is due for it.', 'ledgerplus-shop' ), $label, $used, $referrer_number, $new )
					);
					if ( $referrer ) {
						$referrer->add_order_note(
							/* translators: 1: order number, 2: cancelled/refunded, 3: new status. */
							sprintf( __( 'Ledger Plus referral: order #%1$s, which used this customer’s code, was %2$s. Referral status: %3$s. No reward is due for it.', 'ledgerplus-shop' ), $target->get_order_number(), $label, $new )
						);
					}
				}

				// This order is a referrer: its code stops working while the order is not paid.
				$own = (string) $target->get_meta( self::META_CODE, true );
				if ( '' !== $own && $reason !== (string) $target->get_meta( self::META_CODE_INACTIVE, true ) ) {
					$target->update_meta_data( self::META_CODE_INACTIVE, $reason );
					$changed[ self::META_CODE_INACTIVE ] = $reason;

					$s       = self::settings();
					$pending = self::find_referred_orders( (int) $target->get_id(), self::STATUS_PENDING );
					$numbers = array();
					foreach ( $pending as $referred ) {
						$numbers[] = '#' . $referred->get_order_number();
						$referred->add_order_note(
							/* translators: 1: referrer order number, 2: cancelled/refunded. */
							sprintf( __( 'Ledger Plus referral: the referrer’s order #%1$s was %2$s. Review this referral before any reward is paid.', 'ledgerplus-shop' ), $target->get_order_number(), $label )
						);
					}
					$code_text = ! empty( $s['require_paid_referrer'] )
						/* translators: %s: referral code. */
						? sprintf( __( 'Its referral code %s no longer works for new orders.', 'ledgerplus-shop' ), $own )
						/* translators: %s: referral code. */
						: sprintf( __( 'Its referral code %s still works for new orders (require_paid_referrer is off).', 'ledgerplus-shop' ), $own );
					$list_text = $numbers
						/* translators: %s: order numbers. */
						? sprintf( __( 'Referred orders still pending review: %s. Decide on those rewards by hand.', 'ledgerplus-shop' ), implode( ', ', $numbers ) )
						: __( 'No referred orders are pending review.', 'ledgerplus-shop' );
					/* translators: 1: cancelled/refunded, 2: code sentence, 3: referred orders sentence. */
					$target->add_order_note( sprintf( __( 'Ledger Plus: this order was %1$s. %2$s %3$s', 'ledgerplus-shop' ), $label, $code_text, $list_text ) );
				}

				if ( ! $changed ) {
					return;
				}
				if ( $target !== $order ) {
					// Inside a status hook the transition flag is already reset, so this save does not
					// fire the hooks again (class-wc-order.php:436-441).
					$target->save();
					if ( $order instanceof \WC_Order ) {
						foreach ( $changed as $key => $value ) {
							$order->update_meta_data( $key, $value );
						}
					}
				} else {
					$order->save();
				}
			} catch ( \Throwable $e ) {
				self::log( 'Cancel/refund check failed for order ' . absint( $order_id ) . ': ' . $e->getMessage() );
			}
		}

		/**
		 * Orders that used this referrer order's code, optionally with one referral status.
		 * HPOS supports meta_query (OrdersTableQuery.php:313-330); posts storage ignores it
		 * (class-wc-data-store-wp.php:285), so the meta is re-checked here.
		 *
		 * @param int    $referrer_order_id Referrer order ID.
		 * @param string $status            Referral status to match ('' = any).
		 * @return \WC_Order[]
		 */
		public static function find_referred_orders( $referrer_order_id, $status = '' ) {
			if ( ! function_exists( 'wc_get_orders' ) || $referrer_order_id <= 0 ) {
				return array();
			}
			$meta_query = array(
				'relation' => 'AND',
				array(
					'key'   => self::META_REFERRER_ORDER,
					'value' => (string) absint( $referrer_order_id ),
				),
			);
			if ( '' !== $status ) {
				$meta_query[] = array(
					'key'   => self::META_STATUS,
					'value' => (string) $status,
				);
			}
			$found = wc_get_orders(
				array(
					'type'       => 'shop_order',
					'limit'      => 20,
					'orderby'    => 'date',
					'order'      => 'ASC',
					'return'     => 'objects',
					'exclude'    => array( absint( $referrer_order_id ) ),
					'meta_query' => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				)
			);
			$out = array();
			foreach ( (array) $found as $o ) {
				if ( $o instanceof \WC_Order
					&& absint( $o->get_meta( self::META_REFERRER_ORDER, true ) ) === absint( $referrer_order_id )
					&& ( '' === $status || (string) $o->get_meta( self::META_STATUS, true ) === $status ) ) {
					$out[] = $o;
				}
			}
			return $out;
		}

		/* ---------- F3f follow-up. Admin notice when an on-hold order is paid ---------- */

		/**
		 * woocommerce_order_status_changed ( $order_id, $from, $to, $order ).
		 * Sends the admin "Ledger Plus checks" once when an order that was on-hold (so its "New order"
		 * email went out before payment) becomes processing or completed. Uses the "New order" email's
		 * recipient and only when that email is enabled (WC_Email::get_recipient()/is_enabled(),
		 * class-wc-email.php:635,868; WC_Emails::wrap_message()/send(), class-wc-emails.php:454,493).
		 *
		 * @param int            $order_id Order ID.
		 * @param string         $from     Old status.
		 * @param string         $to       New status.
		 * @param \WC_Order|null $order    Order.
		 */
		public static function maybe_send_admin_paid_notice( $order_id, $from = '', $to = '', $order = null ) {
			if ( 'on-hold' !== $from || ! in_array( $to, array( 'processing', 'completed' ), true ) ) {
				return;
			}
			try {
				$s = self::settings();
				if ( empty( $s['admin_paid_notice'] ) ) {
					return;
				}
				if ( ! ( $order instanceof \WC_Order ) ) {
					$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
				}
				if ( ! ( $order instanceof \WC_Order ) || ! self::order_has_product( $order ) || 'yes' === $order->get_meta( self::META_ADMIN_NOTICE, true ) ) {
					return;
				}
				$mailer = WC()->mailer();
				$emails = ( is_object( $mailer ) && method_exists( $mailer, 'get_emails' ) ) ? $mailer->get_emails() : array();
				$new    = $emails['WC_Email_New_Order'] ?? null;
				if ( ! is_object( $new ) || ! method_exists( $new, 'is_enabled' ) || ! $new->is_enabled() ) {
					return;
				}
				$recipient = (string) $new->get_recipient();
				if ( '' === $recipient ) {
					return;
				}

				$heading = __( 'Payment confirmed: Ledger Plus checks', 'ledgerplus-shop' );
				/* translators: %s: order number. */
				$subject = sprintf( __( 'Payment confirmed for order #%s (Ledger Plus checks)', 'ledgerplus-shop' ), $order->get_order_number() );
				/* translators: %s: order number. */
				$body = '<p>' . esc_html( sprintf( __( 'Order #%s was on hold when the "New order" email was sent. It is now paid. Here are the checks:', 'ledgerplus-shop' ), $order->get_order_number() ) ) . '</p>';
				if ( self::email_branding_enabled() ) {
					$body .= self::email_checks_card_html( $order, self::admin_summary_rows( $order ) );
				} else {
					$body .= '<ul>';
					foreach ( self::admin_summary_rows( $order ) as $row ) {
						$body .= '<li><strong>' . esc_html( $row[0] ) . ':</strong> ' . esc_html( $row[1] ) . '</li>';
					}
					$body .= '</ul>';
				}
				if ( method_exists( $order, 'get_edit_order_url' ) ) {
					$body .= '<p><a href="' . esc_url( $order->get_edit_order_url() ) . '">' . esc_html__( 'Open the order', 'ledgerplus-shop' ) . '</a></p>';
				}

				$sent = $mailer->send( $recipient, $subject, $mailer->wrap_message( $heading, $body ) );
				if ( $sent ) {
					$order->update_meta_data( self::META_ADMIN_NOTICE, 'yes' );
					$order->save_meta_data();
				}
			} catch ( \Throwable $e ) {
				self::log( 'Admin paid notice failed for order ' . absint( $order_id ) . ': ' . $e->getMessage() );
			}
		}

		/* ---------- F3d. Early self-referral block ---------- */

		/**
		 * woocommerce_coupon_is_valid ( $valid, $coupon, $discounts ) (class-wc-discounts.php:1280).
		 *
		 * Block checkout: Checkout::process_order() saves the posted billing email and contact
		 * fields to WC()->customer (src/StoreApi/Routes/V1/Checkout.php:600, 952-1021) and then
		 * re-validates every cart coupon (Checkout.php:613 -> CartController.php:482, 762-788,
		 * 1073-1088 -> WC_Coupon::is_valid(), class-wc-coupon.php:993-1003), so this filter sees the
		 * final email and trade licence before the order is placed.
		 *
		 * @param bool       $valid     Valid so far.
		 * @param \WC_Coupon $coupon    Coupon.
		 * @param object     $discounts WC_Discounts.
		 * @return bool
		 */
		public static function filter_coupon_is_valid( $valid, $coupon = null, $discounts = null ) {
			if ( ! $valid || ! self::is_referral_coupon( $coupon ) ) {
				return $valid;
			}
			try {
				$key      = function_exists( 'wc_strtolower' ) ? wc_strtolower( (string) $coupon->get_code() ) : strtolower( (string) $coupon->get_code() );
				$settings = self::settings();

				if ( ! empty( $settings['require_paid_referrer'] ) && function_exists( 'wc_get_order' ) ) {
					$referrer_order = wc_get_order( absint( $coupon->get_meta( self::CMETA_REFERRER_ORDER, true ) ) );
					if ( ! ( $referrer_order instanceof \WC_Order ) || ! $referrer_order->is_paid() ) {
						self::$coupon_errors[ $key ] = __( 'This referral code is no longer active.', 'ledgerplus-shop' );
						return false;
					}
				}

				// One referral code per order (the coupons are not "individual use", so stop a second one here).
				$object = ( is_object( $discounts ) && method_exists( $discounts, 'get_object' ) ) ? $discounts->get_object() : null;
				if ( $object instanceof \WC_Cart && method_exists( $object, 'get_applied_coupons' ) ) {
					foreach ( (array) $object->get_applied_coupons() as $applied ) {
						$applied = (string) $applied;
						if ( '' === $applied || strtolower( $applied ) === $key ) {
							continue;
						}
						if ( self::is_referral_coupon( new \WC_Coupon( $applied ) ) ) {
							self::$coupon_errors[ $key ] = __( 'Only one referral code can be used per order.', 'ledgerplus-shop' );
							return false;
						}
					}
				}

				$identity = self::current_buyer_identity( $discounts );
				$ref_mail = (string) $coupon->get_meta( self::CMETA_REFERRER_EMAIL, true );
				$ref_tl   = (string) $coupon->get_meta( self::CMETA_REFERRER_TL, true );

				$self = false;
				foreach ( $identity['emails'] as $email ) {
					if ( self::is_self_referral( $email, '', $ref_mail, '' ) ) {
						$self = true;
						break;
					}
				}
				if ( ! $self && '' !== $identity['trade_licence'] ) {
					$self = self::is_self_referral( '', $identity['trade_licence'], '', $ref_tl );
				}

				if ( $self ) {
					self::$coupon_errors[ $key ] = __( 'This is your own referral code. Share it with another business: it cannot be used on your own order.', 'ledgerplus-shop' );
					return false;
				}
				unset( self::$coupon_errors[ $key ] );
			} catch ( \Throwable $e ) {
				self::log( 'Referral coupon check failed: ' . $e->getMessage() );
			}
			return $valid;
		}

		/**
		 * woocommerce_coupon_error ( $message, $code, $coupon ) (class-wc-discounts.php:1291).
		 *
		 * - 100 (E_WC_COUPON_INVALID_FILTERED, our woocommerce_coupon_is_valid refusal): the reason found above.
		 * - 105 (E_WC_COUPON_NOT_EXIST, a mistyped code; WC_Coupon keeps the typed code, class-wc-coupon.php:132-139,
		 *   so the Store API reaches this check, CartController.php:955-985 and class-wc-discounts.php:624-635):
		 *   referral wording.
		 * - 109 (E_WC_COUPON_NOT_APPLICABLE, a referral code without product 890 in the cart,
		 *   class-wc-discounts.php:848-856 and class-wc-coupon.php:1216-1221): referral wording.
		 * 105/109 only on the front end (not is_admin(); wc-ajax and Store API requests are front end)
		 * and only while relabel_coupons is on. Codes are shown in upper case (WooCommerce stores them in
		 * lower case, wc-core-functions.php:46).
		 *
		 * @param string     $message Message.
		 * @param int        $code    Error code.
		 * @param \WC_Coupon $coupon  Coupon.
		 * @return string
		 */
		public static function filter_coupon_error( $message, $code = 0, $coupon = null ) {
			if ( ! is_object( $coupon ) || ! method_exists( $coupon, 'get_code' ) ) {
				return $message;
			}
			try {
				$code = (int) $code;
				$raw  = (string) $coupon->get_code();
				if ( 100 === $code ) {
					$key = function_exists( 'wc_strtolower' ) ? wc_strtolower( $raw ) : strtolower( $raw );
					return isset( self::$coupon_errors[ $key ] ) ? self::$coupon_errors[ $key ] : $message;
				}
				if ( ( 105 !== $code && 109 !== $code ) || is_admin() ) {
					return $message;
				}
				$s = self::settings();
				if ( empty( $s['relabel_coupons'] ) ) {
					return $message;
				}
				$shown = function_exists( 'wc_strtoupper' ) ? wc_strtoupper( $raw ) : strtoupper( $raw );
				if ( 105 === $code ) {
					/* translators: %s: code the shopper typed. */
					return sprintf( __( 'Referral code "%s" was not found. Please check it and try again.', 'ledgerplus-shop' ), $shown );
				}
				if ( self::is_referral_coupon( $coupon ) ) {
					$product = function_exists( 'wc_get_product' ) ? wc_get_product( self::product_id() ) : null;
					$name    = ( is_object( $product ) && method_exists( $product, 'get_name' ) && '' !== (string) $product->get_name() ) ? (string) $product->get_name() : 'Ledger Plus';
					/* translators: 1: referral code, 2: product name. */
					return sprintf( __( 'Referral code "%1$s" only works with the %2$s.', 'ledgerplus-shop' ), $shown, $name );
				}
			} catch ( \Throwable $e ) {
				self::log( 'Coupon error wording failed: ' . $e->getMessage() );
			}
			return $message;
		}

		/**
		 * Emails and trade licence of the current buyer, as far as they are known.
		 *
		 * @param object $discounts WC_Discounts (get_object(), class-wc-discounts.php:136).
		 * @return array { emails: string[], trade_licence: string }
		 */
		private static function current_buyer_identity( $discounts ) {
			$emails = array();
			$tl     = '';
			$object = ( is_object( $discounts ) && method_exists( $discounts, 'get_object' ) ) ? $discounts->get_object() : null;

			if ( $object instanceof \WC_Cart ) {
				// Same sources WooCommerce uses for email restrictions (class-wc-discounts.php:1189-1196).
				if ( function_exists( 'is_user_logged_in' ) && is_user_logged_in() ) {
					$user = wp_get_current_user();
					if ( $user && ! empty( $user->user_email ) ) {
						$emails[] = (string) $user->user_email;
					}
				}
				$customer = method_exists( $object, 'get_customer' ) ? $object->get_customer() : null;
				if ( $customer instanceof \WC_Customer ) {
					$emails[] = (string) $customer->get_billing_email();
					$tl       = self::get_field_value( $customer, self::FIELD_TRADE_LICENCE );
				}
			} elseif ( $object instanceof \WC_Order ) {
				$emails[] = (string) $object->get_billing_email();
				$tl       = self::get_field_value( $object, self::FIELD_TRADE_LICENCE );
			}

			return array(
				'emails'        => array_values( array_unique( array_filter( $emails ) ) ),
				'trade_licence' => $tl,
			);
		}

		/* ---------- F3g. Success message ---------- */

		/**
		 * woocommerce_coupon_message ( $msg, $msg_code, $coupon ) (class-wc-coupon.php:1109).
		 * The Store API does not use this message (CartController::apply_coupon(),
		 * CartController.php:951-1061, adds no notice); the block shows its own snackbar,
		 * relabelled in F4.
		 *
		 * @param string     $message Message.
		 * @param int        $code    Message code.
		 * @param \WC_Coupon $coupon  Coupon.
		 * @return string
		 */
		public static function filter_coupon_message( $message, $code = 0, $coupon = null ) {
			if ( 200 === (int) $code && self::is_referral_coupon( $coupon ) ) {
				return self::referral_accepted_text();
			}
			return $message;
		}

		/**
		 * "Referral code accepted. Your price stays AED 1,999."
		 *
		 * @return string
		 */
		public static function referral_accepted_text() {
			$s = self::settings();
			/* translators: %s: price, e.g. AED 1,999. */
			return sprintf( __( 'Referral code accepted. Your price stays %s.', 'ledgerplus-shop' ), $s['price_text'] );
		}

		/* ==================================================================
		 * F3b / F7. Output
		 * ================================================================== */

		/**
		 * The customer's referral sentence as HTML.
		 *
		 * @param string $code Code.
		 * @return string
		 */
		public static function referral_sentence_html( $code ) {
			$s = self::settings();
			return sprintf(
				/* translators: 1: referral code, 2: reward text, 3: reward terms. */
				esc_html__( 'Your referral code: %1$s. Share it with another business. When they buy Ledger Plus with your code, %2$s %3$s', 'ledgerplus-shop' ),
				'<strong class="lp-shop-referral__code">' . esc_html( $code ) . '</strong>',
				esc_html( $s['reward_text'] ),
				esc_html( $s['reward_terms'] )
			);
		}

		/**
		 * The customer's referral sentence as plain text.
		 *
		 * @param string $code Code.
		 * @return string
		 */
		public static function referral_sentence_text( $code ) {
			$s = self::settings();
			return sprintf(
				/* translators: 1: referral code, 2: reward text, 3: reward terms. */
				__( 'Your referral code: %1$s. Share it with another business. When they buy Ledger Plus with your code, %2$s %3$s', 'ledgerplus-shop' ),
				$code,
				$s['reward_text'],
				$s['reward_terms']
			);
		}

		/**
		 * "Need help? Call or WhatsApp +971 50 342 0832." with links.
		 *
		 * @return string HTML.
		 */
		public static function help_line_html() {
			$s    = self::settings();
			$tel  = 'tel:' . preg_replace( '/[^0-9+]/', '', $s['phone_tel'] );
			$call = '<a class="lp-shop-help__link" href="' . esc_url( $tel ) . '">' . esc_html__( 'Call', 'ledgerplus-shop' ) . '</a>';
			$wa   = '<a class="lp-shop-help__link" href="' . esc_url( $s['whatsapp_url'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'WhatsApp', 'ledgerplus-shop' ) . '</a>';
			$num  = '<a class="lp-shop-help__phone" href="' . esc_url( $tel ) . '">' . esc_html( $s['phone_display'] ) . '</a>';
			/* translators: 1: "Call" link, 2: "WhatsApp" link, 3: phone number link. */
			return '<p class="lp-shop-help">' . sprintf( esc_html__( 'Need help? %1$s or %2$s %3$s.', 'ledgerplus-shop' ), $call, $wa, $num ) . '</p>';
		}

		/**
		 * F7. The three "What happens next" steps (thank-you page and, with F11, the Processing order email).
		 *
		 * @return string[]
		 */
		public static function next_steps() {
			return array(
				__( 'Our team contacts you to create your Ledger Plus login.', 'ledgerplus-shop' ),
				__( 'We move your data into Ledger Plus.', 'ledgerplus-shop' ),
				__( 'We book your free onboarding.', 'ledgerplus-shop' ),
			);
		}

		/**
		 * F7. "What happens next" block.
		 *
		 * @param bool $awaiting_payment Order not paid yet: the steps start "Once your payment is confirmed:".
		 * @return string HTML.
		 */
		public static function next_steps_html( $awaiting_payment = false ) {
			$steps = self::next_steps();
			$html  = '<section class="lp-shop-next" aria-labelledby="lp-shop-next-title">';
			$html .= '<h2 class="lp-shop-next__title" id="lp-shop-next-title">' . esc_html__( 'What happens next', 'ledgerplus-shop' ) . '</h2>';
			if ( $awaiting_payment ) {
				$html .= '<p class="lp-shop-next__intro">' . esc_html__( 'Once your payment is confirmed:', 'ledgerplus-shop' ) . '</p>';
			}
			$html .= '<ol class="lp-shop-next__steps">';
			foreach ( $steps as $i => $step ) {
				$html .= '<li class="lp-shop-next__step"><span class="lp-shop-next__num" aria-hidden="true">' . esc_html( (string) ( $i + 1 ) ) . '</span> <span class="lp-shop-next__text">' . esc_html( $step ) . '</span></li>';
			}
			$html .= '</ol>';
			$html .= self::help_line_html();
			$html .= '</section>';
			return $html;
		}

		/**
		 * Referral box HTML for the thank-you page / My Account.
		 *
		 * @param string[] $codes Codes.
		 * @param string   $extra_class Extra class.
		 * @return string
		 */
		public static function referral_box_html( $codes, $extra_class = '' ) {
			$codes = array_values( array_filter( array_map( 'strval', (array) $codes ) ) );
			if ( ! $codes ) {
				return '';
			}
			$class = 'lp-shop-referral' . ( $extra_class ? ' ' . $extra_class : '' );
			$html  = '<section class="' . esc_attr( $class ) . '" aria-label="' . esc_attr__( 'Your referral code', 'ledgerplus-shop' ) . '">';
			foreach ( $codes as $code ) {
				$html .= '<p class="lp-shop-referral__text" data-lp-code="' . esc_attr( $code ) . '">' . self::referral_sentence_html( $code ) . '</p>';
			}
			$html .= '</section>';
			return $html;
		}

		/**
		 * F3b + F7. Thank-you page (classic template; the checkout block on a classic theme renders
		 * the [woocommerce_checkout] shortcode on the order-received endpoint, Checkout.php:231-236,
		 * which loads checkout/thankyou.php, class-wc-shortcode-checkout.php:388).
		 * templates/checkout/thankyou.php:97 fires woocommerce_thankyou for every order status, so:
		 *   - paid (processing/completed): steps + the buyer's code (or "being prepared");
		 *   - pending / on-hold: steps introduced by "Once your payment is confirmed:" + code later;
		 *   - failed, cancelled, refunded and anything else: only the help line.
		 *
		 * @param int $order_id Order ID.
		 */
		public static function render_thankyou( $order_id ) {
			try {
				$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
				if ( ! ( $order instanceof \WC_Order ) || ! self::order_has_product( $order ) ) {
					return;
				}

				if ( $order->is_paid() ) {
					// Read the meta from the database: the code may have been saved by the webhook request.
					$fresh = self::fresh_order( $order->get_id() );
					$order = $fresh ? $fresh : $order;
					$code  = (string) $order->get_meta( self::META_CODE, true );
					if ( '' === $code && self::thankyou_fallback_due( $order ) ) {
						// Paid before this plugin was active, or the status hook failed: same idempotent work.
						// Not for a fresh payment: its status hook may still be running in another request.
						self::process_paid_order( $order, 0 );
						$code = (string) $order->get_meta( self::META_CODE, true );
					}
					$html  = '<div class="lp-shop lp-shop-thankyou lp-shop-thankyou--paid">' . self::next_steps_html();
					$html .= ( '' !== $code )
						? self::referral_box_html( array( $code ) )
						: '<p class="lp-shop-referral lp-shop-referral--pending">' . esc_html__( 'Your referral code is being prepared. Refresh this page in a minute to see it.', 'ledgerplus-shop' ) . '</p>';
				} elseif ( $order->has_status( array( 'pending', 'on-hold' ) ) ) {
					$html  = '<div class="lp-shop lp-shop-thankyou lp-shop-thankyou--awaiting">' . self::next_steps_html( true );
					$html .= '<p class="lp-shop-referral lp-shop-referral--pending">' . esc_html__( 'Your referral code will appear here and in your confirmation email once your payment is confirmed.', 'ledgerplus-shop' ) . '</p>';
				} else {
					$html = '<div class="lp-shop lp-shop-thankyou lp-shop-thankyou--closed">' . self::help_line_html();
				}

				$html .= '</div>';
				echo wp_kses_post( $html );
			} catch ( \Throwable $e ) {
				self::log( 'Thank-you block failed: ' . $e->getMessage() );
			}
		}

		/**
		 * True when the order was paid at least THANKYOU_FALLBACK_AFTER seconds ago (or the paid date
		 * is unknown), so the status hook has had its chance. WC_Order::get_date_paid() (class-wc-order.php:960)
		 * is set on the transition to a paid status (class-wc-order.php:334, 355-376).
		 *
		 * @param \WC_Order $order Order.
		 * @return bool
		 */
		private static function thankyou_fallback_due( $order ) {
			$paid = method_exists( $order, 'get_date_paid' ) ? $order->get_date_paid() : null;
			if ( ! is_object( $paid ) || ! method_exists( $paid, 'getTimestamp' ) ) {
				return true;
			}
			return ( time() - (int) $paid->getTimestamp() ) >= self::THANKYOU_FALLBACK_AFTER;
		}

		/**
		 * F3b. My Account dashboard: codes from the logged-in user's paid orders.
		 */
		public static function render_account_codes() {
			if ( ! function_exists( 'is_user_logged_in' ) || ! is_user_logged_in() || ! function_exists( 'wc_get_orders' ) ) {
				return;
			}
			try {
				$orders = wc_get_orders(
					array(
						'type'        => 'shop_order',
						'customer_id' => get_current_user_id(),
						'status'      => array( 'wc-processing', 'wc-completed' ),
						'limit'       => 20,
						'orderby'     => 'date',
						'order'       => 'DESC',
						'return'      => 'objects',
					)
				);
				$codes  = array();
				foreach ( (array) $orders as $order ) {
					if ( $order instanceof \WC_Order ) {
						$code = strtoupper( (string) $order->get_meta( self::META_CODE, true ) );
						if ( '' !== $code ) {
							$codes[ $code ] = $code;
						}
					}
				}
				if ( $codes ) {
					echo wp_kses_post( '<div class="lp-shop lp-shop-account">' . self::referral_box_html( array_values( $codes ), 'lp-shop-referral--account' ) . '</div>' );
				}
			} catch ( \Throwable $e ) {
				self::log( 'My Account block failed: ' . $e->getMessage() );
			}
		}

		/**
		 * F3b + F3f (+ F11). woocommerce_email_order_meta ( $order, $sent_to_admin, $plain_text, $email ).
		 *
		 * Email branding off: the v1.0.0 output. On: the "What happens next" card (Processing order
		 * email only), the "Your referral code" card and, for the admin, the "Ledger Plus checks" card.
		 * In the WooCommerce email preview (WooCommerce > Settings > Emails), whose dummy order has no
		 * Ledger Plus product, the branded cards are shown with a sample code so the design can be checked.
		 *
		 * @param \WC_Order $order         Order.
		 * @param bool      $sent_to_admin Sent to admin.
		 * @param bool      $plain_text    Plain text.
		 * @param object    $email         WC_Email.
		 */
		public static function render_email_block( $order, $sent_to_admin = false, $plain_text = false, $email = null ) {
			if ( ! ( $order instanceof \WC_Order ) || ! is_object( $email ) || empty( $email->id ) || ! is_string( $email->id ) ) {
				return;
			}
			try {
				$branded = self::email_branding_enabled();
				$sample  = false;
				if ( ! self::order_has_product( $order ) ) {
					if ( ! $branded || ! self::is_email_preview() ) {
						return;
					}
					$sample = true;
				}
				$id = (string) $email->id;
				// Email ids: class-wc-email-customer-processing-order.php:30, -completed-order.php:30, -new-order.php:32.
				if ( 'customer_processing_order' === $id || 'customer_completed_order' === $id ) {
					$code = $sample ? self::SAMPLE_CODE : (string) $order->get_meta( self::META_CODE, true );
					if ( ! $branded ) {
						if ( '' === $code ) {
							return;
						}
						if ( $plain_text ) {
							echo "\n" . esc_html( wp_strip_all_tags( self::referral_sentence_text( $code ) ) ) . "\n\n";
						} else {
							echo wp_kses_post( '<div class="lp-shop-email-referral" style="margin:0 0 24px;padding:16px 20px;border:1px solid #dde5f4;border-radius:12px;background:#eaf1fd;color:#030922;"><p style="margin:0;">' . self::referral_sentence_html( $code ) . '</p></div>' );
						}
						return;
					}
					$processing = ( 'customer_processing_order' === $id );
					if ( $plain_text ) {
						$out = $processing ? self::email_next_steps_text() : '';
						if ( '' !== $code ) {
							$out .= "\n" . esc_html( wp_strip_all_tags( self::email_referral_text( $code ) ) ) . "\n\n";
						}
						echo $out; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
						return;
					}
					$html  = $processing ? self::email_next_steps_html() : '';
					$html .= ( '' !== $code ) ? self::email_referral_card_html( $code ) : '';
					echo wp_kses_post( $html );
				} elseif ( 'new_order' === $id ) {
					self::render_admin_email_block( $order, (bool) $plain_text );
				}
			} catch ( \Throwable $e ) {
				self::log( 'Email block failed: ' . $e->getMessage() );
			}
		}

		/**
		 * Rows for the admin email / admin order box.
		 *
		 * @param \WC_Order $order Order.
		 * @return array<int,array{0:string,1:string}>
		 */
		public static function admin_summary_rows( $order ) {
			$tl     = self::get_field_value( $order, self::FIELD_TRADE_LICENCE );
			$trn    = self::get_field_value( $order, self::FIELD_TRN );
			$status = (string) $order->get_meta( self::META_STATUS, true );
			$used   = (string) $order->get_meta( self::META_REFERRED_BY, true );
			$ref_id = absint( $order->get_meta( self::META_REFERRER_ORDER, true ) );

			if ( '' === $used ) {
				// Not paid yet (e.g. on-hold): read the coupon directly.
				$ref = self::find_referral_coupon_in_order( $order );
				if ( $ref ) {
					$used   = $ref['code'];
					$ref_id = absint( $ref['coupon']->get_meta( self::CMETA_REFERRER_ORDER, true ) );
				}
			}

			if ( '' === $used ) {
				$used_text = __( 'None', 'ledgerplus-shop' );
			} else {
				$ref_order  = $ref_id ? wc_get_order( $ref_id ) : null;
				$ref_number = ( $ref_order instanceof \WC_Order ) ? (string) $ref_order->get_order_number() : (string) $ref_id;
				if ( self::STATUS_SELF === $status ) {
					$status_text = __( 'ineligible: same buyer as the referrer', 'ledgerplus-shop' );
				} elseif ( self::STATUS_PENDING === $status ) {
					$status_text = __( 'pending review', 'ledgerplus-shop' );
				} elseif ( self::STATUS_VOID_CANCELLED === $status ) {
					$status_text = __( 'void: this order was cancelled', 'ledgerplus-shop' );
				} elseif ( self::STATUS_VOID_REFUNDED === $status ) {
					$status_text = __( 'void: this order was refunded', 'ledgerplus-shop' );
				} else {
					$status_text = __( 'checked when payment is confirmed', 'ledgerplus-shop' );
				}
				/* translators: 1: code, 2: referrer order number, 3: status. */
				$used_text = sprintf( __( '%1$s (referrer order #%2$s, status: %3$s)', 'ledgerplus-shop' ), $used, $ref_number, $status_text );
			}

			$repeat_text = __( 'No', 'ledgerplus-shop' );
			if ( 'yes' === $order->get_meta( self::META_REPEAT, true ) ) {
				$numbers = array();
				foreach ( array_filter( array_map( 'absint', explode( ',', (string) $order->get_meta( self::META_REPEAT_ORDERS, true ) ) ) ) as $match_id ) {
					$match     = wc_get_order( $match_id );
					$numbers[] = '#' . ( ( $match instanceof \WC_Order ) ? $match->get_order_number() : $match_id );
				}
				/* translators: %s: order numbers. */
				$repeat_text = sprintf( __( 'Yes, see order %s', 'ledgerplus-shop' ), implode( ', ', $numbers ) );
			} elseif ( 'yes' !== $order->get_meta( self::META_REPEAT_CHECKED, true ) ) {
				$repeat_text = __( 'Checked when payment is confirmed', 'ledgerplus-shop' );
			}

			$own = (string) $order->get_meta( self::META_CODE, true );

			return array(
				array( __( 'Trade licence', 'ledgerplus-shop' ), '' !== $tl ? $tl : __( 'Not given', 'ledgerplus-shop' ) ),
				array( __( 'VAT TRN', 'ledgerplus-shop' ), '' !== $trn ? $trn : __( 'Not given', 'ledgerplus-shop' ) ),
				array( __( 'Referral code used', 'ledgerplus-shop' ), $used_text ),
				array( __( 'Possible existing customer', 'ledgerplus-shop' ), $repeat_text ),
				array( __( 'Buyer’s own referral code', 'ledgerplus-shop' ), '' !== $own ? $own : __( 'Created when payment is confirmed', 'ledgerplus-shop' ) ),
			);
		}

		/**
		 * F3f. Admin new-order email block.
		 *
		 * @param \WC_Order $order      Order.
		 * @param bool      $plain_text Plain text.
		 */
		private static function render_admin_email_block( $order, $plain_text ) {
			$rows  = self::admin_summary_rows( $order );
			$title = __( 'Ledger Plus checks', 'ledgerplus-shop' );
			if ( $plain_text ) {
				$out = "\n" . strtoupper( $title ) . "\n";
				foreach ( $rows as $row ) {
					$out .= $row[0] . ': ' . $row[1] . "\n";
				}
				// wp_strip_all_tags() trims, so add the line breaks outside it. Typographic quotes only:
				// WC_Email removes unknown entities such as &#039; from plain text (class-wc-email.php:198-217).
				echo "\n" . esc_html( wp_strip_all_tags( $out ) ) . "\n\n";
				return;
			}
			if ( self::email_branding_enabled() ) {
				echo wp_kses_post( self::email_checks_card_html( $order, $rows ) );
				return;
			}
			$html  = '<div class="lp-shop-email-admin" style="margin:0 0 24px;padding:16px 20px;border:1px solid #dde5f4;border-radius:12px;background:#f6f8fd;color:#030922;">';
			$html .= '<h3 style="margin:0 0 8px;color:#041950;">' . esc_html( $title ) . '</h3><ul style="margin:0;padding:0 0 0 18px;">';
			foreach ( $rows as $row ) {
				$html .= '<li><strong>' . esc_html( $row[0] ) . ':</strong> ' . esc_html( $row[1] ) . '</li>';
			}
			$html .= '</ul></div>';
			echo wp_kses_post( $html );
		}

		/**
		 * Admin order screen summary under the billing address.
		 *
		 * @param \WC_Order $order Order.
		 */
		public static function render_admin_order_box( $order ) {
			if ( ! ( $order instanceof \WC_Order ) ) {
				return;
			}
			try {
				if ( ! self::order_has_product( $order ) ) {
					return;
				}
				$html = '<div class="lp-shop-admin-box"><h4>' . esc_html__( 'Ledger Plus', 'ledgerplus-shop' ) . '</h4>';
				foreach ( self::admin_summary_rows( $order ) as $row ) {
					$html .= '<p><strong>' . esc_html( $row[0] ) . ':</strong> ' . esc_html( $row[1] ) . '</p>';
				}
				$html .= '</div>';
				echo wp_kses_post( $html );
			} catch ( \Throwable $e ) {
				self::log( 'Admin order box failed: ' . $e->getMessage() );
			}
		}

		/* ==================================================================
		 * F11. Email branding
		 *
		 * The WooCommerce emails keep their own templates (no template override). The plugin only:
		 *   1. appends CSS through woocommerce_email_styles, which WooCommerce inlines with Emogrifier
		 *      like its own CSS (class-wc-email.php:972-1004; rules it cannot inline, such as the
		 *      mobile media query, stay in a <style> element);
		 *   2. prints its own cards (what happens next, referral code, admin checks) from
		 *      woocommerce_email_order_meta, with inline, table-based, email-safe styles;
		 *   3. hides the grey placeholder thumbnail when no ordered product has an image
		 *      (woocommerce_email_order_items_args);
		 *   4. warns on WooCommerce > Settings > Emails when WooCommerce's new email templates are off,
		 *      because the CSS is only added with them (admin_notices).
		 * Setting 'email_branding' => false restores the v1.0.0 emails.
		 * ================================================================== */

		/** Code shown on the cards in the WooCommerce email preview, whose dummy order has no code. */
		const SAMPLE_CODE = 'LP-SAMPLE';

		/** Marks the appended CSS so it is never appended twice. */
		const EMAIL_CSS_MARKER = '/* ledgerplus-shop: email branding */';

		/**
		 * Font stack: WooCommerce's "Helvetica" email font (src/Internal/Email/EmailFont.php:27). No web
		 * font: Outlook for Windows shows Times New Roman when the first font of a stack is a web font.
		 */
		const EMAIL_FONT = "'Helvetica Neue', Helvetica, Roboto, Arial, sans-serif";

		/**
		 * True when F11 is on (setting email_branding).
		 *
		 * @return bool
		 */
		public static function email_branding_enabled() {
			$s = self::settings();
			return ! empty( $s['email_branding'] );
		}

		/**
		 * True while WooCommerce renders its email preview (WooCommerce > Settings > Emails). EmailPreview adds
		 * this filter around the render (src/Internal/Admin/EmailPreview/EmailPreview.php:644, 353-371).
		 *
		 * @return bool
		 */
		public static function is_email_preview() {
			return function_exists( 'apply_filters' ) && (bool) apply_filters( 'woocommerce_is_email_preview', false );
		}

		/**
		 * True when WooCommerce uses its new ("email improvements") email templates:
		 * FeaturesUtil::feature_is_enabled( 'email_improvements' ) (src/Internal/Features/FeaturesController.php:513;
		 * read by templates/emails/email-styles.php:25 and email-header.php:24). The feature is opt-in, but
		 * WooCommerce switches it on for stores installed since 9.8.0 (includes/class-wc-install.php:1349-1353).
		 *
		 * @return bool
		 */
		public static function email_improvements_enabled() {
			$util = '\Automattic\WooCommerce\Utilities\FeaturesUtil';
			if ( ! class_exists( $util ) || ! method_exists( $util, 'feature_is_enabled' ) ) {
				return false;
			}
			return (bool) call_user_func( array( $util, 'feature_is_enabled' ), 'email_improvements' );
		}

		/**
		 * The F11 CSS is written for the WooCommerce "email improvements" templates. Block-editor emails
		 * render other markup (class-wc-email.php:1845-1853), so F11 stays out of them.
		 *
		 * @param object|null $email WC_Email.
		 * @return bool
		 */
		private static function email_templates_supported( $email ) {
			if ( is_object( $email ) && ! empty( $email->block_email_editor_enabled ) ) {
				return false;
			}
			return self::email_improvements_enabled();
		}

		/**
		 * The email font: WooCommerce > Settings > Emails > Font family, mapped exactly as
		 * templates/emails/email-styles.php:36, 47-63, 68 does (preview transient, EmailFont::$font list of
		 * safe fonts, Helvetica when the new templates are off). Falls back to EMAIL_FONT.
		 *
		 * @return string CSS font-family value (only names, commas, spaces, quotes and hyphens).
		 */
		public static function email_font() {
			if ( ! self::email_improvements_enabled() || ! function_exists( 'get_option' ) ) {
				return self::EMAIL_FONT;
			}
			$key = get_option( 'woocommerce_email_font_family', 'Helvetica' );
			if ( self::is_email_preview() && function_exists( 'get_transient' ) ) {
				$preview = get_transient( 'woocommerce_email_font_family' );
				$key     = ( is_string( $preview ) && '' !== $preview ) ? $preview : $key;
			}
			$map = '\Automattic\WooCommerce\Internal\Email\EmailFont';
			if ( is_string( $key ) && class_exists( $map ) && isset( $map::$font ) && is_array( $map::$font ) && isset( $map::$font[ $key ] ) ) {
				$font = $map::$font[ $key ];
				if ( is_string( $font ) && preg_match( "/^[A-Za-z0-9 ,'-]+$/", $font ) ) {
					return $font;
				}
			}
			return self::EMAIL_FONT;
		}

		/**
		 * Reward terms for the branded emails. A placeholder (the default "[Reward terms: to confirm]", or
		 * any text wholly in square brackets) is left out, so customers never see an unfinished note. Set
		 * the real terms with LP_SHOP_REWARD_TERMS or the lp_shop_settings filter ('reward_terms').
		 *
		 * @return string Plain text ('' = no terms line).
		 */
		public static function email_reward_terms() {
			$s     = self::settings();
			$terms = trim( (string) $s['reward_terms'] );
			if ( '' === $terms || self::DEFAULT_REWARD_TERMS === $terms || preg_match( '/^\[[^\]]*\]$/', $terms ) ) {
				return '';
			}
			return $terms;
		}

		/**
		 * F11. The referral sentence for the branded plain-text emails (same words as the v1.0.0 sentence,
		 * without a placeholder for the terms).
		 *
		 * @param string $code Code.
		 * @return string Plain text.
		 */
		public static function email_referral_text( $code ) {
			$s = self::settings();
			return trim(
				sprintf(
					/* translators: 1: referral code, 2: reward text, 3: reward terms. */
					__( 'Your referral code: %1$s. Share it with another business. When they buy Ledger Plus with your code, %2$s %3$s', 'ledgerplus-shop' ),
					$code,
					$s['reward_text'],
					self::email_reward_terms()
				)
			);
		}

		/**
		 * F11. On WooCommerce > Settings > Emails only: a warning when email branding is on but WooCommerce
		 * still uses its legacy email templates. The F11 CSS is not added then (only the plugin's cards
		 * show), and WooCommerce offers "Try our new email templates!" on that screen
		 * (includes/admin/settings/class-wc-settings-emails.php:938-947).
		 */
		public static function admin_notice_email_templates() {
			try {
				if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_woocommerce' ) ) {
					return;
				}
				// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only screen check.
				$page = ( isset( $_GET['page'] ) && is_string( $_GET['page'] ) ) ? wp_unslash( $_GET['page'] ) : '';
				$tab  = ( isset( $_GET['tab'] ) && is_string( $_GET['tab'] ) ) ? wp_unslash( $_GET['tab'] ) : '';
				// phpcs:enable WordPress.Security.NonceVerification.Recommended
				// Not on one email's own settings, nor while WooCommerce previews the new templates (try-new-templates).
				if ( 'wc-settings' !== $page || 'email' !== $tab || isset( $_GET['section'] ) || isset( $_GET['try-new-templates'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					return;
				}
				if ( ! self::email_branding_enabled() || self::email_improvements_enabled() ) {
					return;
				}
				echo '<div class="notice notice-warning"><p>' . esc_html__( 'Ledger Plus Shop: the Ledger Plus email design needs WooCommerce’s new email templates, which are off on this store. Use “Try our new email templates!” below the email colours, check the preview, then save. Until then the emails keep the legacy WooCommerce design and only the Ledger Plus cards are added.', 'ledgerplus-shop' ) . '</p></div>';
			} catch ( \Throwable $e ) {
				self::log( 'Email templates notice failed: ' . $e->getMessage() );
			}
		}

		/**
		 * F11. woocommerce_email_styles ( $css, $email ): appends the Ledger Plus email CSS once.
		 *
		 * @param string      $css   CSS from templates/emails/email-styles.php (+ other filters).
		 * @param object|null $email WC_Email.
		 * @return string
		 */
		public static function filter_email_styles( $css, $email = null ) {
			if ( ! is_string( $css ) || false !== strpos( $css, self::EMAIL_CSS_MARKER ) ) {
				return $css;
			}
			try {
				if ( ! self::email_branding_enabled() || ! self::email_templates_supported( $email ) ) {
					return $css;
				}
				return $css . "\n" . self::EMAIL_CSS_MARKER . "\n" . self::email_css();
			} catch ( \Throwable $e ) {
				self::log( 'Email styles failed: ' . $e->getMessage() );
				return $css;
			}
		}

		/**
		 * F11. woocommerce_mail_content: keeps every line within the 998 characters RFC 5322 allows.
		 *
		 * The F11 styles make some lines longer once inlined: Emogrifier writes the CSS it cannot inline
		 * (the mobile media query) as a single line (pelago/emogrifier src/Utilities/CssConcatenator.php:
		 * 100-103), and WooCommerce prints some sections (e.g. Additional information) on one line.
		 * PHPMailer then switches to quoted-printable, but a relay that does not re-encode could break a
		 * line inside a style. So: a line break after each "}" inside <style> (CSS ignores it) and, in a
		 * line still over 998 characters, one before each block-level opening tag that directly follows
		 * another tag (whitespace there has no visual effect). Only HTML messages, new templates, F11 on.
		 *
		 * @param string $content Message after WC_Email::style_inline().
		 * @return string
		 */
		public static function filter_mail_content( $content ) {
			if ( ! is_string( $content ) || false === stripos( $content, '<style' ) || false === stripos( $content, '</html>' ) ) {
				return $content;
			}
			try {
				if ( ! self::email_branding_enabled() || ! self::email_improvements_enabled() ) {
					return $content;
				}
				$out = preg_replace_callback(
					'#(<style\b[^>]*>)(.*?)(</style>)#is',
					static function ( $m ) {
						return $m[1] . preg_replace( '/\}(?!\n)/', "}\n", $m[2] ) . $m[3];
					},
					$content
				);
				$out   = is_string( $out ) ? $out : $content;
				$lines = explode( "\n", $out );
				foreach ( $lines as $i => $line ) {
					if ( strlen( $line ) > 998 ) {
						$split       = preg_replace( '#(?<=>)(?=<(?:ul|ol|li|h[1-6]|table|thead|tbody|tfoot|tr|td|th|p|div|address|hr|blockquote)\b)#i', "\n", $line );
						$lines[ $i ] = is_string( $split ) ? $split : $line;
					}
				}
				return implode( "\n", $lines );
			} catch ( \Throwable $e ) {
				self::log( 'Email content failed: ' . $e->getMessage() );
				return $content;
			}
		}

		/**
		 * F11 CSS (filter: lp_shop_email_css). Simple selectors only (ids, classes, element names,
		 * :last-child like WooCommerce's own CSS) so Emogrifier can inline them. No CSS variables, :is(),
		 * :has(), flex or grid. Ids are only used for WooCommerce's own elements, which have no classes:
		 * Gmail ignores id selectors in <style>, so the inlined (desktop) values must work on a phone too.
		 * Brand colours are fixed here on purpose: navy #041950, blue #0f5cd8, text #2c3651, muted #5b6684,
		 * line #dde5f4, tint #eaf1fd, surface #f6f8fd, light blue #8fb8ff. The WooCommerce colour pickers
		 * keep driving the page background and the parts F11 does not restyle; the font follows the
		 * WooCommerce Font family setting (email_font()). Fixed colours also survive WooCommerce's
		 * "Auto-sync with theme", which on a classic theme with a theme.json (Hello Elementor) resets
		 * the five colour options to WooCommerce's defaults (src/Internal/Email/EmailStyleSync.php:91-133,
		 * EmailColors.php:30-41, 106-110).
		 * Dark mode, secondary measure only: the logo link gets a white background colour and a
		 * one-colour background image, which some apps that invert colours leave alone. Outlook for
		 * Windows ignores the image and apps that darken colours themselves recolour both, so the
		 * reliable fix is a logo file flattened onto white (EMAIL-DESIGN.md 4c).
		 * Images blocked: the alt text (the store name) is styled as a navy sans wordmark.
		 *
		 * @return string
		 */
		public static function email_css() {
			$font = self::email_font();
			$css  = <<<CSS
#wrapper { padding: 32px 0 40px; }
#inner_wrapper { background-color: #ffffff; border: 1px solid #dde5f4; border-top: 4px solid #0f5cd8; border-radius: 16px; }
#template_header_image { padding: 32px 32px 0; }
#template_header_image p { margin: 0; }
#template_header_image a { background-color: #ffffff; background-image: linear-gradient(#ffffff, #ffffff); border-radius: 6px; color: #041950; }
#template_header_image img { color: #041950; font-family: {$font}; font-size: 22px; line-height: 28px; }
.email-logo-text { color: #041950; font-size: 22px; font-weight: bold; margin: 0; }
#template_header { color: #041950; }
#header_wrapper { padding: 28px 32px 0; }
#template_header h1, #template_header h1 a { color: #041950; }
h1 { font-size: 28px; letter-spacing: -0.5px; line-height: 125%; }
#body_content_inner { color: #2c3651; font-family: {$font}; font-size: 16px; line-height: 160%; }
#body_content p { margin: 0 0 14px; }
.email-introduction { padding-bottom: 10px; }
h2 { color: #041950; font-size: 18px; line-height: 140%; margin: 0 0 12px; }
h3 { color: #041950; }
a { color: #0f5cd8; }
h2.email-order-detail-heading { font-size: 20px; margin: 0 0 18px; }
h2.email-order-detail-heading span { color: #5b6684; font-size: 14px; font-weight: normal; margin-top: 2px; }
.td { color: #2c3651; }
#body_content table .email-order-details td, #body_content table .email-order-details th { padding-left: 6px; padding-right: 6px; }
#body_content .email-order-details thead th { border-bottom: 1px solid #dde5f4; color: #5b6684; font-size: 12px; font-weight: bold; letter-spacing: 0.4px; padding-bottom: 10px; text-transform: uppercase; }
#body_content .email-order-details tbody td { padding-top: 16px; }
#body_content .order-item-data { border-collapse: collapse; }
#body_content .wc-product-name { color: #041950; font-size: 16px !important; font-weight: bold !important; line-height: 140%; }
#body_content .email-order-details tbody tr:last-child td { border-bottom: 0; padding-bottom: 6px; }
#body_content_inner hr { border-top-color: #dde5f4 !important; margin: 16px 0 !important; }
#body_content .email-order-details .order-totals td, #body_content .email-order-details .order-totals th { color: #2c3651; font-size: 15px; padding-bottom: 6px; padding-top: 6px; }
#body_content .email-order-details .order-totals-total th { color: #041950; font-size: 16px; font-weight: bold; padding-top: 12px; }
#body_content .email-order-details .order-totals-total td { color: #041950; font-size: 20px; font-weight: bold; padding-top: 12px; }
#body_content .email-order-details .order-totals .includes_tax { color: #2c3651; font-size: 14px; font-weight: normal; margin-top: 2px; }
#body_content .email-order-details .order-totals-payment_method th, #body_content .email-order-details .order-totals-payment_method td { color: #5b6684; font-size: 14px; }
#body_content .email-order-details .order-totals-last td, #body_content .email-order-details .order-totals-last th { border-bottom: 0; padding-bottom: 20px; }
#body_content .email-order-details .order-customer-note td { border-bottom: 0; color: #2c3651; padding-bottom: 8px; padding-top: 4px; }
#body_content .email-order-details .order-customer-note b { color: #041950; }
.address-title { color: #041950; display: block; font-size: 18px; font-weight: bold; margin: 0 0 4px; }
.address { color: #2c3651; font-size: 15px; line-height: 155%; padding: 4px 0 0; }
.address a { color: #0f5cd8; text-decoration: underline; }
.additional-fields { background-color: #f6f8fd; border: 1px solid #dde5f4; border-radius: 12px; color: #2c3651; font-size: 15px; margin: 0 0 24px !important; padding: 14px 18px 4px; }
.additional-fields li { margin: 0 0 10px; }
.additional-fields strong { color: #041950; }
#body_content table td td.email-additional-content { color: #2c3651; padding: 8px 0 0; }
.email-additional-content p { text-align: left; }
#template_footer { background-color: #041950; border-radius: 0 0 12px 12px; }
#template_footer #credit { border-top: 0; color: #c9d5ee; font-size: 13px; line-height: 170%; padding: 28px 32px 30px; text-align: center; }
#template_footer #credit a { color: #8fb8ff; font-weight: bold; text-decoration: none; }
#template_footer #credit strong, #template_footer #credit b { color: #ffffff; font-size: 15px; }
#template_footer #credit p { margin: 0; }
@media screen and (max-width: 600px) {
#wrapper { padding: 8px 0 16px !important; }
#template_header_image { padding: 24px 20px 0 !important; }
#header_wrapper { padding: 20px 20px 0 !important; }
#header_wrapper h1 { font-size: 24px !important; }
#body_content_inner_cell { padding: 16px 20px 28px !important; }
#body_content_inner { font-size: 15px !important; }
.email-order-item-meta { font-size: 13px !important; }
#body_content .email-order-details thead th { font-size: 11px !important; letter-spacing: 0.2px !important; }
#body_content .email-order-details .order-totals-total td { font-size: 18px !important; }
.email-order-detail-heading { font-size: 18px !important; line-height: 140% !important; }
#template_footer #credit { padding: 24px 20px 26px !important; }
.lp-email-card-cell { padding: 18px 18px 6px !important; }
.lp-email-card-foot { padding: 12px 18px 16px !important; }
.lp-email-code { font-size: 22px !important; letter-spacing: 2px !important; }
}
CSS;
			$filtered = function_exists( 'apply_filters' ) ? apply_filters( 'lp_shop_email_css', $css ) : $css;
			return is_string( $filtered ) ? $filtered : $css;
		}

		/**
		 * F11. woocommerce_email_order_items_args: no thumbnail column when no ordered product has an
		 * image (WooCommerce would show its grey placeholder, email-order-items.php:45-49, 68-81).
		 *
		 * @param array $args Template args.
		 * @return array
		 */
		public static function filter_email_order_items_args( $args ) {
			if ( ! is_array( $args ) || empty( $args['show_image'] ) || empty( $args['items'] ) ) {
				return $args;
			}
			try {
				if ( ! self::email_branding_enabled() ) {
					return $args;
				}
				foreach ( (array) $args['items'] as $item ) {
					$product = ( is_object( $item ) && method_exists( $item, 'get_product' ) ) ? $item->get_product() : null;
					if ( is_object( $product ) && method_exists( $product, 'get_image_id' ) && $product->get_image_id() ) {
						return $args;
					}
				}
				$args['show_image'] = false;
			} catch ( \Throwable $e ) {
				self::log( 'Email items args failed: ' . $e->getMessage() );
			}
			return $args;
		}

		/**
		 * Inline style for email text (font repeated on every cell: Outlook for Windows does not
		 * inherit the font into tables).
		 *
		 * @param string $extra More declarations.
		 * @return string
		 */
		private static function email_text_style( $extra ) {
			return 'font-family:' . self::email_font() . ';' . $extra;
		}

		/**
		 * Small blue capitals above a card's content.
		 *
		 * @param string $text Text.
		 * @return string HTML.
		 */
		private static function email_eyebrow( $text ) {
			return '<p class="lp-email-eyebrow" style="' . esc_attr( self::email_text_style( 'margin:0 0 14px;font-size:12px;line-height:16px;font-weight:bold;letter-spacing:1px;text-transform:uppercase;color:#0f5cd8;' ) ) . '">' . esc_html( $text ) . '</p>' . "\n";
		}

		/**
		 * Opening of a card, up to and including its first cell. The card is a one-cell table
		 * (border-radius is ignored by Outlook for Windows, which then shows square corners) inside a
		 * full-width spacer table: the gap below the card is cell padding, which every client keeps
		 * (Outlook for Windows may drop margins on tables). The markup has a line break at each row
		 * boundary, so no line comes near the 998-character limit of RFC 5322.
		 *
		 * @param string $class       Extra class.
		 * @param string $background  Background colour.
		 * @param string $border      Border colour.
		 * @param int    $gap         Space below the card, in px.
		 * @param string $cell_bottom Bottom padding of the first cell.
		 * @return string HTML.
		 */
		private static function email_card_open( $class, $background, $border, $gap = 24, $cell_bottom = '8px' ) {
			return '<table role="presentation" class="lp-email-gap" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;border-collapse:collapse;"><tr><td style="padding:0 0 ' . absint( $gap ) . 'px;">' . "\n"
				. '<table role="presentation" class="lp-email-card ' . esc_attr( $class ) . '" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;border-collapse:separate;background-color:' . esc_attr( $background ) . ';border:1px solid ' . esc_attr( $border ) . ';border-radius:14px;">' . "\n"
				. '<tr><td class="lp-email-card-cell" style="' . esc_attr( self::email_text_style( 'padding:22px 24px ' . $cell_bottom . ';color:#2c3651;' ) ) . '">' . "\n";
		}

		/**
		 * Closing of a card opened with email_card_open() (after its last row).
		 *
		 * @return string HTML.
		 */
		private static function email_card_close() {
			return '</table>' . "\n" . '</td></tr></table>' . "\n";
		}

		/**
		 * "Need help? Call or WhatsApp +971 50 342 0832." for emails (same text as help_line_html()).
		 *
		 * @return string HTML.
		 */
		public static function email_help_line_html() {
			$s    = self::settings();
			$tel  = 'tel:' . preg_replace( '/[^0-9+]/', '', $s['phone_tel'] );
			$link = 'color:#0f5cd8;font-weight:bold;text-decoration:none;';
			$call = '<a href="' . esc_url( $tel ) . '" style="' . $link . '">' . esc_html__( 'Call', 'ledgerplus-shop' ) . '</a>';
			$wa   = '<a href="' . esc_url( $s['whatsapp_url'] ) . '" style="' . $link . '">' . esc_html__( 'WhatsApp', 'ledgerplus-shop' ) . '</a>';
			$num  = '<a href="' . esc_url( $tel ) . '" style="color:#041950;font-weight:bold;text-decoration:none;white-space:nowrap;">' . esc_html( $s['phone_display'] ) . '</a>';
			/* translators: 1: "Call" link, 2: "WhatsApp" link, 3: phone number link. */
			return sprintf( esc_html__( 'Need help? %1$s or %2$s %3$s.', 'ledgerplus-shop' ), $call, $wa, $num );
		}

		/**
		 * F11. "What happens next" card for the Processing order email.
		 *
		 * @return string HTML.
		 */
		public static function email_next_steps_html() {
			$html  = self::email_card_open( 'lp-email-next', '#ffffff', '#dde5f4', 16 );
			$html .= self::email_eyebrow( __( 'What happens next', 'ledgerplus-shop' ) );
			$html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;">' . "\n";
			foreach ( self::next_steps() as $i => $step ) {
				$html .= '<tr>' . "\n"
					. '<td width="40" valign="top" style="width:28px;padding:0 12px 14px 0;">'
					. '<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>' . "\n"
					. '<td class="lp-email-num" width="28" height="28" align="center" valign="middle" bgcolor="#0f5cd8" style="' . esc_attr( self::email_text_style( 'width:28px;height:28px;padding:0;background-color:#0f5cd8;border-radius:14px;color:#ffffff;font-size:13px;line-height:28px;font-weight:bold;text-align:center;' ) ) . '">' . esc_html( (string) ( $i + 1 ) ) . '</td>' . "\n"
					. '</tr></table>'
					. '</td>' . "\n"
					. '<td valign="top" style="' . esc_attr( self::email_text_style( 'padding:4px 0 14px;font-size:15px;line-height:22px;color:#041950;' ) ) . '">' . esc_html( $step ) . '</td>' . "\n"
					. '</tr>' . "\n";
			}
			$html .= '</table>' . "\n" . '</td></tr>' . "\n";
			$html .= '<tr><td class="lp-email-card-foot" style="' . esc_attr( self::email_text_style( 'padding:14px 24px 18px;border-top:1px solid #dde5f4;font-size:14px;line-height:20px;color:#5b6684;' ) ) . '">' . self::email_help_line_html() . '</td></tr>' . "\n";
			$html .= self::email_card_close();
			return $html;
		}

		/**
		 * F11. Plain-text "What happens next" for the Processing order email.
		 *
		 * @return string Escaped text.
		 */
		public static function email_next_steps_text() {
			$s   = self::settings();
			$out = "\n" . strtoupper( __( 'What happens next', 'ledgerplus-shop' ) ) . "\n";
			foreach ( self::next_steps() as $i => $step ) {
				$out .= ( $i + 1 ) . '. ' . $step . "\n";
			}
			/* translators: 1: "Call", 2: "WhatsApp", 3: phone number. */
			$out .= sprintf( __( 'Need help? %1$s or %2$s %3$s.', 'ledgerplus-shop' ), __( 'Call', 'ledgerplus-shop' ), __( 'WhatsApp', 'ledgerplus-shop' ), $s['phone_display'] );
			return "\n" . esc_html( wp_strip_all_tags( $out ) ) . "\n";
		}

		/**
		 * F11. "Your referral code" card: the code large, then the reward sentence and, when they are
		 * set (not a placeholder), the reward terms (email_reward_terms()).
		 *
		 * @param string $code Code.
		 * @return string HTML.
		 */
		public static function email_referral_card_html( $code ) {
			$s     = self::settings();
			$terms = self::email_reward_terms();
			$html  = self::email_card_open( 'lp-email-referral', '#eaf1fd', '#c9dbfb' );
			$html .= self::email_eyebrow( __( 'Your referral code', 'ledgerplus-shop' ) );
			$html .= '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="border-collapse:separate;"><tr>' . "\n"
				. '<td class="lp-email-code" bgcolor="#ffffff" style="' . esc_attr( self::email_text_style( 'padding:10px 18px;background-color:#ffffff;border:2px dashed #0f5cd8;border-radius:10px;font-size:26px;line-height:32px;font-weight:bold;letter-spacing:3px;color:#041950;' ) ) . '" data-lp-code="' . esc_attr( $code ) . '">' . esc_html( $code ) . '</td>' . "\n"
				. '</tr></table>' . "\n";
			$html .= '<p style="' . esc_attr( self::email_text_style( 'margin:14px 0 14px;font-size:15px;line-height:23px;color:#2c3651;' ) ) . '">'
				/* translators: %s: reward text, e.g. "you get AED 999 cash back after their payment is confirmed." */
				. sprintf( esc_html__( 'Share it with another business. When they buy Ledger Plus with your code, %s', 'ledgerplus-shop' ), esc_html( $s['reward_text'] ) )
				. ( '' !== $terms ? ' <span style="color:#5b6684;">' . esc_html( $terms ) . '</span>' : '' )
				. '</p>' . "\n";
			$html .= '</td></tr>' . "\n" . self::email_card_close();
			return $html;
		}

		/**
		 * F11. "Ledger Plus checks" card for the admin emails: one label / value row per check.
		 * A possible existing customer is shown in red.
		 *
		 * @param \WC_Order $order Order.
		 * @param array     $rows  admin_summary_rows().
		 * @return string HTML.
		 */
		public static function email_checks_card_html( $order, $rows ) {
			$repeat = is_object( $order ) && method_exists( $order, 'get_meta' ) && 'yes' === $order->get_meta( self::META_REPEAT, true );
			$html   = self::email_card_open( 'lp-email-checks', '#f6f8fd', '#dde5f4', 24, '22px' );
			$html  .= self::email_eyebrow( __( 'Ledger Plus checks', 'ledgerplus-shop' ) );
			$html  .= '<table role="presentation" class="lp-email-checks-table" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;">' . "\n";
			foreach ( array_values( $rows ) as $i => $row ) {
				$line   = $i ? 'border-top:1px solid #dde5f4;' : '';
				$alert  = ( 3 === $i && $repeat );
				$html  .= '<tr>' . "\n"
					. '<td width="44%" valign="top" style="' . esc_attr( self::email_text_style( 'width:44%;padding:9px 12px 9px 0;' . $line . 'font-size:14px;line-height:20px;color:#5b6684;' ) ) . '">' . esc_html( $row[0] ) . '</td>' . "\n"
					. '<td valign="top" style="' . esc_attr( self::email_text_style( 'padding:9px 0;' . $line . 'font-size:14px;line-height:20px;font-weight:bold;color:' . ( $alert ? '#b42318' : '#041950' ) . ';' ) ) . '">' . esc_html( $row[1] ) . '</td>' . "\n"
					. '</tr>' . "\n";
			}
			$html .= '</table>' . "\n" . '</td></tr>' . "\n" . self::email_card_close();
			return $html;
		}

		/* ==================================================================
		 * F4. Coupon wording
		 * ================================================================== */

		/**
		 * Block cart/checkout msgids (woocommerce text domain), WooCommerce 11.3.1:
		 *   client/blocks/assets/js/base/components/cart-checkout/totals/coupon/index.tsx:90,94,107 (131 "Apply" is kept)
		 *   client/blocks/assets/js/base/components/cart-checkout/totals/discount/index.tsx:79-81,95,105-107,121
		 *   client/blocks/assets/js/base/context/hooks/cart/use-store-cart-coupons.ts:69-71,113-115
		 * "Discount" (discount/index.tsx:120) is NOT mapped: it only shows when the discount is not zero,
		 * which a referral code (AED 0) never causes, so it would mislabel a real discount.
		 *
		 * @return array<string,string>
		 */
		public static function block_label_map() {
			$map = array(
				'Add coupons'                                       => __( 'Have a referral code?', 'ledgerplus-shop' ),
				'Enter code'                                        => __( 'Referral code', 'ledgerplus-shop' ),
				"Applying coupon\u{2026}"                           => __( "Applying referral code\u{2026}", 'ledgerplus-shop' ),
				"Removing coupon\u{2026}"                           => __( "Removing referral code\u{2026}", 'ledgerplus-shop' ),
				'Coupon: %s'                                        => __( 'Referral code: %s', 'ledgerplus-shop' ),
				'Remove coupon "%s"'                                => __( 'Remove referral code "%s"', 'ledgerplus-shop' ),
				'Coupons'                                           => __( 'Referral code', 'ledgerplus-shop' ),
				'Coupon code "%s" has been applied to your cart.'   => self::referral_accepted_text(),
				'Coupon code "%s" has been removed from your cart.' => __( 'Referral code "%s" has been removed.', 'ledgerplus-shop' ),
			);
			$map = apply_filters( 'lp_shop_block_coupon_labels', $map );
			return is_array( $map ) ? $map : array();
		}

		/**
		 * The inline script: wp.hooks filters on i18n.gettext_woocommerce, which @wordpress/i18n's __()
		 * applies to every string (gutenberg packages/i18n/src/create-i18n.ts:169-195, default hooks
		 * instance in default-i18n.ts:10). The block bundles use wp.i18n as an external
		 * (client/blocks/bin/webpack-configs.js:75-80, DependencyExtractionWebpackPlugin), so wp-hooks
		 * loads before them.
		 *
		 * @return string
		 */
		public static function block_labels_js() {
			$json = wp_json_encode( (object) self::block_label_map(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
			if ( ! is_string( $json ) ) {
				return '';
			}
			return '(function(){var w=window;if(!w.wp||!w.wp.hooks||typeof w.wp.hooks.addFilter!=="function"){return;}'
				. 'var m=' . $json . ';var h=Object.prototype.hasOwnProperty;'
				. 'w.wp.hooks.addFilter("i18n.gettext_woocommerce","ledgerplus-shop/referral-labels",function(t,x){return (typeof x==="string"&&h.call(m,x))?m[x]:t;});'
				. '})();';
		}

		/**
		 * Adds the inline script after wp-hooks on cart and checkout pages only.
		 */
		public static function enqueue_block_labels() {
			if ( self::$inline_added || is_admin() || ! self::is_cart_or_checkout() ) {
				return;
			}
			$s = self::settings();
			if ( empty( $s['relabel_coupons'] ) ) {
				return;
			}
			$js = self::block_labels_js();
			if ( '' === $js ) {
				return;
			}
			self::$inline_added = true;
			wp_enqueue_script( 'wp-hooks' );
			wp_add_inline_script( 'wp-hooks', $js, 'after' );
		}

		/**
		 * True on the cart or checkout page (includes/wc-conditional-functions.php:108,126).
		 *
		 * @return bool
		 */
		private static function is_cart_or_checkout() {
			return ( function_exists( 'is_cart' ) && is_cart() ) || ( function_exists( 'is_checkout' ) && is_checkout() );
		}

		/**
		 * Classic templates (templates/cart/cart.php:202, templates/checkout/form-coupon.php:35,42-47,
		 * includes/wc-cart-functions.php:307) on the cart/checkout pages.
		 *
		 * @return array<string,string>
		 */
		private static function classic_label_map() {
			return array(
				'Have a coupon?'         => __( 'Have a referral code?', 'ledgerplus-shop' ),
				'Coupon code'            => __( 'Referral code', 'ledgerplus-shop' ),
				'Apply coupon'           => __( 'Apply', 'ledgerplus-shop' ),
				'Coupon:'                => __( 'Referral code:', 'ledgerplus-shop' ),
				'Coupon: %s'             => __( 'Referral code: %s', 'ledgerplus-shop' ),
				'Enter your coupon code' => __( 'Enter your referral code', 'ledgerplus-shop' ),
			);
		}

		/**
		 * Store API messages built in PHP (src/StoreApi/Utilities/CartController.php:961,973,1082).
		 *
		 * @return array<string,string>
		 */
		private static function store_api_label_map() {
			return array(
				'"%s" is an invalid coupon code.'                       => __( '"%s" is not a valid referral code. Please check it and try again.', 'ledgerplus-shop' ),
				'Coupon code "%s" has already been applied.'            => __( 'Referral code "%s" has already been applied.', 'ledgerplus-shop' ),
				'The "%1$s" coupon has been removed from your cart: %2$s' => __( 'The referral code "%1$s" has been removed: %2$s', 'ledgerplus-shop' ),
			);
		}

		/**
		 * gettext_woocommerce ( $translation, $text, $domain ). Only these exact msgids, only on the
		 * front end cart/checkout pages, or in Store API requests (WooCommerce::is_store_api_request(),
		 * includes/class-woocommerce.php:682).
		 *
		 * @param string $translation Translation.
		 * @param string $text        Original.
		 * @param string $domain      Domain.
		 * @return string
		 */
		public static function filter_gettext_woocommerce( $translation, $text = '', $domain = 'woocommerce' ) {
			static $keys = array(
				'Have a coupon?'                                          => 'classic',
				'Coupon code'                                             => 'classic',
				'Apply coupon'                                            => 'classic',
				'Coupon:'                                                 => 'classic',
				'Coupon: %s'                                              => 'classic',
				'Enter your coupon code'                                  => 'classic',
				'"%s" is an invalid coupon code.'                         => 'store',
				'Coupon code "%s" has already been applied.'              => 'store',
				'The "%1$s" coupon has been removed from your cart: %2$s' => 'store',
			);
			static $busy = false;

			if ( $busy || ! is_string( $text ) || ! isset( $keys[ $text ] ) || ! did_action( 'init' ) ) {
				return $translation;
			}
			$busy = true;
			try {
				$s = self::settings();
				if ( empty( $s['relabel_coupons'] ) ) {
					return $translation;
				}
				if ( 'store' === $keys[ $text ] ) {
					if ( function_exists( 'WC' ) && method_exists( WC(), 'is_store_api_request' ) && WC()->is_store_api_request() ) {
						$map = self::store_api_label_map();
						return isset( $map[ $text ] ) ? $map[ $text ] : $translation;
					}
					return $translation;
				}
				if ( is_admin() || ! did_action( 'wp' ) || ! self::is_cart_or_checkout() ) {
					return $translation;
				}
				$map = self::classic_label_map();
				return isset( $map[ $text ] ) ? $map[ $text ] : $translation;
			} finally {
				$busy = false;
			}
		}

		/* ==================================================================
		 * F5. Buy-now link safety
		 * ================================================================== */

		/**
		 * Pure decision: skip the add silently?
		 *
		 * @param int   $product_id        Product being added.
		 * @param int   $requested_id      absint( $_REQUEST['add-to-cart'] ).
		 * @param int[] $buy_now_ids       Products covered.
		 * @param bool  $sold_individually Product is sold individually.
		 * @param array $cart_items        WC()->cart->get_cart().
		 * @param bool  $in_form_handler   Running inside WC_Form_Handler::add_to_cart_action (wp_loaded).
		 * @return bool
		 */
		public static function should_skip_duplicate_add( $product_id, $requested_id, $buy_now_ids, $sold_individually, $cart_items, $in_form_handler ) {
			$product_id = absint( $product_id );
			if ( ! $in_form_handler || ! $sold_individually || ! $product_id || absint( $requested_id ) !== $product_id ) {
				return false;
			}
			if ( ! in_array( $product_id, array_map( 'absint', (array) $buy_now_ids ), true ) ) {
				return false;
			}
			foreach ( (array) $cart_items as $item ) {
				if ( is_array( $item ) && absint( $item['product_id'] ?? 0 ) === $product_id && (float) ( $item['quantity'] ?? 0 ) > 0 ) {
					return true;
				}
			}
			return false;
		}

		/**
		 * woocommerce_add_to_cart_validation ( $passed, $product_id, $quantity ).
		 *
		 * Returning false in WC_Form_Handler::add_to_cart_handler_simple() skips WC()->cart->add_to_cart()
		 * (where the "You cannot add another" exception is thrown, class-wc-cart.php:1356-1380) and
		 * wc_add_to_cart_message(), adds no notice and makes no redirect (class-wc-form-handler.php:1055,1094-1100).
		 * Limited to the form handler on wp_loaded: the Store API turns false into an error
		 * (src/StoreApi/Utilities/CartController.php:337-353).
		 *
		 * @param bool $passed     Passed so far.
		 * @param int  $product_id Product ID.
		 * @param int  $quantity   Quantity.
		 * @return bool
		 */
		public static function filter_buy_now_validation( $passed, $product_id = 0, $quantity = 1 ) {
			if ( ! $passed ) {
				return $passed;
			}
			try {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended
				if ( ! isset( $_REQUEST['add-to-cart'] ) || ! is_scalar( $_REQUEST['add-to-cart'] ) ) {
					return $passed;
				}
				$in_form_handler = doing_action( 'wp_loaded' ) && ! wp_doing_ajax() && ! self::is_rest_request();
				if ( ! $in_form_handler ) {
					return $passed;
				}
				$s = self::settings();
				if ( ! in_array( absint( $product_id ), $s['buy_now_product_ids'], true ) ) {
					return $passed;
				}
				$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
				$cart    = ( function_exists( 'WC' ) && WC() && isset( WC()->cart ) ) ? WC()->cart : null;
				if ( ! $product || ! is_object( $cart ) || ! method_exists( $cart, 'get_cart' ) ) {
					return $passed;
				}
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$requested = absint( wp_unslash( $_REQUEST['add-to-cart'] ) );
				if ( self::should_skip_duplicate_add( $product_id, $requested, $s['buy_now_product_ids'], (bool) $product->is_sold_individually(), $cart->get_cart(), true ) ) {
					return false;
				}
			} catch ( \Throwable $e ) {
				self::log( 'Buy-now check failed: ' . $e->getMessage() );
			}
			return $passed;
		}

		/* ==================================================================
		 * F6. Product page redirect
		 * ================================================================== */

		/**
		 * 302 from the product 890 page to the published sales page 892.
		 */
		public static function maybe_redirect_product_page() {
			// phpcs:disable WordPress.Security.NonceVerification.Recommended
			if ( is_admin() || wp_doing_ajax() || ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) || self::is_rest_request() ) {
				return;
			}
			if ( is_feed() || is_preview() || ( function_exists( 'is_customize_preview' ) && is_customize_preview() ) || is_embed() ) {
				return;
			}
			if ( isset( $_REQUEST['add-to-cart'] ) || isset( $_GET['elementor-preview'] ) || isset( $_GET['preview'] ) || isset( $_GET['preview_id'] ) ) {
				return;
			}
			if ( ! is_singular( 'product' ) ) {
				return;
			}
			$s = self::settings();
			if ( empty( $s['redirect_product_page'] ) || (int) get_queried_object_id() !== (int) $s['product_id'] ) {
				return;
			}
			// Capability first; never cast the raw value (?lp_stay[]=1 must not raise "Array to string conversion").
			if ( isset( $_GET['lp_stay'] ) && current_user_can( 'manage_woocommerce' ) && is_string( $_GET['lp_stay'] ) && '1' === wp_unslash( $_GET['lp_stay'] ) ) {
				return;
			}
			$page_id = (int) $s['sales_page_id'];
			if ( $page_id <= 0 || 'publish' !== get_post_status( $page_id ) ) {
				return;
			}
			$url = get_permalink( $page_id );
			if ( ! $url ) {
				return;
			}
			$keep = array();
			foreach ( $_GET as $key => $value ) {
				if ( is_string( $key ) && is_string( $value ) && preg_match( '/^(utm_[a-z0-9_]+|gclid|fbclid|msclkid)$/', $key ) ) {
					$keep[ $key ] = rawurlencode( sanitize_text_field( wp_unslash( $value ) ) );
				}
			}
			// phpcs:enable
			if ( $keep ) {
				$url = add_query_arg( $keep, $url );
			}
			if ( wp_safe_redirect( $url, 302, 'Ledger Plus Shop' ) ) {
				exit;
			}
		}

		/* ==================================================================
		 * Helpers
		 * ================================================================== */

		/**
		 * REST request (WP_REST, or WooCommerce's URL check).
		 *
		 * @return bool
		 */
		private static function is_rest_request() {
			if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
				return true;
			}
			if ( function_exists( 'wp_is_serving_rest_request' ) && wp_is_serving_rest_request() ) {
				return true;
			}
			return function_exists( 'WC' ) && method_exists( WC(), 'is_rest_api_request' ) && WC()->is_rest_api_request();
		}

		/**
		 * WooCommerce logger (source "ledgerplus-shop").
		 *
		 * @param string $message Message.
		 */
		private static function log( $message ) {
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->warning( (string) $message, array( 'source' => 'ledgerplus-shop' ) );
			}
		}

		/* ==================================================================
		 * F10. TODO - Phase 2: Zoho CRM Deal sync (NOT implemented)
		 * ==================================================================
		 *
		 * Out of scope for this version. Nothing here talks to Zoho, pays a reward or
		 * moves money. Plan for Phase 2:
		 *
		 * 1. Trigger: woocommerce_order_status_processing (priority 20, after the
		 *    referral work above). Do NOT call Zoho inside the request: schedule an
		 *    Action Scheduler job, e.g. as_enqueue_async_action( 'lp_shop_zoho_sync',
		 *    array( 'order_id' => $id ), 'ledgerplus-shop' ).
		 * 2. Job: load the order with wc_get_order(); skip if _lp_zoho_deal_id is set
		 *    (idempotent); find or create the Contact/Account by billing email and
		 *    trade licence; create a Deal (name "Ledger Plus Starter - #order",
		 *    amount, stage "Closed Won - onboarding", trade licence, TRN, referral
		 *    code used + _lp_referral_status, buyer's own code, possible repeat flag);
		 *    store _lp_zoho_deal_id on the order and add an order note.
		 * 3. Credentials: use the site's existing Zoho connection or a server-side
		 *    secret store. Never hard-code keys in this file or in the WPCode snippet.
		 * 4. Errors: retry with back-off through Action Scheduler; log to
		 *    wc_get_logger() source "ledgerplus-shop"; never block checkout.
		 * 5. Referral rewards stay manual. A reward can only be due for an order whose
		 *    _lp_referral_status is pending_review AND whose order status is still
		 *    processing or completed (cancelled/refunded orders become void_cancelled /
		 *    void_refunded; ineligible_self is never due). The sales team reviews and pays
		 *    by hand. Sync the referral status again on woocommerce_order_status_cancelled /
		 *    _refunded so the Deal never shows a void referral as payable.
		 */
	}

	LP_Shop::bootstrap();
}
