<?php
/**
 * Ledger Plus Package Calculator: backend tests (pure PHP, WordPress and Elementor Pro stubs).
 * Usage: php run-tests.php
 */

require __DIR__ . '/stubs-wp.php';
require __DIR__ . '/stubs-elementor.php';

define( 'LP_TEST_TMP', sys_get_temp_dir() . '/lp-calc-test-' . getmypid() );
define( 'LP_TEST_LOG', LP_TEST_TMP . '/php-error.log' );
@mkdir( LP_TEST_TMP, 0700, true );
ini_set( 'log_errors', '1' );
ini_set( 'error_log', LP_TEST_LOG );

require __DIR__ . '/fixtures.php';

// Every notice, warning or deprecation is a failure.
$GLOBALS['lp_php_errors'] = array();
set_error_handler(
	function ( $no, $str, $file, $line ) {
		if ( error_reporting() & $no ) {
			$GLOBALS['lp_php_errors'][] = "[$no] $str (" . basename( $file ) . ":$line)";
		}
		return true;
	}
);

$plugin = dirname( __DIR__, 2 ) . '/plugin/ledgerplus-calculator/ledgerplus-calculator.php';
require $plugin;

$GLOBALS['lp_results'] = array( 'pass' => 0, 'fail' => 0 );
function check( $name, $cond, $detail = '' ) {
	if ( $cond ) {
		$GLOBALS['lp_results']['pass']++;
		echo "  PASS  $name\n";
	} else {
		$GLOBALS['lp_results']['fail']++;
		echo "  FAIL  $name" . ( '' !== $detail ? "  -- $detail" : '' ) . "\n";
	}
}
function section( $t ) {
	echo "\n== $t ==\n";
}
function line_by_key( array $quote, $key ) {
	foreach ( $quote['lines'] as $line ) {
		if ( $key === $line['key'] ) {
			return $line;
		}
	}
	return null;
}
function sel( array $raw ) {
	$n = LP_Calc_Pricing::normalize(
		array_merge(
			array(
				'business'     => 'Retail',
				'transactions' => '1000',
				'vat_status'   => 'registered',
				'ct_status'    => 'registered',
				'employees'    => 'up_to_20',
				'reports'      => '4',
				'services'     => 'accounting',
			),
			$raw
		)
	);
	return $n;
}
function quote( array $raw ) {
	$n = sel( $raw );
	return LP_Calc_Pricing::compute( $n['selection'] );
}

/**
 * Amounts in page text, by the rules of test/ui/amounts.js: a currency ("AED", also "AED2,150",
 * "dirham", "Dh"/"Dhs", "د.إ"), a non-zero default price as "1,234" or "1234", "Included" as a price
 * label, "extra charge", "free of charge", "per report".
 * Allowed (counts, not prices): transaction volumes ("1,000 transactions a year"), and a count as
 * value="1000" on the step 2 select's <option>s and the hidden form_fields[lpc_transactions] field
 * only, and as "value":1000 / "transactions":1000 in the config JSON.
 *
 * @param string $text Text or markup.
 * @return array The amounts found.
 */
function lp_test_amounts( $text ) {
	$tx    = LP_Calc_Pricing::TRANSACTIONS;
	$count = function ( $tag ) use ( $tx ) {
		return preg_replace_callback(
			'/(\svalue=["\']?)(\d+)(?=["\'\s>])/',
			function ( $m ) use ( $tx ) {
				return in_array( (int) $m[2], $tx, true ) ? $m[1] . '#' : $m[0];
			},
			$tag
		);
	};
	$s     = preg_replace_callback(
		'/\b\d{1,3}(?:,\d{3})* transactions a year\b/',
		function ( $m ) use ( $tx ) {
			return in_array( (int) str_replace( ',', '', $m[0] ), $tx, true ) ? '# transactions a year' : $m[0];
		},
		(string) $text
	);
	$s     = preg_replace_callback(
		'#<select\b[^>]*\sdata-lp-calc-input="transactions"[^>]*>.*?</select>#s',
		function ( $m ) use ( $count ) {
			return preg_replace_callback(
				'/<option\b[^>]*>/',
				function ( $o ) use ( $count ) {
					return $count( $o[0] );
				},
				$m[0]
			);
		},
		$s
	);
	$s     = preg_replace_callback(
		'/<input\b[^>]*\sname="form_fields\[lpc_transactions\]"[^>]*>/',
		function ( $m ) use ( $count ) {
			return $count( $m[0] );
		},
		$s
	);
	$s     = preg_replace_callback(
		'/("(?:value|transactions)":\s*)(\d+)/',
		function ( $m ) use ( $tx ) {
			return in_array( (int) $m[2], $tx, true ) ? $m[1] . '#' : $m[0];
		},
		$s
	);
	$hits = array();
	foreach ( array( '/\bAED(?![a-z])/i', '/dirham/i', '/\bdhs?\b/i', '/د\.?\s*إ/u', '/\bIncluded\b/', '/extra charge/i', '/free of charge/i', '/\bper report\b/i' ) as $re ) {
		if ( preg_match( $re, $s, $m ) ) {
			$hits[] = $m[0];
		}
	}
	foreach ( array_unique( array_filter( LP_Calc_Pricing::default_prices() ) ) as $price ) {
		foreach ( array( number_format( $price ), (string) $price ) as $needle ) {
			if ( preg_match( '/(?<![\d.,])' . preg_quote( $needle, '/' ) . '(?![\d]|[.,]\d)/', $s ) ) {
				$hits[] = $needle;
			}
		}
	}
	return array_values( array_unique( $hits ) );
}

/** Every string (keys included) of a nested array. */
function lp_test_strings( $value, array &$out = array() ) {
	if ( is_string( $value ) ) {
		$out[] = $value;
	} elseif ( is_array( $value ) ) {
		foreach ( $value as $k => $v ) {
			$out[] = (string) $k;
			lp_test_strings( $v, $out );
		}
	}
	return $out;
}

$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
add_filter(
	'lp_calc_logo_path',
	function () {
		return LP_TEST_LOGO;
	}
);
lp_test_reset();

/* ====================================================================== Boot */
section( 'Boot' );
check( 'constants defined, version 1.1.0', defined( 'LP_CALC_VERSION' ) && '1.1.0' === LP_CALC_VERSION && LP_CALC_FILE === realpath( $plugin ) || LP_CALC_FILE === $plugin );
check( 'LP_CALC_DIR / LP_CALC_URL', '/' === substr( LP_CALC_DIR, -1 ) && 0 === strpos( LP_CALC_URL, 'https://' ) );
foreach ( array( 'LP_Calc_Pricing', 'LP_Calc_PDF', 'LP_Calc_Quote_PDF', 'LP_Calc_Mailer', 'LP_Calc_Elementor', 'LP_Calc_Shortcode' ) as $class ) {
	check( "class $class", class_exists( $class, false ) );
}
check( 'validation hook at priority 10, 2 args', array( 10, 2 ) === lp_test_hook_priority( 'elementor_pro/forms/validation', array( 'LP_Calc_Elementor', 'validate' ) ) );
check( 'process hook (end of process_fields) at priority 10, 2 args', array( 10, 2 ) === lp_test_hook_priority( 'elementor_pro/forms/process', array( 'LP_Calc_Elementor', 'process' ) ) );
check( 'actions_before filter at priority 10, 2 args', array( 10, 2 ) === lp_test_hook_priority( 'elementor_pro/forms/record/actions_before', array( 'LP_Calc_Elementor', 'actions_before' ) ) );
check( 'new_record hook at priority 20, 2 args', array( 20, 2 ) === lp_test_hook_priority( 'elementor_pro/forms/new_record', array( 'LP_Calc_Elementor', 'new_record' ) ) );
require $plugin; // A second load must be a no-op.
LP_Calc_Elementor::init();
LP_Calc_Shortcode::init();
check( 'loading twice / init twice adds no hooks', 1 === lp_test_hook_count( 'elementor_pro/forms/validation' ) && 1 === lp_test_hook_count( 'elementor_pro/forms/process' ) && 1 === lp_test_hook_count( 'init' ) );
do_action( 'init' );
check( 'shortcode registered on init', isset( $GLOBALS['lp_test']['shortcodes']['lp_package_calculator'] ) );
check( 'assets registered: version 1.1.0 (LP_CALC_VERSION), jquery dependency, footer', '1.1.0' === $GLOBALS['lp_test']['scripts']['lp-calc']['ver'] && array( 'jquery' ) === $GLOBALS['lp_test']['scripts']['lp-calc']['deps'] && true === $GLOBALS['lp_test']['scripts']['lp-calc']['footer'] && '1.1.0' === $GLOBALS['lp_test']['styles']['lp-calc']['ver'] );
$head = file_get_contents( $plugin );
foreach ( array( 'Plugin Name:       Ledger Plus Package Calculator', 'Version:           1.1.0', 'Requires at least: 6.0', 'Requires PHP:      7.4', 'Text Domain:       ledgerplus-calculator' ) as $h ) {
	check( "header: $h", false !== strpos( $head, $h ) );
}
$readme = file_get_contents( dirname( $plugin ) . '/readme.txt' );
check( 'readme.txt: Stable tag 1.1.0 and a 1.1.0 changelog entry', false !== strpos( $readme, "Stable tag: 1.1.0\n" ) && false !== strpos( $readme, "= 1.1.0 =\n* Prices show only after the visitor sends their details; the page no longer contains prices." ) );

/* ====================================================================== Config */
section( 'Config' );
$c = LP_Calc_Pricing::config();
check( 'currency, vat_included, vat_rate', 'AED' === $c['currency'] && true === $c['vat_included'] && 5 === $c['vat_rate'] );
check( '8 business types in order', array( 'Trading / Wholesale', 'Retail', 'Restaurant / Hospitality', 'E-commerce', 'Construction / Contracting', 'Real Estate', 'Professional Services', 'Other' ) === array_column( $c['business_types'], 'value' ) );
check( 'transactions values and labels', array( 1000, 2000, 3000, 4000, 5000, 10000, 20000, 30000, 40000, 50000 ) === array_column( $c['transactions'], 'value' ) && '1,000 transactions a year' === $c['transactions'][0]['label'] && '50,000 transactions a year' === $c['transactions'][9]['label'] );
check( 'reports_options 1-4', array( 1, 2, 3, 4 ) === $c['reports_options'] );
check( 'employees value/label', array( array( 'value' => 'up_to_20', 'label' => 'Up to 20 employees' ), array( 'value' => 'over_20', 'label' => 'More than 20 employees' ) ) === $c['employees'] );
check( 'statuses value/label', array( array( 'value' => 'registered', 'label' => 'Already registered' ), array( 'value' => 'not_registered', 'label' => 'Not registered' ) ) === $c['statuses'] );
check( '9 services in table order', LP_Calc_Pricing::SERVICE_KEYS === array_column( $c['services'], 'key' ) );
$hints = array_column( $c['services'], 'hint', 'key' );
check( 'service hints', 'AED 2,000 a year' === $hints['accounting'] && 'AED 500 per report' === $hints['reports'] && 'From AED 2,000 a year' === $hints['vat'] && 'From AED 1,500 a year' === $hints['ct'] && 'AED 500 per 1,000 transactions' === $hints['einvoicing'] && 'Included' === $hints['account_manager'], json_encode( $hints ) );
check( 'every service has key, label, hint', count( array_filter( $c['services'], function ( $s ) { return isset( $s['key'], $s['label'], $s['hint'] ) && '' !== $s['label']; } ) ) === 9 );
check( 'prices', LP_Calc_Pricing::default_prices() === $c['prices'] && 2000 === $c['prices']['accounting'] && 500 === $c['prices']['einvoicing_per_1000'] && 0 === $c['prices']['account_manager'] );
check( 'config is JSON-encodable', false !== wp_json_encode( $c ) );
check( 'config() keeps every key of 1.0.0 (the server prices with it)', array( 'currency', 'vat_included', 'vat_rate', 'total_label', 'business_types', 'transactions', 'reports_options', 'employees', 'statuses', 'services', 'prices', 'defaults' ) === array_keys( $c ) );
check( 'service descriptions state no price', array() === lp_test_amounts( implode( "\n", array_column( $c['services'], 'description' ) ) ) && 'One point of contact for your account.' === $c['services'][8]['description'], json_encode( array_column( $c['services'], 'description' ) ) );

/* ====================================================================== browser_config() */
section( 'browser_config(): what the page gets, without prices' );
$b = LP_Calc_Pricing::browser_config();
check( 'keys: choices, labels, defaults, total label only', array( 'business_types', 'transactions', 'reports_options', 'employees', 'statuses', 'services', 'defaults', 'total_label' ) === array_keys( $b ), implode( ',', array_keys( $b ) ) );
$bs = lp_test_strings( $b );
check( 'no prices, currency, vat_rate, vat_included or hint key anywhere', array() === array_intersect( array( 'prices', 'currency', 'vat_rate', 'vat_included', 'hint' ), $bs ) );
check( 'no amount in any string (transaction counts allowed)', array() === lp_test_amounts( implode( "\n", $bs ) . "\n" . wp_json_encode( $b ) ), json_encode( lp_test_amounts( implode( "\n", $bs ) . wp_json_encode( $b ) ) ) );
check( 'same choices and labels as config()', $c['business_types'] === $b['business_types'] && $c['transactions'] === $b['transactions'] && $c['reports_options'] === $b['reports_options'] && $c['employees'] === $b['employees'] && $c['statuses'] === $b['statuses'] && $c['defaults'] === $b['defaults'] && $c['total_label'] === $b['total_label'] );
check( 'services: key, label, description of config(), no hint', array_map( function ( $x ) { return array( 'key' => $x['key'], 'label' => $x['label'], 'description' => $x['description'] ); }, $c['services'] ) === $b['services'] );
add_filter( 'lp_calc_config', $pricey = function ( $cfg ) {
	$cfg['prices']['accounting']        = 2150;
	$cfg['services'][0]['hint']        = 'AED 2,150 a year';
	$cfg['services'][0]['description'] = 'Bookkeeping all year for AED 2,150.';
	$cfg['services'][8]['description'] = 'One point of contact, at no extra charge.';
	$cfg['services'][1]['label']       = 'Financial reports';
	$cfg['price_list']                 = array( 'accounting' => 2150 );
	$cfg['defaults']['note']           = 'AED 650 per 1,000 transactions';
	return $cfg;
} );
$bf = LP_Calc_Pricing::browser_config();
$cf = LP_Calc_Pricing::config();
remove_filter( 'lp_calc_config', $pricey );
check( 'lp_calc_config filter applies first (labels follow it)', 'Financial reports' === $bf['services'][1]['label'] && 2150 === $cf['prices']['accounting'] );
check( 'filtered: a description that states a price is left out', ! isset( $bf['services'][0]['description'] ) && ! isset( $bf['services'][8]['description'] ) && isset( $bf['services'][2]['description'] ) );
check( 'filtered: unknown keys (price_list, defaults.note) are not copied', ! isset( $bf['price_list'] ) && ! isset( $bf['defaults']['note'] ) );
$bfs = implode( "\n", lp_test_strings( $bf ) ) . wp_json_encode( $bf );
check( 'filtered: no amount and no filtered price', array() === lp_test_amounts( $bfs ) && false === strpos( $bfs, '2150' ) && false === strpos( $bfs, '2,150' ) && false === strpos( $bfs, '650' ) );
check( 'mentions_price(): AED, extra charge; not scope words', LP_Calc_Pricing::mentions_price( 'From aed 500' ) && LP_Calc_Pricing::mentions_price( 'at no extra charge' ) && ! LP_Calc_Pricing::mentions_price( 'Registration included if you are not registered.' ) && ! LP_Calc_Pricing::mentions_price( 'Daedalus Trading' ) );
$priced   = array( 'now AED2,150', 'from 2,150 dirhams', 'Dhs 1,000 a year', 'د.إ 900', 'One point of contact, Included.', 'AED 500 per report', 'Bookkeeping all year, from 2,000 a year.', 'Payroll from 1000 a year', 'USD 99', '$99 a month', 'at no charge' );
$unpriced = array( '1,000 transactions a year', '50,000 transactions a year', 'Up to 20 employees', 'More than 20 employees', 'Estimated annual total (incl. 5% VAT)', 'Financial reports each quarter, 1 to 4 a year.', 'UAE e-invoicing, priced by your annual transactions.', 'One point of contact for your account.', 'Registration included if you are not registered.', 'Daedalus Trading', 'Retail', '4 reports a year' );
check( 'mentions_price(): other spellings of a price', array() === array_values( array_filter( $priced, function ( $t ) { return ! LP_Calc_Pricing::mentions_price( $t ); } ) ), json_encode( array_values( array_filter( $priced, function ( $t ) { return ! LP_Calc_Pricing::mentions_price( $t ); } ) ) ) );
check( 'mentions_price(): counts and the default texts are not prices', array() === array_values( array_filter( $unpriced, array( 'LP_Calc_Pricing', 'mentions_price' ) ) ), json_encode( array_values( array_filter( $unpriced, array( 'LP_Calc_Pricing', 'mentions_price' ) ) ) ) );
check( 'mentions_price(): a filtered price as a bare number', LP_Calc_Pricing::mentions_price( 'Bookkeeping, 2,150 a year', array( 'accounting' => 2150 ) ) && ! LP_Calc_Pricing::mentions_price( 'Bookkeeping, 2,150 a year' ) );
check( 'amount scanner: a price as value= outside the step 2 options and lpc_transactions is found', array() !== lp_test_amounts( '<div data-price-value="2000">' ) && array() !== lp_test_amounts( '<input type="hidden" name="lp_total_value" value="2000">' ) && array() !== lp_test_amounts( '<option value="2000">' ) && array() !== lp_test_amounts( 'now AED2,150' ) && array() !== lp_test_amounts( '2,150 dirhams' ) );
check( 'amount scanner: the documented counts are allowed', array() === lp_test_amounts( '<select class="lp-calc__input" data-lp-calc-input="transactions"><option value="2000" selected>2,000 transactions a year</option></select><input type="hidden" name="form_fields[lpc_transactions]" value="1000">{"value":1000,"transactions":1000}' ) );

// A site owner's filter that writes prices into every text the page gets.
add_filter( 'lp_calc_config', $strict = function ( $cfg ) {
	$cfg['prices']['accounting']               = 2150;
	$cfg['services'][0]['label']               = 'Accounting (AED 2,150)';
	$cfg['services'][0]['description']         = 'Bookkeeping all year, now AED2,150.';
	$cfg['services'][1]['description']         = 'From 2,150 dirhams.';
	$cfg['services'][4]['description']         = 'Payroll runs, Dhs 1,000 a year.';
	$cfg['services'][8]['description']         = 'One point of contact, Included.';
	$cfg['services'][]                         = array( 'key' => 'audit', 'label' => 'External audit (AED 3,000)', 'hint' => 'AED 3,000', 'description' => 'Annual audit.' );
	$cfg['services'][]                         = array( 'key' => 'payroll_plus', 'label' => 'Payroll plus', 'price' => 700 );
	foreach ( $cfg['transactions'] as $i => $t ) {
		$cfg['transactions'][ $i ]['label'] = number_format( $t['value'] ) . ' transactions a year (AED ' . number_format( $t['value'] / 2 ) . ')';
		$cfg['transactions'][ $i ]['price'] = $t['value'] / 2;
	}
	$cfg['transactions'][]                     = array( 'value' => 60000, 'label' => '60,000 transactions (AED 30,000)' );
	$cfg['employees'][1]['label']              = 'More than 20 employees (+AED 1,000)';
	$cfg['statuses'][1]['label']               = 'Not registered (registration AED 500)';
	$cfg['business_types'][0]['label']         = 'Trading, from AED 2,000';
	$cfg['business_types'][]                   = array( 'value' => 'Shipping (AED 9,999)', 'label' => 'Shipping' );
	$cfg['reports_options']                    = array( array( 'value' => 2, 'label' => '2 reports, AED 1,000' ), 4 );
	$cfg['defaults']['employees']              = 'AED 1,000';
	$cfg['defaults']['services']               = array( 'einvoicing', 'audit' );
	$cfg['total_label']                        = 'Estimated annual total in AED';
	return $cfg;
} );
$bx  = LP_Calc_Pricing::browser_config();
$cx  = LP_Calc_Pricing::config();
remove_filter( 'lp_calc_config', $strict );
$bxs = implode( "\n", lp_test_strings( $bx ) ) . wp_json_encode( $bx );
check( 'strict filter: config() keeps the filtered texts (the server is unchanged)', 'Accounting (AED 2,150)' === $cx['services'][0]['label'] && 2150 === $cx['prices']['accounting'] );
check( 'strict filter: no amount and no filtered price anywhere in browser_config()', array() === lp_test_amounts( $bxs ) && false === strpos( $bxs, '2150' ) && false === strpos( $bxs, '2,150' ) && false === strpos( $bxs, 'External audit' ) && false === strpos( $bxs, '9,999' ) && false === strpos( $bxs, '60,000' ) && false === strpos( $bxs, '60000' ) && false === strpos( $bxs, '"price"' ), json_encode( lp_test_amounts( $bxs ) ) );
check( 'strict filter: a priced service label falls back to the plugin label', 'Accounting and bookkeeping' === $bx['services'][0]['label'] );
check( 'strict filter: priced descriptions in any spelling are left out, clean ones kept', ! isset( $bx['services'][0]['description'] ) && ! isset( $bx['services'][1]['description'] ) && ! isset( $bx['services'][4]['description'] ) && ! isset( $bx['services'][8]['description'] ) && isset( $bx['services'][2]['description'] ) );
check( 'strict filter: an added service with a priced label is left out; other added keys are not copied', ! in_array( 'audit', array_column( $bx['services'], 'key' ), true ) && array( 'key' => 'payroll_plus', 'label' => 'Payroll plus' ) === end( $bx['services'] ) );
check( 'strict filter: option labels fall back to the plugin labels, options carry value and label only', $cx['business_types'] !== $bx['business_types'] && LP_Calc_Pricing::config()['transactions'] === $bx['transactions'] && 'More than 20 employees' === $bx['employees'][1]['label'] && 'Not registered' === $bx['statuses'][1]['label'] && 'Trading / Wholesale' === $bx['business_types'][0]['label'] );
check( 'strict filter: an added option with a priced value or label is left out', 10 === count( $bx['transactions'] ) && ! in_array( 'Shipping (AED 9,999)', array_column( $bx['business_types'], 'value' ), true ) && 8 === count( $bx['business_types'] ) );
check( 'strict filter: reports options as numbers only', array( 2, 4 ) === $bx['reports_options'] );
check( 'strict filter: defaults limited to what the page offers', array( 'transactions' => 1000, 'reports' => 4, 'services' => array( 'einvoicing' ) ) === $bx['defaults'], json_encode( $bx['defaults'] ) );
check( 'strict filter: a priced total label falls back to the plugin label', LP_Calc_Pricing::TOTAL_LABEL === $bx['total_label'] );
check( 'strict filter: package lines use the clean labels', array( 'More than 20 employees', '1,000 transactions a year' ) === array_column( LP_Calc_Pricing::package_lines( array( 'services' => array( 'payroll', 'einvoicing' ), 'employees' => 'over_20', 'transactions' => 1000 ), $bx ), 'detail' ) );

$pk = LP_Calc_Pricing::package_lines( LP_Calc_Pricing::normalize( array( 'business' => 'Trading / Wholesale', 'transactions' => '1000', 'vat_status' => 'registered', 'ct_status' => 'not_registered', 'employees' => 'up_to_20', 'services' => 'accounting,vat,ct,payroll' ) )['selection'] );
check( 'package_lines(): Excel example', array( array( 'key' => 'accounting', 'label' => 'Accounting and bookkeeping', 'detail' => 'Annual service' ), array( 'key' => 'vat', 'label' => 'VAT returns filing and compliance', 'detail' => 'Already VAT registered' ), array( 'key' => 'ct', 'label' => 'Corporate Tax registration and filing', 'detail' => 'Includes Corporate Tax registration' ), array( 'key' => 'payroll', 'label' => 'Payroll and WPS support', 'detail' => 'Up to 20 employees' ) ) === $pk, json_encode( $pk ) );
$pk = LP_Calc_Pricing::package_lines( array( 'services' => array( 'reports', 'vat', 'ct', 'payroll', 'einvoicing', 'account_manager' ), 'reports' => 1, 'vat_status' => 'not_registered', 'ct_status' => 'registered', 'employees' => 'over_20', 'transactions' => 20000 ) );
check( 'package_lines(): details without prices', array( '1 report a year', 'Includes VAT registration', 'Already registered for Corporate Tax', 'More than 20 employees', '20,000 transactions a year', 'One point of contact' ) === array_column( $pk, 'detail' ), json_encode( array_column( $pk, 'detail' ) ) );

/* ====================================================================== Pricing rules */
section( 'Pricing rules' );
$q = quote( array( 'services' => 'accounting' ) );
check( 'accounting 2,000 "Annual service"', 2000 === $q['total'] && 'Annual service' === $q['lines'][0]['detail'] && 'Accounting and bookkeeping' === $q['lines'][0]['label'] );
foreach ( array( 1, 2, 3, 4 ) as $n ) {
	$q = quote( array( 'services' => 'reports', 'reports' => (string) $n ) );
	check( "reports x$n = " . ( 500 * $n ), 500 * $n === $q['total'] && ( 1 === $n ? '1 report a year × AED 500' : "$n reports a year × AED 500" ) === $q['lines'][0]['detail'], $q['lines'][0]['detail'] );
}
$q = quote( array( 'services' => 'vat', 'vat_status' => 'registered' ) );
check( 'VAT registered 2,000 "Already VAT registered"', 2000 === $q['total'] && 'Already VAT registered' === $q['lines'][0]['detail'] );
$q = quote( array( 'services' => 'vat', 'vat_status' => 'not_registered' ) );
check( 'VAT not registered 2,500 "Includes VAT registration (AED 500)"', 2500 === $q['total'] && 'Includes VAT registration (AED 500)' === $q['lines'][0]['detail'] );
$q = quote( array( 'services' => 'ct', 'ct_status' => 'registered' ) );
check( 'CT registered 1,500', 1500 === $q['total'] && 'Already registered for Corporate Tax' === $q['lines'][0]['detail'] );
$q = quote( array( 'services' => 'ct', 'ct_status' => 'not_registered' ) );
check( 'CT not registered 2,000', 2000 === $q['total'] && 'Includes Corporate Tax registration' === $q['lines'][0]['detail'] );
$q = quote( array( 'services' => 'payroll', 'employees' => 'up_to_20' ) );
check( 'payroll up to 20: 1,000', 1000 === $q['total'] && 'Up to 20 employees' === $q['lines'][0]['detail'] );
$q = quote( array( 'services' => 'payroll', 'employees' => 'over_20' ) );
check( 'payroll over 20: 2,000', 2000 === $q['total'] && 'More than 20 employees' === $q['lines'][0]['detail'] );
check( 'expense 500', 500 === quote( array( 'services' => 'expense' ) )['total'] );
check( 'invoicing 1,000', 1000 === quote( array( 'services' => 'invoicing' ) )['total'] );
foreach ( LP_Calc_Pricing::TRANSACTIONS as $t ) {
	$q = quote( array( 'services' => 'einvoicing', 'transactions' => (string) $t ) );
	check( "e-invoicing $t tx = " . 500 * (int) ceil( $t / 1000 ), 500 * (int) ceil( $t / 1000 ) === $q['total'] );
}
$q = quote( array( 'services' => 'einvoicing', 'transactions' => '10000' ) );
check( 'e-invoicing detail', '10,000 transactions a year (AED 500 per 1,000)' === $q['lines'][0]['detail'], $q['lines'][0]['detail'] );
$q = quote( array( 'services' => 'account_manager' ) );
check( 'account manager 0, "Included"', 0 === $q['total'] && 'Included' === $q['lines'][0]['detail'] && 'Included' === LP_Calc_Pricing::line_amount_text( $q['lines'][0] ) );
check( 'transactions affect only e-invoicing', quote( array( 'services' => 'accounting,vat,ct,payroll,reports,expense,invoicing,account_manager', 'transactions' => '1000' ) )['total'] === quote( array( 'services' => 'accounting,vat,ct,payroll,reports,expense,invoicing,account_manager', 'transactions' => '50000' ) )['total'] );

// Excel default example.
$excel = LP_Calc_Pricing::normalize( array( 'business' => 'Trading / Wholesale', 'transactions' => '1000', 'vat_status' => 'registered', 'ct_status' => 'not_registered', 'employees' => 'up_to_20', 'services' => 'accounting,vat,ct,payroll' ) );
$q     = LP_Calc_Pricing::compute( $excel['selection'] );
check( 'Excel example: no errors', array() === $excel['errors'] );
check( 'Excel example total AED 7,000', 7000 === $q['total'] && 'AED 7,000' === LP_Calc_Pricing::money( $q['total'] ) );
check( 'Excel example lines in table order', array( 'accounting', 'vat', 'ct', 'payroll' ) === array_column( $q['lines'], 'key' ) && array( 2000, 2000, 2000, 1000 ) === array_column( $q['lines'], 'amount' ) );
check( 'line keys: key, label, detail, amount(int)', array( 'key', 'label', 'detail', 'amount' ) === array_keys( $q['lines'][0] ) && is_int( $q['lines'][0]['amount'] ) );

// Maximum.
$max = LP_Calc_Pricing::normalize( array( 'business' => 'Retail', 'transactions' => '50000', 'vat_status' => 'not_registered', 'ct_status' => 'not_registered', 'employees' => 'over_20', 'reports' => '4', 'services' => implode( ',', LP_Calc_Pricing::SERVICE_KEYS ) ) );
$qm  = LP_Calc_Pricing::compute( $max['selection'] );
check( 'maximum: 9 lines, total AED 37,000', 9 === count( $qm['lines'] ) && 37000 === $qm['total'], (string) $qm['total'] );
check( 'maximum line amounts', array( 2000, 2000, 2500, 2000, 2000, 500, 1000, 25000, 0 ) === array_column( $qm['lines'], 'amount' ) );

// Every combination of the inputs that affect price, against an independent formula.
$combos  = 0;
$bad     = 0;
$bad_pkg = 0;
for ( $mask = 1; $mask < 512; $mask++ ) {
	$keys = array();
	foreach ( LP_Calc_Pricing::SERVICE_KEYS as $i => $k ) {
		if ( $mask & ( 1 << $i ) ) {
			$keys[] = $k;
		}
	}
	foreach ( array( 'registered', 'not_registered' ) as $vat ) {
		foreach ( array( 'registered', 'not_registered' ) as $ct ) {
			foreach ( in_array( 'payroll', $keys, true ) ? array( 'up_to_20', 'over_20' ) : array( 'up_to_20' ) as $emp ) {
				foreach ( in_array( 'reports', $keys, true ) ? array( 1, 2, 3, 4 ) : array( 4 ) as $rep ) {
					foreach ( in_array( 'einvoicing', $keys, true ) ? LP_Calc_Pricing::TRANSACTIONS : array( 1000 ) as $tx ) {
						$expect = 0;
						$expect += in_array( 'accounting', $keys, true ) ? 2000 : 0;
						$expect += in_array( 'reports', $keys, true ) ? 500 * $rep : 0;
						$expect += in_array( 'vat', $keys, true ) ? ( 'not_registered' === $vat ? 2500 : 2000 ) : 0;
						$expect += in_array( 'ct', $keys, true ) ? ( 'registered' === $ct ? 1500 : 2000 ) : 0;
						$expect += in_array( 'payroll', $keys, true ) ? ( 'over_20' === $emp ? 2000 : 1000 ) : 0;
						$expect += in_array( 'expense', $keys, true ) ? 500 : 0;
						$expect += in_array( 'invoicing', $keys, true ) ? 1000 : 0;
						$expect += in_array( 'einvoicing', $keys, true ) ? 500 * (int) ceil( $tx / 1000 ) : 0;
						$n = LP_Calc_Pricing::normalize( array( 'business' => 'Retail', 'transactions' => (string) $tx, 'vat_status' => $vat, 'ct_status' => $ct, 'employees' => $emp, 'reports' => (string) $rep, 'services' => implode( ',', $keys ) ) );
						$got = LP_Calc_Pricing::compute( $n['selection'] );
						$sum = array_sum( array_column( $got['lines'], 'amount' ) );
						$pkg = LP_Calc_Pricing::package_lines( $n['selection'] );
						$combos++;
						if ( $n['errors'] || $got['total'] !== $expect || $sum !== $got['total'] || count( $got['lines'] ) !== count( $keys ) ) {
							$bad++;
						}
						if ( array_column( $pkg, 'key' ) !== array_column( $got['lines'], 'key' ) || array() !== lp_test_amounts( implode( "\n", array_column( $pkg, 'detail' ) ) ) ) {
							$bad_pkg++;
						}
					}
				}
			}
		}
	}
}
check( "all $combos price combinations match the rules", 0 === $bad, "$bad wrong" );
check( "all $combos: package_lines() lists the quote's services in order, without an amount", 0 === $bad_pkg, "$bad_pkg wrong" );

check( 'money()', 'AED 7,000' === LP_Calc_Pricing::money( 7000 ) && 'AED 0' === LP_Calc_Pricing::money( 0 ) && 'AED 37,000' === LP_Calc_Pricing::money( 37000 ) && 'AED 500' === LP_Calc_Pricing::money( 500 ) );

$expect_summary = "Quote LPQ-261127-4F2A9\nBusiness: Trading / Wholesale\nAnnual transactions: 1,000\nVAT: Already registered\nCorporate Tax: Not registered\nEmployees: Up to 20 employees\n\nServices:\n- Accounting and bookkeeping: AED 2,000\n- VAT returns filing and compliance: AED 2,000\n- Corporate Tax registration and filing: AED 2,000\n- Payroll and WPS support: AED 1,000\n\nEstimated annual total (incl. 5% VAT): AED 7,000";
check( 'summary_text (Excel example)', $expect_summary === LP_Calc_Pricing::summary_text( $excel['selection'], $q, 'LPQ-261127-4F2A9' ), LP_Calc_Pricing::summary_text( $excel['selection'], $q, 'LPQ-261127-4F2A9' ) );
$sm = LP_Calc_Pricing::summary_text( $max['selection'], $qm, 'LPQ-261127-4F2A9' );
check( 'summary_text: reports line, Included, no employees line without payroll', false !== strpos( $sm, "Reports a year: 4\n" ) && false !== strpos( $sm, '- Dedicated account manager: Included' ) && false === strpos( LP_Calc_Pricing::summary_text( sel( array() )['selection'], quote( array() ), 'X' ), 'Employees:' ) );
$other = sel( array( 'business' => 'Other', 'business_other' => 'Yacht charter' ) );
check( 'summary_text: Other with text', false !== strpos( LP_Calc_Pricing::summary_text( $other['selection'], quote( array() ), 'X' ), "Business: Other (Yacht charter)\n" ) );

// Prices come from the (filtered) config.
add_filter( 'lp_calc_config', $raise = function ( $cfg ) {
	$cfg['prices']['accounting'] = 2100;
	$cfg['prices']['vat']        = 'not a number';
	return $cfg;
} );
check( 'lp_calc_config filter changes compute(); broken values fall back', 2100 + 2000 === quote( array( 'services' => 'accounting,vat' ) )['total'] );
remove_filter( 'lp_calc_config', $raise );
check( 'filter removed: back to 2,000', 2000 === quote( array( 'services' => 'accounting' ) )['total'] );

/* ====================================================================== normalize() */
section( 'normalize() rejections and defaults' );
$errs = function ( array $raw ) {
	return sel( $raw )['errors'];
};
check( 'missing business', isset( $errs( array( 'business' => '' ) )['business'] ) );
check( 'unknown business', isset( $errs( array( 'business' => 'Banking' ) )['business'] ) );
check( 'business is case-insensitive and canonicalised', array() === $errs( array( 'business' => ' retail ' ) ) && 'Retail' === sel( array( 'business' => 'retail' ) )['selection']['business'] );
check( 'business as array is rejected', isset( $errs( array( 'business' => array( 'Retail' ) ) )['business'] ) );
check( 'Other without text', isset( $errs( array( 'business' => 'Other', 'business_other' => '  ' ) )['business_other'] ) );
check( 'Other with 81 characters', isset( $errs( array( 'business' => 'Other', 'business_other' => str_repeat( 'a', 81 ) ) )['business_other'] ) );
check( 'Other with 80 multibyte characters is fine', array() === $errs( array( 'business' => 'Other', 'business_other' => str_repeat( 'ب', 80 ) ) ) );
check( 'Other text is cleaned (tags, brackets, white space)', 'Yacht (x) charter' === sel( array( 'business' => 'Other', 'business_other' => "<b>Yacht</b> [x]\n  charter" ) )['selection']['business_other'] );
check( 'Other text only from tags is empty', isset( $errs( array( 'business' => 'Other', 'business_other' => '<img src=x onerror=alert(1)>' ) )['business_other'] ) );
foreach ( array( 'Refund form at https://evil.example/r', 'see www.example.test', 'pay@example.test', 'mailto:x' ) as $link ) {
	check( "Other text that becomes a link is rejected: $link", 'Please describe your business activity without a web or email address.' === ( $errs( array( 'business' => 'Other', 'business_other' => $link ) )['business_other'] ?? '' ) );
}
check( 'Other text with a dot or colon is fine', array() === $errs( array( 'business' => 'Other', 'business_other' => 'Logistics: freight, 3.5t trucks' ) ) );
check( 'employees label accepted (what actions_before writes back)', 'over_20' === sel( array( 'services' => 'payroll', 'employees' => 'More than 20 employees' ) )['selection']['employees'] );
check( 'business_other dropped when business is not Other', '' === sel( array( 'business' => 'Retail', 'business_other' => 'ignored' ) )['selection']['business_other'] );
check( 'transactions 1500 rejected', isset( $errs( array( 'transactions' => '1500' ) )['transactions'] ) );
check( 'transactions "abc" / "" / "-1000" rejected', isset( $errs( array( 'transactions' => 'abc' ) )['transactions'] ) && isset( $errs( array( 'transactions' => '' ) )['transactions'] ) && isset( $errs( array( 'transactions' => '-1000' ) )['transactions'] ) );
check( 'transactions "10,000" accepted', 10000 === sel( array( 'transactions' => '10,000' ) )['selection']['transactions'] );
check( 'vat_status "yes" rejected', isset( $errs( array( 'vat_status' => 'yes' ) )['vat_status'] ) );
check( 'ct_status missing rejected', isset( $errs( array( 'ct_status' => '' ) )['ct_status'] ) );
check( 'employees invalid with payroll rejected', isset( $errs( array( 'services' => 'payroll', 'employees' => '25' ) )['employees'] ) );
$n = sel( array( 'services' => 'accounting', 'employees' => 'bogus' ) );
check( 'employees ignored without payroll, set to default', array() === $n['errors'] && 'up_to_20' === $n['selection']['employees'] );
check( 'reports 5 / 0 / "" rejected with reports', isset( $errs( array( 'services' => 'reports', 'reports' => '5' ) )['reports'] ) && isset( $errs( array( 'services' => 'reports', 'reports' => '0' ) )['reports'] ) && isset( $errs( array( 'services' => 'reports', 'reports' => '' ) )['reports'] ) );
$n = sel( array( 'services' => 'accounting', 'reports' => '9' ) );
check( 'reports ignored without reports, set to default 4', array() === $n['errors'] && 4 === $n['selection']['reports'] );
check( 'no services rejected', 'Choose at least one service.' === ( $errs( array( 'services' => '' ) )['services'] ?? '' ) && isset( $errs( array( 'services' => ' , ,' ) )['services'] ) );
check( 'unknown service rejected', isset( $errs( array( 'services' => 'accounting,free_money' ) )['services'] ) );
$n = sel( array( 'services' => 'einvoicing, ACCOUNTING,accounting,Payroll and WPS support' ) );
check( 'services: trimmed, case-folded, labels accepted, deduplicated, table order', array( 'accounting', 'payroll', 'einvoicing' ) === $n['selection']['services'] && array() === $n['errors'] );
check( 'services as an array', array( 'accounting', 'vat' ) === sel( array( 'services' => array( 'vat', 'accounting' ) ) )['selection']['services'] );
$all = LP_Calc_Pricing::normalize( array() );
check( 'empty input: errors for business, transactions, vat, ct, services', array( 'services', 'business', 'transactions', 'vat_status', 'ct_status' ) === array_keys( $all['errors'] ) );
check( 'errors are short readable sentences', count( array_filter( $all['errors'], function ( $m ) { return strlen( $m ) < 120 && '.' === substr( $m, -1 ); } ) ) === count( $all['errors'] ) );

/* ====================================================================== Validation hook */
section( 'Validation hook (elementor_pro/forms/validation)' );
lp_test_reset();
$r = lp_test_submit();
check( 'valid submission succeeds', true === $r['success'], json_encode( $r['data'] ) );
check( 'success message from the form settings', 'Thank you. Your quotation is on its way to your inbox.' === $r['data']['message'] );
check( 'all three actions ran', 1 === count( $GLOBALS['lp_test']['saved'] ) && 1 === count( $GLOBALS['lp_test']['team_email'] ) && 1 === count( $GLOBALS['lp_test']['zoho'] ) );

lp_test_reset();
$r = lp_test_submit( array( 'lpc_services' => '' ) );
check( 'no services: blocked before the actions', false === $r['success'] && empty( $GLOBALS['lp_test']['zoho'] ) && empty( $GLOBALS['lp_test']['mails'] ) && empty( $GLOBALS['lp_test']['saved'] ) );
check( 'no services: message shown and set on lpc_services', 'Choose at least one service.' === $r['data']['message'] && 'Choose at least one service.' === $r['data']['errors']['lpc_services'] );

lp_test_reset();
$r = lp_test_submit( array( 'lpc_business' => 'Other', 'lpc_business_other' => '', 'lpc_vat_status' => 'maybe' ) );
check( 'two selection errors: both messages, joined with <br>', false === $r['success'] && 2 === substr_count( $r['data']['message'], '.' ) && false !== strpos( $r['data']['message'], '<br>' ) && isset( $r['data']['errors']['lpc_business_other'], $r['data']['errors']['lpc_vat_status'] ) );

$cases = array(
	'name 1 character'            => array( array( 'name' => 'A' ), 'name' ),
	'name 81 characters'          => array( array( 'name' => str_repeat( 'n', 81 ) ), 'name' ),
	'company 1 character'         => array( array( 'company' => 'X' ), 'company' ),
	'company 121 characters'      => array( array( 'company' => str_repeat( 'c', 121 ) ), 'company' ),
	'email invalid'               => array( array( 'email' => 'not-an-email' ), 'email' ),
	'phone letters'               => array( array( 'phone' => 'call me maybe' ), 'phone' ),
	'phone too short'             => array( array( 'phone' => '12345' ), 'phone' ),
	'phone 26 characters'         => array( array( 'phone' => str_repeat( '1', 26 ) ), 'phone' ),
	'phone only symbols'          => array( array( 'phone' => '++++ ()--' ), 'phone' ),
	'message 1,001 characters'    => array( array( 'message' => str_repeat( 'm', 1001 ) ), 'message' ),
);
foreach ( $cases as $label => $case ) {
	lp_test_reset();
	$r = lp_test_submit( $case[0] );
	check( "rejects $label (error on {$case[1]})", false === $r['success'] && isset( $r['data']['errors'][ $case[1] ] ) && empty( $GLOBALS['lp_test']['zoho'] ), json_encode( $r['data']['errors'] ?? null ) );
}
$links = array(
	'name with https://'   => array( array( 'name' => 'Accounts https://evil.example' ), 'name', 'Please enter your name without a web or email address.' ),
	'name with www.'       => array( array( 'name' => 'Accounts www.evil.example/pay' ), 'name', 'Please enter your name without a web or email address.' ),
	'name with an email'   => array( array( 'name' => 'pay@evil.example' ), 'name', 'Please enter your name without a web or email address.' ),
	'company with https:'  => array( array( 'company' => 'Overdue AED 9,850: pay now at https://evil.example/ledgerplus' ), 'company', 'Please enter your company name without a web or email address.' ),
	'company with WWW.'    => array( array( 'company' => 'WWW.EVIL.EXAMPLE' ), 'company', 'Please enter your company name without a web or email address.' ),
	'Other text with http' => array( array( 'lpc_business' => 'Other', 'lpc_business_other' => 'Refund form at https://evil.example/r' ), 'lpc_business_other', 'Please describe your business activity without a web or email address.' ),
);
foreach ( $links as $label => $case ) {
	lp_test_reset();
	$r = lp_test_submit( $case[0] );
	check( "rejects $label (link in the PDF), nothing sent", false === $r['success'] && $case[2] === ( $r['data']['errors'][ $case[1] ] ?? '' ) && empty( $GLOBALS['lp_test']['mails'] ) && empty( $GLOBALS['lp_test']['zoho'] ), json_encode( $r['data']['errors'] ?? null ) );
}
lp_test_reset();
$r = lp_test_submit( array( 'name' => 'Test O\'Brien-Smith Jr.', 'company' => 'Example.ae Trading: Dubai / Sharjah' ) );
check( 'names and companies with dots, colons and slashes are fine', true === $r['success'], json_encode( $r['data'] ) );
lp_test_reset();
$r = lp_test_submit( array( 'phone' => '50 123 4567' ) );
check( "Elementor Pro's Tel field rejects spaces (why calculator.js strips them)", false === $r['success'] && ElementorPro\Modules\Forms\Classes\Tel_Field::MESSAGE === ( $r['data']['errors']['phone'] ?? '' ) );
lp_test_reset();
$r = lp_test_submit( array( 'phone' => '0501234567' ) );
check( 'a phone without spaces passes Elementor and the plugin', true === $r['success'], json_encode( $r['data'] ) );
lp_test_reset();
$r = lp_test_submit( array( 'name' => 'A' ) );
check( 'visible field errors keep the generic invalid message', 'Please check the highlighted fields and try again.' === $r['data']['message'] );
lp_test_reset();
$r = lp_test_submit( array( 'message' => str_repeat( 'm', 1000 ), 'phone' => '+971(4)000-0000', 'name' => 'Al', 'company' => 'XY' ) );
check( 'accepts limits: message 1,000, phone with ( ) -, name/company 2 characters', true === $r['success'], json_encode( $r['data'] ) );
lp_test_reset();
$r = lp_test_submit( array( 'name' => '' ) );
check( 'empty required name keeps Elementor\'s "This field is required."', false === $r['success'] && 'This field is required.' === $r['data']['errors']['name'] );
lp_test_reset();
$r = lp_test_submit( array( 'name' => 'محمد الاختبار', 'company' => 'شركة الاختبار' ) );
check( 'non-Latin name and company accepted', true === $r['success'] );

/* ====================================================================== Rate limit */
section( 'Rate limit' );
lp_test_reset();
$ok = 0;
for ( $i = 1; $i <= 5; $i++ ) {
	$r   = lp_test_submit( array( 'email' => "ip-test-$i@example.com" ) );
	$ok += $r['success'] ? 1 : 0;
}
check( '5 submissions from one IP in an hour pass', 5 === $ok );
$r = lp_test_submit( array( 'email' => 'ip-test-6@example.com' ) );
check( 'the 6th from the same IP is blocked with the limit message', false === $r['success'] && LP_Calc_Elementor::LIMIT_MESSAGE === $r['data']['message'] && empty( $GLOBALS['lp_test']['zoho'][5] ) );
$key = 'lp_calc_rl_' . md5( '203.0.113.10' );
check( 'IP transient key lp_calc_rl_ + md5(ip), 1 hour', isset( $GLOBALS['lp_test']['transients'][ $key ] ) && 5 === $GLOBALS['lp_test']['transients'][ $key ]['value']['count'] && $GLOBALS['lp_test']['transients'][ $key ]['ttl'] <= 3600 && $GLOBALS['lp_test']['transients'][ $key ]['ttl'] > 3500 );
$_SERVER['REMOTE_ADDR'] = '203.0.113.11';
check( 'another IP still passes', true === lp_test_submit( array( 'email' => 'ip-test-7@example.com' ) )['success'] );
$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
$GLOBALS['lp_test']['transients'][ $key ]['value']['start'] -= 3601;
check( 'after the hour the IP passes again', true === lp_test_submit( array( 'email' => 'ip-test-8@example.com' ) )['success'] );

lp_test_reset();
$ok = 0;
foreach ( array( '198.51.100.1', '198.51.100.2', '198.51.100.3' ) as $i => $ip ) {
	$_SERVER['REMOTE_ADDR'] = $ip;
	$ok                    += lp_test_submit( array( 'email' => 0 === $i ? 'Same.Person@Example.com' : 'same.person@example.com' ) )['success'] ? 1 : 0;
}
check( '3 submissions per email (case-insensitive) pass', 3 === $ok );
$_SERVER['REMOTE_ADDR'] = '198.51.100.4';
$r = lp_test_submit( array( 'email' => 'SAME.person@example.com' ) );
check( 'the 4th for the same email is blocked', false === $r['success'] && LP_Calc_Elementor::LIMIT_MESSAGE === $r['data']['message'] );
$ekey = 'lp_calc_rl_' . md5( 'same.person@example.com' );
check( 'email transient key lp_calc_rl_ + md5(lowercased email), 24 hours', isset( $GLOBALS['lp_test']['transients'][ $ekey ] ) && $GLOBALS['lp_test']['transients'][ $ekey ]['ttl'] > 86000 );
check( 'the blocked IP was not counted', 0 === ( $GLOBALS['lp_test']['transients'][ 'lp_calc_rl_' . md5( '198.51.100.4' ) ]['value']['count'] ?? 0 ) );

lp_test_reset();
$_SERVER['REMOTE_ADDR'] = '192.0.2.50';
for ( $i = 0; $i < 7; $i++ ) {
	lp_test_submit( array( 'lpc_services' => '', 'email' => "bad-$i@example.com" ) );
}
check( 'failed validations are not counted', ! isset( $GLOBALS['lp_test']['transients'][ 'lp_calc_rl_' . md5( '192.0.2.50' ) ] ) && true === lp_test_submit()['success'] );

lp_test_reset();
add_filter( 'lp_calc_rate_limits', $one = function ( $l ) {
	$l['ip'] = 1;
	return $l;
} );
$_SERVER['REMOTE_ADDR'] = '192.0.2.60';
$a = lp_test_submit( array( 'email' => 'f1@example.com' ) )['success'];
$b = lp_test_submit( array( 'email' => 'f2@example.com' ) )['success'];
remove_filter( 'lp_calc_rate_limits', $one );
check( 'limits are filterable', true === $a && false === $b );
add_filter( 'lp_calc_rate_limits', $off = function ( $l ) {
	$l['ip'] = 0;
	$l['email'] = 0;
	return $l;
} );
$all_ok = true;
for ( $i = 0; $i < 8; $i++ ) {
	$all_ok = $all_ok && lp_test_submit()['success'];
}
remove_filter( 'lp_calc_rate_limits', $off );
check( 'a limit of 0 switches the check off', $all_ok );
$_SERVER['REMOTE_ADDR'] = '203.0.113.10';

check( 'canonical mailbox: case, +tag, Gmail dots', 'victim@example.com' === LP_Calc_Elementor::canonical_email( 'Victim+1@Example.com' ) && 'v.ictim@example.com' === LP_Calc_Elementor::canonical_email( 'v.ictim@example.com' ) && 'victim@gmail.com' === LP_Calc_Elementor::canonical_email( 'V.ictim+x@googlemail.com' ) && '+x@example.com' === LP_Calc_Elementor::canonical_email( '+x@example.com' ) );
check( 'IP bucket: IPv4 as is, IPv6 by /64, IPv4-mapped as IPv4', '198.51.100.7' === LP_Calc_Elementor::ip_bucket( '198.51.100.7' ) && LP_Calc_Elementor::ip_bucket( '2001:db8:1:2::1' ) === LP_Calc_Elementor::ip_bucket( '2001:db8:1:2:ffff::9' ) && LP_Calc_Elementor::ip_bucket( '2001:db8:1:2::1' ) !== LP_Calc_Elementor::ip_bucket( '2001:db8:1:3::1' ) && '198.51.100.7' === LP_Calc_Elementor::ip_bucket( '::ffff:198.51.100.7' ) );

lp_test_reset();
$sent = array();
foreach ( array( 'victim@example.com', 'victim+1@example.com', 'VICTIM+2@example.com', 'victim+3@example.com' ) as $i => $addr ) {
	$_SERVER['REMOTE_ADDR'] = '198.51.100.' . ( 30 + $i );
	$sent[]                 = lp_test_submit( array( 'email' => $addr ) )['success'];
}
check( 'plus-addressed variants share the 3-per-mailbox limit', array( true, true, true, false ) === $sent, json_encode( $sent ) );
check( 'mailbox transient key: md5 of the canonical address', 3 === ( $GLOBALS['lp_test']['transients'][ 'lp_calc_rl_' . md5( 'victim@example.com' ) ]['value']['count'] ?? 0 ) );
lp_test_reset();
$sent = array();
foreach ( array( 'test.victim@gmail.com', 'testvictim@gmail.com', 't.e.s.t.victim+q@googlemail.com', 'testvic.tim@gmail.com' ) as $i => $addr ) {
	$_SERVER['REMOTE_ADDR'] = '198.51.100.' . ( 40 + $i );
	$sent[]                 = lp_test_submit( array( 'email' => $addr ) )['success'];
}
check( 'Gmail dot variants share the limit', array( true, true, true, false ) === $sent, json_encode( $sent ) );

lp_test_reset();
$n = 0;
for ( $k = 1; $k <= 8; $k++ ) {
	$_SERVER['REMOTE_ADDR'] = '2001:db8:1:2::' . dechex( $k );
	$n                     += lp_test_submit( array( 'email' => "v6-$k@example.com" ) )['success'] ? 1 : 0;
}
check( 'IPv6: one /64 shares the 5-per-hour limit', 5 === $n, $n );
$_SERVER['REMOTE_ADDR'] = '2001:db8:1:3::1';
check( 'IPv6: another /64 still passes', true === lp_test_submit( array( 'email' => 'v6-other@example.com' ) )['success'] );

lp_test_reset();
add_filter( 'lp_calc_rate_limits', $cap = function ( $l ) {
	$l['global'] = 2;
	return $l;
} );
$res = array();
foreach ( array( 1, 2, 3 ) as $i ) {
	$_SERVER['REMOTE_ADDR'] = '198.51.100.' . ( 60 + $i );
	$r                      = lp_test_submit( array( 'email' => "cap-$i@example.com" ) );
	$res[]                  = array( $r['success'], $r['data']['data']['lp_quote']['email_sent'] ?? null );
}
remove_filter( 'lp_calc_rate_limits', $cap );
check( 'site-wide cap: past it the submission succeeds without the client email', array( array( true, true ), array( true, true ), array( true, false ) ) === $res && 2 === count( $GLOBALS['lp_test']['mails'] ), json_encode( $res ) );
check( 'site-wide cap: the lead still reaches Zoho and the team', 3 === count( $GLOBALS['lp_test']['zoho'] ) && 3 === count( $GLOBALS['lp_test']['team_email'] ) );
check( 'site-wide cap: logged without personal data', false !== strpos( file_get_contents( LP_TEST_LOG ), '[lp-calc] site-wide client email limit reached' ) && false === strpos( file_get_contents( LP_TEST_LOG ), '@' ) );
check( 'site-wide cap: default 30 an hour, in an lp_calc_ transient', 30 === LP_Calc_Elementor::rate_limits()['global'] && 3600 === LP_Calc_Elementor::rate_limits()['global_window'] && isset( $GLOBALS['lp_test']['transients']['lp_calc_rl_global'] ) );
$_SERVER['REMOTE_ADDR'] = '203.0.113.10';

/* ====================================================================== actions_before */
section( 'actions_before: server quote fields' );
lp_test_reset();
$r      = lp_test_submit( array( 'quote_total' => 'AED 1', 'quote_ref' => 'FAKE', 'quote_summary' => 'tampered', 'field_lead_form' => 'Other form', 'lead_source' => 'Spam', 'lpc_services' => 'accounting,vat,ct,payroll' ) );
$fields = $r['record']->get( 'fields' );
$ref    = $fields['quote_ref']['value'];
check( 'quote_ref format LPQ-YYMMDD-XXXXX (UTC date)', 1 === preg_match( '/^LPQ-' . gmdate( 'ymd' ) . '-[0-9A-F]{5}$/', $ref ), $ref );
check( 'quote_total overwritten', 'AED 7,000' === $fields['quote_total']['value'] );
check( 'quote_summary overwritten with summary_text', LP_Calc_Pricing::summary_text( $excel['selection'], $q, $ref ) === $fields['quote_summary']['value'] );
check( 'field_lead_form forced', 'Ledgerplus Package Calculator' === $fields['field_lead_form']['value'] );
check( 'lead_source forced', 'Website' === $fields['lead_source']['value'] );
check( 'lpc_services rewritten as labels', 'Accounting and bookkeeping, VAT returns filing and compliance, Corporate Tax registration and filing, Payroll and WPS support' === $fields['lpc_services']['value'] );
check( 'raw_value updated too', $ref === $fields['quote_ref']['raw_value'] );
$lead = $GLOBALS['lp_test']['zoho'][0];
check( 'Zoho lead: Description holds company and the server summary', 0 === strpos( $lead['Description'], "Client Company Name: Test Trading LLC\n\nPackage calculator estimate:\nQuote $ref\n" ) && false !== strpos( $lead['Description'], 'AED 7,000' ) && false === strpos( $lead['Description'], 'tampered' ) );
check( 'Zoho lead: Lead_Form, Lead_Source, Name, Email, Mobile', 'Ledgerplus Package Calculator' === $lead['Lead_Form'] && 'Website' === $lead['Lead_Source'] && 'Test Customer' === $lead['Name'] && 'test.customer@example.com' === $lead['Email'] && '+971500000000' === $lead['Mobile'] );
$team = $GLOBALS['lp_test']['team_email'][0];
check( 'team email subject has company and ref', "Package calculator lead: Test Trading LLC ($ref)" === $team['subject'] );
check( 'team email content has the total and multi-line summary', false !== strpos( $team['content'], 'AED 7,000' ) && false !== strpos( $team['content'], "\n- Payroll and WPS support: AED 1,000\n" ) );
check( 'saved submission has the server values', 'AED 7,000' === $GLOBALS['lp_test']['saved'][0]['quote_total'] );

lp_test_reset();
$r      = lp_test_submit( array( 'lpc_services' => 'accounting,vat', 'lpc_employees' => 'over_20', 'lpc_reports' => '2', 'lpc_business' => 'retail', 'lpc_business_other' => 'tampered' ) );
$fields = $r['record']->get( 'fields' );
check( 'unchosen payroll/reports: lpc_employees and lpc_reports emptied in the record', '' === $fields['lpc_employees']['value'] && '' === $fields['lpc_reports']['value'] );
check( 'business canonical, Other text emptied when business is not Other', 'Retail' === $fields['lpc_business']['value'] && '' === $fields['lpc_business_other']['value'] );
check( 'saved submission matches the quote summary', '' === $GLOBALS['lp_test']['saved'][0]['lpc_employees'] && '' === $GLOBALS['lp_test']['saved'][0]['lpc_reports'] && false === strpos( $GLOBALS['lp_test']['saved'][0]['quote_summary'], 'Employees' ) );
lp_test_reset();
$r      = lp_test_submit( array( 'lpc_services' => 'payroll,reports', 'lpc_employees' => 'over_20', 'lpc_reports' => '3' ) );
$fields = $r['record']->get( 'fields' );
check( 'chosen payroll/reports: readable employees, report count', 'More than 20 employees' === $fields['lpc_employees']['value'] && '3' === $fields['lpc_reports']['value'] );

lp_test_reset();
$r = lp_test_submit( array( 'lpc_business' => 'Other', 'lpc_business_other' => 'Yacht [field id="email"] charter' ) );
check( 'Other text cannot inject an Elementor shortcode into the summary', true === $r['success'] && false === strpos( $GLOBALS['lp_test']['zoho'][0]['Description'], '[field' ) && false !== strpos( $GLOBALS['lp_test']['zoho'][0]['Description'], 'Business: Other (Yacht (field id="email") charter)' ) );

lp_test_reset();
$form_without = lp_test_form();
$form_without['settings']['form_fields'] = array_values( array_filter( $form_without['settings']['form_fields'], function ( $f ) {
	return 'quote_summary' !== $f['custom_id'];
} ) );
$handler = new ElementorPro\Modules\Forms\Classes\Ajax_Handler();
$r       = $handler->ajax_send_form( $form_without, lp_test_post(), lp_test_actions() );
check( 'a missing hidden field is skipped (update_field needs the field)', true === $r['success'] && ! isset( $r['record']->get( 'fields' )['quote_summary'] ) && 'AED 7,000' === $r['record']->get( 'fields' )['quote_total']['value'] );

/* ====================================================================== Every server path */
section( 'Quote fields on every server path (the page sends them empty)' );
$empty      = array( 'quote_ref' => '', 'quote_total' => '', 'quote_summary' => '' );
$figures_ok = function ( $r, $label, $expect_actions = true ) {
	$f    = $r['record']->get( 'fields' );
	$ref  = $f['quote_ref']['value'];
	$sum  = $f['quote_summary']['value'];
	$q    = $r['data']['data']['lp_quote'] ?? array();
	$mail = $GLOBALS['lp_test']['mails'][0] ?? array( 'subject' => '', 'attachments' => array() );
	check( "$label: record has the server ref, total and summary", 1 === preg_match( '/^LPQ-\d{6}-[0-9A-F]{5}$/', $ref ) && 'AED 7,000' === $f['quote_total']['value'] && 0 === strpos( $sum, "Quote $ref\n" ) && false !== strpos( $sum, 'Estimated annual total (incl. 5% VAT): AED 7,000' ), json_encode( array( $ref, $f['quote_total']['value'] ) ) );
	check( "$label: lp_quote has the ref, lines and total", $ref === ( $q['ref'] ?? '' ) && 'AED 7,000' === ( $q['total'] ?? '' ) && 4 === count( $q['lines'] ?? array() ) && 'AED 2,000' === $q['lines'][0]['amount'] );
	check( "$label: client email and PDF carry the ref and the figures", "Your Ledger Plus package estimate ($ref)" === $mail['subject'] && false !== strpos( $mail['message'] ?? '', 'AED 7,000' ) && isset( $mail['attachments'][0] ) && "Ledger-Plus-Quotation-$ref.pdf" === $mail['attachments'][0]['name'] );
	if ( $expect_actions ) {
		$lead = $GLOBALS['lp_test']['zoho'][0] ?? array( 'Description' => '' );
		$team = $GLOBALS['lp_test']['team_email'][0] ?? array( 'subject' => '', 'content' => '' );
		$save = $GLOBALS['lp_test']['saved'][0] ?? array();
		check( "$label: Zoho Description, team email and saved entry have the figures", false !== strpos( $lead['Description'], "Package calculator estimate:\nQuote $ref\n" ) && false !== strpos( $lead['Description'], '- Accounting and bookkeeping: AED 2,000' ) && "Package calculator lead: Test Trading LLC ($ref)" === $team['subject'] && false !== strpos( $team['content'], 'AED 7,000' ) && $ref === ( $save['quote_ref'] ?? '' ) && 'AED 7,000' === ( $save['quote_total'] ?? '' ) );
	}
	return $ref;
};

// a) The normal path: process() writes the fields, actions_before() writes them again, same ref.
lp_test_reset();
$at_process = array();
add_action( 'elementor_pro/forms/process', $spy = function ( $record ) use ( &$at_process ) {
	$f          = $record->get( 'fields' );
	$at_process = array( $f['quote_ref']['value'], $f['quote_total']['value'] );
}, 11, 1 );
$r = lp_test_submit( $empty );
remove_action( 'elementor_pro/forms/process', $spy, 11 );
check( 'normal path: the browser posted the figure fields empty', '' === lp_test_post( $empty )['quote_total'] && '' === lp_test_post( $empty )['quote_summary'] && '' === lp_test_post( $empty )['quote_ref'] );
$ref = $figures_ok( $r, 'normal path' );
check( 'normal path: written already at elementor_pro/forms/process, the same ref kept by actions_before', $ref === $at_process[0] && 'AED 7,000' === $at_process[1], json_encode( $at_process ) );
check( 'normal path: nothing logged', '' === trim( file_get_contents( LP_TEST_LOG ) ), file_get_contents( LP_TEST_LOG ) );

// b) actions_before does not run (filter removed): process() has written the fields before the actions.
lp_test_reset();
remove_filter( 'elementor_pro/forms/record/actions_before', array( 'LP_Calc_Elementor', 'actions_before' ) );
$r = lp_test_submit( $empty );
add_filter( 'elementor_pro/forms/record/actions_before', array( 'LP_Calc_Elementor', 'actions_before' ), 10, 2 );
$figures_ok( $r, 'without actions_before' );
check( 'without actions_before: nothing logged', '' === trim( file_get_contents( LP_TEST_LOG ) ), file_get_contents( LP_TEST_LOG ) );

// c) process does not run (an Elementor Pro without the hook): actions_before() writes them.
lp_test_reset();
remove_action( 'elementor_pro/forms/process', array( 'LP_Calc_Elementor', 'process' ) );
$r = lp_test_submit( $empty );
add_action( 'elementor_pro/forms/process', array( 'LP_Calc_Elementor', 'process' ), 10, 2 );
$figures_ok( $r, 'without the process hook' );

// d) Neither ran: new_record() rebuilds the quote (state_from_record) with a server ref (a posted ref
// is never used), writes the fields into the record and logs that the actions ran without them.
lp_test_reset();
remove_filter( 'elementor_pro/forms/record/actions_before', array( 'LP_Calc_Elementor', 'actions_before' ) );
remove_action( 'elementor_pro/forms/process', array( 'LP_Calc_Elementor', 'process' ) );
$r = lp_test_submit( array( 'quote_ref' => 'LPQ-000000-00000', 'quote_total' => '', 'quote_summary' => '' ) );
add_filter( 'elementor_pro/forms/record/actions_before', array( 'LP_Calc_Elementor', 'actions_before' ), 10, 2 );
add_action( 'elementor_pro/forms/process', array( 'LP_Calc_Elementor', 'process' ), 10, 2 );
$ref = $figures_ok( $r, 'state_from_record', false );
check( 'state_from_record: a posted quote_ref is not used', 'LPQ-000000-00000' !== $ref );
check( 'state_from_record: logged without personal data', false !== strpos( file_get_contents( LP_TEST_LOG ), "[lp-calc] quote fields written after the form actions for $ref" ) && false === strpos( file_get_contents( LP_TEST_LOG ), '@' ) );
check( 'state_from_record: the actions ran before the figures existed (why process() is hooked too)', false === strpos( $GLOBALS['lp_test']['zoho'][0]['Description'], 'AED' ) );

// e) Tampered figure fields are overwritten on the path without actions_before too.
lp_test_reset();
remove_filter( 'elementor_pro/forms/record/actions_before', array( 'LP_Calc_Elementor', 'actions_before' ) );
$r = lp_test_submit( array( 'quote_ref' => 'LPQ-000000-00000', 'quote_total' => 'AED 1', 'quote_summary' => 'Tampered', 'field_lead_form' => 'Other', 'lead_source' => 'Spam' ) );
add_filter( 'elementor_pro/forms/record/actions_before', array( 'LP_Calc_Elementor', 'actions_before' ), 10, 2 );
$ref = $figures_ok( $r, 'tampered, without actions_before' );
check( 'tampered, without actions_before: lead form and source forced, nothing tampered reaches Zoho', 'Ledgerplus Package Calculator' === $GLOBALS['lp_test']['zoho'][0]['Lead_Form'] && 'Website' === $GLOBALS['lp_test']['zoho'][0]['Lead_Source'] && false === strpos( $GLOBALS['lp_test']['zoho'][0]['Description'], 'Tampered' ) && 'LPQ-000000-00000' !== $ref );
check( 'state cleared after each request', null === LP_Calc_Elementor::state() );

// f) process() and then actions_before() on the same record: the second pass reuses the quote and
// does not normalize the plugin's own output again (clean_text() decodes entities on each pass).
foreach ( array( '&amp;lt;b&amp;gt;', 'info&amp;#64;example' ) as $other ) {
	lp_test_reset();
	$r = lp_test_submit( array( 'lpc_business' => 'Other', 'lpc_business_other' => $other ) );
	$f = $r['record']->get( 'fields' );
	$q = $r['data']['data']['lp_quote'] ?? array();
	check( "double-encoded Other activity \"$other\": lp_quote with the figures and the record's ref", true === $r['success'] && 'AED 7,000' === ( $q['total'] ?? '' ) && 4 === count( $q['lines'] ?? array() ) && $f['quote_ref']['value'] === ( $q['ref'] ?? '' ), json_encode( $q ) );
	check( "double-encoded Other activity \"$other\": client email and PDF sent, nothing logged", 1 === count( $GLOBALS['lp_test']['mails'] ) && isset( $GLOBALS['lp_test']['mails'][0]['attachments'][0] ) && '' === trim( file_get_contents( LP_TEST_LOG ) ), file_get_contents( LP_TEST_LOG ) );
}

/* ====================================================================== new_record */
section( 'new_record: PDF, client email, response data' );
lp_test_reset();
$r     = lp_test_submit();
$ref   = $r['record']->get( 'fields' )['quote_ref']['value'];
$data  = $r['data']['data']['lp_quote'] ?? null;
check( 'lp_quote response data present', is_array( $data ) );
check( 'lp_quote: ref matches the record', $ref === $data['ref'] );
check( 'lp_quote: lines label/detail/amount as text', 4 === count( $data['lines'] ) && 'Accounting and bookkeeping' === $data['lines'][0]['label'] && 'Annual service' === $data['lines'][0]['detail'] && 'AED 2,000' === $data['lines'][0]['amount'] );
check( 'lp_quote: total, email, email_sent', 'AED 7,000' === $data['total'] && 'test.customer@example.com' === $data['email'] && true === $data['email_sent'] );
check( 'response is JSON-encodable', false !== json_encode( $r['data'] ) );
$mails = $GLOBALS['lp_test']['mails'];
check( 'one wp_mail call (the client email)', 1 === count( $mails ) );
$m = $mails[0];
check( 'to = client email', 'test.customer@example.com' === $m['to'] );
check( 'subject', "Your Ledger Plus package estimate ($ref)" === $m['subject'] );
check( 'headers: HTML and Reply-To, no From', array( 'Content-Type: text/html; charset=UTF-8', 'Reply-To: Ledger Plus UAE <info@ledgerplus.ae>' ) === $m['headers'] );
check( 'one attachment named Ledger-Plus-Quotation-<ref>.pdf', 1 === count( $m['attachments'] ) && "Ledger-Plus-Quotation-$ref.pdf" === $m['attachments'][0]['name'] && $m['attachments'][0]['exists'] );
$pdf = $m['attachments'][0]['bytes'];
check( 'attachment is a PDF 1.4 file', 0 === strpos( $pdf, '%PDF-1.4' ) && "%%EOF\n" === substr( $pdf, -6 ) );
check( 'temporary PDF and folder deleted', ! file_exists( $m['attachments'][0]['path'] ) && ! is_dir( dirname( $m['attachments'][0]['path'] ) ) );
check( 'no lp-calc-* folders left in the temp dir', array() === glob( LP_TEST_TMP . '/lp-calc-*' ) );
check( 'plain-text part set for this message', false !== strpos( $m['alt_body'], 'Estimated annual total (incl. 5% VAT): AED 7,000' ) );
check( 'phpmailer_init hook removed afterwards', 0 === lp_test_hook_count( 'phpmailer_init' ) );
$body = $m['message'];
check( 'body: greeting, intro with ref, total', false !== strpos( $body, 'Hi Test,' ) && false !== strpos( $body, "Your estimate <span style=\"white-space:nowrap;\">$ref</span> is below, and the full quotation is attached as a PDF." ) && false !== strpos( $body, 'AED 7,000' ) );
check( 'body: what happens next, buttons', false !== strpos( $body, 'A Ledger Plus advisor will review your selections' ) && false !== strpos( $body, 'href="mailto:info@ledgerplus.ae?subject=Quotation%20' . $ref . '"' ) && false !== strpos( $body, 'href="https://wa.me/971503420832"' ) );
check( 'body: logo fallback URL, 180 wide, alt', false !== strpos( $body, 'src="https://ledgerplus.ae/wp-content/uploads/2026/09/Logo-Assests-01-edited.png" width="180" alt="Ledger Plus"' ) );
check( 'body: max-width 600, table based', false !== strpos( $body, 'max-width:600px' ) && substr_count( $body, 'role="presentation"' ) > 5 );
check( 'body: every line under 998 characters', max( array_map( 'strlen', explode( "\n", $body ) ) ) < 998 );
check( 'no failure logged', '' === trim( file_get_contents( LP_TEST_LOG ) ), file_get_contents( LP_TEST_LOG ) );

$GLOBALS['lp_test']['options']['woocommerce_email_header_image'] = 'https://ledgerplus.ae/wp-content/uploads/2026/09/email-logo.png';
lp_test_reset();
lp_test_submit();
check( 'body: logo from woocommerce_email_header_image', false !== strpos( $GLOBALS['lp_test']['mails'][0]['message'], 'src="https://ledgerplus.ae/wp-content/uploads/2026/09/email-logo.png"' ) );
unset( $GLOBALS['lp_test']['options']['woocommerce_email_header_image'] );

// Escaping: user text is escaped and never becomes a link.
lp_test_reset();
lp_test_submit( array( 'name' => 'evil.example.com "Quote" & <b>Co</b>', 'company' => 'Test & Sons' ) );
$body = $GLOBALS['lp_test']['mails'][0]['message'];
check( 'name escaped and split so it cannot become a link', false !== strpos( $body, 'Hi evil.&#8203;example.&#8203;com,' ) && false === strpos( $body, '<b>Co' ) );
check( 'no user text inside any href', ! preg_match( '/href="[^"]*(evil|example\.com)/', $body ) );
lp_test_reset();
lp_test_submit( array( 'name' => 'Dr. Test Customer' ) );
check( 'greeting skips a title', false !== strpos( $GLOBALS['lp_test']['mails'][0]['message'], 'Hi Test,' ) );

// Mail failure.
lp_test_reset();
$GLOBALS['lp_test']['mail_ok'] = false;
$r   = lp_test_submit();
$log = file_get_contents( LP_TEST_LOG );
check( 'wp_mail failure: response still has lp_quote with email_sent false', true === $r['success'] && false === $r['data']['data']['lp_quote']['email_sent'] );
check( 'wp_mail failure: logged with [lp-calc], no personal data', false !== strpos( $log, '[lp-calc] client email not sent for LPQ-' ) && false === strpos( $log, '@' ) && false === strpos( $log, 'Test Customer' ) && false === strpos( $log, '971' ) );
check( 'wp_mail failure: temp files deleted', array() === glob( LP_TEST_TMP . '/lp-calc-*' ) );

// A form action fails (Zoho throws): Elementor answers "Something went wrong. Please try again".
$zoho_throws = lp_test_actions();
$zoho_throws['zoho_Ledgers_Plus'] = function () {
	throw new Exception( 'Zoho API down' );
};
lp_test_reset();
$handler = new ElementorPro\Modules\Forms\Classes\Ajax_Handler();
$r       = $handler->ajax_send_form( lp_test_form(), lp_test_post(), $zoho_throws );
$q       = $r['data']['data']['lp_quote'] ?? array();
check( 'action failed, client emailed: lp_quote keeps the figures (the page shows the result)', false === $r['success'] && true === ( $q['email_sent'] ?? null ) && 'AED 7,000' === ( $q['total'] ?? '' ) && 4 === count( $q['lines'] ?? array() ) );
foreach ( array( 'site-wide cap reached' => 'cap', 'wp_mail fails' => 'mail' ) as $why => $how ) {
	lp_test_reset();
	if ( 'cap' === $how ) {
		add_filter( 'lp_calc_rate_limits', $cap = function ( $l ) {
			$l['global'] = 1;
			return $l;
		} );
		LP_Calc_Elementor::client_email_allowed();
	} else {
		$GLOBALS['lp_test']['mail_ok'] = false;
	}
	$handler = new ElementorPro\Modules\Forms\Classes\Ajax_Handler();
	$r       = $handler->ajax_send_form( lp_test_form(), lp_test_post(), $zoho_throws );
	if ( 'cap' === $how ) {
		remove_filter( 'lp_calc_rate_limits', $cap );
	}
	$q = $r['data']['data']['lp_quote'] ?? null;
	check( "action failed, $why: the error response carries no figures", false === $r['success'] && is_array( $q ) && false === $q['email_sent'] && array() === $q['lines'] && '' === $q['total'] && 1 === preg_match( '/^LPQ-\d{6}-[0-9A-F]{5}$/', $q['ref'] ) && array() === lp_test_amounts( wp_json_encode( $r['data'] ) ), wp_json_encode( $r['data'] ) );
	check( "action failed, $why: same keys as always (ref, lines, total, email, email_sent)", array( 'ref', 'lines', 'total', 'email', 'email_sent' ) === array_keys( $q ) );
	check( "action failed, $why: the team email and the saved entry still have the figures", 'AED 7,000' === ( $GLOBALS['lp_test']['saved'][0]['quote_total'] ?? '' ) && false !== strpos( $GLOBALS['lp_test']['team_email'][0]['content'] ?? '', 'AED 7,000' ) );
}
check( 'response_data() without figures', array( 'ref' => 'LPQ-261128-AAAAA', 'lines' => array(), 'total' => '', 'email' => 'test.customer@example.com', 'email_sent' => false ) === LP_Calc_Elementor::response_data( array( 'ref' => 'LPQ-261128-AAAAA', 'quote' => LP_Calc_Pricing::compute( $excel['selection'] ), 'contact' => array( 'email' => 'test.customer@example.com' ) ), false, false ) );

// A folder an earlier request left behind (hard kill) is swept after an hour; a fresh one is kept.
lp_test_reset();
$stale = LP_TEST_TMP . '/lp-calc-0123456789abcdef';
$fresh = LP_TEST_TMP . '/lp-calc-fedcba9876543210';
foreach ( array( $stale, $fresh ) as $d ) {
	@mkdir( $d, 0700 );
	file_put_contents( $d . '/Ledger-Plus-Quotation-LPQ-261127-AAAAA.pdf', '%PDF-1.4' );
}
touch( $stale, time() - 7200 );
lp_test_submit();
check( 'temp sweep: folder older than an hour removed, recent one kept', ! is_dir( $stale ) && is_dir( $fresh ) );
LP_Calc_Elementor::remove_temp_on_shutdown( $fresh );
check( 'shutdown cleanup removes the folder', ! is_dir( $fresh ) );

// A fatal error while wp_mail() runs (time-out on a slow SMTP relay) skips `finally`: the
// shutdown function still deletes the PDF with the client's details.
$fatal_dir    = LP_TEST_TMP . '/fatal';
$fatal_script = LP_TEST_TMP . '/fatal.php';
@mkdir( $fatal_dir, 0700 );
file_put_contents(
	$fatal_script,
	'<?php
require ' . var_export( __DIR__ . '/stubs-wp.php', true ) . ';
require ' . var_export( __DIR__ . '/stubs-elementor.php', true ) . ';
define( "LP_TEST_TMP", ' . var_export( $fatal_dir, true ) . ' );
define( "LP_TEST_LOG", LP_TEST_TMP . "/php-error.log" );
require ' . var_export( __DIR__ . '/fixtures.php', true ) . ';
require ' . var_export( $plugin, true ) . ';
lp_test_reset();
$_SERVER["REMOTE_ADDR"] = "198.51.100.7";
add_action( "phpmailer_init", function () {
	$GLOBALS["lp_fatal_seen"] = glob( LP_TEST_TMP . "/lp-calc-*/*.pdf" );
	file_put_contents( LP_TEST_TMP . "/seen.txt", (string) count( $GLOBALS["lp_fatal_seen"] ) );
	set_time_limit( 1 );
	for ( $t = 0; ; $t++ ) {} // CPU-bound stand-in for a stalled send.
} );
lp_test_submit();
'
);
$fatal_out = (string) shell_exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $fatal_script ) . ' 2>&1' );
check( 'fatal during wp_mail(): the PDF existed when the mail was being sent', '1' === @file_get_contents( $fatal_dir . '/seen.txt' ) && false !== strpos( $fatal_out, 'Maximum execution time' ), substr( $fatal_out, 0, 300 ) );
check( 'fatal during wp_mail(): no PDF or lp-calc-* folder left behind', array() === glob( $fatal_dir . '/lp-calc-*' ) );

// Temp folder cannot be created: the email goes out without an attachment.
lp_test_reset();
$GLOBALS['lp_test']['temp_dir'] = LP_TEST_TMP . '/does-not-exist/deeper';
$r = lp_test_submit();
$m = $GLOBALS['lp_test']['mails'][0] ?? array();
check( 'no temp folder: email sent without attachment, intro without "attached"', true === $r['data']['data']['lp_quote']['email_sent'] && array() === $m['attachments'] && false === strpos( $m['message'], 'attached as a PDF' ) );
check( 'no temp folder: logged', false !== strpos( file_get_contents( LP_TEST_LOG ), '[lp-calc] could not create a temporary folder' ) );

// An exception in a filter never breaks the response.
lp_test_reset();
add_filter( 'lp_calc_client_email', $boom = function () {
	throw new RuntimeException( 'boom for test.customer@example.com +971 50 000 0000' );
} );
$r = lp_test_submit();
remove_filter( 'lp_calc_client_email', $boom );
$log = file_get_contents( LP_TEST_LOG );
check( 'exception: form still succeeds, lp_quote email_sent false', true === $r['success'] && false === $r['data']['data']['lp_quote']['email_sent'] );
check( 'exception: logged without email or phone', false !== strpos( $log, 'RuntimeException' ) && false === strpos( $log, 'example.com' ) && false === strpos( $log, '000 0000' ) );
check( 'exception: temp files deleted', array() === glob( LP_TEST_TMP . '/lp-calc-*' ) );

// The lp_calc_client_email filter.
lp_test_reset();
add_filter( 'lp_calc_client_email', $cc = function ( $mail, $data ) {
	$mail['subject'] .= ' [filtered]';
	$mail['headers'][] = 'Bcc: archive@example.com';
	return $mail;
}, 10, 2 );
lp_test_submit();
remove_filter( 'lp_calc_client_email', $cc );
check( 'lp_calc_client_email filters subject and headers', ' [filtered]' === substr( $GLOBALS['lp_test']['mails'][0]['subject'], -11 ) && in_array( 'Bcc: archive@example.com', $GLOBALS['lp_test']['mails'][0]['headers'], true ) );

// Non-Latin contact details still produce a PDF and an email with the real values.
lp_test_reset();
$r = lp_test_submit( array( 'name' => 'محمد الاختبار', 'company' => 'شركة الاختبار' ) );
$m = $GLOBALS['lp_test']['mails'][0];
check( 'non-Latin: email greets with the real name', false !== strpos( $m['message'], 'Hi محمد,' ) );
check( 'non-Latin: PDF attached', 1 === count( $m['attachments'] ) && 0 === strpos( $m['attachments'][0]['bytes'], '%PDF-1.4' ) );
check( 'state cleared after the request', null === LP_Calc_Elementor::state() );

/* ====================================================================== Other forms */
section( 'Other forms are left untouched' );
lp_test_reset();
$r = lp_test_submit( array( 'lpc_services' => '' ), array( 'form_name' => 'Hero form' ) );
check( 'other form name: no selection errors, no quote fields, no client mail, no lp_quote', true === $r['success'] && '' === $r['record']->get( 'fields' )['quote_ref']['value'] && array() === $GLOBALS['lp_test']['mails'] && ! isset( $r['data']['data']['lp_quote'] ) );
check( 'other form name: lpc_services untouched', '' === $r['record']->get( 'fields' )['lpc_services']['value'] );
lp_test_reset();
$form_nover = lp_test_form();
$form_nover['settings']['form_fields'] = array_values( array_filter( $form_nover['settings']['form_fields'], function ( $f ) {
	return 'lpc_version' !== $f['custom_id'];
} ) );
$handler = new ElementorPro\Modules\Forms\Classes\Ajax_Handler();
$r       = $handler->ajax_send_form( $form_nover, lp_test_post( array( 'name' => 'A' ) ), lp_test_actions() );
check( 'right name but no lpc_version field: untouched (no name length check)', true === $r['success'] && array() === $GLOBALS['lp_test']['mails'] && '' === $r['record']->get( 'fields' )['quote_ref']['value'] );
check( 'no rate-limit transients for other forms', array() === $GLOBALS['lp_test']['transients'] );
lp_test_reset();
add_filter( 'lp_calc_is_calculator_form', $force = function ( $is, $record ) {
	return 'Hero form' === $record->get_form_settings( 'form_name' ) ? true : $is;
}, 10, 2 );
$r = lp_test_submit( array(), array( 'form_name' => 'Hero form' ) );
remove_filter( 'lp_calc_is_calculator_form', $force );
check( 'lp_calc_is_calculator_form can opt a form in', true === $r['success'] && isset( $r['data']['data']['lp_quote'] ) );
lp_test_reset();
add_filter( 'lp_calc_is_calculator_form', '__return_false_lp' );
function __return_false_lp() {
	return false;
}
$r = lp_test_submit();
remove_all_filters( 'lp_calc_is_calculator_form' );
check( 'lp_calc_is_calculator_form can opt the form out', true === $r['success'] && ! isset( $r['data']['data']['lp_quote'] ) && array() === $GLOBALS['lp_test']['mails'] );
check( 'hooks ignore non-record arguments', null === LP_Calc_Elementor::validate( null, null ) && null === LP_Calc_Elementor::process( null, null ) && 'x' === LP_Calc_Elementor::actions_before( 'x', null ) && null === LP_Calc_Elementor::new_record( new stdClass(), null ) );

/* ====================================================================== PDF writer */
section( 'PDF writer' );
$w = new LP_Calc_PDF();
check( 'Helvetica widths: "Hello" at 10pt = 22.78', abs( $w->text_width( 'Hello', 10 ) - 22.78 ) < 0.001 );
check( 'Helvetica-Bold widths: "AED 7,000" at 10pt', abs( $w->text_width( 'AED 7,000', 10, true ) - ( 722 + 667 + 722 + 278 + 556 + 278 + 556 * 3 ) / 100 ) < 0.001 );
check( 'width tables have 224 entries (32-255)', 224 === count( LP_Calc_PDF::WIDTHS_REGULAR ) && 224 === count( LP_Calc_PDF::WIDTHS_BOLD ) );
check( 'WinAnsi: euro, em dash, multiply, middle dot', "\x80\x97\xD7\xB7" === LP_Calc_PDF::to_winansi( '€—×·' ) );
check( 'printable(): Arabic only gives —', '—' === LP_Calc_PDF::printable( 'محمد الاختبار' ) );
check( 'printable(): accents kept', 'José Müller' === LP_Calc_PDF::printable( 'José Müller' ) );
check( 'printable(): mostly Latin keeps the Latin part', 'Test Customer' === LP_Calc_PDF::printable( 'Test محمد Customer' ) );
check( 'printable(): mostly Arabic gives —', '—' === LP_Calc_PDF::printable( 'شركة الأمل LLC' ) );
check( 'printable(): empty gives —', '—' === LP_Calc_PDF::printable( '   ' ) );
$mixed = array(
	'Test Co ذ.م.م'                      => 'Test Co',
	'Al Noor Trading (النور) LLC'        => 'Al Noor Trading LLC',
	'Al Noor Trading LLC - النور للتجارة' => 'Al Noor Trading LLC',
	'Ali علي بن محمد'                    => 'Ali',
	'شركة الاختبار ذ.م.م'                => '—',
	'A & B (Trading) LLC.'               => 'A & B (Trading) LLC.',
);
foreach ( $mixed as $in => $want ) {
	check( "printable(): mixed script \"$in\" gives \"$want\"", $want === LP_Calc_PDF::printable( $in ), LP_Calc_PDF::printable( $in ) );
}
check( 'defang(): schemes, www., mailto: and @ broken up for PDF viewers', 'pay at https: //evil.example/x, www .evil.example, mailto :a, a @ b.example' === LP_Calc_Quote_PDF::defang( 'pay at https://evil.example/x, www.evil.example, mailto:a, a@b.example' ) );
check( 'defang(): ordinary text unchanged', 'Test Trading LLC: Dubai / 3.5t' === LP_Calc_Quote_PDF::defang( 'Test Trading LLC: Dubai / 3.5t' ) );
$w->add_page();
$w->text( 40, 60, 'Paren (test) \\ back', array( 'size' => 12 ) );
$w->add_page();
$w->text( 40, 60, 'Second page' );
$out = $w->output();
check( 'two pages', 1 === preg_match( '#/Type /Pages /Kids \[[^\]]+\] /Count 2#', $out ) );
// xref offsets point at their objects.
preg_match( '/startxref\n(\d+)\n%%EOF\n$/', $out, $sx );
$xref_ok = isset( $sx[1] ) && 'xref' === substr( $out, (int) $sx[1], 4 );
if ( $xref_ok ) {
	preg_match( '/^xref\n0 (\d+)\n/', substr( $out, (int) $sx[1] ), $hdr );
	$entries = substr( $out, (int) $sx[1] + strlen( $hdr[0] ), 20 * (int) $hdr[1] );
	for ( $i = 1; $i < (int) $hdr[1]; $i++ ) {
		$off     = (int) substr( $entries, $i * 20, 10 );
		$xref_ok = $xref_ok && 0 === strpos( substr( $out, $off, 20 ), "$i 0 obj" );
	}
}
check( 'xref table: every offset points at "n 0 obj"; startxref correct', $xref_ok );
$streams_ok = true;
preg_match_all( '#<< /Length (\d+) /Filter /FlateDecode >>\nstream\n#', $out, $mm, PREG_OFFSET_CAPTURE );
foreach ( $mm[1] as $i => $len ) {
	$start      = $mm[0][ $i ][1] + strlen( $mm[0][ $i ][0] );
	$data       = substr( $out, $start, (int) $len[0] );
	$streams_ok = $streams_ok && "\nendstream" === substr( $out, $start + (int) $len[0], 10 ) && false !== @gzuncompress( $data );
}
check( 'FlateDecode streams: correct /Length and inflate', $streams_ok && count( $mm[1] ) === 2 );
$page1 = gzuncompress( substr( $out, $mm[0][0][1] + strlen( $mm[0][0][0] ), (int) $mm[1][0][0] ) );
check( 'strings escape ( ) \\', false !== strpos( $page1, '(Paren \\(test\\) \\\\ back) Tj' ) );
check( 'Info: Producer "Ledger Plus"', false !== strpos( $out, '/Producer (Ledger Plus)' ) );

/* ====================================================================== Shortcode (without the template) */
section( 'Shortcode' );
$GLOBALS['lp_test']['scripts'] = array();
$GLOBALS['lp_test']['styles']  = array();
$GLOBALS['lp_test']['caps']    = array();
$html                          = LP_Calc_Shortcode::render();
check( 'shortcode enqueues calculator.css and calculator.js', wp_style_is( 'lp-calc' ) && wp_script_is( 'lp-calc' ) && 'https://example.test/wp-content/plugins/ledgerplus-calculator/assets/calculator.js' === $GLOBALS['lp_test']['scripts']['lp-calc']['src'] );
check( 'real template renders the root and the config once', false !== strpos( $html, 'data-lp-calc' ) && 1 === substr_count( $html, 'id="lp-calc-config"' ) );
preg_match( '#<script type="application/json" id="lp-calc-config">(.*?)</script>#s', $html, $cj );
check( 'the page gets browser_config(), not config()', isset( $cj[1] ) && LP_Calc_Pricing::browser_config() === json_decode( $cj[1], true ) );
check( 'shortcode output (markup + config JSON): no amount', array() === lp_test_amounts( $html ), json_encode( lp_test_amounts( $html ) ) );
check( 'shortcode output: no aria-live, no total, no estimate box, no phone bar, no price hint', false === strpos( $html, 'aria-live' ) && false === strpos( $html, 'data-lp-calc-total' ) && false === strpos( $html, 'lp-calc__estimate' ) && false === strpos( $html, 'lp-calc__bar' ) && false === strpos( $html, 'lp-calc__service-price' ) );
check( 'shortcode output: step 2 hint and step 4 intro without amounts', false !== strpos( $html, 'We use this to price your UAE e-invoicing.' ) && false !== strpos( $html, '>Select one or more services.</p>' ) && false === strpos( $html, 'Your estimate updates' ) );
check( 'shortcode output: step 5 "Your package" with the default selection, intro and PDF note', false !== strpos( $html, '<span class="lp-calc__line-label">UAE e-invoicing</span><span class="lp-calc__line-detail">1,000 transactions a year</span>' ) && false !== strpos( $html, 'Leave your details to see your price.' ) && false !== strpos( $html, 'We&#039;ll also email your quotation to you as a PDF.' ) );
preg_match_all( '/\b(?:aria-controls|aria-describedby|aria-labelledby|for)="([^"]+)"/', $html, $refs );
preg_match_all( '/\bid="([^"]+)"/', $html, $ids );
$dangling = array();
foreach ( $refs[1] as $list ) {
	foreach ( preg_split( '/\s+/', $list ) as $rid ) {
		if ( '' !== $rid && ! in_array( $rid, $ids[1], true ) ) {
			$dangling[] = $rid;
		}
	}
}
check( 'shortcode output: every aria-controls, aria-describedby, aria-labelledby and for points at an id', array() === $dangling, implode( ',', $dangling ) );
add_filter( 'lp_calc_config', $pricey );
$GLOBALS['lp_test']['scripts'] = array();
$fhtml                         = LP_Calc_Shortcode::render();
remove_filter( 'lp_calc_config', $pricey );
check( 'shortcode output with prices added by a filter: no amount, no filtered price', array() === lp_test_amounts( $fhtml ) && false === strpos( $fhtml, '2150' ) && false === strpos( $fhtml, '2,150' ), json_encode( lp_test_amounts( $fhtml ) ) );
$script = LP_Calc_Shortcode::config_script( LP_Calc_Pricing::browser_config() );
check( 'config script: application/json, id, valid JSON, no "</"', 1 === preg_match( '#^<script type="application/json" id="lp-calc-config">(.*)</script>$#s', $script, $js ) && is_array( json_decode( $js[1], true ) ) && false === strpos( $js[1], '</' ) );
$GLOBALS['lp_test']['scripts'] = array();
$GLOBALS['lp_test']['singular'] = true;
$GLOBALS['lp_test']['queried']  = (object) array( 'ID' => 12, 'post_content' => '' );
$GLOBALS['lp_test']['post_meta'][12]['_elementor_data'] = '[{"widgetType":"shortcode","settings":{"shortcode":"[lp_package_calculator]"}}]';
LP_Calc_Shortcode::maybe_enqueue_early();
check( 'assets enqueued early on an Elementor page with the shortcode', wp_script_is( 'lp-calc' ) );
$GLOBALS['lp_test']['scripts'] = array();
$GLOBALS['lp_test']['queried'] = (object) array( 'ID' => 13, 'post_content' => 'Hello' );
LP_Calc_Shortcode::maybe_enqueue_early();
check( 'not enqueued on other pages', ! wp_script_is( 'lp-calc' ) );

/* ====================================================================== Uninstall */
section( 'Uninstall' );
$un = file_get_contents( dirname( $plugin ) . '/uninstall.php' );
check( 'uninstall.php checks WP_UNINSTALL_PLUGIN and deletes lp_calc_ transients only', false !== strpos( $un, "defined( 'WP_UNINSTALL_PLUGIN' )" ) && false !== strpos( $un, "'_transient_lp_calc_'" ) && false !== strpos( $un, "'_transient_timeout_lp_calc_'" ) );

/* ====================================================================== PHP notices */
section( 'PHP notices / warnings' );
check( 'no PHP notices, warnings or deprecations', array() === $GLOBALS['lp_php_errors'], implode( ' | ', $GLOBALS['lp_php_errors'] ) );

// Clean up.
foreach ( (array) glob( LP_TEST_TMP . '/*' ) as $f ) {
	is_file( $f ) && unlink( $f );
}
@rmdir( LP_TEST_TMP );

echo "\n{$GLOBALS['lp_results']['pass']} passed, {$GLOBALS['lp_results']['fail']} failed\n";
exit( $GLOBALS['lp_results']['fail'] > 0 ? 1 : 0 );
