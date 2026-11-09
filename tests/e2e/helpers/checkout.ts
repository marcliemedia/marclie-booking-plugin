/**
 * Guest-side steps shared by the specs: inline calendar, checkout wizard, Stripe Payment Element.
 */
import { expect, type Frame, type Locator, type Page } from '@playwright/test';

export const CARDS = {
	visa: '4242 4242 4242 4242',
	threeDs: '4000 0027 6000 3184',
	insufficientFunds: '4000 0000 0000 9995',
} as const;

/** Turns the inline calendar of `root` until `date` is shown, then waits for its availability. */
export async function showMonth( root: Locator, date: string ): Promise< Locator > {
	const day = root.locator( `[data-dp-date="${ date }"]` );
	for ( let i = 0; i < 40 && ! ( await day.count() ); i += 1 ) {
		await root.locator( '[data-dp-nav="1"]' ).click();
	}
	await expect( day ).toBeVisible();
	// every month turned loads GET /availability; the local server answers them slowly
	await expect( root ).not.toHaveClass( /is-loading/, { timeout: 120_000 } );
	await expect( day ).not.toHaveClass( /is-pending/, { timeout: 30_000 } );
	return day;
}

/** Day button of the calendar `root`. */
export const dayOf = ( root: Locator, date: string ) => root.locator( `[data-dp-date="${ date }"]` );

/** Asserts a day cannot be picked: aria-disabled and visibly inactive (opacity < 0.6). */
export async function expectInactiveDay( day: Locator ): Promise< void > {
	await expect( day ).toHaveAttribute( 'aria-disabled', 'true' );
	const opacity = await day.evaluate( ( el ) => parseFloat( getComputedStyle( el ).opacity ) );
	expect( opacity, 'unselectable day opacity' ).toBeLessThan( 0.6 );
}

export interface Guest {
	first: string;
	last: string;
	email: string;
	/** Full international number; the flag field gets its country from the dial code. */
	phone?: string;
}

/** Step 1 „Vaši podaci” → step 2 (payment). */
export async function fillDetails( page: Page, guest: Guest ): Promise< void > {
	const form = page.locator( '[data-co-form]' );
	await expect( form ).toBeVisible();
	// the aside quote has loaded (begin_checkout): the stay is bookable
	await expect( page.locator( '[data-co-lines]' ) ).not.toHaveAttribute( 'aria-busy', 'true' );
	await page.locator( '#b-first' ).fill( guest.first );
	await page.locator( '#b-last' ).fill( guest.last );
	await page.locator( '#b-email' ).fill( guest.email );
	// the phone field shows the dial code next to a flag: set the whole number through intl-tel-input
	await page.locator( '#b-phone' ).evaluate( ( el, number ) => {
		const iti = ( el as HTMLInputElement & { mbkIti?: { setNumber: ( n: string ) => void } } ).mbkIti;
		if ( iti ) {
			iti.setNumber( number );
		} else {
			( el as HTMLInputElement ).value = number;
		}
		el.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	}, guest.phone ?? '+385 91 555 0101' );
	await page.locator( '[data-co-next]' ).click();
	await expect( page.locator( '[data-co-panel]' ).nth( 1 ) ).toBeVisible();
	await expect( page.locator( '[data-co-step]' ).nth( 1 ) ).toHaveAttribute( 'aria-current', 'step' );
}

/** The Payment Element iframe (card fields). */
export function paymentFrame( page: Page ) {
	return page.locator( '[data-co-element] iframe[title]' ).first().contentFrame();
}

/** Types a test card into the Payment Element. */
export async function fillCard( page: Page, number: string ): Promise< void > {
	const frame = paymentFrame( page );
	const cardNumber = frame.locator( 'input[name="number"]' );
	await expect( cardNumber ).toBeVisible( { timeout: 45_000 } );
	await cardNumber.fill( number );
	await frame.locator( 'input[name="expiry"]' ).fill( '12 / 34' );
	await frame.locator( 'input[name="cvc"]' ).fill( '123' );
	const postal = frame.locator( 'input[name="postalCode"]' );
	if ( await postal.isVisible().catch( () => false ) ) {
		await postal.fill( '23250' );
	}
}

/** Accepts the terms and presses „Potvrdi i plati”. */
export async function submitPayment( page: Page ): Promise< void > {
	await page.locator( '[data-co-terms] input[type="checkbox"]' ).check();
	await page.locator( '[data-co-submit]' ).click();
}

/**
 * Finds Stripe's 3-D Secure test challenge (nested frames) and presses COMPLETE / FAIL, again
 * while the challenge stays open (a press during its opening animation is ignored).
 */
export async function completeThreeDs( page: Page, action: 'complete' | 'fail' = 'complete' ): Promise< void > {
	const selector = action === 'complete' ? '#test-source-authorize-3ds' : '#test-source-fail-3ds';
	const deadline = Date.now() + 90_000;
	let pressed = false;
	while ( Date.now() < deadline ) {
		const frame = await challengeFrame( page, selector );
		if ( ! frame ) {
			if ( pressed ) {
				return;
			}
		} else {
			await page.waitForTimeout( 1_000 );
			await frame.locator( selector ).click( { timeout: 5_000 } ).catch( () => undefined );
			pressed = true;
			await page.waitForTimeout( 4_000 );
			continue;
		}
		await page.waitForTimeout( 500 );
	}
	throw new Error( pressed ? '3-D Secure challenge did not close.' : '3-D Secure challenge did not appear.' );
}

async function challengeFrame( page: Page, selector: string ): Promise< Frame | null > {
	for ( const frame of page.frames() ) {
		if ( await frame.locator( selector ).isVisible().catch( () => false ) ) {
			return frame;
		}
	}
	return null;
}

/** Thank-you page reached and confirmed (data-cf-state="done"). Returns the booking ref. */
export async function expectConfirmed( page: Page ): Promise< string > {
	await page.waitForURL( /\/hvala\/?\?/, { timeout: 90_000 } );
	const done = page.locator( '[data-cf-state="done"]' );
	await expect( done ).toBeVisible( { timeout: 90_000 } );
	const ref = ( await done.locator( '[data-cf="ref"]' ).first().textContent() )?.trim() ?? '';
	expect( ref ).not.toBe( '' );
	return ref;
}

/** Money text "1.234,50 €" / "€1,234.50" → cents. */
export function cents( text: string ): number {
	const t = text.replace( /[^\d.,]/g, '' );
	const m = /^(.*?)[.,](\d{2})$/.exec( t );
	return m ? parseInt( m[ 1 ].replace( /[.,]/g, '' ) || '0', 10 ) * 100 + parseInt( m[ 2 ], 10 ) : parseInt( t.replace( /[.,]/g, '' ), 10 ) * 100;
}
