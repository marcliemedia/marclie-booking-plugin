<?php
/**
 * mbk_booking_tokens: guest link tokens per booking. Only the SHA-256 hash of
 * a token is stored. Purposes: `access` (confirmation page, created with the
 * booking, no expiry), `manage` (R2), `pay` (balance payment link, expires).
 */

namespace Marclie\Booking\Repository;

defined( 'ABSPATH' ) || exit;

final class TokenRepository {

	public const ACCESS = 'access';
	public const MANAGE = 'manage';
	public const PAY    = 'pay';

	public const PURPOSES = array( self::ACCESS, self::MANAGE, self::PAY );

	private function table(): string {
		return Db::table( 'mbk_booking_tokens' );
	}

	public function insert( int $bookingId, string $purpose, string $tokenHash, ?string $expiresUtc ): int {
		global $wpdb;

		$ok = $wpdb->insert(
			$this->table(),
			array(
				'booking_id' => $bookingId,
				'purpose'    => $purpose,
				'token_hash' => $tokenHash,
				'expires_at' => $expiresUtc,
				'created_at' => Db::nowUtc(),
			)
		);

		return false === $ok ? 0 : (int) $wpdb->insert_id;
	}

	/**
	 * @return list<array{id:int, booking_id:int, purpose:string, token_hash:string, expires_at:?string, created_at:string}>
	 */
	public function forBooking( int $bookingId ): array {
		global $wpdb;

		$rows = (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$this->table()} WHERE booking_id = %d ORDER BY id ASC", $bookingId ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return array_map(
			static function ( array $row ): array {
				$row['id']         = (int) $row['id'];
				$row['booking_id'] = (int) $row['booking_id'];

				return $row;
			},
			$rows
		);
	}

	/** Deletes `$purpose` tokens that expired before `$nowUtc` (short-lived account manage links); false on error. */
	public function deleteExpired( string $purpose, string $nowUtc ): bool {
		global $wpdb;

		return false !== $wpdb->query( $wpdb->prepare( "DELETE FROM {$this->table()} WHERE purpose = %s AND expires_at IS NOT NULL AND expires_at < %s", $purpose, $nowUtc ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Deletes every `$purpose` token of the bookings (account deleted); false on error.
	 *
	 * @param list<int> $bookingIds
	 */
	public function deleteForBookings( array $bookingIds, string $purpose ): bool {
		global $wpdb;

		if ( ! $bookingIds ) {
			return true;
		}
		$args = array_merge( array( $purpose ), array_map( 'intval', $bookingIds ) );

		return false !== $wpdb->query( $wpdb->prepare( "DELETE FROM {$this->table()} WHERE purpose = %s AND booking_id IN (" . Db::placeholders( $bookingIds ) . ')', $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}
}
