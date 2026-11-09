<?php
/**
 * Database schema (dbDelta) and version-based migrations.
 *
 * DDL rules: plain MySQL types only (no ENUM / FULLTEXT), one column per line,
 * two spaces after PRIMARY KEY — dbDelta parses the statements textually.
 * Money is INT cents, stay dates are DATE, system timestamps are DATETIME UTC.
 */

namespace Marclie\Booking\Install;

defined( 'ABSPATH' ) || exit;

final class Schema {

	/** Bump on every schema change; Activator::upgrade() re-runs install() when it differs (v6: guest account role, no DDL change; v7: iCal sync; v8: iCal echo provenance; v9: 12-month price table replaces seasons; v10: minimum paying guests, children 0–4). */
	public const VERSION = '10';

	public const VERSION_OPTION = 'mbk_db_version';

	/** Unprefixed table names (without `{$wpdb->prefix}`). */
	public const TABLES = array(
		'mbk_bookings',
		'mbk_guests',
		'mbk_nights',
		'mbk_blocks',
		'mbk_external_blocks',
		'mbk_ical_feeds',
		'mbk_prices',
		'mbk_coupons',
		'mbk_coupon_redemptions',
		'mbk_email_templates',
		'mbk_payments',
		'mbk_events',
		'mbk_booking_tokens',
		'mbk_ical_exports',
		'mbk_ical_export_fetches',
	);

	/** Tables of older versions, dropped by migrate() (uninstall drops them too). v9: seasons × rates. */
	private const LEGACY_TABLES = array( 'mbk_seasons', 'mbk_rates' );

	public static function table( string $name ): string {
		global $wpdb;

		return $wpdb->prefix . $name;
	}

	/**
	 * Runs version migrations, then dbDelta. The version is saved only when no SQL
	 * error occurred and every table exists, so a failed run is retried next load.
	 */
	public static function install(): bool {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$from = (int) get_option( self::VERSION_OPTION, 0 );
		self::migrate( $from );

		$wpdb->last_error = '';
		dbDelta( self::statements() );

		if ( '' !== $wpdb->last_error || self::missingTables() ) {
			return false;
		}

		self::migrateData( $from );
		if ( '' !== $wpdb->last_error ) {
			return false;
		}

		update_option( self::VERSION_OPTION, self::VERSION, true );

		return true;
	}

	/**
	 * @return list<string> Prefixed names of plugin tables that do not exist.
	 */
	public static function missingTables(): array {
		global $wpdb;

		$missing = array();
		foreach ( self::TABLES as $name ) {
			$table = self::table( $name );
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
				$missing[] = $table;
			}
		}

		return $missing;
	}

	/**
	 * Changes dbDelta cannot express (it never drops or alters indexes). 0 = fresh install.
	 */
	private static function migrate( int $from ): void {
		if ( $from > 0 && $from < 2 ) {
			// v2: payments.stripe_intent_id UNIQUE → plain KEY (re-added by dbDelta).
			self::dropIndex( 'mbk_payments', 'stripe_intent_id', true );
		}
		if ( $from > 0 && $from < 3 ) {
			// v3: payments recorded outside Stripe (cash, bank transfer…) with an admin note.
			self::addColumn( 'mbk_payments', 'method', "varchar(20) NOT NULL default 'stripe'", 'kind' );
			self::addColumn( 'mbk_payments', 'note', 'text NULL', 'error_code' );
		}
		if ( $from > 0 && $from < 4 ) {
			// v4: idempotent outside payments; date indexes for admin filters/dashboard (indexes: dbDelta).
			self::addColumn( 'mbk_payments', 'idempotency_key', 'varchar(64) NULL', 'note' );
		}
		if ( $from > 0 && $from < 5 ) {
			// v5: Stripe webhook dedupe (events.external_id, UNIQUE via dbDelta), GA purchase flag.
			self::addColumn( 'mbk_events', 'external_id', 'varchar(100) NULL', 'payload_json' );
			self::addColumn( 'mbk_bookings', 'ga_sent_at', 'datetime NULL', 'hold_expires_at' );
		}
		if ( $from > 0 && $from < 7 ) {
			// v7: iCal sync state per feed (mbk_ical_exports is created by dbDelta).
			self::addColumn( 'mbk_ical_feeds', 'last_modified', 'varchar(100) NULL', 'etag' );
			self::addColumn( 'mbk_ical_feeds', 'active', 'tinyint(1) unsigned NOT NULL default 1', 'last_modified' );
			self::addColumn( 'mbk_ical_feeds', 'last_ok_at', 'datetime NULL', 'active' );
			self::addColumn( 'mbk_ical_feeds', 'last_error', 'varchar(255) NULL', 'last_ok_at' );
			self::addColumn( 'mbk_ical_feeds', 'fail_since', 'datetime NULL', 'last_error' );
			self::addColumn( 'mbk_ical_feeds', 'events_count', 'int(10) unsigned NOT NULL default 0', 'fail_since' );
			self::addColumn( 'mbk_ical_feeds', 'created_at', 'datetime NULL', 'events_count' );
		}
		if ( $from > 0 && $from < 8 ) {
			// v8: provenance of imported blocks (echo of our own export vs. external), one row per feed + UID.
			self::addColumn( 'mbk_external_blocks', 'first_seen_at', 'datetime NULL', 'seen_at' );
			self::addColumn( 'mbk_external_blocks', 'origin', "varchar(10) NOT NULL default 'external'", 'first_seen_at' );
			self::addColumn( 'mbk_external_blocks', 'orphaned_at', 'datetime NULL', 'origin' );
			self::addColumn( 'mbk_external_blocks', 'orphan_fetch_at', 'datetime NULL', 'orphaned_at' );
			self::dedupeExternalBlocks();
			// The plain KEY feed_uid becomes UNIQUE KEY feed_uid (re-added by dbDelta).
			self::dropIndex( 'mbk_external_blocks', 'feed_uid', false );
		}
		if ( $from > 0 && $from < 9 ) {
			// v9: seasons × rates replaced by the 12-month price table (mbk_prices, created by dbDelta, starts empty).
			global $wpdb;
			foreach ( self::LEGACY_TABLES as $name ) {
				$wpdb->query( 'DROP TABLE IF EXISTS ' . self::table( $name ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
			}
		}
		if ( $from > 0 && $from < 10 ) {
			// v10: per-unit minimum paying guests; children 0–4 (free, not counted in capacity) on bookings.
			self::addColumn( 'mbk_prices', 'min_guests', 'smallint(5) unsigned NOT NULL default 1', 'min_stay' );
			self::addColumn( 'mbk_bookings', 'infants', 'tinyint(3) unsigned NOT NULL default 0', 'children' );
		}
	}

	/**
	 * Data migrations that need the tables dbDelta just created.
	 */
	private static function migrateData( int $from ): void {
		global $wpdb;

		if ( $from > 0 && $from < 5 ) {
			// v5: guest access tokens move to mbk_booking_tokens (purpose `access`, no expiry).
			// bookings.access_token_hash is kept (legacy, no longer read or written).
			$bookings = self::table( 'mbk_bookings' );
			$tokens   = self::table( 'mbk_booking_tokens' );
			$rows     = (array) $wpdb->get_results( "SELECT b.id, b.access_token_hash, b.created_at FROM {$bookings} b LEFT JOIN {$tokens} t ON t.booking_id = b.id AND t.purpose = 'access' WHERE b.access_token_hash <> '' AND t.id IS NULL", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			foreach ( $rows as $row ) {
				$wpdb->insert(
					$tokens,
					array(
						'booking_id' => (int) $row['id'],
						'purpose'    => 'access',
						'token_hash' => (string) $row['access_token_hash'],
						'expires_at' => null,
						'created_at' => (string) $row['created_at'],
					)
				);
			}
		}
		if ( $from > 0 && $from < 8 ) {
			// v8: blocks imported before provenance existed count as external, first seen when last seen.
			$external = self::table( 'mbk_external_blocks' );
			$wpdb->query( $wpdb->prepare( "UPDATE {$external} SET first_seen_at = COALESCE(seen_at, %s) WHERE first_seen_at IS NULL", gmdate( 'Y-m-d H:i:s' ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}

	/** Keeps the oldest row per (feed_id, uid) so the UNIQUE key can be added. */
	private static function dedupeExternalBlocks(): void {
		global $wpdb;

		$table = self::table( 'mbk_external_blocks' );
		if ( in_array( $table, self::missingTables(), true ) ) {
			return;
		}
		$wpdb->query( "DELETE FROM {$table} WHERE id NOT IN (SELECT id FROM (SELECT MIN(id) AS id FROM {$table} GROUP BY feed_id, uid) AS keep_rows)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	private static function addColumn( string $name, string $column, string $definition, string $after ): void {
		global $wpdb;

		$table = self::table( $name );
		if ( in_array( $table, self::missingTables(), true ) ) {
			return;
		}

		$exists = $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM `' . esc_sql( $table ) . '` LIKE %s', $column ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( $exists ) {
			return;
		}

		$wpdb->query( 'ALTER TABLE `' . esc_sql( $table ) . '` ADD COLUMN `' . esc_sql( $column ) . '` ' . $definition . ' AFTER `' . esc_sql( $after ) . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/** Drops an index when it exists and is (`$unique` true) or is not (false) unique. */
	private static function dropIndex( string $name, string $index, bool $unique ): void {
		global $wpdb;

		$table = self::table( $name );
		if ( in_array( $table, self::missingTables(), true ) ) {
			return;
		}

		foreach ( (array) $wpdb->get_results( 'SHOW INDEX FROM `' . esc_sql( $table ) . '`' ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( $index === $row->Key_name && ( 0 === (int) $row->Non_unique ) === $unique ) {
				$wpdb->query( 'ALTER TABLE `' . esc_sql( $table ) . '` DROP INDEX `' . esc_sql( $index ) . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				return;
			}
		}
	}

	/**
	 * Drops every plugin table, also those of older versions not migrated yet. Only called from uninstall.php.
	 */
	public static function drop(): void {
		global $wpdb;

		foreach ( array_merge( self::TABLES, self::LEGACY_TABLES ) as $name ) {
			$wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( self::table( $name ) ) . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		delete_option( self::VERSION_OPTION );
	}

	/**
	 * @return list<string> CREATE TABLE statements for dbDelta.
	 */
	public static function statements(): array {
		global $wpdb;

		$collate = $wpdb->get_charset_collate();
		$t       = static fn( string $name ): string => self::table( $name );

		return array(
			"CREATE TABLE {$t( 'mbk_bookings' )} (
  id bigint(20) unsigned NOT NULL auto_increment,
  ref varchar(40) NOT NULL,
  access_token_hash varchar(64) NOT NULL default '',
  unit_id bigint(20) unsigned NOT NULL,
  guest_id bigint(20) unsigned NULL,
  arrival date NOT NULL,
  departure date NOT NULL,
  nights smallint(5) unsigned NOT NULL default 0,
  adults tinyint(3) unsigned NOT NULL default 1,
  children tinyint(3) unsigned NOT NULL default 0,
  infants tinyint(3) unsigned NOT NULL default 0,
  status varchar(20) NOT NULL default 'hold',
  lang varchar(5) NOT NULL default 'hr',
  first_name varchar(100) NOT NULL default '',
  last_name varchar(100) NOT NULL default '',
  email varchar(190) NOT NULL default '',
  phone varchar(40) NOT NULL default '',
  country char(2) NOT NULL default '',
  arrival_time varchar(20) NOT NULL default '',
  message text NULL,
  internal_notes text NULL,
  user_id bigint(20) unsigned NULL,
  currency char(3) NOT NULL default 'EUR',
  subtotal_cents int(11) NOT NULL default 0,
  discount_cents int(11) NOT NULL default 0,
  total_cents int(11) NOT NULL default 0,
  deposit_cents int(11) NOT NULL default 0,
  balance_cents int(11) NOT NULL default 0,
  balance_due_on date NULL,
  free_cancel_until date NULL,
  coupon_code varchar(50) NULL,
  quote_json longtext NULL,
  policy_json longtext NULL,
  stripe_customer_id varchar(100) NULL,
  stripe_payment_method_id varchar(100) NULL,
  terms_accepted_at datetime NULL,
  terms_version varchar(20) NULL,
  hold_expires_at datetime NULL,
  ga_sent_at datetime NULL,
  source varchar(20) NOT NULL default 'direct',
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY ref (ref),
  KEY unit_stay (unit_id,arrival,departure),
  KEY guest_id (guest_id),
  KEY user_id (user_id),
  KEY status (status),
  KEY arrival (arrival),
  KEY departure (departure),
  KEY created_at (created_at),
  KEY email (email),
  KEY hold_expires_at (hold_expires_at),
  KEY balance_due_on (balance_due_on)
) {$collate};",

			"CREATE TABLE {$t( 'mbk_guests' )} (
  id bigint(20) unsigned NOT NULL auto_increment,
  email varchar(190) NOT NULL,
  first_name varchar(100) NOT NULL default '',
  last_name varchar(100) NOT NULL default '',
  phone varchar(40) NOT NULL default '',
  country char(2) NOT NULL default '',
  lang varchar(5) NOT NULL default 'hr',
  user_id bigint(20) unsigned NULL,
  tags varchar(255) NOT NULL default '',
  notes text NULL,
  marketing_consent_at datetime NULL,
  stripe_customer_id varchar(100) NULL,
  bookings_count int(10) unsigned NOT NULL default 0,
  nights_total int(10) unsigned NOT NULL default 0,
  revenue_cents bigint(20) NOT NULL default 0,
  first_booking_at datetime NULL,
  last_stay_at date NULL,
  anonymized_at datetime NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY email (email),
  KEY user_id (user_id),
  KEY last_name (last_name)
) {$collate};",

			"CREATE TABLE {$t( 'mbk_nights' )} (
  id bigint(20) unsigned NOT NULL auto_increment,
  unit_id bigint(20) unsigned NOT NULL,
  night date NOT NULL,
  booking_id bigint(20) unsigned NULL,
  block_id bigint(20) unsigned NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY unit_night (unit_id,night),
  KEY night (night),
  KEY booking_id (booking_id),
  KEY block_id (block_id)
) {$collate};",

			"CREATE TABLE {$t( 'mbk_blocks' )} (
  id bigint(20) unsigned NOT NULL auto_increment,
  unit_id bigint(20) unsigned NOT NULL,
  date_from date NOT NULL,
  date_to date NOT NULL,
  reason varchar(255) NOT NULL default '',
  created_by bigint(20) unsigned NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY unit_dates (unit_id,date_from,date_to)
) {$collate};",

			"CREATE TABLE {$t( 'mbk_external_blocks' )} (
  id bigint(20) unsigned NOT NULL auto_increment,
  feed_id bigint(20) unsigned NOT NULL,
  unit_id bigint(20) unsigned NOT NULL,
  uid varchar(191) NOT NULL default '',
  date_from date NOT NULL,
  date_to date NOT NULL,
  summary varchar(255) NOT NULL default '',
  seen_at datetime NULL,
  first_seen_at datetime NULL,
  origin varchar(10) NOT NULL default 'external',
  orphaned_at datetime NULL,
  orphan_fetch_at datetime NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY feed_uid (feed_id,uid),
  KEY unit_dates (unit_id,date_from,date_to)
) {$collate};",

			"CREATE TABLE {$t( 'mbk_ical_feeds' )} (
  id bigint(20) unsigned NOT NULL auto_increment,
  unit_id bigint(20) unsigned NOT NULL,
  channel varchar(20) NOT NULL default '',
  url text NOT NULL,
  last_synced_at datetime NULL,
  last_status varchar(255) NULL,
  etag varchar(255) NULL,
  last_modified varchar(100) NULL,
  active tinyint(1) unsigned NOT NULL default 1,
  last_ok_at datetime NULL,
  last_error varchar(255) NULL,
  fail_since datetime NULL,
  events_count int(10) unsigned NOT NULL default 0,
  created_at datetime NULL,
  PRIMARY KEY  (id),
  KEY unit_id (unit_id)
) {$collate};",

			"CREATE TABLE {$t( 'mbk_prices' )} (
  unit_id bigint(20) unsigned NOT NULL,
  min_stay smallint(5) unsigned NOT NULL default 3,
  min_guests smallint(5) unsigned NOT NULL default 1,
  m1 int(10) unsigned NULL,
  m2 int(10) unsigned NULL,
  m3 int(10) unsigned NULL,
  m4 int(10) unsigned NULL,
  m5 int(10) unsigned NULL,
  m6 int(10) unsigned NULL,
  m7 int(10) unsigned NULL,
  m8 int(10) unsigned NULL,
  m9 int(10) unsigned NULL,
  m10 int(10) unsigned NULL,
  m11 int(10) unsigned NULL,
  m12 int(10) unsigned NULL,
  PRIMARY KEY  (unit_id)
) {$collate};",

			"CREATE TABLE {$t( 'mbk_coupons' )} (
  id bigint(20) unsigned NOT NULL auto_increment,
  code varchar(50) NOT NULL,
  type varchar(10) NOT NULL default 'pct',
  amount int(10) unsigned NOT NULL default 0,
  min_nights smallint(5) unsigned NOT NULL default 0,
  book_from date NULL,
  book_to date NULL,
  stay_from date NULL,
  stay_to date NULL,
  unit_ids text NULL,
  usage_limit int(10) unsigned NULL,
  per_guest_limit int(10) unsigned NULL,
  used_count int(10) unsigned NOT NULL default 0,
  guest_id bigint(20) unsigned NULL,
  active tinyint(1) unsigned NOT NULL default 1,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY code (code),
  KEY guest_id (guest_id)
) {$collate};",

			"CREATE TABLE {$t( 'mbk_coupon_redemptions' )} (
  id bigint(20) unsigned NOT NULL auto_increment,
  coupon_id bigint(20) unsigned NOT NULL,
  booking_id bigint(20) unsigned NOT NULL,
  guest_id bigint(20) unsigned NULL,
  discount_cents int(11) NOT NULL default 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY booking_id (booking_id),
  KEY coupon_id (coupon_id),
  KEY guest_id (guest_id)
) {$collate};",

			"CREATE TABLE {$t( 'mbk_email_templates' )} (
  code varchar(50) NOT NULL,
  lang varchar(5) NOT NULL,
  subject varchar(255) NOT NULL default '',
  preheader varchar(255) NOT NULL default '',
  heading varchar(255) NOT NULL default '',
  body_html longtext NULL,
  enabled tinyint(1) unsigned NOT NULL default 1,
  updated_by bigint(20) unsigned NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (code,lang)
) {$collate};",

			"CREATE TABLE {$t( 'mbk_payments' )} (
  id bigint(20) unsigned NOT NULL auto_increment,
  booking_id bigint(20) unsigned NOT NULL,
  kind varchar(20) NOT NULL,
  method varchar(20) NOT NULL default 'stripe',
  amount_cents int(11) NOT NULL default 0,
  status varchar(20) NOT NULL default 'pending',
  stripe_intent_id varchar(100) NULL,
  stripe_charge_id varchar(100) NULL,
  stripe_refund_id varchar(100) NULL,
  card_brand varchar(20) NOT NULL default '',
  card_last4 char(4) NOT NULL default '',
  attempts smallint(5) unsigned NOT NULL default 0,
  error_code varchar(100) NULL,
  note text NULL,
  idempotency_key varchar(64) NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY stripe_refund_id (stripe_refund_id),
  UNIQUE KEY idempotency_key (idempotency_key),
  KEY created_at (created_at),
  KEY stripe_intent_id (stripe_intent_id),
  KEY booking_id (booking_id),
  KEY status (status)
) {$collate};",

			"CREATE TABLE {$t( 'mbk_events' )} (
  id bigint(20) unsigned NOT NULL auto_increment,
  booking_id bigint(20) unsigned NULL,
  guest_id bigint(20) unsigned NULL,
  type varchar(50) NOT NULL,
  actor varchar(100) NOT NULL default '',
  payload_json longtext NULL,
  external_id varchar(100) NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY external_id (external_id),
  KEY booking_id (booking_id),
  KEY guest_id (guest_id),
  KEY type (type),
  KEY created_at (created_at)
) {$collate};",

			"CREATE TABLE {$t( 'mbk_booking_tokens' )} (
  id bigint(20) unsigned NOT NULL auto_increment,
  booking_id bigint(20) unsigned NOT NULL,
  purpose varchar(20) NOT NULL default 'access',
  token_hash char(64) NOT NULL,
  expires_at datetime NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY token_hash (token_hash),
  KEY booking_id (booking_id)
) {$collate};",

			"CREATE TABLE {$t( 'mbk_ical_exports' )} (
  unit_id bigint(20) unsigned NOT NULL,
  secret varchar(64) NOT NULL,
  rotated_at datetime NOT NULL,
  PRIMARY KEY  (unit_id)
) {$collate};",

			"CREATE TABLE {$t( 'mbk_ical_export_fetches' )} (
  unit_id bigint(20) unsigned NOT NULL,
  channel varchar(20) NOT NULL,
  last_fetched_at datetime NOT NULL,
  PRIMARY KEY  (unit_id,channel)
) {$collate};",
		);
	}
}
