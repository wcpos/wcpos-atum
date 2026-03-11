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
		add_filter( 'woocommerce_rest_prepare_product_object', array( $this, 'inject_atum_product_data' ), 20, 3 );
		add_filter( 'woocommerce_rest_prepare_product_variation_object', array( $this, 'inject_atum_product_data' ), 20, 3 );
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
	 * Inject ATUM inventory data into the product REST response.
	 *
	 * Runs at priority 20, after WCPOS Pro's per-store pricing (priority 10).
	 *
	 * @param \WP_REST_Response $response
	 * @param \WC_Product       $product
	 * @param \WP_REST_Request  $request
	 *
	 * @return \WP_REST_Response
	 */
	public function inject_atum_product_data( \WP_REST_Response $response, $product, \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! $this->is_atum_mi_supported() ) {
			return $response;
		}

		if ( ! $this->is_wcpos_route( $request ) ) {
			return $response;
		}

		$store_id = (int) $request->get_param( 'store_id' );
		if ( $store_id <= 0 ) {
			return $response;
		}

		$location_term_id = (int) get_post_meta( $store_id, self::STORE_LOCATION_META_KEY, true );
		if ( $location_term_id <= 0 ) {
			return $response;
		}

		$product_id = $product->get_id();
		$inventory  = $this->get_inventory_for_product_at_location( $product_id, $location_term_id );

		if ( null === $inventory ) {
			return $response;
		}

		$data = $response->get_data();

		// Always inject stock.
		$stock_qty              = isset( $inventory['stock_quantity'] ) ? (int) $inventory['stock_quantity'] : 0;
		$data['stock_quantity'] = $stock_qty;
		$data['stock_status']   = $stock_qty > 0 ? 'instock' : 'outofstock';

		// Inject price if pricing source is ATUM.
		$pricing_source = get_post_meta( $store_id, self::STORE_PRICING_SOURCE_KEY, true );
		if ( 'atum' === $pricing_source ) {
			$regular_price = isset( $inventory['_regular_price'] ) ? $inventory['_regular_price'] : '';
			$sale_price    = isset( $inventory['_sale_price'] ) ? $inventory['_sale_price'] : '';
			$price         = isset( $inventory['_price'] ) ? $inventory['_price'] : '';

			if ( '' !== $regular_price ) {
				$data['regular_price'] = $regular_price;
			}
			if ( '' !== $sale_price ) {
				$data['sale_price'] = $sale_price;
			}
			if ( '' !== $price ) {
				$data['price'] = $price;
			}

			$data['on_sale'] = '' !== $sale_price && (float) $sale_price < (float) $regular_price;
		}

		// Inject SKU if override enabled.
		$sku_override = get_post_meta( $store_id, self::STORE_SKU_OVERRIDE_KEY, true );
		if ( $sku_override && ! empty( $inventory['_sku'] ) ) {
			$data['sku'] = $inventory['_sku'];
		}

		$response->set_data( $data );

		return $response;
	}

	/**
	 * Find the ATUM inventory record for a product at a specific location.
	 *
	 * @param int $product_id       WooCommerce product ID.
	 * @param int $location_term_id Term ID of the atum_location taxonomy term.
	 *
	 * @return array|null Associative array of inventory meta, or null if not found.
	 */
	public function get_inventory_for_product_at_location( int $product_id, int $location_term_id ): ?array {
		global $wpdb;

		$inventory_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT i.id
			FROM {$wpdb->prefix}atum_inventories i
			INNER JOIN {$wpdb->prefix}atum_inventory_locations il ON i.id = il.inventory_id
			INNER JOIN {$wpdb->term_taxonomy} tt ON il.term_taxonomy_id = tt.term_taxonomy_id
			WHERE i.product_id = %d
			AND tt.term_id = %d
			AND tt.taxonomy = 'atum_location'
			LIMIT 1",
			$product_id,
			$location_term_id
		) );

		if ( $inventory_id <= 0 ) {
			return null;
		}

		$meta_rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT meta_key, meta_value
			FROM {$wpdb->prefix}atum_inventory_meta
			WHERE inventory_id = %d",
			$inventory_id
		) );

		if ( empty( $meta_rows ) ) {
			return null;
		}

		$meta = array( 'inventory_id' => $inventory_id );
		foreach ( $meta_rows as $row ) {
			$meta[ $row->meta_key ] = $row->meta_value;
		}

		return $meta;
	}

	/**
	 * Check if request is a WCPOS route.
	 *
	 * @param \WP_REST_Request $request
	 *
	 * @return bool
	 */
	private function is_wcpos_route( \WP_REST_Request $request ): bool {
		return 0 === strpos( $request->get_route(), '/wcpos/v1/' );
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
