<?php
/**
 * YS Order Status Class
 *
 * 定義與註冊自訂訂單狀態
 *
 * @package yangsheep\paynow\shipping\utils
 * @since   1.0.0
 */

namespace yangsheep\paynow\shipping\utils;

defined( 'ABSPATH' ) || exit;

/**
 * YSOrderStatus 類別
 */
class YSOrderStatus {

	const SHIPPING_ORDERED = 'wc-shipping-ordered';
	const SHIPPING_TRANSIT = 'wc-shipping-transit';
	const SHIPPING_ARRIVED = 'wc-shipping-arrived';
	const SHIPPING_RETURNED = 'wc-shipping-returned';

	/**
	 * 初始化
	 */
	public static function init() {
		// 檢查是否啟用自動狀態配置
		$auto_status = get_option( 'ys_paynow_shipping_auto_status', 'yes' );

		if ( 'yes' === $auto_status ) {
			add_action( 'init', array( __CLASS__, 'register_post_statuses' ) );
			add_filter( 'wc_order_statuses', array( __CLASS__, 'add_order_statuses' ) );
			add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_styles' ) );
		}
	}

	/**
	 * 註冊 Post Status
	 */
	public static function register_post_statuses() {
		register_post_status( self::SHIPPING_ORDERED, array(
			'label'                     => _x( '已安排出貨', 'Order status', 'ys-paynow-shipping' ),
			'public'                    => true,
			'exclude_from_search'       => false,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			/* translators: %s: count */
			'label_count'               => _n_noop( '已安排出貨 <span class="count">(%s)</span>', '已安排出貨 <span class="count">(%s)</span>', 'ys-paynow-shipping' ),
		) );

		register_post_status( self::SHIPPING_TRANSIT, array(
			'label'                     => _x( '運送中', 'Order status', 'ys-paynow-shipping' ),
			'public'                    => true,
			'exclude_from_search'       => false,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			/* translators: %s: count */
			'label_count'               => _n_noop( '運送中 <span class="count">(%s)</span>', '運送中 <span class="count">(%s)</span>', 'ys-paynow-shipping' ),
		) );

		register_post_status( self::SHIPPING_ARRIVED, array(
			'label'                     => _x( '已到達取貨商店', 'Order status', 'ys-paynow-shipping' ),
			'public'                    => true,
			'exclude_from_search'       => false,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			/* translators: %s: count */
			'label_count'               => _n_noop( '已到達取貨商店 <span class="count">(%s)</span>', '已到達取貨商店 <span class="count">(%s)</span>', 'ys-paynow-shipping' ),
		) );

		register_post_status( self::SHIPPING_RETURNED, array(
			'label'                     => _x( '逾時退回', 'Order status', 'ys-paynow-shipping' ),
			'public'                    => true,
			'exclude_from_search'       => false,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			/* translators: %s: count */
			'label_count'               => _n_noop( '逾時退回 <span class="count">(%s)</span>', '逾時退回 <span class="count">(%s)</span>', 'ys-paynow-shipping' ),
		) );
	}

	/**
	 * 新增至訂單狀態列表
	 *
	 * @param array $order_statuses 原有訂單狀態.
	 * @return array 更新後的訂單狀態.
	 */
	public static function add_order_statuses( $order_statuses ) {
		$new_order_statuses = array();

		// 將新狀態插入在 'wc-processing' 之後
		foreach ( $order_statuses as $key => $status ) {
			$new_order_statuses[ $key ] = $status;

			if ( 'wc-processing' === $key ) {
				$new_order_statuses[ self::SHIPPING_ORDERED ] = _x( '已安排出貨', 'Order status', 'ys-paynow-shipping' );
				$new_order_statuses[ self::SHIPPING_TRANSIT ] = _x( '運送中', 'Order status', 'ys-paynow-shipping' );
				$new_order_statuses[ self::SHIPPING_ARRIVED ] = _x( '已到達取貨商店', 'Order status', 'ys-paynow-shipping' );
				$new_order_statuses[ self::SHIPPING_RETURNED ] = _x( '逾時退回', 'Order status', 'ys-paynow-shipping' );
			}
		}

		return $new_order_statuses;
	}

	/**
	 * 載入後台狀態樣式
	 * 使用莫蘭迪配色風格
	 */
	public static function enqueue_admin_styles() {
		$screen = get_current_screen();

		// 只在訂單相關頁面載入
		$allowed_screens = array(
			'edit-shop_order',
			'woocommerce_page_wc-orders',
			'shop_order',
		);

		if ( ! $screen || ! in_array( $screen->id, $allowed_screens, true ) ) {
			return;
		}

		// 註冊一個空的 CSS handle，然後附加 inline style
		wp_register_style( 'ys-paynow-order-status', false );
		wp_enqueue_style( 'ys-paynow-order-status' );

		$css = "
			/* YS PayNow 自訂訂單狀態標籤顏色 - 莫蘭迪配色 */
			.order-status.status-shipping-ordered {
				background: #e8eff5 !important;
				color: #6b8a9a !important;
			}
			.order-status.status-shipping-transit {
				background: #fef6e8 !important;
				color: #b8860b !important;
			}
			.order-status.status-shipping-arrived {
				background: #f3e5f5 !important;
				color: #7b1fa2 !important;
			}
			.order-status.status-shipping-returned {
				background: #ffebee !important;
				color: #c62828 !important;
			}
			.order-status.status-shipping-ordered,
			.order-status.status-shipping-transit,
			.order-status.status-shipping-arrived,
			.order-status.status-shipping-returned {
				font-weight: 500 !important;
				border-radius: 4px !important;
			}
		";

		wp_add_inline_style( 'ys-paynow-order-status', $css );
	}
}
