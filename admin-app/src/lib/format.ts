/**
 * Locale-aware formatting of money (integer cents) and calendar dates (Y-m-d).
 *
 * The locale defaults to the WordPress user locale (`window.mbkAdmin.locale`,
 * e.g. `hr` or `en_US`) and can be overridden per call.
 */
import { _n, sprintf } from '@wordpress/i18n';

import { parseYmd } from '@/lib/dates';

export type DateStyle = 'short' | 'medium' | 'long' | 'day' | 'weekday' | 'month';

let defaultLocale = toBcp47( window.mbkAdmin?.locale ?? 'en_US' );

export function toBcp47( wpLocale: string ): string {
	const tag = wpLocale.replace( '_', '-' );
	// Bare `hr` formats months in genitive ("12. srp 2027."), which is what staff expect.
	return tag === 'hr' ? 'hr-HR' : tag;
}

export function setFormatLocale( wpLocale: string ): void {
	defaultLocale = toBcp47( wpLocale );
}

export function getFormatLocale(): string {
	return defaultLocale;
}

const moneyFormatters = new Map< string, Intl.NumberFormat >();

function moneyFormatter( locale: string, currency: string ): Intl.NumberFormat {
	const key = `${ locale }|${ currency }`;
	let formatter = moneyFormatters.get( key );
	if ( ! formatter ) {
		formatter = new Intl.NumberFormat( locale, {
			style: 'currency',
			currency,
			minimumFractionDigits: 2,
			maximumFractionDigits: 2,
		} );
		moneyFormatters.set( key, formatter );
	}
	return formatter;
}

/** 136500 → "1.365,00 €" (hr) / "€1,365.00" (en-US). */
export function formatMoney( cents: number, locale: string = defaultLocale, currency = 'EUR' ): string {
	return moneyFormatter( locale, currency ).format( cents / 100 );
}

/** Whole euros for dense grids: 11500 → "115 €" (hr) / "€115" (en). */
export function formatMoneyShort( cents: number, locale: string = defaultLocale, currency = 'EUR' ): string {
	return new Intl.NumberFormat( locale, { style: 'currency', currency, maximumFractionDigits: 0 } ).format( Math.round( cents / 100 ) );
}

/** Plain decimal for inputs: 136550 → "1365,50" (hr) / "1365.50" (en). */
export function centsToInput( cents: number | null | undefined, locale: string = defaultLocale ): string {
	if ( cents === null || cents === undefined || Number.isNaN( cents ) ) {
		return '';
	}
	const separator = decimalSeparator( locale );
	const sign = cents < 0 ? '-' : '';
	const abs = Math.abs( cents );
	const euros = Math.floor( abs / 100 );
	const rest = String( abs % 100 ).padStart( 2, '0' );
	return `${ sign }${ euros }${ separator }${ rest }`;
}

/** Parse "1.365,50", "1365.50", "1 365,5" → cents; null when not a number. */
export function parseMoneyInput( raw: string ): number | null {
	let value = raw.replace( /[\s€]/g, '' );
	if ( value === '' ) {
		return null;
	}
	const lastComma = value.lastIndexOf( ',' );
	const lastDot = value.lastIndexOf( '.' );
	const decimalIndex = Math.max( lastComma, lastDot );
	if ( decimalIndex !== -1 && value.length - decimalIndex - 1 <= 2 ) {
		value = value.slice( 0, decimalIndex ).replace( /[.,]/g, '' ) + '.' + value.slice( decimalIndex + 1 );
	} else {
		value = value.replace( /[.,]/g, '' );
	}
	if ( ! /^-?\d+(\.\d{0,2})?$/.test( value ) ) {
		return null;
	}
	return Math.round( parseFloat( value ) * 100 );
}

function decimalSeparator( locale: string ): string {
	const part = new Intl.NumberFormat( locale ).formatToParts( 1.5 ).find( ( p ) => p.type === 'decimal' );
	return part?.value ?? '.';
}

const dateOptions: Record< DateStyle, Intl.DateTimeFormatOptions > = {
	short: { day: 'numeric', month: 'numeric' },
	medium: { day: 'numeric', month: 'short', year: 'numeric' },
	long: { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' },
	day: { day: 'numeric', month: 'short' },
	weekday: { weekday: 'short', day: 'numeric', month: 'short' },
	month: { month: 'long', year: 'numeric' },
};

const dateFormatters = new Map< string, Intl.DateTimeFormat >();

function dateFormatter( locale: string, style: DateStyle ): Intl.DateTimeFormat {
	const key = `${ locale }|${ style }`;
	let formatter = dateFormatters.get( key );
	if ( ! formatter ) {
		formatter = new Intl.DateTimeFormat( locale, dateOptions[ style ] );
		dateFormatters.set( key, formatter );
	}
	return formatter;
}

/** "2027-07-12" → "12. srp 2027." (hr, medium). */
export function formatDate( ymd: string | null | undefined, style: DateStyle = 'medium', locale: string = defaultLocale ): string {
	if ( ! ymd ) {
		return '—';
	}
	return dateFormatter( locale, style ).format( parseYmd( ymd.slice( 0, 10 ) ) );
}

/** "12.–19. srp 2027." style range using Intl formatRange. */
export function formatDateRange( from: string, to: string, locale: string = defaultLocale ): string {
	const formatter = dateFormatter( locale, 'medium' );
	return formatter.formatRange( parseYmd( from ), parseYmd( to ) );
}

/** Stand-alone month names, index 0 = January: "sij"… (hr, short) / "Jan"… (en, short). */
export function monthNames( style: 'short' | 'long' = 'short', locale: string = defaultLocale ): string[] {
	const formatter = new Intl.DateTimeFormat( locale, { month: style, timeZone: 'UTC' } );
	return Array.from( { length: 12 }, ( _, month ) => formatter.format( new Date( Date.UTC( 2027, month, 1 ) ) ) );
}

/** ISO datetime → "12. srp 2027. 14:05". */
export function formatDateTime( iso: string | null | undefined, locale: string = defaultLocale ): string {
	if ( ! iso ) {
		return '—';
	}
	const date = new Date( iso );
	if ( Number.isNaN( date.getTime() ) ) {
		return iso;
	}
	return new Intl.DateTimeFormat( locale, {
		day: 'numeric',
		month: 'short',
		year: 'numeric',
		hour: '2-digit',
		minute: '2-digit',
	} ).format( date );
}

/** ISO datetime → "5 min ago" / "3 days ago". */
export function formatRelative( iso: string | null | undefined, locale: string = defaultLocale ): string {
	if ( ! iso ) {
		return '—';
	}
	const date = new Date( iso );
	const diffSeconds = Math.round( ( date.getTime() - Date.now() ) / 1000 );
	const rtf = new Intl.RelativeTimeFormat( locale, { numeric: 'auto' } );
	const abs = Math.abs( diffSeconds );
	if ( abs < 60 ) {
		return rtf.format( diffSeconds, 'second' );
	}
	if ( abs < 3600 ) {
		return rtf.format( Math.round( diffSeconds / 60 ), 'minute' );
	}
	if ( abs < 86400 ) {
		return rtf.format( Math.round( diffSeconds / 3600 ), 'hour' );
	}
	if ( abs < 86400 * 30 ) {
		return rtf.format( Math.round( diffSeconds / 86400 ), 'day' );
	}
	return formatDateTime( iso, locale );
}

/** 42.5 → "43 %" (hr) / "43%" (en); `value` is a percentage, not a fraction. */
export function formatPercent( value: number, locale: string = defaultLocale, fractionDigits = 0 ): string {
	return new Intl.NumberFormat( locale, { style: 'percent', maximumFractionDigits: fractionDigits } ).format( value / 100 );
}

export function formatNumber( value: number, locale: string = defaultLocale ): string {
	return new Intl.NumberFormat( locale ).format( value );
}

export function nightsLabel( nights: number ): string {
	return sprintf(
		/* translators: %d: number of nights. */
		_n( '%d night', '%d nights', nights, 'marclie-booking' ),
		nights
	);
}

export function minStayLabel( nights: number ): string {
	return sprintf(
		/* translators: %d: minimum number of nights. */
		_n( 'min %d night', 'min %d nights', nights, 'marclie-booking' ),
		nights
	);
}

/** "2 adults, 1 child" (+ ", 1 child 0–4" when there are children 0–4). */
export function guestsLabel( adults: number, children: number, infants: number ): string {
	const adultsText = sprintf(
		/* translators: %d: number of adults. */
		_n( '%d adult', '%d adults', adults, 'marclie-booking' ),
		adults
	);
	const childrenText = sprintf(
		/* translators: %d: number of children. */
		_n( '%d child', '%d children', children, 'marclie-booking' ),
		children
	);
	if ( infants <= 0 ) {
		return `${ adultsText }, ${ childrenText }`;
	}
	const infantsText = sprintf(
		/* translators: %d: number of children aged 0–4. */
		_n( '%d child 0–4', '%d children 0–4', infants, 'marclie-booking' ),
		infants
	);
	return `${ adultsText }, ${ childrenText }, ${ infantsText }`;
}
