const cardsEl = document.getElementById('cards');
        const emptyEl = document.getElementById('empty');
        const stationEl = document.getElementById('station');
        const useLogicalCloseEl = document.getElementById('useLogicalClose');
        const lastSyncEl = document.getElementById('lastSync');
        const refreshInEl = document.getElementById('refreshIn');
        const refreshProgressEl = document.getElementById('refreshProgress');
        const soundBtn = document.getElementById('soundToggle');
        let loading = false;
        const refreshIntervalSec = 10;
        let refreshCycleStartedAt = Date.now();
        let refreshCircleLen = 0;
        let waitLimitSec = 0;
        let seenIds = null;
        let soundMuted = false;
        let audioCtx = null;
        let isRefreshing = false;

        const loadMuted = () => {
            try { soundMuted = (localStorage.getItem('ko_sound_muted') === '1'); } catch (_) { soundMuted = false; }
        };
        const saveMuted = () => {
            try { localStorage.setItem('ko_sound_muted', soundMuted ? '1' : '0'); } catch (_) {}
        };
        const renderSoundIcon = () => {
            if (!soundBtn) return;
            soundBtn.textContent = soundMuted ? '🔇' : '🔊';
        };
        const ensureAudio = async () => {
            if (audioCtx) return true;
            const Ctx = window.AudioContext || window.webkitAudioContext;
            if (!Ctx) return false;
            audioCtx = new Ctx();
            try { await audioCtx.resume(); } catch (_) {}
            return true;
        };
        const beep = async () => {
            if (soundMuted) return;
            const ok = await ensureAudio();
            if (!ok || !audioCtx) return;
            if (audioCtx.state === 'suspended') {
                try { await audioCtx.resume(); } catch (_) { return; }
            }
            const o = audioCtx.createOscillator();
            const g = audioCtx.createGain();
            o.type = 'sine';
            o.frequency.value = 880;
            g.gain.value = 0.0001;
            o.connect(g);
            g.connect(audioCtx.destination);
            const now = audioCtx.currentTime;
            g.gain.setValueAtTime(0.0001, now);
            g.gain.exponentialRampToValueAtTime(0.15, now + 0.01);
            g.gain.exponentialRampToValueAtTime(0.0001, now + 0.25);
            o.start(now);
            o.stop(now + 0.26);
        };

        const extractItemIds = () => {
            const els = Array.from(cardsEl.querySelectorAll('.ko-item[data-item-id]'));
            return els.map(el => parseInt(el.getAttribute('data-item-id') || '0', 10)).filter(n => n > 0);
        };
        const detectNewItems = () => {
            const current = extractItemIds();
            if (seenIds === null) {
                seenIds = new Set(current);
                return;
            }
            const curSet = new Set(current);
            let hasNew = false;
            for (const id of curSet) {
                if (!seenIds.has(id)) { hasNew = true; break; }
            }
            seenIds = curSet;
            if (hasNew) beep();
        };

        const updateLive = () => {
            const els = Array.from(document.getElementsByClassName('live-wait'));
            const nowSec = Math.floor(Date.now() / 1000);
            for (const el of els) {
                const sentTs = parseInt(el.dataset.sentTs || '0', 10);
                if (!sentTs) continue;
                const diffSec = Math.max(0, nowSec - sentTs);
                if (waitLimitSec > 0) {
                    const itemEl = el.closest('.ko-item');
                    if (itemEl) itemEl.classList.toggle('ko-item-overdue', diffSec >= waitLimitSec);
                    const remaining = Math.max(0, waitLimitSec - diffSec);
                    const ratio = Math.max(0, Math.min(1, remaining / waitLimitSec));
                    const pct = Math.round(ratio * 100);
                    el.style.background = `linear-gradient(to right, rgba(38,165,228,0.18) 0%, rgba(38,165,228,0.18) ${pct}%, transparent ${pct}%, transparent 100%)`;
                    el.style.borderRadius = '6px';
                    el.style.padding = '1px 4px';
                }
                const mm = Math.floor(diffSec / 60);
                const ss = diffSec % 60;
                const out = String(mm).padStart(2, '0') + ':' + String(ss).padStart(2, '0');
                const t = el.querySelector('.live-time');
                if (t) t.textContent = out;
            }
        };

        let reqSeq = 0;
        let activeCtrl = null;
        const request = async (method, action, payload) => {
            reqSeq += 1;
            const mySeq = reqSeq;
            if (activeCtrl) {
                try { activeCtrl.abort(); } catch (_) {}
            }
            activeCtrl = (typeof AbortController !== 'undefined') ? new AbortController() : null;
            loading = true;
            isRefreshing = true;
            try {
                const params = new URLSearchParams();
                params.set('ajax', '1');
                params.set('action', action);
                params.set('station', stationEl.value);
                params.set('_ts', String(Date.now()));
                const headers = { 'X-Requested-With': 'XMLHttpRequest' };
                if (method === 'POST' && typeof payload === 'string') {
                    headers['Content-Type'] = 'application/json';
                }
                const init = {
                    method,
                    headers,
                    cache: 'no-store',
                    signal: activeCtrl ? activeCtrl.signal : undefined,
                    body: payload
                };
                const res = await fetch(`kitchen_online.php?${params.toString()}`, init);
                const data = await res.json();
                if (mySeq !== reqSeq) return null;
                return data;
            } catch (e) {
                if (e && e.name === 'AbortError') return null;
                return null;
            } finally {
                if (mySeq === reqSeq) {
                    loading = false;
                    isRefreshing = false;
                }
            }
        };

        const loadCards = async (action = 'list') => {
            const data = await request('GET', action, undefined);
            if (!data || !data.ok) return;
            cardsEl.innerHTML = data.html || '';
            emptyEl.style.display = (cardsEl.children.length === 0) ? 'block' : 'none';
            if (data.last_sync) lastSyncEl.textContent = data.last_sync;
            if (typeof data.wait_limit_minutes === 'number') waitLimitSec = data.wait_limit_minutes * 60;
            updateLive();
            detectNewItems();
        };

        const refreshVisible = async () => {
            const txIds = Array.from(cardsEl.querySelectorAll('.ko-card'))
                .map(el => parseInt(el.dataset.txId || '0', 10))
                .filter(n => n > 0);
            if (txIds.length === 0) {
                await loadCards('list');
                return;
            }
            const payload = new FormData();
            for (const id of txIds) payload.append('tx_ids[]', String(id));
            const data = await request('POST', 'refresh', payload);
            if (!data || !data.ok) return;
            cardsEl.innerHTML = data.html || '';
            emptyEl.style.display = (cardsEl.children.length === 0) ? 'block' : 'none';
            if (data.last_sync) lastSyncEl.textContent = data.last_sync;
            if (typeof data.wait_limit_minutes === 'number') waitLimitSec = data.wait_limit_minutes * 60;
            updateLive();
            detectNewItems();
        };

        stationEl.addEventListener('change', () => {
            refreshCycleStartedAt = Date.now();
            loadCards('list');
        });
        if (useLogicalCloseEl) {
            useLogicalCloseEl.addEventListener('change', async () => {
                await request('POST', 'set_logclose', JSON.stringify({ use: useLogicalCloseEl.checked ? 1 : 0 }));
                refreshCycleStartedAt = Date.now();
                loadCards('list');
            }, { passive: true });
        }
        loadMuted();
        renderSoundIcon();
        if (soundBtn) {
            soundBtn.addEventListener('click', async () => {
                soundMuted = !soundMuted;
                saveMuted();
                renderSoundIcon();
                if (!soundMuted) {
                    await ensureAudio();
                    beep();
                }
            });
        }

        cardsEl.addEventListener('wheel', (e) => {
            if (!cardsEl || cardsEl.scrollWidth <= cardsEl.clientWidth) return;
            if (e.shiftKey) return;
            const dx = Math.abs(e.deltaX || 0);
            const dy = Math.abs(e.deltaY || 0);
            if (dx > dy) return;
            e.preventDefault();
            cardsEl.scrollLeft += e.deltaY;
        }, { passive: false });

        cardsEl.addEventListener('click', async (e) => {
            const btn = e.target.closest('button.ko-ack');
            if (!btn) return;
            const itemId = parseInt(btn.dataset.itemId || '0', 10);
            if (!itemId) return;
            if (btn.disabled) return;
            btn.disabled = true;
            try {
                const payload = new FormData();
                payload.set('toggle_exclude_item', String(itemId));
                payload.set('exclude_from_dashboard', '1');
                const params = new URLSearchParams();
                params.set('ajax', '1');
                params.set('action', 'exclude');
                const res = await fetch(`kitchen_online.php?${params.toString()}`, { method: 'POST', body: payload, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                const data = await res.json();
                if (!data || !data.ok) throw new Error('bad');
                const itemEl = btn.closest('.ko-item');
                if (itemEl) itemEl.remove();
                const cardEl = btn.closest('.ko-card');
                if (cardEl && cardEl.querySelectorAll('.ko-item').length === 0) {
                    cardEl.remove();
                }
                emptyEl.style.display = (cardsEl.children.length === 0) ? 'block' : 'none';
            } catch (err) {
                btn.disabled = false;
            }
        });

        loadCards('list');
        setInterval(updateLive, 1000);
        if (refreshProgressEl) {
            try {
                refreshCircleLen = refreshProgressEl.getTotalLength();
                refreshProgressEl.style.strokeDasharray = String(refreshCircleLen);
                refreshProgressEl.style.strokeDashoffset = String(refreshCircleLen);
            } catch (_) {
                refreshCircleLen = 0;
            }
        }
        const renderRefreshCountdown = () => {
            const now = Date.now();
            const durMs = refreshIntervalSec * 1000;
            const elapsed = Math.max(0, Math.min(durMs, now - refreshCycleStartedAt));
            const remainingMs = Math.max(0, durMs - elapsed);
            const remainingSec = Math.max(0, Math.floor((Math.max(0, remainingMs) - 1) / 1000));
            if (refreshInEl) refreshInEl.textContent = isRefreshing ? '…' : String(remainingSec);
            if (refreshProgressEl && refreshCircleLen > 0) {
                const progress = isRefreshing ? 0 : (elapsed / durMs);
                refreshProgressEl.style.strokeDashoffset = String(refreshCircleLen * (1 - progress));
            }
        };
        renderRefreshCountdown();
        setInterval(renderRefreshCountdown, 100);
        setInterval(() => {
            refreshCycleStartedAt = Date.now();
            if (refreshProgressEl && refreshCircleLen > 0) {
                refreshProgressEl.style.strokeDashoffset = String(refreshCircleLen);
            }
            renderRefreshCountdown();
            refreshVisible();
        }, refreshIntervalSec * 1000);
