/**
 * Marclie Booking — shared frontend helpers (window.mbk): config, DOM helpers, REST client, steppers.
 * Classic deferred script; widget scripts depend on it.
 */
(function () {
	"use strict";

	const cfg = window.mbkFront || {};
	const mbk = (window.mbk = window.mbk || {});
	let domReady = document.readyState !== "loading";
	document.addEventListener("DOMContentLoaded", () => { domReady = true; });

	mbk.cfg = cfg;

	/** Today (00:00 local Date) in the site timezone — computed per visit, never baked into cached HTML. */
	mbk.today = (() => {
		try {
			const parts = new Intl.DateTimeFormat("en-CA", { timeZone: cfg.timeZone || undefined, year: "numeric", month: "2-digit", day: "2-digit" }).formatToParts(new Date());
			const get = type => parseInt(parts.find(p => p.type === type).value, 10);
			return new Date(get("year"), get("month") - 1, get("day"));
		} catch (e) {
			const t = new Date();
			t.setHours(0, 0, 0, 0);
			return t;
		}
	})();
	mbk.$ = (sel, root) => (root || document).querySelector(sel);
	mbk.$$ = (sel, root) => Array.from((root || document).querySelectorAll(sel));

	/** Runs after every deferred widget script has executed (in registration order). */
	mbk.ready = fn => {
		if (domReady) fn();
		else document.addEventListener("DOMContentLoaded", fn);
	};

	/**
	 * Shows the `[attr="name"]` state panel of root and hides the others. The page keeps exactly one
	 * <h1>: the shown panel's [data-state-title] heading (h2 in the markup) becomes h1, the rest h2.
	 * Returns that heading (or null).
	 */
	mbk.showState = (root, attr, name) => {
		let title = null;
		mbk.$$("[" + attr + "]", root).forEach(panel => {
			const on = panel.getAttribute(attr) === name;
			panel.hidden = !on;
			mbk.$$("[data-state-title]", panel).forEach(h => {
				const tag = on ? "H1" : "H2";
				let el = h;
				if (h.tagName !== tag) {
					el = document.createElement(tag);
					Array.from(h.attributes).forEach(a => el.setAttribute(a.name, a.value));
					while (h.firstChild) el.appendChild(h.firstChild);
					h.replaceWith(el);
				}
				if (on && !title) title = el;
			});
		});
		return title;
	};

	mbk.debounce = (fn, ms) => {
		let t = 0;
		const run = (...args) => { clearTimeout(t); t = setTimeout(() => fn(...args), ms); };
		run.cancel = () => clearTimeout(t);
		return run;
	};

	function endpoint(path, params) {
		const root = cfg.restRoot || "/wp-json/marclie-booking/v1/";
		let url;
		if (root.indexOf("rest_route=") > -1) {
			url = new URL(root, location.href);
			url.searchParams.set("rest_route", url.searchParams.get("rest_route").replace(/\/?$/, "/") + path);
		} else url = new URL(root + path, location.href);
		Object.keys(params || {}).forEach(k => url.searchParams.set(k, params[k]));
		return url.toString();
	}

	/**
	 * REST call; rejects with an Error carrying code/data/status of the WP_Error body. A logged-in
	 * visitor's calls carry the `wp_rest` nonce (window.mbkFront.account), so the routes see the user
	 * (account routes, booking linked to the account); anonymous pages never send one (page cache).
	 * @param {string} path e.g. "availability"
	 * @param {{params?: object, body?: object, method?: string, signal?: AbortSignal}} [opts]
	 */
	mbk.api = async (path, opts) => {
		const o = opts || {};
		const headers = { "X-MBK-Lang": cfg.lang || "hr", Accept: "application/json" };
		if (o.body) headers["Content-Type"] = "application/json";
		const account = cfg.account || {};
		if (account.loggedIn && account.nonce) headers["X-WP-Nonce"] = account.nonce;
		const res = await fetch(endpoint(path, o.params), {
			method: o.method || (o.body ? "POST" : "GET"),
			headers,
			body: o.body ? JSON.stringify(o.body) : undefined,
			signal: o.signal,
			credentials: "same-origin"
		});
		let data = null;
		try { data = await res.json(); } catch (e) { data = null; }
		if (!res.ok) {
			const err = new Error((data && data.message) || res.statusText);
			err.code = data && data.code;
			err.data = (data && data.data) || {};
			err.status = res.status;
			throw err;
		}
		return data;
	};

	/*
	 * Analytics consent bridge (contract for the site's cookie banner, see phase-09 doc):
	 * cookie `mbk_consent` = URI-encoded JSON `{"analytics":true|false}` (6 months, SameSite=Lax);
	 * `window.mbkConsent` mirrors it (default `{analytics:false}`); `mbk.setConsent({analytics})`
	 * writes the cookie and dispatches `mbk:consent` (detail = the consent) on window. A banner
	 * that writes the cookie itself dispatches the same event; this listener applies its detail.
	 */
	const CONSENT_COOKIE = "mbk_consent";
	const CONSENT_MAX_AGE = 15552000; // 180 days
	const normConsent = c => ({ analytics: !!(c && c.analytics === true) });
	function readConsent() {
		const m = document.cookie.match(/(?:^|;\s*)mbk_consent=([^;]*)/);
		if (!m) return null;
		try { return normConsent(JSON.parse(decodeURIComponent(m[1]))); } catch (e) { return null; }
	}
	window.mbkConsent = readConsent() || normConsent(window.mbkConsent);

	mbk.analyticsAllowed = () => !!(window.mbkConsent && window.mbkConsent.analytics === true);

	/** Stores the visitor's choice (cookie, 6 months) and notifies listeners (`mbk:consent`). */
	mbk.setConsent = consent => {
		const c = normConsent(consent);
		document.cookie = CONSENT_COOKIE + "=" + encodeURIComponent(JSON.stringify(c)) + "; Max-Age=" + CONSENT_MAX_AGE + "; Path=/; SameSite=Lax" + (location.protocol === "https:" ? "; Secure" : "");
		window.mbkConsent = c;
		window.dispatchEvent(new CustomEvent("mbk:consent", { detail: c }));
		return c;
	};
	window.addEventListener("mbk:consent", e => {
		window.mbkConsent = e.detail ? normConsent(e.detail) : readConsent() || normConsent(window.mbkConsent);
	});

	/**
	 * GA4 ecommerce event into dataLayer (only with analytics consent); returns whether it was pushed.
	 * @param {string} event e.g. "begin_checkout"
	 * @param {object} ecommerce
	 */
	mbk.track = (event, ecommerce) => {
		if (!mbk.analyticsAllowed()) return false;
		const dl = (window.dataLayer = window.dataLayer || []);
		dl.push({ ecommerce: null });
		dl.push({ event, ecommerce });
		return true;
	};

	/**
	 * Booking link params of /rezervacija/hvala/ and /placanje/ (?ref&t): the token moves to
	 * sessionStorage (per ref) and leaves the URL together with Stripe's return params
	 * (payment_intent, payment_intent_client_secret, redirect_status), so neither lands in history,
	 * analytics page_location or a Referer. A reload reads the token back.
	 * @return {{ref: string, token: string, redirectStatus: string}}
	 */
	mbk.bookingLink = () => {
		const url = new URL(location.href);
		const p = url.searchParams;
		const ref = p.get("ref") || "";
		const key = "mbk-t-" + ref;
		let token = p.get("t") || "";
		try {
			if (token) sessionStorage.setItem(key, token);
			else token = sessionStorage.getItem(key) || "";
		} catch (e) { /* storage blocked: keep the token in the URL */ }
		const redirectStatus = p.get("redirect_status") || "";
		const stored = (() => { try { return sessionStorage.getItem(key) === token; } catch (e) { return false; } })();
		["payment_intent", "payment_intent_client_secret", "redirect_status"].concat(stored ? ["t"] : []).forEach(k => p.delete(k));
		if (url.href !== location.href) history.replaceState(history.state, "", url.pathname + url.search + url.hash);
		return { ref, token, redirectStatus };
	};

	/** Stripe instance (null without keys or when Stripe.js did not load). */
	let stripe;
	mbk.stripe = () => {
		if (stripe === undefined) {
			const key = cfg.stripe && cfg.stripe.publishableKey;
			stripe = key && typeof window.Stripe === "function" ? window.Stripe(key, { locale: mbk.stripeLocale() }) : null;
		}
		return stripe;
	};
	mbk.stripeLocale = () => (cfg.lang === "en" ? "en" : "hr");

	/** Any CSS colour (incl. var()/oklch()) as #rrggbb, which the Stripe Appearance API accepts. */
	function cssColor(expr, fallback) {
		try {
			const probe = document.createElement("span");
			probe.style.color = fallback;
			probe.style.color = expr;
			document.body.appendChild(probe);
			const value = getComputedStyle(probe).color;
			probe.remove();
			const ctx = document.createElement("canvas").getContext("2d");
			ctx.fillStyle = fallback;
			ctx.fillStyle = value;
			ctx.fillRect(0, 0, 1, 1);
			const [r, g, b] = ctx.getImageData(0, 0, 1, 1).data;
			return "#" + [r, g, b].map(n => n.toString(16).padStart(2, "0")).join("");
		} catch (e) {
			return fallback;
		}
	}

	/** Payment Element appearance from the brand tokens of the theme. */
	mbk.stripeAppearance = () => {
		const body = getComputedStyle(document.body);
		// The theme font is not loaded inside Stripe's iframes: without a generic fallback the
		// fields render in the browser's default serif.
		const family = /\b(sans-serif|serif|monospace|system-ui)\b/.test(body.fontFamily)
			? body.fontFamily
			: body.fontFamily + ', system-ui, -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif';
		return {
			theme: "stripe",
			variables: {
				colorPrimary: cssColor("var(--btn-bg, #111111)", "#111111"),
				colorText: cssColor("var(--text-body, " + body.color + ")", "#1a1a1a"),
				colorBackground: cssColor("var(--bg-surface, #ffffff)", "#ffffff"),
				colorDanger: cssColor("var(--error, #b3261e)", "#b3261e"),
				fontFamily: family,
				borderRadius: "8px"
			}
		};
	};

	/**
	 * [data-qty] stepper: buttons [data-step="±1"] around a number input; bounds follow the input's
	 * min/max attributes, which callers may change (bookbox: adults + children ≤ capacity).
	 */
	mbk.initQty = root => {
		const input = mbk.$("input", root);
		const btns = mbk.$$("[data-step]", root);
		if (!input || !btns.length || root.mbkQty) return;
		root.mbkQty = true;
		const min = () => parseInt(input.min || "0", 10);
		const max = () => (input.max === "" ? Infinity : parseInt(input.max, 10));
		const sync = () => {
			const v = parseInt(input.value, 10);
			btns.forEach(b => { b.disabled = parseInt(b.getAttribute("data-step"), 10) > 0 ? v >= max() : v <= min(); });
		};
		btns.forEach(b => b.addEventListener("click", e => {
			e.preventDefault();
			const cur = parseInt(input.value, 10);
			input.value = Math.min(max(), Math.max(min(), (isNaN(cur) ? min() : cur) + parseInt(b.getAttribute("data-step"), 10)));
			input.dispatchEvent(new Event("change", { bubbles: true }));
		}));
		input.addEventListener("change", () => {
			const v = parseInt(input.value, 10);
			input.value = isNaN(v) ? min() : Math.min(max(), Math.max(min(), v));
			sync();
		});
		input.addEventListener("input", sync);
		root.mbkSync = sync;
		sync();
	};

	/**
	 * [data-mbk-reveal] checkbox showing the stepper box named by its aria-controls (children 0–4):
	 * ticked → box shown, count starts at 1 and cannot go below 1; unticked → box hidden, count 0.
	 * Either way the input fires "change" (re-quote). `box.mbkSync()` re-reads a restored count.
	 */
	mbk.initReveal = box => {
		const target = document.getElementById(box.getAttribute("aria-controls") || "");
		const input = target && mbk.$("input", target);
		if (!input || box.mbkReveal) return;
		box.mbkReveal = true;
		const show = on => {
			box.checked = on;
			target.hidden = !on;
			input.min = on ? "1" : "0";
		};
		box.addEventListener("change", () => {
			show(box.checked);
			input.value = box.checked ? "1" : "0";
			input.dispatchEvent(new Event("change", { bubbles: true }));
		});
		box.mbkSync = () => show((parseInt(input.value, 10) || 0) > 0);
		box.mbkSync();
	};

	/**
	 * Phone input with a country flag and dial code (intl-tel-input, vendored; libphonenumber `utils.js`
	 * loads after the page). HR: Croatia preselected; EN: the region of the browser language, else none
	 * (the guest picks a flag or types +…). Without the library the input stays a plain text field.
	 *
	 * @returns the instance (also `input.mbkIti`) or null.
	 */
	mbk.initPhone = input => {
		const iti = window.intlTelInput;
		if (!input || typeof iti !== "function") return null;
		if (input.mbkIti) return input.mbkIti;
		const lang = cfg.lang === "en" ? "en" : "hr";
		const region = lang === "hr" ? "hr" : (Array.from(navigator.languages || [navigator.language || ""])
			.map(l => (String(l).split("-")[1] || "").toLowerCase()).find(r => /^[a-z]{2}$/.test(r)) || "");
		const { __, sprintf } = window.wp.i18n;
		input.mbkIti = iti(input, {
			initialCountry: region,
			countryNameLocale: lang,
			separateDialCode: true,
			placeholderNumberPolicy: "AGGRESSIVE",
			uiTranslations: {
				/* translators: keep ${countryName} and ${dialCode} as they are (filled in by the phone field). */
				selectedCountryAriaLabel: __("Change the country of the phone number, now ${countryName} (${dialCode})", "marclie-booking"),
				noCountrySelected: __("Choose the country of the phone number", "marclie-booking"),
				countryListAriaLabel: __("Countries", "marclie-booking"),
				searchPlaceholder: __("Search countries", "marclie-booking"),
				clearSearchAriaLabel: __("Clear the search", "marclie-booking"),
				closeCountrySelectorAriaLabel: __("Close", "marclie-booking"),
				searchEmptyState: __("No country found", "marclie-booking"),
				/* translators: %d: number of countries found. */
				searchSummaryAria: count => sprintf(window.wp.i18n._n("%d country found", "%d countries found", count, "marclie-booking"), count)
			},
			loadUtils: () => import(cfg.phoneUtils)
		});
		return input.mbkIti;
	};

	/** True when the libphonenumber utils have loaded (validation and formatting available). */
	mbk.phoneReady = input => !!(input && input.mbkIti && window.intlTelInput && window.intlTelInput.utils);

	/**
	 * The full number of a phone input: "+44 20 7946 0958" (international format) once the utils are loaded,
	 * before that the dial code + what was typed; a plain input returns its value.
	 */
	mbk.phoneNumber = input => {
		if (!input) return "";
		const typed = input.value.trim();
		const iti = input.mbkIti;
		if (!iti || !typed) return typed;
		if (mbk.phoneReady(input)) return iti.getNumber(window.intlTelInput.NUMBER_FORMAT.INTERNATIONAL) || typed;
		const country = iti.getSelectedCountry();
		return typed.startsWith("+") || !country || !country.dialCode ? typed : "+" + country.dialCode + " " + typed;
	};
})();
