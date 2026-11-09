/**
 * Marclie Booking — thank-you page ([data-mbk-confirmation], /rezervacija/hvala/?ref&t).
 *
 * GET /bookings/{ref}?t= (the server reconciles the PaymentIntent): confirmed/paid → details;
 * pending_payment → spinner, polled every 2 s for up to 60 s; failed/expired → retry link to the
 * checkout; bad link → generic notice without data. GA4 `purchase` is pushed exactly once:
 * analytics consent + no localStorage guard + `ga_sent` false + POST /bookings/{ref}/ga → send.
 */
(function () {
	"use strict";

	const mbk = window.mbk;
	const { __, _n, sprintf } = window.wp.i18n;
	const { $, $$, cfg } = mbk;

	const POLL_MS = 2000, POLL_MAX_MS = 60000;
	const DONE = ["confirmed", "paid", "balance_failed", "completed"];
	const DEAD = ["expired", "cancelled"];
	const DL = cfg.locale || "hr-HR";

	const parseIso = s => { const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(s || ""); return m ? new Date(+m[1], +m[2] - 1, +m[3]) : null; };
	const longFmt = new Intl.DateTimeFormat(DL, { day: "numeric", month: "long", year: "numeric" });
	const shortFmt = new Intl.DateTimeFormat(DL, { day: "numeric", month: "short", year: "numeric" });
	/** ISO date → long local date; already formatted strings pass through. */
	const date = (s, fmt) => { const d = parseIso(s); return d ? (fmt || longFmt).format(d) : s || ""; };
	/** Amount as formatted string: accepts "409,50 €" or {formatted|display, cents}. */
	const money = v => (v && typeof v === "object" ? v.formatted || v.display || "" : v || "");
	const cents = (v, alt) => (v && typeof v === "object" ? v.cents : typeof alt === "number" ? alt : 0);

	function storage(kind) {
		try { return window[kind]; } catch (e) { return null; }
	}

	function init(root) {
		if (root.mbkConfirmation) return;
		root.mbkConfirmation = true;
		const { ref, token } = mbk.bookingLink();
		let started = Date.now();

		function show(name) {
			const h = mbk.showState(root, "data-cf-state", name);
			if (h && name !== "processing") { h.setAttribute("tabindex", "-1"); h.focus({ preventScroll: true }); }
		}
		const set = (key, text) => $$('[data-cf="' + key + '"]', root).forEach(el => { el.textContent = text; });

		if (!/^[A-Za-z0-9-]{3,40}$/.test(ref) || !/^[A-Za-z0-9_-]{16,}$/.test(token)) { show("invalid"); return; }

		function retryUrl(b) {
			const url = new URL(cfg.pages && cfg.pages.rezervacija ? cfg.pages.rezervacija : "/rezervacija/", location.href);
			if (b.unit && b.unit.id) url.searchParams.set("apartman", b.unit.id);
			if (b.arrival) url.searchParams.set("dolazak", b.arrival);
			if (b.departure) url.searchParams.set("odlazak", b.departure);
			url.searchParams.set("odrasli", b.adults || 1);
			url.searchParams.set("djeca", b.children || 0);
			if (b.infants) url.searchParams.set("bebe", b.infants);
			return url.toString();
		}

		function fill(b) {
			const unit = b.unit || {}, totals = b.totals || {}, display = b.display || {};
			const arrival = parseIso(b.arrival), departure = parseIso(b.departure);
			const nights = b.nights || (arrival && departure ? Math.round((departure - arrival) / 864e5) : 0);
			const nightsText = sprintf(_n("%d night", "%d nights", nights, "marclie-booking"), nights);
			const range = arrival && departure && longFmt.formatRange ? longFmt.formatRange(arrival, departure) : date(b.arrival) + " – " + date(b.departure);
			const paid = money(totals.paid), balance = money(totals.balance);
			const balanceDue = date(display.balance_due_on || b.balance_due_on);
			const hasBalance = !b.pay_in_full && cents(totals.balance, totals.balance_cents) > 0;

			/* translators: %s: apartment name. */
			set("intro", sprintf(__("We have sent the confirmation for %s to your email address.", "marclie-booking"), unit.title || ""));
			set("ref", b.ref || "");
			set("dates", range + " · " + nightsText);
			/* translators: 1: number of adults, 2: number of children aged 5–10. */
			const guests = sprintf(__("Adults %1$d · Children %2$d", "marclie-booking"), b.adults || 0, b.children || 0);
			/* translators: %d: number of children aged 0–4. */
			set("guests", b.infants ? guests + " · " + sprintf(__("Children (0–4) %d", "marclie-booking"), b.infants) : guests);
			set("checkin", display.checkin_window ? date(b.arrival) + ", " + display.checkin_window : date(b.arrival));
			set("paid", b.pay_in_full
				/* translators: %s: amount paid. */
				? sprintf(__("%s (full amount)", "marclie-booking"), paid)
				/* translators: 1: amount paid, 2: deposit percentage, e.g. "30 %". */
				: display.deposit_pct ? sprintf(__("%1$s (%2$s deposit)", "marclie-booking"), paid, display.deposit_pct) : paid);
			/* translators: 1: balance amount, 2: due date. */
			set("balance", sprintf(__("%1$s on %2$s", "marclie-booking"), balance, balanceDue));
			/* translators: %s: balance due date. */
			set("next-balance-title", sprintf(__("Balance on %s.", "marclie-booking"), balanceDue));
			// Card bookings: the balance is charged automatically; manual (admin, paid outside Stripe): staff send instructions.
			const manual = b.balance_method === "manual";
			set("next-balance", manual
				/* translators: 1: balance amount, 2: due date. */
				? sprintf(__("The balance of %1$s is due by %2$s – we will contact you with payment instructions.", "marclie-booking"), balance, balanceDue)
				/* translators: %s: balance amount. */
				: sprintf(__("We charge %s to the same card and email you the receipt.", "marclie-booking"), balance));
			set("paid-label", manual ? __("Paid", "marclie-booking") : __("Paid today", "marclie-booking"));
			$$("[data-cf-balance]", root).forEach(el => { el.hidden = !hasBalance; });

			set("title", unit.title || "");
			// Page breadcrumb (outside the widget): `a[data-mbk-thanks-unit]` + its separator, hidden until known.
			if (unit.title && unit.permalink) {
				document.querySelectorAll("[data-mbk-thanks-unit]").forEach(el => {
					if (el.tagName === "A") { el.textContent = unit.title; el.href = unit.permalink; }
					el.hidden = false;
				});
			}
			set("house", unit.house || "");
			$$('[data-cf="house"]', root).forEach(el => { el.hidden = !unit.house; });
			set("place", unit.place || "");
			$$("[data-cf-place]", root).forEach(el => { el.hidden = !unit.place; });
			const thumb = $("[data-cf-thumb]", root);
			if (thumb && unit.thumb && /^https?:\/\//.test(unit.thumb)) { thumb.src = unit.thumb; thumb.hidden = false; }
			set("arrival", date(b.arrival, shortFmt));
			set("checkin-window", display.checkin_window || "");
			set("departure", date(b.departure, shortFmt));
			set("checkout-until", display.checkout_until || "");
			set("nights", nightsText);
			set("total", money(totals.total));
			set("paid-amount", paid);
			/* translators: %s: balance due date. */
			set("balance-label", sprintf(__("Balance on %s", "marclie-booking"), balanceDue));
			set("balance-amount", balance);
			const freeUntil = date(display.free_cancel_until || b.free_cancel_until);
			set("cancel", freeUntil
				/* translators: %s: last day of free cancellation. */
				? sprintf(__("Free until %s; after that the deposit is non-refundable.", "marclie-booking"), freeUntil)
				: __("The deposit is non-refundable.", "marclie-booking"));
			const back = $("[data-cf-back]", root);
			if (unit.permalink) {
				back.href = unit.permalink;
				/* translators: %s: apartment name. */
				set("back", sprintf(__("Back to %s", "marclie-booking"), unit.title || ""));
			} else back.hidden = true;

			// Account: linked → "Go to my account"; no account for the email yet → sign-up invite (?email= prefill).
			const account = b.account || {};
			const register = $("[data-cf-register]", root);
			$$('[data-cf-account="linked"]', root).forEach(el => { el.hidden = !account.linked; });
			$$('[data-cf-account="invite"]', root).forEach(el => { el.hidden = !!account.linked || !account.invite_email; });
			if (register && account.invite_email) {
				const url = new URL(register.href, location.href);
				url.searchParams.set("email", account.invite_email);
				register.href = url.toString();
			}
		}

		let shown = null, purchasing = false;
		async function purchase(b) {
			const local = storage("localStorage"), key = "mbk-ga-" + b.ref;
			if (purchasing || b.ga_sent || !mbk.analyticsAllowed()) return;
			try { if (local && local.getItem(key)) return; } catch (e) { /* blocked storage */ }
			purchasing = true;
			let send = false;
			try {
				const res = await mbk.api("bookings/" + encodeURIComponent(ref) + "/ga", { params: { t: token }, body: {} });
				send = !!(res && res.send);
			} catch (e) {
				purchasing = false;
				return;
			}
			if (!send) { b.ga_sent = true; return; }
			// Q-E: value = booking total; the deposit charged now travels as the custom param `deposit`.
			const p = b.purchase || {};
			const value = typeof p.value === "number" ? p.value : cents((b.totals || {}).total, (b.totals || {}).total_cents) / 100;
			const nights = p.quantity || b.nights || 1;
			mbk.track("purchase", {
				transaction_id: p.transaction_id || b.ref,
				value,
				deposit: typeof p.deposit === "number" ? p.deposit : undefined,
				currency: p.currency || "EUR",
				coupon: p.coupon || undefined,
				items: [{ item_id: p.item_id || String((b.unit && b.unit.id) || ""), item_name: p.item_name || (b.unit && b.unit.title) || "", price: Math.round(value / nights * 100) / 100, quantity: nights }]
			});
			try { if (local) local.setItem(key, "1"); } catch (e) { /* blocked storage */ }
			b.ga_sent = true;
		}

		function clearCheckout() {
			const s = storage("sessionStorage");
			try { if (s) { s.removeItem("mbk-checkout-pending"); s.removeItem("mbk-checkout-draft"); } } catch (e) { /* blocked storage */ }
		}

		function searchUrl(b) {
			const url = new URL(cfg.pages && cfg.pages.pretraga ? cfg.pages.pretraga : "/pretraga/", location.href);
			if (b.arrival) url.searchParams.set("dolazak", b.arrival);
			if (b.departure) url.searchParams.set("odlazak", b.departure);
			url.searchParams.set("odrasli", b.adults || 1);
			url.searchParams.set("djeca", b.children || 0);
			if (b.infants) url.searchParams.set("bebe", b.infants);
			return url.toString();
		}

		async function load() {
			let b;
			try {
				b = await mbk.api("bookings/" + encodeURIComponent(ref), { params: { t: token } });
			} catch (err) {
				if (err.status === 400 || err.status === 401 || err.status === 403 || err.status === 404) { show("invalid"); return; }
				if (Date.now() - started < POLL_MAX_MS) { setTimeout(load, POLL_MS); return; }
				show("error");
				return;
			}
			const status = b.status, ps = b.payment_state, reason = b.cancel_reason || "";
			if (DONE.indexOf(status) > -1) {
				fill(b);
				show("done");
				clearCheckout();
				shown = b;
				purchase(b);
				return;
			}
			if (status === "cancelled" && reason === "hold_lost") {
				// paid after the hold had expired and the nights were taken: refunded in full
				clearCheckout();
				$("[data-cf-search]", root).href = searchUrl(b);
				show("hold_lost");
				return;
			}
			if (status === "cancelled" && (ps === "refunded" || ["admin", ""].indexOf(reason) > -1)) {
				clearCheckout();
				$$('[data-cf="cancelled-refund"]', root).forEach(el => { el.hidden = ps !== "refunded"; });
				show("cancelled");
				return;
			}
			if (DEAD.indexOf(status) > -1 || ps === "failed" || ps === "requires_action") {
				// not charged: expired hold, superseded by a newer attempt, failed or abandoned 3-D Secure
				$("[data-cf-retry]", root).href = retryUrl(b);
				show("failed");
				return;
			}
			if (Date.now() - started >= POLL_MAX_MS) { show("timeout"); return; }
			show("processing");
			setTimeout(load, POLL_MS);
		}

		$("[data-cf-reload]", root).addEventListener("click", () => {
			show("loading");
			started = Date.now();
			load();
		});
		// consent given after the page loaded (phase 09 banner dispatches `mbk:consent` on window)
		window.addEventListener("mbk:consent", () => { if (shown) purchase(shown); });

		load();
	}

	mbk.ready(() => $$("[data-mbk-confirmation]").forEach(init));
})();
