import { defineConfig, devices } from '@playwright/test';

/**
 * Marclie Booking E2E — real browser flows against the local WordPress Studio site with Stripe
 * TEST mode. Serial on purpose: one SQLite database, per-IP booking rate limits and Stripe test
 * webhooks (forwarded by `stripe listen`) are shared by every test.
 */
export const BASE_URL = process.env.MBK_E2E_BASE_URL ?? 'https://localhost';

export default defineConfig( {
	testDir: './specs',
	outputDir: './test-results',
	fullyParallel: false,
	workers: 1,
	retries: 0,
	timeout: 240_000,
	expect: { timeout: 20_000 },
	forbidOnly: !! process.env.CI,
	reporter: [ [ 'list' ], [ 'html', { open: 'never', outputFolder: 'playwright-report' } ] ],
	globalSetup: './global-setup.ts',
	globalTeardown: './global-teardown.ts',
	use: {
		baseURL: BASE_URL,
		ignoreHTTPSErrors: true,
		locale: 'hr-HR',
		timezoneId: 'Europe/Zagreb',
		viewport: { width: 1366, height: 900 },
		actionTimeout: 20_000,
		navigationTimeout: 60_000,
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
		video: 'off',
	},
	projects: [
		{
			name: 'chromium',
			use: { ...devices[ 'Desktop Chrome' ], viewport: { width: 1366, height: 900 } },
		},
	],
} );
