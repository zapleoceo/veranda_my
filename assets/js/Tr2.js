﻿(() => {
  const cfg = window.__TR_CONFIG__ || {};
  let UI_LANG = cfg.lang || 'ru';
  let UI_LOCALE = cfg.locale || (UI_LANG === 'vi' ? 'vi-VN' : (UI_LANG === 'en' ? 'en-US' : 'ru-RU'));
  let STR = cfg.str || {};
  const I18N_ALL = cfg.i18n_all || {};
  window.UI_LANG = UI_LANG;
  window.UI_LOCALE = UI_LOCALE;
  window.STR = STR;
  const t = (key) => (STR && Object.prototype.hasOwnProperty.call(STR, key)) ? STR[key] : String(key);
  const fmtVars = (str, vars) => String(str || '').replace(/\{(\w+)\}/g, (_, k) => (vars && vars[k] != null) ? String(vars[k]) : '');
  const setLangCookie = (l) => { try { document.cookie = 'links_lang=' + encodeURIComponent(l) + '; path=/; samesite=lax; max-age=' + (365*24*3600); } catch (_) {} };
  const applyI18n = () => {
    document.documentElement.lang = UI_LANG;
    document.title = t('page_title');
    document.querySelectorAll('[data-i18n]').forEach((el) => {
      if (!(el instanceof HTMLElement)) return;
      const key = String(el.getAttribute('data-i18n') || '').trim();
      if (!key) return;
      el.textContent = t(key);
    });
    const reqComment = document.getElementById('reqComment');
    if (reqComment) reqComment.setAttribute('placeholder', t('comment_placeholder'));
    const resDateBtn = document.getElementById('resDateBtn');
    const resDate = document.getElementById('resDate');
    if (resDateBtn) {
      if (resDate && String(resDate.value || '').trim() && typeof fmtCashDate === 'function') resDateBtn.textContent = fmtCashDate(resDate.value);
      else resDateBtn.textContent = t('pick_date');
    }
  };
  const switchLang = (l) => {
    const supported = ['ru','en','vi'];
    if (!supported.includes(l)) return;
    setLangCookie(l);
    UI_LANG = l;
    UI_LOCALE = (l === 'ru') ? 'ru-RU' : (l === 'vi' ? 'vi-VN' : 'en-US');
    STR = I18N_ALL[l] || {};
    window.UI_LANG = UI_LANG;
    window.UI_LOCALE = UI_LOCALE;
    window.STR = STR;
    try {
      if (typeof preorderMenu !== 'undefined') preorderMenu = null;
      if (typeof preorderMenuLoading !== 'undefined') preorderMenuLoading = false;
      if (typeof preorderBody !== 'undefined' && preorderBody) preorderBody.innerHTML = '';
    } catch (_) {}
    applyI18n();
    if (typeof setStatus === 'function') setStatus(selectedTableNum);
    if (typeof renderSelectedTable === 'function') renderSelectedTable();
    if (typeof updatePreorderUi === 'function') updatePreorderUi();
    if (typeof renderPreorderBox === 'function') renderPreorderBox();
  };
  (() => {
    const langEl = document.querySelector('.lang');
    if (!langEl) return;
    langEl.addEventListener('click', (e) => {
      const a = e.target && e.target.closest ? e.target.closest('a') : null;
      if (!a) return;
      e.preventDefault();
      const txt = String(a.textContent || '').trim().toLowerCase();
      const map = { ru: 'ru', en: 'en', vi: 'vi' };
      const l = map[txt] || (txt.includes('ru') ? 'ru' : (txt.includes('en') ? 'en' : (txt.includes('vi') ? 'vi' : null)));
      if (l) switchLang(l);
      Array.from(langEl.querySelectorAll('a')).forEach((x) => x.classList.remove('active'));
      a.classList.add('active');
    });
  })();
  const root = document.documentElement;
    const mapShell = document.querySelector('.map-shell');
    const tileLayer = mapShell ? mapShell.querySelector(':scope > .tile-layer') : null;
    const mapZoomVal = document.getElementById('mapZoomVal');
    const mapZoomMinus = document.getElementById('mapZoomMinus');
    const mapZoomPlus = document.getElementById('mapZoomPlus');
    const mapZoomRange = document.getElementById('mapZoomRange');
    const mapZoomBox = document.getElementById('mapZoomBox');
    const syncTileLayerSize = () => {
      if (!mapShell || !tileLayer) return;
      tileLayer.style.width = String(Math.max(mapShell.scrollWidth, mapShell.clientWidth)) + 'px';
      tileLayer.style.height = String(Math.max(mapShell.scrollHeight, mapShell.clientHeight)) + 'px';
    };

    const applyMapZoom = (pct, keepAnchor) => {
      if (!mapShell) return;
      const raw = Math.round(Number(pct || 100) || 100);
      let p = Math.max(10, Math.min(100, raw));
      if (raw === 93) p = 100;
      const scale = p / 100;
      if (mapZoomVal) mapZoomVal.textContent = String(Math.round(p)) + '%';
      if (mapZoomRange) mapZoomRange.value = String(Math.round(p));

      let ax = 0, ay = 0;
      if (keepAnchor) {
        const old = Number(getComputedStyle(mapShell).getPropertyValue('--map-scale')) || 1;
        const rect = mapShell.getBoundingClientRect();
        ax = (mapShell.scrollLeft + rect.width / 2) / old;
        ay = (mapShell.scrollTop + rect.height / 2) / old;
      }

      mapShell.style.setProperty('--map-scale', String(scale));
      mapShell.style.setProperty('--inv-map-scale', String(1 / scale));
      syncTileLayerSize();

      if (keepAnchor) {
        const rect = mapShell.getBoundingClientRect();
        mapShell.scrollLeft = Math.max(0, ax * scale - rect.width / 2);
        mapShell.scrollTop = Math.max(0, ay * scale - rect.height / 2);
      }
    };

    const getInitialZoomPct = () => {
      if (!mapShell) return 100;
      if (!window.matchMedia || !window.matchMedia('(max-width: 640px)').matches) return 100;
      const pad = 32;
      const baseW = mapZoomBox ? (mapZoomBox.offsetWidth || 820) : 820;
      const fit = Math.floor(((mapShell.clientWidth || baseW) - pad) / baseW * 100);
      return Math.max(10, Math.min(100, fit));
    };
    applyMapZoom(getInitialZoomPct(), false);
    if (typeof window.addEventListener === 'function') {
      window.addEventListener('resize', () => { syncTileLayerSize(); });
    }
    const getCurrentZoomPct = () => {
      if (!mapShell) return 100;
      const cur = Number(getComputedStyle(mapShell).getPropertyValue('--map-scale')) || 1;
      return Math.round(cur * 100);
    };
    if (mapZoomMinus) mapZoomMinus.addEventListener('click', () => applyMapZoom(getCurrentZoomPct() - 5, true));
    if (mapZoomPlus) mapZoomPlus.addEventListener('click', () => applyMapZoom(Math.min(100, getCurrentZoomPct() + 5), true));
    if (mapZoomRange) mapZoomRange.addEventListener('input', () => applyMapZoom(mapZoomRange.value, true));
    const defaultResDateLocal = String(cfg.defaultResDateLocal || "");
    const allowedTableNums = cfg.allowedTableNums ?? null;
    const tableCapsByNum = cfg.tableCapsByNum ?? null;
    const allowedSet = Array.isArray(allowedTableNums) ? new Set(allowedTableNums.map((x) => String(x))) : null;

    const tables = Array.from(document.querySelectorAll('.table'));
    const shiftTablesUp = (px) => {
      tables.forEach((t) => {
        const n = String(t.dataset.table || '');
        const num = Number(n);
        if (!isFinite(num) || num < 1 || num > 20) return;
        const topStr = String(t.style.top || '').trim();
        const m = topStr.match(/^(-?\d+(?:\.\d+)?)px$/);
        if (!m) return;
        const cur = Number(m[1]);
        if (!isFinite(cur)) return;
        t.style.top = String(cur - px) + 'px';
      });
    };
    const shiftTablesRight = (fromNum, toNum, px) => {
      tables.forEach((t) => {
        const n = String(t.dataset.table || '');
        const num = Number(n);
        if (!isFinite(num) || num < fromNum || num > toNum) return;
        const leftStr = String(t.style.left || '').trim();
        const m = leftStr.match(/^(-?\d+(?:\.\d+)?)px$/);
        if (!m) return;
        const cur = Number(m[1]);
        if (!isFinite(cur)) return;
        t.style.left = String(cur + px) + 'px';
      });
    };
    shiftTablesUp(56);
    shiftTablesRight(15, 19, 28);
    if (allowedSet !== null && allowedSet.size > 0) {
      tables.forEach((t) => {
        const n = String(t.dataset.table || '');
        if (!allowedSet.has(n)) {
          t.classList.add('disabled');
        }
      });
    }

    tables.forEach((t) => {
      const n = String(t.dataset.table || '');
      const capEl = t.querySelector('.cap');
      const cap = tableCapsByNum && typeof tableCapsByNum === 'object' && tableCapsByNum[n] != null ? Number(tableCapsByNum[n]) : null;
      if (capEl) capEl.textContent = (cap != null && isFinite(cap)) ? (String(Math.max(0, Math.floor(cap))) + ' 👤') : '';
    });

    const setBusyLabel = (dateStr) => {
      const busyDateLabel = document.getElementById('busyDateLabel');
      if (busyDateLabel) busyDateLabel.textContent = t('data_on');
    };
    const setBusyLoader = (isOn) => {
      const busyDateLoader = document.getElementById('busyDateLoader');
      if (!busyDateLoader) return;
      busyDateLoader.hidden = !isOn;
      busyDateLoader.style.display = isOn ? 'inline-block' : 'none';
      const busyProgress = document.getElementById('busyProgress');
      if (busyProgress) {
        busyProgress.hidden = !isOn;
        busyProgress.style.display = isOn ? 'block' : 'none';
      }
    };

    const clearReservationsOnTables = () => {
      tables.forEach((t) => {
        const el = t.querySelector('.res-time');
        if (el) el.remove();
      });
    };

    let lastReservationsByTable = {};
    const applyReservationsItemsToTables = (items, dateStr, dtValue) => {
      const list = Array.isArray(items) ? items : [];
      const day = String(dateStr || '').slice(0, 10);
      if (!day) {
        tables.forEach((t) => { delete t.dataset.resBusy; });
        return;
      }

      const dt = String(dtValue || '').trim();
      const selMin = (() => {
        const m = dt.match(/^\d{4}-\d{2}-\d{2}[ T](\d{2}):(\d{2})/);
        if (!m) return null;
        const hh = Number(m[1]);
        const mm = Number(m[2]);
        if (!isFinite(hh) || !isFinite(mm)) return null;
        return (hh * 60) + mm;
      })();
      const today = new Date();
      const todayStr = today.getFullYear() + '-' + String(today.getMonth() + 1).padStart(2, '0') + '-' + String(today.getDate()).padStart(2, '0');
      const isToday = day === todayStr;
      const nowMin = isToday ? (today.getHours() * 60 + today.getMinutes()) : null;
      const durMin = 120;

      const byTable = {};
      list.forEach((it) => {
        if (!it || typeof it !== 'object') return;
        const tableTitle = String(it.table_title ?? '').trim();
        const s = String(it.date_start ?? '').trim();
        const e = String(it.date_end ?? '').trim();
        if (!tableTitle || !s || !e) return;
        if (s.slice(0, 10) !== day) return;
        const sm = Number(s.slice(11, 13)) * 60 + Number(s.slice(14, 16));
        const em = Number(e.slice(11, 13)) * 60 + Number(e.slice(14, 16));
        if (!isFinite(sm) || !isFinite(em)) return;
        if (!byTable[tableTitle]) byTable[tableTitle] = [];
        byTable[tableTitle].push([sm, em]);
      });

      Object.keys(byTable).forEach((k) => {
        const arr = byTable[k].slice().sort((a, b) => a[0] - b[0]);
        const merged = [];
        arr.forEach(([s, e]) => {
          if (!merged.length) { merged.push([s, e]); return; }
          const last = merged[merged.length - 1];
          if (s <= last[1]) last[1] = Math.max(last[1], e);
          else merged.push([s, e]);
        });
        byTable[k] = merged;
      });

      const pad2 = (x) => String(x).padStart(2, '0');
      const fmt = (m) => pad2(Math.floor(m / 60)) + ':' + pad2(m % 60);

      lastReservationsByTable = byTable;

      tables.forEach((tableEl) => {
        const n = String(tableEl.dataset.table || '');
        let ranges = Array.isArray(byTable[n]) ? byTable[n] : [];
        if (isToday && nowMin != null) {
          ranges = ranges.filter(([s, e]) => e > nowMin);
        }
        const selEnd = selMin != null ? (selMin + durMin) : null;
        const overlapsSel = (selMin != null && selEnd != null)
          ? ranges.some(([s, e]) => s < selEnd && e > selMin)
          : false;
        if (overlapsSel) tableEl.dataset.resBusy = '1';
        else delete tableEl.dataset.resBusy;
        let txt = ranges.length ? ranges.slice(0, 2).map(([s, e]) => fmt(s) + '-' + fmt(e)).join(' · ') : '';
        if (isToday && selMin != null && !overlapsSel && !ranges.length && last && !freeNums.has(n)) {
          txt = t('busy_now');
        }
        let el = tableEl.querySelector('.res-time');
        if (!txt) {
          if (el) el.remove();
          return;
        }
        if (!el) {
          el = document.createElement('div');
          el.className = 'res-time';
        }
        el.textContent = txt;
        if (!tableEl.contains(el)) tableEl.appendChild(el);
      });
    };
    const resDate = document.getElementById('resDate');
    const resDateBtn = document.getElementById('resDateBtn');
    const resultText = document.getElementById('resultText');
    const selectedTableEl = document.getElementById('selectedTable');
    const statusLine = document.getElementById('statusLine');
    const stepGuests = document.getElementById('stepGuests');
    const stepCheck = document.getElementById('stepCheck');
    const capModal = document.getElementById('capModal');
    const capModalText = document.getElementById('capModalText');
    const capModalYes = document.getElementById('capModalYes');
    const capModalNo = document.getElementById('capModalNo');
    const reqModal = document.getElementById('reqModal');
    const reqModalCard = document.getElementById('reqModalCard');
    const reqForm = document.getElementById('reqForm');
    const reqLeft = document.getElementById('reqLeft');
    const reqName = document.getElementById('reqName');
    const reqPhone = document.getElementById('reqPhone');
    const reqComment = document.getElementById('reqComment');
    const reqCommentLabel = document.getElementById('reqCommentLabel');
    const reqPreorderLabel = document.getElementById('reqPreorderLabel');
    const reqPreorderBox = document.getElementById('reqPreorderBox');
    const reqModalTable = document.getElementById('reqModalTable');
    const reqGuests = document.getElementById('reqGuests');
    const reqGuestsMinus = document.getElementById('reqGuestsMinus');
    const reqGuestsPlus = document.getElementById('reqGuestsPlus');
    const reqStart = document.getElementById('reqStart');
    const reqHint = document.getElementById('reqHint');
    const preorderReqHint = document.getElementById('preorderReqHint');
    const preorderPanel = document.getElementById('preorderPanel');
    const preorderBody = document.getElementById('preorderBody');
    const reqSubmit = document.getElementById('reqSubmit');
    const msgrTgBtn = document.getElementById('msgrTgBtn');
    const msgrHint = document.getElementById('msgrHint');
    const toastEl = document.getElementById('tableToast');
    const toastTitleEl = document.getElementById('toastTitle');
    const dtpModal = document.getElementById('dtpModal');
    const dtpDateList = document.getElementById('dtpDateList');
    const dtpTimeList = document.getElementById('dtpTimeList');
    const dtpOk = document.getElementById('dtpOk');

    let last = null;
    let freeNums = new Set();
    let lastKey = '';
    let selectedTableNum = '';
    let isLoading = false;
    let capConfirmResolve = null;
    let toastTimer = null;
    let toastHideTimer = null;
    let reqGuestsHintTimer = null;
    let dtpDates = [];
    let dtpTimes = [];
    let dtpSelDate = null;
    let dtpSelTime = null;
    let skipNextResDateAutoLoad = false;

    const pad2 = (n) => String(n).padStart(2, '0');
    const isoDate = (d) => d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate());
    const timeToMin = (hhmm) => {
      const m = String(hhmm || '').match(/^(\d{2}):(\d{2})$/);
      if (!m) return 0;
      return (Number(m[1]) * 60) + Number(m[2]);
    };

    const getMinSelectableSlot = () => {
      const now = new Date();
      const base = new Date(now.getFullYear(), now.getMonth(), now.getDate(), now.getHours(), now.getMinutes(), 0, 0);
      const m = base.getMinutes();
      const add = (30 - (m % 30)) % 30;
      base.setMinutes(m + add, 0, 0);
      return { dateVal: isoDate(base), timeVal: pad2(base.getHours()) + ':' + pad2(base.getMinutes()) };
    };

    const clampToMinSlot = (dateVal, timeVal) => {
      const minSlot = getMinSelectableSlot();
      if (!dateVal) return minSlot;
      if (dateVal < minSlot.dateVal) return minSlot;
      if (dateVal === minSlot.dateVal && timeToMin(timeVal) < timeToMin(minSlot.timeVal)) return minSlot;
      return { dateVal, timeVal };
    };

    const fmtCashDate = (dtLocal) => {
      const raw = String(dtLocal || '').trim();
      const m = raw.match(/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/);
      if (!m) return t('pick_date');
      const d = new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]), Number(m[4]), Number(m[5]), 0);
      const datePart = new Intl.DateTimeFormat(UI_LOCALE, { weekday: 'short', day: '2-digit', month: 'short' }).format(d);
      return datePart + ' · ' + pad2(d.getHours()) + ':' + pad2(d.getMinutes());
    };

    const setDtpModal = (on) => {
      if (!dtpModal) return;
      if (on) {
        dtpModal.classList.add('on');
        dtpModal.setAttribute('aria-hidden', 'false');
      } else {
        dtpModal.classList.remove('on');
        dtpModal.setAttribute('aria-hidden', 'true');
      }
    };

    const wheelIndex = (el, count) => {
      if (!el) return 0;
      const idx = Math.round(el.scrollTop / 40);
      return Math.max(0, Math.min((count || 1) - 1, idx));
    };

    const updateWheelActive = (listEl, idx) => {
      if (!listEl) return;
      Array.from(listEl.children).forEach((c, i) => {
        if (!(c instanceof HTMLElement)) return;
        if (i === idx) c.classList.add('active');
        else c.classList.remove('active');
      });
    };

    const setWheelTo = (listEl, idx) => {
      if (!listEl) return;
      listEl.scrollTop = Math.max(0, idx) * 40;
      updateWheelActive(listEl, idx);
    };

    const ensureDtpData = () => {
      if (!dtpDateList) return;
      if (!dtpDates.length) {
        const now = new Date();
        const base = new Date(now.getFullYear(), now.getMonth(), now.getDate(), 0, 0, 0);
        const days = 28;
        dtpDates = [];
        for (let i = 0; i < days; i++) {
          const d = new Date(base.getTime() + (i * 86400000));
          dtpDates.push({ value: isoDate(d), date: d });
        }
        dtpDateList.innerHTML = '';
        dtpDates.forEach(({ value, date }) => {
          const label = new Intl.DateTimeFormat(UI_LOCALE, { weekday: 'short', day: '2-digit', month: 'short' }).format(date);
          const it = document.createElement('div');
          it.className = 'wheel-item';
          it.dataset.value = value;
          it.textContent = label;
          dtpDateList.appendChild(it);
        });
      }
    };

    const syncDtpSelectionFromInput = () => {
      ensureDtpData();
      const raw = resDate ? String(resDate.value || '').trim() : '';
      const m = raw.match(/^(\d{4}-\d{2}-\d{2})/);
      const fallback = getMinSelectableSlot();
      const pickedDate = m ? m[1] : fallback.dateVal;
      dtpSelDate = pickedDate;
      const dIdx = Math.max(0, dtpDates.findIndex((x) => x.value === pickedDate));
      setWheelTo(dtpDateList, dIdx);
    };

    const applyDtpToInput = () => {
      if (!resDate) return;
      const fallback = getMinSelectableSlot();
      const pickedDate = dtpSelDate || fallback.dateVal;
      resDate.value = pickedDate;
      if (resDateBtn) resDateBtn.textContent = fmtCashDate(resDate.value);
      resDate.dispatchEvent(new Event('change', { bubbles: true }));
    };



    if (dtpDateList) {
      let t = null;
      dtpDateList.addEventListener('scroll', () => {
        if (t) clearTimeout(t);
        t = setTimeout(() => {
          const idx = wheelIndex(dtpDateList, dtpDates.length);
          updateWheelActive(dtpDateList, idx);
          dtpSelDate = dtpDates[idx] ? dtpDates[idx].value : dtpSelDate;
        }, 80);
      });
    }
    if (dtpOk) dtpOk.addEventListener('click', () => {
      skipNextResDateAutoLoad = true;
      applyDtpToInput();
      setDtpModal(false);
      loadFree(false).catch((e) => setOutput(t('err_prefix') + String(e && e.message ? e.message : e)));
    });
    document.querySelectorAll('[data-dtp-close]').forEach((x) => x.addEventListener('click', () => setDtpModal(false)));
    if (resDateBtn) {
      resDateBtn.addEventListener('click', () => {
        syncDtpSelectionFromInput();
        setDtpModal(true);
      });
    }

    const setModal = (el, on) => {
      if (!el) return;
      if (on) {
        el.classList.add('on');
        el.setAttribute('aria-hidden', 'false');
        if (el.id === 'reqModal' || el.id === 'mobilePreorderModal') {
          history.pushState({ modal: el.id }, '');
        }
      } else {
        el.classList.remove('on');
        el.setAttribute('aria-hidden', 'true');
      }
    };

    const DRAFT_KEY = 'tr_booking_draft_v1';
    try { localStorage.removeItem(DRAFT_KEY); } catch (_) {}
    const loadDraft = () => {
      try {
        const raw = localStorage.getItem(DRAFT_KEY);
        if (!raw) return null;
        const d = JSON.parse(raw);
        if (!d || typeof d !== 'object') return null;
        const ts = Number(d.ts || 0) || 0;
        if (ts && (Date.now() - ts) > (7 * 86400 * 1000)) return null;
        return d;
      } catch (_) {
        return null;
      }
    };
    const saveDraft = () => {
      try {
        if (!reqName || !reqPhone || !reqComment || !reqGuests) return;
        const d = {
          ts: Date.now(),
          name: String(reqName.value || ''),
          phone: String(reqPhone.value || ''),
          comment: String(reqComment.value || ''),
          guests: String(reqGuests.value || ''),
          preorder: (typeof preorderCounts === 'object' && preorderCounts) ? preorderCounts : {},
        };
        localStorage.setItem(DRAFT_KEY, JSON.stringify(d));
      } catch (_) {
      }
    };

    document.querySelectorAll('[data-modal-close]').forEach((x) => {
      x.addEventListener('click', () => {
        const id = String(x.getAttribute('data-modal-close') || '');
        if (!id) return;
        const el = document.getElementById(id);
        if (id === 'reqModal' || id === 'mobilePreorderModal') {
            if (id === 'reqModal') saveDraft();
            setModal(el, false);
            if (history.state && history.state.modal === id) history.back();
        } else {
            setModal(el, false);
        }
        
        if (id === 'capModal' && typeof capConfirmResolve === 'function') {
          capConfirmResolve(false);
          capConfirmResolve = null;
        }
      });
    });

    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') {
        const modals = [mobilePreorderModal, reqModal, capModal, dtpModal];
        for (const m of modals) {
          if (m && m.classList.contains('on')) {
            if (m.id === 'reqModal' || m.id === 'mobilePreorderModal') {
                if (m.id === 'reqModal') saveDraft();
                setModal(m, false);
                if (history.state && history.state.modal === m.id) history.back();
            } else {
                setModal(m, false);
            }
            if (m.id === 'capModal' && typeof capConfirmResolve === 'function') {
              capConfirmResolve(false);
              capConfirmResolve = null;
            }
            break;
          }
        }
      }
    });

    window.addEventListener('popstate', (e) => {
      if (mobilePreorderModal && mobilePreorderModal.classList.contains('on') && (!e.state || e.state.modal !== 'mobilePreorderModal')) {
        setModal(mobilePreorderModal, false);
        return;
      }
      if (reqModal && reqModal.classList.contains('on') && (!e.state || e.state.modal !== 'reqModal')) {
        saveDraft();
        reqModal.classList.remove('on');
        reqModal.setAttribute('aria-hidden', 'true');
      }
    });

    const confirmCapacity = (maxCap, guests) => new Promise((resolve) => {
      capConfirmResolve = resolve;
      if (capModalText) capModalText.textContent = fmtVars(t('confirm_capacity'), { max: maxCap, guests });
      setModal(capModal, true);
    });

    if (capModalYes) {
      capModalYes.addEventListener('click', () => {
        setModal(capModal, false);
        if (typeof capConfirmResolve === 'function') capConfirmResolve(true);
        capConfirmResolve = null;
      });
    }
    if (capModalNo) {
      capModalNo.addEventListener('click', () => {
        setModal(capModal, false);
        if (typeof capConfirmResolve === 'function') capConfirmResolve(false);
        capConfirmResolve = null;
      });
    }

    let pendingBooking = null;
    let messengerLinked = { telegram: false, whatsapp: false, zalo: false };
    let linkedTg = null;
    let submitBusy = false;
    let submitPrevText = '';
    let preorderMenu = null;
    let preorderMenuLoading = false;
    const setPreorderOpen = (on) => {
      if (!preorderPanel) return;
      if (on) {
        if (preorderPanel.hidden) preorderPanel.hidden = false;
        preorderPanel.classList.add('open');
        if (preorderBody && preorderBody.childElementCount === 0) preorderBody.textContent = t('menu_loading');
      } else {
        preorderPanel.classList.remove('open');
        setTimeout(() => {
          if (!preorderPanel.classList.contains('open')) preorderPanel.hidden = true;
        }, 190);
      }
    };
    const btnOpenMobilePreorder = document.getElementById('btnOpenMobilePreorder');
    const mobilePreorderModal = document.getElementById('mobilePreorderModal');
    const mobilePreorderBox = document.getElementById('mobilePreorderBox');
    const mobilePreorderMenuBody = document.getElementById('mobilePreorderMenuBody');
    const mobilePreorderTotal = document.getElementById('mobilePreorderTotal');

    const updatePreorderUi = () => {
      const guests = reqGuests ? (Number(reqGuests.value || 0) || 0) : 0;
      const on = guests > 5;
      const isMobile = !!(window.matchMedia && window.matchMedia('(max-width: 640px)').matches);
      if (preorderReqHint) preorderReqHint.hidden = !on;
      if (reqModalCard) reqModalCard.classList.toggle('wide', on && !isMobile);
      if (reqPreorderLabel) {
        reqPreorderLabel.hidden = !on;
        reqPreorderLabel.style.display = on ? '' : 'none';
        if (on && !isMobile) reqPreorderLabel.classList.remove('full');
        else reqPreorderLabel.classList.add('full');
      }
      if (reqCommentLabel) {
        if (on && !isMobile) reqCommentLabel.classList.remove('full');
        else reqCommentLabel.classList.add('full');
      }
      if (btnOpenMobilePreorder) {
        btnOpenMobilePreorder.hidden = !on || !isMobile;
      }
      setPreorderOpen(!isMobile && on);
      if (!on) {
        return;
      }
      loadPreorderMenu().catch(() => null);
    };

    let preorderCounts = {};
    const getPreorderUiTitle = (key) => {
      const k = String(key || '').trim();
      if (!k) return '';
      const map = window.preorderUiTitleByKey && typeof window.preorderUiTitleByKey === 'object' ? window.preorderUiTitleByKey : {};
      const v = map[k];
      const vv = v != null ? String(v).trim() : '';
      return vv || k;
    };
    const normalizePreorder = (obj) => {
      const out = {};
      if (!obj || typeof obj !== 'object') return out;
      Object.keys(obj).forEach((k) => {
        const title = String(k || '').trim();
        if (!title) return;
        const n = Number(obj[k] || 0) || 0;
        if (n <= 0) return;
        out[title] = Math.min(99, Math.floor(n));
      });
      return out;
    };
    const parsePreorderTextToCounts = (text) => {
      const out = {};
      String(text || '').split('\n').forEach((raw) => {
        const line = String(raw || '').trim();
        if (!line) return;
        const m = line.match(/^\-\s*(.+?)(?:\s*x\s*(\d+))?\s*$/i);
        const title = (m && m[1]) ? String(m[1]).trim() : line.replace(/^[\-\*\u2022]\s*/, '').trim();
        if (!title) return;
        const qty = (m && m[2]) ? (Number(m[2]) || 1) : 1;
        out[title] = (Number(out[title] || 0) || 0) + Math.max(1, qty);
      });
      return normalizePreorder(out);
    };
    const renderPreorderBox = () => {
      if (!reqPreorderBox) return;
      const counts = normalizePreorder(preorderCounts);
      const keys = Object.keys(counts).sort((a, b) => a.localeCompare(b, UI_LOCALE, { sensitivity: 'base' }));
      reqPreorderBox.innerHTML = '';
      if (mobilePreorderBox) mobilePreorderBox.innerHTML = '';

      const totalPrice = keys.reduce((acc, key) => {
        const price = (window.preorderPriceByKey && window.preorderPriceByKey[key]) ? Number(window.preorderPriceByKey[key]) : 0;
        return acc + (price * (Number(counts[key] || 0) || 0));
      }, 0);
      if (mobilePreorderTotal) mobilePreorderTotal.textContent = keys.length ? fmtPrice(totalPrice) : '';
      
      const renderToTarget = (targetBox, isMobile) => {
        if (!targetBox) return;
        if (!keys.length) {
          const empty = document.createElement('div');
          empty.className = 'preorder-empty';
          empty.textContent = '—';
          targetBox.appendChild(empty);
          return;
        }
        keys.forEach((key) => {
          const row = document.createElement('div');
          row.className = 'preorder-line';
          row.setAttribute('data-preorder-title', key);
          const left = document.createElement('div');
          const tEl = document.createElement('div');
          tEl.className = 'preorder-title';
          tEl.textContent = getPreorderUiTitle(key);
          left.appendChild(tEl);
          const qty = document.createElement('div');
          qty.className = 'preorder-qty';
          qty.textContent = 'x' + String(counts[key]);
          const minus = document.createElement('button');
          minus.type = 'button';
          minus.className = 'preorder-minus';
          minus.setAttribute('data-preorder-minus', key);
          minus.textContent = '−';
          row.appendChild(left);
          row.appendChild(qty);
          row.appendChild(minus);
          targetBox.appendChild(row);
        });
        if (!isMobile) {
          const totalEl = document.createElement('div');
          totalEl.className = 'preorder-total';
          totalEl.textContent = fmtPrice(totalPrice);
          targetBox.appendChild(totalEl);
        }
      };

      renderToTarget(reqPreorderBox, false);
      renderToTarget(mobilePreorderBox, true);
    };
    const incPreorder = (key, uiTitle) => {
      const t0 = String(key || '').trim();
      if (!t0) return;
      const uiT = String(uiTitle || '').trim();
      if (uiT) window.preorderUiTitleByKey = Object.assign(window.preorderUiTitleByKey || {}, { [t0]: uiT });
      preorderCounts = normalizePreorder(preorderCounts);
      preorderCounts[t0] = Math.min(99, (Number(preorderCounts[t0] || 0) || 0) + 1);
      renderPreorderBox();
      saveDraft();
    };
    const decPreorder = (title) => {
      const t0 = String(title || '').trim();
      if (!t0) return;
      preorderCounts = normalizePreorder(preorderCounts);
      const next = (Number(preorderCounts[t0] || 0) || 0) - 1;
      if (next <= 0) delete preorderCounts[t0];
      else preorderCounts[t0] = next;
      renderPreorderBox();
      saveDraft();
    };
    const getPreorderText = (mode) => {
      const counts = normalizePreorder(preorderCounts);
      const keys = Object.keys(counts).sort((a, b) => a.localeCompare(b, UI_LOCALE, { sensitivity: 'base' }));
      const m = String(mode || 'ui');
      return keys.map((k) => {
        const title = (m === 'ru') ? k : getPreorderUiTitle(k);
        return '- ' + title + (counts[k] > 1 ? (' x' + String(counts[k])) : '');
      }).join('\n');
    };

    const fmtPrice = (n) => {
      const v = Number(n);
      if (!isFinite(v)) return '';
      return new Intl.NumberFormat(UI_LOCALE).format(Math.round(v)) + ' ₫';
    };
    const renderPreorderMenu = () => {
      if (!preorderBody && !mobilePreorderMenuBody) return;
      const groups = preorderMenu && typeof preorderMenu === 'object' && Array.isArray(preorderMenu.groups) ? preorderMenu.groups : [];
      if (!groups.length) {
        if (preorderBody) preorderBody.textContent = t('menu_unavailable');
        if (mobilePreorderMenuBody) mobilePreorderMenuBody.textContent = t('menu_unavailable');
        return;
      }
      
      const createMenuDOM = () => {
        const container = document.createElement('div');
        groups.forEach((g) => {
          const details = document.createElement('details');
          details.className = 'pre-group';
          details.open = false;
          const sum = document.createElement('summary');
          const tEl = document.createElement('span');
          tEl.textContent = String(g.title || '');
          const cnt = document.createElement('span');
          cnt.className = 'cnt';
          const itemsCount = (Array.isArray(g.categories) ? g.categories : []).reduce((acc, c) => acc + (Array.isArray(c.items) ? c.items.length : 0), 0);
          cnt.textContent = String(itemsCount);
          sum.appendChild(tEl);
          sum.appendChild(cnt);
          details.appendChild(sum);
          const cats = Array.isArray(g.categories) ? g.categories : [];
          cats.forEach((c) => {
            const catDetails = document.createElement('details');
            catDetails.className = 'pre-group';
            catDetails.open = false;
            const catSum = document.createElement('summary');
            const catTitle = document.createElement('span');
            catTitle.textContent = String(c.title || '');
            const catCnt = document.createElement('span');
            catCnt.className = 'cnt';
            const items = Array.isArray(c.items) ? c.items : [];
            catCnt.textContent = String(items.length);
            catSum.appendChild(catTitle);
            catSum.appendChild(catCnt);
            catDetails.appendChild(catSum);
            items.forEach((it) => {
              const uiTitle = String(it && it.title != null ? it.title : '').trim();
              const ruTitle = String(it && it.ru_title != null ? it.ru_title : '').trim() || uiTitle;
              if (ruTitle) {
                window.preorderPriceByKey = Object.assign(window.preorderPriceByKey || {}, { [ruTitle]: (it && it.price != null ? Number(it.price) : 0) || 0 });
                if (uiTitle) window.preorderUiTitleByKey = Object.assign(window.preorderUiTitleByKey || {}, { [ruTitle]: uiTitle });
              }
              const row = document.createElement('div');
              row.className = 'pre-item';
              row.setAttribute('data-pre-item', '1');
              row.setAttribute('data-title', uiTitle);
              row.setAttribute('data-ru-title', ruTitle);
              const left = document.createElement('div');
              const tt = document.createElement('div');
              tt.className = 't';
              tt.textContent = uiTitle;
              left.appendChild(tt);
              const desc = String(it.description || '').trim();
              if (desc) {
                const dd = document.createElement('div');
                dd.className = 'd';
                dd.textContent = desc;
                left.appendChild(dd);
              }
              const price = document.createElement('div');
              price.className = 'p';
              price.textContent = it.price != null ? fmtPrice(it.price) : '';
              row.appendChild(left);
              row.appendChild(price);
              catDetails.appendChild(row);
            });
            details.appendChild(catDetails);
          });
          container.appendChild(details);
        });
        return container;
      };

      if (preorderBody) {
        preorderBody.innerHTML = '';
        preorderBody.appendChild(createMenuDOM());
      }
      if (mobilePreorderMenuBody) {
        mobilePreorderMenuBody.innerHTML = '';
        mobilePreorderMenuBody.appendChild(createMenuDOM());
      }
    };
    const loadPreorderMenu = async () => {
      if (preorderMenuLoading || preorderMenu) return;
      if (!preorderBody) return;
      preorderMenuLoading = true;
      try {
        const url = new URL(location.href);
        url.searchParams.set('ajax', 'menu_preorder');
        url.searchParams.set('lang', UI_LANG);
        const timeoutMs = 12000;
        let res;
        if (typeof AbortController === 'function') {
          const ctrl = new AbortController();
          const tm = setTimeout(() => ctrl.abort(), timeoutMs);
          try {
            res = await fetch(url.toString(), { headers: { 'Accept': 'application/json' }, signal: ctrl.signal });
          } finally {
            clearTimeout(tm);
          }
        } else {
          res = await fetch(url.toString(), { headers: { 'Accept': 'application/json' } });
        }
        const j = await res.json().catch(() => null);
        if (!res.ok || !j || !j.ok) throw new Error('bad');
        preorderMenu = j;
        renderPreorderMenu();
      } catch (_) {
        if (preorderBody) preorderBody.textContent = t('menu_load_failed');
      } finally {
        preorderMenuLoading = false;
      }
    };

    if (preorderBody) {
      preorderBody.addEventListener('click', (e) => {
        const target = e.target && e.target.closest ? e.target.closest('[data-pre-item]') : null;
        if (!target) return;
        const ruTitle = String(target.getAttribute('data-ru-title') || '').trim();
        const uiTitle = String(target.getAttribute('data-title') || '').trim();
        if (!ruTitle) return;
        incPreorder(ruTitle, uiTitle);
      });
    }
    if (mobilePreorderMenuBody) {
      mobilePreorderMenuBody.addEventListener('click', (e) => {
        const target = e.target && e.target.closest ? e.target.closest('[data-pre-item]') : null;
        if (!target) return;
        const ruTitle = String(target.getAttribute('data-ru-title') || '').trim();
        const uiTitle = String(target.getAttribute('data-title') || '').trim();
        if (!ruTitle) return;
        incPreorder(ruTitle, uiTitle);
      });
    }

    if (btnOpenMobilePreorder) {
      btnOpenMobilePreorder.addEventListener('click', () => {
        setModal(mobilePreorderModal, true);
      });
    }

    if (mobilePreorderBox) {
      mobilePreorderBox.addEventListener('click', (e) => {
        const btn = e.target && e.target.closest ? e.target.closest('[data-preorder-minus]') : null;
        if (!btn) return;
        e.preventDefault();
        e.stopPropagation();
        const title = String(btn.getAttribute('data-preorder-minus') || '').trim();
        if (!title) return;
        decPreorder(title);
      });
    }
    const parseIsoLocal = (raw) => {
      const s = String(raw || '').trim();
      const m = s.match(/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})/);
      if (!m) return null;
      return new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]), Number(m[4]), Number(m[5]), 0);
    };
    const fmtStartHuman = (raw) => {
      const d = parseIsoLocal(raw);
      if (!d) return '';
      const datePart = new Intl.DateTimeFormat(UI_LOCALE, { day: '2-digit', month: '2-digit', year: 'numeric' }).format(d);
      const timePart = String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0');
      return datePart + ' ' + timePart;
    };
    let modalTableBusy = false;

    const checkModalAvailability = () => {
      if (!pendingBooking || !pendingBooking.tableNum || !reqStart || !reqStart.value || !reqDuration) return;
      const isoDateStr = String(pendingBooking.start || '').slice(0, 10);
      if (!isoDateStr) return;

      const timeParts = reqStart.value.split(':');
      if (timeParts.length < 2) return;
      const selMin = parseInt(timeParts[0], 10) * 60 + parseInt(timeParts[1], 10);
      const durMin = parseInt(reqDuration.value, 10);
      const selEnd = selMin + durMin;

      const tableNum = String(pendingBooking.tableNum);
      const ranges = Array.isArray(lastReservationsByTable[tableNum]) ? lastReservationsByTable[tableNum] : [];

      const now = new Date();
      const isToday = isoDateStr === now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0') + '-' + String(now.getDate()).padStart(2, '0');

      let busy = false;
      const overlaps = ranges.some(([s, e]) => s < selEnd + 30 && e > selMin - 30);
      if (overlaps) busy = true;
      if (isToday && freeNums && !freeNums.has(tableNum)) busy = true;

      modalTableBusy = busy;
      const tableBusyWarning = document.getElementById('tableBusyWarning');
      if (tableBusyWarning) tableBusyWarning.style.display = busy ? 'block' : 'none';
      syncSubmitState();
    };

    const syncSubmitState = () => {
      if (!reqSubmit) return;
      const linked = !!(messengerLinked.telegram || messengerLinked.whatsapp || messengerLinked.zalo);
      if (linked && !modalTableBusy) reqSubmit.classList.remove('is-disabled');
      else reqSubmit.classList.add('is-disabled');
      reqSubmit.setAttribute('aria-disabled', (linked && !modalTableBusy) ? 'false' : 'true');
      reqSubmit.disabled = !!submitBusy || modalTableBusy;
    };
    const openRequestForm = ({ tableNum, guests, start, name, phone, comment, preorder, keepFields }) => {
      pendingBooking = { tableNum: String(tableNum || ''), guests: Number(guests || 0), start: String(start || '') };
      if (reqModalTable) reqModalTable.textContent = String(tableNum || '');
      if (reqGuests) reqGuests.value = String(guests);
      const reqModalDate = document.getElementById('reqModalDate');
      if (reqStart) {
        const iso = String(start || '').trim();
        reqStart.dataset.iso = iso;
        const d = new Date(iso);
        if (!isNaN(d.getTime())) {
          if (reqModalDate) reqModalDate.textContent = d.toLocaleDateString(UI_LOCALE, {day: '2-digit', month: '2-digit', year: 'numeric'});
          const hh = String(d.getHours()).padStart(2, '0');
          const mm = String(d.getMinutes()).padStart(2, '0');
          reqStart.value = `${hh}:${mm}`;
        } else {
          reqStart.value = '';
          if (reqModalDate) reqModalDate.textContent = '';
        }
      }
      if (!keepFields) {
        const d = loadDraft();
        if (reqName) reqName.value = d && typeof d.name === 'string' ? d.name : '';
        if (reqPhone) reqPhone.value = d && typeof d.phone === 'string' ? d.phone : '';
        if (reqComment) reqComment.value = d && typeof d.comment === 'string' ? d.comment : '';
        if (reqGuests) reqGuests.value = d && typeof d.guests === 'string' && String(d.guests || '').trim() ? String(d.guests) : String(guests);
        preorderCounts = d && typeof d.preorder === 'object' ? normalizePreorder(d.preorder) : {};
      } else {
        if (reqName) reqName.value = String(name || '');
        if (reqPhone) reqPhone.value = String(phone || '');
        if (reqComment) reqComment.value = String(comment || '');
        preorderCounts = parsePreorderTextToCounts(preorder);
      }
      if (pendingBooking && reqGuests) pendingBooking.guests = Number(reqGuests.value || pendingBooking.guests || 0) || pendingBooking.guests;
      if (reqHint) { reqHint.hidden = true; reqHint.textContent = ''; reqHint.classList.remove('warn'); }
      syncSubmitState();
      updatePreorderUi();
      renderPreorderBox();
      if (!(messengerLinked.telegram || messengerLinked.whatsapp || messengerLinked.zalo)) setMsgrHint(t('link_tg_hint'));
      checkModalAvailability();
      setModal(reqModal, true);
      if (reqName) reqName.focus();
    };

    let msgrBusy = false;
    const setMsgrHint = (msg) => {
      if (!msgrHint) return;
      const t = String(msg || '').trim();
      if (!t) { msgrHint.hidden = true; msgrHint.textContent = ''; return; }
      msgrHint.hidden = false;
      msgrHint.textContent = t;
    };

    const startTelegramFlow = async () => {
      if (!msgrTgBtn || msgrBusy) return;
      if (!pendingBooking) { setMsgrHint(t('hint_pick_table_first')); return; }
      const tableNum = String(pendingBooking.tableNum || '');
      const guests = reqGuests ? Number(reqGuests.value || pendingBooking.guests || 0) : Number(pendingBooking.guests || 0);
      const start = reqStart ? String(reqStart.dataset.iso || reqStart.value || pendingBooking.start || '').trim() : String(pendingBooking.start || '').trim();
      const name = reqName ? String(reqName.value || '').trim() : '';
      const phone = reqPhone ? String(reqPhone.value || '').trim() : '';
      const comment = reqComment ? String(reqComment.value || '').trim() : '';
      const preorder = guests > 5 ? getPreorderText('ui') : '';
      const preorderRu = guests > 5 ? getPreorderText('ru') : '';
      const resDt = resDate ? String(resDate.value || '').trim() : '';
      const scrollY = Math.max(0, Math.floor(window.scrollY || 0));

      msgrBusy = true;
      msgrTgBtn.disabled = true;
      setMsgrHint(t('hint_opening_tg'));
      try {
        const url = new URL(location.href);
        url.searchParams.set('ajax', 'tg_state_create');
        const res = await fetch(url.toString(), {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({ table_num: tableNum, guests, start, name, phone, comment, preorder, preorder_ru: preorderRu, lang: UI_LANG, res_date: resDt, scroll_y: scrollY }),
        });
        const j = await res.json().catch(() => null);
        if (!res.ok || !j || !j.ok) throw new Error((j && j.error) ? j.error : t('err_generic'));
        const botUrl = String(j.bot_url || '').trim();
        if (!botUrl) throw new Error(t('err_no_bot_link'));
        setMsgrHint(t('hint_tg_back'));
        window.location.href = botUrl;
      } catch (e) {
        setMsgrHint(String(e && e.message ? e.message : e));
      } finally {
        msgrBusy = false;
        msgrTgBtn.disabled = false;
      }
    };

    if (msgrTgBtn) msgrTgBtn.addEventListener('click', () => { startTelegramFlow().catch(() => null); });

    const updateReqGuestsHint = async () => {
      if (!reqHint) return;
      const tableNum = pendingBooking ? String(pendingBooking.tableNum || '') : '';
      const guests = reqGuests ? Number(reqGuests.value || 0) : 0;
      if (!tableNum || !isFinite(guests) || guests <= 0) {
        reqHint.hidden = true;
        reqHint.textContent = '';
        reqHint.classList.remove('warn');
        return;
      }
      try {
        const url = new URL(location.href);
        url.searchParams.set('ajax', 'cap_check');
        url.searchParams.set('table_num', tableNum);
        url.searchParams.set('guests', String(Math.floor(guests)));
        const res = await fetch(url.toString(), { headers: { 'Accept': 'application/json' } });
        const j = await res.json().catch(() => null);
        if (!res.ok || !j || !j.ok) throw new Error((j && j.error) ? j.error : t('err_generic'));
        if (j.status === 'warn' && j.message) {
          reqHint.innerHTML = '<span class="hint-text">' + esc(String(j.message)) + '</span>';
          reqHint.classList.add('warn');
          reqHint.hidden = false;
        } else {
          reqHint.hidden = true;
          reqHint.textContent = '';
          reqHint.classList.remove('warn');
        }
      } catch (_) {
        reqHint.hidden = true;
        reqHint.textContent = '';
        reqHint.classList.remove('warn');
      }
    };

    const bumpGuests = (delta) => {
      if (!reqGuests) return;
      const cur = Number(reqGuests.value || 0) || 0;
      let next = cur + Number(delta || 0);
      if (!isFinite(next) || next <= 0) next = 1;
      if (next > 99) next = 99;
      reqGuests.value = String(Math.floor(next));
      reqGuests.dispatchEvent(new Event('input', { bubbles: true }));
      reqGuests.dispatchEvent(new Event('change', { bubbles: true }));
      reqGuests.focus();
    };
    if (reqGuestsMinus) reqGuestsMinus.addEventListener('click', () => bumpGuests(-1));
    if (reqGuestsPlus) reqGuestsPlus.addEventListener('click', () => bumpGuests(1));

    if (reqGuests) {
      reqGuests.addEventListener('input', () => {
        if (pendingBooking) pendingBooking.guests = Number(reqGuests.value || 0) || pendingBooking.guests;
        updatePreorderUi();
        saveDraft();
        if (reqGuestsHintTimer) clearTimeout(reqGuestsHintTimer);
        reqGuestsHintTimer = setTimeout(() => { updateReqGuestsHint().catch(() => null); }, 180);
      });
      reqGuests.addEventListener('change', () => { updateReqGuestsHint().catch(() => null); });
    }

    if (reqName) reqName.addEventListener('input', () => { saveDraft(); });
    if (reqPhone) reqPhone.addEventListener('input', () => { saveDraft(); });
    if (reqStart) {
      reqStart.addEventListener('change', () => {
        checkModalAvailability();
        saveDraft();
      });
    }
    const reqDuration = document.getElementById('reqDuration');
    if (reqDuration) {
      reqDuration.addEventListener('change', () => {
        checkModalAvailability();
        saveDraft();
      });
    }
    if (reqComment) reqComment.addEventListener('input', () => { saveDraft(); });
    if (reqPreorderBox) {
      reqPreorderBox.addEventListener('click', (e) => {
        const btn = e.target && e.target.closest ? e.target.closest('[data-preorder-minus]') : null;
        if (!btn) return;
        e.preventDefault();
        e.stopPropagation();
        const title = String(btn.getAttribute('data-preorder-minus') || '').trim();
        if (!title) return;
        decPreorder(title);
      });
    }

    if (reqForm) {
      reqForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        if (submitBusy || modalTableBusy) return;
        const name = reqName ? String(reqName.value || '').trim() : '';
        const phone = reqPhone ? String(reqPhone.value || '').trim() : '';
        const guests = reqGuests ? Number(reqGuests.value || 0) : 0;
        let start = '';
        if (pendingBooking && pendingBooking.start && reqStart && reqStart.value) {
          const dPart = String(pendingBooking.start).slice(0, 10);
          const tPart = String(reqStart.value);
          start = `${dPart}T${tPart}:00`;
        }
        const duration_m = reqDuration ? parseInt(reqDuration.value, 10) : 120;
        const comment = reqComment ? String(reqComment.value || '').trim() : '';
        const preorder = guests > 5 ? getPreorderText('ui') : '';
        const preorderRu = guests > 5 ? getPreorderText('ru') : '';
        const tableNum = pendingBooking ? String(pendingBooking.tableNum || '') : '';
        const missing = [];
        if (!tableNum) missing.push(t('missing_table'));
        if (!start) missing.push(t('missing_start'));
        if (!isFinite(guests) || guests <= 0) missing.push(t('missing_guests'));
        if (!name) missing.push(t('missing_name'));
        if (!phone) missing.push(t('missing_phone'));
        if (guests > 5 && !String(preorder || '').trim()) missing.push(t('missing_preorder'));
        if (!(messengerLinked.telegram || messengerLinked.whatsapp || messengerLinked.zalo)) missing.push(t('missing_telegram'));
        if (missing.length) {
          const msg = t('missing_prefix') + missing.join(', ');
          setOutput({ ok: false, error: msg });
          setMsgrHint(msg);
          syncSubmitState();
          return;
        }

        submitBusy = true;
        if (reqSubmit) {
          submitPrevText = String(reqSubmit.textContent || '');
          reqSubmit.textContent = t('sending');
          reqSubmit.disabled = true;
        }
        try {
          // Double check availability before submit
          const dPart = String(pendingBooking.start).slice(0, 10);
          
          const chkUrl = new URL(location.href);
          chkUrl.searchParams.set('ajax', 'free_tables');
          chkUrl.searchParams.set('date_reservation', dPart);
          chkUrl.searchParams.set('duration', String(duration_m * 60));
          chkUrl.searchParams.set('spot_id', '1');
          
          const rChk = await fetch(chkUrl.toString(), { headers: { 'Accept': 'application/json' } });
          const jChk = await rChk.json().catch(() => null);
          
          if (rChk.ok && jChk && jChk.ok && Array.isArray(jChk.free_table_nums)) {
            freeNums = new Set(jChk.free_table_nums.map(String));
            
            // Also fetch reservations for exact ranges
            const rUrl = new URL(location.href);
            rUrl.searchParams.set('ajax', 'reservations');
            rUrl.searchParams.set('date_reservation', dPart);
            rUrl.searchParams.set('duration', String(duration_m * 60));
            rUrl.searchParams.set('spot_id', '1');
            const rRes = await fetch(rUrl.toString(), { headers: { 'Accept': 'application/json' } });
            const jRes = await rRes.json().catch(() => null);
            if (rRes.ok && jRes && jRes.ok && Array.isArray(jRes.reservations_items)) {
               applyReservationsItemsToTables(jRes.reservations_items, dPart, start);
            }
          }

          checkModalAvailability();
          if (modalTableBusy) {
            throw new Error(t('table_busy_warning'));
          }

          const url = new URL(location.href);
          url.searchParams.set('ajax', 'submit_booking');
          const res = await fetch(url.toString(), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({ table_num: tableNum, guests, start, duration_m, name, phone, comment, preorder, preorder_ru: preorderRu, lang: UI_LANG, tg: linkedTg }),
          });
          const j = await res.json().catch(() => null);
          if (!res.ok || !j || !j.ok) throw new Error((j && j.error) ? j.error : t('err_generic'));
          setModal(reqModal, false);
          if (history.state && history.state.modal === 'reqModal') history.back();
          setOutput(fmtVars(t('submit_success'), { start: (fmtStartHuman(start) || start), table: tableNum, guests: String(guests), name, phone }));
        } catch (err) {
          const errMsg = String(err && err.message ? err.message : err);
          setOutput({ ok: false, error: errMsg });
          const tableBusyWarning = document.getElementById('tableBusyWarning');
          if (tableBusyWarning) {
            tableBusyWarning.textContent = errMsg;
            tableBusyWarning.style.display = 'block';
          } else {
            setMsgrHint(errMsg);
          }
        } finally {
          submitBusy = false;
          if (reqSubmit) {
            if (submitPrevText) reqSubmit.textContent = submitPrevText;
            reqSubmit.disabled = false;
          }
          syncSubmitState();
        }
      });
    }

    const esc = (s) => String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    const fmtJson = (x) => {
      try { return JSON.stringify(x, null, 2); } catch (_) { return String(x); }
    };

    const parseSel = (dtRaw) => {
      const raw = String(dtRaw || '').trim();
      const m = raw.match(/^(\d{4}-\d{2}-\d{2})[ T](\d{2}):(\d{2})/);
      if (!m) return null;
      const hh = Number(m[2]);
      const mm = Number(m[3]);
      if (!isFinite(hh) || !isFinite(mm)) return null;
      const day = m[1];
      const selMin = (hh * 60) + mm;
      const now = new Date();
      const todayStr = now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0') + '-' + String(now.getDate()).padStart(2, '0');
      return { day, selMin, isToday: day === todayStr };
    };

    const fmtMin = (m) => String(Math.floor(m / 60)).padStart(2, '0') + ':' + String(m % 60).padStart(2, '0');

    const positionToast = (target) => {
      if (!toastEl || !target) return;
      const r = target.getBoundingClientRect();
      const x = Math.round(r.left + (r.width / 2));
      const y = Math.round(r.top);
      toastEl.style.left = String(x) + 'px';
      toastEl.style.top = String(y) + 'px';
    };

    const hideToast = () => {
      if (!toastEl) return;
      toastEl.classList.remove('on');
      if (toastTimer) { clearTimeout(toastTimer); toastTimer = null; }
    };

    const showToast = (target, reason, detail) => {
      if (!toastEl || !toastTitleEl || !toastReasonEl) return;
      if (toastHideTimer) { clearTimeout(toastHideTimer); toastHideTimer = null; }
      positionToast(target);
      toastTitleEl.textContent = 'Этот столик не доступен';
      toastReasonEl.innerHTML = 'Причина: <b>' + esc(reason) + '</b>' + (detail ? (' · ' + esc(detail)) : '');
      toastEl.classList.add('on');
      if (toastTimer) clearTimeout(toastTimer);
      toastTimer = setTimeout(hideToast, 2200);
    };

    const getUnavailableReason = (tableNum, current) => {
      const tEl = tables.find((x) => String(x.dataset.table || '') === String(tableNum));
      if (!tEl) return null;
      if (tEl.classList.contains('disabled')) return { reason: 'отключено в настройках', detail: '' };
      if (!last || !current) return null;
      const ps = parseSel(current.dtRaw);
      const ranges = Array.isArray(lastReservationsByTable[String(tableNum)]) ? lastReservationsByTable[String(tableNum)] : [];
      if (ps && ranges.length) {
        const selEnd = ps.selMin + 120;
        const overlaps = ranges.some(([s, e]) => s < selEnd && e > ps.selMin);
        if (overlaps) {
          const txt = ranges.slice(0, 2).map(([s, e]) => fmtMin(s) + '-' + fmtMin(e)).join(' · ');
          return { reason: 'там есть бронь', detail: txt };
        }
      }
      if (!freeNums.has(String(tableNum))) {
        if (ps && ps.isToday) return { reason: 'гости сейчас сидят', detail: '' };
        return { reason: 'недоступен на это время', detail: '' };
      }
      return null;
    };

    const setOutput = (obj) => {
      if (!resultText) return;
      if (typeof obj === 'string') {
        resultText.value = obj;
        return;
      }
      resultText.value = fmtJson(obj);
    };

    const formatReservationsOnlyText = (items, meta) => {
      const list = Array.isArray(items) ? items : [];
      const lines = [];
      lines.push('Текущие брони (incomingOrders.getReservations)');
      if (meta && typeof meta === 'object') {
        if (meta.date_from || meta.date_to) {
          lines.push(`Интервал: ${String(meta.date_from || '')} — ${String(meta.date_to || '')}`);
        }
      }
      lines.push('');
      lines.push('Формат: ID стола | Имя стола | Статус | Имя | Старт брони | Конец брони | Кол-во человек');
      if (!list.length) {
        lines.push('—');
        return lines.join('\n');
      }
      list.slice(0, 120).forEach((it) => {
        const tableId = String(it.table_id ?? '—');
        const tableTitle = String(it.table_title ?? '—');
        const status = String(it.status ?? '—');
        const name = String(it.guest_name ?? '—');
        const start = String(it.date_start ?? '—');
        const end = String(it.date_end ?? '—');
        const guests = String(it.guests_count ?? '—');
        lines.push(`${tableId} | ${tableTitle} | ${status} | ${name} | ${start} | ${end} | ${guests}`);
      });
      if (list.length > 120) lines.push(`… ещё ${list.length - 120}`);
      return lines.join('\n');
    };

    const setStatus = (tableNum) => {
      if (selectedTableEl) selectedTableEl.textContent = tableNum ? String(tableNum) : '—';
      if (!tableNum) {
        if (statusLine) statusLine.textContent = '—';
        return;
      }
      if (isLoading) {
        if (statusLine) statusLine.textContent = t('checking');
        return;
      }
      if (!last) {
        if (statusLine) statusLine.textContent = t('press_ok');
        return;
      }
      const isFree = freeNums.has(String(tableNum));
      if (statusLine) statusLine.textContent = isFree ? t('status_free') : t('status_busy');
    };

    const applyAvailabilityStyles = () => {
      tables.forEach((t) => {
        const n = String(t.dataset.table || '');
        t.classList.remove('free', 'busy');
        if (!last) return;
        if (t.dataset.resBusy === '1') { t.classList.add('busy'); return; }
        if (freeNums.has(n)) t.classList.add('free');
        else t.classList.add('busy');
      });
    };

    const getCurrentRequest = () => {
      const dtRaw = resDate ? String(resDate.value || '').trim() : '';
      if (!dtRaw) return null;
      // dtRaw is YYYY-MM-DD
      const now = new Date();
      const todayStr = now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0') + '-' + String(now.getDate()).padStart(2, '0');
      
      let timeStr = '12:00:00';
      if (dtRaw === todayStr) {
        timeStr = String(now.getHours()).padStart(2, '0') + ':' + String(now.getMinutes()).padStart(2, '0') + ':00';
      }
      
      const dt = dtRaw + ' ' + timeStr;
      return { dt, guests: 1, dtRaw: dtRaw + 'T' + timeStr.slice(0, 5), durationSec: 7200, durationHours: 2 };
    };

    const invalidateLast = () => {
      last = null;
      freeNums = new Set();
      lastKey = '';
      applyAvailabilityStyles();
      clearReservationsOnTables();
      renderSelectedTable();
    };

    const renderSelectedTable = () => {
      setStatus(selectedTableNum);
      if (!selectedTableNum) return;
      if (!last) {
        setOutput(t('press_ok'));
        return;
      }
      setOutput(formatReservationsOnlyText(last.reservations_items, last.reservations_request));
    };
  
    tables.forEach(table => {
      table.addEventListener('mouseenter', () => {
        const id = String(table.dataset.table || '');
        const current = getCurrentRequest();
        const un = getUnavailableReason(id, current);
        if (un) showToast(table, un.reason, un.detail);
      });
      table.addEventListener('mouseleave', () => {
        if (toastHideTimer) clearTimeout(toastHideTimer);
        toastHideTimer = setTimeout(hideToast, 180);
      });

      table.addEventListener('click', async () => {
        const id = String(table.dataset.table || '');
        const current = getCurrentRequest();
        if (!current) {
          if (!resDate || !String(resDate.value || '').trim()) {
            setStatus(id);
            setOutput({ ok: false, error: t('select_date_time') });
            return;
          }
          setOutput({ ok: false, error: t('select_date_time') });
          return;
        }

        const key = current.dt + '|' + String(current.guests);
        if ((!last || lastKey !== key) && !isLoading) {
          try {
            await loadFree(true);
          } catch (e) {
            setOutput({ ok: false, error: String(e && e.message ? e.message : e) });
            return;
          }
        }

        const un = getUnavailableReason(id, current);
        if (un) {
          selectedTableNum = '';
          showToast(table, un.reason, un.detail);
          return;
        }

        const cap = tableCapsByNum && typeof tableCapsByNum === 'object' && tableCapsByNum[id] != null ? Number(tableCapsByNum[id]) : null;
        if (cap != null && isFinite(cap) && current.guests > cap) {
          const ok = await confirmCapacity(Math.max(1, Math.floor(cap)), current.guests);
          if (!ok) {
            selectedTableNum = '';
            setOutput('Исправь кол-во гостей и выбери столик снова.');
            return;
          }
        }

        selectedTableNum = id;
        openRequestForm({ tableNum: id, guests: current.guests, start: current.dtRaw });
      });
    });

    const initDate = () => {
      if (!resDate) return;
      const minSlot = getMinSelectableSlot();
      resDate.min = minSlot.dateVal;
      resDate.value = minSlot.dateVal;
      if (resDateBtn) resDateBtn.textContent = fmtCashDate(resDate.value);
      setBusyLabel(String(resDate.value || '').slice(0, 10));
      clearReservationsOnTables();
    };

    const syncSteps = () => {
      const hasDate = !!(resDate && String(resDate.value || '').trim());
      if (stepGuests) stepGuests.hidden = !hasDate;
      if (stepCheck) stepCheck.hidden = !hasDate;
      if (!hasDate) invalidateLast();
    };

    const loadFree = async (silent) => {
      if (isLoading) return;
      const current = getCurrentRequest();
      if (!current) {
        if (!resDate || !String(resDate.value || '').trim()) setOutput({ ok: false, error: t('select_date_time') });
        else setOutput({ ok: false, error: t('select_date_time') });
        return;
      }

      const dt = current.dt;
      const guests = current.guests;
      const key = dt + '|' + String(guests);
      const dateStr = String(dt).slice(0, 10);

      isLoading = true;
      if (statusLine) statusLine.textContent = t('checking');
      setBusyLabel(dateStr);
      setBusyLoader(true);

      const url = new URL(location.href);
      url.searchParams.set('ajax', 'free_tables');
      url.searchParams.set('date_reservation', dt);
      url.searchParams.set('duration', String(current.durationSec || 7200));
      url.searchParams.set('spot_id', '1');
      url.searchParams.set('guests_count', String(guests));

      const fetchJson = async (u) => {
        const timeoutMs = 12000;
        if (typeof AbortController === 'function') {
          const ctrl = new AbortController();
          const tm = setTimeout(() => ctrl.abort(), timeoutMs);
          try {
            const r = await fetch(u, { headers: { 'Accept': 'application/json' }, signal: ctrl.signal });
            const j = await r.json().catch(() => null);
            return { res: r, json: j };
          } finally {
            clearTimeout(tm);
          }
        }
        const r = await fetch(u, { headers: { 'Accept': 'application/json' } });
        const j = await r.json().catch(() => null);
        return { res: r, json: j };
      };

      const loadReservations = async () => {
        const rUrl = new URL(location.href);
        rUrl.searchParams.set('ajax', 'reservations');
        rUrl.searchParams.set('date_reservation', dt);
        rUrl.searchParams.set('duration', String(current.durationSec || 7200));
        rUrl.searchParams.set('spot_id', '1');
        const rX = await fetchJson(rUrl.toString());
        const rRes = rX.res;
        const rJ = rX.json;
        if (!rRes.ok || !rJ || !rJ.ok) return null;
        return rJ;
      };

      try {
        const x = await fetchJson(url.toString());
        const res = x.res;
        const j = x.json;
        if (!res.ok || !j || !j.ok) {
          last = null;
          freeNums = new Set();
          lastKey = '';
          applyAvailabilityStyles();
          setOutput(j && typeof j === 'object' ? fmtJson(j) : t('try_ok_again'));
          renderSelectedTable();
          return;
        }

        last = j;
        lastKey = key;
        freeNums = new Set(Array.isArray(j.free_table_nums) ? j.free_table_nums.map(String) : []);
        applyAvailabilityStyles();
        const r = await loadReservations().catch(() => null);
        if (r) {
          last.reservations_request = r.request;
          last.reservations_items = r.reservations_items;
        } else {
          last.reservations_request = null;
          last.reservations_items = [];
        }
        clearReservationsOnTables();
        applyReservationsItemsToTables(last.reservations_items, dateStr, dt);
        applyAvailabilityStyles();
        if (!silent) setOutput(formatReservationsOnlyText(last.reservations_items, last.reservations_request));
        renderSelectedTable();
      } catch (_) {
        last = null;
        freeNums = new Set();
        lastKey = '';
        applyAvailabilityStyles();
        if (!silent) setOutput(t('try_ok_again'));
      } finally {
        isLoading = false;
        setStatus(selectedTableNum);
        setBusyLoader(false);
      }
    };

    initDate();
    setTimeout(() => { loadFree(true).catch(() => null); }, 0);
    const restoreFromTgState = async () => {
      const params = new URLSearchParams(location.search);
      const code = String(params.get('tg_state') || '').trim();
      if (!code) return;
      try {
        const url = new URL(location.href);
        url.searchParams.set('ajax', 'tg_state_get');
        url.searchParams.set('code', code);
        const res = await fetch(url.toString(), { headers: { 'Accept': 'application/json' } });
        const j = await res.json().catch(() => null);
        if (!res.ok || !j || !j.ok || !j.payload) throw new Error((j && j.error) ? j.error : t('err_generic'));
        const p = j.payload || {};
        const resDt = String(p.res_date || '').trim();
        if (resDate && resDt && /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(resDt)) {
          skipNextResDateAutoLoad = true;
          resDate.value = resDt;
          if (resDateBtn) resDateBtn.textContent = fmtCashDate(resDate.value);
          setBusyLabel(String(resDate.value || '').slice(0, 10));
          invalidateLast();
          setTimeout(() => { loadFree(true).catch(() => null); }, 0);
        }
        const tableNum = String(p.table_num || '').trim();
        const guests = Number(p.guests || 0) || 0;
        const start = String(p.start || '').trim();
        const name = String(p.name || '');
        const phone = String(p.phone || '');
        const comment = String(p.comment || '');
        const preorder = String(p.preorder || '');
        const preorderRu = String(p.preorder_ru || '');
        const tg = j.tg && typeof j.tg === 'object' ? j.tg : null;
        if (tableNum && guests > 0 && start) {
          selectedTableNum = tableNum;
          messengerLinked.telegram = true;
          linkedTg = tg ? { user_id: Number(tg.user_id || 0) || 0, username: String(tg.username || ''), name: String(tg.name || '') } : null;
          openRequestForm({ tableNum, guests, start, name, phone, comment, preorder: preorderRu || preorder, keepFields: true });
          if (linkedTg && linkedTg.username) setMsgrHint('Telegram привязан ✅ @' + String(linkedTg.username).replace(/^@+/, ''));
          else setMsgrHint('Telegram привязан ✅');
          syncSubmitState();
          updateReqGuestsHint().catch(() => null);
        }
        const scrollY = Math.max(0, Math.floor(Number(p.scroll_y || 0) || 0));
        if (scrollY > 0) setTimeout(() => { window.scrollTo(0, scrollY); }, 60);
      } catch (_) {
      } finally {
        const next = new URL(location.href);
        next.searchParams.delete('tg_state');
        history.replaceState(null, '', next.toString());
      }
    };
    restoreFromTgState().catch(() => null);
    syncSteps();
    if (resDate) {
      resDate.addEventListener('input', () => { syncSteps(); invalidateLast(); setBusyLabel(String(resDate.value || '').slice(0, 10)); });
    }
    setOutput(t('press_ok_then_tables'));
})();
