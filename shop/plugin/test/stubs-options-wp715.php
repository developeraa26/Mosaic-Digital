<?php
/**
 * Option functions modelled on WordPress 7.1.5 wp-includes/option.php (cached copy:
 * scratchpad/wcsrc/wp/option-7.1.5.php), for the concurrency test (run-race.php).
 * Based on the model written by the independent reviewer (review-plugin-1/adv-options.php).
 *
 *  - get_option(): a miss is remembered in the per-request 'notoptions' cache (option.php:213).
 *  - add_option(): checks existence only when 'notoptions' does NOT list the key (option.php:1116-1125),
 *    then INSERT ... ON DUPLICATE KEY UPDATE (option.php:1142) and returns false when affected rows
 *    are 0. MySQL affected rows: 1 inserted, 2 existing row changed, 0 existing row already identical.
 *    On success the key leaves 'notoptions' (option.php:1157-1163).
 *  - update_option(): returns false for an unchanged value (option.php:923) and adds when get_option() gives the default (option.php:928-929), else UPDATE.
 *  - delete_option(): deletes the row and sets notoptions[$option] = true (option.php:1248).
 *  - wp_cache_delete(): this model keeps no value cache, so it has nothing to drop (it never
 *    touches 'notoptions', like WordPress).
 * Fibers let the test pause a "request" at named points (adv_point).
 */

$GLOBALS['adv'] = array(
	'db'      => array(),
	'req'     => array(),
	'cur'     => 'main',
	'suspend' => array(),
	'trace'   => array(),
);

function adv_req() {
	$cur = $GLOBALS['adv']['cur'];
	if ( ! isset( $GLOBALS['adv']['req'][ $cur ] ) ) {
		$GLOBALS['adv']['req'][ $cur ] = array( 'notoptions' => array() );
	}
	return $cur;
}

function adv_point( $name ) {
	$me                        = $GLOBALS['adv']['cur'];
	$GLOBALS['adv']['trace'][] = "$me:$name";
	$hit = ! empty( $GLOBALS['adv']['suspend'][ $name ] ) || ! empty( $GLOBALS['adv']['suspend'][ "$me:$name" ] );
	if ( $hit && Fiber::getCurrent() ) {
		Fiber::suspend( $name );
		$GLOBALS['adv']['cur'] = $me;
	}
}

function get_option( $k, $default = false ) {
	$r = adv_req();
	if ( isset( $GLOBALS['adv']['req'][ $r ]['notoptions'][ $k ] ) ) {
		return $default;
	}
	if ( array_key_exists( $k, $GLOBALS['adv']['db'] ) ) {
		return $GLOBALS['adv']['db'][ $k ];
	}
	$GLOBALS['adv']['req'][ $r ]['notoptions'][ $k ] = true;
	return $default;
}

function add_option( $k, $v = '', $dep = '', $autoload = null ) {
	$r = adv_req();
	if ( ! isset( $GLOBALS['adv']['req'][ $r ]['notoptions'][ $k ] ) ) {
		if ( false !== get_option( $k ) ) {
			adv_point( 'add_option:exists' ); // A request waiting for a lock can be paused here (F14 race scenario).
			return false;
		}
	}
	adv_point( 'add_option:pre_insert' );
	$v = (string) $v;
	if ( ! array_key_exists( $k, $GLOBALS['adv']['db'] ) ) {
		$GLOBALS['adv']['db'][ $k ] = $v;
		$affected                   = 1;
	} elseif ( $GLOBALS['adv']['db'][ $k ] === $v ) {
		$affected = 0;
	} else {
		$GLOBALS['adv']['db'][ $k ] = $v;
		$affected                   = 2;
	}
	$GLOBALS['adv']['trace'][] = $GLOBALS['adv']['cur'] . ":INSERT $k affected=$affected";
	if ( ! $affected ) {
		return false;
	}
	unset( $GLOBALS['adv']['req'][ $r ]['notoptions'][ $k ] );
	return true;
}

function update_option( $k, $v, $autoload = null ) {
	$old = get_option( $k );
	if ( false === $old ) {
		return add_option( $k, $v, '', $autoload );
	}
	if ( (string) $old === (string) $v ) {
		return false;
	}
	$GLOBALS['adv']['db'][ $k ] = (string) $v;
	return true;
}

function delete_option( $k ) {
	adv_point( 'delete_option:pre_delete' );
	$r       = adv_req();
	$existed = array_key_exists( $k, $GLOBALS['adv']['db'] );
	unset( $GLOBALS['adv']['db'][ $k ] );
	$GLOBALS['adv']['req'][ $r ]['notoptions'][ $k ] = true;
	return $existed;
}

function wp_cache_delete( $key, $group = '' ) {
	return true;
}
