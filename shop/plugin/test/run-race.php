<?php
/**
 * Concurrency test: two "requests" work on the same paid order at the same time
 * (payment return + webhook, or webhook + thank-you page). PHP Fibers interleave them at
 * named points; the option functions follow WordPress 7.1.5 (stubs-options-wp715.php),
 * including INSERT ... ON DUPLICATE KEY UPDATE affected-rows semantics.
 *
 * Usage: php run-race.php <scenario>
 *   same-second      both pass add_option()'s existence check before either INSERT (same time())
 *   next-second      same, but the second request starts one second later
 *   stale-takeover   a stale lock from a dead request; both requests clear it at the same moment
 *   stale-worst      the residual case: both requests end up holding the lock (see the scenario)
 *   webhook-thankyou status hook (waits for the lock) vs. the thank-you page fallback
 *   geidea-callback-thankyou  F12: the Geidea callback guard and the thank-you page lookup complete the same
 *                             unpaid order at the same moment (both past the payment lock's existence check)
 *   geidea-thankyou-scheduled F12: thank-you page lookup vs. a scheduled check, paused at every order save
 */
require __DIR__ . '/stubs-options-wp715.php';
require __DIR__ . '/stubs.php';
require __DIR__ . '/geidea-fixtures.php';

$scenario = $argv[1] ?? 'next-second';
$plugin   = $argv[2] ?? dirname( __DIR__ ) . '/ledgerplus-shop/ledgerplus-shop.php';

$GLOBALS['errs'] = array();
set_error_handler(
	function ( $no, $str, $f, $l ) {
		$GLOBALS['errs'][] = "[$no] $str ($f:$l)";
		return true;
	}
);

require $plugin;
do_action( 'plugins_loaded' );
do_action( 'init' );
do_action( 'woocommerce_init' );
add_filter(
	'lp_shop_settings',
	function ( $s ) {
		$s['lock_wait_seconds'] = 3;
		return $s;
	}
);

$GLOBALS['lp_test']['products'][890] = new LP_Test_Product( true );
WC_Order::make( 101, 'buyer@example.com', 'processing', array( 890 ) );
$GLOBALS['adv']['out'] = array();

function status_hook_request( $name ) {
	return new Fiber(
		function () use ( $name ) {
			$GLOBALS['adv']['cur'] = $name;
			$order                 = wc_get_order( 101 ); // each request has its own object
			LP_Shop::on_order_paid( 101, $order );
			// What this request's processing email prints (same object, class-wc-email-customer-processing-order.php:88-104).
			$GLOBALS['adv']['out'][ $name ] = 'email=' . (string) $order->get_meta( '_lp_referral_code', true );
		}
	);
}

function thankyou_request( $name ) {
	return new Fiber(
		function () use ( $name ) {
			$GLOBALS['adv']['cur'] = $name;
			ob_start();
			LP_Shop::render_thankyou( 101 );
			$html = ob_get_clean();
			preg_match( '/data-lp-code="([^"]+)"/', $html, $m );
			$GLOBALS['adv']['out'][ $name ] = 'page=' . ( $m[1] ?? ( false !== strpos( $html, 'being prepared' ) ? '(being prepared)' : '(nothing)' ) );
		}
	);
}

/** Round-robin: resume each live fiber in turn until all are done. */
function run_all( array $fibers ) {
	$guard = 0;
	while ( $fibers && $guard++ < 500 ) {
		foreach ( $fibers as $k => $f ) {
			if ( ! $f->isStarted() ) {
				$f->start();
			} elseif ( ! $f->isTerminated() ) {
				$f->resume();
			}
			if ( $f->isTerminated() ) {
				unset( $fibers[ $k ] );
			}
		}
	}
}

$GLOBALS['adv']['suspend'] = array(
	'add_option:pre_insert'    => true,
	'delete_option:pre_delete' => true,
	'coupon_lookup'            => true,
);

/* ---- F12 scenarios ---- */
if ( 0 === strpos( $scenario, 'geidea-' ) ) {
	$GLOBALS['adv']['db']['woocommerce_geidea_settings'] = lp_geidea_settings();
	lp_make_geidea_order( 101 );
	$GLOBALS['lp_test']['http_handler'] = function () {
		if ( function_exists( 'adv_point' ) ) {
			adv_point( 'http' );
		}
		return lp_test_http_json( 200, lp_geidea_lookup_body( array( lp_geidea_failed_order( 101 ), lp_geidea_order( 101 ) ) ) );
	};
	$GLOBALS['wp']                        = (object) array( 'query_vars' => array( 'order-received' => 101 ) );
	$_GET                                 = array( 'key' => 'wc_order_key101' );
	$GLOBALS['lp_test']['order_received'] = true;
	$_SERVER['REQUEST_METHOD']            = 'POST';
	$fib = function ( $name, $fn ) {
		return new Fiber(
			function () use ( $name, $fn ) {
				$GLOBALS['adv']['cur'] = $name;
				$fn();
			}
		);
	};
	$callback  = $fib( 'C', function () { LP_Shop::handle_geidea_callback( lp_geidea_callback_body( 101 ) ); } );
	$thankyou  = $fib( 'T', function () { LP_Shop::maybe_check_geidea_on_thankyou(); } );
	$scheduled = $fib( 'S', function () { LP_Shop::run_scheduled_geidea_check( 101, 1 ); } );
	$GLOBALS['adv']['suspend'] = array(
		'add_option:pre_insert'    => true,
		'delete_option:pre_delete' => true,
		'http'                     => true,
		'order_save'               => true,
	);
	run_all( 'geidea-callback-thankyou' === $scenario ? array( $thankyou, $callback ) : array( $thankyou, $scheduled ) );

	$row   = $GLOBALS['lp_test']['orders'][101];
	$trans = array_values( array_filter( $GLOBALS['lp_test']['transitions'] ?? array(), function ( $t ) { return 101 === $t[0]; } ) );
	$notes = $GLOBALS['lp_test']['notes'][101] ?? array();
	$count = function ( $needle ) use ( $notes ) {
		return count( array_filter( $notes, function ( $n ) use ( $needle ) { return false !== strpos( $n, $needle ); } ) );
	};
	$live  = array_filter( $GLOBALS['lp_test']['coupons'], function ( $c ) { return 101 === ( $c['meta']['_lp_referrer_order_id'] ?? 0 ) && 'publish' === $c['props']['status']; } );
	echo "\n== race: $scenario ==\n";
	echo '  trace: ' . implode( ' | ', array_filter( $GLOBALS['adv']['trace'], function ( $t ) { return false !== strpos( $t, 'INSERT lp_shop_gdlock' ) || false !== strpos( $t, 'http' ) || false !== strpos( $t, 'order_save' ); } ) ) . "\n";
	echo '  status: ' . $row['status'] . ', transitions: ' . json_encode( $trans ) . "\n";
	$fail = 0;
	$ok   = function ( $name, $cond ) use ( &$fail ) {
		echo ( $cond ? '  PASS  ' : '  FAIL  ' ) . $name . "\n";
		if ( ! $cond ) {
			$fail++;
		}
	};
	$ok( 'order completed exactly once (one status transition, one "Payment via" note)', 'completed' === $row['status'] && array( array( 101, 'pending', 'completed' ) ) === $trans && 1 === $count( 'Payment via Geidea Online Payments' ) );
	$ok( 'one "payment confirmed by Geidea" note', 1 === $count( 'Ledger Plus: payment confirmed by Geidea' ) );
	$ok( 'Geidea meta set once, from the paid attempt', lp_geidea_order( 101 )['orderId'] === ( $row['meta']['Geidea Order Id'] ?? '' ) && lp_geidea_order( 101 )['orderId'] === ( $row['meta']['_lp_geidea_paid'] ?? '' ) );
	$ok( 'F3 ran once: one live referral coupon, stored on the order', 1 === count( $live ) && strtoupper( reset( $live )['props']['code'] ) === ( $row['meta']['_lp_referral_code'] ?? '' ) );
	$ok( 'payment and referral locks released', ! array_key_exists( 'lp_shop_gdlock_101', $GLOBALS['adv']['db'] ) && ! array_key_exists( 'lp_shop_gdlock_101_at', $GLOBALS['adv']['db'] ) && ! array_key_exists( 'lp_shop_lock_101', $GLOBALS['adv']['db'] ) );
	$ok( 'no PHP errors', array() === $GLOBALS['errs'] );
	foreach ( $GLOBALS['errs'] as $e ) {
		echo "    $e\n";
	}
	echo '  Result: ' . ( $fail ? "$fail failed" : 'all passed' ) . "\n";
	exit( $fail ? 1 : 0 );
}

/* ---- F14 scenarios (Zoho CRM sync; the add-on stub and the fake Zoho of zoho-fixtures.php) ---- */
if ( 0 === strpos( $scenario, 'zoho-' ) ) {
	require __DIR__ . '/zoho-fixtures.php';
	lp_zoho_reset();
	$GLOBALS['lp_test']['orders'][101]['email'] = 'race.buyer@example.com';
	$GLOBALS['wp']                              = (object) array( 'query_vars' => array( 'order-received' => 101 ) );
	$_GET                                       = array( 'key' => 'wc_order_key101' );
	$GLOBALS['lp_test']['order_received']       = true;
	$fib = function ( $name, $fn ) {
		return new Fiber(
			function () use ( $name, $fn ) {
				$GLOBALS['adv']['cur'] = $name;
				$fn();
			}
		);
	};
	$GLOBALS['adv']['suspend'] = array(
		'add_option:pre_insert'    => true,
		'delete_option:pre_delete' => true,
		'zoho_http'                => true,
	);
	$expect_updates = 0;
	if ( 'zoho-thankyou-job' === $scenario || 'zoho-job-thankyou' === $scenario ) {
		// The order is paid; its background job and the thank-you page sync it at the same moment (both past the
		// lock's existence check before either INSERT).
		$job      = $fib( 'J', function () { LP_Shop::run_zoho_job( 101, 'paid' ); } );
		$thankyou = $fib( 'T', function () { LP_Shop::maybe_sync_zoho_on_thankyou(); } );
		run_all( 'zoho-thankyou-job' === $scenario ? array( $thankyou, $job ) : array( $job, $thankyou ) );
	} else {
		// zoho-checkout-paid: the checkout job holds the lock and is waiting for Zoho's search answer when the order is
		// paid; the paid job starts then and has to wait. It must not create a second record, and must still set
		// Closed Opportunity (it reads the order again inside the lock).
		$GLOBALS['lp_test']['orders'][101]['status']    = 'pending';
		$GLOBALS['lp_test']['orders'][101]['date_paid'] = null;
		$checkout = $fib( 'C', function () { LP_Shop::run_zoho_job( 101, 'checkout' ); } );
		$checkout->start();
		while ( ! $checkout->isTerminated() && 'C:zoho_http' !== end( $GLOBALS['adv']['trace'] ) ) {
			$checkout->resume();
		}
		$GLOBALS['adv']['cur'] = 'main';
		$GLOBALS['adv']['suspend'] = array();
		wc_get_order( 101 )->update_status( 'processing', 'Paid.' );
		$GLOBALS['adv']['suspend'] = array(
			'add_option:pre_insert'    => true,
			'add_option:exists'        => true, // The paid job finds the lock taken: let the checkout job go on.
			'delete_option:pre_delete' => true,
			'zoho_http'                => true,
		);
		$paid = $fib( 'P', function () { LP_Shop::run_zoho_job( 101, 'paid' ); } );
		run_all( array( $checkout, $paid ) );
		$expect_updates = 1;
	}
	$GLOBALS['adv']['suspend'] = array();

	$records = $GLOBALS['lp_zoho']['records'];
	$rec     = reset( $records );
	$rid     = is_array( $rec ) ? $rec['id'] : '';
	$titles  = array_map( function ( $n ) { return $n['Note_Title']; }, lp_zoho_notes_of( $rid ) );
	$writes  = $GLOBALS['lp_zoho']['writes'];
	$row     = $GLOBALS['lp_test']['orders'][101];
	$synced  = array_filter( $GLOBALS['lp_test']['notes'][101] ?? array(), function ( $n ) { return false !== strpos( $n, 'sent to Zoho CRM' ); } );
	echo "\n== race: $scenario ==\n";
	echo '  trace: ' . implode( ' | ', array_filter( $GLOBALS['adv']['trace'], function ( $t ) { return false !== strpos( $t, 'zlock' ) || false !== strpos( $t, 'zoho_http' ); } ) ) . "\n";
	echo '  Zoho: ' . count( $records ) . ' record(s), notes: ' . implode( ', ', $titles ) . ', writes: ' . implode( ', ', array_column( $writes, 'op' ) ) . "\n";
	$fail = 0;
	$ok   = function ( $name, $cond ) use ( &$fail ) {
		echo ( $cond ? '  PASS  ' : '  FAIL  ' ) . $name . "\n";
		if ( ! $cond ) {
			$fail++;
		}
	};
	$ok( 'exactly one Zoho record, created once, now Closed Opportunity', 1 === count( $records ) && 1 === count( array_filter( $writes, function ( $w ) { return 'create' === $w['op']; } ) ) && 'Closed Opportunity' === ( $rec['Lead_Status'] ?? '' ) );
	$ok( 'one checkout note and one payment note on it', array( 'Ledger Plus Shop order #101 placed', 'Ledger Plus Shop order #101 paid' ) === $titles );
	$ok( $expect_updates ? 'one update (Prospecting -> Closed Opportunity by the paid job, after reading the order again in the lock)' : 'no update (created as Closed Opportunity)', $expect_updates === count( array_filter( $writes, function ( $w ) { return 'update' === $w['op']; } ) ) );
	$ok( 'order meta: that record, stage paid, both notes', $rid === ( $row['meta']['_lp_zoho_record_id'] ?? '' ) && 'paid' === ( $row['meta']['_lp_zoho_stage'] ?? '' ) && 'checkout,paid' === ( $row['meta']['_lp_zoho_notes'] ?? '' ) );
	$ok( $expect_updates ? 'two "sent to Zoho CRM" order notes (one per job)' : 'one "sent to Zoho CRM" order note (the other request found nothing to send)', ( $expect_updates ? 2 : 1 ) === count( $synced ) );
	$ok( 'Zoho lock released', ! array_key_exists( 'lp_shop_zlock_101', $GLOBALS['adv']['db'] ) && ! array_key_exists( 'lp_shop_zlock_101_at', $GLOBALS['adv']['db'] ) );
	$ok( 'no PHP errors', array() === $GLOBALS['errs'] );
	foreach ( $GLOBALS['errs'] as $e ) {
		echo "    $e\n";
	}
	echo '  Result: ' . ( $fail ? "$fail failed" : 'all passed' ) . "\n";
	exit( $fail ? 1 : 0 );
}

switch ( $scenario ) {
	case 'same-second':
	case 'next-second':
		$a = status_hook_request( 'A' );
		$b = status_hook_request( 'B' );
		$a->start();                   // A: existence check misses, pauses before INSERT.
		if ( 'next-second' === $scenario ) {
			$t = time();
			while ( time() === $t ) {
				usleep( 20000 );
			}
		}
		$b->start();                   // B: existence check misses too, pauses before INSERT.
		run_all( array( $a, $b ) );
		break;

	case 'stale-takeover':
		$GLOBALS['adv']['db']['lp_shop_lock_101']    = '1';
		$GLOBALS['adv']['db']['lp_shop_lock_101_at'] = (string) ( time() - 500 );
		run_all( array( status_hook_request( 'A' ), status_hook_request( 'B' ) ) );
		break;

	case 'stale-worst':
		// Worst case for the lock: a stale lock, A clears it and takes a new one while B is paused
		// just before its own clearing delete; B then deletes A's fresh lock and takes one too.
		// Both requests look up existing coupons before either creates one. Only the
		// reconciliation in issue_referral_code() can save the day here.
		$GLOBALS['adv']['db']['lp_shop_lock_101']    = '1';
		$GLOBALS['adv']['db']['lp_shop_lock_101_at'] = (string) ( time() - 500 );
		$GLOBALS['adv']['suspend'] = array( 'delete_option:pre_delete' => true );
		$a = status_hook_request( 'A' );
		$b = status_hook_request( 'B' );
		$a->start();                   // A: stale lock seen, paused before deleting it.
		$b->start();                   // B: same.
		$GLOBALS['adv']['suspend'] = array( 'B:delete_option:pre_delete' => true, 'coupon_lookup_done' => true );
		$a->resume();                  // A: deletes, retries, INSERT -> holds the lock, paused after its (empty) coupon lookup.
		while ( ! $b->isTerminated() && ! in_array( 'B:coupon_lookup_done', array_slice( $GLOBALS['adv']['trace'], -1 ), true ) ) {
			$b->resume();              // B: deletes A's fresh lock (!), retries, INSERT -> holds it too, paused after its empty lookup.
		}
		$GLOBALS['adv']['suspend'] = array();
		run_all( array( $a, $b ) );    // Both create a coupon; reconciliation keeps the oldest.
		break;

	case 'webhook-thankyou':
		run_all( array( thankyou_request( 'T' ), status_hook_request( 'W' ) ) );
		break;
}

$live = array();
$all  = 0;
foreach ( $GLOBALS['lp_test']['coupons'] as $c ) {
	if ( 101 === ( $c['meta']['_lp_referrer_order_id'] ?? 0 ) ) {
		$all++;
		if ( 'publish' === $c['props']['status'] ) {
			$live[] = strtoupper( $c['props']['code'] );
		}
	}
}
$stored = (string) ( $GLOBALS['lp_test']['orders'][101]['meta']['_lp_referral_code'] ?? '' );
$out    = $GLOBALS['adv']['out'];
$codes  = array();
foreach ( $out as $line ) {
	if ( preg_match( '/=(LP-[A-Z0-9]{6})$/', $line, $m ) ) {
		$codes[] = $m[1];
	}
}

echo "\n== race: $scenario ==\n";
echo '  trace: ' . implode( ' | ', array_filter( $GLOBALS['adv']['trace'], function ( $t ) { return false !== strpos( $t, 'INSERT' ) || false !== strpos( $t, 'delete' ); } ) ) . "\n";
echo '  coupons for order 101: ' . $all . ' (live: ' . implode( ', ', $live ) . ")\n";
echo '  stored on order: ' . $stored . "\n";
echo '  per request: ' . implode( '  ', array_map( function ( $k, $v ) { return "$k $v"; }, array_keys( $out ), $out ) ) . "\n";

$fail = 0;
$ok   = function ( $name, $cond ) use ( &$fail ) {
	echo ( $cond ? '  PASS  ' : '  FAIL  ' ) . $name . "\n";
	if ( ! $cond ) {
		$fail++;
	}
};
$ok( 'exactly one live referral coupon', 1 === count( $live ) );
$ok( 'the order stores that code', isset( $live[0] ) && $stored === $live[0] );
$ok( 'every request that shows a code shows that code', ! array_diff( $codes, $live ) );
if ( 'webhook-thankyou' !== $scenario ) {
	$ok( 'both status-hook requests put the code on their own order object (emails)', 2 === count( $codes ) );
} else {
	$ok( 'the status hook request has the code for its email', isset( $out['W'] ) && 'email=' . ( $live[0] ?? '?' ) === $out['W'] );
	$ok( 'the thank-you page shows the code or "being prepared", never an empty box', isset( $out['T'] ) && '(nothing)' !== substr( $out['T'], 5 ) );
}
$ok( 'one "code created" note' . ( 'stale-worst' === $scenario ? ' (or one per request plus a duplicate note)' : '' ), 'stale-worst' === $scenario || 1 === count( array_filter( $GLOBALS['lp_test']['notes'][101] ?? array(), function ( $n ) { return false !== strpos( $n, 'created for this customer' ); } ) ) );
$ok( 'lock options removed', ! array_key_exists( 'lp_shop_lock_101', $GLOBALS['adv']['db'] ) && ! array_key_exists( 'lp_shop_lock_101_at', $GLOBALS['adv']['db'] ) );
$ok( 'no PHP errors', array() === $GLOBALS['errs'] );
foreach ( $GLOBALS['errs'] as $e ) {
	echo "    $e\n";
}
echo "  Result: " . ( $fail ? "$fail failed" : 'all passed' ) . "\n";
exit( $fail ? 1 : 0 );
