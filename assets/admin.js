(function($){
    let total = 0, batch = 100, processed = 0, running = false, results = [];

    function setProgress(pct, text){
        $('#zums-bar-fill').css('width', pct + '%');
        $('#zums-progress').text(text || '');
    }

    function renderTable(items){
        const $tbody = $('#zums-table tbody').empty();
        items.forEach(it => {
            const row = `
            <tr>
              <td><input type="checkbox" class="zums-chk" value="${it.ID}"></td>
              <td>${it.ID}</td>
              <td>${it.thumb ? `<img src="${it.thumb}" alt="">` : ''}</td>
              <td>${$('<div>').text(it.title).html()}</td>
              <td><a href="${it.url}" target="_blank">${it.url}</a></td>
              <td>${$('<div>').text(it.file).html()}</td>
            </tr>`;
            $tbody.append(row);
        });
        $('#zums-results').show();
    }

    function startScan(){
        if (running) return;
        running = true;
        results = [];
        setProgress(0, ZUMS.i18n.starting);

        $.post(ZUMS.ajax, { action: 'zums_start_scan', nonce: ZUMS.nonce })
        .done(function(res){
            if (!res || !res.success) { alert('Init error'); running=false; return; }
            total = res.data.total;
            batch = res.data.batch;
            processed = 0;
            scanNext();
        })
        .fail(function(){ alert('AJAX error'); running=false; });
    }

    function scanNext(){
        if (processed >= total) { finishScan(); return; }
        const limit = batch;
        const offset = processed;

        $.post(ZUMS.ajax, { action: 'zums_scan_batch', nonce: ZUMS.nonce, offset, limit })
        .done(function(res){
            if (!res || !res.success) { alert('Batch error'); running=false; return; }
            processed += limit;
            const pct = Math.min(100, Math.round((processed/total)*100));
            setProgress(pct, ZUMS.i18n.scanning + ' ' + processed + '/' + total);
            setTimeout(scanNext, 100);
        })
        .fail(function(){ alert('AJAX error'); running=false; });
    }

    function finishScan(){
        $.post(ZUMS.ajax, { action: 'zums_finish_scan', nonce: ZUMS.nonce })
        .done(function(res){
            running = false;
            if (!res || !res.success) { alert('Finish error'); return; }
            results = res.data.results || [];
            setProgress(100, ZUMS.i18n.done + ' — ' + results.length + ' suspects');
            renderTable(results);
        })
        .fail(function(){ alert('AJAX error'); running=false; });
    }

    function trashSelected(){
        const ids = $('.zums-chk:checked').map(function(){ return $(this).val(); }).get();
        if (!ids.length) { alert('Sélectionnez au moins une image'); return; }
        if (!confirm(ZUMS.i18n.trashConfirm)) return;
        $.post(ZUMS.ajax, { action: 'zums_trash_selected', nonce: ZUMS.nonce, ids })
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
        $('#zums-start').on('click', startScan);
        $('#zums-trash-selected').on('click', trashSelected);
        $('#zums-check-all').on('change', function(){
            $('.zums-chk').prop('checked', this.checked);
        });
    });
})(jQuery);
