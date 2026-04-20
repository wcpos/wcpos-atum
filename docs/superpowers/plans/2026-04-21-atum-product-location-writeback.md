# ATUM Product Location Write-Back Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ensure WCPOS product and variation edits with a mapped `store_id` update the corresponding ATUM inventory row for that location.

**Architecture:** Add regression tests that reproduce the missing write-back for simple products and variations, then hook WCPOS product/variation REST save events to synchronize changed fields into `{$wpdb->prefix}atum_inventory_meta` for the store’s mapped inventory. Only copy fields present in the request so existing WooCommerce/WCPOS save behavior remains intact.

**Tech Stack:** WordPress, WooCommerce REST hooks, PHPUnit, custom ATUM test tables.

---

### Task 1: Add failing regression tests for POS product and variation edits

**Files:**
- Modify: `tests/test-wcpos-atum.php`
- Test: `tests/test-wcpos-atum.php`

- [ ] **Step 1: Write the failing simple-product regression test**

```php
public function test_pos_product_update_writes_stock_price_and_sku_to_atum_inventory(): void {
	add_filter( 'wcpos_atum_is_supported', '__return_true' );
	$this->create_atum_tables();
	$this->register_atum_location_taxonomy();

	$store_id          = $this->create_store_with_location();
	$location_term_id  = (int) get_post_meta( $store_id, '_wcpos_atum_inventory_location', true );
	$product           = new \WC_Product_Simple();
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

	$request = new \WP_REST_Request( 'PATCH', '/wcpos/v1/products/' . $product->get_id() );
	$request->set_param( 'store_id', $store_id );
	$request->set_param( 'stock_quantity', 9 );
	$request->set_param( 'regular_price', '22.00' );
	$request->set_param( 'sale_price', '18.00' );
	$request->set_param( 'sku', 'ATUM-NEW' );

	$plugin = \WCPOS\ATUM\Plugin::instance();
	$plugin->sync_atum_inventory_on_product_update( $product, $request, true );

	$meta = $this->get_inventory_meta( $inventory_id );
	$this->assertSame( '9', $meta['stock_quantity'] );
	$this->assertSame( '22.00', $meta['regular_price'] );
	$this->assertSame( '18.00', $meta['sale_price'] );
	$this->assertSame( '18.00', $meta['price'] );
	$this->assertSame( 'ATUM-NEW', $meta['sku'] );
}
```

- [ ] **Step 2: Run the simple-product regression test and verify it fails**

Run: `npx wp-env run tests-cli --env-cwd='wp-content/plugins/product-location-writeback' -- vendor/bin/phpunit -c phpunit.xml.dist --filter test_pos_product_update_writes_stock_price_and_sku_to_atum_inventory`
Expected: FAIL with missing method or unchanged ATUM inventory values.

- [ ] **Step 3: Write the failing variation regression test**

```php
public function test_pos_variation_update_writes_stock_price_and_sku_to_atum_inventory(): void {
	add_filter( 'wcpos_atum_is_supported', '__return_true' );
	$this->create_atum_tables();
	$this->register_atum_location_taxonomy();

	$store_id         = $this->create_store_with_location();
	$location_term_id = (int) get_post_meta( $store_id, '_wcpos_atum_inventory_location', true );

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

	$request = new \WP_REST_Request( 'PATCH', '/wcpos/v1/products/' . $parent->get_id() . '/variations/' . $variation->get_id() );
	$request->set_param( 'store_id', $store_id );
	$request->set_param( 'stock_quantity', 8 );
	$request->set_param( 'regular_price', '30.00' );
	$request->set_param( 'sale_price', '' );
	$request->set_param( 'sku', 'VAR-NEW' );

	$plugin = \WCPOS\ATUM\Plugin::instance();
	$plugin->sync_atum_inventory_on_product_update( $variation, $request, true );

	$meta = $this->get_inventory_meta( $inventory_id );
	$this->assertSame( '8', $meta['stock_quantity'] );
	$this->assertSame( '30.00', $meta['regular_price'] );
	$this->assertSame( '', $meta['sale_price'] );
	$this->assertSame( '30.00', $meta['price'] );
	$this->assertSame( 'VAR-NEW', $meta['sku'] );
}
```

- [ ] **Step 4: Run the variation regression test and verify it fails**

Run: `npx wp-env run tests-cli --env-cwd='wp-content/plugins/product-location-writeback' -- vendor/bin/phpunit -c phpunit.xml.dist --filter test_pos_variation_update_writes_stock_price_and_sku_to_atum_inventory`
Expected: FAIL with missing method or unchanged ATUM inventory values.

- [ ] **Step 5: Commit the failing tests**

```bash
git add tests/test-wcpos-atum.php docs/superpowers/plans/2026-04-21-atum-product-location-writeback.md
git commit -m "test: cover missing ATUM product write-back"
```

### Task 2: Implement mapped ATUM inventory write-back for WCPOS product saves

**Files:**
- Modify: `includes/class-plugin.php`
- Test: `tests/test-wcpos-atum.php`

- [ ] **Step 1: Register the REST save hooks**

```php
add_action( 'woocommerce_rest_insert_product_object', array( $this, 'sync_atum_inventory_on_product_update' ), 20, 3 );
add_action( 'woocommerce_rest_insert_product_variation_object', array( $this, 'sync_atum_inventory_on_product_update' ), 20, 3 );
```

- [ ] **Step 2: Implement the guarded sync entrypoint**

```php
public function sync_atum_inventory_on_product_update( $product, \WP_REST_Request $request, bool $creating ): void {
	if ( $creating || ! $this->is_atum_mi_supported() || ! $this->is_wcpos_product_write_request( $request ) ) {
		return;
	}

	$store_id = (int) $request->get_param( 'store_id' );
	if ( $store_id <= 0 ) {
		return;
	}

	$location_term_id = (int) get_post_meta( $store_id, self::STORE_LOCATION_META_KEY, true );
	if ( $location_term_id <= 0 ) {
		return;
	}

	$product_id = is_object( $product ) && is_callable( array( $product, 'get_id' ) ) ? (int) $product->get_id() : 0;
	if ( $product_id <= 0 ) {
		return;
	}

	$inventory = $this->get_inventory_for_product_at_location( $product_id, $location_term_id );
	if ( null === $inventory || empty( $inventory['inventory_id'] ) ) {
		return;
	}

	$this->update_inventory_meta_from_request( (int) $inventory['inventory_id'], $request, $inventory );
}
```

- [ ] **Step 3: Implement field extraction and inventory meta update helpers**

```php
private function update_inventory_meta_from_request( int $inventory_id, \WP_REST_Request $request, array $inventory ): void {
	$updates = array();

	if ( $request->has_param( 'stock_quantity' ) ) {
		$updates['stock_quantity'] = $this->normalize_inventory_number( $request->get_param( 'stock_quantity' ) );
	}
	if ( $request->has_param( 'regular_price' ) ) {
		$updates['regular_price'] = (string) $request->get_param( 'regular_price' );
	}
	if ( $request->has_param( 'sale_price' ) ) {
		$updates['sale_price'] = (string) $request->get_param( 'sale_price' );
	}
	if ( $request->has_param( 'sku' ) ) {
		$updates['sku'] = (string) $request->get_param( 'sku' );
	}
	if ( $request->has_param( 'price' ) ) {
		$updates['price'] = (string) $request->get_param( 'price' );
	} elseif ( array_key_exists( 'regular_price', $updates ) || array_key_exists( 'sale_price', $updates ) ) {
		$regular = $updates['regular_price'] ?? ( $inventory['regular_price'] ?? '' );
		$sale    = $updates['sale_price'] ?? ( $inventory['sale_price'] ?? '' );
		$updates['price'] = '' !== $sale ? $sale : $regular;
	}

	if ( empty( $updates ) ) {
		return;
	}

	global $wpdb;
	$wpdb->update(
		"{$wpdb->prefix}atum_inventory_meta",
		$updates,
		array( 'inventory_id' => $inventory_id )
	);
}
```

- [ ] **Step 4: Add the route guard helper**

```php
private function is_wcpos_product_write_request( \WP_REST_Request $request ): bool {
	if ( 0 !== strpos( $request->get_route(), '/wcpos/v1/products' ) ) {
		return false;
	}

	return in_array( $request->get_method(), array( 'POST', 'PUT', 'PATCH' ), true );
}
```

- [ ] **Step 5: Run the two regression tests and verify they pass**

Run: `npx wp-env run tests-cli --env-cwd='wp-content/plugins/product-location-writeback' -- vendor/bin/phpunit -c phpunit.xml.dist --filter 'test_pos_(product|variation)_update_writes_stock_price_and_sku_to_atum_inventory'`
Expected: PASS with 2 tests, 0 failures.

- [ ] **Step 6: Commit the implementation**

```bash
git add includes/class-plugin.php tests/test-wcpos-atum.php docs/superpowers/plans/2026-04-21-atum-product-location-writeback.md
git commit -m "fix: sync POS product edits to mapped ATUM inventory"
```

### Task 3: Verify the full plugin test suite

**Files:**
- Modify: none
- Test: `tests/test-wcpos-atum.php`

- [ ] **Step 1: Run the full PHPUnit suite**

Run: `npx wp-env run tests-cli --env-cwd='wp-content/plugins/product-location-writeback' -- vendor/bin/phpunit -c phpunit.xml.dist`
Expected: PASS with all tests green.

- [ ] **Step 2: Record the worktree-specific test command caveat**

```text
Use wp-content/plugins/product-location-writeback as the in-container path from this worktree; the package.json script still points at wp-content/plugins/wcpos-atum.
```

- [ ] **Step 3: Commit verification-only doc updates if any were made**

```bash
git status --short
```
Expected: no unexpected changes.
