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
		add_filter( 'woocommerce_pos_store_meta_fields', array( $this, 'extend_store_meta_fields' ) );
		add_filter( 'rest_post_dispatch', array( $this, 'inject_store_atum_fields' ), 20, 3 );
	}

	/**
	 * Load plugin translations.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'wcpos-atum', false, dirname( plugin_basename( dirname( __DIR__ ) . '/wcpos-atum.php' ) ) . '/languages' );
	}

	/**
	 * Extend WCPOS Pro store meta field mappings.
	 *
	 * @param array $fields
	 *
	 * @return array
	 */
	public function extend_store_meta_fields( array $fields ): array {
		if ( ! $this->is_atum_mi_supported() ) {
			return $fields;
		}

		$fields['atum_inventory_location'] = self::STORE_LOCATION_META_KEY;
		$fields['pricing_source']          = self::STORE_PRICING_SOURCE_KEY;
		$fields['atum_sku_override']       = self::STORE_SKU_OVERRIDE_KEY;

		return $fields;
	}

	/**
	 * Inject ATUM fields into WCPOS stores API responses.
	 *
	 * @param mixed            $result
	 * @param \WP_REST_Server  $server
	 * @param \WP_REST_Request $request
	 *
	 * @return mixed
	 */
	public function inject_store_atum_fields( $result, $server, \WP_REST_Request $request ) {
		if ( ! $this->is_atum_mi_supported() ) {
			return $result;
		}

		if ( ! ( $result instanceof \WP_REST_Response ) ) {
			return $result;
		}

		$route = $request->get_route();
		if ( 0 !== strpos( $route, '/wcpos/v1/stores' ) ) {
			return $result;
		}

		$data = $result->get_data();

		if ( is_array( $data ) && $this->is_list_array( $data ) ) {
			foreach ( $data as $index => $item ) {
				if ( is_array( $item ) && isset( $item['id'] ) ) {
					$data[ $index ] = $this->add_atum_fields_to_store( $item );
				}
			}
		} elseif ( is_array( $data ) && isset( $data['id'] ) ) {
			$data = $this->add_atum_fields_to_store( $data );
		}

		$result->set_data( $data );
		return $result;
	}

	/**
	 * Add ATUM fields to a single store data array.
	 *
	 * @param array $store_data
	 *
	 * @return array
	 */
	private function add_atum_fields_to_store( array $store_data ): array {
		$store_id = (int) $store_data['id'];

		$store_data['atum_inventory_location'] = (int) get_post_meta( $store_id, self::STORE_LOCATION_META_KEY, true );
		$store_data['pricing_source']          = get_post_meta( $store_id, self::STORE_PRICING_SOURCE_KEY, true ) ?: 'default';
		$store_data['atum_sku_override']       = (string) get_post_meta( $store_id, self::STORE_SKU_OVERRIDE_KEY, true );

		return $store_data;
	}

	/**
	 * @param mixed $items
	 *
	 * @return bool
	 */
	private function is_list_array( $items ): bool {
		if ( ! is_array( $items ) ) {
			return false;
		}

		$expected_key = 0;
		foreach ( $items as $key => $unused ) {
			if ( $key !== $expected_key ) {
				return false;
			}
			++$expected_key;
		}

		return true;
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
