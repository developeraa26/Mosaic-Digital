<?php
/**
 * Writes sample quotation PDFs and client emails to calculator/test/out/ and checks them:
 * pdftotext must show the expected strings; pdftoppm -r 110 PNGs are saved for visual review.
 * Usage: php make-samples.php [out-dir]
 */

require __DIR__ . '/stubs-wp.php';
require __DIR__ . '/stubs-elementor.php';
define( 'LP_TEST_TMP', sys_get_temp_dir() . '/lp-calc-samples-' . getmypid() );
define( 'LP_TEST_LOG', LP_TEST_TMP . '/php-error.log' );
require __DIR__ . '/fixtures.php';
require dirname( __DIR__, 2 ) . '/plugin/ledgerplus-calculator/ledgerplus-calculator.php';

$out = isset( $argv[1] ) ? rtrim( $argv[1], '/' ) : dirname( __DIR__ ) . '/out';
if ( ! is_dir( $out ) ) {
	mkdir( $out, 0755, true );
}

$logo = LP_TEST_LOGO;
add_filter(
	'lp_calc_logo_path',
	function () use ( &$logo ) {
		return $logo;
	}
);

$cases = array(
	'excel'    => array(
		'raw'     => array( 'business' => 'Trading / Wholesale', 'transactions' => '1000', 'vat_status' => 'registered', 'ct_status' => 'not_registered', 'employees' => 'up_to_20', 'services' => 'accounting,vat,ct,payroll' ),
		'contact' => array( 'name' => 'Test Customer', 'company' => 'Test Trading LLC', 'email' => 'test.customer@example.com', 'phone' => '+971 50 000 0000' ),
		'expect'  => array( 'QUOTATION ESTIMATE', 'Reference LPQ-261127-4F2A9', 'Date 27 November 2026', 'PREPARED FOR', 'Test Customer', 'Test Trading LLC', 'test.customer@example.com', 'Trading / Wholesale', 'BUSINESS PROFILE', 'SELECTED SERVICES AND ANNUAL CHARGES', 'NOTES', 'QUOTATION ACCEPTANCE', 'Already registered', 'Not registered', 'Up to 20 employees', 'Accounting and bookkeeping', 'Annual service', 'VAT returns filing and compliance', 'Corporate Tax registration and filing', 'Payroll and WPS support', 'Estimated annual total (incl. 5% VAT)', 'AED 7,000', 'This is an indicative estimate based on the information you provided.', 'Any services outside the agreed scope will be quoted separately.', 'subject to confirmation and a final service agreement.', 'To proceed, reply to the email this quotation came with, or contact us on +971 50 342 0832.', 'For Ledger Plus', 'For the customer: name / signature / date', 'Ledger Plus UAE · DIFC, Dubai, United Arab Emirates · +971 50 342 0832 · info@ledgerplus.ae · ledgerplus.ae', 'Page 1 of 1' ),
		'pages'   => 1,
		'total'   => 7000,
	),
	'max'      => array(
		'raw'     => array( 'business' => 'Other', 'business_other' => 'Yacht charter and marine services', 'transactions' => '50000', 'vat_status' => 'not_registered', 'ct_status' => 'not_registered', 'employees' => 'over_20', 'reports' => '4', 'services' => implode( ',', LP_Calc_Pricing::SERVICE_KEYS ) ),
		'contact' => array( 'name' => 'Test Customer With A Rather Long Name', 'company' => 'Example Holdings International Trading and Contracting LLC (Branch)', 'email' => 'accounts.department@example-holdings-international.com', 'phone' => '+971 (4) 000-0000' ),
		'expect'  => array( 'AED 37,000', 'Quarterly financial reports', '4 reports a year × AED 500', 'Includes VAT registration (AED 500)', 'Includes Corporate Tax registration', 'More than 20 employees', 'Expense management', 'Invoicing and payment tracking', 'UAE e-invoicing', '50,000 transactions a year (AED 500 per 1,000)', 'Dedicated account manager', 'Included', '25,000', 'Other (Yacht charter and marine', 'Page 1 of 2', 'Page 2 of 2', '(continued)' ),
		'pages'   => 2,
		'total'   => 37000,
	),
	'nonlatin' => array(
		'raw'     => array( 'business' => 'Other', 'business_other' => 'تجارة عامة', 'transactions' => '10000', 'vat_status' => 'not_registered', 'ct_status' => 'registered', 'services' => 'accounting,einvoicing,account_manager' ),
		'contact' => array( 'name' => 'محمد الاختبار', 'company' => 'شركة الاختبار ذ.م.م', 'email' => 'test@example.com', 'phone' => '+971 50 000 0000' ),
		'expect'  => array( 'Name —', 'Company —', 'Business activity Other', 'test@example.com', 'AED 7,000', '10,000 transactions a year (AED 500 per 1,000)', 'Page 1 of 1' ),
		'absent'  => array( '?' ),
		'pages'   => 1,
		'total'   => 7000,
	),
	// The e2e happy path: 6 services fit one page (notes and signatures included).
	'six'      => array(
		'raw'     => array( 'business' => 'Trading / Wholesale', 'transactions' => '10000', 'vat_status' => 'registered', 'ct_status' => 'not_registered', 'employees' => 'over_20', 'reports' => '2', 'services' => 'accounting,reports,vat,ct,payroll,einvoicing' ),
		'contact' => array( 'name' => 'Test Customer', 'company' => 'Test Trading LLC', 'email' => 'test.customer@example.com', 'phone' => '501234567' ),
		'expect'  => array( 'AED 14,000', 'NOTES', 'QUOTATION ACCEPTANCE', 'For the customer: name / signature / date', 'Page 1 of 1' ),
		'absent'  => array( '(continued)' ),
		'pages'   => 1,
		'total'   => 14000,
	),
	// Mixed-script names: the Arabic part is dropped with the punctuation between its letters.
	'mixed'    => array(
		'raw'     => array( 'business' => 'Other', 'business_other' => 'Trading (تجارة عامة)', 'transactions' => '1000', 'vat_status' => 'registered', 'ct_status' => 'registered', 'services' => 'accounting' ),
		'contact' => array( 'name' => 'Ali علي بن محمد', 'company' => 'Test Co ذ.م.م', 'email' => 'test@example.com', 'phone' => '+971500000000' ),
		'expect'  => array( 'Name Ali', 'Company Test Co', 'Other (Trading)', 'AED 2,000', 'Page 1 of 1' ),
		'absent'  => array( '..', '()', '?', 'Name —' ),
		'pages'   => 1,
		'total'   => 2000,
	),
	// Link-like text that got past the form (it rejects it): no PDF viewer may turn it into a link.
	'defang'   => array(
		'raw'     => array( 'business' => 'Other', 'business_other' => 'placeholder', 'transactions' => '1000', 'vat_status' => 'registered', 'ct_status' => 'registered', 'services' => 'accounting' ),
		'inject'  => array( 'business_other' => 'Refund at https://evil.example/r' ),
		'contact' => array( 'name' => 'Accounts www.evil.example/pay', 'company' => 'Overdue: pay at https://evil.example/ledgerplus', 'email' => 'test@example.com', 'phone' => '+971500000000' ),
		'expect'  => array( 'www .evil.example/pay', 'https: //evil.example/ledgerplus', 'https: //evil.example/r', 'test@example.com' ),
		'absent'  => array( '://', 'www.' ),
		'pages'   => 1,
		'total'   => 2000,
	),
	'wordmark' => array(
		'raw'     => array( 'business' => 'Retail', 'transactions' => '1000', 'vat_status' => 'registered', 'ct_status' => 'registered', 'services' => 'einvoicing' ),
		'contact' => array( 'name' => 'Test Customer', 'company' => 'Test Retail LLC', 'email' => 'test.customer@example.com', 'phone' => '+971 50 000 0000' ),
		'expect'  => array( 'LEDGER', 'PLUS', 'AED 500', 'Page 1 of 1' ),
		'pages'   => 1,
		'total'   => 500,
		'logo'    => '/nonexistent/logo.png',
	),
);

$fail = 0;
$have = array(
	'pdftotext' => '' !== trim( (string) shell_exec( 'command -v pdftotext' ) ),
	'pdftoppm'  => '' !== trim( (string) shell_exec( 'command -v pdftoppm' ) ),
	'pdfinfo'   => '' !== trim( (string) shell_exec( 'command -v pdfinfo' ) ),
);
foreach ( $cases as $name => $case ) {
	$logo = isset( $case['logo'] ) ? $case['logo'] : LP_TEST_LOGO;
	LP_Calc_Quote_PDF::reset_logo_cache();
	$norm = LP_Calc_Pricing::normalize( $case['raw'] );
	if ( $norm['errors'] ) {
		echo "  FAIL  $name: selection errors " . json_encode( $norm['errors'] ) . "\n";
		$fail++;
		continue;
	}
	if ( isset( $case['inject'] ) ) {
		$norm['selection'] = array_merge( $norm['selection'], $case['inject'] );
	}
	$quote = LP_Calc_Pricing::compute( $norm['selection'] );
	$data  = array(
		'ref'       => 'LPQ-261127-4F2A9',
		'date'      => '27 November 2026',
		'contact'   => $case['contact'],
		'selection' => $norm['selection'],
		'quote'     => $quote,
	);
	$t0  = microtime( true );
	$pdf = LP_Calc_Quote_PDF::render( $data );
	$ms  = (int) round( ( microtime( true ) - $t0 ) * 1000 );
	$file = "$out/quote-$name.pdf";
	file_put_contents( $file, $pdf );
	file_put_contents( "$out/email-$name.html", LP_Calc_Mailer::html( $data, true ) );
	file_put_contents( "$out/email-$name.txt", LP_Calc_Mailer::text( $data, true ) );

	$ok   = $quote['total'] === $case['total'];
	$why  = $ok ? '' : "total {$quote['total']}";
	$size = strlen( $pdf );
	if ( $have['pdftotext'] ) {
		$text = (string) shell_exec( 'pdftotext -enc UTF-8 ' . escapeshellarg( $file ) . ' - 2>&1' );
		$flat = preg_replace( '/\s+/u', ' ', $text );
		foreach ( $case['expect'] as $needle ) {
			if ( false === strpos( $flat, $needle ) ) {
				$ok   = false;
				$why .= " missing \"$needle\"";
			}
		}
		foreach ( isset( $case['absent'] ) ? $case['absent'] : array() as $needle ) {
			if ( false !== strpos( $flat, $needle ) ) {
				$ok   = false;
				$why .= " unexpected \"$needle\"";
			}
		}
	}
	if ( $have['pdfinfo'] ) {
		$info = (string) shell_exec( 'pdfinfo ' . escapeshellarg( $file ) . ' 2>&1' );
		if ( ! preg_match( '/^Pages:\s+' . $case['pages'] . '$/m', $info ) || ! preg_match( '/^PDF version:\s+1\.4$/m', $info ) || ! preg_match( '/^Page size:\s+595\.28 x 841\.89 pts \(A4\)/m', $info ) || false !== stripos( $info, 'error' ) ) {
			$ok   = false;
			$why .= ' pdfinfo: ' . str_replace( "\n", ' | ', trim( $info ) );
		}
		if ( ! preg_match( '/^Producer:\s+Ledger Plus$/m', $info ) || ! preg_match( '/^Title:\s+Ledger Plus quotation estimate LPQ-261127-4F2A9$/m', $info ) ) {
			$ok   = false;
			$why .= ' Info dictionary';
		}
	}
	if ( $have['pdftoppm'] ) {
		foreach ( glob( "$out/quote-$name-*.png" ) as $old ) {
			unlink( $old );
		}
		shell_exec( 'pdftoppm -r 110 -png ' . escapeshellarg( $file ) . ' ' . escapeshellarg( "$out/quote-$name" ) . ' 2>&1' );
		$pngs = glob( "$out/quote-$name-*.png" );
		if ( count( $pngs ) !== $case['pages'] ) {
			$ok   = false;
			$why .= ' png count ' . count( $pngs );
		}
	}
	echo ( $ok ? '  PASS' : '  FAIL' ) . "  sample $name: {$case['pages']} page(s), " . LP_Calc_Pricing::money( $quote['total'] ) . ", $size bytes, {$ms} ms$why\n";
	$fail += $ok ? 0 : 1;
}

foreach ( $have as $tool => $present ) {
	if ( ! $present ) {
		echo "  NOTE  $tool not found: those checks were skipped\n";
	}
}
echo $fail ? "\n$fail sample(s) failed\n" : "\nall samples OK (files in $out)\n";
exit( $fail ? 1 : 0 );
