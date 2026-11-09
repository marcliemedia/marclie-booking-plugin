<?php
/**
 * /admin/email-templates — editor of the email templates (phase 07):
 * list, detail (registry meta + both languages), save per language (422
 * `missing_required_tags`), on/off per code, preview, test send, reset to
 * the shipped default. All routes need mbk_manage_settings.
 *
 * Booking resends and the free guest email live in BookingsController and
 * GuestsController (mbk_manage_bookings).
 */

namespace Marclie\Booking\Rest\Admin;

use Marclie\Booking\Container;
use Marclie\Booking\Domain\Exception\NotFoundException;
use Marclie\Booking\Domain\Exception\ValidationException;
use Marclie\Booking\Email\EmailRegistry;
use Marclie\Booking\Email\Mailer;
use Marclie\Booking\Email\TemplateRepository;
use Marclie\Booking\Install\Activator;

defined( 'ABSPATH' ) || exit;

final class EmailTemplatesController extends BaseController {

	private const CODE = '/email-templates/(?P<code>[a-z_]+)';

	public function register(): void {
		$cap = Activator::CAP_SETTINGS;

		$this->route( '/email-templates', $this->endpoint( \WP_REST_Server::READABLE, array( $this, 'index' ), $cap ) );
		$this->route(
			self::CODE,
			array(
				$this->endpoint( \WP_REST_Server::READABLE, array( $this, 'show' ), $cap ),
				$this->endpoint(
					'PATCH',
					array( $this, 'toggle' ),
					$cap,
					array(
						'enabled' => array(
							'type'     => 'boolean',
							'required' => true,
						),
					)
				),
			)
		);
		$this->route(
			self::CODE . '/(?P<lang>hr|en)',
			array(
				$this->endpoint( 'PUT', array( $this, 'update' ), $cap, self::fieldArgs( true ) ),
				$this->endpoint( \WP_REST_Server::DELETABLE, array( $this, 'reset' ), $cap ),
			)
		);
		$this->route( self::CODE . '/preview', $this->endpoint( \WP_REST_Server::CREATABLE, array( $this, 'preview' ), $cap, self::previewArgs() ) );
		$this->route(
			self::CODE . '/test',
			$this->endpoint(
				\WP_REST_Server::CREATABLE,
				array( $this, 'test' ),
				$cap,
				self::previewArgs() + array(
					'to' => array(
						'type'     => 'string',
						'format'   => 'email',
						'required' => true,
					),
				)
			)
		);
	}

	/** @return list<array<string, mixed>> */
	public function index(): array {
		$templates  = Container::emailTemplates();
		$customized = $templates->customized();

		return array_map(
			static function ( array $meta ) use ( $templates, $customized ): array {
				$langs = array();
				foreach ( TemplateRepository::LANGS as $lang ) {
					$at             = $customized[ $meta['code'] ][ $lang ] ?? null;
					$langs[ $lang ] = array(
						'customized' => null !== $at,
						'updated_at' => Presenter::iso( $at ),
					);
				}

				return array(
					'code'        => $meta['code'],
					'recipient'   => $meta['recipient'],
					'label'       => $meta['label'],
					'description' => $meta['description'],
					'enabled'     => $templates->isEnabled( $meta['code'] ),
					'langs'       => $langs,
				);
			},
			EmailRegistry::all()
		);
	}

	/** @return array<string, mixed> */
	public function show( \WP_REST_Request $request ): array {
		return self::detail( self::code( $request ) );
	}

	/** @return array<string, mixed>|\WP_Error */
	public function update( \WP_REST_Request $request ) {
		$code   = self::code( $request );
		$lang   = (string) $request['lang'];
		$fields = TemplateRepository::clean(
			array(
				'subject'   => (string) $request['subject'],
				'preheader' => (string) $request['preheader'],
				'heading'   => (string) $request['heading'],
				'body_html' => (string) $request['body_html'],
			)
		);

		$errors = array();
		if ( '' === $fields['subject'] ) {
			$errors['subject'] = __( 'Enter a subject.', 'marclie-booking' );
		}
		if ( '' === trim( wp_strip_all_tags( $fields['body_html'] ) ) ) {
			$errors['body_html'] = __( 'Write the email text.', 'marclie-booking' );
		}
		if ( $errors ) {
			throw ValidationException::fields( $errors );
		}

		$missing = EmailRegistry::missingRequiredTags( $code, $fields );
		if ( $missing ) {
			$list = implode( ', ', array_map( static fn( string $tag ): string => '{' . $tag . '}', $missing ) );

			return new \WP_Error(
				'missing_required_tags',
				/* translators: %s: comma-separated merge tags, e.g. "{booking_ref}" */
				sprintf( __( 'This email must contain %s.', 'marclie-booking' ), $list ),
				array(
					'status'  => 422,
					/* translators: %s: comma-separated merge tags, e.g. "{booking_ref}" */
					'fields'  => array( 'body_html' => sprintf( __( 'Add %s to the subject or the text.', 'marclie-booking' ), $list ) ),
					'missing' => $missing,
				)
			);
		}

		if ( ! Container::emailTemplates()->save( $code, $lang, $fields, self::userId() ) ) {
			throw new \RuntimeException( 'Saving the email template failed.' );
		}
		self::log( $code, $lang, 'saved' );

		return self::detail( $code );
	}

	/** @return array<string, mixed> */
	public function reset( \WP_REST_Request $request ): array {
		$code = self::code( $request );
		$lang = (string) $request['lang'];
		Container::emailTemplates()->reset( $code, $lang );
		self::log( $code, $lang, 'reset' );

		return self::detail( $code );
	}

	/** @return array<string, mixed> */
	public function toggle( \WP_REST_Request $request ): array {
		$code    = self::code( $request );
		$enabled = (bool) $request['enabled'];
		Container::emailTemplates()->setEnabled( $code, $enabled );
		self::log( $code, null, $enabled ? 'enabled' : 'disabled' );

		return self::detail( $code );
	}

	/** @return array{subject: string, html: string, text: string} */
	public function preview( \WP_REST_Request $request ): array {
		$code = self::code( $request );

		return Container::mailer()->preview( $code, (string) $request['lang'], self::draft( $request ), self::booking( $request, $code ) );
	}

	/** @return array{sent: bool}|\WP_Error */
	public function test( \WP_REST_Request $request ) {
		$code    = self::code( $request );
		$to      = strtolower( trim( (string) $request['to'] ) );
		$booking = self::booking( $request, $code );
		// Real booking data (name, dates, amounts, card digits) only goes to the site's own people.
		if ( null !== $booking && false === get_user_by( 'email', $to ) && ! in_array( $to, array_map( 'strtolower', Mailer::adminRecipients() ), true ) ) {
			throw ValidationException::fields( array( 'to' => __( 'With a real booking, send the test only to a user of this site or an admin recipient.', 'marclie-booking' ) ) );
		}
		$sent = Container::mailer()->sendTest( $code, (string) $request['lang'], $to, self::draft( $request ), $booking, self::actor() );

		return $sent ? array( 'sent' => true ) : self::sendFailed();
	}

	/* Helpers ---------------------------------------------------------------- */

	/** 502 `email_failed` (wp_mail refused or the transport failed; logged as `email_failed`). */
	public static function sendFailed(): \WP_Error {
		return new \WP_Error( 'email_failed', __( 'The email could not be sent. Check the mail settings of the site (SMTP).', 'marclie-booking' ), array( 'status' => 502 ) );
	}

	/** @return array<string, mixed> */
	private static function detail( string $code ): array {
		$templates = Container::emailTemplates();
		$out       = (array) EmailRegistry::get( $code );

		$out['enabled'] = $templates->isEnabled( $code );
		foreach ( TemplateRepository::LANGS as $lang ) {
			$t            = $templates->get( $code, $lang );
			$out[ $lang ] = array(
				'subject'    => $t['subject'],
				'preheader'  => $t['preheader'],
				'heading'    => $t['heading'],
				'body_html'  => $t['body_html'],
				'customized' => $t['customized'],
				'updated_at' => Presenter::iso( $t['updated_at'] ),
			);
		}

		return $out;
	}

	private static function code( \WP_REST_Request $request ): string {
		$code = (string) $request['code'];
		if ( ! EmailRegistry::exists( $code ) ) {
			throw new NotFoundException( 'Unknown email template.', array( 'code' => $code ) );
		}

		return $code;
	}

	/** @return array<string, string>|null */
	private static function draft( \WP_REST_Request $request ): ?array {
		$draft = $request['draft'];
		if ( ! is_array( $draft ) ) {
			return null;
		}

		return array_map( 'strval', array_intersect_key( $draft, array_flip( TemplateRepository::FIELDS ) ) ) + array_fill_keys( TemplateRepository::FIELDS, '' );
	}

	/** @return array<string, mixed>|null */
	private static function booking( \WP_REST_Request $request, string $code ): ?array {
		if ( empty( $request['booking_id'] ) ) {
			return null;
		}

		return Container::bookings()->mustFind( (int) $request['booking_id'] );
	}

	private static function log( string $code, ?string $lang, string $action ): void {
		Container::eventRepository()->log(
			'email_template_changed',
			self::actor(),
			null,
			null,
			array_filter(
				array(
					'code'   => $code,
					'lang'   => $lang,
					'action' => $action,
				)
			)
		);
	}

	/** @return array<string, array<string, mixed>> */
	private static function fieldArgs( bool $required ): array {
		$line = array(
			'type'      => 'string',
			'maxLength' => 255,
		);

		return array(
			'subject'   => $line + array( 'required' => $required ),
			'preheader' => $line,
			'heading'   => $line,
			'body_html' => array(
				'type'      => 'string',
				'maxLength' => 65535,
				'required'  => $required,
			),
		);
	}

	/** @return array<string, array<string, mixed>> */
	private static function previewArgs(): array {
		return array(
			'lang'       => array(
				'type'     => 'string',
				'enum'     => TemplateRepository::LANGS,
				'required' => true,
			),
			'draft'      => array(
				'type'       => array( 'object', 'null' ),
				'properties' => self::fieldArgs( false ),
			),
			'booking_id' => array(
				'type'    => array( 'integer', 'null' ),
				'minimum' => 1,
			),
		);
	}
}
