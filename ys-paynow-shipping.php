<?php
/**
 * YS PayNow Shipping for WooCommerce
 *
 * 整合 PayNow 物流服務至 WooCommerce，支援超商取貨與宅配。
 *
 * @link              https://yangsheep.com.tw
 * @since             1.0.0
 * @package           yangsheep\paynow\shipping
 *
 * @wordpress-plugin
 * Plugin Name:       YS PAYNOW VIA WOOCOMMERCE
 * Plugin URI:        https://yangsheep.com.tw/plugins/ys-paynow-shipping
 * Description:       整合 PayNow 物流服務至 WooCommerce，支援 7-11、全家、萊爾富超商取貨與黑貓宅配。
 * Version:           1.5.2
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

namespace yangsheep\paynow\shipping;

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
define( 'YS_PAYNOW_SHIPPING_VERSION', '1.5.2' );
define( 'YS_PAYNOW_SHIPPING_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'YS_PAYNOW_SHIPPING_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'YS_PAYNOW_SHIPPING_BASENAME', plugin_basename( __FILE__ ) );

/*
|--------------------------------------------------------------------------
| Composer autoloader（載入 hub-client 等 vendor 套件）
|--------------------------------------------------------------------------
*/
if ( file_exists( YS_PAYNOW_SHIPPING_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
	require_once YS_PAYNOW_SHIPPING_PLUGIN_DIR . 'vendor/autoload.php';
}

/*
|--------------------------------------------------------------------------
| PSR-4 Fallback Autoloader（永遠註冊，確保自身 namespace 可載入）
|--------------------------------------------------------------------------
*/
spl_autoload_register( function ( $class ) {
	$prefix   = 'yangsheep\\paynow\\shipping\\';
	$base_dir = YS_PAYNOW_SHIPPING_PLUGIN_DIR . 'src/';
	$len      = strlen( $prefix );

	if ( strncmp( $prefix, $class, $len ) !== 0 ) {
		return;
	}

	$relative_class = substr( $class, $len );
	$file           = $base_dir . str_replace( '\\', '/', $relative_class ) . '.php';

	if ( file_exists( $file ) ) {
		require $file;
	}
} );

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
| YS Plugin Hub Client 註冊
|--------------------------------------------------------------------------
*/
add_action( 'plugins_loaded', function () {
	if ( class_exists( '\YangSheep\PluginHubClient\YSPluginHubClient' ) ) {
		\YangSheep\PluginHubClient\YSPluginHubClient::register( array(
			'slug'        => 'ys-paynow-shipping',
			'version'     => YS_PAYNOW_SHIPPING_VERSION,
			'plugin_file' => __FILE__,
			'name'        => 'YS PAYNOW VIA WOOCOMMERCE',
		) );
	}
}, 5 );

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

	// 載入開發工具 (僅在後台)
	if ( is_admin() && file_exists( YS_PAYNOW_SHIPPING_PLUGIN_DIR . 'dev-tools/test-order-setup.php' ) ) {
		require_once YS_PAYNOW_SHIPPING_PLUGIN_DIR . 'dev-tools/test-order-setup.php';
	}
}
add_action( 'plugins_loaded', __NAMESPACE__ . '\\run_ys_paynow_shipping' );

/*
|--------------------------------------------------------------------------
| 外掛動作連結
|--------------------------------------------------------------------------
| 在外掛列表頁面添加「設定」連結。
*/
add_filter( 'plugin_action_links_' . YS_PAYNOW_SHIPPING_BASENAME, function( $links ) {
	$settings_link = '<a href="' . admin_url( 'admin.php?page=ys-paynow-shipping' ) . '">' . __( '設定', 'ys-paynow-shipping' ) . '</a>';
	array_unshift( $links, $settings_link );
	return $links;
} );
