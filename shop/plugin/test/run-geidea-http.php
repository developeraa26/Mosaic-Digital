<?php
/**
 * F12a end to end: real HTTP callbacks to PHP's built-in server (geidea-server.php), so php://input, the
 * WooCommerce output buffer, Geidea's die(), http_response_code() and the shutdown order are PHP's own.
 * Usage: php run-geidea-http.php [path-to-plugin-file]
 */

require __DIR__ . '/stubs.php';
require __DIR__ . '/geidea-fixtures.php';

$plugin = realpath( $argv[1] ?? dirname( __DIR__ ) . '/ledgerplus-shop/ledgerplus-shop.php' );
$tmp    = sys_get_temp_dir() . '/lp-geidea-http-' . getmypid();
@mkdir( $tmp, 0700, true );
$state_file = $tmp . '/state.ser';
$log_file   = $tmp . '/server.log';

$pass = 0;
$fail = 0;
function hcheck( $name, $cond, $detail = '' ) {
	global $pass, $fail;
	if ( $cond ) {
		$pass++;
		echo "  PASS  $name\n";
	} else {
		$fail++;
		echo "  FAIL  $name" . ( '' !== $detail ? "  -- $detail" : '' ) . "\n";
	}
}

// Free port, then the server.
$probe = stream_socket_server( 'tcp://127.0.0.1:0', $errno, $errstr );
$port  = (int) substr( strrchr( stream_socket_get_name( $probe, false ), ':' ), 1 );
fclose( $probe );
$env  = array_merge( getenv(), array( 'LP_GEIDEA_STATE' => $state_file, 'LP_GEIDEA_PLUGIN' => $plugin ) );
$proc = proc_open(
	array( PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'error_log=' . $log_file, '-S', '127.0.0.1:' . $port, __DIR__ . '/geidea-server.php' ),
	array( 0 => array( 'pipe', 'r' ), 1 => array( 'file', $tmp . '/stdout.log', 'a' ), 2 => array( 'file', $tmp . '/stdout.log', 'a' ) ),
	$pipes,
	__DIR__,
	$env
);
$up = false;
for ( $i = 0; $i < 100 && ! $up; $i++ ) {
	$c = @fsockopen( '127.0.0.1', $port, $e1, $e2, 0.2 );
	if ( $c ) {
		fclose( $c );
		$up = true;
	} else {
		usleep( 50000 );
	}
}
echo "\n== F12a: real dispatch through PHP's built-in server (port $port) ==\n";
hcheck( 'built-in server started', $up );
if ( ! $up ) {
	proc_terminate( $proc );
	exit( 1 );
}

$GLOBALS['lp_test']['options']['woocommerce_geidea_settings'] = lp_geidea_settings();
$server_errors = array();

/** One request: write the state, send it, read the state back. */
function request( $method, $body, $config = array(), $query = 'wc-api=geidea' ) {
	global $state_file, $port, $server_errors;
	file_put_contents( $state_file, serialize( array( 'config' => $config, 'lp_test' => $GLOBALS['lp_test'] ) ) );
	$ctx  = stream_context_create(
		array(
			'http' => array(
				'method'        => $method,
				'header'        => "Content-Type: application/json\r\n",
				'content'       => 'POST' === $method ? $body : '',
				'ignore_errors' => true,
				'timeout'       => 30,
			),
		)
	);
	$resp = file_get_contents( "http://127.0.0.1:$port/?$query", false, $ctx );
	$code = 0;
	if ( isset( $http_response_header[0] ) && preg_match( '/^HTTP\/\S+\s+(\d{3})/', $http_response_header[0], $m ) ) {
		$code = (int) $m[1];
	}
	$saved = unserialize( file_get_contents( $state_file ) );
	foreach ( $saved['lp_test'] as $k => $v ) {
		$GLOBALS['lp_test'][ $k ] = $v;
	}
	foreach ( $GLOBALS['lp_test']['php_errors'] ?? array() as $e ) {
		$server_errors[] = $e;
	}
	return array( $code, (string) $resp );
}
function hnotes( $id, $needle ) {
	return array_values( array_filter( $GLOBALS['lp_test']['notes'][ $id ] ?? array(), function ( $n ) use ( $needle ) { return false !== strpos( $n, $needle ); } ) );
}
function htransitions( $id ) {
	return array_values( array_filter( $GLOBALS['lp_test']['transitions'] ?? array(), function ( $t ) use ( $id ) { return $id === $t[0]; } ) );
}
function hrow( $id ) {
	return $GLOBALS['lp_test']['orders'][ $id ];
}

/* A. Valid paid callback. */
lp_make_geidea_order( 940 );
$gid = lp_geidea_order( 940 )['orderId'];
list( $code, $body ) = request( 'POST', lp_geidea_callback_body( 940 ) );
hcheck( 'valid callback: Geidea gets 200 "Order is completed!" (its own reply)', 200 === $code && 'Order is completed!' === $body, "$code $body" );
hcheck( '... order completed by Ledger Plus before Geidea\'s handler ran, one transition', 'completed' === hrow( 940 )['status'] && array( array( 940, 'pending', 'completed' ) ) === htransitions( 940 ) && $gid === hrow( 940 )['meta']['_lp_geidea_paid'] );
hcheck( '... Geidea\'s handler still ran (read php://input again, saved its card token, emptied the cart)', 1 === ( $GLOBALS['lp_test']['geidea_handler_ran'] ?? 0 ) && 1 === count( $GLOBALS['lp_test']['tokens_saved'] ?? array() ) );
hcheck( '... meta for refunds through Geidea', $gid === hrow( 940 )['meta']['Geidea Order Id'] && 'wc-completed' === hrow( 940 )['meta']['Order Success Status Setting'] && $gid === hrow( 940 )['transaction_id'] );
$n = hnotes( 940, 'Geidea callback:' );
hcheck( '... one callback note with the real HTTP status and reply', 1 === count( $n ) && 'Geidea callback: Success / Paid, Geidea order ' . $gid . ', AED 1.00. Signature OK. Ledger Plus completed the order (now Completed). Site reply: 200 Order is completed! (Order status now: Completed.)' === $n[0], $n[0] ?? '(none)' );
hcheck( '... referral code issued once (F3), even though woocommerce_payment_complete fired twice (Ledger Plus + Geidea)', 1 === count( array_filter( $GLOBALS['lp_test']['coupons'], function ( $c ) { return 940 === ( $c['meta']['_lp_referrer_order_id'] ?? 0 ); } ) ) && 2 === ( $GLOBALS['lp_test']['actions_done']['woocommerce_payment_complete'] ?? 0 ) && 1 === ( $GLOBALS['lp_test']['actions_done']['woocommerce_order_status_completed'] ?? 0 ) );
hcheck( '... a plain shutdown function registered during the request no longer sees the reply (WordPress flushed it at shutdown priority 1)', 0 === ( $GLOBALS['lp_test']['probe']['ob_level'] ?? -1 ) && '' === ( $GLOBALS['lp_test']['probe']['buffer'] ?? 'x' ) && 200 === ( $GLOBALS['lp_test']['probe']['code'] ?? 0 ) );
$last = $GLOBALS['lp_test']['options']['lp_shop_geidea_last_callback'] ?? array();
hcheck( '... last-callback option written', isset( $last['result'] ) && 0 === strpos( $last['result'], 'Order #940: Geidea callback: Success / Paid' ) );

/* B. The same callback again. */
list( $code, $body ) = request( 'POST', lp_geidea_callback_body( 940 ) );
$n = hnotes( 940, 'Geidea callback:' );
hcheck( 'duplicate callback: 200, still one transition and one coupon, second note says nothing changed', 200 === $code && 1 === count( htransitions( 940 ) ) && 1 === ( $GLOBALS['lp_test']['actions_done']['woocommerce_order_status_completed'] ?? 0 ) && 2 === count( $n ) && false !== strpos( $n[1], 'Ledger Plus did not change the order: Ledger Plus already completed it once. Site reply: 200 Order is completed!' ) );

/* C. Invalid signature. */
lp_make_geidea_order( 941 );
list( $code, $body ) = request( 'POST', lp_geidea_callback_body( 941, array(), false ) );
$n = hnotes( 941, 'Geidea callback:' );
hcheck( 'invalid signature: Geidea gets 400 "Invalid signature!", order stays pending, note records it', 400 === $code && 'Invalid signature!' === $body && 'pending' === hrow( 941 )['status'] && 1 === count( $n ) && 0 === strpos( $n[0], 'Unverified Geidea callback:' ) && false !== strpos( $n[0], 'Signature INVALID (the merchant key or API password in the Geidea settings does not match the Geidea account, or the request did not come from Geidea). Ledger Plus did not use it. Site reply: 400 Invalid signature!' ), $n[0] ?? '' );

/* D. Geidea's handler dies with a PHP fatal error after Ledger Plus completed the order. */
lp_make_geidea_order( 942 );
list( $code, $body ) = request( 'POST', lp_geidea_callback_body( 942 ), array( 'sim' => 'fatal' ) );
$n = hnotes( 942, 'Geidea callback:' );
hcheck( 'fatal in Geidea\'s handler: Geidea gets 500 and WordPress\'s error page', 500 === $code && false !== strpos( $body, 'There has been a critical error on this website.' ), "$code" );
hcheck( '... but the order was already completed by Ledger Plus', 'completed' === hrow( 942 )['status'] && 1 === count( htransitions( 942 ) ) );
hcheck( '... note captures status, reply and the fatal error (message + file:line, no stack trace)', 1 === count( $n ) && false !== strpos( $n[0], 'Site reply: 500 WordPress › Error There has been a critical error on this website. Learn more' ) && 1 === preg_match( '/PHP fatal error: Error: Call to undefined method WC_Order::get_product_from_item_v352\(\) \(geidea-sim\.php:\d+\) \(Order status now: Completed\.\)$/', $n[0] ) && false === strpos( $n[0], 'Stack trace' ), $n[0] ?? '' );
hcheck( '... logged as an error', (bool) array_filter( $GLOBALS['lp_test']['glogs'], function ( $l ) { return 0 === strpos( $l, 'error: Order #942: Geidea callback:' ); } ) );

/* D2. The fatal error is still named when a silenced warning comes after it (review finding 8). */
// Each case leaves only one of the three protections to do the work: the recorder's PHP_INT_MIN priority, the error
// WordPress's handler passed to wp_php_error_args, and reading error_get_last() before anything else.
$fatal_cases = array(
	946 => array( 'notice' => 'shutdown0', 'no_error_args' => true ),
	947 => array( 'notice' => 'fatal_handler' ),
	948 => array( 'notice' => 'order_read', 'no_error_args' => true ),
);
foreach ( $fatal_cases as $id => $cfg ) {
	$where = $cfg['notice'] . ( empty( $cfg['no_error_args'] ) ? '' : ', no wp_php_error_args' );
	lp_make_geidea_order( $id );
	list( $code, $body ) = request( 'POST', lp_geidea_callback_body( $id ), array_merge( array( 'sim' => 'fatal' ), $cfg ) );
	$n = hnotes( $id, 'Geidea callback:' );
	hcheck( "fatal error, then a silenced warning ($where): the note still names the fatal error", 500 === $code && 1 === count( $n ) && false !== strpos( $n[0], 'PHP fatal error: Error: Call to undefined method WC_Order::get_product_from_item_v352() (geidea-sim.php:' ), $n[0] ?? '' );
}

/* E. GET and junk. */
$notes_before = $GLOBALS['lp_test']['notes'];
$last_before  = $GLOBALS['lp_test']['options']['lp_shop_geidea_last_callback'];
list( $code, $body ) = request( 'GET', '' );
hcheck( 'GET ?wc-api=geidea: not checked by Ledger Plus (Geidea answers 400 Invalid request!)', 400 === $code && 'Invalid request!' === $body && $notes_before === $GLOBALS['lp_test']['notes'] && $last_before === $GLOBALS['lp_test']['options']['lp_shop_geidea_last_callback'] );
list( $code, $body ) = request( 'POST', 'not json at all' );
hcheck( 'POST without JSON: recorded as unverified (own option) and logged, no order note, the last good callback line kept', 400 === $code && $notes_before === $GLOBALS['lp_test']['notes'] && 'Unverified Geidea callback: unreadable. No JSON "order" in the body. Site reply: 400 Invalid request!' === $GLOBALS['lp_test']['options']['lp_shop_geidea_last_unverified']['result'] && $last_before === $GLOBALS['lp_test']['options']['lp_shop_geidea_last_callback'] );
list( $code, $body ) = request( 'POST', lp_geidea_callback_body( 99999 ) );
hcheck( 'unknown order number: no note, logged with the reference (Geidea\'s own handler then fails: 500)', $notes_before === $GLOBALS['lp_test']['notes'] && (bool) array_filter( $GLOBALS['lp_test']['glogs'], function ( $l ) { return false !== strpos( $l, 'Reference 99999: Geidea callback:' ); } ), "$code" );

/* F. Amount mismatch: Geidea's own handler would mark it paid (it does not check the amount), so it is stopped. */
lp_make_geidea_order( 943, 'pending', array( 'total' => '1999.00' ) );
$ran = $GLOBALS['lp_test']['geidea_handler_ran'] ?? 0;
list( $code, $body ) = request( 'POST', lp_geidea_callback_body( 943 ) );
$n = hnotes( 943, 'Geidea callback:' );
hcheck( 'amount mismatch: refused, Geidea\'s handler stopped (WooCommerce answers 400 "-1"), the order stays pending', 400 === $code && '-1' === $body && 'pending' === hrow( 943 )['status'] && $ran === ( $GLOBALS['lp_test']['geidea_handler_ran'] ?? 0 ) && ! isset( hrow( 943 )['meta']['Geidea Order Id'] ) && 1 === count( $n ) && 1 === preg_match( "/Ledger Plus refused it: its amount 1\\.00 is not the order total 1999\\.00\\. Geidea's handler was stopped, so the order was not marked paid; check this payment in the Geidea portal\\. Site reply: 400( -1)? \\(Order status now: Pending payment\\.\\)$/", $n[0] ), "$code $body " . ( $n[0] ?? '' ) );

/* F2. Re-split signatures (review finding 1): Geidea signs merchantReferenceId . timeStamp with no separator. */
function lp_resplit( $json, $move ) {
	$b = json_decode( $json, true );
	if ( $move > 0 ) {
		$b['order']['merchantReferenceId'] .= substr( $b['timeStamp'], 0, $move );
		$b['timeStamp']                     = substr( $b['timeStamp'], $move );
	} else {
		$b['timeStamp']                    = substr( $b['order']['merchantReferenceId'], $move ) . $b['timeStamp'];
		$b['order']['merchantReferenceId'] = substr( $b['order']['merchantReferenceId'], 0, $move );
	}
	unset( $b['order']['callbackUrl'], $b['order']['returnUrl'] ); // Unsigned, so an attacker can drop them.
	return json_encode( $b );
}
$f   = lp_resplit( lp_geidea_callback_body( 950 ), 1 ); // A genuine AED 1.00 callback for order 950, never processed.
$big = (int) json_decode( $f, true )['order']['merchantReferenceId'];
lp_make_geidea_order( $big, 'pending', array( 'total' => '1999.00' ) );
list( $code, $body ) = request( 'POST', $f );
hcheck( "genuine AED 1.00 callback re-split onto AED 1,999 order $big: 400, the order stays pending", 400 === $code && 'pending' === hrow( $big )['status'] && ! isset( hrow( $big )['meta']['Geidea Order Id'] ) && 1 === count( hnotes( $big, 'refused it: its amount 1.00 is not the order total 1999.00' ) ), "$code" );
lp_make_geidea_order( 94 );
list( $code, $body ) = request( 'POST', lp_resplit( lp_geidea_callback_body( 940 ), -1 ) ); // 940's processed callback, onto order 94.
hcheck( 'order 940\'s processed callback re-split onto order 94 (same amount): 400, its Geidea order is already on #940', 400 === $code && 'pending' === hrow( 94 )['status'] && 1 === count( hnotes( 94, 'refused it: its Geidea order ' . $gid . ' is already recorded on order #940' ) ) );
lp_make_geidea_order( 95 );
list( $code, $body ) = request( 'POST', lp_resplit( lp_geidea_callback_body( 951 ), -1 ) ); // 951's callback never processed, onto 95.
hcheck( 'residual (Geidea\'s scheme): an unprocessed callback re-split onto an order with the same amount, Geidea\'s API not reachable: Ledger Plus does not complete it and says so; Geidea\'s own handler still does', 'completed' === hrow( 95 )['status'] && ! isset( hrow( 95 )['meta']['_lp_geidea_paid'] ) && 1 === count( hnotes( 95, "Ledger Plus did not complete it: its time stamp (1" ) ) && 1 === count( hnotes( 95, "and Geidea's API could not confirm it" ) ) );

/* F3. Geidea's "Order status on success" not Processing/Completed (review finding 6). */
$cases = array(
	960 => array( 'wc-on-hold', 'on-hold', '(on-hold)' ),
	961 => array( 'wc-nonsense', 'pending', '(nonsense)' ),
	962 => array( null, 'pending', '(not set)' ),
);
foreach ( $cases as $id => $c ) {
	lp_make_geidea_order( $id );
	if ( null === $c[0] ) {
		unset( $GLOBALS['lp_test']['options']['woocommerce_geidea_settings']['order_status_success'] );
	} else {
		$GLOBALS['lp_test']['options']['woocommerce_geidea_settings']['order_status_success'] = $c[0];
	}
	$errors_before = count( $server_errors );
	list( $code, $body ) = request( 'POST', lp_geidea_callback_body( $id ) );
	if ( null === $c[0] ) {
		// Geidea's own code warns about the missing key (geidea-sim.php); any other warning still counts below.
		$new           = array_slice( $server_errors, $errors_before );
		$server_errors = array_merge( array_slice( $server_errors, 0, $errors_before ), array_values( array_filter( $new, function ( $e ) { return false === strpos( $e, 'geidea-sim.php' ); } ) ) );
		hcheck( '... (Geidea\'s own code warned about the missing setting)', (bool) array_filter( $new, function ( $e ) { return false !== strpos( $e, 'orderStatusSuccess' ); } ), implode( ' | ', $new ) );
	}
	$GLOBALS['lp_test']['options']['woocommerce_geidea_settings']['order_status_success'] = 'wc-completed';
	$coupons = array_filter( $GLOBALS['lp_test']['coupons'], function ( $cp ) use ( $id ) { return $id === ( $cp['meta']['_lp_referrer_order_id'] ?? 0 ); } );
	hcheck( 'success setting ' . ( $c[0] ?? '(missing)' ) . ': Ledger Plus leaves the status to Geidea\'s handler, which ends at ' . $c[1] . '; never paid then unpaid, no referral code', 200 === $code && $c[1] === hrow( $id )['status'] && null === hrow( $id )['date_paid'] && ! $coupons && ! isset( hrow( $id )['meta']['_lp_geidea_paid'] ) && 1 === count( hnotes( $id, "Ledger Plus did not complete it: Geidea's \"Order status on success\" " . $c[2] ) ), json_encode( array( hrow( $id )['status'], hrow( $id )['date_paid'], htransitions( $id ) ) ) );
}

/* F4. A paid order cannot be moved back by Geidea's handler: a signed failed callback for a Processing order. */
lp_make_geidea_order( 963, 'processing', array( 'date_paid' => time() - 60 ) );
$failed = lp_geidea_failed_order( 963 );
$ts     = lp_geidea_ts();
list( $code, $body ) = request( 'POST', json_encode( array( 'order' => $failed, 'signature' => lp_geidea_sign( $failed, $ts ), 'timeStamp' => $ts ) ) );
hcheck( 'signed "failed" callback for a Processing order: Geidea\'s handler asks for Failed, the order stays Processing, the note says so', 200 === $code && 'Payment failed!' === $body && 'processing' === hrow( 963 )['status'] && ! htransitions( 963 ) && 1 === count( hnotes( 963, "Geidea's handler asked for Failed; Ledger Plus kept the order Processing." ) ) );

/* G. Cancelled-then-paid. */
lp_make_geidea_order( 939, 'cancelled' );
list( $code, $body ) = request( 'POST', lp_geidea_callback_body( 939 ) );
hcheck( 'cancelled order paid: completed by Ledger Plus with the "after the order was cancelled" note', 200 === $code && 'completed' === hrow( 939 )['status'] && 1 === count( hnotes( 939, 'payment confirmed by Geidea after the order was cancelled (Geidea callback)' ) ) && array( array( 939, 'cancelled', 'completed' ) ) === htransitions( 939 ) );

/* H. Failed payment callback. */
lp_make_geidea_order( 944 );
$failed = lp_geidea_failed_order( 944 );
$body_f = json_encode( array( 'order' => $failed, 'signature' => lp_geidea_sign( $failed, 'ts-1' ), 'timeStamp' => 'ts-1' ) );
list( $code, $body ) = request( 'POST', $body_f );
$n = hnotes( 944, 'Geidea callback:' );
hcheck( 'failed payment: Geidea marks it failed, Ledger Plus records it', 200 === $code && 'Payment failed!' === $body && 'failed' === hrow( 944 )['status'] && 1 === count( $n ) && false !== strpos( $n[0], 'Geidea callback: Failed / OrderFailed, Geidea order 0b6c1e1a-5f7d-4a52-9c1e-000000000944, AED 1.00. Signature OK. Ledger Plus did not use it: it is not Success / Paid. Site reply: 200 Payment failed!' ), $n[0] ?? '' );

/* I. Safety net off: Geidea's handler alone, as in 1.1.0. */
lp_make_geidea_order( 945 );
list( $code, $body ) = request( 'POST', lp_geidea_callback_body( 945 ), array( 'settings' => array( 'geidea_safety_net' => false ) ) );
hcheck( 'safety net off: no Ledger Plus note, Geidea\'s handler completes the order itself', 200 === $code && 'completed' === hrow( 945 )['status'] && ! hnotes( 945, 'Ledger Plus: payment confirmed' ) && ! hnotes( 945, 'Geidea callback:' ) && ! isset( hrow( 945 )['meta']['_lp_geidea_paid'] ) && 1 === count( htransitions( 945 ) ) );

/* Secrets. */
$blob = array();
foreach ( $GLOBALS['lp_test']['notes'] as $list ) {
	$blob = array_merge( $blob, $list );
}
$blob = array_merge( $blob, $GLOBALS['lp_test']['glogs'] ?? array(), $GLOBALS['lp_test']['logs'] ?? array() );
foreach ( $GLOBALS['lp_test']['options'] as $k => $v ) {
	if ( 'woocommerce_geidea_settings' !== $k ) {
		$blob[] = json_encode( $v );
	}
}
foreach ( $GLOBALS['lp_test']['orders'] as $r ) {
	$blob[] = json_encode( $r['meta'] );
}
$blob = implode( "\n", $blob );
$sig  = json_decode( lp_geidea_callback_body( 940 ), true )['signature'];
hcheck( 'nothing sensitive in notes, logs, options or order meta (key, password, signature, token, card, email)', false === strpos( $blob, LP_FAKE_KEY ) && false === strpos( $blob, LP_FAKE_PASS ) && false === strpos( $blob, $sig ) && false === strpos( $blob, LP_FAKE_TOKEN ) && false === strpos( $blob, LP_FAKE_CARD ) && false === strpos( $blob, LP_FAKE_EMAIL ) );
hcheck( 'no PHP notices or warnings in the server requests', ! $server_errors, implode( ' | ', $server_errors ) );

proc_terminate( $proc );
proc_close( $proc );
foreach ( glob( $tmp . '/*' ) as $f ) {
	unlink( $f );
}
rmdir( $tmp );
echo "Result: $pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
