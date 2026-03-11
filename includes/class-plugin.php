<?php
/**
 * Main plugin class.
 *
 * @package WCPOS\ATUM
 */

namespace WCPOS\ATUM;

use WCPOS\WooCommercePOSPro\Services\Stores as WCPOS_Pro_Stores;

/**
 * Main plugin class handling ATUM Multi-Inventory integration with WCPOS.
 */
class Plugin {
	public const STORE_LOCATION_META_KEY  = '_wcpos_atum_inventory_location';
	public const STORE_PRICING_SOURCE_KEY = '_wcpos_pricing_source';
	public const STORE_SKU_OVERRIDE_KEY   = '_wcpos_atum_sku_override';

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton instance.
	 *
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
		add_filter( 'atum/multi_inventory/can_reduce_order_stock', array( $this, 'maybe_block_atum_reduction' ), 10, 2 );
		add_filter( 'atum/multi_inventory/can_restore_order_stock', array( $this, 'maybe_block_atum_reduction' ), 10, 2 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'maybe_reduce_pos_order_stock' ), 100, 4 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'maybe_restore_pos_order_stock' ), 100, 4 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_store_edit_assets' ), 20 );
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
	 * @param array $fields Existing store meta field mappings.
	 *
	 * @return array Modified field mappings.
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
	 * @param mixed            $result  The REST response.
	 * @param \WP_REST_Server  $server  The REST server instance.
	 * @param \WP_REST_Request $request The current request.
	 *
	 * @return mixed Modified response.
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
	 * @param array $store_data Store data array from the REST response.
	 *
	 * @return array Store data with ATUM fields added.
	 */
	private function add_atum_fields_to_store( array $store_data ): array {
		$store_id = (int) $store_data['id'];

		$store_data['atum_inventory_location'] = (int) get_post_meta( $store_id, self::STORE_LOCATION_META_KEY, true );
		$pricing_source                        = get_post_meta( $store_id, self::STORE_PRICING_SOURCE_KEY, true );
		$store_data['pricing_source']          = $pricing_source ? $pricing_source : 'default';
		$store_data['atum_sku_override']       = (string) get_post_meta( $store_id, self::STORE_SKU_OVERRIDE_KEY, true );

		return $store_data;
	}

	/**
	 * Check whether an array is a sequential list (0-indexed).
	 *
	 * @param mixed $items Value to check.
	 *
	 * @return bool True if the array is a sequential list.
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
	 * @param \WP_REST_Response $response The product REST response.
	 * @param \WC_Product       $product  The product object.
	 * @param \WP_REST_Request  $request  The current request.
	 *
	 * @return \WP_REST_Response Modified response with ATUM data.
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

		$inventory_id = (int) $wpdb->get_var(
			$wpdb->prepare(
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
			)
		);

		if ( $inventory_id <= 0 ) {
			return null;
		}

		$meta_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_key, meta_value
			FROM {$wpdb->prefix}atum_inventory_meta
			WHERE inventory_id = %d",
				$inventory_id
			)
		);

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
	 * Prevent ATUM's default allocation for POS orders that have a store with an ATUM location.
	 *
	 * @param bool  $can_reduce Whether ATUM can reduce stock.
	 * @param mixed $order      The WooCommerce order.
	 *
	 * @return bool False if the POS store has a mapped ATUM location.
	 */
	public function maybe_block_atum_reduction( bool $can_reduce, $order ): bool {
		if ( ! $can_reduce ) {
			return $can_reduce;
		}

		if ( ! is_callable( array( $order, 'get_meta' ) ) ) {
			return $can_reduce;
		}

		$store_id = (int) $order->get_meta( '_pos_store' );
		if ( $store_id <= 0 ) {
			return $can_reduce;
		}

		$location_term_id = (int) get_post_meta( $store_id, self::STORE_LOCATION_META_KEY, true );
		if ( $location_term_id <= 0 ) {
			return $can_reduce;
		}

		return false;
	}

	/**
	 * Reduce stock at the store's ATUM inventory location for POS orders.
	 *
	 * @param int    $order_id   The order ID.
	 * @param string $old_status Previous order status.
	 * @param string $new_status New order status.
	 * @param mixed  $order      The WooCommerce order.
	 */
	public function maybe_reduce_pos_order_stock( int $order_id, string $old_status, string $new_status, $order ): void {
		if ( ! $this->is_atum_mi_supported() ) {
			return;
		}

		$reduce_statuses = array( 'processing', 'completed' );
		if ( ! in_array( $new_status, $reduce_statuses, true ) ) {
			return;
		}

		if ( $order->get_meta( '_wcpos_atum_stock_reduced' ) ) {
			return;
		}

		$store_id = (int) $order->get_meta( '_pos_store' );
		if ( $store_id <= 0 ) {
			return;
		}

		$location_term_id = (int) get_post_meta( $store_id, self::STORE_LOCATION_META_KEY, true );
		if ( $location_term_id <= 0 ) {
			return;
		}

		$this->reduce_order_stock_at_location( $order, $location_term_id );

		$order->update_meta_data( '_wcpos_atum_stock_reduced', 'yes' );
		$order->save();
	}

	/**
	 * Restore stock to the originating ATUM inventory location on refund/cancel.
	 *
	 * @param int    $order_id   The order ID.
	 * @param string $old_status Previous order status.
	 * @param string $new_status New order status.
	 * @param mixed  $order      The WooCommerce order.
	 */
	public function maybe_restore_pos_order_stock( int $order_id, string $old_status, string $new_status, $order ): void {
		if ( ! $this->is_atum_mi_supported() ) {
			return;
		}

		$restore_statuses = array( 'refunded', 'cancelled' );
		if ( ! in_array( $new_status, $restore_statuses, true ) ) {
			return;
		}

		if ( ! $order->get_meta( '_wcpos_atum_stock_reduced' ) ) {
			return;
		}

		if ( $order->get_meta( '_wcpos_atum_stock_restored' ) ) {
			return;
		}

		$store_id = (int) $order->get_meta( '_pos_store' );
		if ( $store_id <= 0 ) {
			return;
		}

		$location_term_id = (int) get_post_meta( $store_id, self::STORE_LOCATION_META_KEY, true );
		if ( $location_term_id <= 0 ) {
			return;
		}

		$this->restore_order_stock_from_atum_records( $order );

		$order->update_meta_data( '_wcpos_atum_stock_restored', 'yes' );
		$order->save();
	}

	/**
	 * Reduce stock for each order line item at the specified ATUM location.
	 *
	 * @param mixed $order            The WooCommerce order.
	 * @param int   $location_term_id ATUM location term ID.
	 */
	private function reduce_order_stock_at_location( $order, int $location_term_id ): void {
		global $wpdb;

		foreach ( $order->get_items() as $item ) {
			$variation_id = $item->get_variation_id();
			$product_id   = $variation_id ? $variation_id : $item->get_product_id();
			$qty        = $item->get_quantity();

			if ( $qty <= 0 ) {
				continue;
			}

			$inventory = $this->get_inventory_for_product_at_location( $product_id, $location_term_id );
			if ( null === $inventory || ! isset( $inventory['inventory_id'] ) ) {
				continue;
			}

			$inventory_id  = (int) $inventory['inventory_id'];
			$current_stock = isset( $inventory['stock_quantity'] ) ? (float) $inventory['stock_quantity'] : 0;
			$new_stock     = $current_stock - $qty;

			$wpdb->update(
				"{$wpdb->prefix}atum_inventory_meta",
				array( 'meta_value' => (string) $new_stock ),
				array(
					'inventory_id' => $inventory_id,
					'meta_key'     => 'stock_quantity',
				)
			);

			$wpdb->insert(
				"{$wpdb->prefix}atum_inventory_orders",
				array(
					'order_item_id' => $item->get_id(),
					'inventory_id'  => $inventory_id,
					'product_id'    => $product_id,
					'order_type'    => 1,
					'qty'           => $qty,
					'subtotal'      => $item->get_subtotal(),
					'total'         => $item->get_total(),
				)
			);
		}
	}

	/**
	 * Restore stock using atum_inventory_orders records to find the originating inventory.
	 *
	 * @param mixed $order The WooCommerce order.
	 */
	private function restore_order_stock_from_atum_records( $order ): void {
		global $wpdb;

		foreach ( $order->get_items() as $item ) {
			$order_item_id = $item->get_id();

			$records = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT inventory_id, qty FROM {$wpdb->prefix}atum_inventory_orders
				WHERE order_item_id = %d AND order_type = 1",
					$order_item_id
				)
			);

			if ( empty( $records ) ) {
				continue;
			}

			foreach ( $records as $record ) {
				$inventory_id = (int) $record->inventory_id;
				$qty          = (float) $record->qty;

				$current_stock = (float) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT meta_value FROM {$wpdb->prefix}atum_inventory_meta
					WHERE inventory_id = %d AND meta_key = 'stock_quantity'",
						$inventory_id
					)
				);

				$new_stock = $current_stock + $qty;

				$wpdb->update(
					"{$wpdb->prefix}atum_inventory_meta",
					array( 'meta_value' => (string) $new_stock ),
					array(
						'inventory_id' => $inventory_id,
						'meta_key'     => 'stock_quantity',
					)
				);
			}
		}
	}

	/**
	 * Enqueue WCPOS Pro store edit extension script.
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 */
	public function enqueue_store_edit_assets( string $hook_suffix ): void {
		if ( ! $this->is_atum_mi_supported() ) {
			return;
		}

		if ( 'admin_page_wcpos-store-edit' !== $hook_suffix ) {
			return;
		}

		if ( ! class_exists( WCPOS_Pro_Stores::class ) ) {
			return;
		}

		$pro_store_edit_handle = 'woocommerce-pos-pro-store-edit';
		if ( ! wp_script_is( $pro_store_edit_handle, 'enqueued' ) ) {
			return;
		}

		$locations = $this->get_atum_locations_for_js();

		wp_enqueue_script(
			'wcpos-atum-store-edit',
			plugins_url( 'assets/js/store-atum-section.js', dirname( __DIR__ ) . '/wcpos-atum.php' ),
			array( $pro_store_edit_handle, 'wp-element' ),
			VERSION,
			true
		);

		wp_add_inline_script(
			'wcpos-atum-store-edit',
			'window.wcposAtumStoreEdit = ' . wp_json_encode(
				array(
					'locations' => $locations,
					'strings'   => array(
						'sectionLabel'        => __( 'ATUM Inventory', 'wcpos-atum' ),
						'locationTitle'       => __( 'Inventory location', 'wcpos-atum' ),
						'locationDescription' => __( 'Link this store to an ATUM inventory location.', 'wcpos-atum' ),
						'locationDefault'     => __( 'No location (use default stock)', 'wcpos-atum' ),
						'noLocations'         => __( 'No ATUM locations found. Create locations in ATUM settings.', 'wcpos-atum' ),
						'pricingTitle'        => __( 'Pricing source', 'wcpos-atum' ),
						'pricingDescription'  => __( 'Choose which pricing system this store uses.', 'wcpos-atum' ),
						'pricingDefault'      => __( 'Default (WooCommerce prices)', 'wcpos-atum' ),
						'pricingPro'          => __( 'WCPOS Pro (per-store pricing)', 'wcpos-atum' ),
						'pricingAtum'         => __( 'ATUM (per-inventory pricing)', 'wcpos-atum' ),
						'skuTitle'            => __( 'SKU override', 'wcpos-atum' ),
						'skuLabel'            => __( 'Use location-specific SKUs from ATUM', 'wcpos-atum' ),
					),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * Get ATUM locations for the store editor dropdown.
	 *
	 * @return array
	 */
	private function get_atum_locations_for_js(): array {
		$terms = get_terms(
			array(
				'taxonomy'   => 'atum_location',
				'hide_empty' => false,
			)
		);

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return array();
		}

		$options = array();
		foreach ( $terms as $term ) {
			$options[] = array(
				'value' => $term->term_id,
				'label' => $term->name,
			);
		}

		return $options;
	}

	/**
	 * Check if request is a WCPOS route.
	 *
	 * @param \WP_REST_Request $request The current REST request.
	 *
	 * @return bool True if this is a WCPOS API route.
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
