<?php
/**
 * F14 test fixtures: a stub of the Zoho CRM add-on's Crm class and an in-memory fake Zoho CRM v2 API.
 *
 * The stub follows what the brief read from the live add-on ("Elementor Pro Form Widget - Zoho CRM - Integration"
 * 1.21.0, includes/Crm.php): public static sendPostRequest / sendPutRequest / sendGetRequest( $model, $fields ) fill a
 * private static $authorizationInfo lazily (RequestHelper::getAuthInfo()), return that value instead of calling Zoho
 * when it is a WP_Error or has no access_token, apply itglx_elpro_zoho_post_data_before_send (POST / PUT), call
 * wp_remote_post / wp_remote_request / wp_remote_get on "{api_domain}/crm/v2/{model}" with a 20 s timeout (WordPress
 * passes the arguments through http_request_args and the reply through http_response, WP_Http::request()), and
 * return json_decode( body, true ): null for an empty or non-JSON body (Zoho answers a search without match with
 * HTTP 204; a 5xx error page has no JSON either). The token refresh can be modelled as one more HTTP request in the
 * same call (refresh_latency).
 *
 * Nothing here is a real token or domain. Load with: require __DIR__ . '/zoho-fixtures.php';
 * Mode "no-addon" of run-f14.php sets LP_TEST_ZOHO_ADDON = 'none' (class not defined) or 'old' (no sendPutRequest)
 * before loading this file.
 */

namespace Itgalaxy\Elementor\Form\Zoho\Integration\Includes {

	if ( ! defined( 'LP_TEST_ZOHO_ADDON' ) || 'full' === LP_TEST_ZOHO_ADDON ) {
		class Crm {
			/** @var mixed RequestHelper::getAuthInfo() result, filled once per request like the add-on. */
			private static $authorizationInfo = null;

			public static function sendPostRequest( $model, $fields = array() ) {
				return self::request( 'POST', $model, $fields );
			}
			public static function sendPutRequest( $model, $fields = array() ) {
				return self::request( 'PUT', $model, $fields );
			}
			public static function sendGetRequest( $model, $fields = array() ) {
				return self::request( 'GET', $model, $fields );
			}
			/** Test helper: a new "request" (the add-on's static auth cache starts empty again). */
			public static function lp_test_reset() {
				self::$authorizationInfo = null;
			}
			private static function request( $method, $model, $fields ) {
				if ( null === self::$authorizationInfo ) {
					$GLOBALS['lp_test']['zoho_auth_reads'] = ( $GLOBALS['lp_test']['zoho_auth_reads'] ?? 0 ) + 1;
					if ( ! empty( $GLOBALS['lp_zoho']['refresh_latency'] ) ) {
						\lp_zoho_token_refresh( (float) $GLOBALS['lp_zoho']['refresh_latency'] );
					}
					self::$authorizationInfo = $GLOBALS['lp_test']['zoho_auth'] ?? \lp_zoho_connected_auth();
				}
				$auth = self::$authorizationInfo;
				if ( \is_wp_error( $auth ) || ! is_array( $auth ) || empty( $auth['access_token'] ) ) {
					$GLOBALS['lp_test']['zoho_calls'][] = array( 'method' => $method, 'model' => $model, 'fields' => $fields, 'timeout' => null, 'sent' => false );
					return $auth; // Not connected: the add-on hands back its own auth data / error.
				}
				if ( 'GET' !== $method ) {
					$fields = \apply_filters( 'itglx_elpro_zoho_post_data_before_send', $fields );
				}
				$url  = $auth['api_domain'] . '/crm/v2/' . $model;
				$args = array(
					'method'  => $method,
					'headers' => array( 'Authorization' => 'Zoho-oauthtoken ' . $auth['access_token'], 'Content-Type' => 'application/json' ),
					'body'    => 'GET' === $method ? $fields : json_encode( $fields ),
					'timeout' => 20,
				);
				$args = \apply_filters( 'http_request_args', $args, $url ); // WP_Http::request()
				$GLOBALS['lp_test']['zoho_calls'][] = array( 'method' => $method, 'model' => $model, 'fields' => $fields, 'timeout' => $args['timeout'], 'sent' => true );
				if ( function_exists( 'adv_point' ) ) {
					\adv_point( 'zoho_http' );
				}
				$response = \lp_zoho_handle( $method, $model, $fields, $args );
				if ( \is_wp_error( $response ) ) {
					return $response;
				}
				if ( empty( $GLOBALS['lp_zoho']['no_http_response'] ) ) {
					// WP_Http::request() hands every reply through http_response before returning it. no_http_response
					// models another plugin answering the request itself (pre_http_request), which skips that filter.
					$response = \apply_filters( 'http_response', array( 'headers' => array(), 'body' => (string) $response['body'], 'response' => array( 'code' => (int) $response['code'], 'message' => '' ), 'cookies' => array() ), $args, $url );
				}
				return json_decode( (string) $response['body'], true );
			}
		}
	} elseif ( 'old' === LP_TEST_ZOHO_ADDON ) {
		/** An add-on version without sendPutRequest(): the plugin must treat it as missing. */
		class Crm {
			public static function sendPostRequest( $model, $fields = array() ) {
				$GLOBALS['lp_test']['zoho_calls'][] = array( 'method' => 'POST', 'model' => $model, 'fields' => $fields, 'timeout' => null, 'sent' => true );
				return null;
			}
			public static function sendGetRequest( $model, $fields = array() ) {
				$GLOBALS['lp_test']['zoho_calls'][] = array( 'method' => 'GET', 'model' => $model, 'fields' => $fields, 'timeout' => null, 'sent' => true );
				return null;
			}
		}
	}
}

namespace {

	const LP_FAKE_ZOHO_ACCESS  = '1000.fakeaccess0123456789abcdef0123456789.fakeaccess0123456789abcdef01';
	const LP_FAKE_ZOHO_REFRESH = '1000.fakerefresh0123456789abcdef012345678.fakerefresh0123456789abcdef';
	const LP_FAKE_ZOHO_DOMAIN  = 'https://fake-zoho-api.example.invalid';

	/** The add-on's auth data when it is connected (fake values). */
	function lp_zoho_connected_auth() {
		return array(
			'access_token'  => LP_FAKE_ZOHO_ACCESS,
			'refresh_token' => LP_FAKE_ZOHO_REFRESH,
			'api_domain'    => LP_FAKE_ZOHO_DOMAIN,
		);
	}

	/** Action Scheduler 3.x as_enqueue_async_action() (recorded like the other actions, due now). */
	if ( ! function_exists( 'as_enqueue_async_action' ) ) {
		function as_enqueue_async_action( $hook, $args = array(), $group = '', $unique = false, $priority = 10 ) {
			if ( ! empty( $GLOBALS['lp_test']['as_throw'] ) ) {
				throw new RuntimeException( is_string( $GLOBALS['lp_test']['as_throw'] ) ? $GLOBALS['lp_test']['as_throw'] : 'Action Scheduler store unavailable' );
			}
			$GLOBALS['lp_test']['as'][] = array( 'time' => time(), 'hook' => $hook, 'args' => $args, 'group' => $group, 'status' => 'pending', 'async' => true );
			return count( $GLOBALS['lp_test']['as'] );
		}
	}

	/** A fresh fake Zoho: records of the module, notes, the id counter, behaviour switches and injected answers. */
	function lp_zoho_reset( $options = array() ) {
		$GLOBALS['lp_zoho'] = array_merge(
			array(
				'module'           => 'Ledgers_Plus',
				'records'          => array(),
				'notes'            => array(),
				'next_id'          => 5725767000000400001,
				'unique_email'     => false, // Zoho duplicate check on Email: create answers DUPLICATE_DATA.
				'search_blind'     => false, // Search finds nothing (e.g. a record created seconds ago is not indexed yet).
				'latency'          => 0.0,   // Seconds each request takes.
				'inject'           => array(), // Answers for the next requests (FIFO): WP_Error, array( code, body ), or a callable.
				'writes'           => array(), // Every create / update with its trigger value.
				'late_writes'      => false, // A write answered after the client's time-out is carried out all the same (like a real server).
				'refresh_latency'  => 0.0,   // > 0: the add-on refreshes its token first (one more HTTP request in the same call).
				'no_http_response' => false, // The http_response filter is not run (another plugin answered the request).
			),
			$options
		);
		$GLOBALS['lp_test']['zoho_refreshes'] = array();
		$GLOBALS['lp_test']['zoho_calls'] = array();
		unset( $GLOBALS['lp_test']['zoho_auth'] );
		if ( class_exists( '\Itgalaxy\Elementor\Form\Zoho\Integration\Includes\Crm' ) && method_exists( '\Itgalaxy\Elementor\Form\Zoho\Integration\Includes\Crm', 'lp_test_reset' ) ) {
			\Itgalaxy\Elementor\Form\Zoho\Integration\Includes\Crm::lp_test_reset();
		}
	}

	/** Adds an existing record to the fake Zoho (as the website forms would have created it). */
	function lp_zoho_add_record( $fields ) {
		$id                                  = (string) $GLOBALS['lp_zoho']['next_id']++;
		$GLOBALS['lp_zoho']['records'][ $id ] = array_merge( array( 'id' => $id ), $fields );
		return $id;
	}

	function lp_zoho_json( $code, $data ) {
		return array( 'code' => $code, 'body' => null === $data ? '' : json_encode( $data ) );
	}

	function lp_zoho_success( $id, $message = 'record added' ) {
		return array( 'data' => array( array( 'code' => 'SUCCESS', 'details' => array( 'Modified_Time' => '2026-11-27T10:00:00+04:00', 'id' => $id ), 'message' => $message, 'status' => 'success' ) ) );
	}

	function lp_zoho_item_error( $code, $message, $details = array() ) {
		return array( 'data' => array( array( 'code' => $code, 'details' => $details, 'message' => $message, 'status' => 'error' ) ) );
	}

	function lp_zoho_top_error( $code, $message ) {
		return array( 'code' => $code, 'details' => array(), 'message' => $message, 'status' => 'error' );
	}

	/**
	 * The add-on's token refresh inside RequestHelper::getAuthInfo() (not readable; modelled as one wp_remote_post()
	 * with WordPress's default 5 s time-out): goes through http_request_args and http_response like any WP_Http request.
	 */
	function lp_zoho_token_refresh( $latency ) {
		$url  = 'https://accounts.example.invalid/oauth/v2/token';
		$args = \apply_filters( 'http_request_args', array( 'method' => 'POST', 'timeout' => 5, 'body' => array( 'grant_type' => 'refresh_token' ) ), $url );
		$GLOBALS['lp_test']['zoho_refreshes'][] = array( 'timeout' => $args['timeout'], 'at' => microtime( true ) );
		usleep( (int) ( 1000000 * min( $latency, (float) $args['timeout'] ) ) );
		\apply_filters( 'http_response', array( 'headers' => array(), 'body' => '{}', 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array() ), $args, $url );
	}

	/** The fake Zoho CRM v2 API behind the add-on stub. */
	function lp_zoho_handle( $method, $model, $fields, $args ) {
		$z       = &$GLOBALS['lp_zoho'];
		$latency = (float) $z['latency'];
		if ( $latency > 0 ) {
			$timeout = (float) $args['timeout'];
			usleep( (int) ( 1000000 * min( $latency, $timeout ) ) );
			if ( $latency > $timeout ) {
				if ( ! empty( $z['late_writes'] ) && 'GET' !== $method ) {
					// Zoho carries the write out; only its answer comes too late for the client.
					$z['latency'] = 0.0;
					lp_zoho_handle( $method, $model, $fields, $args );
					$z['latency'] = $latency;
				}
				return new WP_Error( 'http_request_failed', sprintf( 'cURL error 28: Operation timed out after %d milliseconds with 0 bytes received', (int) ( 1000 * $timeout ) ) );
			}
		}
		if ( $z['inject'] ) {
			$answer = array_shift( $z['inject'] );
			if ( is_callable( $answer ) ) {
				$answer = $answer( $method, $model, $fields, $args );
			}
			if ( null !== $answer ) {
				return $answer;
			}
		}
		$module = $z['module'];
		if ( 'GET' === $method && $module . '/search' === $model ) {
			$email = strtolower( (string) ( $fields['email'] ?? '' ) );
			$hits  = array();
			foreach ( $z['records'] as $r ) {
				foreach ( $r as $key => $value ) { // Zoho's "email" search looks at every email field of the module.
					if ( ! $z['search_blind'] && '' !== $email && false !== stripos( $key, 'Email' ) && is_string( $value ) && strtolower( $value ) === $email ) {
						$hits[] = $r;
						break;
					}
				}
			}
			return $hits ? lp_zoho_json( 200, array( 'data' => array_values( $hits ), 'info' => array( 'per_page' => 200, 'count' => count( $hits ), 'page' => 1, 'more_records' => false ) ) ) : lp_zoho_json( 204, null );
		}
		if ( 'POST' === $method && $module === $model ) {
			$rec = $fields['data'][0] ?? array();
			if ( $z['unique_email'] ) {
				foreach ( $z['records'] as $r ) {
					if ( '' !== (string) ( $rec['Email'] ?? '' ) && strtolower( (string) ( $r['Email'] ?? '' ) ) === strtolower( (string) $rec['Email'] ) ) {
						return lp_zoho_json( 202, lp_zoho_item_error( 'DUPLICATE_DATA', 'duplicate data', array( 'api_name' => 'Email', 'id' => $r['id'] ) ) );
					}
				}
			}
			if ( empty( $rec['Name'] ) ) {
				return lp_zoho_json( 202, lp_zoho_item_error( 'MANDATORY_NOT_FOUND', 'required field not found', array( 'api_name' => 'Name' ) ) );
			}
			$id                  = (string) $z['next_id']++;
			$z['records'][ $id ] = array_merge( array( 'id' => $id ), $rec );
			$z['writes'][]       = array( 'op' => 'create', 'id' => $id, 'fields' => $rec, 'trigger' => $fields['trigger'] ?? 'not sent' );
			return lp_zoho_json( 201, lp_zoho_success( $id ) );
		}
		if ( 'PUT' === $method && $module === $model ) {
			$rec = $fields['data'][0] ?? array();
			$id  = (string) ( $rec['id'] ?? '' );
			if ( ! isset( $z['records'][ $id ] ) ) {
				return lp_zoho_json( 202, lp_zoho_item_error( 'INVALID_DATA', 'the id given seems to be invalid', array( 'api_name' => 'id' ) ) );
			}
			$z['records'][ $id ] = array_merge( $z['records'][ $id ], $rec );
			$z['writes'][]       = array( 'op' => 'update', 'id' => $id, 'fields' => $rec, 'trigger' => $fields['trigger'] ?? 'not sent' );
			return lp_zoho_json( 200, lp_zoho_success( $id, 'record updated' ) );
		}
		if ( 'POST' === $method && 'Notes' === $model ) {
			$note   = $fields['data'][0] ?? array();
			$parent = (string) ( $note['Parent_Id'] ?? '' );
			if ( ! isset( $z['records'][ $parent ] ) || ( $note['se_module'] ?? '' ) !== $module ) {
				return lp_zoho_json( 202, lp_zoho_item_error( 'INVALID_DATA', 'the related id given seems to be invalid', array( 'api_name' => 'Parent_Id' ) ) );
			}
			$id                = (string) $z['next_id']++;
			$z['notes'][ $id ] = $note;
			return lp_zoho_json( 201, lp_zoho_success( $id ) );
		}
		return lp_zoho_json( 400, lp_zoho_top_error( 'INVALID_URL_PATTERN', 'Please check if the URL trying to access is a correct one' ) );
	}

	/** Notes of a record in the fake Zoho. */
	function lp_zoho_notes_of( $record_id ) {
		return array_values( array_filter( $GLOBALS['lp_zoho']['notes'], function ( $n ) use ( $record_id ) { return (string) $record_id === (string) ( $n['Parent_Id'] ?? '' ); } ) );
	}
}
