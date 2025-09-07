(function($){
    let total = 0, batch = 40, processed = 0, running = false, results = [];
    let retries = 0, maxRetries = 3, backoff = 500;

    function setProgress(pct, text){
        $('#ums-bar-fill').css('width', pct + '%');
        $('#ums-progress').text(text || '');
    }

    function renderTable(items){
        const $tbody = $('#ums-table tbody').empty();
        items.forEach(it => {
            const row = `
            <tr>
              <td><input type="checkbox" class="ums-chk" value="${it.ID}"></td>
              <td>${it.ID}</td>
              <td>${it.thumb ? `<img src="${it.thumb}" alt="">` : ''}</td>
              <td>${$('<div>').text(it.title).html()}</td>
              <td><a href="${it.url}" target="_blank">${it.url}</a></td>
              <td>${$('<div>').text(it.file).html()}</td>
            </tr>`;
            $tbody.append(row);
        });
        $('#ums-results').show();
    }

    function getState(){ return $.post(UMS.ajax, { action: 'zums_get_state', nonce: UMS.nonce }); }
    function resetState(){ return $.post(UMS.ajax, { action: 'zums_reset_state', nonce: UMS.nonce }); }
    function getLastError(){ return $.post(UMS.ajax, { action: 'zums_get_last_error', nonce: UMS.nonce }); }

    function startScan(){
        if (running) return;
        running = true;
        results = [];
        retries = 0; backoff = 500; processed = 0;
        batch = parseInt($('#ums-batch').val(), 10) || 40;
        setProgress(0, UMS.i18n.starting);

        resetState().always(function(){
            $.post(UMS.ajax, { action: 'ums_start_scan', nonce: UMS.nonce, batch })
            .done(function(res){
                if (!res || !res.success) { alert('Init error'); running=false; return; }
                total = res.data.total;
                batch = res.data.batch;
                processed = 0;
                scanNext();
            })
            .fail(function(){ alert('AJAX error'); running=false; });
        });
    }

    function scanNext(){
        if (!running) return;
        if (processed >= total) { finishScan(); return; }
        const limit = batch;
        const offset = processed;

        $.post(UMS.ajax, { action: 'ums_scan_batch', nonce: UMS.nonce, offset, limit })
        .done(function(res){
            retries = 0; backoff = 500;
            if (!res || !res.success) { alert('Batch error'); running=false; return; }
            processed += limit;
            const pct = Math.min(100, Math.round((processed/total)*100));
            setProgress(pct, UMS.i18n.scanning + ' ' + processed + '/' + total);
            setTimeout(scanNext, 300);
        })
        .fail(function(xhr){
            if (retries < maxRetries) {
                retries++; backoff = Math.min(backoff*2, 8000);
                setProgress(Math.min(99, Math.round((processed/total)*100)), 'Reconnexion… (tentative ' + retries + '/' + maxRetries + ')');
                setTimeout(scanNext, backoff);
            } else {
                running=false;
                getLastError().done(function(log){
                    if (log && log.success && log.data) {
                        alert('AJAX error — ' + (log.data.message || '') + '\n' + (log.data.file || '') + ':' + (log.data.line || ''));
                    } else {
                        alert('AJAX error');
                    }
                }).fail(function(){ alert('AJAX error'); });
            }
        });
    }

    function finishScan(){
        $.post(UMS.ajax, { action: 'ums_finish_scan', nonce: UMS.nonce })
        .done(function(res){
            running = false;
            if (!res || !res.success) { alert('Finish error'); return; }
            results = res.data.results || [];
            setProgress(100, UMS.i18n.done + ' — ' + results.length + ' suspects');
            renderTable(results);
        })
        .fail(function(){ alert('AJAX error'); running=false; });
    }

    function trashSelected(){
        const ids = $('.ums-chk:checked').map(function(){ return $(this).val(); }).get();
        if (!ids.length) { alert('Sélectionnez au moins une image'); return; }
        if (!confirm('Mettre à la corbeille les fichiers sélectionnés ?')) return;
        $.post(UMS.ajax, { action: 'zums_trash_selected', nonce: UMS.nonce, ids })
        .done(function(res){
            if (!res || !res.success) { alert('Erreur corbeille'); return; }
            alert('Envoyées à la corbeille: ' + res.data.trashed);
            const set = new Set(ids.map(String));
            results = results.filter(r => !set.has(String(r.ID)));
            renderTable(results);
        })
        .fail(function(){ alert('AJAX error'); });
    }

    $(function(){
        // Offer resume if state exists
        getState().done(function(res){
            if (res && res.success && res.data && res.data.offset && res.data.total) {
                if (confirm(UMS.i18n.resumeAsk + ' (' + res.data.offset + '/' + res.data.total + ')')) {
                    total = res.data.total;
                    batch = res.data.batch || 40;
                    processed = res.data.offset;
                    running = true;
                    setProgress(Math.round((processed/total)*100), 'Reprise…');
                    scanNext();
                }
            }
        });

        $('#ums-start').on('click', startScan);
        $('#ums-trash-selected').on('click', trashSelected);
        $('#ums-check-all').on('change', function(){ $('.ums-chk').prop('checked', this.checked); });
    });
})(jQuery);
