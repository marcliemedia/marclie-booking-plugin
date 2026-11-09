<?php
/**
 * Plugin Name: MBK E2E mail capture (temporary)
 * Description: Copied to mu-plugins by the Playwright global setup and removed by its teardown.
 * Mail to `@mbk-e2e-pw.test` is never sent: it is appended to wp-content/mbk-e2e-pw-mail.jsonl.
 */

defined( 'ABSPATH' ) || exit;

add_filter(
	'pre_wp_mail',
	static function ( $result, array $atts ) {
		$to = is_array( $atts['to'] ) ? $atts['to'] : explode( ',', (string) $atts['to'] );
		$to = array_map( 'trim', $to );
		$e2e = array_filter( $to, static fn( string $address ): bool => (bool) preg_match( '/@mbk-e2e-pw\.test>?$/i', $address ) );
		if ( ! $e2e ) {
			return $result;
		}
		file_put_contents(
			WP_CONTENT_DIR . '/mbk-e2e-pw-mail.jsonl',
			wp_json_encode(
				array(
					'time'    => gmdate( 'c' ),
					'to'      => array_values( $to ),
					'subject' => (string) $atts['subject'],
					'bytes'   => strlen( (string) $atts['message'] ),
				)
			) . "\n",
			FILE_APPEND | LOCK_EX
		);

		return true;
	},
	1,
	2
);
