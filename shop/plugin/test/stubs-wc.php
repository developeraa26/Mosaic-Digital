<?php
/**
 * WooCommerce stubs (loaded after stubs-wp.php).
 */

/* ---------------- WooCommerce ---------------- */

define( 'WC_VERSION', '11.3.1' );

class WooCommerce {
	public $cart = null;
	public $customer = null;
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
	public function warning( $m, $ctx = array() ) {
		$GLOBALS['lp_test']['logs'][] = $m;
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

class WC_Order extends WC_Data {
	public $billing_email = '';
	public $status        = 'pending';
	public $product_ids   = array();
	public $coupon_codes  = array();
	public $customer_id   = 0;
	public $date_paid     = null;

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
		return $o;
	}
	public function get_billing_email( $context = 'view' ) {
		return $this->billing_email;
	}
	public function get_items( $types = 'line_item' ) {
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
		$GLOBALS['lp_test']['orders'][ $this->id ]['meta']   = $this->meta;
		$GLOBALS['lp_test']['orders'][ $this->id ]['status'] = $this->status;
		$GLOBALS['lp_test']['saves'][ $this->id ]            = ( $GLOBALS['lp_test']['saves'][ $this->id ] ?? 0 ) + 1;
		return $this->id;
	}
}

function wc_get_order( $id ) {
	$row = $GLOBALS['lp_test']['orders'][ (int) $id ] ?? null;
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
}
function wc_get_product( $id ) {
	return $GLOBALS['lp_test']['products'][ (int) $id ] ?? false;
}

function woocommerce_register_additional_checkout_field( $options ) {
	$GLOBALS['lp_test']['registered'][] = $options;
}
