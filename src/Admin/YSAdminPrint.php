<?php
/**
 * YS Admin Print Class
 *
 * 處理後台列印相關功能 (批次列印、快速列印)。
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
		// 批次動作 - 傳統 Post ID
		add_filter( 'bulk_actions-edit-shop_order', array( __CLASS__, 'add_bulk_actions' ) );
		add_filter( 'handle_bulk_actions-edit-shop_order', array( __CLASS__, 'handle_bulk_actions' ), 10, 3 );
		
		// 批次動作 - HPOS
		add_filter( 'bulk_actions_woocommerce_page_wc-orders', array( __CLASS__, 'add_bulk_actions' ) );
		add_filter( 'handle_bulk_actions-woocommerce_page_wc-orders', array( __CLASS__, 'handle_bulk_actions' ), 10, 3 );

		// 快速列印按鈕
		add_filter( 'woocommerce_admin_order_actions', array( __CLASS__, 'add_quick_print_action' ), 10, 2 );

		// JS 處理批次列印彈窗
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_print_scripts' ) );
	}

	/**
	 * 新增批次動作
	 *
	 * @param array $actions 現有動作。
	 * @return array 修改後的動作。
	 */
	public static function add_bulk_actions( $actions ) {
		$actions['ys_print_tcat']   = __( '批次 PayNow 黑貓列印', 'ys-paynow-shipping' );
		$actions['ys_print_seven']  = __( '批次 PayNow 7-11 列印', 'ys-paynow-shipping' );
		$actions['ys_print_family'] = __( '批次 PayNow 全家列印', 'ys-paynow-shipping' );
		$actions['ys_print_hilife'] = __( '批次 PayNow 萊爾富列印', 'ys-paynow-shipping' );
		return $actions;
	}

	/**
	 * 處理批次動作
	 *
	 * @param string $redirect_to 重導向 URL。
	 * @param string $action      動作名稱。
	 * @param array  $post_ids    Post ID 陣列。
	 * @return string 修改後的重導向 URL。
	 */
	public static function handle_bulk_actions( $redirect_to, $action, $post_ids ) {
		$service = '';

		switch ( $action ) {
			case 'ys_print_tcat':
				$service = YSLogisticService::TCAT; // 或 TCAT_OWN，稍後會自動檢查設定
				// 這裡先傳遞 generic TCAT，讓 Request 端判斷
				break;
			case 'ys_print_seven':
				$service = YSLogisticService::SEVEN;
				break;
			case 'ys_print_family':
				$service = YSLogisticService::FAMI;
				break;
			case 'ys_print_hilife':
				$service = YSLogisticService::HILIFE;
				break;
			default:
				return $redirect_to;
		}

		// 這裡做一個簡單修正：如果是使用自有代號，Request 端判斷比較好
		// 若要在這裡判斷，需讀取設定。這裡統一傳遞，由 AJAX 端處理細節 mapping。
		// 但注意 AJAX 端(get_print_label_url) 是根據 passed service 來決定 endpoint。
		// 對於 TCAT_OWN，它使用 TCAT endpoint，所以傳 TCAT 即可。
		// 對於 CVS，我們需要精確的 Service ID 嗎？
		// 7-11 包含 SEVEN (01), SEVENBULK (02), SEVENFROZEN...
		// PayNow 的 Batch Print API 通常是針對 "類型" (Order711, OrderFamiC2C)。
		// CVS 的批次列印是否支援混合 C2C/B2C?
		// 根據 YSShippingRequest::get_print_label_url:
		// Order711, OrderFamiC2C, OrderHiLife.
		// 它們似乎主要是 C2C。
		// 若要支援 B2C 批次列印，可能需要不同的 API 或參數。
		// 暫時假設這些 Batch Action 僅支援 C2C，或者 PayNow API 能自動識別。

		if ( ! empty( $service ) ) {
			// 將訂單 ID 傳遞給前端，由 JS 開啟視窗
			$redirect_to = add_query_arg(
				array(
					'ys_printing'   => '1',
					'ys_service'    => $service,
					'ys_order_ids'  => implode( ',', $post_ids ),
				),
				$redirect_to
			);
		}

		return $redirect_to;
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

	/**
	 * 載入列印相關腳本
	 */
	public static function enqueue_print_scripts() {
		// 檢查是否有批次列印參數
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['ys_printing'] ) || '1' !== $_GET['ys_printing'] ) {
			return;
		}

		$service   = isset( $_GET['ys_service'] ) ? sanitize_text_field( wp_unslash( $_GET['ys_service'] ) ) : '';
		$order_ids = isset( $_GET['ys_order_ids'] ) ? sanitize_text_field( wp_unslash( $_GET['ys_order_ids'] ) ) : '';

		if ( empty( $service ) || empty( $order_ids ) ) {
			return;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		// 產生列印 URL
		$print_url = admin_url( 'admin-ajax.php?action=ys_paynow_print_label&order_ids=' . $order_ids . '&service=' . $service );

		// 輸出 JS 自動開啟視窗
		?>
		<script type="text/javascript">
			jQuery(document).ready(function($) {
				window.open('<?php echo esc_url_raw( $print_url ); ?>', '_blank');
			});
		</script>
		<?php
	}
}
