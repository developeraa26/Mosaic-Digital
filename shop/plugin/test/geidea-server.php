<?php
/**
 * Router for PHP's built-in server (php -S), used by run-geidea-http.php. One HTTP request = one WordPress
 * request on the stubs; the test state is kept in a file between requests (env LP_GEIDEA_STATE).
 *
 * It models what the Geidea callback path depends on, in this order:
 *  1. wp-settings.php: the fatal error handler is registered first (wp_register_fatal_error_handler()), then
 *     register_shutdown_function( 'shutdown_action_hook' ) (wp-settings.php:166). shutdown_action_hook() runs
 *     do_action( 'shutdown' ) (load.php:1302), and default-filters.php adds wp_ob_end_flush_all() to it at
 *     priority 1. WP_Fatal_Error_Handler shows its error page with wp_die( ..., array( 'exit' => false ) ), so
 *     the other shutdown functions still run.
 *  2. WooCommerce 11.3.1 LegacyRestApiStub::maybe_process_wc_api_query_var(): ob_start(), wc_nocache_headers(),
 *     do_action( 'woocommerce_api_request', $api_request ), status_header( has_action( ... ) ? 200 : 400 ),
 *     do_action( 'woocommerce_api_' . $api_request ), ob_end_clean(), die( '-1' ).
 *  3. Geidea's return_handler() (geidea-sim.php) reads php://input itself, echoes, sets the status and die()s
 *     inside WooCommerce's buffer.
 * A probe registered as a plain shutdown function during the request shows what such a function can still see.
 * The error handler lets PHP handle every error as well (returns false), like a site without its own handler, so
 * error_get_last() behaves as on the live site. Test config "notice" adds a silenced warning where other code can
 * raise one before the recorder reads the error: "shutdown0" (another plugin's shutdown callback at priority 0),
 * "fatal_handler" (inside WordPress's fatal error handler, after it passed the error to wp_php_error_args) or
 * "order_read" (another plugin's woocommerce_order_class filter, which runs whenever an order is read). Config
 * "no_error_args" leaves out the wp_php_error_args filter (as with a wp-content/php-error.php drop-in).
 */

$state_file = getenv( 'LP_GEIDEA_STATE' );
$plugin     = getenv( 'LP_GEIDEA_PLUGIN' );
$saved      = is_string( $state_file ) && is_file( $state_file ) ? unserialize( file_get_contents( $state_file ) ) : array();
$config     = $saved['config'] ?? array();

require __DIR__ . '/stubs.php';
require __DIR__ . '/geidea-fixtures.php';
require __DIR__ . '/geidea-sim.php';
ini_set( 'display_errors', '0' ); // stubs-wp.php turns it on for the CLI tests; a live site runs with it off.

foreach ( $saved['lp_test'] ?? array() as $k => $v ) {
	$GLOBALS['lp_test'][ $k ] = $v;
}
$GLOBALS['lp_test']['php_errors'] = array();
set_error_handler(
	function ( $no, $str, $file, $line ) {
		if ( error_reporting() & $no ) { // Not silenced with @.
			$GLOBALS['lp_test']['php_errors'][] = "[$no] $str (" . basename( $file ) . ":$line)";
		}
		return false; // PHP records it too (error_get_last()), as on a site without its own error handler.
	}
);

/* ---- 1. WordPress shutdown model ---- */
function lp_sim_fatal_error_handler() {
	$e = error_get_last();
	if ( ! $e || ! in_array( $e['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR ), true ) || headers_sent() ) {
		return;
	}
	// WP_Fatal_Error_Handler::display_default_error_template(): the wp_php_error_args filter gets the error read above,
	// then wp_die( $message, '', array( 'response' => 500, 'exit' => false ) ).
	if ( empty( $GLOBALS['lp_sim_config']['no_error_args'] ) ) {
		apply_filters( 'wp_php_error_args', array( 'response' => 500, 'exit' => false ), $e );
	}
	if ( 'fatal_handler' === ( $GLOBALS['lp_sim_config']['notice'] ?? '' ) ) {
		@trigger_error( 'Recovery mode email could not be sent', E_USER_WARNING ); // phpcs:ignore -- silenced, as WordPress does.
	}
	http_response_code( 500 );
	echo '<!DOCTYPE html><html><head><title>WordPress &rsaquo; Error</title></head><body id="error-page"><div class="wp-die-message"><p>There has been a critical error on this website.</p><p><a href="https://wordpress.org/documentation/article/faq-troubleshooting/">Learn more about troubleshooting WordPress.</a></p></div></body></html>';
}
function shutdown_action_hook() {
	do_action( 'shutdown' );
	// Save the test state last: a shutdown function registered now runs after every one registered before.
	register_shutdown_function( 'lp_sim_save_state' );
}
function wp_ob_end_flush_all() {
	$levels = ob_get_level();
	for ( $i = 0; $i < $levels; $i++ ) {
		ob_end_flush();
	}
}
function lp_sim_save_state() {
	$keep = array();
	foreach ( array( 'orders', 'notes', 'options', 'coupons', 'next_coupon_id', 'logs', 'glogs', 'actions_done', 'transitions', 'tokens_saved', 'post_meta', 'stock_reduced', 'transients', 'as', 'geidea_handler_ran', 'php_errors', 'probe', 'saves' ) as $k ) {
		if ( isset( $GLOBALS['lp_test'][ $k ] ) ) {
			$keep[ $k ] = $GLOBALS['lp_test'][ $k ];
		}
	}
	file_put_contents( getenv( 'LP_GEIDEA_STATE' ), serialize( array( 'config' => $GLOBALS['lp_sim_config'], 'lp_test' => $keep ) ) );
}
$GLOBALS['lp_sim_config'] = $config;
register_shutdown_function( 'lp_sim_fatal_error_handler' );
register_shutdown_function( 'shutdown_action_hook' );
add_action( 'shutdown', 'wp_ob_end_flush_all', 1 );

if ( 'order_read' === ( $config['notice'] ?? '' ) ) {
	add_filter(
		'woocommerce_order_class',
		function ( $class ) {
			if ( did_action( 'shutdown' ) ) {
				$a = array();
				$x = @$a['not-set']; // Another plugin's order filter with a silenced warning.
			}
			return $class;
		}
	);
}
if ( 'shutdown0' === ( $config['notice'] ?? '' ) ) {
	add_action(
		'shutdown',
		function () {
			$a = array();
			$x = @$a['not-set']; // Another plugin's shutdown callback with a silenced warning.
		},
		0
	);
}

/* ---- WordPress / WooCommerce boot with the plugin ---- */
require $plugin;
do_action( 'plugins_loaded' );
do_action( 'init' );
do_action( 'woocommerce_init' );
if ( ! empty( $config['settings'] ) ) {
	add_filter(
		'lp_shop_settings',
		function ( $s ) {
			return array_merge( $s, $GLOBALS['lp_sim_config']['settings'] );
		}
	);
}
$GLOBALS['lp_test']['products'][890] = new LP_Test_Product( true );
WC()->cart                           = new WC_Cart();
$gateway                             = new LP_Test_Geidea_Gateway();
add_action( 'woocommerce_api_geidea', array( $gateway, 'return_handler' ) );

// The probe: a plain shutdown function registered during the request, after the plugin's hooks (priority 50).
add_action(
	'woocommerce_api_request',
	function () {
		register_shutdown_function(
			function () {
				$GLOBALS['lp_test']['probe'] = array(
					'ob_level' => ob_get_level(),
					'buffer'   => ob_get_level() ? (string) ob_get_contents() : '',
					'code'     => http_response_code(),
				);
			}
		);
	},
	50
);

/* ---- 2. WooCommerce wc-api dispatch (LegacyRestApiStub) ---- */
function status_header( $code ) {
	http_response_code( (int) $code );
}
function wc_nocache_headers() {
	header( 'Cache-Control: no-cache, must-revalidate, max-age=0' );
}
if ( ! empty( $_GET['wc-api'] ) ) {
	ob_start();
	wc_nocache_headers();
	$api_request = strtolower( sanitize_key( wp_unslash( $_GET['wc-api'] ) ) );
	do_action( 'woocommerce_api_request', $api_request );
	status_header( has_action( 'woocommerce_api_' . $api_request ) ? 200 : 400 );
	do_action( 'woocommerce_api_' . $api_request );
	ob_end_clean();
	die( '-1' );
}
echo 'not a wc-api request';
