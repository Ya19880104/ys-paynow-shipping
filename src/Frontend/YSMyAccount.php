<?php
/**
 * YS My Account
 *
 * 在 My Account 訂單列表中顯示物流狀態。
 *
 * @package YangSheep\PayNow\Shipping\Frontend
 * @since   1.0.0
 */

namespace YangSheep\PayNow\Shipping\Frontend;

use YangSheep\PayNow\Shipping\Utils\YSOrderMeta;
use YangSheep\PayNow\Shipping\Utils\YSLogisticService;

defined( 'ABSPATH' ) || exit;

/**
 * YSMyAccount 類別
 *
 * 在前台 My Account 頁面顯示物流資訊。
 *
 * @since 1.0.0
 */
class YSMyAccount {

	/**
	 * 初始化
	 *
	 * @return void
	 */
	public static function init() {
		// 在訂單列表添加物流狀態欄
		add_filter( 'woocommerce_my_account_my_orders_columns', array( __CLASS__, 'add_shipping_status_column' ) );
		add_action( 'woocommerce_my_account_my_orders_column_ys_shipping_status', array( __CLASS__, 'render_shipping_status_column' ) );


	}

	/**
	 * 添加物流狀態欄
	 *
	 * @param array $columns 現有欄位。
	 * @return array 修改後的欄位。
	 */
	public static function add_shipping_status_column( $columns ) {
		// 如果已啟用 YangSheep Checkout Optimizer 的訂單強化功能，則不顯示原本的欄位
		if ( 'yes' === get_option( 'yangsheep_enable_order_enhancement', 'no' ) ) {
			return $columns;
		}

		$new_columns = array();

		foreach ( $columns as $key => $value ) {
			$new_columns[ $key ] = $value;

			// 在訂單狀態後插入物流狀態
			if ( 'order-status' === $key ) {
				$new_columns['ys_shipping_status'] = __( '物流狀態', 'ys-paynow-shipping' );
			}
		}

		return $new_columns;
	}

	/**
	 * 渲染物流狀態欄內容
	 *
	 * @param \WC_Order $order 訂單物件。
	 * @return void
	 */
	public static function render_shipping_status_column( $order ) {
		$logistic_service_id = $order->get_meta( YSOrderMeta::LogisticServiceId );

		if ( empty( $logistic_service_id ) ) {
			echo '<span class="ys-status-na">–</span>';
			return;
		}

		$logistic_number = $order->get_meta( YSOrderMeta::LogisticNumber );
		$delivery_status = $order->get_meta( YSOrderMeta::DeliveryStatus );
		$service_name    = YSLogisticService::get_service_name( $logistic_service_id );

		echo '<div class="ys-shipping-status-cell">';
		echo '<small style="color: #666;">' . esc_html( $service_name ) . '</small><br>';

		if ( ! empty( $logistic_number ) ) {
			if ( ! empty( $delivery_status ) ) {
				$status_class = self::get_status_class( $delivery_status );
				echo '<span class="ys-status-badge ' . esc_attr( $status_class ) . '">' . esc_html( $delivery_status ) . '</span>';
			} else {
				echo '<span class="ys-status-badge ys-status-created">' . esc_html__( '已建立', 'ys-paynow-shipping' ) . '</span>';
			}

		} else {
			echo '<span class="ys-status-badge ys-status-pending">' . esc_html__( '待建立', 'ys-paynow-shipping' ) . '</span>';
		}

		echo '</div>';
	}

	/**
	 * 取得物流狀態的 CSS class
	 *
	 * @param string $status 物流狀態文字。
	 * @return string CSS class 名稱。
	 */
	private static function get_status_class( $status ) {
		if ( strpos( $status, '等待' ) !== false || strpos( $status, '待' ) !== false ) {
			return 'ys-status-waiting';
		} elseif ( strpos( $status, '已建立' ) !== false || strpos( $status, '建立' ) !== false ) {
			return 'ys-status-created';
		} elseif ( strpos( $status, '配送中' ) !== false || strpos( $status, '寄件' ) !== false || strpos( $status, '出貨' ) !== false ) {
			return 'ys-status-shipping';
		} elseif ( strpos( $status, '到店' ) !== false || strpos( $status, '取貨' ) !== false ) {
			return 'ys-status-arrived';
		} elseif ( strpos( $status, '完成' ) !== false || strpos( $status, '已取' ) !== false ) {
			return 'ys-status-completed';
		} elseif ( strpos( $status, '退' ) !== false || strpos( $status, '取消' ) !== false ) {
			return 'ys-status-cancelled';
		}
		return 'ys-status-default';
	}

	/**
	 * AJAX: 前台用戶查詢物流狀態
	 *
	 * @return void
	 */
	public static function ajax_user_query_status() {
		check_ajax_referer( 'ys-paynow-frontend', 'nonce' );

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$order    = wc_get_order( $order_id );

		if ( ! $order ) {
			wp_send_json_error( __( '訂單不存在', 'ys-paynow-shipping' ) );
		}

		// 確認用戶有權限查看此訂單
		if ( get_current_user_id() !== $order->get_customer_id() && ! current_user_can( 'edit_shop_orders' ) ) {
			wp_send_json_error( __( '權限不足', 'ys-paynow-shipping' ) );
		}

		$delivery_status = $order->get_meta( YSOrderMeta::DeliveryStatus );
		$logistic_number = $order->get_meta( YSOrderMeta::LogisticNumber );

		wp_send_json_success( array(
			'status'          => $delivery_status,
			'logistic_number' => $logistic_number,
		) );
	}


}
