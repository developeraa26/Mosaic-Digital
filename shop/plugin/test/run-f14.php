<?php
/**
 * F14 (1.2.0): Zoho CRM sync through the site's Zoho CRM add-on (stubbed in zoho-fixtures.php, with an in-memory
 * fake Zoho CRM v2 API behind it).
 * Usage: php run-f14.php [path-to-plugin-file] [mode]
 *   run-all.sh runs it on the plugin and on the WPCode snippet (with <?php prepended).
 *   Modes run in a second PHP process of this file (a class or a constant cannot be undefined again):
 *     constants  define( 'LP_SHOP_ZOHO_SYNC', false )
 *     no-addon   the add-on's Crm class is not loaded
 *     old-addon  the Crm class has no sendPutRequest()
 *     order-cache  WooCommerce's per-request HPOS order cache (order-cache-fixtures.php; wc_get_order() hands back the
 *                  copy read earlier in the request)
 */

$plugin = $argv[1] ?? dirname( __DIR__ ) . '/ledgerplus-shop/ledgerplus-shop.php';
$mode   = $argv[2] ?? '';
define( 'LP_TEST_ZOHO_ADDON', 'no-addon' === $mode ? 'none' : ( 'old-addon' === $mode ? 'old' : 'full' ) );

require __DIR__ . '/stubs.php';
require __DIR__ . '/geidea-fixtures.php';
require __DIR__ . '/zoho-fixtures.php';
if ( 'order-cache' === $mode ) {
	require __DIR__ . '/order-cache-fixtures.php';
}

$GLOBALS['lp_php_errors'] = array();
set_error_handler(
	function ( $no, $str, $file, $line ) {
		$GLOBALS['lp_php_errors'][] = "[$no] $str ($file:$line)";
		return true;
	}
);

if ( 'constants' === $mode ) {
	define( 'LP_SHOP_ZOHO_SYNC', false );
}

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
	echo "\n== F14: $t ==\n";
}
function settings_filter( $changes ) {
	remove_all_filters( 'lp_shop_settings' );
	if ( $changes ) {
		add_filter( 'lp_shop_settings', function ( $s ) use ( $changes ) { return array_merge( $s, $changes ); } );
	}
	LP_Shop::reset_settings();
}
function notes( $id, $needle = '' ) {
	return array_values( array_filter( $GLOBALS['lp_test']['notes'][ $id ] ?? array(), function ( $n ) use ( $needle ) { return '' === $needle || false !== strpos( $n, $needle ); } ) );
}
function zoho_notes_on_order( $id ) {
	return notes( $id, 'Zoho CRM' );
}
function meta( $id, $key ) {
	return $GLOBALS['lp_test']['orders'][ $id ]['meta'][ $key ] ?? '';
}
function row( $id ) {
	return $GLOBALS['lp_test']['orders'][ $id ];
}
/** Zoho requests the add-on was asked for (sent or not). */
function zcalls() {
	return $GLOBALS['lp_test']['zoho_calls'] ?? array();
}
function zcount() {
	return count( zcalls() );
}
function zcalls_since( $n ) {
	return array_slice( zcalls(), $n );
}
function zsig( $calls ) {
	return implode( ' | ', array_map( function ( $c ) { return $c['method'] . ' ' . $c['model']; }, $calls ) );
}
function zlog_lines( $needle ) {
	return array_values( array_filter( $GLOBALS['lp_test']['zlogs'] ?? array(), function ( $l ) use ( $needle ) { return false !== strpos( $l, $needle ); } ) );
}
function zrecords() {
	return $GLOBALS['lp_zoho']['records'];
}
function zrecord_of( $order_id ) {
	return $GLOBALS['lp_zoho']['records'][ (string) meta( $order_id, '_lp_zoho_record_id' ) ] ?? null;
}
/** Pending lp_shop_zoho_sync jobs of an order (optionally with one reason). */
function zjobs( $id, $reason = null ) {
	return array_values( array_filter( $GLOBALS['lp_test']['as'] ?? array(), function ( $a ) use ( $id, $reason ) { return 'pending' === $a['status'] && 'lp_shop_zoho_sync' === $a['hook'] && $id === ( $a['args']['order_id'] ?? 0 ) && ( null === $reason || $reason === ( $a['args']['reason'] ?? '' ) ); } ) );
}
/** Runs the due lp_shop_zoho_sync jobs (only those; F12's checks are left alone), like Action Scheduler. */
function run_zoho_jobs( $now = null, $only_order = null ) {
	$now = null === $now ? time() : $now;
	foreach ( array_keys( $GLOBALS['lp_test']['as'] ?? array() ) as $i ) {
		$a = $GLOBALS['lp_test']['as'][ $i ];
		if ( 'pending' === $a['status'] && 'lp_shop_zoho_sync' === $a['hook'] && $a['time'] <= $now && ( null === $only_order || $only_order === $a['args']['order_id'] ) ) {
			$GLOBALS['lp_test']['as'][ $i ]['status'] = 'complete';
			do_action( $a['hook'], ...array_values( $a['args'] ) );
		}
	}
}
function thankyou_visit( $id, $key = null, $render = false ) {
	$GLOBALS['wp']                        = (object) array( 'query_vars' => array( 'order-received' => $id ) );
	$_GET                                 = null === $key ? array() : array( 'key' => $key );
	$GLOBALS['lp_test']['order_received'] = true;
	ob_start();
	do_action( 'template_redirect' );
	if ( $render ) {
		do_action( 'woocommerce_thankyou', $id );
	}
	$page                                 = ob_get_clean();
	$GLOBALS['lp_test']['order_received'] = false;
	$_GET                                 = array();
	return $page;
}
function hooked_at( $tag, $priority, $method ) {
	foreach ( $GLOBALS['lp_test']['filters'][ $tag ][ $priority ] ?? array() as $entry ) {
		if ( array( 'LP_Shop', $method ) === $entry[0] ) {
			return $entry[1];
		}
	}
	return 0;
}
function hooked_total( $tag, $method ) {
	$n = 0;
	foreach ( $GLOBALS['lp_test']['filters'][ $tag ] ?? array() as $cbs ) {
		foreach ( $cbs as $entry ) {
			$n += ( array( 'LP_Shop', $method ) === $entry[0] ) ? 1 : 0;
		}
	}
	return $n;
}
function admin_rows( $id ) {
	return array_column( LP_Shop::zoho_admin_rows( wc_get_order( $id ) ), 1, 0 );
}
function inject( ...$answers ) {
	$GLOBALS['lp_zoho']['inject'] = $answers;
}
/** Forgets the site-wide hourly count of unpaid orders sent (zoho_open_per_hour), like the next hour. */
function zoho_open_reset() {
	foreach ( array_keys( $GLOBALS['lp_test']['transients'] ?? array() ) as $k ) {
		if ( 0 === strpos( $k, 'lp_shop_zoho_open_' ) ) {
			unset( $GLOBALS['lp_test']['transients'][ $k ] );
		}
	}
}
function zlock( $id, $on ) {
	if ( $on ) {
		$GLOBALS['lp_test']['options'][ 'lp_shop_zlock_' . $id ]    = '1';
		$GLOBALS['lp_test']['options'][ 'lp_shop_zlock_' . $id . '_at' ] = (string) time();
	} else {
		unset( $GLOBALS['lp_test']['options'][ 'lp_shop_zlock_' . $id ], $GLOBALS['lp_test']['options'][ 'lp_shop_zlock_' . $id . '_at' ] );
	}
}
function finish() {
	section( 'PHP notices' );
	check( 'no PHP notices, warnings or deprecations', array() === $GLOBALS['lp_php_errors'], implode( ' | ', $GLOBALS['lp_php_errors'] ) );
	check( 'nothing logged to ledgerplus-shop (the Zoho sync logs to ledgerplus-zoho)', array() === $GLOBALS['lp_test']['logs'], implode( ' | ', $GLOBALS['lp_test']['logs'] ) );
	echo "\nResult: {$GLOBALS['lp_results']['pass']} passed, {$GLOBALS['lp_results']['fail']} failed\n";
	exit( $GLOBALS['lp_results']['fail'] > 0 ? 1 : 0 );
}

/** Every buyer of these tests, for the privacy scan at the end. */
$GLOBALS['zb_people'] = array();
/** A shop order like the live ones: product 890 at AED 1,999 incl. 5% VAT, billing data, trade licence and TRN. */
function zorder( $id, $status = 'pending', $extra = array() ) {
	$email = "zb{$id}.buyer@example.com";
	WC_Order::make( $id, $email, $status, array( 890 ), array(), array( '_wc_other/ledgerplus/trade-licence' => 'CN-' . $id . '567', '_wc_other/ledgerplus/trn' => '100123456789003' ), 0 );
	$GLOBALS['lp_test']['orders'][ $id ] = array_merge(
		$GLOBALS['lp_test']['orders'][ $id ],
		array(
			'payment_method' => 'bacs',
			'total'          => '1999.00',
			'total_tax'      => '95.19',
			'currency'       => 'AED',
			'order_key'      => 'wc_order_key' . $id,
			'created'        => time() - 600,
			'phone'          => '+971 50 000 ' . $id,
			'first_name'     => 'Aisha',
			'last_name'      => 'Zohobuyer' . $id,
			'company'        => 'Zoho Test Trading ' . $id . ' LLC',
			'customer_note'  => "Please call after 4pm.\nOr email " . $email,
			'lines'          => array( array( 890, 'E-Invoicing Starter Package', 1, '1903.81', '95.19' ) ),
		),
		$extra
	);
	$row                    = $GLOBALS['lp_test']['orders'][ $id ];
	$GLOBALS['zb_people'][] = array( $row['email'], $row['phone'], $row['last_name'], $row['company'] );
	return wc_get_order( $id );
}

$GLOBALS['lp_test']['products'][890]                          = new LP_Test_Product( true );
$GLOBALS['lp_test']['permalinks'][887]                        = 'https://ledgerplus.ae/checkout/';
$GLOBALS['lp_test']['options']['woocommerce_geidea_settings'] = lp_geidea_settings();
$GLOBALS['lp_test']['caps']                                   = array( 'manage_woocommerce' );
$_SERVER['REQUEST_METHOD']                                    = 'GET';
WC()->cart                                                    = new WC_Cart();
lp_zoho_reset();

/* ======================================================================
 * Second-process modes.
 * ====================================================================== */
if ( 'constants' === $mode ) {
	section( 'wp-config constant LP_SHOP_ZOHO_SYNC' );
	check( "define( 'LP_SHOP_ZOHO_SYNC', false ) turns F14 off", false === LP_Shop::settings()['zoho_sync'] && false === LP_Shop::zoho_enabled() );
	zorder( 1900 );
	do_action( 'woocommerce_store_api_checkout_order_processed', wc_get_order( 1900 ) );
	wc_get_order( 1900 )->payment_complete( 'txn' );
	check( '... no job at checkout or payment, no Zoho request', ! zjobs( 1900 ) && 0 === zcount() );
	thankyou_visit( 1900, 'wc_order_key1900' );
	check( '... no thank-you page sync', 0 === zcount() );
	check( '... no order action, no Zoho rows in the order box', ! isset( apply_filters( 'woocommerce_order_actions', array(), wc_get_order( 1900 ) )['lp_shop_zoho_sync'] ) && array() === LP_Shop::zoho_admin_rows( wc_get_order( 1900 ) ) );
	settings_filter( array( 'zoho_sync' => true ) );
	check( 'the lp_shop_settings filter can switch it on again', true === LP_Shop::zoho_enabled() );
	finish();
}

if ( 'no-addon' === $mode || 'old-addon' === $mode ) {
	$what = 'no-addon' === $mode ? 'the add-on\'s Crm class is missing' : 'the Crm class has no sendPutRequest()';
	section( "Zoho CRM add-on missing ($what)" );
	check( 'zoho_addon_ready() is false', false === LP_Shop::zoho_addon_ready() );
	zorder( 1910 );
	do_action( 'woocommerce_store_api_checkout_order_processed', wc_get_order( 1910 ) );
	check( 'checkout still queues the job (cheap; the job notes the missing add-on)', 1 === count( zjobs( 1910, 'checkout' ) ) );
	run_zoho_jobs();
	$missing = 'Ledger Plus: not sent to Zoho CRM: the "Elementor Pro Form Widget - Zoho CRM - Integration" add-on is not active on this site (its Crm class is missing). Activate it, then use the order action "Send to Zoho CRM again".';
	check( 'one order note naming the add-on and the order action', array( $missing ) === zoho_notes_on_order( 1910 ), implode( ' | ', notes( 1910 ) ) );
	check( '... not retried: no job pending, no attempt counted, no request to the add-on', ! zjobs( 1910 ) && '' === meta( 1910, '_lp_zoho_attempts' ) && 0 === zcount() );
	check( '... meta: add-on missing, last error', 'yes' === meta( 1910, '_lp_zoho_addon_missing' ) && 'the Zoho CRM add-on is not active' === meta( 1910, '_lp_zoho_last_error' ) );
	wc_get_order( 1910 )->payment_complete( 'txn-1910' );
	run_zoho_jobs();
	check( 'the paid job notes nothing again (noted once per order)', 1 === count( zoho_notes_on_order( 1910 ) ) && ! zjobs( 1910 ) );
	$page = thankyou_visit( 1910, 'wc_order_key1910', true );
	check( 'thank-you page: no sync, no note, the paid page as usual', 1 === count( zoho_notes_on_order( 1910 ) ) && false !== strpos( $page, 'lp-shop-thankyou--paid' ) );
	check( 'order box: "not sent: the Zoho CRM add-on is not active"', 'not sent: the Zoho CRM add-on is not active' === ( admin_rows( 1910 )['Zoho CRM record'] ?? '' ) && 'none yet' === ( admin_rows( 1910 )['Zoho CRM Lead Status sent'] ?? '' ) );
	do_action( 'woocommerce_order_action_lp_shop_zoho_sync', wc_get_order( 1910 ) );
	check( 'order action: noted again (someone asked), still no request', 2 === count( notes( 1910, 'add-on is not active on this site' ) ) && 0 === zcount() );
	check( 'log: one warning per note, no error lines', 2 === count( zlog_lines( 'warning: Order 1910: not sent to Zoho CRM, the Zoho CRM add-on is not active' ) ) && ! zlog_lines( 'error:' ) );
	finish();
}

if ( 'order-cache' === $mode ) {
	section( 'with HPOS: the thank-you sync reads the order again (WooCommerce\'s per-request order cache)' );
	$GLOBALS['lp_test']['order_cache'] = true;
	$GLOBALS['lp_order_cache']         = array();
	zorder( 1920 ); // zorder() reads the order: this request now has its copy (Pending payment) in the cache.
	$GLOBALS['lp_test']['orders'][1920]['status']    = 'processing'; // Paid by Geidea's callback in another request.
	$GLOBALS['lp_test']['orders'][1920]['date_paid'] = time();
	check( 'the cache model works: wc_get_order() still hands back the copy read before (Pending payment)', 'pending' === wc_get_order( 1920 )->get_status() );
	$GLOBALS['zb_seen_1920'] = null;
	add_action( 'woocommerce_thankyou', function () { $GLOBALS['zb_seen_1920'] = zrecord_of( 1920 )['Lead_Status'] ?? null; }, 1 );
	$page = thankyou_visit( 1920, 'wc_order_key1920', true );
	check( 'thank-you page: the order is read again, so it is synced as paid before the page: Closed Opportunity, both notes', 'Closed Opportunity' === $GLOBALS['zb_seen_1920'] && 'GET Ledgers_Plus/search | POST Ledgers_Plus | POST Notes | POST Notes' === zsig( zcalls() ), zsig( zcalls() ) );
	check( '... and the page (which reads the order from the cache) shows the paid version', false !== strpos( $page, 'lp-shop-thankyou--paid' ) );

	lp_zoho_reset();
	$GLOBALS['lp_order_cache'] = array();
	zorder( 1921, 'pending', array( 'payment_method' => 'geidea' ) );
	$GLOBALS['lp_order_cache']          = array(); // The thank-you page is a new request.
	$GLOBALS['lp_test']['http_handler'] = function ( $url, $args ) {
		// While the F12 check (priority 1, which read the order into the cache) waits for Geidea, Geidea's callback
		// completes the order in another request; the lookup then times out.
		$GLOBALS['lp_test']['orders'][1921]['status']    = 'processing';
		$GLOBALS['lp_test']['orders'][1921]['date_paid'] = time();
		return new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 10001 milliseconds' );
	};
	thankyou_visit( 1921, 'wc_order_key1921' );
	unset( $GLOBALS['lp_test']['http_handler'] );
	check( 'with F12: its lookup times out while the callback pays the order; F14 still sees the paid order and sends it before the page', 'Closed Opportunity' === ( zrecord_of( 1921 )['Lead_Status'] ?? '' ) && 4 === zcount() && 1 === count( array_filter( $GLOBALS['lp_test']['glogs'] ?? array(), function ( $l ) { return false !== strpos( $l, 'Order 1921: lookup (thank-you page check): unknown' ); } ) ) );
	finish();
}

/* ======================================================================
 * Settings and hooks
 * ====================================================================== */
section( 'settings and hooks' );
$s = LP_Shop::settings();
check( 'defaults: on, Ledgers_Plus, owner 794156085, Prospecting / Closed Opportunity, Website, "Ledger Plus Shop", workflow trigger, 8 s thank-you budget, 4 s per request, retries after 5/30/120 min, 60 unpaid orders per hour', true === $s['zoho_sync'] && 'Ledgers_Plus' === $s['zoho_module'] && '794156085' === $s['zoho_owner_id'] && 'Prospecting' === $s['zoho_status_open'] && 'Closed Opportunity' === $s['zoho_status_paid'] && 'Website' === $s['zoho_lead_source'] && 'Ledger Plus Shop' === $s['zoho_lead_form'] && true === $s['zoho_trigger_workflow'] && 8 === $s['zoho_thankyou_budget'] && 4 === $s['zoho_request_timeout'] && array( 5, 30, 120 ) === $s['zoho_retry_delays'] && 60 === $s['zoho_open_per_hour'] );
settings_filter( array( 'zoho_module' => 'Ledgers Plus; DROP', 'zoho_owner_id' => 'abc', 'zoho_status_open' => '  ', 'zoho_status_paid' => "<b>Closed\nWon</b>", 'zoho_lead_source' => '', 'zoho_lead_form' => str_repeat( 'x', 300 ), 'zoho_trigger_workflow' => 0, 'zoho_thankyou_budget' => 99, 'zoho_request_timeout' => 0, 'zoho_retry_delays' => array( '30', 5, 'x', 0, 5, 5000 ), 'zoho_open_per_hour' => 5000 ) );
$s = LP_Shop::settings();
check( 'filter values are cleaned (bad module name and owner id -> defaults, empty status -> default, one-line status, Lead_Source may be empty, 255 characters, budget 0-20, timeout 1-10, retry minutes 1-1440 sorted, unpaid orders per hour 0-1000)', 'Ledgers_Plus' === $s['zoho_module'] && '794156085' === $s['zoho_owner_id'] && 'Prospecting' === $s['zoho_status_open'] && 'Closed Won' === $s['zoho_status_paid'] && '' === $s['zoho_lead_source'] && 255 === strlen( $s['zoho_lead_form'] ) && false === $s['zoho_trigger_workflow'] && 20 === $s['zoho_thankyou_budget'] && 1 === $s['zoho_request_timeout'] && array( 5, 30 ) === $s['zoho_retry_delays'] && 1000 === $s['zoho_open_per_hour'] );
settings_filter( array( 'zoho_owner_id' => '', 'zoho_module' => 'Leads', 'zoho_retry_delays' => array() ) );
$s = LP_Shop::settings();
check( 'owner id may be empty (no Owner sent), another module name is accepted, no retries', '' === $s['zoho_owner_id'] && 'Leads' === $s['zoho_module'] && array() === $s['zoho_retry_delays'] );
settings_filter( array() );
check( 'checkout hooks after F12 (priority 30): classic (3 args), Store API, woocommerce_payment_successful_result (2 args)', 3 === hooked_at( 'woocommerce_checkout_order_processed', 30, 'on_zoho_checkout_classic' ) && 1 === hooked_at( 'woocommerce_store_api_checkout_order_processed', 30, 'on_zoho_checkout' ) && 2 === hooked_at( 'woocommerce_payment_successful_result', 30, 'filter_zoho_payment_result' ) );
check( 'paid hooks: woocommerce_order_status_changed (4 args) and woocommerce_payment_complete', 4 === hooked_at( 'woocommerce_order_status_changed', 30, 'on_zoho_status_changed' ) && 1 === hooked_at( 'woocommerce_payment_complete', 30, 'on_zoho_payment_complete' ) );
check( 'Action Scheduler callback lp_shop_zoho_sync with 2 args ( order_id, reason )', 2 === hooked_at( 'lp_shop_zoho_sync', 10, 'run_zoho_job' ) );
check( 'thank-you sync on template_redirect at priority 3, after the F12 payment check at 1', 1 === hooked_at( 'template_redirect', 3, 'maybe_sync_zoho_on_thankyou' ) && 1 === hooked_at( 'template_redirect', 1, 'maybe_check_geidea_on_thankyou' ) );
check( 'order action filter and handler', 2 === hooked_at( 'woocommerce_order_actions', 20, 'filter_zoho_order_actions' ) && 1 === hooked_at( 'woocommerce_order_action_lp_shop_zoho_sync', 10, 'run_manual_zoho_sync' ) );
LP_Shop::init();
check( 'every F14 callback is hooked once (init() again adds nothing)', 1 === hooked_total( 'woocommerce_order_status_changed', 'on_zoho_status_changed' ) && 1 === hooked_total( 'template_redirect', 'maybe_sync_zoho_on_thankyou' ) && 1 === hooked_total( 'lp_shop_zoho_sync', 'run_zoho_job' ) );
check( 'the add-on stub is seen as ready', true === LP_Shop::zoho_addon_ready() );

/* ======================================================================
 * Checkout: a background job and the utm values, no Zoho request
 * ====================================================================== */
section( 'checkout queues a job (no Zoho request during "Place order")' );
$cookie = 'elementor-zohocrm-utm-cookie';
$_COOKIE[ $cookie ] = json_encode( array( 'utm_source' => 'google', 'utm_medium' => 'cpc', 'utm_campaign' => '<script>x</script>einvoicing ' . str_repeat( 'c', 300 ), 'utm_term' => "e invoicing\nuae", 'utm_keyword' => 'e-invoicing software', 'utm_content' => 'not a field', 'gclid' => 'not a field' ) );
zorder( 1401 );
do_action( 'woocommerce_store_api_checkout_order_processed', wc_get_order( 1401 ) );
$jobs = zjobs( 1401 );
check( 'block checkout: one async lp_shop_zoho_sync job (reason checkout, group ledgerplus-shop), no Zoho request', 1 === count( $jobs ) && 'checkout' === $jobs[0]['args']['reason'] && 'ledgerplus-shop' === $jobs[0]['group'] && ! empty( $jobs[0]['async'] ) && 0 === zcount() );
$utm = meta( 1401, '_lp_zoho_utm' );
check( 'utm values kept on the order: only the five utm_* fields, no tags, one line, at most 255 characters', is_array( $utm ) && array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_keyword' ) === array_keys( $utm ) && 'google' === $utm['utm_source'] && 'e invoicing uae' === $utm['utm_term'] && 0 === strpos( $utm['utm_campaign'], 'xeinvoicing ccc' ) && 255 === strlen( $utm['utm_campaign'] ), json_encode( $utm ) );
do_action( 'woocommerce_store_api_checkout_order_processed', wc_get_order( 1401 ) );
check( 'placing the order again: no second job', 1 === count( zjobs( 1401 ) ) );
$_COOKIE[ $cookie ] = json_encode( array( 'utm_source' => 'facebook' ) );
$result = array( 'result' => 'success', 'redirect' => 'https://ledgerplus.ae/checkout/order-pay/1401/' );
check( '"Pay for order" (woocommerce_payment_successful_result): the gateway result comes back unchanged', $result === apply_filters( 'woocommerce_payment_successful_result', $result, 1401 ) );
check( '... the first utm values are kept, still one job', 'google' === meta( 1401, '_lp_zoho_utm' )['utm_source'] && 1 === count( zjobs( 1401 ) ) );
zorder( 1402 );
do_action( 'woocommerce_checkout_order_processed', 1402, array(), wc_get_order( 1402 ) );
check( 'classic checkout: one job, utm from the cookie', 1 === count( zjobs( 1402, 'checkout' ) ) && 'facebook' === meta( 1402, '_lp_zoho_utm' )['utm_source'] );
zorder( 1403 );
$_COOKIE[ $cookie ] = addslashes( json_encode( array( 'utm_source' => 'linkedin', 'utm_medium' => 'social "paid"' ) ) ); // WordPress's wp_magic_quotes().
apply_filters( 'woocommerce_payment_successful_result', $result, 1403 );
check( '"Pay for order" of an order not seen before: one job; a cookie with added slashes is read', 1 === count( zjobs( 1403, 'checkout' ) ) && array( 'utm_source' => 'linkedin', 'utm_medium' => 'social "paid"' ) === meta( 1403, '_lp_zoho_utm' ) );
zorder( 1404 );
$_COOKIE[ $cookie ] = 'not json {';
do_action( 'woocommerce_store_api_checkout_order_processed', wc_get_order( 1404 ) );
unset( $_COOKIE[ $cookie ] );
zorder( 1405 );
do_action( 'woocommerce_store_api_checkout_order_processed', wc_get_order( 1405 ) );
check( 'an unreadable cookie or none: no utm meta, the job is still queued', '' === meta( 1404, '_lp_zoho_utm' ) && '' === meta( 1405, '_lp_zoho_utm' ) && 1 === count( zjobs( 1404 ) ) && 1 === count( zjobs( 1405 ) ) );
check( 'zoho_parse_utm(): arrays, booleans, cookies over 8 KB (also valid JSON) and other keys are ignored', array() === LP_Shop::zoho_parse_utm( json_encode( array( 'utm_source' => array( 'x' ), 'utm_medium' => true, 'source' => 'y' ) ) ) && array() === LP_Shop::zoho_parse_utm( str_repeat( 'a', 9000 ) ) && array() === LP_Shop::zoho_parse_utm( json_encode( array( 'utm_source' => 'x', 'pad' => str_repeat( 'a', 9000 ) ) ) ) && array() === LP_Shop::zoho_parse_utm( null ) && array( 'utm_term' => '42' ) === LP_Shop::zoho_parse_utm( '{"utm_term":42}' ) );
settings_filter( array( 'zoho_sync' => false ) );
zorder( 1406 );
do_action( 'woocommerce_store_api_checkout_order_processed', wc_get_order( 1406 ) );
wc_get_order( 1406 )->payment_complete( 'txn-1406' );
check( 'sync off: nothing queued at checkout or payment', ! zjobs( 1406 ) );
settings_filter( array() );

/* ======================================================================
 * Background job: a new person -> a record created as Prospecting + a checkout note
 * ====================================================================== */
section( 'background job: new buyer, record created as Prospecting' );
$n0 = zcount();
run_zoho_jobs( null, 1401 );
$calls = zcalls_since( $n0 );
check( 'requests: search by email, create, note', 'GET Ledgers_Plus/search | POST Ledgers_Plus | POST Notes' === zsig( $calls ), zsig( $calls ) );
check( 'search: GET Ledgers_Plus/search with email = the billing email', array( 'email' => 'zb1401.buyer@example.com' ) === $calls[0]['fields'] );
check( 'each request capped at 15 s in the background (the add-on asks for 20)', array( 15.0, 15.0, 15.0 ) === array_map( 'floatval', array_column( $calls, 'timeout' ) ) );
$create = $calls[1]['fields'];
$f      = $create['data'][0];
check( 'create: one record, "trigger": ["workflow"] (Zoho workflow rules run)', 1 === count( $create['data'] ) && array( 'workflow' ) === $create['trigger'] );
check( 'create: Name, Email, Mobile, Lead_Status Prospecting, Lead_Source Website, Lead_Form "Ledger Plus Shop", Owner {"id":"794156085"}', 'Aisha Zohobuyer1401' === $f['Name'] && 'zb1401.buyer@example.com' === $f['Email'] && '+971 50 000 1401' === $f['Mobile'] && 'Prospecting' === $f['Lead_Status'] && 'Website' === $f['Lead_Source'] && 'Ledger Plus Shop' === $f['Lead_Form'] && array( 'id' => '794156085' ) === $f['Owner'] );
check( 'create: the utm values, Page_Name, Landing_page_URL and the buyer\'s note (one line) in Client_s_message', 'google' === $f['utm_source'] && 'cpc' === $f['utm_medium'] && 'e-invoicing software' === $f['utm_keyword'] && 'Checkout' === $f['Page_Name'] && 'https://ledgerplus.ae/checkout/' === $f['Landing_page_URL'] && 'Please call after 4pm. Or email zb1401.buyer@example.com' === $f['Client_s_message'] );
$desc = $f['Description'];
check( 'Description: company, product with price, order, date, trade licence, TRN, referral checks, the buyer\'s note', 0 === strpos( $desc, "Client Company Name: Zoho Test Trading 1401 LLC\nProduct: E-Invoicing Starter Package x 1 (AED 1,999.00)\nOrder: #1401, AED 1,999.00 incl. VAT AED 95.19, bacs\nOrder date: " . gmdate( 'Y-m-d', time() - 600 ) ) && false !== strpos( $desc, "\nTrade licence: CN-1401567\nVAT TRN: 100123456789003\nReferral code used: None\nPossible existing customer: Checked when payment is confirmed\nBuyer’s own referral code: Created when payment is confirmed\nOrder note from the buyer: Please call after 4pm.\nOr email zb1401.buyer@example.com\nSent by Ledger Plus Shop from the website checkout." ), $desc );
check( 'no Company1, no other unknown field; every text value at most 255 characters except Description', ! isset( $f['Company1'] ) && array() === array_diff( array_keys( $f ), array( 'Name', 'Email', 'Mobile', 'Lead_Status', 'Lead_Source', 'Lead_Form', 'Description', 'Page_Name', 'Landing_page_URL', 'Client_s_message', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_keyword', 'Owner' ) ) && ! array_filter( $f, function ( $v, $k ) { return is_string( $v ) && 'Description' !== $k && mb_strlen( $v ) > 255; }, ARRAY_FILTER_USE_BOTH ) );
$rid  = (string) meta( 1401, '_lp_zoho_record_id' );
$note = $calls[2]['fields']['data'][0];
check( 'checkout note: on the new record (Parent_Id, se_module Ledgers_Plus), title and order details', isset( zrecords()[ $rid ] ) && $rid === $note['Parent_Id'] && 'Ledgers_Plus' === $note['se_module'] && 'Ledger Plus Shop order #1401 placed' === $note['Note_Title'] && 0 === strpos( $note['Note_Content'], 'Order #1401 placed on ' . gmdate( 'Y-m-d', time() - 600 ) ) && false !== strpos( $note['Note_Content'], "at the website checkout. Order status: Pending payment.\nClient Company Name: Zoho Test Trading 1401 LLC\nProduct: E-Invoicing Starter Package x 1 (AED 1,999.00)" ) );
check( 'fake Zoho: one record (Prospecting), one note', 1 === count( zrecords() ) && 'Prospecting' === zrecord_of( 1401 )['Lead_Status'] && 1 === count( lp_zoho_notes_of( $rid ) ) );
check( 'order meta: record id, created by this order, stage open, Prospecting sent, checkout note sent, no error', '' !== LP_Shop::zoho_id( $rid ) && 'created' === meta( 1401, '_lp_zoho_record_source' ) && 'open' === meta( 1401, '_lp_zoho_stage' ) && 'Prospecting' === meta( 1401, '_lp_zoho_status_sent' ) && 'checkout' === meta( 1401, '_lp_zoho_notes' ) && '' === meta( 1401, '_lp_zoho_last_error' ) && '' === meta( 1401, '_lp_zoho_attempts' ) );
check( 'order note: record id and what was done, nothing personal', array( "Ledger Plus: sent to Zoho CRM (Ledgers_Plus record $rid): created as Prospecting; checkout note added (background job)." ) === zoho_notes_on_order( 1401 ), implode( ' | ', notes( 1401 ) ) );
check( 'log: one info line with the order id, record id and requests', 1 === count( zlog_lines( "info: Order 1401: sent to Zoho CRM (Ledgers_Plus record $rid, background job, 3 requests, " ) ) );
$n0 = zcount();
do_action( 'lp_shop_zoho_sync', 1401, 'checkout' );
do_action( 'lp_shop_zoho_sync', 1401, 'retry' );
check( 'the job again (or a retry): no request, no new note (idempotent)', $n0 === zcount() && 1 === count( zoho_notes_on_order( 1401 ) ) );
run_zoho_jobs();
check( 'the other checkout jobs: one record per order (each its own person), utm values sent', 5 === count( zrecords() ) && 'linkedin' === zrecord_of( 1403 )['utm_source'] && 'social "paid"' === zrecord_of( 1403 )['utm_medium'] && ! isset( zrecord_of( 1404 )['utm_source'] ) && ! zjobs( 1402 ) && ! zjobs( 1405 ) );

/* ======================================================================
 * Paid: a job is queued; the thank-you page sets Closed Opportunity before it is shown
 * ====================================================================== */
section( 'paid: Closed Opportunity before the thank-you page' );
$n0 = zcount();
wc_get_order( 1401 )->payment_complete( 'txn-1401' );
check( 'payment: one job (reason paid; woocommerce_order_status_changed and woocommerce_payment_complete together), no Zoho request in that request', 1 === count( zjobs( 1401, 'paid' ) ) && $n0 === zcount() && 'processing' === row( 1401 )['status'] );
$GLOBALS['zb_seen_at_render'] = null;
add_action( 'woocommerce_thankyou', function () { $GLOBALS['zb_seen_at_render'] = zrecord_of( 1401 )['Lead_Status'] ?? null; }, 1 );
$page  = thankyou_visit( 1401, 'wc_order_key1401', true );
$calls = zcalls_since( $n0 );
check( 'thank-you page (valid key, paid order): update + payment note right away', 'PUT Ledgers_Plus | POST Notes' === zsig( $calls ), zsig( $calls ) );
check( '... the record was "Closed Opportunity" before the page was rendered', 'Closed Opportunity' === $GLOBALS['zb_seen_at_render'] && false !== strpos( $page, 'lp-shop-thankyou--paid' ) );
check( '... each request capped at 4 s (zoho_request_timeout)', ! array_filter( $calls, function ( $c ) { return (float) $c['timeout'] > 4.0 || (float) $c['timeout'] <= 0; } ) );
check( 'update: only the id and Lead_Status "Closed Opportunity", "trigger": ["workflow"]', array( 'data' => array( array( 'id' => $rid, 'Lead_Status' => 'Closed Opportunity' ) ), 'trigger' => array( 'workflow' ) ) === $calls[0]['fields'] );
$pn = $calls[1]['fields']['data'][0];
check( 'payment note: title, payment line with the total and method, transaction, statuses, product, referral lines', 'Ledger Plus Shop order #1401 paid' === $pn['Note_Title'] && $rid === $pn['Parent_Id'] && 0 === strpos( $pn['Note_Content'], 'Payment confirmed for order #1401 on ' . gmdate( 'Y-m-d' ) ) && false !== strpos( $pn['Note_Content'], ": AED 1,999.00 incl. VAT AED 95.19 via bacs.\nTransaction: txn-1401\nOrder status: Processing. Lead Status: Closed Opportunity.\nProduct: E-Invoicing Starter Package x 1\nReferral code used: None\nPossible existing customer: No\nBuyer’s own referral code: LP-" ), $pn['Note_Content'] );
$rec = zrecord_of( 1401 );
check( 'fake Zoho: no new record, this one now Closed Opportunity; Owner, Lead_Source, Lead_Form and Description unchanged; two notes', 5 === count( zrecords() ) && 'Closed Opportunity' === $rec['Lead_Status'] && array( 'id' => '794156085' ) === $rec['Owner'] && 'Website' === $rec['Lead_Source'] && 'Ledger Plus Shop' === $rec['Lead_Form'] && $desc === $rec['Description'] && 2 === count( lp_zoho_notes_of( $rid ) ) );
check( 'meta: stage paid, Closed Opportunity sent, both notes', 'paid' === meta( 1401, '_lp_zoho_stage' ) && 'Closed Opportunity' === meta( 1401, '_lp_zoho_status_sent' ) && 'checkout,paid' === meta( 1401, '_lp_zoho_notes' ) );
check( 'order note names the thank-you page', 1 === count( notes( 1401, "Ledger Plus: sent to Zoho CRM (Ledgers_Plus record $rid): Lead Status set to Closed Opportunity; payment note added (thank-you page)." ) ) );
$n0 = zcount();
run_zoho_jobs( null, 1401 );
$GLOBALS['lp_test']['time_shift'] = 31;
thankyou_visit( 1401, 'wc_order_key1401' );
$GLOBALS['lp_test']['time_shift'] = 0;
check( 'the queued paid job and a later reload of the thank-you page send nothing', $n0 === zcount() && ! zjobs( 1401 ) && 2 === count( zoho_notes_on_order( 1401 ) ) );
$box = LP_Shop::zoho_admin_rows( wc_get_order( 1401 ) );
check( 'order box: record id (module, created by this order) and "Closed Opportunity (date UTC)", no error row', array( array( 'Zoho CRM record', "$rid (Ledgers_Plus, created by this order)" ), array( 'Zoho CRM Lead Status sent', 'Closed Opportunity (' . gmdate( 'Y-m-d H:i', (int) meta( 1401, '_lp_zoho_stage_at' ) ) . ' UTC)' ) ) === $box, json_encode( $box ) );
ob_start();
do_action( 'woocommerce_admin_order_data_after_billing_address', wc_get_order( 1401 ) );
$html = ob_get_clean();
check( '... shown in the "Ledger Plus" box under the Ledger Plus checks', false !== strpos( $html, '<div class="lp-shop-admin-box"><h4>Ledger Plus</h4><p><strong>Trade licence:</strong>' ) && false !== strpos( $html, '<p><strong>Zoho CRM record:</strong> ' . $rid . ' (Ledgers_Plus, created by this order)</p><p><strong>Zoho CRM Lead Status sent:</strong> Closed Opportunity (' ) );

/* ======================================================================
 * One record per person: an existing record is updated, never moved down
 * ====================================================================== */
section( 'existing record found by email' );
lp_zoho_reset();
$other = lp_zoho_add_record( array( 'Name' => 'Colleague with the address as second email', 'Email' => 'colleague@example.com', 'Secondary_Email' => 'ZB1410.Buyer@Example.com', 'Lead_Status' => 'No Response' ) );
$rid   = lp_zoho_add_record( array( 'Name' => 'Aisha (hero form)', 'Email' => 'ZB1410.buyer@example.com', 'Lead_Status' => 'Developing', 'Owner' => array( 'id' => '111' ), 'Lead_Source' => 'Facebook', 'Lead_Form' => 'Hero form', 'Description' => 'Client Company Name: from the hero form' ) );
$before = zrecords()[ $rid ];
zorder( 1410 );
do_action( 'woocommerce_store_api_checkout_order_processed', wc_get_order( 1410 ) );
run_zoho_jobs( null, 1410 );
check( 'checkout job: search, then only a note (no create, no update)', 'GET Ledgers_Plus/search | POST Notes' === zsig( zcalls() ), zsig( zcalls() ) );
check( '... on the record whose Email is the address (letter case ignored), not on an earlier match by another email field', $rid === meta( 1410, '_lp_zoho_record_id' ) && 1 === count( lp_zoho_notes_of( $rid ) ) && ! lp_zoho_notes_of( $other ) );
check( '... the record is unchanged: Lead_Status (no "Prospecting"), Owner, Lead_Source, Lead_Form, Description', $before === zrecords()[ $rid ] && 2 === count( zrecords() ) );
check( 'meta: found by email, stage open, no status sent', 'found' === meta( 1410, '_lp_zoho_record_source' ) && 'open' === meta( 1410, '_lp_zoho_stage' ) && '' === meta( 1410, '_lp_zoho_status_sent' ) );
check( 'order note says the Lead Status was not changed', array( "Ledger Plus: sent to Zoho CRM (Ledgers_Plus record $rid): existing record found by email; its Lead Status was not changed; checkout note added (background job)." ) === zoho_notes_on_order( 1410 ), implode( ' | ', notes( 1410 ) ) );
check( 'order box: existing record, Lead Status not changed', "$rid (Ledgers_Plus, existing record, found by email)" === admin_rows( 1410 )['Zoho CRM record'] && 0 === strpos( admin_rows( 1410 )['Zoho CRM Lead Status sent'], 'not changed (existing record; checkout note only) (' ) );
wc_get_order( 1410 )->update_status( 'completed', 'Set by hand.' );
check( 'marked Completed by hand (no payment_complete): a paid job is queued', 1 === count( zjobs( 1410, 'paid' ) ) );
$n0 = zcount();
run_zoho_jobs( null, 1410 );
check( 'paid job: update to Closed Opportunity (id + Lead_Status only) and a payment note', 'PUT Ledgers_Plus | POST Notes' === zsig( zcalls_since( $n0 ) ) && array( array( 'id' => $rid, 'Lead_Status' => 'Closed Opportunity' ) ) === zcalls_since( $n0 )[0]['fields']['data'] );
$after = zrecords()[ $rid ];
check( '... only Lead_Status changed (Owner 111, Lead_Source Facebook, Lead_Form, Description kept)', 'Closed Opportunity' === $after['Lead_Status'] && array_merge( $before, array( 'Lead_Status' => 'Closed Opportunity' ) ) === $after );
zorder( 1411 );
$GLOBALS['lp_test']['orders'][1411]['email'] = 'zb1410.buyer@example.com';
do_action( 'woocommerce_store_api_checkout_order_processed', wc_get_order( 1411 ) );
run_zoho_jobs( null, 1411 );
wc_get_order( 1411 )->update_status( 'cancelled' );
run_zoho_jobs( null, 1411 );
check( 'the same person\'s next order, unpaid then cancelled: a note on the same record, which stays Closed Opportunity (never moved down)', 'Closed Opportunity' === zrecords()[ $rid ]['Lead_Status'] && $rid === meta( 1411, '_lp_zoho_record_id' ) && 3 === count( lp_zoho_notes_of( $rid ) ) && 2 === count( zrecords() ) && ! zjobs( 1411 ) );
check( 'no request ever changed an existing record except Lead_Status', ! array_filter( $GLOBALS['lp_zoho']['writes'], function ( $w ) { return 'update' === $w['op'] && array( 'id', 'Lead_Status' ) !== array_keys( $w['fields'] ); } ) );

section( 'first sync after the payment: created as Closed Opportunity' );
lp_zoho_reset();
zorder( 1420 );
do_action( 'woocommerce_store_api_checkout_order_processed', wc_get_order( 1420 ) );
wc_get_order( 1420 )->payment_complete( 'txn-1420' );
run_zoho_jobs( null, 1420 );
check( 'requests: search, create, checkout note, payment note (no update)', 'GET Ledgers_Plus/search | POST Ledgers_Plus | POST Notes | POST Notes' === zsig( zcalls() ), zsig( zcalls() ) );
check( '... created with Lead_Status Closed Opportunity; notes in order: checkout, then payment', 'Closed Opportunity' === zcalls()[1]['fields']['data'][0]['Lead_Status'] && 'Ledger Plus Shop order #1420 placed' === zcalls()[2]['fields']['data'][0]['Note_Title'] && 'Ledger Plus Shop order #1420 paid' === zcalls()[3]['fields']['data'][0]['Note_Title'] );
check( '... the second job (paid) sent nothing; meta stage paid', 4 === zcount() && 'paid' === meta( 1420, '_lp_zoho_stage' ) && 'checkout,paid' === meta( 1420, '_lp_zoho_notes' ) && 'Closed Opportunity' === meta( 1420, '_lp_zoho_status_sent' ) );

section( 'Zoho reports a duplicate' );
lp_zoho_reset( array( 'unique_email' => true, 'search_blind' => true ) );
$rid = lp_zoho_add_record( array( 'Name' => 'Aisha', 'Email' => 'zb1430.buyer@example.com', 'Lead_Status' => 'Follow up' ) );
zorder( 1430, 'processing' );
do_action( 'lp_shop_zoho_sync', 1430, 'paid' );
check( 'search finds nothing (not indexed yet), create answers DUPLICATE_DATA with the record id: that record is used', 'GET Ledgers_Plus/search | POST Ledgers_Plus | PUT Ledgers_Plus | POST Notes | POST Notes' === zsig( zcalls() ) && $rid === meta( 1430, '_lp_zoho_record_id' ) && 1 === count( zrecords() ) && 'Closed Opportunity' === zrecords()[ $rid ]['Lead_Status'] && 2 === count( lp_zoho_notes_of( $rid ) ), zsig( zcalls() ) );
check( '... meta and order box: existing record reported by Zoho as a duplicate', 'duplicate' === meta( 1430, '_lp_zoho_record_source' ) && "$rid (Ledgers_Plus, existing record, reported by Zoho as a duplicate)" === admin_rows( 1430 )['Zoho CRM record'] && 1 === count( notes( 1430, 'existing record found (Zoho reported a duplicate); Lead Status set to Closed Opportunity; checkout note added; payment note added (background job).' ) ) );
lp_zoho_reset( array( 'search_blind' => true ) );
zorder( 1431 );
inject( null, lp_zoho_json( 202, lp_zoho_item_error( 'DUPLICATE_DATA', 'duplicate data', array( 'api_name' => 'Email' ) ) ) );
do_action( 'lp_shop_zoho_sync', 1431, 'checkout' );
check( 'DUPLICATE_DATA without a record id: a failed attempt, retried', 1 === count( notes( 1431, 'Ledger Plus: Zoho CRM sync failed (attempt 1 of 4, background job): Zoho answered DUPLICATE_DATA: duplicate data (field Email). Ledger Plus will try again in about 5 minutes.' ) ) && 1 === count( zjobs( 1431, 'retry' ) ) && '' === meta( 1431, '_lp_zoho_record_id' ) );

/* ======================================================================
 * Failures, retries, notes
 * ====================================================================== */
section( 'failures: notes, last error, retries after 5, 30 and 120 minutes' );
lp_zoho_reset();
zorder( 1440 );
$zoho_err = lp_zoho_json( 202, lp_zoho_item_error( 'INVALID_DATA', 'invalid data for zb1440.buyer@example.com / +971 50 000 1440 / 971500001440', array( 'api_name' => 'Mobile', 'expected_data_type' => 'phonenumber' ) ) );
inject( null, $zoho_err );
$t0 = time();
do_action( 'lp_shop_zoho_sync', 1440, 'checkout' );
check( 'Zoho error on create: note with attempt, code, message (email and phone hidden), field and the next try', array( 'Ledger Plus: Zoho CRM sync failed (attempt 1 of 4, background job): Zoho answered INVALID_DATA: invalid data for [email] / [phone] / [hidden] (field Mobile). Ledger Plus will try again in about 5 minutes.' ) === zoho_notes_on_order( 1440 ), implode( ' | ', notes( 1440 ) ) );
$retry = zjobs( 1440, 'retry' );
check( '... meta: last error, attempts 1; one retry job about 5 minutes later', 'Zoho answered INVALID_DATA: invalid data for [email] / [phone] / [hidden] (field Mobile)' === meta( 1440, '_lp_zoho_last_error' ) && 1 === meta( 1440, '_lp_zoho_attempts' ) && 1 === count( $retry ) && abs( $retry[0]['time'] - ( $t0 + 300 ) ) <= 2 );
check( '... error log line with the code, no personal data', 1 === count( zlog_lines( 'error: Order 1440: Zoho CRM sync failed (attempt 1 of 4, background job, code INVALID_DATA): Zoho answered INVALID_DATA: invalid data for [email] / [phone] / [hidden] (field Mobile).' ) ) );
foreach ( array( array( 300, 2, 'Ledger Plus will try again in about 30 minutes.' ), array( 1800, 3, 'Ledger Plus will try again in about 120 minutes.' ), array( 7200, 4, 'No more automatic tries: use the order action "Send to Zoho CRM again".' ) ) as $step ) {
	inject( null, $zoho_err );
	run_zoho_jobs( time() + $step[0] + 5, 1440 );
	check( "retry: attempt {$step[1]} of 4 noted: \"{$step[2]}\"", 1 === count( notes( 1440, "(attempt {$step[1]} of 4, background job)" ) ) && 1 === count( notes( 1440, $step[2] ) ) && $step[1] === meta( 1440, '_lp_zoho_attempts' ) );
}
check( 'after the 4th attempt: no job pending, 4 creates tried, nothing in Zoho', ! zjobs( 1440 ) && 4 === count( array_filter( zcalls(), function ( $c ) { return 'POST' === $c['method'] && 'Ledgers_Plus' === $c['model']; } ) ) && array() === zrecords() );
check( 'order box: last error with the time and "failed attempts: 4 of 4"', 1 === preg_match( '/^\d{4}-\d\d-\d\d \d\d:\d\d UTC: Zoho answered INVALID_DATA: invalid data for \[email\] \/ \[phone\] \/ \[hidden\] \(field Mobile\) \(failed attempts: 4 of 4\)$/', admin_rows( 1440 )['Zoho CRM last error'] ?? '' ), admin_rows( 1440 )['Zoho CRM last error'] ?? '' );
check( 'order action "Send to Zoho CRM again" is offered (also on non-Geidea orders), not without an order', 'Send to Zoho CRM again' === ( apply_filters( 'woocommerce_order_actions', array(), wc_get_order( 1440 ) )['lp_shop_zoho_sync'] ?? '' ) && array() === apply_filters( 'woocommerce_order_actions', array(), null ) );
$GLOBALS['lp_test']['caps'] = array();
do_action( 'woocommerce_order_action_lp_shop_zoho_sync', wc_get_order( 1440 ) );
check( '... without the right (edit_shop_orders / manage_woocommerce): nothing happens', 4 === count( array_filter( zcalls(), function ( $c ) { return 'POST' === $c['method'] && 'Ledgers_Plus' === $c['model']; } ) ) );
$GLOBALS['lp_test']['caps'] = array( 'edit_shop_orders' );
do_action( 'woocommerce_order_action_lp_shop_zoho_sync', wc_get_order( 1440 ) );
$rid = (string) meta( 1440, '_lp_zoho_record_id' );
check( '... with it: sent now (created as Prospecting, checkout note), error and attempts cleared, note names the order action', isset( zrecords()[ $rid ] ) && 'Prospecting' === zrecords()[ $rid ]['Lead_Status'] && '' === meta( 1440, '_lp_zoho_last_error' ) && '' === meta( 1440, '_lp_zoho_attempts' ) && 1 === count( notes( 1440, "sent to Zoho CRM (Ledgers_Plus record $rid): created as Prospecting; checkout note added (order action)." ) ) && ! isset( admin_rows( 1440 )['Zoho CRM last error'] ) );
check( '... the order action\'s requests are capped at 10 s', ! array_filter( array_slice( zcalls(), -3 ), function ( $c ) { return (float) $c['timeout'] > 10.0; } ) );
do_action( 'woocommerce_order_action_lp_shop_zoho_sync', wc_get_order( 1440 ) );
check( '... again: "nothing to send" note, no request', 1 === count( notes( 1440, "Ledger Plus: Zoho CRM (order action): nothing to send. Ledgers_Plus record $rid already has this order (Lead Status Prospecting and its notes)." ) ) );
$GLOBALS['lp_test']['caps'] = array( 'manage_woocommerce' );

section( 'a new event starts a new series of retries' );
lp_zoho_reset();
zorder( 1441 );
inject( lp_zoho_json( 500, lp_zoho_top_error( 'INTERNAL_ERROR', 'Internal Server Error' ) ) );
do_action( 'lp_shop_zoho_sync', 1441, 'checkout' );
inject( null, $zoho_err );
run_zoho_jobs( time() + 305, 1441 );
check( 'checkout job failed twice (attempts 1 and 2; top-level Zoho error quoted)', 1 === count( notes( 1441, '(attempt 1 of 4, background job): Zoho answered INTERNAL_ERROR: Internal Server Error.' ) ) && 2 === meta( 1441, '_lp_zoho_attempts' ) && 1 === count( zjobs( 1441, 'retry' ) ) );
wc_get_order( 1441 )->payment_complete( 'txn-1441' );
inject( lp_zoho_json( 500, lp_zoho_top_error( 'INTERNAL_ERROR', 'Internal Server Error' ) ) );
run_zoho_jobs( time() + 1, 1441 );
check( 'the paid job fails too: attempt 1 again, one retry pending (the old one replaced) about 5 minutes later', 2 === count( notes( 1441, '(attempt 1 of 4, background job)' ) ) && 1 === meta( 1441, '_lp_zoho_attempts' ) && 1 === count( zjobs( 1441, 'retry' ) ) && abs( zjobs( 1441, 'retry' )[0]['time'] - ( time() + 300 ) ) <= 2 );
run_zoho_jobs( time() + 305, 1441 );
check( 'the retry succeeds: created as Closed Opportunity with both notes; error cleared', 'Closed Opportunity' === ( zrecord_of( 1441 )['Lead_Status'] ?? '' ) && 'checkout,paid' === meta( 1441, '_lp_zoho_notes' ) && '' === meta( 1441, '_lp_zoho_last_error' ) && ! zjobs( 1441 ) );

section( 'kinds of failure' );
$kinds = array(
	'WP_Error from the HTTP API (the host is hidden)' => array( new WP_Error( 'http_request_failed', 'cURL error 6: Could not resolve host: fake-zoho-api.example.invalid' ), 'the request to Zoho failed (http_request_failed: cURL error 6: Could not resolve host: [host])' ),
	'time-out (cURL error 28)'                        => array( new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 15001 milliseconds with 0 bytes received' ), 'no answer from Zoho in time (cURL error 28: Operation timed out after 15001 milliseconds with 0 bytes received)' ),
	'top-level Zoho error (INVALID_TOKEN)'            => array( lp_zoho_json( 401, lp_zoho_top_error( 'INVALID_TOKEN', 'invalid oauth token' ) ), 'Zoho answered INVALID_TOKEN: invalid oauth token' ),
	'OAUTH_SCOPE_MISMATCH'                            => array( lp_zoho_json( 401, lp_zoho_top_error( 'OAUTH_SCOPE_MISMATCH', 'invalid oauth scope to access this URL' ) ), 'Zoho answered OAUTH_SCOPE_MISMATCH: invalid oauth scope to access this URL' ),
	'search answers something that is not a list'     => array( lp_zoho_json( 200, array( 'data' => 'x' ) ), 'unexpected answer from Zoho' ),
	'an exception inside the add-on (message scrubbed)' => array( function () { throw new RuntimeException( 'boom for zb1442.buyer@example.com at https://fake-zoho-api.example.invalid/crm/v2' ); }, 'the Zoho CRM add-on failed (RuntimeException: boom for [email] at [url])' ),
	'the Authorization header in an error message is hidden' => array( new WP_Error( 'http_request_failed', 'cURL error 35: SSL connect error, header Zoho-oauthtoken ' . LP_FAKE_ZOHO_ACCESS ), 'the request to Zoho failed (http_request_failed: cURL error 35: SSL connect error, header [hidden])' ),
	'a token-like string in an error message is hidden' => array( new WP_Error( 'http_request_failed', 'refresh failed: invalid_code for ' . LP_FAKE_ZOHO_REFRESH ), 'the request to Zoho failed (http_request_failed: refresh failed: invalid_code for [hidden])' ),
);
$i = 1442;
foreach ( $kinds as $what => $k ) {
	lp_zoho_reset();
	zorder( $i );
	inject( $k[0] );
	do_action( 'lp_shop_zoho_sync', $i, 'checkout' );
	check( "$what: \"{$k[1]}\", retry queued", $k[1] === meta( $i, '_lp_zoho_last_error' ) && 1 === count( notes( $i, '(attempt 1 of 4, background job): ' . $k[1] . '. Ledger Plus will try again in about 5 minutes.' ) ) && 1 === count( zjobs( $i, 'retry' ) ), (string) meta( $i, '_lp_zoho_last_error' ) );
	$i++;
}
$writes = array(
	'create answers an empty body'                 => array( lp_zoho_json( 204, null ), 'unexpected answer from Zoho (empty)' ),
	'create answers success without a record id'   => array( lp_zoho_json( 201, array( 'data' => array( array( 'code' => 'SUCCESS', 'details' => array(), 'status' => 'success' ) ) ) ), 'unexpected answer from Zoho (no record id)' ),
	'create answers an empty data list'            => array( lp_zoho_json( 200, array( 'data' => array() ) ), 'unexpected answer from Zoho (no data)' ),
	'create: MANDATORY_NOT_FOUND names the field'  => array( lp_zoho_json( 202, lp_zoho_item_error( 'MANDATORY_NOT_FOUND', 'required field not found', array( 'api_name' => 'Name' ) ) ), 'Zoho answered MANDATORY_NOT_FOUND: required field not found (field Name)' ),
	'a code with odd characters is not quoted'     => array( lp_zoho_json( 202, lp_zoho_item_error( 'BAD<script>', 'x' ) ), 'Zoho answered ERROR: x' ),
);
foreach ( $writes as $what => $k ) {
	lp_zoho_reset();
	zorder( $i );
	inject( null, $k[0] );
	do_action( 'lp_shop_zoho_sync', $i, 'checkout' );
	check( "$what: \"{$k[1]}\"", $k[1] === meta( $i, '_lp_zoho_last_error' ) && '' === meta( $i, '_lp_zoho_record_id' ) && 1 === count( zjobs( $i, 'retry' ) ), (string) meta( $i, '_lp_zoho_last_error' ) );
	$i++;
}

section( 'a search answer without JSON is an error, never "no record" (one record per person)' );
$cases = array(
	'HTTP 503 with an HTML error page'                           => array( array( 'code' => 503, 'body' => '<html><body>Service Temporarily Unavailable</body></html>' ), false, 'unexpected answer from Zoho to the search (HTTP 503, no JSON)' ),
	'HTTP 500 with an empty body'                                => array( array( 'code' => 500, 'body' => '' ), false, 'unexpected answer from Zoho to the search (HTTP 500, no JSON)' ),
	'HTTP 200 with a body cut off'                               => array( array( 'code' => 200, 'body' => '{"data":[{"id":"57257670000' ), false, 'unexpected answer from Zoho to the search (HTTP 200, no JSON)' ),
	'no HTTP status seen (another plugin answered the request)' => array( array( 'code' => 204, 'body' => '' ), true, 'unexpected answer from Zoho to the search (no HTTP status, no JSON)' ),
);
foreach ( $cases as $what => $k ) {
	lp_zoho_reset( array( 'no_http_response' => $k[1] ) );
	$rid = lp_zoho_add_record( array( 'Name' => 'Aisha (hero form)', 'Email' => "zb{$i}.buyer@example.com", 'Lead_Status' => 'Developing' ) );
	zorder( $i );
	inject( $k[0] );
	do_action( 'lp_shop_zoho_sync', $i, 'checkout' );
	check( "$what: only the search was sent (no create), \"{$k[2]}\" noted, retry queued", 'GET Ledgers_Plus/search' === zsig( zcalls() ) && 1 === count( zrecords() ) && $k[2] === meta( $i, '_lp_zoho_last_error' ) && 1 === count( notes( $i, '(attempt 1 of 4, background job): ' . $k[2] . '. Ledger Plus will try again in about 5 minutes.' ) ) && 1 === count( zjobs( $i, 'retry' ) ), zsig( zcalls() ) . ' / ' . meta( $i, '_lp_zoho_last_error' ) );
	if ( 'HTTP 503 with an HTML error page' === $what ) {
		run_zoho_jobs( time() + 305, $i );
		check( '... the retry finds the person\'s record and uses it (Lead Status kept, a note), still one record', 1 === count( zrecords() ) && $rid === meta( $i, '_lp_zoho_record_id' ) && 'found' === meta( $i, '_lp_zoho_record_source' ) && 'Developing' === zrecords()[ $rid ]['Lead_Status'] && 1 === count( lp_zoho_notes_of( $rid ) ) );
	}
	$i++;
}
lp_zoho_reset( array( 'refresh_latency' => 0.01 ) );
zorder( $i );
do_action( 'lp_shop_zoho_sync', $i, 'checkout' );
check( 'Zoho\'s HTTP 204 after a token refresh (HTTP 200) in the same call: the last answer counts, "no record", so it is created', 1 === count( $GLOBALS['lp_test']['zoho_refreshes'] ) && 'GET Ledgers_Plus/search | POST Ledgers_Plus | POST Notes' === zsig( zcalls() ) && 'created' === meta( $i, '_lp_zoho_record_source' ) );
$i++;

section( 'the add-on is not connected to Zoho' );
lp_zoho_reset();
$GLOBALS['lp_test']['zoho_auth'] = array( 'refresh_token' => LP_FAKE_ZOHO_REFRESH, 'api_domain' => LP_FAKE_ZOHO_DOMAIN ); // no access_token
zorder( 1460 );
do_action( 'lp_shop_zoho_sync', 1460, 'checkout' );
check( 'no access token: the add-on hands back its own data; noted as "not connected", retried, nothing sent', 'the Zoho CRM add-on is not connected to Zoho (it returned no Zoho answer; connect it again in its settings)' === meta( 1460, '_lp_zoho_last_error' ) && 1 === count( zjobs( 1460, 'retry' ) ) && ! array_filter( zcalls(), function ( $c ) { return $c['sent']; } ) );
lp_zoho_reset();
$GLOBALS['lp_test']['zoho_auth'] = new WP_Error( 'zoho_refresh_failed', 'Refresh token is invalid for fake-zoho-api.example.invalid' );
zorder( 1461 );
do_action( 'lp_shop_zoho_sync', 1461, 'checkout' );
check( 'the add-on\'s token refresh fails (WP_Error): noted with its code, host hidden, retried', 'the request to Zoho failed (zoho_refresh_failed: Refresh token is invalid for [host])' === meta( 1461, '_lp_zoho_last_error' ) && 1 === count( zjobs( 1461, 'retry' ) ) );
lp_zoho_reset();

section( 'partial progress is kept; nothing is created twice' );
zorder( 1465 );
inject( null, null, lp_zoho_json( 500, lp_zoho_top_error( 'INTERNAL_ERROR', 'Internal Server Error' ) ) );
do_action( 'lp_shop_zoho_sync', 1465, 'checkout' );
$rid = (string) meta( 1465, '_lp_zoho_record_id' );
check( 'created, then the note failed: record id and stage kept, the note names what was done', isset( zrecords()[ $rid ] ) && 'open' === meta( 1465, '_lp_zoho_stage' ) && '' === meta( 1465, '_lp_zoho_notes' ) && 1 === count( notes( 1465, "Zoho answered INTERNAL_ERROR: Internal Server Error. Done before the error (record $rid): created as Prospecting. Ledger Plus will try again in about 5 minutes." ) ) );
$n0 = zcount();
run_zoho_jobs( time() + 305, 1465 );
check( '... the retry only adds the note (no search, no second record)', 'POST Notes' === zsig( zcalls_since( $n0 ) ) && 1 === count( zrecords() ) && 'checkout' === meta( 1465, '_lp_zoho_notes' ) );

section( 'a create that got no answer: no second create for 3 minutes' );
lp_zoho_reset();
zorder( 1468 );
wc_get_order( 1468 )->payment_complete( 'txn-1468' ); // The paid job is queued (Geidea's callback / F12).
inject(
	null, // The search: no match.
	function ( $method, $model, $fields ) { // The create: Zoho stores the record, its answer is cut by the 4 s cap.
		$id                                   = (string) $GLOBALS['lp_zoho']['next_id']++;
		$GLOBALS['lp_zoho']['records'][ $id ] = array_merge( array( 'id' => $id ), $fields['data'][0] );
		return new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 4000 milliseconds with 0 bytes received' );
	}
);
thankyou_visit( 1468, 'wc_order_key1468' );
check( 'thank-you page: the create timed out after Zoho stored it; no record id on the order, the time of the unanswered create kept, the paid job still queued', 1 === count( zrecords() ) && '' === meta( 1468, '_lp_zoho_record_id' ) && abs( (int) meta( 1468, '_lp_zoho_create_unconfirmed' ) - time() ) <= 2 && 1 === count( zjobs( 1468 ) ) && 1 === count( zjobs( 1468, 'paid' ) ) );
$GLOBALS['lp_zoho']['search_blind'] = true; // Zoho's search does not show the new record yet.
$n0                                 = zcount();
run_zoho_jobs( null, 1468 );
check( 'the paid job right after it: the search finds nothing, but no second create; noted (attempt 1), retried in 5 minutes', 1 === count( zrecords() ) && 'GET Ledgers_Plus/search' === zsig( zcalls_since( $n0 ) ) && 1 === count( notes( 1468, 'Ledger Plus: Zoho CRM sync failed (attempt 1 of 4, background job): an earlier create got no answer from Zoho and the search does not show that record yet, so no second record is created within 3 minutes of it. Ledger Plus will try again in about 5 minutes.' ) ) && 1 === count( zjobs( 1468, 'retry' ) ), implode( ' | ', notes( 1468 ) ) );
$GLOBALS['lp_zoho']['search_blind'] = false;
$n0                                 = zcount();
run_zoho_jobs( time() + 305, 1468 );
check( '... the retry finds the record Zoho stored and uses it: still one record, Closed Opportunity, both notes; the mark is removed', 1 === count( zrecords() ) && 'found' === meta( 1468, '_lp_zoho_record_source' ) && 'Closed Opportunity' === ( zrecord_of( 1468 )['Lead_Status'] ?? '' ) && 'checkout,paid' === meta( 1468, '_lp_zoho_notes' ) && '' === meta( 1468, '_lp_zoho_create_unconfirmed' ) && 'GET Ledgers_Plus/search | PUT Ledgers_Plus | POST Notes | POST Notes' === zsig( zcalls_since( $n0 ) ) );
lp_zoho_reset();
zorder( 1469 );
inject( null, new WP_Error( 'http_request_failed', 'cURL error 52: Empty reply from server' ) );
do_action( 'lp_shop_zoho_sync', 1469, 'checkout' );
check( 'the background job\'s create gets no answer (cURL error 52): marked too', '' !== meta( 1469, '_lp_zoho_create_unconfirmed' ) && 1 === count( zjobs( 1469, 'retry' ) ) );
$GLOBALS['lp_test']['orders'][1469]['meta']['_lp_zoho_create_unconfirmed'] = time() - 181; // The retry runs 5 minutes later.
run_zoho_jobs( time() + 305, 1469 );
check( '... the retry after more than 3 minutes: Zoho had not stored it, so the record is created now; the mark is removed', 1 === count( zrecords() ) && 'created' === meta( 1469, '_lp_zoho_record_source' ) && '' === meta( 1469, '_lp_zoho_create_unconfirmed' ) );
zorder( 1478 );
inject( null, lp_zoho_json( 202, lp_zoho_item_error( 'MANDATORY_NOT_FOUND', 'required field not found', array( 'api_name' => 'Name' ) ) ) );
do_action( 'lp_shop_zoho_sync', 1478, 'checkout' );
check( 'a create Zoho clearly refused (an error for the record) is not marked', '' === meta( 1478, '_lp_zoho_create_unconfirmed' ) );

section( 'the record was deleted in Zoho' );
lp_zoho_reset();
zorder( 1466 );
do_action( 'lp_shop_zoho_sync', 1466, 'checkout' );
$old = (string) meta( 1466, '_lp_zoho_record_id' );
unset( $GLOBALS['lp_zoho']['records'][ $old ] );
wc_get_order( 1466 )->payment_complete( 'txn-1466' );
run_zoho_jobs( null, 1466 );
check( 'update answers INVALID_DATA (field id): the stored record is forgotten, the note says so', '' === meta( 1466, '_lp_zoho_record_id' ) && '' === meta( 1466, '_lp_zoho_stage' ) && '' === meta( 1466, '_lp_zoho_notes' ) && 1 === count( notes( 1466, "Zoho answered INVALID_DATA: the id given seems to be invalid (field id); the record $old no longer exists in Zoho, so the next try looks the buyer up again." ) ) && 1 === count( zjobs( 1466, 'retry' ) ) );
run_zoho_jobs( time() + 305, 1466 );
$new = (string) meta( 1466, '_lp_zoho_record_id' );
check( '... the retry looks the buyer up again and creates a new record as Closed Opportunity with both notes', '' !== $new && $new !== $old && 'Closed Opportunity' === zrecords()[ $new ]['Lead_Status'] && 2 === count( lp_zoho_notes_of( $new ) ) && 'paid' === meta( 1466, '_lp_zoho_stage' ) );
lp_zoho_reset();
zorder( 1479 );
do_action( 'lp_shop_zoho_sync', 1479, 'checkout' );
$old = (string) meta( 1479, '_lp_zoho_record_id' );
unset( $GLOBALS['lp_zoho']['records'][ $old ] );
wc_get_order( 1479 )->payment_complete( 'txn-1479' );
inject( lp_zoho_json( 202, lp_zoho_item_error( 'INVALID_DATA', 'the id given seems to be invalid', array( 'id' => $old ) ) ) ); // details {"id"}, no api_name
run_zoho_jobs( null, 1479 );
check( 'INVALID_DATA "the id given seems to be invalid" with details {"id": …} and no field name: also forgotten', '' === meta( 1479, '_lp_zoho_record_id' ) && 1 === count( notes( 1479, "Zoho answered INVALID_DATA: the id given seems to be invalid; the record $old no longer exists in Zoho, so the next try looks the buyer up again." ) ) );
run_zoho_jobs( time() + 305, 1479 );
$new = (string) meta( 1479, '_lp_zoho_record_id' );
check( '... the retry creates a new record as Closed Opportunity with both notes', '' !== $new && $new !== $old && 'Closed Opportunity' === zrecords()[ $new ]['Lead_Status'] && 2 === count( lp_zoho_notes_of( $new ) ) );
wc_get_order( 1479 )->update_status( 'on-hold' );
$GLOBALS['lp_test']['orders'][1479]['meta']['_lp_zoho_stage'] = 'open'; // As if the Lead Status had not been sent yet.
wc_get_order( 1479 )->update_status( 'processing' );
inject( lp_zoho_json( 202, lp_zoho_item_error( 'INVALID_DATA', 'invalid data', array( 'api_name' => 'Lead_Status' ) ) ) );
run_zoho_jobs( null, 1479 );
check( 'INVALID_DATA for another field (Lead_Status): the record is kept', $new === meta( 1479, '_lp_zoho_record_id' ) && 1 === count( notes( 1479, 'Zoho answered INVALID_DATA: invalid data (field Lead_Status).' ) ) );

section( 'another request is sending the order (lock)' );
zorder( 1467 );
$GLOBALS['lp_test']['options']['lp_shop_zlock_1467']    = '1';
$GLOBALS['lp_test']['options']['lp_shop_zlock_1467_at'] = (string) time();
settings_filter( array( 'lock_wait_seconds' => 0 ) );
$n0 = zcount();
do_action( 'lp_shop_zoho_sync', 1467, 'checkout' );
check( 'background job: no request, no note, no attempt counted; tries again about 60 s later, still as the checkout job (its reason kept)', $n0 === zcount() && ! zoho_notes_on_order( 1467 ) && '' === meta( 1467, '_lp_zoho_attempts' ) && 1 === count( zjobs( 1467, 'checkout' ) ) && ! zjobs( 1467, 'busy' ) && abs( zjobs( 1467, 'checkout' )[0]['time'] - ( time() + 60 ) ) <= 2 );
run_zoho_jobs( time() + 65, 1467 );
check( '... still busy a minute later: the job is replaced, not added again', 1 === count( zjobs( 1467 ) ) && 1 === count( zjobs( 1467, 'checkout' ) ) );
do_action( 'lp_shop_zoho_sync', 1467, 'busy' );
do_action( 'lp_shop_zoho_sync', 1467, 'busy' );
check( '... a retry (or busy) job that finds it busy tries again as a busy job, replaced too', 1 === count( zjobs( 1467, 'busy' ) ) && 1 === count( zjobs( 1467, 'checkout' ) ) );
do_action( 'woocommerce_order_action_lp_shop_zoho_sync', wc_get_order( 1467 ) );
check( 'order action while busy: a note, nothing sent', 1 === count( notes( 1467, 'Ledger Plus: Zoho CRM (order action): another request is sending this order to Zoho CRM right now. Nothing was changed; check the order notes again in a minute.' ) ) && $n0 === zcount() );
unset( $GLOBALS['lp_test']['options']['lp_shop_zlock_1467'], $GLOBALS['lp_test']['options']['lp_shop_zlock_1467_at'] );
settings_filter( array() );
run_zoho_jobs( time() + 130, 1467 );
check( '... once free, the job sends the order (the other one then has nothing to send)', '' !== meta( 1467, '_lp_zoho_record_id' ) && ! zjobs( 1467 ) && 1 === count( notes( 1467, 'sent to Zoho CRM' ) ) );
check( 'no Zoho lock option is left behind', ! array_filter( array_keys( $GLOBALS['lp_test']['options'] ), function ( $k ) { return 0 === strpos( $k, 'lp_shop_zlock_' ); } ) );

section( 'series of tries: a page visit or a repeated checkout never starts a new one' );
lp_zoho_reset();
unset( $GLOBALS['lp_test']['transients'] );
$GLOBALS['lp_test']['zoho_auth'] = array( 'refresh_token' => LP_FAKE_ZOHO_REFRESH ); // Not connected: every try fails.
zorder( 1500 );
wc_get_order( 1500 )->payment_complete( 'txn-1500' );
for ( $pass = 0; $pass < 5; $pass++ ) {
	run_zoho_jobs( time() + 86400, 1500 );
}
check( 'the paid job and its 3 retries fail: 4 failure notes, attempts 4, no job left', 4 === count( notes( 1500, 'Zoho CRM sync failed' ) ) && 4 === meta( 1500, '_lp_zoho_attempts' ) && ! zjobs( 1500 ) );
thankyou_visit( 1500, 'wc_order_key1500' );
check( 'the buyer opens the thank-you page again: it tries once itself, but queues no job (the tries are used up) and logs that', ! zjobs( 1500 ) && 1 === count( zlog_lines( 'Order 1500: thank-you page sync stopped: the Zoho CRM add-on is not connected to Zoho (it returned no Zoho answer; connect it again in its settings). The page was shown as usual; its automatic tries are used up (order action "Send to Zoho CRM again").' ) ) );
for ( $pass = 0; $pass < 5; $pass++ ) {
	run_zoho_jobs( time() + 86400, 1500 );
}
check( '... no new failure notes, attempts still 4', 4 === count( notes( 1500, 'Zoho CRM sync failed' ) ) && 4 === meta( 1500, '_lp_zoho_attempts' ) );
unset( $GLOBALS['lp_test']['transients'] );
zorder( 1501 );
$GLOBALS['lp_test']['orders'][1501]['meta']['_lp_zoho_attempts']   = 4; // An earlier checkout series used up its tries.
$GLOBALS['lp_test']['orders'][1501]['meta']['_lp_zoho_last_error'] = 'the Zoho CRM add-on is not connected to Zoho';
wc_get_order( 1501 )->payment_complete( 'txn-1501' );
zlock( 1501, true );
settings_filter( array( 'lock_wait_seconds' => 0 ) );
run_zoho_jobs( null, 1501 );
check( 'the payment\'s job finds another request sending the order: tried again in 60 s as the paid job (its reason kept)', 1 === count( zjobs( 1501 ) ) && 1 === count( zjobs( 1501, 'paid' ) ) && 4 === meta( 1501, '_lp_zoho_attempts' ) );
zlock( 1501, false );
run_zoho_jobs( time() + 65, 1501 );
check( '... then fails: attempt 1 of 4 of the payment\'s own series, with its retry in 5 minutes', 1 === count( notes( 1501, '(attempt 1 of 4, background job)' ) ) && 1 === meta( 1501, '_lp_zoho_attempts' ) && 1 === count( zjobs( 1501, 'retry' ) ) );
zlock( 1501, true );
run_zoho_jobs( time() + 305, 1501 );
check( 'its retry finds the order busy: tried again as a busy job', 1 === count( zjobs( 1501 ) ) && 1 === count( zjobs( 1501, 'busy' ) ) );
zlock( 1501, false );
run_zoho_jobs( time() + 370, 1501 );
check( '... which counts on: attempt 2 of 4, retry in 30 minutes', 1 === count( notes( 1501, '(attempt 2 of 4, background job)' ) ) && 1 === count( notes( 1501, 'Ledger Plus will try again in about 30 minutes.' ) ) && 2 === meta( 1501, '_lp_zoho_attempts' ) );
settings_filter( array() );
lp_zoho_reset();
zorder( 1511 );
do_action( 'woocommerce_store_api_checkout_order_processed', wc_get_order( 1511 ) );
$GLOBALS['lp_test']['orders'][1511]['status']    = 'processing'; // Paid before its checkout job ran.
$GLOBALS['lp_test']['orders'][1511]['date_paid'] = time();
inject( new WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect' ) );
thankyou_visit( 1511, 'wc_order_key1511' );
check( 'a thank-you failure while the checkout job still waits: no second job (that job sends everything)', 1 === count( zjobs( 1511 ) ) && 1 === count( zjobs( 1511, 'checkout' ) ) );
run_zoho_jobs( null, 1511 );
check( '... which it does: created as Closed Opportunity, both notes', 'Closed Opportunity' === ( zrecord_of( 1511 )['Lead_Status'] ?? '' ) && 'checkout,paid' === meta( 1511, '_lp_zoho_notes' ) && ! zjobs( 1511 ) );
unset( $GLOBALS['lp_test']['transients'] );
lp_zoho_reset();

zorder( 1502 );
$err = lp_zoho_json( 202, lp_zoho_item_error( 'INVALID_DATA', 'invalid data', array( 'api_name' => 'Mobile' ) ) );
$n0  = zcount();
for ( $pass = 0; $pass < 3; $pass++ ) {
	inject( null, $err );
	do_action( 'woocommerce_store_api_checkout_order_processed', wc_get_order( 1502 ) );
	apply_filters( 'woocommerce_payment_successful_result', array( 'result' => 'success' ), 1502 );
	run_zoho_jobs( null, 1502 );
}
$GLOBALS['lp_zoho']['inject'] = array();
check( '"Place order" and "Pay for order" three times while Zoho refuses the create: one failure note (attempt 1 of 4), attempts 1, one retry, 2 Zoho requests', 1 === count( notes( 1502, 'Zoho CRM sync failed' ) ) && 1 === count( notes( 1502, '(attempt 1 of 4, background job)' ) ) && 1 === meta( 1502, '_lp_zoho_attempts' ) && 1 === count( zjobs( 1502 ) ) && 1 === count( zjobs( 1502, 'retry' ) ) && 2 === zcount() - $n0, implode( ' | ', notes( 1502 ) ) );
inject( null, $err );
do_action( 'lp_shop_zoho_sync', 1502, 'checkout' );
check( 'a checkout job that runs while a series is under way counts on (attempt 2 of 4)', 1 === count( notes( 1502, '(attempt 2 of 4, background job)' ) ) && 2 === meta( 1502, '_lp_zoho_attempts' ) );
run_zoho_jobs( time() + 1805, 1502 );
$n0 = zcount();
do_action( 'woocommerce_store_api_checkout_order_processed', wc_get_order( 1502 ) );
check( 'the retry sends it; "Place order" again afterwards queues nothing', 'created' === meta( 1502, '_lp_zoho_record_source' ) && ! zjobs( 1502 ) && $n0 === zcount() );

section( 'site-wide hourly limit of unpaid orders (zoho_open_per_hour)' );
lp_zoho_reset();
zoho_open_reset();
settings_filter( array( 'zoho_open_per_hour' => 2 ) );
foreach ( array( 1503, 1504, 1505 ) as $id ) {
	zorder( $id );
	do_action( 'woocommerce_store_api_checkout_order_processed', wc_get_order( $id ) );
}
run_zoho_jobs();
$next = 3660 - ( time() % 3600 );
check( 'limit 2: two unpaid orders sent; the third waits for the next hour as its checkout job (no request, no note, no attempt, an info line)', 2 === count( zrecords() ) && 6 === zcount() && '' === meta( 1505, '_lp_zoho_record_id' ) && 1 === count( zjobs( 1505 ) ) && 1 === count( zjobs( 1505, 'checkout' ) ) && abs( zjobs( 1505, 'checkout' )[0]['time'] - ( time() + $next ) ) <= 2 && ! zoho_notes_on_order( 1505 ) && '' === meta( 1505, '_lp_zoho_attempts' ) && 1 === count( zlog_lines( 'info: Order 1505: not sent to Zoho CRM yet: 2 unpaid orders were sent this hour (zoho_open_per_hour); trying again in ' ) ) );
check( '... counted in one transient for this hour (lp_shop_zoho_open_YmdH = 2)', 2 === get_transient( 'lp_shop_zoho_open_' . gmdate( 'YmdH' ) ) );
zorder( 1506, 'processing' );
do_action( 'lp_shop_zoho_sync', 1506, 'paid' );
zorder( 1507 );
do_action( 'woocommerce_order_action_lp_shop_zoho_sync', wc_get_order( 1507 ) );
check( 'a paid order and the order action are not limited', 'Closed Opportunity' === ( zrecord_of( 1506 )['Lead_Status'] ?? '' ) && 'Prospecting' === ( zrecord_of( 1507 )['Lead_Status'] ?? '' ) );
zoho_open_reset();
run_zoho_jobs( time() + $next + 5, 1505 );
check( 'the next hour: the waiting order is sent', 'Prospecting' === ( zrecord_of( 1505 )['Lead_Status'] ?? '' ) && ! zjobs( 1505 ) );
settings_filter( array( 'zoho_open_per_hour' => 0 ) );
foreach ( array( 1508, 1509, 1510 ) as $id ) {
	zorder( $id );
	do_action( 'lp_shop_zoho_sync', $id, 'checkout' );
}
check( 'zoho_open_per_hour 0: no limit (three more unpaid orders sent in the same hour)', 8 === count( zrecords() ) && '' !== meta( 1510, '_lp_zoho_record_id' ) );
settings_filter( array() );
zoho_open_reset();

/* ======================================================================
 * Thank-you page
 * ====================================================================== */
section( 'thank-you page guards' );
lp_zoho_reset();
zorder( 1470, 'processing' );
zorder( 1471, 'processing' );
zorder( 1472, 'pending' );
$n0 = zcount();
thankyou_visit( 1470, 'wrong-key' );
thankyou_visit( 1470 );
thankyou_visit( 1470, 'wc_order_key1471' );
check( 'wrong key, no key, another order\'s key: no sync', $n0 === zcount() );
$GLOBALS['wp'] = (object) array( 'query_vars' => array( 'order-received' => 1470 ) );
$_GET          = array( 'key' => 'wc_order_key1470' );
do_action( 'template_redirect' );
$_GET = array();
check( 'not the order-received page: no sync', $n0 === zcount() );
thankyou_visit( 1472, 'wc_order_key1472' );
check( 'unpaid order (valid key): no sync on the page (its background job handles Prospecting)', $n0 === zcount() );
settings_filter( array( 'zoho_thankyou_budget' => 0 ) );
thankyou_visit( 1470, 'wc_order_key1470' );
settings_filter( array( 'zoho_sync' => false ) );
thankyou_visit( 1470, 'wc_order_key1470' );
settings_filter( array() );
unset( $GLOBALS['lp_test']['transients'] );
check( 'zoho_thankyou_budget 0, or the sync off: no sync', $n0 === zcount() );
thankyou_visit( 1470, 'wc_order_key1470' );
check( 'valid key on a paid order never sent: the whole sync now (search, create as Closed Opportunity, both notes)', 'GET Ledgers_Plus/search | POST Ledgers_Plus | POST Notes | POST Notes' === zsig( zcalls_since( $n0 ) ) && 'Closed Opportunity' === zrecord_of( 1470 )['Lead_Status'] );

section( 'thank-you page failures and the rate limit' );
lp_zoho_reset();
zorder( 1473, 'processing' );
$GLOBALS['lp_test']['as'] = array();
$err = new WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect' );
inject( $err );
$n0   = zcount();
$page = thankyou_visit( 1473, 'wc_order_key1473', true );
check( 'failure on the page: the page is shown as usual', false !== strpos( $page, 'lp-shop-thankyou--paid' ) && 1 === zcount() - $n0 );
check( '... no order note, no attempt counted; last error kept; a background job queued (none was pending; reason retry, so it counts on from the attempts made) and logged', ! zoho_notes_on_order( 1473 ) && '' === meta( 1473, '_lp_zoho_attempts' ) && 'the request to Zoho failed (http_request_failed: cURL error 7: Failed to connect)' === meta( 1473, '_lp_zoho_last_error' ) && 1 === count( zjobs( 1473 ) ) && 1 === count( zjobs( 1473, 'retry' ) ) && 1 === count( zlog_lines( 'warning: Order 1473: thank-you page sync stopped: the request to Zoho failed (http_request_failed: cURL error 7: Failed to connect). The page was shown as usual; a background job was queued to finish it.' ) ) );
inject( $err );
thankyou_visit( 1473, 'wc_order_key1473' );
check( 'a reload within 30 s: no second try', 1 === zcount() - $n0 );
foreach ( array( 31, 62 ) as $shift ) {
	$GLOBALS['lp_test']['time_shift'] = $shift;
	inject( $err );
	thankyou_visit( 1473, 'wc_order_key1473' );
}
$GLOBALS['lp_test']['time_shift'] = 93;
thankyou_visit( 1473, 'wc_order_key1473' );
check( 'after 30 s it tries again; at most 3 tries per order in an hour; still one job', 3 === zcount() - $n0 && 1 === count( zjobs( 1473 ) ) );
$GLOBALS['lp_test']['time_shift'] = 3700;
thankyou_visit( 1473, 'wc_order_key1473' );
$GLOBALS['lp_test']['time_shift'] = 0;
check( '... an hour later it may try again (and succeeds)', 'paid' === meta( 1473, '_lp_zoho_stage' ) && '' === meta( 1473, '_lp_zoho_last_error' ) );
$n0 = zcount();
run_zoho_jobs( null, 1473 );
check( 'the queued background job then has nothing to send', $n0 === zcount() && ! zjobs( 1473 ) );
unset( $GLOBALS['lp_test']['transients'] );

section( 'thank-you page time budget (real time, shortened settings)' );
settings_filter( array( 'zoho_thankyou_budget' => 2, 'zoho_request_timeout' => 1 ) );
lp_zoho_reset( array( 'latency' => 3.0 ) );
zorder( 1474, 'processing' );
$t    = microtime( true );
$page = thankyou_visit( 1474, 'wc_order_key1474', true );
$took = microtime( true ) - $t;
check( 'Zoho slower than the 1 s request limit: the request is cut at 1 s, the page is shown after about 1 s', 1 === count( zcalls() ) && 1.0 === (float) zcalls()[0]['timeout'] && $took >= 0.9 && $took < 1.8 && false !== strpos( $page, 'lp-shop-thankyou--paid' ), sprintf( '%.2f s, timeout %s', $took, zcalls()[0]['timeout'] ?? '?' ) );
check( '... recorded as a time-out; a background job finishes it', 0 === strpos( (string) meta( 1474, '_lp_zoho_last_error' ), 'no answer from Zoho in time (cURL error 28: Operation timed out after 1000 milliseconds' ) && 1 === count( zjobs( 1474, 'retry' ) ) );
lp_zoho_reset( array( 'latency' => 0.6 ) );
zorder( 1475, 'processing' );
$t    = microtime( true );
thankyou_visit( 1475, 'wc_order_key1475' );
$took = microtime( true ) - $t;
$rid  = (string) meta( 1475, '_lp_zoho_record_id' );
check( 'each request 0.6 s, budget 2 s, 1 s per request: search and create (1.4 s left: its full 1 s time-out), then no write with less than its 1 s left; under 2 s in all', 'GET Ledgers_Plus/search | POST Ledgers_Plus' === zsig( zcalls() ) && $took < 2.0 && isset( zrecords()[ $rid ] ) && 'Closed Opportunity' === zrecords()[ $rid ]['Lead_Status'], sprintf( '%s in %.2f s', zsig( zcalls() ), $took ) );
check( '... the create got its full time-out (1 s), not more', 1.0 === (float) zcalls()[1]['timeout'] );
check( '... logged as info ("time limit of 2 seconds"), record and stage kept, notes left to the background job', 1 === count( zlog_lines( 'info: Order 1475: thank-you page sync stopped: the time limit of 2 seconds ran out before the next Zoho request.' ) ) && 'paid' === meta( 1475, '_lp_zoho_stage' ) && '' === meta( 1475, '_lp_zoho_notes' ) );
$GLOBALS['lp_zoho']['latency'] = 0.0;
$n0                            = zcount();
run_zoho_jobs( null, 1475 );
check( '... the background job adds only the two notes', 'POST Notes | POST Notes' === zsig( zcalls_since( $n0 ) ) && 1 === count( zrecords() ) && 'checkout,paid' === meta( 1475, '_lp_zoho_notes' ) );
zorder( 1476, 'processing' );
$GLOBALS['lp_test']['options']['lp_shop_zlock_1476']    = '1';
$GLOBALS['lp_test']['options']['lp_shop_zlock_1476_at'] = (string) time();
$t  = microtime( true );
$n0 = zcount();
thankyou_visit( 1476, 'wc_order_key1476' );
$took = microtime( true ) - $t;
check( 'lock held by a background job: the page waits at most the budget less 1 s, then shows (nothing sent, a retry job queued)', $n0 === zcount() && $took < 1.6 && 1 === count( zjobs( 1476, 'retry' ) ) && 1 === count( zlog_lines( 'Order 1476: thank-you page sync skipped, another request was sending this order to Zoho CRM.' ) ), sprintf( '%.2f s', $took ) );
unset( $GLOBALS['lp_test']['options']['lp_shop_zlock_1476'], $GLOBALS['lp_test']['options']['lp_shop_zlock_1476_at'] );
settings_filter( array() );
unset( $GLOBALS['lp_test']['transients'] );
check( 'no HTTP time-out cap left on http_request_args, no status filter left on http_response after the calls', ! has_filter( 'http_request_args' ) && ! has_filter( 'http_response' ) );

section( 'thank-you page: a write starts only with its full time-out left (real time)' );
settings_filter( array( 'zoho_thankyou_budget' => 4, 'zoho_request_timeout' => 2 ) );
lp_zoho_reset( array( 'latency' => 1.4, 'late_writes' => true ) ); // A write answered too late is carried out all the same.
zorder( 1477, 'processing' );
$t = microtime( true );
thankyou_visit( 1477, 'wc_order_key1477' );
$took = microtime( true ) - $t;
check( 'each request 1.4 s, budget 4 s, 2 s per request: search, create with its full 2 s; the note (about 1.2 s left) is not started', 'GET Ledgers_Plus/search | POST Ledgers_Plus' === zsig( zcalls() ) && 2.0 === (float) zcalls()[1]['timeout'] && $took < 3.4 && 1 === count( zrecords() ) && 1 === count( zlog_lines( 'info: Order 1477: thank-you page sync stopped: the time limit of 4 seconds ran out before the next Zoho request.' ) ), sprintf( '%s in %.2f s', zsig( zcalls() ), $took ) );
$GLOBALS['lp_zoho']['latency'] = 0.0;
run_zoho_jobs( null, 1477 );
$rid = (string) meta( 1477, '_lp_zoho_record_id' );
check( '... the background job adds the two notes: one checkout note, one payment note (none twice)', array( 'Ledger Plus Shop order #1477 placed', 'Ledger Plus Shop order #1477 paid' ) === array_column( lp_zoho_notes_of( $rid ), 'Note_Title' ) && 1 === count( zrecords() ) );
settings_filter( array( 'zoho_thankyou_budget' => 2, 'zoho_request_timeout' => 3 ) );
lp_zoho_reset();
zorder( 1482, 'processing' );
thankyou_visit( 1482, 'wc_order_key1482' );
check( 'budget 2 s, 3 s per request: the search may use what is left (about 2 s); the create, which needs 3 s, is left to the background job', 'GET Ledgers_Plus/search' === zsig( zcalls() ) && (float) zcalls()[0]['timeout'] <= 2.0 && (float) zcalls()[0]['timeout'] > 1.8 && 1 === count( zjobs( 1482, 'retry' ) ) );
settings_filter( array( 'zoho_thankyou_budget' => 2, 'zoho_request_timeout' => 2 ) );
lp_zoho_reset( array( 'refresh_latency' => 0.8 ) );
zorder( 1483, 'processing' );
thankyou_visit( 1483, 'wc_order_key1483' );
$refresh = $GLOBALS['lp_test']['zoho_refreshes'];
check( 'the add-on refreshes its token first (0.8 s) in the same call: the refresh is capped at 2 s, and the API request after it only gets what is left of the 2 s budget', 1 === count( $refresh ) && (float) $refresh[0]['timeout'] <= 2.0 && (float) $refresh[0]['timeout'] > 1.8 && 1 === zcount() && (float) zcalls()[0]['timeout'] < 1.3 && (float) zcalls()[0]['timeout'] >= 0.5, json_encode( array( $refresh, array_column( zcalls(), 'timeout' ) ) ) );
check( '... the search answer after the refresh (HTTP 204) still means "no record"; nothing else started', '' === meta( 1483, '_lp_zoho_record_id' ) && 1 === count( zlog_lines( 'info: Order 1483: thank-you page sync stopped: the time limit of 2 seconds ran out before the next Zoho request.' ) ) );
settings_filter( array() );
lp_zoho_reset();
unset( $GLOBALS['lp_test']['transients'] );

/* ======================================================================
 * F12 + F14 together (Geidea)
 * ====================================================================== */
section( 'with F12: Geidea callback, then the thank-you page' );
lp_zoho_reset();
$GLOBALS['lp_test']['as'] = array();
lp_make_geidea_order( 1480 );
$GLOBALS['zb_people'][] = array( LP_FAKE_EMAIL, LP_FAKE_PHONE, 'Testbuyer', 'Mariam' );
do_action( 'woocommerce_store_api_checkout_order_processed', wc_get_order( 1480 ) );
$geidea_checks = count( array_filter( $GLOBALS['lp_test']['as'], function ( $a ) { return 'lp_shop_geidea_check' === $a['hook'] && 'pending' === $a['status']; } ) );
check( 'block checkout of a Geidea order: F12\'s 4 checks and one Zoho job', 4 === $geidea_checks && 1 === count( zjobs( 1480, 'checkout' ) ) && 5 === count( $GLOBALS['lp_test']['as'] ) );
run_zoho_jobs( null, 1480 );
check( 'the Zoho job: created as Prospecting', 'Prospecting' === zrecord_of( 1480 )['Lead_Status'] );
$n0 = zcount();
LP_Shop::reset_geidea_callback();
$_SERVER['REQUEST_METHOD'] = 'POST';
LP_Shop::handle_geidea_callback( lp_geidea_callback_body( 1480 ) );
ob_start();
echo 'Order is completed!';
do_action( 'shutdown' );
ob_end_clean();
$_SERVER['REQUEST_METHOD'] = 'GET';
check( 'signed Geidea callback: F12 completes the order; no Zoho request inside the callback request, one paid job queued', 'completed' === row( 1480 )['status'] && $n0 === zcount() && 1 === count( zjobs( 1480, 'paid' ) ) );
$GLOBALS['zb_seen_1480'] = null;
add_action( 'woocommerce_thankyou', function () { $GLOBALS['zb_seen_1480'] = zrecord_of( 1480 )['Lead_Status'] ?? null; }, 2 );
$page = thankyou_visit( 1480, 'wc_order_key1480', true );
check( 'thank-you page: Closed Opportunity before the page is rendered, which shows the paid version', 'Closed Opportunity' === $GLOBALS['zb_seen_1480'] && 'PUT Ledgers_Plus | POST Notes' === zsig( zcalls_since( $n0 ) ) && false !== strpos( $page, 'lp-shop-thankyou--paid' ) );
check( '... the payment note has Geidea\'s order id as the transaction and the order status Completed', false !== strpos( zcalls_since( $n0 )[1]['fields']['data'][0]['Note_Content'], "via Geidea Online Payments.\nTransaction: " . lp_geidea_order( 1480 )['orderId'] . "\nOrder status: Completed. Lead Status: Closed Opportunity." ) );

section( 'with F12: the thank-you page payment check completes the order in the same request' );
lp_zoho_reset();
lp_make_geidea_order( 1481 );
do_action( 'woocommerce_store_api_checkout_order_processed', wc_get_order( 1481 ) );
run_zoho_jobs( null, 1481 );
$GLOBALS['lp_test']['http_handler'] = function ( $url, $args ) {
	$GLOBALS['zb_order_of_events'][] = 'geidea lookup';
	return lp_test_http_json( 200, lp_geidea_lookup_body( array( lp_geidea_order( 1481 ) ) ) );
};
add_filter( 'itglx_elpro_zoho_post_data_before_send', function ( $fields ) { $GLOBALS['zb_order_of_events'][] = 'zoho ' . ( isset( $fields['data'][0]['Lead_Status'] ) ? 'status' : 'note' ); return $fields; } );
$GLOBALS['zb_order_of_events'] = array();
$page                          = thankyou_visit( 1481, 'wc_order_key1481', true );
check( 'F12 (priority 1) asks Geidea and completes the order, then F14 (priority 3) sets Closed Opportunity, before the page', array( 'geidea lookup', 'zoho status', 'zoho note' ) === $GLOBALS['zb_order_of_events'] && 'completed' === row( 1481 )['status'] && 'Closed Opportunity' === zrecord_of( 1481 )['Lead_Status'] && false !== strpos( $page, 'lp-shop-thankyou--paid' ), implode( ', ', $GLOBALS['zb_order_of_events'] ) );
remove_all_filters( 'itglx_elpro_zoho_post_data_before_send' );
unset( $GLOBALS['lp_test']['http_handler'] );
unset( $GLOBALS['lp_test']['transients'] );

/* ======================================================================
 * Settings in use, the fields filter, robustness
 * ====================================================================== */
section( 'settings in use' );
lp_zoho_reset();
settings_filter( array( 'zoho_trigger_workflow' => false, 'zoho_owner_id' => '', 'zoho_lead_source' => '', 'zoho_status_open' => 'Developing' ) );
zorder( 1490 );
do_action( 'lp_shop_zoho_sync', 1490, 'checkout' );
wc_get_order( 1490 )->payment_complete( 'txn-1490' );
run_zoho_jobs( null, 1490 );
$w = $GLOBALS['lp_zoho']['writes'];
check( 'zoho_trigger_workflow false: "trigger": [] on create and update; no Owner, no Lead_Source; the open status setting used', 2 === count( $w ) && array() === $w[0]['trigger'] && array() === $w[1]['trigger'] && ! isset( $w[0]['fields']['Owner'] ) && ! isset( $w[0]['fields']['Lead_Source'] ) && 'Developing' === $w[0]['fields']['Lead_Status'] );
settings_filter( array() );

section( 'filter lp_shop_zoho_record_fields' );
lp_zoho_reset();
add_filter(
	'lp_shop_zoho_record_fields',
	function ( $fields, $order, $status ) {
		$fields['Custom_Field'] = 'From the filter (' . $status . ')';
		$fields['bad key!']     = 'dropped';
		$fields['Mobile']       = array( 'not text' );
		$fields['Name']         = str_repeat( 'N', 300 );
		$fields['Description']  = str_repeat( 'D', 40000 );
		$fields['Owner']        = array( 'id' => 'not-a-number' );
		unset( $fields['Page_Name'] );
		return $fields;
	},
	10,
	3
);
zorder( 1491 );
do_action( 'lp_shop_zoho_sync', 1491, 'checkout' );
$f = $GLOBALS['lp_zoho']['writes'][0]['fields'];
check( 'the filter gets ( $fields, $order, $status ) and may add fields; invalid names, non-text values and a bad Owner are dropped; Name cut to 255, Description to 32000', 'From the filter (Prospecting)' === $f['Custom_Field'] && ! isset( $f['bad key!'] ) && ! isset( $f['Mobile'] ) && ! isset( $f['Owner'] ) && ! isset( $f['Page_Name'] ) && 255 === strlen( $f['Name'] ) && 32000 === strlen( $f['Description'] ) );
remove_all_filters( 'lp_shop_zoho_record_fields' );
add_filter( 'lp_shop_zoho_record_fields', function () { return 'not an array'; } );
zorder( 1492 );
do_action( 'lp_shop_zoho_sync', 1492, 'checkout' );
check( 'a filter that returns no array: the plugin\'s own fields are sent', 'Aisha Zohobuyer1492' === $GLOBALS['lp_zoho']['writes'][1]['fields']['Name'] );
remove_all_filters( 'lp_shop_zoho_record_fields' );
add_filter( 'lp_shop_zoho_record_fields', function () { throw new LogicException( 'filter bug for zb1493.buyer@example.com' ); } );
zorder( 1493 );
do_action( 'lp_shop_zoho_sync', 1493, 'checkout' );
check( 'a filter that throws: a failed attempt like any other (noted, scrubbed, retried), the lock released', 'Ledger Plus failed while preparing the request (LogicException: filter bug for [email])' === meta( 1493, '_lp_zoho_last_error' ) && 1 === count( zjobs( 1493, 'retry' ) ) && ! isset( $GLOBALS['lp_test']['options']['lp_shop_zlock_1493'] ) );
remove_all_filters( 'lp_shop_zoho_record_fields' );
zorder( 1494, 'processing', array( 'first_name' => '', 'last_name' => '', 'company' => '' ) );
do_action( 'lp_shop_zoho_sync', 1494, 'paid' );
check( 'no name: the company, else the email is the record Name', 'zb1494.buyer@example.com' === end( $GLOBALS['lp_zoho']['writes'] )['fields']['Name'] );

section( 'nothing here breaks checkout, payment or the thank-you page' );
$GLOBALS['lp_test']['as_throw'] = 'Action Scheduler store unavailable for zb1495.buyer@example.com';
zorder( 1495 );
$ok = true;
try {
	do_action( 'woocommerce_store_api_checkout_order_processed', wc_get_order( 1495 ) );
	$r = apply_filters( 'woocommerce_payment_successful_result', array( 'result' => 'success' ), 1495 );
	wc_get_order( 1495 )->payment_complete( 'txn-1495' );
} catch ( \Throwable $e ) {
	$ok = false;
}
$GLOBALS['lp_test']['as_throw'] = false;
check( 'Action Scheduler failing at checkout and payment: no exception escapes, the order is paid, errors logged (email in the message hidden)', $ok && array( 'result' => 'success' ) === $r && 'processing' === row( 1495 )['status'] && 3 <= count( zlog_lines( 'error: Queueing the Zoho CRM sync' ) ) && 3 <= count( zlog_lines( 'Action Scheduler store unavailable for [email]' ) ) );
check( '... caught by this plugin itself (WooCommerce\'s "Payment complete event failed" note never needed)', ! notes( 1495, 'Payment complete event failed' ) );
add_filter( 'lp_shop_zoho_record_fields', function () { throw new Error( 'fatal in a filter' ); } );
$page = thankyou_visit( 1495, 'wc_order_key1495', true );
remove_all_filters( 'lp_shop_zoho_record_fields' );
check( 'a PHP Error during the thank-you sync: the page is shown as usual', false !== strpos( $page, 'lp-shop-thankyou--paid' ) && 0 === strpos( (string) meta( 1495, '_lp_zoho_last_error' ), 'Ledger Plus failed while preparing the request (Error: fatal in a filter)' ) );
unset( $GLOBALS['lp_test']['transients'] );
zorder( 1484, 'processing' );
zlock( 1484, true );
settings_filter( array( 'lock_wait_seconds' => 0 ) );
$GLOBALS['lp_test']['as_throw'] = true;
$ok                             = true;
try {
	$page = thankyou_visit( 1484, 'wc_order_key1484', true );
} catch ( \Throwable $e ) {
	$ok = false;
}
$GLOBALS['lp_test']['as_throw'] = false;
zlock( 1484, false );
settings_filter( array() );
check( 'thank-you page while another request sends the order and Action Scheduler is down: the page is shown, the error logged', $ok && false !== strpos( $page, 'lp-shop-thankyou--paid' ) && 1 === count( zlog_lines( 'error: Thank-you page Zoho CRM sync failed: Action Scheduler store unavailable' ) ) );
unset( $GLOBALS['lp_test']['transients'] );
zorder( 1485 );
$entry = array(
	'checkout'         => array( function () { LP_Shop::on_zoho_checkout( wc_get_order( 1485 ) ); }, 'Queueing the Zoho CRM sync at checkout failed' ),
	'status change'    => array( function () { LP_Shop::on_zoho_status_changed( 1485, 'pending', 'processing' ); }, 'Queueing the Zoho CRM sync for a paid order failed' ),
	'payment complete' => array( function () { LP_Shop::on_zoho_payment_complete( 1485 ); }, 'Queueing the Zoho CRM sync after payment failed' ),
	'background job'   => array( function () { LP_Shop::run_zoho_job( 1485, 'checkout' ); }, 'Zoho CRM job failed for order 1485' ),
	'order action'     => array( function () { LP_Shop::run_manual_zoho_sync( wc_get_order( 1485 ) ); }, 'Order action "Send to Zoho CRM again" failed' ),
	'thank-you page'   => array( function () { $GLOBALS['wp'] = (object) array( 'query_vars' => array( 'order-received' => 1485 ) ); $_GET = array( 'key' => 'wc_order_key1485' ); $GLOBALS['lp_test']['order_received'] = true; LP_Shop::maybe_sync_zoho_on_thankyou(); }, 'Thank-you page Zoho CRM sync failed' ),
);
foreach ( $entry as $what => $e ) {
	remove_all_filters( 'lp_shop_settings' );
	add_filter( 'lp_shop_settings', function () { throw new RuntimeException( 'settings broken for zb1485.buyer@example.com' ); } );
	LP_Shop::reset_settings();
	$ok = true;
	try {
		$e[0]();
	} catch ( \Throwable $t ) {
		$ok = false;
	}
	$GLOBALS['lp_test']['order_received'] = false;
	$_GET                                 = array();
	settings_filter( array() );
	check( "entry point $what: a RuntimeException is caught and logged (email hidden)", $ok && 1 === count( zlog_lines( 'error: ' . $e[1] . ': settings broken for [email]' ) ) );
}

section( 'order box' );
zorder( 1496 );
check( 'an order not sent yet: "not sent yet", "none yet", no error row', array( 'Zoho CRM record' => 'not sent yet', 'Zoho CRM Lead Status sent' => 'none yet' ) === admin_rows( 1496 ) );
WC_Order::make( 1497, 'other@example.com', 'completed', array( 12 ) );
ob_start();
do_action( 'woocommerce_admin_order_data_after_billing_address', wc_get_order( 1497 ) );
$html = ob_get_clean();
check( 'an order without product 890 (and not Geidea): the box shows only the Zoho rows', false !== strpos( $html, '<h4>Ledger Plus</h4><p><strong>Zoho CRM record:</strong> not sent yet</p>' ) && false === strpos( $html, 'Trade licence' ) );
zorder( 1486 );
$GLOBALS['lp_test']['orders'][1486]['meta'] = array_merge(
	$GLOBALS['lp_test']['orders'][1486]['meta'],
	array( '_lp_zoho_record_id' => '5725767000000999999', '_lp_zoho_stage' => 'paid', '_lp_zoho_notes' => 'checkout,paid', '_lp_zoho_status_sent' => '<i>Closed</i>', '_lp_zoho_last_error' => 'x <b>bold</b> & y' )
);
ob_start();
do_action( 'woocommerce_admin_order_data_after_billing_address', wc_get_order( 1486 ) );
$html = ob_get_clean();
check( 'order box: stored values are escaped (no markup from the meta)', false === strpos( $html, '<b>' ) && false === strpos( $html, '<i>' ) && false !== strpos( $html, '&lt;i&gt;Closed&lt;/i&gt;' ) && false !== strpos( $html, 'x &lt;b&gt;bold&lt;/b&gt; &amp; y' ) );
do_action( 'woocommerce_order_action_lp_shop_zoho_sync', wc_get_order( 1497 ) );
check( 'order action on an order from before 1.2.0 (paid, no Zoho meta): created as Closed Opportunity with both notes', 'Closed Opportunity' === ( zrecord_of( 1497 )['Lead_Status'] ?? '' ) && 'checkout,paid' === meta( 1497, '_lp_zoho_notes' ) && 1 === count( notes( 1497, 'created as Closed Opportunity; checkout note added; payment note added (order action).' ) ) );
settings_filter( array( 'zoho_sync' => false ) );
check( 'sync off: no Zoho rows, no order action', array() === LP_Shop::zoho_admin_rows( wc_get_order( 1401 ) ) && ! isset( apply_filters( 'woocommerce_order_actions', array(), wc_get_order( 1401 ) )['lp_shop_zoho_sync'] ) );
$n0 = zcount();
do_action( 'lp_shop_zoho_sync', 1496, 'checkout' );
check( '... a queued job does nothing', $n0 === zcount() );
settings_filter( array() );

/* ======================================================================
 * Privacy
 * ====================================================================== */
section( 'nothing personal or secret stored or logged by the plugin' );
$stored = array();
foreach ( $GLOBALS['lp_test']['notes'] as $list ) {
	$stored = array_merge( $stored, $list );
}
$stored = array_merge( $stored, $GLOBALS['lp_test']['zlogs'] ?? array(), $GLOBALS['lp_test']['glogs'] ?? array(), $GLOBALS['lp_test']['logs'] );
foreach ( $GLOBALS['lp_test']['options'] as $k => $v ) {
	if ( 'woocommerce_geidea_settings' !== $k ) {
		$stored[] = $k . '=' . json_encode( $v );
	}
}
foreach ( $GLOBALS['lp_test']['orders'] as $r ) {
	$stored[] = json_encode( $r['meta'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); // The order's billing fields are its own data, not meta.
}
foreach ( $GLOBALS['lp_test']['transients'] ?? array() as $k => $v ) {
	$stored[] = $k . '=' . json_encode( $v );
}
foreach ( $GLOBALS['lp_test']['as'] ?? array() as $a ) {
	$stored[] = json_encode( $a['args'] );
}
$blob = implode( "\n", $stored );
$leak = array();
foreach ( $GLOBALS['zb_people'] as $p ) {
	foreach ( array( $p[0], strtoupper( $p[0] ), $p[1], preg_replace( '/\D+/', '', $p[1] ), $p[2], $p[3] ) as $value ) {
		if ( strlen( $value ) >= 4 && false !== stripos( $blob, $value ) ) {
			$leak[] = $value;
		}
	}
}
check( 'no buyer email, phone (as typed or digits only), last name or company in notes, logs, options, order meta, transients or job args', array() === $leak, implode( ', ', array_unique( $leak ) ) );
foreach ( array( 'access token' => LP_FAKE_ZOHO_ACCESS, 'refresh token' => LP_FAKE_ZOHO_REFRESH, 'API domain' => 'fake-zoho-api.example.invalid', 'auth header' => 'Zoho-oauthtoken', 'token prefix' => '1000.fake' ) as $what => $value ) {
	check( "the add-on's $what is never stored or logged", false === stripos( $blob, $value ) );
}
check( 'the plugin did write notes and logs (the scan saw them)', count( $stored ) > 200 && false !== strpos( $blob, 'sent to Zoho CRM' ) && false !== strpos( $blob, 'Zoho CRM sync failed' ) );
$sent = json_encode( array_column( zcalls(), 'fields' ) );
check( '... while Zoho itself got the buyer\'s details (that is the purpose)', false !== strpos( $sent, 'zb1491.buyer@example.com' ) && false !== strpos( $sent, 'Zoho Test Trading 1491 LLC' ) );
check( 'no error lines in the ledgerplus-geidea log', ! array_filter( $GLOBALS['lp_test']['glogs'] ?? array(), function ( $l ) { return 0 === strpos( $l, 'error:' ); } ) );

/* ======================================================================
 * Second processes
 * ====================================================================== */
foreach ( array( 'constants', 'no-addon', 'old-addon', 'order-cache' ) as $child ) {
	section( "second PHP process: $child" );
	$cmd   = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' ' . escapeshellarg( $plugin ) . ' ' . $child . ' 2>&1';
	$lines = array();
	exec( $cmd, $lines, $rc );
	foreach ( $lines as $line ) {
		if ( 0 === strpos( $line, '  PASS' ) || 0 === strpos( $line, '  FAIL' ) ) {
			echo '  ' . $line . "\n";
		}
	}
	check( "$child process: all checks passed", 0 === $rc && false !== strpos( implode( "\n", $lines ), ' 0 failed' ), implode( ' | ', array_slice( $lines, -3 ) ) );
}

finish();
