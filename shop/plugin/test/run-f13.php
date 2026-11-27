<?php
/**
 * F13 (1.1.2): a) reward wording, b) shop archive redirect, c) "What happens next" in the Completed order email.
 * Usage: php run-f13.php [path-to-plugin-file]
 *   run-all.sh runs it on the plugin and on the WPCode snippet (with <?php prepended).
 *   The wp-config constants are checked in a second PHP process of this file (mode "constants"), because a
 *   constant cannot be undefined again.
 */

require __DIR__ . '/stubs.php';

$plugin = $argv[1] ?? dirname( __DIR__ ) . '/ledgerplus-shop/ledgerplus-shop.php';
$mode   = $argv[2] ?? '';

$GLOBALS['lp_php_errors'] = array();
set_error_handler(
	function ( $no, $str, $file, $line ) {
		$GLOBALS['lp_php_errors'][] = "[$no] $str ($file:$line)";
		return true;
	}
);

if ( 'constants' === $mode ) {
	define( 'LP_SHOP_REWARD_TEXT', 'you can receive AED 999 cash back once their payment is confirmed.' );
	define( 'LP_SHOP_REWARD_TERMS', '[Terms: to confirm]' );
	define( 'LP_SHOP_REDIRECT_SHOP_ARCHIVE', false );
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
	echo "\n== F13: $t ==\n";
}
function settings_filter( $changes ) {
	remove_all_filters( 'lp_shop_settings' );
	if ( $changes ) {
		add_filter( 'lp_shop_settings', function ( $s ) use ( $changes ) { return array_merge( $s, $changes ); } );
	}
	LP_Shop::reset_settings();
}
function try_redirect() {
	$GLOBALS['lp_test']['redirects'] = array();
	try {
		do_action( 'template_redirect' );
	} catch ( LP_Test_Redirect $e ) {
		return $e->getMessage();
	}
	return null;
}
function email( $order, $id, $plain = false, $to_admin = false ) {
	$em     = new stdClass();
	$em->id = $id;
	ob_start();
	do_action( 'woocommerce_email_order_meta', $order, $to_admin, $plain, $em );
	return ob_get_clean();
}
function stored_meta( $id, $key ) {
	return $GLOBALS['lp_test']['orders'][ $id ]['meta'][ $key ] ?? '';
}
function finish() {
	section( 'PHP notices' );
	check( 'no PHP notices, warnings or deprecations', array() === $GLOBALS['lp_php_errors'], implode( ' | ', $GLOBALS['lp_php_errors'] ) );
	check( 'nothing logged by the plugin', array() === $GLOBALS['lp_test']['logs'], implode( ' | ', $GLOBALS['lp_test']['logs'] ) );
	echo "\nResult: {$GLOBALS['lp_results']['pass']} passed, {$GLOBALS['lp_results']['fail']} failed\n";
	exit( $GLOBALS['lp_results']['fail'] > 0 ? 1 : 0 );
}

$TEXT  = 'you can receive AED 999 cash back after their payment is confirmed.';
$TERMS = 'No cash back is due if their order is refunded, cancelled or disputed.';
$LEAD  = 'Share it with another business. When they buy Ledger Plus with your code, ';

/* ======================================================================
 * Mode "constants": LP_SHOP_REWARD_TEXT, LP_SHOP_REWARD_TERMS, LP_SHOP_REDIRECT_SHOP_ARCHIVE.
 * ====================================================================== */
if ( 'constants' === $mode ) {
	section( 'wp-config constants' );
	$s = LP_Shop::settings();
	check( 'LP_SHOP_REWARD_TEXT sets reward_text', LP_SHOP_REWARD_TEXT === $s['reward_text'] );
	check( 'LP_SHOP_REWARD_TERMS sets reward_terms (stored as given)', '[Terms: to confirm]' === $s['reward_terms'] );
	check( 'a placeholder given by constant is left out of the sentence (page and emails)', 'Your referral code: LP-K. ' . $LEAD . LP_SHOP_REWARD_TEXT === LP_Shop::referral_sentence_text( 'LP-K' ) && '' === LP_Shop::email_reward_terms() );
	check( 'LP_SHOP_REDIRECT_SHOP_ARCHIVE false -> redirect_shop_archive false', false === $s['redirect_shop_archive'] );
	$GLOBALS['lp_test']['permalinks'][892]  = 'https://ledgerplus.ae/e-invoicing-starter/';
	$GLOBALS['lp_test']['post_status'][892] = 'publish';
	$GLOBALS['lp_test']['is_shop']          = true;
	$GLOBALS['lp_test']['queried_id']       = 885;
	check( 'constant off -> /shop/ is not redirected', null === try_redirect() );
	settings_filter( array( 'redirect_shop_archive' => true, 'reward_terms' => 'One reward per referred business.' ) );
	check( 'the lp_shop_settings filter overrides the constants', null !== try_redirect() && 'One reward per referred business.' === LP_Shop::email_reward_terms() );
	settings_filter( array( 'redirect_shop_archive' => 0 ) );
	check( 'filter value 0 -> off (cast to bool)', false === LP_Shop::settings()['redirect_shop_archive'] && null === try_redirect() );
	finish();
}

/* ======================================================================
 * F13a. Reward wording
 * ====================================================================== */
section( 'a) reward wording defaults' );
check( 'DEFAULT_REWARD_TEXT is the confirmed wording', $TEXT === LP_Shop::DEFAULT_REWARD_TEXT );
check( 'DEFAULT_REWARD_TERMS is the confirmed wording', $TERMS === LP_Shop::DEFAULT_REWARD_TERMS );
$s = LP_Shop::settings();
check( 'settings: reward_text / reward_terms default to them', $TEXT === $s['reward_text'] && $TERMS === $s['reward_terms'] );
$full_text = 'Your referral code: LP-HG4HG7. ' . $LEAD . $TEXT . ' ' . $TERMS;
check( 'plain sentence reads as one paragraph', $full_text === LP_Shop::referral_sentence_text( 'LP-HG4HG7' ), LP_Shop::referral_sentence_text( 'LP-HG4HG7' ) );
check( 'HTML sentence: same words, code in <strong>', 'Your referral code: <strong class="lp-shop-referral__code">LP-HG4HG7</strong>. ' . $LEAD . $TEXT . ' ' . $TERMS === LP_Shop::referral_sentence_html( 'LP-HG4HG7' ) );
check( 'no promise of payout method or timing in the defaults', ! preg_match( '/bank|transfer|within|days|guarantee/i', $TEXT . $TERMS ) );
check( 'no square brackets, no "to confirm" in the defaults', false === strpos( $TEXT . $TERMS, '[' ) && false === stripos( $TEXT . $TERMS, 'to confirm' ) );
check( 'no straight apostrophe in the defaults (plain-text emails drop &#039;)', false === strpos( $TEXT . $TERMS, "'" ) );
check( 'email_reward_terms() shows the default terms', $TERMS === LP_Shop::email_reward_terms() );

// A paid order with a code, for the pages and emails.
WC_Order::make( 300, 'ref@buyer.ae', 'completed', array( 890 ), array(), array( '_lp_referral_code' => 'LP-HG4HG7' ), 77 );

ob_start();
do_action( 'woocommerce_thankyou', 300 );
$ty = ob_get_clean();
check( 'thank-you page: the full sentence with the default terms', false !== strpos( $ty, '<p class="lp-shop-referral__text" data-lp-code="LP-HG4HG7">Your referral code: <strong class="lp-shop-referral__code">LP-HG4HG7</strong>. ' . $LEAD . $TEXT . ' ' . $TERMS . '</p>' ), $ty );
check( 'thank-you page: no "[Reward terms: to confirm]", no square brackets', false === strpos( $ty, 'Reward terms' ) && false === strpos( $ty, '[' ) );

$GLOBALS['lp_test']['logged_in'] = true;
$GLOBALS['lp_test']['user_id']   = 77;
ob_start();
do_action( 'woocommerce_account_dashboard' );
$acc = ob_get_clean();
check( 'My Account dashboard: the full sentence with the default terms', false !== strpos( $acc, 'lp-shop-referral--account' ) && false !== strpos( $acc, $TEXT . ' ' . $TERMS . '</p>' ) && false === strpos( $acc, '[' ) );

$c_html = email( wc_get_order( 300 ), 'customer_completed_order' );
check( 'branded email card: reward text, then the terms in grey', false !== strpos( $c_html, $LEAD . $TEXT . ' <span style="color:#5b6684;">' . $TERMS . '</span></p>' ) && false === strpos( $c_html, '[' ) );
$c_txt = email( wc_get_order( 300 ), 'customer_completed_order', true );
check( 'branded plain-text email: the full sentence', false !== strpos( $c_txt, "\n" . $full_text . "\n\n" ) && $c_txt === strip_tags( $c_txt ) );
settings_filter( array( 'email_branding' => false ) );
$u_html = email( wc_get_order( 300 ), 'customer_completed_order' );
$u_txt  = email( wc_get_order( 300 ), 'customer_completed_order', true );
check( 'email branding off (v1.0.0 box): the full sentence', false !== strpos( $u_html, '<p style="margin:0;">Your referral code: <strong class="lp-shop-referral__code">LP-HG4HG7</strong>. ' . $LEAD . $TEXT . ' ' . $TERMS . '</p></div>' ) );
check( 'email branding off (plain text): the full sentence', "\n" . $full_text . "\n\n" === $u_txt );
settings_filter( array() );

section( 'a) placeholders are left out, real terms shown' );
foreach ( array( '[Reward terms: to confirm]', ' [Terms v2] ', 'Paid by [payment method] within [X days].', '', '   ' ) as $placeholder ) {
	settings_filter( array( 'reward_terms' => $placeholder ) );
	$label = '"' . $placeholder . '"';
	ob_start();
	do_action( 'woocommerce_thankyou', 300 );
	$p_ty = ob_get_clean();
	check( "terms $label: thank-you sentence ends after the reward text (no space, no bracket)", false !== strpos( $p_ty, $LEAD . $TEXT . '</p>' ) && false === strpos( $p_ty, '[' ) );
	check( "terms $label: plain sentence ends after the reward text", 'Your referral code: LP-HG4HG7. ' . $LEAD . $TEXT === LP_Shop::referral_sentence_text( 'LP-HG4HG7' ) );
	$p_card = email( wc_get_order( 300 ), 'customer_completed_order' );
	$p_txt  = email( wc_get_order( 300 ), 'customer_completed_order', true );
	check( "terms $label: branded emails without a terms span or bracket", false === strpos( $p_card, '<span style="color:#5b6684;">' ) && false === strpos( $p_card . $p_txt, '[' ) && false !== strpos( $p_txt, $LEAD . $TEXT . "\n\n" ) );
	settings_filter( array( 'reward_terms' => $placeholder, 'email_branding' => false ) );
	$p_u = email( wc_get_order( 300 ), 'customer_completed_order' ) . email( wc_get_order( 300 ), 'customer_completed_order', true );
	check( "terms $label: unbranded emails without a bracket", false === strpos( $p_u, '[' ) && false !== strpos( $p_u, $TEXT . '</p></div>' ) );
}
settings_filter( array( 'reward_terms' => 'One reward per referred business, <after> 14 days & no refund.' ) );
ob_start();
do_action( 'woocommerce_thankyou', 300 );
$r_ty = ob_get_clean();
check( 'real terms from the filter: shown (escaped) on the thank-you page', false !== strpos( $r_ty, $TEXT . ' One reward per referred business, &lt;after&gt; 14 days &amp; no refund.</p>' ) );
check( 'real terms from the filter: shown (escaped) in the email card', false !== strpos( email( wc_get_order( 300 ), 'customer_completed_order' ), '<span style="color:#5b6684;">One reward per referred business, &lt;after&gt; 14 days &amp; no refund.</span>' ) );
check( 'real terms from the filter: shown in the plain-text email (tags stripped, as in 1.1.1)', false !== strpos( email( wc_get_order( 300 ), 'customer_completed_order', true ), $TEXT . ' One reward per referred business,  14 days &amp; no refund.' ) );
settings_filter( array( 'reward_text' => 'you can receive AED 500 cash back after their payment is confirmed.' ) );
check( 'reward_text from the filter is used', false !== strpos( LP_Shop::referral_sentence_text( 'LP-X' ), 'with your code, you can receive AED 500 cash back after their payment is confirmed. ' . $TERMS ) );
settings_filter( array() );

section( 'a) no other customer-visible placeholder' );
$src     = (string) file_get_contents( $plugin );
$strings = array();
foreach ( token_get_all( 0 === strpos( ltrim( $src ), '<?php' ) ? $src : '<?php ' . $src ) as $tok ) {
	if ( is_array( $tok ) && T_CONSTANT_ENCAPSED_STRING === $tok[0] ) {
		$strings[] = $tok[1];
	}
}
$bad = array_values( array_filter( $strings, function ( $str ) { return 1 === preg_match( '/\[[^\]]*(to confirm|tbc|x days|payment method)[^\]]*\]/i', $str ); } ) );
check( 'no string literal in the code holds a "[... to confirm]"-style placeholder', array() === $bad, implode( ' | ', $bad ) );
check( 'cart / checkout referral wording unchanged and placeholder-free', 'Referral code accepted. Your price stays AED 1,999.' === LP_Shop::referral_accepted_text() && false === strpos( implode( '', LP_Shop::block_label_map() ), '[' ) );

/* ======================================================================
 * F13b. Shop archive redirect
 * ====================================================================== */
section( 'b) shop archive redirect' );
$hooked = 0;
foreach ( $GLOBALS['lp_test']['filters']['template_redirect'][5] ?? array() as $entry ) {
	$hooked += ( array( 'LP_Shop', 'maybe_redirect_shop_archive' ) === $entry[0] ) ? 1 : 0;
}
check( 'hooked once on template_redirect at priority 5 (with F6)', 1 === $hooked );
check( 'setting redirect_shop_archive defaults to true', true === LP_Shop::settings()['redirect_shop_archive'] );

$SALES = 'https://ledgerplus.ae/e-invoicing-starter/';
$GLOBALS['lp_test']['permalinks'][892]  = $SALES;
$GLOBALS['lp_test']['post_status'][892] = 'publish';
$GLOBALS['lp_test']['logged_in']        = false;
function on_shop() {
	$GLOBALS['lp_test']['is_shop']             = true;
	$GLOBALS['lp_test']['is_product_taxonomy'] = false;
	$GLOBALS['lp_test']['is_singular']         = false;
	$GLOBALS['lp_test']['queried_id']          = 885;
}
on_shop();
$_GET = array();
$to   = try_redirect();
check( '/shop/ (page 885) -> 302 to the sales page', $SALES === $to && 302 === ( $GLOBALS['lp_test']['redirects'][0][1] ?? 0 ) && 1 === count( $GLOBALS['lp_test']['redirects'] ), (string) $to );
$_GET = array( 'utm_source' => 'google', 'utm_campaign' => 'launch', 'gclid' => 'G1', 'fbclid' => 'F1', 'msclkid' => 'M1', 'orderby' => 'price', 'lp_x' => 'y' );
$to   = (string) try_redirect();
check( 'keeps utm_*, gclid, fbclid, msclkid; drops other parameters', 0 === strpos( $to, $SALES . '?' ) && false !== strpos( $to, 'utm_source=google' ) && false !== strpos( $to, 'utm_campaign=launch' ) && false !== strpos( $to, 'gclid=G1' ) && false !== strpos( $to, 'fbclid=F1' ) && false !== strpos( $to, 'msclkid=M1' ) && false === strpos( $to, 'orderby' ) && false === strpos( $to, 'lp_x' ), $to );
$_GET = array( 'utm_source' => array( 'x' ) );
check( 'an array parameter is dropped without a PHP warning', $SALES === try_redirect() );
$_GET = array();
$GLOBALS['lp_test']['is_shop']             = false;
$GLOBALS['lp_test']['is_product_taxonomy'] = true;
$GLOBALS['lp_test']['queried_id']          = 15; // A product_cat / product_tag / pa_* term.
check( 'product category / tag / attribute archive -> 302 to the sales page', $SALES === try_redirect() );
// The queried object of a taxonomy archive is a WP_Term: its id is a term id, counted apart from page ids.
$GLOBALS['lp_test']['queried_id'] = 892;
check( 'product taxonomy archive with term id 892 (= sales_page_id) -> still 302 (a term id is not the sales page)', $SALES === try_redirect() && 1 === count( $GLOBALS['lp_test']['redirects'] ) );
$GLOBALS['lp_test']['queried_id'] = 885;
check( 'product taxonomy archive with term id 885 (= the shop page id) -> still 302', $SALES === try_redirect() );
$GLOBALS['lp_test']['queried_id'] = 15;
$GLOBALS['lp_test']['is_shop'] = true; // Product search (?s=…&post_type=product) is also is_shop().
check( 'shop and taxonomy at once -> one redirect', $SALES === try_redirect() && 1 === count( $GLOBALS['lp_test']['redirects'] ) );
on_shop();

// The sales page.
$GLOBALS['lp_test']['post_status'][892] = 'draft';
check( 'sales page draft -> no redirect', null === try_redirect() );
$GLOBALS['lp_test']['post_status'][892] = 'private';
check( 'sales page private -> no redirect', null === try_redirect() );
$GLOBALS['lp_test']['post_status'][892] = 'publish';
unset( $GLOBALS['lp_test']['permalinks'][892] );
check( 'sales page without a permalink -> no redirect', null === try_redirect() );
$GLOBALS['lp_test']['permalinks'][892] = $SALES;
settings_filter( array( 'sales_page_id' => 0 ) );
check( 'sales_page_id 0 -> no redirect', null === try_redirect() );
settings_filter( array( 'sales_page_id' => 885 ) );
$GLOBALS['lp_test']['post_status'][885] = 'publish';
$GLOBALS['lp_test']['permalinks'][885]  = 'https://ledgerplus.ae/shop/';
check( 'sales page = the shop page -> no redirect loop', null === try_redirect() );
// A WooCommerce that leaves the product post type object as the queried object of /shop/ (queried id 0).
$GLOBALS['lp_test']['queried_id'] = 0;
check( 'sales page = the shop page, queried id 0 -> no redirect loop (wc_get_page_id)', null === try_redirect() );
$GLOBALS['lp_test']['wc_pages'] = array( 'shop' => 900 );
$GLOBALS['lp_test']['queried_id'] = 900;
check( 'sales page 885 while the shop page is 900 -> 302 (885 is not the page shown)', 'https://ledgerplus.ae/shop/' === try_redirect() );
$GLOBALS['lp_test']['wc_pages'] = array();
check( 'no shop page set (wc_get_page_id -1) -> 302', 'https://ledgerplus.ae/shop/' === try_redirect() );
unset( $GLOBALS['lp_test']['wc_pages'] );
on_shop();
settings_filter( array( 'sales_page_id' => 885 ) );
$GLOBALS['lp_test']['is_shop']             = false;
$GLOBALS['lp_test']['is_product_taxonomy'] = true;
check( 'sales page = the shop page, on a product category archive -> 302 to the shop page (one hop, then it stops)', 'https://ledgerplus.ae/shop/' === try_redirect() );
on_shop();
settings_filter( array( 'redirect_shop_archive' => false ) );
check( 'setting off (filter) -> no redirect', null === try_redirect() );
settings_filter( array( 'redirect_product_page' => false ) );
check( 'F6 setting off does not switch F13b off', $SALES === try_redirect() );
settings_filter( array() );

// Guards (as F6).
$guards = array(
	'admin'      => 'is_admin',
	'AJAX'       => 'doing_ajax',
	'cron'       => 'doing_cron',
	'REST'       => 'store_api',
	'feed'       => 'is_feed',
	'preview'    => 'is_preview',
	'Customizer' => 'is_customize',
	'embed'      => 'is_embed',
);
foreach ( $guards as $label => $flag ) {
	$GLOBALS['lp_test'][ $flag ] = true;
	check( "$label -> no redirect", null === try_redirect() );
	$GLOBALS['lp_test'][ $flag ] = false;
}
check( 'guards reset -> redirect again', $SALES === try_redirect() );
$_REQUEST['add-to-cart'] = '890';
check( '?add-to-cart -> no redirect (WooCommerce adds the product first)', null === try_redirect() );
unset( $_REQUEST['add-to-cart'] );
foreach ( array( 'elementor-preview' => '885', 'preview' => 'true', 'preview_id' => '885' ) as $key => $value ) {
	$_GET = array( $key => $value );
	check( "?$key -> no redirect", null === try_redirect() );
}
$GLOBALS['lp_test']['caps'] = array( 'manage_woocommerce' );
$_GET                       = array( 'lp_stay' => '1' );
check( 'shop manager with ?lp_stay=1 stays', null === try_redirect() );
$_GET = array( 'lp_stay' => array( '1' ) );
$e0   = count( $GLOBALS['lp_php_errors'] );
check( 'shop manager with ?lp_stay[]=1 is redirected, no PHP warning', $SALES === try_redirect() && count( $GLOBALS['lp_php_errors'] ) === $e0 );
$_GET = array( 'lp_stay' => '2' );
check( 'shop manager with ?lp_stay=2 is redirected', $SALES === try_redirect() );
$GLOBALS['lp_test']['caps'] = array();
$_GET                       = array( 'lp_stay' => '1' );
check( 'visitor with ?lp_stay=1 is redirected', $SALES === try_redirect() );
$_GET = array();

// Pages that must never be redirected, even if WooCommerce called them "shop".
$never = array(
	'cart'           => 'is_cart',
	'checkout'       => 'is_checkout',
	'My Account'     => 'is_account',
	'order-received' => 'order_received',
	'order-pay'      => 'is_pay_page',
	'front page'     => 'is_front_page',
);
foreach ( $never as $label => $flag ) {
	$GLOBALS['lp_test'][ $flag ] = true;
	check( "$label -> no redirect", null === try_redirect() );
	$GLOBALS['lp_test'][ $flag ] = false;
}
foreach ( array( 'cart' => 'is_cart', 'checkout' => 'is_checkout', 'My Account' => 'is_account' ) as $label => $flag ) {
	$GLOBALS['lp_test']['is_shop']               = false;
	$GLOBALS['lp_test'][ $flag ]                 = true;
	$GLOBALS['lp_test']['queried_id']            = array( 'is_cart' => 886, 'is_checkout' => 887, 'is_account' => 888 )[ $flag ];
	check( "plain $label page -> no redirect", null === try_redirect() );
	$GLOBALS['lp_test'][ $flag ] = false;
}
$GLOBALS['lp_test']['is_shop']    = false;
$GLOBALS['lp_test']['queried_id'] = 0;
check( 'blog archive / other page -> no redirect', null === try_redirect() );
$GLOBALS['lp_test']['queried_id'] = 892;
check( 'the sales page itself -> no redirect', null === try_redirect() );
// F6 is unchanged next to F13b.
$GLOBALS['lp_test']['is_singular'] = true;
$GLOBALS['lp_test']['queried_id']  = 890;
check( 'product 890 page: still one F6 redirect', $SALES === try_redirect() && 1 === count( $GLOBALS['lp_test']['redirects'] ) );
settings_filter( array( 'redirect_product_page' => false ) );
check( 'F6 setting off -> product page not redirected', null === try_redirect() );
settings_filter( array( 'redirect_product_page' => 0 ) );
check( 'F6 setting 0 (filter) -> product page not redirected', null === try_redirect() );
settings_filter( array( 'redirect_shop_archive' => false ) );
check( 'F13b setting off does not switch F6 off', $SALES === try_redirect() );
settings_filter( array() );
check( 'F6 setting back on -> product page redirected again', $SALES === try_redirect() );
$GLOBALS['lp_test']['queried_id'] = 891;
check( 'another product page: no redirect', null === try_redirect() );
$GLOBALS['lp_test']['is_singular'] = false;
$GLOBALS['lp_test']['queried_id']  = 0;

/* ======================================================================
 * F13c. "What happens next" in the Completed order email
 * ====================================================================== */
section( 'c) "What happens next" in the Completed order email' );
$FLAG  = LP_Shop::META_NEXT_STEPS_EMAILED;
$STEPS = LP_Shop::email_next_steps_html();
check( 'order meta key', '_lp_next_steps_emailed' === $FLAG );

// This site: pending -> completed, no Processing email.
WC_Order::make( 310, 'c@buyer.ae', 'completed', array( 890 ), array(), array( '_lp_referral_code' => 'LP-CMPL23' ) );
$h = email( wc_get_order( 310 ), 'customer_completed_order' );
check( 'completed without a Processing email (HTML): steps card, then the referral card exactly as before', $STEPS . LP_Shop::email_referral_card_html( 'LP-CMPL23' ) === $h );
$t = email( wc_get_order( 310 ), 'customer_completed_order', true );
check( 'completed without a Processing email (plain): steps, then the referral sentence', LP_Shop::email_next_steps_text() . "\n" . 'Your referral code: LP-CMPL23. ' . $LEAD . $TEXT . ' ' . $TERMS . "\n\n" === $t, $t );
check( 'plain text: no tags, help line included', $t === strip_tags( $t ) && false !== strpos( $t, '1. Our team contacts you to create your Ledger Plus login.' ) && false !== strpos( $t, 'Need help? Call or WhatsApp +971 50 342 0832.' ) );
check( 'the Completed email records nothing', '' === stored_meta( 310, $FLAG ) && 0 === ( $GLOBALS['lp_test']['meta_saves'][310] ?? 0 ) );
check( 'resent Completed email (admin): steps again', $h === email( wc_get_order( 310 ), 'customer_completed_order' ) );

// Processing -> Completed (e.g. a gateway that sets Processing, completed by hand later).
WC_Order::make( 311, 'p@buyer.ae', 'processing', array( 890 ), array(), array( '_lp_referral_code' => 'LP-PRCS23' ) );
$o311 = wc_get_order( 311 );
$ph   = email( $o311, 'customer_processing_order' );
check( 'processing (HTML): steps + referral card, as in 1.1.1', $STEPS . LP_Shop::email_referral_card_html( 'LP-PRCS23' ) === $ph );
check( 'processing: records the flag in the order meta (saved)', 'yes' === stored_meta( 311, $FLAG ) && 1 === ( $GLOBALS['lp_test']['meta_saves'][311] ?? 0 ) );
$pt = email( $o311, 'customer_processing_order', true );
check( 'multipart: the plain part has the steps and does not save again', false !== strpos( $pt, 'WHAT HAPPENS NEXT' ) && 1 === ( $GLOBALS['lp_test']['meta_saves'][311] ?? 0 ) );
check( 'resent Processing email: steps again, no second save', false !== strpos( email( wc_get_order( 311 ), 'customer_processing_order' ), 'lp-email-next' ) && 1 === ( $GLOBALS['lp_test']['meta_saves'][311] ?? 0 ) );
$GLOBALS['lp_test']['orders'][311]['status'] = 'completed';
$ch = email( wc_get_order( 311 ), 'customer_completed_order' );
check( 'completed after a Processing email (HTML): referral card only, exactly as before', LP_Shop::email_referral_card_html( 'LP-PRCS23' ) === $ch );
$ct = email( wc_get_order( 311 ), 'customer_completed_order', true );
check( 'completed after a Processing email (plain): the referral sentence only', "\n" . 'Your referral code: LP-PRCS23. ' . $LEAD . $TEXT . ' ' . $TERMS . "\n\n" === $ct );

// Stale order object: read before the Processing email (another request) saved the flag.
WC_Order::make( 312, 's@buyer.ae', 'processing', array( 890 ), array(), array( '_lp_referral_code' => 'LP-STAL23' ) );
$stale = wc_get_order( 312 );
email( wc_get_order( 312 ), 'customer_processing_order' );
$reads = $GLOBALS['lp_test']['meta_reads'] ?? 0;
check( 'stale object without the flag: the Completed email checks the database and leaves the steps out', false === strpos( email( $stale, 'customer_completed_order' ), 'lp-email-next' ) && ( $GLOBALS['lp_test']['meta_reads'] ?? 0 ) > $reads );

// Plain-text-only Processing email also records it.
WC_Order::make( 313, 't@buyer.ae', 'processing', array( 890 ) );
$pt313 = email( wc_get_order( 313 ), 'customer_processing_order', true );
check( 'plain-text Processing email (no code yet): steps, flag recorded', false !== strpos( $pt313, 'WHAT HAPPENS NEXT' ) && false === strpos( $pt313, 'Your referral code' ) && 'yes' === stored_meta( 313, $FLAG ) );
WC_Order::make( 314, 'u@buyer.ae', 'completed', array( 890 ) );
$nc = email( wc_get_order( 314 ), 'customer_completed_order' );
check( 'completed without a code yet: steps, no referral card', $STEPS === $nc );

// An order id the store does not know (no database row): steps shown, nothing written.
$ghost = WC_Order::from_row( array( 'id' => 999, 'email' => 'g@buyer.ae', 'status' => 'completed', 'product_ids' => array( 890 ), 'coupons' => array(), 'meta' => array(), 'customer_id' => 0 ) );
check( 'completed for an order that is not stored: steps, no error', false !== strpos( email( $ghost, 'customer_completed_order' ), 'lp-email-next' ) && ! isset( $GLOBALS['lp_test']['orders'][999] ) );

// WooCommerce email preview: PreviewOrder keeps id 0 and has no Ledger Plus product.
$preview_order = new WC_Order();
add_filter( 'woocommerce_is_email_preview', function () { return true; } );
$pvc = email( $preview_order, 'customer_completed_order' );
check( 'email preview, Completed: steps + sample code', false !== strpos( $pvc, 'lp-email-next' ) && false !== strpos( $pvc, LP_Shop::SAMPLE_CODE ) && strpos( $pvc, 'lp-email-next' ) < strpos( $pvc, 'lp-email-referral' ) );
$pvp = email( $preview_order, 'customer_processing_order' );
$pvt = email( $preview_order, 'customer_completed_order', true );
check( 'email preview, Processing and plain Completed: steps, nothing written', false !== strpos( $pvp, 'lp-email-next' ) && false !== strpos( $pvt, 'WHAT HAPPENS NEXT' ) && ! isset( $GLOBALS['lp_test']['orders'][0] ) );
// A preview rendered with a real order (should WooCommerce ever do that): shows the steps, writes nothing.
WC_Order::make( 315, 'v@buyer.ae', 'processing', array( 890 ), array(), array( '_lp_referral_code' => 'LP-PREV23' ) );
email( wc_get_order( 315 ), 'customer_processing_order' );
check( 'email preview with a real order: Processing writes nothing', '' === stored_meta( 315, $FLAG ) && 0 === ( $GLOBALS['lp_test']['meta_saves'][315] ?? 0 ) );
check( 'email preview with a real flagged order: Completed still shows the steps', false !== strpos( email( wc_get_order( 311 ), 'customer_completed_order' ), 'lp-email-next' ) );
remove_all_filters( 'woocommerce_is_email_preview' );

// Not a preview, order without an id but with product 890 (unsaved object): steps, no write.
$unsaved = new WC_Order();
$unsaved->product_ids = array( 890 );
$unsaved->status      = 'processing';
email( $unsaved, 'customer_processing_order' );
check( 'unsaved order (id 0), Processing: nothing written', ! isset( $GLOBALS['lp_test']['orders'][0] ) );
check( 'unsaved order (id 0), Completed: steps shown', false !== strpos( email( $unsaved, 'customer_completed_order' ), 'lp-email-next' ) );

// Branding off: the v1.0.0 emails (no steps in any email, nothing recorded).
settings_filter( array( 'email_branding' => false ) );
WC_Order::make( 316, 'w@buyer.ae', 'processing', array( 890 ), array(), array( '_lp_referral_code' => 'LP-OFFF23' ) );
$off_p = email( wc_get_order( 316 ), 'customer_processing_order' ) . email( wc_get_order( 316 ), 'customer_processing_order', true );
$off_c = email( wc_get_order( 310 ), 'customer_completed_order' ) . email( wc_get_order( 310 ), 'customer_completed_order', true );
check( 'branding off: no steps in Processing or Completed, flag not recorded', false === strpos( $off_p . $off_c, 'lp-email-next' ) && false === strpos( $off_p . $off_c, 'WHAT HAPPENS NEXT' ) && '' === stored_meta( 316, $FLAG ) );
check( 'branding off: the v1.0.0 referral box still there', false !== strpos( $off_c, 'lp-shop-email-referral' ) );
settings_filter( array() );

// Other emails.
foreach ( array( 'customer_on_hold_order', 'customer_invoice', 'customer_note', 'customer_refunded_order' ) as $other ) {
	check( "$other: nothing added, nothing recorded", '' === email( wc_get_order( 314 ), $other ) . email( wc_get_order( 314 ), $other, true ) && '' === stored_meta( 314, $FLAG ) );
}
$adm = email( wc_get_order( 314 ), 'new_order', false, true );
check( 'admin New order email: checks card only, nothing recorded', false !== strpos( $adm, 'lp-email-checks' ) && false === strpos( $adm, 'lp-email-next' ) && '' === stored_meta( 314, $FLAG ) );
// Another product: nothing.
WC_Order::make( 317, 'x@buyer.ae', 'completed', array( 12 ) );
check( 'order without product 890: nothing', '' === email( wc_get_order( 317 ), 'customer_completed_order' ) );

section( 'wp-config constants (second PHP process)' );
$cmd = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' ' . escapeshellarg( $plugin ) . ' constants 2>&1';
exec( $cmd, $lines, $rc );
foreach ( $lines as $line ) {
	if ( 0 === strpos( $line, '  PASS' ) || 0 === strpos( $line, '  FAIL' ) ) {
		echo '  ' . $line . "\n";
	}
}
check( 'constants process: all checks passed', 0 === $rc && false !== strpos( implode( "\n", $lines ), ' 0 failed' ), implode( ' | ', array_slice( $lines, -3 ) ) );

finish();
