<?php
/**
 * PHP side of the UI tests, with the plugin's real LP_Calc_Pricing. Reads a JSON request on stdin.
 *
 * {"mode":"prices"}            default_prices() and the transaction counts (for test/ui/amounts.js)
 * {"mode":"package"}           every selection that changes the quote or the package list, with the
 *                              server's quote line keys and its "Your package" lines (package_lines())
 * {"mode":"quote","fields":{}} what the plugin would add to the Elementor response (lp_quote)
 */
require_once __DIR__ . '/config-source.php';

$req    = json_decode( stream_get_contents( STDIN ), true );
$config = lp_calc_ui_config();

if ( 'prices' === $req['mode'] ) {
	echo json_encode(
		array(
			'prices'       => LP_Calc_Pricing::default_prices(),
			'transactions' => LP_Calc_Pricing::TRANSACTIONS,
			'currency'     => LP_Calc_Pricing::CURRENCY,
		)
	);
	exit( 0 );
}

if ( 'package' === $req['mode'] ) {
	$keys = LP_Calc_Pricing::SERVICE_KEYS;
	$tx   = LP_Calc_Pricing::TRANSACTIONS;
	$out  = array();
	for ( $mask = 1; $mask < 512; $mask++ ) {
		$services = array();
		foreach ( $keys as $i => $k ) {
			if ( $mask & ( 1 << $i ) ) {
				$services[] = $k;
			}
		}
		$emps = in_array( 'payroll', $services, true ) ? array( 'up_to_20', 'over_20' ) : array( 'up_to_20' );
		$reps = in_array( 'reports', $services, true ) ? array( 1, 2, 3, 4 ) : array( 4 );
		foreach ( array( 'registered', 'not_registered' ) as $vat ) {
			foreach ( array( 'registered', 'not_registered' ) as $ct ) {
				foreach ( $emps as $emp ) {
					foreach ( $reps as $rep ) {
						foreach ( $tx as $t ) {
							$sel   = array(
								'business'       => 'Retail',
								'business_other' => '',
								'services'       => $services,
								'transactions'   => $t,
								'vat_status'     => $vat,
								'ct_status'      => $ct,
								'employees'      => $emp,
								'reports'        => $rep,
							);
							$out[] = array(
								's' => $sel,
								'k' => array_column( LP_Calc_Pricing::compute( $sel )['lines'], 'key' ),
								'p' => LP_Calc_Pricing::package_lines( $sel ),
							);
						}
					}
				}
			}
		}
	}
	echo json_encode( array( 'combos' => $out ), JSON_UNESCAPED_UNICODE );
	exit( 0 );
}

if ( 'quote' === $req['mode'] ) {
	$f   = $req['fields'];
	$raw = array(
		'business'       => isset( $f['lpc_business'] ) ? $f['lpc_business'] : '',
		'business_other' => isset( $f['lpc_business_other'] ) ? $f['lpc_business_other'] : '',
		'transactions'   => isset( $f['lpc_transactions'] ) ? $f['lpc_transactions'] : '',
		'vat_status'     => isset( $f['lpc_vat_status'] ) ? $f['lpc_vat_status'] : '',
		'ct_status'      => isset( $f['lpc_ct_status'] ) ? $f['lpc_ct_status'] : '',
		'employees'      => isset( $f['lpc_employees'] ) ? $f['lpc_employees'] : '',
		'reports'        => isset( $f['lpc_reports'] ) ? $f['lpc_reports'] : '',
		'services'       => isset( $f['lpc_services'] ) ? $f['lpc_services'] : '',
	);
	$n      = LP_Calc_Pricing::normalize( $raw );
	$sel    = $n['selection'];
	$errors = $n['errors'];
	if ( $errors ) {
		echo json_encode( array( 'success' => false, 'errors' => $errors ) );
		exit( 0 );
	}
	$q = LP_Calc_Pricing::compute( $sel );
	echo json_encode(
		array(
			'success'   => true,
			'selection' => $sel,
			'lp_quote'  => array(
				'ref'        => 'LPQ-261127-4F2A9',
				'lines'      => array_map( static function ( $l ) {
					return array( 'key' => $l['key'], 'label' => $l['label'], 'detail' => $l['detail'], 'amount' => LP_Calc_Pricing::line_amount_text( $l ) );
				}, $q['lines'] ),
				'total'      => LP_Calc_Pricing::money( $q['total'] ),
				'email'      => isset( $f['email'] ) ? $f['email'] : '',
				'email_sent' => true,
			),
		),
		JSON_UNESCAPED_UNICODE
	);
	exit( 0 );
}

fwrite( STDERR, "unknown mode\n" );
exit( 2 );
