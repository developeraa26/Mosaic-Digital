<?php
/**
 * Loads the plugin's LP_Calc_Pricing for the UI tests (exits 2 if it cannot load).
 *
 * Sets $GLOBALS['lp_calc_ui_source'] to 'real'.
 */
require_once __DIR__ . '/stubs.php';

function lp_calc_ui_plugin_dir() {
	return dirname( __DIR__, 2 ) . '/plugin/ledgerplus-calculator';
}

function lp_calc_ui_load_real() {
	if ( class_exists( 'LP_Calc_Pricing', false ) ) {
		return true;
	}
	$file = lp_calc_ui_plugin_dir() . '/includes/class-lp-calc-pricing.php';
	if ( ! is_file( $file ) ) {
		return false;
	}
	try {
		require_once $file;
	} catch ( \Throwable $e ) {
		fwrite( STDERR, '[ui] real pricing class did not load: ' . $e->getMessage() . "\n" );
		return false;
	}
	return class_exists( 'LP_Calc_Pricing', false ) && method_exists( 'LP_Calc_Pricing', 'config' );
}

function lp_calc_ui_config() {
	if ( ! lp_calc_ui_load_real() ) {
		fwrite( STDERR, "[ui] LP_Calc_Pricing is not available\n" );
		exit( 2 );
	}
	$GLOBALS['lp_calc_ui_source'] = 'real';
	return LP_Calc_Pricing::config();
}
