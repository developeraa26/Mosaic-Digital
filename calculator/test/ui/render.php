<?php
/**
 * Renders the page parts the UI harness needs, from the REAL plugin:
 *   - the [lp_package_calculator] shortcode output (LP_Calc_Shortcode via do_shortcode, so the real
 *     LP_Calc_Pricing::config() and templates/calculator.php),
 *   - the assets the plugin enqueued (URL, version, dependencies, footer),
 *   - the Elementor Pro form widget built from page/out/page.json.
 *
 * php render.php [--editor] [--json] [--config-json] [--query=utm_source=x&...]
 *   (default)      print the shortcode markup
 *   --editor       current user can edit_posts (editors-only notices)
 *   --json         print {shortcode, form, form_id, assets, early, head, footer, notices} as JSON
 *   --config-json  print the config JSON (LP_CALC_CONFIG=stub prints the spec-shaped stub instead,
 *                  for the JS parity test of the plain-list config shape)
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

if ( in_array( '--config-json', $args, true ) ) {
	if ( 'stub' === getenv( 'LP_CALC_CONFIG' ) ) {
		require_once __DIR__ . '/stub-config.php';
		$config = lp_calc_ui_stub_config();
	} else {
		$config = LP_Calc_Pricing::config();
	}
	echo json_encode( $config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
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
