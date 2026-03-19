 (function () {
   function pad2(n) {
     return String(n).padStart(2, '0');
   }
 
   function isoFromDate(d) {
     return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate());
   }
 
   function dateFromIso(iso) {
     if (!iso) return null;
     var parts = String(iso).split('-');
     if (parts.length !== 3) return null;
     var y = parseInt(parts[0], 10);
     var m = parseInt(parts[1], 10);
     var da = parseInt(parts[2], 10);
     if (!y || !m || !da) return null;
     return new Date(y, m - 1, da);
   }
 
   function ruFromDate(d) {
     return pad2(d.getDate()) + '.' + pad2(d.getMonth() + 1) + '.' + d.getFullYear();
   }
 
   function clampToDay(d) {
     return new Date(d.getFullYear(), d.getMonth(), d.getDate());
   }
 
   function sameDay(a, b) {
     return a && b && a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();
   }
 
   function compareDay(a, b) {
     var ta = a.getTime();
     var tb = b.getTime();
     if (ta < tb) return -1;
     if (ta > tb) return 1;
     return 0;
   }
 
   function withinInclusive(d, start, end) {
     if (!start || !end) return false;
     var t = d.getTime();
     return t >= start.getTime() && t <= end.getTime();
   }
 
   function DateRangeDialog(root) {
     this.root = root;
     this.display = root.querySelector('.dp-display');
     this.openBtn = root.querySelector('.dp-open');
     this.fromInput = document.getElementById(root.getAttribute('data-from-input'));
     this.toInput = document.getElementById(root.getAttribute('data-to-input'));
     this.overlay = root.querySelector('[data-dp-overlay]');
     this.dialog = root.querySelector('[data-dp-dialog]');
     this.monthLabel = root.querySelector('.dp-month-year');
     this.prevBtn = root.querySelector('.dp-prev-month');
     this.nextBtn = root.querySelector('.dp-next-month');
     this.gridBody = root.querySelector('.dp-grid tbody');
     this.hint = root.querySelector('.dp-hint');
     this.cancelBtn = root.querySelector('.dp-cancel');
     this.okBtn = root.querySelector('.dp-ok');
 
     this.viewDate = clampToDay(new Date());
     this.rangeStart = null;
     this.rangeEnd = null;
     this.beforeOpen = null;
     this.activeCellIso = null;
     this.lastFocus = null;
 
     this.init();
   }
 
   DateRangeDialog.prototype.init = function () {
     var self = this;
 
     if (this.fromInput && this.toInput) {
       var s = clampToDay(dateFromIso(this.fromInput.value) || new Date());
       var e = clampToDay(dateFromIso(this.toInput.value) || s);
       this.rangeStart = s;
       this.rangeEnd = e;
       this.viewDate = clampToDay(s);
       this.updateDisplay();
     }
 
     if (this.display) {
       this.display.addEventListener('click', function () { self.open(); });
       this.display.addEventListener('keydown', function (e) {
         if (e.key === 'Enter' || e.key === ' ') {
           e.preventDefault();
           self.open();
         }
       });
     }
     if (this.openBtn) {
       this.openBtn.addEventListener('click', function () { self.open(); });
     }
 
     this.overlay.addEventListener('click', function () { self.close(false); });
 
     this.cancelBtn.addEventListener('click', function () { self.close(false); });
     this.okBtn.addEventListener('click', function () { self.applyAndClose(); });
 
     this.prevBtn.addEventListener('click', function () {
       self.viewDate = new Date(self.viewDate.getFullYear(), self.viewDate.getMonth() - 1, 1);
       self.render();
       self.focusBestCell();
     });
     this.nextBtn.addEventListener('click', function () {
       self.viewDate = new Date(self.viewDate.getFullYear(), self.viewDate.getMonth() + 1, 1);
       self.render();
       self.focusBestCell();
     });
 
     this.dialog.addEventListener('keydown', function (e) { self.onDialogKeydown(e); });
   };
 
   DateRangeDialog.prototype.updateDisplay = function () {
     if (!this.display) return;
     var s = this.rangeStart;
     var e = this.rangeEnd;
     if (!s && !e) {
       this.display.value = '';
       return;
     }
     if (s && !e) {
       this.display.value = ruFromDate(s) + ' — …';
       return;
     }
     if (s && e) {
       this.display.value = ruFromDate(s) + ' — ' + ruFromDate(e);
     }
   };
 
   DateRangeDialog.prototype.open = function () {
     this.beforeOpen = {
       from: this.fromInput ? this.fromInput.value : '',
       to: this.toInput ? this.toInput.value : '',
       start: this.rangeStart ? isoFromDate(this.rangeStart) : '',
       end: this.rangeEnd ? isoFromDate(this.rangeEnd) : ''
     };
 
     var s = dateFromIso(this.beforeOpen.from);
     var e = dateFromIso(this.beforeOpen.to);
     if (s) this.rangeStart = clampToDay(s);
     if (e) this.rangeEnd = clampToDay(e);
     if (this.rangeStart) this.viewDate = new Date(this.rangeStart.getFullYear(), this.rangeStart.getMonth(), 1);
 
     this.overlay.hidden = false;
     this.dialog.hidden = false;
     document.body.style.overflow = 'hidden';
     this.render();
     this.focusBestCell();
   };
 
   DateRangeDialog.prototype.close = function (keepChanges) {
     if (!keepChanges && this.beforeOpen) {
       if (this.fromInput) this.fromInput.value = this.beforeOpen.from;
       if (this.toInput) this.toInput.value = this.beforeOpen.to;
       var s = dateFromIso(this.beforeOpen.start);
       var e = dateFromIso(this.beforeOpen.end);
       this.rangeStart = s ? clampToDay(s) : null;
       this.rangeEnd = e ? clampToDay(e) : null;
       this.updateDisplay();
     }
 
     this.overlay.hidden = true;
     this.dialog.hidden = true;
     document.body.style.overflow = '';
     if (this.display) this.display.focus();
   };
 
   DateRangeDialog.prototype.applyAndClose = function () {
     if (!this.rangeStart) {
       this.close(false);
       return;
     }
     if (!this.rangeEnd) this.rangeEnd = this.rangeStart;
     var a = this.rangeStart;
     var b = this.rangeEnd;
     if (compareDay(a, b) > 0) {
       this.rangeStart = b;
       this.rangeEnd = a;
     }
     if (this.fromInput) this.fromInput.value = isoFromDate(this.rangeStart);
     if (this.toInput) this.toInput.value = isoFromDate(this.rangeEnd);
     this.updateDisplay();
     this.close(true);
   };
 
   DateRangeDialog.prototype.onDialogKeydown = function (e) {
     if (e.key === 'Escape') {
       e.preventDefault();
       this.close(false);
       return;
     }
 
     var focusable = this.dialog.querySelectorAll('button, [tabindex="0"]');
     if (e.key === 'Tab') {
       if (!focusable.length) return;
       var first = focusable[0];
       var last = focusable[focusable.length - 1];
       if (e.shiftKey && document.activeElement === first) {
         e.preventDefault();
         last.focus();
       } else if (!e.shiftKey && document.activeElement === last) {
         e.preventDefault();
         first.focus();
       }
       return;
     }
 
     if (document.activeElement && document.activeElement.classList.contains('dp-cell')) {
       this.onGridKeydown(e);
     }
   };
 
   DateRangeDialog.prototype.onGridKeydown = function (e) {
     var iso = this.activeCellIso;
     var current = dateFromIso(iso);
     if (!current) return;
 
     var next = null;
     if (e.key === 'ArrowLeft') next = new Date(current.getFullYear(), current.getMonth(), current.getDate() - 1);
     if (e.key === 'ArrowRight') next = new Date(current.getFullYear(), current.getMonth(), current.getDate() + 1);
     if (e.key === 'ArrowUp') next = new Date(current.getFullYear(), current.getMonth(), current.getDate() - 7);
     if (e.key === 'ArrowDown') next = new Date(current.getFullYear(), current.getMonth(), current.getDate() + 7);
     if (e.key === 'Home') next = new Date(current.getFullYear(), current.getMonth(), current.getDate() - ((current.getDay() + 6) % 7));
     if (e.key === 'End') next = new Date(current.getFullYear(), current.getMonth(), current.getDate() + (6 - ((current.getDay() + 6) % 7)));
 
     if (next) {
       e.preventDefault();
       this.focusDate(next);
       return;
     }
 
     if (e.key === 'Enter' || e.key === ' ') {
       e.preventDefault();
       this.selectDate(current);
     }
   };
 
   DateRangeDialog.prototype.focusDate = function (d) {
     var target = clampToDay(d);
     if (target.getFullYear() !== this.viewDate.getFullYear() || target.getMonth() !== this.viewDate.getMonth()) {
       this.viewDate = new Date(target.getFullYear(), target.getMonth(), 1);
       this.render();
     }
     var iso = isoFromDate(target);
     var btn = this.dialog.querySelector('.dp-cell[data-iso="' + iso + '"]');
     if (!btn || btn.disabled) {
       this.focusBestCell();
       return;
     }
     this.setActiveCell(btn);
     btn.focus();
   };
 
   DateRangeDialog.prototype.focusBestCell = function () {
     var preferred = this.rangeEnd || this.rangeStart || clampToDay(new Date());
     this.focusDate(preferred);
   };
 
   DateRangeDialog.prototype.setActiveCell = function (btn) {
     var prev = this.dialog.querySelector('.dp-cell[tabindex="0"]');
     if (prev && prev !== btn) prev.tabIndex = -1;
     btn.tabIndex = 0;
     this.activeCellIso = btn.getAttribute('data-iso');
   };
 
   DateRangeDialog.prototype.selectDate = function (d) {
     var picked = clampToDay(d);
     if (!this.rangeStart || (this.rangeStart && this.rangeEnd)) {
       this.rangeStart = picked;
       this.rangeEnd = null;
       this.hint.textContent = 'Выберите конец периода';
       this.render();
       this.focusDate(picked);
       return;
     }
     this.rangeEnd = picked;
     this.applyAndClose();
   };
 
   DateRangeDialog.prototype.render = function () {
     var months = ['Январь','Февраль','Март','Апрель','Май','Июнь','Июль','Август','Сентябрь','Октябрь','Ноябрь','Декабрь'];
     var weekdays = ['Пн','Вт','Ср','Чт','Пт','Сб','Вс'];
 
     this.monthLabel.textContent = months[this.viewDate.getMonth()] + ' ' + this.viewDate.getFullYear();
     if (!this.rangeStart) this.hint.textContent = 'Выберите начало периода';
     else if (this.rangeStart && !this.rangeEnd) this.hint.textContent = 'Выберите конец периода';
     else this.hint.textContent = '';
 
     var first = new Date(this.viewDate.getFullYear(), this.viewDate.getMonth(), 1);
     var startWeekday = (first.getDay() + 6) % 7;
     var startDate = new Date(first.getFullYear(), first.getMonth(), 1 - startWeekday);
 
     while (this.gridBody.firstChild) this.gridBody.removeChild(this.gridBody.firstChild);
 
     var rows = 6;
     var cols = 7;
     var cursor = startDate;
 
     for (var r = 0; r < rows; r++) {
       var tr = document.createElement('tr');
       for (var c = 0; c < cols; c++) {
         var td = document.createElement('td');
         var btn = document.createElement('button');
         btn.type = 'button';
         btn.className = 'dp-cell';
         btn.tabIndex = -1;
 
         var cellDate = clampToDay(cursor);
         var inMonth = cellDate.getMonth() === this.viewDate.getMonth();
         btn.textContent = String(cellDate.getDate());
         btn.setAttribute('data-iso', isoFromDate(cellDate));
         btn.setAttribute('aria-label', ruFromDate(cellDate));
         if (!inMonth) {
           btn.disabled = true;
         }
 
         var s = this.rangeStart;
         var e = this.rangeEnd;
         if (s && e) {
           var a = compareDay(s, e) <= 0 ? s : e;
           var b = compareDay(s, e) <= 0 ? e : s;
           if (withinInclusive(cellDate, a, b)) btn.classList.add('in-range');
           if (sameDay(cellDate, a)) btn.classList.add('range-start');
           if (sameDay(cellDate, b)) btn.classList.add('range-end');
         } else if (s && sameDay(cellDate, s)) {
           btn.classList.add('range-start');
         }
 
         var self = this;
         btn.addEventListener('click', (function (d) {
           return function () { if (!this.disabled) self.selectDate(d); };
         })(cellDate));
 
         btn.addEventListener('focus', function () { self.setActiveCell(this); });
 
         td.appendChild(btn);
         tr.appendChild(td);
 
         cursor = new Date(cursor.getFullYear(), cursor.getMonth(), cursor.getDate() + 1);
       }
       this.gridBody.appendChild(tr);
     }
 
     var headerRow = this.root.querySelector('.dp-grid thead tr');
     if (headerRow && headerRow.children.length === 0) {
       for (var w = 0; w < weekdays.length; w++) {
         var th = document.createElement('th');
         th.scope = 'col';
         th.textContent = weekdays[w];
         headerRow.appendChild(th);
       }
     }
   };
 
   function initAll() {
     var nodes = document.querySelectorAll('[data-date-range-picker]');
     for (var i = 0; i < nodes.length; i++) {
       new DateRangeDialog(nodes[i]);
     }
   }
 
   if (document.readyState === 'loading') {
     document.addEventListener('DOMContentLoaded', initAll);
   } else {
     initAll();
   }
 })();
