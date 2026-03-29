<?php
/**
 * 日誌頁面模板
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
<div class="wrap ys-marketplace-wrap">

    <h1 class="ys-page-title">
        <span class="dashicons dashicons-list-view"></span>
        <?php esc_html_e( '操作日誌', 'ys-plugin-hub-client' ); ?>
    </h1>

    <!-- 篩選列 -->
    <div class="ys-log-filters" style="display:flex;gap:10px;margin:16px 0;align-items:center;">
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
            <option value="check" <?php selected( $filter_action, 'check' ); ?>><?php esc_html_e( '檢查更新', 'ys-plugin-hub-client' ); ?></option>
            <option value="connect" <?php selected( $filter_action, 'connect' ); ?>><?php esc_html_e( '連線', 'ys-plugin-hub-client' ); ?></option>
            <option value="circuit_breaker" <?php selected( $filter_action, 'circuit_breaker' ); ?>><?php esc_html_e( '熔斷器', 'ys-plugin-hub-client' ); ?></option>
            <option value="sync" <?php selected( $filter_action, 'sync' ); ?>><?php esc_html_e( '同步', 'ys-plugin-hub-client' ); ?></option>
        </select>

        <button id="ys-log-filter-btn" class="button button-secondary"><?php esc_html_e( '篩選', 'ys-plugin-hub-client' ); ?></button>
        <button id="ys-log-clear-btn" class="button" style="color:var(--ys-adm-danger,#c08080);margin-left:auto;"><?php esc_html_e( '清除全部日誌', 'ys-plugin-hub-client' ); ?></button>
    </div>

    <!-- 日誌表格 -->
    <table class="widefat striped ys-log-table" style="border-color:var(--ys-adm-border,#e2e8ed);">
        <thead>
            <tr>
                <th style="width:140px;"><?php esc_html_e( '時間', 'ys-plugin-hub-client' ); ?></th>
                <th style="width:70px;"><?php esc_html_e( '等級', 'ys-plugin-hub-client' ); ?></th>
                <th style="width:100px;"><?php esc_html_e( '操作', 'ys-plugin-hub-client' ); ?></th>
                <th><?php esc_html_e( '訊息', 'ys-plugin-hub-client' ); ?></th>
            </tr>
        </thead>
        <tbody id="ys-log-tbody">
            <?php if ( empty( $logs ) ) : ?>
                <tr><td colspan="4" style="text-align:center;color:var(--ys-adm-text-muted,#7b8a96);padding:24px;">
                    <?php esc_html_e( '目前沒有日誌記錄', 'ys-plugin-hub-client' ); ?>
                </td></tr>
            <?php else : ?>
                <?php foreach ( $logs as $log ) : ?>
                <tr>
                    <td style="font-size:12px;color:var(--ys-adm-text-muted,#7b8a96);white-space:nowrap;">
                        <?php echo esc_html( wp_date( 'Y-m-d H:i:s', strtotime( $log->created_at ) ) ); ?>
                    </td>
                    <td>
                        <?php
                        $level_colors = array(
                            'info'    => 'background:var(--ys-adm-info-bg,#e8eff5);color:var(--ys-adm-primary-dark,#6b8a9a);',
                            'success' => 'background:var(--ys-adm-success-bg,#e8f3ec);color:var(--ys-adm-success,#7dab8e);',
                            'warning' => 'background:var(--ys-adm-warning-bg,#faf2e5);color:var(--ys-adm-warning,#c4a67a);',
                            'error'   => 'background:var(--ys-adm-danger-bg,#f8e8e8);color:var(--ys-adm-danger,#c08080);',
                        );
                        $style = $level_colors[ $log->level ] ?? $level_colors['info'];
                        $label_map = array( 'info' => '資訊', 'success' => '成功', 'warning' => '警告', 'error' => '錯誤' );
                        ?>
                        <span style="<?php echo esc_attr( $style ); ?>padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;">
                            <?php echo esc_html( $label_map[ $log->level ] ?? $log->level ); ?>
                        </span>
                    </td>
                    <td style="font-size:12px;">
                        <?php
                        $action_map = array(
                            'install'         => '安裝',
                            'update'          => '更新',
                            'check'           => '檢查更新',
                            'connect'         => '連線',
                            'circuit_breaker' => '熔斷器',
                            'sync'            => '同步',
                        );
                        echo esc_html( $action_map[ $log->action ] ?? $log->action );
                        ?>
                    </td>
                    <td>
                        <?php echo esc_html( $log->message ); ?>
                        <?php if ( ! empty( $log->context ) ) : ?>
                            <button class="button-link ys-log-context-toggle" data-context="<?php echo esc_attr( $log->context ); ?>" style="font-size:11px;color:var(--ys-adm-primary,#8fa8b8);">
                                <?php esc_html_e( '詳情', 'ys-plugin-hub-client' ); ?>
                            </button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <!-- 分頁 -->
    <?php if ( $total > 50 ) : ?>
    <div class="ys-log-pagination" style="margin-top:16px;display:flex;gap:8px;justify-content:center;">
        <?php
        $total_pages = ceil( $total / 50 );
        $base_url    = admin_url( 'admin.php?page=ys-hub-logs' );
        for ( $i = 1; $i <= min( $total_pages, 10 ); $i++ ) :
            $url = add_query_arg( array( 'paged' => $i, 'level' => $filter_level, 'action_filter' => $filter_action ), $base_url );
            $active = ( $page === $i ) ? 'font-weight:700;color:var(--ys-adm-primary-dark,#6b8a9a);' : '';
        ?>
            <a href="<?php echo esc_url( $url ); ?>" style="padding:4px 10px;border:1px solid var(--ys-adm-border,#e2e8ed);border-radius:4px;text-decoration:none;<?php echo esc_attr( $active ); ?>">
                <?php echo esc_html( $i ); ?>
            </a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>

</div>

<script>
jQuery(function($){
    // 篩選
    $('#ys-log-filter-btn').on('click', function(){
        var level = $('#ys-log-level-filter').val();
        var action = $('#ys-log-action-filter').val();
        var url = '<?php echo esc_js( admin_url( 'admin.php?page=ys-hub-logs' ) ); ?>';
        if(level) url += '&level=' + level;
        if(action) url += '&action_filter=' + action;
        window.location.href = url;
    });

    // 清除日誌
    $('#ys-log-clear-btn').on('click', function(){
        if(!confirm('<?php echo esc_js( __( '確定要清除所有日誌？', 'ys-plugin-hub-client' ) ); ?>')) return;
        var btn = $(this);
        btn.prop('disabled', true).text('<?php echo esc_js( __( '清除中...', 'ys-plugin-hub-client' ) ); ?>');
        $.post(window.ysHubClient ? window.ysHubClient.ajaxUrl : ajaxurl, {
            action: 'ys_hub_client_clear_logs',
            nonce: window.ysHubClient ? window.ysHubClient.nonce : ''
        }, function(r){
            if(r.success) window.location.reload();
        }).always(function(){ btn.prop('disabled', false); });
    });

    // 展開 context 詳情
    $(document).on('click', '.ys-log-context-toggle', function(){
        var ctx = $(this).data('context');
        try { ctx = JSON.stringify(JSON.parse(ctx), null, 2); } catch(e) {}
        alert(ctx);
    });
});
</script>
