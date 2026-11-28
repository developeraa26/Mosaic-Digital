<?php
/**
 * Renders the page parts the UI harness needs, from the REAL plugin:
 *   - the [lp_package_calculator] shortcode output (LP_Calc_Shortcode via do_shortcode, so the real
 *     LP_Calc_Pricing::browser_config() and templates/calculator.php),
 *   - the assets the plugin enqueued (URL, version, dependencies, footer),
 *   - the Elementor Pro form widget built from page/out/page.json.
 *
 * php render.php [--editor] [--json] [--config-json] [--price-filter] [--strict-filter] [--query=utm_source=x&...]
 *   (default)        print the shortcode markup
 *   --editor         current user can edit_posts (editors-only notices)
 *   --json           print {shortcode, form, form_id, assets, early, head, footer, notices} as JSON
 *   --config-json    print the config the page gets (LP_Calc_Pricing::browser_config())
 *   --price-filter   an lp_calc_config filter changes the prices and hints, puts a price into a
 *                    service description and adds an unknown key, as a site owner might
 *   --strict-filter  an lp_calc_config filter writes prices into every text the page gets, in other
 *                    spellings too: service labels and descriptions ("AED2,150", "dirhams", "Dhs",
 *                    "Included", a bare filtered price), an added service, option labels and values,
 *                    extra keys on the options, the defaults and the total label
 */
require_once dirname( __DIR__ ) . '/wp/env.php';
require_once dirname( __DIR__ ) . '/wp/elementor-form.php';

$args   = array_slice( $argv, 1 );
$editor = in_array( '--editor', $args, true );
$query  = array();
foreach ( $args as $a ) {
	if ( 0 === strpos( $a, '--query=' ) ) {
		parse_str( substr( $a, 8 ), $query );
	}
}

lp_env_boot( array( 'editor' => $editor ) );

if ( in_array( '--price-filter', $args, true ) ) {
	add_filter(
		'lp_calc_config',
		static function ( $config ) {
			$config['prices']['accounting']          = 2150;
			$config['prices']['einvoicing_per_1000'] = 650;
			$config['services'][0]['hint']          = 'AED 2,150 a year';
			$config['services'][0]['description']   = 'Bookkeeping all year for AED 2,150.';
			$config['services'][8]['description']   = 'One point of contact, at no extra charge.';
			$config['price_list']                   = array( 'accounting' => 'AED 2,150' );
			$config['defaults']['note']             = 'AED 650 per 1,000 transactions';
			return $config;
		}
	);
}

if ( in_array( '--strict-filter', $args, true ) ) {
	add_filter(
		'lp_calc_config',
		static function ( $config ) {
			$config['prices']['accounting']       = 2150;
			$config['services'][0]['label']       = 'Accounting (AED 2,150)';
			$config['services'][0]['description'] = 'Bookkeeping all year, now AED2,150.';
			$config['services'][1]['description'] = 'Reports from 2,150 dirhams.';
			$config['services'][2]['description'] = 'VAT returns, from 2,150 a year.';
			$config['services'][4]['description'] = 'Payroll runs, Dhs 1,000 a year.';
			$config['services'][8]['description'] = 'One point of contact, Included.';
			$config['services'][]                 = array( 'key' => 'audit', 'label' => 'External audit (AED 3,000)', 'description' => 'Annual audit.' );
			foreach ( $config['transactions'] as $i => $t ) {
				$config['transactions'][ $i ]['label'] = number_format( $t['value'] ) . ' transactions a year (AED ' . number_format( $t['value'] / 2 ) . ')';
				$config['transactions'][ $i ]['price'] = $t['value'] / 2;
			}
			$config['employees'][1]['label']      = 'More than 20 employees (+AED 1,000)';
			$config['statuses'][1]['label']       = 'Not registered (registration AED 500)';
			$config['business_types'][0]['label'] = 'Trading, from AED 2,000';
			$config['business_types'][]           = array( 'value' => 'Shipping (AED 9,999)', 'label' => 'Shipping' );
			$config['defaults']['employees']      = 'AED 1,000';
			$config['total_label']                = 'Estimated annual total in AED';
			return $config;
		}
	);
}

if ( in_array( '--config-json', $args, true ) ) {
	echo json_encode( LP_Calc_Pricing::browser_config(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	exit( 0 );
}

$html = lp_env_shortcode_html();

if ( in_array( '--json', $args, true ) ) {
	$assets = lp_env_assets( '/wp-content/plugins/ledgerplus-calculator/' );
	$tags   = lp_env_asset_tags( $assets );
	$widget = lp_ef_widget();
	echo json_encode(
		array(
			'shortcode' => $html,
			'form'      => $widget ? lp_ef_render( $widget, array( 'query' => $query ) ) : '',
			'form_id'   => $widget ? $widget['id'] : '',
			'assets'    => $assets,
			'early'     => ! empty( $GLOBALS['lp_env_early_enqueue'] ),
			'head'      => $tags['head'],
			'footer'    => $tags['footer'],
			'notices'   => $GLOBALS['lp_env_notices'],
		),
		JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
	);
	exit( $GLOBALS['lp_env_notices'] ? 1 : 0 );
}

echo $html;
exit( $GLOBALS['lp_env_notices'] ? 1 : 0 );
