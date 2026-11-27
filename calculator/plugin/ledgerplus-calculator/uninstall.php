<?php
/**
 * Uninstall: deletes the rate-limit transients (lp_calc_%). The plugin stores nothing else.
 *
 * With a persistent object cache, transients live in the cache instead of the options table
 * and expire on their own within 24 hours.
 *
 * @package LedgerPlusCalculator
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$lp_calc_like = array(
	$wpdb->esc_like( '_transient_lp_calc_' ) . '%',
	$wpdb->esc_like( '_transient_timeout_lp_calc_' ) . '%',
);

$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$lp_calc_like[0],
		$lp_calc_like[1]
	)
);

if ( is_multisite() ) {
	$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			"DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s OR meta_key LIKE %s",
			$wpdb->esc_like( '_site_transient_lp_calc_' ) . '%',
			$wpdb->esc_like( '_site_transient_timeout_lp_calc_' ) . '%'
		)
	);
}

unset( $lp_calc_like );
