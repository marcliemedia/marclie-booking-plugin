/**
 * Marclie Booking — checkout ([data-mbk-checkout], /rezervacija/).
 *
 * States (data-state): `free` two-step wizard, `booked` (zauzeto), `dates` (inline picker → reload
 * with dolazak/odlazak), `no_unit`. Step 1 „Vaši podaci” validates client-side and re-quotes;
 * step 2 mounts the Stripe Payment Element (deferred intent, deposit amount) and submits:
 * elements.submit() → [Turnstile token] → POST /bookings → stripe.confirmPayment() → redirect to /rezervacija/hvala/.
 * Guest fields are drafted in sessionStorage; the aside shows the POST /quote display lines and
 * applies the coupon through /quote. Without Stripe keys step 2 shows the offline notice.
 */
(function () {
	"use strict";

	const mbk = window.mbk;
	const { __, sprintf } = window.wp.i18n;
	const { $, $$, cfg } = mbk;
	const D = mbk.dates;

	const DRAFT = "mbk-checkout-draft";
	const PENDING = "mbk-checkout-pending";
	// The party (odrasli, djeca, bebe) lives in the URL, not in the draft: what the guest chose on the bookbox wins.
	const DRAFT_FIELDS = ["ime", "prezime", "email", "telefon", "vrijeme-dolaska", "napomena"];
	const STEP1_FIELDS = ["guest.first_name", "guest.last_name", "guest.email", "guest.phone", "adults", "children", "infants", "arrival_time", "message"];

	const store = {
		get(key) { try { return JSON.parse(sessionStorage.getItem(key) || "null"); } catch (e) { return null; } },
		set(key, value) { try { sessionStorage.setItem(key, JSON.stringify(value)); } catch (e) { /* private mode */ } },
		del(key) { try { sessionStorage.removeItem(key); } catch (e) { /* private mode */ } }
	};

	/** Aside price row (mockup `div.w-checkout__line > dt.w-checkout__price-term + dd.w-checkout__price-value`). */
	function row(label, amount, modifier) {
		const div = document.createElement("div"), dt = document.createElement("dt"), dd = document.createElement("dd");
		div.className = "w-checkout__line" + (modifier ? " w-checkout__line--" + modifier : "");
		dt.className = "w-checkout__price-term";
		dd.className = "w-checkout__price-value";
		dt.textContent = label;
		dd.textContent = amount;
		div.append(dt, dd);
		return div;
	}

	/* ------------------------------------------------------------- dates state */
	function initDates(root) {
		const go = $("[data-co-dates-go]", root);
		let range = null;
		document.addEventListener("mbk:dates", e => {
			if (e.detail.key !== "co-dates") return;
			range = e.detail.start && e.detail.end ? e.detail : null;
			go.disabled = !range;
		});
		go.addEventListener("click", () => {
			if (!range) return;
			const url = new URL(location.href);
			url.searchParams.set("dolazak", range.start);
			url.searchParams.set("odlazak", range.end);
			location.assign(url.toString());
		});
	}

	/* ------------------------------------------------------------ wizard state */
	function init(root) {
		if (root.mbkCheckout) return;
		root.mbkCheckout = true;
		const state = root.getAttribute("data-state");
		if (state === "dates") { initDates(root); return; }
		if (state === "no_unit") return;

		const unit = parseInt(root.getAttribute("data-unit"), 10);
		const arrival = root.getAttribute("data-arrival");
		const departure = root.getAttribute("data-departure");
		const stayKey = [unit, arrival, departure].join("|");
		const form = $("[data-co-form]", root);
		const lines = $("[data-co-lines]", root);
		const alertEl = $("[data-co-alert]", root);
		const depositEl = $("[data-co-deposit]", root);
		// phones: the summary is one row (apartment · dates · total) until the guest opens it; CSS ignores it on wider screens
		const summary = $("[data-co-summary]", root), summaryToggle = $("[data-co-summary-toggle]", root);
		const miniTotal = $("[data-co-mini-total]", root);
		if (summary && summaryToggle) {
			summaryToggle.hidden = false;
			summary.setAttribute("data-collapsed", "");
			summaryToggle.addEventListener("click", () => {
				const open = summaryToggle.getAttribute("aria-expanded") !== "true";
				summaryToggle.setAttribute("aria-expanded", String(open));
				summary.toggleAttribute("data-collapsed", !open);
			});
		}
		const panels = $$("[data-co-panel]", root);
		const steps = $$("[data-co-step]", root);
		const prev = $("[data-co-prev]", root), next = $("[data-co-next]", root), submit = $("[data-co-submit]", root);
		const submitAmount = $("[data-co-submit-amount]", root);
		const payBox = $("[data-co-pay]", root), offline = $("[data-co-offline]", root);
		const payError = $("[data-co-pay-error]", root);
		const adults = form.elements.odrasli, kids = form.elements.djeca, infants = form.elements.bebe;
		const cap = parseInt(adults.getAttribute("data-cap"), 10) || 1;
		const kidsMax = parseInt(kids.getAttribute("data-max"), 10) || 0;
		const couponBox = $("[data-coupon]", root);
		const couponInput = $("#b-coupon", root), couponMsg = $("[data-coupon-msg]", root);
		const couponEntry = $("[data-coupon-entry]", root), couponApplied = $("[data-coupon-applied]", root);
		const couponLive = $("[data-coupon-live]", root), terms = $("[data-co-terms]", root);
		const item = { item_id: root.getAttribute("data-code") || String(unit), item_name: root.getAttribute("data-name") || "" };

		let step = 0, quote = null, coupon = "", busy = false;
		let stripe = null, elements = null, paymentEl = null, offlineMode = !mbk.stripe();
		const tracked = {};

		/* --- steppers + draft --- */
		const steppers = $$("[data-qty]", form);
		steppers.forEach(mbk.initQty);
		const reveals = $$("[data-mbk-reveal]", form);
		reveals.forEach(mbk.initReveal);
		// phone with a country flag: the dial code is picked, the number typed next to it
		const phone = form.elements.telefon;
		mbk.initPhone(phone);
		// a new flag changes the number without an input event: recap, draft and an error shown keep up
		phone.addEventListener("countrychange", () => {
			mirrors();
			saveDraft();
			if (phone.getAttribute("aria-invalid")) setError(phone, checkField(phone));
		});
		function bounds() {
			const a = parseInt(adults.value, 10) || 1, c = parseInt(kids.value, 10) || 0;
			adults.max = String(Math.max(1, cap - c));
			kids.max = String(Math.max(0, Math.min(kidsMax, cap - a)));
			steppers.forEach(s => { if (s.mbkSync) s.mbkSync(); });
		}

		function restoreDraft() {
			const draft = store.get(DRAFT);
			if (draft && draft.fields) {
				DRAFT_FIELDS.forEach(name => {
					const el = form.elements[name], v = draft.fields[name];
					if (!el || v === undefined || v === "") return;
					if (el === phone && phone.mbkIti) phone.mbkIti.setNumber(v);
					else el.value = v;
				});
			}
			reveals.forEach(r => r.mbkSync && r.mbkSync());
		}
		const saveDraft = mbk.debounce(() => {
			const fields = {};
			DRAFT_FIELDS.forEach(name => { if (form.elements[name]) fields[name] = form.elements[name] === phone ? mbk.phoneNumber(phone) : form.elements[name].value; });
			store.set(DRAFT, { stay: stayKey, fields });
		}, 300);

		/* --- mirrors (recap + aside guests) --- */
		function mirrors() {
			$$("[data-mirror]", root).forEach(dd => {
				const text = dd.getAttribute("data-mirror").split(/\s+/).map(id => {
					const el = document.getElementById(id);
					if (!el) return "";
					if (el.tagName === "SELECT") return el.value ? el.options[el.selectedIndex].text : "";
					if (el === phone) return mbk.phoneNumber(phone);
					return el.value.trim();
				}).filter(Boolean).join(" ");
				dd.textContent = text || "—";
			});
			// children 0–4: only listed when travelling with them
			$$("[data-co-infants]", root).forEach(row => { row.hidden = !((parseInt(infants.value, 10) || 0) > 0); });
		}

		/* --- field errors --- */
		function fieldEl(field) { return $('[data-field="' + field + '"]', form); }
		function setError(el, msg) {
			if (!el) return;
			// error line after the field's box: a <p> may not sit inside the terms <label>
			const holder = el.closest("[data-field-box]") || el.parentNode;
			const inLabel = holder.tagName === "LABEL";
			const id = (el.id || el.name) + "-error";
			let p = document.getElementById(id);
			if (!msg) {
				el.removeAttribute("aria-invalid");
				if (p) p.remove();
				return;
			}
			if (!p) {
				p = document.createElement("p");
				p.className = "w-field__hint";
				p.setAttribute("data-field-error", "");
				p.id = id;
				if (inLabel) holder.after(p); else holder.appendChild(p);
			}
			p.textContent = msg;
			el.setAttribute("aria-invalid", "true");
			const described = (el.getAttribute("aria-describedby") || "").split(/\s+/).filter(Boolean);
			if (described.indexOf(p.id) < 0) el.setAttribute("aria-describedby", described.concat(p.id).join(" "));
		}
		function clearErrors() { $$("[data-field]", form).forEach(el => setError(el, "")); }
		function alert(msg) {
			alertEl.textContent = msg || "";
			alertEl.hidden = !msg;
		}

		const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;
		function checkField(el) {
			const v = el.value.trim();
			switch (el.getAttribute("data-field")) {
				case "guest.first_name":
				case "guest.last_name":
					return v ? "" : __("Please fill in this field.", "marclie-booking");
				case "guest.email":
					return !v ? __("Please fill in this field.", "marclie-booking") : EMAIL.test(v) ? "" : __("Enter a valid email address.", "marclie-booking");
				case "guest.phone": {
					if (!v) return __("Please fill in this field.", "marclie-booking");
					// libphonenumber (when loaded) knows the length of each country's numbers
					if (mbk.phoneReady(el)) return el.mbkIti.isValidNumber() ? "" : __("Enter a valid phone number for the chosen country.", "marclie-booking");
					const full = mbk.phoneNumber(el), digits = full.replace(/\D/g, "");
					return /^(\+|00)/.test(full) && /^[\d\s()+.\/-]+$/.test(full) && digits.length >= 8 && digits.length <= 15
						? "" : __("Enter a valid phone number for the chosen country.", "marclie-booking");
				}
				case "arrival_time":
					/* translators: 1: earliest check-in time, 2: latest check-in time. */
					return !v || ((!el.min || v >= el.min) && (!el.max || v <= el.max)) ? "" : sprintf(__("Choose a time between %1$s and %2$s.", "marclie-booking"), el.min, el.max);
				case "terms":
					return el.checked ? "" : __("Please accept the booking terms to continue.", "marclie-booking");
				default:
					return "";
			}
		}
		/** Validates the fields of a panel; focuses the first invalid one. */
		function validate(container) {
			let first = null;
			$$("[data-field]", container).forEach(el => {
				const msg = checkField(el);
				setError(el, msg);
				if (msg && !first) first = el;
			});
			if (first) first.focus();
			return !first;
		}

		/* --- states --- */
		/** Codes of dates that can never be booked (vs. `unavailable` = taken). */
		const UNBOOKABLE = ["min_stay", "no_rate", "past_date", "beyond_horizon", "invalid_stay"];

		/**
		 * Zauzeto / unbookable: swaps the wizard for the „Odaberi druge datume” block; the aside keeps
		 * only the stay price. `reason` = server message for dates that can never be booked.
		 */
		function showBooked(data, reason) {
			form.hidden = true;
			$$("[data-co-free]", root).forEach(el => { el.hidden = true; });
			$$("[data-co-booked]", root).forEach(el => { el.hidden = false; });
			// the page title's ledes live outside the widget (page document): [data-co-lede="free|booked"]
			$$("[data-co-lede]").forEach(el => { el.hidden = !!reason || el.getAttribute("data-co-lede") !== "booked"; });
			store.del(PENDING);
			if (reason) {
				$("[data-co-unavail-title]", root).textContent = __("These dates cannot be booked", "marclie-booking");
				$("[data-co-unavail-text]", root).textContent = reason + " " + __("Choose other dates in the apartment's calendar.", "marclie-booking");
				$("[data-co-unavail-search]", root).hidden = true;
				$("[data-co-check-off]", root).hidden = true;
				lines.replaceChildren();
				lines.removeAttribute("aria-busy");
			} else {
				const shown = data && data.display ? data : quote;
				if (shown) render(shown);
			}
			const block = $("[data-co-unavail]", root);
			if (block) { block.setAttribute("tabindex", "-1"); block.focus(); }
		}
		/** No Stripe: the notice (above the steps) replaces the card form, deposit note, terms and pay button. */
		function setOffline() {
			offlineMode = true;
			[payBox, depositEl, terms, submit].forEach(el => { if (el) el.hidden = true; });
			if (offline) offline.hidden = false;
			submit.disabled = true;
		}

		/* --- quote + aside --- */
		function render(data) {
			const d = data.display, q = data.quote;
			const pct = root.getAttribute("data-deposit-pct") || "";
			const rows = d.lines.map(l => row(l.label, l.amount, l.type === "coupon" ? "save" : ""));
			rows.push(row(__("Total", "marclie-booking"), d.total, "total"));
			if (form.hidden) {
				// zauzeto: price of the requested stay only
			} else if (q.pay_in_full) {
				rows.push(row(__("Due today", "marclie-booking"), d.deposit, "due"));
			} else {
				/* translators: %s: deposit percentage, e.g. "30 %". */
				rows.push(row(sprintf(__("Deposit today (%s)", "marclie-booking"), pct), d.deposit, "due"));
				/* translators: %s: balance due date. */
				rows.push(row(sprintf(__("Balance on %s", "marclie-booking"), d.balance_due_on || ""), d.balance));
			}
			lines.replaceChildren(...rows);
			lines.removeAttribute("aria-busy");
			// amounts and the balance date are in the summary lines and on the pay button: the note only says how
			depositEl.textContent = d.deposit_note || "";
			submitAmount.textContent = d.deposit;
			if (miniTotal) miniTotal.textContent = d.total;
			if (elements && q.deposit_cents > 0) elements.update({ amount: q.deposit_cents });
		}

		const fmtMoney = c => new Intl.NumberFormat(cfg.locale || "hr-HR", { style: "currency", currency: "EUR", minimumFractionDigits: c % 100 ? 2 : 0 }).format(c / 100);

		/** GA4 ecommerce of the current quote: same shape as `purchase` (item quantity = nights, deposit param). */
		function ecommerce(extra) {
			const q = quote && quote.quote;
			const total = q ? q.total_cents / 100 : 0, nights = (q && q.nights) || 1;
			return Object.assign({
				currency: "EUR",
				value: total,
				deposit: q ? q.deposit_cents / 100 : undefined,
				coupon: (q && q.coupon_code) || undefined,
				items: [Object.assign({ price: Math.round(total / nights * 100) / 100, quantity: nights }, item)]
			}, extra || {});
		}
		function track(event, extra) {
			if (tracked[event] || !quote) return;
			tracked[event] = mbk.track(event, ecommerce(extra));
		}

		/** The guest's own unexpired hold on this stay (after a declined card): it makes /quote report the nights as taken. */
		function ownHold() {
			const b = store.get(PENDING);
			return !!(b && b.stay === stayKey && b.hold_expires_at && Date.parse(b.hold_expires_at) > Date.now());
		}

		/**
		 * POST /quote with the party and coupon current when it runs; rejects with the API error.
		 * Requests run one after another, so responses are handled in the order they were asked for
		 * (a party change and a coupon can overlap) and the last one rendered is the newest.
		 */
		let quoteChain = Promise.resolve(), quotePending = 0;
		function fetchQuote(code) {
			lines.setAttribute("aria-busy", "true");
			quotePending++;
			const run = () => mbk.api("quote", {
				body: { unit_id: unit, arrival, departure, adults: parseInt(adults.value, 10) || 1, children: parseInt(kids.value, 10) || 0, infants: parseInt(infants.value, 10) || 0, coupon: code || null }
			});
			const p = quoteChain.then(run, run).finally(() => { if (--quotePending === 0) lines.removeAttribute("aria-busy"); });
			quoteChain = p.catch(() => {});
			return p;
		}

		/** Quotes, renders, and resolves true while the stay is still bookable. */
		async function requote() {
			let data;
			try {
				data = await fetchQuote(coupon);
			} catch (err) {
				if (err.code === "coupon_invalid" && coupon) {
					// a coupon that stopped applying is dropped, the stay stays bookable
					couponState("", err.message);
					return requote();
				}
				if (UNBOOKABLE.indexOf(err.code) > -1) { showBooked(null, err.message); return false; }
				alert(err.code && err.message ? err.message : __("The price could not be loaded. Please try again.", "marclie-booking"));
				return false;
			}
			if (!data || !data.display || (!data.available && !ownHold())) { showBooked(data); return false; }
			quote = data;
			render(data);
			alert("");
			return true;
		}

		function couponState(code, msg) {
			coupon = code;
			couponEntry.hidden = !!code;
			couponApplied.hidden = !code;
			$("[data-coupon-code]", couponApplied).textContent = code;
			couponMsg.textContent = msg || "";
		}
		function announce(msg) {
			if (!couponLive) return;
			couponLive.textContent = "";
			setTimeout(() => { couponLive.textContent = msg; }, 50);
		}

		if (couponBox) {
			const applyBtn = $("[data-coupon-apply]", couponBox), removeBtn = $("[data-coupon-remove]", couponBox);
			applyBtn.addEventListener("click", async () => {
				const code = couponInput.value.trim().toUpperCase();
				if (!code) { couponMsg.textContent = __("Enter a coupon code.", "marclie-booking"); couponInput.focus(); return; }
				if (applyBtn.disabled) return;
				couponMsg.textContent = "";
				applyBtn.disabled = true;
				try {
					const data = await fetchQuote(code);
					if (!data.available && !ownHold()) { showBooked(data); return; }
					quote = data;
					const applied = data.quote.coupon_code || code;
					couponState(applied, "");
					render(data);
					const line = data.display.lines.find(l => l.type === "coupon");
					announce(line
						/* translators: 1: coupon code, 2: discount amount. */
						? sprintf(__("Coupon %1$s applied: %2$s off.", "marclie-booking"), applied, line.amount.replace(/^[\u2212-]\s*/, ""))
						/* translators: %s: coupon code. */
						: sprintf(__("%s applied", "marclie-booking"), applied));
					removeBtn.focus();
				} catch (err) {
					if (UNBOOKABLE.indexOf(err.code) > -1) { showBooked(null, err.message); return; }
					couponMsg.textContent = err.code && err.message ? err.message : __("The coupon cannot be applied.", "marclie-booking");
				} finally {
					applyBtn.disabled = false;
				}
			});
			couponInput.addEventListener("keydown", e => {
				if (e.key === "Enter") { e.preventDefault(); applyBtn.click(); }
			});
			removeBtn.addEventListener("click", async () => {
				couponState("", "");
				couponInput.focus();
				await requote();
				announce(__("Coupon removed.", "marclie-booking"));
			});
		}

		/* --- Payment Element --- */
		function mountPayment() {
			if (offlineMode || elements || !quote) return;
			stripe = mbk.stripe();
			if (!stripe) { setOffline(); return; }
			const f = form.elements;
			elements = stripe.elements({
				mode: "payment",
				amount: quote.quote.deposit_cents,
				currency: "eur",
				setupFutureUsage: "off_session",
				allowedPaymentMethodTypes: ["card"], // Stripe.js Endive: `paymentMethodTypes` was removed
				locale: mbk.stripeLocale(),
				appearance: mbk.stripeAppearance()
			});
			paymentEl = elements.create("payment", {
				layout: "tabs",
				defaultValues: {
					billingDetails: {
						name: (f.ime.value + " " + f.prezime.value).trim(),
						email: f.email.value.trim(),
						phone: mbk.phoneNumber(f.telefon)
					}
				}
			});
			paymentEl.on("change", onPaymentChange);
			paymentEl.on("loaderror", () => setOffline());
			paymentEl.mount($("[data-co-element]", root));
			if (captcha) captcha.preload();
		}

		/*
		 * Bot check (Cloudflare Turnstile, cfg.turnstile = site key): every POST /bookings carries a fresh
		 * single-use token. The widget stays invisible unless Cloudflare wants an interaction.
		 */
		const captcha = cfg.turnstile ? (() => {
			let loading = null, widget = null, box = null, pending = null;
			function load() {
				if (!loading) {
					loading = new Promise((resolve, reject) => {
						if (window.turnstile) { resolve(window.turnstile); return; }
						const s = document.createElement("script");
						s.src = "https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit";
						s.async = true;
						s.onload = () => (window.turnstile ? resolve(window.turnstile) : s.onerror());
						s.onerror = () => { loading = null; s.remove(); reject(new Error("turnstile")); };
						document.head.appendChild(s);
					});
				}
				return loading;
			}
			function settle(fn, value) {
				if (!pending) return;
				const p = pending;
				pending = null;
				p[fn](value);
			}
			return {
				preload() { load().catch(() => {}); },
				async token() {
					const ts = await load();
					if (!box) {
						box = document.createElement("div");
						box.className = "w-checkout__captcha";
						(payBox || form).append(box);
					}
					return new Promise((resolve, reject) => {
						settle("reject", new Error("turnstile"));
						pending = { resolve, reject };
						if (widget === null) {
							widget = ts.render(box, {
								sitekey: cfg.turnstile,
								action: "booking",
								execution: "execute",
								appearance: "interaction-only",
								language: cfg.lang === "en" ? "en" : "hr",
								callback: t => settle("resolve", t),
								"error-callback": () => settle("reject", new Error("turnstile")),
								"timeout-callback": () => settle("reject", new Error("turnstile"))
							});
						} else {
							ts.reset(widget);
						}
						ts.execute(widget);
					});
				}
			};
		})() : null;

		/*
		 * [data-co-pay-error] (role=alert) shows a field error reported by Stripe on change ("stripe") or a
		 * message of our submit/decline path ("submit"). Stripe emits a change right after a failed
		 * confirmation, so ours is only cleared by the next submit, or once the guest has edited the card
		 * (an incomplete state) and Stripe reports the details complete again.
		 */
		let payErrSource = "", cardEdited = false;
		function onPaymentChange(e) {
			if (e.error) { payErr(e.error.message, "stripe"); return; }
			if (payErrSource === "stripe") { payErr(""); return; }
			if (payErrSource !== "submit") return;
			if (!e.complete) cardEdited = true;
			else if (cardEdited) payErr("");
		}
		function payErr(msg, source) {
			payError.textContent = msg || "";
			payError.hidden = !msg;
			payErrSource = msg ? source || "submit" : "";
			cardEdited = false;
		}

		/* --- steps + history (step 2 is a history entry: browser Back/Forward move between steps) --- */
		let afterBack = null; // {focus, alert} applied once the popstate to step 1 has rendered

		function go(i, fromHistory) {
			step = i;
			panels.forEach((p, k) => { p.hidden = k !== i; });
			steps.forEach((s, k) => {
				s.classList.toggle("is-done", k < i);
				s.classList.toggle("is-active", k === i);
				if (k === i) s.setAttribute("aria-current", "step"); else s.removeAttribute("aria-current");
			});
			prev.hidden = i === 0;
			next.hidden = i !== 0;
			submit.hidden = i !== 1 || offlineMode;
			alert("");
			if (i === 1) {
				mirrors();
				mountPayment();
				if (!offlineMode) track("add_payment_info", { payment_type: "card" });
				if (!fromHistory) history.pushState({ mbkStep: 1 }, "");
			}
			const h = $("h2", panels[i]);
			form.scrollIntoView({ block: "start", behavior: "smooth" });
			if (h) h.focus({ preventScroll: true });
		}

		/** Step 1 → 2: validates, re-quotes (amount + availability), then shows the payment step. */
		async function enterPayment(fromHistory) {
			if (busy) return;
			clearErrors();
			let ok = validate(panels[0]);
			if (!ok) alert(__("Please check the highlighted fields.", "marclie-booking"));
			else {
				setBusy(true, next);
				ok = await requote();
				setBusy(false, next);
			}
			if (ok) go(1, fromHistory);
			else if (fromHistory) history.replaceState({ mbkStep: 0 }, "");
		}

		/** Back to step 1 through history (one Back press per step), then focus/alert. */
		function backToDetails(focusEl, msg) {
			if (history.state && history.state.mbkStep === 1) {
				afterBack = { focus: focusEl, alert: msg };
				history.back();
				return;
			}
			go(0, true);
			if (msg) alert(msg);
			if (focusEl) focusEl.focus();
		}

		window.addEventListener("popstate", e => {
			if (busy || form.hidden) return;
			const target = e.state && e.state.mbkStep === 1 ? 1 : 0;
			if (target === 1 && step !== 1) { enterPayment(true); return; }
			if (target === 0 && step !== 0) go(0, true);
			if (afterBack) {
				if (afterBack.alert) alert(afterBack.alert);
				if (afterBack.focus) afterBack.focus.focus();
				afterBack = null;
			}
		});

		next.addEventListener("click", () => enterPayment(false));
		prev.addEventListener("click", () => backToDetails(null, ""));
		$$("[data-co-goto]", root).forEach(b => b.addEventListener("click", () => prev.click()));

		function setBusy(on, btn) {
			busy = on;
			form.setAttribute("aria-busy", on ? "true" : "false");
			[prev, next, submit].forEach(b => { b.disabled = on || (b === submit && offlineMode); });
			if (btn) btn.classList.toggle("is-loading", on);
		}

		/* --- submit --- */
		/** Everything the booking stores: a change after a declined card needs a new booking (superseding the old hold). */
		function signature(b) {
			return JSON.stringify([b.unit_id, b.arrival, b.departure, b.adults, b.children, b.infants, b.coupon, b.guest, b.arrival_time, b.message]);
		}
		function body() {
			const f = form.elements;
			return {
				unit_id: unit,
				arrival,
				departure,
				adults: parseInt(adults.value, 10) || 1,
				children: parseInt(kids.value, 10) || 0,
				infants: parseInt(infants.value, 10) || 0,
				coupon: coupon || null,
				guest: {
					first_name: f.ime.value.trim(),
					last_name: f.prezime.value.trim(),
					email: f.email.value.trim(),
					phone: mbk.phoneNumber(f.telefon)
				},
				arrival_time: f["vrijeme-dolaska"].value || null,
				message: f.napomena.value.trim() || null,
				terms: f.uvjeti.checked,
				website: f.website.value
			};
		}

		function apiError(err) {
			const fields = (err.data && err.data.fields) || null;
			if (err.status === 409 || err.code === "unavailable") { showBooked(); return; }
			if (UNBOOKABLE.indexOf(err.code) > -1) { showBooked(null, err.message); return; }
			if (err.code === "payments_unavailable" || err.status === 503) { setOffline(); alert(""); return; }
			if (fields && typeof fields === "object") {
				let first = null, onStep1 = false;
				Object.keys(fields).forEach(key => {
					const el = fieldEl(key) || fieldEl("guest." + key);
					if (!el) return;
					setError(el, String(fields[key]));
					if (!first) first = el;
					if (STEP1_FIELDS.indexOf(el.getAttribute("data-field")) > -1) onStep1 = true;
				});
				const msg = __("Please check the highlighted fields.", "marclie-booking");
				if (onStep1) { backToDetails(first, msg); return; }
				if (first) first.focus();
				alert(msg);
				return;
			}
			if (err.status === 429 || err.code === "rate_limited") {
				alert(err.message || __("Too many attempts. Please wait a few minutes and try again.", "marclie-booking"));
				return;
			}
			alert(err.code && err.message ? err.message : __("The booking could not be completed. Please try again.", "marclie-booking"));
		}

		// Enter in a step-1 field means „Nastavi na plaćanje” (implicit submission would hit the hidden/disabled pay button)
		form.addEventListener("keydown", e => {
			if (e.key !== "Enter" || step !== 0 || e.target.tagName !== "INPUT" || e.target.type === "checkbox") return;
			e.preventDefault();
			enterPayment(false);
		});

		form.addEventListener("submit", async e => {
			e.preventDefault();
			if (busy) return;
			if (step === 0) { enterPayment(false); return; }
			alert("");
			payErr("");
			if (offlineMode || !elements) { setOffline(); return; }
			if (!validate(panels[1])) return;
			setBusy(true, submit);
			try {
				const submitted = await elements.submit();
				if (submitted.error) { payErr(submitted.error.message); return; }

				const payload = body();
				const sig = signature(payload);
				let booking = store.get(PENDING);
				// a declined card keeps the hold + PaymentIntent of the first attempt: retry on it while the hold lasts
				const holdLeft = booking && booking.hold_expires_at ? Date.parse(booking.hold_expires_at) - Date.now() : 0;
				if (!booking || booking.sig !== sig || !(holdLeft > 60000)) {
					// changed details (or a hold about to end): new booking that releases the previous hold
					const supersede = booking && booking.ref && booking.token ? { ref: booking.ref, t: booking.token } : null;
					try {
						const extra = {};
						if (supersede) extra.supersede = supersede;
						if (captcha) extra.captcha = await captcha.token();
						booking = await mbk.api("bookings", { body: Object.assign(extra, payload) });
					} catch (err) {
						apiError(err);
						return;
					}
					booking.sig = sig;
					booking.stay = stayKey;
					store.set(PENDING, booking);
				}
				// the server re-quotes: a different amount is shown and confirmed before charging it
				if (booking.amount_cents && quote && booking.amount_cents !== quote.quote.deposit_cents && !booking.amountSeen) {
					booking.amountSeen = true;
					store.set(PENDING, booking);
					if (!(await requote())) return;
					elements.update({ amount: booking.amount_cents });
					const amount = fmtMoney(booking.amount_cents);
					submitAmount.textContent = amount;
					/* translators: %s: amount charged now. */
					alert(sprintf(__("The amount due today is %s. Please check it and confirm again.", "marclie-booking"), amount));
					return;
				}

				const result = await stripe.confirmPayment({
					elements,
					clientSecret: booking.client_secret,
					confirmParams: { return_url: booking.return_url }
				});
				// only reached on an immediate error; success redirects to return_url
				if (result.error) {
					// already paid (e.g. the redirect of an earlier attempt never finished): the thank-you page knows
					if (result.error.code === "payment_intent_unexpected_state") { location.assign(booking.return_url); return; }
					const t = result.error.type;
					payErr(t === "card_error" || t === "validation_error"
						? result.error.message
						: __("The payment could not be completed. Please try again or use another card.", "marclie-booking"));
				}
			} catch (err) {
				alert(__("The booking could not be completed. Please try again.", "marclie-booking"));
			} finally {
				setBusy(false, submit);
			}
		});

		form.addEventListener("input", e => {
			saveDraft();
			if (e.target.getAttribute("aria-invalid")) setError(e.target, checkField(e.target));
		});
		form.addEventListener("change", e => {
			saveDraft();
			mirrors();
			if (e.target === form.elements.uvjeti) setError(e.target, checkField(e.target));
		});
		// The party sets the price (charged persons): re-quote the free stay, and keep it in the URL
		// so a reload or the login round trip shows the same party.
		let partyTimer = 0;
		[adults, kids, infants].forEach(i => i.addEventListener("change", () => {
			bounds();
			const url = new URL(location.href);
			url.searchParams.set("odrasli", adults.value);
			url.searchParams.set("djeca", kids.value);
			if ((parseInt(infants.value, 10) || 0) > 0) url.searchParams.set("bebe", infants.value);
			else url.searchParams.delete("bebe");
			history.replaceState(history.state, "", url.toString());
			if (state === "free" && quote) { clearTimeout(partyTimer); partyTimer = setTimeout(requote, 250); }
		}));

		// "Have an account? Log in" comes back to this exact checkout (same-origin, checked by the login page).
		const loginLink = $("[data-mbk-login-link]", root);
		if (loginLink) loginLink.addEventListener("click", () => {
			const u = new URL(loginLink.href);
			u.searchParams.set("redirect", location.href.split("#")[0]);
			loginLink.href = u.toString();
		});

		restoreDraft();
		bounds();
		mirrors();
		if (offlineMode) setOffline();
		if (history.state && history.state.mbkStep === 1) history.replaceState({ mbkStep: 0 }, "");
		if (state === "free") {
			requote().then(ok => { if (ok) track("begin_checkout"); });
		} else {
			// booked: the aside still shows the price of the requested stay
			fetchQuote("").then(data => { if (data && data.display) render(data); }).catch(() => lines.replaceChildren());
		}
	}

	mbk.ready(() => $$("[data-mbk-checkout]").forEach(init));
})();
