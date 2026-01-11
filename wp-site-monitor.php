<?php
/**
 * Plugin Name: WP Site Monitor
 * Plugin URI: https://example.com/wp-site-monitor
 * Description: Ежедневная проверка страниц сайта на наличие noindex и вывод отчетов в админ-панели
 * Version: 1.0.2
 * Plugin URI: https://github.com/RobertoBennett/wp-site-monitor
 * Author: Robert Bennett
 * Text Domain: wp-site-monitor
 */

// Запрещаем прямой доступ
if (!defined('ABSPATH')) {
    exit;
}

// Константы плагина
define('WPSM_VERSION', '1.0.1');
define('WPSM_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WPSM_PLUGIN_URL', plugin_dir_url(__FILE__));

// Автозагрузка классов
spl_autoload_register(function($class) {
    if (strpos($class, 'WPSM_') === 0) {
        $class_file = 'class-' . strtolower(str_replace('_', '-', substr($class, 5))) . '.php';
        $file_path = WPSM_PLUGIN_DIR . 'includes/' . $class_file;
        
        if (file_exists($file_path)) {
            require_once $file_path;
        }
    }
});

// Инициализация плагина
add_action('plugins_loaded', 'wpsm_init_plugin');

function wpsm_init_plugin() {
    // Загружаем основные классы
    if (!class_exists('WPSM_Database')) {
        require_once WPSM_PLUGIN_DIR . 'includes/class-wpsm-database.php';
    }
    if (!class_exists('WPSM_Crawler')) {
        require_once WPSM_PLUGIN_DIR . 'includes/class-wpsm-crawler.php';
    }
    if (!class_exists('WPSM_Admin')) {
        require_once WPSM_PLUGIN_DIR . 'includes/class-wpsm-admin.php';
    }
    if (!class_exists('WPSM_Cron')) {
        require_once WPSM_PLUGIN_DIR . 'includes/class-wpsm-cron.php';
    }
    
    // Инициализация компонентов
    if (class_exists('WPSM_Database')) {
        WPSM_Database::init();
    }
    
    if (class_exists('WPSM_Admin')) {
        WPSM_Admin::init();
    }
    
    if (class_exists('WPSM_Cron')) {
        WPSM_Cron::init();
    }
    
    // AJAX обработчики
    add_action('wp_ajax_wpsm_start_scan', 'wpsm_ajax_start_scan');
    add_action('wp_ajax_wpsm_stop_scan', 'wpsm_ajax_stop_scan');
    add_action('wp_ajax_wpsm_get_results', 'wpsm_ajax_get_results');
    add_action('wp_ajax_wpsm_get_scan_progress', 'wpsm_ajax_get_scan_progress');
    
    // Загрузка текстового домена
    load_plugin_textdomain('wpsm', false, dirname(plugin_basename(__FILE__)) . '/languages');
}

// Активация плагина
register_activation_hook(__FILE__, 'wpsm_activate_plugin');
function wpsm_activate_plugin() {
    // Создаем таблицы
    if (!class_exists('WPSM_Database')) {
        require_once WPSM_PLUGIN_DIR . 'includes/class-wpsm-database.php';
    }
    WPSM_Database::create_tables();
    
    // Планируем события CRON
    if (!class_exists('WPSM_Cron')) {
        require_once WPSM_PLUGIN_DIR . 'includes/class-wpsm-cron.php';
    }
    WPSM_Cron::schedule_events();
    
    // Создаем каталоги если нужно
    $upload_dir = wp_upload_dir();
    $wpsm_dir = $upload_dir['basedir'] . '/wpsm-logs';
    if (!file_exists($wpsm_dir)) {
        wp_mkdir_p($wpsm_dir);
    }
    
    // Добавляем capabilities если нужно
    $role = get_role('administrator');
    if ($role) {
        $role->add_cap('manage_wpsm');
    }
}

// Деактивация плагина
register_deactivation_hook(__FILE__, 'wpsm_deactivate_plugin');
function wpsm_deactivate_plugin() {
    if (!class_exists('WPSM_Cron')) {
        require_once WPSM_PLUGIN_DIR . 'includes/class-wpsm-cron.php';
    }
    WPSM_Cron::clear_events();
    
    // Удаляем опции при деактивации (опционально)
    // delete_option('wpsm_sitemaps');
    // delete_option('wpsm_scan_time');
    // delete_option('wpsm_last_scan');
    // delete_option('wpsm_scan_in_progress');
}

// AJAX: Запуск сканирования
function wpsm_ajax_start_scan() {
    // Проверка nonce
    if (!check_ajax_referer('wpsm_nonce', 'nonce', false)) {
        wp_send_json_error('Неверный nonce');
    }
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Доступ запрещен');
    }
    
    $sitemaps = isset($_POST['sitemaps']) ? sanitize_textarea_field($_POST['sitemaps']) : '';
    $sitemap_array = array_filter(array_map('trim', explode("\n", $sitemaps)));
    
    if (empty($sitemap_array)) {
        wp_send_json_error('Не указаны sitemap файлы');
    }
    
    if (!class_exists('WPSM_Crawler')) {
        require_once WPSM_PLUGIN_DIR . 'includes/class-wpsm-crawler.php';
    }
    
    $result = WPSM_Crawler::start_scan($sitemap_array);
    
    if ($result['status'] === 'started') {
        wp_send_json_success($result);
    } else {
        wp_send_json_error($result['message'] ?? 'Ошибка запуска сканирования');
    }
}

// AJAX: Остановка сканирования
function wpsm_ajax_stop_scan() {
    // Проверка nonce
    if (!check_ajax_referer('wpsm_nonce', 'nonce', false)) {
        wp_die('Неверный nonce');
    }
    
    if (!current_user_can('manage_options')) {
        wp_die('Доступ запрещен');
    }
    
    update_option('wpsm_scan_in_progress', false);
    delete_option('wpsm_scan_queue');
    delete_option('wpsm_scan_current');
    delete_option('wpsm_scan_noindex');
    
    wp_send_json_success('Сканирование остановлено');
}

// AJAX: Получение результатов
function wpsm_ajax_get_results() {
    // Проверка nonce
    if (!check_ajax_referer('wpsm_nonce', 'nonce', false)) {
        wp_die('Неверный nonce');
    }
    
    if (!current_user_can('manage_options')) {
        wp_die('Доступ запрещен');
    }
    
    $page = isset($_POST['page']) ? intval($_POST['page']) : 1;
    $per_page = 20;
    $status = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : 'all';
    
    $results = WPSM_Database::get_results($page, $per_page, $status);
    wp_send_json_success($results);
}

// AJAX: Получение прогресса сканирования
function wpsm_ajax_get_scan_progress() {
    // Проверка nonce
    if (!check_ajax_referer('wpsm_nonce', 'nonce', false)) {
        wp_die('Неверный nonce');
    }
    
    if (!current_user_can('manage_options')) {
        wp_die('Доступ запрещен');
    }
    
    $in_progress = get_option('wpsm_scan_in_progress', false);
    $queue = get_option('wpsm_scan_queue', []);
    $current = get_option('wpsm_scan_current', 0);
    
    wp_send_json_success([
        'in_progress' => $in_progress,
        'total' => count($queue),
        'processed' => $current,
        'progress_percent' => count($queue) > 0 ? round(($current / count($queue)) * 100) : 0
    ]);
}

// Регистрация настроек
add_action('admin_init', 'wpsm_register_settings');
function wpsm_register_settings() {
    register_setting('wpsm_settings', 'wpsm_sitemaps', [
        'type' => 'string',
        'sanitize_callback' => 'sanitize_textarea_field',
        'default' => get_site_url() . '/sitemap.xml'
    ]);
    
    register_setting('wpsm_settings', 'wpsm_scan_time', [
        'type' => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'default' => '03:00'
    ]);
}

// Добавляем ссылку настроек на странице плагинов
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'wpsm_add_settings_link');
function wpsm_add_settings_link($links) {
    $settings_link = '<a href="' . admin_url('admin.php?page=wp-site-monitor-settings') . '">' . __('Настройки', 'wpsm') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
}

// Расширенная статистика
add_action('wp_ajax_wpsm_get_extended_stats', 'wpsm_ajax_get_extended_stats');
function wpsm_ajax_get_extended_stats() {
    check_ajax_referer('wpsm_nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_die('Доступ запрещен');
    }
    
    global $wpdb;
    $table_name = $wpdb->prefix . 'wpsm_results';
    
    $stats = [
        'today' => $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name WHERE DATE(checked_at) = CURDATE()"
        )),
        'yesterday' => $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name WHERE DATE(checked_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)"
        )),
        'last_week' => $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name WHERE checked_at > DATE_SUB(NOW(), INTERVAL 7 DAY)"
        )),
        'noindex_trend' => $wpdb->get_results(
            "SELECT DATE(checked_at) as date, COUNT(*) as count 
             FROM $table_name 
             WHERE is_noindex = 1 
             AND checked_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
             GROUP BY DATE(checked_at)
             ORDER BY date DESC"
        ),
        'slow_pages' => $wpdb->get_results(
            "SELECT url, response_time, http_code 
             FROM $table_name 
             WHERE response_time > 2 
             ORDER BY response_time DESC 
             LIMIT 10"
        )
    ];
    
    wp_send_json_success($stats);
}

// Экспорт результатов в CSV
add_action('wp_ajax_wpsm_export_csv', 'wpsm_ajax_export_csv');
function wpsm_ajax_export_csv() {
    check_ajax_referer('wpsm_nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_die('Доступ запрещен');
    }
    
    $status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : 'all';
    $date_from = isset($_GET['date_from']) ? sanitize_text_field($_GET['date_from']) : '';
    $date_to = isset($_GET['date_to']) ? sanitize_text_field($_GET['date_to']) : '';
    
    global $wpdb;
    $table_name = $wpdb->prefix . 'wpsm_results';
    
    $where = [];
    if ($status === 'noindex') {
        $where[] = "is_noindex = 1";
    } elseif ($status === 'indexable') {
        $where[] = "is_noindex = 0 AND http_code = 200";
    }
    
    if ($date_from) {
        $where[] = $wpdb->prepare("checked_at >= %s", $date_from . ' 00:00:00');
    }
    if ($date_to) {
        $where[] = $wpdb->prepare("checked_at <= %s", $date_to . ' 23:59:59');
    }
    
    $where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    
    $results = $wpdb->get_results(
        "SELECT * FROM $table_name $where_sql ORDER BY checked_at DESC",
        ARRAY_A
    );
    
    // Генерируем CSV
    $filename = 'site-monitor-export-' . date('Y-m-d-H-i') . '.csv';
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    
    // Заголовки
    fputcsv($output, ['URL', 'HTTP Code', 'Status', 'Reasons', 'Response Time', 'Checked At']);
    
    // Данные
    foreach ($results as $row) {
        $status = $row['is_noindex'] ? 'NOINDEX' : ($row['http_code'] == 200 ? 'INDEXABLE' : 'ERROR');
        fputcsv($output, [
            $row['url'],
            $row['http_code'],
            $status,
            $row['reasons'],
            $row['response_time'] . 's',
            $row['checked_at']
        ]);
    }
    
    fclose($output);
    exit;
}

// Сравнение с предыдущим сканированием
add_action('wp_ajax_wpsm_compare_scans', 'wpsm_ajax_compare_scans');
function wpsm_ajax_compare_scans() {
    check_ajax_referer('wpsm_nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_die('Доступ запрещен');
    }
    
    global $wpdb;
    $table_name = $wpdb->prefix . 'wpsm_results';
    
    // Получаем последние 2 сканирования
    $scans = $wpdb->get_results(
        "SELECT 
            DATE(checked_at) as scan_date,
            COUNT(*) as total_urls,
            SUM(CASE WHEN is_noindex = 1 THEN 1 ELSE 0 END) as noindex_count,
            AVG(response_time) as avg_response_time
         FROM $table_name 
         WHERE checked_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
         GROUP BY DATE(checked_at)
         ORDER BY scan_date DESC
         LIMIT 2"
    );
    
    if (count($scans) < 2) {
        wp_send_json_error('Недостаточно данных для сравнения');
    }
    
    $comparison = [
        'scan1' => $scans[0],
        'scan2' => $scans[1],
        'difference' => [
            'total_urls' => $scans[0]->total_urls - $scans[1]->total_urls,
            'noindex_count' => $scans[0]->noindex_count - $scans[1]->noindex_count,
            'avg_response_time' => round($scans[0]->avg_response_time - $scans[1]->avg_response_time, 2)
        ]
    ];
    
    wp_send_json_success($comparison);

}
