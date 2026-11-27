<?php
/**
 * LP_Calc_Pricing::config() exactly as SPEC.md describes it (plain lists for business types and
 * transactions, services with key/label/hint). The real class may return a superset (value/label
 * items, descriptions, defaults); the template and JS accept both shapes.
 */
function lp_calc_ui_stub_config() {
	return array(
		'currency'        => 'AED',
		'vat_included'    => true,
		'vat_rate'        => 5,
		'business_types'  => array( 'Trading / Wholesale', 'Retail', 'Restaurant / Hospitality', 'E-commerce', 'Construction / Contracting', 'Real Estate', 'Professional Services', 'Other' ),
		'transactions'    => array( 1000, 2000, 3000, 4000, 5000, 10000, 20000, 30000, 40000, 50000 ),
		'reports_options' => array( 1, 2, 3, 4 ),
		'employees'       => array(
			array( 'value' => 'up_to_20', 'label' => 'Up to 20 employees' ),
			array( 'value' => 'over_20', 'label' => 'More than 20 employees' ),
		),
		'statuses'        => array(
			array( 'value' => 'registered', 'label' => 'Already registered' ),
			array( 'value' => 'not_registered', 'label' => 'Not registered' ),
		),
		'services'        => array(
			array( 'key' => 'accounting', 'label' => 'Accounting and bookkeeping', 'hint' => 'AED 2,000 a year' ),
			array( 'key' => 'reports', 'label' => 'Quarterly financial reports', 'hint' => 'AED 500 per report' ),
			array( 'key' => 'vat', 'label' => 'VAT returns filing and compliance', 'hint' => 'From AED 2,000 a year' ),
			array( 'key' => 'ct', 'label' => 'Corporate Tax registration and filing', 'hint' => 'From AED 1,500 a year' ),
			array( 'key' => 'payroll', 'label' => 'Payroll and WPS support', 'hint' => 'From AED 1,000 a year' ),
			array( 'key' => 'expense', 'label' => 'Expense management', 'hint' => 'AED 500 a year' ),
			array( 'key' => 'invoicing', 'label' => 'Invoicing and payment tracking', 'hint' => 'AED 1,000 a year' ),
			array( 'key' => 'einvoicing', 'label' => 'UAE e-invoicing', 'hint' => 'AED 500 per 1,000 transactions' ),
			array( 'key' => 'account_manager', 'label' => 'Dedicated account manager', 'hint' => 'Included' ),
		),
		'prices'          => array(
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
		),
	);
}
