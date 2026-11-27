<?php
/**
 * A small WordPress "page request" for the UI and end-to-end harnesses.
 *
 * Loads the backend test stubs (test/php/stubs-wp.php, test/php/stubs-elementor.php), then the REAL
 * plugin through its main file, and fires `init` and `wp_enqueue_scripts` the way WordPress does for
 * a singular page whose `_elementor_data` is the page builder's output (page/out/elementor-data.json).
 * The calculator markup then comes from the real shortcode callback via do_shortcode(), exactly as
 * Elementor's shortcode widget calls it, and the assets from what the plugin enqueued.
 *
 * Used by test/ui/render.php (PHP CLI) and test/e2e/router.php (php -S).
 */

if ( defined( 'LP_ENV_LOADED' ) ) {
	return;
}
define( 'LP_ENV_LOADED', true );

define( 'LP_ENV_TEST_DIR', dirname( __DIR__ ) );
define( 'LP_ENV_CALC_DIR', dirname( __DIR__, 2 ) );
define( 'LP_ENV_PLUGIN_DIR', LP_ENV_CALC_DIR . '/plugin/ledgerplus-calculator' );
define( 'LP_ENV_PAGE_JSON', LP_ENV_CALC_DIR . '/page/out/page.json' );
define( 'LP_ENV_ELEMENTOR_DATA', LP_ENV_CALC_DIR . '/page/out/elementor-data.json' );
define( 'LP_ENV_PAGE_ID', 9001 );
define( 'LP_ENV_SHORTCODE', '[lp_package_calculator]' );

require_once LP_ENV_TEST_DIR . '/php/stubs-wp.php';
require_once LP_ENV_TEST_DIR . '/php/stubs-elementor.php';

// stubs-wp.php prints errors to stdout; keep stdout clean (HTML or JSON) and collect them instead.
ini_set( 'display_errors', 'cli' === PHP_SAPI ? 'stderr' : '0' );
ini_set( 'log_errors', '1' );
$GLOBALS['lp_env_notices'] = array();
set_error_handler(
	static function ( $no, $str, $file, $line ) {
		if ( error_reporting() & $no ) {
			$GLOBALS['lp_env_notices'][] = "[$no] $str (" . basename( $file ) . ":$line)";
		}
		return false; // Let PHP log it as well.
	}
);

if ( ! function_exists( 'do_shortcode' ) ) {
	/**
	 * Enough of do_shortcode() for registered self-closing shortcodes such as [lp_package_calculator].
	 */
	function do_shortcode( $content ) {
		$tags = array_keys( $GLOBALS['lp_test']['shortcodes'] );
		if ( ! $tags ) {
			return (string) $content;
		}
		$re = '/\[(' . implode( '|', array_map( 'preg_quote', $tags ) ) . ')(\s[^\]]*)?\]/';
		return preg_replace_callback(
			$re,
			static function ( $m ) {
				$atts = array();
				if ( isset( $m[2] ) && preg_match_all( '/(\w+)\s*=\s*"([^"]*)"/', $m[2], $pairs, PREG_SET_ORDER ) ) {
					foreach ( $pairs as $p ) {
						$atts[ strtolower( $p[1] ) ] = $p[2];
					}
				}
				return (string) call_user_func( $GLOBALS['lp_test']['shortcodes'][ $m[1] ], $atts ? $atts : '', null, $m[1] );
			},
			(string) $content
		);
	}
}
if ( ! function_exists( 'shortcode_unautop' ) ) {
	function shortcode_unautop( $s ) {
		return (string) $s;
	}
}

/**
 * Boots the page request once: the real plugin, `init`, the queried page and `wp_enqueue_scripts`.
 *
 * @param array $opts editor (bool): current user can edit_posts; page_title; url (the [geturl] value).
 */
function lp_env_boot( array $opts = array() ) {
	static $booted = false;
	$opts                         = array_merge(
		array(
			'editor'     => false,
			'page_title' => 'Build Your Package',
			'url'        => 'https://ledgerplus.ae/package-calculator/',
		),
		$opts
	);
	$GLOBALS['lp_env_opts']       = $opts;
	$GLOBALS['lp_test']['caps']   = $opts['editor'] ? array( 'edit_posts' ) : array();
	$GLOBALS['lp_test']['timezone'] = 'Asia/Dubai';
	if ( $booted ) {
		return;
	}
	$booted = true;

	require_once LP_ENV_PLUGIN_DIR . '/ledgerplus-calculator.php';

	// The site's [geturl] shortcode (used by the page_url hidden field's dynamic tag).
	add_shortcode(
		'geturl',
		static function () {
			return $GLOBALS['lp_env_opts']['url'];
		}
	);

	do_action( 'init' );

	// A singular Elementor page: post_content is not the layout, _elementor_data is.
	$GLOBALS['lp_test']['singular'] = true;
	$GLOBALS['lp_test']['queried']  = (object) array(
		'ID'           => LP_ENV_PAGE_ID,
		'post_title'   => $opts['page_title'],
		'post_content' => '',
		'post_type'    => 'page',
	);
	$GLOBALS['lp_test']['post_meta'][ LP_ENV_PAGE_ID ]['_elementor_data'] = is_file( LP_ENV_ELEMENTOR_DATA ) ? (string) file_get_contents( LP_ENV_ELEMENTOR_DATA ) : '';
	do_action( 'wp_enqueue_scripts' );
	// Enqueued in the head already (found in _elementor_data), before the shortcode itself runs.
	$GLOBALS['lp_env_early_enqueue'] = wp_style_is( 'lp-calc' ) && wp_script_is( 'lp-calc' );
}

/**
 * The shortcode widget's output, as Elementor renders it: do_shortcode( shortcode_unautop( ... ) ).
 *
 * @return string
 */
function lp_env_shortcode_html() {
	return do_shortcode( shortcode_unautop( LP_ENV_SHORTCODE ) );
}

/**
 * Enqueued plugin styles and scripts, with their URLs moved from the stub plugin URL to $base.
 *
 * @param string $base URL prefix that serves the plugin folder (e.g. '/wp-content/plugins/ledgerplus-calculator/').
 * @return array{styles: array, scripts: array}
 */
function lp_env_assets( $base ) {
	$stub_url = plugin_dir_url( LP_ENV_PLUGIN_DIR . '/ledgerplus-calculator.php' );
	$out      = array(
		'styles'  => array(),
		'scripts' => array(),
	);
	foreach ( array( 'styles', 'scripts' ) as $kind ) {
		foreach ( $GLOBALS['lp_test'][ $kind ] as $handle => $asset ) {
			if ( empty( $asset['enqueued'] ) ) {
				continue;
			}
			$src = (string) $asset['src'];
			if ( 0 === strpos( $src, $stub_url ) ) {
				$src = $base . substr( $src, strlen( $stub_url ) );
			}
			$out[ $kind ][] = array(
				'handle' => $handle,
				'src'    => $src . ( $asset['ver'] ? '?ver=' . rawurlencode( (string) $asset['ver'] ) : '' ),
				'deps'   => array_values( (array) $asset['deps'] ),
				'footer' => 'scripts' === $kind ? ( true === $asset['footer'] || ( is_array( $asset['footer'] ) && ! empty( $asset['footer']['in_footer'] ) ) ) : false,
			);
		}
	}
	return $out;
}

/**
 * The <link>/<script> tags WordPress would print for the enqueued assets.
 *
 * @param array $assets From lp_env_assets().
 * @return array{head: string, footer: string}
 */
function lp_env_asset_tags( array $assets ) {
	$head   = '';
	$footer = '';
	foreach ( $assets['styles'] as $s ) {
		$head .= "<link rel='stylesheet' id='" . esc_attr( $s['handle'] ) . "-css' href='" . esc_attr( $s['src'] ) . "' media='all' />\n";
	}
	foreach ( $assets['scripts'] as $s ) {
		$tag = '<script src="' . esc_attr( $s['src'] ) . '" id="' . esc_attr( $s['handle'] ) . '-js"></script>' . "\n";
		if ( $s['footer'] ) {
			$footer .= $tag;
		} else {
			$head .= $tag;
		}
	}
	return array(
		'head'   => $head,
		'footer' => $footer,
	);
}
