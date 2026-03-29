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
<div class="ys-marketplace-wrap">

    <!-- 頁面標題 -->
    <div class="ys-marketplace-header">
        <h1 class="ys-marketplace-title">
            <span class="dashicons dashicons-store"></span>
            <?php echo esc_html__( 'YS 外掛市集', 'ys-plugin-hub-client' ); ?>
        </h1>
        <div class="ys-marketplace-actions">
            <button type="button" id="ys-refresh-btn" class="ys-btn ys-btn-outline">
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

    <!-- 連線設定區 -->
    <div class="ys-settings-section">
        <h2 class="ys-settings-title">
            <span class="dashicons dashicons-admin-settings"></span>
            <?php echo esc_html__( '連線設定', 'ys-plugin-hub-client' ); ?>
        </h2>

        <div class="ys-settings-grid">
            <!-- Hub 伺服器（readonly） -->
            <div class="ys-setting-row">
                <label for="ys-hub-url">
                    <?php echo esc_html__( 'Hub 伺服器', 'ys-plugin-hub-client' ); ?>
                </label>
                <input type="url"
                       id="ys-hub-url"
                       value="<?php echo esc_attr( YS_HUB_CLIENT_HUB_URL ); ?>"
                       readonly
                />
            </div>

            <!-- Site Key -->
            <div class="ys-setting-row">
                <label for="ys-site-key">
                    <?php echo esc_html__( 'Site Key', 'ys-plugin-hub-client' ); ?>
                </label>
                <div class="ys-setting-inline">
                    <input type="text"
                           id="ys-site-key"
                           value="<?php echo esc_attr( $site_key ); ?>"
                           placeholder="<?php echo esc_attr__( '輸入或自動產生', 'ys-plugin-hub-client' ); ?>"
                    />
                    <button type="button" id="ys-generate-key" class="ys-btn ys-btn-outline ys-btn-sm">
                        <?php echo esc_html__( '自動產生', 'ys-plugin-hub-client' ); ?>
                    </button>
                </div>
            </div>

            <!-- 自動檢查更新 -->
            <div class="ys-setting-row">
                <label><?php echo esc_html__( '更新設定', 'ys-plugin-hub-client' ); ?></label>
                <div class="ys-setting-checkbox">
                    <input type="checkbox"
                           id="ys-auto-check"
                           <?php checked( $auto_check, 'yes' ); ?>
                    />
                    <label for="ys-auto-check">
                        <?php echo esc_html__( '自動檢查更新', 'ys-plugin-hub-client' ); ?>
                    </label>
                </div>
            </div>

            <!-- 連線狀態 -->
            <div class="ys-setting-row">
                <label><?php echo esc_html__( '連線狀態', 'ys-plugin-hub-client' ); ?></label>
                <div id="ys-connection-status"
                     class="ys-connection-status ys-status-<?php echo esc_attr( $cb_state ); ?>">
                    <span class="ys-status-dot"></span>
                    <span class="ys-status-label"><?php echo esc_html( $cb_label ); ?></span>
                </div>
            </div>
        </div>

        <!-- 操作按鈕 -->
        <div class="ys-settings-actions">
            <button type="button" id="ys-save-settings" class="ys-btn ys-btn-primary">
                <span class="dashicons dashicons-yes-alt"></span>
                <?php echo esc_html__( '儲存設定', 'ys-plugin-hub-client' ); ?>
            </button>
            <button type="button" id="ys-test-connection" class="ys-btn ys-btn-outline">
                <span class="dashicons dashicons-admin-links"></span>
                <?php echo esc_html__( '測試連線', 'ys-plugin-hub-client' ); ?>
            </button>
        </div>
    </div>

</div>
