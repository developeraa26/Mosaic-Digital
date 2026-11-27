<?php
/**
 * Independent restatement of the SPEC.md pricing rules, used as a test oracle when the plugin's
 * LP_Calc_Pricing is not available (and cross-checked against it when it is).
 */
function lp_calc_ui_ref_money( $n ) {
	return 'AED ' . number_format( (int) $n );
}

/**
 * @param array $p   prices from the config.
 * @param array $sel services (keys), transactions, vat_status, ct_status, employees, reports.
 * @return array{lines: array, total: int}
 */
function lp_calc_ui_ref_compute( array $p, array $labels, array $sel ) {
	$order = array( 'accounting', 'reports', 'vat', 'ct', 'payroll', 'expense', 'invoicing', 'einvoicing', 'account_manager' );
	$lines = array();
	$total = 0;
	foreach ( $order as $key ) {
		if ( ! in_array( $key, $sel['services'], true ) ) {
			continue;
		}
		switch ( $key ) {
			case 'accounting':
				$a = $p['accounting'];
				$d = 'Annual service';
				break;
			case 'reports':
				$n = (int) $sel['reports'];
				$a = $p['report_each'] * $n;
				$d = $n . ( 1 === $n ? ' report' : ' reports' ) . ' a year × ' . lp_calc_ui_ref_money( $p['report_each'] );
				break;
			case 'vat':
				$a = $p['vat'] + ( 'not_registered' === $sel['vat_status'] ? $p['vat_registration'] : 0 );
				$d = 'not_registered' === $sel['vat_status'] ? 'Includes VAT registration (' . lp_calc_ui_ref_money( $p['vat_registration'] ) . ')' : 'Already VAT registered';
				break;
			case 'ct':
				$a = 'registered' === $sel['ct_status'] ? $p['ct_registered'] : $p['ct_not_registered'];
				$d = 'registered' === $sel['ct_status'] ? 'Already registered for Corporate Tax' : 'Includes Corporate Tax registration';
				break;
			case 'payroll':
				$a = $p['payroll'] + ( 'over_20' === $sel['employees'] ? $p['payroll_over_20'] : 0 );
				$d = 'over_20' === $sel['employees'] ? 'More than 20 employees' : 'Up to 20 employees';
				break;
			case 'einvoicing':
				$a = (int) ceil( $sel['transactions'] / 1000 ) * $p['einvoicing_per_1000'];
				$d = number_format( $sel['transactions'] ) . ' transactions a year (' . lp_calc_ui_ref_money( $p['einvoicing_per_1000'] ) . ' per 1,000)';
				break;
			case 'account_manager':
				$a = $p['account_manager'];
				$d = 'Included';
				break;
			default:
				$a = $p[ $key ];
				$d = 'Annual service';
		}
		$lines[] = array( 'key' => $key, 'label' => $labels[ $key ], 'detail' => $d, 'amount' => (int) $a );
		$total  += (int) $a;
	}
	return array( 'lines' => $lines, 'total' => $total );
}
