<?php
/**
 * YS PayNow Shipping for WooCommerce
 *
 * 整合 PayNow 物流服務至 WooCommerce，支援超商取貨與宅配。
 *
 * @link              https://yangsheep.com.tw
 * @since             1.0.0
 * @package           YangSheep\PayNow\Shipping
 *
 * @wordpress-plugin
 * Plugin Name:       YS PAYNOW VIA WOOCOMMERCE
 * Plugin URI:        https://yangsheep.com.tw/plugins/ys-paynow-shipping
 * Description:       整合 PayNow 物流服務至 WooCommerce，支援 7-11、全家、萊爾富超商取貨與黑貓宅配。
 * Version:           1.1.0
 * Author:            YANGSHEEP DESIGN
 * Author URI:        https://yangsheep.com.tw
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Requires Plugins:  woocommerce
 * Text Domain:       ys-paynow-shipping
 * Domain Path:       /languages
 * Requires at least: 5.8
 * Requires PHP:      7.4
 */

namespace YangSheep\PayNow\Shipping;

// 防止直接存取
if ( ! defined( 'WPINC' ) ) {
	die;
}

/*
|--------------------------------------------------------------------------
| 外掛常數定義
|--------------------------------------------------------------------------
| 定義外掛所需的常數，方便在各處使用。
*/
define( 'YS_PAYNOW_SHIPPING_VERSION', '1.1.0' );
define( 'YS_PAYNOW_SHIPPING_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'YS_PAYNOW_SHIPPING_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'YS_PAYNOW_SHIPPING_BASENAME', plugin_basename( __FILE__ ) );
define( 'YS_PAYNOW_SHIPPING_TEMPLATE_DIR', plugin_dir_path( __FILE__ ) . 'templates/' );

/*
|--------------------------------------------------------------------------
| 自動載入器
|--------------------------------------------------------------------------
| 載入 Composer 自動載入器，若不存在則顯示錯誤提示。
*/
if ( file_exists( YS_PAYNOW_SHIPPING_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
	require_once YS_PAYNOW_SHIPPING_PLUGIN_DIR . 'vendor/autoload.php';
} else {
	// 若無 Composer autoloader，使用簡易的類別載入
	spl_autoload_register( function ( $class ) {
		// 命名空間前綴
		$prefix = 'YangSheep\\PayNow\\Shipping\\';

		// 基礎目錄
		$base_dir = YS_PAYNOW_SHIPPING_PLUGIN_DIR . 'src/';

		// 檢查類別是否使用此命名空間前綴
		$len = strlen( $prefix );
		if ( strncmp( $prefix, $class, $len ) !== 0 ) {
			return;
		}

		// 取得相對類別名稱
		$relative_class = substr( $class, $len );

		// 將命名空間分隔符號轉換為目錄分隔符號，並加上 .php
		$file = $base_dir . str_replace( '\\', '/', $relative_class ) . '.php';

		// 若檔案存在則載入
		if ( file_exists( $file ) ) {
			require $file;
		}
	});
}

/*
|--------------------------------------------------------------------------
| WooCommerce 相容性宣告
|--------------------------------------------------------------------------
| 宣告與 WooCommerce High-Performance Order Storage (HPOS) 相容。
*/
add_action( 'before_woocommerce_init', function() {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

/*
|--------------------------------------------------------------------------
| WooCommerce 相依性檢查
|--------------------------------------------------------------------------
| 當 WooCommerce 未安裝或未啟用時，顯示警告訊息。
*/
function ys_paynow_shipping_needs_woocommerce() {
	echo '<div id="message" class="error">';
	echo '  <p>' . esc_html__( 'PayNow Shipping 需要 WooCommerce 才能運作，請先安裝並啟用 WooCommerce！', 'ys-paynow-shipping' ) . '</p>';
	echo '</div>';
}

/*
|--------------------------------------------------------------------------
| 外掛初始化
|--------------------------------------------------------------------------
| 在 plugins_loaded 時執行初始化，確保 WooCommerce 已載入。
*/
function run_ys_paynow_shipping() {

	// 檢查 WooCommerce 是否已啟用
	if ( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ), true ) ) {
		// 多站點支援
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! is_plugin_active_for_network( 'woocommerce/woocommerce.php' ) ) {
			add_action( 'admin_notices', __NAMESPACE__ . '\\ys_paynow_shipping_needs_woocommerce' );
			return;
		}
	}

	// 載入語言檔
	load_plugin_textdomain( 'ys-paynow-shipping', false, dirname( YS_PAYNOW_SHIPPING_BASENAME ) . '/languages/' );

	// 初始化主類別
	YSPaynowShipping::init();
}
add_action( 'plugins_loaded', __NAMESPACE__ . '\\run_ys_paynow_shipping' );

/*
|--------------------------------------------------------------------------
| 外掛動作連結
|--------------------------------------------------------------------------
| 在外掛列表頁面添加「設定」連結。
*/
add_filter( 'plugin_action_links_' . YS_PAYNOW_SHIPPING_BASENAME, function( $links ) {
	$settings_link = '<a href="' . admin_url( 'admin.php?page=wc-settings&tab=ys_paynow_shipping' ) . '">' . __( '設定', 'ys-paynow-shipping' ) . '</a>';
	array_unshift( $links, $settings_link );
	return $links;
} );
