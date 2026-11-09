<?php
/**
 * Guests (CRM, "Gosti"): upsert by email, statistics, merge, GDPR export/anonymize.
 */

namespace Marclie\Booking\Guests;

use Marclie\Booking\Account\AccountService;
use Marclie\Booking\Domain\Exception\GuestHasActiveBookingsException;
use Marclie\Booking\Domain\Exception\NotFoundException;
use Marclie\Booking\Domain\Exception\ValidationException;
use Marclie\Booking\Repository\BookingRepository;
use Marclie\Booking\Repository\CouponRedemptionRepository;
use Marclie\Booking\Repository\CouponRepository;
use Marclie\Booking\Repository\Db;
use Marclie\Booking\Repository\EventRepository;
use Marclie\Booking\Repository\GuestRepository;
use Marclie\Booking\Repository\LockConflictException;
use Marclie\Booking\Repository\PaymentRepository;

defined( 'ABSPATH' ) || exit;

final class GuestService {

	/** Contact fields copied from booking data onto the guest. */
	private const CONTACT_FIELDS = array( 'first_name', 'last_name', 'phone', 'country' );

	/** Event payload keys blanked on anonymize (at any depth). */
	private const PII_PAYLOAD_KEYS = array( 'email', 'from_email', 'to_email', 'recipient', 'first_name', 'last_name', 'name', 'phone', 'message', 'arrival_time', 'address', 'reason', 'note' );

	/** Extra PII keys per event type: recipient address, subject (guest name) and mailer error (may quote the address) of emails. */
	private const PII_PAYLOAD_KEYS_BY_TYPE = array(
		'email_sent'   => array( 'to', 'subject' ),
		'email_failed' => array( 'to', 'subject', 'error' ),
	);

	/** Column lengths of editable profile fields (mbk_guests). */
	private const MAX_LENGTH = array(
		'first_name' => 100,
		'last_name'  => 100,
		'phone'      => 40,
	);

	public function __construct(
		private GuestRepository $guests,
		private BookingRepository $bookings,
		private EventRepository $events,
		private CouponRedemptionRepository $redemptions,
		private CouponRepository $coupons,
		private PaymentRepository $payments
	) {}

	/** @return array<string, mixed>|null */
	public function find( int $id ): ?array {
		return $this->guests->find( $id );
	}

	/** @return array<string, mixed>|null */
	public function findByEmail( string $email ): ?array {
		return $this->guests->findByEmail( $email );
	}

	/**
	 * Finds the guest by email (lowercased, trimmed) or creates one. Non-empty
	 * contact fields overwrite stored values (latest booking wins); with
	 * `$fillOnly` (unverified online checkouts: anyone can type an email) they
	 * only fill empty fields of an existing guest.
	 *
	 * @param array{email:string, first_name?:string, last_name?:string, phone?:string, country?:string, marketing_consent?:bool, user_id?:int|null} $data
	 * @return int Guest id.
	 */
	public function upsertFromBooking( array $data, string $lang, bool $fillOnly = false ): int {
		$email = GuestRepository::normalizeEmail( (string) ( $data['email'] ?? '' ) );
		if ( '' === $email || ! is_email( $email ) ) {
			throw new \InvalidArgumentException( 'A valid guest email is required.' );
		}

		$fields = array( 'lang' => $lang );
		foreach ( self::CONTACT_FIELDS as $field ) {
			$value = trim( (string) ( $data[ $field ] ?? '' ) );
			if ( '' !== $value ) {
				$fields[ $field ] = 'country' === $field ? strtoupper( substr( $value, 0, 2 ) ) : $value;
			}
		}
		// A guest is linked only to the account that verified this very address.
		if ( ! empty( $data['user_id'] ) && AccountService::verifiedEmailOf( (int) $data['user_id'] ) === $email ) {
			$fields['user_id'] = (int) $data['user_id'];
		}
		$consent = ! empty( $data['marketing_consent'] );

		$existing = $this->guests->findByEmail( $email );
		if ( null === $existing ) {
			$id = $this->guests->insert(
				array_merge(
					$fields,
					array(
						'email'                => $email,
						'marketing_consent_at' => $consent ? Db::nowUtc() : null,
					)
				)
			);
			if ( $id > 0 ) {
				return $id;
			}

			if ( Db::ERROR_DUPLICATE !== Db::errorKind() ) {
				throw new \RuntimeException( Db::message( 'Creating guest failed' ) );
			}

			// Lost a race against a concurrent insert of the same email. Inside a
			// transaction the snapshot may not show that row: let the caller retry.
			$existing = $this->guests->findByEmail( $email );
			if ( null === $existing ) {
				throw new LockConflictException( 'Concurrent guest insert for the same email.' );
			}
		}

		if ( $consent && null === $existing['marketing_consent_at'] ) {
			$fields['marketing_consent_at'] = Db::nowUtc();
		}
		if ( $fillOnly ) {
			$fields = array_filter(
				$fields,
				static fn( $value, string $field ): bool => '' === (string) ( $existing[ $field ] ?? '' ),
				ARRAY_FILTER_USE_BOTH
			);
			if ( ! $fields ) {
				return (int) $existing['id'];
			}
		}
		Db::check( $this->guests->update( (int) $existing['id'], $fields ), 'Updating guest' );

		return (int) $existing['id'];
	}

	/**
	 * Admin edit of the CRM profile (email is the identity and stays unchanged).
	 *
	 * @param array{first_name?:string, last_name?:string, phone?:string|null, country?:string|null, lang?:string, tags?:list<string>, notes?:string|null, marketing_consent?:bool} $patch
	 * @return array<string, mixed> The updated guest row.
	 * @throws NotFoundException|ValidationException
	 */
	public function updateProfile( int $id, array $patch, string $actor ): array {
		$guest = $this->guests->find( $id );
		if ( null === $guest ) {
			throw new NotFoundException( 'Guest not found.', array( 'guest_id' => $id ) );
		}
		if ( null !== $guest['anonymized_at'] ) {
			throw ValidationException::fields( array( 'id' => __( 'Anonymized guests cannot be edited.', 'marclie-booking' ) ) );
		}

		$row    = array();
		$errors = array();
		foreach ( self::MAX_LENGTH as $field => $max ) {
			if ( array_key_exists( $field, $patch ) ) {
				$row[ $field ] = trim( (string) $patch[ $field ] );
				if ( mb_strlen( $row[ $field ] ) > $max ) {
					/* translators: %d: maximum number of characters */
					$errors[ $field ] = sprintf( __( 'Use at most %d characters.', 'marclie-booking' ), $max );
				}
			}
		}
		if ( array_key_exists( 'country', $patch ) ) {
			$country = strtoupper( trim( (string) $patch['country'] ) );
			if ( '' !== $country && ! preg_match( '/^[A-Z]{2}$/', $country ) ) {
				$errors['country'] = __( 'Use a two-letter country code.', 'marclie-booking' );
			}
			$row['country'] = $country;
		}
		if ( array_key_exists( 'lang', $patch ) ) {
			if ( ! in_array( $patch['lang'], array( 'hr', 'en' ), true ) ) {
				$errors['lang'] = __( 'Choose Croatian or English.', 'marclie-booking' );
			}
			$row['lang'] = (string) $patch['lang'];
		}
		if ( array_key_exists( 'tags', $patch ) ) {
			$tags        = array_map( static fn( $t ): string => trim( str_replace( ',', ' ', (string) $t ) ), (array) $patch['tags'] );
			$row['tags'] = implode( ',', array_values( array_unique( array_filter( $tags, static fn( string $t ): bool => '' !== $t ) ) ) );
			if ( strlen( $row['tags'] ) > 255 ) {
				$errors['tags'] = __( 'Too many tags.', 'marclie-booking' );
			}
		}
		if ( array_key_exists( 'notes', $patch ) ) {
			$notes        = trim( (string) $patch['notes'] );
			$row['notes'] = '' !== $notes ? $notes : null;
		}
		if ( array_key_exists( 'marketing_consent', $patch ) ) {
			$consent                     = (bool) $patch['marketing_consent'];
			$row['marketing_consent_at'] = $consent ? ( $guest['marketing_consent_at'] ?? Db::nowUtc() ) : null;
		}
		if ( $errors ) {
			throw ValidationException::fields( $errors );
		}
		if ( ! $row ) {
			return $guest;
		}

		Db::transaction(
			function () use ( $id, $row, $actor ): void {
				Db::check( $this->guests->update( $id, $row ) ?: false, 'Updating guest' );
				Db::check( $this->events->log( 'guest_updated', $actor, null, $id, array( 'fields' => array_keys( $row ) ) ) ?: false, 'Logging guest update' );
			}
		);

		return (array) $this->guests->find( $id );
	}

	/**
	 * `mbk_booking_status_changed` listener: refreshes the guest's statistics.
	 *
	 * @param array<string, mixed> $booking
	 * @param mixed                $from
	 * @param mixed                $to
	 */
	public function onStatusChanged( array $booking, $from = null, $to = null ): void {
		if ( ! empty( $booking['guest_id'] ) ) {
			$this->guests->recomputeStats( (int) $booking['guest_id'] );
		}
	}

	public function recomputeStats( int $guestId ): void {
		$this->guests->recomputeStats( $guestId );
	}

	/**
	 * Moves bookings, redemptions, events and personal coupons of `$fromId` onto
	 * `$toId`, merges tags/notes, deletes `$fromId` and recomputes statistics.
	 */
	public function merge( int $fromId, int $toId, string $actor = 'system' ): void {
		if ( $fromId === $toId ) {
			throw new \InvalidArgumentException( 'Cannot merge a guest into itself.' );
		}

		$from = $this->guests->find( $fromId );
		$to   = $this->guests->find( $toId );
		if ( null === $from || null === $to ) {
			throw new \InvalidArgumentException( 'Guest not found.' );
		}

		Db::transaction(
			function () use ( $from, $to, $fromId, $toId, $actor ): void {
				Db::check( $this->bookings->reassignGuest( $fromId, $toId ), 'Moving bookings' );
				Db::check( $this->redemptions->reassignGuest( $fromId, $toId ), 'Moving coupon redemptions' );
				Db::check( $this->events->reassignGuest( $fromId, $toId ), 'Moving events' );
				Db::check( $this->coupons->reassignGuest( $fromId, $toId ), 'Moving personal coupons' );

				$tags  = array_unique( array_filter( array_map( 'trim', explode( ',', $to['tags'] . ',' . $from['tags'] ) ) ) );
				$notes = trim( implode( "\n\n", array_filter( array( (string) $to['notes'], (string) $from['notes'] ) ) ) );

				$patch = array(
					'tags'  => implode( ',', $tags ),
					'notes' => '' !== $notes ? $notes : null,
				);
				foreach ( array_merge( self::CONTACT_FIELDS, array( 'user_id', 'stripe_customer_id', 'marketing_consent_at' ) ) as $field ) {
					if ( empty( $to[ $field ] ) && ! empty( $from[ $field ] ) ) {
						$patch[ $field ] = $from[ $field ];
					}
				}
				if ( $from['created_at'] < $to['created_at'] ) {
					$patch['created_at'] = $from['created_at'];
				}

				Db::check( $this->guests->delete( $fromId ), 'Deleting merged guest' );
				Db::check( $this->guests->update( $toId, $patch ), 'Updating guest' );
				Db::check( $this->guests->recomputeStats( $toId ), 'Recomputing guest statistics' );
				Db::check(
					$this->events->log(
						'guest_merged',
						$actor,
						null,
						$toId,
						array(
							'from_guest_id'   => $fromId,
							'from_email_hash' => hash( 'sha256', (string) $from['email'] ),
						)
					) ?: false,
					'Logging guest merge'
				);
			}
		);
	}

	/**
	 * GDPR export: guest record, bookings (without token hashes) and events, as JSON.
	 */
	public function export( int $id ): string {
		$guest = $this->guests->find( $id );
		if ( null === $guest ) {
			throw new \InvalidArgumentException( 'Guest not found.' );
		}

		$bookings = array_map(
			static function ( array $booking ): array {
				unset( $booking['access_token_hash'], $booking['internal_notes'] );
				$booking['quote']  = null === $booking['quote_json'] ? null : json_decode( (string) $booking['quote_json'], true );
				$booking['policy'] = null === $booking['policy_json'] ? null : json_decode( (string) $booking['policy_json'], true );
				unset( $booking['quote_json'], $booking['policy_json'] );

				return $booking;
			},
			$this->bookings->forGuest( $id )
		);

		$events = array_map(
			static fn( array $event ): array => array(
				'type'       => $event['type'],
				'booking_id' => $event['booking_id'],
				'created_at' => $event['created_at'],
				'payload'    => $event['payload'],
			),
			$this->events->forGuest( $id )
		);

		unset( $guest['notes'] );

		return (string) wp_json_encode(
			array(
				'exported_at' => gmdate( 'c' ),
				'guest'       => $guest,
				'bookings'    => $bookings,
				'events'      => $events,
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);
	}

	/**
	 * Removes PII from the guest, their bookings, payment notes and event payloads;
	 * amounts, dates and statistics stay. Refused while the guest has upcoming
	 * active bookings unless `$force` (their contact data would be lost).
	 *
	 * @throws GuestHasActiveBookingsException
	 */
	public function anonymize( int $id, string $actor = 'system', bool $force = false ): void {
		if ( null === $this->guests->find( $id ) ) {
			throw new \InvalidArgumentException( 'Guest not found.' );
		}

		$upcoming = $this->bookings->search(
			array(
				'guest_id' => $id,
				'status'   => 'upcoming',
				'today'    => wp_date( 'Y-m-d' ),
			),
			1,
			1
		)['total'];
		if ( $upcoming > 0 && ! $force ) {
			throw new GuestHasActiveBookingsException( 'The guest has upcoming bookings.', array( 'bookings' => $upcoming ) );
		}

		Db::transaction(
			function () use ( $id, $actor ): void {
				$bookingIds = $this->bookings->idsForGuest( $id );

				Db::check(
					$this->guests->update(
						$id,
						array(
							'email'                => sprintf( 'anonymized-%d@invalid.invalid', $id ),
							'first_name'           => '',
							'last_name'            => '',
							'phone'                => '',
							'country'              => '',
							'tags'                 => '',
							'notes'                => null,
							'user_id'              => null,
							'marketing_consent_at' => null,
							'stripe_customer_id'   => null,
							'anonymized_at'        => Db::nowUtc(),
						)
					),
					'Anonymizing guest'
				);
				Db::check( $this->bookings->anonymizeForGuest( $id ), 'Anonymizing bookings' );
				Db::check( $this->payments->clearNotes( $bookingIds ), 'Clearing payment notes' );
				Db::check( $this->events->scrubPayloads( $id, $bookingIds, self::PII_PAYLOAD_KEYS, self::PII_PAYLOAD_KEYS_BY_TYPE ), 'Scrubbing event payloads' );
				Db::check( $this->events->log( 'guest_anonymized', $actor, null, $id ) ?: false, 'Logging anonymization' );
			}
		);
	}
}
