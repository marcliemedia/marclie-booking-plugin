<?php
/**
 * A feed that cannot be fetched, read or changed. `reason`:
 * invalid_url, insecure_redirect, private_address, dns, timeout, network
 * (`detail` = transport error), http_status (`httpStatus`), too_large,
 * too_many_events, not_ical, import_failed, fatal, busy (another sync holds
 * the feed), legacy (text stored before v8).
 *
 * Feeds store the reason as JSON (toStored()) and translate it when it is
 * read (describe()), so the admin sees it in their own language whoever
 * triggered the sync.
 */

namespace Marclie\Booking\Ical;

defined( 'ABSPATH' ) || exit;

final class IcalException extends \RuntimeException {

	public function __construct( public readonly string $reason, public readonly string $detail = '', public readonly int $httpStatus = 0 ) {
		parent::__construct( '' !== $detail ? $reason . ': ' . $detail : $reason );
	}

	/** JSON for mbk_ical_feeds.last_error (fits varchar(255)). */
	public function toStored(): string {
		$detail = function_exists( 'mb_strcut' ) ? mb_strcut( $this->detail, 0, 150, 'UTF-8' ) : substr( $this->detail, 0, 150 );

		return (string) wp_json_encode(
			array_filter(
				array(
					'reason' => $this->reason,
					'detail' => $detail,
					'status' => $this->httpStatus,
				)
			)
		);
	}

	/** The stored error of a feed, or null; plain text (before v8) becomes `legacy`. */
	public static function fromStored( ?string $stored ): ?self {
		if ( null === $stored || '' === $stored ) {
			return null;
		}
		$data = json_decode( $stored, true );
		if ( ! is_array( $data ) || empty( $data['reason'] ) ) {
			return new self( 'legacy', $stored );
		}

		return new self( (string) $data['reason'], (string) ( $data['detail'] ?? '' ), (int) ( $data['status'] ?? 0 ) );
	}

	/** Short operator-facing reason in the current language. */
	public function describe(): string {
		switch ( $this->reason ) {
			case 'invalid_url':
				return __( 'Enter a valid https:// calendar address.', 'marclie-booking' );
			case 'insecure_redirect':
				return __( 'The calendar address redirects to an insecure (http://) or invalid address.', 'marclie-booking' );
			case 'private_address':
				return __( 'The calendar address points to a private or local network address.', 'marclie-booking' );
			case 'dns':
				return __( 'The calendar host name could not be resolved.', 'marclie-booking' );
			case 'timeout':
				return __( 'The calendar did not answer in time.', 'marclie-booking' );
			case 'http_status':
				/* translators: %d: HTTP status code */
				return sprintf( __( 'The calendar address answered with HTTP %d.', 'marclie-booking' ), $this->httpStatus );
			case 'too_large':
				return __( 'The calendar file is too large.', 'marclie-booking' );
			case 'too_many_events':
				/* translators: %d: maximum number of events */
				return sprintf( __( 'The calendar has more than %d events.', 'marclie-booking' ), Importer::MAX_EVENTS );
			case 'not_ical':
				return __( 'The address did not return an iCal (.ics) calendar.', 'marclie-booking' );
			case 'import_failed':
				return __( 'The calendar could not be imported.', 'marclie-booking' );
			case 'fatal':
				return __( 'The import stopped unexpectedly (server error).', 'marclie-booking' );
			case 'busy':
				return __( 'This calendar is being synced right now. Try again in a moment.', 'marclie-booking' );
			case 'legacy':
				return $this->detail;
			default:
				/* translators: %s: network error message */
				return sprintf( __( 'The calendar could not be downloaded (%s).', 'marclie-booking' ), '' !== $this->detail ? $this->detail : $this->reason );
		}
	}
}
