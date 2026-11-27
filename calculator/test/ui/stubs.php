<?php
/**
 * Minimal WordPress stubs for rendering the calculator template from PHP CLI.
 * Each one is defined only if missing, so the real plugin's test stubs can be loaded first.
 */

error_reporting( E_ALL );
ini_set( 'display_errors', 'stderr' );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $s ) {
		return htmlspecialchars( (string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $s ) {
		return htmlspecialchars( (string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}
if ( ! function_exists( '__' ) ) {
	function __( $s, $domain = 'default' ) {
		return $s;
	}
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $s, $domain = 'default' ) {
		return esc_html( $s );
	}
}
if ( ! function_exists( 'esc_attr__' ) ) {
	function esc_attr__( $s, $domain = 'default' ) {
		return esc_attr( $s );
	}
}
if ( ! function_exists( 'esc_html_e' ) ) {
	function esc_html_e( $s, $domain = 'default' ) {
		echo esc_html( $s );
	}
}
if ( ! function_exists( 'esc_attr_e' ) ) {
	function esc_attr_e( $s, $domain = 'default' ) {
		echo esc_attr( $s );
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options | JSON_UNESCAPED_UNICODE, $depth );
	}
}
if ( ! function_exists( 'wp_unique_id' ) ) {
	function wp_unique_id( $prefix = '' ) {
		static $n = 0;
		return $prefix . (string) ++$n;
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value ) {
		return $value;
	}
}
