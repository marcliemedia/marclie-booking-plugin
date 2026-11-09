<?php
/**
 * /admin/guests — CRM list, profile, edit, merge, GDPR export and anonymization.
 */

namespace Marclie\Booking\Rest\Admin;

use Marclie\Booking\Container;
use Marclie\Booking\Domain\Exception\NotFoundException;
use Marclie\Booking\Domain\Exception\ValidationException;
use Marclie\Booking\Email\TemplateRepository;
use Marclie\Booking\Install\Activator;

defined( 'ABSPATH' ) || exit;

final class GuestsController extends BaseController {

	private const ID = '/guests/(?P<id>\d+)';

	public function register(): void {
		$cap = Activator::CAP_BOOKINGS;

		$this->route(
			'/guests',
			$this->endpoint(
				\WP_REST_Server::READABLE,
				array( $this, 'index' ),
				$cap,
				array_merge(
					self::paginationArgs(),
					array(
						'search'      => array( 'type' => 'string' ),
						'tag'         => array( 'type' => 'string' ),
						'country'     => array( 'type' => 'string' ),
						'has_consent' => array( 'type' => 'boolean' ),
						'has_account' => array( 'type' => 'boolean' ),
						'tier'        => array(
							'type' => 'string',
							'enum' => array( 'clan', 'srebrni', 'zlatni' ),
						),
						'orderby'     => array(
							'type'    => 'string',
							'enum'    => array( 'last_stay_at', 'revenue', 'bookings_count', 'name' ),
							'default' => 'last_stay_at',
						),
					)
				)
			)
		);
		$this->route(
			self::ID,
			array(
				$this->endpoint( \WP_REST_Server::READABLE, array( $this, 'show' ), $cap ),
				$this->endpoint(
					'PATCH',
					array( $this, 'update' ),
					$cap,
					array(
						'first_name'        => self::text( 100 ),
						'last_name'         => self::text( 100 ),
						'phone'             => self::text( 40 ),
						'country'           => self::text( 2 ),
						'lang'              => array(
							'type' => 'string',
							'enum' => array( 'hr', 'en' ),
						),
						'tags'              => array(
							'type'  => 'array',
							'items' => array(
								'type'      => 'string',
								'maxLength' => 50,
							),
						),
						'notes'             => self::text( 10000 ),
						'marketing_consent' => array( 'type' => 'boolean' ),
					)
				),
			)
		);
		$this->route( self::ID . '/merge', $this->endpoint( \WP_REST_Server::CREATABLE, array( $this, 'merge' ), $cap, array( 'into_id' => self::idArg() ) ) );
		$this->route( self::ID . '/export', $this->endpoint( \WP_REST_Server::READABLE, array( $this, 'export' ), $cap ) );
		$this->route(
			self::ID . '/anonymize',
			$this->endpoint(
				\WP_REST_Server::CREATABLE,
				array( $this, 'anonymize' ),
				$cap,
				array(
					'confirm' => array(
						'type'     => 'string',
						'required' => true,
					),
					'force'   => array(
						'type'    => 'boolean',
						'default' => false,
					),
				)
			)
		);
		$this->route(
			self::ID . '/email',
			$this->endpoint(
				\WP_REST_Server::CREATABLE,
				array( $this, 'email' ),
				$cap,
				array(
					'subject'   => array(
						'type'      => 'string',
						'maxLength' => 255,
						'required'  => true,
					),
					'body_html' => array(
						'type'      => 'string',
						'maxLength' => 65535,
						'required'  => true,
					),
					'lang'      => array(
						'type' => array( 'string', 'null' ),
						'enum' => array( 'hr', 'en', null ),
					),
				)
			)
		);
	}

	/** @return array<string, mixed> */
	public function index( \WP_REST_Request $request ): array {
		$result = Container::guestRepository()->search(
			array(
				'search'      => (string) $request['search'],
				'tag'         => (string) $request['tag'],
				'country'     => (string) $request['country'],
				'has_consent' => null === $request['has_consent'] ? null : (bool) $request['has_consent'],
				'has_account' => null === $request['has_account'] ? null : (bool) $request['has_account'],
				'tier'        => (string) $request['tier'],
			),
			(int) $request['page'],
			(int) $request['per_page'],
			(string) $request['orderby'],
			(string) $request['order']
		);
		// One user-meta query for the tier of every linked guest on the page.
		$userIds = array_values( array_filter( array_map( 'intval', array_column( $result['items'], 'user_id' ) ) ) );
		if ( $userIds ) {
			update_meta_cache( 'user', $userIds );
		}

		return self::listResponse( array_map( array( Presenter::class, 'guest' ), $result['items'] ), $result['total'], $request );
	}

	/** @return array<string, mixed> */
	public function show( \WP_REST_Request $request ): array {
		$guest = self::mustFind( (int) $request['id'] );
		$id    = (int) $guest['id'];

		return Presenter::guest( $guest ) + array(
			'bookings' => Presenter::bookingSummaries( Container::bookingRepository()->forGuest( $id ) ),
			'payments' => array_map( array( Presenter::class, 'payment' ), Container::paymentRepository()->forGuest( $id ) ),
			'coupons'  => array_map( array( Presenter::class, 'coupon' ), Container::couponRepository()->rowsForGuest( $id ) ),
			'account'  => empty( $guest['user_id'] ) ? null : Presenter::account( (int) $guest['user_id'] ),
		);
	}

	/** @return array<string, mixed> */
	public function update( \WP_REST_Request $request ): array {
		$patch = array();
		foreach ( array( 'first_name', 'last_name', 'phone', 'country', 'lang', 'tags', 'notes', 'marketing_consent' ) as $field ) {
			if ( self::has( $request, $field ) ) {
				$patch[ $field ] = $request[ $field ];
			}
		}

		return Presenter::guest( Container::guests()->updateProfile( (int) $request['id'], $patch, self::actor() ) );
	}

	/** @return array<string, mixed> */
	public function merge( \WP_REST_Request $request ): array {
		$from = self::mustFind( (int) $request['id'] );
		$into = self::mustFind( (int) $request['into_id'] );
		if ( (int) $from['id'] === (int) $into['id'] ) {
			throw ValidationException::fields( array( 'into_id' => __( 'Choose a different guest to merge into.', 'marclie-booking' ) ) );
		}
		if ( null !== $into['anonymized_at'] ) {
			throw ValidationException::fields( array( 'into_id' => __( 'Cannot merge into an anonymized guest.', 'marclie-booking' ) ) );
		}

		Container::guests()->merge( (int) $from['id'], (int) $into['id'], self::actor() );

		return Presenter::guest( self::mustFind( (int) $into['id'] ) );
	}

	public function export( \WP_REST_Request $request ): \WP_REST_Response {
		$guest    = self::mustFind( (int) $request['id'] );
		$response = new \WP_REST_Response( json_decode( Container::guests()->export( (int) $guest['id'] ), true ) );
		$response->header( 'Content-Disposition', sprintf( 'attachment; filename="guest-%d.json"', (int) $guest['id'] ) );

		return $response;
	}

	/** @return array<string, mixed> */
	/**
	 * `force:true` (anonymize despite upcoming bookings) needs mbk_manage_settings.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function anonymize( \WP_REST_Request $request ) {
		if ( 'ANONYMIZE' !== (string) $request['confirm'] ) {
			throw ValidationException::fields( array( 'confirm' => __( 'Type ANONYMIZE to confirm.', 'marclie-booking' ) ) );
		}
		$force = (bool) $request['force'];
		if ( $force && ! current_user_can( Activator::CAP_SETTINGS ) ) {
			return new \WP_Error( 'rest_forbidden', __( 'Only an administrator can anonymize a guest with upcoming bookings.', 'marclie-booking' ), array( 'status' => 403 ) );
		}
		$guest = self::mustFind( (int) $request['id'] );
		Container::guests()->anonymize( (int) $guest['id'], self::actor(), $force );

		return Presenter::guest( self::mustFind( (int) $guest['id'] ) );
	}

	/**
	 * Free email to the guest (template guest_message: shared layout, merge
	 * tags of the guest, logged as `email_sent`). Language: `lang` or the guest's.
	 *
	 * @return array{sent: bool}|\WP_Error
	 */
	public function email( \WP_REST_Request $request ) {
		$guest = self::mustFind( (int) $request['id'] );
		if ( null !== $guest['anonymized_at'] ) {
			throw ValidationException::fields( array( 'id' => __( 'This guest is anonymized.', 'marclie-booking' ) ) );
		}

		$subject = trim( wp_strip_all_tags( (string) $request['subject'] ) );
		$body    = TemplateRepository::sanitizeBody( (string) $request['body_html'] );
		$errors  = array();
		if ( '' === $subject ) {
			$errors['subject'] = __( 'Enter a subject.', 'marclie-booking' );
		}
		if ( '' === trim( wp_strip_all_tags( $body ) ) ) {
			$errors['body_html'] = __( 'Write the email text.', 'marclie-booking' );
		}
		if ( $errors ) {
			throw ValidationException::fields( $errors );
		}

		$sent = Container::mailer()->sendToGuest( $guest, $subject, $body, null !== $request['lang'] ? (string) $request['lang'] : null, self::actor() );

		return $sent ? array( 'sent' => true ) : EmailTemplatesController::sendFailed();
	}

	/** @return array<string, mixed> */
	private static function mustFind( int $id ): array {
		$guest = Container::guestRepository()->find( $id );
		if ( null === $guest ) {
			throw new NotFoundException( 'Guest not found.', array( 'guest_id' => $id ) );
		}

		return $guest;
	}
}
