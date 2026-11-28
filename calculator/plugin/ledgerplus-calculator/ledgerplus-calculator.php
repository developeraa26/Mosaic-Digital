<?php
/**
 * Plugin Name:       Ledger Plus Package Calculator
 * Plugin URI:        https://ledgerplus.ae/
 * Description:       Package cost calculator for Ledger Plus UAE. Adds the [lp_package_calculator] shortcode, checks and prices the Elementor Pro form "Ledger Plus package calculator" on the server, and emails the client a branded estimate with a PDF quotation.
 * Version:           1.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Ledger Plus UAE
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ledgerplus-calculator
 *
 * @package LedgerPlusCalculator
 */

defined( 'ABSPATH' ) || exit;

// Loaded twice (for example a second copy in another folder): keep the first one.
if ( defined( 'LP_CALC_VERSION' ) ) {
	return;
}

define( 'LP_CALC_VERSION', '1.1.0' );
define( 'LP_CALC_FILE', __FILE__ );
define( 'LP_CALC_DIR', plugin_dir_path( __FILE__ ) );
define( 'LP_CALC_URL', plugin_dir_url( __FILE__ ) );

require_once LP_CALC_DIR . 'includes/class-lp-calc-pricing.php';
require_once LP_CALC_DIR . 'includes/class-lp-calc-pdf.php';
require_once LP_CALC_DIR . 'includes/class-lp-calc-quote-pdf.php';
require_once LP_CALC_DIR . 'includes/class-lp-calc-mailer.php';
require_once LP_CALC_DIR . 'includes/class-lp-calc-elementor.php';
require_once LP_CALC_DIR . 'includes/class-lp-calc-shortcode.php';

LP_Calc_Shortcode::init();
LP_Calc_Elementor::init();
