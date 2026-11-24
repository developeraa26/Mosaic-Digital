<?php
/**
 * Minimal WordPress / WooCommerce stubs for the Ledger Plus Shop tests.
 * Only what the plugin calls. Not a WordPress emulator.
 */

error_reporting( E_ALL );
ini_set( 'display_errors', '1' );

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['lp_test'] = array(
	'filters'        => array(),
	'actions_done'   => array(),
	'action_stack'   => array(),
	'options'        => array(),
	'orders'         => array(),
	'coupons'        => array(),
	'next_coupon_id' => 5000,
	'notes'          => array(),
	'saves'          => array(),
	'registered'     => array(),
	'scripts'        => array(),
	'inline'         => array(),
	'redirects'      => array(),
	'is_admin'       => false,
	'doing_ajax'     => false,
	'logged_in'      => false,
	'user_email'     => '',
	'user_id'        => 0,
	'caps'           => array(),
	'is_cart'        => false,
	'is_checkout'    => false,
	'is_singular'    => false,
	'queried_id'     => 0,
	'post_status'    => array(),
	'permalinks'     => array(),
	'is_preview'     => false,
	'is_feed'        => false,
	'store_api'      => false,
	'products'       => array(),
	'logs'           => array(),
);

/* ---------------- Hooks ---------------- */

function add_filter( $tag, $cb, $priority = 10, $args = 1 ) {
	$GLOBALS['lp_test']['filters'][ $tag ][ $priority ][] = array( $cb, $args );
	return true;
}
function add_action( $tag, $cb, $priority = 10, $args = 1 ) {
	return add_filter( $tag, $cb, $priority, $args );
}
function remove_all_filters( $tag ) {
	unset( $GLOBALS['lp_test']['filters'][ $tag ] );
}
function lp_test_hook_count( $tag ) {
	$n = 0;
	foreach ( $GLOBALS['lp_test']['filters'][ $tag ] ?? array() as $cbs ) {
		$n += count( $cbs );
	}
	return $n;
}
function apply_filters( $tag, $value, ...$args ) {
	$all = $GLOBALS['lp_test']['filters'][ $tag ] ?? array();
	ksort( $all );
	foreach ( $all as $cbs ) {
		foreach ( $cbs as $entry ) {
			$value = call_user_func_array( $entry[0], array_slice( array_merge( array( $value ), $args ), 0, $entry[1] ) );
		}
	}
	return $value;
}
function do_action( $tag, ...$args ) {
	$GLOBALS['lp_test']['actions_done'][ $tag ] = ( $GLOBALS['lp_test']['actions_done'][ $tag ] ?? 0 ) + 1;
	$GLOBALS['lp_test']['action_stack'][]       = $tag;
	$all = $GLOBALS['lp_test']['filters'][ $tag ] ?? array();
	ksort( $all );
	try {
		foreach ( $all as $cbs ) {
			foreach ( $cbs as $entry ) {
				call_user_func_array( $entry[0], array_slice( $args, 0, $entry[1] ) );
			}
		}
	} finally {
		array_pop( $GLOBALS['lp_test']['action_stack'] );
	}
}
function did_action( $tag ) {
	return $GLOBALS['lp_test']['actions_done'][ $tag ] ?? 0;
}
function doing_action( $tag = null ) {
	return in_array( $tag, $GLOBALS['lp_test']['action_stack'], true );
}
function has_filter( $tag ) {
	return ! empty( $GLOBALS['lp_test']['filters'][ $tag ] );
}

/* ---------------- i18n / escaping ---------------- */

function __( $t, $d = 'default' ) {
	return $t;
}
function esc_html__( $t, $d = 'default' ) {
	return esc_html( $t );
}
function esc_attr__( $t, $d = 'default' ) {
	return esc_attr( $t );
}
function esc_html( $t ) {
	return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' );
}
function esc_attr( $t ) {
	return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' );
}
function esc_url( $u ) {
	$u = (string) $u;
	return preg_match( '#^(https?:|tel:|mailto:)#', $u ) ? htmlspecialchars( $u, ENT_QUOTES, 'UTF-8' ) : '';
}
function wp_kses_post( $h ) {
	return (string) $h;
}
function wp_strip_all_tags( $s ) {
	return trim( strip_tags( (string) $s ) );
}
function sanitize_email( $e ) {
	return preg_replace( '/[^a-z0-9@._+\-]/i', '', (string) $e );
}
function sanitize_text_field( $s ) {
	return trim( strip_tags( (string) $s ) );
}
function absint( $n ) {
	return abs( (int) $n );
}
function wp_unslash( $v ) {
	return $v;
}
function wp_json_encode( $data, $options = 0, $depth = 512 ) {
	return json_encode( $data, $options, $depth );
}
function wc_strtolower( $s ) {
	return strtolower( (string) $s );
}
function wc_strtoupper( $s ) {
	return strtoupper( (string) $s );
}
function add_query_arg( $args, $url ) {
	return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $args );
}

/* ---------------- Context ---------------- */

function is_admin() {
	return $GLOBALS['lp_test']['is_admin'];
}
function wp_doing_ajax() {
	return $GLOBALS['lp_test']['doing_ajax'];
}
function wp_doing_cron() {
	return false;
}
function is_user_logged_in() {
	return $GLOBALS['lp_test']['logged_in'];
}
function wp_get_current_user() {
	$u             = new stdClass();
	$u->user_email = $GLOBALS['lp_test']['user_email'];
	return $u;
}
function get_current_user_id() {
	return $GLOBALS['lp_test']['user_id'];
}
function current_user_can( $cap ) {
	return in_array( $cap, $GLOBALS['lp_test']['caps'], true );
}
function is_cart() {
	return $GLOBALS['lp_test']['is_cart'];
}
function is_checkout() {
	return $GLOBALS['lp_test']['is_checkout'];
}
function is_singular( $type = '' ) {
	return $GLOBALS['lp_test']['is_singular'];
}
function get_queried_object_id() {
	return $GLOBALS['lp_test']['queried_id'];
}
function get_post_status( $id ) {
	return $GLOBALS['lp_test']['post_status'][ $id ] ?? false;
}
function get_permalink( $id ) {
	return $GLOBALS['lp_test']['permalinks'][ $id ] ?? false;
}
function is_feed() {
	return $GLOBALS['lp_test']['is_feed'];
}
function is_preview() {
	return $GLOBALS['lp_test']['is_preview'];
}
function is_embed() {
	return false;
}
function is_customize_preview() {
	return false;
}
class LP_Test_Redirect extends Exception {
}
function wp_safe_redirect( $url, $status = 302, $by = '' ) {
	$GLOBALS['lp_test']['redirects'][] = array( $url, $status );
	throw new LP_Test_Redirect( $url ); // Stops before the plugin's exit.
}

/* ---------------- Options (lock) ---------------- */

// run-race.php defines its own WordPress 7.1.5 option model before loading these stubs.
if ( ! function_exists( 'add_option' ) ) {
	function add_option( $k, $v = '', $dep = '', $autoload = null ) {
		if ( array_key_exists( $k, $GLOBALS['lp_test']['options'] ) ) {
			return false;
		}
		$GLOBALS['lp_test']['options'][ $k ] = $v;
		return true;
	}
	function get_option( $k, $default = false ) {
		return $GLOBALS['lp_test']['options'][ $k ] ?? $default;
	}
	function update_option( $k, $v, $autoload = null ) {
		$GLOBALS['lp_test']['options'][ $k ] = $v;
		return true;
	}
	function delete_option( $k ) {
		unset( $GLOBALS['lp_test']['options'][ $k ] );
		return true;
	}
	function wp_cache_delete( $key, $group = '' ) {
		return true;
	}
}

/* ---------------- Scripts ---------------- */

function wp_enqueue_script( $h ) {
	$GLOBALS['lp_test']['scripts'][] = $h;
}
function wp_add_inline_script( $h, $js, $pos = 'after' ) {
	$GLOBALS['lp_test']['inline'][] = array( $h, $js, $pos );
	return true;
}

/* ---------------- WP_Error ---------------- */

class WP_Error {
	public $errors = array();
	public function __construct( $code = '', $message = '' ) {
		if ( $code ) {
			$this->errors[ $code ][] = $message;
		}
	}
	public function get_error_code() {
		$codes = array_keys( $this->errors );
		return $codes[0] ?? '';
	}
	public function get_error_message() {
		$code = $this->get_error_code();
		return $code ? $this->errors[ $code ][0] : '';
	}
}
function is_wp_error( $x ) {
	return $x instanceof WP_Error;
}

