# Changelog

All notable changes to this project will be documented in this file.

## [0.1.2] - 2026-04-21

### Fixed
- Sync product and variation edits from the WCPOS Pro products screen back to the mapped ATUM inventory so location-specific stock updates land on the correct inventory record (PR #8).
- Refuse ambiguous write-back updates when more than one ATUM inventory could match the same store location, preventing accidental updates to the wrong inventory row (PR #8).

### Tests
- Add regression coverage for simple-product and variation edits through the WCPOS REST product endpoints to lock in the location-aware write-back behavior (PR #8).

### Changed
- Bump the plugin version to `0.1.2` for the product write-back fix release (PR #8).

## [0.1.1] - 2026-04-20

### Fixed
- Persist WooCommerce order IDs in `atum_inventory_orders` for POS sales and refunds so ATUM stock history stays traceable from the originating order (PR #6).
- Scope ATUM inventory lookups for POS stock movements to the configured store location before recording order-level inventory usage (PR #6).
- Prefer direct ATUM inventory values with safe fallbacks when shaping product responses for the POS API (PR #6).

### Added
- Add the project README with installation, configuration, and development guidance for GitHub visitors and contributors (PR #4).

### Changed
- Optimize GitHub Actions workflow concurrency and CI path filtering to reduce redundant release and test runs (PR #3).
- Bump the plugin version to `0.1.1` for the ATUM order history persistence release (PR #7).
- Align ATUM test fixtures and store editor asset loading with the plugin's current admin and POS editing paths (PR #6).

### Tests
- Add regression coverage for ATUM inventory order ID persistence and update the test schema to better match current ATUM installs (PR #6).

## [0.1.0] - 2026-03-11

### Added
- Initial release of the WCPOS ATUM integration plugin.
- Store-level ATUM inventory location mapping with location-aware stock, pricing, and optional SKU overrides in the POS.
- POS order stock reduction and restoration against the mapped ATUM inventory location.
