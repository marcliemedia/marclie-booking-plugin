/**
 * Admin App flows (spec §Automated, Admin): Kalendar → manual booking, cancel + refund,
 * email template edit + test send, Stripe „Provjeri vezu”, coupon created then used at checkout.
 */
import { expect, test, type Page } from '@playwright/test';

import { ADMIN_STATE } from '../global-setup';
import { CARDS, cents, expectConfirmed, fillCard, fillDetails, submitPayment } from '../helpers/checkout';
import { addDays, checkoutUrl, findStay } from '../helpers/stay';
import { bookingsOf, capturedMail, COUPON_PREFIX, guestEmail, resetLimits, seedPaid, template, TEMPLATE_MARKER } from '../helpers/wp';

test.use( { storageState: ADMIN_STATE, locale: 'en-US' } );

test.beforeEach( () => {
	resetLimits();
} );

/** Opens a hash route of the Admin App (`#/calendar?from=…`). */
async function openApp( page: Page, route: string ): Promise< void > {
	await page.goto( `/wp-admin/admin.php?page=marclie-booking#${ route }` );
	await expect( page.locator( '#mbk-app' ) ).not.toBeEmpty();
}

test( '7 · Kalendar drag-select → New booking wizard → booking in the list', async ( { page, request } ) => {
	const stay = await findStay( request, { from: '2028-02-07', nights: 3 } );
	const email = guestEmail( 'manual' );

	await openApp( page, `/calendar?from=${ addDays( stay.arrival, -2 ) }` );
	const grid = page.getByRole( 'grid', { name: 'Availability calendar' } );
	await expect( grid ).toHaveAttribute( 'aria-busy', 'false', { timeout: 60_000 } );
	const row = grid.locator( `[data-row-unit="${ stay.unitId }"]` );
	const first = row.locator( '[data-cell$="-2"]' );
	const last = row.locator( `[data-cell$="-${ 2 + stay.nights - 1 }"]` );
	await expect( first ).toHaveAttribute( 'aria-label', /Available/ );
	await first.scrollIntoViewIfNeeded();

	// drag across the empty nights
	const a = await first.boundingBox();
	const b = await last.boundingBox();
	if ( ! a || ! b ) {
		throw new Error( 'calendar cells are not laid out' );
	}
	await page.mouse.move( a.x + a.width / 2, a.y + a.height / 2 );
	await page.mouse.down();
	await page.mouse.move( b.x + b.width / 2, b.y + b.height / 2, { steps: 8 } );
	await page.mouse.up();
	await expect( row.locator( '[aria-selected="true"]' ) ).toHaveCount( stay.nights );

	const popover = page.getByRole( 'dialog' ).filter( { hasText: stay.title } );
	await expect( popover ).toContainText( `${ stay.nights } nights` );
	await popover.getByRole( 'button', { name: 'New booking' } ).click();

	// wizard: Stay (prefilled) → Guest (new) → Payment (none) → Create
	const wizard = page.getByRole( 'dialog', { name: 'New booking' } );
	await expect( wizard ).toBeVisible();
	await expect( wizard.locator( '#wz-unit' ) ).toContainText( stay.title );
	await expect( wizard ).toContainText( 'Total' );
	await wizard.getByRole( 'button', { name: 'Continue' } ).click();
	await wizard.getByRole( 'button', { name: 'Create a new guest' } ).click();
	await wizard.locator( '#ng-first' ).fill( 'Marko' );
	await wizard.locator( '#ng-last' ).fill( 'Playwright' );
	await wizard.locator( '#ng-email' ).fill( email );
	await wizard.locator( '#ng-phone' ).fill( '+385 91 555 0101' );
	await wizard.locator( '#ng-country' ).fill( 'HR' );
	await wizard.getByRole( 'button', { name: 'Continue' } ).click();
	await expect( wizard.getByText( 'No payment yet' ) ).toBeVisible();
	await wizard.getByRole( 'button', { name: /^Create booking/ } ).click();
	await expect( page.getByText( /Booking \S+ created for Marko Playwright\./ ) ).toBeVisible();

	const [ booking ] = bookingsOf( email );
	expect( booking.arrival ).toBe( stay.arrival );
	expect( booking.departure ).toBe( stay.departure );
	expect( Number( booking.unit_id ) ).toBe( stay.unitId );

	await openApp( page, '/bookings' );
	await page.getByLabel( 'Search bookings' ).fill( email );
	const listRow = page.getByRole( 'row' ).filter( { hasText: booking.ref } );
	await expect( listRow ).toBeVisible();
	await expect( listRow ).toContainText( 'Marko Playwright' );
	await page.screenshot( { path: test.info().outputPath( 'manual-booking-list.png' ) } );
} );

test( '8 · cancel + refund a paid test booking', async ( { page, request } ) => {
	const stay = await findStay( request, { from: '2028-04-10' } );
	const email = guestEmail( 'refund' );
	const paid = seedPaid( email, stay.arrival, stay.departure );
	expect( paid.status ).toBe( 'confirmed' );

	await openApp( page, `/bookings/${ paid.id }` );
	const sheet = page.getByRole( 'dialog' ).filter( { hasText: paid.ref } );
	await expect( sheet ).toBeVisible();
	await sheet.getByRole( 'button', { name: 'More' } ).click();
	await page.getByRole( 'menuitem', { name: 'Cancel booking' } ).click();

	const confirm = page.getByRole( 'alertdialog' ).or( page.getByRole( 'dialog', { name: `Cancel booking ${ paid.ref }?` } ) ).first();
	await expect( confirm ).toContainText( `Cancel booking ${ paid.ref }?` );
	await expect( confirm.getByRole( 'radio', { name: /Refund per policy/ } ) ).toBeChecked();
	const go = confirm.getByRole( 'button', { name: /^Cancel and refund/ } );
	await expect( go ).toBeEnabled( { timeout: 30_000 } );
	await go.click();
	await expect( page.getByText( new RegExp( `Booking ${ paid.ref } cancelled\\. Refund due` ) ) ).toBeVisible( { timeout: 60_000 } );

	let [ booking ] = bookingsOf( email );
	expect( booking.status ).toBe( 'cancelled' );
	const refund = booking.payments.find( ( p ) => p.kind === 'refund' );
	expect( refund?.refund ).toMatch( /^re_/ );
	expect( refund?.amount ).toBe( Number( booking.deposit_cents ) );

	// charge.refunded arrives through `stripe listen` → refund succeeded
	await expect
		.poll( () => bookingsOf( email )[ 0 ].payments.find( ( p ) => p.kind === 'refund' )?.status, { timeout: 90_000, intervals: [ 3_000 ] } )
		.toBe( 'succeeded' );
	await page.reload();
	await expect( page.getByRole( 'dialog' ).filter( { hasText: paid.ref } ) ).toBeVisible();
	await page.getByRole( 'tab', { name: /Payments/ } ).click();
	await expect( page.getByText( 'Refunded through Stripe.' ) ).toBeVisible();
	[ booking ] = bookingsOf( email );
	expect( booking.status ).toBe( 'cancelled' );
	await page.screenshot( { path: test.info().outputPath( 'refund.png' ) } );
} );

test( '9 · email template subject (EN): edit → preview → send test (captured) → reset to default', async ( { page } ) => {
	const code = [ 'guest_confirmed', 'guest_cancelled', 'guest_balance_paid', 'guest_payment_link' ].find( ( c ) => ! template( c, 'en' ).customized );
	test.skip( ! code, 'every candidate EN template is customized on this site; not touching edited text' );
	const original = template( code as string, 'en' ).subject;
	const subject = `${ TEMPLATE_MARKER } ${ original }`;
	const to = guestEmail( 'mailtest' );

	await openApp( page, `/emails/${ code }` );
	await page.getByRole( 'tab', { name: /^EN/ } ).click();
	const field = page.locator( '#mbk-email-en-subject' );
	await expect( field ).toHaveValue( original );
	await field.fill( subject );

	const previewSubject = page.getByText( 'Subject:' ).locator( 'xpath=following-sibling::span' );
	await expect( previewSubject ).toContainText( TEMPLATE_MARKER, { timeout: 30_000 } );

	await page.getByRole( 'button', { name: 'Save', exact: true } ).click();
	await expect( page.getByText( 'E-mail template saved.' ) ).toBeVisible();
	expect( template( code as string, 'en' ) ).toEqual( { subject, customized: true } );

	await page.getByRole( 'button', { name: 'Send test e-mail' } ).click();
	const dialog = page.getByRole( 'dialog', { name: 'Send test e-mail' } );
	await dialog.locator( '#mbk-test-to' ).fill( to );
	await dialog.locator( '#mbk-test-lang' ).click();
	await page.getByRole( 'option', { name: 'English' } ).click();
	await dialog.getByRole( 'button', { name: 'Send test' } ).click();
	await expect( page.getByText( `Test e-mail sent to ${ to }.` ) ).toBeVisible( { timeout: 30_000 } );
	const mail = capturedMail().filter( ( m ) => m.to.some( ( t ) => t.includes( to ) ) );
	expect( mail ).toHaveLength( 1 );
	expect( mail[ 0 ].subject ).toContain( TEMPLATE_MARKER );

	await page.getByRole( 'button', { name: 'More actions' } ).click();
	await page.getByRole( 'menuitem', { name: /Reset to default \(EN\)/ } ).click();
	await page.getByRole( 'alertdialog' ).getByRole( 'button', { name: 'Reset to default' } ).click();
	await expect( page.getByText( 'English version reset to the default text.' ) ).toBeVisible();
	await expect( field ).toHaveValue( original );
	expect( template( code as string, 'en' ) ).toEqual( { subject: original, customized: false } );
} );

test( '10 · Postavke › Stripe „Provjeri vezu” succeeds', async ( { page } ) => {
	await openApp( page, '/settings/stripe' );
	await expect( page.getByText( 'Stripe connection' ) ).toBeVisible();
	await expect( page.getByText( 'TEST', { exact: true } ) ).toBeVisible();
	await page.getByRole( 'button', { name: 'Check connection' } ).click();
	await expect( page.getByText( 'Connected to Stripe.' ) ).toBeVisible( { timeout: 60_000 } );
	const check = page.getByText( 'Connection check' ).locator( '..' );
	await expect( check.getByText( 'Account', { exact: true } ) ).toBeVisible();
	await expect( check.getByText( 'Charges', { exact: true } ) ).toBeVisible();
	await expect( check.getByText( 'Stripe connection failed.' ) ).toHaveCount( 0 );
	await page.screenshot( { path: test.info().outputPath( 'stripe-verify.png' ) } );
} );

test( '11 · coupon created in the Admin App is accepted at checkout', async ( { page, browser, request } ) => {
	const code = `${ COUPON_PREFIX }${ Date.now().toString( 36 ).toUpperCase() }`;
	await openApp( page, '/coupons' );
	await page.getByRole( 'button', { name: 'New coupon' } ).first().click();
	const sheet = page.getByRole( 'dialog', { name: 'New coupon' } );
	await sheet.locator( '#coupon-code' ).fill( code );
	await sheet.locator( '#coupon-pct' ).fill( '10' );
	await sheet.getByRole( 'button', { name: 'Save coupon' } ).click();
	await expect( page.getByText( 'Coupon created.' ) ).toBeVisible();
	await expect( page.getByRole( 'row' ).filter( { hasText: code } ) ).toBeVisible();

	// a guest (no admin session) uses it
	const guest = await browser.newContext( { ignoreHTTPSErrors: true, locale: 'hr-HR', timezoneId: 'Europe/Zagreb', baseURL: test.info().project.use.baseURL } );
	const shop = await guest.newPage();
	try {
		const stay = await findStay( request, { from: '2028-09-11' } );
		const email = guestEmail( 'coupon' );
		await shop.goto( checkoutUrl( stay ) );
		const due = shop.locator( '[data-co-lines] .w-checkout__line--due dd' );
		await expect( due ).toContainText( '€' );
		const before = cents( ( await due.textContent() ) ?? '' );
		await shop.locator( '#b-coupon' ).fill( code.toLowerCase() );
		await shop.locator( '[data-coupon-apply]' ).click();
		await expect( shop.locator( '[data-coupon-code]' ) ).toHaveText( code );
		await expect.poll( async () => cents( ( await due.textContent() ) ?? '' ) ).toBeLessThan( before );

		await fillDetails( shop, { first: 'Luka', last: 'Playwright', email } );
		await fillCard( shop, CARDS.visa );
		await submitPayment( shop );
		await expectConfirmed( shop );

		const [ booking ] = bookingsOf( email );
		expect( booking.status ).toBe( 'confirmed' );
		expect( booking.coupon_code ).toBe( code );
		expect( Number( booking.discount_cents ) ).toBeGreaterThan( 0 );
	} finally {
		await guest.close();
	}
} );
