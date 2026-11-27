<?php
/**
 * Minimal WordPress stubs for the Ledger Plus calculator tests.
 * Only what the plugin calls. Not a WordPress emulator.
 */

error_reporting( E_ALL );
ini_set( 'display_errors', '1' );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

$GLOBALS['lp_test'] = array(
	'filters'    => array(),
	'actions'    => array(),
	'options'    => array(),
	'transients' => array(),
	'now'        => null, // null = real time().
	'mails'      => array(),
	'mail_ok'    => true,
	'caps'       => array(),
	'styles'     => array(),
	'scripts'    => array(),
	'shortcodes' => array(),
	'uploads'    => sys_get_temp_dir() . '/lp-calc-test-uploads',
	'temp_dir'   => sys_get_temp_dir() . '/',
	'singular'   => false,
	'queried'    => null,
	'post_meta'  => array(),
	'timezone'   => 'Asia/Dubai',
);

function lp_now() {
	return null === $GLOBALS['lp_test']['now'] ? time() : $GLOBALS['lp_test']['now'];
}

/* ---------------- Hooks ---------------- */

function add_filter( $tag, $cb, $priority = 10, $args = 1 ) {
	$GLOBALS['lp_test']['filters'][ $tag ][ $priority ][] = array( $cb, $args );
	return true;
}
function add_action( $tag, $cb, $priority = 10, $args = 1 ) {
	return add_filter( $tag, $cb, $priority, $args );
}
function remove_filter( $tag, $cb, $priority = 10 ) {
	if ( empty( $GLOBALS['lp_test']['filters'][ $tag ][ $priority ] ) ) {
		return false;
	}
	foreach ( $GLOBALS['lp_test']['filters'][ $tag ][ $priority ] as $i => $entry ) {
		if ( $entry[0] === $cb ) {
			unset( $GLOBALS['lp_test']['filters'][ $tag ][ $priority ][ $i ] );
			return true;
		}
	}
	return false;
}
function remove_action( $tag, $cb, $priority = 10 ) {
	return remove_filter( $tag, $cb, $priority );
}
function remove_all_filters( $tag ) {
	unset( $GLOBALS['lp_test']['filters'][ $tag ] );
}
function has_filter( $tag, $cb = false ) {
	return ! empty( $GLOBALS['lp_test']['filters'][ $tag ] );
}
function lp_test_hook_count( $tag ) {
	$n = 0;
	foreach ( $GLOBALS['lp_test']['filters'][ $tag ] ?? array() as $cbs ) {
		$n += count( $cbs );
	}
	return $n;
}
function lp_test_hook_priority( $tag, $cb ) {
	foreach ( $GLOBALS['lp_test']['filters'][ $tag ] ?? array() as $prio => $cbs ) {
		foreach ( $cbs as $entry ) {
			if ( $entry[0] === $cb ) {
				return array( $prio, $entry[1] );
			}
		}
	}
	return null;
}
function apply_filters( $tag, $value, ...$args ) {
	$all = $GLOBALS['lp_test']['filters'][ $tag ] ?? array();
	ksort( $all );
	foreach ( $all as $cbs ) {
		foreach ( $cbs as $entry ) {
			$value = call_user_func_array( $entry[0], array_slice( array_merge( array( $value ), $args ), 0, $entry[1] ) );
		}
	}
	return $value;
}
function do_action( $tag, ...$args ) {
	$GLOBALS['lp_test']['actions'][ $tag ] = ( $GLOBALS['lp_test']['actions'][ $tag ] ?? 0 ) + 1;
	$all = $GLOBALS['lp_test']['filters'][ $tag ] ?? array();
	ksort( $all );
	foreach ( $all as $cbs ) {
		foreach ( $cbs as $entry ) {
			call_user_func_array( $entry[0], array_slice( $args, 0, $entry[1] ) );
		}
	}
}
function do_action_ref_array( $tag, $args ) {
	do_action( $tag, ...$args );
}
function did_action( $tag ) {
	return $GLOBALS['lp_test']['actions'][ $tag ] ?? 0;
}

/* ---------------- Escaping / sanitizing (WordPress behaviour) ---------------- */

function __( $t, $d = 'default' ) {
	return $t;
}
function esc_html__( $t, $d = 'default' ) {
	return esc_html( $t );
}
function esc_attr__( $t, $d = 'default' ) {
	return esc_attr( $t );
}
function _e( $t, $d = 'default' ) {
	echo $t;
}
function esc_html_e( $t, $d = 'default' ) {
	echo esc_html( $t );
}
function esc_attr_e( $t, $d = 'default' ) {
	echo esc_attr( $t );
}
function _n( $single, $plural, $n, $d = 'default' ) {
	return 1 === (int) $n ? $single : $plural;
}
function _x( $t, $context, $d = 'default' ) {
	return $t;
}
function wp_unique_id( $prefix = '' ) {
	static $id = 0;
	return $prefix . (string) ++$id;
}
function wp_kses_post( $h ) {
	return (string) $h;
}
// WordPress does not double-encode existing entities in esc_html()/esc_attr().
function esc_html( $t ) {
	return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8', false );
}
function esc_attr( $t ) {
	return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8', false );
}
function esc_url( $u ) {
	$u = trim( (string) $u );
	if ( ! preg_match( '#^(https?:|tel:|mailto:)#i', $u ) ) {
		return '';
	}
	$u = str_replace( ' ', '%20', $u );
	return htmlspecialchars( $u, ENT_QUOTES, 'UTF-8', false );
}
function wp_strip_all_tags( $s, $remove_breaks = false ) {
	$s = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $s );
	$s = strip_tags( $s );
	if ( $remove_breaks ) {
		$s = preg_replace( '/[\r\n\t ]+/', ' ', $s );
	}
	return trim( $s );
}
function sanitize_text_field( $s ) {
	$s = (string) $s;
	if ( false !== strpos( $s, '<' ) ) {
		$s = preg_replace_callback( '%<[^>]*?((?=<)|>|$)%', function ( $m ) {
			return false === strpos( $m[0], '>' ) ? esc_html( $m[0] ) : $m[0];
		}, $s );
		$s = wp_strip_all_tags( $s, false );
	}
	$s = preg_replace( '/[\r\n\t ]+/', ' ', $s );
	return trim( $s );
}
function sanitize_textarea_field( $s ) {
	return trim( strip_tags( (string) $s ) );
}
function sanitize_email( $e ) {
	return preg_replace( '/[^a-z0-9@._+\-]/i', '', (string) $e );
}
// Close to WordPress' is_email(): local@domain.tld with a dot in the domain.
function is_email( $email ) {
	$email = (string) $email;
	if ( strlen( $email ) < 6 || false === strpos( $email, '@', 1 ) ) {
		return false;
	}
	list( $local, $domain ) = explode( '@', $email, 2 );
	if ( ! preg_match( '/^[a-zA-Z0-9!#$%&\'*+\/=?^_`{|}~\.-]+$/', $local ) ) {
		return false;
	}
	if ( preg_match( '/\.{2,}/', $domain ) || trim( $domain, " \t\n\r\0\x0B." ) !== $domain ) {
		return false;
	}
	$subs = explode( '.', $domain );
	if ( count( $subs ) < 2 ) {
		return false;
	}
	foreach ( $subs as $sub ) {
		if ( trim( $sub, " \t\n\r\0\x0B-" ) !== $sub || ! preg_match( '/^[a-z0-9-]+$/i', $sub ) ) {
			return false;
		}
	}
	return $email;
}
function absint( $n ) {
	return abs( (int) $n );
}
function wp_unslash( $v ) {
	return is_array( $v ) ? array_map( 'wp_unslash', $v ) : stripslashes( (string) $v );
}
function wp_json_encode( $data, $options = 0, $depth = 512 ) {
	return json_encode( $data, $options, $depth );
}
function trailingslashit( $s ) {
	return rtrim( (string) $s, '/\\' ) . '/';
}

/* ---------------- Options / transients ---------------- */

function get_option( $k, $default = false ) {
	return array_key_exists( $k, $GLOBALS['lp_test']['options'] ) ? $GLOBALS['lp_test']['options'][ $k ] : $default;
}
function get_transient( $k ) {
	$t = $GLOBALS['lp_test']['transients'][ $k ] ?? null;
	if ( null === $t ) {
		return false;
	}
	if ( $t['exp'] && $t['exp'] <= lp_now() ) {
		unset( $GLOBALS['lp_test']['transients'][ $k ] );
		return false;
	}
	return $t['value'];
}
function set_transient( $k, $v, $exp = 0 ) {
	if ( strlen( $k ) > 172 ) {
		return false; // WordPress limit.
	}
	$GLOBALS['lp_test']['transients'][ $k ] = array(
		'value' => $v,
		'exp'   => $exp ? lp_now() + (int) $exp : 0,
		'ttl'   => (int) $exp,
	);
	return true;
}
function delete_transient( $k ) {
	unset( $GLOBALS['lp_test']['transients'][ $k ] );
	return true;
}

/* ---------------- Paths / time ---------------- */

function plugin_dir_path( $file ) {
	return trailingslashit( dirname( $file ) );
}
function plugin_dir_url( $file ) {
	return 'https://example.test/wp-content/plugins/' . basename( dirname( $file ) ) . '/';
}
function wp_upload_dir( $time = null, $create = true ) {
	return array(
		'basedir' => $GLOBALS['lp_test']['uploads'],
		'baseurl' => 'https://example.test/wp-content/uploads',
		'error'   => false,
	);
}
function get_temp_dir() {
	return trailingslashit( $GLOBALS['lp_test']['temp_dir'] );
}
function wp_timezone() {
	return new DateTimeZone( $GLOBALS['lp_test']['timezone'] );
}
function wp_is_writable( $path ) {
	return is_writable( $path );
}

/* ---------------- Users / assets / shortcodes ---------------- */

function current_user_can( $cap ) {
	return in_array( $cap, $GLOBALS['lp_test']['caps'], true );
}
function add_shortcode( $tag, $cb ) {
	$GLOBALS['lp_test']['shortcodes'][ $tag ] = $cb;
}
function shortcode_atts( $pairs, $atts, $shortcode = '' ) {
	$atts = (array) $atts;
	$out  = array();
	foreach ( $pairs as $name => $default ) {
		$out[ $name ] = array_key_exists( $name, $atts ) ? $atts[ $name ] : $default;
	}
	return $out;
}
function has_shortcode( $content, $tag ) {
	return false !== strpos( (string) $content, '[' . $tag );
}
function wp_register_style( $h, $src, $deps = array(), $ver = false, $media = 'all' ) {
	$GLOBALS['lp_test']['styles'][ $h ] = array( 'src' => $src, 'deps' => $deps, 'ver' => $ver, 'enqueued' => false );
	return true;
}
function wp_register_script( $h, $src, $deps = array(), $ver = false, $args = false ) {
	$GLOBALS['lp_test']['scripts'][ $h ] = array( 'src' => $src, 'deps' => $deps, 'ver' => $ver, 'footer' => $args, 'enqueued' => false );
	return true;
}
function wp_enqueue_style( $h, $src = '', $deps = array(), $ver = false, $media = 'all' ) {
	if ( $src ) {
		wp_register_style( $h, $src, $deps, $ver, $media );
	}
	if ( isset( $GLOBALS['lp_test']['styles'][ $h ] ) ) {
		$GLOBALS['lp_test']['styles'][ $h ]['enqueued'] = true;
	}
}
function wp_enqueue_script( $h, $src = '', $deps = array(), $ver = false, $args = false ) {
	if ( $src ) {
		wp_register_script( $h, $src, $deps, $ver, $args );
	}
	if ( isset( $GLOBALS['lp_test']['scripts'][ $h ] ) ) {
		$GLOBALS['lp_test']['scripts'][ $h ]['enqueued'] = true;
	}
}
function wp_style_is( $h, $list = 'enqueued' ) {
	return 'registered' === $list ? isset( $GLOBALS['lp_test']['styles'][ $h ] ) : ! empty( $GLOBALS['lp_test']['styles'][ $h ]['enqueued'] );
}
function wp_script_is( $h, $list = 'enqueued' ) {
	return 'registered' === $list ? isset( $GLOBALS['lp_test']['scripts'][ $h ] ) : ! empty( $GLOBALS['lp_test']['scripts'][ $h ]['enqueued'] );
}
function is_singular( $type = '' ) {
	return $GLOBALS['lp_test']['singular'];
}
function get_queried_object() {
	return $GLOBALS['lp_test']['queried'];
}
function get_post_meta( $id, $key = '', $single = false ) {
	return $GLOBALS['lp_test']['post_meta'][ $id ][ $key ] ?? '';
}

/* ---------------- Mail ---------------- */

class LP_Test_PHPMailer {
	public $AltBody = ''; // phpcs:ignore
	public $Body    = ''; // phpcs:ignore
}

/**
 * Captures the call. Attachment files are read now, because the plugin deletes them afterwards.
 */
function wp_mail( $to, $subject, $message, $headers = '', $attachments = array() ) {
	$phpmailer       = new LP_Test_PHPMailer();
	$phpmailer->Body = $message;
	do_action_ref_array( 'phpmailer_init', array( &$phpmailer ) );
	$files = array();
	foreach ( (array) $attachments as $path ) {
		$files[] = array(
			'path'   => $path,
			'name'   => basename( $path ),
			'exists' => is_file( $path ),
			'bytes'  => is_file( $path ) ? file_get_contents( $path ) : '',
		);
	}
	$GLOBALS['lp_test']['mails'][] = array(
		'to'          => $to,
		'subject'     => $subject,
		'message'     => $message,
		'headers'     => $headers,
		'attachments' => $files,
		'alt_body'    => $phpmailer->AltBody,
	);
	return $GLOBALS['lp_test']['mail_ok'];
}
