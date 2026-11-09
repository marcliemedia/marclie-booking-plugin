/**
 * REST types — see plans/…/reports/admin-api-contract.md (binding contract).
 */
export type Cents = number;
export type YMD = string;
export type ISO = string;

export type BookingStatus =
	| 'pending_payment'
	| 'confirmed'
	| 'paid'
	| 'balance_failed'
	| 'cancelled'
	| 'expired'
	| 'completed';

export type BookingSource = 'direct' | 'admin' | 'airbnb' | 'booking';

export interface Unit {
	id: number;
	code: string;
	title: string;
	house: string;
	houseSlug: string | null;
	capacity: number;
	maxChildren: number;
	thumb: string | null;
	permalink: string;
	checkinFrom: string;
	checkinTo: string;
	checkoutUntil: string;
}

export interface GuestRef {
	id: number | null;
	name: string;
	email: string;
	country: string | null;
}

export interface BookingSummary {
	id: number;
	ref: string;
	status: BookingStatus;
	source: BookingSource;
	unit_id: number;
	unit_title: string;
	arrival: YMD;
	departure: YMD;
	nights: number;
	adults: number;
	children: number;
	/** Children 0–4: free, not counted toward capacity or the minimum. */
	infants: number;
	guest: GuestRef;
	total_cents: Cents;
	paid_cents: Cents;
	balance_cents: Cents;
	balance_due_on: YMD | null;
	free_cancel_until: YMD | null;
	created_at: ISO;
}

export type PaymentKind = 'deposit' | 'balance' | 'full' | 'refund';
export type PaymentMethod = 'stripe' | 'cash' | 'bank' | 'other';
export type PaymentStatus = 'scheduled' | 'pending' | 'succeeded' | 'failed' | 'refunded';

export interface Payment {
	id: number;
	booking_id: number;
	booking_ref: string;
	guest_name: string;
	kind: PaymentKind;
	method: PaymentMethod;
	amount_cents: Cents;
	status: PaymentStatus;
	card_brand: string | null;
	card_last4: string | null;
	stripe_intent_id: string | null;
	/** Stripe refund id (`re_…`) on refund rows created by an admin cancel. */
	stripe_refund_id?: string | null;
	/** Deep link into the Stripe Dashboard for this intent/refund (test or live). */
	stripe_dashboard_url?: string | null;
	/** Refund rows: id of the charge row they refund. */
	refund_of_id?: number | null;
	/** Stripe error/decline code on failed rows (e.g. `card_declined`, `authentication_required`). */
	error_code?: string | null;
	created_at: ISO;
	note: string | null;
}

export interface EventItem {
	id: number;
	type: string;
	actor: string;
	created_at: ISO;
	booking_ref: string | null;
	summary: string;
	payload: Record< string, unknown >;
}

/**
 * One line of a price quote.
 * - `nights`: `unit_cents` = apartment nightly price: `adults` × `person_cents` + `children` (5–10)
 *   × `child_cents` (half the person price), or `min_guests` × `person_cents` when the party is
 *   below the unit minimum (`min_guests` = 0 when the party pays).
 */
export interface QuoteLine {
	type: 'nights' | 'cleaning' | 'tourist_tax' | 'coupon';
	nights: number;
	unit_cents: Cents;
	total_cents: Cents;
	person_cents: Cents;
	adults: number;
	children: number;
	child_cents: Cents;
	min_guests: number;
}

export interface Quote {
	unit_id: number;
	arrival: YMD;
	departure: YMD;
	nights: number;
	adults: number;
	children: number;
	infants: number;
	lines: QuoteLine[];
	subtotal_cents: Cents;
	discount_cents: Cents;
	total_cents: Cents;
	deposit_cents: Cents;
	balance_cents: Cents;
	balance_due_on: YMD | null;
	free_cancel_until: YMD | null;
	pay_in_full: boolean;
	coupon_code: string | null;
	min_stay: number;
}

/** Membership tier of a guest account (`mbk_tier` user meta). */
export type MemberTier = 'clan' | 'srebrni' | 'zlatni';

/** WordPress user (role `mbk_guest`) linked to a guest profile. */
export interface GuestAccount {
	id: number;
	email: string;
	email_verified_at: ISO | null;
	tier: MemberTier | null;
	tier_since: YMD | null;
	member_since: ISO;
}

export interface Guest {
	id: number;
	first_name: string;
	last_name: string;
	email: string;
	phone: string | null;
	country: string | null;
	lang: 'hr' | 'en';
	tags: string[];
	notes: string | null;
	marketing_consent: boolean;
	bookings_count: number;
	nights_total: number;
	revenue_cents: Cents;
	first_booking_at: ISO | null;
	last_stay_at: YMD | null;
	anonymized: boolean;
	/** Linked guest account (WP user id), `null` when the guest has none. */
	user_id: number | null;
	tier: MemberTier | null;
}

export interface BookingDetail extends BookingSummary {
	/** The booking row's own contact fields (may differ from the guest profile). */
	first_name: string;
	last_name: string;
	email: string;
	phone: string;
	arrival_time: string | null;
	message: string | null;
	internal_notes: string | null;
	lang: 'hr' | 'en';
	quote: Quote | null;
	policy: Record< string, unknown >;
	hold_expires_at: ISO | null;
	coupon_code: string | null;
	/** A Stripe customer + payment method is saved, so an off-session charge is possible. */
	saved_card?: boolean;
	/** E-mail templates that may be sent again for this booking (status fits, valid recipient). */
	resendable_codes?: string[];
	payments: Payment[];
	events: EventItem[];
	guest_full: Guest | null;
	/** Guest account the booking is linked to. */
	user_id: number | null;
	account: GuestAccount | null;
	allowed_transitions: BookingStatus[];
}

/** One unit's row of the monthly price table (same every year). */
export interface UnitPrices {
	unit_id: number;
	min_stay: number;
	/** Minimum paying guests (1…capacity): the apartment is charged for at least this many persons. */
	min_guests: number;
	/** Length 12, index 0 = January; cents per person per night, `null` = month closed. */
	months: ( Cents | null )[];
}

export interface PricesResponse {
	units: UnitPrices[];
}

/** Calendar price of one night: `cents` = apartment nightly price. */
export interface CalendarPrice {
	cents: Cents;
	person_cents: Cents;
	min_stay: number;
}

export interface Coupon {
	id: number;
	code: string;
	type: 'pct' | 'fixed';
	amount: number;
	min_nights: number | null;
	book_from: YMD | null;
	book_to: YMD | null;
	stay_from: YMD | null;
	stay_to: YMD | null;
	unit_ids: number[] | null;
	usage_limit: number | null;
	per_guest_limit: number | null;
	used_count: number;
	guest_id: number | null;
	active: boolean;
	created_at: ISO;
}

export interface ListResponse< T > {
	items: T[];
	total: number;
	page: number;
	per_page: number;
}

export interface ListQuery {
	page?: number;
	per_page?: number;
	orderby?: string;
	order?: 'asc' | 'desc';
}

/* ---- Shell-owned resources -------------------------------------------- */

export interface Policy {
	deposit_pct: number;
	balance_days: number;
	free_cancel_days: number;
	hold_minutes: number;
	default_coupon: string | null;
	checkin_from: string;
	checkin_to: string;
	checkout_until: string;
	ref_prefix: string;
}

export interface Bootstrap {
	/** `email` is the logged-in user's address (default recipient of test e-mails). */
	user: { id: number; name: string; email?: string; locale: string };
	caps: { manage_bookings: boolean; manage_rates: boolean; manage_settings: boolean };
	version: string;
	/** Business name (ACF option `brand_name`, else the site title). */
	brand: string;
	today: YMD;
	currency: 'EUR';
	units: Unit[];
	statuses: BookingStatus[];
	policy: Policy;
	stripe: { mode: 'test' | 'live' | null; configured: boolean };
}

export type AlertType =
	| 'balance_failed'
	| 'stale_hold'
	| 'dispute'
	| 'overpayment'
	| 'webhook_silent'
	| 'ical_conflict'
	| 'ical_feed_failing'
	| 'ical_stale_echo'
	| 'stripe_not_configured'
	| 'cancel_request';

export interface DashboardAlert {
	type: AlertType;
	booking_id?: number;
	booking_ref?: string;
	/** External link (e.g. the dispute in the Stripe Dashboard). */
	url?: string;
	message: string;
	/** `cancel_request`: the guest's reason and when it was sent. */
	reason?: string | null;
	requested_at?: ISO;
	/** `ical_conflict` / `ical_stale_echo`: first night (`date_to` exclusive end). `ical_feed_failing`: `since` = start of the failure streak. */
	date?: YMD;
	date_to?: YMD;
	since?: ISO;
	unit_id?: number;
	feed_id?: number;
	external_block_id?: number;
	channel?: IcalChannel;
	/** `ical_feed_failing`: no successful sync of any feed for 2 h — WP-Cron is probably not running. */
	stalled?: boolean;
	/** Informational alerts (`ical_stale_echo`) are not problems that need action. */
	level?: 'info';
}

export interface DashboardStay extends BookingSummary {
	arrival_time?: string | null;
	message?: string | null;
}

export interface Dashboard {
	kpis: {
		occupancy_pct: number;
		occupancy_next_pct: number;
		collected_cents: Cents;
		scheduled_cents: Cents;
		new_bookings_7d: number;
		balances_due_14d_cents: Cents;
	};
	today: { arrivals: DashboardStay[]; departures: DashboardStay[] };
	alerts: DashboardAlert[];
	occupancy_by_month: { month: string; pct: number }[];
	revenue_by_unit: { unit_id: number; cents: Cents }[];
}

export interface CalendarBooking {
	id: number;
	ref: string;
	unit_id: number;
	arrival: YMD;
	departure: YMD;
	status: BookingStatus;
	source: BookingSource;
	guest_name: string;
	nights: number;
	adults: number;
	children: number;
	infants: number;
}

export interface CalendarBlock {
	id: number;
	unit_id: number;
	date_from: YMD;
	date_to: YMD;
	reason: string;
}

export type IcalChannel = 'airbnb' | 'booking' | 'other';

export interface CalendarExternal {
	id: number;
	unit_id: number;
	date_from: YMD;
	date_to: YMD;
	channel: IcalChannel;
	summary: string;
	/** Overlaps a direct/admin booking that occupies the same nights. */
	conflict: boolean;
	/** `echo`: our own export read back from the channel — never a conflict, never re-exported. */
	origin: 'external' | 'echo';
}

export interface CalendarData {
	bookings: CalendarBooking[];
	blocks: CalendarBlock[];
	external: CalendarExternal[];
	prices?: Record< string, Record< YMD, CalendarPrice > >;
}

export type BookingCounts = Record< BookingStatus | 'all' | 'upcoming', number >;

export interface BookingListResponse extends ListResponse< BookingSummary > {
	counts: BookingCounts;
}

export type PaymentState = 'unpaid' | 'deposit' | 'paid' | 'failed';

export interface BookingListQuery extends ListQuery {
	status?: BookingStatus | 'upcoming' | '';
	unit_id?: number | '';
	source?: BookingSource | '';
	search?: string;
	date_field?: 'arrival' | 'departure' | 'created';
	from?: YMD;
	to?: YMD;
	payment_state?: PaymentState | '';
}

export interface QuoteResponse {
	quote: Quote;
	available: boolean;
}

export interface MoveDryRun {
	available: boolean;
	quote_old: Quote;
	quote_new: Quote;
	diff_cents: Cents;
	coupon_dropped?: boolean;
}

export interface CancelDecision {
	refundable_cents: Cents;
	refund_cents: Cents;
	reason: string;
	paid_cents?: Cents;
	within_free_cancel?: boolean;
	free_cancel_until?: YMD | null;
	/** Planned refunds per received charge (card refunds go through Stripe). */
	refunds?: CancelRefundLine[];
}

export interface CancelRefundLine {
	/** Null in dry runs (refund rows do not exist yet). */
	payment_id: number | null;
	method: PaymentMethod;
	amount_cents: Cents;
	card_brand?: string | null;
	card_last4?: string | null;
}

export interface PaymentLink {
	url: string;
	expires_at: ISO;
	/** Set when the request asked for `send_email`: whether the guest e-mail went out. */
	email_sent?: boolean;
}

export interface SearchResults {
	bookings: { id: number; ref: string; guest_name: string; unit_title: string; arrival: YMD; status: BookingStatus }[];
	guests: { id: number; name: string; email: string }[];
}

export interface NewGuestInput {
	first_name: string;
	last_name: string;
	email: string;
	phone: string;
	country: string;
	lang: 'hr' | 'en';
}

export interface CreateBookingInput {
	unit_id: number;
	arrival: YMD;
	departure: YMD;
	adults: number;
	children: number;
	infants: number;
	guest: { id: number } | NewGuestInput;
	coupon?: string;
	payment: { mode: 'outside' | 'none'; amount_cents?: Cents; method?: 'cash' | 'bank' | 'other' };
	internal_notes?: string;
}

export interface UpdateBookingInput {
	first_name?: string;
	last_name?: string;
	email?: string;
	phone?: string;
	country?: string;
	lang?: 'hr' | 'en';
	adults?: number;
	children?: number;
	infants?: number;
	arrival_time?: string;
	internal_notes?: string;
}

/* ---- E-mail templates (phase 07) ------------------------------------- */

export type EmailLang = 'hr' | 'en';
export type EmailRecipient = 'guest' | 'admin';

export interface EmailContent {
	subject: string;
	preheader: string;
	heading: string;
	body_html: string;
}

export interface EmailTemplateListItem {
	code: string;
	recipient: EmailRecipient;
	label: string;
	/** When the e-mail is sent (trigger). */
	description: string;
	enabled: boolean;
	langs: Record< EmailLang, { customized: boolean; updated_at: ISO | null } >;
}

export interface EmailTemplateLangContent extends EmailContent {
	customized: boolean;
	updated_at?: ISO | null;
}

export interface EmailTemplateDetail {
	code: string;
	recipient: EmailRecipient;
	label: string;
	description: string;
	enabled: boolean;
	/** Merge tag behind the `{cta_button}` block (e.g. `manage_link`), if any. */
	cta?: string | null;
	/** Allowed merge tags (with or without braces). */
	merge_tags: string[];
	required_tags: string[];
	/** Dynamic blocks allowed in the body (`{booking_summary}`, …). */
	blocks: string[];
	hr: EmailTemplateLangContent;
	en: EmailTemplateLangContent;
}

export interface EmailPreview {
	subject: string;
	html: string;
	text: string;
}

export interface EmailPreviewInput {
	lang: EmailLang;
	draft?: EmailContent;
	booking_id?: number;
}
