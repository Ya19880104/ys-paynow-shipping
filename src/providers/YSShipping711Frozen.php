<?php
/**
 * YS Shipping 7-11 Frozen C2C
 *
 * 7-11 冷凍交貨便 (C2C)。
 *
 * @package yangsheep\paynow\shipping\providers
 * @since   1.0.0
 */

namespace yangsheep\paynow\shipping\providers;

use yangsheep\paynow\shipping\utils\YSLogisticService;

defined( 'ABSPATH' ) || exit;

class YSShipping711Frozen extends YSAbstractShipping {

	public function __construct( $instance_id = 0 ) {
		$this->id                 = 'ys_paynow_shipping_711_frozen';
		$this->method_title       = __( 'PayNow 7-11 冷凍交貨便 (C2C)', 'ys-paynow-shipping' );
		$this->method_description = __( '使用 PayNow 物流服務，提供 7-11 冷凍交貨便 (C2C)。', 'ys-paynow-shipping' );
		$this->logistic_service   = YSLogisticService::SEVENFROZEN_C2C;

		parent::__construct( $instance_id );
	}

	public function is_available( $package ) {
		if ( 'no' === get_option( 'ys_paynow_shipping_enable_711_frozen_c2c', 'no' ) ) {
			return false;
		}
		return parent::is_available( $package );
	}
}
