(function () {
    'use strict';

    // Działa zarówno przy natychmiastowym jak i opóźnionym (LiteSpeed) ładowaniu
    function init() {
        const cfg = window.AR || {};

    /* ---- DOM — main player ---- */
    const player      = document.getElementById('article-reader-player');
    const btnPlay     = document.getElementById('ar-play');
    const btnStop     = document.getElementById('ar-stop');
    const btnSpeed    = document.getElementById('ar-speed');
    const btnSkipBack = document.getElementById('ar-skip-back');
    const btnSkipFwd  = document.getElementById('ar-skip-fwd');
    const btnDownload = document.getElementById('ar-download');
    const progressFill= document.getElementById('ar-progress');
    const progressWrap= player && player.querySelector('.ar-progress-wrap');
    const timeEl      = document.getElementById('ar-time');
    const statusEl    = document.getElementById('ar-status');
    const waveform    = player && player.querySelector('.ar-waveform');
    const iconPlay    = btnPlay && btnPlay.querySelector('.ar-icon--play');
    const iconPause   = btnPlay && btnPlay.querySelector('.ar-icon--pause');
    const iconLoad    = btnPlay && btnPlay.querySelector('.ar-icon--loading');

    /* ---- DOM — floating player ---- */
    const floating      = document.getElementById('ar-floating');
    const floatPlay     = document.getElementById('ar-float-play');
    const floatSkipBack = document.getElementById('ar-float-skip-back');
    const floatSkipFwd  = document.getElementById('ar-float-skip-fwd');
    const floatClose    = document.getElementById('ar-float-close');
    const floatStatus   = document.getElementById('ar-float-status');
    const floatProgress = document.getElementById('ar-float-progress');
    const floatWaveform = floating && floating.querySelector('.ar-float-waveform');
    const floatIconPlay = floatPlay && floatPlay.querySelector('.ar-icon--play');
    const floatIconPause= floatPlay && floatPlay.querySelector('.ar-icon--pause');

    if (!player || !btnPlay) return;

    /* ---- State ---- */
    let chunks      = [], paragraphs = [], audioQueue = {};
    let currentIdx  = 0, elapsed = 0, timerID = null, startTime = null;
    let isPlaying   = false, isPaused = false, currentAudio = null;
    let floatDismissed = false;
    let firstChunkUrl  = null; // dla przycisku download
    let currentRate = parseFloat(cfg.rate) || 1.0;

    const SPEED_STEPS = [0.75, 0.9, 1.0, 1.1, 1.25, 1.5, 1.75, 2.0];
    let speedIdx = SPEED_STEPS.findIndex(s => Math.abs(s - currentRate) < 0.05);
    if (speedIdx < 0) speedIdx = 2;
    if (btnSpeed) btnSpeed.textContent = currentRate + '×';

    /* ---- Helpers ---- */
    const setStatus = (msg) => {
        if (statusEl)    statusEl.textContent    = msg;
        if (floatStatus) floatStatus.textContent = msg;
    };
    const fmt = s => `${Math.floor(s/60)}:${String(Math.floor(s%60)).padStart(2,'0')}`;

    function setUI(state) {
        isPlaying = state === 'playing';
        isPaused  = state === 'paused';

        player.classList.toggle('ar-is-playing', isPlaying);
        if (waveform)     waveform.classList.toggle('ar-waveform--active', isPlaying);
        if (floatWaveform) floatWaveform.classList.toggle('ar-waveform--active', isPlaying);

        const showPlay  = state === 'idle' || state === 'paused';
        const showPause = state === 'playing';
        const showLoad  = state === 'loading';

        if (iconPlay)  iconPlay.style.display   = showPlay  ? '' : 'none';
        if (iconPause) iconPause.style.display  = showPause ? '' : 'none';
        if (iconLoad)  iconLoad.style.display   = showLoad  ? '' : 'none';
        if (floatIconPlay)  floatIconPlay.style.display  = showPlay  ? '' : 'none';
        if (floatIconPause) floatIconPause.style.display = showPause ? '' : 'none';

        // Floating player visibility
        if (floating && !floatDismissed) {
            floating.classList.toggle('ar-floating--active',
                (isPlaying || isPaused) && !isMainPlayerVisible()
            );
        }
    }

    function updateProgress() {
        if (!chunks.length) return;
        const pct = Math.round((currentIdx / chunks.length) * 100);
        if (progressFill)   progressFill.style.width   = pct + '%';
        if (floatProgress)  floatProgress.style.width  = pct + '%';
        if (progressWrap)   progressWrap.setAttribute('aria-valuenow', pct);
    }

    function startTimer() {
        clearInterval(timerID);
        timerID = setInterval(() => {
            if (isPlaying) {
                elapsed++;
                if (timeEl) timeEl.textContent = fmt(elapsed);
                // Aktualizuj też floating progress na podstawie currentAudio.currentTime
                if (currentAudio && currentAudio.duration && floatProgress) {
                    const chunkPct = currentAudio.currentTime / currentAudio.duration;
                    const overall  = ((currentIdx + chunkPct) / chunks.length) * 100;
                    floatProgress.style.width = Math.round(overall) + '%';
                }
            }
        }, 1000);
    }
    function stopTimer() { clearInterval(timerID); }

    /* ---- Elementor/XStore paragraph detection ---- */
    function initParagraphs() {
        // 1. Akapity z data-ar-p (dodane przez PHP gdy cache off)
        let found = Array.from(document.querySelectorAll('[data-ar-p]'));
        if (found.length > 1) return found;

        // 2. Selektory — XStore i Elementor na początku
        const selectors = [
            // XStore theme (etheme)
            '.etheme-single-post-content p',
            '.single-post-content p',
            // Elementor text editor widget
            '.elementor-widget-theme-post-content p',
            '.elementor-widget-text-editor p',
            '.elementor-text-editor p',
            // Elementor generic
            '.elementor-widget-container > .elementor-widget-text-editor > div > p',
            '.elementor-widget-container p',
            '.elementor-section p',
            // Standard WordPress
            'article .entry-content p',
            '.entry-content p',
            '.post-content p',
            '.single-content p',
            'main article p',
            'article p',
        ];

        for (const sel of selectors) {
            const nodes = Array.from(document.querySelectorAll(sel)).filter(p =>
                p.textContent.trim().length > 30 &&
                !p.closest('#article-reader-player') &&
                !p.closest('#ar-floating') &&
                !p.closest('nav') &&
                !p.closest('header') &&
                !p.closest('footer') &&
                !p.closest('.widget') &&
                !p.closest('.comments-area')
            );
            if (nodes.length > 1) {
                console.log('[Yash] ✓ Paragraphs via:', sel, '→', nodes.length);
                found = nodes;
                break;
            }
        }

        // 3. Ostateczny fallback — wszystkie <p> z treścią
        if (found.length === 0) {
            console.warn('[Yash] ⚠ Using generic fallback — please report your theme name');
            found = Array.from(document.querySelectorAll('p')).filter(p =>
                p.textContent.trim().length > 60 &&
                !p.closest('#article-reader-player') &&
                !p.closest('#ar-floating') &&
                !p.closest('nav') &&
                !p.closest('header') &&
                !p.closest('footer') &&
                !p.closest('.comments-area') &&
                !p.closest('.widget')
            );
        }

        found.forEach((p, i) => p.setAttribute('data-ar-p', i));
        console.log('[Yash] Total paragraphs:', found.length);
        return found;
    }

    /* ---- Podświetlanie ---- */
    function highlightChunk(idx) {
        if (!paragraphs.length || !chunks.length) return;
        paragraphs.forEach(p => p.classList.remove('ar-highlighted'));
        const pIdx = Math.min(paragraphs.length - 1, Math.floor((idx / chunks.length) * paragraphs.length));
        const p = paragraphs[pIdx];
        if (!p) return;
        p.classList.add('ar-highlighted');
        const rect = p.getBoundingClientRect();
        if (rect.top < 80 || rect.bottom > window.innerHeight - 80) {
            p.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }
    function clearHighlight() { paragraphs.forEach(p => p.classList.remove('ar-highlighted')); }

    /* ---- Chunking — zachowaj strukturę dla SSML ---- */
    function buildChunks() {
        const src = document.getElementById('ar-text-source');
        if (!src) return [];

        const text = src.textContent.replace(/[ \t]+/g, ' ').trim();
        const paras = text.split(/\n+/).filter(p => p.trim().length > 0);
        const result = [];
        let cur = '';

        for (const p of paras) {
            const sep = cur ? '\n' : '';
            if ((cur + sep + p).length > 1400 && cur.length > 0) {
                result.push(cur.trim());
                cur = p;
            } else {
                cur += sep + p;
            }
        }
        if (cur.trim()) result.push(cur.trim());
        return result.length ? result : [text];
    }

    /* ---- AI Summary — pobierz i wstaw przed artykułem ---- */
    async function fetchAndPlaySummary() {
        if (!cfg.hasClaude || !cfg.postId) return null;

        setStatus('Generowanie podsumowania AI…');
        const body = new FormData();
        body.append('action',  'ar_get_summary');
        body.append('nonce',   cfg.nonce);
        body.append('post_id', cfg.postId);

        try {
            const r    = await fetch(cfg.ajaxUrl, { method: 'POST', body });
            const data = await r.json();
            if (!data.success || !data.data.summary) return null;

            const summary = data.data.summary;

            // Wygeneruj audio dla podsumowania
            const ttsBody = new FormData();
            ttsBody.append('action',    'ar_synthesize');
            ttsBody.append('nonce',     cfg.nonce);
            ttsBody.append('post_id',   0); // nie cachuj — podsumowanie może się zmienić
            ttsBody.append('chunk_idx', 0);
            ttsBody.append('text',      summary);
            ttsBody.append('voice',     cfg.voice || 'pl-PL-Wavenet-A');
            ttsBody.append('rate',      currentRate * 0.95); // minimalnie wolniej dla wstępu
            ttsBody.append('pitch',     cfg.pitch || 0);

            const ttsR    = await fetch(cfg.ajaxUrl, { method: 'POST', body: ttsBody });
            const ttsData = await ttsR.json();
            if (!ttsData.success) return null;

            return new Audio('data:audio/mpeg;base64,' + ttsData.data.audio);
        } catch(e) {
            console.warn('[Yash] AI summary error:', e);
            return null;
        }
    }

    /* ---- Fetch audio ---- */
    function fetchChunk(idx) {
        const body = new FormData();
        body.append('action',    'ar_synthesize');
        body.append('nonce',     cfg.nonce);
        body.append('post_id',   cfg.postId || 0);
        body.append('chunk_idx', idx);
        body.append('text',      chunks[idx]);
        body.append('voice',     cfg.voice || 'pl-PL-Wavenet-A');
        body.append('rate',      currentRate);
        body.append('pitch',     cfg.pitch || 0);

        return fetch(cfg.ajaxUrl, { method: 'POST', body })
            .then(r => r.json())
            .then(data => {
                if (!data.success) throw new Error(data.data?.message || 'Błąd TTS');
                // Zaktualizuj przycisk download dla chunk 0
                if (idx === 0 && data.data.url && btnDownload) {
                    btnDownload.href = data.data.url;
                    btnDownload.style.opacity = '1';
                    btnDownload.style.pointerEvents = '';
                    firstChunkUrl = data.data.url;
                }
                const src = 'data:audio/mpeg;base64,' + data.data.audio;
                return new Audio(src);
            });
    }

    function prefetch(idx) {
        if (idx < chunks.length && !audioQueue[idx]) {
            audioQueue[idx] = fetchChunk(idx).catch(err => {
                console.warn('[Yash] prefetch error chunk', idx, err);
                return null;
            });
        }
    }

    /* ---- Play chunk ---- */
    async function playChunk(idx) {
        if (idx >= chunks.length) { onFinished(); return; }
        currentIdx = idx;
        updateProgress();
        highlightChunk(idx);
        const preview = chunks[idx].replace(/\n/g, ' ').slice(0, 55);
        setStatus(chunks[idx].length > 55 ? preview + '…' : preview);

        if (!audioQueue[idx]) audioQueue[idx] = fetchChunk(idx);
        let audio;
        try {
            audio = await audioQueue[idx];
        } catch(e) {
            setStatus('Błąd: ' + (e.message || e));
            setUI('idle'); stopTimer(); return;
        }
        if (!audio) { playChunk(idx + 1); return; }

        prefetch(idx + 1);
        prefetch(idx + 2);

        currentAudio = audio;
        setUI('playing');
        audio.onended = () => { if (!isPaused) playChunk(idx + 1); };
        audio.onerror = () => playChunk(idx + 1);
        audio.play().catch(err => { setStatus('Błąd: ' + err.message); setUI('idle'); });
    }

    function onFinished() {
        sendStat('complete');
        clearHighlight();
        setUI('idle');
        stopTimer();
        if (progressFill) progressFill.style.width = '100%';
        if (floatProgress) floatProgress.style.width = '100%';
        setStatus('Artykuł przeczytany ✓');
        setTimeout(() => {
            if (progressFill) progressFill.style.width = '0%';
            if (floatProgress) floatProgress.style.width = '0%';
            if (timeEl) timeEl.textContent = '0:00';
            elapsed = 0; currentIdx = 0;
            setStatus('Naciśnij play, aby słuchać');
        }, 3500);
    }

    /* ---- Skip ±15s ---- */
    function skipSeconds(secs) {
        if (!currentAudio) return;
        const newTime = (currentAudio.currentTime || 0) + secs;
        if (newTime < 0) {
            // Poprzedni chunk
            if (currentIdx > 0) {
                const prevIdx = currentIdx - 1;
                if (currentAudio) { currentAudio.pause(); currentAudio = null; }
                audioQueue = {};
                setUI('loading');
                playChunk(prevIdx);
            } else {
                currentAudio.currentTime = 0;
            }
        } else if (currentAudio.duration && newTime > currentAudio.duration) {
            // Następny chunk
            if (isPlaying || isPaused) {
                if (currentAudio) { currentAudio.pause(); currentAudio = null; }
                isPaused = false;
                setUI('loading');
                playChunk(currentIdx + 1);
            }
        } else {
            currentAudio.currentTime = newTime;
        }
    }

    /* ---- Stats ---- */
    function sendStat(action) {
        if (!cfg.postId) return;
        const listenedS = startTime ? Math.round((Date.now() - startTime) / 1000) : elapsed;
        const body = new FormData();
        body.append('action',      'ar_stat');
        body.append('nonce',       cfg.nonce);
        body.append('post_id',     cfg.postId);
        body.append('action_type', action);
        body.append('listened_s',  listenedS);
        fetch(cfg.ajaxUrl, { method: 'POST', body }).catch(() => {});
    }

    /* ---- Seek (kliknięcie w pasek) ---- */
    function seek(pct) {
        if (!chunks.length) return;
        const targetIdx = Math.max(0, Math.min(chunks.length - 1, Math.floor(pct * chunks.length)));
        if (currentAudio) { currentAudio.pause(); currentAudio = null; }
        audioQueue = {};
        clearHighlight();
        if (isPlaying || isPaused) {
            isPaused = false;
            setUI('loading');
            setStatus('Przewijanie…');
            playChunk(targetIdx);
        } else {
            currentIdx = targetIdx;
            updateProgress();
        }
    }

    if (progressWrap) {
        progressWrap.style.cursor = 'pointer';
        progressWrap.addEventListener('click', e => {
            if (!chunks.length) { chunks = buildChunks(); paragraphs = initParagraphs(); }
            const rect = progressWrap.getBoundingClientRect();
            seek(Math.max(0, Math.min(1, (e.clientX - rect.left) / rect.width)));
        });
    }

    /* ---- Floating player visibility ---- */
    function isMainPlayerVisible() {
        if (!player) return true;
        const rect = player.getBoundingClientRect();
        return rect.bottom > 0 && rect.top < window.innerHeight;
    }

    if (floating && typeof IntersectionObserver !== 'undefined') {
        const obs = new IntersectionObserver(entries => {
            if (floatDismissed) return;
            const visible = entries[0].isIntersecting;
            if (!visible && (isPlaying || isPaused)) {
                floating.classList.add('ar-floating--active');
            } else {
                floating.classList.remove('ar-floating--active');
            }
        }, { threshold: 0.1 });
        obs.observe(player);
    }

    /* ---- Start / Pause / Resume ---- */
    async function startOrToggle() {
        if (!cfg.hasKey) {
            setStatus('⚠ Brak klucza API. Uzupełnij w Ustawienia → Yash.');
            return;
        }
        if (isPlaying) {
            if (currentAudio) currentAudio.pause();
            isPaused = true; setUI('paused'); setStatus('Wstrzymano'); return;
        }
        if (isPaused && currentAudio) {
            isPaused = false; setUI('playing'); setStatus('Wznowiono');
            currentAudio.play(); return;
        }
        if (currentAudio) { currentAudio.pause(); currentAudio = null; }
        chunks = buildChunks(); paragraphs = initParagraphs();
        audioQueue = {}; currentIdx = 0; elapsed = 0; startTime = Date.now();
        if (timeEl) timeEl.textContent = '0:00';
        if (!chunks.length) { setStatus('Brak treści.'); return; }
        setUI('loading'); setStatus('Pobieranie audio…'); startTimer(); sendStat('play');

        // AI podsumowanie — zagraj przed artykułem jeśli dostępne
        if (cfg.hasClaude) {
            const summaryAudio = await fetchAndPlaySummary();
            if (summaryAudio) {
                setStatus('Podsumowanie AI…');
                setUI('playing');
                currentAudio = summaryAudio;
                await new Promise(resolve => {
                    summaryAudio.onended = resolve;
                    summaryAudio.onerror = resolve;
                    summaryAudio.play().catch(resolve);
                });
                if (!isPlaying && !isPaused) return; // zatrzymano podczas podsumowania
            }
        }

        prefetch(0); prefetch(1);
        await playChunk(0);
    }

    function stopAll() {
        if (isPlaying || isPaused) sendStat('pause');
        if (currentAudio) { currentAudio.pause(); currentAudio = null; }
        audioQueue = {}; clearHighlight(); setUI('idle'); stopTimer();
        elapsed = 0; currentIdx = 0;
        if (progressFill) progressFill.style.width = '0%';
        if (floatProgress) floatProgress.style.width = '0%';
        if (timeEl) timeEl.textContent = '0:00';
        if (floating) floating.classList.remove('ar-floating--active');
        setStatus('Naciśnij play, aby słuchać');
    }

    /* ---- Przyciski ---- */
    btnPlay.addEventListener('click', startOrToggle);
    btnStop.addEventListener('click', stopAll);

    if (btnSkipBack) btnSkipBack.addEventListener('click', () => skipSeconds(-15));
    if (btnSkipFwd)  btnSkipFwd.addEventListener('click',  () => skipSeconds(15));

    if (btnSpeed) {
        btnSpeed.addEventListener('click', () => {
            speedIdx = (speedIdx + 1) % SPEED_STEPS.length;
            currentRate = SPEED_STEPS[speedIdx];
            btnSpeed.textContent = currentRate + '×';
            if (isPlaying || isPaused) {
                const resumeIdx = currentIdx;
                if (currentAudio) { currentAudio.pause(); currentAudio = null; }
                audioQueue = {}; isPaused = false;
                setUI('loading'); setStatus('Zmiana prędkości…');
                playChunk(resumeIdx);
            }
        });
    }

    /* ---- Floating player przyciski ---- */
    if (floatPlay)     floatPlay.addEventListener('click',     startOrToggle);
    if (floatSkipBack) floatSkipBack.addEventListener('click', () => skipSeconds(-15));
    if (floatSkipFwd)  floatSkipFwd.addEventListener('click',  () => skipSeconds(15));
    if (floatClose) {
        floatClose.addEventListener('click', () => {
            floatDismissed = true;
            floating.classList.remove('ar-floating--active');
        });
    }

    /* ---- Klawiatura ---- */
    document.addEventListener('keydown', e => {
        const tag = document.activeElement?.tagName;
        if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;
        if (e.code === 'Space') {
            // Tylko gdy player jest na stronie i użytkownik nie fokusuje czegoś innego
            if (document.activeElement === document.body || document.activeElement === null) {
                e.preventDefault();
                startOrToggle();
            }
        }
        if (e.code === 'ArrowRight' && (isPlaying || isPaused)) { e.preventDefault(); skipSeconds(15); }
        if (e.code === 'ArrowLeft'  && (isPlaying || isPaused)) { e.preventDefault(); skipSeconds(-15); }
    });

    /* ---- Cleanup ---- */
    window.addEventListener('beforeunload', () => {
        if (isPlaying || isPaused) sendStat('pause');
        if (currentAudio) currentAudio.pause();
    });

    } // end init()

    // Uruchom — działa czy DOM jest gotowy czy nie
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
