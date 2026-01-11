<?php
/**
 * YS Abstract Shipping Method
 *
 * 所有 YS PayNow 運送方式的抽象基底類別。
 *
 * @package YangSheep\PayNow\Shipping\Providers
 * @since   1.0.0
 */

namespace YangSheep\PayNow\Shipping\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * YSAbstractShipping 抽象類別
 *
 * 擴展 WC_Shipping_Method，提供共用功能給所有 PayNow 運送方式。
 *
 * @since 1.0.0
 */
abstract class YSAbstractShipping extends \WC_Shipping_Method {

	/**
	 * 外掛名稱
	 *
	 * @var string
	 */
	protected $plugin_name = 'ys-paynow-shipping';

	/**
	 * 外掛版本
	 *
	 * @var string
	 */
	protected $version = YS_PAYNOW_SHIPPING_VERSION;

	/**
	 * 物流服務代碼
	 *
	 * @see YSLogisticService
	 * @var string
	 */
	public $logistic_service = '';

	/**
	 * 免運費條件
	 *
	 * @var string
	 */
	public $free_shipping_requires = '';

	/**
	 * 免運費最低金額
	 *
	 * @var int
	 */
	public $free_shipping_min_amount = 0;

	/**
	 * 建構函式
	 *
	 * @param int $instance_id 運送方式實例 ID。
	 */
	public function __construct( $instance_id = 0 ) {
		$this->instance_id = absint( $instance_id );

		$this->supports = array(
			'shipping-zones',
			'instance-settings',
			'instance-settings-modal',
		);

		$this->init();
	}

	/**
	 * 初始化設定
	 *
	 * @return void
	 */
	protected function init() {
		// 載入表單欄位設定
		$this->init_form_fields();

		// 載入設定值
		$this->init_settings();

		// 設定屬性
		$this->enabled                  = $this->get_option( 'enabled' );
		$this->title                    = $this->get_option( 'title' );
		$this->cost                     = $this->get_option( 'cost' );
		$this->free_shipping_requires   = $this->get_option( 'free_shipping_requires' );
		$this->free_shipping_min_amount = $this->get_option( 'free_shipping_min_amount' );

		// 儲存設定時的 hook
		add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	/**
	 * 初始化表單欄位
	 *
	 * @return void
	 */
	public function init_form_fields() {
		$this->instance_form_fields = array(
			'enabled'                  => array(
				'title'   => __( '啟用', 'ys-paynow-shipping' ),
				'type'    => 'checkbox',
				'label'   => __( '啟用此運送方式', 'ys-paynow-shipping' ),
				'default' => 'yes',
			),
			'title'                    => array(
				'title'       => __( '標題', 'ys-paynow-shipping' ),
				'type'        => 'text',
				'description' => __( '顯示在結帳頁的運送方式名稱', 'ys-paynow-shipping' ),
				'default'     => $this->method_title,
				'desc_tip'    => true,
			),
			'cost'                     => array(
				'title'       => __( '運費', 'ys-paynow-shipping' ),
				'type'        => 'price',
				'description' => __( '運送費用', 'ys-paynow-shipping' ),
				'default'     => '60',
				'desc_tip'    => true,
			),
			'free_shipping_requires'   => array(
				'title'   => __( '免運費條件', 'ys-paynow-shipping' ),
				'type'    => 'select',
				'class'   => 'wc-enhanced-select',
				'default' => '',
				'options' => array(
					''           => __( '無', 'ys-paynow-shipping' ),
					'min_amount' => __( '訂單金額達到指定門檻', 'ys-paynow-shipping' ),
					'coupon'     => __( '使用免運費優惠券', 'ys-paynow-shipping' ),
					'either'     => __( '金額達門檻或使用免運費優惠券', 'ys-paynow-shipping' ),
					'both'       => __( '金額達門檻且使用免運費優惠券', 'ys-paynow-shipping' ),
				),
			),
			'free_shipping_min_amount' => array(
				'title'       => __( '免運費最低金額', 'ys-paynow-shipping' ),
				'type'        => 'price',
				'description' => __( '訂單金額達此門檻即可享有免運費', 'ys-paynow-shipping' ),
				'default'     => '0',
				'desc_tip'    => true,
			),
		);
	}

	/**
	 * 計算運費
	 *
	 * @param array $package 運送包裹資訊。
	 * @return void
	 */
	public function calculate_shipping( $package = array() ) {
		$rate = array(
			'id'      => $this->get_rate_id(),
			'label'   => $this->title,
			'cost'    => $this->get_cost(),
			'package' => $package,
		);

		$this->add_rate( $rate );
	}

	/**
	 * 取得運費（考慮免運費條件）
	 *
	 * @return float 運費金額。
	 */
	protected function get_cost() {
		// 無免運費條件
		if ( empty( $this->free_shipping_requires ) ) {
			return $this->cost;
		}

		// 檢查最低金額
		if ( 'min_amount' === $this->free_shipping_requires ) {
			return $this->has_met_min_amount() ? 0 : $this->cost;
		}

		// 檢查優惠券
		if ( 'coupon' === $this->free_shipping_requires ) {
			return $this->has_free_shipping_coupon() ? 0 : $this->cost;
		}

		// 金額或優惠券 (擇一)
		if ( 'either' === $this->free_shipping_requires ) {
			return ( $this->has_met_min_amount() || $this->has_free_shipping_coupon() ) ? 0 : $this->cost;
		}

		// 金額且優惠券 (兩者皆需)
		if ( 'both' === $this->free_shipping_requires ) {
			return ( $this->has_met_min_amount() && $this->has_free_shipping_coupon() ) ? 0 : $this->cost;
		}

		return $this->cost;
	}

	/**
	 * 檢查是否達到免運費最低金額
	 *
	 * @return bool
	 */
	private function has_met_min_amount() {
		if ( ! WC()->cart ) {
			return false;
		}

		$total = WC()->cart->get_displayed_subtotal();

		if ( WC()->cart->display_prices_including_tax() ) {
			$total -= WC()->cart->get_discount_tax();
		}

		$total = round( $total, wc_get_price_decimals() );

		return $total >= $this->free_shipping_min_amount;
	}

	/**
	 * 檢查是否有免運費優惠券
	 *
	 * @return bool
	 */
	private function has_free_shipping_coupon() {
		if ( ! WC()->cart ) {
			return false;
		}

		$coupons = WC()->cart->get_coupons();

		foreach ( $coupons as $coupon ) {
			if ( $coupon->is_valid() && $coupon->get_free_shipping() ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * 取得外掛名稱
	 *
	 * @return string
	 */
	public function get_plugin_name() {
		return $this->plugin_name;
	}

	/**
	 * 取得外掛版本
	 *
	 * @return string
	 */
	public function get_version() {
		return $this->version;
	}
}
