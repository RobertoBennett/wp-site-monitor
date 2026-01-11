<?php
class WPSM_Database {
    
    public static function init() {
        // Дополнительная инициализация при необходимости
    }
    
    public static function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        $table_name = $wpdb->prefix . 'wpsm_results';
        $table_logs = $wpdb->prefix . 'wpsm_logs';
        
        // Таблица результатов
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            url varchar(1000) NOT NULL,
            http_code int(11) NOT NULL,
            is_noindex tinyint(1) DEFAULT 0,
            reasons text,
            response_time float,
            checked_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY url (url(191)),
            KEY is_noindex (is_noindex),
            KEY checked_at (checked_at)
        ) $charset_collate;";
        
        // Таблица логов
        $sql_logs = "CREATE TABLE IF NOT EXISTS $table_logs (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            scan_id varchar(50),
            total_urls int(11),
            processed_urls int(11),
            noindex_count int(11),
            start_time datetime,
            end_time datetime,
            status varchar(20),
            PRIMARY KEY (id),
            KEY scan_id (scan_id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        $result1 = dbDelta($sql);
        $result2 = dbDelta($sql_logs);
        
        // Добавляем опции по умолчанию
        if (get_option('wpsm_sitemaps') === false) {
            add_option('wpsm_sitemaps', get_site_url() . '/sitemap.xml');
        }
        if (get_option('wpsm_scan_time') === false) {
            add_option('wpsm_scan_time', '03:00');
        }
        if (get_option('wpsm_last_scan') === false) {
            add_option('wpsm_last_scan', '');
        }
        if (get_option('wpsm_scan_in_progress') === false) {
            add_option('wpsm_scan_in_progress', false);
        }
        
        return $result1 && $result2;
    }
    
    public static function save_result($data) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wpsm_results';
        
        // Удаляем старый результат для этого URL
        $wpdb->delete($table_name, ['url' => $data['url']]);
        
        // Сохраняем новый результат
        return $wpdb->insert($table_name, $data);
    }
    
    public static function get_results($page = 1, $per_page = 20, $status = 'all') {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wpsm_results';
        
        $offset = ($page - 1) * $per_page;
        
        $where = '';
        if ($status === 'noindex') {
            $where = "WHERE is_noindex = 1";
        } elseif ($status === 'indexable') {
            $where = "WHERE is_noindex = 0 AND http_code = 200";
        } elseif ($status === 'errors') {
            $where = "WHERE http_code >= 400 OR http_code = 0";
        }
        
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $table_name 
                 $where 
                 ORDER BY checked_at DESC 
                 LIMIT %d OFFSET %d",
                $per_page,
                $offset
            ),
            ARRAY_A
        );
        
        $total = $wpdb->get_var("SELECT COUNT(*) FROM $table_name $where");
        
        return [
            'results' => $results ?: [],
            'total' => $total ?: 0,
            'pages' => $total > 0 ? ceil($total / $per_page) : 0,
            'current_page' => $page
        ];
    }
    
    public static function get_stats() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wpsm_results';
        
        $stats = $wpdb->get_row("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN is_noindex = 1 THEN 1 ELSE 0 END) as noindex_count,
                SUM(CASE WHEN is_noindex = 0 AND http_code = 200 THEN 1 ELSE 0 END) as indexable_count,
                SUM(CASE WHEN http_code >= 400 OR http_code = 0 THEN 1 ELSE 0 END) as error_count
            FROM $table_name
            WHERE checked_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
        ", ARRAY_A);
        
        return $stats ?: [
            'total' => 0,
            'noindex_count' => 0,
            'indexable_count' => 0,
            'error_count' => 0
        ];
    }
    
    public static function log_scan($data) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wpsm_logs';
        
        return $wpdb->insert($table_name, $data);
    }
    
    public static function clear_old_data($days = 30) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wpsm_results';
        $table_logs = $wpdb->prefix . 'wpsm_logs';
        
        $date = date('Y-m-d H:i:s', strtotime("-$days days"));
        
        $result1 = $wpdb->query(
            $wpdb->prepare("DELETE FROM $table_name WHERE checked_at < %s", $date)
        );
        
        $result2 = $wpdb->query(
            $wpdb->prepare("DELETE FROM $table_logs WHERE start_time < %s", $date)
        );
        
        return $result1 !== false && $result2 !== false;
    }
}