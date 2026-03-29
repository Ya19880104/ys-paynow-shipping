<?php
/**
 * YS PayNow COD Gateway
 *
 * PayNow 貨到付款金流。
 * 僅在選擇 PayNow 物流時顯示。
 *
 * @package yangsheep\paynow\shipping\gateways
 * @since   1.0.0
 */

namespace yangsheep\paynow\shipping\gateways;

use yangsheep\paynow\shipping\YSPaynowShipping;

defined( 'ABSPATH' ) || exit;

class YSGatewayPaynowCOD extends \WC_Payment_Gateway {

	/**
	 * 建構子
	 */
	public function __construct() {
		$this->id                 = 'ys_paynow_cod';
		$this->icon               = ''; // 可選：設定圖示
		$this->has_fields         = false;
		$this->method_title       = __( 'PayNow 貨到付款', 'ys-paynow-shipping' );
		$this->method_description = __( '僅限配合 PayNow 物流使用的貨到付款方式。', 'ys-paynow-shipping' );

		// 載入設定
		$this->init_form_fields();
		$this->init_settings();

		// 設定變數
		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );

		// 儲存設定 Hook
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	/**
	 * 初始化設定欄位
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled' => array(
				'title'   => __( '啟用/停用', 'ys-paynow-shipping' ),
				'type'    => 'checkbox',
				'label'   => __( '啟用 PayNow 貨到付款', 'ys-paynow-shipping' ),
				'default' => 'yes',
			),
			'title'   => array(
				'title'       => __( '標題', 'ys-paynow-shipping' ),
				'type'        => 'text',
				'description' => __( '結帳時顯示的付款方式名稱。', 'ys-paynow-shipping' ),
				'default'     => __( 'PayNow 貨到付款', 'ys-paynow-shipping' ),
				'desc_tip'    => true,
			),
			'description' => array(
				'title'       => __( '描述', 'ys-paynow-shipping' ),
				'type'        => 'textarea',
				'description' => __( '結帳時顯示的付款方式描述。', 'ys-paynow-shipping' ),
				'default'     => __( '貨到付款。', 'ys-paynow-shipping' ),
				'desc_tip'    => true,
			),
		);
	}

	/**
	 * 檢查是否可用
	 *
	 * 僅在選擇 PayNow 物流時可用。
	 *
	 * @return bool
	 */
	public function is_available() {
		// 基本檢查 (是否啟用、是否有貨幣支援等)
		if ( ! parent::is_available() ) {
			return false;
		}

		if ( is_admin() ) {
			return true;
		}

		// 檢查選擇的運送方式
		$chosen_shipping_methods = WC()->session ? WC()->session->get( 'chosen_shipping_methods' ) : array();
		
		if ( empty( $chosen_shipping_methods ) ) {
			return false;
		}

		$shipping_method = $chosen_shipping_methods[0];
		$method_id       = explode( ':', $shipping_method )[0];

		// 檢查是否為 YS PayNow 物流
		// 假設所有 id 以 ys_paynow_shipping 開頭的都是
		return strpos( $method_id, 'ys_paynow_shipping' ) === 0;
	}

	/**
	 * 處理付款
	 *
	 * @param int $order_id 訂單 ID。
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		// 標記為保留 (等待貨到付款)
		// PayNow API 參數 DeliverMode 需設為 01 (取貨付款)，這在 YSShippingRequest 中處理，
		// 這裡只需確保訂單狀態正確。通常 COD 訂單建立後為 Processing。
		
		$order->update_status( 'processing', __( '等待貨到付款。', 'ys-paynow-shipping' ) );

		// 減少庫存
		wc_reduce_stock_levels( $order_id );

		// 清空購物車
		WC()->cart->empty_cart();

		// 回傳跳轉網址
		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}
}
