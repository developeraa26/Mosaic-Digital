<?php
/**
 * F12 Geidea payment safety net: in-process tests (stubs, mocked wp_remote_get, simulated WC_Order::payment_complete()).
 * The real request dispatch (php://input, output buffer, die(), shutdown order) is tested in run-geidea-http.php.
 * Usage: php run-geidea.php [path-to-plugin-file]
 */

require __DIR__ . '/stubs.php';
require __DIR__ . '/geidea-fixtures.php';
require __DIR__ . '/geidea-sim.php';

$plugin    = $argv[1] ?? dirname( __DIR__ ) . '/ledgerplus-shop/ledgerplus-shop.php';
$is_plugin = false !== strpos( (string) file_get_contents( $plugin ), 'Plugin Name:' );

$GLOBALS['lp_php_errors'] = array();
set_error_handler(
	function ( $no, $str, $file, $line ) {
		$GLOBALS['lp_php_errors'][] = "[$no] $str ($file:$line)";
		return true;
	}
);

require $plugin;
do_action( 'plugins_loaded' );
do_action( 'init' );
do_action( 'woocommerce_init' );

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
	echo "\n== F12: $t ==\n";
}
function notes( $id, $needle = '' ) {
	return array_values( array_filter( $GLOBALS['lp_test']['notes'][ $id ] ?? array(), function ( $n ) use ( $needle ) { return '' === $needle || false !== strpos( $n, $needle ); } ) );
}
function transitions( $id ) {
	return array_values( array_filter( $GLOBALS['lp_test']['transitions'] ?? array(), function ( $t ) use ( $id ) { return $id === $t[0]; } ) );
}
function row( $id ) {
	return $GLOBALS['lp_test']['orders'][ $id ];
}
function meta( $id, $key ) {
	return $GLOBALS['lp_test']['orders'][ $id ]['meta'][ $key ] ?? '';
}
function settings_filter( $changes ) {
	remove_all_filters( 'lp_shop_settings' );
	if ( $changes ) {
		add_filter( 'lp_shop_settings', function ( $s ) use ( $changes ) { return array_merge( $s, $changes ); } );
	}
	LP_Shop::reset_settings();
}
/** The callback guard as on a real request (without reading php://input), then the shutdown recorder with a buffered reply. */
function callback( $body, $reply = 'Order is completed!' ) {
	LP_Shop::reset_geidea_callback();
	$state = LP_Shop::handle_geidea_callback( $body );
	ob_start();
	echo $reply; // What Geidea's handler echoes into WooCommerce's buffer.
	do_action( 'shutdown' );
	ob_end_clean();
	return $state;
}
function coupons_for( $id ) {
	return count( array_filter( $GLOBALS['lp_test']['coupons'], function ( $c ) use ( $id ) { return $id === ( $c['meta']['_lp_referrer_order_id'] ?? 0 ) && 'publish' === $c['props']['status']; } ) );
}
function http_answer( $code, $data ) {
	$GLOBALS['lp_test']['http_handler'] = function () use ( $code, $data ) {
		return lp_test_http_json( $code, $data );
	};
}
function http_calls() {
	return count( $GLOBALS['lp_test']['http_log'] ?? array() );
}
function pending_checks( $id ) {
	return count( array_filter( $GLOBALS['lp_test']['as'] ?? array(), function ( $a ) use ( $id ) { return 'pending' === $a['status'] && $id === ( $a['args']['order_id'] ?? 0 ); } ) );
}
/** How often LP_Shop::$method is hooked on $tag (F14 adds its own callbacks to some of the same hooks). */
function hooked( $tag, $method ) {
	$n = 0;
	foreach ( $GLOBALS['lp_test']['filters'][ $tag ] ?? array() as $cbs ) {
		foreach ( $cbs as $entry ) {
			$n += ( array( 'LP_Shop', $method ) === $entry[0] ) ? 1 : 0;
		}
	}
	return $n;
}
function glog_lines( $needle ) {
	return array_values( array_filter( $GLOBALS['lp_test']['glogs'] ?? array(), function ( $l ) use ( $needle ) { return false !== strpos( $l, $needle ); } ) );
}
/** A signed body re-split at the merchantReferenceId|timeStamp boundary (same signature): $move > 0 moves that many
 * characters of the time stamp into the reference, $move < 0 moves digits of the reference into the time stamp. */
function resplit( $json, $move ) {
	$b   = json_decode( $json, true );
	$ref = $b['order']['merchantReferenceId'];
	$ts  = $b['timeStamp'];
	if ( $move > 0 ) {
		$ref .= substr( $ts, 0, $move );
		$ts   = substr( $ts, $move );
	} else {
		$ts  = substr( $ref, $move ) . $ts;
		$ref = substr( $ref, 0, $move );
	}
	$b['order']['merchantReferenceId'] = $ref;
	$b['timeStamp']                    = $ts;
	return $b;
}

$GLOBALS['lp_test']['options']['woocommerce_geidea_settings'] = lp_geidea_settings();
$GLOBALS['lp_test']['products'][890]                          = new LP_Test_Product( true );
WC()->cart                                                    = new WC_Cart();
$_SERVER['REQUEST_METHOD']                                    = 'POST';
$GLOBALS['lp_test']['caps']                                   = array( 'manage_woocommerce' );

/* ---------------------------------------------------------------- */
section( 'settings and hooks' );
$s = LP_Shop::settings();
check( 'defaults: safety net on, delays 2/10/30/120 min, timeout 10 s, 20 s and 3 an hour per order, 60 per hour, 7 days, API base from Geidea', true === $s['geidea_safety_net'] && array( 2, 10, 30, 120 ) === $s['geidea_check_delays'] && 10 === $s['geidea_lookup_timeout'] && 20 === $s['geidea_lookup_interval'] && 3 === $s['geidea_lookups_per_order_hour'] && 60 === $s['geidea_lookups_per_hour'] && 7 === $s['geidea_max_age_days'] && '' === $s['geidea_api_base'] );
settings_filter( array( 'geidea_check_delays' => array( '30', 2, 'x', 0, 2, 99999 ), 'geidea_lookup_timeout' => 100, 'geidea_lookups_per_hour' => -5, 'geidea_api_base' => 'http://evil.example' ) );
$s = LP_Shop::settings();
check( 'filter values are cleaned (delays sorted/unique/in range, timeout capped, http base refused)', array( 2, 30 ) === $s['geidea_check_delays'] && 30 === $s['geidea_lookup_timeout'] && 0 === $s['geidea_lookups_per_hour'] && '' === $s['geidea_api_base'] );
settings_filter( array( 'geidea_api_base' => 'https://api.geidea.ae/' ) );
check( 'https API base accepted without the trailing slash', 'https://api.geidea.ae' === LP_Shop::settings()['geidea_api_base'] );
settings_filter( array() );
check( 'callback guard on woocommerce_api_request at priority 1', isset( $GLOBALS['lp_test']['filters']['woocommerce_api_request'][1] ) );
check( 'thank-you check on template_redirect at priority 1 (before F6 at 5)', isset( $GLOBALS['lp_test']['filters']['template_redirect'][1] ) && isset( $GLOBALS['lp_test']['filters']['template_redirect'][5] ) );
check( 'scheduling on classic and Store API checkout and on woocommerce_payment_successful_result (not before_pay_action); Action Scheduler callback with 2 args', 1 === hooked( 'woocommerce_checkout_order_processed', 'on_checkout_order_processed' ) && 1 === hooked( 'woocommerce_store_api_checkout_order_processed', 'schedule_geidea_checks' ) && 0 === lp_test_hook_count( 'woocommerce_before_pay_action' ) && 2 === ( $GLOBALS['lp_test']['filters']['woocommerce_payment_successful_result'][20][0][1] ?? 0 ) && 2 === $GLOBALS['lp_test']['filters']['lp_shop_geidea_check'][10][0][1] );
if ( $is_plugin ) {
	check( 'plugin: deactivation hook registered for its own file', 1 === count( $GLOBALS['lp_test']['deactivation'] ?? array() ) && 'ledgerplus-shop.php' === $GLOBALS['lp_test']['deactivation'][0][0] );
} else {
	check( 'snippet: no deactivation hook (it needs a plugin file)', ! isset( $GLOBALS['lp_test']['deactivation'] ) );
}
check( 'order action filter and handler', 1 === hooked( 'woocommerce_order_actions', 'filter_order_actions' ) && 1 === lp_test_hook_count( 'woocommerce_order_action_lp_shop_geidea_check' ) );
$envs = array( 'UAE-PROD' => 'https://api.geidea.ae', 'KSA-PROD' => 'https://api.ksamerchant.geidea.net', 'EGY-PROD' => 'https://api.merchant.geidea.net', 'TEST' => '' );
$ok   = true;
foreach ( $envs as $env => $host ) {
	$GLOBALS['lp_test']['options']['woocommerce_geidea_settings']['geidea_environment'] = $env;
	$ok = $ok && $host === LP_Shop::geidea_api_base();
}
$GLOBALS['lp_test']['options']['woocommerce_geidea_settings']['geidea_environment'] = 'UAE-PROD';
check( 'API host from the Geidea environment (UAE/KSA/EGY as in Geidea config.php; unknown = none)', $ok );
$st = array( 'wc-completed' => 'completed', 'wc-processing' => 'processing', 'wc-on-hold' => '', 'wc-nonsense' => '', '' => '' );
$ok = true;
foreach ( $st as $setting => $want ) {
	$GLOBALS['lp_test']['options']['woocommerce_geidea_settings']['order_status_success'] = $setting;
	$ok = $ok && $want === LP_Shop::geidea_success_status();
}
$GLOBALS['lp_test']['options']['woocommerce_geidea_settings']['order_status_success'] = 'wc-completed';
check( 'Geidea success status: wc- removed; only a registered paid status counts', $ok );

/* ---------------------------------------------------------------- */
section( 'callback signature (same formula as Geidea ReturnHandler.php)' );
$vector = array(
	'order'     => array( 'amount' => 1, 'currency' => 'AED', 'orderId' => '11111111-2222-3333-4444-555555555555', 'status' => 'Success', 'merchantReferenceId' => '940' ),
	'timeStamp' => '11/27/2026 07:55:01',
	'signature' => 'Vv87ZDkqDdqe+Yc2gOugrHttMQMP0cNYEGPZmIlqTAU=',
);
check( 'published test vector (fake keys) -> OK', 'OK' === LP_Shop::verify_geidea_signature( $vector ) );
check( 'the fixture signer agrees with the vector', $vector['signature'] === lp_geidea_sign( $vector['order'], $vector['timeStamp'] ) );
$v2                    = $vector;
$v2['order']['amount'] = '1.00';
check( 'amount sent as the string "1.00" -> OK (2-decimal formatting)', 'OK' === LP_Shop::verify_geidea_signature( $v2 ) );
$v2['order']['amount'] = 1.01;
check( 'tampered amount -> INVALID', 'INVALID' === LP_Shop::verify_geidea_signature( $v2 ) );
$v3 = $vector;
unset( $v3['signature'] );
check( 'missing signature -> INVALID', 'INVALID' === LP_Shop::verify_geidea_signature( $v3 ) );
$v3                    = $vector;
$v3['order']['status'] = array( 'Success' );
check( 'non-scalar field -> INVALID', 'INVALID' === LP_Shop::verify_geidea_signature( $v3 ) );
$v3              = $vector;
$v3['timeStamp'] = '11/27/2026 07:55:02';
check( 'other timeStamp -> INVALID', 'INVALID' === LP_Shop::verify_geidea_signature( $v3 ) );
$GLOBALS['lp_test']['options']['woocommerce_geidea_settings']['merchant_password'] = '';
check( 'API password not set -> not checked', 0 === strpos( LP_Shop::verify_geidea_signature( $vector ), 'not checked' ) );
$GLOBALS['lp_test']['options']['woocommerce_geidea_settings']['merchant_password'] = LP_FAKE_PASS;

/* ---------------------------------------------------------------- */
section( 'callback guard: valid paid callback (order 940)' );
lp_make_geidea_order( 940 );
$gid   = lp_geidea_order( 940 )['orderId'];
$state = callback( lp_geidea_callback_body( 940 ) );
$o     = wc_get_order( 940 );
check( 'signature OK, Ledger Plus completed the order', 'OK' === $state['signature'] && 'Ledger Plus completed the order (now Completed)' === $state['action'], json_encode( $state ) );
check( 'order status = Geidea success status "completed" (WooCommerce alone would say processing)', 'completed' === $o->get_status() );
check( 'meta as Geidea writes it: Geidea Order Id, Merchant Reference Id, Order Success Status Setting', $gid === meta( 940, 'Geidea Order Id' ) && '940' === meta( 940, 'Merchant Reference Id' ) && 'wc-completed' === meta( 940, 'Order Success Status Setting' ) );
check( 'transaction id = Geidea order id; paid date set; own marker', $gid === $o->get_transaction_id() && null !== $o->get_date_paid() && $gid === meta( 940, '_lp_geidea_paid' ) && 'callback' === meta( 940, '_lp_geidea_paid_source' ) );
check( 'exactly one status transition pending -> completed', array( array( 940, 'pending', 'completed' ) ) === transitions( 940 ) );
check( 'WooCommerce note "Payment via Geidea Online Payments (<id>)." and the Ledger Plus note', 1 === count( notes( 940, 'Payment via Geidea Online Payments (' . $gid . ').' ) ) && 1 === count( notes( 940, 'Ledger Plus: payment confirmed by Geidea (Geidea callback). Geidea order ' . $gid . ', AED 1.00, Success / Paid. The order is now Completed.' ) ) );
check( 'F3 ran once: one referral code on the order, one coupon', LP_Shop::looks_like_code( meta( 940, '_lp_referral_code' ) ) && 1 === coupons_for( 940 ) );
$refund_ok = $o->get_status() === str_replace( 'wc-', '', lp_geidea_settings()['order_status_success'] ) && '' !== (string) $o->get_meta( 'Geidea Order Id' );
check( 'refundable through Geidea (ProcessRefund.php: status = success status, Geidea Order Id set)', $refund_ok );
check( 'the scoped status filter was removed again; only the keep-paid guard for this callback is left', ! isset( $GLOBALS['lp_test']['filters']['woocommerce_payment_complete_order_status'][999] ) && 1 === count( $GLOBALS['lp_test']['filters']['woocommerce_payment_complete_order_status'][ PHP_INT_MAX ] ?? array() ) );
check( 'lock released', ! array_filter( array_keys( $GLOBALS['lp_test']['options'] ), function ( $k ) { return 0 === strpos( $k, 'lp_shop_gdlock_' ) || 0 === strpos( $k, 'lp_shop_lock_' ); } ) );
$cb_note = notes( 940, 'Geidea callback:' );
check( 'shutdown recorder: one callback note with summary, signature, action and reply', 1 === count( $cb_note ) && 'Geidea callback: Success / Paid, Geidea order ' . $gid . ', AED 1.00. Signature OK. Ledger Plus completed the order (now Completed). Site reply: (status unknown) Order is completed! (Order status now: Completed.)' === $cb_note[0], $cb_note[0] ?? '' );
$last = get_option( 'lp_shop_geidea_last_callback' );
check( 'last-callback option: time and one line', is_array( $last ) && abs( $last['time'] - time() ) < 5 && 0 === strpos( $last['result'], 'Order #940: Geidea callback: Success / Paid' ) );
check( 'per-order meta: callback note count 1 and last callback line', 1 === meta( 940, '_lp_geidea_callback_notes' ) && false !== strpos( meta( 940, '_lp_geidea_last_callback' ), 'UTC: Geidea callback: Success / Paid' ) );
check( 'logged (source ledgerplus-geidea)', (bool) array_filter( $GLOBALS['lp_test']['glogs'], function ( $l ) { return 0 === strpos( $l, 'info: Order #940: Geidea callback:' ); } ) );
// Geidea's own handler runs next on the same request: its payment_complete() must change nothing.
$gw = new LP_Test_Geidea_Gateway();
$go = wc_get_order( 940 );
$go->update_meta_data( 'Geidea Order Id', $gid );
$gw->payment_complete( $go );
check( 'Geidea handler after ours: no second transition, still completed, one coupon', 1 === count( transitions( 940 ) ) && 'completed' === row( 940 )['status'] && 1 === coupons_for( 940 ) );

section( 'callback guard: duplicates and bad callbacks' );
$state = callback( lp_geidea_callback_body( 940 ) );
check( 'duplicate callback: nothing changed', 'Ledger Plus did not change the order: Ledger Plus already completed it once' === $state['action'] && 1 === count( transitions( 940 ) ) && 1 === coupons_for( 940 ) && 2 === count( notes( 940, 'Geidea callback:' ) ) );

lp_make_geidea_order( 941 );
$state = callback( lp_geidea_callback_body( 941, array(), false ), 'Invalid signature!' );
check( 'invalid signature: not used, order unchanged, kept apart as "unverified" with both possible causes', 'INVALID' === $state['signature'] && 'pending' === row( 941 )['status'] && '' === meta( 941, 'Geidea Order Id' ) && 1 === count( notes( 941, 'Unverified Geidea callback: Success / Paid, Geidea order ' . lp_geidea_order( 941 )['orderId'] . ', AED 1.00. Signature INVALID (the merchant key or API password in the Geidea settings does not match the Geidea account, or the request did not come from Geidea). Ledger Plus did not use it. Site reply: (status unknown) Invalid signature! (Order status now: Pending payment.)' ) ) && '' === meta( 941, '_lp_geidea_last_callback' ) && false !== strpos( meta( 941, '_lp_geidea_last_unverified' ), 'Unverified Geidea callback' ) && 1 === meta( 941, '_lp_geidea_unverified_notes' ) );

$leaky = 'Internal Server Error!Exception: key ' . LP_FAKE_KEY . ' password ' . LP_FAKE_PASS . ' for someone.else@example.org, ' . LP_FAKE_EMAIL . ' and ' . str_repeat( 'x', 40 ) . LP_FAKE_PASS;
lp_make_geidea_order( 9411 );
lp_make_geidea_order( 9412 );
lp_make_geidea_order( 9413 );
lp_make_geidea_order( 9414 );
callback( lp_geidea_callback_body( 9411, array(), false ), $leaky );
$leak_note = notes( 9411, 'Internal Server Error!' );
callback( lp_geidea_callback_body( 9412 ), 'Error for Mariam Testbuyer, phone 0500000001 / ' . LP_FAKE_PHONE . ', other +971 55 123 4567' );
$pii_note = notes( 9412, 'Error for' );
check( 'reply text: the order\'s billing names and phone, and other +phone numbers, hidden', 1 === count( $pii_note ) && false !== strpos( $pii_note[0], 'Error for [hidden] [hidden], phone 0500000001 / [hidden], other [phone]' ), $pii_note[0] ?? '' );
check( 'reply text: key, password and email hidden before it is shortened to 120 characters', 1 === count( $leak_note ) && false !== strpos( $leak_note[0], 'key [hidden] password [hidden] for [email], [hidden] and' ) && false === strpos( $leak_note[0], substr( LP_FAKE_PASS, 0, 8 ) ) && false === strpos( $leak_note[0], substr( LP_FAKE_KEY, 0, 8 ) ), $leak_note[0] ?? '' );

callback( lp_geidea_callback_body( 9413, array(), false ), "Internal Server Error!Exception: boom in /x/ReturnHandler.php:40\nStack trace:\n#0 /x/ReturnHandler.php(40): hash_hmac('sha256', 'test-public-key...', '" . substr( LP_FAKE_PASS, 0, 15 ) . "...', true)" );
$trace_note = notes( 9413, 'Exception: boom' );
check( 'reply text: stack trace cut off, no 15-character start of the password', 1 === count( $trace_note ) && false !== strpos( $trace_note[0], 'Exception: boom in /x/ReturnHandler.php:40 [stack trace removed]' ) && false === strpos( $trace_note[0], substr( LP_FAKE_PASS, 0, 12 ) ) && false === strpos( $trace_note[0], 'hash_hmac' ), $trace_note[0] ?? '' );
LP_Shop::reset_geidea_callback();
LP_Shop::handle_geidea_callback( lp_geidea_callback_body( 9414, array(), false ) );
ob_start();
echo 'Trace arg: ' . substr( LP_FAKE_KEY, 0, 15 ) . '... and ' . substr( LP_FAKE_PASS, 0, 15 ) . '...';
do_action( 'shutdown' );
ob_end_clean();
$prefix_note = notes( 9414, 'Trace arg:' );
check( 'reply text: a 15-character start of the key or password is hidden too', 1 === count( $prefix_note ) && false !== strpos( $prefix_note[0], 'Trace arg: [hidden]' ) && false === strpos( $prefix_note[0], substr( LP_FAKE_KEY, 0, 12 ) ) && false === strpos( $prefix_note[0], substr( LP_FAKE_PASS, 0, 12 ) ), $prefix_note[0] ?? '' );

lp_make_geidea_order( 942, 'pending', array( 'total' => '1999.00' ) );
$state = callback( lp_geidea_callback_body( 942 ) );
check( 'wrong amount: refused and Geidea\'s handler stopped (it would mark any amount paid); the note gives both amounts', 'pending' === row( 942 )['status'] && true === $state['blocked'] && 1 === count( notes( 942, "Signature OK. Ledger Plus refused it: its amount 1.00 is not the order total 1999.00. Geidea's handler was stopped, so the order was not marked paid; check this payment in the Geidea portal." ) ) );

lp_make_geidea_order( 943 );
$state = callback( lp_geidea_callback_body( 943, array( 'currency' => 'USD' ) ) );
check( 'wrong currency: refused, Geidea\'s handler stopped', 'pending' === row( 943 )['status'] && true === $state['blocked'] && false !== strpos( $state['action'], 'refused it: its currency USD is not the order currency AED' ) );
lp_make_geidea_order( 9431 );
$state = callback( lp_geidea_callback_body( 9431, array( 'callbackUrl' => 'https://staging.example.ae/?wc-api=geidea' ) ) );
check( 'signed paid callback made for another site (staging on the same merchant account): refused, Geidea\'s handler stopped', 'pending' === row( 9431 )['status'] && true === $state['blocked'] && false !== strpos( $state['action'], 'refused it: it was made for another site (staging.example.ae)' ) );
lp_make_geidea_order( 9432 );
$state = callback( lp_geidea_callback_body( 9432, array( 'transactions' => array( array( 'type' => 'Authentication', 'status' => 'Success' ) ) ) ) );
check( 'other mismatches (no successful Pay transaction): not used, Geidea\'s handler not stopped', 'pending' === row( 9432 )['status'] && false === $state['blocked'] );
$state = callback( lp_geidea_callback_body( 9432, array( 'status' => 'Failed', 'detailedStatus' => 'OrderFailed', 'amount' => 7 ) ) );
check( 'a failed result with another amount is not refused (only Success / Paid is)', false === $state['blocked'] && false !== strpos( $state['action'], 'it is not Success / Paid' ) );

$before = $GLOBALS['lp_test']['notes'];
$state  = callback( lp_geidea_callback_body( 99999 ) );
check( 'unknown order number: nothing touched, no note anywhere, logged with the reference', 0 === $state['order_id'] && $before === $GLOBALS['lp_test']['notes'] && (bool) array_filter( $GLOBALS['lp_test']['glogs'], function ( $l ) { return false !== strpos( $l, 'Reference 99999: Geidea callback' ) && false !== strpos( $l, 'No Geidea order with this number' ); } ) );
WC_Order::make( 944, 'x@y.ae', 'pending', array( 890 ) );
$GLOBALS['lp_test']['orders'][944]['payment_method'] = 'bacs';
$GLOBALS['lp_test']['orders'][944]['total']          = '1.00';
$state = callback( lp_geidea_callback_body( 944 ) );
check( 'order paid with another gateway: not touched, no note', 0 === $state['order_id'] && 'pending' === row( 944 )['status'] && ! notes( 944 ) );
lp_make_geidea_order( 945, 'pending', array( 'type' => 'shop_order_refund' ) );
$state = callback( lp_geidea_callback_body( 945 ) );
check( 'not a shop_order (refund id): not touched', 0 === $state['order_id'] && 'pending' === row( 945 )['status'] );
$state = callback( lp_geidea_callback_body( '94a' ) );
check( 'merchant reference with letters: refused', 0 === $state['order_id'] && false !== strpos( $state['action'], 'not a WooCommerce order number' ) );
$state = callback( '{not json', 'Invalid request!' );
check( 'invalid JSON: recorded as unverified, no order touched, the last good callback line kept', false !== strpos( $state['action'], 'No JSON "order"' ) && 'Unverified Geidea callback: unreadable. No JSON "order" in the body. Site reply: (status unknown) Invalid request!' === get_option( 'lp_shop_geidea_last_unverified' )['result'] && false !== strpos( get_option( 'lp_shop_geidea_last_callback' )['result'], 'Signature OK' ) );
$state = callback( str_repeat( ' ', 262145 ) );
check( 'body over 256 KB: not parsed', 'Body too large; not read' === $state['action'] );

lp_make_geidea_order( 946 );
$state = callback( lp_geidea_callback_body( 946, array( 'detailedStatus' => 'Authorized' ) ) );
check( 'Success / Authorized (funds held, not paid): not used', 'pending' === row( 946 )['status'] && false !== strpos( $state['action'], 'it is not Success / Paid' ) );
$state = callback( lp_geidea_callback_body( 946, array( 'status' => 'SUCCESS', 'detailedStatus' => 'paid' ) ) );
check( 'status words compared case-insensitively (as Geidea does): completed', 'completed' === row( 946 )['status'] );
lp_make_geidea_order( 947 );
$state = callback( lp_geidea_callback_body( 947, array( 'transactions' => array( array( 'type' => 'Authentication', 'status' => 'Success', 'codes' => array( 'responseCode' => '000' ) ) ) ) ) );
check( 'transactions listed but no successful Pay: not used', 'pending' === row( 947 )['status'] && false !== strpos( $state['action'], 'no successful Pay transaction' ) );
$state = callback( lp_geidea_callback_body( 947, array( 'transactions' => array( array( 'type' => 'Pay', 'status' => 'Success', 'codes' => array( 'responseCode' => '999' ) ) ) ) ) );
check( 'Pay transaction whose code is not 000: not used', 'pending' === row( 947 )['status'] );

section( 'callback guard: order states' );
lp_make_geidea_order( 939, 'cancelled' );
$state = callback( lp_geidea_callback_body( 939 ) );
check( 'cancelled order (like 939/940) paid: completed, note says it was cancelled and how to refund', 'completed' === row( 939 )['status'] && array( array( 939, 'cancelled', 'completed' ) ) === transitions( 939 ) && 1 === count( notes( 939, 'payment confirmed by Geidea after the order was cancelled (Geidea callback)' ) ) && 1 === count( notes( 939, 'If the sale should not go ahead, refund it from this order.' ) ) );
lp_make_geidea_order( 948, 'cancelled', array( 'created' => time() - 8 * 86400 ) );
$state = callback( lp_geidea_callback_body( 948 ) );
check( 'cancelled and older than 7 days: not completed by Ledger Plus', 'cancelled' === row( 948 )['status'] && false !== strpos( $state['action'], 'the order is cancelled and older than 7 days' ) );
lp_make_geidea_order( 949, 'cancelled', array( 'date_paid' => time() - 3600 ) );
$state = callback( lp_geidea_callback_body( 949 ) );
check( 'paid before and cancelled by hand: not completed again', 'cancelled' === row( 949 )['status'] && false !== strpos( $state['action'], 'was paid before and later set to Cancelled by hand' ) );
lp_make_geidea_order( 950, 'processing', array( 'date_paid' => time() - 60 ) );
$state = callback( lp_geidea_callback_body( 950 ) );
check( 'already processing: nothing changed', 'processing' === row( 950 )['status'] && false !== strpos( $state['action'], 'the order is already Processing' ) && ! transitions( 950 ) );
lp_make_geidea_order( 951, 'failed' );
lp_make_geidea_order( 952, 'on-hold' );
callback( lp_geidea_callback_body( 951 ) );
callback( lp_geidea_callback_body( 952 ) );
check( 'failed and on-hold orders paid: completed', 'completed' === row( 951 )['status'] && 'completed' === row( 952 )['status'] );
$GLOBALS['lp_test']['options']['woocommerce_geidea_settings']['order_status_success'] = 'wc-on-hold';
lp_make_geidea_order( 953 );
$state = callback( lp_geidea_callback_body( 953 ) );
check( 'Geidea success status not a paid status (on-hold): the callback guard leaves the order to Geidea\'s handler and says why', 'pending' === row( 953 )['status'] && '' === meta( 953, '_lp_geidea_paid' ) && false === $state['blocked'] && 'Ledger Plus did not complete it: Geidea\'s "Order status on success" (on-hold) is not Processing or Completed, so Geidea\'s handler sets the status' === $state['action'] );
unset( $GLOBALS['lp_test']['options']['woocommerce_geidea_settings']['order_status_success'] );
$state = callback( lp_geidea_callback_body( 953 ) );
check( '... the same when the setting is missing', false !== strpos( $state['action'], '"Order status on success" (not set)' ) && 'pending' === row( 953 )['status'] );
$GLOBALS['lp_test']['options']['woocommerce_geidea_settings']['order_status_success'] = 'wc-on-hold';
http_answer( 200, lp_geidea_lookup_body( array( lp_geidea_order( 953 ) ) ) );
do_action( 'woocommerce_order_action_lp_shop_geidea_check', wc_get_order( 953 ) );
check( '... a lookup (no Geidea handler after it) completes it with WooCommerce\'s default, Processing', 'processing' === row( 953 )['status'] && 'wc-on-hold' === meta( 953, 'Order Success Status Setting' ) );
lp_make_geidea_order( 9531, 'on-hold', array( 'meta' => array( 'Geidea Order Id' => lp_geidea_order( 9531 )['orderId'] ) ) );
check( '... an order Geidea\'s handler already put into its success status (on-hold, with Geidea Order Id) is not completed again', "Geidea's handler already set it to Geidea's success status (On hold)" === LP_Shop::geidea_completion_blocker( wc_get_order( 9531 ) ) );
$GLOBALS['lp_test']['options']['woocommerce_geidea_settings']['order_status_success'] = 'wc-completed';
check( '... with the setting back on Completed, an on-hold order is completable', '' === LP_Shop::geidea_completion_blocker( wc_get_order( 9531 ) ) );
lp_make_geidea_order( 954 );
add_option( 'lp_shop_gdlock_954', '1' );
update_option( 'lp_shop_gdlock_954_at', (string) time() );
$r = LP_Shop::complete_from_geidea( 954, lp_geidea_order( 954 ), 'manual', 0 );
check( 'payment lock held by another request: busy, nothing changed, the other lock kept', 'busy' === $r['result'] && 'pending' === row( 954 )['status'] && '1' === get_option( 'lp_shop_gdlock_954' ) );
delete_option( 'lp_shop_gdlock_954' );
delete_option( 'lp_shop_gdlock_954_at' );
$r = LP_Shop::complete_from_geidea( 954, lp_geidea_order( 954, array( 'merchantReferenceId' => '955' ) ), 'manual', 0 );
check( 'completion re-checks the match on the fresh order', 'skipped' === $r['result'] && 'pending' === row( 954 )['status'] );

section( 'callback guard: request filter, switch and note limit' );
LP_Shop::reset_geidea_callback();
LP_Shop::on_wc_api_request( 'geidea' );
check( 'POST to wc-api=geidea is checked (php://input is empty in the CLI)', is_array( LP_Shop::geidea_callback_state() ) );
LP_Shop::reset_geidea_callback();
$_SERVER['REQUEST_METHOD'] = 'GET';
LP_Shop::on_wc_api_request( 'geidea' );
check( 'GET: ignored', null === LP_Shop::geidea_callback_state() );
$_SERVER['REQUEST_METHOD'] = 'POST';
LP_Shop::on_wc_api_request( 'stripe' );
check( 'other wc-api endpoint: ignored', null === LP_Shop::geidea_callback_state() );
settings_filter( array( 'geidea_safety_net' => false ) );
LP_Shop::on_wc_api_request( 'geidea' );
check( 'safety net off: ignored', null === LP_Shop::geidea_callback_state() );
settings_filter( array() );
for ( $i = 0; $i < 6; $i++ ) {
	callback( lp_geidea_callback_body( 941, array(), false ), 'Invalid signature!' );
}
check( 'at most 3 unverified notes per order, the rest only in the log', 3 === count( notes( 941, 'Unverified Geidea callback:' ) ) && (bool) glog_lines( 'Order #941: note limit for unverified callbacks reached' ) );
for ( $i = 0; $i < 11; $i++ ) {
	callback( lp_geidea_callback_body( 941 ), 'Order is completed!' );
}
check( '... a signed callback is still noted after that (its own budget: 10 per order)', 10 === count( array_filter( notes( 941 ), function ( $n ) { return 0 === strpos( $n, 'Geidea callback: Success / Paid' ); } ) ) && 'completed' === row( 941 )['status'] && (bool) glog_lines( 'Order #941: note limit reached, the callback above is only in this log.' ) );
LP_Shop::reset_geidea_callback();

/* ---------------------------------------------------------------- */
section( 'callback guard: re-split signatures, time stamps, Geidea order ids already used (review finding 1)' );
// Geidea's signature joins merchantReferenceId and timeStamp with no separator, so digits can move between the two.
lp_make_geidea_order( 7300, 'pending', array( 'created' => time() ) );
$o7300  = wc_get_order( 7300 );
$now_ts = lp_geidea_ts();
$now_is = gmdate( 'Y-m-d\TH:i:s\Z', time() - 30 );
$ts_cases = array(
	'recent "n/d/Y H:i:s"'                                => array( $now_ts, true ),
	'recent with AM/PM'                                   => array( gmdate( 'n/d/Y g:i:s A', time() - 60 ), true ),
	'recent ISO with 7 fraction digits'                   => array( gmdate( 'Y-m-d\TH:i:s.1234567\Z', time() - 60 ), true ),
	'recent ISO with an offset'                           => array( gmdate( 'Y-m-d\TH:i:s', time() - 60 ) . '+00:00', true ),
	'a reference digit moved in front ("0" + stamp)'      => array( '0' . $now_ts, false ),
	'the first character moved into the reference'        => array( substr( $now_ts, 1 ), false ),
	'ISO: "0" + stamp'                                     => array( '0' . $now_is, false ),
	'ISO: the year\'s first digit moved away ("026-…")'   => array( substr( $now_is, 1 ), false ),
	'3 days old'                                          => array( gmdate( 'n/d/Y H:i:s', time() - 3 * 86400 ), false ),
	'2 days ahead'                                        => array( gmdate( 'n/d/Y H:i:s', time() + 2 * 86400 ), false ),
	'36 hours before the order was created'               => array( gmdate( 'n/d/Y H:i:s', time() - 36 * 3600 ), false ),
	'another format (d.m.Y)'                              => array( gmdate( 'd.m.Y H:i:s' ), false ),
	'not a string'                                        => array( 1795770000, false ),
);
foreach ( $ts_cases as $name => $c ) {
	check( "time stamp: $name -> " . ( $c[1] ? 'recent' : 'not recent' ), $c[1] === LP_Shop::geidea_timestamp_fresh( $c[0], $o7300 ), (string) $c[0] );
}
check( 'time stamp: 20 hours before the order was created is still accepted (time zones)', LP_Shop::geidea_timestamp_fresh( gmdate( 'n/d/Y H:i:s', time() - 20 * 3600 ), $o7300 ) );
$shapes = array(
	'1/05/2027 10:00:01'           => true,
	'11/27/2026 7:55:01 AM'        => true,
	'2026-11-27T07:55:01.1234567Z' => true,
	'2026-11-27 07:55:01'          => true,
	'01/05/2027 10:00:01'          => false, // "0" moved in front of a January stamp.
	'011/27/2026 07:55:01'         => false,
	'13/05/2027 10:00:01'          => false, // "1" moved in front of a March stamp.
	'/05/2027 10:00:01'            => false, // A one-digit month moved into the reference.
	'026-11-27T07:55:01Z'          => false,
	'02026-11-27T07:55:01Z'        => false,
	'27.11.2026 07:55:01'          => false,
);
$ok = true;
foreach ( $shapes as $stamp => $want ) {
	$ok = $ok && $want === LP_Shop::geidea_timestamp_format_ok( $stamp );
}
check( 'time stamp shapes, any month: no leading zero on the month, ISO with a 4-digit year', $ok );

lp_make_geidea_order( 7310 ); // Its genuine callback never reached the site (as for 939/940).
$legit = lp_geidea_callback_body( 7310 );
$f_out = resplit( $legit, -1 ); // Order 731, time stamp "0…".
$f_in  = resplit( $legit, 1 );  // Order 7310 + the stamp's first digit.
$in_id = (int) $f_in['order']['merchantReferenceId'];
lp_make_geidea_order( 731 );
lp_make_geidea_order( $in_id );
check( 're-split bodies keep a valid signature (Geidea\'s scheme), both directions', 'OK' === LP_Shop::verify_geidea_signature( $f_out ) && 'OK' === LP_Shop::verify_geidea_signature( $f_in ) && '731' === $f_out['order']['merchantReferenceId'] );
$GLOBALS['lp_test']['http_handler'] = function () {
	return new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
};
$calls = http_calls();
$s1    = callback( json_encode( $f_out ) );
$s2    = callback( json_encode( $f_in ) );
check( 're-split onto order 731 and onto order ' . $in_id . ' (same amount), Geidea\'s API not answering: Ledger Plus does not complete either', 'pending' === row( 731 )['status'] && 'pending' === row( $in_id )['status'] && '' === meta( 731, '_lp_geidea_paid' ) && '' === meta( $in_id, '_lp_geidea_paid' ) && http_calls() === $calls + 2 );
check( '... the notes say why (the time stamp, and that Geidea could not confirm it); Geidea\'s handler is not stopped when Geidea cannot be asked', false === $s1['blocked'] && false === $s2['blocked'] && 0 === strpos( $s1['action'], 'Ledger Plus did not complete it: its time stamp (0' ) && false !== strpos( $s2['action'], "is not a recent date, and Geidea's API could not confirm it (the request failed: cURL error 28: Operation timed out)" ) );
check( '... the callback asks Geidea with a 5 s time-out (Geidea is waiting for the reply)', 5 === end( $GLOBALS['lp_test']['http_log'] )['args']['timeout'] );
http_answer( 200, lp_geidea_lookup_body( array( lp_geidea_failed_order( 731 ) ) ) );
$s1 = callback( json_encode( $f_out ) );
check( '... Geidea\'s API lists no paid payment for 731: not completed, the note says to check the Geidea portal', 'pending' === row( 731 )['status'] && false !== strpos( $s1['action'], "and Geidea's API does not list it as paid for this order (Geidea has 1 payment attempt(s) for this order, none paid and matching: Failed / OrderFailed); check this payment in the Geidea portal" ) );

lp_make_geidea_order( 7320 );
$f_big = resplit( lp_geidea_callback_body( 7320 ), 1 );
$big   = (int) $f_big['order']['merchantReferenceId'];
lp_make_geidea_order( $big, 'pending', array( 'total' => '1999.00' ) );
$s = callback( json_encode( $f_big ) );
check( 'a genuine AED 1.00 callback re-split onto an AED 1,999 order: refused and Geidea\'s handler stopped', 'pending' === row( $big )['status'] && true === $s['blocked'] && false !== strpos( $s['action'], 'refused it: its amount 1.00 is not the order total 1999.00' ) );

lp_make_geidea_order( 7330 );
$legit = lp_geidea_callback_body( 7330 );
callback( $legit );
lp_make_geidea_order( 733 );
$s = callback( json_encode( resplit( $legit, -1 ) ) );
check( 'a processed callback re-split onto order 733 (same amount): refused, its Geidea order is already on #7330, Geidea\'s handler stopped', 'completed' === row( 7330 )['status'] && 'pending' === row( 733 )['status'] && true === $s['blocked'] && false !== strpos( $s['action'], 'refused it: its Geidea order ' . lp_geidea_order( 7330 )['orderId'] . ' is already recorded on order #7330' ) );
$s = callback( $legit );
check( '... the same callback again for its own order is a plain duplicate, not refused', false === $s['blocked'] && false !== strpos( $s['action'], 'Ledger Plus already completed it once' ) );

lp_make_geidea_order( 7340 );
http_answer( 200, lp_geidea_lookup_body( array( lp_geidea_failed_order( 7340 ), lp_geidea_order( 7340 ) ) ) );
$s = callback( lp_geidea_callback_body( 7340, array(), true, gmdate( 'd.m.Y H:i:s' ) ) );
check( 'a genuine callback whose time stamp format is unknown: Geidea\'s API lists the same payment, so it is completed', 'completed' === row( 7340 )['status'] && false === $s['blocked'] && 0 === strpos( $s['action'], 'Ledger Plus completed the order (now Completed); its time stamp (' ) && false !== strpos( $s['action'], "so Ledger Plus asked Geidea's API, which lists this payment as paid for this order" ) );
lp_make_geidea_order( 7341 );
$other = '0b6c1e1a-0000-4a52-9c1e-000000007341';
http_answer( 200, lp_geidea_lookup_body( array( lp_geidea_order( 7341, array( 'orderId' => $other ) ) ) ) );
$s = callback( lp_geidea_callback_body( 7341, array(), true, gmdate( 'd.m.Y H:i:s' ) ) );
check( '... Geidea\'s API lists another paid payment for the order: completed with that one, Geidea\'s handler stopped (it would save this callback\'s id)', 'completed' === row( 7341 )['status'] && $other === meta( 7341, 'Geidea Order Id' ) && true === $s['blocked'] );

lp_make_geidea_order( 7350 );
$iso = lp_geidea_callback_body( 7350, array(), true, $now_is );
$f   = resplit( $iso, 1 );
lp_make_geidea_order( (int) $f['order']['merchantReferenceId'] );
$GLOBALS['lp_test']['http_handler'] = function () {
	return new WP_Error( 'http_request_failed', 'cURL error 6: Could not resolve host' );
};
$s = callback( json_encode( $f ) );
check( 'ISO time stamp, the year\'s first digit moved into the reference (order 73502, "026-…"): not completed', '73502' === $f['order']['merchantReferenceId'] && 'pending' === row( 73502 )['status'] && false !== strpos( $s['action'], 'its time stamp (026-' ) );
$s = callback( $iso );
check( '... the genuine ISO callback completes its own order without asking Geidea', 'completed' === row( 7350 )['status'] && 'Ledger Plus completed the order (now Completed)' === $s['action'] );

/* ---------------------------------------------------------------- */
section( 'callback: Geidea\'s handler cannot move a paid order back (review finding 6)' );
lp_make_geidea_order( 7360, 'processing', array( 'date_paid' => time() - 60 ) );
$failed = lp_geidea_failed_order( 7360 );
$ts     = lp_geidea_ts();
LP_Shop::reset_geidea_callback();
LP_Shop::handle_geidea_callback( json_encode( array( 'order' => $failed, 'signature' => lp_geidea_sign( $failed, $ts ), 'timeStamp' => $ts ) ) );
check( 'during a callback for a paid order, Geidea asking for "failed" keeps it Processing', 'processing' === apply_filters( 'woocommerce_payment_complete_order_status', 'failed', 7360 ) );
check( '... an unregistered status (WooCommerce would make it Pending) is refused too, a paid status passes', 'processing' === apply_filters( 'woocommerce_payment_complete_order_status', 'nonsense', 7360 ) && 'completed' === apply_filters( 'woocommerce_payment_complete_order_status', 'completed', 7360 ) );
check( '... other orders are not touched', 'failed' === apply_filters( 'woocommerce_payment_complete_order_status', 'failed', 940 ) );
ob_start();
echo 'Payment failed!';
do_action( 'shutdown' );
ob_end_clean();
check( '... the note says what Geidea asked for and what was kept', 1 === count( notes( 7360, "Geidea's handler asked for Failed; Ledger Plus kept the order Processing." ) ) );
lp_make_geidea_order( 7361 );
$failed = lp_geidea_failed_order( 7361 );
LP_Shop::reset_geidea_callback();
LP_Shop::handle_geidea_callback( json_encode( array( 'order' => $failed, 'signature' => lp_geidea_sign( $failed, $ts ), 'timeStamp' => $ts ) ) );
check( 'an unpaid order may still be set to failed by Geidea', 'failed' === apply_filters( 'woocommerce_payment_complete_order_status', 'failed', 7361 ) );
LP_Shop::reset_geidea_callback();
check( 'outside a callback request the guard is gone', 'failed' === apply_filters( 'woocommerce_payment_complete_order_status', 'failed', 7360 ) );

/* ---------------------------------------------------------------- */
section( 'callback recorder: unverified POSTs kept apart from Geidea\'s callbacks (review findings 2 and 9)' );
lp_make_geidea_order( 7380 );
for ( $i = 0; $i < 5; $i++ ) {
	callback( json_encode( array( 'order' => array( 'merchantReferenceId' => '7380', 'status' => 'Refund to IBAN AE00', 'detailedStatus' => 'call +971 55 000 0000', 'amount' => 1, 'currency' => 'AED', 'orderId' => lp_geidea_order( 7380 )['orderId'] ), 'signature' => 'INVALID', 'timeStamp' => 'x' ) ), 'Invalid signature!' );
}
$unv = notes( 7380, 'Unverified Geidea callback:' );
check( 'five anonymous POSTs: 3 notes, no sentence from the request in them, a chosen "signature" hides no word', 3 === count( $unv ) && 0 === strpos( $unv[0], 'Unverified Geidea callback: ? / ?, Geidea order ' . lp_geidea_order( 7380 )['orderId'] . ', AED 1.00. Signature INVALID (the merchant key' ) && false === strpos( implode( ' ', $unv ), 'IBAN' ) && false === strpos( implode( ' ', $unv ), '[hidden]' ) );
lp_make_geidea_order( 7381 );
callback( json_encode( array( 'order' => array( 'merchantReferenceId' => '7381', 'status' => 'Success', 'detailedStatus' => 'Paid', 'amount' => 1, 'currency' => 'AED' ), 'signature' => 'signature', 'timeStamp' => 'x' ) ), 'Invalid signature!' );
check( '... a "signature" that is not one (not 44 base64 characters) hides nothing in Geidea\'s reply either', 1 === count( notes( 7381, 'Site reply: (status unknown) Invalid signature!' ) ) );
callback( lp_geidea_callback_body( 7380 ), 'Order is completed!' );
check( 'the real signed callback afterwards: completed and noted (its own budget)', 'completed' === row( 7380 )['status'] && 1 === count( array_filter( notes( 7380 ), function ( $n ) { return 0 === strpos( $n, 'Geidea callback: Success / Paid' ); } ) ) );
callback( '{"hello":"world"}', 'Invalid request!' );
callback( json_encode( array( 'order' => array( 'merchantReferenceId' => '7380', 'status' => 'Success', 'detailedStatus' => 'Paid', 'amount' => 1, 'currency' => 'AED' ), 'signature' => 'zzzz', 'timeStamp' => 'x' ) ), 'Invalid signature!' );
$rows = array_column( LP_Shop::geidea_admin_rows( wc_get_order( 7380 ) ), 1, 0 );
check( 'admin box: the last signed callback (order and site) is still the real one; unverified POSTs have their own lines', false !== strpos( $rows['Geidea callback for this order'] ?? '', 'Geidea callback: Success / Paid' ) && false !== strpos( $rows['Last Geidea callback on this site'] ?? '', 'Order #7380: Geidea callback: Success / Paid' ) && false !== strpos( $rows['Unverified callback for this order'] ?? '', 'Unverified Geidea callback' ) && false !== strpos( $rows['Last unverified callback on this site'] ?? '', 'Order #7380: Unverified Geidea callback' ) );
unset( $GLOBALS['lp_test']['transients'] );
$before = count( glog_lines( 'Unverified Geidea callback' ) );
for ( $i = 0; $i < 65; $i++ ) {
	callback( '{"x":1}', 'Invalid request!' );
}
check( 'at most 60 unverified POSTs logged an hour, then one warning', 60 === count( glog_lines( 'Unverified Geidea callback' ) ) - $before && 1 === count( glog_lines( 'More than 60 unverified POSTs' ) ) );
unset( $GLOBALS['lp_test']['transients'] );

/* ---------------------------------------------------------------- */
section( 'callback recorder: the fatal error WordPress\'s handler saw (review finding 8)' );
lp_make_geidea_order( 7390 );
LP_Shop::reset_geidea_callback();
LP_Shop::handle_geidea_callback( lp_geidea_callback_body( 7390 ) );
$fatal = array(
	'type'    => E_ERROR,
	'message' => "Uncaught Error: Call to undefined method WC_Order::get_product_from_item() in /srv/www/wp-content/plugins/geidea-online-payments/functions/PaymentComplete.php:18\nStack trace:\n#0 {main}",
	'file'    => '/srv/www/wp-content/plugins/geidea-online-payments/functions/PaymentComplete.php',
	'line'    => 18,
);
check( 'wp_php_error_args: the arguments come back unchanged', array( 'response' => 500 ) === apply_filters( 'wp_php_error_args', array( 'response' => 500 ), $fatal ) );
ob_start();
echo 'There has been a critical error on this website.';
do_action( 'shutdown' );
ob_end_clean();
$n = notes( 7390, 'Geidea callback:' );
check( '... the note names that error (plugin-relative file:line, no trace) even when error_get_last() no longer shows it', 1 === count( $n ) && false !== strpos( $n[0], 'PHP fatal error: Error: Call to undefined method WC_Order::get_product_from_item() (geidea-online-payments/functions/PaymentComplete.php:18)' ) && false === strpos( $n[0], 'Stack trace' ), $n[0] ?? '' );
$GLOBALS['lp_test']['glogs'] = array_values( array_filter( $GLOBALS['lp_test']['glogs'], function ( $l ) { return false === strpos( $l, 'error: Order #7390:' ); } ) ); // Expected error line.
LP_Shop::reset_geidea_callback();

/* ---------------------------------------------------------------- */
section( 'lookup: request and answers' );
lp_make_geidea_order( 960 );
$o960                           = wc_get_order( 960 );
$GLOBALS['lp_test']['http_log'] = array();
http_answer( 200, lp_geidea_lookup_body( array( lp_geidea_failed_order( 960 ), lp_geidea_order( 960 ) ) ) );
$res = LP_Shop::geidea_lookup( $o960 );
$req = $GLOBALS['lp_test']['http_log'][0];
check( 'GET https://api.geidea.ae/pgw/api/v1/direct/order?MerchantReferenceId=960', 'https://api.geidea.ae/pgw/api/v1/direct/order?MerchantReferenceId=960' === $req['url'] && 'GET' === $req['method'] );
check( 'Basic auth (public key : API password), Accept JSON, 10 s timeout, no redirects, response size cap', 'Basic ' . base64_encode( LP_FAKE_KEY . ':' . LP_FAKE_PASS ) === $req['args']['headers']['Authorization'] && 'application/json' === $req['args']['headers']['Accept'] && 10 === $req['args']['timeout'] && 0 === $req['args']['redirection'] && 1048576 === $req['args']['limit_response_size'] );
check( 'list with a failed and a paid attempt -> paid, the paid one is picked', 'paid' === $res['state'] && lp_geidea_order( 960 )['orderId'] === LP_Shop::geidea_order_id( $res['item'] ) && array( lp_geidea_order( 960 )['orderId'] ) === $res['paid_ids'] );
$cases = array(
	'single "order" object'                     => array( 200, array( 'order' => lp_geidea_order( 960 ), 'responseCode' => '000' ), 'paid' ),
	'bare list without envelope'                => array( 200, array( lp_geidea_order( 960 ) ), 'paid' ),
	'envelope without responseCode'             => array( 200, array( 'orders' => array( lp_geidea_order( 960 ) ) ), 'paid' ),
	'amount as the string "1.00"'               => array( 200, lp_geidea_lookup_body( array( lp_geidea_order( 960, array( 'amount' => '1.00' ) ) ) ), 'paid' ),
	'no transactions in the list item'          => array( 200, lp_geidea_lookup_body( array( array_diff_key( lp_geidea_order( 960 ), array( 'transactions' => 1 ) ) ) ), 'paid' ),
	'www. host in callbackUrl'                  => array( 200, lp_geidea_lookup_body( array( lp_geidea_order( 960, array( 'callbackUrl' => 'https://www.ledgerplus.ae/?wc-api=geidea' ) ) ) ), 'paid' ),
	'unparseable createdDate (ignored)'         => array( 200, lp_geidea_lookup_body( array( lp_geidea_order( 960, array( 'createdDate' => 'yesterday-ish?' ) ) ) ), 'paid' ),
	'empty orders list'                         => array( 200, lp_geidea_lookup_body( array() ), 'not_paid' ),
	'only a failed attempt'                     => array( 200, lp_geidea_lookup_body( array( lp_geidea_failed_order( 960 ) ) ), 'not_paid' ),
	'paid order of another reference only'      => array( 200, lp_geidea_lookup_body( array( lp_geidea_order( 961 ) ) ), 'not_paid' ),
	'paid, wrong amount'                        => array( 200, lp_geidea_lookup_body( array( lp_geidea_order( 960, array( 'amount' => 2 ) ) ) ), 'not_paid' ),
	'paid, wrong currency'                      => array( 200, lp_geidea_lookup_body( array( lp_geidea_order( 960, array( 'currency' => 'SAR' ) ) ) ), 'not_paid' ),
	'Success / InProgress'                      => array( 200, lp_geidea_lookup_body( array( lp_geidea_order( 960, array( 'detailedStatus' => 'InProgress' ) ) ) ), 'not_paid' ),
	'paid but refunded'                         => array( 200, lp_geidea_lookup_body( array( lp_geidea_order( 960, array( 'totalRefundedAmount' => 1 ) ) ) ), 'not_paid' ),
	'paid for another site (staging host)'      => array( 200, lp_geidea_lookup_body( array( lp_geidea_order( 960, array( 'callbackUrl' => 'https://staging.example.ae/?wc-api=geidea' ) ) ) ), 'not_paid' ),
	'Geidea order created 2 days before'        => array( 200, lp_geidea_lookup_body( array( lp_geidea_order( 960, array( 'createdDate' => gmdate( 'Y-m-d\TH:i:s\Z', time() - 2 * 86400 ) ) ) ) ), 'not_paid' ),
	'orderId not a GUID'                        => array( 200, lp_geidea_lookup_body( array( lp_geidea_order( 960, array( 'orderId' => 'abc' ) ) ) ), 'not_paid' ),
	'only an Authentication transaction'        => array( 200, lp_geidea_lookup_body( array( lp_geidea_order( 960, array( 'transactions' => array( array( 'type' => 'Authentication', 'status' => 'Success' ) ) ) ) ) ), 'not_paid' ),
	'responseCode 100 (call failed)'            => array( 200, lp_geidea_lookup_body( array( lp_geidea_order( 960 ) ), '100' ), 'unknown' ),
	'HTTP 401'                                  => array( 401, array( 'title' => 'Unauthorized' ), 'unknown' ),
	'HTTP 500'                                  => array( 500, 'oops', 'unknown' ),
	'not JSON'                                  => array( 200, '<html>maintenance</html>', 'unknown' ),
	'JSON without an order list'                => array( 200, array( 'responseCode' => '000' ), 'unknown' ),
	'JSON null'                                 => array( 200, 'null', 'unknown' ),
);
foreach ( $cases as $name => $c ) {
	http_answer( $c[0], $c[1] );
	$res = LP_Shop::geidea_lookup( $o960 );
	check( "answer: $name -> {$c[2]}", $c[2] === $res['state'], $res['state'] . ': ' . $res['message'] );
}
http_answer( 200, lp_geidea_lookup_body( array( lp_geidea_order( 960, array( 'amount' => 2 ) ) ) ) );
check( 'not paid: the reason is given (amount)', false !== strpos( LP_Shop::geidea_lookup( $o960 )['message'], 'Success / Paid (its amount 2.00 is not the order total 1.00)' ) );
http_answer( 200, lp_geidea_lookup_body( array( lp_geidea_failed_order( 960 ), lp_geidea_order( 960, array( 'detailedStatus' => 'InProgress' ) ) ) ) );
check( 'not paid: the attempts are listed', false !== strpos( LP_Shop::geidea_lookup( $o960 )['message'], 'Geidea has 2 payment attempt(s) for this order, none paid and matching: Failed / OrderFailed; Success / InProgress' ) );
$GLOBALS['lp_test']['http_handler'] = function () {
	return new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 10001 milliseconds with 0 bytes received' );
};
$res = LP_Shop::geidea_lookup( $o960 );
check( 'timeout -> unknown with the error text', 'unknown' === $res['state'] && false !== strpos( $res['message'], 'cURL error 28' ) );
http_answer( 200, lp_geidea_lookup_body( array( lp_geidea_order( 960 ), lp_geidea_order( 960, array( 'orderId' => '99999999-1c3e-4e8a-b0f2-000000000960' ) ) ) ) );
check( 'two paid entries -> paid, both ids returned (double charge)', 2 === count( LP_Shop::geidea_lookup( $o960 )['paid_ids'] ) );
$calls = http_calls();
$GLOBALS['lp_test']['options']['woocommerce_geidea_settings']['geidea_environment'] = 'MARS-PROD';
check( 'unknown Geidea environment -> unknown, no request', 'unknown' === LP_Shop::geidea_lookup( $o960 )['state'] && http_calls() === $calls );
$GLOBALS['lp_test']['options']['woocommerce_geidea_settings']['geidea_environment'] = 'UAE-PROD';
$GLOBALS['lp_test']['options']['woocommerce_geidea_settings']['merchant_gateway_key'] = '';
check( 'Geidea keys missing -> unknown, no request', 'unknown' === LP_Shop::geidea_lookup( $o960 )['state'] && http_calls() === $calls );
$GLOBALS['lp_test']['options']['woocommerce_geidea_settings']['merchant_gateway_key'] = LP_FAKE_KEY;
settings_filter( array( 'geidea_api_base' => 'https://api.example-geidea.test', 'geidea_lookup_timeout' => 5 ) );
http_answer( 200, lp_geidea_lookup_body( array() ) );
LP_Shop::geidea_lookup( $o960 );
$req = end( $GLOBALS['lp_test']['http_log'] );
check( 'geidea_api_base and geidea_lookup_timeout settings used', 0 === strpos( $req['url'], 'https://api.example-geidea.test/pgw/api/v1/direct/order?MerchantReferenceId=960' ) && 5 === $req['args']['timeout'] );
settings_filter( array() );

http_answer( 404, array( 'responseCode' => '023', 'responseMessage' => 'Order not found', 'detailedResponseCode' => '023.003', 'detailedResponseMessage' => 'No order found for this merchant reference' ) );
$r = LP_Shop::geidea_lookup( $o960 );
check( 'HTTP 404 with a Geidea JSON error: unknown, with its codes and message (review finding 7)', 'unknown' === $r['state'] && 'Geidea answered HTTP 404 (023 / 023.003 / No order found for this merchant reference)' === $r['message'], $r['message'] );
$GLOBALS['lp_test']['http_handler'] = function () {
	return lp_test_http_json( 404, '<html><head><title>404 Not Found</title></head><body><center><h1>404 Not Found</h1></center><hr><center>nginx</center></body></html>', array( 'Content-Type' => 'text/html; charset=utf-8' ) );
};
$r = LP_Shop::geidea_lookup( $o960 );
check( 'HTTP 404 web server page (wrong host or path): content type and the start of the page', 'Geidea answered HTTP 404 (text/html: 404 Not Found 404 Not Found nginx)' === $r['message'], $r['message'] );
$GLOBALS['lp_test']['http_handler'] = function () {
	return lp_test_http_json( 301, '', array( 'Location' => 'https://api.merchant.geidea.net/pgw/api/v1/direct/order?x=1' ) );
};
$r = LP_Shop::geidea_lookup( $o960 );
check( 'HTTP 301: the host it redirects to', 'Geidea answered HTTP 301 (redirect to api.merchant.geidea.net; set geidea_api_base if Geidea gives another host)' === $r['message'], $r['message'] );
$GLOBALS['lp_test']['http_handler'] = function () {
	return lp_test_http_json( 200, '<html>maintenance</html>', array( 'Content-Type' => 'text/html' ) );
};
$r = LP_Shop::geidea_lookup( $o960 );
check( 'HTTP 200 that is not JSON: content type and the start of the body', 'unknown' === $r['state'] && 'the answer is not JSON (text/html: maintenance)' === $r['message'], $r['message'] );
http_answer( 401, array( 'title' => 'Unauthorized', 'status' => 401 ) );
check( 'HTTP 401 problem JSON: status and title', 'Geidea answered HTTP 401 (401 / Unauthorized)' === LP_Shop::geidea_lookup( $o960 )['message'] );
http_answer( 500, 'Error for ' . LP_FAKE_EMAIL . ' key ' . LP_FAKE_KEY );
check( '... body text is scrubbed (email, merchant key)', 'Geidea answered HTTP 500 (Error for [email] key [hidden])' === LP_Shop::geidea_lookup( $o960 )['message'] );

/* ---------------------------------------------------------------- */
section( 'thank-you page check' );
function thankyou_visit( $id, $key = null ) {
	$GLOBALS['wp']                       = (object) array( 'query_vars' => array( 'order-received' => $id ) );
	$_GET                                = null === $key ? array() : array( 'key' => $key );
	$GLOBALS['lp_test']['order_received'] = true;
	do_action( 'template_redirect' );
	$GLOBALS['lp_test']['order_received'] = false;
	$_GET                                = array();
}
lp_make_geidea_order( 970 );
http_answer( 200, lp_geidea_lookup_body( array( lp_geidea_order( 970 ) ) ) );
$calls = http_calls();
thankyou_visit( 970, 'wrong-key' );
check( 'wrong order key: no lookup', http_calls() === $calls && 'pending' === row( 970 )['status'] );
thankyou_visit( 970 );
check( 'no key: no lookup', http_calls() === $calls );
$GLOBALS['wp'] = (object) array( 'query_vars' => array( 'order-received' => 970 ) );
$_GET          = array( 'key' => 'wc_order_key970' );
do_action( 'template_redirect' );
$_GET = array();
check( 'not the order-received page: no lookup', http_calls() === $calls );
thankyou_visit( 970, 'wc_order_key970' );
check( 'valid key, Geidea says paid: completed before the page is built (source thank-you page check)', http_calls() === $calls + 1 && 'completed' === row( 970 )['status'] && 1 === count( notes( 970, 'payment confirmed by Geidea (thank-you page check)' ) ) );
ob_start();
do_action( 'woocommerce_thankyou', 970 );
$page = ob_get_clean();
check( '... so the thank-you page shows the paid version with the referral code', false !== strpos( $page, 'lp-shop-thankyou--paid' ) && false !== strpos( $page, 'data-lp-code="' . meta( 970, '_lp_referral_code' ) . '"' ) && false === strpos( $page, 'once your payment is confirmed' ) );
$calls = http_calls();
thankyou_visit( 970, 'wc_order_key970' );
check( 'already paid: no lookup', http_calls() === $calls );
WC_Order::make( 971, 'x@y.ae', 'pending', array( 890 ) );
thankyou_visit( 971, 'wc_order_key971' );
check( 'not a Geidea order: no lookup', http_calls() === $calls );
lp_make_geidea_order( 972, 'pending', array( 'created' => time() - 8 * 86400 ) );
thankyou_visit( 972, 'wc_order_key972' );
check( 'older than 7 days: no lookup', http_calls() === $calls );
lp_make_geidea_order( 973 );
http_answer( 200, lp_geidea_lookup_body( array( lp_geidea_failed_order( 973 ) ) ) );
thankyou_visit( 973, 'wc_order_key973' );
thankyou_visit( 973, 'wc_order_key973' );
check( 'throttle: a second visit within 20 s makes no second lookup', http_calls() === $calls + 1 && 'pending' === row( 973 )['status'] && ! notes( 973 ) );
$GLOBALS['lp_test']['time_shift'] = 21;
thankyou_visit( 973, 'wc_order_key973' );
$GLOBALS['lp_test']['time_shift'] = 0;
check( '... after 20 s it asks again', http_calls() === $calls + 2 );
settings_filter( array( 'geidea_lookups_per_hour' => 3 ) );
unset( $GLOBALS['lp_test']['transients'] );
$calls = http_calls();
foreach ( array( 974, 975, 976, 977, 978 ) as $id ) {
	lp_make_geidea_order( $id );
	thankyou_visit( $id, 'wc_order_key' . $id );
}
check( 'site-wide cap: at most geidea_lookups_per_hour lookups in an hour', http_calls() === $calls + 3 );
settings_filter( array( 'geidea_safety_net' => false ) );
unset( $GLOBALS['lp_test']['transients'] );
thankyou_visit( 977, 'wc_order_key977' );
check( 'safety net off: no lookup', http_calls() === $calls + 3 );
settings_filter( array() );
unset( $GLOBALS['lp_test']['transients'] );

unset( $GLOBALS['lp_test']['transients'] );
lp_make_geidea_order( 9790 );
lp_make_geidea_order( 9791 );
$GLOBALS['lp_test']['http_handler'] = function ( $url ) {
	$ref = (int) substr( $url, strrpos( $url, '=' ) + 1 );
	return lp_test_http_json( 200, lp_geidea_lookup_body( 9791 === $ref ? array( lp_geidea_order( 9791 ) ) : array( lp_geidea_failed_order( $ref ) ) ) );
};
$calls = http_calls();
for ( $i = 0; $i < 60; $i++ ) {
	$GLOBALS['lp_test']['time_shift'] = 21 * $i;
	thankyou_visit( 9790, 'wc_order_key9790' );
}
$GLOBALS['lp_test']['time_shift'] = 0;
check( 'one order key reloaded every 21 s for 21 minutes: 3 lookups (geidea_lookups_per_order_hour; review finding 3)', http_calls() === $calls + 3 );
thankyou_visit( 9791, 'wc_order_key9791' );
check( '... another buyer\'s thank-you page still gets its lookup and the paid page', http_calls() === $calls + 4 && 'completed' === row( 9791 )['status'] );
$GLOBALS['lp_test']['time_shift'] = 3700;
thankyou_visit( 9790, 'wc_order_key9790' );
$GLOBALS['lp_test']['time_shift'] = 0;
check( '... an hour later the first order may ask again', http_calls() === $calls + 5 );
unset( $GLOBALS['lp_test']['transients'] );

/* ---------------------------------------------------------------- */
section( 'scheduled checks (Action Scheduler)' );
$GLOBALS['lp_test']['as'] = array();
lp_make_geidea_order( 980 );
$t0 = time();
do_action( 'woocommerce_store_api_checkout_order_processed', wc_get_order( 980 ) );
$as  = $GLOBALS['lp_test']['as'];
$ok  = 4 === count( $as );
$mins = array( 2, 10, 30, 120 );
foreach ( $as as $i => $a ) {
	$ok = $ok && 'lp_shop_geidea_check' === $a['hook'] && 'ledgerplus-shop' === $a['group'] && array( 'order_id' => 980, 'attempt' => $i + 1 ) === $a['args'] && abs( $a['time'] - ( $t0 + 60 * $mins[ $i ] ) ) <= 2;
}
check( 'block checkout: 4 single actions at +2, +10, +30, +120 min, args order_id + attempt, group ledgerplus-shop', $ok, json_encode( $as ) );
do_action( 'woocommerce_store_api_checkout_order_processed', wc_get_order( 980 ) );
check( 'placing the order again: no duplicate actions', 4 === count( $GLOBALS['lp_test']['as'] ) );
lp_make_geidea_order( 981 );
do_action( 'woocommerce_checkout_order_processed', 981, array(), wc_get_order( 981 ) );
lp_make_geidea_order( 982 );
$result = array( 'result' => 'success', 'redirect' => 'https://ledgerplus.ae/cart/?geidea-session=x' );
check( 'woocommerce_payment_successful_result: the gateway result comes back unchanged', $result === apply_filters( 'woocommerce_payment_successful_result', $result, 982 ) );
check( 'classic checkout and "Pay for order" schedule too', 12 === count( $GLOBALS['lp_test']['as'] ) );
WC_Order::make( 983, 'x@y.ae', 'pending', array( 890 ) );
do_action( 'woocommerce_store_api_checkout_order_processed', wc_get_order( 983 ) );
check( 'not a Geidea order: nothing scheduled', 12 === count( $GLOBALS['lp_test']['as'] ) );
// "Pay for order" (class-wc-form-handler.php pay_action()): before_pay_action (:573) sees the old method; the new one
// is saved at :606-607; then process_payment() and woocommerce_payment_successful_result (:619).
lp_make_geidea_order( 986, 'pending', array( 'payment_method' => '' ) );
do_action( 'woocommerce_before_pay_action', wc_get_order( 986 ) );
$GLOBALS['lp_test']['orders'][986]['payment_method'] = 'geidea';
apply_filters( 'woocommerce_payment_successful_result', $result, 986 );
check( '"Pay for order" switching an order with no method to Geidea: 4 checks', 4 === pending_checks( 986 ) && 16 === count( $GLOBALS['lp_test']['as'] ) );
lp_make_geidea_order( 987, 'pending', array( 'payment_method' => 'stripe' ) );
apply_filters( 'woocommerce_payment_successful_result', $result, 987 );
check( '"Pay for order" with another gateway: none', 0 === pending_checks( 987 ) );
$GLOBALS['lp_test']['as'] = array_slice( $GLOBALS['lp_test']['as'], 0, 12 );
settings_filter( array( 'geidea_check_delays' => array( 5, 60 ) ) );
lp_make_geidea_order( 984 );
do_action( 'woocommerce_store_api_checkout_order_processed', wc_get_order( 984 ) );
check( 'geidea_check_delays setting: 2 actions', 14 === count( $GLOBALS['lp_test']['as'] ) );
settings_filter( array( 'geidea_check_delays' => array() ) );
lp_make_geidea_order( 985 );
do_action( 'woocommerce_store_api_checkout_order_processed', wc_get_order( 985 ) );
check( 'empty geidea_check_delays: nothing scheduled', 14 === count( $GLOBALS['lp_test']['as'] ) );
settings_filter( array() );
$GLOBALS['lp_test']['as'] = array_slice( $GLOBALS['lp_test']['as'], 0, 8 ); // Orders 980 and 981.

// 980: Geidea never reports it paid. 981: paid at the second check.
$GLOBALS['lp_test']['http_handler'] = function ( $url ) {
	if ( false !== strpos( $url, '=981' ) && ( $GLOBALS['lp_test']['n981'] = ( $GLOBALS['lp_test']['n981'] ?? 0 ) + 1 ) >= 2 ) {
		return lp_test_http_json( 200, lp_geidea_lookup_body( array( lp_geidea_failed_order( 981 ), lp_geidea_order( 981 ) ) ) );
	}
	return lp_test_http_json( 200, lp_geidea_lookup_body( array( lp_geidea_failed_order( (int) substr( $url, -3 ) ) ) ) );
};
$GLOBALS['lp_test']['http_log'] = array();
lp_test_run_due_actions( $t0 + 2 * 60 + 5 );
check( 'first run (+2 min): asked Geidea for both orders, no note yet', 2 === http_calls() && ! notes( 980 ) && 'pending' === row( 981 )['status'] );
lp_test_run_due_actions( $t0 + 10 * 60 + 5 );
check( 'second run (+10 min): 981 completed (source scheduled check)', 4 === http_calls() && 'completed' === row( 981 )['status'] && 1 === count( notes( 981, 'payment confirmed by Geidea (scheduled check)' ) ) );
lp_test_run_due_actions( $t0 + 120 * 60 + 5 );
check( 'later runs: 981 is paid, so no more requests for it', 6 === http_calls() && 2 === count( array_filter( $GLOBALS['lp_test']['http_log'], function ( $r ) { return false !== strpos( $r['url'], '=981' ); } ) ) );
check( 'last run for 980 leaves one note, nothing changed', 'pending' === row( 980 )['status'] && 1 === count( notes( 980, 'Ledger Plus: last scheduled Geidea payment check (120 minutes after checkout): Geidea has 1 payment attempt(s) for this order, none paid and matching: Failed / OrderFailed. Nothing was changed.' ) ) && 1 === count( notes( 980 ) ) );

$GLOBALS['lp_test']['as'] = array();
lp_make_geidea_order( 7370 );
do_action( 'woocommerce_store_api_checkout_order_processed', wc_get_order( 7370 ) );
callback( lp_geidea_callback_body( 7370 ) );
check( 'paid through the callback: its 4 pending checks are cancelled (review finding 10)', 'completed' === row( 7370 )['status'] && 0 === pending_checks( 7370 ) && 4 === count( array_filter( $GLOBALS['lp_test']['as'], function ( $a ) { return 'canceled' === $a['status']; } ) ) );
lp_make_geidea_order( 7371 );
$t1 = time();
do_action( 'woocommerce_store_api_checkout_order_processed', wc_get_order( 7371 ) );
$GLOBALS['lp_test']['orders'][7371]['status']    = 'completed'; // Geidea's own handler completed it.
$GLOBALS['lp_test']['orders'][7371]['date_paid'] = time();
$calls = http_calls();
lp_test_run_due_actions( $t1 + 2 * 60 + 5 );
check( 'paid by Geidea\'s own handler: the next check stops, cancels the other 3 and asks nothing', 0 === pending_checks( 7371 ) && http_calls() === $calls && 1 === count( glog_lines( 'Order 7371: scheduled check 1 stopped: the order is already Completed; the remaining checks are cancelled.' ) ) );
lp_test_run_due_actions( $t1 + 200 * 60 );
check( '... the cancelled checks never run', 1 === count( glog_lines( 'Order 7371: scheduled check' ) ) );
if ( $is_plugin ) {
	lp_make_geidea_order( 7372 );
	do_action( 'woocommerce_store_api_checkout_order_processed', wc_get_order( 7372 ) );
	$pending_before = pending_checks( 7372 );
	call_user_func( $GLOBALS['lp_test']['deactivation'][0][1] );
	check( 'plugin deactivated: every pending lp_shop_geidea_check is cancelled', 4 === $pending_before && 0 === pending_checks( 7372 ) );
}

/* ---------------------------------------------------------------- */
section( 'admin order action "Check payment with Geidea"' );
lp_make_geidea_order( 990, 'cancelled', array( 'created' => time() - 86400 ) );
$actions = apply_filters( 'woocommerce_order_actions', array( 'send_order_details' => 'x' ), wc_get_order( 990 ) );
check( 'offered on a Geidea order', 'Check payment with Geidea' === ( $actions['lp_shop_geidea_check'] ?? '' ) && isset( $actions['send_order_details'] ) );
check( 'not offered on other orders, or without an order', ! isset( apply_filters( 'woocommerce_order_actions', array(), wc_get_order( 944 ) )['lp_shop_geidea_check'] ) && array() === apply_filters( 'woocommerce_order_actions', array(), null ) );
settings_filter( array( 'geidea_safety_net' => false ) );
check( 'not offered when the safety net is off', ! isset( apply_filters( 'woocommerce_order_actions', array(), wc_get_order( 990 ) )['lp_shop_geidea_check'] ) );
settings_filter( array() );
http_answer( 200, lp_geidea_lookup_body( array( lp_geidea_failed_order( 990 ), lp_geidea_order( 990 ) ) ) );
$GLOBALS['lp_test']['caps'] = array();
$calls                      = http_calls();
do_action( 'woocommerce_order_action_lp_shop_geidea_check', wc_get_order( 990 ) );
check( 'user without order rights: nothing', http_calls() === $calls && 'cancelled' === row( 990 )['status'] );
$GLOBALS['lp_test']['caps'] = array( 'edit_shop_orders' );
do_action( 'woocommerce_order_action_lp_shop_geidea_check', wc_get_order( 990 ) );
check( 'cancelled test order (like 939/940) paid at Geidea: completed, note names the manual check', 'completed' === row( 990 )['status'] && 1 === count( notes( 990, 'payment confirmed by Geidea after the order was cancelled (manual check)' ) ) && lp_geidea_order( 990 )['orderId'] === meta( 990, 'Geidea Order Id' ) );
lp_make_geidea_order( 991 );
http_answer( 200, lp_geidea_lookup_body( array( lp_geidea_failed_order( 991 ) ) ) );
do_action( 'woocommerce_order_action_lp_shop_geidea_check', wc_get_order( 991 ) );
check( 'not paid: note with the attempts, nothing changed', 'pending' === row( 991 )['status'] && 1 === count( notes( 991, 'Ledger Plus: Geidea payment check (manual): no paid payment found. Geidea has 1 payment attempt(s) for this order, none paid and matching: Failed / OrderFailed. Nothing was changed.' ) ) );
http_answer( 503, 'busy' );
do_action( 'woocommerce_order_action_lp_shop_geidea_check', wc_get_order( 991 ) );
check( 'no answer: note with the start of the reply, nothing changed', 'pending' === row( 991 )['status'] && 1 === count( notes( 991, 'no answer from Geidea: Geidea answered HTTP 503 (busy). Nothing was changed; try again later.' ) ) );
lp_make_geidea_order( 992, 'completed', array( 'date_paid' => time() - 3600 ) );
http_answer( 200, lp_geidea_lookup_body( array( lp_geidea_order( 992 ) ) ) );
do_action( 'woocommerce_order_action_lp_shop_geidea_check', wc_get_order( 992 ) );
check( 'paid order without Geidea Order Id (completed by hand): the id is saved, status unchanged', 'completed' === row( 992 )['status'] && lp_geidea_order( 992 )['orderId'] === meta( 992, 'Geidea Order Id' ) && lp_geidea_order( 992 )['orderId'] === row( 992 )['transaction_id'] && ! transitions( 992 ) && 1 === count( notes( 992, 'saved the Geidea order id so refunds through Geidea work' ) ) );
lp_make_geidea_order( 993, 'cancelled', array( 'created' => time() - 10 * 86400 ) );
http_answer( 200, lp_geidea_lookup_body( array( lp_geidea_order( 993 ) ) ) );
do_action( 'woocommerce_order_action_lp_shop_geidea_check', wc_get_order( 993 ) );
check( 'cancelled 10 days ago and paid: reported, not completed', 'cancelled' === row( 993 )['status'] && 1 === count( notes( 993, 'Geidea reports it paid (Success / Paid, Geidea order ' . lp_geidea_order( 993 )['orderId'] . ', AED 1.00), but Ledger Plus did not change the order: the order is cancelled and older than 7 days.' ) ) );
lp_make_geidea_order( 994 );
http_answer( 200, lp_geidea_lookup_body( array( lp_geidea_order( 994 ), lp_geidea_order( 994, array( 'orderId' => '99999999-1c3e-4e8a-b0f2-000000000994' ) ) ) ) );
do_action( 'woocommerce_order_action_lp_shop_geidea_check', wc_get_order( 994 ) );
check( 'two paid payments: completed and a double-charge note', 'completed' === row( 994 )['status'] && 1 === count( notes( 994, 'Geidea reports 2 paid payments for this order' ) ) );
lp_make_geidea_order( 996 );
$upper = '7D2F9B44-1C3E-4E8A-B0F2-000000000996';
http_answer( 200, lp_geidea_lookup_body( array( lp_geidea_order( 996, array( 'orderId' => $upper ) ), lp_geidea_order( 996, array( 'orderId' => strtolower( $upper ) ) ) ) ) );
do_action( 'woocommerce_order_action_lp_shop_geidea_check', wc_get_order( 996 ) );
check( 'Geidea order id saved exactly as Geidea sent it (upper case), also as the transaction id; one GUID in two letter cases is one payment (review finding 5)', 'completed' === row( 996 )['status'] && $upper === meta( 996, 'Geidea Order Id' ) && $upper === row( 996 )['transaction_id'] && $upper === meta( 996, '_lp_geidea_paid' ) && ! notes( 996, 'paid payments for this order' ) );
$GLOBALS['lp_test']['caps'] = array( 'manage_woocommerce' );

/* ---------------------------------------------------------------- */
section( 'admin order box' );
ob_start();
do_action( 'woocommerce_admin_order_data_after_billing_address', wc_get_order( 940 ) );
$box = ob_get_clean();
check( 'Geidea order: Ledger Plus checks plus Geidea order id, this order\'s last callback and the site\'s last callback', false !== strpos( $box, 'Trade licence:' ) && false !== strpos( $box, 'Geidea order id:</strong> ' . $gid ) && false !== strpos( $box, 'Geidea callback for this order:</strong> ' . gmdate( 'Y-m-d' ) ) && false !== strpos( $box, 'Last Geidea callback on this site:</strong> ' . gmdate( 'Y-m-d' ) ) );
ob_start();
do_action( 'woocommerce_admin_order_data_after_billing_address', wc_get_order( 944 ) );
$box = ob_get_clean();
check( 'other gateway: no Geidea lines', false !== strpos( $box, 'Trade licence:' ) && false === strpos( $box, 'Geidea' ) );
lp_make_geidea_order( 995 );
$GLOBALS['lp_test']['orders'][995]['product_ids'] = array( 891 );
ob_start();
do_action( 'woocommerce_admin_order_data_after_billing_address', wc_get_order( 995 ) );
$box = ob_get_clean();
check( 'Geidea order without product 890: only the Geidea lines', false === strpos( $box, 'Trade licence:' ) && false !== strpos( $box, 'Geidea callback for this order:</strong> none received' ) && false !== strpos( $box, 'not saved yet' ) );
// No callback recorded on the site yet. The site went from 1.1.0 straight to 1.1.2, so the line names no single version.
$lp_last_cb = $GLOBALS['lp_test']['options'][ LP_Shop::GEIDEA_LAST_OPTION ] ?? null;
unset( $GLOBALS['lp_test']['options'][ LP_Shop::GEIDEA_LAST_OPTION ] );
$rows = array_column( LP_Shop::geidea_admin_rows( wc_get_order( 995 ) ), 1, 0 );
check( 'no callback on this site yet: "none received since the Geidea safety net (Ledger Plus Shop 1.1.1 or later) was installed"', 'none received since the Geidea safety net (Ledger Plus Shop 1.1.1 or later) was installed' === ( $rows['Last Geidea callback on this site'] ?? '' ), (string) ( $rows['Last Geidea callback on this site'] ?? '' ) );
if ( null !== $lp_last_cb ) {
	$GLOBALS['lp_test']['options'][ LP_Shop::GEIDEA_LAST_OPTION ] = $lp_last_cb;
}

/* ---------------------------------------------------------------- */
section( 'nothing sensitive stored or logged' );
$stored = array();
foreach ( $GLOBALS['lp_test']['notes'] as $list ) {
	$stored = array_merge( $stored, $list );
}
$stored = array_merge( $stored, $GLOBALS['lp_test']['glogs'], $GLOBALS['lp_test']['logs'] );
foreach ( $GLOBALS['lp_test']['options'] as $k => $v ) {
	if ( 'woocommerce_geidea_settings' !== $k ) {
		$stored[] = $k . '=' . json_encode( $v );
	}
}
foreach ( $GLOBALS['lp_test']['orders'] as $r ) {
	$stored[] = json_encode( $r['meta'] ); // The order meta; the billing email field is the order's own data.
}
foreach ( $GLOBALS['lp_test']['transients'] ?? array() as $k => $v ) {
	$stored[] = $k . '=' . json_encode( $v );
}
$blob    = implode( "\n", $stored );
$secrets = array(
	'merchant public key'   => LP_FAKE_KEY,
	'API password'          => LP_FAKE_PASS,
	'Basic auth value'      => base64_encode( LP_FAKE_KEY . ':' . LP_FAKE_PASS ),
	'callback signature'    => json_decode( lp_geidea_callback_body( 940 ), true )['signature'],
	'bad signature value'   => 'AAAAbadsignature',
	'card token'            => LP_FAKE_TOKEN,
	'masked card number'    => LP_FAKE_CARD,
	'card last 4 as number' => '******1111',
	'customer email'        => LP_FAKE_EMAIL,
	'customer phone'        => LP_FAKE_PHONE,
	'customer last name'    => 'Testbuyer',
);
foreach ( $secrets as $what => $value ) {
	check( "not stored or logged: $what", false === strpos( $blob, $value ) );
}
check( 'the plugin wrote notes and logs at all (the scan saw them)', count( $stored ) > 100 && false !== strpos( $blob, 'Geidea callback:' ) );

section( 'PHP notices / deprecations, ledgerplus-shop warnings' );
check( 'no PHP notices, warnings or deprecations', array() === $GLOBALS['lp_php_errors'], implode( ' | ', $GLOBALS['lp_php_errors'] ) );
check( 'nothing logged to ledgerplus-shop (referral code work stayed quiet)', array() === $GLOBALS['lp_test']['logs'], implode( ' | ', $GLOBALS['lp_test']['logs'] ) );
check( 'no error lines in the ledgerplus-geidea log', ! array_filter( $GLOBALS['lp_test']['glogs'], function ( $l ) { return 0 === strpos( $l, 'error:' ); } ), implode( ' | ', array_filter( $GLOBALS['lp_test']['glogs'], function ( $l ) { return 0 === strpos( $l, 'error:' ); } ) ) );

section( 'emergency switch in wp-config.php' );
define( 'LP_SHOP_GEIDEA_SAFETY_NET', false );
settings_filter( array() );
check( 'define( \'LP_SHOP_GEIDEA_SAFETY_NET\', false ) turns F12 off', false === LP_Shop::geidea_enabled() );
LP_Shop::reset_geidea_callback();
LP_Shop::on_wc_api_request( 'geidea' );
check( '... callbacks are then left to Geidea alone, no order action', null === LP_Shop::geidea_callback_state() && ! isset( apply_filters( 'woocommerce_order_actions', array(), wc_get_order( 990 ) )['lp_shop_geidea_check'] ) );

echo "\nResult: {$GLOBALS['lp_results']['pass']} passed, {$GLOBALS['lp_results']['fail']} failed\n";
exit( $GLOBALS['lp_results']['fail'] > 0 ? 1 : 0 );
