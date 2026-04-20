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
		add_filter( 'rest_request_before_callbacks', array( $this, 'inject_pos_order_item_inventories' ), 10, 3 );
		add_filter( 'rest_post_dispatch', array( $this, 'inject_store_atum_fields' ), 20, 3 );
		add_filter( 'woocommerce_rest_prepare_product_object', array( $this, 'inject_atum_product_data' ), 20, 3 );
		add_filter( 'woocommerce_rest_prepare_product_variation_object', array( $this, 'inject_atum_product_data' ), 20, 3 );
		add_filter( 'atum/multi_inventory/order_item_inventories', array( $this, 'scope_order_item_inventories_to_store_location' ), 20, 2 );
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
	 * Inject explicit ATUM inventory allocations into WCPOS REST order payloads.
	 *
	 * ATUM's REST order saver expects line_items[].mi_inventories during order creation,
	 * otherwise it falls back to the main inventory even when a POS store is mapped to a
	 * specific ATUM location.
	 *
	 * @param mixed            $response The current REST pre-callback response.
	 * @param array            $handler  The matched route handler.
	 * @param \WP_REST_Request $request  The current REST request.
	 *
	 * @return mixed
	 */
	public function inject_pos_order_item_inventories( $response, array $handler, \WP_REST_Request $request ) {
		unset( $handler );

		if ( ! $this->is_atum_mi_supported() ) {
			return $response;
		}

		if ( ! $this->is_wcpos_order_write_request( $request ) ) {
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

		$line_items = $request->get_param( 'line_items' );
		if ( ! is_array( $line_items ) ) {
			return $response;
		}

		$changed = false;

		foreach ( $line_items as $index => $line_item ) {
			if ( ! is_array( $line_item ) || ! empty( $line_item['mi_inventories'] ) ) {
				continue;
			}

			$product_id = absint( ! empty( $line_item['variation_id'] ) ? $line_item['variation_id'] : ( $line_item['product_id'] ?? 0 ) );
			if ( $product_id <= 0 ) {
				continue;
			}

			$quantity = $line_item['quantity'] ?? null;
			if ( ! is_numeric( $quantity ) || (float) $quantity <= 0 ) {
				continue;
			}

			$inventory = $this->get_inventory_for_product_at_location( $product_id, $location_term_id );
			if ( null === $inventory || empty( $inventory['inventory_id'] ) ) {
				continue;
			}

			$line_items[ $index ]['mi_inventories'] = array(
				array(
					'inventory_id' => (int) $inventory['inventory_id'],
					'product_id'   => $product_id,
					'qty'          => 0 + $quantity,
				),
			);
			$changed                            = true;
		}

		if ( $changed ) {
			$request->set_param( 'line_items', $line_items );
		}

		return $response;
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
			$regular_price = isset( $inventory['regular_price'] ) ? $inventory['regular_price'] : ( $inventory['_regular_price'] ?? '' );
			$sale_price    = isset( $inventory['sale_price'] ) ? $inventory['sale_price'] : ( $inventory['_sale_price'] ?? '' );
			$price         = isset( $inventory['price'] ) ? $inventory['price'] : ( $inventory['_price'] ?? '' );

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
		$inventory_sku = isset( $inventory['sku'] ) ? $inventory['sku'] : ( $inventory['_sku'] ?? '' );
		if ( $sku_override && '' !== $inventory_sku ) {
			$data['sku'] = $inventory_sku;
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

		$meta = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT *
			FROM {$wpdb->prefix}atum_inventory_meta
			WHERE inventory_id = %d
			LIMIT 1",
				$inventory_id
			),
			ARRAY_A
		);

		if ( empty( $meta ) ) {
			return null;
		}

		$meta['inventory_id'] = $inventory_id;
		if ( isset( $meta['stock_quantity'] ) ) {
			$meta['stock_quantity'] = $this->normalize_inventory_number( $meta['stock_quantity'] );
		}
		$meta['_sku']           = $meta['sku'] ?? '';
		$meta['_regular_price'] = $meta['regular_price'] ?? '';
		$meta['_sale_price']    = $meta['sale_price'] ?? '';
		$meta['_price']         = $meta['price'] ?? '';

		return $meta;
	}

	/**
	 * Restrict ATUM's native order item inventory selection to the store's mapped location.
	 *
	 * @param array $inventories Candidate ATUM inventories for the order item.
	 * @param mixed $item        WooCommerce order item being prepared by ATUM.
	 *
	 * @return array
	 */
	public function scope_order_item_inventories_to_store_location( array $inventories, $item ): array {
		$location_term_id = $this->get_order_item_store_location( $item );
		if ( $location_term_id <= 0 ) {
			return $inventories;
		}

		$filtered = array_values(
			array_filter(
				$inventories,
				function ( $inventory ) use ( $location_term_id ): bool {
					return $this->inventory_matches_location( $inventory, $location_term_id );
				}
			)
		);

		return $filtered;
	}

	/**
	 * Get the mapped ATUM location term ID for an order item's POS store.
	 *
	 * @param mixed $item WooCommerce order item.
	 *
	 * @return int
	 */
	private function get_order_item_store_location( $item ): int {
		if ( ! is_object( $item ) || ! is_callable( array( $item, 'get_order_id' ) ) ) {
			return 0;
		}

		$order = is_callable( array( $item, 'get_order' ) ) ? $item->get_order() : null;
		if ( ! $order && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( (int) $item->get_order_id() );
		}

		if ( ! $order || ! is_callable( array( $order, 'get_meta' ) ) ) {
			return 0;
		}

		$store_id = (int) $order->get_meta( '_pos_store' );
		if ( $store_id <= 0 ) {
			return 0;
		}

		return (int) get_post_meta( $store_id, self::STORE_LOCATION_META_KEY, true );
	}

	/**
	 * Check whether an ATUM inventory is linked to a specific location term.
	 *
	 * @param mixed $inventory        ATUM inventory object/array.
	 * @param int   $location_term_id ATUM location term ID.
	 *
	 * @return bool
	 */
	private function inventory_matches_location( $inventory, int $location_term_id ): bool {
		$locations = array();

		if ( is_object( $inventory ) && is_callable( array( $inventory, 'get_locations' ) ) ) {
			$locations = $inventory->get_locations();
		} elseif ( is_object( $inventory ) && isset( $inventory->location ) ) {
			$locations = $inventory->location;
		} elseif ( is_array( $inventory ) && isset( $inventory['location'] ) ) {
			$locations = $inventory['location'];
		}

		$locations = array_map( 'intval', (array) $locations );

		return in_array( $location_term_id, $locations, true );
	}

	/**
	 * Normalize database numeric strings like 25.0000 to 25.
	 *
	 * @param mixed $value Numeric database value.
	 *
	 * @return mixed
	 */
	private function normalize_inventory_number( $value ) {
		if ( ! is_scalar( $value ) ) {
			return $value;
		}

		$value = (string) $value;
		if ( false === strpos( $value, '.' ) ) {
			return $value;
		}

		$normalized = rtrim( rtrim( $value, '0' ), '.' );

		return '' !== $normalized ? $normalized : '0';
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

		if ( ! in_array( $hook_suffix, array( 'admin_page_wcpos-store-edit', 'pos_page_wcpos-store-edit' ), true ) ) {
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
	 * Check if a request is creating/updating a POS order through wcpos/v1.
	 *
	 * @param \WP_REST_Request $request The current REST request.
	 *
	 * @return bool
	 */
	private function is_wcpos_order_write_request( \WP_REST_Request $request ): bool {
		if ( 0 !== strpos( $request->get_route(), '/wcpos/v1/orders' ) ) {
			return false;
		}

		return in_array( $request->get_method(), array( 'POST', 'PUT', 'PATCH' ), true );
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
