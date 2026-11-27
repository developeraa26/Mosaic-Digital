<?php
/**
 * Calculator markup, output by the [lp_package_calculator] shortcode.
 *
 * In scope:
 * - $config    array  LP_Calc_Pricing::config() (already filtered with lp_calc_config).
 * - $is_editor bool   current_user_can( 'edit_posts' ): shows the missing-form notice to editors only.
 *
 * Steps 1-4 are plain controls outside any form. Step 5 has an empty slot that calculator.js fills
 * with the page's Elementor Pro form (the widget with the CSS class "lp-calc-form").
 *
 * @package LedgerPlusCalculator
 */

defined( 'ABSPATH' ) || exit;

$config    = isset( $config ) && is_array( $config ) ? $config : array();
$is_editor = ! empty( $is_editor );

$lp_currency = isset( $config['currency'] ) ? (string) $config['currency'] : 'AED';
$lp_prices   = isset( $config['prices'] ) && is_array( $config['prices'] ) ? $config['prices'] : array();
$lp_vat_rate = isset( $config['vat_rate'] ) ? (float) $config['vat_rate'] : 5;
$lp_vat_rate = ( floor( $lp_vat_rate ) === $lp_vat_rate ) ? (string) (int) $lp_vat_rate : (string) $lp_vat_rate;
$lp_defaults = isset( $config['defaults'] ) && is_array( $config['defaults'] ) ? $config['defaults'] : array();
$lp_total_label = isset( $config['total_label'] ) && '' !== $config['total_label']
	? (string) $config['total_label']
	: sprintf( 'Estimated annual total (incl. %s%% VAT)', $lp_vat_rate );

$lp_money = static function ( $amount ) use ( $lp_currency ) {
	return $lp_currency . ' ' . number_format( (int) round( (float) $amount ) );
};
$lp_price = static function ( $key ) use ( $lp_prices ) {
	return isset( $lp_prices[ $key ] ) && is_numeric( $lp_prices[ $key ] ) ? (int) $lp_prices[ $key ] : 0;
};

/*
 * Option lists accept plain values or array( 'value' => …, 'label' => … ) items.
 */
$lp_options = static function ( $list, $label_cb = null ) {
	$out = array();
	foreach ( (array) $list as $item ) {
		if ( is_array( $item ) ) {
			if ( ! isset( $item['value'] ) ) {
				continue;
			}
			$value = (string) $item['value'];
			$label = isset( $item['label'] ) ? (string) $item['label'] : $value;
		} else {
			$value = (string) $item;
			$label = $label_cb ? (string) call_user_func( $label_cb, $item ) : $value;
		}
		$out[] = array(
			'value' => $value,
			'label' => $label,
		);
	}
	return $out;
};

$lp_business_types = $lp_options( isset( $config['business_types'] ) ? $config['business_types'] : array() );
$lp_transactions   = $lp_options(
	isset( $config['transactions'] ) ? $config['transactions'] : array(),
	static function ( $n ) {
		return number_format( (int) $n ) . ' transactions a year';
	}
);
$lp_reports_options = $lp_options(
	isset( $config['reports_options'] ) ? $config['reports_options'] : array( 1, 2, 3, 4 ),
	static function ( $n ) {
		return (int) $n . ( 1 === (int) $n ? ' report a year' : ' reports a year' );
	}
);
$lp_employees = $lp_options( isset( $config['employees'] ) ? $config['employees'] : array() );
$lp_statuses  = $lp_options( isset( $config['statuses'] ) ? $config['statuses'] : array() );
$lp_services  = array();
foreach ( (array) ( isset( $config['services'] ) ? $config['services'] : array() ) as $lp_service ) {
	if ( is_array( $lp_service ) && ! empty( $lp_service['key'] ) ) {
		$lp_services[] = $lp_service;
	}
}

// Defaults (spec): 1,000 transactions, up to 20 employees, 4 reports, e-invoicing ticked.
$lp_default_tx       = isset( $lp_defaults['transactions'] ) ? (string) $lp_defaults['transactions'] : '1000';
$lp_default_emp      = isset( $lp_defaults['employees'] ) ? (string) $lp_defaults['employees'] : 'up_to_20';
$lp_default_reports  = isset( $lp_defaults['reports'] ) ? (string) $lp_defaults['reports'] : '4';
$lp_default_services = isset( $lp_defaults['services'] ) ? array_map( 'strval', (array) $lp_defaults['services'] ) : array( 'einvoicing' );

// The default estimate (e-invoicing at 1,000 transactions), printed so the page shows the right
// total and lines before calculator.js runs. calculator.js renders the same markup on every change.
$lp_quote = array(
	'lines' => array(),
	'total' => 0,
);
if ( class_exists( 'LP_Calc_Pricing' ) ) {
	$lp_quote = LP_Calc_Pricing::compute(
		array(
			'services'     => $lp_default_services,
			'transactions' => (int) $lp_default_tx,
			'employees'    => $lp_default_emp,
			'reports'      => (int) $lp_default_reports,
		)
	);
}
$lp_lines = static function ( $with_detail ) use ( $lp_quote, $lp_money ) {
	if ( empty( $lp_quote['lines'] ) ) {
		echo '<li class="lp-calc__line lp-calc__line--empty">' . esc_html__( 'Choose at least one service in step 4.', 'ledgerplus-calculator' ) . '</li>';
		return;
	}
	foreach ( $lp_quote['lines'] as $lp_line ) {
		$lp_amount = 0 === (int) $lp_line['amount'] ? __( 'Included', 'ledgerplus-calculator' ) : $lp_money( $lp_line['amount'] );
		$lp_detail = $with_detail ? (string) $lp_line['detail'] : '';
		if ( 0 === strcasecmp( $lp_detail, $lp_amount ) ) {
			$lp_detail = '';
		}
		echo '<li class="lp-calc__line"><span class="lp-calc__line-text"><span class="lp-calc__line-label">' . esc_html( $lp_line['label'] ) . '</span>';
		if ( '' !== $lp_detail ) {
			echo '<span class="lp-calc__line-detail">' . esc_html( $lp_detail ) . '</span>';
		}
		echo '</span><span class="lp-calc__line-amount">' . esc_html( $lp_amount ) . '</span></li>';
	}
};

// Short card descriptions when the config has none.
$lp_descriptions = array(
	'accounting'      => 'Bookkeeping and accounts kept up to date all year.',
	'reports'         => 'Financial reports each quarter, 1 to 4 a year.',
	'vat'             => 'VAT returns filed for you. Registration included if you are not registered.',
	'ct'              => 'Corporate Tax return filed for you. Registration included if you are not registered.',
	'payroll'         => 'Payroll runs and WPS salary files.',
	'expense'         => 'Expense claims and receipts tracked.',
	'invoicing'       => 'Invoices issued and customer payments tracked.',
	'einvoicing'      => 'UAE e-invoicing, priced by your annual transactions.',
	'account_manager' => 'One point of contact for your account, at no extra charge.',
);

// Status labels for the Yes / No cards.
$lp_status_label = array(
	'registered'     => 'Already registered',
	'not_registered' => 'Not registered',
);
foreach ( $lp_statuses as $lp_status ) {
	$lp_status_label[ $lp_status['value'] ] = $lp_status['label'];
}

$lp_uid = function_exists( 'wp_unique_id' ) ? wp_unique_id( 'lp-calc-' ) : 'lp-calc-1';
$lp_steps = array(
	1 => 'Business',
	2 => 'Volumes',
	3 => 'Tax status',
	4 => 'Services',
	5 => 'Your details',
);

$lp_icon_arrow = '<svg class="lp-calc__icon" viewBox="0 0 20 20" width="18" height="18" aria-hidden="true" focusable="false"><path d="M4 10h11m-4.5-4.5L15 10l-4.5 4.5" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>';
$lp_icon_back  = '<svg class="lp-calc__icon" viewBox="0 0 20 20" width="18" height="18" aria-hidden="true" focusable="false"><path d="M16 10H5m4.5-4.5L5 10l4.5 4.5" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>';
$lp_icon_check = '<svg class="lp-calc__icon" viewBox="0 0 20 20" width="14" height="14" aria-hidden="true" focusable="false"><path d="M4.5 10.5l3.5 3.5 7.5-8" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>';
$lp_icon_mail  = '<svg class="lp-calc__icon" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><rect x="3" y="5" width="18" height="14" rx="2.5" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M4 7l8 6 8-6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
$lp_icon_chev  = '<svg class="lp-calc__icon lp-calc__chev" viewBox="0 0 20 20" width="18" height="18" aria-hidden="true" focusable="false"><path d="M5.5 12.5L10 8l4.5 4.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
?>
<style id="lp-calc-preload">body:not(.elementor-editor-active) .elementor-widget.lp-calc-form{display:none}body:not(.elementor-editor-active) .lp-calc .elementor-widget.lp-calc-form{display:block}</style>
<div class="lp-calc" data-lp-calc id="<?php echo esc_attr( $lp_uid ); ?>">
	<script type="application/json" id="lp-calc-config"><?php echo wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP ); ?></script>

	<div class="lp-calc__workspace">
		<aside class="lp-calc__rail" aria-label="<?php esc_attr_e( 'Your package setup', 'ledgerplus-calculator' ); ?>">
			<div class="lp-calc__rail-card">
				<p class="lp-calc__rail-label"><?php esc_html_e( 'Your package setup', 'ledgerplus-calculator' ); ?></p>
				<ol class="lp-calc__steps" data-lp-calc-steps>
					<?php foreach ( $lp_steps as $lp_n => $lp_name ) : ?>
						<li class="lp-calc__step<?php echo 1 === $lp_n ? ' is-active' : ''; ?>" data-lp-calc-step="<?php echo esc_attr( $lp_n ); ?>"<?php echo 1 === $lp_n ? ' aria-current="step"' : ''; ?>>
							<span class="lp-calc__step-num" aria-hidden="true"><span class="lp-calc__step-digit"><?php echo esc_html( $lp_n ); ?></span><?php echo $lp_icon_check; // phpcs:ignore WordPress.Security.EscapeOutput -- static SVG. ?></span>
							<span class="lp-calc__step-name"><span class="lp-calc__sr"><?php echo esc_html( sprintf( 'Step %d: ', $lp_n ) ); ?></span><?php echo esc_html( $lp_name ); ?></span>
						</li>
					<?php endforeach; ?>
				</ol>
			</div>

			<div class="lp-calc__rail-sticky">
				<div class="lp-calc__estimate">
					<p class="lp-calc__estimate-label"><?php esc_html_e( 'Estimated annual total', 'ledgerplus-calculator' ); ?></p>
					<p class="lp-calc__estimate-total" data-lp-calc-total aria-live="polite" aria-atomic="true"><?php echo esc_html( $lp_money( $lp_quote['total'] ) ); ?></p>
					<p class="lp-calc__estimate-note"><?php echo esc_html( sprintf( 'Billed annually, incl. %s%% VAT', $lp_vat_rate ) ); ?></p>
					<ul class="lp-calc__lines lp-calc__lines--rail" data-lp-calc-lines="rail"><?php $lp_lines( false ); ?></ul>
				</div>

				<div class="lp-calc__help">
					<p class="lp-calc__help-title"><?php esc_html_e( 'Need a hand?', 'ledgerplus-calculator' ); ?></p>
					<p class="lp-calc__help-text"><?php esc_html_e( 'Our Ledger Plus team can help you choose the right setup for your business.', 'ledgerplus-calculator' ); ?></p>
					<a class="lp-calc__help-link" href="tel:+971503420832">+971 50 342 0832</a>
					<a class="lp-calc__help-link" href="mailto:info@ledgerplus.ae">info@ledgerplus.ae</a>
				</div>
			</div>
		</aside>

		<section class="lp-calc__card" data-lp-calc-card aria-label="<?php esc_attr_e( 'Package builder', 'ledgerplus-calculator' ); ?>">
			<div class="lp-calc__card-top">
				<div class="lp-calc__topline">
					<span class="lp-calc__kicker"><?php esc_html_e( 'Package builder', 'ledgerplus-calculator' ); ?></span>
					<span class="lp-calc__count" data-lp-calc-count>STEP 01 / 05</span>
				</div>
				<div class="lp-calc__progress" aria-hidden="true"><div class="lp-calc__progress-fill" data-lp-calc-progress style="width:20%"></div></div>
			</div>

			<?php if ( $is_editor ) : ?>
				<div class="lp-calc__notice lp-calc__notice--editor" data-lp-calc-editor-notice hidden>
					<strong><?php esc_html_e( 'Editors only:', 'ledgerplus-calculator' ); ?></strong>
					<?php esc_html_e( 'the "Ledger Plus package calculator" form widget is missing from this page. Add an Elementor Form widget with that form name and the CSS class lp-calc-form, or visitors cannot request a quotation.', 'ledgerplus-calculator' ); ?>
				</div>
			<?php endif; ?>

			<div class="lp-calc__body">

				<?php /* Step 1: Business */ ?>
				<div class="lp-calc__panel" data-lp-calc-panel="1">
					<h2 class="lp-calc__title" id="<?php echo esc_attr( $lp_uid ); ?>-h1" tabindex="-1"><?php esc_html_e( 'First, tell us about your business.', 'ledgerplus-calculator' ); ?></h2>
					<p class="lp-calc__intro"><?php esc_html_e( 'This helps us understand the financial and compliance support your business needs.', 'ledgerplus-calculator' ); ?></p>

					<fieldset class="lp-calc__fieldset" data-lp-calc-field="business" aria-describedby="<?php echo esc_attr( $lp_uid ); ?>-err-business">
						<legend class="lp-calc__legend"><?php esc_html_e( 'Primary business activity', 'ledgerplus-calculator' ); ?></legend>
						<p class="lp-calc__error lp-calc__error--group" id="<?php echo esc_attr( $lp_uid ); ?>-err-business" data-lp-calc-error="business" hidden></p>
						<div class="lp-calc__choices">
							<?php foreach ( $lp_business_types as $lp_i => $lp_type ) : ?>
								<div class="lp-calc__choice">
									<input class="lp-calc__choice-input" type="radio" name="<?php echo esc_attr( $lp_uid ); ?>-business" id="<?php echo esc_attr( $lp_uid . '-business-' . $lp_i ); ?>" value="<?php echo esc_attr( $lp_type['value'] ); ?>" data-lp-calc-input="business">
									<label class="lp-calc__choice-label" for="<?php echo esc_attr( $lp_uid . '-business-' . $lp_i ); ?>"><span class="lp-calc__radio" aria-hidden="true"></span><span class="lp-calc__choice-text"><?php echo esc_html( $lp_type['label'] ); ?></span></label>
								</div>
							<?php endforeach; ?>
						</div>
					</fieldset>

					<div class="lp-calc__field" data-lp-calc-field="business_other" data-lp-calc-reveal="business_other" hidden>
						<label class="lp-calc__label" for="<?php echo esc_attr( $lp_uid ); ?>-business-other"><?php esc_html_e( 'Please specify your business activity', 'ledgerplus-calculator' ); ?></label>
						<input class="lp-calc__input" type="text" id="<?php echo esc_attr( $lp_uid ); ?>-business-other" maxlength="80" autocomplete="off" placeholder="<?php esc_attr_e( 'For example: logistics, healthcare, IT services', 'ledgerplus-calculator' ); ?>" data-lp-calc-input="business_other" aria-describedby="<?php echo esc_attr( $lp_uid ); ?>-err-business_other">
						<p class="lp-calc__error" id="<?php echo esc_attr( $lp_uid ); ?>-err-business_other" data-lp-calc-error="business_other" hidden></p>
					</div>
				</div>

				<?php /* Step 2: Volumes */ ?>
				<div class="lp-calc__panel" data-lp-calc-panel="2" hidden>
					<h2 class="lp-calc__title" id="<?php echo esc_attr( $lp_uid ); ?>-h2" tabindex="-1"><?php esc_html_e( 'What are your annual transaction volumes?', 'ledgerplus-calculator' ); ?></h2>
					<p class="lp-calc__intro"><?php esc_html_e( 'Select the approximate number of sales and purchase transactions your business processes each year.', 'ledgerplus-calculator' ); ?></p>

					<div class="lp-calc__field" data-lp-calc-field="transactions">
						<label class="lp-calc__label" for="<?php echo esc_attr( $lp_uid ); ?>-transactions"><?php esc_html_e( 'Annual transactions', 'ledgerplus-calculator' ); ?></label>
						<div class="lp-calc__select">
							<select class="lp-calc__input" id="<?php echo esc_attr( $lp_uid ); ?>-transactions" data-lp-calc-input="transactions" aria-describedby="<?php echo esc_attr( $lp_uid ); ?>-hint-transactions">
								<?php foreach ( $lp_transactions as $lp_tx ) : ?>
									<option value="<?php echo esc_attr( $lp_tx['value'] ); ?>"<?php echo $lp_tx['value'] === $lp_default_tx ? ' selected' : ''; ?>><?php echo esc_html( $lp_tx['label'] ); ?></option>
								<?php endforeach; ?>
							</select>
						</div>
						<p class="lp-calc__hint" id="<?php echo esc_attr( $lp_uid ); ?>-hint-transactions">
							<?php echo esc_html( sprintf( 'Used for UAE e-invoicing pricing: %s per 1,000 transactions a year.', $lp_money( $lp_price( 'einvoicing_per_1000' ) ) ) ); ?>
						</p>
					</div>

					<p class="lp-calc__note"><?php esc_html_e( 'Not sure? Choose the closest volume. Our team confirms the details with you before anything is final.', 'ledgerplus-calculator' ); ?></p>
				</div>

				<?php /* Step 3: Tax status */ ?>
				<div class="lp-calc__panel" data-lp-calc-panel="3" hidden>
					<h2 class="lp-calc__title" id="<?php echo esc_attr( $lp_uid ); ?>-h3" tabindex="-1"><?php esc_html_e( 'Tell us about your tax registration.', 'ledgerplus-calculator' ); ?></h2>
					<p class="lp-calc__intro"><?php esc_html_e( 'Your current registration status shapes the VAT and Corporate Tax services in your package.', 'ledgerplus-calculator' ); ?></p>

					<?php
					$lp_tax_questions = array(
						'vat_status' => __( 'Is your business registered for VAT?', 'ledgerplus-calculator' ),
						'ct_status'  => __( 'Is your business registered for Corporate Tax?', 'ledgerplus-calculator' ),
					);
					foreach ( $lp_tax_questions as $lp_key => $lp_question ) :
						?>
						<fieldset class="lp-calc__fieldset" data-lp-calc-field="<?php echo esc_attr( $lp_key ); ?>" aria-describedby="<?php echo esc_attr( $lp_uid . '-err-' . $lp_key ); ?>">
							<legend class="lp-calc__legend"><?php echo esc_html( $lp_question ); ?></legend>
							<p class="lp-calc__error lp-calc__error--group" id="<?php echo esc_attr( $lp_uid . '-err-' . $lp_key ); ?>" data-lp-calc-error="<?php echo esc_attr( $lp_key ); ?>" hidden></p>
							<div class="lp-calc__choices">
								<?php
								$lp_yes_no = array(
									'registered'     => __( 'Yes', 'ledgerplus-calculator' ),
									'not_registered' => __( 'No', 'ledgerplus-calculator' ),
								);
								foreach ( $lp_yes_no as $lp_value => $lp_word ) :
									$lp_id = $lp_uid . '-' . $lp_key . '-' . $lp_value;
									?>
									<div class="lp-calc__choice">
										<input class="lp-calc__choice-input" type="radio" name="<?php echo esc_attr( $lp_uid . '-' . $lp_key ); ?>" id="<?php echo esc_attr( $lp_id ); ?>" value="<?php echo esc_attr( $lp_value ); ?>" data-lp-calc-input="<?php echo esc_attr( $lp_key ); ?>">
										<label class="lp-calc__choice-label" for="<?php echo esc_attr( $lp_id ); ?>"><span class="lp-calc__radio" aria-hidden="true"></span><span class="lp-calc__choice-text"><?php echo esc_html( $lp_word ); ?><span class="lp-calc__choice-sub"><?php echo esc_html( $lp_status_label[ $lp_value ] ); ?></span></span></label>
									</div>
								<?php endforeach; ?>
							</div>
						</fieldset>
					<?php endforeach; ?>
				</div>

				<?php /* Step 4: Services */ ?>
				<div class="lp-calc__panel" data-lp-calc-panel="4" hidden>
					<h2 class="lp-calc__title" id="<?php echo esc_attr( $lp_uid ); ?>-h4" tabindex="-1"><?php esc_html_e( 'Choose the services you need.', 'ledgerplus-calculator' ); ?></h2>
					<p class="lp-calc__intro"><?php esc_html_e( 'Select one or more services. Your estimate updates as you go.', 'ledgerplus-calculator' ); ?></p>

					<fieldset class="lp-calc__fieldset" data-lp-calc-field="services" aria-describedby="<?php echo esc_attr( $lp_uid ); ?>-err-services">
						<legend class="lp-calc__legend"><?php esc_html_e( 'Services', 'ledgerplus-calculator' ); ?></legend>
						<p class="lp-calc__error lp-calc__error--group" id="<?php echo esc_attr( $lp_uid ); ?>-err-services" data-lp-calc-error="services" hidden></p>
						<div class="lp-calc__services">
							<?php
							foreach ( $lp_services as $lp_service ) :
								$lp_key  = (string) $lp_service['key'];
								$lp_id   = $lp_uid . '-svc-' . $lp_key;
								$lp_desc = isset( $lp_service['description'] ) ? (string) $lp_service['description'] : ( isset( $lp_descriptions[ $lp_key ] ) ? $lp_descriptions[ $lp_key ] : '' );
								$lp_on   = in_array( $lp_key, $lp_default_services, true );
								?>
								<div class="lp-calc__service" data-lp-calc-service="<?php echo esc_attr( $lp_key ); ?>">
									<input class="lp-calc__service-input" type="checkbox" id="<?php echo esc_attr( $lp_id ); ?>" value="<?php echo esc_attr( $lp_key ); ?>" data-lp-calc-input="services"<?php echo $lp_on ? ' checked' : ''; ?><?php echo '' !== $lp_desc ? ' aria-describedby="' . esc_attr( $lp_id . '-desc' ) . '"' : ''; ?>>
									<label class="lp-calc__service-label" for="<?php echo esc_attr( $lp_id ); ?>">
										<span class="lp-calc__check" aria-hidden="true"><?php echo $lp_icon_check; // phpcs:ignore WordPress.Security.EscapeOutput -- static SVG. ?></span>
										<span class="lp-calc__service-text">
											<span class="lp-calc__service-name"><?php echo esc_html( isset( $lp_service['label'] ) ? $lp_service['label'] : $lp_key ); ?></span>
											<?php if ( '' !== $lp_desc ) : ?>
												<span class="lp-calc__service-desc" id="<?php echo esc_attr( $lp_id . '-desc' ); ?>"><?php echo esc_html( $lp_desc ); ?></span>
											<?php endif; ?>
											<?php if ( ! empty( $lp_service['hint'] ) ) : ?>
												<span class="lp-calc__service-price"><?php echo esc_html( $lp_service['hint'] ); ?></span>
											<?php endif; ?>
										</span>
									</label>

									<?php if ( 'payroll' === $lp_key ) : ?>
										<fieldset class="lp-calc__extra" data-lp-calc-reveal="employees" data-lp-calc-field="employees"<?php echo $lp_on ? '' : ' hidden'; ?>>
											<legend class="lp-calc__extra-label"><?php esc_html_e( 'Number of employees', 'ledgerplus-calculator' ); ?></legend>
											<div class="lp-calc__pills">
												<?php foreach ( $lp_employees as $lp_emp ) : ?>
													<?php $lp_eid = $lp_uid . '-employees-' . $lp_emp['value']; ?>
													<span class="lp-calc__pill">
														<input class="lp-calc__pill-input" type="radio" name="<?php echo esc_attr( $lp_uid ); ?>-employees" id="<?php echo esc_attr( $lp_eid ); ?>" value="<?php echo esc_attr( $lp_emp['value'] ); ?>" data-lp-calc-input="employees"<?php echo $lp_emp['value'] === $lp_default_emp ? ' checked' : ''; ?>>
														<label class="lp-calc__pill-label" for="<?php echo esc_attr( $lp_eid ); ?>"><?php echo esc_html( $lp_emp['label'] ); ?></label>
													</span>
												<?php endforeach; ?>
											</div>
										</fieldset>
									<?php elseif ( 'reports' === $lp_key ) : ?>
										<div class="lp-calc__extra" data-lp-calc-reveal="reports" data-lp-calc-field="reports"<?php echo $lp_on ? '' : ' hidden'; ?>>
											<label class="lp-calc__extra-label" for="<?php echo esc_attr( $lp_uid ); ?>-reports"><?php esc_html_e( 'Reports per year', 'ledgerplus-calculator' ); ?></label>
											<div class="lp-calc__select lp-calc__select--sm">
												<select class="lp-calc__input" id="<?php echo esc_attr( $lp_uid ); ?>-reports" data-lp-calc-input="reports">
													<?php foreach ( $lp_reports_options as $lp_rep ) : ?>
														<option value="<?php echo esc_attr( $lp_rep['value'] ); ?>"<?php echo $lp_rep['value'] === $lp_default_reports ? ' selected' : ''; ?>><?php echo esc_html( $lp_rep['label'] ); ?></option>
													<?php endforeach; ?>
												</select>
											</div>
										</div>
									<?php endif; ?>
								</div>
							<?php endforeach; ?>
						</div>
					</fieldset>
				</div>

				<?php /* Step 5: Your details (the Elementor form is moved into the slot) */ ?>
				<div class="lp-calc__panel" data-lp-calc-panel="5" hidden>
					<div class="lp-calc__contact" data-lp-calc-contact>
						<h2 class="lp-calc__title" id="<?php echo esc_attr( $lp_uid ); ?>-h5" tabindex="-1"><?php esc_html_e( 'Where should we send your quotation?', 'ledgerplus-calculator' ); ?></h2>
						<p class="lp-calc__intro"><?php esc_html_e( 'Leave your details and we will send your quotation.', 'ledgerplus-calculator' ); ?></p>

						<div class="lp-calc__summary">
							<p class="lp-calc__summary-title"><?php esc_html_e( 'Your estimate', 'ledgerplus-calculator' ); ?></p>
							<ul class="lp-calc__lines lp-calc__lines--summary" data-lp-calc-lines="summary"><?php $lp_lines( true ); ?></ul>
							<div class="lp-calc__summary-total">
								<span><?php echo esc_html( $lp_total_label ); ?></span>
								<strong data-lp-calc-total-static><?php echo esc_html( $lp_money( $lp_quote['total'] ) ); ?></strong>
							</div>
						</div>

						<p class="lp-calc__pdf-note"><?php echo $lp_icon_mail; // phpcs:ignore WordPress.Security.EscapeOutput -- static SVG. ?><span><?php esc_html_e( "We'll email this quotation to you as a PDF.", 'ledgerplus-calculator' ); ?></span></p>

						<div class="lp-calc__form-slot" data-lp-calc-form-slot></div>

						<p class="lp-calc__notice lp-calc__notice--info" data-lp-calc-editmode hidden><?php esc_html_e( 'In the Elementor editor the form stays in its own container. On the published page it appears here.', 'ledgerplus-calculator' ); ?></p>

						<?php if ( $is_editor ) : ?>
							<p class="lp-calc__notice lp-calc__notice--editor" data-lp-calc-missing hidden><strong><?php esc_html_e( 'Editors only:', 'ledgerplus-calculator' ); ?></strong> <?php esc_html_e( 'the "Ledger Plus package calculator" form widget (CSS class lp-calc-form) is missing from this page.', 'ledgerplus-calculator' ); ?></p>
						<?php else : ?>
							<p class="lp-calc__notice" data-lp-calc-missing hidden><?php esc_html_e( 'Email', 'ledgerplus-calculator' ); ?> <a href="mailto:info@ledgerplus.ae?subject=Package%20quotation%20request" data-lp-calc-mailto>info@ledgerplus.ae</a> <?php esc_html_e( 'for a quotation.', 'ledgerplus-calculator' ); ?></p>
						<?php endif; ?>
					</div>

					<div class="lp-calc__result" data-lp-calc-result hidden></div>
				</div>

				<div class="lp-calc__actions" data-lp-calc-actions>
					<button type="button" class="lp-calc__btn lp-calc__btn--secondary" data-lp-calc-back hidden><?php echo $lp_icon_back; // phpcs:ignore WordPress.Security.EscapeOutput -- static SVG. ?><span><?php esc_html_e( 'Back', 'ledgerplus-calculator' ); ?></span></button>
					<button type="button" class="lp-calc__btn lp-calc__btn--primary" data-lp-calc-next><span><?php esc_html_e( 'Continue', 'ledgerplus-calculator' ); ?></span><?php echo $lp_icon_arrow; // phpcs:ignore WordPress.Security.EscapeOutput -- static SVG. ?></button>
				</div>
			</div>

			<div class="lp-calc__bar" data-lp-calc-bar>
				<div class="lp-calc__bar-panel" id="<?php echo esc_attr( $lp_uid ); ?>-breakdown" data-lp-calc-bar-panel hidden>
					<p class="lp-calc__bar-heading"><?php esc_html_e( 'Your estimate', 'ledgerplus-calculator' ); ?></p>
					<ul class="lp-calc__lines lp-calc__lines--bar" data-lp-calc-lines="bar"><?php $lp_lines( true ); ?></ul>
					<p class="lp-calc__bar-note"><?php echo esc_html( sprintf( 'Prices are annual and include %s%% VAT.', $lp_vat_rate ) ); ?></p>
				</div>
				<div class="lp-calc__bar-row" data-lp-calc-bar-row>
					<p class="lp-calc__bar-sum">
						<span class="lp-calc__bar-label"><?php esc_html_e( 'Estimated annual total', 'ledgerplus-calculator' ); ?></span>
						<span class="lp-calc__bar-total" data-lp-calc-total aria-live="polite" aria-atomic="true"><?php echo esc_html( $lp_money( $lp_quote['total'] ) ); ?></span>
					</p>
					<button type="button" class="lp-calc__bar-toggle" aria-expanded="false" aria-controls="<?php echo esc_attr( $lp_uid ); ?>-breakdown" data-lp-calc-bar-toggle>
						<span data-lp-calc-bar-toggle-text><?php esc_html_e( 'Breakdown', 'ledgerplus-calculator' ); ?></span><?php echo $lp_icon_chev; // phpcs:ignore WordPress.Security.EscapeOutput -- static SVG. ?>
					</button>
				</div>
			</div>
		</section>

		<?php /* Phones and tablets (850px and narrower) hide the rail's help box: the same links, below the card. */ ?>
		<div class="lp-calc__help lp-calc__help--below">
			<p class="lp-calc__help-title"><?php esc_html_e( 'Need a hand?', 'ledgerplus-calculator' ); ?></p>
			<p class="lp-calc__help-text"><?php esc_html_e( 'Our Ledger Plus team can help you choose the right setup for your business.', 'ledgerplus-calculator' ); ?></p>
			<a class="lp-calc__help-link" href="tel:+971503420832">+971 50 342 0832</a>
			<a class="lp-calc__help-link" href="mailto:info@ledgerplus.ae">info@ledgerplus.ae</a>
		</div>
	</div>
</div>
