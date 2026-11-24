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
 */
require __DIR__ . '/stubs-options-wp715.php';
require __DIR__ . '/stubs.php';

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
