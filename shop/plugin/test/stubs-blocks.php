<?php
/**
 * Stub of the WooCommerce Blocks container + CheckoutFields getter
 * (real: src/Blocks/Package.php:83, src/Blocks/Domain/Services/CheckoutFieldsStorage.php:96).
 */

namespace Automattic\WooCommerce\Blocks\Domain\Services {

	class CheckoutFields {
		public function get_field_from_object( string $key, \WC_Data $wc_object, string $group = 'other', bool $apply_defaults = true ) {
			$GLOBALS['lp_api_calls'][] = array( $key, $wc_object->get_id(), $group );
			if ( ! empty( $GLOBALS['lp_api_throw'] ) ) {
				throw new \RuntimeException( 'boom' );
			}
			return $wc_object->get_meta( '_wc_other/' . $key, true );
		}
	}
}

namespace Automattic\WooCommerce\Blocks {

	class LP_Test_Container {
		public function get( $id ) {
			return new Domain\Services\CheckoutFields();
		}
	}

	class Package {
		public static function container( $reset = false ) {
			return new LP_Test_Container();
		}
	}
}
