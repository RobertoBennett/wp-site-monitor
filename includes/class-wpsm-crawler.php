<?php
class WPSM_Crawler {
    
    private static $user_agent = 'Mozilla/5.0 (compatible; YandexWebmaster/2.0; +http://yandex.com/bots)';
    
    public static function start_scan($sitemaps) {
        update_option('wpsm_scan_in_progress', true);
        update_option('wpsm_last_scan', current_time('mysql'));
        
        $scan_id = uniqid('scan_');
        $all_urls = [];
        
        // Собираем все URL из sitemap
        foreach ($sitemaps as $sitemap_url) {
            $urls = self::parse_sitemap($sitemap_url);
            if (!empty($urls)) {
                $all_urls = array_merge($all_urls, $urls);
            }
        }
        
        $all_urls = array_unique($all_urls);
        $total_urls = count($all_urls);
        
        if ($total_urls === 0) {
            update_option('wpsm_scan_in_progress', false);
            return [
                'status' => 'error',
                'message' => 'Не удалось получить URL из sitemap файлов'
            ];
        }
        
        // Логируем начало сканирования
        WPSM_Database::log_scan([
            'scan_id' => $scan_id,
            'total_urls' => $total_urls,
            'processed_urls' => 0,
            'noindex_count' => 0,
            'start_time' => current_time('mysql'),
            'status' => 'started'
        ]);
        
        // Сохраняем URL для фоновой обработки
        update_option('wpsm_scan_queue', $all_urls);
        update_option('wpsm_scan_current', 0);
        update_option('wpsm_scan_noindex', 0);
        
        // Запускаем первую проверку немедленно
        if (!wp_next_scheduled('wpsm_process_next_url', [$scan_id])) {
            wp_schedule_single_event(time() + 1, 'wpsm_process_next_url', [$scan_id]);
        }
        
        return [
            'status' => 'started',
            'total_urls' => $total_urls,
            'scan_id' => $scan_id
        ];
    }
    
    private static function parse_sitemap($sitemap_url) {
        $response = wp_safe_remote_get($sitemap_url, [
            'timeout' => 15,
            'user-agent' => self::$user_agent,
            'sslverify' => false
        ]);
        
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) != 200) {
            return [];
        }
        
        $body = wp_remote_retrieve_body($response);
        
        // Проверяем, является ли это sitemap index файлом
        if (strpos($body, '<sitemapindex') !== false) {
            // Это индексный файл sitemap
            preg_match_all('/<loc>(.*?)<\/loc>/s', $body, $matches);
            
            $urls = [];
            if (!empty($matches[1])) {
                foreach ($matches[1] as $sitemap) {
                    $sitemap_urls = self::parse_sitemap(trim($sitemap));
                    $urls = array_merge($urls, $sitemap_urls);
                }
            }
            return $urls;
        } else {
            // Обычный sitemap
            preg_match_all('/<loc>(.*?)<\/loc>/s', $body, $matches);
            
            if (empty($matches[1])) {
                return [];
            }
            
            $urls = array_map('trim', $matches[1]);
            return array_unique($urls);
        }
    }
    
    public static function process_next_url($scan_id) {
        if (!get_option('wpsm_scan_in_progress', false)) {
            return;
        }
        
        $queue = get_option('wpsm_scan_queue', []);
        $current_index = get_option('wpsm_scan_current', 0);
        $noindex_count = get_option('wpsm_scan_noindex', 0);
        
        if ($current_index >= count($queue)) {
            // Сканирование завершено
            self::complete_scan($scan_id, count($queue), $noindex_count);
            return;
        }
        
        $url = $queue[$current_index];
        $result = self::check_url($url);
        
        // Сохраняем результат
        WPSM_Database::save_result([
            'url' => $url,
            'http_code' => $result['http_code'],
            'is_noindex' => $result['is_noindex'] ? 1 : 0,
            'reasons' => $result['reasons'],
            'response_time' => $result['response_time'],
            'checked_at' => current_time('mysql')
        ]);
        
        if ($result['is_noindex']) {
            $noindex_count++;
            update_option('wpsm_scan_noindex', $noindex_count);
        }
        
        $current_index++;
        update_option('wpsm_scan_current', $current_index);
        
        // Запускаем следующую проверку через 1 секунду
        if (!wp_next_scheduled('wpsm_process_next_url', [$scan_id])) {
            wp_schedule_single_event(time() + 1, 'wpsm_process_next_url', [$scan_id]);
        }
        
        // Обновляем лог каждые 10 URL
        if ($current_index % 10 === 0 || $current_index >= count($queue)) {
            WPSM_Database::log_scan([
                'scan_id' => $scan_id,
                'total_urls' => count($queue),
                'processed_urls' => $current_index,
                'noindex_count' => $noindex_count,
                'status' => 'processing'
            ]);
        }
    }
    
    private static function check_url($url) {
        $start_time = microtime(true);
        
        $response = wp_safe_remote_get($url, [
            'timeout' => 15,
            'user-agent' => self::$user_agent,
            'sslverify' => false,
            'headers' => [
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'
            ]
        ]);
        
        $response_time = microtime(true) - $start_time;
        
        $result = [
            'http_code' => 0,
            'is_noindex' => false,
            'reasons' => '',
            'response_time' => round($response_time, 2)
        ];
        
        if (is_wp_error($response)) {
            $result['http_code'] = 0;
            $result['reasons'] = $response->get_error_message();
            return $result;
        }
        
        $http_code = wp_remote_retrieve_response_code($response);
        $headers = wp_remote_retrieve_headers($response);
        $body = wp_remote_retrieve_body($response);
        
        $result['http_code'] = $http_code;
        $is_noindex = false;
        $reasons = [];
        
        // Проверка заголовка X-Robots-Tag
        if (!empty($headers['x-robots-tag'])) {
            $x_robots = is_array($headers['x-robots-tag']) ? 
                        $headers['x-robots-tag'] : 
                        [$headers['x-robots-tag']];
            
            foreach ($x_robots as $tag) {
                if (stripos($tag, 'noindex') !== false) {
                    $is_noindex = true;
                    $reasons[] = 'Header: X-Robots-Tag';
                    break;
                }
            }
        }
        
        // Проверка meta robots
        if (!$is_noindex && !empty($body)) {
            // Проверка через регулярное выражение
            if (preg_match('/<meta[^>]*name=["\']robots["\'][^>]*content=["\'][^"\']*noindex[^"\']*["\'][^>]*>/i', $body)) {
                $is_noindex = true;
                $reasons[] = 'Meta: robots noindex';
            }
        }
        
        $result['is_noindex'] = $is_noindex;
        $result['reasons'] = implode(', ', $reasons);
        
        return $result;
    }
    
    private static function complete_scan($scan_id, $total_urls, $noindex_count) {
        update_option('wpsm_scan_in_progress', false);
        delete_option('wpsm_scan_queue');
        delete_option('wpsm_scan_current');
        delete_option('wpsm_scan_noindex');
        
        WPSM_Database::log_scan([
            'scan_id' => $scan_id,
            'total_urls' => $total_urls,
            'processed_urls' => $total_urls,
            'noindex_count' => $noindex_count,
            'end_time' => current_time('mysql'),
            'status' => 'completed'
        ]);
        
        // Отправляем email уведомление администратору
        self::send_notification_email($total_urls, $noindex_count);
    }
    
    private static function send_notification_email($total_urls, $noindex_count) {
        if ($noindex_count == 0) {
            return;
        }
        
        $admin_email = get_option('admin_email');
        $site_name = get_bloginfo('name');
        $site_url = get_site_url();
        
        $subject = sprintf(__('[%s] Найдены страницы с noindex', 'wpsm'), $site_name);
        
        $message = sprintf(
            __("На сайте %s (%s) завершена проверка на noindex.\n\n", 'wpsm'),
            $site_name,
            $site_url
        );
        $message .= sprintf(__("Всего проверено страниц: %d\n", 'wpsm'), $total_urls);
        $message .= sprintf(__("Найдено страниц с noindex: %d\n\n", 'wpsm'), $noindex_count);
        $message .= __('Подробный отчет доступен в админ-панели WordPress: ', 'wpsm') . 
                    admin_url('admin.php?page=wpsm-dashboard&status=noindex') . "\n";
        
        wp_mail($admin_email, $subject, $message);
    }
}