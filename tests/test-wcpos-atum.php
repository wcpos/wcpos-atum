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
}
