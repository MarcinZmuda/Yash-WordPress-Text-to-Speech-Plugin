(function() {
    'use strict';
    const { ajaxUrl, nonce } = window.ARAdmin || {};

    const btnLoad      = document.getElementById('ar-bulk-load');
    const btnStart     = document.getElementById('ar-bulk-start');
    const btnSelectAll = document.getElementById('ar-bulk-select-all');
    const table        = document.getElementById('ar-bulk-table');
    const tbody        = document.getElementById('ar-bulk-tbody');
    const log          = document.getElementById('ar-bulk-log');
    const checkAll     = document.getElementById('ar-bulk-check-all');

    if (!btnLoad) return;

    let posts = [];

    function addLog(msg, type = 'info') {
        const colors = { info: '#333', success: '#196f3d', error: '#922b21', warn: '#7d6608' };
        log.style.display = 'block';
        log.innerHTML += `<div style="color:${colors[type]||'#333'};margin:2px 0">${msg}</div>`;
        log.scrollTop = log.scrollHeight;
    }

    btnLoad.addEventListener('click', async () => {
        btnLoad.disabled = true;
        btnLoad.textContent = 'Ładowanie…';
        addLog('Pobieranie listy artykułów…');

        const body = new FormData();
        body.append('action', 'ar_get_posts_for_bulk');
        body.append('nonce',  nonce);

        const res  = await fetch(ajaxUrl, { method: 'POST', body });
        const data = await res.json();

        if (!data.success) {
            addLog('Błąd: ' + (data.data?.message || 'nieznany błąd'), 'error');
            btnLoad.disabled = false;
            btnLoad.textContent = 'Załaduj listę';
            return;
        }

        posts = data.data;
        tbody.innerHTML = '';

        posts.forEach(p => {
            const tr = document.createElement('tr');
            tr.id = 'ar-bulk-row-' + p.id;
            tr.innerHTML = `
                <td><input type="checkbox" class="ar-bulk-cb" data-id="${p.id}" ${!p.cached ? 'checked' : ''}></td>
                <td><a href="${p.url}" target="_blank">${p.title}</a></td>
                <td>${p.words}</td>
                <td>${p.cached ? '✅ Tak' : '—'}</td>
                <td id="ar-bulk-status-${p.id}">—</td>
            `;
            tbody.appendChild(tr);
        });

        table.style.display = '';
        btnStart.style.display = '';
        btnSelectAll.style.display = '';
        btnLoad.textContent = 'Odśwież listę';
        btnLoad.disabled = false;
        addLog(`Załadowano ${posts.length} artykułów.`, 'success');
    });

    btnSelectAll.addEventListener('click', () => {
        document.querySelectorAll('.ar-bulk-cb').forEach(cb => {
            const post = posts.find(p => p.id == cb.dataset.id);
            cb.checked = post && !post.cached;
        });
    });

    checkAll.addEventListener('change', () => {
        document.querySelectorAll('.ar-bulk-cb').forEach(cb => cb.checked = checkAll.checked);
    });

    btnStart.addEventListener('click', async () => {
        const selected = Array.from(document.querySelectorAll('.ar-bulk-cb:checked')).map(cb => parseInt(cb.dataset.id));
        if (!selected.length) { addLog('Zaznacz przynajmniej jeden artykuł.', 'warn'); return; }

        btnStart.disabled = true;
        addLog(`Generowanie audio dla ${selected.length} artykułów…`, 'info');

        let done = 0, errors = 0;

        for (const postId of selected) {
            const statusEl = document.getElementById('ar-bulk-status-' + postId);
            if (statusEl) statusEl.textContent = '⏳ Generowanie…';

            const body = new FormData();
            body.append('action',  'ar_bulk_generate');
            body.append('nonce',   nonce);
            body.append('post_id', postId);

            try {
                const res  = await fetch(ajaxUrl, { method: 'POST', body });
                const data = await res.json();
                if (data.success) {
                    done++;
                    const title = posts.find(p => p.id === postId)?.title || '#' + postId;
                    addLog(`✅ ${title}`, 'success');
                    if (statusEl) statusEl.textContent = '✅ Gotowe';
                } else {
                    errors++;
                    addLog(`❌ Post #${postId}: ${data.data?.message || 'błąd'}`, 'error');
                    if (statusEl) statusEl.textContent = '❌ Błąd';
                }
            } catch(e) {
                errors++;
                addLog(`❌ Post #${postId}: ${e.message}`, 'error');
                if (statusEl) statusEl.textContent = '❌ Błąd';
            }

            // Pauza między requestami
            await new Promise(r => setTimeout(r, 1500));
        }

        addLog(`Zakończono: ${done} OK, ${errors} błędów.`, done > 0 ? 'success' : 'error');
        btnStart.disabled = false;
    });
})();
