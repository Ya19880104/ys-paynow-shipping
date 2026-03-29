<?php
/**
 * YS Order List Table
 *
 * 訂單列表頁的批次操作功能。
 *
 * @package yangsheep\paynow\shipping\admin
 * @since   1.0.0
 */

namespace yangsheep\paynow\shipping\admin;

use Automattic\WooCommerce\Utilities\OrderUtil;
use yangsheep\paynow\shipping\YSPaynowShipping;
use yangsheep\paynow\shipping\utils\YSOrderMeta;
use yangsheep\paynow\shipping\utils\YSLogisticService;

defined( 'ABSPATH' ) || exit;

/**
 * YSOrderListTable 類別
 *
 * 在訂單列表頁面添加批次列印標籤等功能。
 *
 * @since 1.0.0
 */
class YSOrderListTable {

	/**
	 * 初始化
	 *
	 * @return void
	 */
	public static function init() {
		// 新增訂單列表欄位
		add_filter( 'manage_edit-shop_order_columns', array( __CLASS__, 'add_order_columns' ), 20 );
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( __CLASS__, 'add_order_columns' ), 20 );

		// 填充訂單列表欄位內容
		add_action( 'manage_shop_order_posts_custom_column', array( __CLASS__, 'render_order_column' ), 10, 2 );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( __CLASS__, 'render_order_column' ), 10, 2 );

		// 新增批次列印按鈕
		add_action( 'admin_footer', array( __CLASS__, 'add_batch_print_buttons_script' ) );

		// AJAX: 取得未列印訂單
		add_action( 'wp_ajax_ys_paynow_get_unprinted_orders', array( __CLASS__, 'ajax_get_unprinted_orders' ) );

	}

	/**
	 * 新增訂單列表欄位
	 *
	 * @param array $columns 現有的欄位。
	 * @return array 修改後的欄位。
	 */
	public static function add_order_columns( $columns ) {
		// 檢查是否啟用訂單物流欄位顯示
		if ( 'yes' !== get_option( 'ys_paynow_shipping_show_order_column', 'yes' ) ) {
			return $columns;
		}

		// 如果 checkout-optimizer 外掛已啟用訂單增強功能，則不添加此欄位（避免重複）
		if ( self::is_checkout_optimizer_order_enhancement_enabled() ) {
			return $columns;
		}

		$new_columns = array();

		foreach ( $columns as $key => $value ) {
			$new_columns[ $key ] = $value;

			// 在訂單狀態欄位後插入物流欄位
			if ( 'order_status' === $key ) {
				$new_columns['ys_paynow_shipping'] = __( 'PayNow 物流', 'ys-paynow-shipping' );
			}
		}

		return $new_columns;
	}

	/**
	 * 檢查 checkout-optimizer 外掛是否啟用訂單增強功能
	 *
	 * @return bool
	 */
	private static function is_checkout_optimizer_order_enhancement_enabled() {
		// 檢查外掛是否存在並啟用
		if ( ! class_exists( 'YANGSHEEP_Checkout_Order_Enhancer' ) ) {
			return false;
		}

		// 使用 YSSettingsManager 讀取設定（支援自訂資料表）
		if ( class_exists( 'YangSheep\CheckoutOptimizer\Settings\YSSettingsManager' ) ) {
			return \YangSheep\CheckoutOptimizer\Settings\YSSettingsManager::get( 'yangsheep_enable_order_enhancement', 'no' ) === 'yes';
		}

		// Fallback: 檢查 wp_options
		return get_option( 'yangsheep_enable_order_enhancement', 'no' ) === 'yes';
	}

	/**
	 * 渲染訂單列表欄位內容
	 *
	 * @param string      $column   欄位名稱。
	 * @param int|object  $post_or_order_id Post ID 或 Order 物件。
	 * @return void
	 */
	public static function render_order_column( $column, $post_or_order_id ) {
		if ( 'ys_paynow_shipping' !== $column ) {
			return;
		}

		// 如果 checkout-optimizer 外掛已啟用訂單增強功能，則不渲染（由該外掛處理）
		if ( self::is_checkout_optimizer_order_enhancement_enabled() ) {
			return;
		}

		$order = is_object( $post_or_order_id ) ? $post_or_order_id : wc_get_order( $post_or_order_id );

		if ( ! $order ) {
			return;
		}

		$logistic_service_id = $order->get_meta( YSOrderMeta::LogisticServiceId );

		if ( empty( $logistic_service_id ) ) {
			echo '<span class="na">–</span>';
			return;
		}

		$logistic_number = $order->get_meta( YSOrderMeta::LogisticNumber );
		$delivery_status = $order->get_meta( YSOrderMeta::DeliveryStatus );
		$delivery_type   = $order->get_meta( YSOrderMeta::DeliveryType );
		$service_name    = YSLogisticService::get_service_name( $logistic_service_id, $delivery_type );

		echo '<div class="ys-paynow-shipping-info">';
		echo '<strong>' . esc_html( $service_name ) . '</strong><br>';

		if ( ! empty( $logistic_number ) ) {
			echo '<code style="font-size: 11px; background: #f0f0f1; padding: 2px 4px;">' . esc_html( $logistic_number ) . '</code><br>';
			
			// 顯示狀態標籤
			if ( ! empty( $delivery_status ) ) {
				$status_class = self::get_status_class( $delivery_status );
				echo '<span class="ys-status-badge ' . esc_attr( $status_class ) . '">' . esc_html( $delivery_status ) . '</span>';
			} else {
				echo '<span class="ys-status-badge ys-status-created">' . esc_html__( '已建立', 'ys-paynow-shipping' ) . '</span>';
			}
			
			// 顯示已列印標籤
			$is_printed = 'yes' === $order->get_meta( '_ys_label_printed' );
			if ( $is_printed ) {
				echo '<span class="ys-printed-badge" style="display:inline-block; margin-left:4px; font-size:10px; color:#007cba;">' . esc_html__( '(已列印)', 'ys-paynow-shipping' ) . '</span>';
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
		// 根據狀態關鍵字判斷顏色
		if ( strpos( $status, '等待' ) !== false || strpos( $status, '待' ) !== false ) {
			return 'ys-status-waiting'; // 綠色
		} elseif ( strpos( $status, '已建立' ) !== false || strpos( $status, '建立' ) !== false ) {
			return 'ys-status-created'; // 藍色
		} elseif ( strpos( $status, '配送中' ) !== false || strpos( $status, '寄件' ) !== false || strpos( $status, '出貨' ) !== false || strpos( $status, '運送' ) !== false || strpos( $status, '集貨' ) !== false || strpos( $status, '暫置' ) !== false || strpos( $status, '轉運' ) !== false || strpos( $status, '理貨' ) !== false ) {
			return 'ys-status-shipping'; // 橙色
		} elseif ( strpos( $status, '到店' ) !== false || strpos( $status, '取貨' ) !== false ) {
			return 'ys-status-arrived'; // 紫色
		} elseif ( strpos( $status, '完成' ) !== false || strpos( $status, '已取' ) !== false ) {
			return 'ys-status-completed'; // 綠色深
		} elseif ( strpos( $status, '退' ) !== false || strpos( $status, '取消' ) !== false ) {
			return 'ys-status-cancelled'; // 紅色
		}
		return 'ys-status-default';
	}

	/**
	 * 在頁尾加入批次列印按鈕與 Modal HTML/JS
	 *
	 * @return void
	 */
	public static function add_batch_print_buttons_script() {
		$screen = get_current_screen();

		// 使用 screen ID 檢查是否為訂單列表頁面
		$allowed_screens = array(
			'edit-shop_order',            // 傳統訂單列表
			'woocommerce_page_wc-orders', // HPOS 訂單列表
		);

		if ( ! $screen || ! in_array( $screen->id, $allowed_screens, true ) ) {
			return;
		}

		// 定義要顯示的按鈕 - 主要超商類別
		$buttons = array(
			array(
				'label'   => '7-11',
				'service' => 'seven', // 使用分類識別符
				'icon'    => 'dashicons-store',
			),
			array(
				'label'   => '全家',
				'service' => 'family',
				'icon'    => 'dashicons-store',
			),
			array(
				'label'   => '萊爾富',
				'service' => YSLogisticService::HILIFE,
				'icon'    => 'dashicons-store',
			),
			array(
				'label'   => '黑貓',
				'service' => YSLogisticService::TCAT,
				'icon'    => 'dashicons-car',
			),
		);

		// 7-11 子類型選項
		$seven_options = array(
			array(
				'label'   => '7-11 交貨便 (C2C)',
				'service' => YSLogisticService::SEVEN,
			),
			array(
				'label'   => '7-11 大宗 (B2C)',
				'service' => YSLogisticService::SEVENBULK,
			),
			array(
				'label'   => '7-11 冷凍 (C2C)',
				'service' => YSLogisticService::SEVENFROZEN_C2C,
			),
			array(
				'label'   => '7-11 冷凍 (B2C)',
				'service' => YSLogisticService::SEVENFROZEN,
			),
		);

		// 全家子類型選項
		$family_options = array(
			array(
				'label'   => '全家店到店 (C2C)',
				'service' => YSLogisticService::FAMI,
			),
			array(
				'label'   => '全家大宗 (B2C)',
				'service' => YSLogisticService::FAMIBULK,
			),
			array(
				'label'   => '全家冷凍 (C2C)',
				'service' => YSLogisticService::FAMIFROZEN_C2C,
			),
			array(
				'label'   => '全家冷凍 (B2C)',
				'service' => YSLogisticService::FAMIFROZEN,
			),
		);
		?>
		
		<!-- Batch Print Modal -->
		<div id="ys-paynow-batch-print-modal">
			<div class="ys-paynow-modal-content">
				<div class="ys-paynow-modal-header">
					<h2 class="ys-paynow-modal-title"><?php esc_html_e( 'Batch Print', 'ys-paynow-shipping' ); ?></h2>
					<button type="button" class="ys-paynow-modal-close">&times;</button>
				</div>
				<div class="ys-paynow-modal-body">
					<!-- 篩選條件 -->
					<div class="ys-paynow-filters" style="margin-bottom: 15px; padding: 10px; background: #f9f9f9; border-radius: 4px;">
						<div style="display: flex; flex-wrap: wrap; gap: 15px; align-items: center;">
							<!-- 超商子類型選擇器 (僅 7-11 和全家顯示) -->
							<div class="ys-filter-group ys-subtype-filter" style="display: none;">
								<label style="font-weight: 600; margin-right: 5px;"><?php esc_html_e( '服務類型：', 'ys-paynow-shipping' ); ?></label>
								<select id="ys-service-subtype" class="ys-paynow-filter-select">
								</select>
							</div>
							<div class="ys-filter-group">
								<label style="font-weight: 600; margin-right: 5px;"><?php esc_html_e( '日期篩選：', 'ys-paynow-shipping' ); ?></label>
								<select id="ys-date-filter" class="ys-paynow-filter-select">
									<option value="all"><?php esc_html_e( '全部', 'ys-paynow-shipping' ); ?></option>
									<option value="today" selected><?php esc_html_e( '今日', 'ys-paynow-shipping' ); ?></option>
									<option value="yesterday"><?php esc_html_e( '昨日', 'ys-paynow-shipping' ); ?></option>
								</select>
							</div>
							<div class="ys-filter-group">
								<label style="font-weight: 600; margin-right: 5px;"><?php esc_html_e( '列印狀態：', 'ys-paynow-shipping' ); ?></label>
								<select id="ys-print-status-filter" class="ys-paynow-filter-select">
									<option value="all" selected><?php esc_html_e( '全部', 'ys-paynow-shipping' ); ?></option>
									<option value="unprinted"><?php esc_html_e( '未列印', 'ys-paynow-shipping' ); ?></option>
									<option value="printed"><?php esc_html_e( '已列印', 'ys-paynow-shipping' ); ?></option>
								</select>
							</div>
							<div class="ys-filter-group">
								<label style="display: flex; align-items: center; gap: 5px; cursor: pointer;">
									<input type="checkbox" id="ys-has-logistic-no-filter" checked>
									<span style="font-weight: 600;"><?php esc_html_e( '只顯示已取號', 'ys-paynow-shipping' ); ?></span>
								</label>
							</div>
							<div class="ys-filter-group ys-order-status-filter">
								<span style="font-weight: 600; margin-right: 5px;"><?php esc_html_e( '訂單狀態：', 'ys-paynow-shipping' ); ?></span>
								<label style="display: inline-flex; align-items: center; gap: 3px; cursor: pointer; margin-right: 10px;">
									<input type="checkbox" name="ys-order-status[]" value="processing" checked>
									<span><?php esc_html_e( '處理中', 'ys-paynow-shipping' ); ?></span>
								</label>
								<label style="display: inline-flex; align-items: center; gap: 3px; cursor: pointer;">
									<input type="checkbox" name="ys-order-status[]" value="wc-shipping">
									<span><?php esc_html_e( '已安排出貨', 'ys-paynow-shipping' ); ?></span>
								</label>
							</div>
							<button type="button" class="button ys-paynow-refresh-orders">
								<span class="dashicons dashicons-update" style="vertical-align: text-top;"></span>
								<?php esc_html_e( '重新載入', 'ys-paynow-shipping' ); ?>
							</button>
						</div>
					</div>

					<div class="ys-paynow-orders-loading" style="text-align: center; padding: 20px;">
						<span class="spinner is-active" style="float: none;"></span>
						<p><?php esc_html_e( '載入訂單中...', 'ys-paynow-shipping' ); ?></p>
					</div>
					<div class="ys-paynow-orders-list" style="display: none;">
						<div class="ys-paynow-orders-info">
							<p class="description"></p>
						</div>
						<div class="ys-paynow-orders-table-wrapper" style="max-height: 350px; overflow-y: auto;">
							<table class="wp-list-table widefat fixed striped">
								<thead class="ys-paynow-table-header">
									<tr>
										<td id="cb" class="manage-column column-cb check-column">
											<input id="cb-select-all-ys" type="checkbox">
										</td>
										<th><?php esc_html_e( '訂單編號', 'ys-paynow-shipping' ); ?></th>
										<th><?php esc_html_e( '訂單日期', 'ys-paynow-shipping' ); ?></th>
										<th><?php esc_html_e( '收件人', 'ys-paynow-shipping' ); ?></th>
										<th><?php esc_html_e( '金額', 'ys-paynow-shipping' ); ?></th>
										<th><?php esc_html_e( '物流單號', 'ys-paynow-shipping' ); ?></th>
										<th><?php esc_html_e( '已列印', 'ys-paynow-shipping' ); ?></th>
									</tr>
								</thead>
								<tbody></tbody>
							</table>
						</div>
						<div class="ys-paynow-no-orders" style="display: none; text-align: center; padding: 40px;">
							<p><?php esc_html_e( '沒有找到符合條件的訂單', 'ys-paynow-shipping' ); ?></p>
						</div>
					</div>
				</div>
				<div class="ys-paynow-modal-footer">
					<div style="display: flex; align-items: center; gap: 10px;">
						<button type="button" class="button button-primary ys-paynow-print-confirm" disabled>
							<span class="dashicons dashicons-printer" style="vertical-align: text-top;"></span>
							<?php esc_html_e( '列印標籤', 'ys-paynow-shipping' ); ?> (<span class="selected-count">0</span>)
						</button>
						<label class="ys-change-status-label" style="display: flex; align-items: center; gap: 5px;">
							<input type="checkbox" id="ys-change-status-after-print" checked>
							<span><?php esc_html_e( '列印後更改狀態為「已安排出貨」', 'ys-paynow-shipping' ); ?></span>
						</label>
					</div>
					<span style="flex: 1;"></span>
					<button type="button" class="button ys-paynow-modal-cancel"><?php esc_html_e( '取消', 'ys-paynow-shipping' ); ?></button>
				</div>
			</div>
		</div>

		<script type="text/javascript">
		jQuery(document).ready(function($) {
			// 子類型選項定義
			var sevenOptions = [
				<?php foreach ( $seven_options as $opt ) : ?>
				{ label: '<?php echo esc_html( $opt['label'] ); ?>', service: '<?php echo esc_attr( $opt['service'] ); ?>' },
				<?php endforeach; ?>
			];

			var familyOptions = [
				<?php foreach ( $family_options as $opt ) : ?>
				{ label: '<?php echo esc_html( $opt['label'] ); ?>', service: '<?php echo esc_attr( $opt['service'] ); ?>' },
				<?php endforeach; ?>
			];

			// Add batch print buttons - 使用 div 獨立一行
			var buttonsHtml = '<div class="ys-paynow-batch-print-wrapper">' +
				'<span class="ys-paynow-batch-print-label">PayNow:</span>';

			<?php foreach ( $buttons as $btn ) : ?>
			buttonsHtml += '<button type="button" class="button ys-paynow-batch-print-btn" data-service="<?php echo esc_attr( $btn['service'] ); ?>">' +
				'<span class="dashicons <?php echo esc_attr( $btn['icon'] ); ?>"></span>' +
				'<?php echo esc_html( $btn['label'] ); ?>' +
				'</button>';
			<?php endforeach; ?>

			buttonsHtml += '</div>';

			// 避免重複注入
			if ($('.ys-paynow-batch-print-wrapper').length > 0) {
				return;
			}

			// 注入到 .wp-header-end 前面（獨立一行）
			var injected = false;
			var $headerEnd = $('.wp-header-end');
			if ($headerEnd.length > 0) {
				$headerEnd.before(buttonsHtml);
				injected = true;
				console.log('[YS PayNow] 按鈕注入成功 (Before .wp-header-end)');
			}

			// 備用：插入到標題後面
			if (!injected) {
				var $h1 = $('.wrap h1.wp-heading-inline, .wrap > h1').first();
				if ($h1.length > 0) {
					$h1.after(buttonsHtml);
					injected = true;
					console.log('[YS PayNow] 按鈕注入成功 (After h1)');
				}
			}

			if (!injected) {
				console.log('[YS PayNow] 警告：無法找到注入點');
			}

			// Handlers
			var currentService = '';
			var currentServiceCategory = ''; // 'seven', 'family', or direct service code
			var currentServiceName = '';

			// 將 Modal 移動到 #wpbody-content 內
			var $modal = $('#ys-paynow-batch-print-modal');
			if ($modal.length && $('#wpbody-content').length) {
				$modal.appendTo('#wpbody-content');
			}

			// 更新子類型下拉選單
			function updateSubtypeOptions(category) {
				var $subtypeFilter = $('.ys-subtype-filter');
				var $select = $('#ys-service-subtype');
				$select.empty();

				var options = [];
				if (category === 'seven') {
					options = sevenOptions;
				} else if (category === 'family') {
					options = familyOptions;
				}

				if (options.length > 0) {
					options.forEach(function(opt) {
						$select.append('<option value="' + opt.service + '">' + opt.label + '</option>');
					});
					$subtypeFilter.show();
					currentService = options[0].service; // 預設選第一個
				} else {
					$subtypeFilter.hide();
				}
			}

			// Open Modal
			$(document).on('click', '.ys-paynow-batch-print-btn', function() {
				currentServiceCategory = $(this).data('service');
				currentServiceName = $(this).text().trim();

				// 如果是 7-11 或全家，顯示子類型選擇器
				if (currentServiceCategory === 'seven' || currentServiceCategory === 'family') {
					updateSubtypeOptions(currentServiceCategory);
				} else {
					// 直接使用服務代碼
					currentService = currentServiceCategory;
					$('.ys-subtype-filter').hide();
				}

				$('#ys-paynow-batch-print-modal').addClass('ys-modal-open');
				$('#ys-paynow-batch-print-modal .ys-paynow-modal-title').text('<?php esc_html_e( '批次列印', 'ys-paynow-shipping' ); ?>: ' + currentServiceName);

				// Reset filters to defaults
				$('#ys-date-filter').val('today');
				$('#ys-print-status-filter').val('all');
				$('#ys-has-logistic-no-filter').prop('checked', true);
				// 訂單狀態預設勾選「處理中」
				$('input[name="ys-order-status[]"][value="processing"]').prop('checked', true);
				$('input[name="ys-order-status[]"][value="wc-shipping"]').prop('checked', false);

				loadOrders(currentService);
			});

			// Close Modal
			$('.ys-paynow-modal-close, .ys-paynow-modal-cancel').on('click', function() {
				$('#ys-paynow-batch-print-modal').removeClass('ys-modal-open');
			});

			// 子類型選擇變更
			$('#ys-service-subtype').on('change', function() {
				currentService = $(this).val();
				loadOrders(currentService);
			});

			// Filter change handlers
			$('#ys-date-filter, #ys-print-status-filter').on('change', function() {
				loadOrders(currentService);
			});

			// 「只顯示已取號」勾選變更
			$('#ys-has-logistic-no-filter').on('change', function() {
				loadOrders(currentService);
			});

			// 訂單狀態 checkbox 變更
			$(document).on('change', 'input[name="ys-order-status[]"]', function() {
				loadOrders(currentService);
			});

			// Refresh button
			$('.ys-paynow-refresh-orders').on('click', function() {
				loadOrders(currentService);
			});

			// Load Orders
			function loadOrders(service) {
				$('.ys-paynow-orders-loading').show();
				$('.ys-paynow-orders-list').hide();

				// Get selected IDs from WP list table
				var selectedIds = [];
				$('.wp-list-table input[name="post[]"]:checked, .wp-list-table input[name="id[]"]:checked').each(function() {
					selectedIds.push($(this).val());
				});

				// Get filter values
				var dateFilter = $('#ys-date-filter').val();
				var printStatusFilter = $('#ys-print-status-filter').val();
				var hasLogisticNoOnly = $('#ys-has-logistic-no-filter').is(':checked') ? '1' : '0';

				// 取得勾選的訂單狀態
				var orderStatuses = [];
				$('input[name="ys-order-status[]"]:checked').each(function() {
					orderStatuses.push($(this).val());
				});

				$.ajax({
					url: '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>',
					type: 'POST',
					data: {
						action: 'ys_paynow_get_unprinted_orders',
						nonce: '<?php echo esc_attr( wp_create_nonce( 'ys-paynow-batch-print' ) ); ?>',
						service: service,
						selected_ids: selectedIds,
						date_filter: dateFilter,
						print_status: printStatusFilter,
						has_logistic_no_only: hasLogisticNoOnly,
						order_statuses: orderStatuses
					},
					success: function(response) {
						if (response.success && response.data) {
							renderOrders(response.data.orders);
							var infoText = '';
							if (selectedIds.length > 0) {
								infoText = '<?php esc_html_e( '已顯示選取的訂單中符合條件的項目', 'ys-paynow-shipping' ); ?>';
							} else {
								infoText = '<?php esc_html_e( '顯示符合條件的訂單', 'ys-paynow-shipping' ); ?> (' + response.data.orders.length + ' <?php esc_html_e( '筆', 'ys-paynow-shipping' ); ?>)';
							}
							$('.ys-paynow-orders-info .description').text(infoText);
						} else {
							alert(response.data || 'Error loading orders');
						}
					},
					complete: function() {
						$('.ys-paynow-orders-loading').hide();
						$('.ys-paynow-orders-list').show();
					}
				});
			}

			// Render Orders Table
			function renderOrders(orders) {
				var $tbody = $('.ys-paynow-orders-table-wrapper tbody');
				$tbody.empty();
				$('#cb-select-all-ys').prop('checked', false);

				if (orders.length === 0) {
					$('.ys-paynow-no-orders').show();
					$('.ys-paynow-orders-table-wrapper').hide();
					$('.ys-paynow-print-confirm').prop('disabled', true);
					$('.selected-count').text('0');
					return;
				}

				$('.ys-paynow-no-orders').hide();
				$('.ys-paynow-orders-table-wrapper').show();

				orders.forEach(function(order) {
					var printedBadge = order.is_printed ?
						'<span style="color: #46b450;">✓</span>' :
						'<span style="color: #999;">-</span>';
					var row = '<tr>' +
						'<td class="check-column"><input type="checkbox" name="ys_print_order[]" value="' + order.id + '"></td>' +
						'<td><a href="' + order.edit_url + '" target="_blank">#' + order.number + '</a></td>' +
						'<td>' + order.date + '</td>' +
						'<td>' + order.customer + '</td>' +
						'<td>' + order.total + '</td>' +
						'<td>' + (order.has_logistic_no ? '<code style="font-size:11px;">' + order.logistic_no + '</code>' : '<span style="color:#d63638;"><?php esc_html_e( '無單號', 'ys-paynow-shipping' ); ?></span>') + '</td>' +
						'<td>' + printedBadge + '</td>' +
						'</tr>';
					$tbody.append(row);
				});

				updateSelectedCount();
			}

			// Checkbox handlers
			$(document).on('change', '#cb-select-all-ys', function() {
				var isChecked = $(this).is(':checked');
				$('input[name="ys_print_order[]"]').prop('checked', isChecked);
				updateSelectedCount();
			});

			$(document).on('change', 'input[name="ys_print_order[]"]', function() {
				updateSelectedCount();
			});

			function updateSelectedCount() {
				var count = $('input[name="ys_print_order[]"]:checked').length;
				$('.selected-count').text(count);
				$('.ys-paynow-print-confirm').prop('disabled', count === 0);
			}

			// Confirm Print
			$('.ys-paynow-print-confirm').on('click', function() {
				var orderIds = [];
				$('input[name="ys_print_order[]"]:checked').each(function() {
					orderIds.push($(this).val());
				});

				if (orderIds.length === 0) return;

				var changeStatus = $('#ys-change-status-after-print').is(':checked') ? '1' : '0';

				var printUrl = '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>?action=ys_paynow_print_label&_wpnonce=<?php echo esc_attr( wp_create_nonce( 'ys-paynow-print-label' ) ); ?>&service=' + currentService + '&order_ids=' + orderIds.join(',') + '&change_status=' + changeStatus;

				window.open(printUrl, '_blank');
				$('#ys-paynow-batch-print-modal').removeClass('ys-modal-open');

				// Reload page after a short delay to reflect changes
				if (changeStatus === '1') {
					setTimeout(function() {
						location.reload();
					}, 2000);
				}
			});
		});
		</script>
		
		<style type="text/css">
		/* ===== YS PayNow Modal 共用樣式 ===== */
		/* 隱藏狀態 - 批次列印 Modal */
		#ys-paynow-batch-print-modal {
			display: none !important;
		}

		/* 顯示時的樣式 - 批次列印 Modal */
		#ys-paynow-batch-print-modal.ys-modal-open {
			display: flex !important;
			position: fixed !important;
			z-index: 100100 !important;
			left: 0 !important;
			top: 0 !important;
			right: 0 !important;
			bottom: 0 !important;
			width: 100vw !important;
			height: 100vh !important;
			margin: 0 !important;
			padding: 0 !important;
			background: rgba(0, 0, 0, 0.6) !important;
			align-items: center !important;
			justify-content: center !important;
			box-sizing: border-box !important;
		}

		/* ===== 重選超商 Modal 樣式修正 ===== */
		#ys-paynow-selector-modal {
			display: none !important;
			background: rgba(0, 0, 0, 0.6) !important;
		}

		#ys-paynow-selector-modal[style*="display: flex"],
		#ys-paynow-selector-modal[style*="display:flex"],
		#ys-paynow-selector-modal.ys-modal-open {
			display: flex !important;
			position: fixed !important;
			z-index: 100100 !important;
			left: 0 !important;
			top: 0 !important;
			right: 0 !important;
			bottom: 0 !important;
			width: 100vw !important;
			height: 100vh !important;
			margin: 0 !important;
			padding: 0 !important;
			background: rgba(0, 0, 0, 0.6) !important;
			align-items: center !important;
			justify-content: center !important;
			box-sizing: border-box !important;
		}

		#ys-paynow-selector-modal > .ys-paynow-modal-content {
			background: #fff !important;
			border-radius: 8px !important;
			box-shadow: 0 5px 30px rgba(0, 0, 0, 0.4) !important;
			width: 400px !important;
			max-width: calc(100vw - 40px) !important;
			padding: 20px !important;
			position: relative !important;
			box-sizing: border-box !important;
		}

		#ys-paynow-selector-modal h3 {
			margin-top: 0 !important;
			margin-bottom: 15px !important;
			font-size: 18px !important;
			font-weight: 600 !important;
			color: #1d2327 !important;
		}

		#ys-paynow-selector-modal select {
			width: 100% !important;
			padding: 8px 12px !important;
			margin-bottom: 20px !important;
			border: 1px solid #8c8f94 !important;
			border-radius: 4px !important;
		}

		#ys-paynow-selector-modal p {
			margin: 0 0 10px 0 !important;
			color: #50575e !important;
		}

		#ys-paynow-selector-modal .ys-paynow-modal-buttons {
			text-align: right !important;
			margin-top: 15px !important;
		}

		#ys-paynow-selector-modal .ys-paynow-modal-buttons .button {
			margin-left: 10px !important;
		}

		#ys-paynow-selector-modal .ys-paynow-modal-buttons .button:first-child {
			margin-left: 0 !important;
		}

		/* Modal 內容區塊 */
		#ys-paynow-batch-print-modal .ys-paynow-modal-content {
			background: #fff !important;
			border-radius: 8px !important;
			box-shadow: 0 5px 30px rgba(0, 0, 0, 0.4) !important;
			width: 800px !important;
			max-width: calc(100vw - 40px) !important;
			max-height: calc(100vh - 100px) !important;
			display: flex !important;
			flex-direction: column !important;
			margin: 0 !important;
			padding: 0 !important;
			position: relative !important;
			box-sizing: border-box !important;
			overflow: hidden !important;
		}

		/* Modal Header */
		#ys-paynow-batch-print-modal .ys-paynow-modal-header {
			padding: 15px 20px !important;
			border-bottom: 1px solid #ddd !important;
			display: flex !important;
			justify-content: space-between !important;
			align-items: center !important;
			flex-shrink: 0 !important;
			background: #fff !important;
		}

		#ys-paynow-batch-print-modal .ys-paynow-modal-title {
			margin: 0 !important;
			padding: 0 !important;
			font-size: 18px !important;
			font-weight: 600 !important;
			color: #1d2327 !important;
		}

		#ys-paynow-batch-print-modal .ys-paynow-modal-close {
			background: none !important;
			border: none !important;
			font-size: 28px !important;
			line-height: 1 !important;
			cursor: pointer !important;
			color: #666 !important;
			padding: 0 !important;
			margin: 0 !important;
			width: auto !important;
			height: auto !important;
		}

		#ys-paynow-batch-print-modal .ys-paynow-modal-close:hover {
			color: #d63638 !important;
		}

		/* Modal Body */
		#ys-paynow-batch-print-modal .ys-paynow-modal-body {
			padding: 20px !important;
			flex: 1 !important;
			overflow-y: auto !important;
			overflow-x: hidden !important;
			background: #fff !important;
		}

		/* Modal Footer */
		#ys-paynow-batch-print-modal .ys-paynow-modal-footer {
			padding: 15px 20px !important;
			border-top: 1px solid #ddd !important;
			display: flex !important;
			justify-content: flex-start !important;
			align-items: center !important;
			gap: 15px !important;
			flex-shrink: 0 !important;
			background: #f9f9f9 !important;
			flex-wrap: wrap !important;
		}

		/* 篩選區塊 */
		#ys-paynow-batch-print-modal .ys-paynow-filters {
			margin-bottom: 15px !important;
			padding: 12px !important;
			background: #f5f5f5 !important;
			border-radius: 4px !important;
			border: 1px solid #e0e0e0 !important;
		}

		/* 訂單表格 */
		#ys-paynow-batch-print-modal .ys-paynow-orders-table-wrapper {
			max-height: 300px !important;
			overflow-y: auto !important;
			border: 1px solid #e0e0e0 !important;
			border-radius: 4px !important;
		}

		#ys-paynow-batch-print-modal .ys-paynow-orders-table-wrapper table {
			width: 100% !important;
			border-collapse: collapse !important;
			margin: 0 !important;
		}

		#ys-paynow-batch-print-modal .ys-paynow-orders-table-wrapper th,
		#ys-paynow-batch-print-modal .ys-paynow-orders-table-wrapper td {
			padding: 10px 8px !important;
			text-align: left !important;
			border-bottom: 1px solid #eee !important;
		}

		#ys-paynow-batch-print-modal .ys-paynow-orders-table-wrapper thead th {
			background: #f9f9f9 !important;
			font-weight: 600 !important;
			position: sticky !important;
			top: 0 !important;
		}

		/* 批次列印按鈕區塊 - 獨立一行 */
		.ys-paynow-batch-print-wrapper {
			display: flex !important;
			align-items: center !important;
			gap: 8px !important;
			width: 100% !important;
			padding: 10px 0 !important;
			margin: 5px 0 10px 0 !important;
			border-bottom: 1px solid #c3c4c7 !important;
			flex-wrap: wrap !important;
		}

		.ys-paynow-batch-print-label {
			color: #1d2327 !important;
			font-weight: 600 !important;
			margin-right: 5px !important;
		}

		.ys-paynow-batch-print-btn {
			display: inline-flex !important;
			align-items: center !important;
			gap: 4px !important;
		}

		.ys-paynow-batch-print-btn .dashicons {
			font-size: 16px !important;
			width: 16px !important;
			height: 16px !important;
			line-height: 16px !important;
		}

		/* 取消按鈕靠右 */
		#ys-paynow-batch-print-modal .ys-paynow-modal-cancel {
			margin-left: auto !important;
		}
		</style>
		<?php
	}

	/**
	 * AJAX: 取得訂單 (支援日期與列印狀態篩選)
	 *
	 * @return void
	 */
	public static function ajax_get_unprinted_orders() {
		check_ajax_referer( 'ys-paynow-batch-print', 'nonce' );

		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_send_json_error( __( '權限不足', 'ys-paynow-shipping' ) );
		}

		$service              = isset( $_POST['service'] ) ? sanitize_text_field( wp_unslash( $_POST['service'] ) ) : '';
		$selected_ids         = isset( $_POST['selected_ids'] ) ? array_map( 'absint', $_POST['selected_ids'] ) : array();
		$date_filter          = isset( $_POST['date_filter'] ) ? sanitize_text_field( wp_unslash( $_POST['date_filter'] ) ) : 'today';
		$print_status         = isset( $_POST['print_status'] ) ? sanitize_text_field( wp_unslash( $_POST['print_status'] ) ) : 'all';
		$has_logistic_no_only = isset( $_POST['has_logistic_no_only'] ) && '1' === $_POST['has_logistic_no_only'];
		// 訂單狀態篩選
		$order_statuses       = isset( $_POST['order_statuses'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['order_statuses'] ) ) : array( 'processing' );

		if ( empty( $service ) ) {
			wp_send_json_error( __( '無效的服務', 'ys-paynow-shipping' ) );
		}

		$orders_data = array();

		// 計算日期範圍
		$date_query = array();
		$today = wp_date( 'Y-m-d' );
		$yesterday = wp_date( 'Y-m-d', strtotime( '-1 day' ) );

		if ( 'today' === $date_filter ) {
			$date_query = array(
				'after'     => $today . ' 00:00:00',
				'before'    => $today . ' 23:59:59',
				'inclusive' => true,
			);
		} elseif ( 'yesterday' === $date_filter ) {
			$date_query = array(
				'after'     => $yesterday . ' 00:00:00',
				'before'    => $yesterday . ' 23:59:59',
				'inclusive' => true,
			);
		}

		// 處理訂單狀態（移除 wc- 前綴如果需要）
		$status_query = array();
		foreach ( $order_statuses as $status ) {
			// WooCommerce 訂單狀態查詢不需要 wc- 前綴
			$status_query[] = str_replace( 'wc-', '', $status );
		}
		// 如果沒有選擇任何狀態，預設處理中
		if ( empty( $status_query ) ) {
			$status_query = array( 'processing' );
		}

		// 如果有選擇特定的訂單
		if ( ! empty( $selected_ids ) ) {
			foreach ( $selected_ids as $order_id ) {
				$order = wc_get_order( $order_id );
				if ( ! $order ) {
					continue;
				}

				// 檢查訂單狀態是否在篩選範圍內
				if ( ! in_array( $order->get_status(), $status_query, true ) ) {
					continue;
				}

				// 檢查物流服務是否匹配
				$logistic_service_id = $order->get_meta( YSOrderMeta::LogisticServiceId );
				if ( $logistic_service_id !== $service ) {
					continue;
				}

				// 檢查是否有物流單號
				if ( $has_logistic_no_only ) {
					$logistic_number = $order->get_meta( YSOrderMeta::LogisticNumber );
					if ( empty( $logistic_number ) ) {
						continue;
					}
				}

				// 檢查列印狀態
				if ( ! self::match_print_status( $order, $print_status ) ) {
					continue;
				}

				// 檢查日期
				if ( ! empty( $date_query ) ) {
					$order_date = $order->get_date_created()->format( 'Y-m-d' );
					if ( 'today' === $date_filter && $order_date !== $today ) {
						continue;
					}
					if ( 'yesterday' === $date_filter && $order_date !== $yesterday ) {
						continue;
					}
				}

				$orders_data[] = self::build_order_data( $order );
			}
		} else {
			// 否則撈取符合條件的訂單
			$args = array(
				'limit'    => 100,
				'status'   => $status_query,
				'orderby'  => 'date',
				'order'    => 'DESC',
			);

			// 日期篩選
			if ( ! empty( $date_query ) ) {
				$args['date_created'] = $date_query['after'] . '...' . $date_query['before'];
			}

			$orders = wc_get_orders( $args );

			foreach ( $orders as $order ) {
				// 檢查物流服務是否匹配
				$logistic_service_id = $order->get_meta( YSOrderMeta::LogisticServiceId );
				if ( $logistic_service_id !== $service ) {
					continue;
				}

				// 檢查是否有物流單號
				if ( $has_logistic_no_only ) {
					$logistic_number = $order->get_meta( YSOrderMeta::LogisticNumber );
					if ( empty( $logistic_number ) ) {
						continue;
					}
				}

				// 檢查列印狀態
				if ( ! self::match_print_status( $order, $print_status ) ) {
					continue;
				}

				$orders_data[] = self::build_order_data( $order );
			}
		}

		wp_send_json_success( array( 'orders' => $orders_data ) );
	}

	/**
	 * 檢查訂單是否符合列印狀態篩選
	 *
	 * @param \WC_Order $order        訂單物件。
	 * @param string    $print_status 列印狀態篩選值。
	 * @return bool
	 */
	private static function match_print_status( $order, $print_status ) {
		$is_printed = 'yes' === $order->get_meta( '_ys_label_printed' ) || 'yes' === $order->get_meta( '_ys_paynow_label_printed' );

		if ( 'unprinted' === $print_status && $is_printed ) {
			return false;
		}
		if ( 'printed' === $print_status && ! $is_printed ) {
			return false;
		}

		return true;
	}

	/**
	 * 建立訂單資料陣列
	 *
	 * @param \WC_Order $order 訂單物件。
	 * @return array
	 */
	private static function build_order_data( $order ) {
		$logistic_number = $order->get_meta( YSOrderMeta::LogisticNumber );
		$is_printed      = 'yes' === $order->get_meta( '_ys_label_printed' ) || 'yes' === $order->get_meta( '_ys_paynow_label_printed' );

		// 取得訂單編輯連結
		$edit_url = '';
		if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$edit_url = admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $order->get_id() );
		} else {
			$edit_url = admin_url( 'post.php?post=' . $order->get_id() . '&action=edit' );
		}

		return array(
			'id'              => $order->get_id(),
			'number'          => $order->get_order_number(),
			'date'            => $order->get_date_created()->format( 'Y-m-d H:i' ),
			'customer'        => $order->get_formatted_billing_full_name(),
			'total'           => $order->get_formatted_order_total(),
			'status_name'     => wc_get_order_status_name( $order->get_status() ),
			'has_logistic_no' => ! empty( $logistic_number ),
			'logistic_no'     => $logistic_number,
			'is_printed'      => $is_printed,
			'edit_url'        => $edit_url,
		);
	}
}
