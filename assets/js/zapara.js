(() => {
    const chartsEl = document.getElementById('charts');
    const dateFromEl = document.getElementById('dateFrom');
    const dateToEl = document.getElementById('dateTo');
    const loadBtn = document.getElementById('loadBtn');
    const chartTypeToggle = document.getElementById('chartTypeToggle');
    const metricToggle = document.getElementById('metricToggle');
    const prog = document.getElementById('prog');
    const progFill = document.getElementById('progFill');
    const progPct = document.getElementById('progPct');
    const progText = document.getElementById('progText');
    let chartType = 'bar';
    let metric = 'checks';
    let lastData = null;

    try {
        chartType = (localStorage.getItem('zapara_chart_type') || '') === 'line' ? 'line' : 'bar';
    } catch (_) {}
    try {
        metric = (localStorage.getItem('zapara_metric') || '') === 'dishes' ? 'dishes' : 'checks';
    } catch (_) {}
    if (chartTypeToggle) {
        chartTypeToggle.checked = chartType === 'line';
        chartTypeToggle.addEventListener('change', () => {
            chartType = chartTypeToggle.checked ? 'line' : 'bar';
            try { localStorage.setItem('zapara_chart_type', chartType); } catch (_) {}
            if (lastData) render(lastData);
        });
    }
    if (metricToggle) {
        metricToggle.checked = metric === 'dishes';
        metricToggle.addEventListener('change', () => {
            metric = metricToggle.checked ? 'dishes' : 'checks';
            try { localStorage.setItem('zapara_metric', metric); } catch (_) {}
            if (lastData) render(lastData);
        });
    }

    const isYmd = (s) => /^\d{4}-\d{2}-\d{2}$/.test(String(s || '').trim());
    const saveDates = () => {
        if (!dateFromEl || !dateToEl) return;
        const df = String(dateFromEl.value || '').trim();
        const dt = String(dateToEl.value || '').trim();
        try {
            if (isYmd(df)) localStorage.setItem('zapara_date_from', df);
            if (isYmd(dt)) localStorage.setItem('zapara_date_to', dt);
        } catch (_) {}
    };
    const restoreDates = () => {
        if (!dateFromEl || !dateToEl) return;
        try {
            const df = localStorage.getItem('zapara_date_from') || '';
            const dt = localStorage.getItem('zapara_date_to') || '';
            if (isYmd(df)) dateFromEl.value = df;
            if (isYmd(dt)) dateToEl.value = dt;
        } catch (_) {}
    };
    restoreDates();
    if (dateFromEl) {
        dateFromEl.addEventListener('change', saveDates);
        dateFromEl.addEventListener('input', saveDates);
    }
    if (dateToEl) {
        dateToEl.addEventListener('change', saveDates);
        dateToEl.addEventListener('input', saveDates);
    }

    const dows = [
        { key: '1', name: 'Пн' },
        { key: '2', name: 'Вт' },
        { key: '3', name: 'Ср' },
        { key: '4', name: 'Чт' },
        { key: '5', name: 'Пт' },
        { key: '6', name: 'Сб' },
        { key: '7', name: 'Вс' },
    ];

    const makeCanvasCard = (title, metaText) => {
        const wrap = document.createElement('div');
        wrap.className = 'card';
        const head = document.createElement('div');
        head.className = 'row';
        const t = document.createElement('div');
        t.textContent = title;
        t.style.fontWeight = '900';
        const meta = document.createElement('div');
        meta.className = 'muted';
        meta.textContent = metaText || '09:00 — 24:00';
        head.appendChild(t);
        head.appendChild(meta);
        const box = document.createElement('div');
        box.className = 'chart';
        const canvas = document.createElement('canvas');
        canvas.width = 800;
        canvas.height = 220;
        box.appendChild(canvas);
        wrap.appendChild(head);
        wrap.appendChild(box);
        return { wrap, canvas };
    };

    const clearCharts = () => { chartsEl.innerHTML = ''; };

    const drawBars = (canvas, hours, countsByHour, avgByHour, isAvgChart) => {
        const ctx = canvas.getContext('2d');
        if (!ctx) return;
        const w = canvas.width;
        const h = canvas.height;
        ctx.clearRect(0, 0, w, h);

        const padL = 44;
        const padR = 16;
        const padT = 12;
        const padB = 28;
        const iw = w - padL - padR;
        const ih = h - padT - padB;

        const vals = hours.map((hh) => Number(countsByHour[String(hh)] || 0));
        const maxV = Math.max(1, ...vals);

        ctx.strokeStyle = 'rgba(255,255,255,0.10)';
        ctx.lineWidth = 1;
        for (let i = 0; i <= 4; i++) {
            const y = padT + (ih * i / 4);
            ctx.beginPath();
            ctx.moveTo(padL, y);
            ctx.lineTo(padL + iw, y);
            ctx.stroke();
        }

        ctx.fillStyle = 'rgba(245,238,228,0.62)';
        ctx.font = '12px system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif';
        ctx.textAlign = 'right';
        ctx.textBaseline = 'middle';
        for (let i = 0; i <= 4; i++) {
            const v = Math.round(maxV * (1 - i / 4));
            const y = padT + (ih * i / 4);
            ctx.fillText(String(v), padL - 8, y);
        }

        const barGap = 6;
        const barW = Math.max(6, Math.floor((iw - barGap * (hours.length - 1)) / hours.length));
        const usedW = barW * hours.length + barGap * (hours.length - 1);
        const startX = padL + Math.floor((iw - usedW) / 2);

        ctx.textAlign = 'center';
        ctx.textBaseline = 'top';
        hours.forEach((hh, idx) => {
            const v = vals[idx];
            const hk = String(hh);
            const x = startX + idx * (barW + barGap);
            const bh = Math.round((v / maxV) * ih);
            const y = padT + (ih - bh);

            ctx.fillStyle = 'rgba(255, 120, 120, 0.86)';
            ctx.fillRect(x, y, barW, bh);

            if (v > 0) {
                ctx.save();
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                ctx.fillStyle = 'rgba(11, 15, 22, 0.90)';
                const mid = y + (bh / 2);
                const top = y + 11;
                const ty = Math.max(top, mid);
                const vLabel = isAvgChart ? (Math.round(v * 10) / 10).toFixed(1).replace(/\.0$/, '') : String(Math.round(v));
                const avgVal = avgByHour && avgByHour[hk] != null ? Number(avgByHour[hk] || 0) : null;
                const showAvg = !isAvgChart && avgVal != null && isFinite(avgVal) && bh >= 32;
                if (showAvg) {
                    ctx.font = '900 11px system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif';
                    ctx.fillText(vLabel, x + barW / 2, ty - 6);
                    ctx.font = '800 10px system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif';
                    const aLabel = (Math.round(avgVal * 10) / 10).toFixed(1).replace(/\.0$/, '');
                    ctx.fillText('~' + aLabel, x + barW / 2, ty + 7);
                } else {
                    ctx.font = '900 11px system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif';
                    ctx.fillText(vLabel, x + barW / 2, ty);
                }
                ctx.restore();
            }

            const label = String(hh);
            ctx.fillStyle = 'rgba(245,238,228,0.62)';
            if (hh % 2 === 1) ctx.fillText(label, x + barW / 2, padT + ih + 6);
        });
    };

    const drawLine = (canvas, hours, countsByHour) => {
        const ctx = canvas.getContext('2d');
        if (!ctx) return;
        const w = canvas.width;
        const h = canvas.height;
        ctx.clearRect(0, 0, w, h);

        const padL = 44;
        const padR = 16;
        const padT = 12;
        const padB = 28;
        const iw = w - padL - padR;
        const ih = h - padT - padB;

        const vals = hours.map((hh) => Number(countsByHour[String(hh)] || 0));
        const maxV = Math.max(1, ...vals);

        ctx.strokeStyle = 'rgba(255,255,255,0.10)';
        ctx.lineWidth = 1;
        for (let i = 0; i <= 4; i++) {
            const y = padT + (ih * i / 4);
            ctx.beginPath();
            ctx.moveTo(padL, y);
            ctx.lineTo(padL + iw, y);
            ctx.stroke();
        }

        ctx.fillStyle = 'rgba(245,238,228,0.62)';
        ctx.font = '12px system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif';
        ctx.textAlign = 'right';
        ctx.textBaseline = 'middle';
        for (let i = 0; i <= 4; i++) {
            const v = Math.round(maxV * (1 - i / 4));
            const y = padT + (ih * i / 4);
            ctx.fillText(String(v), padL - 8, y);
        }

        const stepX = iw / Math.max(1, (hours.length - 1));
        const x0 = padL;

        ctx.strokeStyle = 'rgba(255, 120, 120, 0.92)';
        ctx.lineWidth = 2;
        ctx.beginPath();
        hours.forEach((hh, idx) => {
            const v = vals[idx];
            const x = x0 + stepX * idx;
            const y = padT + ih - (v / maxV) * ih;
            if (idx === 0) ctx.moveTo(x, y);
            else ctx.lineTo(x, y);
        });
        ctx.stroke();

        ctx.fillStyle = 'rgba(255, 120, 120, 0.92)';
        hours.forEach((hh, idx) => {
            const v = vals[idx];
            const x = x0 + stepX * idx;
            const y = padT + ih - (v / maxV) * ih;
            ctx.beginPath();
            ctx.arc(x, y, 2.5, 0, Math.PI * 2);
            ctx.fill();
        });

        ctx.textAlign = 'center';
        ctx.textBaseline = 'top';
        hours.forEach((hh, idx) => {
            if (hh % 2 !== 1) return;
            const x = x0 + stepX * idx;
            ctx.fillStyle = 'rgba(245,238,228,0.62)';
            ctx.fillText(String(hh), x, padT + ih + 6);
        });
    };

    const drawChart = (canvas, hours, countsByHour) => {
        if (chartType === 'line') drawLine(canvas, hours, countsByHour);
        else drawBars(canvas, hours, countsByHour, null, false);
    };

    // ─── Multi-series renderers (bar workshop + kitchen workshop) ───
    //
    // series = [{ label, color, counts: {hour: value} }, ...]
    // Используется только когда metric === 'dishes' — для чеков разбивка
    // по цехам не имеет смысла (один чек охватывает оба цеха).

    const drawLineMulti = (canvas, hours, series) => {
        const ctx = canvas.getContext('2d');
        if (!ctx) return;
        const w = canvas.width, h = canvas.height;
        ctx.clearRect(0, 0, w, h);
        const padL = 44, padR = 16, padT = 12, padB = 28;
        const iw = w - padL - padR, ih = h - padT - padB;
        let maxV = 1;
        series.forEach((s) => {
            hours.forEach((hh) => {
                const v = Number(s.counts[String(hh)] || 0);
                if (v > maxV) maxV = v;
            });
        });
        ctx.strokeStyle = 'rgba(255,255,255,0.10)';
        ctx.lineWidth = 1;
        for (let i = 0; i <= 4; i++) {
            const y = padT + (ih * i / 4);
            ctx.beginPath(); ctx.moveTo(padL, y); ctx.lineTo(padL + iw, y); ctx.stroke();
        }
        ctx.fillStyle = 'rgba(245,238,228,0.62)';
        ctx.font = '12px system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif';
        ctx.textAlign = 'right'; ctx.textBaseline = 'middle';
        for (let i = 0; i <= 4; i++) {
            const v = Math.round(maxV * (1 - i / 4));
            const y = padT + (ih * i / 4);
            ctx.fillText(String(v), padL - 8, y);
        }
        const stepX = iw / Math.max(1, (hours.length - 1));
        const x0 = padL;
        series.forEach((s) => {
            ctx.strokeStyle = s.color;
            ctx.lineWidth = 2;
            ctx.beginPath();
            hours.forEach((hh, idx) => {
                const v = Number(s.counts[String(hh)] || 0);
                const x = x0 + stepX * idx;
                const y = padT + ih - (v / maxV) * ih;
                if (idx === 0) ctx.moveTo(x, y); else ctx.lineTo(x, y);
            });
            ctx.stroke();
            ctx.fillStyle = s.color;
            hours.forEach((hh, idx) => {
                const v = Number(s.counts[String(hh)] || 0);
                const x = x0 + stepX * idx;
                const y = padT + ih - (v / maxV) * ih;
                ctx.beginPath(); ctx.arc(x, y, 2.5, 0, Math.PI * 2); ctx.fill();
            });
        });
        ctx.textAlign = 'center'; ctx.textBaseline = 'top';
        hours.forEach((hh, idx) => {
            if (hh % 2 !== 1) return;
            const x = x0 + stepX * idx;
            ctx.fillStyle = 'rgba(245,238,228,0.62)';
            ctx.fillText(String(hh), x, padT + ih + 6);
        });
    };

    // Стэк bar-on-top-of-kitchen — две группы видно в одном столбике.
    const drawBarsStack = (canvas, hours, series, isAvgChart) => {
        const ctx = canvas.getContext('2d');
        if (!ctx) return;
        const w = canvas.width, h = canvas.height;
        ctx.clearRect(0, 0, w, h);
        const padL = 44, padR = 16, padT = 12, padB = 28;
        const iw = w - padL - padR, ih = h - padT - padB;
        const totals = hours.map((hh) => {
            let s = 0;
            series.forEach((sr) => { s += Number(sr.counts[String(hh)] || 0); });
            return s;
        });
        const maxV = Math.max(1, ...totals);
        ctx.strokeStyle = 'rgba(255,255,255,0.10)';
        ctx.lineWidth = 1;
        for (let i = 0; i <= 4; i++) {
            const y = padT + (ih * i / 4);
            ctx.beginPath(); ctx.moveTo(padL, y); ctx.lineTo(padL + iw, y); ctx.stroke();
        }
        ctx.fillStyle = 'rgba(245,238,228,0.62)';
        ctx.font = '12px system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif';
        ctx.textAlign = 'right'; ctx.textBaseline = 'middle';
        for (let i = 0; i <= 4; i++) {
            const v = Math.round(maxV * (1 - i / 4));
            const y = padT + (ih * i / 4);
            ctx.fillText(String(v), padL - 8, y);
        }
        const barGap = 6;
        const barW = Math.max(6, Math.floor((iw - barGap * (hours.length - 1)) / hours.length));
        const usedW = barW * hours.length + barGap * (hours.length - 1);
        const startX = padL + Math.floor((iw - usedW) / 2);

        hours.forEach((hh, idx) => {
            const x = startX + idx * (barW + barGap);
            let stackBottom = padT + ih;
            series.forEach((sr) => {
                const v = Number(sr.counts[String(hh)] || 0);
                if (v <= 0) return;
                const bh = Math.round((v / maxV) * ih);
                const y = stackBottom - bh;
                ctx.fillStyle = sr.color;
                ctx.fillRect(x, y, barW, bh);
                stackBottom = y;
            });
            const total = totals[idx];
            if (total > 0) {
                ctx.save();
                ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
                ctx.fillStyle = 'rgba(11, 15, 22, 0.90)';
                ctx.font = '900 11px system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif';
                const totalBh = Math.round((total / maxV) * ih);
                const ty = Math.max(padT + ih - totalBh + 11, padT + ih - totalBh / 2);
                const tLabel = isAvgChart
                    ? (Math.round(total * 10) / 10).toFixed(1).replace(/\.0$/, '')
                    : String(Math.round(total));
                ctx.fillText(tLabel, x + barW / 2, ty);
                ctx.restore();
            }
            ctx.fillStyle = 'rgba(245,238,228,0.62)';
            ctx.textAlign = 'center'; ctx.textBaseline = 'top';
            if (hh % 2 === 1) ctx.fillText(String(hh), x + barW / 2, padT + ih + 6);
        });
    };

    // Цвета двух серий + цвет для одиночной серии «Чеки».
    const SERIES_COLORS = {
        bar:     'rgba(96, 165, 250, 0.92)',   // голубой
        kitchen: 'rgba(255, 145, 90, 0.92)',   // оранжевый
        checks:  'rgba(255, 120, 120, 0.92)',  // красный — тот же что и старый одиночный
    };

    const makeLegendChip = (label, color) => {
        const span = document.createElement('span');
        span.style.display = 'inline-flex';
        span.style.alignItems = 'center';
        span.style.gap = '6px';
        span.style.marginRight = '16px';
        span.style.fontSize = '13px';
        const dot = document.createElement('span');
        dot.style.display = 'inline-block';
        dot.style.width = '12px';
        dot.style.height = '12px';
        dot.style.borderRadius = '50%';
        dot.style.background = color;
        dot.style.boxShadow = '0 0 0 2px rgba(255,255,255,.08)';
        const t = document.createElement('span');
        t.textContent = label;
        t.style.fontWeight = '700';
        span.appendChild(dot);
        span.appendChild(t);
        return span;
    };

    // Легенда — инлайн внутри подзаголовка «Источник: Poster ...».
    // Переписывается каждый раз render() — пересоздаём чипы под текущую метрику.
    const updateLegendInline = (isDishes) => {
        const slot = document.getElementById('zapLegend');
        if (!slot) return;
        slot.innerHTML = '';
        if (isDishes) {
            slot.appendChild(makeLegendChip('Кухня', SERIES_COLORS.kitchen));
            slot.appendChild(makeLegendChip('Бар',   SERIES_COLORS.bar));
        } else {
            slot.appendChild(makeLegendChip('Чеки', SERIES_COLORS.checks));
        }
    };

    // ─── Hover-tooltip overlay ─────────────────────────────────────
    //
    // Каждый canvas после рендера получает в `._z` копию данных, которыми
    // его рисовали. mousemove → пересчёт ближайшего часа → перерисовка с
    // подсветкой + тултип в углу. mouseleave → перерисовка без подсветки.
    // Дёшево: 15 точек на график, ребайнд занимает <1ms.

    const CHART_PAD = { L: 44, R: 16, T: 12, B: 28 };

    const computeHourIdxLine = (canvas, mouseX, hoursLen) => {
        const iw = canvas.width - CHART_PAD.L - CHART_PAD.R;
        const stepX = iw / Math.max(1, hoursLen - 1);
        const idx = Math.round((mouseX - CHART_PAD.L) / stepX);
        if (idx < 0 || idx >= hoursLen) return -1;
        return idx;
    };
    const computeHourIdxBar = (canvas, mouseX, hoursLen) => {
        const iw = canvas.width - CHART_PAD.L - CHART_PAD.R;
        const barGap = 6;
        const barW = Math.max(6, Math.floor((iw - barGap * (hoursLen - 1)) / hoursLen));
        const usedW = barW * hoursLen + barGap * (hoursLen - 1);
        const startX = CHART_PAD.L + Math.floor((iw - usedW) / 2);
        const localX = mouseX - startX;
        if (localX < 0) return -1;
        const idx = Math.floor(localX / (barW + barGap));
        if (idx < 0 || idx >= hoursLen) return -1;
        return idx;
    };

    /**
     * Перерисовать canvas по сохранённому в нём _z. Если передан hourIdx — добавить
     * сверху вертикальный пунктир + тултип со значениями серий за этот час.
     */
    const drawCanvasFromZ = (canvas, hoverIdx = -1) => {
        const z = canvas._z;
        if (!z) return;
        if (z.chartType === 'line') {
            if (z.series.length === 1) drawLine(canvas, z.hours, z.series[0].counts);
            else drawLineMulti(canvas, z.hours, z.series);
        } else {
            if (z.series.length === 1) drawBars(canvas, z.hours, z.series[0].counts, null, z.isAvgChart);
            else drawBarsStack(canvas, z.hours, z.series, z.isAvgChart);
        }
        if (hoverIdx >= 0) drawHoverOverlay(canvas, hoverIdx);
    };

    const drawHoverOverlay = (canvas, hourIdx) => {
        const z = canvas._z;
        if (!z) return;
        const ctx = canvas.getContext('2d');
        if (!ctx) return;
        const ih = canvas.height - CHART_PAD.T - CHART_PAD.B;
        const hours = z.hours;

        // X центра выделенного часа — отличается для линии и баров.
        let cx;
        if (z.chartType === 'line') {
            const iw = canvas.width - CHART_PAD.L - CHART_PAD.R;
            const stepX = iw / Math.max(1, hours.length - 1);
            cx = CHART_PAD.L + stepX * hourIdx;
        } else {
            const iw = canvas.width - CHART_PAD.L - CHART_PAD.R;
            const barGap = 6;
            const barW = Math.max(6, Math.floor((iw - barGap * (hours.length - 1)) / hours.length));
            const usedW = barW * hours.length + barGap * (hours.length - 1);
            const startX = CHART_PAD.L + Math.floor((iw - usedW) / 2);
            cx = startX + hourIdx * (barW + barGap) + barW / 2;
        }

        // Вертикальный пунктир-направляющая.
        ctx.save();
        ctx.strokeStyle = 'rgba(255,255,255,0.32)';
        ctx.lineWidth = 1;
        ctx.setLineDash([4, 3]);
        ctx.beginPath();
        ctx.moveTo(cx, CHART_PAD.T);
        ctx.lineTo(cx, CHART_PAD.T + ih);
        ctx.stroke();
        ctx.setLineDash([]);
        ctx.restore();

        // Состав тултипа.
        const hourLabel = String(hours[hourIdx]).padStart(2, '0') + ':00';
        const fmt = (v) => z.isAvgChart
            ? (Math.round(v * 10) / 10).toFixed(1).replace(/\.0$/, '')
            : String(Math.round(v));
        const seriesLines = z.series.map((s) => ({
            label: s.label,
            color: s.color,
            value: fmt(Number(s.counts[String(hours[hourIdx])] || 0)),
        }));
        const hasTotal = z.series.length > 1;
        const totalRaw = z.series.reduce((acc, s) => acc + Number(s.counts[String(hours[hourIdx])] || 0), 0);
        const totalLine = hasTotal ? { label: 'Всего', value: fmt(totalRaw) } : null;

        // Замер ширины
        ctx.save();
        ctx.font = '600 13px system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif';
        let maxW = ctx.measureText(hourLabel).width;
        ctx.font = '12px system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif';
        seriesLines.forEach((l) => {
            const w = ctx.measureText(l.label + ': ' + l.value).width + 16;
            if (w > maxW) maxW = w;
        });
        if (totalLine) {
            const w = ctx.measureText(totalLine.label + ': ' + totalLine.value).width;
            if (w > maxW) maxW = w;
        }
        const padIn = 10;
        const lineH = 18;
        const linesCount = 1 + seriesLines.length + (totalLine ? 1 : 0);
        const tipW = Math.ceil(maxW) + padIn * 2;
        const tipH = padIn + linesCount * lineH + padIn - 4;
        let tipX = cx + 10;
        if (tipX + tipW > canvas.width - 4) tipX = cx - 10 - tipW;
        if (tipX < 4) tipX = 4;
        const tipY = CHART_PAD.T + 4;

        // Фон + бордер
        ctx.fillStyle = 'rgba(15, 18, 24, 0.93)';
        ctx.strokeStyle = 'rgba(255,255,255,0.22)';
        ctx.lineWidth = 1;
        ctx.beginPath();
        ctx.rect(tipX + 0.5, tipY + 0.5, tipW, tipH);
        ctx.fill();
        ctx.stroke();

        // Час (жирно)
        ctx.textAlign = 'left';
        ctx.textBaseline = 'top';
        ctx.fillStyle = 'rgba(245,238,228,0.95)';
        ctx.font = '700 13px system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif';
        ctx.fillText(hourLabel, tipX + padIn, tipY + padIn - 2);

        // Серии — с цветной точкой
        ctx.font = '12px system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif';
        seriesLines.forEach((line, i) => {
            const ly = tipY + padIn - 2 + (i + 1) * lineH;
            ctx.fillStyle = line.color;
            ctx.beginPath();
            ctx.arc(tipX + padIn + 4, ly + 6, 4, 0, Math.PI * 2);
            ctx.fill();
            ctx.fillStyle = 'rgba(245,238,228,0.85)';
            ctx.fillText(line.label + ': ' + line.value, tipX + padIn + 14, ly);
        });
        // Всего (для мультисерийных)
        if (totalLine) {
            const ly = tipY + padIn - 2 + (seriesLines.length + 1) * lineH;
            ctx.fillStyle = 'rgba(245,238,228,0.95)';
            ctx.font = '700 12px system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif';
            ctx.fillText(totalLine.label + ': ' + totalLine.value, tipX + padIn, ly);
        }
        ctx.restore();
    };

    const setupCanvasHover = (canvas) => {
        if (canvas._hoverWired) return;
        canvas._hoverWired = true;
        canvas.style.cursor = 'crosshair';
        canvas.addEventListener('mousemove', (e) => {
            const z = canvas._z;
            if (!z) return;
            const rect = canvas.getBoundingClientRect();
            const x = (e.clientX - rect.left) * (canvas.width / rect.width);
            const idx = z.chartType === 'line'
                ? computeHourIdxLine(canvas, x, z.hours.length)
                : computeHourIdxBar(canvas, x, z.hours.length);
            drawCanvasFromZ(canvas, idx);
        });
        canvas.addEventListener('mouseleave', () => drawCanvasFromZ(canvas, -1));
    };

    const hours = [];
    for (let hh = 9; hh <= 23; hh++) hours.push(hh);

    /**
     * Превращает counts_by_dow (для одного дня недели) в перисечасовое
     * среднее по count'у дней этого dow. Возвращает {hour: per-day-average}.
     */
    const perDayAverages = (countsForDow, dCnt) => {
        const out = {};
        hours.forEach((h) => {
            const hk = String(h);
            const v = countsForDow ? Number(countsForDow[hk] || 0) : 0;
            out[hk] = dCnt > 0 ? (v / dCnt) : 0;
        });
        return out;
    };

    const render = (data) => {
        clearCharts();
        const isDishes = (metric === 'dishes');
        const counts = isDishes
            ? ((data && data.counts_dishes_by_dow) ? data.counts_dishes_by_dow : {})
            : ((data && data.counts_checks_by_dow) ? data.counts_checks_by_dow : ((data && data.counts_by_dow) ? data.counts_by_dow : {}));
        const countsBar     = (data && data.counts_bar_by_dow)     ? data.counts_bar_by_dow     : {};
        const countsKitchen = (data && data.counts_kitchen_by_dow) ? data.counts_kitchen_by_dow : {};
        const daysByDow = (data && data.days_by_dow) ? data.days_by_dow : {};
        const daysTotal = Number((data && data.days_total) ? data.days_total : 0) || 0;

        // Легенду рисуем инлайном в подзаголовке «Источник: Poster ...» —
        // не отбираем место в сетке графиков.
        updateLegendInline(isDishes);

        dows.forEach((d) => {
            const dCnt = Number(daysByDow && daysByDow[d.key] ? daysByDow[d.key] : 0) || 0;
            const perDay        = perDayAverages(counts[d.key],        dCnt);
            const perDayBar     = perDayAverages(countsBar[d.key],     dCnt);
            const perDayKitchen = perDayAverages(countsKitchen[d.key], dCnt);

            let dayAvgTotal = 0;
            hours.forEach((h) => { dayAvgTotal += Number(perDay[String(h)] || 0) || 0; });
            const dayAvgTxt = (Math.round(dayAvgTotal * 10) / 10).toFixed(1).replace(/\.0$/, '');
            const unit = isDishes ? 'блюд' : 'чек';
            const meta = '09:00 — 24:00 · ср/день: ' + dayAvgTxt + ' ' + unit + (dCnt > 0 ? (' · ' + String(dCnt) + ' дн') : '');
            const { wrap, canvas } = makeCanvasCard(d.name, meta);
            chartsEl.appendChild(wrap);
            renderOneChart(canvas, isDishes, perDay, perDayBar, perDayKitchen, true);
        });

        // «Среднее» — сумма всех dow поделённая на daysTotal.
        const avgAll        = avgAcrossDows(counts,        daysTotal);
        const avgAllBar     = avgAcrossDows(countsBar,     daysTotal);
        const avgAllKitchen = avgAcrossDows(countsKitchen, daysTotal);
        let avgAllTotal = 0;
        hours.forEach((h) => { avgAllTotal += Number(avgAll[String(h)] || 0) || 0; });
        const avgAllTxt = (Math.round(avgAllTotal * 10) / 10).toFixed(1).replace(/\.0$/, '');
        const unit = isDishes ? 'блюд' : 'чек';
        const meta = '09:00 — 24:00 · ср/день: ' + avgAllTxt + ' ' + unit;
        const { wrap, canvas } = makeCanvasCard('Среднее', meta);
        chartsEl.appendChild(wrap);
        renderOneChart(canvas, isDishes, avgAll, avgAllBar, avgAllKitchen, true);
    };

    // Усреднение counts_*_by_dow в "среднее по часу за весь период".
    const avgAcrossDows = (countsByDow, daysTotal) => {
        const out = {};
        hours.forEach((h) => {
            const hk = String(h);
            let sum = 0;
            dows.forEach((d) => {
                const v = countsByDow && countsByDow[d.key] ? Number(countsByDow[d.key][hk] || 0) : 0;
                if (isFinite(v)) sum += v;
            });
            out[hk] = daysTotal > 0 ? (sum / daysTotal) : 0;
        });
        return out;
    };

    /**
     * isDishes=true ⇒ две серии: кухня + бар (без общей).
     * isDishes=false ⇒ одна серия (чеки нельзя разделить по цехам).
     *
     * Сохраняет данные в canvas._z и подключает hover-обработчик чтобы
     * наведение мыши показывало значения за конкретный час.
     */
    const renderOneChart = (canvas, isDishes, totalCounts, barCounts, kitchenCounts, isAvgChart) => {
        const series = isDishes
            ? [
                { label: 'Кухня', color: SERIES_COLORS.kitchen, counts: kitchenCounts },
                { label: 'Бар',   color: SERIES_COLORS.bar,     counts: barCounts     },
              ]
            : [
                { label: 'Чеки', color: SERIES_COLORS.checks, counts: totalCounts },
              ];
        canvas._z = { hours, series, chartType, isAvgChart };
        drawCanvasFromZ(canvas, -1);
        setupCanvasHover(canvas);
    };

    const setProgress = (done, total, text) => {
        if (!prog || !progFill || !progPct || !progText) return;
        const pct = total > 0 ? Math.round((done / total) * 100) : 0;
        prog.classList.add('on');
        progFill.style.width = String(Math.max(0, Math.min(100, pct))) + '%';
        progPct.textContent = String(pct) + '%';
        progText.textContent = String(text || '');
    };
    const hideProgress = () => {
        if (!prog) return;
        prog.classList.remove('on');
    };

    const parseYmd = (s) => {
        const m = String(s || '').trim().match(/^(\d{4})-(\d{2})-(\d{2})$/);
        if (!m) return null;
        return { y: Number(m[1]), mo: Number(m[2]), d: Number(m[3]) };
    };
    const fmtYmd = (dt) => {
        const y = dt.getFullYear();
        const m = String(dt.getMonth() + 1).padStart(2, '0');
        const d = String(dt.getDate()).padStart(2, '0');
        return String(y) + '-' + m + '-' + d;
    };
    const buildDateList = (fromStr, toStr) => {
        const a = parseYmd(fromStr);
        const b = parseYmd(toStr);
        if (!a || !b) return [];
        const from = new Date(a.y, a.mo - 1, a.d, 12, 0, 0);
        const to = new Date(b.y, b.mo - 1, b.d, 12, 0, 0);
        if (!(from instanceof Date) || !(to instanceof Date) || !isFinite(from.getTime()) || !isFinite(to.getTime())) return [];
        if (from.getTime() > to.getTime()) return [];
        const out = [];
        for (let cur = new Date(from.getTime()); cur.getTime() <= to.getTime(); cur.setDate(cur.getDate() + 1)) {
            out.push(fmtYmd(cur));
            if (out.length > 366) break;
        }
        return out;
    };

    const load = async () => {
        const df = String(dateFromEl.value || '').trim();
        const dt = String(dateToEl.value || '').trim();
        const dates = buildDateList(df, dt);
        if (!df || !dt || dates.length === 0) return;
        if (dates.length > 366) { alert('Слишком большой диапазон'); return; }
        loadBtn.disabled = true;
        clearCharts();
        chartsEl.innerHTML = '<div class="card muted" style="display:flex; align-items:center; justify-content:center; min-height: 120px;">Загрузка…</div>';
        try {
            const countsChecks  = {};
            const countsDishes  = {};
            const countsBar     = {};
            const countsKitchen = {};
            for (let dow = 1; dow <= 7; dow++) {
                countsChecks[String(dow)]  = {};
                countsDishes[String(dow)]  = {};
                countsBar[String(dow)]     = {};
                countsKitchen[String(dow)] = {};
                hours.forEach((h) => {
                    countsChecks[String(dow)][String(h)]  = 0;
                    countsDishes[String(dow)][String(h)]  = 0;
                    countsBar[String(dow)][String(h)]     = 0;
                    countsKitchen[String(dow)][String(h)] = 0;
                });
            }
            const daysByDow = {};
            for (let dow = 1; dow <= 7; dow++) daysByDow[String(dow)] = 0;
            let daysTotal = 0;

            const errors = [];
            let done = 0;
            setProgress(0, dates.length, 'Подготовка…');

            // Параллелим запросы максимально: для типичного 14-дневного диапазона
            // все 14 ajax-вызовов летят одновременно. Капим на 30 — это
            // защита от перегруза браузером/Cloudflare на годовых диапазонах
            // (HTTP/2 хорошо мультиплексирует, но 365 параллельных fetch
            // могут упереться в браузерные лимиты соединений).
            const concurrency = Math.min(dates.length, 30);
            let idx = 0;

            const runOne = async (date) => {
                const url = new URL('/zapara/', location.origin);
                url.searchParams.set('ajax', 'day');
                url.searchParams.set('date', date);
                const res = await fetch(url.toString(), { headers: { 'Accept': 'application/json' } });
                const txt = await res.text();
                let j = null;
                try { j = JSON.parse(txt); } catch (_) {}
                if (!j || !j.ok) {
                    // Если сервер вернул не-JSON (например HTML с ошибкой Slim),
                    // показываем первые ~200 символов тела вместо обобщённого «(500)»,
                    // чтобы причина была видна в alert клиенту.
                    const reason = (j && j.error)
                        ? j.error
                        : (txt
                            ? txt.replace(/<[^>]+>/g, '').trim().slice(0, 200)
                            : ('Ошибка (' + String(res.status) + ')'));
                    throw new Error(reason);
                }
                const dow = String(j.dow || '');
                const byHourChecks  = j.counts_by_hour_checks  || {};
                const byHourDishes  = j.counts_by_hour_dishes  || {};
                const byHourBar     = j.counts_by_hour_bar     || {};
                const byHourKitchen = j.counts_by_hour_kitchen || {};
                if (!countsChecks[dow]) return;
                daysByDow[dow] = (Number(daysByDow[dow] || 0) || 0) + 1;
                daysTotal += 1;
                hours.forEach((h) => {
                    const hk = String(h);
                    countsChecks[dow][hk]  += Number(byHourChecks[hk]  || 0) || 0;
                    countsDishes[dow][hk]  += Number(byHourDishes[hk]  || 0) || 0;
                    countsBar[dow][hk]     += Number(byHourBar[hk]     || 0) || 0;
                    countsKitchen[dow][hk] += Number(byHourKitchen[hk] || 0) || 0;
                });

            };

            const workers = Array.from({ length: concurrency }, async () => {
                while (true) {
                    const my = idx;
                    idx += 1;
                    if (my >= dates.length) break;
                    const date = dates[my];
                    setProgress(done, dates.length, 'Запросы Poster: ' + String(done) + '/' + String(dates.length));
                    try {
                        await runOne(date);
                    } catch (e) {
                        errors.push({ date, error: String(e && e.message ? e.message : e) });
                    } finally {
                        done += 1;
                        setProgress(done, dates.length, 'Запросы Poster: ' + String(done) + '/' + String(dates.length));
                    }
                }
            });

            await Promise.all(workers);
            hideProgress();
            lastData = {
                counts_checks_by_dow:  countsChecks,
                counts_dishes_by_dow:  countsDishes,
                counts_bar_by_dow:     countsBar,
                counts_kitchen_by_dow: countsKitchen,
                days_by_dow:           daysByDow,
                days_total:            daysTotal,
            };
            render(lastData);
            if (errors.length) {
                const head = errors.slice(0, 3).map((x) => x.date + ': ' + x.error).join('\n');
                alert('Ошибки загрузки: ' + String(errors.length) + '\n' + head);
            }
        } catch (e) {
            hideProgress();
            alert(e && e.message ? e.message : 'Ошибка');
        } finally {
            loadBtn.disabled = false;
        }
    };

    loadBtn.addEventListener('click', load);
})();
