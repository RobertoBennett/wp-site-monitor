<?php
class WPSM_Cron {
    
    public static function init() {
        add_action('wpsm_daily_scan', [__CLASS__, 'run_daily_scan']);
        add_action('wpsm_process_next_url', ['WPSM_Crawler', 'process_next_url']);
    }
    
    public static function schedule_events() {
        if (!wp_next_scheduled('wpsm_daily_scan')) {
            $time = get_option('wpsm_scan_time', '03:00');
            $timestamp = self::get_next_timestamp($time);
            
            wp_schedule_event($timestamp, 'daily', 'wpsm_daily_scan');
        }
    }
    
    private static function get_next_timestamp($time) {
        $current_time = current_time('timestamp');
        $scheduled_time = strtotime("today {$time}", $current_time);
        
        // Если время уже прошло сегодня, планируем на завтра
        if ($scheduled_time < $current_time) {
            $scheduled_time = strtotime("tomorrow {$time}", $current_time);
        }
        
        return $scheduled_time;
    }
    
    public static function clear_events() {
        wp_clear_scheduled_hook('wpsm_daily_scan');
        
        // Очищаем все запланированные задачи process_next_url
        $cron = get_option('cron');
        foreach ($cron as $timestamp => $hooks) {
            if (is_array($hooks) && isset($hooks['wpsm_process_next_url'])) {
                unset($cron[$timestamp]['wpsm_process_next_url']);
                if (empty($cron[$timestamp])) {
                    unset($cron[$timestamp]);
                }
            }
        }
        update_option('cron', $cron);
    }
    
    public static function run_daily_scan() {
        $sitemaps = get_option('wpsm_sitemaps', get_site_url() . '/sitemap.xml');
        $sitemap_array = array_filter(array_map('trim', explode("\n", $sitemaps)));
        
        if (!empty($sitemap_array)) {
            WPSM_Crawler::start_scan($sitemap_array);
        }
    }
}