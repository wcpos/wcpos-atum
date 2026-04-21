# WCPOS ATUM Integration

Integrates [ATUM Multi-Inventory](https://www.stockmanagementlabs.com/addons/atum-multi-inventory/) with [WooCommerce POS Pro](https://wcpos.com), enabling location-based inventory, pricing, and SKUs at the Point of Sale.

## Releases and Changelog

- **Latest version:** `0.1.3`
- **Downloads and packaged releases:** [GitHub Releases](https://github.com/wcpos/wcpos-atum/releases)
- **Version history:** [CHANGELOG.md](./CHANGELOG.md)

### Recent Changes

- **0.1.3** — add GitHub update metadata (`Update URI`) and bump the plugin version for the release packaging update.
- **0.1.2** — product and variation edits from the POS Pro products screen now write stock changes back to the mapped ATUM inventory, with regression tests covering both paths.
- **0.1.1** — POS order inventory history now persists WooCommerce order IDs in ATUM, plus workflow and documentation improvements.
- **0.1.0** — initial public release of the WCPOS ATUM integration.

## How It Works

ATUM Multi-Inventory lets you split a product's stock across multiple inventory locations (e.g. warehouses, retail stores). This plugin connects those locations to WCPOS Pro stores so that each POS terminal sees the correct stock levels, prices, and SKUs for its physical location.

### Store Configuration

In the WCPOS Pro store editor, this plugin adds an **ATUM Inventory** section to the sidebar where you can configure three settings per store:

- **Inventory Location** — Select which ATUM location this store pulls stock from.
- **Pricing Source** — Choose where product prices come from:
  - *Default* — Standard WooCommerce prices
  - *WCPOS Pro* — Per-store pricing set in WCPOS Pro
  - *ATUM* — Location-specific prices from the ATUM inventory
- **SKU Override** — Optionally use location-specific SKUs from ATUM instead of the product's main SKU.

### What Changes at the POS

When a store has an ATUM location assigned, product data served to the POS is automatically adjusted:

- **Stock quantities** reflect the inventory at that specific location, not the aggregate WooCommerce stock.
- **Stock status** is recalculated based on the location's quantity.
- **Prices** come from the configured pricing source (WooCommerce default, WCPOS Pro, or ATUM location).
- **SKUs** can be swapped to the location-specific ATUM SKU if the override is enabled.

All of this happens transparently through the WCPOS REST API — no changes are needed on the POS app side.

### Stock Management

The plugin takes over stock management for POS orders placed at stores with an ATUM location:

1. **Blocks ATUM's default stock allocation** — Prevents ATUM from automatically distributing stock reductions across inventories using its default priority logic.
2. **Reduces stock at the correct location** — When an order moves to `processing` or `completed`, stock is deducted from the specific ATUM inventory tied to that store's location.
3. **Restores stock on refund/cancellation** — If an order is refunded or cancelled, stock is returned to the same inventory location it was originally taken from.

Each stock movement is recorded in ATUM's `atum_inventory_orders` table, maintaining a full audit trail.

## Requirements

- WordPress >= 5.9
- PHP >= 7.4
- [WooCommerce](https://woocommerce.com/)
- [ATUM Inventory Management](https://www.stockmanagementlabs.com/)
- [ATUM Multi-Inventory](https://www.stockmanagementlabs.com/addons/atum-multi-inventory/)
- [WooCommerce POS Pro](https://wcpos.com)

## Installation

1. Download or clone this repository into `wp-content/plugins/wcpos-atum/`.
2. Activate the plugin from the WordPress admin.
3. Go to **POS > Stores**, edit a store, and configure the ATUM Inventory section in the sidebar.

## Development

```bash
# Install dependencies
composer install

# Run tests (requires wp-env or a WordPress test environment)
composer test

# Lint PHP
composer lint
```
