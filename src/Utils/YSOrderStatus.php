<?php
/**
 * YS Order Status Class
 *
 * 定義與註冊自訂訂單狀態
 *
 * @package YangSheep\PayNow\Shipping\Utils
 * @since   1.0.0
 */

namespace YangSheep\PayNow\Shipping\Utils;

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
	 */
	public static function enqueue_admin_styles() {
		// 這裡使用內聯樣式以簡化流程，未來可移至獨立 CSS 檔案
		$css = "
			.order-status.status-shipping-ordered { background: #e5e5e5; color: #555; }
			.order-status.status-shipping-transit { background: #ffba00; color: #fff; }
			.order-status.status-shipping-arrived { background: #7ad03a; color: #fff; }
			.order-status.status-shipping-returned { background: #a00; color: #fff; }
		";
		wp_add_inline_style( 'woocommerce_admin_styles', $css );
	}
}
