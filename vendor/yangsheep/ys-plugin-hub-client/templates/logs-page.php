<?php
/**
 * 系統紀錄頁面模板
 *
 * @package YangSheep\PluginHubClient
 * @var array  $logs   日誌列表
 * @var int    $total  日誌總數
 * @var string $filter_level  篩選等級
 * @var string $filter_action 篩選操作
 * @var int    $page   目前頁碼
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap">
    <div class="ys-marketplace-wrap">

        <!-- 色塊 Header -->
        <div class="ys-page-hero">
            <div class="ys-page-hero-content">
                <h1>
                    <span class="dashicons dashicons-list-view"></span>
                    <?php esc_html_e( '系統紀錄', 'ys-plugin-hub-client' ); ?>
                </h1>
                <p><?php esc_html_e( '記錄所有外掛安裝、更新、連線操作（自動保留 30 天）', 'ys-plugin-hub-client' ); ?></p>
            </div>
        </div>

        <!-- 篩選列 -->
        <div class="ys-log-toolbar">
            <div class="ys-log-filters">
                <select id="ys-log-level-filter" class="ys-select">
                    <option value=""><?php esc_html_e( '全部等級', 'ys-plugin-hub-client' ); ?></option>
                    <option value="info" <?php selected( $filter_level, 'info' ); ?>><?php esc_html_e( '資訊', 'ys-plugin-hub-client' ); ?></option>
                    <option value="success" <?php selected( $filter_level, 'success' ); ?>><?php esc_html_e( '成功', 'ys-plugin-hub-client' ); ?></option>
                    <option value="warning" <?php selected( $filter_level, 'warning' ); ?>><?php esc_html_e( '警告', 'ys-plugin-hub-client' ); ?></option>
                    <option value="error" <?php selected( $filter_level, 'error' ); ?>><?php esc_html_e( '錯誤', 'ys-plugin-hub-client' ); ?></option>
                </select>

                <select id="ys-log-action-filter" class="ys-select">
                    <option value=""><?php esc_html_e( '全部操作', 'ys-plugin-hub-client' ); ?></option>
                    <option value="install" <?php selected( $filter_action, 'install' ); ?>><?php esc_html_e( '安裝', 'ys-plugin-hub-client' ); ?></option>
                    <option value="update" <?php selected( $filter_action, 'update' ); ?>><?php esc_html_e( '更新', 'ys-plugin-hub-client' ); ?></option>
                    <option value="activate" <?php selected( $filter_action, 'activate' ); ?>><?php esc_html_e( '啟用', 'ys-plugin-hub-client' ); ?></option>
                    <option value="connect" <?php selected( $filter_action, 'connect' ); ?>><?php esc_html_e( '連線', 'ys-plugin-hub-client' ); ?></option>
                </select>

                <button id="ys-log-filter-btn" class="ys-btn ys-btn-outline ys-btn-sm"><?php esc_html_e( '篩選', 'ys-plugin-hub-client' ); ?></button>
            </div>

            <button id="ys-log-clear-btn" class="ys-btn ys-btn-sm" style="color:#c08080;border:1px solid #c08080;background:transparent;">
                <span class="dashicons dashicons-trash" style="font-size:14px;width:14px;height:14px;"></span>
                <?php esc_html_e( '清除全部日誌', 'ys-plugin-hub-client' ); ?>
            </button>
        </div>

        <!-- 日誌表格 -->
        <table class="widefat striped ys-log-table">
            <thead>
                <tr>
                    <th style="width:150px;"><?php esc_html_e( '時間', 'ys-plugin-hub-client' ); ?></th>
                    <th style="width:70px;"><?php esc_html_e( '等級', 'ys-plugin-hub-client' ); ?></th>
                    <th style="width:80px;"><?php esc_html_e( '操作', 'ys-plugin-hub-client' ); ?></th>
                    <th><?php esc_html_e( '訊息', 'ys-plugin-hub-client' ); ?></th>
                </tr>
            </thead>
            <tbody id="ys-log-tbody">
                <?php if ( empty( $logs ) ) : ?>
                    <tr><td colspan="4" style="text-align:center;color:#7b8a96;padding:32px;">
                        <?php esc_html_e( '目前沒有日誌記錄', 'ys-plugin-hub-client' ); ?>
                    </td></tr>
                <?php else : ?>
                    <?php foreach ( $logs as $log ) : ?>
                    <tr>
                        <td style="font-size:12px;color:#7b8a96;white-space:nowrap;">
                            <?php echo esc_html( wp_date( 'Y-m-d H:i:s', strtotime( $log->created_at ) ) ); ?>
                        </td>
                        <td>
                            <?php
                            $level_map = array(
                                'info'    => array( '資訊', '#e8eff5', '#6b8a9a' ),
                                'success' => array( '成功', '#e8f3ec', '#7dab8e' ),
                                'warning' => array( '警告', '#faf2e5', '#c4a67a' ),
                                'error'   => array( '錯誤', '#f8e8e8', '#c08080' ),
                            );
                            $lv = $level_map[ $log->level ] ?? $level_map['info'];
                            ?>
                            <span style="background:<?php echo esc_attr( $lv[1] ); ?>;color:<?php echo esc_attr( $lv[2] ); ?>;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;">
                                <?php echo esc_html( $lv[0] ); ?>
                            </span>
                        </td>
                        <td style="font-size:12px;">
                            <?php
                            $act_map = array( 'install' => '安裝', 'update' => '更新', 'activate' => '啟用', 'check' => '檢查', 'connect' => '連線', 'sync' => '同步' );
                            echo esc_html( $act_map[ $log->action ] ?? $log->action );
                            ?>
                        </td>
                        <td><?php echo esc_html( $log->message ); ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <!-- 分頁 -->
        <?php if ( $total > 50 ) : ?>
        <div style="margin-top:16px;display:flex;gap:8px;justify-content:center;">
            <?php
            $total_pages = ceil( $total / 50 );
            for ( $i = 1; $i <= min( $total_pages, 10 ); $i++ ) :
                $url = add_query_arg( array( 'paged' => $i, 'level' => $filter_level, 'action_filter' => $filter_action ), admin_url( 'admin.php?page=ys-hub-logs' ) );
            ?>
                <a href="<?php echo esc_url( $url ); ?>" style="padding:4px 10px;border:1px solid #e2e8ed;border-radius:4px;text-decoration:none;<?php echo $page === $i ? 'font-weight:700;color:#6b8a9a;' : ''; ?>">
                    <?php echo esc_html( $i ); ?>
                </a>
            <?php endfor; ?>
        </div>
        <?php endif; ?>

        <!-- Footer -->
        <div class="ys-marketplace-footer">
            <p>由 <a href="https://yangsheep.com.tw" target="_blank" rel="noopener noreferrer">YANGSHEEP CLOUD</a> 開發與維護</p>
        </div>

    </div>
</div>

<script>
jQuery(function($){
    $('#ys-log-filter-btn').on('click', function(){
        var level = $('#ys-log-level-filter').val();
        var action = $('#ys-log-action-filter').val();
        var url = '<?php echo esc_js( admin_url( 'admin.php?page=ys-hub-logs' ) ); ?>';
        if(level) url += '&level=' + level;
        if(action) url += '&action_filter=' + action;
        window.location.href = url;
    });
    $('#ys-log-clear-btn').on('click', function(){
        if(!confirm('<?php echo esc_js( __( '確定要清除所有日誌？', 'ys-plugin-hub-client' ) ); ?>')) return;
        var btn = $(this);
        btn.prop('disabled', true);
        $.post(ysHubClient.ajaxUrl, {
            action: 'ys_hub_client_clear_logs',
            nonce: ysHubClient.nonce
        }, function(r){ if(r.success) window.location.reload(); }).always(function(){ btn.prop('disabled', false); });
    });
});
</script>
