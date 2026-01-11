<?php
class WPSM_Admin {
    
    public static function init() {
        // Проверяем, находимся ли мы в админке
        if (!is_admin()) {
            return;
        }
        
        add_action('admin_menu', [__CLASS__, 'add_admin_menu']);
        add_action('admin_bar_menu', [__CLASS__, 'add_admin_bar_notification'], 999);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_admin_scripts']);
        add_action('wp_dashboard_setup', [__CLASS__, 'add_dashboard_widget']);
        add_action('admin_post_wpsm_clear_old', [__CLASS__, 'handle_clear_old']);
    }
    
    public static function add_admin_menu() {
        // Главное меню
        add_menu_page(
            __('Site Monitor', 'wpsm'),
            __('Site Monitor', 'wpsm'),
            'manage_options',
            'wp-site-monitor',
            [__CLASS__, 'render_dashboard'],
            'dashicons-search',
            30
        );
        
        // Подменю - дашборд (то же самое, что главное меню)
        add_submenu_page(
            'wp-site-monitor',
            __('Dashboard', 'wpsm'),
            __('Dashboard', 'wpsm'),
            'manage_options',
            'wp-site-monitor',
            [__CLASS__, 'render_dashboard']
        );
        
        // Подменю - настройки
        add_submenu_page(
            'wp-site-monitor',
            __('Settings', 'wpsm'),
            __('Settings', 'wpsm'),
            'manage_options',
            'wp-site-monitor-settings',
            [__CLASS__, 'render_settings']
        );
    }
    
    public static function add_admin_bar_notification($admin_bar) {
        if (!current_user_can('manage_options') || !is_admin_bar_showing()) {
            return;
        }
        
        $noindex_count = self::get_noindex_count();
        
        if ($noindex_count > 0) {
            $admin_bar->add_node([
                'id' => 'wpsm-notification',
                'title' => '<span class="ab-icon dashicons dashicons-warning" style="margin-top: 2px;"></span> ' . $noindex_count,
                'href' => admin_url('admin.php?page=wp-site-monitor&status=noindex'),
                'meta' => [
                    'title' => sprintf(__('Found %d pages with noindex', 'wpsm'), $noindex_count),
                    'class' => 'wpsm-alert'
                ]
            ]);
        }
        
        // Индикатор сканирования
        if (get_option('wpsm_scan_in_progress', false)) {
            $admin_bar->add_node([
                'id' => 'wpsm-scanning',
                'title' => '<span class="ab-icon dashicons dashicons-update" style="margin-top: 2px;"></span>',
                'href' => admin_url('admin.php?page=wp-site-monitor'),
                'meta' => [
                    'title' => __('Site scan in progress', 'wpsm'),
                    'class' => 'wpsm-scanning'
                ]
            ]);
        }
    }
    
    private static function get_noindex_count() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wpsm_results';
        
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name 
             WHERE is_noindex = 1 
             AND checked_at > DATE_SUB(NOW(), INTERVAL %d DAY)",
            7
        ));
        
        return $count ? intval($count) : 0;
    }
    
    public static function enqueue_admin_scripts($hook) {
        // Загружаем только на страницах плагина и дашборде
        $plugin_pages = ['toplevel_page_wp-site-monitor', 'wp-site-monitor_page_wp-site-monitor-settings'];
        
        if (in_array($hook, $plugin_pages) || $hook === 'index.php') {
            // Стили
            wp_enqueue_style(
                'wpsm-admin', 
                WPSM_PLUGIN_URL . 'assets/css/admin.css', 
                [], 
                WPSM_VERSION
            );
            
            // Скрипты только для страниц плагина (кроме дашборда WP)
            if (in_array($hook, $plugin_pages)) {
                wp_enqueue_script(
                    'wpsm-admin', 
                    WPSM_PLUGIN_URL . 'assets/js/admin.js', 
                    ['jquery'], 
                    WPSM_VERSION, 
                    true
                );
                
                wp_localize_script('wpsm-admin', 'wpsm_ajax', [
                    'ajax_url' => admin_url('admin-ajax.php'),
                    'nonce' => wp_create_nonce('wpsm_nonce'),
                    'strings' => [
                        'scan_started' => __('Scan started...', 'wpsm'),
                        'scan_completed' => __('Scan completed', 'wpsm'),
                        'confirm_stop' => __('Are you sure you want to stop scanning?', 'wpsm'),
                        'confirm_start' => __('Start checking all pages from sitemap? This may take some time.', 'wpsm')
                    ]
                ]);
            }
        }
    }
    
    public static function add_dashboard_widget() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        wp_add_dashboard_widget(
            'wpsm_dashboard_widget',
            __('Site Indexing Status', 'wpsm'),
            [__CLASS__, 'render_dashboard_widget']
        );
    }
    
    public static function render_dashboard_widget() {
        $stats = WPSM_Database::get_stats();
        $last_scan = get_option('wpsm_last_scan', '');
        $scan_in_progress = get_option('wpsm_scan_in_progress', false);
        
        ?>
        <div class="wpsm-widget">
            <?php if ($stats['total'] > 0): ?>
                <div class="wpsm-stats">
                    <p><strong><?php _e('Last 7 days:', 'wpsm'); ?></strong></p>
                    <ul style="margin-left: 15px;">
                        <li><?php _e('Total checked:', 'wpsm'); ?> <strong><?php echo $stats['total']; ?></strong></li>
                        <li style="color: green;"><?php _e('Indexable:', 'wpsm'); ?> <strong><?php echo $stats['indexable_count']; ?></strong></li>
                        <li style="color: red;"><?php _e('Noindex:', 'wpsm'); ?> <strong><?php echo $stats['noindex_count']; ?></strong></li>
                        <li style="color: orange;"><?php _e('Errors:', 'wpsm'); ?> <strong><?php echo $stats['error_count']; ?></strong></li>
                    </ul>
                </div>
            <?php else: ?>
                <p><?php _e('No scans performed yet.', 'wpsm'); ?></p>
            <?php endif; ?>
            
            <?php if ($last_scan): ?>
                <p><small><?php _e('Last scan:', 'wpsm'); ?> <?php echo date_i18n('d.m.Y H:i', strtotime($last_scan)); ?></small></p>
            <?php endif; ?>
            
            <div style="margin-top: 10px;">
                <a href="<?php echo admin_url('admin.php?page=wp-site-monitor'); ?>" class="button button-primary">
                    <?php _e('Detailed report', 'wpsm'); ?>
                </a>
                
                <?php if ($scan_in_progress): ?>
                    <button class="button button-secondary wpsm-stop-scan" style="margin-top: 10px;">
                        <span class="dashicons dashicons-update" style="animation: spin 2s linear infinite; vertical-align: middle;"></span> 
                        <?php _e('Stop', 'wpsm'); ?>
                    </button>
                    <script>
                    jQuery(document).ready(function($) {
                        $('.wpsm-stop-scan').on('click', function() {
                            if (confirm('<?php _e('Are you sure you want to stop scanning?', 'wpsm'); ?>')) {
                                $.ajax({
                                    url: ajaxurl,
                                    type: 'POST',
                                    data: {
                                        action: 'wpsm_stop_scan',
                                        nonce: '<?php echo wp_create_nonce('wpsm_nonce'); ?>'
                                    },
                                    success: function() {
                                        location.reload();
                                    }
                                });
                            }
                        });
                    });
                    </script>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
    
    public static function render_dashboard() {
        $stats = WPSM_Database::get_stats();
        $last_scan = get_option('wpsm_last_scan', '');
        $scan_in_progress = get_option('wpsm_scan_in_progress', false);
        $sitemaps = get_option('wpsm_sitemaps', get_site_url() . '/sitemap.xml');
        
        // Определяем текущий статус фильтра
        $current_status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : 'all';
        
        ?>
        <div class="wrap wpsm-dashboard">
            <h1><?php _e('Site Monitor - Indexing Report', 'wpsm'); ?></h1>
            
            <div class="wpsm-controls">
                <div class="wpsm-scan-controls">
                    <button id="wpsm-start-scan" class="button button-primary" 
                            <?php echo $scan_in_progress ? 'disabled' : ''; ?>>
                        <?php _e('Start scan', 'wpsm'); ?>
                    </button>
                    <button id="wpsm-stop-scan" class="button button-secondary" 
                            style="<?php echo $scan_in_progress ? '' : 'display: none;'; ?>">
                        <?php _e('Stop scan', 'wpsm'); ?>
                    </button>
                    <span id="wpsm-scan-progress" style="margin-left: 15px; display: none;">
                        <span class="spinner is-active" style="float: none; margin-top: 0;"></span>
                        <span id="wpsm-progress-text"></span>
                    </span>
                </div>
                
                <div class="wpsm-filters">
                    <select id="wpsm-status-filter">
                        <option value="all" <?php selected($current_status, 'all'); ?>><?php _e('All pages', 'wpsm'); ?></option>
                        <option value="noindex" <?php selected($current_status, 'noindex'); ?>><?php _e('Only noindex', 'wpsm'); ?></option>
                        <option value="indexable" <?php selected($current_status, 'indexable'); ?>><?php _e('Only indexable', 'wpsm'); ?></option>
                        <option value="errors" <?php selected($current_status, 'errors'); ?>><?php _e('Errors', 'wpsm'); ?></option>
                    </select>
                </div>
            </div>
            
            <div class="wpsm-stats-grid">
                <div class="wpsm-stat-card">
                    <h3><?php _e('Current status', 'wpsm'); ?></h3>
                    <p class="stat-value">
                        <?php if ($scan_in_progress): ?>
                            <span class="dashicons dashicons-update" style="animation: spin 2s linear infinite; vertical-align: middle;"></span> 
                            <?php _e('Scanning...', 'wpsm'); ?>
                        <?php elseif ($last_scan): ?>
                            <span style="color: green;">✓ <?php _e('Active', 'wpsm'); ?></span>
                        <?php else: ?>
                            <?php _e('Not scanned', 'wpsm'); ?>
                        <?php endif; ?>
                    </p>
                </div>
                
                <div class="wpsm-stat-card">
                    <h3><?php _e('Last scan', 'wpsm'); ?></h3>
                    <p class="stat-value">
                        <?php echo $last_scan ? date_i18n('d.m.Y H:i', strtotime($last_scan)) : '—'; ?>
                    </p>
                </div>
                
                <div class="wpsm-stat-card">
                    <h3><?php _e('Noindex pages', 'wpsm'); ?></h3>
                    <p class="stat-value" style="color: red;">
                        <?php echo $stats['noindex_count']; ?>
                    </p>
                </div>
                
                <div class="wpsm-stat-card">
                    <h3><?php _e('Indexable pages', 'wpsm'); ?></h3>
                    <p class="stat-value" style="color: green;">
                        <?php echo $stats['indexable_count']; ?>
                    </p>
                </div>
            </div>
            
            <div class="wpsm-results">
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th width="50%"><?php _e('URL', 'wpsm'); ?></th>
                            <th width="10%"><?php _e('HTTP code', 'wpsm'); ?></th>
                            <th width="15%"><?php _e('Status', 'wpsm'); ?></th>
                            <th width="25%"><?php _e('Reason', 'wpsm'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="wpsm-results-body">
                        <tr>
                            <td colspan="4" style="text-align: center;">
                                <span class="spinner is-active" style="float: none;"></span> 
                                <?php _e('Loading results...', 'wpsm'); ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
                
                <div class="wpsm-pagination" id="wpsm-pagination">
                    <!-- Пагинация -->
                </div>
            </div>
            
            <!-- Скрытое поле для sitemap -->
            <textarea id="wpsm_sitemaps" style="display: none;"><?php 
                echo esc_textarea($sitemaps); 
            ?></textarea>
            
            <style>
            .wpsm-dashboard .spinner.is-active {
                float: none;
                margin: 0 10px 0 0;
            }
            </style>
        </div>
        <?php
    }
    
    public static function render_settings() {
        // Сообщения об успешном сохранении
        if (isset($_GET['settings-updated']) && $_GET['settings-updated'] === 'true') {
            ?>
            <div class="notice notice-success is-dismissible">
                <p><?php _e('Settings saved.', 'wpsm'); ?></p>
            </div>
            <?php
        }
        
        $sitemaps = get_option('wpsm_sitemaps', get_site_url() . '/sitemap.xml');
        $scan_time = get_option('wpsm_scan_time', '03:00');
        ?>
        <div class="wrap">
            <h1><?php _e('Site Monitor Settings', 'wpsm'); ?></h1>
            
            <form method="post" action="options.php">
                <?php settings_fields('wpsm_settings'); ?>
                <?php do_settings_sections('wpsm_settings'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="wpsm_sitemaps"><?php _e('Sitemap files', 'wpsm'); ?></label></th>
                        <td>
                            <textarea name="wpsm_sitemaps" id="wpsm_sitemaps" rows="5" cols="50" class="large-text"><?php 
                                echo esc_textarea($sitemaps); 
                            ?></textarea>
                            <p class="description">
                                <?php _e('Enter URLs of sitemap files (one per line)', 'wpsm'); ?><br>
                                <?php _e('Example:', 'wpsm'); ?><br>
                                <code>https://example.com/sitemap.xml</code><br>
                                <code>https://example.com/sitemap_index.xml</code><br>
                                <code>https://example.com/news-sitemap.xml</code>
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><label for="wpsm_scan_time"><?php _e('Daily scan time', 'wpsm'); ?></label></th>
                        <td>
                            <input type="time" name="wpsm_scan_time" id="wpsm_scan_time" value="<?php echo esc_attr($scan_time); ?>">
                            <p class="description">
                                <?php _e('Time in 24-hour format (recommended night time)', 'wpsm'); ?>
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><?php _e('Test connection', 'wpsm'); ?></th>
                        <td>
                            <button type="button" id="wpsm-test-sitemap" class="button button-secondary">
                                <?php _e('Test sitemap connection', 'wpsm'); ?>
                            </button>
                            <p class="description">
                                <?php _e('Test if sitemap files are accessible', 'wpsm'); ?>
                            </p>
                            <div id="wpsm-test-result" style="margin-top: 10px;"></div>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(); ?>
            </form>
            
            <hr>
            
            <h3><?php _e('Database statistics', 'wpsm'); ?></h3>
            <?php
            global $wpdb;
            $table_results = $wpdb->prefix . 'wpsm_results';
            $table_logs = $wpdb->prefix . 'wpsm_logs';
            
            $results_count = $wpdb->get_var("SELECT COUNT(*) FROM $table_results") ?: 0;
            $logs_count = $wpdb->get_var("SELECT COUNT(*) FROM $table_logs") ?: 0;
            ?>
            
            <p><?php _e('Records in results table:', 'wpsm'); ?> <strong><?php echo $results_count; ?></strong></p>
            <p><?php _e('Records in logs table:', 'wpsm'); ?> <strong><?php echo $logs_count; ?></strong></p>
            
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <input type="hidden" name="action" value="wpsm_clear_old">
                <?php wp_nonce_field('wpsm_clear_old', 'wpsm_nonce'); ?>
                <p>
                    <button type="submit" class="button button-secondary" 
                            onclick="return confirm('<?php _e('Delete results older than 30 days?', 'wpsm'); ?>')">
                        <?php _e('Clear old data', 'wpsm'); ?>
                    </button>
                    <span class="description">
                        <?php _e('Deletes scan results older than 30 days', 'wpsm'); ?>
                    </span>
                </p>
            </form>
            
            <script>
            jQuery(document).ready(function($) {
                $('#wpsm-test-sitemap').on('click', function() {
                    var $button = $(this);
                    var $result = $('#wpsm-test-result');
                    var sitemaps = $('#wpsm_sitemaps').val();
                    
                    $button.prop('disabled', true).text('<?php _e('Testing...', 'wpsm'); ?>');
                    $result.html('<span class="spinner is-active" style="float: none;"></span> <?php _e('Testing...', 'wpsm'); ?>');
                    
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'wpsm_test_sitemap',
                            nonce: '<?php echo wp_create_nonce('wpsm_nonce'); ?>',
                            sitemaps: sitemaps
                        },
                        success: function(response) {
                            $button.prop('disabled', false).text('<?php _e('Test sitemap connection', 'wpsm'); ?>');
                            
                            if (response.success) {
                                $result.html('<div class="notice notice-success"><p>' + response.data.message + '</p></div>');
                            } else {
                                $result.html('<div class="notice notice-error"><p>' + (response.data || 'Error') + '</p></div>');
                            }
                        },
                        error: function() {
                            $button.prop('disabled', false).text('<?php _e('Test sitemap connection', 'wpsm'); ?>');
                            $result.html('<div class="notice notice-error"><p><?php _e('Network error', 'wpsm'); ?></p></div>');
                        }
                    });
                });
            });
            </script>
        </div>
        <?php
    }
    
    public static function handle_clear_old() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Access denied', 'wpsm'));
        }
        
        check_admin_referer('wpsm_clear_old', 'wpsm_nonce');
        
        $result = WPSM_Database::clear_old_data(30);
        
        if ($result) {
            $message = __('Old data successfully deleted.', 'wpsm');
            $type = 'success';
        } else {
            $message = __('Error deleting old data.', 'wpsm');
            $type = 'error';
        }
        
        wp_redirect(add_query_arg([
            'page' => 'wp-site-monitor-settings',
            'wpsm_message' => urlencode($message),
            'wpsm_type' => $type
        ], admin_url('admin.php')));
        exit;
    }
}

// Добавляем AJAX для тестирования sitemap
add_action('wp_ajax_wpsm_test_sitemap', 'wpsm_ajax_test_sitemap');
function wpsm_ajax_test_sitemap() {
    // Проверка nonce
    if (!check_ajax_referer('wpsm_nonce', 'nonce', false)) {
        wp_send_json_error('Invalid nonce');
    }
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Access denied');
    }
    
    $sitemaps = isset($_POST['sitemaps']) ? sanitize_textarea_field($_POST['sitemaps']) : '';
    $sitemap_array = array_filter(array_map('trim', explode("\n", $sitemaps)));
    
    if (empty($sitemap_array)) {
        wp_send_json_error('No sitemap files specified');
    }
    
    $results = [];
    $success_count = 0;
    
    foreach ($sitemap_array as $sitemap_url) {
        $response = wp_safe_remote_get($sitemap_url, [
            'timeout' => 10,
            'sslverify' => false
        ]);
        
        if (is_wp_error($response)) {
            $results[] = $sitemap_url . ': ' . $response->get_error_message();
        } elseif (wp_remote_retrieve_response_code($response) == 200) {
            $results[] = $sitemap_url . ': ✓ OK';
            $success_count++;
        } else {
            $results[] = $sitemap_url . ': HTTP ' . wp_remote_retrieve_response_code($response);
        }
    }
    
    $message = sprintf(__('Checked %d sitemap(s). Successful: %d', 'wpsm'), count($sitemap_array), $success_count);
    $message .= '<br><pre style="white-space: pre-wrap; background: #f5f5f5; padding: 10px;">' . implode("\n", $results) . '</pre>';
    
    wp_send_json_success(['message' => $message]);
}
