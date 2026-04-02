(function () {
    'use strict';

    const cfg = window.AR || {};

    /* ---- DOM ---- */
    const player       = document.getElementById('article-reader-player');
    const btnPlay      = document.getElementById('ar-play');
    const btnStop      = document.getElementById('ar-stop');
    const btnSpeed     = document.getElementById('ar-speed');
    const progressFill = document.getElementById('ar-progress');
    const progressTrack= player && player.querySelector('.ar-progress-wrap');
    const timeEl       = document.getElementById('ar-time');
    const statusEl     = document.getElementById('ar-status');
    const waveform     = player && player.querySelector('.ar-waveform');
    const iconPlay     = btnPlay && btnPlay.querySelector('.ar-icon--play');
    const iconPause    = btnPlay && btnPlay.querySelector('.ar-icon--pause');
    const iconLoad     = btnPlay && btnPlay.querySelector('.ar-icon--loading');

    if (!player || !btnPlay) return;

    /* ---- Stan ---- */
    let chunks      = [];
    let paragraphs  = [];
    let audioQueue  = {};
    let currentIdx  = 0;
    let elapsed     = 0;
    let timerID     = null;
    let startTime   = null;
    let isPlaying   = false;
    let isPaused    = false;
    let currentAudio= null;
    let currentRate = parseFloat(cfg.rate) || 1.0;

    const SPEED_STEPS = [0.75, 0.9, 1.0, 1.1, 1.25, 1.5, 1.75, 2.0];
    let speedIdx = SPEED_STEPS.findIndex(s => Math.abs(s - currentRate) < 0.05);
    if (speedIdx < 0) speedIdx = 2;

    if (btnSpeed) btnSpeed.textContent = currentRate + '×';

    /* ---- Helpers ---- */
    const setStatus = msg => { if (statusEl) statusEl.textContent = msg; };
    const fmt = s => `${Math.floor(s / 60)}:${String(Math.floor(s % 60)).padStart(2, '0')}`;

    function setUI(state) {
        isPlaying = state === 'playing';
        isPaused  = state === 'paused';
        player.classList.toggle('ar-is-playing', isPlaying);
        if (waveform) waveform.classList.toggle('ar-waveform--active', isPlaying);
        if (iconPlay)  iconPlay.style.display  = (state === 'idle' || state === 'paused') ? '' : 'none';
        if (iconPause) iconPause.style.display = state === 'playing' ? '' : 'none';
        if (iconLoad)  iconLoad.style.display  = state === 'loading' ? '' : 'none';
    }

    function updateProgress() {
        if (!chunks.length || !progressFill) return;
        const pct = Math.round((currentIdx / chunks.length) * 100);
        progressFill.style.width = pct + '%';
    }

    function startTimer() {
        clearInterval(timerID);
        timerID = setInterval(() => {
            if (isPlaying) { elapsed++; if (timeEl) timeEl.textContent = fmt(elapsed); }
        }, 1000);
    }
    function stopTimer() { clearInterval(timerID); }

    /* ---- Podział tekstu na chunki ---- */
    function buildChunks() {
        const src = document.getElementById('ar-text-source');
        if (!src) return [];
        const text = src.textContent.replace(/\s+/g, ' ').trim();
        const sentences = text.match(/[^.!?…]+[.!?…]*\s*/g) || [text];
        const result = [];
        let cur = '';
        for (const s of sentences) {
            if ((cur + s).length > 1500 && cur.length > 0) { result.push(cur.trim()); cur = s; }
            else cur += s;
        }
        if (cur.trim()) result.push(cur.trim());
        return result;
    }

    /* ---- Podświetlanie ---- */
    function initParagraphs() {
        // Próbuj znaleźć akapity z atrybutem dodanym przez PHP
        let found = Array.from(document.querySelectorAll('[data-ar-p]'));

        // Fallback: jeśli PHP nie dodało atrybutów (np. cache), dodaj je przez JS
        if (found.length === 0) {
            const selectors = [
                'article .entry-content p',
                'article .post-content p',
                '.entry-content p',
                '.post-content p',
                '.article-content p',
                '.content-area p',
                'main article p',
                '.single-post p',
                'article p',
            ];

            let container = null;
            for (const sel of selectors) {
                const els = document.querySelectorAll(sel);
                if (els.length > 1) { // min 2 akapity
                    container = els;
                    break;
                }
            }

            // Ostateczny fallback - wszystkie p na stronie z treścią
            if (!container) {
                container = Array.from(document.querySelectorAll('p')).filter(p => {
                    // Pomiń krótkie, nawigacyjne i elementy playera
                    return p.textContent.trim().length > 60
                        && !p.closest('#article-reader-player')
                        && !p.closest('nav')
                        && !p.closest('header')
                        && !p.closest('footer');
                });
            }

            // Nadaj atrybuty
            Array.from(container).forEach((p, i) => {
                p.setAttribute('data-ar-p', i);
            });

            found = Array.from(document.querySelectorAll('[data-ar-p]'));
        }

        return found;
    }

    function highlightChunk(idx) {
        if (!paragraphs.length || !chunks.length) return;

        paragraphs.forEach(p => p.classList.remove('ar-highlighted'));

        const pIdx = Math.min(
            paragraphs.length - 1,
            Math.floor((idx / chunks.length) * paragraphs.length)
        );

        const p = paragraphs[pIdx];
        if (!p) return;
        p.classList.add('ar-highlighted');

        // Przewiń do akapitu jeśli poza widokiem
        const rect = p.getBoundingClientRect();
        if (rect.top < 80 || rect.bottom > window.innerHeight - 80) {
            p.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }

    function clearHighlight() {
        paragraphs.forEach(p => p.classList.remove('ar-highlighted'));
    }

    /* ---- Pobieranie audio ---- */
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
                const src = data.data.url
                    ? data.data.url
                    : 'data:audio/mpeg;base64,' + data.data.audio;
                return new Audio(src);
            });
    }

    function prefetch(idx) {
        if (idx < chunks.length && !audioQueue[idx]) {
            audioQueue[idx] = fetchChunk(idx).catch(err => {
                console.warn('[AR] prefetch error chunk', idx, err);
                return null;
            });
        }
    }

    /* ---- Odtwarzanie chunka ---- */
    async function playChunk(idx) {
        if (idx >= chunks.length) { onFinished(); return; }

        currentIdx = idx;
        updateProgress();
        highlightChunk(idx);

        const preview = chunks[idx].slice(0, 60);
        setStatus(chunks[idx].length > 60 ? preview + '…' : preview);

        if (!audioQueue[idx]) audioQueue[idx] = fetchChunk(idx);

        let audio;
        try {
            audio = await audioQueue[idx];
        } catch (e) {
            setStatus('Błąd: ' + (e.message || e));
            setUI('idle'); stopTimer(); return;
        }

        if (!audio) { playChunk(idx + 1); return; }

        prefetch(idx + 1);
        prefetch(idx + 2);

        currentAudio = audio;
        setUI('playing');

        audio.onended = () => { if (!isPaused) playChunk(idx + 1); };
        audio.onerror = () => { console.warn('[AR] audio error chunk', idx); playChunk(idx + 1); };

        audio.play().catch(err => {
            setStatus('Błąd odtwarzania: ' + err.message);
            setUI('idle');
        });
    }

    function onFinished() {
        sendStat('complete');
        clearHighlight();
        setUI('idle');
        stopTimer();
        if (progressFill) progressFill.style.width = '100%';
        setStatus('Artykuł przeczytany ✓');
        setTimeout(() => {
            if (progressFill) progressFill.style.width = '0%';
            if (timeEl) timeEl.textContent = '0:00';
            elapsed = 0; currentIdx = 0;
            setStatus('Naciśnij play, aby słuchać');
        }, 3500);
    }

    /* ---- Statystyki ---- */
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

    /* ---- Previjanie (seek) ---- */
    function seekTo(pct) {
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
            const preview = chunks[targetIdx]?.slice(0, 60) || '';
            setStatus(preview + (chunks[targetIdx]?.length > 60 ? '…' : ''));
        }
    }

    if (progressTrack) {
        // Kliknięcie
        progressTrack.addEventListener('click', e => {
            // Upewnij się że chunki są zbudowane
            if (!chunks.length) chunks = buildChunks();
            if (!paragraphs.length) paragraphs = initParagraphs();
            const rect = progressTrack.getBoundingClientRect();
            const pct  = Math.max(0, Math.min(1, (e.clientX - rect.left) / rect.width));
            seekTo(pct);
        });

        // Podgląd pozycji przy hover
        progressTrack.addEventListener('mousemove', e => {
            const rect = progressTrack.getBoundingClientRect();
            const pct  = Math.max(0, Math.min(1, (e.clientX - rect.left) / rect.width));
            progressTrack.setAttribute('title', Math.round(pct * 100) + '%');
        });
    }

    /* ---- Przyciski ---- */
    btnPlay.addEventListener('click', async () => {
        if (!cfg.hasKey) {
            setStatus('⚠ Brak klucza API. Uzupełnij w Ustawienia → Article Reader.');
            return;
        }

        if (isPlaying) {
            if (currentAudio) currentAudio.pause();
            isPaused = true; setUI('paused'); setStatus('Wstrzymano');
            return;
        }

        if (isPaused && currentAudio) {
            isPaused = false; setUI('playing'); setStatus('Wznowiono');
            currentAudio.play();
            return;
        }

        if (currentAudio) { currentAudio.pause(); currentAudio = null; }

        chunks     = buildChunks();
        paragraphs = initParagraphs();
        audioQueue = {};
        currentIdx = 0;
        elapsed    = 0;
        startTime  = Date.now();
        if (timeEl) timeEl.textContent = '0:00';

        if (!chunks.length) { setStatus('Brak treści do odczytania.'); return; }

        setUI('loading');
        setStatus('Pobieranie audio…');
        startTimer();
        sendStat('play');
        prefetch(0);
        prefetch(1);

        await playChunk(0);
    });

    btnStop.addEventListener('click', () => {
        if (isPlaying || isPaused) sendStat('pause');
        if (currentAudio) { currentAudio.pause(); currentAudio = null; }
        audioQueue = {};
        clearHighlight();
        setUI('idle');
        stopTimer();
        elapsed = 0; currentIdx = 0;
        if (progressFill) progressFill.style.width = '0%';
        if (timeEl) timeEl.textContent = '0:00';
        setStatus('Naciśnij play, aby słuchać');
    });

    btnSpeed.addEventListener('click', () => {
        speedIdx    = (speedIdx + 1) % SPEED_STEPS.length;
        currentRate = SPEED_STEPS[speedIdx];
        btnSpeed.textContent = currentRate + '×';

        if (isPlaying || isPaused) {
            const resumeIdx = currentIdx;
            if (currentAudio) { currentAudio.pause(); currentAudio = null; }
            audioQueue = {};
            isPaused   = false;
            setUI('loading');
            setStatus('Zmiana prędkości…');
            playChunk(resumeIdx);
        }
    });

    window.addEventListener('beforeunload', () => {
        if (isPlaying || isPaused) sendStat('pause');
        if (currentAudio) currentAudio.pause();
    });

})();
