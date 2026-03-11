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
}
