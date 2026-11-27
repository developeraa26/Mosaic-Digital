<?php
/**
 * PHP side of the UI tests. Reads a JSON request on stdin.
 *
 * {"mode":"combos"}            every price-relevant combination with the PHP lines and total
 * {"mode":"quote","fields":{}} what the plugin would add to the Elementor response (lp_quote)
 *
 * Uses the real LP_Calc_Pricing (default), or the spec oracle with LP_CALC_CONFIG=stub.
 */
require_once __DIR__ . '/config-source.php';
require_once __DIR__ . '/ref-pricing.php';

$req    = json_decode( stream_get_contents( STDIN ), true );
$config = lp_calc_ui_config();
$real   = 'real' === $GLOBALS['lp_calc_ui_source'];
$labels = array();
foreach ( $config['services'] as $s ) {
	$labels[ $s['key'] ] = $s['label'];
}

$compute = static function ( array $sel ) use ( $real, $config, $labels ) {
	return $real ? LP_Calc_Pricing::compute( $sel ) : lp_calc_ui_ref_compute( $config['prices'], $labels, $sel );
};

if ( 'combos' === $req['mode'] ) {
	$keys = array( 'accounting', 'reports', 'vat', 'ct', 'payroll', 'expense', 'invoicing', 'einvoicing', 'account_manager' );
	$tx   = array( 1000, 2000, 3000, 4000, 5000, 10000, 20000, 30000, 40000, 50000 );
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
							$sel = array(
								'business'       => 'Retail',
								'business_other' => '',
								'services'       => $services,
								'transactions'   => $t,
								'vat_status'     => $vat,
								'ct_status'      => $ct,
								'employees'      => $emp,
								'reports'        => $rep,
							);
							$q     = $compute( $sel );
							$out[] = array(
								's' => $sel,
								't' => $q['total'],
								'l' => array_map( static function ( $l ) {
									return array( $l['key'], $l['amount'], $l['detail'] );
								}, $q['lines'] ),
							);
						}
					}
				}
			}
		}
	}
	echo json_encode( array( 'source' => $GLOBALS['lp_calc_ui_source'], 'combos' => $out ), JSON_UNESCAPED_UNICODE );
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
	if ( $real ) {
		$n      = LP_Calc_Pricing::normalize( $raw );
		$sel    = $n['selection'];
		$errors = $n['errors'];
	} else {
		$errors = array();
		$sel    = array(
			'services'     => array_values( array_filter( explode( ',', $raw['services'] ) ) ),
			'transactions' => (int) $raw['transactions'],
			'vat_status'   => $raw['vat_status'],
			'ct_status'    => $raw['ct_status'],
			'employees'    => $raw['employees'] ? $raw['employees'] : 'up_to_20',
			'reports'      => $raw['reports'] ? (int) $raw['reports'] : 4,
		);
		if ( ! $sel['services'] ) {
			$errors['services'] = 'Choose at least one service.';
		}
		if ( ! in_array( $sel['vat_status'], array( 'registered', 'not_registered' ), true ) || ! in_array( $sel['ct_status'], array( 'registered', 'not_registered' ), true ) ) {
			$errors['status'] = 'Tell us your tax registration status.';
		}
		if ( '' === $raw['business'] ) {
			$errors['business'] = 'Choose your business activity.';
		}
	}
	if ( $errors ) {
		echo json_encode( array( 'success' => false, 'errors' => $errors ) );
		exit( 0 );
	}
	$q     = $compute( $sel );
	$money = static function ( $n ) {
		return 0 === (int) $n ? 'Included' : 'AED ' . number_format( (int) $n );
	};
	echo json_encode(
		array(
			'success'   => true,
			'selection' => $sel,
			'lp_quote'  => array(
				'ref'        => 'LPQ-261127-4F2A9',
				'lines'      => array_map( static function ( $l ) use ( $money ) {
					return array( 'label' => $l['label'], 'detail' => $l['detail'], 'amount' => $money( $l['amount'] ) );
				}, $q['lines'] ),
				'total'      => 'AED ' . number_format( $q['total'] ),
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
