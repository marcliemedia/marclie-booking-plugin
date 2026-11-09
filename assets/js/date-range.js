/**
 * Marclie Booking — date-range picker core (window.mbk.dates).
 *
 * input[type=date][data-date-end="<end id>"] pairs become one range picker (popover, one month
 * < 768 px, two otherwise). Every picker/calendar of the same key (the start input id, or the
 * data-date-inline value of an inline calendar) shares one selection ("group"). With data-unit
 * the group loads GET /availability per visible months: occupied and closed nights (months without
 * a price) are struck through and cannot be a check-in, the stay may end on the first such night, the
 * minimum stay of the arrival date is enforced (a date without min_stay has no price = no check-in).
 * Changes dispatch `mbk:dates` on document: detail {key, unit, start, end, nights}.
 * Keyboard: Enter/Space/↓ opens; arrows day/week, PageUp/PageDown month, Home/End week; Esc closes.
 * DOM + classes = the mockup engine's date picker (wireframe.js initDateRange: .w-datepicker*,
 * .w-datepicker-input, .w-datepicker-field around inputs inside a .w-field).
 */
(function () {
	"use strict";

	const mbk = window.mbk;
	const { __, _n, _x, sprintf } = window.wp.i18n;
	const { $, $$, cfg } = mbk;

	const DL = cfg.locale || "hr-HR";
	const pad = n => String(n).padStart(2, "0");
	const iso = d => (d ? d.getFullYear() + "-" + pad(d.getMonth() + 1) + "-" + pad(d.getDate()) : "");
	const ym = d => d.getFullYear() + "-" + pad(d.getMonth() + 1);
	const parse = s => { const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(s || ""); return m ? new Date(+m[1], +m[2] - 1, +m[3]) : null; };
	const same = (a, b) => !!a && !!b && a.getTime() === b.getTime();
	const addDays = (d, n) => new Date(d.getFullYear(), d.getMonth(), d.getDate() + n);
	const addMonths = (d, n) => new Date(d.getFullYear(), d.getMonth() + n, 1);
	const monthStart = d => new Date(d.getFullYear(), d.getMonth(), 1);
	const nightsBetween = (a, b) => Math.round((b - a) / 864e5);
	const ucfirst = s => s.charAt(0).toLocaleUpperCase(DL) + s.slice(1);
	const fmt = d => (d ? d.toLocaleDateString(DL, { day: "numeric", month: "short", year: "numeric" }) : "");
	const fmtSeg = d => d.toLocaleDateString(DL, { weekday: "short", day: "numeric", month: "short" });
	const fmtLong = d => d.toLocaleDateString(DL, { weekday: "long", day: "numeric", month: "long", year: "numeric" });
	const monthTitle = new Intl.DateTimeFormat(DL, { month: "long", year: "numeric" });
	const today = mbk.today;
	const minView = monthStart(today);
	const weekdays = [];
	for (let i = 0; i < 7; i++) weekdays.push(new Date(2024, 0, 1 + i).toLocaleDateString(DL, { weekday: "short" }).slice(0, 2));

	const SVG = d => '<svg class="w-icon w-icon--small" viewBox="0 0 256 256" aria-hidden="true" focusable="false"><path d="' + d + '"/></svg>';
	const ICON_PREV = SVG("M165.66,202.34a8,8,0,0,1-11.32,11.32l-80-80a8,8,0,0,1,0-11.32l80-80a8,8,0,0,1,11.32,11.32L91.31,128Z");
	const ICON_NEXT = SVG("M181.66,133.66l-80,80a8,8,0,0,1-11.32-11.32L164.69,128,90.34,53.66a8,8,0,0,1,11.32-11.32l80,80A8,8,0,0,1,181.66,133.66Z");
	const ICON_CAL = SVG("M208,32H184V24a8,8,0,0,0-16,0v8H88V24a8,8,0,0,0-16,0v8H48A16,16,0,0,0,32,48V208a16,16,0,0,0,16,16H208a16,16,0,0,0,16-16V48A16,16,0,0,0,208,32ZM72,48v8a8,8,0,0,0,16,0V48h80v8a8,8,0,0,0,16,0V48h24V80H48V48ZM208,208H48V96H208V208Z");

	/* ------------------------------------------------------------ availability */
	const stores = new Map();
	function store(unit) {
		let s = stores.get(unit);
		if (!s) { s = { months: new Set(), pending: new Map(), occupied: new Set(), closed: new Set(), min: new Map() }; stores.set(unit, s); }
		return s;
	}
	/** Loads `count` months from `from` (once per unit+month); resolves when they are known. */
	function load(unit, from, count) {
		const s = store(unit), missing = [], waits = [];
		for (let i = 0; i < count; i++) {
			const m = ym(addMonths(from, i));
			if (s.pending.has(m)) waits.push(s.pending.get(m));
			else if (!s.months.has(m)) missing.push(addMonths(from, i));
		}
		if (missing.length) {
			const first = missing[0], last = missing[missing.length - 1];
			const n = (last.getFullYear() - first.getFullYear()) * 12 + last.getMonth() - first.getMonth() + 1;
			const p = mbk.api("availability", { params: { unit, from: ym(first), months: Math.min(12, n) } })
				.then(data => {
					(data.occupied || []).forEach(d => s.occupied.add(d));
					// closed nights block a stay exactly like occupied ones; only their label differs
					(data.closed || []).forEach(d => { s.occupied.add(d); s.closed.add(d); });
					Object.keys(data.min_stay || {}).forEach(d => s.min.set(d, +data.min_stay[d]));
					for (let i = 0; i < n; i++) s.months.add(ym(addMonths(first, i)));
				})
				// months past the booking calendar window (400 invalid_range): nothing to book there, stop loading
				// (other failures stay unknown: the days remain unselectable and are retried on the next render)
				.catch(err => { if (err && err.code === "invalid_range") for (let i = 0; i < n; i++) s.months.add(ym(addMonths(first, i))); })
				.finally(() => { for (let i = 0; i < n; i++) s.pending.delete(ym(addMonths(first, i))); });
			for (let i = 0; i < n; i++) s.pending.set(ym(addMonths(first, i)), p);
			waits.push(p);
		}
		return Promise.all(waits);
	}

	/* ------------------------------------------------------------------ groups */
	const groups = new Map();
	function group(key, unit) {
		let g = groups.get(key);
		if (!g) {
			g = { key, unit: 0, start: null, end: null, active: 0, hover: null, cals: [], pair: null };
			groups.set(key, g);
		}
		if (unit && !g.unit) g.unit = parseInt(unit, 10) || 0;
		return g;
	}
	const known = (g, d) => !g.unit || store(g.unit).months.has(ym(d));
	/** Every month from a to b (inclusive) is loaded. */
	function knownRange(g, a, b) {
		for (let m = monthStart(a); m <= b; m = addMonths(m, 1)) if (!known(g, m)) return false;
		return true;
	}
	/**
	 * Availability needed to judge d is still loading: as check-out, every month from the arrival
	 * to d (minimum stay + occupied nights); as check-in, d's month and the next one (a booking
	 * early next month limits the stay). Such days stay unselectable — no minimum-stay bypass.
	 */
	function pending(g, d) {
		if (!g.unit || d < today) return false;
		if (g.active === 1 && g.start && d > g.start) return !knownRange(g, g.start, d);
		return !known(g, d) || !known(g, addMonths(d, 1));
	}
	const isBooked = (g, d) => !!g.unit && store(g.unit).occupied.has(iso(d));
	/** Minimum nights when arriving on d; null = no rate (no check-in); 1 while unknown. */
	function minStay(g, d) {
		if (!g.unit || !known(g, d)) return 1;
		const n = store(g.unit).min.get(iso(d));
		return n === undefined ? null : Math.max(1, n);
	}
	function nextBooked(g, d) {
		if (!g.unit) return null;
		let best = null;
		store(g.unit).occupied.forEach(s => { const x = parse(s); if (x > d && (!best || x < best)) best = x; });
		return best;
	}
	function canArrive(g, d) {
		if (d < today || isBooked(g, d)) return false;
		const min = minStay(g, d);
		if (min === null) return false;
		const nb = nextBooked(g, d);
		return !nb || nightsBetween(d, nb) >= min;
	}
	function blocked(g, d) {
		if (d < today || pending(g, d)) return true;
		if (g.active === 1 && g.start && d > g.start) {
			const nb = nextBooked(g, g.start);
			if (nb && d > nb) return true;
			return nightsBetween(g.start, d) < (minStay(g, g.start) || 1);
		}
		return !canArrive(g, d);
	}
	const nights = g => (g.start && g.end ? nightsBetween(g.start, g.end) : 0);
	const rangeEnd = g => (g.active === 1 && g.start && g.hover && g.hover > g.start ? g.hover : g.end);
	const nightsText = n => sprintf(_n("%d night", "%d nights", n, "marclie-booking"), n);

	/** Status line: nights chosen, else the next step, with the arrival's minimum stay. */
	function summary(g) {
		const n = nights(g);
		if (n) return nightsText(n);
		if (g.active === 1 && g.start) {
			const min = minStay(g, g.start) || 1;
			return min > 1 ? sprintf(_n("Minimum %d night", "Minimum %d nights", min, "marclie-booking"), min) : __("Select your check-out date", "marclie-booking");
		}
		return __("Select your check-in date", "marclie-booking");
	}

	/* --------------------------------------------------------------- calendars */
	/**
	 * One calendar (popover or inline) of group g.
	 * @param {number} maxMonths inline: months shown when wide enough (1–2)
	 */
	function makeCal(g, root, inline, maxMonths) {
		const cal = { g, root, inline, view: minView, focus: today, shown: 0, max: maxMonths || 2 };
		root.classList.add("w-datepicker");
		if (inline) root.classList.add("w-datepicker--inline");
		root.innerHTML =
			(inline ? "" : '<div class="w-datepicker__head">' + [0, 1].map(k => '<button type="button" class="w-datepicker__segment" data-dp-seg="' + k +
				'"><span class="w-datepicker__segment-label"></span><span class="w-datepicker__segment-value"></span></button>').join("") + "</div>") +
			'<div class="w-datepicker__body"><div class="w-datepicker__nav"><button type="button" class="w-datepicker__nav-button w-datepicker__prev" data-dp-nav="-1" aria-label="' +
			__("Previous month", "marclie-booking") + '">' + ICON_PREV + '</button><button type="button" class="w-datepicker__nav-button w-datepicker__next" data-dp-nav="1" aria-label="' +
			__("Next month", "marclie-booking") + '">' + ICON_NEXT + '</button></div><div class="w-datepicker__months" data-dp-months></div></div>' +
			'<div class="w-datepicker__foot">' + (inline ? "" : '<p class="w-datepicker__summary" aria-live="polite" data-dp-summary></p>') +
			'<button type="button" class="w-datepicker__clear" data-dp-clear>' + _x("Clear dates", "guest booking flow button", "marclie-booking") + "</button>" +
			(inline ? "" : '<button type="button" class="w-button-primary w-button-primary--small" data-dp-done>' + __("Done", "marclie-booking") + "</button>") + "</div>";
		cal.months = () => Math.min(cal.max, inline ? (root.clientWidth >= 680 ? 2 : 1) : (window.matchMedia("(min-width: 768px)").matches ? 2 : 1));
		bindCal(cal);
		g.cals.push(cal);
		return cal;
	}

	function render(cal) {
		const hadFocus = !!document.activeElement && document.activeElement.hasAttribute("data-dp-date") && cal.root.contains(document.activeElement);
		cal.shown = cal.months();
		let html = "";
		for (let m = 0; m < cal.shown; m++) {
			const first = addMonths(cal.view, m);
			const days = new Date(first.getFullYear(), first.getMonth() + 1, 0).getDate();
			html += '<div class="w-datepicker__month"><p class="w-datepicker__title">' + ucfirst(monthTitle.format(first)) +
				'</p><div class="w-datepicker__grid">' + weekdays.map(w => '<span class="w-datepicker__weekday" aria-hidden="true">' + w + "</span>").join("") +
				'<span class="w-datepicker__blank"></span>'.repeat((first.getDay() + 6) % 7);
			for (let n = 1; n <= days; n++) {
				const d = new Date(first.getFullYear(), first.getMonth(), n);
				html += '<button type="button" class="w-datepicker__day" data-dp-date="' + iso(d) + '" tabindex="-1">' + n + "</button>";
			}
			html += "</div></div>";
		}
		$("[data-dp-months]", cal.root).innerHTML = html;
		$('[data-dp-nav="-1"]', cal.root).disabled = cal.view <= minView;
		paint(cal);
		if (hadFocus) focusDay(cal); // keyboard users keep focus when the months turn
		const g = cal.g;
		if (g.unit) {
			const shownKnown = c => { for (let m = 0; m < c.shown; m++) if (!known(g, addMonths(c.view, m))) return false; return true; };
			if (!shownKnown(cal)) {
				cal.root.classList.add("is-loading");
				cal.root.setAttribute("aria-busy", "true");
			}
			// shown months plus look-ahead: at least 3 from the first one on open, one past the last when navigating
			load(g.unit, cal.view, Math.max(3, cal.shown + 1)).then(() => g.cals.forEach(c => {
				if (shownKnown(c)) {
					c.root.classList.remove("is-loading");
					c.root.removeAttribute("aria-busy");
				}
				if (c.shown) paint(c);
			}));
		}
	}

	function paint(cal) {
		const g = cal.g, last = rangeEnd(g), preview = !same(last, g.end);
		$$("[data-dp-date]", cal.root).forEach(b => {
			const d = parse(b.getAttribute("data-dp-date"));
			const isStart = same(d, g.start), isEnd = same(d, last), booked = isBooked(g, d), wait = pending(g, d);
			// aria-disabled (not disabled) keeps every day focusable so arrow keys can cross blocked days
			b.setAttribute("aria-disabled", blocked(g, d) ? "true" : "false");
			b.classList.toggle("is-booked", booked);
			b.classList.toggle("is-pending", wait);
			b.tabIndex = same(d, cal.focus) ? 0 : -1;
			b.classList.toggle("is-today", same(d, today));
			b.classList.toggle("is-start", isStart);
			b.classList.toggle("is-end", isEnd);
			b.classList.toggle("is-range", !!(g.start && last && d > g.start && d < last));
			b.classList.toggle("has-range", !!(g.start && last) && (isStart || isEnd));
			b.classList.toggle("is-preview", preview && !!last);
			b.setAttribute("aria-pressed", same(d, g.start) || same(d, g.end) ? "true" : "false");
			const closed = booked && store(g.unit).closed.has(iso(d));
			b.setAttribute("aria-label", fmtLong(d) + (closed ? ", " + __("closed", "marclie-booking") : booked ? ", " + __("booked", "marclie-booking") : wait ? ", " + __("loading availability", "marclie-booking") : ""));
		});
		$$("[data-dp-seg]", cal.root).forEach((s, k) => {
			const v = k ? g.end : g.start, val = $(".w-datepicker__segment-value", s);
			s.classList.toggle("is-active", k === g.active);
			s.setAttribute("aria-pressed", k === g.active ? "true" : "false");
			$(".w-datepicker__segment-label", s).textContent = g.pair ? g.pair.labels[k] : "";
			val.textContent = v ? fmtSeg(v) : __("Add date", "marclie-booking");
			val.classList.toggle("is-empty", !v);
		});
		const sum = $("[data-dp-summary]", cal.root);
		if (sum) sum.textContent = summary(g);
		$("[data-dp-clear]", cal.root).disabled = !g.start && !g.end;
	}

	function focusDay(cal) {
		const b = $('[data-dp-date="' + iso(cal.focus) + '"]', cal.root);
		if (b) b.focus({ preventScroll: true });
	}

	/** Moves the keyboard focus to d, turning the months so it is visible. */
	function showDate(cal, d, moveFocus) {
		const lastShown = new Date(cal.view.getFullYear(), cal.view.getMonth() + cal.months(), 0);
		cal.focus = d < today ? today : d;
		if (cal.focus < cal.view || cal.focus > lastShown) {
			cal.view = cal.focus < cal.view ? monthStart(cal.focus) : addMonths(cal.focus, 1 - cal.months());
			if (cal.view < minView) cal.view = minView;
			render(cal);
		} else paint(cal);
		if (moveFocus !== false) focusDay(cal);
	}

	function update(g) {
		const p = g.pair;
		if (p) {
			p.start.hidden.value = iso(g.start);
			p.end.hidden.value = iso(g.end);
			p.start.el.value = fmt(g.start);
			p.end.el.value = fmt(g.end);
		}
		g.cals.forEach(c => { if (c.shown) paint(c); });
		document.dispatchEvent(new CustomEvent("mbk:dates", {
			detail: { key: g.key, unit: g.unit, start: iso(g.start), end: iso(g.end), nights: nights(g), picking: g.active === 1 }
		}));
	}

	/** @param {boolean} pointer chosen with the mouse/touch: the clicked day keeps the focus */
	function pick(cal, d, pointer) {
		const g = cal.g;
		if (cal.inline && g.start && g.end) g.end = null; // a full range on the open calendar: the next click starts over
		if (g.active === 0 || !g.start || d <= g.start) {
			g.start = d;
			if (g.end && (g.end <= g.start || blockedEnd(g, g.end))) g.end = null;
			g.active = 1;
			g.hover = null;
			if (g.unit) load(g.unit, monthStart(d), 3).then(() => g.cals.forEach(c => { if (c.shown) paint(c); }));
			update(g);
			// keyboard: focus jumps to the earliest check-out; pointer: it stays on the clicked day
			const next = g.end || addDays(d, minStay(g, d) || 1);
			if (!pointer) showDate(cal, next);
			else {
				showDate(cal, next, false);
				if ($('[data-dp-date="' + iso(d) + '"]', cal.root)) { cal.focus = d; paint(cal); }
				focusDay(cal);
			}
		} else {
			g.end = d;
			g.active = 0;
			g.hover = null;
			cal.focus = d; // no focus ring left on arrival + minimum stay
			update(g);
			if (cal.inline) focusDay(cal);
			else if (g.popover) g.popover.close(true, g.pair.end.el);
		}
	}
	function blockedEnd(g, d) {
		const was = g.active;
		g.active = 1;
		const b = blocked(g, d);
		g.active = was;
		return b;
	}

	function clear(g) {
		g.start = g.end = null;
		g.active = 0;
		g.hover = null;
		update(g);
	}

	function bindCal(cal) {
		const g = cal.g;
		cal.root.addEventListener("click", e => {
			const day = e.target.closest("[data-dp-date]");
			if (day) { if (day.getAttribute("aria-disabled") !== "true") pick(cal, parse(day.getAttribute("data-dp-date")), e.detail > 0); return; }
			const seg = e.target.closest("[data-dp-seg]");
			if (seg) {
				g.active = +seg.getAttribute("data-dp-seg") === 1 && g.start ? 1 : 0;
				g.hover = null;
				update(g);
				showDate(cal, (g.active ? g.end || addDays(g.start, 1) : g.start) || today);
				return;
			}
			const nav = e.target.closest("[data-dp-nav]");
			if (nav) {
				cal.view = addMonths(cal.view, +nav.getAttribute("data-dp-nav"));
				if (cal.view < minView) cal.view = minView;
				const lastShown = new Date(cal.view.getFullYear(), cal.view.getMonth() + cal.months(), 0);
				if (cal.focus < cal.view || cal.focus > lastShown) cal.focus = cal.view < today ? today : cal.view;
				render(cal);
				return;
			}
			if (e.target.closest("[data-dp-clear]")) { clear(g); showDate(cal, today); return; }
			if (e.target.closest("[data-dp-done]") && g.popover) g.popover.close(true);
		});
		cal.root.addEventListener("mouseover", e => {
			const day = e.target.closest("[data-dp-date]");
			const d = day && day.getAttribute("aria-disabled") !== "true" ? parse(day.getAttribute("data-dp-date")) : null;
			if (same(d, g.hover) || (!d && !g.hover)) return;
			g.hover = d;
			if (g.active === 1 && g.start) g.cals.forEach(c => { if (c.shown) paint(c); });
		});
		cal.root.addEventListener("mouseleave", () => { if (g.hover) { g.hover = null; paint(cal); } });
		cal.root.addEventListener("keydown", e => {
			if (e.key === "Escape") {
				if (!cal.inline && g.popover) { e.preventDefault(); g.popover.close(true); return; }
				if (g.active === 1) { e.preventDefault(); g.active = 0; g.hover = null; if (!g.end) g.start = null; update(g); }
				return;
			}
			const day = e.target.closest("[data-dp-date]");
			if (!day) return;
			let d = parse(day.getAttribute("data-dp-date"));
			const step = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7 }[e.key];
			if (step) d = addDays(d, step);
			else if (e.key === "PageUp" || e.key === "PageDown") d = new Date(d.getFullYear(), d.getMonth() + (e.key === "PageUp" ? -1 : 1), Math.min(d.getDate(), 28));
			else if (e.key === "Home") d = addDays(d, -((d.getDay() + 6) % 7));
			else if (e.key === "End") d = addDays(d, 6 - ((d.getDay() + 6) % 7));
			else return;
			e.preventDefault();
			g.hover = d >= today && !blocked(g, d) ? d : null;
			showDate(cal, d);
		});
	}

	/* ----------------------------------------------------------------- popover */
	function attachInputs(startEl) {
		const endEl = document.getElementById(startEl.getAttribute("data-date-end"));
		if (!endEl || startEl.mbkDates) return null;
		startEl.mbkDates = true;
		const g = group(startEl.id, startEl.getAttribute("data-unit"));
		const labels = [startEl, endEl].map(el => {
			const l = el.id && document.querySelector('label[for="' + el.id + '"]');
			return l ? l.textContent.trim() : "";
		});
		const [start, end] = [startEl, endEl].map(el => {
			// Moving a focused node (and switching its type) blurs it: e.g. the header dialog's autofocused check-in
			// when the lazy bundle loads after showModal(). Focus is restored below; focus does not open the picker.
			const hadFocus = document.activeElement === el;
			const hidden = document.createElement("input");
			hidden.type = "hidden";
			hidden.value = el.value;
			if (el.name) { hidden.name = el.name; el.removeAttribute("name"); }
			el.type = "text";
			el.readOnly = true;
			el.autocomplete = "off";
			el.placeholder = __("Add date", "marclie-booking");
			el.classList.add("w-datepicker-input");
			// combobox: a text input may only carry aria-expanded with this role.
			el.setAttribute("role", "combobox");
			el.setAttribute("aria-haspopup", "dialog");
			el.setAttribute("aria-expanded", "false");
			el.removeAttribute("min");
			if (el.closest(".w-field")) {
				const wrap = document.createElement("span");
				wrap.className = "w-datepicker-field";
				wrap.setAttribute("data-dp-field", "");
				el.classList.add("w-datepicker-field__input");
				el.before(wrap);
				wrap.append(el, hidden);
				wrap.insertAdjacentHTML("beforeend", ICON_CAL);
				wrap.lastElementChild.classList.add("w-datepicker-field__icon");
			} else el.after(hidden);
			if (hadFocus && document.activeElement !== el) el.focus({ preventScroll: true });
			return { el, hidden };
		});
		g.pair = { start, end, labels };
		if (!g.start) {
			const s = parse(start.hidden.value), en = parse(end.hidden.value);
			if (s && s >= today) { g.start = s; g.end = en && en > s ? en : null; }
		}

		const pop = makeCal(g, document.createElement("div"), false, 2);
		const dp = pop.root;
		dp.hidden = true;
		dp.setAttribute("role", "dialog");
		dp.setAttribute("aria-label", __("Choose dates", "marclie-booking"));
		(startEl.closest("dialog") || document.body).appendChild(dp);
		let opener = startEl;
		const fields = [start, end];

		function place() {
			if (dp.hidden) return;
			// anchor below both fields when they share a row, below the field that opened it when they stack
			let r = fields.map(p => (p.el.closest(".w-field") || p.el).getBoundingClientRect());
			if (Math.abs(r[0].top - r[1].top) > 4) r = [r[fields.findIndex(p => p.el === opener)]];
			const top = Math.min(...r.map(x => x.top)), bottom = Math.max(...r.map(x => x.bottom)), left = Math.min(...r.map(x => x.left));
			const vw = document.documentElement.clientWidth, vh = window.innerHeight, w = dp.offsetWidth, h = dp.offsetHeight;
			let y = bottom + 8;
			if (y + h > vh - 8) y = top - h - 8 >= 8 ? top - h - 8 : Math.max(8, vh - h - 8);
			dp.style.left = Math.max(8, Math.min(left, vw - w - 8)) + "px";
			dp.style.top = y + "px";
		}
		function open(k) {
			g.active = k === 1 && !g.start ? 0 : k;
			opener = fields[k].el;
			g.hover = null;
			const target = (g.active ? g.end || (g.start && addDays(g.start, 1)) : g.start) || today;
			pop.focus = target < today ? today : target;
			const fm = monthStart(pop.focus);
			pop.view = g.active && g.start && pop.months() === 2 && fm.getTime() === addMonths(g.start, 1).getTime() ? monthStart(g.start) : fm;
			if (pop.view < minView) pop.view = minView;
			render(pop);
			if (dp.hidden) {
				dp.hidden = false;
				document.addEventListener("pointerdown", outside, true);
				window.addEventListener("resize", onResize);
				window.addEventListener("scroll", place, true);
			}
			fields.forEach(p => p.el.setAttribute("aria-expanded", "true"));
			const f = (opener.closest(".w-field") || opener).getBoundingClientRect();
			if (!startEl.closest("dialog") && f.bottom + 8 + dp.offsetHeight > window.innerHeight - 8) window.scrollBy(0, f.top - 88);
			place();
			focusDay(pop);
		}
		function close(refocus, to) {
			if (dp.hidden) return;
			dp.hidden = true;
			pop.shown = 0;
			if (g.active === 1 && !g.end) g.active = 0;
			fields.forEach(p => p.el.setAttribute("aria-expanded", "false"));
			document.removeEventListener("pointerdown", outside, true);
			window.removeEventListener("resize", onResize);
			window.removeEventListener("scroll", place, true);
			g.cals.forEach(c => { if (c.shown) paint(c); });
			if (refocus) (to || opener).focus();
		}
		function outside(e) {
			if (dp.contains(e.target) || fields.some(p => (p.el.closest("[data-dp-field]") || p.el).contains(e.target))) return;
			close(false);
		}
		function onResize() { if (pop.shown !== pop.months()) render(pop); place(); }
		dp.addEventListener("focusout", () => setTimeout(() => {
			const a = document.activeElement;
			if (dp.hidden || !a || a === document.body || dp.contains(a) || fields.some(p => p.el === a)) return;
			close(false);
		}, 0));
		fields.forEach((p, k) => {
			p.el.addEventListener("click", () => open(k));
			p.el.addEventListener("keydown", e => {
				if (e.key === "Enter" || e.key === " " || e.key === "ArrowDown") { e.preventDefault(); open(k); }
				else if (e.key === "Escape") close(false);
				else if (e.key === "Backspace" || e.key === "Delete") {
					e.preventDefault();
					if (k === 0) g.start = null;
					g.end = null;
					update(g);
				}
			});
			const label = p.el.id && document.querySelector('label[for="' + p.el.id + '"]');
			if (label) label.addEventListener("click", e => { e.preventDefault(); open(k); });
		});
		g.popover = { open, close };
		update(g);
		return g;
	}

	mbk.dates = { group, makeCal, render, paint, update, clear, attachInputs, load, parse, iso, fmt, nightsText, addMonths, monthStart, today, minStay, canArrive };

	mbk.ready(() => { $$("input[data-date-end]").forEach(attachInputs); });
})();
