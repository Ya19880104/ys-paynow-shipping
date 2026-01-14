<?php
/**
 * YS PayNow CVS Store Selector Frontend Handler
 * 超商選擇前端處理
 *
 * @package YangSheep\PayNow\Shipping\Frontend
 * @since   1.0.0
 */

namespace YangSheep\PayNow\Shipping\Frontend;

use YangSheep\PayNow\Shipping\YSPaynowShipping;
use YangSheep\PayNow\Shipping\Utils\YSOrderMeta;
use YangSheep\PayNow\Shipping\Utils\YSLogisticService;

defined( 'ABSPATH' ) || exit;

/**
 * YSStoreSelector Class
 *
 * 處理結帳頁的超商選擇器功能，包括開啟地圖、接收回傳資料、儲存至 session。
 *
 * @since 1.0.0
 */
class YSStoreSelector {

	/**
	 * 防止重複渲染的標記
	 *
	 * @var bool
	 */
	private static $selector_rendered = false;

	/**
	 * 初始化
	 *
	 * @return void
	 */
	public static function init() {
		// ★ 在結帳頁初始載入時（非 AJAX），恢復 POST 中的門市資料到 Session
		// 這必須在任何 AJAX 調用之前執行，因為 POST 資料只在初始頁面載入時存在
		add_action( 'template_redirect', array( __CLASS__, 'restore_store_data_on_checkout_load' ) );

		// ★ Server-Side：偵測物流變更並清除 CVS Session（自身清理，不依賴結帳強化外掛）
		add_action( 'woocommerce_checkout_update_order_review', array( __CLASS__, 'check_shipping_method_change' ), 10 );

		// ★ 使用 woocommerce_review_order_after_shipping hook - 在運送區塊之後渲染選擇器
		// 與 PayUni 使用相同的 hook，確保兩個外掛的選擇器位置一致
		add_action( 'woocommerce_review_order_after_shipping', array( __CLASS__, 'display_store_selector_after_shipping' ) );

		// ★ 註冊 AJAX fragment，確保選擇器在 AJAX 更新時也會更新
		add_filter( 'woocommerce_update_order_review_fragments', array( __CLASS__, 'add_store_selector_fragment' ), 20 );

		// AJAX 端點
		add_action( 'wp_ajax_ys_paynow_save_store', array( __CLASS__, 'ajax_save_store' ) );
		add_action( 'wp_ajax_nopriv_ys_paynow_save_store', array( __CLASS__, 'ajax_save_store' ) );

		add_action( 'wp_ajax_ys_paynow_get_map_url', array( __CLASS__, 'ajax_get_map_url' ) );
		add_action( 'wp_ajax_nopriv_ys_paynow_get_map_url', array( __CLASS__, 'ajax_get_map_url' ) );

		add_action( 'wp_ajax_ys_paynow_clear_store_data', array( __CLASS__, 'ajax_clear_store_data' ) );
		add_action( 'wp_ajax_nopriv_ys_paynow_clear_store_data', array( __CLASS__, 'ajax_clear_store_data' ) );

		// 接收超商回傳資料 (前台)
		add_action( 'woocommerce_api_ys-paynow-cvs-callback', array( __CLASS__, 'handle_cvs_callback' ) );

		// 接收超商回傳資料 (後台 - 使用 postMessage)
		add_action( 'woocommerce_api_ys-paynow-cvs-admin-callback', array( __CLASS__, 'handle_cvs_admin_callback' ) );

		// 在結帳更新時傳遞門市資料
		add_filter( 'woocommerce_update_order_review_fragments', array( __CLASS__, 'add_store_data_fragment' ) );
	}

	/**
	 * 在結帳頁初始載入時處理門市資料
	 *
	 * 1. 從超商地圖 callback 返回時，恢復 POST 門市資料到 Session
	 * 2. 若當前沒有選擇 YS PayNow 超取，清除遺留的門市資料
	 *
	 * @return void
	 */
	public static function restore_store_data_on_checkout_load() {
		if ( ! is_checkout() ) {
			return;
		}

		if ( wp_doing_ajax() ) {
			return;
		}

		// ★ 確保 WC Session 存在
		if ( ! WC()->session ) {
			YSPaynowShipping::log( '[restore_store_data_on_checkout_load] WC Session 不存在，嘗試初始化' );
			// 嘗試初始化 session
			if ( is_callable( array( WC(), 'initialize_session' ) ) ) {
				WC()->initialize_session();
			}
			// 再次檢查
			if ( ! WC()->session ) {
				YSPaynowShipping::log( '[restore_store_data_on_checkout_load] Session 初始化失敗' );
				return;
			}
		}

		// ★ 首先嘗試從 POST 恢復門市資料（從 PayNow 地圖 callback 返回時）
		$restored_from_post = self::restore_store_data_from_post();
		if ( $restored_from_post ) {
			return; // POST 資料已恢復，不需要進一步處理
		}

		$stored_data = WC()->session->get( 'ys_paynow_selected_store_data', array() );
		if ( empty( $stored_data ) || empty( $stored_data['id'] ) ) {
			return;
		}

		$chosen_methods = WC()->session->get( 'chosen_shipping_methods' );
		$current_method    = ! empty( $chosen_methods ) ? $chosen_methods[0] : '';
		$current_method_id = strpos( $current_method, ':' ) !== false ? explode( ':', $current_method )[0] : $current_method;
		$is_current_cvs    = self::is_ys_paynow_cvs_method( $current_method_id );

		// 如果當前不是超取方式，清除門市資料
		if ( ! $is_current_cvs ) {
			WC()->session->set( 'ys_paynow_selected_store_data', null );
			WC()->session->set( 'ys_paynow_selected_store_method_id', null );
			YSPaynowShipping::log( '[restore_store_data] 清除門市資料：當前非超取方式' );
			return;
		}

		// 檢查門市的 logistic_service_id 是否與當前選擇的超商類型匹配
		$current_service_id = YSPaynowShipping::get_logistic_service_id( $current_method_id );
		$stored_service_id  = $stored_data['logistic_service_id'] ?? '';

		// 如果儲存的門市類型與當前選擇的超商類型不匹配，清除門市資料
		if ( ! empty( $stored_service_id ) && $stored_service_id !== $current_service_id ) {
			WC()->session->set( 'ys_paynow_selected_store_data', null );
			WC()->session->set( 'ys_paynow_selected_store_method_id', null );
			YSPaynowShipping::log( sprintf(
				'[restore_store_data] 清除門市資料：超商類型不匹配（stored=%s, current=%s）',
				$stored_service_id,
				$current_service_id
			) );
			return;
		}

		// 更新 method_id（門市資料保留，但更新關聯的運送方式）
		WC()->session->set( 'ys_paynow_selected_store_method_id', $current_method_id );
	}

	/**
	 * Server-Side：偵測物流方式變更並清除 CVS Session
	 *
	 * 當用戶切換到不同超商類型時，清除已選擇的門市資料。
	 * 例如：從 7-11 切換到全家時，需要清除 7-11 的門市資料。
	 *
	 * @param string $post_data URL-encoded form data.
	 * @return void
	 */
	public static function check_shipping_method_change( $post_data ) {
		if ( ! WC()->session ) {
			return;
		}

		parse_str( $post_data, $data );
		$current_method = isset( $data['shipping_method'][0] ) ? $data['shipping_method'][0] : '';
		if ( empty( $current_method ) ) {
			return;
		}

		$current_method_id = strpos( $current_method, ':' ) !== false ? explode( ':', $current_method )[0] : $current_method;

		// 如果有新的門市資料正在提交，不要清除
		$has_fresh_store_data = ! empty( $data['ys_paynow_selected_store_id'] );
		if ( ! $has_fresh_store_data && ! empty( $data['ys_paynow_selected_store_data'] ) ) {
			$has_fresh_store_data = true;
		}
		if ( $has_fresh_store_data ) {
			return;
		}

		// 取得儲存的門市資料
		$stored_data = WC()->session->get( 'ys_paynow_selected_store_data', array() );
		if ( empty( $stored_data ) || empty( $stored_data['id'] ) ) {
			return;
		}

		$is_current_cvs = self::is_ys_paynow_cvs_method( $current_method_id );

		// 如果當前不是超取方式，清除門市資料
		if ( ! $is_current_cvs ) {
			WC()->session->set( 'ys_paynow_selected_store_data', null );
			WC()->session->set( 'ys_paynow_selected_store_method_id', null );
			YSPaynowShipping::log( '[check_shipping_method_change] 清除門市資料：切換到非超取方式' );
			return;
		}

		// 檢查超商類型是否改變
		$current_service_id = YSPaynowShipping::get_logistic_service_id( $current_method_id );
		$stored_service_id  = $stored_data['logistic_service_id'] ?? '';

		// 如果超商類型不同，清除門市資料
		if ( ! empty( $stored_service_id ) && $stored_service_id !== $current_service_id ) {
			WC()->session->set( 'ys_paynow_selected_store_data', null );
			WC()->session->set( 'ys_paynow_selected_store_method_id', null );
			YSPaynowShipping::log( sprintf(
				'[check_shipping_method_change] 清除門市資料：超商類型變更（%s -> %s）',
				$stored_service_id,
				$current_service_id
			) );
			return;
		}

		// 更新 method_id
		WC()->session->set( 'ys_paynow_selected_store_method_id', $current_method_id );
	}

	/**
	 * 判斷是否為 YS PayNow 超商取貨物流
	 *
	 * @param string $method_id 物流方式 ID.
	 * @return bool
	 */
	private static function is_ys_paynow_cvs_method( $method_id ) {
		if ( empty( $method_id ) ) {
			return false;
		}

		// 使用主類別的 needs_cvs 方法，確保邏輯一致
		return YSPaynowShipping::needs_cvs( $method_id );
	}

	/**
	 * 根據 logistic_service_id 取得對應的 method_id
	 *
	 * @param string $logistic_service_id 物流服務代碼 (01, 03, 05 等)。
	 * @return string method_id 或空字串。
	 */
	private static function get_method_id_from_service( $logistic_service_id ) {
		// logistic_service_id 對應到 method_id 的映射
		// 注意：此映射需與 YSPaynowShipping::get_logistic_service_id() 保持同步
		$mapping = array(
			// C2C 店到店
			YSLogisticService::SEVEN   => 'ys_paynow_shipping_711',         // 01 -> 7-ELEVEN
			YSLogisticService::FAMI    => 'ys_paynow_shipping_family',      // 03 -> 全家
			YSLogisticService::HILIFE  => 'ys_paynow_shipping_hilife',      // 05 -> 萊爾富
			// B2C 大宗寄倉
			YSLogisticService::SEVENBULK => 'ys_paynow_shipping_711_bulk',    // 02 -> 7-11 大宗
			YSLogisticService::FAMIBULK  => 'ys_paynow_shipping_family_bulk', // 04 -> 全家大宗
			// 冷凍 C2C
			YSLogisticService::SEVENFROZEN_C2C => 'ys_paynow_shipping_711_frozen',    // 21 -> 7-11 冷凍 C2C
			YSLogisticService::FAMIFROZEN_C2C  => 'ys_paynow_shipping_family_frozen', // 23 -> 全家冷凍 C2C
			// 冷凍 B2C (大宗冷凍)
			YSLogisticService::SEVENFROZEN => 'ys_paynow_shipping_711_bulk_frozen',    // 22 -> 7-11 冷凍 B2C
			YSLogisticService::FAMIFROZEN  => 'ys_paynow_shipping_family_bulk_frozen', // 24 -> 全家冷凍 B2C
		);

		return isset( $mapping[ $logistic_service_id ] ) ? $mapping[ $logistic_service_id ] : '';
	}

	/**
	 * 在運送區塊後顯示超商選擇器
	 *
	 * 使用 woocommerce_review_order_after_shipping hook，在整個運送區塊之後渲染。
	 * 與 PayUni 使用相同的 hook，確保兩個外掛的選擇器位置一致。
	 *
	 * @return void
	 */
	public static function display_store_selector_after_shipping() {
		// ★ 防止重複渲染
		if ( self::$selector_rendered ) {
			return;
		}

		// 取得當前選擇的物流方式
		$chosen_methods = WC()->session ? WC()->session->get( 'chosen_shipping_methods' ) : array();

		if ( empty( $chosen_methods ) ) {
			return;
		}

		$shipping_method = $chosen_methods[0];
		$method_id       = strpos( $shipping_method, ':' ) !== false ? explode( ':', $shipping_method )[0] : $shipping_method;

		// ★ 只在當前選擇的是 YS PayNow 超商取貨時才顯示（與 PayUni 相同邏輯）
		if ( ! YSPaynowShipping::needs_cvs( $method_id ) ) {
			return;
		}

		// 標記為已渲染
		self::$selector_rendered = true;

		// 取得物流服務 ID
		$logistic_service_id = YSPaynowShipping::get_logistic_service_id( $method_id );

		// 取得當前已選門市資料（如果有）
		$stored_store_data = array();
		if ( WC()->session ) {
			$stored_store_data = WC()->session->get( 'ys_paynow_selected_store_data', array() );
		}

		// ★ 檢查門市類型是否匹配當前選擇的超商
		// 如果不匹配，清除門市資料（例如：存的是全家門市，但選的是萊爾富）
		if ( ! empty( $stored_store_data ) && is_array( $stored_store_data ) && ! empty( $stored_store_data['id'] ) ) {
			$stored_service_id = $stored_store_data['logistic_service_id'] ?? '';

			if ( ! empty( $stored_service_id ) && ! empty( $logistic_service_id ) && $stored_service_id !== $logistic_service_id ) {
				WC()->session->set( 'ys_paynow_selected_store_data', null );
				$stored_store_data = array();
			}
		}

			// ★ 根據環境決定輸出格式
		// 優化外掛使用 <div> 結構，標準 WooCommerce 使用 <table> 結構
		$use_div_layout = class_exists( 'YANGSHEEP_Shipping_Cards' ) || did_action( 'yangsheep_after_shipping_cards' );

		if ( $use_div_layout ) {
			// 優化外掛環境：使用 <div> 結構
			?>
			<div id="ys-paynow-store-selector-row" class="ys-paynow-store-selector-row">
				<div id="ys-paynow-store-selector-container" class="ys-paynow-store-selector-wrapper">
					<div class="ys-paynow-store-selector"
						 data-method-id="<?php echo esc_attr( $method_id ); ?>"
						 data-logistic-service="<?php echo esc_attr( $logistic_service_id ); ?>">
						<?php self::render_store_selector_content( $stored_store_data, $logistic_service_id ); ?>
					</div>
				</div>
			</div>
			<?php
		} else {
			// 標準 WooCommerce 環境：使用 <tr> 結構（與 PayUni 相同）
			?>
			<tr id="ys-paynow-store-selector-row" class="ys-paynow-store-selector-row">
				<td colspan="2">
					<div id="ys-paynow-store-selector-container" class="ys-paynow-store-selector-wrapper">
						<div class="ys-paynow-store-selector"
							 data-method-id="<?php echo esc_attr( $method_id ); ?>"
							 data-logistic-service="<?php echo esc_attr( $logistic_service_id ); ?>">
							<?php self::render_store_selector_content( $stored_store_data, $logistic_service_id ); ?>
						</div>
					</div>
				</td>
			</tr>
			<?php
		}
	}

	/**
	 * 渲染超商選擇器內容
	 *
	 * @param array  $stored_store_data 已儲存的門市資料。
	 * @param string $logistic_service_id 當前物流服務 ID（用於顯示超商類型）。
	 * @return void
	 */
	private static function render_store_selector_content( $stored_store_data, $logistic_service_id = '' ) {
		// ★ 關鍵：從 PHP 渲染 hidden fields，確保值來自 Session（最新資料）
		$store_id   = '';
		$store_json = '';
		if ( ! empty( $stored_store_data ) && is_array( $stored_store_data ) && ! empty( $stored_store_data['id'] ) ) {
			$store_id   = $stored_store_data['id'];
			$store_json = wp_json_encode( $stored_store_data );
		}

		// 取得超商類型標籤
		?>
		<!-- Hidden fields rendered by PHP from Session - authoritative source -->
		<input type="hidden" name="ys_paynow_selected_store_id" id="ys_paynow_selected_store_id" value="<?php echo esc_attr( $store_id ); ?>">
		<input type="hidden" name="ys_paynow_selected_store_data" id="ys_paynow_selected_store_data" value="<?php echo esc_attr( $store_json ); ?>">

		<?php if ( ! empty( $store_id ) ) : ?>
			<div class="ys-paynow-selected-store">
				<div class="store-check-icon">
					<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#22c55e" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
				</div>
				<div class="store-info">

					<div class="store-name"><?php echo esc_html( $stored_store_data['name'] ?? '' ); ?></div>
					<div class="store-address"><?php echo esc_html( $stored_store_data['address'] ?? '' ); ?></div>
					<div class="store-meta">
						<span class="store-id"><?php echo esc_html( sprintf( __( '門市代號: %s', 'ys-paynow-shipping' ), $stored_store_data['id'] ?? '' ) ); ?></span>
					</div>
				</div>
				<button type="button" class="ys-paynow-store-map-btn button"><?php esc_html_e( '更換門市', 'ys-paynow-shipping' ); ?></button>
			</div>
		<?php else : ?>
			<div class="ys-paynow-no-store">

				<p class="no-store-message"><?php esc_html_e( '尚未選擇取貨門市', 'ys-paynow-shipping' ); ?></p>
				<button type="button" class="ys-paynow-store-map-btn button"><?php esc_html_e( '選擇門市', 'ys-paynow-shipping' ); ?></button>
			</div>
		<?php
		endif;
	}

	

	/**
	 * AJAX: 儲存選擇的超商資訊至 session
	 *
	 * @return void
	 */
	public static function ajax_save_store() {
		check_ajax_referer( 'ys-paynow-shipping', 'nonce' );

		$store_id   = isset( $_POST['store_id'] ) ? sanitize_text_field( wp_unslash( $_POST['store_id'] ) ) : '';
		$store_name = isset( $_POST['store_name'] ) ? sanitize_text_field( wp_unslash( $_POST['store_name'] ) ) : '';
		$store_addr = isset( $_POST['store_addr'] ) ? sanitize_text_field( wp_unslash( $_POST['store_addr'] ) ) : '';
		$store_tel  = isset( $_POST['store_tel'] ) ? sanitize_text_field( wp_unslash( $_POST['store_tel'] ) ) : '';
		$logistic_service_id = isset( $_POST['logistic_service_id'] ) ? sanitize_text_field( wp_unslash( $_POST['logistic_service_id'] ) ) : '';
		$shipping_method = isset( $_POST['shipping_method'] ) ? sanitize_text_field( wp_unslash( $_POST['shipping_method'] ) ) : '';
		$method_id = $shipping_method ? explode( ':', $shipping_method )[0] : '';
		if ( empty( $method_id ) && WC()->session ) {
			$chosen_methods = WC()->session->get( 'chosen_shipping_methods' );
			$method_id = ! empty( $chosen_methods ) ? $chosen_methods[0] : '';
			$method_id = strpos( $method_id, ':' ) !== false ? explode( ':', $method_id )[0] : $method_id;
		}

		// 使用統一格式儲存至 session
		$store_data = array(
			'id'                  => $store_id,
			'name'                => $store_name,
			'address'             => $store_addr,
			'tel'                 => $store_tel,
			'logistic_service_id' => $logistic_service_id,
		);

		// 儲存 PAYNOW 的門市資料
		WC()->session->set( 'ys_paynow_selected_store_data', $store_data );
		WC()->session->set( 'ys_paynow_selected_store_method_id', $method_id );

		YSPaynowShipping::log( 'Store data saved to session: ' . wc_print_r( $store_data, true ) );

		wp_send_json_success( array( 'message' => '門市資料已儲存' ) );
	}

	/**
	 * AJAX: 清除已選擇的超商資訊
	 *
	 * @return void
	 */
	public static function ajax_clear_store_data() {
		check_ajax_referer( 'ys-paynow-shipping', 'nonce' );

		if ( WC()->session ) {
			WC()->session->set( 'ys_paynow_selected_store_data', null );
			WC()->session->set( 'ys_paynow_selected_store_method_id', null );
		}

		YSPaynowShipping::log( 'Store data cleared from session' );

		wp_send_json_success( array( 'message' => '門市資料已清除' ) );
	}

	/**
	 * AJAX: 取得超商地圖 URL
	 *
	 * @return void
	 */
	public static function ajax_get_map_url() {
		// 支援前台和後台的 nonce
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		
		// 驗證前台或後台 nonce
		if ( ! wp_verify_nonce( $nonce, 'ys-paynow-shipping' ) && ! wp_verify_nonce( $nonce, 'ys-paynow-shipping-admin' ) ) {
			wp_send_json_error( array( 'message' => __( '安全驗證失敗，請重新整理頁面', 'ys-paynow-shipping' ) ) );
		}


		$logistic_service = isset( $_POST['logistic_service'] ) ? sanitize_text_field( wp_unslash( $_POST['logistic_service'] ) ) : '';
		$shipping_method  = isset( $_POST['shipping_method'] ) ? sanitize_text_field( wp_unslash( $_POST['shipping_method'] ) ) : '';
		$is_admin_context = isset( $_POST['is_admin_context'] ) && 'true' === $_POST['is_admin_context'];
		$method_id        = $shipping_method ? explode( ':', $shipping_method )[0] : '';

		if ( empty( $logistic_service ) ) {
			wp_send_json_error( array( 'message' => '請選擇超商取貨運送方式' ) );
		}

		$apicode = trim( YSPaynowShipping::$apicode );
		$encrypted_apicode = self::encrypt_apicode( $apicode );

		if ( empty( $encrypted_apicode ) ) {
			YSPaynowShipping::log( 'APICode encryption failed. Code length: ' . strlen( $apicode ), 'error' );
		}

		// 根據前台/後台選擇不同的 callback URL
		// ★ 在 URL 中帶上 logistic_service 和 method_id，因為 PayNow 地圖回傳不會包含這些資訊
		if ( $is_admin_context ) {
			$return_url = esc_url_raw( add_query_arg( array(
				'logistic_service' => $logistic_service,
				'method_id'        => $method_id,
			), WC()->api_request_url( 'ys-paynow-cvs-admin-callback' ) ) );
		} else {
			$return_url = esc_url_raw( add_query_arg( array(
				'cid'              => WC()->cart->get_cart_hash(),
				'logistic_service' => $logistic_service,
				'method_id'        => $method_id,
				'product'          => '40',
			), WC()->api_request_url( 'ys-paynow-cvs-callback' ) ) );
		}

		// 建立 POST 參數 (依照原始外掛邏輯)
		$params = array(
			'source'            => 'shipping_choose_cvs',
			'methods'           => $method_id,
			'Logistic_serviceID'=> $logistic_service,
			'is_paynow_cvs'     => true,
			'user_account'      => YSPaynowShipping::$user_account,
			'orderno'           => '',
			'apicode'           => $encrypted_apicode,
			'returnUrl'         => $return_url,
			'ajax_url'          => YSPaynowShipping::$cvs_map_url,
		);

		YSPaynowShipping::log( 'Prepared map POST parameters: ' . wp_json_encode( $params ) );

		wp_send_json_success( array(
			'action_url' => YSPaynowShipping::$cvs_map_url,
			'params'     => $params,
		) );
	}

	/**
	 * 處理超商地圖回傳
	 *
	 * @return void
	 */
	public static function handle_cvs_callback() {
		// 取得回傳資料（PayNow POST 回傳）
		$store_id   = isset( $_POST['storeid'] ) ? sanitize_text_field( wp_unslash( $_POST['storeid'] ) ) : '';
		$store_name = isset( $_POST['storename'] ) ? sanitize_text_field( wp_unslash( $_POST['storename'] ) ) : '';
		$store_addr = isset( $_POST['storeaddress'] ) ? sanitize_text_field( wp_unslash( $_POST['storeaddress'] ) ) : '';
		$store_tel  = isset( $_POST['storetel'] ) ? sanitize_text_field( wp_unslash( $_POST['storetel'] ) ) : '';

		// ★ 全家冷凍專用欄位：保留編號、預計運送日期
		$reserved_no = isset( $_POST['ReservedNo'] ) ? sanitize_text_field( wp_unslash( $_POST['ReservedNo'] ) ) : '';
		$ship_date   = isset( $_POST['ShipDate'] ) ? sanitize_text_field( wp_unslash( $_POST['ShipDate'] ) ) : '';

		// ★ PayNow 地圖不會回傳 LogisticServiceID 和 method_id，需從 URL query string 取得
		$logistic_service_id = isset( $_GET['logistic_service'] ) ? sanitize_text_field( wp_unslash( $_GET['logistic_service'] ) ) : '';
		$method_id           = isset( $_GET['method_id'] ) ? sanitize_text_field( wp_unslash( $_GET['method_id'] ) ) : '';

		YSPaynowShipping::log( sprintf(
			'CVS Map Return: storeid=%s, storename=%s, logistic_service_id=%s, method_id=%s, reserved_no=%s, ship_date=%s',
			$store_id,
			$store_name,
			$logistic_service_id ?: '(empty)',
			$method_id ?: '(empty)',
			$reserved_no ?: '(empty)',
			$ship_date ?: '(empty)'
		) );

		if ( empty( $store_id ) ) {
			YSPaynowShipping::log( 'CVS Map Return: Missing store data', 'error' );
			wp_redirect( wc_get_checkout_url() );
			exit;
		}

		// 使用統一格式儲存到 session
		$store_data = array(
			'id'                  => $store_id,
			'name'                => $store_name,
			'address'             => $store_addr,
			'tel'                 => $store_tel,
			'logistic_service_id' => $logistic_service_id,
			'reserved_no'         => $reserved_no,
			'ship_date'           => $ship_date,
		);

		// 儲存 PAYNOW 的門市資料
		WC()->session->set( 'ys_paynow_selected_store_data', $store_data );
		WC()->session->set( 'ys_paynow_selected_store_method_id', $method_id );
		YSPaynowShipping::log( 'Store data saved to session (from callback): ' . wc_print_r( $store_data, true ) . ', method_id=' . $method_id );

		// ★ 強制保存 Session（因為後面會 exit，正常的 shutdown hook 不會執行）
		if ( WC()->session && is_callable( array( WC()->session, 'save_data' ) ) ) {
			WC()->session->save_data();
			YSPaynowShipping::log( 'Session data explicitly saved before redirect' );
		}

		// 建立自動提交表單返回結帳頁（參考 PAYUNI）
		$checkout_url = wc_get_checkout_url();

		$html  = '<!doctype html><html ' . get_language_attributes( 'html' ) . '>';
		$html .= '<head><meta charset="' . get_bloginfo( 'charset', 'display' ) . '">';
		$html .= '<title>' . __( '正在返回結帳頁面...', 'ys-paynow-shipping' ) . '</title>';
		$html .= '<style>body { font-family: Arial, sans-serif; text-align: center; padding: 50px; background: #f0f0f0; }</style>';
		$html .= '</head>';
		$html .= '<body>';
		$html .= '<div style="max-width: 400px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">';
		$html .= '<h3 style="color: #333; margin-bottom: 20px;">' . __( '門市選擇完成', 'ys-paynow-shipping' ) . '</h3>';
		$html .= '<p style="color: #666; margin-bottom: 10px;">選擇的門市：<strong>' . esc_html( $store_name ) . '</strong></p>';
		$html .= '<p style="color: #666; margin-bottom: 20px;">門市地址：' . esc_html( $store_addr ) . '</p>';
		$html .= '<p style="color: #888; font-size: 14px;">正在返回結帳頁面...</p>';
		$html .= '</div>';

		// 建立 POST 表單
		$html .= '<form method="post" id="ys-paynow-store-redirect" action="' . esc_url( $checkout_url ) . '">';
		$html .= '<input type="hidden" name="ys_paynow_selected_store_id" value="' . esc_attr( $store_id ) . '">';
		$html .= '<input type="hidden" name="ys_paynow_selected_store_name" value="' . esc_attr( $store_name ) . '">';
		$html .= '<input type="hidden" name="ys_paynow_selected_store_address" value="' . esc_attr( $store_addr ) . '">';
		$html .= '<input type="hidden" name="ys_paynow_selected_store_data" value="' . esc_attr( wp_json_encode( $store_data ) ) . '">';
		$html .= '</form>';
		$html .= '<script type="text/javascript">';
		$html .= 'setTimeout(function(){ document.getElementById("ys-paynow-store-redirect").submit(); }, 1500);';
		$html .= '</script>';
		$html .= '</body></html>';

		echo $html;
		exit;
	}

	/**
	 * 處理後台超商地圖回傳 (Admin)
	 *
	 * 使用 postMessage 將資料傳回 opener 視窗，而非重導向至結帳頁
	 *
	 * @return void
	 */
	public static function handle_cvs_admin_callback() {
		// 取得回傳資料（PayNow POST 回傳）
		$store_id   = isset( $_POST['storeid'] ) ? sanitize_text_field( wp_unslash( $_POST['storeid'] ) ) : '';
		$store_name = isset( $_POST['storename'] ) ? sanitize_text_field( wp_unslash( $_POST['storename'] ) ) : '';
		$store_addr = isset( $_POST['storeaddress'] ) ? sanitize_text_field( wp_unslash( $_POST['storeaddress'] ) ) : '';
		$store_tel  = isset( $_POST['storetel'] ) ? sanitize_text_field( wp_unslash( $_POST['storetel'] ) ) : '';

		// ★ 全家冷凍專用欄位：保留編號、預計運送日期
		$reserved_no = isset( $_POST['ReservedNo'] ) ? sanitize_text_field( wp_unslash( $_POST['ReservedNo'] ) ) : '';
		$ship_date   = isset( $_POST['ShipDate'] ) ? sanitize_text_field( wp_unslash( $_POST['ShipDate'] ) ) : '';

		// ★ PayNow 地圖不會回傳 LogisticServiceID 和 method_id，需從 URL query string 取得
		$logistic_service_id = isset( $_GET['logistic_service'] ) ? sanitize_text_field( wp_unslash( $_GET['logistic_service'] ) ) : '';
		$method_id           = isset( $_GET['method_id'] ) ? sanitize_text_field( wp_unslash( $_GET['method_id'] ) ) : '';

		YSPaynowShipping::log( sprintf(
			'Admin CVS Map Return: storeid=%s, storename=%s, logistic_service_id=%s, method_id=%s, reserved_no=%s, ship_date=%s',
			$store_id,
			$store_name,
			$logistic_service_id ?: '(empty)',
			$method_id ?: '(empty)',
			$reserved_no ?: '(empty)',
			$ship_date ?: '(empty)'
		) );

		// 建立 postMessage 資料
		$store_data_json = wp_json_encode( array(
			'store_id'            => $store_id,
			'store_name'          => $store_name,
			'store_addr'          => $store_addr,
			'store_tel'           => $store_tel,
			'logistic_service_id' => $logistic_service_id,
			'method_id'           => $method_id,
			'reserved_no'         => $reserved_no,
			'ship_date'           => $ship_date,
		) );

		// 輸出 HTML 頁面，使用 postMessage 傳送資料至 opener
		$html  = '<!doctype html><html ' . get_language_attributes( 'html' ) . '>';
		$html .= '<head><meta charset="' . get_bloginfo( 'charset', 'display' ) . '">';
		$html .= '<title>' . __( '門市選擇完成', 'ys-paynow-shipping' ) . '</title>';
		$html .= '<style>body { font-family: Arial, sans-serif; text-align: center; padding: 50px; background: #f0f0f0; }</style>';
		$html .= '</head>';
		$html .= '<body>';
		$html .= '<div style="max-width: 400px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">';
		$html .= '<h3 style="color: #333; margin-bottom: 20px;">' . __( '門市選擇完成', 'ys-paynow-shipping' ) . '</h3>';
		$html .= '<p style="color: #666; margin-bottom: 10px;">選擇的門市：<strong>' . esc_html( $store_name ) . '</strong></p>';
		$html .= '<p style="color: #666; margin-bottom: 20px;">門市地址：' . esc_html( $store_addr ) . '</p>';
		$html .= '<p style="color: #888; font-size: 14px;">視窗將自動關閉...</p>';
		$html .= '</div>';
		$html .= '<script type="text/javascript">';
		$html .= 'try {';
		$html .= '  if (window.opener && !window.opener.closed) {';
		$html .= '    window.opener.postMessage({';
		$html .= '      action: "ys_paynow_cvs_selected",';
		$html .= '      data: ' . $store_data_json;
		$html .= '    }, "*");';  // In production, should use specific origin
		$html .= '    console.log("postMessage sent to opener");';
		$html .= '  } else {';
		$html .= '    console.warn("No opener window found");';
		$html .= '  }';
		$html .= '} catch(e) { console.error("postMessage error:", e); }';
		$html .= 'setTimeout(function(){ window.close(); }, 2000);';
		$html .= '</script>';
		$html .= '</body></html>';

		echo $html;
		exit;
	}

	/**
	 * 從 POST 資料恢復門市資料到 Session
	 * 
	 * 注意：只在非 AJAX 請求時，且有有效的門市 ID 時才恢復
	 * 這是用於處理從 PayNow 地圖回調後的表單重導向
	 *
	 * @return array|false
	 */
	private static function restore_store_data_from_post() {
		// 如果是 AJAX 請求，不要從 POST 恢復（避免覆蓋已清除的 session）
		if ( wp_doing_ajax() ) {
			return false;
		}

		// 必須有有效的門市 ID
		if ( ! isset( $_POST['ys_paynow_selected_store_id'] ) ) {
			return false;
		}

		$store_id = sanitize_text_field( wp_unslash( $_POST['ys_paynow_selected_store_id'] ?? '' ) );
		
		// store_id 必須非空才恢復
		if ( empty( $store_id ) ) {
			return false;
		}

		$store_name    = sanitize_text_field( wp_unslash( $_POST['ys_paynow_selected_store_name'] ?? '' ) );
		$store_address = sanitize_text_field( wp_unslash( $_POST['ys_paynow_selected_store_address'] ?? '' ) );
		$store_data_json = isset( $_POST['ys_paynow_selected_store_data'] ) ? wp_unslash( $_POST['ys_paynow_selected_store_data'] ) : '';

		$store_data = array(
			'id'      => $store_id,
			'name'    => $store_name,
			'address' => $store_address,
		);

		// 使用 JSON 資料（如果有）
		if ( ! empty( $store_data_json ) ) {
			$parsed_data = json_decode( $store_data_json, true );
			if ( json_last_error() === JSON_ERROR_NONE && $parsed_data ) {
				$store_data = $parsed_data;
			}
		}

		WC()->session->set( 'ys_paynow_selected_store_data', $store_data );

		// ★ 同時恢復 method_id（根據 logistic_service_id 推斷）
		$logistic_service_id = $store_data['logistic_service_id'] ?? '';
		if ( ! empty( $logistic_service_id ) ) {
			$method_id = self::get_method_id_from_service( $logistic_service_id );
			if ( ! empty( $method_id ) ) {
				WC()->session->set( 'ys_paynow_selected_store_method_id', $method_id );
			}
		}

		return $store_data;
	}

	/**
	 * 新增門市選擇器 HTML 到 checkout fragments
	 *
	 * 這確保當用戶切換物流方式時，門市選擇器會正確更新。
	 * 使用獨立的 fragment selector，讓前端 JS 可以替換整個選擇器區塊。
	 *
	 * @param array $fragments AJAX fragments 陣列。
	 * @return array
	 */
	public static function add_store_selector_fragment( $fragments ) {
		// 重置渲染標記，允許在 fragment 中重新渲染
		self::$selector_rendered = false;

		// 檢查是否選擇了 YS PayNow 超商取貨運送方式
		if ( ! WC()->session ) {
			return $fragments;
		}

		$chosen_methods = WC()->session->get( 'chosen_shipping_methods' );
		if ( empty( $chosen_methods ) ) {
			// 沒有選擇物流時，輸出空的容器
			$fragments['#ys-paynow-store-selector-container'] = '<div id="ys-paynow-store-selector-container" class="ys-paynow-store-selector-wrapper"></div>';
			return $fragments;
		}

		$shipping_method = $chosen_methods[0];
		$method_id       = strpos( $shipping_method, ':' ) !== false ? explode( ':', $shipping_method )[0] : $shipping_method;

		// 如果不是 YS PayNow 超取，輸出空的容器
		if ( ! YSPaynowShipping::needs_cvs( $method_id ) ) {
			$fragments['#ys-paynow-store-selector-container'] = '<div id="ys-paynow-store-selector-container" class="ys-paynow-store-selector-wrapper"></div>';
			return $fragments;
		}

		// 取得物流服務 ID
		$logistic_service_id = YSPaynowShipping::get_logistic_service_id( $method_id );

		// 獲取已選擇的門市資料
		$stored_store_data = WC()->session->get( 'ys_paynow_selected_store_data', array() );

		// 檢查門市類型是否匹配當前選擇的超商
		if ( ! empty( $stored_store_data ) && is_array( $stored_store_data ) && ! empty( $stored_store_data['logistic_service_id'] ) ) {
			$stored_service_id = $stored_store_data['logistic_service_id'];
			if ( $stored_service_id !== $logistic_service_id ) {
				WC()->session->set( 'ys_paynow_selected_store_data', null );
				$stored_store_data = array();
			}
		}

		// 使用 output buffering 捕獲選擇器 HTML
		ob_start();
		?>
		<div id="ys-paynow-store-selector-container" class="ys-paynow-store-selector-wrapper">
			<div class="ys-paynow-store-selector" data-method-id="<?php echo esc_attr( $method_id ); ?>" data-logistic-service="<?php echo esc_attr( $logistic_service_id ); ?>">
				<?php self::render_store_selector_content( $stored_store_data, $logistic_service_id ); ?>
			</div>
		</div>
		<?php
		$fragments['#ys-paynow-store-selector-container'] = ob_get_clean();

		// 標記為已渲染
		self::$selector_rendered = true;

		return $fragments;
	}

	/**
	 * 新增門市資料到 checkout fragments
	 *
	 * @param array $fragments AJAX fragments 陣列。
	 * @return array
	 */
	public static function add_store_data_fragment( $fragments ) {
		$stored_store_data = null;
		if ( WC()->session ) {
			$stored_store_data = WC()->session->get( 'ys_paynow_selected_store_data', null );
		}

		// ★ 驗證門市類型是否匹配當前選擇的物流
		if ( $stored_store_data && is_array( $stored_store_data ) && ! empty( $stored_store_data['id'] ) ) {
			// 取得當前選擇的物流
			$chosen_methods = WC()->session ? WC()->session->get( 'chosen_shipping_methods' ) : array();
			if ( ! empty( $chosen_methods ) ) {
				$current_method    = $chosen_methods[0];
				$current_method_id = strpos( $current_method, ':' ) !== false ? explode( ':', $current_method )[0] : $current_method;
				$current_logistic_service_id = YSPaynowShipping::get_logistic_service_id( $current_method_id );

				$stored_service_id = $stored_store_data['logistic_service_id'] ?? '';

				// 門市的 logistic_service_id 與當前物流不符，清除門市資料
				if ( ! empty( $stored_service_id ) && ! empty( $current_logistic_service_id ) && $stored_service_id !== $current_logistic_service_id ) {
					WC()->session->set( 'ys_paynow_selected_store_data', null );
					$stored_store_data = null;
				}
			}
		}

		if ( $stored_store_data ) {
			$fragments['ys_paynow_stored_data'] = $stored_store_data;
		}

		return $fragments;
	}

	/**
	 * 儲存門市資料到訂單
	 *
	 * @param \WC_Order $order 訂單物件。
	 * @param array     $data  訂單資料。
	 * @return void
	 */
	public static function save_store_to_order( $order, $data ) {
		YSPaynowShipping::log( 'save_store_to_order called for order #' . $order->get_id() );

		// 首先從 POST 取得資料
		$selected_store_id   = sanitize_text_field( $_POST['ys_paynow_selected_store_id'] ?? '' );
		$selected_store_data = isset( $_POST['ys_paynow_selected_store_data'] ) ? wp_unslash( $_POST['ys_paynow_selected_store_data'] ) : '';

		YSPaynowShipping::log( 'POST store_id: ' . $selected_store_id );

		// 如果沒有 POST 資料，嘗試從 session 取得
		if ( ( empty( $selected_store_id ) || empty( $selected_store_data ) ) && WC()->session ) {
			$stored_data = WC()->session->get( 'ys_paynow_selected_store_data', null );
			YSPaynowShipping::log( 'Session store data: ' . wc_print_r( $stored_data, true ) );
			if ( $stored_data && is_array( $stored_data ) ) {
				$selected_store_id   = $stored_data['id'] ?? '';
				$selected_store_data = wp_json_encode( $stored_data );
			}
		}

		if ( empty( $selected_store_id ) ) {
			YSPaynowShipping::log( 'No store_id found, skipping store save' );
			return;
		}

		// 解析 JSON 資料
		$store_data = json_decode( $selected_store_data, true );
		if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $store_data ) ) {
			return;
		}

		// ★ 智慧取用收件人姓名電話
		// 根據運送區域啟用狀態決定來源：
		// - 有啟用運送區域：優先使用運送區域姓名電話
		// - 沒有啟用運送區域：使用帳單區域姓名電話
		$recipient_info = self::get_smart_recipient_info( $order, $data );
		YSPaynowShipping::log( 'Smart recipient info: ' . wc_print_r( $recipient_info, true ) );

		// 準備統一的 JSON 格式資料
		$unified_store_data = array(
			'id'                  => $store_data['id'] ?? '',
			'name'                => $store_data['name'] ?? '',
			'address'             => $store_data['address'] ?? '',
			'tel'                 => $store_data['tel'] ?? '',
			'logistic_service_id' => $store_data['logistic_service_id'] ?? '',
			'reserved_no'         => $store_data['reserved_no'] ?? '',
			'ship_date'           => $store_data['ship_date'] ?? '',
		);

		// 儲存統一的 JSON 格式
		$order->update_meta_data( YSOrderMeta::STORE_DATA_JSON, wp_json_encode( $unified_store_data ) );

		// 舊格式儲存（向下相容）
		$order->update_meta_data( YSOrderMeta::StoreId, $store_data['id'] ?? '' );
		$order->update_meta_data( YSOrderMeta::StoreName, $store_data['name'] ?? '' );
		$order->update_meta_data( YSOrderMeta::StoreAddr, $store_data['address'] ?? '' );
		$order->update_meta_data( YSOrderMeta::StoreTel, $store_data['tel'] ?? '' );

		// ★ 全家冷凍專用欄位
		if ( ! empty( $store_data['reserved_no'] ) ) {
			$order->update_meta_data( YSOrderMeta::ReservedNo, $store_data['reserved_no'] );
		}
		if ( ! empty( $store_data['ship_date'] ) ) {
			$order->update_meta_data( YSOrderMeta::ShipDate, $store_data['ship_date'] );
		}

		// ★ 儲存收件人資訊（用於物流標籤列印）
		$order->update_meta_data( '_ys_paynow_recipient_name', $recipient_info['name'] );
		$order->update_meta_data( '_ys_paynow_recipient_phone', $recipient_info['phone'] );
		$order->update_meta_data( '_ys_paynow_recipient_source', $recipient_info['source'] );

		YSPaynowShipping::log( 'Store data saved to order #' . $order->get_id() . ': ' . wc_print_r( $unified_store_data, true ) );

		// 清除 session
		if ( WC()->session ) {
			WC()->session->set( 'ys_paynow_selected_store_data', null );
		}
	}

	/**
	 * 智慧取用收件人姓名電話
	 *
	 * 根據 WooCommerce 運送區域設定決定資料來源：
	 * - 有啟用運送區域且有運送姓名：優先使用運送區域資料
	 * - 沒有運送區域或運送姓名為空：使用帳單區域資料
	 *
	 * @param \WC_Order $order 訂單物件。
	 * @param array     $data  訂單資料（來自 $_POST）。
	 * @return array 包含 name, phone, source 的陣列。
	 */
	private static function get_smart_recipient_info( $order, $data ) {
		// 檢查是否有啟用運送區域（ship_to_different_address）
		$ship_to_different = ! empty( $data['ship_to_different_address'] );

		// 取得運送姓名
		$shipping_first = $order->get_shipping_first_name();
		$shipping_last  = $order->get_shipping_last_name();
		$shipping_name  = trim( $shipping_last . $shipping_first );
		$shipping_phone = $order->get_shipping_phone();

		// 取得帳單姓名
		$billing_first = $order->get_billing_first_name();
		$billing_last  = $order->get_billing_last_name();
		$billing_name  = trim( $billing_last . $billing_first );
		$billing_phone = $order->get_billing_phone();

		// 決定使用哪個來源
		// 條件：勾選「運送到不同地址」且運送姓名非空
		if ( $ship_to_different && ! empty( $shipping_name ) ) {
			return array(
				'name'   => $shipping_name,
				'phone'  => ! empty( $shipping_phone ) ? $shipping_phone : $billing_phone,
				'source' => 'shipping',
			);
		}

		// 預設使用帳單區域
		return array(
			'name'   => $billing_name,
			'phone'  => $billing_phone,
			'source' => 'billing',
		);
	}

	/**
	 * 取得訂單的門市資料（支援新舊格式）
	 *
	 * @param int $order_id 訂單 ID。
	 * @return array|null
	 */
	public static function get_order_store_data( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return null;
		}

		// 優先讀取新的統一 JSON 格式
		$json_data = $order->get_meta( YSOrderMeta::STORE_DATA_JSON );
		if ( ! empty( $json_data ) ) {
			$store_data = json_decode( $json_data, true );
			if ( is_array( $store_data ) && ! empty( $store_data['id'] ) ) {
				return $store_data;
			}
		}

		// 從舊格式讀取
		$store_id = $order->get_meta( YSOrderMeta::StoreId );
		if ( ! empty( $store_id ) ) {
			return array(
				'id'      => $store_id,
				'name'    => $order->get_meta( YSOrderMeta::StoreName ),
				'address' => $order->get_meta( YSOrderMeta::StoreAddr ),
				'tel'     => $order->get_meta( YSOrderMeta::StoreTel ),
			);
		}

		return null;
	}

	/**
	 * 加密 APICode (專 用於地圖 API)
	 *
	 * @param string $apicode 用戶 API 代碼。
	 * @return string
	 */
	private static function encrypt_apicode( $apicode ) {
		// key length = 24.
		$key = '123456789070828783123456';
		$encrypted = openssl_encrypt( $apicode, 'DES-EDE3', $key, OPENSSL_ZERO_PADDING );
		return $encrypted;
	}
}
