<?php
/**
 * Router for the end-to-end harness: php -S 127.0.0.1:<port> calculator/test/e2e/router.php
 *
 *   GET  /package-calculator/   the harness page: the shell written by e2e.test.js (site header, CSS,
 *                               hero, jQuery, intl-tel-input, Elementor form handler double) filled with
 *                               the REAL shortcode output, the plugin's enqueued assets and the form
 *                               widget rendered from page/out/page.json (dynamic tags resolved for this
 *                               request, so utm_* come from the query string).
 *   POST /wp-admin/admin-ajax.php
 *                               action=elementor_pro_forms_send_form, emulating Elementor Pro 4.3.1's
 *                               Ajax_Handler::ajax_send_form(): Form_Record from the page.json form
 *                               settings, validate() (elementor_pro/forms/validation), process_fields(),
 *                               the actions_before filter, the save-to-database / email / zoho_Ledgers_Plus
 *                               actions (fakes that record replace_setting_shortcodes() of the real
 *                               settings), elementor_pro/forms/new_record, send(). The real plugin is
 *                               loaded through its main file and does the rest (validation, rate limit,
 *                               quote fields, PDF, client email through the wp_mail stub).
 *   GET  /__e2e/state           everything recorded so far (JSON).
 *   POST /__e2e/reset           clears the recorded state and the transients. Body (JSON, optional):
 *                               {"zoho_throws": true} makes the fake Zoho action throw; {"keep_files": true}
 *                               keeps the saved mail-* files of earlier requests; {"drop_hooks": ["process",
 *                               "actions_before"]} removes those plugin hooks, to prove the server paths
 *                               where one (or both) of them does not run.
 *   static                      /wp-content/plugins/ledgerplus-calculator/*, /fixtures/*, /node_modules/*.
 *
 * State lives in $LP_E2E_STATE (default calculator/test/out/e2e): state.json (transients survive
 * between requests, so the rate limit works across submissions), saved PDF attachments and client
 * email bodies, uploads/ (the logo), tmp/ (WordPress temp dir; must be empty after each request) and
 * php-error.log (error_log() output, e.g. the plugin's "[lp-calc] ..." lines).
 */

$e2e_dir   = __DIR__;
$test_dir  = dirname( __DIR__ );
$state_dir = getenv( 'LP_E2E_STATE' ) ? getenv( 'LP_E2E_STATE' ) : $test_dir . '/out/e2e';
$path      = (string) parse_url( isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH );
$method    = isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : 'GET';

if ( ! is_dir( $state_dir ) ) {
	mkdir( $state_dir, 0777, true );
}

/* ------------------------------------------------------------------ state */

function lp_e2e_state_file() {
	return $GLOBALS['state_dir'] . '/state.json';
}

function lp_e2e_load() {
	$raw   = is_file( lp_e2e_state_file() ) ? file_get_contents( lp_e2e_state_file() ) : '';
	$state = json_decode( (string) $raw, true );
	return is_array( $state ) ? $state : array(
		'requests'   => array(),
		'transients' => array(),
		'options'    => array(),
	);
}

function lp_e2e_save( array $state ) {
	file_put_contents( lp_e2e_state_file(), json_encode( $state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR ), LOCK_EX );
}

function lp_e2e_json( $data, $status = 200 ) {
	http_response_code( $status );
	header( 'Content-Type: application/json; charset=UTF-8' );
	echo json_encode( $data ); // As wp_send_json(): wp_json_encode() with no flags.
	exit;
}

/** A logo with an alpha channel at the uploads path the plugin uses by default. */
function lp_e2e_logo( $uploads ) {
	$file = $uploads . '/2026/09/Logo-Assests-01-edited.png';
	if ( is_file( $file ) || ! function_exists( 'imagecreatetruecolor' ) ) {
		return;
	}
	@mkdir( dirname( $file ), 0777, true );
	$im = imagecreatetruecolor( 622, 210 );
	imagesavealpha( $im, true );
	imagealphablending( $im, false );
	imagefill( $im, 0, 0, imagecolorallocatealpha( $im, 255, 255, 255, 127 ) );
	imagealphablending( $im, true );
	imagefilledrectangle( $im, 20, 40, 150, 170, imagecolorallocate( $im, 4, 25, 80 ) );
	imagefilledrectangle( $im, 170, 70, 600, 140, imagecolorallocate( $im, 15, 92, 216 ) );
	imagepng( $im, $file );
}

/* ------------------------------------------------------------------ static files */

$static = array(
	'/wp-content/plugins/ledgerplus-calculator/' => dirname( $test_dir ) . '/plugin/ledgerplus-calculator/',
	'/fixtures/'                                 => $test_dir . '/ui/fixtures/',
	'/node_modules/'                             => $test_dir . '/node_modules/',
);
foreach ( $static as $prefix => $base ) {
	if ( 0 !== strpos( $path, $prefix ) ) {
		continue;
	}
	$file = realpath( $base . rawurldecode( substr( $path, strlen( $prefix ) ) ) );
	$root = realpath( $base );
	if ( false === $file || false === $root || 0 !== strpos( $file, $root . DIRECTORY_SEPARATOR ) || ! is_file( $file ) || 'php' === pathinfo( $file, PATHINFO_EXTENSION ) ) {
		http_response_code( 404 );
		echo 'not found';
		exit;
	}
	$types = array(
		'css'   => 'text/css',
		'js'    => 'application/javascript',
		'png'   => 'image/png',
		'svg'   => 'image/svg+xml',
		'woff2' => 'font/woff2',
		'html'  => 'text/html',
	);
	$ext = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
	header( 'Content-Type: ' . ( isset( $types[ $ext ] ) ? $types[ $ext ] : 'application/octet-stream' ) );
	readfile( $file );
	exit;
}

/* ------------------------------------------------------------------ test control */

if ( '/__e2e/state' === $path ) {
	lp_e2e_json( lp_e2e_load() );
}
if ( '/__e2e/reset' === $path && 'POST' === $method ) {
	$opts  = json_decode( (string) file_get_contents( 'php://input' ), true );
	$state = array(
		'requests'   => array(),
		'transients' => array(),
		'options'    => is_array( $opts ) ? $opts : array(),
	);
	if ( empty( $opts['keep_files'] ) ) {
		foreach ( (array) glob( $state_dir . '/mail-*' ) as $old ) {
			@unlink( $old );
		}
	}
	@file_put_contents( $state_dir . '/php-error.log', '' );
	lp_e2e_save( $state );
	lp_e2e_json( array( 'ok' => true ) );
}
if ( '/__e2e/options' === $path && 'POST' === $method ) {
	$opts             = json_decode( (string) file_get_contents( 'php://input' ), true );
	$state            = lp_e2e_load();
	$state['options'] = is_array( $opts ) ? $opts : array();
	lp_e2e_save( $state );
	lp_e2e_json( array( 'ok' => true ) );
}

/* ------------------------------------------------------------------ WordPress */

$scheme = 'http';
$host   = isset( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : '127.0.0.1';

require $test_dir . '/wp/env.php';
require $test_dir . '/wp/elementor-form.php';

ini_set( 'error_log', $state_dir . '/php-error.log' );
$GLOBALS['lp_test']['uploads']  = $state_dir . '/uploads';
$GLOBALS['lp_test']['temp_dir'] = $state_dir . '/tmp/';
@mkdir( $state_dir . '/tmp', 0777, true );

if ( '/package-calculator/' === $path && 'GET' === $method ) {
	$shell = is_file( $state_dir . '/shell.html' ) ? file_get_contents( $state_dir . '/shell.html' ) : '';
	if ( '' === $shell ) {
		http_response_code( 500 );
		echo 'shell.html missing: e2e.test.js writes it';
		exit;
	}
	lp_env_boot( array( 'url' => $scheme . '://' . $host . $_SERVER['REQUEST_URI'] ) );
	$assets = lp_env_assets( '/wp-content/plugins/ledgerplus-calculator/' );
	$tags   = lp_env_asset_tags( $assets );
	$widget = lp_ef_widget();
	$parts  = array(
		'<!--lp:head-->'      => $tags['head'],
		'<!--lp:shortcode-->' => lp_env_shortcode_html(),
		'<!--lp:form-->'      => $widget ? lp_ef_render( $widget, array( 'query' => $_GET ) ) : '',
		'<!--lp:footer-->'    => $tags['footer'],
	);
	if ( $GLOBALS['lp_env_notices'] ) {
		http_response_code( 500 );
		echo 'PHP notices: ' . htmlspecialchars( implode( '; ', $GLOBALS['lp_env_notices'] ) );
		exit;
	}
	header( 'Content-Type: text/html; charset=UTF-8' );
	echo strtr( $shell, $parts );
	exit;
}

if ( '/wp-admin/admin-ajax.php' === $path && 'POST' === $method ) {
	$state   = lp_e2e_load();
	$n       = count( $state['requests'] ) + 1;
	// File prefix for saved mails: a sequence that survives /__e2e/reset.
	$seq     = (int) @file_get_contents( $state_dir . '/seq' ) + 1;
	file_put_contents( $state_dir . '/seq', (string) $seq );
	$log_len = is_file( $state_dir . '/php-error.log' ) ? filesize( $state_dir . '/php-error.log' ) : 0;
	$entry   = array(
		'n'      => $n,
		'post'   => $_POST,
		'ip'     => isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : '',
		'tmp_before' => array_values( array_map( 'basename', (array) glob( $state_dir . '/tmp/*' ) ) ),
	);

	lp_env_boot( array( 'url' => isset( $_SERVER['HTTP_REFERER'] ) ? $_SERVER['HTTP_REFERER'] : '' ) );
	lp_ef_honeypot();
	$drop = isset( $state['options']['drop_hooks'] ) ? (array) $state['options']['drop_hooks'] : array();
	if ( in_array( 'process', $drop, true ) ) {
		remove_action( 'elementor_pro/forms/process', array( 'LP_Calc_Elementor', 'process' ), 10 );
	}
	if ( in_array( 'actions_before', $drop, true ) ) {
		remove_filter( 'elementor_pro/forms/record/actions_before', array( 'LP_Calc_Elementor', 'actions_before' ), 10 );
	}
	$entry['dropped_hooks'] = array_values( $drop );
	lp_e2e_logo( $GLOBALS['lp_test']['uploads'] );
	$GLOBALS['lp_test']['transients'] = isset( $state['transients'] ) ? $state['transients'] : array();
	$GLOBALS['lp_test']['mails']      = array();

	if ( ! isset( $_POST['action'] ) || 'elementor_pro_forms_send_form' !== $_POST['action'] ) {
		// admin-ajax.php with no handler for the action.
		http_response_code( 400 );
		echo '0';
		exit;
	}

	$response = null;
	$log      = array();
	try {
		$widget = lp_ef_widget( isset( $_POST['form_id'] ) ? (string) $_POST['form_id'] : '' );
		if ( ! $widget || ! isset( $_POST['post_id'] ) || (string) LP_ENV_PAGE_ID !== (string) $_POST['post_id'] ) {
			// Elementor: the document or the form was not found.
			$response = array(
				'success' => false,
				'data'    => array(
					'message' => \ElementorPro\Modules\Forms\Classes\Ajax_Handler::get_default_message( 'error', array() ),
					'errors'  => array(),
					'data'    => array(),
				),
			);
		} else {
			$handler  = new \ElementorPro\Modules\Forms\Classes\Ajax_Handler();
			$actions  = lp_ef_actions( $log, isset( $state['options'] ) ? $state['options'] : array() );
			$fields   = isset( $_POST['form_fields'] ) && is_array( $_POST['form_fields'] ) ? $_POST['form_fields'] : array();
			$result   = $handler->ajax_send_form( lp_ef_handler_form( $widget ), $fields, $actions );
			$response = array(
				'success' => $result['success'],
				'data'    => $result['data'],
			);
			$entry['admin_errors'] = $handler->messages['admin_error'];
		}
	} catch ( Throwable $e ) {
		$entry['exception'] = get_class( $e ) . ': ' . $e->getMessage() . ' (' . basename( $e->getFile() ) . ':' . $e->getLine() . ')';
		$response           = array(
			'success' => false,
			'data'    => array(
				'message' => 'Your submission failed because of a server error.',
				'errors'  => array(),
				'data'    => array(),
			),
		);
	}

	// What the client email would have carried. The stub read the attachment when wp_mail() ran;
	// the plugin must have deleted the temporary file by now.
	$mails = array();
	foreach ( $GLOBALS['lp_test']['mails'] as $i => $mail ) {
		$html_file = $state_dir . '/mail-' . $seq . '-' . $i . '.html';
		file_put_contents( $html_file, $mail['message'] );
		$attachments = array();
		foreach ( $mail['attachments'] as $att ) {
			$saved = $state_dir . '/mail-' . $seq . '-' . $i . '-' . $att['name'];
			file_put_contents( $saved, $att['bytes'] );
			$attachments[] = array(
				'name'         => $att['name'],
				'existed'      => $att['exists'],
				'exists_after' => is_file( $att['path'] ),
				'dir_after'    => is_dir( dirname( $att['path'] ) ),
				'bytes'        => strlen( $att['bytes'] ),
				'head'         => substr( $att['bytes'], 0, 8 ),
				'saved_as'     => $saved,
			);
		}
		$mails[] = array(
			'to'          => $mail['to'],
			'subject'     => $mail['subject'],
			'headers'     => $mail['headers'],
			'html_file'   => $html_file,
			'alt_body'    => $mail['alt_body'],
			'attachments' => $attachments,
		);
	}

	clearstatcache();
	$log_now               = is_file( $state_dir . '/php-error.log' ) ? (string) file_get_contents( $state_dir . '/php-error.log' ) : '';
	$entry['response']     = $response;
	$entry['saved']        = $log ? $log['saved'] : array();
	$entry['team_email']   = $log ? $log['team_email'] : array();
	$entry['zoho']         = $log ? $log['zoho'] : array();
	$entry['mails']        = $mails;
	$entry['tmp_after']    = array_values( array_map( 'basename', (array) glob( $state_dir . '/tmp/*' ) ) );
	$entry['error_log']    = substr( $log_now, $log_len );
	$entry['notices']      = $GLOBALS['lp_env_notices'];
	$state['requests'][]   = $entry;
	$state['transients']   = $GLOBALS['lp_test']['transients'];
	lp_e2e_save( $state );

	lp_e2e_json( $response );
}

http_response_code( 404 );
echo 'not found: ' . htmlspecialchars( $path );
