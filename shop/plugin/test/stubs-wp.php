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
function remove_all_actions( $tag ) {
	remove_all_filters( $tag );
}
function register_deactivation_hook( $file, $cb ) {
	$GLOBALS['lp_test']['deactivation'][] = array( basename( $file ), $cb );
}
function remove_filter( $tag, $cb, $priority = 10 ) {
	$removed = false;
	foreach ( $GLOBALS['lp_test']['filters'][ $tag ][ $priority ] ?? array() as $i => $entry ) {
		if ( $entry[0] === $cb ) {
			unset( $GLOBALS['lp_test']['filters'][ $tag ][ $priority ][ $i ] );
			$removed = true;
		}
	}
	if ( isset( $GLOBALS['lp_test']['filters'][ $tag ][ $priority ] ) && ! $GLOBALS['lp_test']['filters'][ $tag ][ $priority ] ) {
		unset( $GLOBALS['lp_test']['filters'][ $tag ][ $priority ] );
	}
	if ( isset( $GLOBALS['lp_test']['filters'][ $tag ] ) && ! $GLOBALS['lp_test']['filters'][ $tag ] ) {
		unset( $GLOBALS['lp_test']['filters'][ $tag ] );
	}
	return $removed;
}
function remove_action( $tag, $cb, $priority = 10 ) {
	return remove_filter( $tag, $cb, $priority );
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
function has_action( $tag ) {
	return has_filter( $tag );
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
	return ! empty( $GLOBALS['lp_test']['doing_cron'] );
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
	return ! empty( $GLOBALS['lp_test']['is_embed'] );
}
function is_customize_preview() {
	return ! empty( $GLOBALS['lp_test']['is_customize'] );
}
function is_front_page() {
	return ! empty( $GLOBALS['lp_test']['is_front_page'] );
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

/* ---------------- Transients (own store; expiry honoured, time can be shifted) ---------------- */

function lp_test_now() {
	return time() + (int) ( $GLOBALS['lp_test']['time_shift'] ?? 0 );
}
function get_transient( $k ) {
	$t = $GLOBALS['lp_test']['transients'][ $k ] ?? null;
	if ( ! $t || ( $t['exp'] && $t['exp'] <= lp_test_now() ) ) {
		unset( $GLOBALS['lp_test']['transients'][ $k ] );
		return false;
	}
	return $t['v'];
}
function set_transient( $k, $v, $ttl = 0 ) {
	$GLOBALS['lp_test']['transients'][ $k ] = array(
		'v'   => $v,
		'exp' => $ttl ? lp_test_now() + (int) $ttl : 0,
	);
	return true;
}
function delete_transient( $k ) {
	unset( $GLOBALS['lp_test']['transients'][ $k ] );
	return true;
}

/* ---------------- HTTP API (mocked: $GLOBALS['lp_test']['http_handler']( $url, $args )) ---------------- */

function wp_remote_get( $url, $args = array() ) {
	$GLOBALS['lp_test']['http_log'][] = array( 'url' => $url, 'args' => $args, 'method' => 'GET' );
	$h = $GLOBALS['lp_test']['http_handler'] ?? null;
	if ( ! is_callable( $h ) ) {
		return new WP_Error( 'http_request_failed', 'No test HTTP handler.' );
	}
	return call_user_func( $h, $url, $args );
}
function wp_remote_retrieve_response_code( $r ) {
	return ( is_array( $r ) && isset( $r['response']['code'] ) ) ? (int) $r['response']['code'] : '';
}
function wp_remote_retrieve_body( $r ) {
	return ( is_array( $r ) && isset( $r['body'] ) ) ? (string) $r['body'] : '';
}
function wp_remote_retrieve_header( $r, $name ) {
	return ( is_array( $r ) && isset( $r['headers'][ strtolower( $name ) ] ) ) ? $r['headers'][ strtolower( $name ) ] : '';
}
function lp_test_http_json( $code, $data, $headers = array() ) {
	return array(
		'response' => array( 'code' => $code, 'message' => '' ),
		'body'     => is_string( $data ) ? $data : json_encode( $data ),
		'headers'  => array_change_key_case( $headers, CASE_LOWER ),
	);
}

/* ---------------- URLs ---------------- */

function home_url( $path = '' ) {
	return 'https://ledgerplus.ae' . $path;
}
function site_url( $path = '' ) {
	return 'https://ledgerplus.ae' . $path;
}
function get_site_url() {
	return 'https://ledgerplus.ae';
}
function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}
function sanitize_key( $k ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) );
}

/* ---------------- Action Scheduler (recorded in $GLOBALS['lp_test']['as']) ---------------- */

function as_schedule_single_action( $timestamp, $hook, $args = array(), $group = '', $unique = false, $priority = 10 ) {
	$GLOBALS['lp_test']['as'][] = array( 'time' => (int) $timestamp, 'hook' => $hook, 'args' => $args, 'group' => $group, 'status' => 'pending' );
	return count( $GLOBALS['lp_test']['as'] );
}
function as_has_scheduled_action( $hook, $args = null, $group = '' ) {
	foreach ( $GLOBALS['lp_test']['as'] ?? array() as $a ) {
		if ( 'pending' === $a['status'] && $a['hook'] === $hook && ( null === $args || $a['args'] === $args ) && ( '' === $group || $a['group'] === $group ) ) {
			return true;
		}
	}
	return false;
}
/** as_unschedule_action(): cancels the next pending action with this hook, args and group (Action Scheduler 3.x). */
function as_unschedule_action( $hook, $args = array(), $group = '' ) {
	foreach ( $GLOBALS['lp_test']['as'] ?? array() as $i => $a ) {
		if ( 'pending' === $a['status'] && $a['hook'] === $hook && $a['args'] === $args && ( '' === $group || $a['group'] === $group ) ) {
			$GLOBALS['lp_test']['as'][ $i ]['status'] = 'canceled';
			return $i + 1;
		}
	}
	return null;
}
/** as_unschedule_all_actions(): with no args, every pending action of the hook. */
function as_unschedule_all_actions( $hook, $args = array(), $group = '' ) {
	foreach ( $GLOBALS['lp_test']['as'] ?? array() as $i => $a ) {
		if ( 'pending' === $a['status'] && $a['hook'] === $hook && ( ! $args || $a['args'] === $args ) && ( '' === $group || $a['group'] === $group ) ) {
			$GLOBALS['lp_test']['as'][ $i ]['status'] = 'canceled';
		}
	}
}
/** Runs the pending actions due at $now (like Action Scheduler: do_action_ref_array( $hook, array_values( $args ) )). */
function lp_test_run_due_actions( $now ) {
	foreach ( array_keys( $GLOBALS['lp_test']['as'] ?? array() ) as $i ) {
		$a = $GLOBALS['lp_test']['as'][ $i ]; // Read now: an earlier action may have cancelled this one.
		if ( 'pending' === $a['status'] && $a['time'] <= $now ) {
			$GLOBALS['lp_test']['as'][ $i ]['status'] = 'complete';
			do_action( $a['hook'], ...array_values( $a['args'] ) );
		}
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

