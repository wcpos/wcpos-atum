<?php

class Test_WCPOS_ATUM extends WP_UnitTestCase {
	public function setUp(): void {
		parent::setUp();

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$user    = get_user_by( 'id', $user_id );
		$user->add_cap( 'access_woocommerce_pos' );
		wp_set_current_user( $user_id );
	}

	public function tearDown(): void {
		remove_all_filters( 'wcpos_atum_is_supported' );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	public function test_is_atum_mi_supported_returns_false_without_atum(): void {
		$plugin = \WCPOS\ATUM\Plugin::instance();
		$this->assertFalse( $plugin->is_atum_mi_supported() );
	}

	public function test_is_atum_mi_supported_can_be_overridden_by_filter(): void {
		add_filter( 'wcpos_atum_is_supported', '__return_true' );
		$plugin = \WCPOS\ATUM\Plugin::instance();
		$this->assertTrue( $plugin->is_atum_mi_supported() );
	}

	public function test_store_meta_fields_include_atum_fields(): void {
		add_filter( 'wcpos_atum_is_supported', '__return_true' );

		$fields = apply_filters( 'woocommerce_pos_store_meta_fields', array() );
		$this->assertSame( '_wcpos_atum_inventory_location', $fields['atum_inventory_location'] );
		$this->assertSame( '_wcpos_pricing_source', $fields['pricing_source'] );
		$this->assertSame( '_wcpos_atum_sku_override', $fields['atum_sku_override'] );
	}

	public function test_store_meta_fields_not_added_without_atum(): void {
		add_filter( 'wcpos_atum_is_supported', '__return_false' );

		$fields = apply_filters( 'woocommerce_pos_store_meta_fields', array() );
		$this->assertArrayNotHasKey( 'atum_inventory_location', $fields );
	}

	public function test_store_response_includes_atum_fields_for_single_store(): void {
		add_filter( 'wcpos_atum_is_supported', '__return_true' );

		$store_id = wp_insert_post( array(
			'post_type'   => 'post',
			'post_status' => 'publish',
			'post_title'  => 'Test Store',
		) );
		$this->assertGreaterThan( 0, $store_id );
		update_post_meta( $store_id, '_wcpos_atum_inventory_location', 42 );
		update_post_meta( $store_id, '_wcpos_pricing_source', 'atum' );
		update_post_meta( $store_id, '_wcpos_atum_sku_override', '1' );

		$request  = new WP_REST_Request( 'GET', '/wcpos/v1/stores/' . $store_id );
		$response = new WP_REST_Response( array( 'id' => $store_id ) );

		$result = apply_filters( 'rest_post_dispatch', $response, rest_get_server(), $request );
		$data   = $result->get_data();

		$this->assertSame( 42, $data['atum_inventory_location'] );
		$this->assertSame( 'atum', $data['pricing_source'] );
		$this->assertSame( '1', $data['atum_sku_override'] );
	}

	// ---- Inventory Lookup Tests ----

	public function test_get_inventory_for_product_at_location_returns_data(): void {
		add_filter( 'wcpos_atum_is_supported', '__return_true' );
		$this->create_atum_tables();

		if ( ! taxonomy_exists( 'atum_location' ) ) {
			register_taxonomy( 'atum_location', 'product', array( 'hierarchical' => true ) );
		}

		$product_id = wp_insert_post( array(
			'post_type'   => 'product',
			'post_status' => 'publish',
			'post_title'  => 'Test Product',
		) );

		$term = wp_insert_term( 'Store A Location', 'atum_location' );
		$location_term_id = $term['term_id'];

		$this->create_test_inventory( $product_id, $location_term_id, array(
			'stock_quantity' => '25',
			'_sku'           => 'LOC-A-001',
			'_regular_price' => '19.99',
			'_sale_price'    => '14.99',
			'_price'         => '14.99',
		) );

		$plugin = \WCPOS\ATUM\Plugin::instance();
		$result = $plugin->get_inventory_for_product_at_location( $product_id, $location_term_id );

		$this->assertIsArray( $result );
		$this->assertSame( '25', $result['stock_quantity'] );
		$this->assertSame( 'LOC-A-001', $result['_sku'] );
		$this->assertSame( '19.99', $result['_regular_price'] );
		$this->assertSame( '14.99', $result['_sale_price'] );
		$this->assertSame( '14.99', $result['_price'] );
	}

	public function test_get_inventory_for_product_at_location_returns_null_when_not_found(): void {
		add_filter( 'wcpos_atum_is_supported', '__return_true' );
		$this->create_atum_tables();

		$plugin = \WCPOS\ATUM\Plugin::instance();
		$result = $plugin->get_inventory_for_product_at_location( 9999, 9999 );

		$this->assertNull( $result );
	}

	// ---- Product Response Injection Tests ----

	public function test_product_response_injects_stock_from_atum_location(): void {
		add_filter( 'wcpos_atum_is_supported', '__return_true' );
		$this->create_atum_tables();

		if ( ! taxonomy_exists( 'atum_location' ) ) {
			register_taxonomy( 'atum_location', 'product', array( 'hierarchical' => true ) );
		}

		$store_id = wp_insert_post( array(
			'post_type'   => 'wcpos_store',
			'post_status' => 'publish',
			'post_title'  => 'POS Store',
		) );
		$term = wp_insert_term( 'Store Location', 'atum_location' );
		update_post_meta( $store_id, '_wcpos_atum_inventory_location', $term['term_id'] );
		update_post_meta( $store_id, '_wcpos_pricing_source', 'default' );

		$product_id = wp_insert_post( array(
			'post_type'   => 'product',
			'post_status' => 'publish',
			'post_title'  => 'Test Product',
		) );
		$this->create_test_inventory( $product_id, $term['term_id'], array(
			'stock_quantity' => '15',
		) );

		$response = new WP_REST_Response( array(
			'id'             => $product_id,
			'stock_quantity' => 100,
			'stock_status'   => 'instock',
			'price'          => '29.99',
			'regular_price'  => '29.99',
			'sale_price'     => '',
			'sku'            => 'ORIG-SKU',
		) );

		$request = new WP_REST_Request( 'GET', '/wcpos/v1/products' );
		$request->set_param( 'store_id', $store_id );

		$plugin = \WCPOS\ATUM\Plugin::instance();
		$result = $plugin->inject_atum_product_data( $response, $this->make_mock_product( $product_id ), $request );
		$data   = $result->get_data();

		$this->assertSame( 15, $data['stock_quantity'] );
		$this->assertSame( 'instock', $data['stock_status'] );
		$this->assertSame( '29.99', $data['price'] );
		$this->assertSame( 'ORIG-SKU', $data['sku'] );
	}

	public function test_product_response_injects_price_when_pricing_source_is_atum(): void {
		add_filter( 'wcpos_atum_is_supported', '__return_true' );
		$this->create_atum_tables();

		if ( ! taxonomy_exists( 'atum_location' ) ) {
			register_taxonomy( 'atum_location', 'product', array( 'hierarchical' => true ) );
		}

		$store_id = wp_insert_post( array(
			'post_type'   => 'wcpos_store',
			'post_status' => 'publish',
			'post_title'  => 'Price Store',
		) );
		$term = wp_insert_term( 'Price Location', 'atum_location' );
		update_post_meta( $store_id, '_wcpos_atum_inventory_location', $term['term_id'] );
		update_post_meta( $store_id, '_wcpos_pricing_source', 'atum' );

		$product_id = wp_insert_post( array(
			'post_type'   => 'product',
			'post_status' => 'publish',
			'post_title'  => 'Priced Product',
		) );
		$this->create_test_inventory( $product_id, $term['term_id'], array(
			'stock_quantity' => '10',
			'_regular_price' => '49.99',
			'_sale_price'    => '39.99',
			'_price'         => '39.99',
		) );

		$response = new WP_REST_Response( array(
			'id'             => $product_id,
			'stock_quantity' => 100,
			'stock_status'   => 'instock',
			'price'          => '29.99',
			'regular_price'  => '29.99',
			'sale_price'     => '',
			'on_sale'        => false,
			'sku'            => 'ORIG',
		) );

		$request = new WP_REST_Request( 'GET', '/wcpos/v1/products' );
		$request->set_param( 'store_id', $store_id );

		$plugin = \WCPOS\ATUM\Plugin::instance();
		$result = $plugin->inject_atum_product_data( $response, $this->make_mock_product( $product_id ), $request );
		$data   = $result->get_data();

		$this->assertSame( '49.99', $data['regular_price'] );
		$this->assertSame( '39.99', $data['sale_price'] );
		$this->assertSame( '39.99', $data['price'] );
		$this->assertTrue( $data['on_sale'] );
	}

	public function test_product_response_injects_sku_when_override_enabled(): void {
		add_filter( 'wcpos_atum_is_supported', '__return_true' );
		$this->create_atum_tables();

		if ( ! taxonomy_exists( 'atum_location' ) ) {
			register_taxonomy( 'atum_location', 'product', array( 'hierarchical' => true ) );
		}

		$store_id = wp_insert_post( array(
			'post_type'   => 'wcpos_store',
			'post_status' => 'publish',
			'post_title'  => 'SKU Store',
		) );
		$term = wp_insert_term( 'SKU Location', 'atum_location' );
		update_post_meta( $store_id, '_wcpos_atum_inventory_location', $term['term_id'] );
		update_post_meta( $store_id, '_wcpos_pricing_source', 'default' );
		update_post_meta( $store_id, '_wcpos_atum_sku_override', '1' );

		$product_id = wp_insert_post( array(
			'post_type'   => 'product',
			'post_status' => 'publish',
			'post_title'  => 'SKU Product',
		) );
		$this->create_test_inventory( $product_id, $term['term_id'], array(
			'stock_quantity' => '5',
			'_sku'           => 'ATUM-SKU-001',
		) );

		$response = new WP_REST_Response( array(
			'id'             => $product_id,
			'stock_quantity' => 100,
			'stock_status'   => 'instock',
			'sku'            => 'ORIG-SKU',
		) );

		$request = new WP_REST_Request( 'GET', '/wcpos/v1/products' );
		$request->set_param( 'store_id', $store_id );

		$plugin = \WCPOS\ATUM\Plugin::instance();
		$result = $plugin->inject_atum_product_data( $response, $this->make_mock_product( $product_id ), $request );
		$data   = $result->get_data();

		$this->assertSame( 'ATUM-SKU-001', $data['sku'] );
	}

	public function test_product_response_not_modified_for_non_wcpos_routes(): void {
		add_filter( 'wcpos_atum_is_supported', '__return_true' );

		$response = new WP_REST_Response( array(
			'id'             => 1,
			'stock_quantity' => 100,
		) );

		$request = new WP_REST_Request( 'GET', '/wc/v3/products' );

		$plugin = \WCPOS\ATUM\Plugin::instance();
		$result = $plugin->inject_atum_product_data( $response, $this->make_mock_product( 1 ), $request );
		$data   = $result->get_data();

		$this->assertSame( 100, $data['stock_quantity'] );
	}

	public function test_product_response_zero_stock_sets_outofstock_status(): void {
		add_filter( 'wcpos_atum_is_supported', '__return_true' );
		$this->create_atum_tables();

		if ( ! taxonomy_exists( 'atum_location' ) ) {
			register_taxonomy( 'atum_location', 'product', array( 'hierarchical' => true ) );
		}

		$store_id = wp_insert_post( array(
			'post_type'   => 'wcpos_store',
			'post_status' => 'publish',
			'post_title'  => 'Empty Store',
		) );
		$term = wp_insert_term( 'Empty Location', 'atum_location' );
		update_post_meta( $store_id, '_wcpos_atum_inventory_location', $term['term_id'] );

		$product_id = wp_insert_post( array(
			'post_type'   => 'product',
			'post_status' => 'publish',
			'post_title'  => 'Empty Product',
		) );
		$this->create_test_inventory( $product_id, $term['term_id'], array(
			'stock_quantity' => '0',
		) );

		$response = new WP_REST_Response( array(
			'id'             => $product_id,
			'stock_quantity' => 100,
			'stock_status'   => 'instock',
		) );

		$request = new WP_REST_Request( 'GET', '/wcpos/v1/products' );
		$request->set_param( 'store_id', $store_id );

		$plugin = \WCPOS\ATUM\Plugin::instance();
		$result = $plugin->inject_atum_product_data( $response, $this->make_mock_product( $product_id ), $request );
		$data   = $result->get_data();

		$this->assertSame( 0, $data['stock_quantity'] );
		$this->assertSame( 'outofstock', $data['stock_status'] );
	}

	// ---- Store Response Defaults Test ----

	public function test_store_response_defaults_when_no_atum_meta(): void {
		add_filter( 'wcpos_atum_is_supported', '__return_true' );

		$store_id = wp_insert_post( array(
			'post_type'   => 'post',
			'post_status' => 'publish',
			'post_title'  => 'Empty Store',
		) );
		$this->assertGreaterThan( 0, $store_id );

		$request  = new WP_REST_Request( 'GET', '/wcpos/v1/stores/' . $store_id );
		$response = new WP_REST_Response( array( 'id' => $store_id ) );

		$result = apply_filters( 'rest_post_dispatch', $response, rest_get_server(), $request );
		$data   = $result->get_data();

		$this->assertSame( 0, $data['atum_inventory_location'] );
		$this->assertSame( 'default', $data['pricing_source'] );
		$this->assertSame( '', $data['atum_sku_override'] );
	}

	// ---- Test Helpers ----

	/**
	 * Create a mock product with get_id().
	 *
	 * @param int $product_id
	 *
	 * @return object
	 */
	private function make_mock_product( int $product_id ) {
		return new class( $product_id ) {
			private $id;
			public function __construct( int $id ) {
				$this->id = $id;
			}
			public function get_id(): int {
				return $this->id;
			}
		};
	}

	/**
	 * Create the ATUM custom tables needed for testing.
	 */
	private function create_atum_tables(): void {
		global $wpdb;

		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}atum_inventories (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			product_id bigint(20) unsigned NOT NULL DEFAULT '0',
			name varchar(200) NOT NULL DEFAULT '',
			priority int(11) NOT NULL DEFAULT '0',
			is_main tinyint(1) NOT NULL DEFAULT '0',
			PRIMARY KEY (id),
			KEY product_id (product_id)
		)" );

		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}atum_inventory_meta (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			inventory_id bigint(20) unsigned NOT NULL DEFAULT '0',
			meta_key varchar(255) DEFAULT NULL,
			meta_value longtext,
			PRIMARY KEY (id),
			KEY inventory_id (inventory_id),
			KEY meta_key (meta_key(191))
		)" );

		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}atum_inventory_locations (
			inventory_id bigint(20) unsigned NOT NULL DEFAULT '0',
			term_taxonomy_id bigint(20) unsigned NOT NULL DEFAULT '0',
			term_order int(11) NOT NULL DEFAULT '0',
			PRIMARY KEY (inventory_id, term_taxonomy_id)
		)" );

		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}atum_inventory_orders (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			order_item_id bigint(20) unsigned NOT NULL DEFAULT '0',
			inventory_id bigint(20) unsigned NOT NULL DEFAULT '0',
			product_id bigint(20) unsigned NOT NULL DEFAULT '0',
			order_type smallint(4) unsigned NOT NULL DEFAULT '1',
			qty double DEFAULT NULL,
			subtotal double DEFAULT NULL,
			total double DEFAULT NULL,
			refund_qty double DEFAULT NULL,
			refund_total double DEFAULT NULL,
			PRIMARY KEY (id),
			KEY order_item_id (order_item_id),
			KEY inventory_id (inventory_id)
		)" );
	}

	/**
	 * Insert a test ATUM inventory with meta and location.
	 *
	 * @param int   $product_id
	 * @param int   $location_term_id
	 * @param array $meta
	 *
	 * @return int Inventory ID.
	 */
	private function create_test_inventory( int $product_id, int $location_term_id, array $meta = array() ): int {
		global $wpdb;

		$wpdb->insert( "{$wpdb->prefix}atum_inventories", array(
			'product_id' => $product_id,
			'name'       => "Inventory at location {$location_term_id}",
			'priority'   => 1,
			'is_main'    => 0,
		) );
		$inventory_id = (int) $wpdb->insert_id;

		$term_taxonomy_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE term_id = %d AND taxonomy = 'atum_location'",
			$location_term_id
		) );

		if ( $term_taxonomy_id > 0 ) {
			$wpdb->insert( "{$wpdb->prefix}atum_inventory_locations", array(
				'inventory_id'     => $inventory_id,
				'term_taxonomy_id' => $term_taxonomy_id,
			) );
		}

		$defaults = array(
			'stock_quantity' => '0',
			'_sku'           => '',
			'_regular_price' => '',
			'_sale_price'    => '',
			'_price'         => '',
		);
		$meta = array_merge( $defaults, $meta );

		foreach ( $meta as $key => $value ) {
			$wpdb->insert( "{$wpdb->prefix}atum_inventory_meta", array(
				'inventory_id' => $inventory_id,
				'meta_key'     => $key,
				'meta_value'   => $value,
			) );
		}

		return $inventory_id;
	}
}
