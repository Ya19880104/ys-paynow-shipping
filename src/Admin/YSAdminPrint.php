<?php
/**
 * YS Admin Print Class
 *
 * 處理後台列印相關功能 (單筆快速列印)。
 * 批次列印功能已移至 YSOrderListTable 的自訂按鈕列。
 *
 * @package YangSheep\PayNow\Shipping\Admin
 * @since   1.0.0
 */

namespace YangSheep\PayNow\Shipping\Admin;

use YangSheep\PayNow\Shipping\Utils\YSOrderMeta;
use YangSheep\PayNow\Shipping\Utils\YSLogisticService;
use YangSheep\PayNow\Shipping\YSPaynowShipping;

defined( 'ABSPATH' ) || exit;

class YSAdminPrint {

	/**
	 * 初始化
	 */
	public static function init() {
		// 單筆訂單快速列印按鈕
		add_filter( 'woocommerce_admin_order_actions', array( __CLASS__, 'add_quick_print_action' ), 10, 2 );
	}

	/**
	 * 新增快速列印按鈕
	 *
	 * @param array     $actions 動作陣列。
	 * @param \WC_Order $order   訂單物件。
	 * @return array 修改後的動作陣列。
	 */
	public static function add_quick_print_action( $actions, $order ) {
		$logistic_number = $order->get_meta( YSOrderMeta::LogisticNumber );
		$service_id      = $order->get_meta( YSOrderMeta::LogisticServiceId );

		if ( empty( $logistic_number ) || empty( $service_id ) ) {
			return $actions;
		}

		// 定義列印 URL
		$print_url = admin_url( 'admin-ajax.php?action=ys_paynow_print_label&order_ids=' . $order->get_id() . '&service=' . $service_id );

		// 判斷按鈕圖示與文字
		$name = __( '列印', 'ys-paynow-shipping' );
		if ( strpos( $service_id, '01' ) === 0 || strpos( $service_id, '02' ) === 0 || strpos( $service_id, '2' ) === 0 ) {
			$name = '7-11 列印';
		} elseif ( strpos( $service_id, '03' ) === 0 || strpos( $service_id, '04' ) === 0 ) {
			$name = '全家列印';
		} elseif ( '36' === $service_id || '06' === $service_id ) {
			$name = '黑貓列印';
		}

		$actions['ys_quick_print'] = array(
			'url'    => $print_url,
			'name'   => $name,
			'action' => 'view ys-print-btn', // CSS class
			'target' => '_blank', // 新視窗開啟
		);

		return $actions;
	}
}
