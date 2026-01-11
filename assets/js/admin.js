jQuery(document).ready(function($) {
    
    let currentPage = 1;
    let currentStatus = $('#wpsm-status-filter').val() || 'all';
    let scanInProgress = false;
    
    // Загрузка результатов при загрузке страницы
    loadResults(currentPage, currentStatus);
    
    // Фильтрация по статусу
    $('#wpsm-status-filter').on('change', function() {
        currentStatus = $(this).val();
        currentPage = 1;
        loadResults(currentPage, currentStatus);
    });
    
    // Пагинация
    $(document).on('click', '.wpsm-page', function(e) {
        e.preventDefault();
        currentPage = $(this).data('page');
        loadResults(currentPage, currentStatus);
    });
    
    // Запуск сканирования
    $('#wpsm-start-scan').on('click', function() {
        if (!confirm(wpsm_ajax.strings.confirm_start)) {
            return;
        }
        
        $('#wpsm-start-scan').prop('disabled', true);
        $('#wpsm-stop-scan').show();
        $('#wpsm-scan-progress').show();
        $('#wpsm-progress-text').text(wpsm_ajax.strings.scan_started);
        
        $.ajax({
            url: wpsm_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'wpsm_start_scan',
                nonce: wpsm_ajax.nonce,
                sitemaps: $('#wpsm_sitemaps').val()
            },
            success: function(response) {
                if (response.success) {
                    scanInProgress = true;
                    monitorScanProgress();
                } else {
                    alert(response.data || 'Error starting scan');
                    resetScanButtons();
                }
            },
            error: function() {
                alert('Network error');
                resetScanButtons();
            }
        });
    });
    
    // Остановка сканирования
    $('#wpsm-stop-scan').on('click', function() {
        if (!confirm(wpsm_ajax.strings.confirm_stop)) {
            return;
        }
        
        $.ajax({
            url: wpsm_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'wpsm_stop_scan',
                nonce: wpsm_ajax.nonce
            },
            success: function() {
                scanInProgress = false;
                resetScanButtons();
                loadResults(currentPage, currentStatus);
            },
            error: function() {
                alert('Network error');
            }
        });
    });
    
    // Проверка статуса сканирования
    function checkScanStatus() {
        $.ajax({
            url: wpsm_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'wpsm_get_scan_progress',
                nonce: wpsm_ajax.nonce
            },
            success: function(response) {
                if (response.success && response.data.in_progress) {
                    scanInProgress = true;
                    $('#wpsm-start-scan').prop('disabled', true);
                    $('#wpsm-stop-scan').show();
                    $('#wpsm-scan-progress').show();
                    $('#wpsm-progress-text').text(
                        'Processed: ' + response.data.processed + ' of ' + response.data.total +
                        ' (' + response.data.progress_percent + '%)'
                    );
                    monitorScanProgress();
                }
            }
        });
    }
    
    // Мониторинг прогресса сканирования
    function monitorScanProgress() {
        if (!scanInProgress) return;
        
        setTimeout(function() {
            $.ajax({
                url: wpsm_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'wpsm_get_scan_progress',
                    nonce: wpsm_ajax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        if (response.data.in_progress) {
                            $('#wpsm-progress-text').text(
                                'Processed: ' + response.data.processed + ' of ' + response.data.total +
                                ' (' + response.data.progress_percent + '%)'
                            );
                            monitorScanProgress();
                        } else {
                            scanInProgress = false;
                            resetScanButtons();
                            $('#wpsm-progress-text').text(wpsm_ajax.strings.scan_completed);
                            loadResults(currentPage, currentStatus);
                            setTimeout(function() {
                                alert(wpsm_ajax.strings.scan_completed);
                            }, 500);
                        }
                    }
                }
            });
        }, 3000);
    }
    
    // Сброс кнопок сканирования
    function resetScanButtons() {
        $('#wpsm-start-scan').prop('disabled', false);
        $('#wpsm-stop-scan').hide();
        $('#wpsm-scan-progress').hide();
    }
    
    // Загрузка результатов
    function loadResults(page, status) {
        $('#wpsm-results-body').html(
            '<tr><td colspan="4" style="text-align: center;"><span class="spinner is-active"></span> Loading...</td></tr>'
        );
        
        $.ajax({
            url: wpsm_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'wpsm_get_results',
                nonce: wpsm_ajax.nonce,
                page: page,
                status: status
            },
            success: function(response) {
                if (response.success) {
                    renderResults(response.data.results);
                    renderPagination(response.data);
                } else {
                    $('#wpsm-results-body').html(
                        '<tr><td colspan="4" style="text-align: center; color: red;">Error loading data</td></tr>'
                    );
                }
            },
            error: function() {
                $('#wpsm-results-body').html(
                    '<tr><td colspan="4" style="text-align: center; color: red;">Network error</td></tr>'
                );
            }
        });
    }
    
    // Отрисовка результатов
    function renderResults(results) {
        let html = '';
        
        if (results.length === 0) {
            html = '<tr><td colspan="4" style="text-align: center;">No data</td></tr>';
        } else {
            $.each(results, function(index, item) {
                let statusClass = 'status-ok';
                let statusText = 'OK';
                
                if (item.is_noindex == '1') {
                    statusClass = 'status-noindex';
                    statusText = 'NOINDEX';
                } else if (item.http_code >= 400 || item.http_code == 0) {
                    statusClass = 'status-error';
                    statusText = 'ERROR';
                }
                
                let displayUrl = item.url.length > 80 ? item.url.substring(0, 80) + '...' : item.url;
                
                html += '<tr>' +
                    '<td><a href="' + item.url + '" target="_blank" title="' + item.url + '">' + displayUrl + '</a></td>' +
                    '<td>' + (item.http_code || '0') + '</td>' +
                    '<td><span class="' + statusClass + '">' + statusText + '</span></td>' +
                    '<td>' + (item.reasons || '') + '</td>' +
                    '</tr>';
            });
        }
        
        $('#wpsm-results-body').html(html);
    }
    
    // Отрисовка пагинации
    function renderPagination(data) {
        let html = '';
        
        if (data.pages > 1) {
            html += '<div class="wpsm-pagination">';
            
            if (currentPage > 1) {
                html += '<button class="button wpsm-page" data-page="1">«</button>' +
                       '<button class="button wpsm-page" data-page="' + (currentPage - 1) + '">‹</button>';
            }
            
            html += '<span style="margin: 0 10px;">Page ' + currentPage + ' of ' + data.pages + '</span>';
            
            if (currentPage < data.pages) {
                html += '<button class="button wpsm-page" data-page="' + (currentPage + 1) + '">›</button>' +
                       '<button class="button wpsm-page" data-page="' + data.pages + '">»</button>';
            }
            
            html += '</div>';
        }
        
        $('#wpsm-pagination').html(html);
    }
    
    // Проверяем статус при загрузке
    setTimeout(checkScanStatus, 1000);
});
