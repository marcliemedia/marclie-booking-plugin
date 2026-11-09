/**
 * Marclie Booking — guest account ([data-mbk-account]): log in, sign up, forgotten/new password,
 * and the my-account board (hash tabs #overview … #profile, profile + newsletter, password change,
 * card update via Stripe SetupIntent, cancellation request, account deletion, coupon copy).
 *
 * REST: POST account/login|register|verify|password/forgot|password/reset|password|delete|card/setup-intent|
 * card/confirm|bookings/{ref}/cancel-request, PATCH account/profile. Logged-in calls carry the
 * `wp_rest` nonce of window.mbkFront.account (front.js). Validation errors (422 `validation`)
 * arrive as data.fields {name: message}; everything else as a translated message.
 */
(function () {
	"use strict";

	const mbk = window.mbk;
	const { __, sprintf } = window.wp.i18n;
	const { $, $$, cfg } = mbk;

	const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;
	const MIN_PASSWORD = 8;

	/* --- forms ------------------------------------------------------------------------------- */

	function setError(el, msg) {
		if (!el) return;
		const holder = el.closest("[data-acct-field]") || el.closest("[data-acct-check]") || el.parentNode;
		const id = (el.id || el.name) + "-error";
		let p = document.getElementById(id);
		const described = (el.getAttribute("aria-describedby") || "").split(/\s+/).filter(Boolean);
		if (!msg) {
			el.removeAttribute("aria-invalid");
			if (p) p.remove();
			el.setAttribute("aria-describedby", described.filter(d => d !== id).join(" "));
			if (!el.getAttribute("aria-describedby")) el.removeAttribute("aria-describedby");
			return;
		}
		if (!p) {
			p = document.createElement("p");
			p.className = "w-field__hint";
			p.setAttribute("data-field-error", "");
			p.id = id;
			// a <p> may not sit inside a <label>: checkbox errors follow the label.
			if (holder.hasAttribute("data-acct-check")) holder.after(p);
			else holder.appendChild(p);
		}
		p.textContent = msg;
		el.setAttribute("aria-invalid", "true");
		if (described.indexOf(id) < 0) el.setAttribute("aria-describedby", described.concat(id).join(" "));
	}

	function showAlert(form, msg) {
		const el = $("[data-acct-alert]", form);
		if (!el) return;
		el.textContent = msg || "";
		el.hidden = !msg;
	}

	function checkField(el) {
		const v = el.type === "checkbox" ? el.checked : el.value.trim();
		switch (el.getAttribute("data-field")) {
			case "first_name":
			case "last_name":
			case "current":
				return v ? "" : __("Please fill in this field.", "marclie-booking");
			case "email":
				return !v ? __("Please fill in this field.", "marclie-booking") : EMAIL.test(v) ? "" : __("Enter a valid email address.", "marclie-booking");
			case "password":
			case "new":
				if (!el.value) return __("Please fill in this field.", "marclie-booking");
				return el.hasAttribute("minlength") && el.value.length < MIN_PASSWORD ? __("Use at least 8 characters.", "marclie-booking") : "";
			case "phone": {
				if (!v) return "";
				const digits = v.replace(/\D/g, "");
				return /^(\+|00)/.test(v) && /^[\d\s()+.\/-]+$/.test(v) && digits.length >= 8 && digits.length <= 15
					? "" : __("Enter your phone number with the country code, e.g. +44 20 7946 0958.", "marclie-booking");
			}
			case "terms":
				return v ? "" : __("Please accept the booking terms and the privacy policy.", "marclie-booking");
			case "confirm":
				return v === "DELETE" ? "" : __("Type DELETE in capital letters to confirm.", "marclie-booking");
			default:
				return "";
		}
	}

	/** Validates every [data-field] of the form; focuses the first invalid one. */
	function validate(form) {
		let first = null;
		$$("[data-field]", form).forEach(el => {
			const msg = checkField(el);
			setError(el, msg);
			if (msg && !first) first = el;
		});
		if (first) first.focus();
		return !first;
	}

	/** Form values; checkboxes as booleans. */
	function values(form) {
		const out = {};
		$$("input[name], select[name], textarea[name]", form).forEach(el => {
			if (el.name === "username") return;
			out[el.name] = el.type === "checkbox" ? el.checked : el.value.trim();
		});
		return out;
	}

	function message(err) {
		if (err && err.status === 429) return __("Too many attempts. Please wait a few minutes and try again.", "marclie-booking");
		if (err && err.message && err.status) return err.message;
		return __("We could not reach the server. Check your connection and try again.", "marclie-booking");
	}

	/** Server errors: per-field messages (data.fields) on their inputs, the rest in the alert. */
	function serverError(form, err) {
		const fields = (err && err.data && err.data.fields) || {};
		let first = null;
		Object.keys(fields).forEach(name => {
			const el = $('[data-field="' + name + '"]', form) || $('[name="' + name + '"]', form);
			if (el && el.type !== "hidden") {
				setError(el, fields[name]);
				if (!first) first = el;
			}
		});
		showAlert(form, first ? "" : message(err));
		if (first) first.focus();
		else {
			const alertEl = $("[data-acct-alert]", form);
			if (alertEl) alertEl.scrollIntoView({ block: "nearest" });
		}
	}

	/**
	 * Submit handler: client validation, busy button, server errors mapped back to the form.
	 * @param {HTMLFormElement} form
	 * @param {(data: object) => Promise<void>} send
	 */
	function onSubmit(form, send) {
		let busy = false;
		form.addEventListener("submit", async e => {
			e.preventDefault();
			if (busy) return;
			showAlert(form, "");
			if (!validate(form)) return;
			const btn = $('button[type="submit"]', form);
			busy = true;
			if (btn) { btn.disabled = true; btn.classList.add("is-loading"); btn.setAttribute("aria-busy", "true"); }
			try {
				await send(values(form));
			} catch (err) {
				serverError(form, err);
			} finally {
				busy = false;
				if (btn) { btn.disabled = false; btn.classList.remove("is-loading"); btn.removeAttribute("aria-busy"); }
			}
		});
		form.addEventListener("input", e => {
			if (e.target.getAttribute && e.target.getAttribute("aria-invalid")) setError(e.target, checkField(e.target));
		});
		form.addEventListener("change", e => {
			if (e.target.type === "checkbox" && e.target.getAttribute("aria-invalid")) setError(e.target, checkField(e.target));
		});
	}

	const post = (path, body) => mbk.api("account/" + path, { body: body || {} });

	/** Shows a [data-acct-done] confirmation and moves focus to it. */
	function done(scope, name) {
		const el = $('[data-acct-done="' + name + '"]', scope);
		if (!el) return;
		el.hidden = false;
		if (!el.hasAttribute("tabindex")) el.setAttribute("tabindex", "-1");
		el.focus();
	}

	/* --- auth pages -------------------------------------------------------------------------- */

	function initForgot(root) {
		$$('[data-acct-form="forgot"]', root).forEach(form => {
			onSubmit(form, async data => {
				await post("password/forgot", { email: data.email, lang: cfg.lang });
				form.hidden = true;
				done(form.parentNode, "forgot");
			});
		});
	}

	function initLogin(root) {
		const form = $('[data-acct-form="login"]', root);
		onSubmit(form, async data => {
			await post("login", { email: data.email, password: $('[name="password"]', form).value, remember: !!data.remember });
			location.assign(root.getAttribute("data-redirect") || "/");
		});
		initForgot(root);
	}

	function initRegister(root) {
		const form = $('[data-acct-form="register"]', root);
		onSubmit(form, async data => {
			await post("register", {
				first_name: data.first_name,
				last_name: data.last_name,
				email: data.email,
				password: $('[name="password"]', form).value,
				terms: !!data.terms,
				newsletter: !!data.newsletter,
				website: data.website || "",
				lang: cfg.lang || "hr"
			});
			const text = $("[data-acct-sent-text]", root);
			text.textContent = sprintf(text.getAttribute("data-template"), data.email);
			// One <h1> on the page: the shown step's title becomes h1, the hidden one h2.
			const title = mbk.showState(root, "data-acct-step", "sent");
			if (title) title.focus();
		});
	}

	/**
	 * Email confirmation: the sign-up password + the link token (removed from the address bar).
	 * Wrong password → field error; a dead token (unknown/used/expired, too many attempts, or the
	 * address got an account meanwhile) → the invalid-link state.
	 */
	function initVerify(root) {
		const url = new URL(location.href);
		if (url.searchParams.has("verify")) {
			url.searchParams.delete("verify");
			history.replaceState(history.state, "", url.pathname + url.search + url.hash);
		}

		// The widget owns the page <h1> only when it renders the whole account page.
		const ownsH1 = !!$("h1[data-state-title]", root);
		const invalid = () => {
			let title;
			if (ownsH1) title = mbk.showState(root, "data-acct-step", "invalid");
			else {
				$$("[data-acct-step]", root).forEach(el => { el.hidden = el.getAttribute("data-acct-step") !== "invalid"; });
				title = $('[data-acct-step="invalid"] [data-state-title]', root);
			}
			if (title) title.focus();
		};

		const form = $('[data-acct-form="verify"]', root);
		if (!form) return;
		onSubmit(form, async data => {
			try {
				await post("verify", { token: data.token, password: $('[name="password"]', form).value });
			} catch (err) {
				const dead = err && (err.code === "invalid_token" || err.code === "already_verified");
				const lastTry = err && err.data && err.data.attempts_left === 0;
				if (dead || lastTry) { invalid(); return; }
				throw err;
			}
			location.assign(root.getAttribute("data-done"));
		});
	}

	function initReset(root) {
		// The key must not linger in the address bar, history or a Referer.
		const url = new URL(location.href);
		["login", "key"].forEach(k => url.searchParams.delete(k));
		if (url.href !== location.href) history.replaceState(history.state, "", url.pathname + url.search + url.hash);

		const form = $('[data-acct-form="reset"]', root);
		onSubmit(form, async data => {
			try {
				await post("password/reset", { login: data.login, key: data.key, password: $('[name="password"]', form).value });
			} catch (err) {
				if (err && err.status && !(err.data && err.data.fields)) $("[data-acct-expired]", root).hidden = false;
				throw err;
			}
			location.assign(root.getAttribute("data-done"));
		});
	}

	/* --- my-account board -------------------------------------------------------------------- */

	/** Hash tabs (tablist with roving tabindex); #bookings etc. selects a tab, also from in-page links. */
	function initTabs(board) {
		const nav = $("[data-acct-tabs]", board);
		const tabs = $$('[role="tab"]', nav);
		const ids = tabs.map(t => t.getAttribute("aria-controls"));
		const panels = ids.map(id => document.getElementById(id));
		let current = "";

		function select(id, focus) {
			const i = Math.max(0, ids.indexOf(id));
			current = ids[i];
			tabs.forEach((t, j) => {
				const on = j === i;
				t.setAttribute("aria-selected", on ? "true" : "false");
				t.tabIndex = on ? 0 : -1;
				t.classList.toggle("is-active", on);
			});
			panels.forEach((p, j) => { p.hidden = j !== i; });
			if (focus === "tab") tabs[i].focus();
			if (focus === "panel") panels[i].focus();
			return current;
		}

		nav.addEventListener("click", e => {
			const tab = e.target.closest('[role="tab"]');
			if (!tab) return;
			e.preventDefault();
			const id = select(tab.getAttribute("aria-controls"));
			if (location.hash !== "#" + id) history.pushState(history.state, "", "#" + id);
		});

		nav.addEventListener("keydown", e => {
			const i = tabs.indexOf(document.activeElement);
			if (i < 0) return;
			let next = -1;
			if (e.key === "ArrowDown" || e.key === "ArrowRight") next = (i + 1) % tabs.length;
			else if (e.key === "ArrowUp" || e.key === "ArrowLeft") next = (i - 1 + tabs.length) % tabs.length;
			else if (e.key === "Home") next = 0;
			else if (e.key === "End") next = tabs.length - 1;
			if (next < 0) return;
			e.preventDefault();
			const id = select(ids[next], "tab");
			history.replaceState(history.state, "", "#" + id);
		});

		window.addEventListener("hashchange", () => {
			const id = location.hash.slice(1);
			if (ids.indexOf(id) > -1 && id !== current) select(id, "panel");
		});
		window.addEventListener("popstate", () => {
			const id = location.hash.slice(1);
			select(ids.indexOf(id) > -1 ? id : ids[0]);
		});

		const start = location.hash.slice(1);
		select(ids.indexOf(start) > -1 ? start : ids[0]);
		if (ids.indexOf(start) > -1) {
			// The browser could not scroll to a panel that was still hidden: show the board instead.
			requestAnimationFrame(() => board.scrollIntoView({ block: "start" }));
		}
	}

	/** button[data-copy]: copies the code; the [data-copy-status] of its coupon says so. */
	function initCopy(board) {
		board.addEventListener("click", e => {
			const btn = e.target.closest("[data-copy]");
			if (!btn) return;
			const code = btn.getAttribute("data-copy");
			const status = $("[data-copy-status]", btn.closest('[role="group"]') || btn.parentNode);
			const say = text => { if (status) status.textContent = text; };
			const fail = () => say(sprintf(__("Copy this code: %s", "marclie-booking"), code));
			if (navigator.clipboard && navigator.clipboard.writeText) {
				navigator.clipboard.writeText(code).then(() => say(btn.getAttribute("data-copy-done") || ""), fail);
			} else fail();
		});
	}

	function initProfile(board) {
		const form = $('[data-acct-form="profile"]', board);
		if (!form) return;
		const notice = $('[data-acct-done="profile"]', form);
		onSubmit(form, async data => {
			notice.hidden = true;
			await mbk.api("account/profile", {
				method: "PATCH",
				body: {
					first_name: data.first_name,
					last_name: data.last_name,
					phone: data.phone,
					country: data.country,
					lang: data.lang,
					newsletter: !!data.newsletter
				}
			});
			notice.hidden = false;
		});
		form.addEventListener("input", () => { notice.hidden = true; });
	}

	function initPassword(board) {
		const form = $('[data-acct-form="password"]', board);
		if (!form) return;
		const notice = $('[data-acct-done="password"]', form);
		onSubmit(form, async data => {
			notice.hidden = true;
			const res = await post("password", { current: $('[name="current"]', form).value, new: $('[name="new"]', form).value });
			if (res && res.nonce && cfg.account) cfg.account.nonce = res.nonce;
			$$('input[type="password"]', form).forEach(el => { el.value = ""; });
			notice.hidden = false;
		});
		form.addEventListener("input", () => { notice.hidden = true; });
	}

	/** Modal dialogs (cancel request, delete account): focus returns to the opener on close. */
	function initDialogs(board) {
		let opener = null;
		const open = (dlg, from) => {
			opener = from;
			const form = $("form", dlg);
			form.reset();
			form.hidden = false;
			showAlert(form, "");
			$$("[data-field]", form).forEach(el => setError(el, ""));
			$$("[data-acct-done]", dlg).forEach(el => { el.hidden = true; });
			if (typeof dlg.showModal === "function") dlg.showModal();
			else dlg.setAttribute("open", "");
			const first = $("textarea, input:not([type=hidden]):not([hidden])", form);
			if (first) first.focus();
		};
		const close = dlg => {
			if (typeof dlg.close === "function") dlg.close();
			else { dlg.removeAttribute("open"); dlg.dispatchEvent(new Event("close")); }
		};
		$$("dialog[data-acct-dialog]", board).forEach(dlg => {
			dlg.addEventListener("close", () => {
				if (opener && document.contains(opener)) opener.focus();
				opener = null;
			});
			dlg.addEventListener("click", e => {
				if (e.target.closest("[data-acct-dialog-close]")) close(dlg);
			});
		});

		// Cancellation request.
		const cancelDlg = $('[data-acct-dialog="cancel"]', board);
		const cancelForm = $("form", cancelDlg);
		let cancelBtn = null;
		board.addEventListener("click", e => {
			const btn = e.target.closest("[data-acct-cancel]");
			if (btn) {
				cancelBtn = btn;
				open(cancelDlg, btn);
				$('[name="ref"]', cancelForm).value = btn.getAttribute("data-acct-cancel");
				$("[data-acct-cancel-booking]", cancelDlg).textContent = btn.getAttribute("data-title") + " · " + btn.getAttribute("data-acct-cancel");
				return;
			}
			const del = e.target.closest("[data-acct-dialog-open]");
			if (del) open($('[data-acct-dialog="' + del.getAttribute("data-acct-dialog-open") + '"]', board), del);
		});
		onSubmit(cancelForm, async data => {
			await post("bookings/" + encodeURIComponent(data.ref) + "/cancel-request", { reason: data.reason });
			cancelForm.hidden = true;
			const ok = $('[data-acct-done="cancel"]', cancelDlg);
			ok.hidden = false;
			$("h2", ok).focus();
			const card = cancelBtn && cancelBtn.closest("[data-acct-booking]");
			if (card) {
				const note = $("[data-acct-requested]", card);
				if (note) note.hidden = false;
				// Focus goes back to the booking title link once the button is gone.
				opener = $("[data-acct-booking-link]", card);
				cancelBtn.remove();
			}
		});

		// Account deletion.
		const delDlg = $('[data-acct-dialog="delete"]', board);
		const delForm = $("form", delDlg);
		onSubmit(delForm, async data => {
			await post("delete", { password: $('[name="password"]', delForm).value, confirm: data.confirm });
			location.assign(board.getAttribute("data-home") || "/");
		});
	}

	/** Card update: SetupIntent + Payment Element (setup mode); offline notice without Stripe. */
	function initCard(board) {
		const box = $("[data-acct-card]", board);
		if (!box) return;
		const openBtn = $("[data-acct-card-open]", box);
		const form = $('[data-acct-form="card"]', box);
		const mount = $("[data-acct-card-element]", box);
		const offline = $("[data-acct-card-offline]", box);
		const submit = $('button[type="submit"]', form);
		const initialMount = mount.innerHTML;
		let stripe = null, elements = null, busy = false;

		const returnUrl = () => {
			const u = new URL(location.href);
			u.hash = "payments";
			return u.toString();
		};

		function showOffline() {
			form.hidden = true;
			openBtn.hidden = true;
			offline.hidden = false;
			offline.focus();
		}

		function closeForm() {
			form.hidden = true;
			openBtn.setAttribute("aria-expanded", "false");
			if (elements) { const el = elements.getElement("payment"); if (el) el.destroy(); }
			elements = null;
			mount.innerHTML = initialMount;
			mount.setAttribute("aria-busy", "true");
			submit.disabled = true;
			openBtn.focus();
		}

		async function confirmOnServer(setupIntentId) {
			await post("card/confirm", { setup_intent: setupIntentId });
			done(box, "card");
		}

		openBtn.addEventListener("click", async () => {
			$('[data-acct-done="card"]', box).hidden = true;
			if (!form.hidden) { closeForm(); return; }
			stripe = mbk.stripe();
			if (!stripe) { showOffline(); return; }
			form.hidden = false;
			showAlert(form, "");
			openBtn.setAttribute("aria-expanded", "true");
			try {
				const res = await post("card/setup-intent");
				elements = stripe.elements({ clientSecret: res.client_secret, appearance: mbk.stripeAppearance(), locale: mbk.stripeLocale() });
				const pe = elements.create("payment", { layout: "tabs" });
				mount.innerHTML = "";
				pe.on("ready", () => {
					mount.removeAttribute("aria-busy");
					submit.disabled = false;
					pe.focus();
				});
				pe.mount(mount);
			} catch (err) {
				if (err && (err.status === 503 || err.code === "payments_unavailable")) showOffline();
				else showAlert(form, message(err));
			}
		});

		$("[data-acct-card-close]", form).addEventListener("click", closeForm);

		form.addEventListener("submit", async e => {
			e.preventDefault();
			if (busy || !elements) return;
			busy = true;
			submit.disabled = true;
			submit.classList.add("is-loading");
			showAlert(form, "");
			try {
				const { error, setupIntent } = await stripe.confirmSetup({ elements, confirmParams: { return_url: returnUrl() }, redirect: "if_required" });
				if (error) {
					showAlert(form, error.message || __("The card could not be saved. Please try again or use another card.", "marclie-booking"));
					return;
				}
				await confirmOnServer(setupIntent.id);
				form.hidden = true;
				openBtn.setAttribute("aria-expanded", "false");
			} catch (err) {
				showAlert(form, message(err));
			} finally {
				busy = false;
				submit.disabled = false;
				submit.classList.remove("is-loading");
			}
		});

		// Back from a bank (3-D Secure) redirect: ?setup_intent=…&redirect_status=…
		const url = new URL(location.href);
		const returned = url.searchParams.get("setup_intent");
		const status = url.searchParams.get("redirect_status");
		if (returned) {
			["setup_intent", "setup_intent_client_secret", "redirect_status"].forEach(k => url.searchParams.delete(k));
			history.replaceState(history.state, "", url.pathname + url.search + url.hash);
			if (status === "succeeded") {
				confirmOnServer(returned).catch(err => { form.hidden = false; showAlert(form, message(err)); });
			} else {
				form.hidden = false;
				showAlert(form, __("The card could not be saved. Please try again or use another card.", "marclie-booking"));
			}
		}
	}

	function initBoard(board) {
		initTabs(board);
		initCopy(board);
		initProfile(board);
		initPassword(board);
		initDialogs(board);
		initCard(board);
		initLogout(board);
	}

	/**
	 * "Odjava" posts POST account/logout (the nonce stays current after a password change, the
	 * server-rendered logout link's nonce does not); the link itself remains the no-JS fallback.
	 */
	function initLogout(board) {
		const link = $("[data-acct-logout]", board);
		if (!link) return;
		link.addEventListener("click", async e => {
			e.preventDefault();
			try {
				await post("logout");
				location.assign(board.getAttribute("data-home") || "/");
			} catch (err) {
				location.assign(link.href);
			}
		});
	}

	const INIT = { login: initLogin, register: initRegister, forgot: initForgot, reset: initReset, verify: initVerify, board: initBoard };

	mbk.ready(() => $$("[data-mbk-account]").forEach(root => {
		const kind = root.getAttribute("data-mbk-account");
		if (root.mbkAccount || !INIT[kind]) return;
		root.mbkAccount = true;
		INIT[kind](root);
	}));
})();
