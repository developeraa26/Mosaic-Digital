<?php
/**
 * F14 test fixtures for run-f14.php mode "order-cache": the WooCommerce classes LP_Shop::reload_order() uses with
 * HPOS (WooCommerce 11.3.1): OrderCache::remove() (src/Caches/OrderCache.php), OrderUtil::
 * custom_orders_table_usage_is_enabled() and wc_get_container(). The cache itself is modelled in stubs-wc.php
 * wc_get_order() while $GLOBALS['lp_test']['order_cache'] is on (a per-request copy, cleared by saves).
 * Load before the plugin, in a process of its own.
 */

namespace Automattic\WooCommerce\Caches {
	class OrderCache {
		public function remove( $id ) {
			unset( $GLOBALS['lp_order_cache'][ (int) $id ] );
			return true;
		}
	}
}

namespace Automattic\WooCommerce\Utilities {
	class OrderUtil {
		public static function custom_orders_table_usage_is_enabled() {
			return true;
		}
	}
}

namespace {
	if ( ! function_exists( 'wc_get_container' ) ) {
		function wc_get_container() {
			return new class() {
				public function get( $id ) {
					return new $id();
				}
			};
		}
	}
}
