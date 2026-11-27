<?php
/**
 * Ledger Plus Shop - local test harness (pure PHP + stubs).
 * Usage: php run-tests.php [path-to-plugin-file]
 */

require __DIR__ . '/stubs.php';

$plugin = $argv[1] ?? dirname( __DIR__ ) . '/ledgerplus-shop/ledgerplus-shop.php';

// Turn every notice/warning/deprecation into a test failure.
$GLOBALS['lp_php_errors'] = array();
set_error_handler(
	function ( $no, $str, $file, $line ) {
		$GLOBALS['lp_php_errors'][] = "[$no] $str ($file:$line)";
		return true;
	}
);

require $plugin;

$GLOBALS['lp_results'] = array(
	'pass' => 0,
	'fail' => 0,
);
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
	echo "\n== $t ==\n";
}
// F11 switch for the tests: true/false forces lp_shop_settings email_branding, null = plugin default.
function lp_branding( $on ) {
	remove_all_filters( 'lp_shop_settings' );
	if ( null !== $on ) {
		add_filter(
			'lp_shop_settings',
			function ( $s ) use ( $on ) {
				$s['email_branding'] = $on;
				return $s;
			}
		);
	}
	LP_Shop::reset_settings();
}

/* ---------------- Boot ---------------- */
section( 'Boot' );
check( 'class LP_Shop defined', class_exists( 'LP_Shop', false ) );
check( 'before_woocommerce_init compat hook added (plugin variant)', lp_test_hook_count( 'before_woocommerce_init' ) === 1 );
check( 'init waits for plugins_loaded', lp_test_hook_count( 'plugins_loaded' ) === 1 && 0 === lp_test_hook_count( 'woocommerce_currency_symbol' ) );
do_action( 'plugins_loaded' );
check( 'hooks added after plugins_loaded', 1 === lp_test_hook_count( 'woocommerce_currency_symbol' ) );
check( 'paid hooks at priority 5', isset( $GLOBALS['lp_test']['filters']['woocommerce_order_status_processing'][5] ) && isset( $GLOBALS['lp_test']['filters']['woocommerce_order_status_completed'][5] ) );
check( 'fields wait for woocommerce_init', array() === $GLOBALS['lp_test']['registered'] && 1 === lp_test_hook_count( 'woocommerce_init' ) );
do_action( 'init' );
do_action( 'woocommerce_init' );
LP_Shop::bootstrap(); // second call must be a no-op.
LP_Shop::init();
check( 'bootstrap/init idempotent', 1 === lp_test_hook_count( 'woocommerce_currency_symbol' ) );

/* ---------------- F1 ---------------- */
section( 'F1 currency symbol' );
check( 'AED -> "AED"', 'AED' === apply_filters( 'woocommerce_currency_symbol', '&#x62f;.&#x625;', 'AED' ) );
check( 'USD unchanged', '$' === apply_filters( 'woocommerce_currency_symbol', '$', 'USD' ) );

/* ---------------- F2 ---------------- */
section( 'F2 checkout fields' );
$reg = $GLOBALS['lp_test']['registered'];
check( 'two fields registered', 2 === count( $reg ) );
check( 'trade licence: id/location/required', 'ledgerplus/trade-licence' === $reg[0]['id'] && 'contact' === $reg[0]['location'] && true === $reg[0]['required'] && 'Trade licence number' === $reg[0]['label'] );
check( 'TRN: id/location/optional/optionalLabel', 'ledgerplus/trn' === $reg[1]['id'] && 'contact' === $reg[1]['location'] && false === $reg[1]['required'] && 'VAT TRN (if VAT registered)' === $reg[1]['optionalLabel'] );
check( 'callbacks callable', is_callable( $reg[0]['sanitize_callback'] ) && is_callable( $reg[0]['validate_callback'] ) && is_callable( $reg[1]['sanitize_callback'] ) && is_callable( $reg[1]['validate_callback'] ) );
$allowed = array( 'maxLength', 'readOnly', 'pattern', 'autocomplete', 'autocapitalize', 'title' );
$attr_ok = true;
foreach ( $reg as $f ) {
	foreach ( array_keys( $f['attributes'] ) as $a ) {
		if ( ! in_array( $a, $allowed, true ) && 0 !== strpos( $a, 'data-' ) && 0 !== strpos( $a, 'aria-' ) ) {
			$attr_ok = false;
		}
	}
}
check( 'only attributes allowed by AbstractFieldType::process_attributes()', $attr_ok );
LP_Shop::register_checkout_fields();
check( 'fields registered once', 2 === count( $GLOBALS['lp_test']['registered'] ) );

$tl_cases = array(
	array( '  cn-1234  567 ', 'CN-1234 567', true ),
	array( "abc\t\n123", 'ABC 123', true ),
	array( '<b>dmcc</b>-12', 'DMCC-12', true ),
	array( 'CN/123.45-6', 'CN/123.45-6', true ),
	array( 'AB', 'AB', false ),
	array( 'ABC', 'ABC', true ),
	array( str_repeat( 'A', 40 ), str_repeat( 'A', 40 ), true ),
	array( str_repeat( 'A', 41 ), str_repeat( 'A', 41 ), false ),
	array( 'CN_123', 'CN_123', false ),
	array( '---', '---', false ),
	array( '١٢٣٤', '١٢٣٤', false ),
);
foreach ( $tl_cases as $c ) {
	$s = LP_Shop::sanitize_trade_licence( $c[0] );
	check( 'trade licence sanitize ' . json_encode( $c[0] ) . ' -> ' . json_encode( $c[1] ), $s === $c[1], 'got ' . json_encode( $s ) );
	$v = LP_Shop::field_validate_trade_licence( $c[0] );
	check( 'trade licence validate ' . json_encode( $c[0] ) . ' => ' . ( $c[2] ? 'valid' : 'error' ), $c[2] ? true === $v : ( $v instanceof WP_Error ), is_object( $v ) ? $v->get_error_message() : var_export( $v, true ) );
}
$req = LP_Shop::field_validate_trade_licence( '   ' );
check( 'trade licence empty -> required error', $req instanceof WP_Error && 'lp_trade_licence_required' === $req->get_error_code() );
check( 'trade licence non-scalar -> ""', '' === LP_Shop::sanitize_trade_licence( array( 'x' ) ) );
check( 'trade licence normalise', 'CN1234567' === LP_Shop::normalise_trade_licence( ' cn-1234 567 ' ) );

$trn_cases = array(
	array( '100-123-456-789-003', '100123456789003', true ),
	array( ' 100 123 456 789 003 ', '100123456789003', true ),
	array( '100123456789003', '100123456789003', true ),
	array( '', '', true ),
	array( '1001234567890', '1001234567890', false ),
	array( '1001234567890031', '1001234567890031', false ),
	array( 'abc', '', true ),                       // no digit at all: saved as empty = not VAT registered
	array( 'N/A', '', true ),
	array( 'TRN 100-123-456-789-003', '100123456789003', true ),
	array( '10012345678900X', '10012345678900', false ),
);
foreach ( $trn_cases as $c ) {
	$s = LP_Shop::sanitize_trn( $c[0] );
	check( 'TRN sanitize ' . json_encode( $c[0] ) . ' -> ' . json_encode( $c[1] ), $s === $c[1], 'got ' . json_encode( $s ) );
	$v = LP_Shop::field_validate_trn( $c[0] );
	check( 'TRN validate ' . json_encode( $c[0] ) . ' => ' . ( $c[2] ? 'valid' : 'error' ), $c[2] ? true === $v : ( $v instanceof WP_Error ) );
	// What WooCommerce really does: validate the sanitized value, and skip the callback when it is empty.
	$s2 = LP_Shop::field_sanitize_trn( $c[0] );
	$v2 = '' === $s2 ? true : LP_Shop::field_validate_trn( $s2 );
	check( 'TRN Store API flow ' . json_encode( $c[0] ) . ' => ' . ( $c[2] ? 'accepted' : 'error' ), $c[2] ? true === $v2 : ( $v2 instanceof WP_Error ) );
}
check( 'TRN validator never rejects an already-sanitized empty value', true === LP_Shop::field_validate_trn( '' ) );
check( 'old raw-input helper removed', ! method_exists( 'LP_Shop', 'is_valid_trn_input' ) );
check( 'TRN is_valid_trn on sanitized values', LP_Shop::is_valid_trn( '' ) && LP_Shop::is_valid_trn( '100123456789003' ) && ! LP_Shop::is_valid_trn( '12345' ) );

/* ---------------- F3 code generation ---------------- */
section( 'F3a code generation' );
$alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
check( 'alphabet has 32 unique chars, no I O 0 1', 32 === strlen( count_chars( $alphabet, 3 ) ) && 32 === strlen( $alphabet ) && false === strpbrk( $alphabet, 'IO01' ) && LP_Shop::CODE_ALPHABET === $alphabet );
$seen   = array();
$chars  = array();
$bad    = 0;
$n      = 20000;
for ( $i = 0; $i < $n; $i++ ) {
	$c = LP_Shop::random_code();
	if ( ! preg_match( '/^LP-[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{6}$/', $c ) || 9 !== strlen( $c ) ) {
		$bad++;
	}
	foreach ( str_split( substr( $c, 3 ) ) as $ch ) {
		$chars[ $ch ] = ( $chars[ $ch ] ?? 0 ) + 1;
	}
	$seen[ $c ] = ( $seen[ $c ] ?? 0 ) + 1;
}
$dupes = $n - count( $seen );
check( "$n codes match LP-[alphabet]{6}", 0 === $bad, "$bad bad" );
check( 'all 32 characters used', 32 === count( $chars ) );
$min = min( $chars );
$max = max( $chars );
check( 'character spread is even (min/max > 0.85)', $min / $max > 0.85, "min $min max $max" );
check( "duplicates in $n random codes <= 3 (expected ~0.19)", $dupes <= 3, "$dupes duplicates" );
check( 'looks_like_code', LP_Shop::looks_like_code( 'lp-abc234' ) && ! LP_Shop::looks_like_code( 'LP-ABCI23' ) && ! LP_Shop::looks_like_code( 'LP-ABC23' ) );

$calls = 0;
$code  = LP_Shop::generate_unique_code(
	function ( $c ) use ( &$calls ) {
		$calls++;
		return $calls <= 3; // First three already exist.
	}
);
check( 'uniqueness loop retries until free (4 calls)', 4 === $calls && LP_Shop::looks_like_code( $code ), "calls $calls code $code" );
$calls = 0;
$code  = LP_Shop::generate_unique_code(
	function ( $c ) use ( &$calls ) {
		$calls++;
		return true;
	},
	10
);
check( 'gives up after 10 attempts with ""', '' === $code && 10 === $calls );
$GLOBALS['lp_test']['coupons'][1] = array(
	'id'    => 1,
	'props' => array( 'code' => 'LP-TAKEN2', 'status' => 'publish' ),
	'meta'  => array(),
);
check( 'default exists() uses wc_get_coupon_id_by_code', 1 === wc_get_coupon_id_by_code( 'lp-taken2' ) );
unset( $GLOBALS['lp_test']['coupons'][1] );

/* ---------------- Self-referral ---------------- */
section( 'F3c/d self-referral comparison' );
$sr = array(
	array( 'Owner@Example.com', '', ' owner@example.com ', '', true, 'same email, other case/spaces' ),
	array( 'owner+lp@example.com', '', 'owner@example.com', '', true, 'plus-address' ),
	array( 'a@x.com', 'CN-123 456', 'b@y.com', 'cn123456', true, 'same trade licence, other format' ),
	array( 'a@x.com', 'CN-123', 'b@y.com', 'CN-124', false, 'different both' ),
	array( 'a@x.com', '', '', '', false, 'empty referrer' ),
	array( '', '', 'b@y.com', 'CN-1', false, 'empty buyer' ),
	array( 'a@x.com', 'CN-1', 'a@x.co', 'CN-2', false, 'similar but different email' ),
);
foreach ( $sr as $c ) {
	check( 'is_self_referral: ' . $c[5], $c[4] === LP_Shop::is_self_referral( $c[0], $c[1], $c[2], $c[3] ) );
}

/* ---------------- F5 buy-now ---------------- */
section( 'F5 buy-now validation' );
$cart_with = array(
	'k1' => array(
		'product_id' => 890,
		'quantity'   => 1,
	),
);
$ids       = array( 890, 891 );
check( 'skip when already in cart', true === LP_Shop::should_skip_duplicate_add( 890, 890, $ids, true, $cart_with, true ) );
check( 'no skip when cart empty', false === LP_Shop::should_skip_duplicate_add( 890, 890, $ids, true, array(), true ) );
check( 'no skip when not sold individually', false === LP_Shop::should_skip_duplicate_add( 890, 890, $ids, false, $cart_with, true ) );
check( 'no skip when request is for another product', false === LP_Shop::should_skip_duplicate_add( 890, 891, $ids, true, $cart_with, true ) );
check( 'no skip for product not covered', false === LP_Shop::should_skip_duplicate_add( 777, 777, $ids, true, array( array( 'product_id' => 777, 'quantity' => 1 ) ), true ) );
check( 'no skip outside the form handler', false === LP_Shop::should_skip_duplicate_add( 890, 890, $ids, true, $cart_with, false ) );
check( 'no skip when quantity 0', false === LP_Shop::should_skip_duplicate_add( 890, 890, $ids, true, array( array( 'product_id' => 890, 'quantity' => 0 ) ), true ) );

$GLOBALS['lp_test']['products'][890] = new LP_Test_Product( true );
WC()->cart                           = new WC_Cart();
WC()->cart->contents                 = $cart_with;
$_REQUEST['add-to-cart']             = '890';
$result                              = null;
add_action(
	'wp_loaded',
	function () use ( &$result ) {
		$result = apply_filters( 'woocommerce_add_to_cart_validation', true, 890, 1 );
	}
);
do_action( 'wp_loaded' );
check( 'filter returns false inside wp_loaded when 890 already in cart', false === $result );
check( 'filter adds no notice / log', array() === $GLOBALS['lp_test']['logs'] );
WC()->cart->contents = array();
do_action( 'wp_loaded' );
check( 'filter returns true when cart is empty', true === $result );
WC()->cart->contents = $cart_with;
check( 'filter untouched outside wp_loaded (e.g. Store API)', true === apply_filters( 'woocommerce_add_to_cart_validation', true, 890, 1 ) );
$GLOBALS['lp_test']['doing_ajax'] = true;
do_action( 'wp_loaded' );
check( 'filter untouched for AJAX', true === $result );
$GLOBALS['lp_test']['doing_ajax'] = false;
check( 'false stays false', false === LP_Shop::filter_buy_now_validation( false, 890, 1 ) );
unset( $_REQUEST['add-to-cart'] );
remove_all_filters( 'wp_loaded' );

/* ---------------- F3 paid-order flow ---------------- */
section( 'F3a/c/e paid-order flow (simulated WC_Order / WC_Coupon)' );
$TL = LP_Shop::FIELD_META_PREFIX . LP_Shop::FIELD_TRADE_LICENCE;

// Referrer order R.
$r = WC_Order::make( 100, 'Owner@Example.com', 'processing', array( 890 ), array(), array( $TL => 'CN-1234567' ) );
do_action( 'woocommerce_order_status_processing', 100, $r, array() );
$code_r = $r->get_meta( '_lp_referral_code' );
check( 'R: code stored on the passed order object (email sees it)', LP_Shop::looks_like_code( $code_r ), $code_r );
check( 'R: code saved to storage', $code_r === ( $GLOBALS['lp_test']['orders'][100]['meta']['_lp_referral_code'] ?? '' ) );
check( 'R: one coupon created', 1 === count( $GLOBALS['lp_test']['coupons'] ) );
$cp = reset( $GLOBALS['lp_test']['coupons'] );
check( 'coupon: fixed_cart, amount 0, product 890, not individual, no usage limit', 'fixed_cart' === $cp['props']['discount_type'] && 0 == $cp['props']['amount'] && array( 890 ) === $cp['props']['product_ids'] && false === $cp['props']['individual_use'] && 0 === $cp['props']['usage_limit'] && 'publish' === $cp['props']['status'] );
check( 'coupon: description', 'Ledger Plus referral code for order #100' === $cp['props']['description'] );
check( 'coupon meta: _lp_referral, order, email lowercase, trade licence', 'yes' === $cp['meta']['_lp_referral'] && 100 === $cp['meta']['_lp_referrer_order_id'] && 'owner@example.com' === $cp['meta']['_lp_referrer_email'] && 'CN-1234567' === $cp['meta']['_lp_referrer_trade_licence'] );
check( 'R: order note added', 1 === count( array_filter( $GLOBALS['lp_test']['notes'][100] ?? array(), function ( $n ) use ( $code_r ) { return false !== strpos( $n, $code_r ); } ) ) );
check( 'R: lock and its timestamp released', array() === array_filter( array_keys( $GLOBALS['lp_test']['options'] ), function ( $k ) { return 0 === strpos( $k, 'lp_shop_lock_' ); } ) );

// Idempotency: completed transition with the fresh object, then with a stale object.
$r2 = wc_get_order( 100 );
$r2->status = 'completed';
do_action( 'woocommerce_order_status_completed', 100, $r2, array() );
check( 'R: completed transition creates no second coupon', 1 === count( $GLOBALS['lp_test']['coupons'] ) && $code_r === $r2->get_meta( '_lp_referral_code' ) );
$stale = WC_Order::from_row( array( 'id' => 100, 'email' => 'Owner@Example.com', 'status' => 'processing', 'product_ids' => array( 890 ), 'coupons' => array(), 'meta' => array( $TL => 'CN-1234567' ), 'customer_id' => 0 ) );
LP_Shop::process_paid_order( $stale );
check( 'stale order object: code copied from storage, no new coupon', 1 === count( $GLOBALS['lp_test']['coupons'] ) && $code_r === $stale->get_meta( '_lp_referral_code' ) );
// Lost order meta but coupon exists -> reuse.
$GLOBALS['lp_test']['orders'][100]['meta'] = array( $TL => 'CN-1234567' );
$lost = wc_get_order( 100 );
LP_Shop::process_paid_order( $lost );
check( 'order meta lost: existing coupon found by _lp_referrer_order_id and reused', 1 === count( $GLOBALS['lp_test']['coupons'] ) && $code_r === $lost->get_meta( '_lp_referral_code' ) );
// Lock held by another request -> skip.
add_option( 'lp_shop_lock_100', '1' );
update_option( 'lp_shop_lock_100_at', (string) time() );
$GLOBALS['lp_test']['orders'][100]['meta'] = array( $TL => 'CN-1234567' );
$locked = wc_get_order( 100 );
check( 'locked order is skipped (no wait)', false === LP_Shop::process_paid_order( $locked ) && '' === $locked->get_meta( '_lp_referral_code' ) );
$t0 = microtime( true );
check( 'locked order is skipped after waiting', false === LP_Shop::process_paid_order( $locked, 0.6 ) );
$waited = microtime( true ) - $t0;
check( '... waited about 0.6 s (retries every 250 ms)', $waited >= 0.5 && $waited < 1.5, sprintf( '%.2f s', $waited ) );
check( 'fresh lock is not cleared', '1' === get_option( 'lp_shop_lock_100' ) );
// Stale lock (holder died more than LOCK_TTL ago): cleared, then the next attempt gets it.
update_option( 'lp_shop_lock_100_at', (string) ( time() - LP_Shop::LOCK_TTL - 5 ) );
check( 'stale lock: first attempt clears it but does not take it over', false === LP_Shop::process_paid_order( $locked ) && false === get_option( 'lp_shop_lock_100' ) );
check( 'stale lock: next attempt works', true === LP_Shop::process_paid_order( wc_get_order( 100 ) ) );
// Lock without timestamp (holder died between add_option and update_option): the clock starts.
add_option( 'lp_shop_lock_100', '1' );
delete_option( 'lp_shop_lock_100_at' );
LP_Shop::process_paid_order( wc_get_order( 100 ) );
check( 'lock without timestamp gets one, so it can expire', (int) get_option( 'lp_shop_lock_100_at' ) >= time() - 2 );
delete_option( 'lp_shop_lock_100' );
delete_option( 'lp_shop_lock_100_at' );
// A lock busy for a short time: the status hook waits, then finds the code the other request made.
add_option( 'lp_shop_lock_100', '1' );
update_option( 'lp_shop_lock_100_at', (string) time() );
add_filter( 'lp_shop_settings', function ( $s ) { $s['lock_wait_seconds'] = 3; return $s; } );
LP_Shop::reset_settings();
$GLOBALS['lp_test']['orders'][100]['meta'] = array( $TL => 'CN-1234567' );
$waiting = wc_get_order( 100 );
// The "other request" finishes about 1 s into the wait (SIGALRM): it releases the lock after saving the code.
pcntl_async_signals( true );
pcntl_signal( SIGALRM, function () use ( $code_r, $TL ) {
	$GLOBALS['lp_test']['orders'][100]['meta'] = array( $TL => 'CN-1234567', '_lp_referral_code' => $code_r, '_lp_repeat_checked' => 'yes', '_lp_trade_licence_norm' => 'CN1234567' );
	delete_option( 'lp_shop_lock_100' );
	delete_option( 'lp_shop_lock_100_at' );
} );
$GLOBALS['lp_test']['notes'] = array();
pcntl_alarm( 1 );
do_action( 'woocommerce_order_status_processing', 100, $waiting, array() );
pcntl_alarm( 0 );
check( 'busy lock: hook waited, then put the other request\'s code on its own order object (for the email)', $code_r === $waiting->get_meta( '_lp_referral_code' ) && 1 === count( $GLOBALS['lp_test']['coupons'] ) );
check( 'busy lock: no duplicate notes', array() === ( $GLOBALS['lp_test']['notes'][100] ?? array() ), implode( ' | ', $GLOBALS['lp_test']['notes'][100] ?? array() ) );
remove_all_filters( 'lp_shop_settings' );
LP_Shop::reset_settings();
LP_Shop::process_paid_order( wc_get_order( 100 ) );

// Buyer B uses R's code (typed in lower case).
$GLOBALS['lp_test']['notes'] = array();
$b = WC_Order::make( 101, 'buyer@other.ae', 'processing', array( 890 ), array( strtolower( $code_r ) ), array( $TL => 'DED-998877' ) );
do_action( 'woocommerce_order_status_processing', 101, $b, array() );
check( 'B: _lp_referred_by_code (upper case)', $code_r === $b->get_meta( '_lp_referred_by_code' ) );
check( 'B: _lp_referrer_order_id = 100', 100 === $b->get_meta( '_lp_referrer_order_id' ) );
check( 'B: status pending_review', 'pending_review' === $b->get_meta( '_lp_referral_status' ) );
check( 'B: note on buyer order', 1 === count( array_filter( $GLOBALS['lp_test']['notes'][101] ?? array(), function ( $n ) { return false !== strpos( $n, 'pending_review' ); } ) ) );
check( 'B: note on referrer order', 1 === count( array_filter( $GLOBALS['lp_test']['notes'][100] ?? array(), function ( $n ) { return false !== strpos( $n, 'order #101' ); } ) ) );
check( 'B: gets its own different code', LP_Shop::looks_like_code( $b->get_meta( '_lp_referral_code' ) ) && $b->get_meta( '_lp_referral_code' ) !== $code_r && 2 === count( $GLOBALS['lp_test']['coupons'] ) );
check( 'B: not flagged as repeat', 'yes' !== $b->get_meta( '_lp_possible_repeat' ) && 'yes' === $b->get_meta( '_lp_repeat_checked' ) );
check( 'B: no money/promise language in notes', 0 === count( array_filter( $GLOBALS['lp_test']['notes'][101], function ( $n ) { return false !== stripos( $n, 'paid to' ) || false !== stripos( $n, 'refund' ); } ) ) );
do_action( 'woocommerce_order_status_completed', 101, wc_get_order( 101 ), array() );
check( 'B: second transition adds no second referral note', 1 === count( array_filter( $GLOBALS['lp_test']['notes'][101], function ( $n ) { return false !== strpos( $n, 'Ledger Plus referral:' ); } ) ) );

// C: self-referral by email (plus address).
$c = WC_Order::make( 102, 'owner+again@example.com', 'processing', array( 890 ), array( $code_r ), array( $TL => 'NEW-1' ) );
do_action( 'woocommerce_order_status_processing', 102, $c, array() );
check( 'C: same email (plus address) -> ineligible_self', 'ineligible_self' === $c->get_meta( '_lp_referral_status' ) );

// D: self-referral by trade licence, also a repeat customer of R.
$d = WC_Order::make( 103, 'someone@else.ae', 'processing', array( 890 ), array( $code_r ), array( $TL => 'cn 1234567' ) );
do_action( 'woocommerce_order_status_processing', 103, $d, array() );
check( 'D: same trade licence (other format) -> ineligible_self', 'ineligible_self' === $d->get_meta( '_lp_referral_status' ) );
check( 'D: possible repeat of order #100', 'yes' === $d->get_meta( '_lp_possible_repeat' ) && in_array( '100', explode( ',', $d->get_meta( '_lp_possible_repeat_orders' ) ), true ) );
check( 'D: note "Possible existing customer: order #100"', 1 === count( array_filter( $GLOBALS['lp_test']['notes'][103] ?? array(), function ( $n ) { return 0 === strpos( $n, 'Possible existing customer: order #100' ); } ) ) );

// E: repeat by email of B.
$e = WC_Order::make( 104, 'BUYER@other.ae', 'processing', array( 890 ), array(), array( $TL => 'ZZZ-1' ) );
do_action( 'woocommerce_order_status_processing', 104, $e, array() );
check( 'E: possible repeat by billing email (order #101)', 'yes' === $e->get_meta( '_lp_possible_repeat' ) && in_array( '101', explode( ',', $e->get_meta( '_lp_possible_repeat_orders' ) ), true ) );

// F: order without product 890 -> nothing.
$before = count( $GLOBALS['lp_test']['coupons'] );
$f      = WC_Order::make( 105, 'x@y.ae', 'processing', array( 891 ) );
do_action( 'woocommerce_order_status_processing', 105, $f, array() );
check( 'F: order without product 890 is ignored', $before === count( $GLOBALS['lp_test']['coupons'] ) && '' === $f->get_meta( '_lp_referral_code' ) );

// Pending order (not paid) -> thank-you shows pending text, no code.
$p = WC_Order::make( 106, 'p@y.ae', 'pending', array( 890 ) );

/* ---------------- F3d coupon validity ---------------- */
section( 'F3d coupon validity (early self-referral block)' );
$coupon_r = new WC_Coupon( $code_r );
$cart     = new WC_Cart();
$cart->customer = new WC_Customer( 'OWNER@example.com' );
$ok = apply_filters( 'woocommerce_coupon_is_valid', true, $coupon_r, new WC_Discounts( $cart ) );
check( 'referrer email in session -> invalid', false === $ok );
$msg = apply_filters( 'woocommerce_coupon_error', 'Coupon is not valid.', 100, $coupon_r );
check( 'error 100 replaced with the self-referral message', false !== strpos( $msg, 'your own referral code' ), $msg );
check( 'other error codes unchanged', 'x' === apply_filters( 'woocommerce_coupon_error', 'x', 107, $coupon_r ) );
$cart->customer = new WC_Customer( 'new@buyer.ae', array( $TL => 'CN 123 4567' ) );
check( 'referrer trade licence in session -> invalid', false === apply_filters( 'woocommerce_coupon_is_valid', true, $coupon_r, new WC_Discounts( $cart ) ) );
$cart->customer = new WC_Customer( 'new@buyer.ae', array( $TL => 'OTHER-9' ) );
check( 'other buyer -> valid', true === apply_filters( 'woocommerce_coupon_is_valid', true, $coupon_r, new WC_Discounts( $cart ) ) );
$cart->applied = array( strtolower( $code_r ) );
check( 'revalidation with only this code applied -> still valid', true === apply_filters( 'woocommerce_coupon_is_valid', true, $coupon_r, new WC_Discounts( $cart ) ) );
$cart->applied = array( wc_get_order( 101 )->get_meta( '_lp_referral_code' ) );
check( 'second referral code in the same cart -> invalid', false === apply_filters( 'woocommerce_coupon_is_valid', true, $coupon_r, new WC_Discounts( $cart ) ) );
check( '... with "Only one referral code" message', false !== strpos( apply_filters( 'woocommerce_coupon_error', 'Coupon is not valid.', 100, $coupon_r ), 'Only one referral code' ) );
$cart->applied = array();
check( 'other buyer -> valid again', true === apply_filters( 'woocommerce_coupon_is_valid', true, $coupon_r, new WC_Discounts( $cart ) ) );
check( 'message cleared after a valid check', 'Coupon is not valid.' === apply_filters( 'woocommerce_coupon_error', 'Coupon is not valid.', 100, $coupon_r ) );
$GLOBALS['lp_test']['logged_in']  = true;
$GLOBALS['lp_test']['user_email'] = 'owner@example.com';
check( 'logged-in referrer account email -> invalid', false === apply_filters( 'woocommerce_coupon_is_valid', true, $coupon_r, new WC_Discounts( $cart ) ) );
$GLOBALS['lp_test']['logged_in']  = false;
$GLOBALS['lp_test']['user_email'] = '';
check( 'order context (admin) uses order billing email', false === apply_filters( 'woocommerce_coupon_is_valid', true, $coupon_r, new WC_Discounts( wc_get_order( 102 ) ) ) );
$GLOBALS['lp_test']['orders'][100]['status'] = 'refunded';
check( 'referrer order refunded -> invalid', false === apply_filters( 'woocommerce_coupon_is_valid', true, $coupon_r, new WC_Discounts( $cart ) ) );
check( '... with "no longer active" message', false !== strpos( apply_filters( 'woocommerce_coupon_error', 'Coupon is not valid.', 100, $coupon_r ), 'no longer active' ) );
$GLOBALS['lp_test']['orders'][100]['status'] = 'completed';
$plain = new WC_Coupon();
check( 'non-referral coupon untouched', true === apply_filters( 'woocommerce_coupon_is_valid', true, $plain, new WC_Discounts( $cart ) ) );
check( 'already-invalid stays invalid', false === apply_filters( 'woocommerce_coupon_is_valid', false, $coupon_r, new WC_Discounts( $cart ) ) );

/* ---------------- F3g ---------------- */
section( 'F3g coupon message' );
check( 'referral success message', 'Referral code accepted. Your price stays AED 1,999.' === apply_filters( 'woocommerce_coupon_message', 'Coupon code applied successfully.', 200, $coupon_r ) );
check( 'removed message unchanged', 'Coupon code removed successfully.' === apply_filters( 'woocommerce_coupon_message', 'Coupon code removed successfully.', 201, $coupon_r ) );
check( 'non-referral success unchanged', 'Coupon code applied successfully.' === apply_filters( 'woocommerce_coupon_message', 'Coupon code applied successfully.', 200, $plain ) );

section( 'Referral wording for common coupon errors' );
$typo = new WC_Coupon( 'lp-zzzz22' ); // does not exist: WooCommerce keeps the typed code (lower case)
$typo->set_code( 'lp-zzzz22' );
check( '105 (does not exist) -> referral wording, code in upper case', 'Referral code "LP-ZZZZ22" was not found. Please check it and try again.' === apply_filters( 'woocommerce_coupon_error', 'Coupon "lp-zzzz22" cannot be applied because it does not exist.', 105, $typo ) );
check( '109 (not applicable) on a referral code -> names the package', 'Referral code "' . $code_r . '" only works with the E-Invoicing Starter Package.' === apply_filters( 'woocommerce_coupon_error', 'Sorry, coupon "x" is not applicable to selected products.', 109, new WC_Coupon( strtolower( $code_r ) ) ) );
check( '109 on a non-referral coupon -> unchanged', 'orig' === apply_filters( 'woocommerce_coupon_error', 'orig', 109, $plain ) );
$GLOBALS['lp_test']['is_admin'] = true;
check( 'admin (order editor) -> unchanged', 'orig' === apply_filters( 'woocommerce_coupon_error', 'orig', 105, $typo ) );
$GLOBALS['lp_test']['is_admin'] = false;
add_filter( 'lp_shop_settings', function ( $s ) { $s['relabel_coupons'] = false; return $s; } );
LP_Shop::reset_settings();
check( 'relabel_coupons off -> unchanged', 'orig' === apply_filters( 'woocommerce_coupon_error', 'orig', 105, $typo ) );
remove_all_filters( 'lp_shop_settings' );
LP_Shop::reset_settings();

/* ---------------- F3b / F7 output ---------------- */
section( 'F3b/F7 output' );
ob_start();
do_action( 'woocommerce_thankyou', 101 );
$ty = ob_get_clean();
$code_b = wc_get_order( 101 )->get_meta( '_lp_referral_code' );
check( 'thank-you: What happens next + 3 steps', false !== strpos( $ty, 'What happens next' ) && false !== strpos( $ty, 'Our team contacts you to create your Ledger Plus login.' ) && false !== strpos( $ty, 'We move your data into Ledger Plus.' ) && false !== strpos( $ty, 'We book your free onboarding.' ) );
check( 'thank-you: referral sentence with code (1.1.2 default reward text and terms)', false !== strpos( $ty, 'Your referral code: <strong class="lp-shop-referral__code">' . $code_b . '</strong>. Share it with another business. When they buy Ledger Plus with your code, you can receive AED 999 cash back after their payment is confirmed. No cash back is due if their order is refunded, cancelled or disputed.</p>' ) );
check( 'thank-you: help line text + tel/wa links', 'Need help? Call or WhatsApp +971 50 342 0832.' === trim( strip_tags( preg_replace( '/.*(<p class="lp-shop-help">.*?<\/p>).*/s', '$1', $ty ) ) ) && false !== strpos( $ty, 'href="tel:+971503420832"' ) && false !== strpos( $ty, 'href="https://wa.me/971503420832"' ) );
check( 'thank-you: lp-shop-* classes', false !== strpos( $ty, 'class="lp-shop lp-shop-thankyou lp-shop-thankyou--paid"' ) && false !== strpos( $ty, 'lp-shop-next__steps' ) && false === strpos( $ty, 'lp-shop-next__intro' ) );
ob_start();
do_action( 'woocommerce_thankyou', 106 );
$ty_p = ob_get_clean();
check( 'thank-you (pending): steps introduced by "Once your payment is confirmed:"', false !== strpos( $ty_p, '<p class="lp-shop-next__intro">Once your payment is confirmed:</p>' ) && false !== strpos( $ty_p, 'Our team contacts you' ) && false !== strpos( $ty_p, 'lp-shop-thankyou--awaiting' ) );
check( 'thank-you (pending): code-later text, no code', false !== strpos( $ty_p, 'once your payment is confirmed.' ) && false === strpos( $ty_p, 'lp-shop-referral__code' ) );
$GLOBALS['lp_test']['orders'][106]['status'] = 'on-hold';
ob_start();
do_action( 'woocommerce_thankyou', 106 );
$ty_h = ob_get_clean();
check( 'thank-you (on-hold): same as pending', false !== strpos( $ty_h, 'Once your payment is confirmed:' ) && false !== strpos( $ty_h, 'lp-shop-referral--pending' ) );
foreach ( array( 'failed', 'cancelled', 'refunded' ) as $closed ) {
	$GLOBALS['lp_test']['orders'][106]['status'] = $closed;
	ob_start();
	do_action( 'woocommerce_thankyou', 106 );
	$ty_c = ob_get_clean();
	check( "thank-you ($closed): help line only, no steps, no code text", false !== strpos( $ty_c, 'lp-shop-thankyou--closed' ) && false !== strpos( $ty_c, 'Need help?' ) && false === strpos( $ty_c, 'Our team contacts you' ) && false === strpos( $ty_c, 'referral code' ) );
}
$GLOBALS['lp_test']['orders'][106]['status'] = 'pending';
ob_start();
do_action( 'woocommerce_thankyou', 105 );
check( 'thank-you (other product): nothing', '' === ob_get_clean() );
// Paid order whose code is missing (plugin activated later) -> created on view.
$late = WC_Order::make( 107, 'late@buyer.ae', 'processing', array( 890 ) );
ob_start();
do_action( 'woocommerce_thankyou', 107 );
$ty_l = ob_get_clean();
check( 'thank-you: order paid an hour ago without code gets one on view', LP_Shop::looks_like_code( wc_get_order( 107 )->get_meta( '_lp_referral_code' ) ) && false !== strpos( $ty_l, wc_get_order( 107 )->get_meta( '_lp_referral_code' ) ) );
// Just paid, code not there yet (the status hook may still be running in the webhook request).
$fresh_paid = WC_Order::make( 109, 'fresh@buyer.ae', 'processing', array( 890 ) );
$GLOBALS['lp_test']['orders'][109]['date_paid'] = time() - 5;
$coupons_before = count( $GLOBALS['lp_test']['coupons'] );
ob_start();
do_action( 'woocommerce_thankyou', 109 );
$ty_f = ob_get_clean();
check( 'thank-you: just-paid order without code -> "being prepared", no code created here', false !== strpos( $ty_f, 'being prepared' ) && $coupons_before === count( $GLOBALS['lp_test']['coupons'] ) && false !== strpos( $ty_f, 'What happens next' ) );
// The webhook request saved the code meanwhile: the page shows it (meta re-read from storage).
$GLOBALS['lp_test']['orders'][109]['meta']['_lp_referral_code'] = 'LP-WEBHK2';
$reads = $GLOBALS['lp_test']['meta_reads'] ?? 0;
ob_start();
do_action( 'woocommerce_thankyou', 109 );
$ty_f2 = ob_get_clean();
check( 'thank-you: code saved by another request is shown (meta read from storage)', false !== strpos( $ty_f2, 'LP-WEBHK2' ) && ( $GLOBALS['lp_test']['meta_reads'] ?? 0 ) > $reads );
// Lock busy when the fallback runs: no empty box, the "being prepared" text instead.
$GLOBALS['lp_test']['orders'][109]['meta']      = array();
$GLOBALS['lp_test']['orders'][109]['date_paid'] = time() - 600;
add_option( 'lp_shop_lock_109', '1' );
update_option( 'lp_shop_lock_109_at', (string) time() );
ob_start();
do_action( 'woocommerce_thankyou', 109 );
$ty_f3 = ob_get_clean();
check( 'thank-you: fallback with a busy lock -> "being prepared", not an empty box', false !== strpos( $ty_f3, 'being prepared' ) && false === strpos( $ty_f3, '<section class="lp-shop-referral"' ) );
delete_option( 'lp_shop_lock_109' );
delete_option( 'lp_shop_lock_109_at' );

$email_c  = new stdClass();
$email_c->id = 'customer_processing_order';
ob_start();
do_action( 'woocommerce_email_order_meta', wc_get_order( 101 ), false, false, $email_c );
$em = ob_get_clean();
check( 'customer email (HTML) has the sentence', false !== strpos( $em, $code_b ) && false !== strpos( $em, 'Share it with another business.' ) );
ob_start();
do_action( 'woocommerce_email_order_meta', wc_get_order( 101 ), false, true, $email_c );
$em_t = ob_get_clean();
check( 'customer email (plain) has the sentence, no tags', false !== strpos( $em_t, 'Your referral code: ' . $code_b . '.' ) && $em_t === strip_tags( $em_t ) );
$email_c->id = 'customer_completed_order';
ob_start();
do_action( 'woocommerce_email_order_meta', wc_get_order( 101 ), false, false, $email_c );
check( 'completed email also has it', false !== strpos( ob_get_clean(), $code_b ) );
$email_c->id = 'customer_invoice';
ob_start();
do_action( 'woocommerce_email_order_meta', wc_get_order( 101 ), false, false, $email_c );
check( 'other emails untouched', '' === ob_get_clean() );

$email_a     = new stdClass();
$email_a->id = 'new_order';
// The v1.0.0 admin markup (F11 email branding off); the branded card is tested in the F11 section.
lp_branding( false );
$GLOBALS['lp_test']['orders'][101]['meta'][ LP_Shop::FIELD_META_PREFIX . LP_Shop::FIELD_TRN ] = '100123456789003';
ob_start();
do_action( 'woocommerce_email_order_meta', wc_get_order( 101 ), true, false, $email_a );
$ad = ob_get_clean();
check( 'admin email: trade licence, TRN', false !== strpos( $ad, 'Trade licence:</strong> DED-998877' ) && false !== strpos( $ad, 'VAT TRN:</strong> 100123456789003' ) );
check( 'admin email: referral used + referrer order + status', false !== strpos( $ad, $code_r . ' (referrer order #100, status: pending review)' ) );
check( 'admin email: repeat flag + own code', false !== strpos( $ad, 'Possible existing customer:</strong> No' ) && false !== strpos( $ad, 'own referral code:</strong> ' . $code_b ) );
ob_start();
do_action( 'woocommerce_email_order_meta', wc_get_order( 103 ), true, true, $email_a );
$ad_t = ob_get_clean();
check( 'admin email (plain): ineligible + repeat order', false !== strpos( $ad_t, 'ineligible' ) && false !== strpos( $ad_t, 'Yes, see order #100' ) && $ad_t === strip_tags( $ad_t ) );
// On-hold order (not paid yet) with a referral code.
$h = WC_Order::make( 108, 'hold@buyer.ae', 'on-hold', array( 890 ), array( $code_r ) );
ob_start();
do_action( 'woocommerce_email_order_meta', $h, true, false, $email_a );
$ad_h = ob_get_clean();
check( 'admin email (on-hold): code read from coupon, status "checked when payment is confirmed"', false !== strpos( $ad_h, $code_r . ' (referrer order #100, status: checked when payment is confirmed)' ) && false !== strpos( $ad_h, 'Created when payment is confirmed' ) );
lp_branding( null );

$GLOBALS['lp_test']['logged_in'] = true;
$GLOBALS['lp_test']['user_id']   = 55;
$GLOBALS['lp_test']['orders'][101]['customer_id'] = 55;
ob_start();
do_action( 'woocommerce_account_dashboard' );
$acc = ob_get_clean();
check( 'My Account dashboard lists the code', false !== strpos( $acc, $code_b ) && false !== strpos( $acc, 'lp-shop-referral--account' ) );
$GLOBALS['lp_test']['logged_in'] = false;
ob_start();
do_action( 'woocommerce_account_dashboard' );
check( 'My Account: nothing when logged out', '' === ob_get_clean() );

/* ---------------- F3c follow-up: cancelled / refunded ---------------- */
section( 'F3c follow-up: cancelled and refunded orders' );
function lp_notes( $id, $needle ) {
	return count( array_filter( $GLOBALS['lp_test']['notes'][ $id ] ?? array(), function ( $n ) use ( $needle ) { return false !== strpos( $n, $needle ); } ) );
}
$GLOBALS['lp_test']['notes'] = array();
$ra = WC_Order::make( 200, 'a@company-a.ae', 'processing', array( 890 ), array(), array( $TL => 'CN-111' ) );
do_action( 'woocommerce_order_status_processing', 200, $ra, array() );
$code_a = wc_get_order( 200 )->get_meta( '_lp_referral_code' );
$rb     = WC_Order::make( 201, 'b@company-b.ae', 'processing', array( 890 ), array( $code_a ), array( $TL => 'CN-222' ) );
do_action( 'woocommerce_order_status_processing', 201, $rb, array() );
$rc = WC_Order::make( 202, 'c@company-c.ae', 'processing', array( 890 ), array( $code_a ), array( $TL => 'CN-333' ) );
do_action( 'woocommerce_order_status_processing', 202, $rc, array() );
check( 'B and C: pending_review before', 'pending_review' === wc_get_order( 201 )->get_meta( '_lp_referral_status' ) && 'pending_review' === wc_get_order( 202 )->get_meta( '_lp_referral_status' ) );
check( 'hooks registered for cancelled, refunded, fully/partially refunded', 1 === lp_test_hook_count( 'woocommerce_order_status_cancelled' ) && 1 === lp_test_hook_count( 'woocommerce_order_status_refunded' ) && 1 === lp_test_hook_count( 'woocommerce_order_fully_refunded' ) && 1 === lp_test_hook_count( 'woocommerce_order_partially_refunded' ) );

// B cancelled (the hook passes WooCommerce's own, older object).
$GLOBALS['lp_test']['orders'][201]['status'] = 'cancelled';
$b_hook = wc_get_order( 201 );
do_action( 'woocommerce_order_status_cancelled', 201, $b_hook, array() );
check( 'B cancelled -> void_cancelled in storage', 'void_cancelled' === wc_get_order( 201 )->get_meta( '_lp_referral_status' ) );
check( 'B cancelled -> mirrored onto the hook object', 'void_cancelled' === $b_hook->get_meta( '_lp_referral_status' ) );
check( 'B cancelled -> note on B and on referrer A', 1 === lp_notes( 201, 'is void. Status: void_cancelled' ) && 1 === lp_notes( 200, 'order #201, which used this customer’s code, was cancelled' ) );
do_action( 'woocommerce_order_status_cancelled', 201, wc_get_order( 201 ), array() );
check( 'B cancelled twice -> no second note', 1 === lp_notes( 201, 'is void' ) );
$ad_v = LP_Shop::admin_summary_rows( wc_get_order( 201 ) );
check( 'admin summary shows the void status', false !== strpos( $ad_v[2][1], 'void: this order was cancelled' ) );
// B paid again -> pending_review again.
$GLOBALS['lp_test']['orders'][201]['status'] = 'processing';
do_action( 'woocommerce_order_status_processing', 201, wc_get_order( 201 ), array() );
check( 'B paid again -> pending_review again, still one own coupon', 'pending_review' === wc_get_order( 201 )->get_meta( '_lp_referral_status' ) && 1 === count( array_filter( $GLOBALS['lp_test']['coupons'], function ( $c ) { return 201 === ( $c['meta']['_lp_referrer_order_id'] ?? 0 ); } ) ) );

// B fully refunded: woocommerce_order_fully_refunded first, then the "refunded" status hook with the older object.
$b_old = wc_get_order( 201 );
do_action( 'woocommerce_order_fully_refunded', 201, 9001 );
$GLOBALS['lp_test']['orders'][201]['status'] = 'refunded';
$b_old->status = 'refunded';
do_action( 'woocommerce_order_status_refunded', 201, $b_old, array() );
check( 'B refunded -> void_refunded', 'void_refunded' === wc_get_order( 201 )->get_meta( '_lp_referral_status' ) );
check( 'B refunded -> exactly one refund note on B and on A (no double from the two hooks)', 1 === lp_notes( 201, 'void_refunded' ) && 1 === lp_notes( 200, 'was refunded. Referral status: void_refunded' ) );
check( 'B refunded -> its own code marked inactive', 'refunded' === wc_get_order( 201 )->get_meta( '_lp_referral_code_inactive' ) );

// C partly refunded -> note only, status unchanged.
do_action( 'woocommerce_order_partially_refunded', 202, 9002 );
check( 'C partly refunded -> stays pending_review, notes on C and A', 'pending_review' === wc_get_order( 202 )->get_meta( '_lp_referral_status' ) && 1 === lp_notes( 202, 'partly refunded' ) && 1 === lp_notes( 200, 'order #202, which used this customer’s code, was partly refunded' ) );

// Referrer A cancelled -> code inactive note listing C (still pending), note on C; ineligible/void orders not listed.
$GLOBALS['lp_test']['orders'][200]['status'] = 'cancelled';
do_action( 'woocommerce_order_status_cancelled', 200, wc_get_order( 200 ), array() );
check( 'A cancelled -> _lp_referral_code_inactive = cancelled', 'cancelled' === wc_get_order( 200 )->get_meta( '_lp_referral_code_inactive' ) );
check( 'A cancelled -> note says the code no longer works and lists #202 only', 1 === lp_notes( 200, 'no longer works for new orders. Referred orders still pending review: #202.' ) );
check( 'A cancelled -> note on C', 1 === lp_notes( 202, 'the referrer’s order #200 was cancelled' ) && 0 === lp_notes( 201, 'the referrer’s order' ) );
check( 'A cancelled -> C keeps pending_review (a person decides)', 'pending_review' === wc_get_order( 202 )->get_meta( '_lp_referral_status' ) );
do_action( 'woocommerce_order_status_cancelled', 200, wc_get_order( 200 ), array() );
check( 'A cancelled twice -> no second note', 1 === lp_notes( 200, 'no longer works for new orders' ) );
check( 'A cancelled -> its code is refused for new orders', false === apply_filters( 'woocommerce_coupon_is_valid', true, new WC_Coupon( $code_a ), new WC_Discounts( new WC_Cart() ) ) );
// A paid again -> code active again, flag cleared, note.
$GLOBALS['lp_test']['orders'][200]['status'] = 'processing';
do_action( 'woocommerce_order_status_processing', 200, wc_get_order( 200 ), array() );
check( 'A paid again -> inactive flag cleared + note', '' === wc_get_order( 200 )->get_meta( '_lp_referral_code_inactive' ) && 1 === lp_notes( 200, 'paid again, so its referral code' ) );
check( 'no money words in any of these notes', 0 === count( array_filter( array_merge( $GLOBALS['lp_test']['notes'][200], $GLOBALS['lp_test']['notes'][201], $GLOBALS['lp_test']['notes'][202] ), function ( $n ) { return false !== stripos( $n, 'paid to' ) || false !== stripos( $n, 'cash' ); } ) ) );
check( 'order without product 890 ignored', null === LP_Shop::void_referral( 105, null, 'cancelled' ) && '' === wc_get_order( 105 )->get_meta( '_lp_referral_status' ) );

/* ---------------- F3f follow-up: admin notice for on-hold orders ---------------- */
section( 'F3f follow-up: admin notice when an on-hold order is paid' );
lp_branding( false ); // v1.0.0 body markup; the branded body is tested in the F11 section.
$mailer = WC()->mailer();
$oh     = WC_Order::make( 210, 'hold2@buyer.ae', 'on-hold', array( 890 ), array(), array( $TL => 'OH-1' ) );
$GLOBALS['lp_test']['orders'][210]['status'] = 'processing';
$oh_obj = wc_get_order( 210 );
do_action( 'woocommerce_order_status_processing', 210, $oh_obj, array() );
do_action( 'woocommerce_order_status_changed', 210, 'on-hold', 'processing', $oh_obj );
$code_oh = $oh_obj->get_meta( '_lp_referral_code' );
check( 'one admin notice sent to the New order recipient', 1 === count( $mailer->sent ) && 'shop@ledgerplus.ae' === $mailer->sent[0]['to'] );
check( '... subject and checks with the buyer’s new code', false !== strpos( $mailer->sent[0]['subject'], 'Payment confirmed for order #210' ) && false !== strpos( $mailer->sent[0]['message'], 'own referral code:</strong> ' . $code_oh ) && false !== strpos( $mailer->sent[0]['message'], 'Trade licence:</strong> OH-1' ) );
check( '... flag saved', 'yes' === wc_get_order( 210 )->get_meta( '_lp_admin_paid_notice_sent' ) );
do_action( 'woocommerce_order_status_changed', 210, 'on-hold', 'completed', wc_get_order( 210 ) );
check( 'not sent twice', 1 === count( $mailer->sent ) );
do_action( 'woocommerce_order_status_changed', 101, 'pending', 'processing', wc_get_order( 101 ) );
check( 'not sent for pending -> processing (the New order email already had the checks)', 1 === count( $mailer->sent ) );
$mailer->new_order->enabled = false;
WC_Order::make( 211, 'hold3@buyer.ae', 'processing', array( 890 ) );
do_action( 'woocommerce_order_status_changed', 211, 'on-hold', 'processing', wc_get_order( 211 ) );
check( 'not sent when the New order email is disabled', 1 === count( $mailer->sent ) );
$mailer->new_order->enabled = true;
WC_Order::make( 212, 'hold4@buyer.ae', 'processing', array( 891 ) );
do_action( 'woocommerce_order_status_changed', 212, 'on-hold', 'processing', wc_get_order( 212 ) );
check( 'not sent for orders without product 890', 1 === count( $mailer->sent ) );
lp_branding( null );

/* ---------------- F3a duplicate reconciliation ---------------- */
section( 'F3a duplicate coupon reconciliation' );
// Another request created an older coupon for the same order between our lookup and our save.
$rival_cb = function ( $id, $coupon ) {
	if ( 300 === ( $coupon->get_meta( '_lp_referrer_order_id' ) ) && empty( $GLOBALS['lp_rival_done'] ) ) {
		$GLOBALS['lp_rival_done']        = true;
		$GLOBALS['lp_test']['coupons'][4000] = array(
			'id'    => 4000,
			'props' => array( 'code' => 'LP-RVAL22', 'status' => 'publish', 'discount_type' => 'fixed_cart', 'amount' => 0, 'individual_use' => false, 'product_ids' => array( 890 ), 'usage_limit' => 0, 'usage_limit_per_user' => 0, 'description' => '' ),
			'meta'  => array( '_lp_referral' => 'yes', '_lp_referrer_order_id' => 300 ),
		);
	}
};
add_action( 'lp_test_coupon_created', $rival_cb, 10, 2 );
$o300 = WC_Order::make( 300, 'race@buyer.ae', 'processing', array( 890 ) );
do_action( 'woocommerce_order_status_processing', 300, $o300, array() );
$live = array_filter( $GLOBALS['lp_test']['coupons'], function ( $c ) { return 300 === ( $c['meta']['_lp_referrer_order_id'] ?? 0 ) && 'publish' === $c['props']['status']; } );
check( 'older rival kept, ours trashed: one live coupon for the order', 1 === count( $live ) && isset( $live[4000] ) );
check( 'order and its email show the kept code', 'LP-RVAL22' === $o300->get_meta( '_lp_referral_code' ) && 'LP-RVAL22' === wc_get_order( 300 )->get_meta( '_lp_referral_code' ) );
check( 'note about the duplicate', 1 === lp_notes( 300, 'Kept LP-RVAL22 and moved 1 duplicate(s) to the trash' ) );
check( 'trashed code cannot be applied', 0 === wc_get_coupon_id_by_code( strtolower( (string) array_values( array_filter( $GLOBALS['lp_test']['coupons'], function ( $c ) { return 300 === ( $c['meta']['_lp_referrer_order_id'] ?? 0 ) && 'trash' === $c['props']['status']; } ) )[0]['props']['code'] ) ) );
remove_all_filters( 'lp_test_coupon_created' );

/* ---------------- F4 ---------------- */
section( 'F4 coupon wording' );
$GLOBALS['lp_test']['is_checkout'] = true;
do_action( 'wp_enqueue_scripts' );
$inline = $GLOBALS['lp_test']['inline'];
check( 'inline script attached once to wp-hooks "after"', 1 === count( $inline ) && 'wp-hooks' === $inline[0][0] && 'after' === $inline[0][2] && in_array( 'wp-hooks', $GLOBALS['lp_test']['scripts'], true ) );
do_action( 'wp_enqueue_scripts' );
check( 'not added twice', 1 === count( $GLOBALS['lp_test']['inline'] ) );
file_put_contents( __DIR__ . '/out-inline.js', $inline[0][1] );
$GLOBALS['lp_test']['is_checkout'] = false;

do_action( 'wp' );
check( 'classic: not on cart/checkout -> unchanged', 'Have a coupon?' === apply_filters( 'gettext_woocommerce', 'Have a coupon?', 'Have a coupon?', 'woocommerce' ) );
$GLOBALS['lp_test']['is_cart'] = true;
check( 'classic: "Have a coupon?"', 'Have a referral code?' === apply_filters( 'gettext_woocommerce', 'Have a coupon?', 'Have a coupon?', 'woocommerce' ) );
check( 'classic: "Coupon code"', 'Referral code' === apply_filters( 'gettext_woocommerce', 'Coupon code', 'Coupon code', 'woocommerce' ) );
check( 'classic: "Apply coupon"', 'Apply' === apply_filters( 'gettext_woocommerce', 'Apply coupon', 'Apply coupon', 'woocommerce' ) );
check( 'classic: other strings unchanged', 'Proceed to checkout' === apply_filters( 'gettext_woocommerce', 'Proceed to checkout', 'Proceed to checkout', 'woocommerce' ) );
$GLOBALS['lp_test']['is_admin'] = true;
check( 'classic: admin unchanged', 'Coupon code' === apply_filters( 'gettext_woocommerce', 'Coupon code', 'Coupon code', 'woocommerce' ) );
$GLOBALS['lp_test']['is_admin'] = false;
$GLOBALS['lp_test']['is_cart']  = false;
$GLOBALS['lp_test']['store_api'] = true;
check( 'Store API: invalid code message', '"%s" is not a valid referral code. Please check it and try again.' === apply_filters( 'gettext_woocommerce', '"%s" is an invalid coupon code.', '"%s" is an invalid coupon code.', 'woocommerce' ) );
check( 'Store API: classic strings not touched in REST', 'Coupon code' === apply_filters( 'gettext_woocommerce', 'Coupon code', 'Coupon code', 'woocommerce' ) );
$GLOBALS['lp_test']['store_api'] = false;
check( 'non-Store API: invalid code message unchanged', '"%s" is an invalid coupon code.' === apply_filters( 'gettext_woocommerce', '"%s" is an invalid coupon code.', '"%s" is an invalid coupon code.', 'woocommerce' ) );

/* ---------------- F6 ---------------- */
section( 'F6 product page redirect' );
function lp_try_redirect() {
	$GLOBALS['lp_test']['redirects'] = array();
	try {
		do_action( 'template_redirect' );
	} catch ( LP_Test_Redirect $e ) {
		return $e->getMessage();
	}
	return null;
}
$GLOBALS['lp_test']['is_singular']      = true;
$GLOBALS['lp_test']['queried_id']       = 890;
$GLOBALS['lp_test']['permalinks'][892]  = 'https://ledgerplus.ae/e-invoicing-starter/';
$GLOBALS['lp_test']['post_status'][892] = 'draft';
check( 'no redirect while page 892 is draft', null === lp_try_redirect() );
$GLOBALS['lp_test']['post_status'][892] = 'publish';
$_GET = array( 'utm_source' => 'gn', 'other' => 'x' );
$to   = lp_try_redirect();
check( 'redirect 302 to page 892 when published (keeps utm_*)', 'https://ledgerplus.ae/e-invoicing-starter/?utm_source=gn' === $to && 302 === ( $GLOBALS['lp_test']['redirects'][0][1] ?? 0 ), (string) $to );
$_GET = array( 'lp_stay' => '1' );
$GLOBALS['lp_test']['caps'] = array( 'manage_woocommerce' );
check( 'shop manager with ?lp_stay=1 stays', null === lp_try_redirect() );
$_GET = array( 'lp_stay' => array( '1' ) );
$errs_before = count( $GLOBALS['lp_php_errors'] );
check( 'shop manager with ?lp_stay[]=1 is redirected, no PHP warning', null !== lp_try_redirect() && count( $GLOBALS['lp_php_errors'] ) === $errs_before, implode( ' | ', array_slice( $GLOBALS['lp_php_errors'], $errs_before ) ) );
$GLOBALS['lp_test']['caps'] = array();
check( 'visitor with ?lp_stay[]=1: no PHP warning', null !== lp_try_redirect() && count( $GLOBALS['lp_php_errors'] ) === $errs_before );
$_GET = array( 'lp_stay' => '1' );
check( 'visitor with ?lp_stay=1 is still redirected', null !== lp_try_redirect() );
$_GET = array();
$_REQUEST['add-to-cart'] = '890';
check( 'add-to-cart request not redirected', null === lp_try_redirect() );
unset( $_REQUEST['add-to-cart'] );
$GLOBALS['lp_test']['is_preview'] = true;
check( 'preview not redirected', null === lp_try_redirect() );
$GLOBALS['lp_test']['is_preview'] = false;
$GLOBALS['lp_test']['is_feed']    = true;
check( 'feed not redirected', null === lp_try_redirect() );
$GLOBALS['lp_test']['is_feed']    = false;
$GLOBALS['lp_test']['doing_ajax'] = true;
check( 'ajax not redirected', null === lp_try_redirect() );
$GLOBALS['lp_test']['doing_ajax'] = false;
$GLOBALS['lp_test']['is_admin']   = true;
check( 'admin not redirected', null === lp_try_redirect() );
$GLOBALS['lp_test']['is_admin']   = false;
$GLOBALS['lp_test']['store_api']  = true;
check( 'REST not redirected', null === lp_try_redirect() );
$GLOBALS['lp_test']['store_api']  = false;
$GLOBALS['lp_test']['queried_id'] = 891;
check( 'other product not redirected', null === lp_try_redirect() );
$GLOBALS['lp_test']['is_singular'] = false;

/* ---------------- F11 ---------------- */
section( 'F11 email branding' );
// FeaturesUtil stand-in (src/Utilities/FeaturesUtil.php) so the email_improvements check can be switched.
if ( ! class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
	eval( 'namespace Automattic\WooCommerce\Utilities; class FeaturesUtil { public static $on = array( "email_improvements" => true ); public static function feature_is_enabled( $id ) { return ! empty( self::$on[ $id ] ); } }' ); // phpcs:ignore
}
lp_branding( null );
$s11 = LP_Shop::settings();
check( 'setting email_branding defaults to true', true === $s11['email_branding'] );
check( 'woocommerce_email_styles filter added once (priority 20)', 1 === lp_test_hook_count( 'woocommerce_email_styles' ) && isset( $GLOBALS['lp_test']['filters']['woocommerce_email_styles'][20] ) );
check( 'woocommerce_email_order_items_args filter added once', 1 === lp_test_hook_count( 'woocommerce_email_order_items_args' ) );
LP_Shop::init();
check( 'init() again does not add the email filters twice', 1 === lp_test_hook_count( 'woocommerce_email_styles' ) && 1 === lp_test_hook_count( 'woocommerce_email_order_items_args' ) );

$wc_css   = "body { background-color: #f6f8fd; }\n";
$em_obj   = new stdClass();
$em_obj->id = 'customer_processing_order';
$css1     = apply_filters( 'woocommerce_email_styles', $wc_css, $em_obj );
check( 'CSS appended after WooCommerce\'s CSS', 0 === strpos( $css1, $wc_css ) && strlen( $css1 ) > strlen( $wc_css ) + 1000 );
check( 'CSS carries the marker once', 1 === substr_count( $css1, LP_Shop::EMAIL_CSS_MARKER ) );
$css2 = apply_filters( 'woocommerce_email_styles', $css1, $em_obj );
check( 'CSS appended once even when the filter runs twice', $css1 === $css2 && 1 === substr_count( $css2, '#inner_wrapper {' ) );
$plain_css = LP_Shop::email_css();
check( 'CSS: braces balanced', substr_count( $plain_css, '{' ) === substr_count( $plain_css, '}' ) );
check( 'CSS: one mobile media query', 1 === substr_count( $plain_css, '@media screen and (max-width: 600px)' ) );
check( 'CSS: no :is() / :has() / CSS variables / flex / grid', ! preg_match( '/:is\(|:has\(|var\(|--[a-z]|display:\s*(flex|grid|inline-flex)|\bgrid-|\bflex-/i', $plain_css ) );
check( 'CSS: no web font, no @import, no url()', false === stripos( $plain_css, 'DM Sans' ) && false === stripos( $plain_css, '@import' ) && false === stripos( $plain_css, 'url(' ) );
\Automattic\WooCommerce\Utilities\FeaturesUtil::$on['email_improvements'] = false;
check( 'email improvements off -> CSS unchanged (legacy templates keep their look)', $wc_css === apply_filters( 'woocommerce_email_styles', $wc_css, $em_obj ) );
\Automattic\WooCommerce\Utilities\FeaturesUtil::$on['email_improvements'] = true;
$em_block = new stdClass();
$em_block->id = 'customer_processing_order';
$em_block->block_email_editor_enabled = true;
check( 'block editor email -> CSS unchanged', $wc_css === apply_filters( 'woocommerce_email_styles', $wc_css, $em_block ) );
check( 'non-string CSS passed through', null === LP_Shop::filter_email_styles( null, $em_obj ) );
add_filter( 'lp_shop_email_css', function ( $c ) { return $c . "\n.lp-extra { color: #000; }"; } );
check( 'lp_shop_email_css filter can extend the CSS', false !== strpos( apply_filters( 'woocommerce_email_styles', $wc_css, $em_obj ), '.lp-extra' ) );
remove_all_filters( 'lp_shop_email_css' );
lp_branding( false );
check( 'setting off -> CSS unchanged', $wc_css === apply_filters( 'woocommerce_email_styles', $wc_css, $em_obj ) );
lp_branding( null );

// Order item thumbnails.
$img_item = new class() {
	public $img = 0;
	public function get_product() {
		$img = $this->img;
		return new class( $img ) {
			private $i;
			public function __construct( $i ) {
				$this->i = $i;
			}
			public function get_image_id() {
				return $this->i;
			}
		};
	}
};
$args = array( 'show_image' => true, 'items' => array( $img_item ) );
check( 'no product image -> no thumbnail column', false === apply_filters( 'woocommerce_email_order_items_args', $args )['show_image'] );
$img_item->img = 77;
check( 'a product image -> thumbnails kept', true === apply_filters( 'woocommerce_email_order_items_args', $args )['show_image'] );
$img_item->img = 0;
lp_branding( false );
check( 'setting off -> thumbnail args untouched', true === apply_filters( 'woocommerce_email_order_items_args', $args )['show_image'] );
lp_branding( null );

// Customer emails.
$em_p     = new stdClass();
$em_p->id = 'customer_processing_order';
ob_start();
do_action( 'woocommerce_email_order_meta', wc_get_order( 101 ), false, false, $em_p );
$p_html = ob_get_clean();
check( 'processing (HTML): "What happens next" card with the 3 steps', false !== strpos( $p_html, 'lp-email-next' ) && false !== strpos( $p_html, 'Our team contacts you to create your Ledger Plus login.' ) && false !== strpos( $p_html, 'We move your data into Ledger Plus.' ) && false !== strpos( $p_html, 'We book your free onboarding.' ) );
check( 'processing (HTML): help line with tel: and WhatsApp links', false !== strpos( $p_html, 'href="tel:+971503420832"' ) && false !== strpos( $p_html, 'href="https://wa.me/971503420832"' ) );
check( 'processing (HTML): referral card with the code on its own, then the reward text', false !== strpos( $p_html, 'lp-email-referral' ) && false !== strpos( $p_html, 'data-lp-code="' . $code_b . '"' ) && false !== strpos( $p_html, '>' . $code_b . '</td>' ) && false !== strpos( $p_html, 'Share it with another business. When they buy Ledger Plus with your code, you can receive AED 999 cash back after their payment is confirmed. <span style="color:#5b6684;">No cash back is due if their order is refunded, cancelled or disputed.</span>' ) );
check( 'processing (HTML): the default reward-terms placeholder is not shown to customers', false === strpos( $p_html, 'Reward terms' ) && false === strpos( $p_html, '[' ) );
check( 'processing (HTML): steps before the code; v1.0.0 box gone', strpos( $p_html, 'lp-email-next' ) < strpos( $p_html, 'lp-email-referral' ) && false === strpos( $p_html, 'lp-shop-email-referral' ) );
check( 'processing (HTML): tables with role=presentation, no <div>/<section>/<h2>', false !== strpos( $p_html, 'role="presentation"' ) && false === strpos( $p_html, '<div' ) && false === strpos( $p_html, '<section' ) && false === strpos( $p_html, '<h2' ) );
check( 'processing (HTML): font set on the cells (Outlook)', substr_count( $p_html, 'font-family:' ) >= 8 );
ob_start();
do_action( 'woocommerce_email_order_meta', wc_get_order( 101 ), false, true, $em_p );
$p_txt = ob_get_clean();
check( 'processing (plain): steps then the referral sentence, no tags', false !== strpos( $p_txt, 'WHAT HAPPENS NEXT' ) && false !== strpos( $p_txt, '1. Our team contacts you' ) && false !== strpos( $p_txt, '3. We book your free onboarding.' ) && strpos( $p_txt, 'WHAT HAPPENS NEXT' ) < strpos( $p_txt, 'Your referral code: ' . $code_b . '.' ) && $p_txt === strip_tags( $p_txt ) );
$em_p->id = 'customer_completed_order';
ob_start();
do_action( 'woocommerce_email_order_meta', wc_get_order( 101 ), false, false, $em_p );
$c_html = ob_get_clean();
check( 'completed (HTML, Processing email with the steps already built): referral card, no steps', false !== strpos( $c_html, 'lp-email-referral' ) && false !== strpos( $c_html, $code_b ) && false === strpos( $c_html, 'lp-email-next' ) );
ob_start();
do_action( 'woocommerce_email_order_meta', wc_get_order( 101 ), false, true, $em_p );
$c_txt = ob_get_clean();
check( 'completed (plain, Processing email with the steps already built): the referral sentence with the 1.1.2 terms only', "\n" . 'Your referral code: ' . $code_b . '. Share it with another business. When they buy Ledger Plus with your code, you can receive AED 999 cash back after their payment is confirmed. No cash back is due if their order is refunded, cancelled or disputed.' . "\n\n" === $c_txt );
// Processing order whose code is not there yet: steps only.
$nc = WC_Order::make( 230, 'nocode@buyer.ae', 'processing', array( 890 ) );
$em_p->id = 'customer_processing_order';
ob_start();
do_action( 'woocommerce_email_order_meta', $nc, false, false, $em_p );
$nc_html = ob_get_clean();
check( 'processing without a code yet: steps, no referral card', false !== strpos( $nc_html, 'lp-email-next' ) && false === strpos( $nc_html, 'lp-email-referral' ) );
$em_p->id = 'customer_invoice';
ob_start();
do_action( 'woocommerce_email_order_meta', wc_get_order( 101 ), false, false, $em_p );
check( 'other emails untouched', '' === ob_get_clean() );
// Escaping of the settings texts.
add_filter( 'lp_shop_settings', function ( $s ) { $s['reward_text'] = 'you get <b>AED 999</b> & more.'; return $s; } );
LP_Shop::reset_settings();
$esc = LP_Shop::email_referral_card_html( 'LP-AB"C<1' );
check( 'card escapes the code and the reward text', false !== strpos( $esc, 'you get &lt;b&gt;AED 999&lt;/b&gt; &amp; more.' ) && false !== strpos( $esc, 'LP-AB&quot;C&lt;1' ) && false === strpos( $esc, '<b>AED' ) );
lp_branding( null );

// Admin card.
$em_a2     = new stdClass();
$em_a2->id = 'new_order';
ob_start();
do_action( 'woocommerce_email_order_meta', wc_get_order( 101 ), true, false, $em_a2 );
$a_html = ob_get_clean();
check( 'admin (HTML): checks card with label / value cells', false !== strpos( $a_html, 'lp-email-checks' ) && false !== strpos( $a_html, '>Trade licence</td>' ) && false !== strpos( $a_html, '>DED-998877</td>' ) && false !== strpos( $a_html, '>VAT TRN</td>' ) && false !== strpos( $a_html, '>100123456789003</td>' ) );
check( 'admin (HTML): referral used + status, own code', false !== strpos( $a_html, '>' . $code_r . ' (referrer order #100, status: pending review)</td>' ) && false !== strpos( $a_html, '>' . $code_b . '</td>' ) && false === strpos( $a_html, '#b42318' ) );
ob_start();
do_action( 'woocommerce_email_order_meta', wc_get_order( 103 ), true, false, $em_a2 );
$a_rep = ob_get_clean();
check( 'admin (HTML): possible existing customer shown in red', false !== strpos( $a_rep, 'color:#b42318;">Yes, see order #100</td>' ) );
ob_start();
do_action( 'woocommerce_email_order_meta', wc_get_order( 103 ), true, true, $em_a2 );
check( 'admin (plain): unchanged by F11', $ad_t === ob_get_clean() );

// WooCommerce email preview: dummy order without product 890.
$dummy = WC_Order::make( 231, 'dummy@example.com', 'processing', array( 12 ) );
$em_p->id = 'customer_processing_order';
ob_start();
do_action( 'woocommerce_email_order_meta', $dummy, false, false, $em_p );
check( 'order without product 890, not a preview -> nothing', '' === ob_get_clean() );
add_filter( 'woocommerce_is_email_preview', function () { return true; } );
ob_start();
do_action( 'woocommerce_email_order_meta', $dummy, false, false, $em_p );
$pv = ob_get_clean();
check( 'email preview -> sample cards with ' . LP_Shop::SAMPLE_CODE, false !== strpos( $pv, 'lp-email-next' ) && false !== strpos( $pv, LP_Shop::SAMPLE_CODE ) );
ob_start();
do_action( 'woocommerce_email_order_meta', $dummy, true, false, $em_a2 );
check( 'email preview (admin) -> checks card', false !== strpos( ob_get_clean(), 'lp-email-checks' ) );
lp_branding( false );
ob_start();
do_action( 'woocommerce_email_order_meta', $dummy, false, false, $em_p );
check( 'email preview with branding off -> nothing (v1.0.0)', '' === ob_get_clean() );
lp_branding( null );
remove_all_filters( 'woocommerce_is_email_preview' );

// Branding off = the v1.0.0 markup, byte for byte.
lp_branding( false );
$em_p->id = 'customer_processing_order';
ob_start();
do_action( 'woocommerce_email_order_meta', wc_get_order( 101 ), false, false, $em_p );
$off = ob_get_clean();
check( 'setting off -> v1.0.0 referral box exactly', '<div class="lp-shop-email-referral" style="margin:0 0 24px;padding:16px 20px;border:1px solid #dde5f4;border-radius:12px;background:#eaf1fd;color:#030922;"><p style="margin:0;">' . LP_Shop::referral_sentence_html( $code_b ) . '</p></div>' === $off );
ob_start();
do_action( 'woocommerce_email_order_meta', wc_get_order( 101 ), false, true, $em_p );
check( 'setting off -> no steps in plain text', false === strpos( ob_get_clean(), 'WHAT HAPPENS NEXT' ) );
lp_branding( null );

// "Payment confirmed" admin email with the branded card.
$mailer11 = WC()->mailer();
$sent0    = count( $mailer11->sent );
$oh11     = WC_Order::make( 232, 'hold11@buyer.ae', 'on-hold', array( 890 ), array(), array( $TL => 'OH-11' ) );
$GLOBALS['lp_test']['orders'][232]['status'] = 'processing';
$oh11_obj = wc_get_order( 232 );
do_action( 'woocommerce_order_status_processing', 232, $oh11_obj, array() );
do_action( 'woocommerce_order_status_changed', 232, 'on-hold', 'processing', $oh11_obj );
$msg11 = $mailer11->sent[ $sent0 ]['message'] ?? '';
check( '"Payment confirmed" email uses the checks card', count( $mailer11->sent ) === $sent0 + 1 && false !== strpos( $msg11, 'lp-email-checks' ) && false !== strpos( $msg11, '>OH-11</td>' ) && false === strpos( $msg11, '<ul>' ) );

// F11 review fixes.
section( 'F11 review fixes' );
lp_branding( null );
// Reward terms: placeholders hidden in the branded emails only; real terms shown.
check( 'v1.0.0 sentence (thank-you page, My Account) carries the configured terms (1.1.2 default)', false !== strpos( LP_Shop::referral_sentence_html( 'LP-X' ), 'No cash back is due if their order is refunded, cancelled or disputed.' ) );
check( 'email_reward_terms(): 1.1.2 default terms are real text -> shown', 'No cash back is due if their order is refunded, cancelled or disputed.' === LP_Shop::email_reward_terms() );
add_filter( 'lp_shop_settings', function ( $s ) { $s['reward_terms'] = '[Reward terms: to confirm]'; return $s; } );
LP_Shop::reset_settings();
check( 'email_reward_terms(): the 1.1.1 default placeholder -> empty', '' === LP_Shop::email_reward_terms() );
lp_branding( null );
add_filter( 'lp_shop_settings', function ( $s ) { $s['reward_terms'] = ' [Terms v2] '; return $s; } );
LP_Shop::reset_settings();
check( 'email_reward_terms(): any text wholly in [brackets] -> empty', '' === LP_Shop::email_reward_terms() && false === strpos( LP_Shop::email_referral_card_html( 'LP-X' ), 'Terms v2' ) );
lp_branding( null );
add_filter( 'lp_shop_settings', function ( $s ) { $s['reward_terms'] = 'Terms & conditions: ledgerplus.ae/referral-terms'; return $s; } );
LP_Shop::reset_settings();
$rt_card = LP_Shop::email_referral_card_html( 'LP-X' );
check( 'real terms -> shown (escaped) in the card', false !== strpos( $rt_card, '<span style="color:#5b6684;">Terms &amp; conditions: ledgerplus.ae/referral-terms</span>' ) );
$em_rt     = new stdClass();
$em_rt->id = 'customer_completed_order';
ob_start();
do_action( 'woocommerce_email_order_meta', wc_get_order( 101 ), false, true, $em_rt );
check( 'real terms -> shown in the plain-text email', false !== strpos( ob_get_clean(), 'after their payment is confirmed. Terms &amp; conditions: ledgerplus.ae/referral-terms' ) );
lp_branding( null );

// Card markup: spacing as cell padding (not table margins), line breaks at row boundaries.
$cards = LP_Shop::email_next_steps_html() . LP_Shop::email_referral_card_html( 'LP-HG4HG7' ) . LP_Shop::email_checks_card_html( wc_get_order( 103 ), LP_Shop::admin_summary_rows( wc_get_order( 103 ) ) );
$longest = max( array_map( 'strlen', explode( "\n", $cards ) ) );
check( 'cards: no line longer than 900 characters (RFC 5322 limit is 998)', $longest <= 900, "longest line $longest" );
check( 'cards: no margin on any table', ! preg_match( '/<table[^>]*style="[^"]*margin/', $cards ) );
check( 'cards: gap below each card is spacer-cell padding (16px after the steps, 24px after the code and checks)', 3 === substr_count( $cards, 'class="lp-email-gap"' ) && 1 === substr_count( $cards, '<td style="padding:0 0 16px;">' ) && 2 === substr_count( $cards, '<td style="padding:0 0 24px;">' ) );
check( 'cards: table tags balanced', substr_count( $cards, '<table' ) === substr_count( $cards, '</table>' ) && substr_count( $cards, '<tr' ) === substr_count( $cards, '</tr>' ) && substr_count( $cards, '<td' ) === substr_count( $cards, '</td>' ) );
check( 'cards: wp_kses_post keeps the markup', wp_kses_post( $cards ) === $cards );

// CSS details.
$css11 = LP_Shop::email_css();
check( 'CSS: one divider below the totals (no border on the last totals row)', false !== strpos( $css11, '.order-totals-last th { border-bottom: 0;' ) );
check( 'CSS: customer note without WooCommerce\'s darker bottom rule', false !== strpos( $css11, '.order-customer-note td { border-bottom: 0;' ) );
check( 'CSS: billing phone link stays underlined', false !== strpos( $css11, '.address a { color: #0f5cd8; text-decoration: underline; }' ) );
check( 'CSS: VAT line 14px in the body text colour', false !== strpos( $css11, '.includes_tax { color: #2c3651; font-size: 14px;' ) );
check( 'CSS: logo alt text styled as a navy sans wordmark', false !== strpos( $css11, '#template_header_image img { color: #041950; font-family: ' . LP_Shop::EMAIL_FONT . '; font-size: 22px;' ) && false !== strpos( $css11, 'border-radius: 6px; color: #041950; }' ) );

// woocommerce_mail_content: one CSS rule per line in the <style> element (Emogrifier writes one line).
check( 'woocommerce_mail_content filter added once (priority 20)', 1 === lp_test_hook_count( 'woocommerce_mail_content' ) && isset( $GLOBALS['lp_test']['filters']['woocommerce_mail_content'][20] ) );
$mail_in  = '<html><head><style type="text/css">@media screen and (max-width: 600px){#a{color: red !important;}.b{padding: 0 !important;}}</style></head><body><p style="color: #000;">x}y</p></body></html>';
$mail_out = apply_filters( 'woocommerce_mail_content', $mail_in );
check( 'mail content: a line break after each } inside <style>, nothing else changed', '<html><head><style type="text/css">@media screen and (max-width: 600px){#a{color: red !important;}' . "\n" . '.b{padding: 0 !important;}' . "\n" . '}' . "\n" . '</style></head><body><p style="color: #000;">x}y</p></body></html>' === $mail_out, $mail_out );
check( 'mail content: running twice changes nothing more', $mail_out === apply_filters( 'woocommerce_mail_content', $mail_out ) );
check( 'mail content: plain text untouched', 'Total: AED 1}999' === apply_filters( 'woocommerce_mail_content', 'Total: AED 1}999' ) );
$pad      = str_repeat( 'x', 1000 );
$long_in  = '<html><head><style>a{b:c}</style></head><body><br><h2 style="color: #041950;">Additional information</h2><ul class="additional-fields"><li><strong>Trade licence number</strong>: ' . $pad . '</li><li><strong>VAT TRN</strong>: 100</li></ul></body></html>';
$long_out = apply_filters( 'woocommerce_mail_content', $long_in );
check( 'mail content: a line over 998 characters is broken before block-level tags only', false !== strpos( $long_out, '<br>' . "\n" . '<h2 style="color: #041950;">Additional information</h2>' . "\n" . '<ul class="additional-fields">' . "\n" . '<li><strong>Trade licence number</strong>: ' ) && false !== strpos( $long_out, '</li>' . "\n" . '<li><strong>VAT TRN</strong>: 100</li></ul>' ) && str_replace( "\n", '', $long_out ) === str_replace( "\n", '', $long_in ) );
$short_in = '<html><head><style>a{b:c}' . "\n" . '</style></head><body><br><h2>T</h2><ul><li>x</li></ul></body></html>';
check( 'mail content: lines within the limit keep their markup', $short_in === apply_filters( 'woocommerce_mail_content', $short_in ) );
lp_branding( false );
check( 'mail content: setting off -> untouched (v1.0.0)', $mail_in === apply_filters( 'woocommerce_mail_content', $mail_in ) );
lp_branding( null );
\Automattic\WooCommerce\Utilities\FeaturesUtil::$on['email_improvements'] = false;
check( 'mail content: new templates off -> untouched', $mail_in === apply_filters( 'woocommerce_mail_content', $mail_in ) );
\Automattic\WooCommerce\Utilities\FeaturesUtil::$on['email_improvements'] = true;

// Font: follows WooCommerce > Settings > Emails > Font family (templates/emails/email-styles.php:36, 68).
check( 'font: no EmailFont class / no option -> Helvetica stack', LP_Shop::EMAIL_FONT === LP_Shop::email_font() );
if ( ! class_exists( '\Automattic\WooCommerce\Internal\Email\EmailFont' ) ) {
	eval( 'namespace Automattic\WooCommerce\Internal\Email; class EmailFont { public static $font = array( "Helvetica" => "\'Helvetica Neue\', Helvetica, Roboto, Arial, sans-serif", "Georgia" => "Georgia, Times, \'Times New Roman\', serif", "Bad" => "x; } body { color: red" ); }' ); // phpcs:ignore
}
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $k ) {
		return $GLOBALS['lp_test']['transients'][ $k ] ?? false;
	}
}
$GLOBALS['lp_test']['options']['woocommerce_email_font_family'] = 'Georgia';
$georgia = "Georgia, Times, 'Times New Roman', serif";
check( 'font: Georgia setting -> Georgia stack in the CSS and on the card cells', $georgia === LP_Shop::email_font() && false !== strpos( LP_Shop::email_css(), 'font-family: ' . $georgia . ';' ) && false !== strpos( LP_Shop::email_referral_card_html( 'LP-X' ), 'font-family:Georgia, Times' ) );
\Automattic\WooCommerce\Utilities\FeaturesUtil::$on['email_improvements'] = false;
check( 'font: new templates off -> Helvetica stack (as WooCommerce does)', LP_Shop::EMAIL_FONT === LP_Shop::email_font() );
\Automattic\WooCommerce\Utilities\FeaturesUtil::$on['email_improvements'] = true;
$GLOBALS['lp_test']['options']['woocommerce_email_font_family'] = 'Bad';
check( 'font: a stack with unsafe characters -> Helvetica stack', LP_Shop::EMAIL_FONT === LP_Shop::email_font() );
$GLOBALS['lp_test']['options']['woocommerce_email_font_family'] = 'Comic';
check( 'font: unknown font name -> Helvetica stack', LP_Shop::EMAIL_FONT === LP_Shop::email_font() );
set_transient( 'woocommerce_email_font_family', 'Georgia' ); // stubs-wp.php transient store (1.1.1).
check( 'font: preview transient ignored outside the preview', LP_Shop::EMAIL_FONT === LP_Shop::email_font() );
add_filter( 'woocommerce_is_email_preview', function () { return true; } );
check( 'font: preview transient used in the email preview', $georgia === LP_Shop::email_font() );
remove_all_filters( 'woocommerce_is_email_preview' );
unset( $GLOBALS['lp_test']['options']['woocommerce_email_font_family'], $GLOBALS['lp_test']['transients'] );

// Admin notice on WooCommerce > Settings > Emails when the new email templates are off.
check( 'admin_notices hook added once (with WooCommerce)', 1 === lp_test_hook_count( 'admin_notices' ) );
$caps0                      = $GLOBALS['lp_test']['caps'];
$GLOBALS['lp_test']['caps'] = array( 'manage_woocommerce' );
$_GET                       = array( 'page' => 'wc-settings', 'tab' => 'email' );
\Automattic\WooCommerce\Utilities\FeaturesUtil::$on['email_improvements'] = false;
ob_start();
do_action( 'admin_notices' );
$nt = ob_get_clean();
check( 'notice: shown on Settings > Emails when the new templates are off', false !== strpos( $nt, 'notice-warning' ) && false !== strpos( $nt, 'Try our new email templates!' ) );
$_GET['section'] = 'wc_email_new_order';
ob_start();
do_action( 'admin_notices' );
check( 'notice: not on one email\'s own settings', '' === ob_get_clean() );
$_GET = array( 'page' => 'wc-settings', 'tab' => 'email', 'try-new-templates' => '' );
ob_start();
do_action( 'admin_notices' );
check( 'notice: not while WooCommerce previews the new templates', '' === ob_get_clean() );
$_GET = array( 'page' => 'wc-settings', 'tab' => 'general' );
ob_start();
do_action( 'admin_notices' );
check( 'notice: not on other screens', '' === ob_get_clean() );
$_GET = array( 'page' => 'wc-settings', 'tab' => 'email' );
lp_branding( false );
ob_start();
do_action( 'admin_notices' );
check( 'notice: not when email branding is off', '' === ob_get_clean() );
lp_branding( null );
$GLOBALS['lp_test']['caps'] = array();
ob_start();
do_action( 'admin_notices' );
check( 'notice: not for users without manage_woocommerce', '' === ob_get_clean() );
$GLOBALS['lp_test']['caps'] = array( 'manage_woocommerce' );
\Automattic\WooCommerce\Utilities\FeaturesUtil::$on['email_improvements'] = true;
ob_start();
do_action( 'admin_notices' );
check( 'notice: not when the new templates are on', '' === ob_get_clean() );
$_GET                       = array();
$GLOBALS['lp_test']['caps'] = $caps0;

/* ---------------- F8 ---------------- */
section( 'F8 settings filter' );
add_filter(
	'lp_shop_settings',
	function ( $s ) {
		$s['reward_terms'] = '[Terms v2]';
		$s['product_id']   = '890abc';
		return $s;
	}
);
LP_Shop::reset_settings();
$s = LP_Shop::settings();
check( 'filter can change values; ids are cast to int', '[Terms v2]' === $s['reward_terms'] && 890 === $s['product_id'] );
check( 'defaults present', 892 === $s['sales_page_id'] && 891 === $s['addon_product_id'] && array( 890, 891 ) === $s['buy_now_product_ids'] && '+971 50 342 0832' === $s['phone_display'] );
remove_all_filters( 'lp_shop_settings' );
LP_Shop::reset_settings();

/* ---------------- PHP notices ---------------- */
section( 'PHP notices / deprecations during the run' );
check( 'no PHP notices, warnings or deprecations', array() === $GLOBALS['lp_php_errors'], implode( ' | ', $GLOBALS['lp_php_errors'] ) );
check( 'nothing logged by the plugin', array() === $GLOBALS['lp_test']['logs'], implode( ' | ', $GLOBALS['lp_test']['logs'] ) );

echo "\nResult: {$GLOBALS['lp_results']['pass']} passed, {$GLOBALS['lp_results']['fail']} failed\n";
exit( $GLOBALS['lp_results']['fail'] > 0 ? 1 : 0 );
