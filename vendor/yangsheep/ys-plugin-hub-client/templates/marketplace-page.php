<?php
/**
 * 市集頁面模板
 *
 * 渲染 skeleton loading → 前端 AJAX 載入外掛列表。
 *
 * @package YangSheep\PluginHubClient
 *
 * @var string $site_key   站台識別金鑰
 * @var string $auto_check 自動檢查更新 (yes/no)
 * @var string $cb_state   Circuit Breaker 狀態
 * @var string $cb_label   Circuit Breaker 狀態中文標籤
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="wrap">
<div class="ys-marketplace-wrap">

    <!-- 色塊 Header -->
    <div class="ys-page-hero">
        <div class="ys-page-hero-content">
            <h1>
                <span class="dashicons dashicons-store"></span>
                <?php echo esc_html__( 'YS 外掛市集', 'ys-plugin-hub-client' ); ?>
            </h1>
            <p><?php echo esc_html__( 'YANGSHEEP DESIGN 電商工具箱 — 瀏覽、安裝、管理所有 YS 外掛', 'ys-plugin-hub-client' ); ?></p>
        </div>
        <div class="ys-page-hero-actions">
            <span id="ys-hub-status" class="ys-hub-status ys-hub-status-checking" title="<?php echo esc_attr__( '檢查連線中...', 'ys-plugin-hub-client' ); ?>">
                <span class="ys-hub-status-dot"></span>
                <span class="ys-hub-status-text"><?php echo esc_html__( '連線中...', 'ys-plugin-hub-client' ); ?></span>
            </span>
            <button type="button" id="ys-refresh-btn" class="ys-btn ys-btn-hero">
                <span class="dashicons dashicons-update"></span>
                <?php echo esc_html__( '檢查更新', 'ys-plugin-hub-client' ); ?>
            </button>
        </div>
    </div>

    <!-- 公告區 -->
    <div id="ys-announcements" class="ys-announcements-wrap" style="display:none;">
        <!-- JS 動態渲染 -->
    </div>

    <!-- 工具列 -->
    <div class="ys-marketplace-toolbar">
        <div class="ys-filter-tabs" id="ys-filter-tabs">
            <button type="button" class="ys-filter-tab active" data-category="all">
                <?php echo esc_html__( '全部', 'ys-plugin-hub-client' ); ?>
            </button>
            <!-- 動態分類由 JS 填入 -->
        </div>
        <div class="ys-search-box">
            <span class="dashicons dashicons-search"></span>
            <input type="text"
                   id="ys-search-input"
                   placeholder="<?php echo esc_attr__( '搜尋外掛...', 'ys-plugin-hub-client' ); ?>"
            />
        </div>
    </div>

    <!-- 外掛網格（初始為 skeleton） -->
    <div id="ys-plugin-grid" class="ys-plugin-grid">
        <!-- JS 會自動填入 skeleton 或外掛卡片 -->
    </div>

    <!-- 市集底部 -->
    <div class="ys-marketplace-footer">
        <p>
            <?php echo wp_kses_post(
                sprintf(
                    /* translators: %s: YANGSHEEP CLOUD link */
                    __( '由 %s 開發與維護', 'ys-plugin-hub-client' ),
                    '<a href="https://yangsheep.com.tw" target="_blank" rel="noopener noreferrer">YANGSHEEP CLOUD</a>'
                )
            ); ?>
        </p>
    </div>

    <!-- 隱藏欄位（保留功能但不向用戶顯示任何設定） -->
    <input type="hidden" id="ys-hub-url" value="<?php echo esc_attr( YS_HUB_CLIENT_HUB_URL ); ?>" />
    <input type="hidden" id="ys-site-key" value="<?php echo esc_attr( $site_key ); ?>" />
    <input type="hidden" id="ys-auto-check" value="yes" />

</div><!-- .ys-marketplace-wrap -->
</div><!-- .wrap -->
