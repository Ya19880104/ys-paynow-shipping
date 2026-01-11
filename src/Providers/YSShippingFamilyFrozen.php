<?php
/**
 * YS Shipping Family Frozen C2C
 *
 * 全家 冷凍店到店 (C2C)。
 *
 * @package YangSheep\PayNow\Shipping\Providers
 * @since   1.0.0
 */

namespace YangSheep\PayNow\Shipping\Providers;

use YangSheep\PayNow\Shipping\Utils\YSLogisticService;

defined( 'ABSPATH' ) || exit;

class YSShippingFamilyFrozen extends YSAbstractShipping {

	public function __construct( $instance_id = 0 ) {
		$this->id                 = 'ys_paynow_shipping_family_frozen';
		$this->method_title       = __( 'PayNow 全家冷凍店到店 (C2C)', 'ys-paynow-shipping' );
		$this->method_description = __( '使用 PayNow 物流服務，提供 全家冷凍店到店 (C2C)。', 'ys-paynow-shipping' );
		$this->logistic_service   = YSLogisticService::FAMIFROZEN_C2C;

		parent::__construct( $instance_id );
	}

	public function is_available( $package ) {
		if ( 'no' === get_option( 'ys_paynow_shipping_enable_family_frozen_c2c', 'no' ) ) {
			return false;
		}
		return parent::is_available( $package );
	}
}
