# Marclie Booking — Playwright E2E

Real browser flows against the local site (`https://localhost`, WordPress Studio)
with Stripe **test mode**. Not shipped: `.distignore` excludes `/tests`.

| # | Spec | Flow |
|---|---|---|
| 1 | `guest-checkout` | HR apartment page → calendar (unselectable days: `aria-disabled` + opacity < 0.6) → bookbox price → checkout → 4242 → hvala, `confirmed` |
| 2 | `guest-checkout` | EN (`?lang=en`) checkout with 3-D Secure card 4000 0027 6000 3184 → `confirmed` |
| 3 | `guest-checkout` | Declined card 4000 0000 0000 9995 → error, `pending_payment`; hold forced into the past + HoldReaper → `expired` |
| 4 | `guest-checkout` | Nights held by another guest → checkout „zauzeto”, struck-through days in the calendar |
| 4b | `guest-checkout` | Booking horizon (current month + 24): later calendar months inactive (not loading forever), checkout link after it → date picker with the reason |
| 5 | `guest-checkout` | 390 px: bookbar mirrors the quote, checkout wizard steps (validation, step 2, Back) |
| 6 | `guest-checkout` | Coupon DIRECT5 lowers the deposit; paid booking stores the coupon |
| 7 | `admin` | Kalendar drag-select → „New booking” wizard → booking in the list |
| 8 | `admin` | Cancel + refund a paid booking; `charge.refunded` webhook → refund succeeded |
| 9 | `admin` | EN email subject edit → preview → save → send test (captured) → reset to default |
| 10 | `admin` | Postavke › Stripe „Check connection” succeeds |
| 11 | `admin` | Coupon created in the Admin App, then applied and paid at checkout |

## Requirements

- The Studio site running; `studio` CLI on `PATH` (helpers call `studio wp eval-file`).
- Stripe test keys in `wordpress-local/wp-config.php` (`MBK_STRIPE_PUBLISHABLE_KEY`,
  `MBK_STRIPE_SECRET_KEY`, `MBK_STRIPE_WEBHOOK_SECRET`).
- Stripe webhooks forwarded to the site (test 8 waits for `charge.refunded`; the paid flows
  also receive `payment_intent.succeeded`), with the `whsec_…` it prints set as
  `MBK_STRIPE_WEBHOOK_SECRET`:

  ```sh
  stripe listen --skip-verify --forward-to https://localhost/wp-json/marclie-booking/v1/stripe/webhook
  ```

- Node 20+.

## Run

```sh
cd wordpress-local/wp-content/plugins/marclie-booking/tests/e2e
npm install
npx playwright install chromium
npx playwright test            # whole suite, serial (about 9 min locally)
npx playwright test -g "3 ·"   # one scenario
npx playwright show-report     # HTML report (traces of failures)
```

Environment overrides: `MBK_E2E_BASE_URL` (site URL), `MBK_E2E_WP_ROOT` (WordPress root),
`MBK_E2E_WP_CLI` (WP-CLI command, default `studio wp`).

## Test data

- Guests use unique `…@mbk-e2e-pw.test` emails; stays are picked in 2028 through `GET /search`.
- Global setup removes leftovers of an interrupted run, copies `helpers/mail-capture.php` to
  `wp-content/mu-plugins/` (mail to `@mbk-e2e-pw.test` is logged to
  `wp-content/mbk-e2e-pw-mail.jsonl`, never sent) and logs the admin in through
  `/studio-auto-login`.
- Global teardown deletes every `@mbk-e2e-pw.test` booking, guest, WordPress user and Stripe test
  customer, `PWE2E…` coupons and `[PW-E2E]` template edits, then removes the mu-plugin, the mail
  log and the saved admin session. Run it alone with `npm run cleanup`.
- `helpers/wp-helper.php` is the server side of the suite (rate-limit reset, booking lookup,
  hold expiry, paid booking seed, cleanup); it refuses emails outside the E2E domain.
