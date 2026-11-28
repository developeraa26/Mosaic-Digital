<?php
/**
 * Elementor Pro form integration for the "Ledger Plus package calculator" form.
 *
 * Elementor Pro (4.3.1) runs, in Ajax_Handler::ajax_send_form():
 *   1. Form_Record::validate()  -> action elementor_pro/forms/validation
 *   2. Form_Record::process_fields(), which ends with action elementor_pro/forms/process
 *   3. filter elementor_pro/forms/record/actions_before ($record, $ajax_handler)
 *   4. each action's run(): save-to-database, email, zoho_Ledgers_Plus
 *   5. action elementor_pro/forms/new_record ($record, $ajax_handler)
 *   6. send()
 *
 * The page has no prices: it sends the selection only, and the figure fields (quote_ref,
 * quote_total, quote_summary) empty. The server prices the selection and writes the figure fields
 * in step 2 and again in step 3, so the actions in step 4 get the server's figures even if one of
 * those two hooks does not run.
 *
 * The Elementor Pro classes are never type-hinted, so this file loads without Elementor Pro.
 *
 * @package LedgerPlusCalculator
 */

defined( 'ABSPATH' ) || exit;

/**
 * Server-side checks, pricing, CRM fields and the client email.
 */
final class LP_Calc_Elementor {

	const FORM_NAME     = 'Ledger Plus package calculator';
	const LEAD_FORM     = 'Ledgerplus Package Calculator';
	const LEAD_SOURCE   = 'Website';
	const LIMIT_MESSAGE = 'You have requested several quotations already. Please try again later or email info@ledgerplus.ae.';
	const RL_PREFIX     = 'lp_calc_rl_';

	/** Selection input => form field custom_id. */
	const SELECTION_FIELDS = array(
		'business'       => 'lpc_business',
		'business_other' => 'lpc_business_other',
		'transactions'   => 'lpc_transactions',
		'vat_status'     => 'lpc_vat_status',
		'ct_status'      => 'lpc_ct_status',
		'employees'      => 'lpc_employees',
		'reports'        => 'lpc_reports',
		'services'       => 'lpc_services',
	);

	/**
	 * The normalized selection and quote of the current request (set in actions_before).
	 *
	 * @var array|null
	 */
	private static $state = null;

	/** @var bool */
	private static $booted = false;

	/**
	 * Adds the hooks.
	 */
	public static function init() {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;
		add_action( 'elementor_pro/forms/validation', array( __CLASS__, 'validate' ), 10, 2 );
		add_action( 'elementor_pro/forms/process', array( __CLASS__, 'process' ), 10, 2 );
		add_filter( 'elementor_pro/forms/record/actions_before', array( __CLASS__, 'actions_before' ), 10, 2 );
		add_action( 'elementor_pro/forms/new_record', array( __CLASS__, 'new_record' ), 20, 2 );
	}

	/**
	 * Is this the calculator form? Form name "Ledger Plus package calculator" and an
	 * lpc_version field. Filterable with `lp_calc_is_calculator_form`.
	 *
	 * @param object $record ElementorPro\Modules\Forms\Classes\Form_Record.
	 * @return bool
	 */
	public static function is_calculator_form( $record ) {
		$is = false;
		if ( is_object( $record ) && method_exists( $record, 'get' ) && method_exists( $record, 'get_form_settings' ) ) {
			$name   = $record->get_form_settings( 'form_name' );
			$fields = $record->get( 'fields' );
			$is     = is_string( $name ) && self::FORM_NAME === trim( $name ) && is_array( $fields ) && isset( $fields['lpc_version'] );
		}
		return (bool) apply_filters( 'lp_calc_is_calculator_form', $is, $record );
	}

	/**
	 * Field values by custom_id.
	 *
	 * @param object $record Form record.
	 * @return array<string,string>
	 */
	private static function values( $record ) {
		$out    = array();
		$fields = $record->get( 'fields' );
		if ( ! is_array( $fields ) ) {
			return $out;
		}
		foreach ( $fields as $id => $field ) {
			$value = '';
			if ( is_array( $field ) ) {
				$value = array_key_exists( 'value', $field ) ? $field['value'] : ( isset( $field['raw_value'] ) ? $field['raw_value'] : '' );
			}
			if ( is_array( $value ) ) {
				$value = implode( ', ', array_filter( $value, 'is_scalar' ) );
			}
			$out[ (string) $id ] = is_scalar( $value ) ? trim( (string) $value ) : '';
		}
		return $out;
	}

	/**
	 * @param array $values Field values.
	 * @return array Raw selection for LP_Calc_Pricing::normalize().
	 */
	private static function raw_selection( array $values ) {
		$raw = array();
		foreach ( self::SELECTION_FIELDS as $key => $field ) {
			$raw[ $key ] = isset( $values[ $field ] ) ? $values[ $field ] : '';
		}
		return $raw;
	}

	/* ------------------------------------------------------------------ 1. Validation */

	/**
	 * Action `elementor_pro/forms/validation`.
	 *
	 * Selection errors are shown as form messages (add_error_message) and also set as field
	 * errors on the hidden lpc_* fields, because only field errors stop Elementor Pro from
	 * running the actions.
	 *
	 * @param object $record       Form record.
	 * @param object $ajax_handler Ajax handler.
	 */
	public static function validate( $record, $ajax_handler ) {
		if ( ! self::is_calculator_form( $record ) || ! is_object( $ajax_handler ) ) {
			return;
		}
		// A new submission: forget the quote of an earlier one in this PHP process.
		self::$state = null;
		try {
			$values = self::values( $record );
			$fields = $record->get( 'fields' );
			$failed = false;

			$norm = LP_Calc_Pricing::normalize( self::raw_selection( $values ) );
			foreach ( $norm['errors'] as $key => $message ) {
				$ajax_handler->add_error_message( $message );
				$ajax_handler->add_error( self::SELECTION_FIELDS[ $key ], $message );
				$failed = true;
			}

			foreach ( self::check_contact( $values, $fields ) as $id => $message ) {
				$ajax_handler->add_error( $id, $message );
				$failed = true;
			}

			// Only count submissions that passed every check, Elementor's own included.
			if ( $failed || ( isset( $ajax_handler->errors ) && ! empty( $ajax_handler->errors ) ) ) {
				return;
			}

			$email = isset( $values['email'] ) ? strtolower( $values['email'] ) : '';
			if ( ! self::rate_limit_allows( self::client_ip(), $email ) ) {
				$ajax_handler->add_error_message( self::LIMIT_MESSAGE );
				$ajax_handler->add_error( 'lpc_version', self::LIMIT_MESSAGE );
			}
		} catch ( Throwable $e ) {
			self::log( 'validation failed: ' . self::describe( $e ) );
		}
	}

	/**
	 * Checks the visible fields. A field the form does not have is skipped; an empty required
	 * field is left to Elementor's own "required" message.
	 *
	 * @param array $values Field values.
	 * @param mixed $fields Record fields.
	 * @return array<string,string> Errors by field id.
	 */
	private static function check_contact( array $values, $fields ) {
		$errors = array();
		$fields = is_array( $fields ) ? $fields : array();
		// Name and company are printed in the PDF sent to the email address typed here, so they
		// must not carry anything a PDF viewer turns into a link (LP_Calc_Pricing::LINK_PATTERN).
		$rules  = array(
			'name'    => array( 2, 80, 'Please enter your full name (2 to 80 characters).', 'Please enter your name without a web or email address.' ),
			'company' => array( 2, 120, 'Please enter your company name (2 to 120 characters).', 'Please enter your company name without a web or email address.' ),
		);
		foreach ( $rules as $id => $rule ) {
			if ( ! isset( $fields[ $id ] ) || self::skip_empty( $values, $fields, $id ) ) {
				continue;
			}
			$len = LP_Calc_Pricing::strlen( $values[ $id ] );
			if ( $len < $rule[0] || $len > $rule[1] ) {
				$errors[ $id ] = $rule[2];
			} elseif ( LP_Calc_Pricing::looks_like_link( $values[ $id ] ) ) {
				$errors[ $id ] = $rule[3];
			}
		}
		if ( isset( $fields['email'] ) && ! self::skip_empty( $values, $fields, 'email' ) ) {
			if ( ! is_email( $values['email'] ) ) {
				$errors['email'] = 'Please enter a valid email address.';
			}
		}
		if ( isset( $fields['phone'] ) && ! self::skip_empty( $values, $fields, 'phone' ) ) {
			$phone = $values['phone'];
			if ( ! preg_match( '/^[0-9+().\- ]{6,25}$/', $phone ) || preg_match_all( '/[0-9]/', $phone ) < 6 ) {
				$errors['phone'] = 'Please enter a valid contact number.';
			}
		}
		if ( isset( $fields['message'], $values['message'] ) && LP_Calc_Pricing::strlen( $values['message'] ) > 1000 ) {
			$errors['message'] = 'Please keep your notes to 1,000 characters or fewer.';
		}
		return $errors;
	}

	/**
	 * @param array  $values Field values.
	 * @param array  $fields Record fields.
	 * @param string $id     Field id.
	 * @return bool True when the value is empty and Elementor already reports it as required.
	 */
	private static function skip_empty( array $values, array $fields, $id ) {
		$value = isset( $values[ $id ] ) ? $values[ $id ] : '';
		return '' === $value && ! empty( $fields[ $id ]['required'] );
	}

	/* ------------------------------------------------------------------ Rate limit */

	/**
	 * Limits, filterable with `lp_calc_rate_limits`. A limit of 0 switches that check off.
	 *
	 * - ip / ip_window: calculator submissions per IP address (IPv6: per /64 network).
	 * - email / email_window: submissions per mailbox (case, "+tag" and Gmail dots ignored).
	 * - global / global_window: client emails the whole site sends. Past it the lead still goes
	 *   to Zoho and the team; only the client email is skipped (email_sent false).
	 *
	 * @return array{ip:int, ip_window:int, email:int, email_window:int, global:int, global_window:int}
	 */
	public static function rate_limits() {
		$defaults = array(
			'ip'            => 5,
			'ip_window'     => HOUR_IN_SECONDS,
			'email'         => 3,
			'email_window'  => DAY_IN_SECONDS,
			'global'        => 30,
			'global_window' => HOUR_IN_SECONDS,
		);
		$limits   = apply_filters( 'lp_calc_rate_limits', $defaults );
		$limits   = is_array( $limits ) ? array_merge( $defaults, $limits ) : $defaults;
		foreach ( $limits as $key => $value ) {
			$limits[ $key ] = max( 0, (int) $value );
		}
		return $limits;
	}

	/**
	 * The visitor's IP address (REMOTE_ADDR), filterable with `lp_calc_client_ip` for sites
	 * behind a proxy that does not restore the real address.
	 *
	 * @return string
	 */
	public static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? trim( (string) $_SERVER['REMOTE_ADDR'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$ip = filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
		return (string) apply_filters( 'lp_calc_client_ip', $ip );
	}

	/**
	 * The mailbox an address delivers to, for the per-email limit: lowercased, without a
	 * "+tag" in the local part, and for Gmail without dots (all of them reach one inbox).
	 *
	 * @param string $email Email address.
	 * @return string
	 */
	public static function canonical_email( $email ) {
		$email = strtolower( trim( (string) $email ) );
		$at    = strrpos( $email, '@' );
		if ( false === $at || 0 === $at ) {
			return $email;
		}
		$local  = substr( $email, 0, $at );
		$domain = substr( $email, $at + 1 );
		$plus   = strpos( $local, '+' );
		if ( false !== $plus && $plus > 0 ) {
			$local = substr( $local, 0, $plus );
		}
		if ( 'gmail.com' === $domain || 'googlemail.com' === $domain ) {
			$local  = str_replace( '.', '', $local );
			$domain = 'gmail.com';
		}
		return $local . '@' . $domain;
	}

	/**
	 * The bucket an IP address counts against: the IPv4 address, or the /64 network of an IPv6
	 * address (one host normally gets a whole /64). IPv4-mapped IPv6 counts as its IPv4 address.
	 *
	 * @param string $ip IP address.
	 * @return string
	 */
	public static function ip_bucket( $ip ) {
		$ip = trim( (string) $ip );
		if ( false === strpos( $ip, ':' ) || ! function_exists( 'inet_pton' ) ) {
			return $ip;
		}
		$bin = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! is_string( $bin ) || 16 !== strlen( $bin ) ) {
			return $ip;
		}
		if ( str_repeat( "\0", 10 ) . "\xff\xff" === substr( $bin, 0, 12 ) ) {
			$v4 = inet_ntop( substr( $bin, 12 ) );
			return is_string( $v4 ) ? $v4 : $ip;
		}
		return bin2hex( substr( $bin, 0, 8 ) ) . '::/64';
	}

	/**
	 * Checks both limits; if the submission is allowed, counts it.
	 *
	 * @param string $ip    IP address ('' skips the IP check).
	 * @param string $email Email address ('' skips the email check).
	 * @return bool
	 */
	public static function rate_limit_allows( $ip, $email ) {
		$limits = self::rate_limits();
		$checks = array();
		if ( '' !== $ip && $limits['ip'] > 0 ) {
			$checks[] = array( self::RL_PREFIX . md5( self::ip_bucket( $ip ) ), $limits['ip'], max( 1, $limits['ip_window'] ) );
		}
		if ( '' !== $email && $limits['email'] > 0 ) {
			$checks[] = array( self::RL_PREFIX . md5( self::canonical_email( $email ) ), $limits['email'], max( 1, $limits['email_window'] ) );
		}
		return self::count_if_allowed( $checks );
	}

	/**
	 * Site-wide cap on client emails: counts one and returns true while under the cap.
	 *
	 * @return bool
	 */
	public static function client_email_allowed() {
		$limits = self::rate_limits();
		if ( $limits['global'] <= 0 ) {
			return true;
		}
		return self::count_if_allowed( array( array( self::RL_PREFIX . 'global', $limits['global'], max( 1, $limits['global_window'] ) ) ) );
	}

	/**
	 * Fixed-window counters in transients. If every counter is under its limit, all of them
	 * are increased and true is returned; otherwise nothing is counted.
	 *
	 * @param array<int,array{0:string,1:int,2:int}> $checks [transient key, limit, window].
	 * @return bool
	 */
	private static function count_if_allowed( array $checks ) {
		$now    = time();
		$counts = array();
		foreach ( $checks as $i => $check ) {
			list( $key, $limit, $window ) = $check;
			$entry = get_transient( $key );
			if ( ! is_array( $entry ) || ! isset( $entry['count'], $entry['start'] ) || $now - (int) $entry['start'] >= $window ) {
				$entry = array(
					'count' => 0,
					'start' => $now,
				);
			}
			if ( (int) $entry['count'] >= $limit ) {
				return false;
			}
			$counts[ $i ] = $entry;
		}
		foreach ( $checks as $i => $check ) {
			list( $key, , $window ) = $check;
			$entry          = $counts[ $i ];
			$entry['count'] = (int) $entry['count'] + 1;
			set_transient( $key, $entry, max( 1, (int) $entry['start'] + $window - $now ) );
		}
		return true;
	}

	/* ------------------------------------------------------------------ 2. Before the actions */

	/**
	 * Action `elementor_pro/forms/process` (the end of Form_Record::process_fields(), before the
	 * actions_before filter): prices the selection and writes the quote fields a first time.
	 * actions_before() writes them again with the same ref. With both, Zoho and the team email get
	 * the server's figures even if one of the two hooks does not run.
	 *
	 * @param object      $record       Form record.
	 * @param object|null $ajax_handler Ajax handler.
	 */
	public static function process( $record, $ajax_handler = null ) {
		if ( ! self::is_calculator_form( $record ) ) {
			return;
		}
		try {
			self::write_quote( $record );
		} catch ( Throwable $e ) {
			self::log( 'process failed: ' . self::describe( $e ) );
		}
	}

	/**
	 * Filter `elementor_pro/forms/record/actions_before`: prices the selection on the server and
	 * writes the quote fields that the email and Zoho actions read.
	 *
	 * @param object      $record       Form record.
	 * @param object|null $ajax_handler Ajax handler.
	 * @return object The record.
	 */
	public static function actions_before( $record, $ajax_handler = null ) {
		if ( ! self::is_calculator_form( $record ) ) {
			self::$state = null;
			return $record;
		}
		try {
			if ( null === self::write_quote( $record ) ) {
				self::log( 'selection invalid after validation; quote fields not written' );
			}
		} catch ( Throwable $e ) {
			self::$state = null;
			self::log( 'actions_before failed: ' . self::describe( $e ) );
		}
		return $record;
	}

	/**
	 * Prices the record's selection and overwrites the quote fields, whatever the page (or a
	 * tampered post) sent. Keeps the ref when this record already has a quote (process() and then
	 * actions_before()); otherwise makes a new one. The visitor's quote_ref is never used.
	 *
	 * @param object $record Form record.
	 * @return array|null The state (selection, quote, ref), or null when the selection is invalid.
	 */
	private static function write_quote( $record ) {
		$previous    = self::$state;
		self::$state = null;

		$norm = LP_Calc_Pricing::normalize( self::raw_selection( self::values( $record ) ) );
		if ( ! empty( $norm['errors'] ) ) {
			return null;
		}
		$selection = $norm['selection'];
		$quote     = LP_Calc_Pricing::compute( $selection );
		$key       = spl_object_hash( $record );
		$ref       = is_array( $previous ) && isset( $previous['record'], $previous['ref'] ) && $key === $previous['record'] ? $previous['ref'] : self::make_ref();

		$services = (array) $selection['services'];
		$updates  = array(
			'quote_ref'          => $ref,
			'quote_total'        => LP_Calc_Pricing::money( $quote['total'] ),
			'quote_summary'      => LP_Calc_Pricing::summary_text( $selection, $quote, $ref ),
			'field_lead_form'    => self::LEAD_FORM,
			'lead_source'        => self::LEAD_SOURCE,
			'lpc_services'       => LP_Calc_Pricing::services_text( $selection ),
			// The saved submission must match the quote: inputs that do not apply are emptied,
			// whatever the page (or a tampered post) sent.
			'lpc_business'       => (string) $selection['business'],
			'lpc_business_other' => 'Other' === $selection['business'] ? (string) $selection['business_other'] : '',
			'lpc_employees'      => in_array( 'payroll', $services, true ) ? LP_Calc_Pricing::employees_label( $selection['employees'] ) : '',
			'lpc_reports'        => in_array( 'reports', $services, true ) ? (string) (int) $selection['reports'] : '',
		);
		if ( method_exists( $record, 'update_field' ) ) {
			foreach ( $updates as $id => $value ) {
				$record->update_field( $id, 'value', $value );
				$record->update_field( $id, 'raw_value', $value );
			}
		}

		self::$state = array(
			'selection' => $selection,
			'quote'     => $quote,
			'ref'       => $ref,
			'record'    => $key,
		);
		return self::$state;
	}

	/**
	 * A quote reference: LPQ-YYMMDD-XXXXX (UTC date, 5 uppercase hex characters).
	 *
	 * @return string
	 */
	public static function make_ref() {
		return 'LPQ-' . gmdate( 'ymd' ) . '-' . strtoupper( substr( bin2hex( random_bytes( 3 ) ), 0, 5 ) );
	}

	/* ------------------------------------------------------------------ 3. After the actions */

	/**
	 * Action `elementor_pro/forms/new_record`: builds the PDF, emails the client and returns
	 * the server's breakdown to the page. Never breaks the form response.
	 *
	 * @param object $record       Form record.
	 * @param object $ajax_handler Ajax handler.
	 */
	public static function new_record( $record, $ajax_handler ) {
		if ( ! self::is_calculator_form( $record ) ) {
			return;
		}
		$dir  = '';
		$path = '';
		try {
			$state = self::$state;
			if ( null === $state ) {
				$state = self::state_from_record( $record );
			}
			if ( null === $state ) {
				self::log( 'no quote for this submission' );
				return;
			}
			$values  = self::values( $record );
			$contact = array();
			foreach ( array( 'name', 'company', 'email', 'phone', 'message' ) as $key ) {
				$contact[ $key ] = isset( $values[ $key ] ) ? $values[ $key ] : '';
			}
			$data = array(
				'ref'       => $state['ref'],
				'date'      => LP_Calc_Quote_PDF::date_text(),
				'contact'   => $contact,
				'selection' => $state['selection'],
				'quote'     => $state['quote'],
			);

			try {
				$pdf = LP_Calc_Quote_PDF::render( $data );
				$dir = self::temp_dir();
				if ( '' !== $dir ) {
					$file = $dir . '/' . LP_Calc_Quote_PDF::filename( $data['ref'] );
					if ( false !== file_put_contents( $file, $pdf ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
						$path = $file;
					} else {
						self::log( 'could not write the PDF for ' . $data['ref'] );
					}
				}
			} catch ( Throwable $e ) {
				self::log( 'PDF failed for ' . $data['ref'] . ': ' . self::describe( $e ) );
			}

			$sent = false;
			if ( ! is_email( $contact['email'] ) ) {
				self::log( 'no valid client email for ' . $data['ref'] );
			} elseif ( ! self::client_email_allowed() ) {
				// Site-wide cap reached: the lead has gone to Zoho and the team already.
				self::log( 'site-wide client email limit reached; no client email for ' . $data['ref'] );
			} else {
				try {
					$sent = LP_Calc_Mailer::send( $data, '' !== $path ? $path : null );
				} catch ( Throwable $e ) {
					self::log( 'client email failed for ' . $data['ref'] . ': ' . self::describe( $e ) );
				}
				if ( ! $sent ) {
					self::log( 'client email not sent for ' . $data['ref'] );
				}
			}

			if ( is_object( $ajax_handler ) && method_exists( $ajax_handler, 'add_response_data' ) ) {
				$ajax_handler->add_response_data( 'lp_quote', self::response_data( $data, $sent ) );
			}
		} catch ( Throwable $e ) {
			self::log( 'new_record failed: ' . self::describe( $e ) );
		} finally {
			self::remove_temp( $dir, $path );
			self::$state = null;
		}
	}

	/**
	 * The `lp_quote` response data.
	 *
	 * @param array $data Quote data.
	 * @param bool  $sent Whether the client email was sent.
	 * @return array
	 */
	public static function response_data( array $data, $sent ) {
		$lines = array();
		foreach ( $data['quote']['lines'] as $line ) {
			$lines[] = array(
				'key'    => $line['key'],
				'label'  => $line['label'],
				'detail' => $line['detail'],
				'amount' => LP_Calc_Pricing::line_amount_text( $line ),
			);
		}
		return array(
			'ref'        => $data['ref'],
			'lines'      => $lines,
			'total'      => LP_Calc_Pricing::money( (int) $data['quote']['total'] ),
			'email'      => (string) $data['contact']['email'],
			'email_sent' => (bool) $sent,
		);
	}

	/**
	 * Rebuilds the state when neither process() nor actions_before() priced this record. The quote
	 * fields are written now (with a new server ref), so the record matches the client email, but
	 * the form actions have already run without them: that is logged.
	 *
	 * @param object $record Form record.
	 * @return array|null
	 */
	private static function state_from_record( $record ) {
		$state = self::write_quote( $record );
		if ( null !== $state ) {
			self::log( 'quote fields written after the form actions for ' . $state['ref'] . '; Zoho and the team email may lack the figures' );
		}
		return $state;
	}

	/**
	 * A new private folder in the temp directory, so the attachment keeps its file name.
	 *
	 * The folder is also removed at shutdown: a fatal error or time-out while wp_mail() waits on
	 * the SMTP relay skips the `finally` in new_record(). Folders left by a hard kill are swept
	 * here after an hour.
	 *
	 * @return string Folder path, or '' on failure.
	 */
	private static function temp_dir() {
		$base = rtrim( (string) ( function_exists( 'get_temp_dir' ) ? get_temp_dir() : sys_get_temp_dir() ), '/\\' );
		self::sweep_temp( $base );
		$dir = $base . '/lp-calc-' . bin2hex( random_bytes( 8 ) );
		if ( ! @mkdir( $dir, 0700 ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
			self::log( 'could not create a temporary folder' );
			return '';
		}
		register_shutdown_function( array( __CLASS__, 'remove_temp_on_shutdown' ), $dir );
		return $dir;
	}

	/**
	 * Shutdown callback: deletes the request's temporary folder if it is still there.
	 *
	 * @param string $dir Folder from temp_dir().
	 */
	public static function remove_temp_on_shutdown( $dir ) {
		if ( is_string( $dir ) && 1 === preg_match( '#/lp-calc-[0-9a-f]{16}$#', $dir ) ) {
			self::remove_temp( $dir, '' );
		}
	}

	/**
	 * Deletes lp-calc-* folders older than an hour that earlier requests left behind.
	 *
	 * @param string $base Temp directory.
	 */
	private static function sweep_temp( $base ) {
		$old = time() - HOUR_IN_SECONDS;
		foreach ( (array) glob( $base . '/lp-calc-*', GLOB_ONLYDIR ) as $left ) {
			if ( is_string( $left ) && 1 === preg_match( '#/lp-calc-[0-9a-f]{16}$#', $left ) && (int) @filemtime( $left ) < $old ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				self::remove_temp( $left, '' );
			}
		}
	}

	/**
	 * Deletes the temporary PDF and its folder.
	 *
	 * @param string $dir  Folder.
	 * @param string $path File.
	 */
	private static function remove_temp( $dir, $path ) {
		if ( '' !== $path && is_file( $path ) ) {
			@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		}
		if ( '' !== $dir && is_dir( $dir ) ) {
			foreach ( (array) glob( $dir . '/Ledger-Plus-Quotation-*.pdf' ) as $left ) {
				if ( is_string( $left ) && is_file( $left ) ) {
					@unlink( $left ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
				}
			}
			@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		}
	}

	/* ------------------------------------------------------------------ Helpers */

	/**
	 * An exception for the log, without personal data.
	 *
	 * @param Throwable $e Exception.
	 * @return string
	 */
	private static function describe( $e ) {
		$msg = (string) $e->getMessage();
		$msg = (string) preg_replace( '/[^\s@<>"\']+@[^\s@<>"\']+/', '[email]', $msg );
		$msg = (string) preg_replace( '/\+?\d[\d\s().-]{5,}\d/', '[number]', $msg );
		return get_class( $e ) . ': ' . substr( $msg, 0, 200 ) . ' (' . basename( $e->getFile() ) . ':' . $e->getLine() . ')';
	}

	/**
	 * @param string $message Message without personal data.
	 */
	private static function log( $message ) {
		error_log( '[lp-calc] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
	}

	/**
	 * The state of the current request (tests and debugging).
	 *
	 * @return array|null
	 */
	public static function state() {
		return self::$state;
	}
}
