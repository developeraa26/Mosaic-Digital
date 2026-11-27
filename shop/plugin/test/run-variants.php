<?php
/**
 * Loading tests for both variants. Each mode runs in its own PHP process.
 * Usage: php run-variants.php <mode>
 *   eval-twice          WPCode snippet eval()'d twice (as on two requests' worth of includes)
 *   plugin-then-snippet plugin file active AND the snippet: no redeclare, hooks once
 *   snippet-then-plugin snippet first, then the plugin file
 *   no-wc               WooCommerce missing: no fatal, only an admin notice hook
 *   old-wc              WooCommerce 8.9: treated as missing
 *   no-abspath          snippet eval()'d without ABSPATH: does nothing
 *   blocks-api          field values read through CheckoutFields::get_field_from_object()
 *   twin-sync           the snippet's code is the plugin's shared core byte for byte; versions agree
 */

$mode    = $argv[1] ?? '';
$build   = dirname( __DIR__ );
$plugin  = $build . '/ledgerplus-shop/ledgerplus-shop.php';
$snippet = file_get_contents( $build . '/ledgerplus-shop-wpcode.php' );

$errors = array();
set_error_handler(
	function ( $no, $str, $file, $line ) use ( &$errors ) {
		$errors[] = "[$no] $str ($file:$line)";
		return true;
	}
);

$pass = 0;
$fail = 0;
function vcheck( $name, $cond, $detail = '' ) {
	global $pass, $fail;
	if ( $cond ) {
		$pass++;
		echo "  PASS  $name\n";
	} else {
		$fail++;
		echo "  FAIL  $name" . ( $detail ? "  -- $detail" : '' ) . "\n";
	}
}

echo "\n== variant: $mode ==\n";

switch ( $mode ) {
	case 'eval-twice':
		require __DIR__ . '/stubs.php';
		vcheck( 'snippet has no opening PHP tag', 0 !== strpos( ltrim( $snippet ), '<?php' ) );
		vcheck( 'snippet has no plugin header', false === strpos( $snippet, 'Plugin Name:' ) );
		vcheck( 'snippet does not reference __FILE__/__DIR__ (tokens)', ! array_filter( token_get_all( '<?php ' . $snippet ), function ( $t ) { return is_array( $t ) && in_array( $t[0], array( T_FILE, T_DIR ), true ); } ) );
		eval( $snippet ); // phpcs:ignore
		eval( $snippet ); // phpcs:ignore
		vcheck( 'class defined after two evals', class_exists( 'LP_Shop', false ) );
		do_action( 'plugins_loaded' );
		vcheck( 'hooks registered once', 1 === lp_test_hook_count( 'woocommerce_currency_symbol' ) && 1 === lp_test_hook_count( 'woocommerce_order_status_processing' ) );
		vcheck( 'no HPOS declaration in snippet', 0 === lp_test_hook_count( 'before_woocommerce_init' ) );
		// Snippet run late (after plugins_loaded and woocommerce_init), as some snippet runners do.
		break;

	case 'plugin-then-snippet':
		require __DIR__ . '/stubs.php';
		require $plugin;
		eval( $snippet ); // phpcs:ignore
		do_action( 'plugins_loaded' );
		vcheck( 'one class, hooks once', class_exists( 'LP_Shop', false ) && 1 === lp_test_hook_count( 'woocommerce_currency_symbol' ) );
		vcheck( 'HPOS declaration hook once', 1 === lp_test_hook_count( 'before_woocommerce_init' ) );
		break;

	case 'snippet-then-plugin':
		require __DIR__ . '/stubs.php';
		do_action( 'plugins_loaded' );
		do_action( 'init' );
		do_action( 'woocommerce_init' );
		eval( $snippet ); // phpcs:ignore  Late snippet: boots immediately.
		vcheck( 'late snippet boots immediately', 1 === lp_test_hook_count( 'woocommerce_currency_symbol' ) );
		vcheck( 'late snippet registers fields immediately', 2 === count( $GLOBALS['lp_test']['registered'] ) );
		require $plugin;
		vcheck( 'plugin after snippet: no redeclare, hooks once', 1 === lp_test_hook_count( 'woocommerce_currency_symbol' ) && 2 === count( $GLOBALS['lp_test']['registered'] ) );
		break;

	case 'no-wc':
		require __DIR__ . '/stubs-wp.php';
		require $plugin;
		do_action( 'plugins_loaded' );
		do_action( 'init' );
		vcheck( 'no WooCommerce: no WooCommerce hooks', 0 === lp_test_hook_count( 'woocommerce_currency_symbol' ) && 0 === lp_test_hook_count( 'woocommerce_init' ) );
		vcheck( 'no WooCommerce: admin notice hook', 1 === lp_test_hook_count( 'admin_notices' ) );
		$GLOBALS['lp_test']['caps'] = array( 'activate_plugins' );
		ob_start();
		do_action( 'admin_notices' );
		$out = ob_get_clean();
		vcheck( 'notice text', false !== strpos( $out, 'needs WooCommerce 9.0 or newer' ) );
		vcheck( 'pure helpers still work', 'ABC 1' === LP_Shop::sanitize_trade_licence( ' abc  1 ' ) );
		eval( $snippet ); // phpcs:ignore
		vcheck( 'snippet eval without WooCommerce: no fatal', class_exists( 'LP_Shop', false ) );
		break;

	case 'old-wc':
		require __DIR__ . '/stubs-wp.php';
		define( 'WC_VERSION', '8.9.0' );
		eval( 'class WooCommerce {} function WC() { return new WooCommerce(); }' ); // phpcs:ignore
		eval( $snippet ); // phpcs:ignore
		do_action( 'plugins_loaded' );
		vcheck( 'WooCommerce 8.9: no hooks, admin notice only', 0 === lp_test_hook_count( 'woocommerce_currency_symbol' ) && 1 === lp_test_hook_count( 'admin_notices' ) );
		break;

	case 'no-abspath':
		$r = eval( $snippet ); // phpcs:ignore
		vcheck( 'without ABSPATH the snippet returns before declaring anything', ! class_exists( 'LP_Shop', false ) );
		break;

	case 'blocks-api':
		require __DIR__ . '/stubs.php';
		require __DIR__ . '/stubs-blocks.php';
		require $plugin;
		do_action( 'plugins_loaded' );
		$o = WC_Order::make( 200, 'a@b.ae', 'processing', array( 890 ), array(), array( '_wc_other/ledgerplus/trade-licence' => 'CN-77' ) );
		vcheck( 'get_field_value via CheckoutFields API', 'CN-77' === LP_Shop::get_field_value( $o, 'ledgerplus/trade-licence' ) );
		vcheck( 'API called with (key, object, "other")', array( 'ledgerplus/trade-licence', 200, 'other' ) === ( $GLOBALS['lp_api_calls'][0] ?? null ) );
		$GLOBALS['lp_api_throw'] = true;
		vcheck( 'API exception -> raw meta fallback', 'CN-77' === LP_Shop::get_field_value( $o, 'ledgerplus/trade-licence' ) );
		break;

	case 'twin-sync':
		$p_src  = file_get_contents( $plugin );
		$marker = "/*\n * Ledger Plus Shop - shared core.";
		$core_p = substr( $p_src, (int) strpos( $p_src, $marker ) );
		$core_s = substr( $snippet, (int) strpos( $snippet, $marker ) );
		preg_match( "/const VERSION = '([0-9.]+)';/", $core_p, $vm );
		$version = $vm[1] ?? '?';
		vcheck( 'shared core found in both files', false !== strpos( $p_src, $marker ) && false !== strpos( $snippet, $marker ) );
		vcheck( 'snippet core = plugin core, byte for byte', $core_p === $core_s );
		vcheck( "plugin header Version = LP_Shop::VERSION ($version)", 1 === preg_match( '/^ \* Version:\s+' . preg_quote( $version, '/' ) . '$/m', $p_src ) );
		vcheck( 'snippet header names the same version', 0 === strpos( $snippet, "/*\n * Ledger Plus Shop - WPCode snippet variant (v$version)." ) );
		vcheck( 'the snippet adds only its header comment before the core', 1 === preg_match( '#^/\*.*?\*/\n$#s', substr( $snippet, 0, (int) strpos( $snippet, $marker ) ) ) );
		$readme = (string) @file_get_contents( $build . '/PLUGIN-README.md' );
		vcheck( "PLUGIN-README.md is for v$version", 0 === strpos( $readme, "# Ledger Plus Shop (v$version)" ) );
		break;

	default:
		echo "unknown mode\n";
		exit( 2 );
}

vcheck( 'no PHP notices/warnings/deprecations', array() === $errors, implode( ' | ', $errors ) );
echo "Result: $pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
