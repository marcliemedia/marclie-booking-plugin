/**
 * Marclie Booking — bookbox ([data-mbk-bookbox="<key>"]): guest steppers (adults + children 5–10 ≤ capacity;
 * children 0–4 are free and not counted),
 * live POST /quote (debounced 250 ms) into [data-mbk-lines], errors in role=alert, GET submit to the
 * booking page. The mobile bookbar ([data-mbk-bookbar="<key>"]) mirrors the quoted total.
 */
(function () {
	"use strict";

	const mbk = window.mbk;
	const { __, sprintf } = window.wp.i18n;
	const { $, $$ } = mbk;
	const D = mbk.dates;

	function row(label, amount, total) {
		const div = document.createElement("div"), dt = document.createElement("dt"), dd = document.createElement("dd");
		div.className = "w-unit-body__line" + (total ? " w-unit-body__line--total" : "");
		dt.className = "w-unit-body__line-label";
		dd.className = "w-unit-body__line-value";
		dt.textContent = label;
		dd.textContent = amount;
		div.append(dt, dd);
		return div;
	}

	function init(box) {
		if (box.mbkBookbox) return;
		box.mbkBookbox = true;
		const key = box.getAttribute("data-mbk-bookbox");
		const unit = parseInt(box.getAttribute("data-unit"), 10);
		const form = $("form", box);
		const lines = $("[data-mbk-lines]", box);
		const alertEl = $("[data-mbk-error]", box);
		const adults = $('input[name="odrasli"]', box);
		const kids = $('input[name="djeca"]', box);
		const infants = $('input[name="bebe"]', box);
		const cap = parseInt(adults.getAttribute("data-cap"), 10) || parseInt(adults.max, 10) || 1;
		const kidsMax = parseInt(kids.getAttribute("data-max"), 10) || 0;
		const initialLines = Array.from(lines.childNodes).map(n => n.cloneNode(true));
		const bars = $$('[data-mbk-bookbar="' + key + '"] [data-bookbar-text]');
		const initialBar = bars.map(b => b.textContent.trim());
		const g = D.group(key, unit);
		let ctrl = null, failed = null; // failed: error code of the last quote, null when it succeeded

		const steppers = $$("[data-qty]", box);
		steppers.forEach(mbk.initQty);
		$$("[data-mbk-reveal]", box).forEach(mbk.initReveal);
		// Mobile bar: only while the bookbox itself is off screen, so one "Book now" shows at a time.
		const barRoots = $$('[data-mbk-bookbar="' + key + '"]');
		if (barRoots.length && "IntersectionObserver" in window) {
			new IntersectionObserver(([entry]) => barRoots.forEach(b => { b.hidden = entry.isIntersecting; })).observe(box);
		}
		function bounds() {
			const a = parseInt(adults.value, 10) || 1, c = parseInt(kids.value, 10) || 0;
			adults.max = String(Math.max(1, cap - c));
			kids.max = String(Math.max(0, Math.min(kidsMax, cap - a)));
			steppers.forEach(s => { if (s.mbkSync) s.mbkSync(); });
		}

		function alert(msg) {
			alertEl.textContent = msg || "";
			alertEl.hidden = !msg;
		}
		function bar(total, nights) {
			bars.forEach((b, i) => {
				/* translators: 1: total price, 2: "7 nights". */
				b.textContent = total ? sprintf(__("%1$s · %2$s", "marclie-booking"), total, D.nightsText(nights)) : initialBar[i];
			});
		}
		function reset() {
			schedule.cancel();
			if (ctrl) ctrl.abort();
			ctrl = null;
			lines.replaceChildren(...initialLines.map(n => n.cloneNode(true)));
			failed = null;
			alert("");
			bar(null);
			box.classList.remove("is-loading");
			lines.removeAttribute("aria-busy");
		}

		async function quote() {
			const arrival = D.iso(g.start), departure = D.iso(g.end);
			if (!arrival || !departure) { reset(); return; }
			if (ctrl) ctrl.abort();
			const my = (ctrl = new AbortController());
			box.classList.add("is-loading");
			lines.setAttribute("aria-busy", "true");
			try {
				const data = await mbk.api("quote", {
					body: { unit_id: unit, arrival, departure, adults: parseInt(adults.value, 10) || 1, children: parseInt(kids.value, 10) || 0, infants: parseInt(infants.value, 10) || 0 },
					signal: my.signal
				});
				if (!data || !data.available || !data.display) {
					const err = new Error(__("Those dates are no longer available. Please choose other dates.", "marclie-booking"));
					err.code = "unavailable";
					throw err;
				}
				const d = data.display;
				lines.replaceChildren(...d.lines.map(l => row(l.label, l.amount)), row(__("Total", "marclie-booking"), d.total, true));
				failed = null;
				alert("");
				bar(d.total, data.quote && data.quote.nights ? data.quote.nights : Math.round((g.end - g.start) / 864e5));
			} catch (err) {
				if (err.name === "AbortError") return;
				lines.replaceChildren(...initialLines.map(n => n.cloneNode(true)));
				failed = err.code || "error";
				// server errors carry a translated message; network/5xx failures do not
				alert(err.code && err.message ? err.message : __("The price could not be loaded. Please try again.", "marclie-booking"));
				bar(null);
			} finally {
				if (my === ctrl) {
					ctrl = null;
					box.classList.remove("is-loading");
					lines.removeAttribute("aria-busy");
				}
			}
		}
		const schedule = mbk.debounce(quote, 250);

		// The party sets the price (charged persons); a capacity error also clears.
		[adults, kids, infants].forEach(i => i.addEventListener("change", () => { bounds(); if (g.start && g.end) schedule(); }));
		document.addEventListener("mbk:dates", e => {
			if (e.detail.key !== key) return;
			if (e.detail.start && e.detail.end) schedule();
			else reset();
		});

		form.addEventListener("submit", e => {
			if (!g.start || !g.end) {
				e.preventDefault();
				alert(g.start ? __("Select your check-out date", "marclie-booking") : __("Select your check-in date", "marclie-booking"));
				if (g.pair) g.pair[g.start ? "end" : "start"].el.click();
				return;
			}
			if (failed) {
				e.preventDefault();
				alertEl.setAttribute("tabindex", "-1");
				alertEl.focus();
			}
		});

		bounds();
		if (g.start && g.end) schedule();
	}

	mbk.ready(() => $$("[data-mbk-bookbox]").forEach(init));
})();
