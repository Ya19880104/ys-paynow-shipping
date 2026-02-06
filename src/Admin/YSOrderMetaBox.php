<?php
/**
 * YS Order Meta Box
 *
 * 訂單詳情頁的 Meta Box。
 *
 * @package YangSheep\PayNow\Shipping\Admin
 * @since   1.0.0
 */

namespace YangSheep\PayNow\Shipping\Admin;

use YangSheep\PayNow\Shipping\YSPaynowShipping;
use YangSheep\PayNow\Shipping\Utils\YSOrderMeta;
use YangSheep\PayNow\Shipping\Utils\YSLogisticService;

defined( 'ABSPATH' ) || exit;

/**
 * YSOrderMetaBox 類別
 *
 * 在訂單編輯頁面顯示物流資訊的 Meta Box。
 *
 * @since 1.0.0
 */
class YSOrderMetaBox {

	/**
	 * 初始化
	 *
	 * @return void
	 */
	public static function init() {
		// 傳統訂單頁面
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );

		// HPOS 訂單頁面
		add_action( 'add_meta_boxes_woocommerce_page_wc-orders', array( __CLASS__, 'add_meta_box' ) );

		// 儲存 Meta Box 資料
		add_action( 'woocommerce_process_shop_order_meta', array( __CLASS__, 'save_meta_box' ), 10, 2 );

		// AJAX: 重選超商
		add_action( 'wp_ajax_ys_paynow_admin_change_store', array( __CLASS__, 'ajax_change_store' ) );
	}

	/**
	 * 新增 Meta Box
	 *
	 * @return void
	 */
	public static function add_meta_box() {
		$screen = wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled()
			? wc_get_page_screen_id( 'shop-order' )
			: 'shop_order';

		// 取得當前訂單 ID
		$order_id = self::get_current_order_id();
		if ( ! $order_id ) {
			return;
		}

		// 檢查是否為 YS PayNow 物流訂單
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$logistic_service_id = $order->get_meta( YSOrderMeta::LogisticServiceId );
		if ( empty( $logistic_service_id ) ) {
			// 非 YS PayNow 物流訂單，不顯示此 Meta Box
			return;
		}

		add_meta_box(
			'ys-paynow-shipping-meta-box',
			__( 'PayNow 物流資訊', 'ys-paynow-shipping' ),
			array( __CLASS__, 'render_meta_box' ),
			$screen,
			'side',
			'high'
		);
	}

	/**
	 * 取得當前訂單 ID
	 *
	 * @return int|false 訂單 ID 或 false
	 */
	private static function get_current_order_id() {
		// HPOS: 從 GET 參數取得
		if ( isset( $_GET['id'] ) ) {
			return absint( $_GET['id'] );
		}

		// 傳統: 從 post 取得
		if ( isset( $_GET['post'] ) ) {
			return absint( $_GET['post'] );
		}

		// 從全域 $post 取得
		global $post;
		if ( $post && $post->ID ) {
			return $post->ID;
		}

		return false;
	}

	/**
	 * 渲染 Meta Box
	 *
	 * @param \WP_Post|\WC_Order $post_or_order Post 或 Order 物件。
	 * @return void
	 */
	public static function render_meta_box( $post_or_order ) {
		$order = ( $post_or_order instanceof \WC_Order ) ? $post_or_order : wc_get_order( $post_or_order->ID );

		if ( ! $order ) {
			echo '<p>' . esc_html__( '無法載入訂單資料', 'ys-paynow-shipping' ) . '</p>';
			return;
		}

		// 檢查是否為 YS PayNow 物流
		$logistic_service_id = $order->get_meta( YSOrderMeta::LogisticServiceId );
		if ( empty( $logistic_service_id ) ) {
			// echo '<p>' . esc_html__( '此訂單非使用 YS PayNow 物流', 'ys-paynow-shipping' ) . '</p>';
			return;
		}

		// 取得物流資訊
		$store_id        = $order->get_meta( YSOrderMeta::StoreId );
		$store_name      = $order->get_meta( YSOrderMeta::StoreName );
		$store_addr      = $order->get_meta( YSOrderMeta::StoreAddr );
		$logistic_number = $order->get_meta( YSOrderMeta::LogisticNumber );
		$payment_no      = $order->get_meta( YSOrderMeta::PaymentNo );
		$validation_no   = $order->get_meta( YSOrderMeta::ValidationNo );
		$delivery_status = $order->get_meta( YSOrderMeta::DeliveryStatus );
		$delivery_type   = $order->get_meta( YSOrderMeta::DeliveryType );
		$status_update   = $order->get_meta( YSOrderMeta::StatusUpdateAt );
		$store_date      = $order->get_meta( YSOrderMeta::StoreDate );
		$store_time      = $order->get_meta( YSOrderMeta::StoreTime );

		wp_nonce_field( 'ys_paynow_meta_box', 'ys_paynow_meta_box_nonce' );
		?>
		<div class="ys-paynow-meta-box">
			<p>
				<strong><?php esc_html_e( '物流服務：', 'ys-paynow-shipping' ); ?></strong>
				<?php echo esc_html( YSLogisticService::get_service_name( $logistic_service_id, $delivery_type ) ); ?>
			</p>

			<?php if ( ! empty( $store_name ) ) : ?>
				<p>
					<strong><?php esc_html_e( '取貨門市：', 'ys-paynow-shipping' ); ?></strong>
					<?php echo esc_html( $store_name ); ?>
					<?php if ( ! empty( $store_id ) ) : ?>
						<small>(<?php echo esc_html( $store_id ); ?>)</small>
					<?php endif; ?>
				</p>
				<p>
					<strong><?php esc_html_e( '門市地址：', 'ys-paynow-shipping' ); ?></strong>
					<?php echo esc_html( $store_addr ); ?>
				</p>
			<?php endif; ?>

			<?php if ( ! empty( $logistic_number ) ) : ?>
				<p>
					<strong><?php esc_html_e( '物流單號：', 'ys-paynow-shipping' ); ?></strong>
					<?php echo esc_html( $logistic_number ); ?>
				</p>
			<?php endif; ?>

			<?php if ( ! empty( $payment_no ) ) : ?>
				<p>
					<strong><?php esc_html_e( '託運單號：', 'ys-paynow-shipping' ); ?></strong>
					<?php echo esc_html( $payment_no ); ?>
					<?php if ( ! empty( $validation_no ) ) : ?>
						<br><small><?php esc_html_e( '驗證碼：', 'ys-paynow-shipping' ); ?><?php echo esc_html( $validation_no ); ?></small>
					<?php endif; ?>
				</p>
			<?php endif; ?>

			<?php if ( ! empty( $delivery_status ) ) : ?>
				<p>
					<strong><?php esc_html_e( '物流狀態：', 'ys-paynow-shipping' ); ?></strong>
					<?php echo esc_html( $delivery_status ); ?>
					<?php if ( ! empty( $status_update ) ) : ?>
						<br><small><?php esc_html_e( '更新時間：', 'ys-paynow-shipping' ); ?><?php echo esc_html( $status_update ); ?></small>
					<?php endif; ?>
				</p>
			<?php endif; ?>

			<?php if ( ! empty( $store_date ) || ! empty( $store_time ) ) : ?>
				<p>
					<strong><?php esc_html_e( '到店資訊：', 'ys-paynow-shipping' ); ?></strong>
					<?php if ( ! empty( $store_date ) ) : ?>
						<?php echo esc_html( $store_date ); ?>
					<?php endif; ?>
					<?php if ( ! empty( $store_time ) ) : ?>
						<?php echo esc_html( $store_time ); ?>
					<?php endif; ?>
				</p>
			<?php endif; ?>

			<hr>

			<?php if ( ! empty( $logistic_number ) ) : ?>
				<div class="ys-paynow-actions-grid">
					<button type="button" class="button ys-paynow-query-status" data-order-id="<?php echo esc_attr( $order->get_id() ); ?>">
						<span class="dashicons dashicons-search" style="vertical-align: middle;"></span>
						<?php esc_html_e( '查詢狀態', 'ys-paynow-shipping' ); ?>
					</button>
					<button type="button" class="button ys-paynow-cancel-order" data-order-id="<?php echo esc_attr( $order->get_id() ); ?>">
						<span class="dashicons dashicons-no-alt" style="vertical-align: middle;"></span>
						<?php esc_html_e( '取消物流', 'ys-paynow-shipping' ); ?>
					</button>
					<button type="button" class="button ys-paynow-reissue-order" data-order-id="<?php echo esc_attr( $order->get_id() ); ?>">
						<span class="dashicons dashicons-update" style="vertical-align: middle;"></span>
						<?php esc_html_e( '重新取號', 'ys-paynow-shipping' ); ?>
					</button>
					<a href="#" class="button button-primary ys-paynow-print-btn" data-url="<?php echo esc_url( admin_url( 'admin-ajax.php?action=ys_paynow_print_label&order_ids=' . $order->get_id() . '&service=' . $logistic_service_id ) ); ?>">
						<span class="dashicons dashicons-printer" style="vertical-align: middle;"></span>
						<?php esc_html_e( '列印標籤', 'ys-paynow-shipping' ); ?>
					</a>
				</div>
			<?php else : ?>
				<div class="ys-paynow-actions-grid">
					<p class="description" style="grid-column: 1 / -1; margin: 0;">
						<?php esc_html_e( '物流單尚未建立。', 'ys-paynow-shipping' ); ?>
					</p>
					<button type="button" class="button button-primary ys-paynow-manual-create" data-order-id="<?php echo esc_attr( $order->get_id() ); ?>">
						<span class="dashicons dashicons-plus-alt" style="vertical-align: middle;"></span>
						<?php esc_html_e( '手動取號', 'ys-paynow-shipping' ); ?>
					</button>
				</div>
			<?php endif; ?>

			<?php if ( YSPaynowShipping::needs_cvs( self::get_shipping_method_id( $order ) ) ) : ?>
				<div class="ys-paynow-actions-full">
					<button type="button" class="button ys-paynow-change-store" data-order-id="<?php echo esc_attr( $order->get_id() ); ?>" data-logistic-service="<?php echo esc_attr( $logistic_service_id ); ?>">
						<span class="dashicons dashicons-store" style="vertical-align: middle;"></span>
						<?php esc_html_e( '重選超商', 'ys-paynow-shipping' ); ?>
					</button>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * 儲存 Meta Box 資料
	 *
	 * @param int       $order_id 訂單 ID。
	 * @param \WC_Order $order    訂單物件。
	 * @return void
	 */
	public static function save_meta_box( $order_id, $order = null ) {
		if ( ! isset( $_POST['ys_paynow_meta_box_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ys_paynow_meta_box_nonce'] ) ), 'ys_paynow_meta_box' ) ) {
			return;
		}

		// 目前無需在此處儲存額外資料
	}

	/**
	 * AJAX: 重選超商
	 *
	 * @return void
	 */
	public static function ajax_change_store() {
		check_ajax_referer( 'ys-paynow-shipping-admin', 'nonce' );

		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_send_json_error( __( '權限不足', 'ys-paynow-shipping' ) );
		}

		$order_id   = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$store_id   = isset( $_POST['store_id'] ) ? sanitize_text_field( wp_unslash( $_POST['store_id'] ) ) : '';
		$store_name = isset( $_POST['store_name'] ) ? sanitize_text_field( wp_unslash( $_POST['store_name'] ) ) : '';
		$store_addr = isset( $_POST['store_addr'] ) ? sanitize_text_field( wp_unslash( $_POST['store_addr'] ) ) : '';
		$new_service_id = isset( $_POST['logistic_service'] ) ? sanitize_text_field( wp_unslash( $_POST['logistic_service'] ) ) : '';

		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			wp_send_json_error( __( '訂單不存在', 'ys-paynow-shipping' ) );
		}

		// 處理物流服務變更
		if ( ! empty( $new_service_id ) ) {
			$current_service_id = $order->get_meta( YSOrderMeta::LogisticServiceId );
			if ( $new_service_id !== $current_service_id ) {
				$order->update_meta_data( YSOrderMeta::LogisticServiceId, $new_service_id );
				
				// 更新訂單項目名稱 (顯示用)
				$new_delivery_type = $order->get_meta( YSOrderMeta::DeliveryType );
				$new_service_name = \YangSheep\PayNow\Shipping\Utils\YSLogisticService::get_service_name( $new_service_id, $new_delivery_type );
				$shipping_items = $order->get_items( 'shipping' );
				foreach ( $shipping_items as $item ) {
					// 假設通常只有一個運送方式，直接更新名稱
					$item->set_name( 'PayNow ' . $new_service_name );
					$item->save();
					break; 
				}
				
				$order->add_order_note( sprintf( __( '物流服務已變更為：%s', 'ys-paynow-shipping' ), $new_service_name ) );
			}
		}

		// 更新超商資訊 (HPOS 相容)
		$order->update_meta_data( YSOrderMeta::StoreId, $store_id );
		$order->update_meta_data( YSOrderMeta::StoreName, $store_name );
		$order->update_meta_data( YSOrderMeta::StoreAddr, $store_addr );
		$order->save();

		/* translators: %s: Store name */
		$order->add_order_note( sprintf( __( '重新選擇超商：%s', 'ys-paynow-shipping' ), $store_name ) );

		// 觸發重建物流單
		do_action( 'ys_paynow_after_admin_changed_store', $order );

		wp_send_json_success( array(
			'message'    => __( '超商已更新', 'ys-paynow-shipping' ),
			'store_name' => $store_name,
			'store_addr' => $store_addr,
		) );
	}

	/**
	 * 取得訂單的運送方式 ID
	 *
	 * @param \WC_Order $order 訂單物件。
	 * @return string 運送方式 ID。
	 */
	private static function get_shipping_method_id( $order ) {
		$shipping_methods = $order->get_shipping_methods();
		if ( empty( $shipping_methods ) ) {
			return '';
		}

		$shipping_method = reset( $shipping_methods );
		return $shipping_method->get_method_id();
	}
}
