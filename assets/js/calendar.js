/**
 * Marclie Booking — inline availability calendar ([data-date-inline="<key>"], unit page #dostupnost).
 * Shares its selection with the bookbox picker of the same key; [data-nights-title="<key>"][data-name]
 * and [data-nights-range="<key>"] follow the selection. data-date-view="YYYY-MM" opens on that month
 * when no dates are chosen.
 */
(function () {
	"use strict";

	const mbk = window.mbk;
	const { __, _n, sprintf } = window.wp.i18n;
	const D = mbk.dates;

	function texts(key, detail) {
		const start = D.parse(detail.start), end = D.parse(detail.end), n = detail.nights;
		mbk.$$('[data-nights-title="' + key + '"]').forEach(t => {
			t.textContent = n
				/* translators: 1: number of nights, 2: apartment name. */
				? sprintf(_n("%1$d night in %2$s", "%1$d nights in %2$s", n, "marclie-booking"), n, t.getAttribute("data-name") || "")
				: start ? __("Select your check-out date", "marclie-booking") : __("Select your check-in date", "marclie-booking");
		});
		mbk.$$('[data-nights-range="' + key + '"]').forEach(r => {
			r.textContent = start ? D.fmt(start) + " – " + (end ? D.fmt(end) : "…") : __("Add your travel dates", "marclie-booking");
		});
	}

	/** Draws every not yet initialised inline calendar (also re-run by the Bricks builder after a render). */
	function init() {
		mbk.$$("[data-date-inline]").forEach(host => {
			if (host.mbkCal) return;
			host.mbkCal = true;
			const key = host.getAttribute("data-date-inline");
			const g = D.group(key, host.getAttribute("data-unit"));
			const cal = D.makeCal(g, host, true, parseInt(host.getAttribute("data-months") || "2", 10));
			host.setAttribute("role", "group");
			host.setAttribute("aria-label", __("Choose dates", "marclie-booking"));
			const view = D.parse((host.getAttribute("data-date-view") || "") + "-01");
			cal.focus = g.start && g.start >= D.today ? g.start : view && view > D.today ? view : D.today;
			cal.view = D.monthStart(cal.focus);
			D.render(cal);
			window.addEventListener("resize", () => { if (cal.shown !== cal.months()) D.render(cal); });
			texts(key, { start: D.iso(g.start), end: D.iso(g.end), nights: g.start && g.end ? Math.round((g.end - g.start) / 864e5) : 0 });
		});
	}
	mbk.initCalendars = init;

	mbk.ready(() => {
		init();
		document.addEventListener("mbk:dates", e => texts(e.detail.key, e.detail));
	});
})();
