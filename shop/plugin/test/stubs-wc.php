<?php
/**
 * WooCommerce stubs (loaded after stubs-wp.php).
 */

/* ---------------- WooCommerce ---------------- */

define( 'WC_VERSION', '11.3.1' );

class WooCommerce {
	public $cart = null;
	public $customer = null;
	public $session = null;
	public function is_store_api_request() {
		return $GLOBALS['lp_test']['store_api'];
	}
	public function is_rest_api_request() {
		return $GLOBALS['lp_test']['store_api'];
	}
	public function mailer() {
		static $m = null;
		if ( null === $m ) {
			$m = new LP_Test_Mailer();
		}
		return $m;
	}
}

class LP_Test_New_Order_Email {
	public $enabled   = true;
	public $recipient = 'shop@ledgerplus.ae';
	public function is_enabled() {
		return $this->enabled;
	}
	public function get_recipient() {
		return $this->recipient;
	}
}
class LP_Test_Mailer {
	public $sent = array();
	public $new_order;
	public function __construct() {
		$this->new_order = new LP_Test_New_Order_Email();
	}
	public function get_emails() {
		return array( 'WC_Email_New_Order' => $this->new_order );
	}
	public function wrap_message( $heading, $message, $deprecated = false ) {
		return '<h1>' . $heading . '</h1>' . $message;
	}
	public function send( $to, $subject, $message, $headers = "Content-Type: text/html\r\n", $attachments = '' ) {
		$this->sent[] = compact( 'to', 'subject', 'message' );
		return true;
	}
}
function WC() {
	static $wc = null;
	if ( null === $wc ) {
		$wc = new WooCommerce();
	}
	return $wc;
}

class LP_Test_Logger {
	private function put( $level, $m, $ctx ) {
		if ( 'ledgerplus-geidea' === ( $ctx['source'] ?? '' ) ) {
			$GLOBALS['lp_test']['glogs'][] = $level . ': ' . $m;
		} elseif ( 'ledgerplus-zoho' === ( $ctx['source'] ?? '' ) ) {
			$GLOBALS['lp_test']['zlogs'][] = $level . ': ' . $m; // F14
		} else {
			$GLOBALS['lp_test']['logs'][] = $m;
		}
	}
	public function warning( $m, $ctx = array() ) {
		$this->put( 'warning', $m, $ctx );
	}
	public function info( $m, $ctx = array() ) {
		$this->put( 'info', $m, $ctx );
	}
	public function error( $m, $ctx = array() ) {
		$this->put( 'error', $m, $ctx );
	}
}
function wc_get_logger() {
	return new LP_Test_Logger();
}

abstract class WC_Data {
	protected $id   = 0;
	protected $meta = array();
	public function get_id() {
		return $this->id;
	}
	public function get_meta( $key = '', $single = true, $context = 'view' ) {
		return $this->meta[ $key ] ?? '';
	}
	public function update_meta_data( $key, $value ) {
		$this->meta[ $key ] = $value;
	}
	public function delete_meta_data( $key ) {
		unset( $this->meta[ $key ] );
	}
	public function get_all_meta() {
		return $this->meta;
	}
}

class LP_Test_Date {
	private $ts;
	public function __construct( $ts ) {
		$this->ts = (int) $ts;
	}
	public function getTimestamp() {
		return $this->ts;
	}
}

class WC_Customer extends WC_Data {
	public $billing_email = '';
	public function __construct( $email = '', $meta = array() ) {
		$this->billing_email = $email;
		$this->meta          = $meta;
	}
	public function get_billing_email() {
		return $this->billing_email;
	}
}

class LP_Test_Item {
	private $pid;
	public function __construct( $pid ) {
		$this->pid = $pid;
	}
	public function get_product_id() {
		return $this->pid;
	}
}
/* F14: a line item with the WC_Order_Item_Product getters the Zoho sync reads (name, quantity, totals). */
class LP_Test_Line_Item extends LP_Test_Item {
	private $name;
	private $qty;
	private $line_total;
	private $line_tax;
	public function __construct( $pid, $name, $qty, $total, $tax ) {
		parent::__construct( $pid );
		$this->name       = $name;
		$this->qty        = $qty;
		$this->line_total = $total;
		$this->line_tax   = $tax;
	}
	public function get_name( $context = 'view' ) {
		return $this->name;
	}
	public function get_quantity() {
		return $this->qty;
	}
	public function get_total( $context = 'view' ) {
		return $this->line_total;
	}
	public function get_total_tax( $context = 'view' ) {
		return $this->line_tax;
	}
}

class WC_Order extends WC_Data {
	public $billing_email  = '';
	public $status         = 'pending';
	public $product_ids    = array();
	public $coupon_codes   = array();
	public $customer_id    = 0;
	public $date_paid      = null;
	public $payment_method = '';
	public $total          = '1999.00';
	public $currency       = 'AED';
	public $order_key      = '';
	public $created        = 0;
	public $transaction_id = '';
	public $type           = 'shop_order';
	protected $status_transition = false;

	public static function make( $id, $email, $status, $product_ids, $coupons = array(), $meta = array(), $customer_id = 0 ) {
		$date_paid                           = in_array( $status, array( 'processing', 'completed' ), true ) ? time() - 3600 : null;
		$GLOBALS['lp_test']['orders'][ $id ] = compact( 'id', 'email', 'status', 'product_ids', 'coupons', 'meta', 'customer_id', 'date_paid' );
		return wc_get_order( $id );
	}
	public static function from_row( $row ) {
		$o                = new WC_Order();
		$o->id            = $row['id'];
		$o->billing_email = $row['email'];
		$o->status        = $row['status'];
		$o->product_ids   = $row['product_ids'];
		$o->coupon_codes  = $row['coupons'];
		$o->meta          = $row['meta'];
		$o->customer_id   = $row['customer_id'];
		$o->date_paid     = $row['date_paid'] ?? null;
		$o->payment_method = $row['payment_method'] ?? '';
		$o->total          = $row['total'] ?? '1999.00';
		$o->currency       = $row['currency'] ?? 'AED';
		$o->order_key      = $row['order_key'] ?? 'wc_order_key' . $row['id'];
		$o->created        = $row['created'] ?? ( time() - 600 );
		$o->transaction_id = $row['transaction_id'] ?? '';
		$o->type           = $row['type'] ?? 'shop_order';
		return $o;
	}
	/* ---- F12: the WC_Order API the Geidea safety net uses (modelled on includes/class-wc-order.php 11.3.1) ---- */
	public function get_payment_method( $context = 'view' ) {
		return $this->payment_method;
	}
	public function get_billing_phone( $context = 'view' ) {
		return $GLOBALS['lp_test']['orders'][ $this->id ]['phone'] ?? '';
	}
	public function get_billing_first_name( $context = 'view' ) {
		return $GLOBALS['lp_test']['orders'][ $this->id ]['first_name'] ?? '';
	}
	public function get_billing_last_name( $context = 'view' ) {
		return $GLOBALS['lp_test']['orders'][ $this->id ]['last_name'] ?? '';
	}
	/* F14 */
	public function get_billing_company( $context = 'view' ) {
		return $GLOBALS['lp_test']['orders'][ $this->id ]['company'] ?? '';
	}
	public function get_customer_note( $context = 'view' ) {
		return $GLOBALS['lp_test']['orders'][ $this->id ]['customer_note'] ?? '';
	}
	public function get_total_tax( $context = 'view' ) {
		return $GLOBALS['lp_test']['orders'][ $this->id ]['total_tax'] ?? '0';
	}
	public function get_payment_method_title( $context = 'view' ) {
		return 'geidea' === $this->payment_method ? 'Geidea Online Payments' : $this->payment_method;
	}
	public function get_total( $context = 'view' ) {
		return $this->total;
	}
	public function get_currency( $context = 'view' ) {
		return $this->currency;
	}
	public function get_order_key( $context = 'view' ) {
		return $this->order_key;
	}
	public function get_date_created( $context = 'view' ) {
		return $this->created ? new LP_Test_Date( $this->created ) : null;
	}
	public function get_transaction_id( $context = 'view' ) {
		return $this->transaction_id;
	}
	public function set_transaction_id( $v ) {
		$this->transaction_id = (string) $v;
	}
	public function get_type() {
		return $this->type;
	}
	public function get_status( $context = 'view' ) {
		return $this->status;
	}
	public function get_user_id( $context = 'view' ) {
		return $this->customer_id;
	}
	public function get_customer_id( $context = 'view' ) {
		return $this->customer_id;
	}
	public function needs_processing() {
		return $GLOBALS['lp_test']['needs_processing'] ?? true; // Virtual, not downloadable: WooCommerce says processing.
	}
	public function set_status( $new_status, $note = '', $manual = false ) {
		$new_status = preg_replace( '/^wc-/', '', (string) $new_status );
		// abstracts/abstract-wc-order.php:942-957: an unregistered status becomes pending (auto-draft and trash excepted).
		if ( ! array_key_exists( 'wc-' . $new_status, wc_get_order_statuses() ) && ! in_array( $new_status, array( 'auto-draft', 'trash' ), true ) ) {
			$new_status = 'pending';
		}
		$from       = $this->status;
		$this->status = $new_status;
		if ( $from !== $new_status ) {
			$this->status_transition = array(
				'from' => ! empty( $this->status_transition['from'] ) ? $this->status_transition['from'] : $from,
				'to'   => $new_status,
				'note' => $note,
			);
			if ( null === $this->date_paid && in_array( $new_status, array( 'processing', 'completed' ), true ) ) {
				$this->date_paid = time(); // maybe_set_date_paid()
			}
		}
		return array( 'from' => $from, 'to' => $new_status );
	}
	public function update_status( $new_status, $note = '', $manual = false ) {
		$this->set_status( $new_status, $note, $manual );
		$this->save();
		return true;
	}
	/** WC_Order::payment_complete() (includes/class-wc-order.php:138-209). */
	public function payment_complete( $transaction_id = '' ) {
		if ( ! $this->get_id() ) {
			return false;
		}
		try {
			do_action( 'woocommerce_pre_payment_complete', $this->get_id(), $transaction_id );
			$valid = apply_filters( 'woocommerce_valid_order_statuses_for_payment_complete', array( 'on-hold', 'pending', 'failed', 'cancelled' ), $this );
			if ( $this->has_status( $valid ) ) {
				if ( ! empty( $transaction_id ) ) {
					$this->set_transaction_id( $transaction_id );
				}
				if ( null === $this->date_paid ) {
					$this->date_paid = time();
				}
				$next = apply_filters( 'woocommerce_payment_complete_order_status', $this->needs_processing() ? 'processing' : 'completed', $this->get_id(), $this );
				$tid  = $this->get_transaction_id();
				$this->set_status( $next, false );
				$this->add_order_note( $tid ? sprintf( 'Payment via %1$s (%2$s).', $this->get_payment_method_title(), $tid ) : 'Payment complete.' );
				$this->save();
				do_action( 'woocommerce_payment_complete', $this->get_id(), $tid );
			} else {
				do_action( 'woocommerce_payment_complete_order_status_' . $this->get_status(), $this->get_id(), $transaction_id );
			}
		} catch ( Exception $e ) {
			$this->add_order_note( 'Payment complete event failed. ' . $e->getMessage() );
			return false;
		}
		return true;
	}
	/** Status transition after save (includes/class-wc-order.php:435-503): the flag is reset before the hooks. */
	protected function status_transition() {
		$t                       = $this->status_transition;
		$this->status_transition = false;
		if ( ! $t ) {
			return;
		}
		$GLOBALS['lp_test']['transitions'][] = array( $this->id, $t['from'], $t['to'] );
		do_action( 'woocommerce_order_status_' . $t['to'], $this->get_id(), $this, $t );
		if ( false !== $t['note'] ) {
			$this->add_order_note( trim( 'Order status changed from ' . ucfirst( $t['from'] ) . ' to ' . ucfirst( $t['to'] ) . '. ' . $t['note'] ) );
		}
		do_action( 'woocommerce_order_status_' . $t['from'] . '_to_' . $t['to'], $this->get_id(), $this );
		do_action( 'woocommerce_order_status_changed', $this->get_id(), $t['from'], $t['to'], $this );
	}
	/* Legacy order API used by Geidea Online Payments 3.5.2 (functions/PaymentComplete.php, ReturnHandler.php). */
	public function __get( $key ) {
		return 'id' === $key ? $this->id : null; // WC_Abstract_Legacy_Order::__get(): $order->id still works.
	}
	public function get_product_from_item( $item ) {
		return wc_get_product( $item->get_product_id() );
	}
	public function reduce_order_stock() {
		$GLOBALS['lp_test']['stock_reduced'][ $this->id ] = ( $GLOBALS['lp_test']['stock_reduced'][ $this->id ] ?? 0 ) + 1;
	}
	public function get_billing_email( $context = 'view' ) {
		return $this->billing_email;
	}
	public function get_items( $types = 'line_item' ) {
		$lines = $GLOBALS['lp_test']['orders'][ $this->id ]['lines'] ?? null; // F14: full line items when a test gives them.
		if ( is_array( $lines ) ) {
			return array_map(
				function ( $l ) {
					return new LP_Test_Line_Item( $l[0], $l[1], $l[2], $l[3], $l[4] );
				},
				$lines
			);
		}
		return array_map(
			function ( $pid ) {
				return new LP_Test_Item( $pid );
			},
			$this->product_ids
		);
	}
	public function get_coupon_codes() {
		return $this->coupon_codes;
	}
	public function get_order_number() {
		return (string) $this->id;
	}
	public function get_date_paid( $context = 'view' ) {
		return null === $this->date_paid ? null : new LP_Test_Date( $this->date_paid );
	}
	public function get_edit_order_url() {
		return 'https://ledgerplus.ae/wp-admin/admin.php?page=wc-orders&action=edit&id=' . $this->id;
	}
	public function read_meta_data( $force_read = false ) {
		$this->meta = $GLOBALS['lp_test']['orders'][ $this->id ]['meta'] ?? array();
		$GLOBALS['lp_test']['meta_reads'] = ( $GLOBALS['lp_test']['meta_reads'] ?? 0 ) + 1;
	}
	public function save_meta_data() {
		$GLOBALS['lp_test']['orders'][ $this->id ]['meta'] = $this->meta;
		$GLOBALS['lp_test']['meta_saves'][ $this->id ]     = ( $GLOBALS['lp_test']['meta_saves'][ $this->id ] ?? 0 ) + 1;
	}
	public function is_paid() {
		return in_array( $this->status, array( 'processing', 'completed' ), true );
	}
	public function has_status( $s ) {
		return in_array( $this->status, (array) $s, true );
	}
	public function add_order_note( $note, $is_customer = 0, $by_user = false, $meta = array() ) {
		$GLOBALS['lp_test']['notes'][ $this->id ][] = $note;
		return count( $GLOBALS['lp_test']['notes'][ $this->id ] );
	}
	public function save() {
		if ( function_exists( 'adv_point' ) ) {
			adv_point( 'order_save' );
		}
		$GLOBALS['lp_test']['orders'][ $this->id ]['meta']   = $this->meta;
		$GLOBALS['lp_test']['orders'][ $this->id ]['status'] = $this->status;
		$GLOBALS['lp_test']['orders'][ $this->id ]['date_paid']      = $this->date_paid;
		$GLOBALS['lp_test']['orders'][ $this->id ]['transaction_id'] = $this->transaction_id;
		$GLOBALS['lp_test']['saves'][ $this->id ]            = ( $GLOBALS['lp_test']['saves'][ $this->id ] ?? 0 ) + 1;
		$this->status_transition();
		return $this->id;
	}
}

function wc_get_order_status_name( $status ) {
	$names = array( 'pending' => 'Pending payment', 'processing' => 'Processing', 'on-hold' => 'On hold', 'completed' => 'Completed', 'cancelled' => 'Cancelled', 'refunded' => 'Refunded', 'failed' => 'Failed' );
	$status = preg_replace( '/^wc-/', '', (string) $status );
	return $names[ $status ] ?? $status;
}
function wc_get_is_paid_statuses() {
	return apply_filters( 'woocommerce_order_is_paid_statuses', array( 'processing', 'completed' ) );
}
function wc_get_order_statuses() {
	return array( 'wc-pending' => 'Pending payment', 'wc-processing' => 'Processing', 'wc-on-hold' => 'On hold', 'wc-completed' => 'Completed', 'wc-cancelled' => 'Cancelled', 'wc-refunded' => 'Refunded', 'wc-failed' => 'Failed' );
}
function is_order_received_page() {
	return ! empty( $GLOBALS['lp_test']['order_received'] );
}
/* F13b: WooCommerce's page conditionals (includes/wc-conditional-functions.php:46-60, 145, 202). */
function is_shop() {
	return ! empty( $GLOBALS['lp_test']['is_shop'] );
}
function is_product_taxonomy() {
	return ! empty( $GLOBALS['lp_test']['is_product_taxonomy'] );
}
/* includes/wc-page-functions.php: the page id of a WooCommerce page, -1 when none is set. */
function wc_get_page_id( $page ) {
	$pages = $GLOBALS['lp_test']['wc_pages'] ?? array( 'shop' => 885, 'cart' => 886, 'checkout' => 887, 'myaccount' => 888 );
	return (int) ( $pages[ $page ] ?? -1 );
}
function is_account_page() {
	return ! empty( $GLOBALS['lp_test']['is_account'] );
}
function is_checkout_pay_page( $use_query_params = false ) {
	return ! empty( $GLOBALS['lp_test']['is_pay_page'] );
}

function wc_get_order( $id ) {
	$row = $GLOBALS['lp_test']['orders'][ (int) $id ] ?? null;
	if ( $row ) {
		apply_filters( 'woocommerce_order_class', 'WC_Order', $row['type'] ?? 'shop_order', (int) $id ); // class-wc-order-factory.php:304
	}
	return $row ? WC_Order::from_row( $row ) : false; // Always a fresh instance, like a real read.
}

function wc_get_orders( $args ) {
	$out = array();
	foreach ( $GLOBALS['lp_test']['orders'] as $row ) {
		$o = WC_Order::from_row( $row );
		if ( ! empty( $args['status'] ) ) {
			$st = array_map(
				function ( $s ) {
					return preg_replace( '/^wc-/', '', $s );
				},
				(array) $args['status']
			);
			if ( ! in_array( $o->status, $st, true ) ) {
				continue;
			}
		}
		if ( ! empty( $args['exclude'] ) && in_array( $o->get_id(), (array) $args['exclude'], true ) ) {
			continue;
		}
		if ( isset( $args['billing_email'] ) && strtolower( $o->billing_email ) !== strtolower( $args['billing_email'] ) ) {
			continue;
		}
		if ( isset( $args['customer_id'] ) && (int) $o->customer_id !== (int) $args['customer_id'] ) {
			continue;
		}
		if ( isset( $args['meta_key'] ) && ( ! isset( $args['meta_value'] ) || (string) $o->get_meta( $args['meta_key'] ) !== (string) $args['meta_value'] ) ) {
			continue; // meta_key / meta_value shortcut (OrdersTableQuery.php:313-331; WP_Query for posts storage).
		}
		if ( ! empty( $args['meta_query'] ) ) {
			$mq  = $args['meta_query'];
			$rel = strtoupper( $mq['relation'] ?? 'AND' );
			unset( $mq['relation'] );
			$hits = array();
			foreach ( $mq as $clause ) {
				$hits[] = ( (string) $o->get_meta( $clause['key'] ) === (string) $clause['value'] );
			}
			$ok = 'OR' === $rel ? in_array( true, $hits, true ) : ! in_array( false, $hits, true );
			if ( ! $ok ) {
				continue;
			}
		}
		$out[] = $o;
		if ( ! empty( $args['limit'] ) && count( $out ) >= $args['limit'] ) {
			break;
		}
	}
	if ( isset( $args['return'] ) && 'ids' === $args['return'] ) {
		return array_map(
			function ( $o ) {
				return $o->get_id();
			},
			$out
		);
	}
	return $out;
}

class WC_Coupon extends WC_Data {
	const WC_COUPON_SUCCESS            = 200;
	const WC_COUPON_REMOVED            = 201;
	const E_WC_COUPON_INVALID_FILTERED = 100;

	public $props = array(
		'code'                 => '',
		'status'               => '',
		'discount_type'        => 'fixed_cart',
		'amount'               => 0,
		'individual_use'       => false,
		'product_ids'          => array(),
		'usage_limit'          => 0,
		'usage_limit_per_user' => 0,
		'description'          => '',
	);

	public function __construct( $data = '' ) {
		$row = null;
		if ( is_int( $data ) || ( is_string( $data ) && ctype_digit( $data ) && '' !== $data ) ) {
			$row = $GLOBALS['lp_test']['coupons'][ (int) $data ] ?? null;
		} elseif ( is_string( $data ) && '' !== $data ) {
			foreach ( $GLOBALS['lp_test']['coupons'] as $c ) {
				if ( strtolower( $c['props']['code'] ) === strtolower( $data ) && 'publish' === $c['props']['status'] ) {
					$row = $c;
					break;
				}
			}
		}
		if ( $row ) {
			$this->id    = $row['id'];
			$this->props = $row['props'];
			$this->meta  = $row['meta'];
		}
	}
	public function set_code( $v ) {
		$this->props['code'] = $v;
	}
	public function set_status( $v ) {
		$this->props['status'] = $v;
	}
	public function set_discount_type( $v ) {
		$this->props['discount_type'] = $v;
	}
	public function set_amount( $v ) {
		$this->props['amount'] = $v;
	}
	public function set_individual_use( $v ) {
		$this->props['individual_use'] = (bool) $v;
	}
	public function set_product_ids( $v ) {
		$this->props['product_ids'] = array_map( 'intval', (array) $v );
	}
	public function set_usage_limit( $v ) {
		$this->props['usage_limit'] = absint( $v );
	}
	public function set_usage_limit_per_user( $v ) {
		$this->props['usage_limit_per_user'] = absint( $v );
	}
	public function set_description( $v ) {
		$this->props['description'] = $v;
	}
	public function get_code() {
		return $this->props['code'];
	}
	public function get_status() {
		return $this->props['status'];
	}
	public function save() {
		$new = ! $this->id;
		if ( $new ) {
			$this->id = $GLOBALS['lp_test']['next_coupon_id']++;
		}
		$GLOBALS['lp_test']['coupons'][ $this->id ] = array(
			'id'    => $this->id,
			'props' => $this->props,
			'meta'  => $this->meta,
		);
		if ( $new ) {
			do_action( 'lp_test_coupon_created', $this->id, $this );
		}
		return $this->id;
	}
	public function delete( $force_delete = false ) {
		if ( $this->id && isset( $GLOBALS['lp_test']['coupons'][ $this->id ] ) ) {
			$this->props['status']                                        = 'trash';
			$GLOBALS['lp_test']['coupons'][ $this->id ]['props']['status'] = 'trash';
		}
		return true;
	}
}

function wc_get_coupon_id_by_code( $code, $exclude = 0 ) {
	foreach ( $GLOBALS['lp_test']['coupons'] as $c ) {
		if ( strtolower( $c['props']['code'] ) === strtolower( $code ) && 'publish' === $c['props']['status'] ) {
			return $c['id'];
		}
	}
	return 0;
}

function get_posts( $args ) {
	if ( function_exists( 'adv_point' ) ) {
		adv_point( 'coupon_lookup' );
	}
	$ids      = array();
	$statuses = (array) ( $args['post_status'] ?? array( 'publish' ) );
	foreach ( $GLOBALS['lp_test']['coupons'] as $c ) {
		$ok = in_array( $c['props']['status'] ?? 'publish', $statuses, true );
		foreach ( $args['meta_query'] as $k => $clause ) {
			if ( 'relation' === $k ) {
				continue;
			}
			if ( (string) ( $c['meta'][ $clause['key'] ] ?? '' ) !== (string) $clause['value'] ) {
				$ok = false;
			}
		}
		if ( $ok ) {
			$ids[] = $c['id'];
		}
	}
	if ( function_exists( 'adv_point' ) ) {
		adv_point( 'coupon_lookup_done' );
	}
	if ( 'ID' === ( $args['orderby'] ?? '' ) ) {
		sort( $ids );
		if ( 'DESC' === strtoupper( $args['order'] ?? 'DESC' ) ) {
			$ids = array_reverse( $ids );
		}
	}
	return array_slice( $ids, 0, $args['posts_per_page'] ?? 5 );
}

class WC_Cart {
	public $emptied  = 0;
	public function empty_cart( $clear_persistent_cart = true ) {
		$this->emptied++;
		$this->contents = array();
	}
	public $contents = array();
	public $customer = null;
	public $applied  = array();
	public function get_applied_coupons() {
		return $this->applied;
	}
	public function get_cart() {
		return $this->contents;
	}
	public function get_customer() {
		return $this->customer;
	}
}

class WC_Discounts {
	protected $object;
	public function __construct( $object = null ) {
		$this->object = $object;
	}
	public function get_object() {
		return $this->object;
	}
}

class LP_Test_Product {
	public $sold_individually;
	public $name = 'E-Invoicing Starter Package';
	public function __construct( $si ) {
		$this->sold_individually = $si;
	}
	public function get_name() {
		return $this->name;
	}
	public function is_sold_individually() {
		return $this->sold_individually;
	}
	public function is_virtual() {
		return true;
	}
	public function is_downloadable() {
		return false;
	}
}
function wc_get_product( $id ) {
	return $GLOBALS['lp_test']['products'][ (int) $id ] ?? false;
}

function woocommerce_register_additional_checkout_field( $options ) {
	$GLOBALS['lp_test']['registered'][] = $options;
}
