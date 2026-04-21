<?php
/**
 * Plugin Name: WCPOS ATUM Integration
 * Description: ATUM Multi-Inventory integration for WCPOS, linking inventory locations to POS stores.
 * Version: 0.1.2
 * Author: kilbot
 * Update URI:  https://github.com/wcpos/wcpos-atum
 * Requires Plugins: woocommerce, atum-stock-manager-for-woocommerce, atum-multi-inventory
 * Text Domain: wcpos-atum
 *
 * @package WCPOS\ATUM
 */

namespace WCPOS\ATUM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const VERSION = '0.1.2';

require_once __DIR__ . '/includes/class-plugin.php';

Plugin::instance();
