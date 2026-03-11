<?php
/**
 * Main plugin class.
 *
 * @package WCPOS\ATUM
 */

namespace WCPOS\ATUM;

use WCPOS\WooCommercePOSPro\Services\Stores as WCPOS_Pro_Stores;

class Plugin {
	public const STORE_LOCATION_META_KEY  = '_wcpos_atum_inventory_location';
	public const STORE_PRICING_SOURCE_KEY = '_wcpos_pricing_source';
	public const STORE_SKU_OVERRIDE_KEY   = '_wcpos_atum_sku_override';

	/**
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		add_action( 'init', array( $this, 'load_textdomain' ) );
	}

	/**
	 * Load plugin translations.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'wcpos-atum', false, dirname( plugin_basename( dirname( __DIR__ ) . '/wcpos-atum.php' ) ) . '/languages' );
	}

	/**
	 * Check whether ATUM Multi-Inventory is active and supported.
	 *
	 * @return bool
	 */
	public function is_atum_mi_supported(): bool {
		$supported = class_exists( 'AtumMultiInventory\Models\Inventory' )
			&& class_exists( 'AtumMultiInventory\Inc\Helpers' );

		return (bool) apply_filters( 'wcpos_atum_is_supported', $supported );
	}
}
