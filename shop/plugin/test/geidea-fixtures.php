<?php
/**
 * F12 test fixtures: FAKE Geidea credentials, a signed callback builder (independent of the plugin code),
 * Geidea orders and lookup answers shaped like the Geidea API documentation.
 * Nothing here is a real key, password, token or card.
 */

const LP_FAKE_KEY  = 'test-public-key-0000';
const LP_FAKE_PASS = 'test-api-password-0000';
const LP_FAKE_TOKEN = 'tok-fake-9f8e7d6c5b4a';
const LP_FAKE_CARD  = '411111******1111';
const LP_FAKE_EMAIL = 'buyer.private@example.com';
const LP_FAKE_PHONE = '+971500000001';

/** Geidea plugin settings as on the site (non-secret values from the brief, fake key and password). */
function lp_geidea_settings( $overrides = array() ) {
	return array_merge(
		array(
			'enabled'              => 'yes',
			'geidea_environment'   => 'UAE-PROD',
			'currency_id'          => 'AED',
			'order_status_success' => 'wc-completed',
			'order_status_waiting' => 'wc-processing',
			'cardOnFile'           => 'yes',
			'merchant_gateway_key' => LP_FAKE_KEY,
			'merchant_password'    => LP_FAKE_PASS,
		),
		$overrides
	);
}

/** The callback signature, written from Geidea's ReturnHandler.php (not from the plugin). */
function lp_geidea_sign( $order, $timestamp, $key = LP_FAKE_KEY, $pass = LP_FAKE_PASS ) {
	$data = $key . number_format( $order['amount'], 2, '.', '' ) . $order['currency'] . $order['orderId'] . $order['status'] . $order['merchantReferenceId'] . $timestamp;
	return base64_encode( hash_hmac( 'sha256', $data, $pass, true ) );
}

/** A Geidea order object (callback "order" / lookup list item). */
function lp_geidea_order( $ref, $overrides = array() ) {
	return array_merge(
		array(
			'orderId'               => sprintf( '7d2f9b44-1c3e-4e8a-b0f2-%012d', (int) $ref ),
			'merchantReferenceId'   => (string) $ref,
			'merchantPublicKey'     => LP_FAKE_KEY,
			'amount'                => 1.00,
			'totalAmount'           => 1.00,
			'currency'              => 'AED',
			'status'                => 'Success',
			'detailedStatus'        => 'Paid',
			'paymentOperation'      => 'Pay',
			'totalAuthorizedAmount' => 1.00,
			'totalCapturedAmount'   => 1.00,
			'totalRefundedAmount'   => 0,
			'callbackUrl'           => 'https://ledgerplus.ae/?wc-api=geidea',
			'returnUrl'             => 'https://ledgerplus.ae/checkout/',
			'cardOnFile'            => true,
			'tokenId'               => LP_FAKE_TOKEN,
			'createdDate'           => gmdate( 'Y-m-d\TH:i:s.000\Z', time() - 300 ),
			'paymentMethod'         => array(
				'type'             => 'Card',
				'brand'            => 'Visa',
				'maskedCardNumber' => LP_FAKE_CARD,
				'expiryDate'       => array( 'month' => 3, 'year' => 29 ),
			),
			'transactions'          => array(
				array(
					'type'   => 'Authentication',
					'status' => 'Success',
					'amount' => 1.00,
					'codes'  => array( 'responseCode' => '000', 'responseMessage' => 'Success', 'detailedResponseCode' => '000', 'detailedResponseMessage' => 'The operation was successful' ),
				),
				array(
					'type'   => 'Pay',
					'status' => 'Success',
					'amount' => 1.00,
					'codes'  => array( 'responseCode' => '000', 'responseMessage' => 'Success', 'detailedResponseCode' => '000', 'detailedResponseMessage' => 'The operation was successful' ),
				),
			),
		),
		$overrides
	);
}

/** A failed attempt for the same reference (another Geidea session). */
function lp_geidea_failed_order( $ref ) {
	return lp_geidea_order(
		$ref,
		array(
			'orderId'        => sprintf( '0b6c1e1a-5f7d-4a52-9c1e-%012d', (int) $ref ),
			'status'         => 'Failed',
			'detailedStatus' => 'OrderFailed',
			'transactions'   => array(
				array(
					'type'   => 'Pay',
					'status' => 'Failed',
					'codes'  => array( 'responseCode' => '999', 'responseMessage' => 'Declined', 'detailedResponseCode' => '999.999', 'detailedResponseMessage' => 'Declined' ),
				),
			),
		)
	);
}

/** A recent callback time stamp like the research test vector's "11/27/2026 07:55:01" (month without a leading zero). */
function lp_geidea_ts( $ago = 30 ) {
	return gmdate( 'n/d/Y H:i:s', time() - $ago );
}

/** Callback body: { order, signature, timeStamp }. $sign = false leaves the signature wrong. */
function lp_geidea_callback_body( $ref, $overrides = array(), $sign = true, $ts = null ) {
	$order = lp_geidea_order( $ref, $overrides );
	$ts    = null === $ts ? lp_geidea_ts() : $ts;
	return json_encode(
		array(
			'order'     => $order,
			'signature' => $sign ? lp_geidea_sign( $order, $ts ) : 'AAAAbadsignatureAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
			'timeStamp' => $ts,
		)
	);
}

/** Lookup answer envelope (Fetch by merchant reference). */
function lp_geidea_lookup_body( $orders, $code = '000' ) {
	return array(
		'orders'                  => $orders,
		'responseCode'            => $code,
		'responseMessage'         => '000' === $code ? 'Success' : 'Error',
		'detailedResponseCode'    => '000' === $code ? '000' : $code . '.001',
		'detailedResponseMessage' => '000' === $code ? 'The operation was successful' : 'Error',
	);
}

/** A Geidea WooCommerce order like 939/940: product 890, AED 1.00, geidea, pending, created 10 minutes ago. */
function lp_make_geidea_order( $id, $status = 'pending', $extra = array() ) {
	WC_Order::make( $id, LP_FAKE_EMAIL, $status, array( 890 ), array(), array(), 1 );
	$GLOBALS['lp_test']['orders'][ $id ] = array_merge(
		$GLOBALS['lp_test']['orders'][ $id ],
		array(
			'payment_method' => 'geidea',
			'total'          => '1.00',
			'currency'       => 'AED',
			'order_key'      => 'wc_order_key' . $id,
			'created'        => time() - 600,
			'date_paid'      => null,
			'phone'          => LP_FAKE_PHONE,
			'first_name'     => 'Mariam',
			'last_name'      => 'Testbuyer',
			'email'          => LP_FAKE_EMAIL,
		),
		$extra
	);
	return wc_get_order( $id );
}
