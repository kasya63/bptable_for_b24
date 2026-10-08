/* local.bptable — редактор таблицы распределения для заданий БП */
(function (w, d) {
	'use strict';
	if (w.LocalBpTable) { return; }

	var NBSP = '\u00A0';

	/* ---------- утилиты ---------- */

	function el(tag, cls, text) {
		var e = d.createElement(tag);
		if (cls) { e.className = cls; }
		if (text !== undefined && text !== null) { e.textContent = text; }
		return e;
	}

	function debounce(fn, ms) {
		var t;
		return function () {
			var a = arguments, self = this;
			clearTimeout(t);
			t = setTimeout(function () { fn.apply(self, a); }, ms);
		};
	}

	/* Деньги: строка "150000.00", считаем в тиынах. Логика = Money.php */
	function parseMoney(raw) {
		if (raw === null || raw === undefined) { return null; }
		if (typeof raw === 'number') { return isFinite(raw) ? fromCents(Math.round(raw * 100)) : null; }
		var s = String(raw).replace(/[\s\u00A0\u202F'₸]/g, '').replace(/KZT|тг|тенге/gi, '');
		if (s === '') { return null; }
		var lc = s.lastIndexOf(','), ld = s.lastIndexOf('.');
		if (lc !== -1 && ld !== -1) {
			s = lc > ld ? s.replace(/\./g, '').replace(',', '.') : s.replace(/,/g, '');
		} else if (lc !== -1 || ld !== -1) {
			var sep = lc !== -1 ? ',' : '.';
			var thousands = new RegExp('^-?\\d{1,3}(\\' + sep + '\\d{3})+$');
			s = thousands.test(s) ? s.split(sep).join('') : s.replace(',', '.');
		}
		var m = /^(-?)(\d+)(?:\.(\d+))?$/.exec(s);
		if (!m) { return null; }
		var frac = (m[3] || '') + '000';
		var cents = parseInt(m[2], 10) * 100 + parseInt(frac.substr(0, 2), 10);
		if (parseInt(frac.charAt(2), 10) >= 5) { cents++; }
		return fromCents(m[1] === '-' ? -cents : cents);
	}

	function toCents(norm) {
		var m = /^(-?)(\d+)\.(\d{2})$/.exec(norm || '');
		if (!m) { return 0; }
		var c = parseInt(m[2], 10) * 100 + parseInt(m[3], 10);
		return m[1] === '-' ? -c : c;
	}

	function fromCents(c) {
		var neg = c < 0, a = Math.abs(c);
		var f = String(a % 100);
		return (neg ? '-' : '') + Math.floor(a / 100) + '.' + (f.length < 2 ? '0' + f : f);
	}

	function fmtMoney(norm) {
		if (!norm) { return ''; }
		var p = norm.split('.');
		var neg = p[0].charAt(0) === '-';
		var i = p[0].replace('-', '').replace(/\B(?=(\d{3})+(?!\d))/g, NBSP);
		return (neg ? '-' : '') + i + ',' + (p[1] || '00');
	}

	/* Период: только дата, время всегда 23:59:59. Логика = Period.php */
	function parsePeriod(raw, tz) {
		if (raw === null || raw === undefined) { return null; }
		var s = String(raw).trim();
		if (s === '') { return null; }
		var y, mo, dd, m;
		if ((m = /^(\d{4})-(\d{2})-(\d{2})/.exec(s))) {
			y = +m[1]; mo = +m[2]; dd = +m[3];
		} else if ((m = /^(\d{1,2})[.\/](\d{1,2})[.\/](\d{4})/.exec(s))) {
			y = +m[3]; mo = +m[2]; dd = +m[1];
		} else if (/^\d{5}$/.test(s)) {
			var dt = new Date(Date.UTC(1899, 11, 30) + parseInt(s, 10) * 86400000);
			y = dt.getUTCFullYear(); mo = dt.getUTCMonth() + 1; dd = dt.getUTCDate();
		} else {
			return null;
		}
		var chk = new Date(Date.UTC(y, mo - 1, dd));
		if (chk.getUTCFullYear() !== y || chk.getUTCMonth() !== mo - 1 || chk.getUTCDate() !== dd) { return null; }
		return y + '-' + pad(mo) + '-' + pad(dd) + 'T23:59:59' + tz;
	}

	function pad(n) { return n < 10 ? '0' + n : String(n); }

	function periodToInput(iso) {
		var m = /^(\d{4}-\d{2}-\d{2})/.exec(iso || '');
		return m ? m[1] : '';
	}

	/* ---------- AJAX ---------- */

	function search(ref, q) {
		var data = { ref: ref, q: q };
		if (w.BX && BX.ajax && BX.ajax.runAction) {
			return BX.ajax.runAction('local:bptable.search.find', { data: data })
				.then(function (r) { return (r.data && r.data.items) || []; },
					function () { return []; });
		}
		var body = new URLSearchParams(data);
		if (w.BX && BX.bitrix_sessid) { body.append('sessid', BX.bitrix_sessid()); }
		return fetch('/bitrix/services/main/ajax.php?action=local:bptable.search.find', {
			method: 'POST', body: body, credentials: 'same-origin'
		}).then(function (r) { return r.json(); })
			.then(function (r) { return (r.data && r.data.items) || []; })
			.catch(function () { return []; });
	}

	/* ---------- выпадающий список (один на документ) ---------- */

	var Drop = {
		box: null, items: [], idx: -1, onPick: null, anchor: null,
		ensure: function () {
			if (this.box) { return; }
			this.box = el('div', 'lbpt-drop');
			this.box.style.display = 'none';
			d.body.appendChild(this.box);
			var self = this;
			d.addEventListener('mousedown', function (e) {
				if (self.box.style.display !== 'none' && !self.box.contains(e.target) && e.target !== self.anchor) { self.hide(); }
			}, true);
			w.addEventListener('scroll', function () { self.place(); }, true);
			w.addEventListener('resize', function () { self.place(); });
		},
		show: function (anchor, items, onPick, loading) {
			this.ensure();
			this.anchor = anchor; this.items = items; this.onPick = onPick; this.idx = -1;
			var box = this.box, self = this;
			box.textContent = '';
			if (loading) {
				box.appendChild(el('div', 'lbpt-drop__empty', 'Поиск…'));
			} else if (!items.length) {
				box.appendChild(el('div', 'lbpt-drop__empty', 'Ничего не найдено'));
			}
			items.forEach(function (it, i) {
				var row = el('div', 'lbpt-drop__item');
				row.appendChild(el('span', 'lbpt-drop__title', it.title));
				var hint = it.code ? ('код ' + it.code) : (it.hint || '');
				if (hint) { row.appendChild(el('span', 'lbpt-drop__hint', hint)); }
				row.addEventListener('mousedown', function (e) { e.preventDefault(); self.pick(i); });
				box.appendChild(row);
			});
			box.style.display = '';
			this.place();
		},
		place: function () {
			if (!this.box || this.box.style.display === 'none' || !this.anchor) { return; }
			var r = this.anchor.getBoundingClientRect();
			this.box.style.left = (r.left + w.pageXOffset) + 'px';
			this.box.style.top = (r.bottom + w.pageYOffset + 2) + 'px';
			this.box.style.minWidth = Math.max(r.width, 260) + 'px';
		},
		move: function (delta) {
			if (!this.items.length) { return; }
			this.idx = (this.idx + delta + this.items.length) % this.items.length;
			var nodes = this.box.querySelectorAll('.lbpt-drop__item');
			for (var i = 0; i < nodes.length; i++) { nodes[i].classList.toggle('is-active', i === this.idx); }
		},
		pick: function (i) {
			var it = this.items[i];
			var cb = this.onPick;
			this.hide();
			if (it && cb) { cb(it); }
		},
		hide: function () {
			if (this.box) { this.box.style.display = 'none'; }
			this.anchor = null; this.items = []; this.onPick = null;
		},
		isOpenFor: function (a) { return this.box && this.box.style.display !== 'none' && this.anchor === a; }
	};

	/* ---------- редактор ---------- */

	function Editor(root, cfg) {
		this.root = root;
		this.cfg = cfg;
		this.cols = cfg.columns;
		this.tz = cfg.tzOffset || '+05:00';
		this.rows = ((cfg.value && cfg.value.rows) || []).map(function (r) { return JSON.parse(JSON.stringify(r)); });
		this.hidden = root.querySelector('.lbpt-editor__value');
		this.build();
	}

	Editor.prototype.build = function () {
		var self = this;
		var fb = this.root.querySelector('.lbpt-editor__fallback');
		if (fb) { fb.parentNode.removeChild(fb); }

		var wrap = el('div', 'lbpt-ed__wrap');
		this.table = el('table', 'lbpt-ed__t');
		var thead = el('thead'), tr = el('tr');
		tr.appendChild(el('th', 'lbpt-ed__num', '#'));
		this.cols.forEach(function (c) {
			var th = el('th', c.type === 'amount' ? 'lbpt-ed__r' : '', c.title);
			if (c.required) { th.appendChild(el('span', 'lbpt-ed__star', '*')); }
			tr.appendChild(th);
		});
		tr.appendChild(el('th', 'lbpt-ed__act', ''));
		thead.appendChild(tr);
		this.tbody = el('tbody');
		var tfoot = el('tfoot'), ft = el('tr');
		var lbl = el('td', '', 'Итого');
		lbl.colSpan = this.cols.length;
		this.totalCell = el('td', 'lbpt-ed__r lbpt-ed__total', '');
		ft.appendChild(lbl); ft.appendChild(this.totalCell); ft.appendChild(el('td'));
		tfoot.appendChild(ft);
		this.table.appendChild(thead); this.table.appendChild(this.tbody); this.table.appendChild(tfoot);
		wrap.appendChild(this.table);

		var bar = el('div', 'lbpt-ed__bar');
		var add = el('button', 'ui-btn ui-btn-xs ui-btn-light-border', '+ Добавить строку');
		add.type = 'button';
		add.addEventListener('click', function () { self.addRow({}, true); });
		var clr = el('button', 'ui-btn ui-btn-xs ui-btn-link', 'Очистить');
		clr.type = 'button';
		clr.addEventListener('click', function () {
			if (!self.rows.length || w.confirm('Удалить все строки таблицы?')) {
				self.rows = []; self.renderRows(); self.sync();
			}
		});
		bar.appendChild(add);
		bar.appendChild(clr);
		this.errBox = el('div', 'lbpt-ed__errors');

		this.root.appendChild(wrap);
		this.root.appendChild(bar);
		this.root.appendChild(this.errBox);
		this.bindSubmitGuard();
		this.root.addEventListener('keydown', function (e) {
			if (e.key === 'Enter' && e.target.tagName === 'INPUT') { e.preventDefault(); }
		});

		if (!this.rows.length) { this.rows.push(this.emptyRow()); }
		this.renderRows();
		this.sync();
	};

	Editor.prototype.emptyRow = function () {
		var r = {};
		this.cols.forEach(function (c) { r[c.key] = null; });
		return r;
	};

	Editor.prototype.addRow = function (row, focus) {
		this.rows.push(Object.assign(this.emptyRow(), row || {}));
		this.renderRows();
		this.sync();
		if (focus) {
			var last = this.tbody.lastElementChild;
			var inp = last && last.querySelector('input');
			if (inp) { inp.focus(); }
		}
	};

	Editor.prototype.renderRows = function () {
		var self = this;
		this.tbody.textContent = '';
		this.rows.forEach(function (row, i) {
			var tr = el('tr');
			tr.appendChild(el('td', 'lbpt-ed__num', String(i + 1)));
			self.cols.forEach(function (c) { tr.appendChild(self.cell(row, c)); });
			var act = el('td', 'lbpt-ed__act');
			var del = el('button', 'lbpt-ed__del', '×');
			del.type = 'button';
			del.title = 'Удалить строку';
			del.addEventListener('click', function () {
				self.rows.splice(self.rows.indexOf(row), 1);
				self.renderRows(); self.sync();
			});
			act.appendChild(del);
			tr.appendChild(act);
			self.tbody.appendChild(tr);
		});
	};

	Editor.prototype.cell = function (row, col) {
		var self = this, td = el('td', 'lbpt-ed__c lbpt-ed__c--' + col.type), inp;

		if (col.type === 'period') {
			inp = el('input');
			inp.type = 'date';
			inp.value = periodToInput(row[col.key]);
			inp.addEventListener('change', function () {
				row[col.key] = parsePeriod(inp.value, self.tz);
				self.sync();
			});
		} else if (col.type === 'amount') {
			inp = el('input', 'lbpt-ed__r');
			inp.type = 'text';
			inp.inputMode = 'decimal';
			inp.value = fmtMoney(row[col.key]);
			inp.addEventListener('input', function () {
				row[col.key] = parseMoney(inp.value);
				inp.classList.toggle('is-bad', inp.value.trim() !== '' && row[col.key] === null);
				self.sync();
			});
			inp.addEventListener('blur', function () {
				if (row[col.key] !== null) { inp.value = fmtMoney(row[col.key]); }
			});
		} else {
			inp = this.refInput(row, col);
		}

		td.appendChild(inp);
		return td;
	};

	Editor.prototype.refInput = function (row, col) {
		var self = this;
		var ref = col.type === 'employee' ? 'employee' : col.key;
		var inp = el('input');
		inp.type = 'text';
		inp.autocomplete = 'off';
		inp.placeholder = col.type === 'employee' ? 'ФИО' : 'Поиск…';

		function paint() {
			var v = row[col.key];
			inp.value = v ? (v.title || ('#' + v.id)) : '';
			inp.title = v && v.code ? ('Код: ' + v.code) : '';
			inp.classList.toggle('is-linked', !!(v && v.id));
			inp.classList.toggle('is-miss', !!(v && !v.id && col.type === 'ref' && !col.required));
		}

		var seq = 0;
		var run = debounce(function () {
			var my = ++seq;
			Drop.show(inp, [], null, true);
			search(ref, inp.value).then(function (items) {
				if (my !== seq || d.activeElement !== inp) { return; }
				Drop.show(inp, items, function (it) {
					row[col.key] = col.type === 'employee'
						? { id: it.id, title: it.title }
						: { id: it.id, title: it.title, code: it.code || null };
					paint(); self.sync();
				});
			});
		}, 250);

		inp.addEventListener('focus', function () { run(); });
		inp.addEventListener('input', function () {
			var t = inp.value.trim();
			if (t === '') {
				row[col.key] = null;
			} else if (col.type === 'employee') {
				row[col.key] = { id: null, title: t };
			} else {
				row[col.key] = self.cfg.keepText ? { id: null, title: t, code: null } : null;
			}
			inp.classList.remove('is-linked');
			inp.classList.toggle('is-miss', col.type === 'ref' && !col.required && t !== '');
			self.sync();
			run();
		});
		inp.addEventListener('keydown', function (e) {
			if (!Drop.isOpenFor(inp)) { return; }
			if (e.key === 'ArrowDown') { e.preventDefault(); Drop.move(1); }
			else if (e.key === 'ArrowUp') { e.preventDefault(); Drop.move(-1); }
			else if (e.key === 'Enter' && Drop.idx >= 0) { e.preventDefault(); Drop.pick(Drop.idx); }
			else if (e.key === 'Escape') { Drop.hide(); }
		});
		inp.addEventListener('blur', function () {
			setTimeout(function () { if (Drop.isOpenFor(inp)) { Drop.hide(); } paint(); }, 150);
		});

		paint();
		return inp;
	};

	Editor.prototype.isEmpty = function (row) {
		return this.cols.every(function (c) { return row[c.key] === null || row[c.key] === undefined; });
	};

	Editor.prototype.flash = function (msg) {
		var n = el('div', 'lbpt-ed__flash', msg);
		this.root.appendChild(n);
		setTimeout(function () { if (n.parentNode) { n.parentNode.removeChild(n); } }, 5000);
	};

	/* Обязательные: ref — ID из справочника, сотрудник — ФИО, сумма — не ноль */
	Editor.prototype.cellOk = function (col, v) {
		if (!col.required) { return true; }
		if (col.type === 'ref') { return !!(v && v.id); }
		if (col.type === 'employee') { return !!(v && String(v.title || '').trim()); }
		if (col.type === 'amount') { return !!v && toCents(v) !== 0; }
		return v !== null && v !== undefined;
	};

	Editor.prototype.validate = function () {
		var self = this, errors = [], n = 0;
		var trs = this.tbody.children;
		this.rows.forEach(function (row, i) {
			var empty = self.isEmpty(row);
			if (!empty) { n++; }
			var missing = [];
			self.cols.forEach(function (c, ci) {
				var ok = empty || self.cellOk(c, row[c.key]);
				var td = trs[i] && trs[i].children[ci + 1];
				var inp = td && td.querySelector('input');
				if (inp) { inp.classList.toggle('is-req', !ok); }
				if (!ok) { missing.push(c.title); }
			});
			if (missing.length) { errors.push('Строка ' + (i + 1) + ': ' + missing.join(', ')); }
		});
		if (!n) { errors.unshift('Добавьте хотя бы одну строку.'); }
		this.errors = errors;
		this.errBox.textContent = '';
		if (errors.length) {
			this.errBox.appendChild(el('div', 'lbpt-ed__errors-title', 'Заполните обязательные поля (статья — из справочника):'));
			errors.slice(0, 10).forEach(function (t) { self.errBox.appendChild(el('div', '', t)); });
			if (errors.length > 10) { this.errBox.appendChild(el('div', '', '… и ещё ' + (errors.length - 10))); }
		}
		return !errors.length;
	};

	/* Блокируем «Сохранить», пока таблица не заполнена. «Отменить» и прочие кнопки с name=cancel не трогаем. */
	Editor.prototype.bindSubmitGuard = function () {
		var self = this;
		var form = this.root.closest('form');
		if (!form || form.__lbptGuard) { return; }
		form.__lbptGuard = true;
		var lastBtn = null;
		form.addEventListener('click', function (e) {
			var b = e.target.closest('button, input[type=submit]');
			if (b) { lastBtn = b; }
		}, true);
		form.addEventListener('submit', function (e) {
			var btn = e.submitter || lastBtn;
			var name = btn ? String(btn.name || '') : '';
			if (/cancel|reject|delegate/i.test(name)) { return; }
			var editors = form.querySelectorAll('.lbpt-editor');
			for (var i = 0; i < editors.length; i++) {
				var ed = editors[i].__lbpt;
				if (ed && !ed.validate()) {
					e.preventDefault();
					e.stopImmediatePropagation();
					if (ed.errBox.scrollIntoView) { ed.errBox.scrollIntoView({ block: 'center', behavior: 'smooth' }); }
					return;
				}
			}
		}, true);
	};

	Editor.prototype.sync = function () {
		var self = this;
		this.validate();
		var rows = this.rows.filter(function (r) { return !self.isEmpty(r); });
		var total = rows.reduce(function (s, r) { return s + toCents(r.amount); }, 0);
		this.totalCell.textContent = fmtMoney(fromCents(total));
		var val = rows.length ? JSON.stringify({ v: 1, preset: this.cfg.preset, rows: rows }) : '';
		if (this.hidden.value !== val) {
			this.hidden.value = val;
			var ev = d.createEvent('HTMLEvents');
			ev.initEvent('change', true, false);
			this.hidden.dispatchEvent(ev);
		}
	};

	/* ---------- стили ---------- */

	function injectCss() {
		if (d.getElementById('lbpt-css')) { return; }
		var s = el('style');
		s.id = 'lbpt-css';
		s.textContent = [
			'.lbpt-editor{position:relative;max-width:100%}',
			'.lbpt-ed__wrap{overflow-x:auto;max-width:100%;border:1px solid #dfe0e3;border-radius:6px}',
			'.lbpt-ed__t{border-collapse:collapse;width:100%;min-width:760px;font-size:13px}',
			'.lbpt-ed__t th{background:#f5f7f8;color:#6a737f;font-weight:normal;text-align:left;padding:6px 6px;border-bottom:1px solid #dfe0e3;white-space:nowrap}',
			'.lbpt-ed__t td{padding:3px 4px;border-bottom:1px solid #edeef0;vertical-align:middle}',
			'.lbpt-ed__t tfoot td{font-weight:bold;padding:6px;border-bottom:none}',
			'.lbpt-ed__t input{width:100%;box-sizing:border-box;height:30px;border:1px solid transparent;border-radius:4px;padding:0 6px;font:inherit;background:transparent}',
			'.lbpt-ed__t input:hover{border-color:#dfe0e3}',
			'.lbpt-ed__t input:focus{border-color:#2fc6f6;outline:none;background:#fff}',
			'.lbpt-ed__t input.is-linked{color:#1e70a7}',
			'.lbpt-ed__t input.is-miss{background:#fff5cc;color:#7a5b00}',
			'.lbpt-ed__t input.is-bad{border-color:#ff5752}',
			'.lbpt-ed__c--period{width:130px}.lbpt-ed__c--amount{width:120px}',
			'.lbpt-ed__num{width:24px;color:#a8adb4;text-align:right}',
			'.lbpt-ed__act{width:24px}',
			'.lbpt-ed__r{text-align:right!important}',
			'.lbpt-ed__del{border:0;background:none;color:#a8adb4;font-size:18px;cursor:pointer;line-height:1}',
			'.lbpt-ed__del:hover{color:#ff5752}',
			'.lbpt-ed__bar{display:flex;align-items:center;gap:10px;margin-top:8px;flex-wrap:wrap}',
			'.lbpt-ed__hint{font-size:12px;color:#a8adb4}',
			'.lbpt-ed__t input.is-req{border-color:#ff5752;background:#fff1f0}',
			'.lbpt-ed__star{color:#ff5752;margin-left:2px}',
			'.lbpt-ed__errors{margin-top:8px;font-size:12px;color:#c4302b}',
			'.lbpt-ed__errors-title{font-weight:bold;margin-bottom:2px}',
			'.lbpt-ed__flash{margin-top:6px;font-size:12px;color:#7a5b00;background:#fff5cc;padding:4px 8px;border-radius:4px;display:inline-block}',
			'.lbpt-drop{position:absolute;z-index:10000;background:#fff;border:1px solid #dfe0e3;border-radius:6px;box-shadow:0 4px 16px rgba(0,0,0,.12);max-height:280px;overflow-y:auto;font-size:13px}',
			'.lbpt-drop__item{padding:7px 10px;cursor:pointer;display:flex;justify-content:space-between;gap:12px}',
			'.lbpt-drop__item:hover,.lbpt-drop__item.is-active{background:#eef8fe}',
			'.lbpt-drop__hint{color:#a8adb4;white-space:nowrap}',
			'.lbpt-drop__empty{padding:7px 10px;color:#a8adb4}'
		].join('\n');
		(d.head || d.documentElement).appendChild(s);
	}

	/* ---------- публичное API ---------- */

	w.LocalBpTable = {
		init: function (id) {
			var root = d.getElementById(id);
			if (!root || root.__lbpt) { return; }
			var cfg;
			try { cfg = JSON.parse(root.getAttribute('data-lbpt-cfg')); } catch (e) { return; }
			injectCss();
			root.__lbpt = new Editor(root, cfg);
		},
		_test: { parseMoney: parseMoney, parsePeriod: parsePeriod, fmtMoney: fmtMoney, toCents: toCents }
	};
})(window, document);
