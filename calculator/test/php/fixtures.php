<?php
/**
 * The calculator form as the page builder defines it (SPEC.md "Form widget settings"),
 * a valid submission, and fake versions of the three form actions.
 */

use ElementorPro\Modules\Forms\Classes\Ajax_Handler;

const LP_TEST_LOGO = '/tmp/claude-0/-home-user-Mosaic-Digital/11279077-d7c0-513e-8725-04117a99ad61/scratchpad/shop/email/assets/logo-placeholder-622x210.png';

function lp_test_form( array $settings = array() ) {
	$f      = function ( $id, $type, $label = '', array $extra = array() ) {
		return array_merge(
			array(
				'_id'         => substr( md5( $id ), 0, 7 ),
				'custom_id'   => $id,
				'field_type'  => $type,
				'field_label' => $label,
				'required'    => '',
			),
			$extra
		);
	};
	$fields = array(
		$f( 'name', 'text', 'Full name', array( 'required' => 'true' ) ),
		$f( 'company', 'text', 'Company name', array( 'required' => 'true' ) ),
		$f( 'email', 'email', 'Business email', array( 'required' => 'true' ) ),
		$f( 'phone', 'tel', 'Contact number', array( 'required' => 'true' ) ),
		$f( 'message', 'textarea', 'Additional requirements or notes (optional)' ),
		$f( 'hp', 'honeypot' ),
	);
	foreach ( array( 'lpc_version', 'lpc_business', 'lpc_business_other', 'lpc_transactions', 'lpc_vat_status', 'lpc_ct_status', 'lpc_employees', 'lpc_reports', 'lpc_services', 'quote_ref', 'quote_total', 'quote_summary', 'field_page_title', 'page_url', 'lead_source', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_keyword', 'field_lead_form' ) as $id ) {
		$fields[] = $f( $id, 'hidden' );
	}
	$z    = 'el_pro_zoho_zoho_Ledgers_Plus_';
	$base = array(
		'form_name'              => 'Ledger Plus package calculator',
		'form_fields'            => $fields,
		'submit_actions'         => array( 'save-to-database', 'email', 'zoho_Ledgers_Plus' ),
		'email_to'               => 'info@ledgerplus.ae',
		'email_subject'          => 'Package calculator lead: [field id="company"] ([field id="quote_ref"])',
		'email_content'          => '<table><tr><td>Quote</td><td>[field id="quote_ref"] [field id="quote_total"]</td></tr><tr><td style="white-space:pre-wrap">[field id="quote_summary"]</td></tr></table>',
		'email_from'             => 'no-reply@ledgerplus.ae',
		'email_from_name'        => 'Ledger Plus UAE website',
		'email_reply_to'         => 'email',
		'email_content_type'     => 'html',
		'custom_messages'        => 'yes',
		'success_message'        => 'Thank you. Your quotation is on its way to your inbox.',
		'error_message'          => 'Something went wrong. Please try again, or email info@ledgerplus.ae.',
		'required_field_message' => 'This field is required.',
		'invalid_message'        => 'Please check the highlighted fields and try again.',
		$z . 'Name'              => '[field id="name"]',
		$z . 'Owner'             => '794156085',
		$z . 'Email'             => '[field id="email"]',
		$z . 'Mobile'            => '[field id="phone"]',
		$z . 'Lead_Source'       => '[field id="lead_source"]',
		$z . 'Lead_Status'       => 'Prospecting',
		$z . 'Description'       => "Client Company Name: [field id=\"company\"]\n\nPackage calculator estimate:\n[field id=\"quote_summary\"]",
		$z . 'Lead_Form'         => '[field id="field_lead_form"]',
		$z . 'utm_keyword'       => '[field id="utm_keyword"]',
		$z . 'utm_campaign'      => '[field id="utm_campaign"]',
		$z . 'utm_term'          => '[field id="utm_term"]',
		$z . 'Page_Name'         => '[field id="field_page_title"]',
		$z . 'utm_source'        => '[field id="utm_source"]',
		$z . 'Landing_page_URL'  => '[field id="page_url"]',
		$z . 'utm_medium'        => '[field id="utm_medium"]',
		$z . 'Client_s_message'  => '[field id="message"]',
	);
	return array(
		'id'       => 'a1b2c3d',
		'settings' => array_merge( $base, $settings ),
	);
}

/** A valid submission: the Excel example (AED 7,000). */
function lp_test_post( array $overrides = array() ) {
	return array_merge(
		array(
			'name'               => 'Test Customer',
			'company'            => 'Test Trading LLC',
			'email'              => 'test.customer@example.com',
			'phone'              => '+971500000000',
			'message'            => 'Please call in the morning.',
			'hp'                 => '',
			'lpc_version'        => '1',
			'lpc_business'       => 'Trading / Wholesale',
			'lpc_business_other' => '',
			'lpc_transactions'   => '1000',
			'lpc_vat_status'     => 'registered',
			'lpc_ct_status'      => 'not_registered',
			'lpc_employees'      => 'up_to_20',
			'lpc_reports'        => '4',
			'lpc_services'       => 'accounting,vat,ct,payroll',
			'quote_ref'          => '',
			'quote_total'        => '',
			'quote_summary'      => '',
			'field_page_title'   => 'Build Your Package',
			'page_url'           => 'https://ledgerplus.ae/package-calculator/',
			'lead_source'        => 'Website',
			'utm_source'         => 'google',
			'utm_medium'         => 'cpc',
			'utm_campaign'       => 'calc',
			'utm_term'           => '',
			'utm_keyword'        => '',
			'field_lead_form'    => 'Ledgerplus Package Calculator',
		),
		$overrides
	);
}

/** Fake actions: they record what they would have sent. */
function lp_test_actions() {
	return array(
		'save-to-database'  => function ( $record, $handler ) {
			$values = array();
			foreach ( $record->get( 'fields' ) as $id => $field ) {
				$values[ $id ] = $field['value'];
			}
			$GLOBALS['lp_test']['saved'][] = $values;
		},
		'email'             => function ( $record, $handler ) {
			$GLOBALS['lp_test']['team_email'][] = array(
				'to'      => $record->replace_setting_shortcodes( $record->get_form_settings( 'email_to' ) ),
				'subject' => $record->replace_setting_shortcodes( $record->get_form_settings( 'email_subject' ) ),
				'content' => $record->replace_setting_shortcodes( $record->get_form_settings( 'email_content' ) ),
			);
		},
		'zoho_Ledgers_Plus' => function ( $record, $handler ) {
			$lead = array();
			foreach ( $record->get( 'form_settings' ) as $key => $value ) {
				if ( 0 === strpos( $key, 'el_pro_zoho_zoho_Ledgers_Plus_' ) ) {
					$lead[ substr( $key, strlen( 'el_pro_zoho_zoho_Ledgers_Plus_' ) ) ] = $record->replace_setting_shortcodes( $value );
				}
			}
			$GLOBALS['lp_test']['zoho'][] = $lead;
		},
	);
}

/** Runs one submission through the fake Ajax_Handler. */
function lp_test_submit( array $post = array(), array $settings = array() ) {
	$handler = new Ajax_Handler();
	return $handler->ajax_send_form( lp_test_form( $settings ), lp_test_post( $post ), lp_test_actions() );
}

/** Clears captured mails, leads, transients and logs. */
function lp_test_reset() {
	$GLOBALS['lp_test']['mails']      = array();
	$GLOBALS['lp_test']['team_email'] = array();
	$GLOBALS['lp_test']['zoho']       = array();
	$GLOBALS['lp_test']['saved']      = array();
	$GLOBALS['lp_test']['transients'] = array();
	$GLOBALS['lp_test']['mail_ok']    = true;
	$GLOBALS['lp_test']['temp_dir']   = LP_TEST_TMP . '/';
	file_put_contents( LP_TEST_LOG, '' );
}
