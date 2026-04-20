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

	public function test_store_edit_assets_enqueue_for_pos_store_edit_hook(): void {
		add_filter( 'wcpos_atum_is_supported', '__return_true' );

		if ( ! taxonomy_exists( 'atum_location' ) ) {
			register_taxonomy( 'atum_location', 'product', array( 'hierarchical' => true ) );
		}

		wp_insert_term( 'Store A Location', 'atum_location' );

		wp_register_script( 'woocommerce-pos-pro-store-edit', 'https://example.org/store-edit.js', array( 'wp-element' ), '1.0.0', true );
		wp_enqueue_script( 'woocommerce-pos-pro-store-edit' );

		$plugin = \WCPOS\ATUM\Plugin::instance();
		$plugin->enqueue_store_edit_assets( 'pos_page_wcpos-store-edit' );

		$this->assertTrue( wp_script_is( 'wcpos-atum-store-edit', 'enqueued' ) );

		$script = wp_scripts()->registered['wcpos-atum-store-edit'];
		$this->assertSame( array( 'woocommerce-pos-pro-store-edit', 'wp-element' ), $script->deps );
		$this->assertStringContainsString( 'store-atum-section.js', $script->src );
		$this->assertStringContainsString( 'Store A Location', $script->extra['before'][1] );
	}

	public function test_store_edit_script_uses_plain_section_styling(): void {
		$script = file_get_contents( dirname( __DIR__ ) . '/assets/js/store-atum-section.js' );

		$this->assertIsString( $script );
		$this->assertStringContainsString( 'wcpos:border-b wcpos:border-gray-200 wcpos:pb-6', $script );
		$this->assertStringNotContainsString( 'wcpos:rounded-lg wcpos:border wcpos:border-gray-200 wcpos:bg-white wcpos:p-6', $script );
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

		$term             = wp_insert_term( 'Store A Location', 'atum_location' );
		$location_term_id = $term['term_id'];

		$this->create_test_inventory( $product_id, $location_term_id, array(
			'stock_quantity' => '25',
			'sku'            => 'LOC-A-001',
			'regular_price'  => '19.99',
			'sale_price'     => '14.99',
			'price'          => '14.99',
		) );

		$plugin = \WCPOS\ATUM\Plugin::instance();
		$result = $plugin->get_inventory_for_product_at_location( $product_id, $location_term_id );

		$this->assertIsArray( $result );
		$this->assertSame( '25', $result['stock_quantity'] );
		$this->assertSame( 'LOC-A-001', $result['sku'] );
		$this->assertSame( '19.99', $result['regular_price'] );
		$this->assertSame( '14.99', $result['sale_price'] );
		$this->assertSame( '14.99', $result['price'] );
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
			'regular_price'  => '49.99',
			'sale_price'     => '39.99',
			'price'          => '39.99',
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
			'sku'            => 'ATUM-SKU-001',
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

	public function test_pos_product_update_writes_stock_price_and_sku_to_atum_inventory(): void {
		add_filter( 'wcpos_atum_is_supported', '__return_true' );
		$this->create_atum_tables();
		$this->register_atum_location_taxonomy();

		$store_id         = $this->create_store_with_location( 'Editable Store', 'Editable Location' );
		$location_term_id = (int) get_post_meta( $store_id, '_wcpos_atum_inventory_location', true );
		update_post_meta( $store_id, '_wcpos_pricing_source', 'atum' );
		update_post_meta( $store_id, '_wcpos_atum_sku_override', '1' );

		$product = new \WC_Product_Simple();
		$product->set_name( 'Editable Product' );
		$product->set_regular_price( '10.00' );
		$product->set_sku( 'BASE-SKU' );
		$product->save();

		$inventory_id = $this->create_test_inventory(
			$product->get_id(),
			$location_term_id,
			array(
				'stock_quantity' => '4',
				'regular_price'  => '12.00',
				'sale_price'     => '',
				'price'          => '12.00',
				'sku'            => 'ATUM-OLD',
			)
		);

		$request = new WP_REST_Request( 'PATCH', '/wcpos/v1/products/' . $product->get_id() );
		$request->set_param( 'store_id', $store_id );
		$request->set_param( 'stock_quantity', 9 );
		$request->set_param( 'regular_price', '22.00' );
		$request->set_param( 'sale_price', '18.00' );
		$request->set_param( 'sku', 'ATUM-NEW' );

		do_action( 'woocommerce_rest_insert_product_object', $product, $request, false );

		$meta = $this->get_inventory_meta( $inventory_id );

		$this->assertSame( '9', $meta['stock_quantity'] );
		$this->assertSame( '22.00', $meta['regular_price'] );
		$this->assertSame( '18.00', $meta['sale_price'] );
		$this->assertSame( '18.00', $meta['price'] );
		$this->assertSame( 'ATUM-NEW', $meta['sku'] );
	}

	public function test_pos_variation_update_writes_stock_price_and_sku_to_atum_inventory(): void {
		add_filter( 'wcpos_atum_is_supported', '__return_true' );
		$this->create_atum_tables();
		$this->register_atum_location_taxonomy();

		$store_id         = $this->create_store_with_location( 'Variation Store', 'Variation Location' );
		$location_term_id = (int) get_post_meta( $store_id, '_wcpos_atum_inventory_location', true );
		update_post_meta( $store_id, '_wcpos_pricing_source', 'atum' );
		update_post_meta( $store_id, '_wcpos_atum_sku_override', '1' );

		$parent = new \WC_Product_Variable();
		$parent->set_name( 'Variable Product' );
		$parent->save();

		$variation = new \WC_Product_Variation();
		$variation->set_parent_id( $parent->get_id() );
		$variation->set_regular_price( '14.00' );
		$variation->set_sku( 'VAR-BASE' );
		$variation->save();

		$inventory_id = $this->create_test_inventory(
			$variation->get_id(),
			$location_term_id,
			array(
				'stock_quantity' => '3',
				'regular_price'  => '16.00',
				'sale_price'     => '',
				'price'          => '16.00',
				'sku'            => 'VAR-OLD',
			)
		);

		$request = new WP_REST_Request( 'PATCH', '/wcpos/v1/products/' . $parent->get_id() . '/variations/' . $variation->get_id() );
		$request->set_param( 'store_id', $store_id );
		$request->set_param( 'stock_quantity', 8 );
		$request->set_param( 'regular_price', '30.00' );
		$request->set_param( 'sale_price', '' );
		$request->set_param( 'sku', 'VAR-NEW' );

		do_action( 'woocommerce_rest_insert_product_variation_object', $variation, $request, false );

		$meta = $this->get_inventory_meta( $inventory_id );

		$this->assertSame( '8', $meta['stock_quantity'] );
		$this->assertSame( '30.00', $meta['regular_price'] );
		$this->assertSame( '', $meta['sale_price'] );
		$this->assertSame( '30.00', $meta['price'] );
		$this->assertSame( 'VAR-NEW', $meta['sku'] );
	}

	public function test_pos_product_update_only_syncs_stock_when_store_is_not_using_atum_price_or_sku(): void {
		add_filter( 'wcpos_atum_is_supported', '__return_true' );
		$this->create_atum_tables();
		$this->register_atum_location_taxonomy();

		$store_id         = $this->create_store_with_location( 'Stock Only Store', 'Stock Only Location' );
		$location_term_id = (int) get_post_meta( $store_id, '_wcpos_atum_inventory_location', true );
		update_post_meta( $store_id, '_wcpos_pricing_source', 'default' );
		update_post_meta( $store_id, '_wcpos_atum_sku_override', '' );

		$product = new \WC_Product_Simple();
		$product->set_name( 'Stock Only Product' );
		$product->set_regular_price( '10.00' );
		$product->set_sku( 'BASE-SKU' );
		$product->save();

		$inventory_id = $this->create_test_inventory(
			$product->get_id(),
			$location_term_id,
			array(
				'stock_quantity' => '2',
				'regular_price'  => '12.00',
				'sale_price'     => '11.00',
				'price'          => '11.00',
				'sku'            => 'ATUM-OLD',
			)
		);

		$request = new WP_REST_Request( 'PATCH', '/wcpos/v1/products/' . $product->get_id() );
		$request->set_param( 'store_id', $store_id );
		$request->set_param( 'stock_quantity', 7 );
		$request->set_param( 'regular_price', '22.00' );
		$request->set_param( 'sale_price', '18.00' );
		$request->set_param( 'sku', 'ATUM-NEW' );

		do_action( 'woocommerce_rest_insert_product_object', $product, $request, false );

		$meta = $this->get_inventory_meta( $inventory_id );

		$this->assertSame( '7', $meta['stock_quantity'] );
		$this->assertSame( '12.00', $meta['regular_price'] );
		$this->assertSame( '11.00', $meta['sale_price'] );
		$this->assertSame( '11.00', $meta['price'] );
		$this->assertSame( 'ATUM-OLD', $meta['sku'] );
	}

	// ---- Native ATUM Flow Tests ----

	public function test_atum_stock_reduction_uses_native_atum_flow_for_pos_orders_with_location(): void {
		add_filter( 'wcpos_atum_is_supported', '__return_true' );
		$this->create_atum_tables();

		if ( ! taxonomy_exists( 'atum_location' ) ) {
			register_taxonomy( 'atum_location', 'product', array( 'hierarchical' => true ) );
		}

		$store_id = wp_insert_post( array(
			'post_type'   => 'wcpos_store',
			'post_status' => 'publish',
			'post_title'  => 'Deduction Store',
		) );
		$term = wp_insert_term( 'Deduction Location', 'atum_location' );
		update_post_meta( $store_id, '_wcpos_atum_inventory_location', $term['term_id'] );

		$order = wc_create_order();
		$order->update_meta_data( '_pos_store', $store_id );
		$order->save();

		$can_reduce = apply_filters( 'atum/multi_inventory/can_reduce_order_stock', true, $order );
		$this->assertTrue( $can_reduce );
	}

	public function test_atum_stock_reduction_not_blocked_for_non_pos_orders(): void {
		add_filter( 'wcpos_atum_is_supported', '__return_true' );

		$order = wc_create_order();
		$order->save();

		$can_reduce = apply_filters( 'atum/multi_inventory/can_reduce_order_stock', true, $order );
		$this->assertTrue( $can_reduce );
	}

	public function test_pos_order_item_inventories_are_scoped_to_the_store_location(): void {
		add_filter( 'wcpos_atum_is_supported', '__return_true' );
		$this->create_atum_tables();

		if ( ! taxonomy_exists( 'atum_location' ) ) {
			register_taxonomy( 'atum_location', 'product', array( 'hierarchical' => true ) );
		}

		$store_id = wp_insert_post( array(
			'post_type'   => 'wcpos_store',
			'post_status' => 'publish',
			'post_title'  => 'Stock Store',
		) );
		$term = wp_insert_term( 'Stock Location', 'atum_location' );
		update_post_meta( $store_id, '_wcpos_atum_inventory_location', $term['term_id'] );

		$product = new \WC_Product_Simple();
		$product->set_name( 'Stock Test Product' );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 100 );
		$product->set_regular_price( '10.00' );
		$product->save();

		$matching_inventory = $this->make_fake_inventory( 11, array( $term['term_id'] ) );
		$other_inventory    = $this->make_fake_inventory( 22, array( $term['term_id'] + 100 ) );

		$order = wc_create_order();
		$order->update_meta_data( '_pos_store', $store_id );
		$order->add_product( $product, 3 );
		$order->save();

		$order_items = $order->get_items();
		$order_item  = reset( $order_items );

		$filtered = apply_filters(
			'atum/multi_inventory/order_item_inventories',
			array( $matching_inventory, $other_inventory ),
			$order_item
		);

		$this->assertCount( 1, $filtered );
		$this->assertSame( 11, $filtered[0]->id );
	}

	public function test_pos_order_item_inventories_return_empty_when_store_location_has_no_matching_inventory(): void {
		add_filter( 'wcpos_atum_is_supported', '__return_true' );
		$this->create_atum_tables();

		if ( ! taxonomy_exists( 'atum_location' ) ) {
			register_taxonomy( 'atum_location', 'product', array( 'hierarchical' => true ) );
		}

		$store_id = wp_insert_post( array(
			'post_type'   => 'wcpos_store',
			'post_status' => 'publish',
			'post_title'  => 'Stock Store',
		) );
		$term = wp_insert_term( 'Stock Location', 'atum_location' );
		update_post_meta( $store_id, '_wcpos_atum_inventory_location', $term['term_id'] );

		$product = new \WC_Product_Simple();
		$product->set_name( 'Stock Test Product' );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 100 );
		$product->set_regular_price( '10.00' );
		$product->save();

		$other_inventory = $this->make_fake_inventory( 22, array( $term['term_id'] + 100 ) );

		$order = wc_create_order();
		$order->update_meta_data( '_pos_store', $store_id );
		$order->add_product( $product, 3 );
		$order->save();

		$order_items = $order->get_items();
		$order_item  = reset( $order_items );

		$filtered = apply_filters(
			'atum/multi_inventory/order_item_inventories',
			array( $other_inventory ),
			$order_item
		);

		$this->assertSame( array(), $filtered );
	}

	public function test_pos_order_request_injects_mi_inventories_for_store_location(): void {
		add_filter( 'wcpos_atum_is_supported', '__return_true' );
		$this->create_atum_tables();

		if ( ! taxonomy_exists( 'atum_location' ) ) {
			register_taxonomy( 'atum_location', 'product', array( 'hierarchical' => true ) );
		}

		$store_id = wp_insert_post( array(
			'post_type'   => 'wcpos_store',
			'post_status' => 'publish',
			'post_title'  => 'REST Store',
		) );
		$term = wp_insert_term( 'REST Location', 'atum_location' );
		update_post_meta( $store_id, '_wcpos_atum_inventory_location', $term['term_id'] );

		$product_id = wp_insert_post( array(
			'post_type'   => 'product',
			'post_status' => 'publish',
			'post_title'  => 'REST Product',
		) );
		$inventory_id = $this->create_test_inventory(
			$product_id,
			$term['term_id'],
			array(
				'stock_quantity' => '7',
			)
		);

		$request = new WP_REST_Request( 'POST', '/wcpos/v1/orders' );
		$request->set_param( 'store_id', $store_id );
		$request->set_param(
			'line_items',
			array(
				array(
					'product_id' => $product_id,
					'quantity'   => 2,
				),
			)
		);

		apply_filters( 'rest_request_before_callbacks', null, array(), $request );

		$line_items = $request->get_param( 'line_items' );

		$this->assertSame(
			array(
				array(
					'inventory_id' => $inventory_id,
					'product_id'   => $product_id,
					'qty'          => 2,
				),
			),
			$line_items[0]['mi_inventories']
		);
	}

	public function test_pos_order_request_preserves_existing_mi_inventories(): void {
		add_filter( 'wcpos_atum_is_supported', '__return_true' );

		$request = new WP_REST_Request( 'POST', '/wcpos/v1/orders' );
		$request->set_param( 'store_id', 123 );
		$request->set_param(
			'line_items',
			array(
				array(
					'product_id'      => 456,
					'quantity'        => 1,
					'mi_inventories'  => array(
						array(
							'inventory_id' => 789,
							'product_id'   => 456,
							'qty'          => 1,
						),
					),
				),
			)
		);

		apply_filters( 'rest_request_before_callbacks', null, array(), $request );

		$line_items = $request->get_param( 'line_items' );

		$this->assertSame( 789, $line_items[0]['mi_inventories'][0]['inventory_id'] );
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
	 * Create a fake ATUM inventory object for order-item inventory filter tests.
	 *
	 * @param int   $inventory_id Inventory ID.
	 * @param array $locations    Linked ATUM location term IDs.
	 *
	 * @return object
	 */
	private function make_fake_inventory( int $inventory_id, array $locations ) {
		return new class( $inventory_id, $locations ) {
			public $id;

			/**
			 * @var int[]
			 */
			private $locations;

			public function __construct( int $inventory_id, array $locations ) {
				$this->id        = $inventory_id;
				$this->locations = $locations;
			}

			public function get_locations(): array {
				return $this->locations;
			}
		};
	}

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
	 * Ensure the ATUM location taxonomy exists for tests.
	 */
	private function register_atum_location_taxonomy(): void {
		if ( ! taxonomy_exists( 'atum_location' ) ) {
			register_taxonomy( 'atum_location', 'product', array( 'hierarchical' => true ) );
		}
	}

	/**
	 * Create a WCPOS store mapped to an ATUM location.
	 *
	 * @param string $store_title    Store post title.
	 * @param string $location_label ATUM location term label.
	 *
	 * @return int
	 */
	private function create_store_with_location( string $store_title, string $location_label ): int {
		$store_id = wp_insert_post(
			array(
				'post_type'   => 'wcpos_store',
				'post_status' => 'publish',
				'post_title'  => $store_title,
			)
		);

		$term = wp_insert_term( $location_label, 'atum_location' );
		update_post_meta( $store_id, '_wcpos_atum_inventory_location', $term['term_id'] );

		return $store_id;
	}

	/**
	 * Fetch a single ATUM inventory_meta row as an associative array.
	 *
	 * @param int $inventory_id Inventory ID.
	 *
	 * @return array<string,string>
	 */
	private function get_inventory_meta( int $inventory_id ): array {
		global $wpdb;

		$meta = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}atum_inventory_meta WHERE inventory_id = %d",
				$inventory_id
			),
			ARRAY_A
		);

		if ( ! is_array( $meta ) ) {
			return array();
		}

		if ( isset( $meta['stock_quantity'] ) ) {
			$meta['stock_quantity'] = rtrim( rtrim( (string) $meta['stock_quantity'], '0' ), '.' );
		}

		return $meta;
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
			inventory_id bigint(20) unsigned NOT NULL DEFAULT '0',
			manage_stock tinyint(1) NOT NULL DEFAULT '1',
			stock_quantity decimal(19,4) DEFAULT NULL,
			sku varchar(100) DEFAULT NULL,
			regular_price varchar(100) DEFAULT NULL,
			sale_price varchar(100) DEFAULT NULL,
			price varchar(100) DEFAULT NULL,
			PRIMARY KEY (inventory_id)
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
			reduced_stock double DEFAULT NULL,
			extra_data longtext DEFAULT NULL,
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
			'manage_stock'   => 1,
			'stock_quantity' => '0',
			'sku'            => '',
			'regular_price'  => '',
			'sale_price'     => '',
			'price'          => '',
		);

		$wpdb->insert(
			"{$wpdb->prefix}atum_inventory_meta",
			array_merge(
				array( 'inventory_id' => $inventory_id ),
				array_merge( $defaults, $meta )
			)
		);

		return $inventory_id;
	}
}
