<?php
/**
 * Booking-flow pages (`studio wp mbk pages [--create]`): Rezervacija with its
 * children Hvala and Plaćanje, Pretraga and Uvjeti rezervacije; guest account
 * pages Prijava, Registracija and Moj račun with its child Zaboravljena
 * lozinka (R2). Creation is
 * idempotent: a mapped published page is kept, an existing page at the path
 * is adopted (and published), otherwise the page is created. A kept/adopted page
 * whose content is still empty gets the default shortcode content ("filled");
 * edited content is never overwritten. IDs are stored
 * in the Settings page map. URLs are always Croatian (K9).
 */

namespace Marclie\Booking\Cli;

use Marclie\Booking\Settings;

defined( 'ABSPATH' ) || exit;

final class Pages {

	private const EMPTY_CONTENT = "<!-- wp:paragraph -->\n<p></p>\n<!-- /wp:paragraph -->";

	/**
	 * Page key => definition, parents before children.
	 *
	 * @return array<string, array{title:string, slug:string, parent:?string, content:string}>
	 */
	public static function definitions(): array {
		return array(
			'rezervacija' => array(
				'title'   => 'Rezervacija',
				'slug'    => 'rezervacija',
				'parent'  => null,
				'content' => self::shortcode( 'mbk_checkout' ),
			),
			'hvala'       => array(
				'title'   => 'Hvala',
				'slug'    => 'hvala',
				'parent'  => 'rezervacija',
				'content' => self::shortcode( 'mbk_confirmation' ),
			),
			'placanje'    => array(
				'title'   => 'Plaćanje',
				'slug'    => 'placanje',
				'parent'  => 'rezervacija',
				'content' => self::shortcode( 'mbk_pay_balance' ),
			),
			'pretraga'    => array(
				'title'   => 'Pretraga',
				'slug'    => 'pretraga',
				'parent'  => null,
				'content' => "<!-- wp:shortcode -->\n[mbk_search_form variant=\"compact\"][mbk_search_results]\n<!-- /wp:shortcode -->",
			),
			'uvjeti'               => array(
				'title'   => 'Uvjeti rezervacije',
				'slug'    => 'uvjeti-rezervacije',
				'parent'  => null,
				'content' => self::EMPTY_CONTENT,
			),
			'prijava'              => array(
				'title'   => 'Prijava',
				'slug'    => 'prijava',
				'parent'  => null,
				'content' => self::shortcode( 'mbk_login' ),
			),
			'registracija'         => array(
				'title'   => 'Registracija',
				'slug'    => 'registracija',
				'parent'  => null,
				'content' => self::shortcode( 'mbk_register' ),
			),
			'moj_racun'            => array(
				'title'   => 'Moj račun',
				'slug'    => 'moj-racun',
				'parent'  => null,
				'content' => self::shortcode( 'mbk_account' ),
			),
			'zaboravljena_lozinka' => array(
				'title'   => 'Zaboravljena lozinka',
				'slug'    => 'zaboravljena-lozinka',
				'parent'  => 'moj_racun',
				'content' => self::shortcode( 'mbk_forgot_password' ),
			),
		);
	}

	/**
	 * Status of every page; with `$create` missing pages are created/adopted first.
	 *
	 * @return list<array{key:string, id:int, path:string, status:string, action:string, url:string}>
	 */
	public function run( bool $create ): array {
		$map  = Settings::pages();
		$rows = array();
		$ids  = array();

		foreach ( self::definitions() as $key => $def ) {
			$path     = self::path( $key );
			$parentId = null === $def['parent'] ? 0 : (int) ( $ids[ $def['parent'] ] ?? 0 );
			$action   = 'none';
			$id       = self::isPage( $map[ $key ] ?? 0 ) ? (int) $map[ $key ] : 0;

			if ( 0 === $id ) {
				$found = get_page_by_path( $path, OBJECT, 'page' );
				$id    = $found instanceof \WP_Post && 'trash' !== $found->post_status ? $found->ID : 0;
				if ( $id ) {
					$action = 'adopted';
				}
			}

			if ( $create ) {
				if ( 0 === $id && ( null === $def['parent'] || $parentId ) ) {
					$id     = $this->insert( $def, $parentId );
					$action = 'created';
				} elseif ( $id && 'publish' !== get_post_status( $id ) ) {
					wp_update_post(
						array(
							'ID'          => $id,
							'post_status' => 'publish',
						)
					);
					$action = 'published';
				}
				if ( $id && self::fill( $id, $def['content'] ) ) {
					$action = 'none' === $action ? 'filled' : $action . ', filled';
				}
			}

			$ids[ $key ] = $id;
			$rows[]      = array(
				'key'    => $key,
				'id'     => $id,
				'path'   => '/' . $path . '/',
				'status' => $id ? (string) get_post_status( $id ) : 'missing',
				'action' => $create || 'none' === $action ? $action : 'would adopt',
				'url'    => $id ? (string) get_permalink( $id ) : '',
			);
		}

		if ( $create ) {
			$patch = array();
			foreach ( $ids as $key => $id ) {
				if ( $id && (int) ( $map[ $key ] ?? 0 ) !== $id ) {
					$patch[ $key ] = $id;
				}
			}
			if ( $patch ) {
				Settings::update( array( 'pages' => $patch ) );
			}
		}

		return $rows;
	}

	private static function path( string $key ): string {
		$def = self::definitions()[ $key ];

		return null === $def['parent'] ? $def['slug'] : self::path( $def['parent'] ) . '/' . $def['slug'];
	}

	private static function isPage( int $id ): bool {
		return $id > 0 && 'page' === get_post_type( $id ) && 'trash' !== get_post_status( $id );
	}

	private static function shortcode( string $tag ): string {
		return "<!-- wp:shortcode -->\n[" . $tag . "]\n<!-- /wp:shortcode -->";
	}

	/** Writes the default content into a page whose content is empty; true when it did. */
	private static function fill( int $id, string $content ): bool {
		$current = (string) get_post_field( 'post_content', $id );
		if ( self::EMPTY_CONTENT === $content || '' !== trim( wp_strip_all_tags( preg_replace( '/<!--.*?-->/s', '', $current ) ) ) ) {
			return false;
		}
		wp_update_post(
			array(
				'ID'           => $id,
				'post_content' => $content,
			)
		);

		return true;
	}

	/** @param array{title:string, slug:string, parent:?string, content:string} $def */
	private function insert( array $def, int $parentId ): int {
		$id = wp_insert_post(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'post_title'     => $def['title'],
				'post_name'      => $def['slug'],
				'post_parent'    => $parentId,
				'post_content'   => $def['content'],
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			\WP_CLI::error( sprintf( 'Creating page "%s" failed: %s', $def['title'], $id->get_error_message() ) );
		}

		return (int) $id;
	}
}
