/**
 * Marclie Booking — search form steppers/validation ([data-mbk-search-form]) and client-side facets,
 * sort and filter drawer of the search results ([data-mbk-catalog], port of the wireframe catalogue).
 * Cards: [data-product] with data-<facet>="a|b", data-price, data-sold, data-order (server lists
 * bookable units only). ?<facet>=<value> pre-selects a filter.
 */
(function () {
	"use strict";

	const mbk = window.mbk;
	const { __, sprintf } = window.wp.i18n;
	const { $, $$ } = mbk;

	function initForm(form) {
		$$("[data-qty]", form).forEach(mbk.initQty);
		form.addEventListener("submit", e => {
			const start = $('input[type="hidden"][name="dolazak"]', form), end = $('input[type="hidden"][name="odlazak"]', form);
			if (!start || !end || (start.value && end.value)) return;
			e.preventDefault();
			const visible = $("input[data-date-end]", form);
			if (visible) visible.click();
		});
	}

	// remove-chip icon: the svg the mockup engine (wireframe.js initCatalog) puts in each chip
	const ICON_X = '<svg class="w-icon w-icon--small pi-x" viewBox="0 0 256 256" aria-hidden="true" focusable="false"><path d="M205.66,194.34a8,8,0,0,1-11.32,11.32L128,139.31,61.66,205.66a8,8,0,0,1-11.32-11.32L116.69,128,50.34,61.66A8,8,0,0,1,61.66,50.34L128,116.69l66.34-66.35a8,8,0,0,1,11.32,11.32L139.31,128Z"/></svg>';

	function initCatalog(root) {
		const cards = $$("[data-product]", root);
		const boxes = $$("[data-facet]", root);
		const sortSel = $("[data-sort]", root);
		const activeEl = $("[data-active-filters]", root);
		const clearEls = $$("[data-clear-filters]", root);
		const emptyEl = $("[data-empty]", root);
		const grid = $("[data-mbk-grid]", root);
		if (!cards.length) return;

		function selected() {
			const map = {};
			boxes.forEach(b => { if (b.checked) (map[b.getAttribute("data-facet")] = map[b.getAttribute("data-facet")] || []).push(b.value); });
			return map;
		}
		const matches = (card, map) => Object.keys(map).every(f => {
			const have = (card.getAttribute("data-" + f) || "").split("|");
			return map[f].some(v => have.indexOf(v) > -1);
		});
		function sortCards(list) {
			const num = (c, a) => parseFloat(c.getAttribute("data-" + a) || "0");
			const cmp = {
				"price-asc": (a, b) => num(a, "price") - num(b, "price"),
				"price-desc": (a, b) => num(b, "price") - num(a, "price"),
				sold: (a, b) => num(b, "sold") - num(a, "sold")
			}[sortSel ? sortSel.value : ""] || ((a, b) => num(a, "order") - num(b, "order"));
			return list.slice().sort(cmp);
		}
		function reset(b) {
			b.checked = false;
			if (b.type !== "radio" || !b.name) return;
			const neutral = $$('input[type="radio"]', root).find(r => r.name === b.name && !r.hasAttribute("data-facet"));
			if (neutral) neutral.checked = true;
		}
		function renderActive() {
			const on = boxes.filter(b => b.checked);
			if (activeEl) {
				activeEl.replaceChildren();
				on.forEach(b => {
					const label = b.getAttribute("data-label") || b.value;
					const chip = document.createElement("button");
					chip.type = "button";
					chip.className = "w-chip is-active";
					chip.textContent = label + " ";
					chip.insertAdjacentHTML("beforeend", ICON_X);
					/* translators: %s: active filter, e.g. "House: House 1". */
					chip.setAttribute("aria-label", sprintf(__("Remove %s", "marclie-booking"), label));
					chip.addEventListener("click", () => { reset(b); apply(); });
					activeEl.appendChild(chip);
				});
			}
			clearEls.forEach(c => { c.hidden = on.length === 0; });
			$$("[data-active-count]", root).forEach(el => { el.textContent = on.length; el.hidden = on.length === 0; });
		}
		function apply() {
			const map = selected();
			const hits = sortCards(cards.filter(c => matches(c, map)));
			cards.forEach(c => { c.hidden = true; });
			hits.forEach(c => { c.hidden = false; if (grid) grid.appendChild(c); });
			const n = hits.length;
			$$("[data-result-label]", root).forEach(el => {
				const t = (n === 1 && el.getAttribute("data-result-label-one")) || el.getAttribute("data-result-label");
				el.textContent = t.replace("{total}", n);
			});
			if (emptyEl) emptyEl.hidden = n > 0;
			renderActive();
		}

		boxes.forEach(b => b.addEventListener("change", apply));
		if (sortSel) sortSel.addEventListener("change", apply);
		clearEls.forEach(c => c.addEventListener("click", e => { e.preventDefault(); boxes.forEach(reset); apply(); }));

		// deep links: ?<facet>=<value>; a numeric value without an exact option picks the smallest threshold >= it
		new URLSearchParams(location.search).forEach((v, k) => {
			const opts = boxes.filter(b => b.getAttribute("data-facet") === k);
			if (!v || !opts.length) return;
			let hit = opts.find(b => b.value === v);
			const n = parseFloat(v);
			if (!hit && !isNaN(n) && opts.every(b => !isNaN(parseFloat(b.value)))) {
				const asc = opts.slice().sort((a, b) => parseFloat(a.value) - parseFloat(b.value));
				hit = asc.find(b => parseFloat(b.value) >= n) || asc[asc.length - 1];
			}
			if (hit) hit.checked = true;
		});
		apply();
		initDrawer($("[data-filter-panel]", root));
	}

	/** Static sidebar where its [data-filter-open] trigger is hidden (CSS), off-canvas where it is visible. */
	function initDrawer(panel) {
		if (!panel || !panel.id) return;
		const triggers = $$('[data-filter-open][aria-controls="' + panel.id + '"]');
		const scrim = $("[data-filter-scrim]", panel.parentNode);
		let opener = null;
		function open(on, from) {
			if (on === panel.classList.contains("is-open")) return;
			panel.classList.toggle("is-open", on);
			if (scrim) scrim.hidden = !on;
			triggers.forEach(t => t.setAttribute("aria-expanded", on ? "true" : "false"));
			document.body.style.overflow = on ? "hidden" : "";
			if (on) {
				opener = from || null;
				panel.setAttribute("role", "dialog");
				panel.setAttribute("aria-modal", "true");
				const first = $("button, input", panel);
				if (first) first.focus();
			} else {
				panel.setAttribute("role", "region");
				panel.removeAttribute("aria-modal");
				if (opener) opener.focus();
			}
		}
		triggers.forEach(t => t.addEventListener("click", e => { e.preventDefault(); open(true, t); }));
		$$("[data-filter-close]", panel).concat(scrim ? [scrim] : []).forEach(c => c.addEventListener("click", e => { e.preventDefault(); open(false); }));
		document.addEventListener("keydown", e => { if (e.key === "Escape" && panel.classList.contains("is-open")) { e.preventDefault(); open(false); } });
		window.addEventListener("resize", () => { if (panel.classList.contains("is-open") && !triggers.some(t => t.getClientRects().length)) open(false); });
	}

	mbk.ready(() => {
		$$("[data-mbk-search-form]").forEach(initForm);
		$$("[data-mbk-catalog]").forEach(initCatalog);
	});
})();
