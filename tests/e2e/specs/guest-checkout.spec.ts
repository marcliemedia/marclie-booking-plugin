/**
 * Guest flows (spec §Automated 1–6): calendar → bookbox → checkout → Stripe → hvala.
 */
import { expect, test } from '@playwright/test';

import {
	CARDS,
	cents,
	completeThreeDs,
	dayOf,
	expectConfirmed,
	expectInactiveDay,
	fillCard,
	fillDetails,
	showMonth,
	submitPayment,
} from '../helpers/checkout';
import { addDays, checkoutUrl, findStay } from '../helpers/stay';
import { bookingsOf, expireHold, guestEmail, resetLimits } from '../helpers/wp';

test.beforeEach( () => {
	resetLimits();
} );

test( '1 · HR: apartment calendar → bookbox price → checkout → 4242 → hvala (confirmed)', async ( { page, request } ) => {
	const stay = await findStay( request, { from: '2028-05-02' } );
	const email = guestEmail( 'hr' );

	await page.goto( stay.permalink );
	const cal = page.locator( '[data-date-inline]' ).first();
	const key = await cal.getAttribute( 'data-date-inline' );
	const arrivalDay = await showMonth( cal, stay.arrival );
	await expect( arrivalDay ).toHaveAttribute( 'aria-disabled', 'false' );
	await arrivalDay.click();
	// the chosen check-in keeps its solid circle while hovered (the pointer is still on it)
	await expect( arrivalDay ).toHaveClass( /is-start/ );
	const fill = await arrivalDay.evaluate( ( el ) => getComputedStyle( el, '::before' ).backgroundColor );
	const [ r, g, b, a = 1 ] = ( fill.match( /[\d.]+/g ) ?? [] ).map( Number );
	expect( a, `check-in circle ${ fill } under hover is opaque` ).toBeGreaterThan( 0.9 );
	expect( 0.2126 * r + 0.7152 * g + 0.0722 * b, `check-in circle ${ fill } under hover is dark` ).toBeLessThan( 160 );

	// minimum stay 3: the next two nights cannot be the check-out — aria-disabled and visibly inactive
	await expectInactiveDay( dayOf( cal, addDays( stay.arrival, 1 ) ) );
	await expectInactiveDay( dayOf( cal, addDays( stay.arrival, 2 ) ) );
	await page.screenshot( { path: test.info().outputPath( 'calendar-inactive-days.png' ) } );
	await dayOf( cal, stay.departure ).click();
	await expect( page.locator( `[data-nights-title="${ key }"]` ) ).toContainText( String( stay.nights ) );

	// bookbox: live quote with the same stay total as /search
	const box = page.locator( '[data-mbk-bookbox]' );
	const lines = box.locator( '[data-mbk-lines]' );
	const norm = ( s: string | null ) => ( s ?? '' ).replace( /[\s\u00a0\u202f]+/g, ' ' ).trim();
	await expect.poll( async () => norm( await lines.textContent() ) ).toContain( norm( stay.totalFormatted ) );
	await expect( box.locator( '[data-mbk-error]' ) ).toBeHidden();
	await box.locator( 'button[type="submit"]' ).click();

	await page.waitForURL( /\/rezervacija\/\?/ );
	await expect( page.locator( '[data-mbk-checkout]' ) ).toHaveAttribute( 'data-state', 'free' );
	await expect( page.locator( '[data-mbk-checkout]' ) ).toHaveAttribute( 'data-arrival', stay.arrival );
	await fillDetails( page, { first: 'Ivana', last: 'Playwright', email } );
	await fillCard( page, CARDS.visa );
	await submitPayment( page );

	const ref = await expectConfirmed( page );
	await expect( page.locator( 'html' ) ).toHaveAttribute( 'lang', /^hr/ );
	const [ booking ] = bookingsOf( email );
	expect( booking.ref ).toBe( ref );
	expect( booking.status ).toBe( 'confirmed' );
	expect( Number( booking.unit_id ) ).toBe( stay.unitId );
	expect( booking.payments.some( ( p ) => p.kind === 'deposit' && p.status === 'succeeded' && p.amount === Number( booking.deposit_cents ) ) ).toBe( true );
} );

test( '2 · EN: checkout with 3-D Secure card 4000 0027 6000 3184 → confirmed', async ( { page, request } ) => {
	const stay = await findStay( request, { from: '2028-06-05' } );
	const email = guestEmail( 'en3ds' );

	// EN = ?lang=en; the plugin widgets carry lang="en" (theme content stays Croatian)
	await page.goto( checkoutUrl( stay, { lang: 'en' } ) );
	await expect( page.locator( '[data-mbk-checkout]' ) ).toHaveAttribute( 'lang', 'en' );
	await expect( page.locator( '[data-mbk-checkout] h1' ) ).toContainText( `Book ${ stay.title }` );
	await expect( page.locator( '[data-co-step]' ).first() ).toContainText( 'Your details' );
	await fillDetails( page, { first: 'Emma', last: 'Playwright', email, phone: '+49 170 1234567' } );
	await fillCard( page, CARDS.threeDs );
	await submitPayment( page );
	await completeThreeDs( page, 'complete' );

	const ref = await expectConfirmed( page );
	await expect( page.locator( '[data-cf-state="done"] [data-state-title]' ) ).toContainText( 'Thank you' );
	const [ booking ] = bookingsOf( email );
	expect( booking.ref ).toBe( ref );
	expect( booking.status ).toBe( 'confirmed' );
} );

test( '3 · declined card 4000 0000 0000 9995 → error, pending_payment, expired after the hold', async ( { page, request } ) => {
	const stay = await findStay( request, { from: '2028-09-04' } );
	const email = guestEmail( 'decline' );

	await page.goto( checkoutUrl( stay ) );
	await fillDetails( page, { first: 'Dora', last: 'Playwright', email } );
	await fillCard( page, CARDS.insufficientFunds );
	await submitPayment( page );

	// the decline stays in our role=alert under the card (Stripe's change event right after a failed confirm must not wipe it)
	const payError = page.locator( '[data-co-pay-error]' );
	await expect( payError ).toBeVisible( { timeout: 60_000 } );
	await expect( payError ).toHaveText( /sredstava|insufficient|odbijen|declined/i );
	await page.waitForTimeout( 3_000 );
	await expect( payError ).toBeVisible();
	await expect( page ).toHaveURL( /\/rezervacija\/\?/ );
	await page.screenshot( { path: test.info().outputPath( 'declined.png' ) } );

	let [ booking ] = bookingsOf( email );
	expect( booking.status ).toBe( 'pending_payment' );
	expect( booking.hold_expires_at ).toBeTruthy();

	const reaped = expireHold( email );
	expect( reaped.expired ).toBeGreaterThanOrEqual( 1 );
	[ booking ] = reaped.bookings;
	expect( booking.status ).toBe( 'expired' );
} );

test( '4 · occupied dates → zauzeto on the checkout, struck through in the calendar', async ( { page, request } ) => {
	const stay = await findStay( request, { from: '2028-03-13' } );
	const email = guestEmail( 'occupy' );

	// another guest's hold takes the nights (POST /bookings → pending_payment)
	const res = await request.post( '/wp-json/marclie-booking/v1/bookings', {
		data: {
			unit_id: stay.unitId,
			arrival: stay.arrival,
			departure: stay.departure,
			adults: 2,
			children: 0,
			guest: { first_name: 'Zora', last_name: 'Playwright', email, phone: '+385 91 555 0101', country: 'HR' },
			terms: true,
			website: '',
		},
	} );
	expect( res.status(), await res.text() ).toBe( 201 );

	await page.goto( checkoutUrl( stay ) );
	const root = page.locator( '[data-mbk-checkout]' );
	await expect( root ).toHaveAttribute( 'data-state', 'booked' );
	await expect( root.locator( '[data-co-unavail][data-co-booked]' ) ).toBeVisible();
	await expect( root.locator( '[data-co-form]' ) ).toBeHidden();
	await expect( root.locator( '[data-co-check-off]' ) ).toBeVisible();
	await page.screenshot( { path: test.info().outputPath( 'zauzeto.png' ), fullPage: true } );

	await page.goto( stay.permalink );
	const cal = page.locator( '[data-date-inline]' ).first();
	const night = await showMonth( cal, stay.arrival );
	await expect( night ).toHaveClass( /is-booked/ );
	await expectInactiveDay( night );
	await expectInactiveDay( dayOf( cal, addDays( stay.arrival, 1 ) ) );
	expect( await night.evaluate( ( el ) => getComputedStyle( el ).textDecorationLine ) ).toContain( 'line-through' );
} );

test( '4b · booking horizon: calendar months after it are inactive, checkout refuses it', async ( { page, request } ) => {
	// GET /availability answers 400 invalid_range {max: "YYYY-MM"} after the last month of the horizon
	const probe = await request.get( '/wp-json/marclie-booking/v1/availability', { params: { unit: 13, from: '2099-01', months: 1 } } );
	expect( probe.status() ).toBe( 400 );
	const body: { code?: string; data?: { max?: string } } = await probe.json();
	expect( body.code ).toBe( 'invalid_range' );
	const [ y, m ] = ( body.data?.max ?? '' ).split( '-' ).map( Number );
	const beyond = new Date( Date.UTC( y, m, 15 ) ).toISOString().slice( 0, 10 ); // 15th of the month after max
	const lastDay = new Date( Date.UTC( y, m, 0 ) ).toISOString().slice( 0, 10 ); // last day of max

	const stay = await findStay( request, { from: '2028-01-03' } );
	await page.goto( stay.permalink );
	const cal = page.locator( '[data-date-inline]' ).first();
	const day = await showMonth( cal, beyond );
	await expectInactiveDay( day );
	await expect( cal ).not.toHaveAttribute( 'aria-busy', 'true' );
	// the last day of the horizon is still a check-in (when free)
	await expect( dayOf( cal, lastDay ) ).not.toHaveClass( /is-pending/ );

	// a checkout link after the horizon falls back to the date picker with the reason
	await page.goto( checkoutUrl( { ...stay, arrival: beyond, departure: addDays( beyond, 4 ) } ) );
	await expect( page.locator( '[data-mbk-checkout]' ) ).toHaveAttribute( 'data-state', 'dates' );
	await expect( page.locator( '[data-co-pick] .w-note[role="alert"]' ) ).toHaveText( 'Rezervacije su moguće najviše 24 mjeseca unaprijed.' );
} );

test.describe( 'mobile 390', () => {
	test.use( { viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true } );

	test( '5 · mobile: bookbar mirrors the price, checkout wizard steps', async ( { page, request } ) => {
		const stay = await findStay( request, { from: '2028-04-17' } );
		await page.goto( stay.permalink );

		const bar = page.locator( '[data-mbk-bookbar]' );
		await expect( bar ).toBeVisible();
		await expect( bar.locator( '[data-bookbar-text]' ) ).toContainText( '€' );
		const box = await bar.boundingBox();
		expect( box, 'bookbar is laid out' ).not.toBeNull();
		expect( ( box?.y ?? 0 ) + ( box?.height ?? 0 ) ).toBeLessThanOrEqual( 844 + 1 );

		// pick the stay in the inline calendar: the bar follows the quoted total
		const cal = page.locator( '[data-date-inline]' ).first();
		await ( await showMonth( cal, stay.arrival ) ).click();
		await dayOf( cal, stay.departure ).click();
		await expect( bar.locator( '[data-bookbar-text]' ) ).toContainText( String( stay.nights ) );
		await page.screenshot( { path: test.info().outputPath( 'mobile-bookbar.png' ) } );

		await bar.getByRole( 'link' ).click();
		await expect( page.locator( '#rezerviraj' ) ).toBeInViewport();
		await page.locator( '#rezerviraj button[type="submit"]' ).click();
		await page.waitForURL( /\/rezervacija\/\?/ );

		const steps = page.locator( '[data-co-step]' );
		await expect( steps.nth( 0 ) ).toHaveAttribute( 'aria-current', 'step' );
		const width = await page.evaluate( () => document.documentElement.scrollWidth );
		expect( width, 'no horizontal overflow' ).toBeLessThanOrEqual( 390 );

		// empty step 1 → validation, then fill → step 2 with the Payment Element
		await page.locator( '[data-co-next]' ).click();
		await expect( page.locator( '[data-co-alert]' ) ).toBeVisible();
		await expect( page.locator( '#b-first' ) ).toHaveAttribute( 'aria-invalid', 'true' );
		await fillDetails( page, { first: 'Mia', last: 'Playwright', email: guestEmail( 'mobile' ) } );
		await expect( steps.nth( 0 ) ).toHaveClass( /is-done/ );
		await expect( page.locator( '[data-co-element] iframe' ).first() ).toBeVisible( { timeout: 45_000 } );
		await expect( page.locator( '[data-co-submit]' ) ).toBeVisible();
		await page.screenshot( { path: test.info().outputPath( 'mobile-step2.png' ), fullPage: true } );

		// Back returns to step 1 with the details kept
		await page.locator( '[data-co-prev]' ).click();
		await expect( steps.nth( 0 ) ).toHaveAttribute( 'aria-current', 'step' );
		await expect( page.locator( '#b-first' ) ).toHaveValue( 'Mia' );
	} );
} );

test( '6 · coupon DIRECT5 at checkout reduces the deposit', async ( { page, request } ) => {
	const stay = await findStay( request, { from: '2028-03-06' } );
	const email = guestEmail( 'direct5' );

	await page.goto( checkoutUrl( stay ) );
	const lines = page.locator( '[data-co-lines]' );
	const due = lines.locator( '.w-checkout__line--due dd' );
	await expect( due ).toContainText( '€' );
	const before = cents( ( await due.textContent() ) ?? '' );

	await page.locator( '#b-coupon' ).fill( 'direct5' );
	await page.locator( '[data-coupon-apply]' ).click();
	await expect( page.locator( '[data-coupon-applied]' ) ).toBeVisible();
	await expect( page.locator( '[data-coupon-code]' ) ).toHaveText( 'DIRECT5' );
	await expect( lines.locator( '.w-checkout__line--save' ) ).toBeVisible();
	await expect.poll( async () => cents( ( await due.textContent() ) ?? '' ) ).toBeLessThan( before );
	const after = cents( ( await due.textContent() ) ?? '' );

	await fillDetails( page, { first: 'Kata', last: 'Playwright', email } );
	await expect( page.locator( '[data-co-submit-label]' ) ).toContainText( ( await due.textContent() )?.trim() ?? '' );
	await fillCard( page, CARDS.visa );
	await submitPayment( page );
	await expectConfirmed( page );

	const [ booking ] = bookingsOf( email );
	expect( booking.status ).toBe( 'confirmed' );
	expect( booking.coupon_code ).toBe( 'DIRECT5' );
	expect( Number( booking.discount_cents ) ).toBeGreaterThan( 0 );
	expect( Number( booking.deposit_cents ) ).toBe( after );
} );
