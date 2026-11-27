<?php
/**
 * Prices many selections with the real LP_Calc_Pricing, for the JS parity test.
 *
 * Input (a file path argument, or stdin): a JSON array of raw selections, each with the keys
 * that LP_Calc_Pricing::normalize() reads: business, business_other, transactions, vat_status,
 * ct_status, employees, reports, services (comma-separated keys or an array).
 *
 * Output (stdout): a JSON array, one entry per input, in the same order:
 *   { "total": 7000, "lines": [ { "key", "label", "detail", "amount" } ], "errors": {} }
 *
 * Usage: php compute-cli.php selections.json > totals.json
 *        echo '[{"business":"Retail",...}]' | php compute-cli.php
 */

require __DIR__ . '/stubs-wp.php';
require dirname( __DIR__, 2 ) . '/plugin/ledgerplus-calculator/includes/class-lp-calc-pricing.php';

$json = isset( $argv[1] ) && '-' !== $argv[1] ? file_get_contents( $argv[1] ) : stream_get_contents( STDIN );
$list = json_decode( (string) $json, true );
if ( ! is_array( $list ) ) {
	fwrite( STDERR, "compute-cli: input must be a JSON array of selections\n" );
	exit( 2 );
}

$out = array();
foreach ( $list as $raw ) {
	$norm  = LP_Calc_Pricing::normalize( is_array( $raw ) ? $raw : array() );
	$quote = $norm['errors'] ? array( 'lines' => array(), 'total' => null ) : LP_Calc_Pricing::compute( $norm['selection'] );
	$out[] = array(
		'total'  => $quote['total'],
		'lines'  => $quote['lines'],
		'errors' => (object) $norm['errors'],
	);
}
echo json_encode( $out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ), "\n";
