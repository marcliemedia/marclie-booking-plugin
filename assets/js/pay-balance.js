/**
 * Marclie Booking — balance payment ([data-mbk-pay-balance], /rezervacija/placanje/?ref&t).
 *
 * GET /bookings/{ref}?t= → outstanding amount; POST /bookings/{ref}/balance-intent?t= →
 * {client_secret, amount_cents, amount} → Payment Element → stripe.confirmPayment (redirect only
 * when 3-D Secure needs it; the return lands here). Success is shown only from the server state
 * (paid, or nothing outstanding), polled every 2 s for up to 60 s while the webhook arrives.
 */
(function () {
	"use strict";

	const mbk = window.mbk;
	const { __, sprintf } = window.wp.i18n;
	const { $, $$, cfg } = mbk;

	const POLL_MS = 2000, POLL_MAX_MS = 60000;
	const DL = cfg.locale || "hr-HR";
	const longFmt = new Intl.DateTimeFormat(DL, { day: "numeric", month: "long", year: "numeric" });
	const parseIso = s => { const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(s || ""); return m ? new Date(+m[1], +m[2] - 1, +m[3]) : null; };
	const money = v => (v && typeof v === "object" ? v.formatted || v.display || "" : v || "");
	const cents = (v, alt) => (v && typeof v === "object" ? v.cents : typeof alt === "number" ? alt : 0);
	const PAID = ["paid", "completed"];
	const FINAL = ["cancelled", "expired"];
	const CLOSED_CODES = ["not_payable", "invalid_transition", "in_progress"];

	function init(root) {
		if (root.mbkPayBalance) return;
		root.mbkPayBalance = true;
		const { ref, token, redirectStatus } = mbk.bookingLink();
		const form = $("[data-pb-form]", root), submit = $("[data-pb-submit]", root), alertEl = $("[data-pb-alert]", root);
		const base = "bookings/" + encodeURIComponent(ref);
		let stripe = null, elements = null, busy = false;

		function show(name) {
			const h = mbk.showState(root, "data-pb-state", name);
			if (h && name !== "processing") { h.setAttribute("tabindex", "-1"); h.focus({ preventScroll: true }); }
		}
		const set = (key, text) => $$('[data-pb="' + key + '"]', root).forEach(el => { el.textContent = text; });
		function alert(msg) { alertEl.textContent = msg || ""; alertEl.hidden = !msg; }
		function offline() {
			$("[data-pb-pay]", root).hidden = true;
			$("[data-pb-offline]", root).hidden = false;
			submit.hidden = true;
			submit.disabled = true;
		}

		const due = b => cents((b.totals || {}).balance, (b.totals || {}).balance_cents);
		const paidUp = b => PAID.indexOf(b.status) > -1 || due(b) <= 0;

		/** GET the booking; null after showing the invalid/error state. */
		async function fetchBooking() {
			try {
				return await mbk.api(base, { params: { t: token } });
			} catch (err) {
				show(err.status === 400 || err.status === 404 ? "invalid" : "error");
				return null;
			}
		}

		/** After a confirmed payment: wait until the server sees it (webhook / reconcile). */
		async function awaitPaid() {
			show("processing");
			const started = Date.now();
			for (;;) {
				let b = null;
				try { b = await mbk.api(base, { params: { t: token } }); } catch (e) { /* retried below */ }
				if (b && paidUp(b)) { show("success"); return; }
				if (b && b.payment_state === "failed") { start("failed"); return; }
				if (Date.now() - started >= POLL_MAX_MS) { show("timeout"); return; }
				await new Promise(r => setTimeout(r, POLL_MS));
			}
		}

		function fill(b) {
			const totals = b.totals || {}, a = parseIso(b.arrival), d = parseIso(b.departure);
			set("ref", b.ref || "");
			set("unit", (b.unit && b.unit.title) || "");
			set("dates", a && d ? (longFmt.formatRange ? longFmt.formatRange(a, d) : longFmt.format(a) + " – " + longFmt.format(d)) : "");
			set("total", money(totals.total));
			set("paid", money(totals.paid));
			set("balance", money(totals.balance));
		}

		async function start(returned) {
			show("loading");
			const b = await fetchBooking();
			if (!b) return;
			if (paidUp(b)) { show("nothing"); return; }
			if (FINAL.indexOf(b.status) > -1 || (b.payable === false && b.not_payable_reason !== "nothing_due")) { show("closed"); return; }
			if (b.payable === false) { show("nothing"); return; }
			fill(b);
			show("form");
			if (returned === "failed") alert(__("The payment could not be completed. Please try again or use another card.", "marclie-booking"));
			if (elements) { submit.disabled = false; return; }

			stripe = mbk.stripe();
			if (!stripe) { offline(); return; }
			let intent;
			try {
				intent = await mbk.api(base + "/balance-intent", { params: { t: token }, body: {} });
			} catch (err) {
				if (err.status === 503 || err.code === "payments_unavailable") { offline(); return; }
				if (err.code === "nothing_due") { show("nothing"); return; }
				if (CLOSED_CODES.indexOf(err.code) > -1) { show("closed"); return; }
				alert(err.code && err.message ? err.message : __("The payment could not be prepared. Please reload the page.", "marclie-booking"));
				return;
			}
			elements = stripe.elements({ clientSecret: intent.client_secret, locale: mbk.stripeLocale(), appearance: mbk.stripeAppearance() });
			const el = elements.create("payment", { layout: "tabs" });
			el.on("ready", () => { submit.disabled = false; });
			el.on("loaderror", offline);
			el.mount($("[data-pb-element]", root));
			if (intent.amount_cents) {
				const amount = intent.amount || new Intl.NumberFormat(DL, { style: "currency", currency: "EUR", minimumFractionDigits: intent.amount_cents % 100 ? 2 : 0 }).format(intent.amount_cents / 100);
				/* translators: %s: amount, e.g. "955,50 €". */
				$("[data-pb-submit-label]", root).textContent = sprintf(__("Pay %s", "marclie-booking"), amount);
			}
		}

		form.addEventListener("submit", async e => {
			e.preventDefault();
			if (busy || !elements) return;
			busy = true;
			submit.disabled = true;
			alert("");
			try {
				// 3-D Secure returns here; the token is restored from sessionStorage
				const returnUrl = new URL(location.href);
				returnUrl.searchParams.set("ref", ref);
				const res = await stripe.confirmPayment({ elements, confirmParams: { return_url: returnUrl.toString() }, redirect: "if_required" });
				if (res.error) {
					const t = res.error.type;
					alert(t === "card_error" || t === "validation_error" ? res.error.message : __("The payment could not be completed. Please try again or use another card.", "marclie-booking"));
				} else {
					await awaitPaid();
				}
			} finally {
				busy = false;
				submit.disabled = false;
			}
		});

		$("[data-pb-reload]", root).addEventListener("click", () => start(""));

		if (!/^[A-Za-z0-9-]{3,40}$/.test(ref) || !/^[A-Za-z0-9_-]{16,}$/.test(token)) { show("invalid"); return; }
		if (redirectStatus === "succeeded" || redirectStatus === "processing") awaitPaid();
		else start(redirectStatus);
	}

	mbk.ready(() => $$("[data-mbk-pay-balance]").forEach(init));
})();
