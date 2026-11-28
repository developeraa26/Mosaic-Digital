<?php
/**
 * The [lp_package_calculator] shortcode.
 *
 * @package LedgerPlusCalculator
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the shortcode, enqueues the assets and includes the template.
 */
final class LP_Calc_Shortcode {

	const TAG    = 'lp_package_calculator';
	const HANDLE = 'lp-calc';

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
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_enqueue_early' ) );
	}

	/**
	 * Registers the shortcode and the assets.
	 */
	public static function register() {
		add_shortcode( self::TAG, array( __CLASS__, 'render' ) );
		self::register_assets();
	}

	/**
	 * Enqueues the assets.
	 */
	public static function enqueue() {
		if ( ! wp_style_is( self::HANDLE, 'registered' ) || ! wp_script_is( self::HANDLE, 'registered' ) ) {
			self::register_assets();
		}
		wp_enqueue_style( self::HANDLE );
		wp_enqueue_script( self::HANDLE );
	}

	/**
	 * Registers the stylesheet and the script (footer, depends on jQuery).
	 */
	private static function register_assets() {
		wp_register_style( self::HANDLE, LP_CALC_URL . 'assets/calculator.css', array(), LP_CALC_VERSION );
		wp_register_script( self::HANDLE, LP_CALC_URL . 'assets/calculator.js', array( 'jquery' ), LP_CALC_VERSION, true );
	}

	/**
	 * Enqueues the assets in the head when the page uses the shortcode, so the stylesheet loads
	 * before the content (no flash of unstyled content). The shortcode enqueues them anyway.
	 */
	public static function maybe_enqueue_early() {
		if ( ! function_exists( 'is_singular' ) || ! is_singular() ) {
			return;
		}
		$post = get_queried_object();
		if ( ! is_object( $post ) || empty( $post->ID ) ) {
			return;
		}
		$found = isset( $post->post_content ) && has_shortcode( (string) $post->post_content, self::TAG );
		if ( ! $found ) {
			$data  = get_post_meta( $post->ID, '_elementor_data', true );
			$found = is_string( $data ) && false !== strpos( $data, self::TAG );
		}
		if ( $found ) {
			self::enqueue();
		}
	}

	/**
	 * Shortcode output: templates/calculator.php with $config and $is_editor in scope.
	 *
	 * $config is LP_Calc_Pricing::browser_config(): the choices and labels, without prices. The
	 * page shows prices only after the server has priced a submission.
	 *
	 * @param array|string $atts    Attributes (none used).
	 * @param string|null  $content Content.
	 * @return string
	 */
	public static function render( $atts = array(), $content = null ) {
		self::enqueue();

		$template = LP_CALC_DIR . 'templates/calculator.php';
		if ( ! is_readable( $template ) ) {
			return '';
		}
		$config    = LP_Calc_Pricing::browser_config();
		$is_editor = current_user_can( 'edit_posts' );

		ob_start();
		include $template;
		$html = (string) ob_get_clean();

		// The template prints the config; add it here if it does not.
		if ( false === strpos( $html, 'id="lp-calc-config"' ) ) {
			$html .= self::config_script( $config );
		}
		return $html;
	}

	/**
	 * The config as a JSON script element.
	 *
	 * @param array $config Config from LP_Calc_Pricing::browser_config().
	 * @return string
	 */
	public static function config_script( array $config ) {
		$json = wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE );
		return '<script type="application/json" id="lp-calc-config">' . ( false === $json ? '{}' : $json ) . '</script>';
	}
}
