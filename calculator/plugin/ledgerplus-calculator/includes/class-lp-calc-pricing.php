<?php
/**
 * Pricing rules: the single source of truth for the calculator.
 *
 * Prices are annual, in AED, include 5% VAT and are whole dirhams.
 *
 * @package LedgerPlusCalculator
 */

defined( 'ABSPATH' ) || exit;

/**
 * Config for the UI, server-side validation and the quote.
 */
final class LP_Calc_Pricing {

	const CURRENCY    = 'AED';
	const VAT_RATE    = 5;
	const TOTAL_LABEL = 'Estimated annual total (incl. 5% VAT)';

	const BUSINESS_TYPES = array(
		'Trading / Wholesale',
		'Retail',
		'Restaurant / Hospitality',
		'E-commerce',
		'Construction / Contracting',
		'Real Estate',
		'Professional Services',
		'Other',
	);

	const TRANSACTIONS = array( 1000, 2000, 3000, 4000, 5000, 10000, 20000, 30000, 40000, 50000 );

	const REPORTS_OPTIONS = array( 1, 2, 3, 4 );

	const STATUSES = array( 'registered', 'not_registered' );

	const EMPLOYEES = array( 'up_to_20', 'over_20' );

	/** Service keys in the order used everywhere (UI, quote lines, PDF, email). */
	const SERVICE_KEYS = array( 'accounting', 'reports', 'vat', 'ct', 'payroll', 'expense', 'invoicing', 'einvoicing', 'account_manager' );

	const DEFAULTS = array(
		'business'       => '',
		'business_other' => '',
		'transactions'   => 1000,
		'vat_status'     => '',
		'ct_status'      => '',
		'employees'      => 'up_to_20',
		'reports'        => 4,
		'services'       => array(),
	);

	const BUSINESS_OTHER_MAX = 80;

	/**
	 * Text that email apps or PDF viewers turn into a link: a scheme ("https://", "mailto:"),
	 * "www." or an email address. Visitor text that reaches the client email or the PDF
	 * (name, company, the "Other" activity) must not contain it.
	 */
	const LINK_PATTERN = '#://|\bwww\.|@|\bmailto:#i';

	/**
	 * Default prices (AED a year, VAT included).
	 *
	 * @return array<string,int>
	 */
	public static function default_prices() {
		return array(
			'accounting'          => 2000,
			'report_each'         => 500,
			'vat'                 => 2000,
			'vat_registration'    => 500,
			'ct_registered'       => 1500,
			'ct_not_registered'   => 2000,
			'payroll'             => 1000,
			'payroll_over_20'     => 1000,
			'expense'             => 500,
			'invoicing'           => 1000,
			'einvoicing_per_1000' => 500,
			'account_manager'     => 0,
		);
	}

	/**
	 * Service labels by key, in table order.
	 *
	 * @return array<string,string>
	 */
	public static function service_labels() {
		return array(
			'accounting'      => 'Accounting and bookkeeping',
			'reports'         => 'Quarterly financial reports',
			'vat'             => 'VAT returns filing and compliance',
			'ct'              => 'Corporate Tax registration and filing',
			'payroll'         => 'Payroll and WPS support',
			'expense'         => 'Expense management',
			'invoicing'       => 'Invoicing and payment tracking',
			'einvoicing'      => 'UAE e-invoicing',
			'account_manager' => 'Dedicated account manager',
		);
	}

	/**
	 * The full config: choices, labels, prices and the service price `hint` texts. Filterable as a
	 * whole with `lp_calc_config`. The server prices quotes with it; the page gets only
	 * browser_config(), which has no prices.
	 *
	 * If a filter changes `prices`, it should change the matching service `hint` too.
	 *
	 * @return array
	 */
	public static function config() {
		$p      = self::default_prices();
		$labels = self::service_labels();

		$hints = array(
			'accounting'      => self::money( $p['accounting'] ) . ' a year',
			'reports'         => self::money( $p['report_each'] ) . ' per report',
			'vat'             => 'From ' . self::money( $p['vat'] ) . ' a year',
			'ct'              => 'From ' . self::money( min( $p['ct_registered'], $p['ct_not_registered'] ) ) . ' a year',
			'payroll'         => 'From ' . self::money( $p['payroll'] ) . ' a year',
			'expense'         => self::money( $p['expense'] ) . ' a year',
			'invoicing'       => self::money( $p['invoicing'] ) . ' a year',
			'einvoicing'      => self::money( $p['einvoicing_per_1000'] ) . ' per 1,000 transactions',
			'account_manager' => 'Included',
		);

		// Short factual descriptions for the service cards (optional for the UI). No prices: the
		// page shows prices only after the visitor has sent their details.
		$descriptions = array(
			'accounting'      => 'Bookkeeping and accounts kept up to date all year.',
			'reports'         => 'Financial reports each quarter, 1 to 4 a year.',
			'vat'             => 'VAT returns filed for you. Registration included if you are not registered.',
			'ct'              => 'Corporate Tax return filed for you. Registration included if you are not registered.',
			'payroll'         => 'Payroll runs and WPS salary files.',
			'expense'         => 'Expense claims and receipts tracked.',
			'invoicing'       => 'Invoices issued and customer payments tracked.',
			'einvoicing'      => 'UAE e-invoicing, priced by your annual transactions.',
			'account_manager' => 'One point of contact for your account.',
		);

		$services = array();
		foreach ( self::SERVICE_KEYS as $key ) {
			$services[] = array(
				'key'         => $key,
				'label'       => $labels[ $key ],
				'hint'        => $hints[ $key ],
				'description' => $descriptions[ $key ],
			);
		}

		$business_types = array();
		foreach ( self::BUSINESS_TYPES as $type ) {
			$business_types[] = array(
				'value' => $type,
				'label' => $type,
			);
		}

		$transactions = array();
		foreach ( self::TRANSACTIONS as $n ) {
			$transactions[] = array(
				'value' => $n,
				'label' => self::transactions_label( $n ),
			);
		}

		$config = array(
			'currency'       => self::CURRENCY,
			'vat_included'   => true,
			'vat_rate'       => self::VAT_RATE,
			'total_label'    => self::TOTAL_LABEL,
			'business_types' => $business_types,
			'transactions'   => $transactions,
			'reports_options' => self::REPORTS_OPTIONS,
			'employees'      => array(
				array(
					'value' => 'up_to_20',
					'label' => 'Up to 20 employees',
				),
				array(
					'value' => 'over_20',
					'label' => 'More than 20 employees',
				),
			),
			'statuses'       => array(
				array(
					'value' => 'registered',
					'label' => 'Already registered',
				),
				array(
					'value' => 'not_registered',
					'label' => 'Not registered',
				),
			),
			'services'       => $services,
			'prices'         => $p,
			'defaults'       => array(
				'transactions' => 1000,
				'employees'    => 'up_to_20',
				'reports'      => 4,
				'services'     => array( 'einvoicing' ),
			),
		);

		$filtered = apply_filters( 'lp_calc_config', $config );
		if ( ! is_array( $filtered ) ) {
			return $config;
		}
		$filtered['prices'] = self::sanitize_prices( isset( $filtered['prices'] ) ? $filtered['prices'] : array() );
		return $filtered;
	}

	/**
	 * The part of config() the page gets: the choices and their labels, without prices.
	 *
	 * Built from the filtered config(), so an `lp_calc_config` filter still applies first. Only the
	 * keys below are copied, and of each option only `value` and `label`. `prices`, `currency`,
	 * `vat_rate` and the service `hint` texts never reach the page. Every text that is copied (option
	 * labels, service labels and descriptions, the total label) is checked with mentions_price():
	 * - a priced description is left out (the template shows its own text);
	 * - a priced label falls back to the plugin's own label for that value or service;
	 * - an option or service the plugin does not know, with a priced label or value, is left out
	 *   (the server accepts only its own choices, so it could not be sent anyway).
	 * The page shows prices only after the server has priced a submission.
	 *
	 * @return array
	 */
	public static function browser_config() {
		$config = self::config();
		$prices = $config['prices'];
		$clean  = static function ( $text ) use ( $prices ) {
			return is_scalar( $text ) && ! is_bool( $text ) && '' !== trim( (string) $text ) && ! self::mentions_price( $text, $prices );
		};
		$out    = array();

		foreach ( array( 'business_types', 'transactions', 'reports_options', 'employees', 'statuses' ) as $key ) {
			if ( isset( $config[ $key ] ) && is_array( $config[ $key ] ) ) {
				$out[ $key ] = self::browser_options( $key, $config[ $key ], $clean );
			}
		}

		$out['services'] = array();
		$labels          = self::service_labels();
		$services        = isset( $config['services'] ) && is_array( $config['services'] ) ? $config['services'] : array();
		foreach ( $services as $service ) {
			if ( ! is_array( $service ) || empty( $service['key'] ) || ! is_scalar( $service['key'] ) || ! $clean( $service['key'] ) ) {
				continue;
			}
			$key   = (string) $service['key'];
			$label = isset( $service['label'] ) ? $service['label'] : $key;
			if ( ! $clean( $label ) ) {
				if ( ! isset( $labels[ $key ] ) ) {
					continue; // A service a filter added, with a priced label.
				}
				$label = $labels[ $key ];
			}
			$item = array(
				'key'   => $key,
				'label' => (string) $label,
			);
			if ( isset( $service['description'] ) && $clean( $service['description'] ) ) {
				$item['description'] = (string) $service['description'];
			}
			$out['services'][] = $item;
		}

		// Defaults: only values the page offers.
		$defaults        = isset( $config['defaults'] ) && is_array( $config['defaults'] ) ? $config['defaults'] : array();
		$offered         = static function ( $key ) use ( $out ) {
			return isset( $out[ $key ] ) ? array_map( 'strval', array_column( $out[ $key ], 'value' ) ) : array();
		};
		$out['defaults'] = array();
		if ( isset( $defaults['transactions'] ) && is_numeric( $defaults['transactions'] ) && in_array( (string) (int) $defaults['transactions'], $offered( 'transactions' ), true ) ) {
			$out['defaults']['transactions'] = (int) $defaults['transactions'];
		}
		if ( isset( $defaults['employees'] ) && is_scalar( $defaults['employees'] ) && in_array( (string) $defaults['employees'], $offered( 'employees' ), true ) ) {
			$out['defaults']['employees'] = (string) $defaults['employees'];
		}
		if ( isset( $defaults['reports'] ) && is_numeric( $defaults['reports'] ) && in_array( (int) $defaults['reports'], isset( $out['reports_options'] ) ? $out['reports_options'] : array(), true ) ) {
			$out['defaults']['reports'] = (int) $defaults['reports'];
		}
		if ( isset( $defaults['services'] ) && is_array( $defaults['services'] ) ) {
			$keys                         = array_column( $out['services'], 'key' );
			$out['defaults']['services'] = array_values( array_filter( $defaults['services'], static function ( $k ) use ( $keys ) {
				return is_string( $k ) && in_array( $k, $keys, true );
			} ) );
		}

		// The label of the total on the result screen, next to the server's figures.
		$out['total_label'] = isset( $config['total_label'] ) && $clean( $config['total_label'] ) ? (string) $config['total_label'] : self::TOTAL_LABEL;
		return $out;
	}

	/**
	 * One option list of browser_config(): `value` and `label` of each option, nothing else.
	 *
	 * - reports_options: the numbers only (the page writes "N reports a year" itself).
	 * - transactions: a numeric value. Other lists: a value without a price.
	 * - A label with a price falls back to the plugin's own label for that value. An option the
	 *   plugin does not know, with a priced label, is left out.
	 *
	 * @param string   $key   business_types|transactions|reports_options|employees|statuses.
	 * @param array    $list  The list from config().
	 * @param callable $clean Returns true for a non-empty text without a price.
	 * @return array
	 */
	private static function browser_options( $key, array $list, $clean ) {
		$out = array();
		foreach ( $list as $item ) {
			$value = is_array( $item ) && isset( $item['value'] ) ? $item['value'] : $item;
			if ( ! is_scalar( $value ) || is_bool( $value ) || '' === (string) $value ) {
				continue;
			}
			if ( 'reports_options' === $key ) {
				if ( is_numeric( $value ) && (int) $value > 0 ) {
					$out[] = (int) $value;
				}
				continue;
			}
			// A transaction count is a number by nature; any other value is text the page prints.
			if ( 'transactions' === $key ? ! is_numeric( $value ) : ! $clean( $value ) ) {
				continue;
			}
			$value = 'transactions' === $key ? (int) $value : (string) $value;
			$given = is_array( $item ) && isset( $item['label'] );
			$label = $given ? $item['label'] : null;
			if ( ! $clean( $label ) ) {
				switch ( $key ) {
					case 'business_types':
						$own = in_array( $value, self::BUSINESS_TYPES, true ) ? $value : null;
						break;
					case 'transactions':
						$own = in_array( $value, self::TRANSACTIONS, true ) ? self::transactions_label( $value ) : null;
						break;
					case 'employees':
						$own = in_array( $value, self::EMPLOYEES, true ) ? self::employees_label( $value ) : null;
						break;
					default: // statuses.
						$own = in_array( $value, self::STATUSES, true ) ? self::status_label( $value ) : null;
				}
				if ( null === $own && $given ) {
					continue; // An option a filter added, with a priced label.
				}
				$label = null !== $own ? $own : ( 'transactions' === $key ? self::transactions_label( $value ) : $value );
			}
			$out[] = array(
				'value' => $value,
				'label' => (string) $label,
			);
		}
		return $out;
	}

	/**
	 * The "Your package" lines of step 5: one per selected service, with a short detail and no
	 * amount. calculator.js builds the same lines in the browser (test/ui/no-prices.test.js
	 * compares them for every selection).
	 *
	 * @param array      $selection Selection (services, transactions, vat_status, ct_status, employees, reports).
	 * @param array|null $config    From browser_config() (default): labels for services and options.
	 * @return array<int,array{key:string,label:string,detail:string}>
	 */
	public static function package_lines( array $selection, $config = null ) {
		$config  = is_array( $config ) ? $config : self::browser_config();
		$sel     = array_merge( self::DEFAULTS, $selection );
		$chosen  = is_array( $sel['services'] ) ? $sel['services'] : array();
		$options = static function ( $key, $value, $fallback ) use ( $config ) {
			foreach ( isset( $config[ $key ] ) ? (array) $config[ $key ] : array() as $item ) {
				if ( is_array( $item ) && isset( $item['value'], $item['label'] ) && (string) $item['value'] === (string) $value ) {
					return (string) $item['label'];
				}
			}
			return $fallback;
		};
		$lines = array();
		foreach ( isset( $config['services'] ) ? (array) $config['services'] : array() as $service ) {
			$key = is_array( $service ) && isset( $service['key'] ) ? (string) $service['key'] : '';
			if ( '' === $key || ! in_array( $key, $chosen, true ) ) {
				continue;
			}
			switch ( $key ) {
				case 'reports':
					$n      = in_array( (int) $sel['reports'], self::REPORTS_OPTIONS, true ) ? (int) $sel['reports'] : 4;
					$detail = $n . ( 1 === $n ? ' report a year' : ' reports a year' );
					break;
				case 'vat':
					$detail = 'not_registered' === $sel['vat_status'] ? 'Includes VAT registration' : 'Already VAT registered';
					break;
				case 'ct':
					$detail = 'registered' === $sel['ct_status'] ? 'Already registered for Corporate Tax' : 'Includes Corporate Tax registration';
					break;
				case 'payroll':
					$emp    = 'over_20' === $sel['employees'] ? 'over_20' : 'up_to_20';
					$detail = $options( 'employees', $emp, self::employees_label( $emp ) );
					break;
				case 'einvoicing':
					$tx     = max( 0, (int) $sel['transactions'] );
					$detail = $options( 'transactions', $tx, self::transactions_label( $tx ) );
					break;
				case 'account_manager':
					$detail = 'One point of contact';
					break;
				default: // accounting, expense, invoicing, and any service a filter adds.
					$detail = 'Annual service';
			}
			$lines[] = array(
				'key'    => $key,
				'label'  => isset( $service['label'] ) && '' !== (string) $service['label'] ? (string) $service['label'] : $key,
				'detail' => $detail,
			);
		}
		return $lines;
	}

	/**
	 * Does the text state a price? True when it has:
	 * - a currency: "AED" (also "AED2,150"), "dirham", "Dh" or "Dhs", the sign "د.إ", USD, EUR, GBP,
	 *   or $, € or £ before a number;
	 * - a price word: "extra charge", "free of charge", "no charge", "per report", or "Included" with
	 *   a capital I (the old price label; "Registration included" is scope, not a price);
	 * - a non-zero price of default_prices() or of $prices written as "1,234" or "1234", not part of
	 *   a longer number. A number followed by "%" or by "transactions", "employees" or "reports" is a
	 *   count, not a price.
	 *
	 * @param mixed      $text   Text.
	 * @param array|null $prices Prices to look for as well, such as the filtered config()['prices'].
	 * @return bool
	 */
	public static function mentions_price( $text, $prices = null ) {
		if ( ! is_scalar( $text ) || is_bool( $text ) ) {
			return false;
		}
		$s = (string) $text;
		if ( 1 === preg_match( '/\b(?:AED|USD|EUR|GBP)(?![a-z])|dirham|\bdhs?\b|د\.?\s*إ|[$€£]\s?\d|extra charge|free of charge|\bno charge\b|\bper report\b/iu', $s ) || 1 === preg_match( '/\bIncluded\b/', $s ) ) {
			return true;
		}
		$values = array();
		foreach ( array_merge( array_values( self::default_prices() ), is_array( $prices ) ? array_values( $prices ) : array() ) as $p ) {
			if ( is_numeric( $p ) && (int) $p > 0 ) {
				$values[ (int) $p ] = true;
			}
		}
		foreach ( array_keys( $values ) as $p ) {
			$re = '/(?<![\d.,])(?:' . preg_quote( number_format( $p ), '/' ) . '|' . $p . ')(?![\d%]|[.,]\d)(?!\s*(?:transactions?|employees?|reports?)\b)/i';
			if ( 1 === preg_match( $re, $s ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Prices from the (filtered) config. Missing or broken values fall back to the defaults.
	 *
	 * @return array<string,int>
	 */
	public static function prices() {
		$config = self::config();
		return $config['prices'];
	}

	/**
	 * @param mixed $prices Prices from a filter.
	 * @return array<string,int>
	 */
	private static function sanitize_prices( $prices ) {
		$out = self::default_prices();
		if ( ! is_array( $prices ) ) {
			return $out;
		}
		foreach ( $out as $key => $default ) {
			if ( isset( $prices[ $key ] ) && is_numeric( $prices[ $key ] ) ) {
				$out[ $key ] = max( 0, (int) round( (float) $prices[ $key ] ) );
			}
		}
		return $out;
	}

	/**
	 * Validates the raw strings against the whitelists.
	 *
	 * Keys of $raw: business, business_other, transactions, vat_status, ct_status, employees,
	 * reports, services (comma-separated keys, or an array). Inputs that do not apply are set
	 * to their defaults.
	 *
	 * @param array $raw Raw values (strings).
	 * @return array{selection: array, errors: array<string,string>} Errors are keyed by input name.
	 */
	public static function normalize( array $raw ) {
		$sel    = self::DEFAULTS;
		$errors = array();
		$get    = static function ( $key ) use ( $raw ) {
			if ( ! isset( $raw[ $key ] ) || is_array( $raw[ $key ] ) || is_object( $raw[ $key ] ) ) {
				return '';
			}
			return trim( (string) $raw[ $key ] );
		};

		// Services first: they decide which other inputs apply.
		$services_raw = isset( $raw['services'] ) ? $raw['services'] : '';
		if ( is_string( $services_raw ) ) {
			$services_raw = explode( ',', $services_raw );
		}
		$services = array();
		$unknown  = false;
		if ( is_array( $services_raw ) ) {
			$by_label = array();
			foreach ( self::service_labels() as $key => $label ) {
				$by_label[ strtolower( $label ) ] = $key;
			}
			foreach ( $services_raw as $item ) {
				if ( ! is_scalar( $item ) ) {
					$unknown = true;
					continue;
				}
				$item = strtolower( trim( (string) $item ) );
				if ( '' === $item ) {
					continue;
				}
				if ( in_array( $item, self::SERVICE_KEYS, true ) ) {
					$services[ $item ] = true;
				} elseif ( isset( $by_label[ $item ] ) ) {
					$services[ $by_label[ $item ] ] = true;
				} else {
					$unknown = true;
				}
			}
		}
		$sel['services'] = array_values( array_filter( self::SERVICE_KEYS, static function ( $key ) use ( $services ) {
			return isset( $services[ $key ] );
		} ) );
		if ( $unknown ) {
			$errors['services'] = 'One of the selected services is not available. Please refresh the page and choose again.';
		} elseif ( empty( $sel['services'] ) ) {
			$errors['services'] = 'Choose at least one service.';
		}

		// Business activity.
		$business = $get( 'business' );
		$match    = '';
		foreach ( self::BUSINESS_TYPES as $type ) {
			if ( 0 === strcasecmp( $type, $business ) ) {
				$match = $type;
				break;
			}
		}
		if ( '' === $match ) {
			$errors['business'] = 'Choose your business activity.';
		} else {
			$sel['business'] = $match;
		}
		if ( 'Other' === $sel['business'] ) {
			$other = self::clean_text( $get( 'business_other' ) );
			$len   = self::strlen( $other );
			if ( 0 === $len ) {
				$errors['business_other'] = 'Tell us your business activity.';
			} elseif ( $len > self::BUSINESS_OTHER_MAX ) {
				$errors['business_other'] = 'Keep your business activity to 80 characters or fewer.';
			} elseif ( self::looks_like_link( $other ) ) {
				$errors['business_other'] = 'Please describe your business activity without a web or email address.';
			} else {
				$sel['business_other'] = $other;
			}
		}

		// Annual transactions (accepts "10000" or "10,000").
		$tx = str_replace( array( ',', ' ' ), '', $get( 'transactions' ) );
		if ( '' !== $tx && ctype_digit( $tx ) && in_array( (int) $tx, self::TRANSACTIONS, true ) ) {
			$sel['transactions'] = (int) $tx;
		} else {
			$errors['transactions'] = 'Choose your annual number of transactions.';
		}

		// Tax status: always asked.
		$vat = strtolower( $get( 'vat_status' ) );
		if ( in_array( $vat, self::STATUSES, true ) ) {
			$sel['vat_status'] = $vat;
		} else {
			$errors['vat_status'] = 'Tell us whether your business is registered for VAT.';
		}
		$ct = strtolower( $get( 'ct_status' ) );
		if ( in_array( $ct, self::STATUSES, true ) ) {
			$sel['ct_status'] = $ct;
		} else {
			$errors['ct_status'] = 'Tell us whether your business is registered for Corporate Tax.';
		}

		// Employees: only with payroll.
		if ( in_array( 'payroll', $sel['services'], true ) ) {
			$emp = strtolower( $get( 'employees' ) );
			// The label is accepted too: actions_before() writes it back into the record.
			foreach ( self::EMPLOYEES as $value ) {
				if ( strtolower( self::employees_label( $value ) ) === $emp ) {
					$emp = $value;
				}
			}
			if ( in_array( $emp, self::EMPLOYEES, true ) ) {
				$sel['employees'] = $emp;
			} else {
				$errors['employees'] = 'Choose how many employees you have.';
			}
		}

		// Reports a year: only with reports.
		if ( in_array( 'reports', $sel['services'], true ) ) {
			$rep = $get( 'reports' );
			if ( '' !== $rep && ctype_digit( $rep ) && in_array( (int) $rep, self::REPORTS_OPTIONS, true ) ) {
				$sel['reports'] = (int) $rep;
			} else {
				$errors['reports'] = 'Choose how many financial reports you need a year (1 to 4).';
			}
		}

		return array(
			'selection' => $sel,
			'errors'    => $errors,
		);
	}

	/**
	 * Prices a normalized selection.
	 *
	 * @param array $selection From normalize().
	 * @return array{lines: array<int,array{key:string,label:string,detail:string,amount:int}>, total: int}
	 */
	public static function compute( array $selection ) {
		$sel    = array_merge( self::DEFAULTS, $selection );
		$p      = self::prices();
		$labels = self::service_labels();
		$chosen = is_array( $sel['services'] ) ? $sel['services'] : array();
		$lines  = array();

		foreach ( self::SERVICE_KEYS as $key ) {
			if ( ! in_array( $key, $chosen, true ) ) {
				continue;
			}
			switch ( $key ) {
				case 'accounting':
					$amount = $p['accounting'];
					$detail = 'Annual service';
					break;
				case 'reports':
					$n      = in_array( (int) $sel['reports'], self::REPORTS_OPTIONS, true ) ? (int) $sel['reports'] : 4;
					$amount = $n * $p['report_each'];
					$detail = $n . ( 1 === $n ? ' report' : ' reports' ) . ' a year × ' . self::money( $p['report_each'] );
					break;
				case 'vat':
					if ( 'not_registered' === $sel['vat_status'] ) {
						$amount = $p['vat'] + $p['vat_registration'];
						$detail = 'Includes VAT registration (' . self::money( $p['vat_registration'] ) . ')';
					} else {
						$amount = $p['vat'];
						$detail = 'Already VAT registered';
					}
					break;
				case 'ct':
					if ( 'registered' === $sel['ct_status'] ) {
						$amount = $p['ct_registered'];
						$detail = 'Already registered for Corporate Tax';
					} else {
						$amount = $p['ct_not_registered'];
						$detail = 'Includes Corporate Tax registration';
					}
					break;
				case 'payroll':
					if ( 'over_20' === $sel['employees'] ) {
						$amount = $p['payroll'] + $p['payroll_over_20'];
						$detail = 'More than 20 employees';
					} else {
						$amount = $p['payroll'];
						$detail = 'Up to 20 employees';
					}
					break;
				case 'einvoicing':
					$tx     = max( 0, (int) $sel['transactions'] );
					$amount = (int) ceil( $tx / 1000 ) * $p['einvoicing_per_1000'];
					$detail = number_format( $tx ) . ' transactions a year (' . self::money( $p['einvoicing_per_1000'] ) . ' per 1,000)';
					break;
				case 'account_manager':
					$amount = $p['account_manager'];
					$detail = 'Included';
					break;
				default: // expense, invoicing.
					$amount = $p[ $key ];
					$detail = 'Annual service';
			}
			$lines[] = array(
				'key'    => $key,
				'label'  => $labels[ $key ],
				'detail' => $detail,
				'amount' => (int) $amount,
			);
		}

		$total = 0;
		foreach ( $lines as $line ) {
			$total += $line['amount'];
		}

		return array(
			'lines' => $lines,
			'total' => $total,
		);
	}

	/**
	 * @param int|float $amount Whole dirhams.
	 * @return string For example "AED 7,000".
	 */
	public static function money( $amount ) {
		return self::CURRENCY . ' ' . number_format( (int) round( (float) $amount ) );
	}

	/**
	 * The amount as shown on a quote line: "Included" for a free line, otherwise money().
	 *
	 * @param array $line A line from compute().
	 * @return string
	 */
	public static function line_amount_text( array $line ) {
		return ( 0 === (int) $line['amount'] ) ? 'Included' : self::money( $line['amount'] );
	}

	/**
	 * @param int $n Transactions a year.
	 * @return string For example "10,000 transactions a year".
	 */
	public static function transactions_label( $n ) {
		return number_format( (int) $n ) . ' transactions a year';
	}

	/**
	 * @param string $status registered|not_registered.
	 * @return string
	 */
	public static function status_label( $status ) {
		return 'registered' === $status ? 'Already registered' : ( 'not_registered' === $status ? 'Not registered' : '' );
	}

	/**
	 * @param string $employees up_to_20|over_20.
	 * @return string
	 */
	public static function employees_label( $employees ) {
		return 'over_20' === $employees ? 'More than 20 employees' : 'Up to 20 employees';
	}

	/**
	 * Business activity as text, with the "Other" description.
	 *
	 * @param array $selection Normalized selection.
	 * @return string For example "Retail" or "Other (Yacht charter)".
	 */
	public static function business_text( array $selection ) {
		$business = isset( $selection['business'] ) ? (string) $selection['business'] : '';
		$other    = isset( $selection['business_other'] ) ? (string) $selection['business_other'] : '';
		if ( 'Other' === $business && '' !== $other ) {
			return 'Other (' . $other . ')';
		}
		return $business;
	}

	/**
	 * The selected service labels, joined with ", ".
	 *
	 * @param array $selection Normalized selection.
	 * @return string
	 */
	public static function services_text( array $selection ) {
		$labels = self::service_labels();
		$out    = array();
		foreach ( self::SERVICE_KEYS as $key ) {
			if ( in_array( $key, (array) $selection['services'], true ) ) {
				$out[] = $labels[ $key ];
			}
		}
		return implode( ', ', $out );
	}

	/**
	 * Label/value rows of the business profile, in display order.
	 *
	 * @param array $selection Normalized selection.
	 * @return array<int,array{0:string,1:string}>
	 */
	public static function profile_rows( array $selection ) {
		$sel  = array_merge( self::DEFAULTS, $selection );
		$rows = array(
			array( 'Annual transactions', number_format( (int) $sel['transactions'] ) ),
			array( 'VAT', self::status_label( $sel['vat_status'] ) ),
			array( 'Corporate Tax', self::status_label( $sel['ct_status'] ) ),
		);
		if ( in_array( 'payroll', (array) $sel['services'], true ) ) {
			$rows[] = array( 'Employees', self::employees_label( $sel['employees'] ) );
		}
		if ( in_array( 'reports', (array) $sel['services'], true ) ) {
			$rows[] = array( 'Reports a year', (string) (int) $sel['reports'] );
		}
		return $rows;
	}

	/**
	 * Plain multi-line text for Zoho and the team email.
	 *
	 * @param array  $selection Normalized selection.
	 * @param array  $quote     From compute().
	 * @param string $ref       Quote reference.
	 * @return string
	 */
	public static function summary_text( array $selection, array $quote, $ref ) {
		$out   = array();
		$out[] = 'Quote ' . $ref;
		$out[] = 'Business: ' . self::business_text( $selection );
		foreach ( self::profile_rows( $selection ) as $row ) {
			$out[] = $row[0] . ': ' . $row[1];
		}
		$out[] = '';
		$out[] = 'Services:';
		foreach ( $quote['lines'] as $line ) {
			$out[] = '- ' . $line['label'] . ': ' . self::line_amount_text( $line );
		}
		$out[] = '';
		$out[] = self::TOTAL_LABEL . ': ' . self::money( $quote['total'] );
		return implode( "\n", $out );
	}

	/**
	 * One line of plain text: tags, control characters, angle and square brackets removed,
	 * white space collapsed. Square brackets become round ones so no shortcode can form.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function clean_text( $value ) {
		$s = is_scalar( $value ) ? (string) $value : '';
		if ( '' === $s ) {
			return '';
		}
		if ( function_exists( 'mb_check_encoding' ) && ! mb_check_encoding( $s, 'UTF-8' ) ) {
			$s = function_exists( 'mb_convert_encoding' ) ? mb_convert_encoding( $s, 'UTF-8', 'UTF-8' ) : '';
		}
		$s = html_entity_decode( $s, ENT_QUOTES, 'UTF-8' );
		$s = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $s ) : strip_tags( $s );
		$s = str_replace( array( '<', '>', '[', ']' ), array( '', '', '(', ')' ), $s );
		$s = preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $s );
		$s = preg_replace( '/\s+/u', ' ', (string) $s );
		return trim( (string) $s );
	}

	/**
	 * Does the text contain something a mail app or PDF viewer would turn into a link?
	 *
	 * @param mixed $value Visitor text.
	 * @return bool
	 */
	public static function looks_like_link( $value ) {
		return is_scalar( $value ) && 1 === preg_match( self::LINK_PATTERN, (string) $value );
	}

	/**
	 * @param string $s UTF-8 text.
	 * @return int Characters.
	 */
	public static function strlen( $s ) {
		if ( function_exists( 'mb_strlen' ) ) {
			return mb_strlen( (string) $s, 'UTF-8' );
		}
		$n = preg_match_all( '/./us', (string) $s );
		return false === $n ? strlen( (string) $s ) : $n;
	}
}
