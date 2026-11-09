<?php
/**
 * Units read from the `property` posts (ACF post type „Apartmani”, ACF fields), always keyed by the HR original.
 */

namespace Marclie\Booking\Units;

use Marclie\Booking\Container;

defined( 'ABSPATH' ) || exit;

final class PropertyUnitProvider implements UnitProvider {

	public const POST_TYPE = 'property';

	/** @var array<int, Unit|null> */
	private array $cache = array();

	/** @var list<Unit>|null Result of all(), in menu order. */
	private ?array $ordered = null;

	public function get( int $id ): ?Unit {
		$id = $this->original( $id );
		if ( ! array_key_exists( $id, $this->cache ) ) {
			$post               = get_post( $id );
			$this->cache[ $id ] = ( $post instanceof \WP_Post && self::POST_TYPE === $post->post_type && 'publish' === $post->post_status )
				? $this->map( $post )
				: null;
		}
		return $this->cache[ $id ];
	}

	public function byCode( string $code ): ?Unit {
		foreach ( $this->all() as $unit ) {
			if ( 0 === strcasecmp( $unit->code, $code ) ) {
				return $unit;
			}
		}
		return null;
	}

	public function all(): array {
		if ( null === $this->ordered ) {
			// One query for full posts; meta + term caches are primed in bulk by WP_Query.
			$posts = get_posts(
				array(
					'post_type'              => self::POST_TYPE,
					'post_status'            => 'publish',
					'numberposts'            => -1,
					'orderby'                => array( 'menu_order' => 'ASC', 'ID' => 'ASC' ),
					'suppress_filters'       => false,
					'update_post_meta_cache' => true,
					'update_post_term_cache' => true,
				)
			);
			$units = array();
			foreach ( $posts as $post ) {
				$id = $this->original( $post->ID );
				if ( $id === $post->ID ) {
					$this->cache[ $id ] ??= $this->map( $post );
				}
				$unit = $id === $post->ID ? $this->cache[ $id ] : $this->get( $id );
				if ( null !== $unit && ! isset( $units[ $unit->id ] ) ) {
					$units[ $unit->id ] = $unit;
				}
			}
			$this->ordered = array_values( $units );
		}
		return $this->ordered;
	}

	/** Maps a WPML translation to the HR original post ID. */
	private function original( int $id ): int {
		if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
			$hr = apply_filters( 'wpml_object_id', $id, self::POST_TYPE, true, 'hr' );
			if ( $hr ) {
				return (int) $hr;
			}
		}
		return $id;
	}

	private function map( \WP_Post $post ): Unit {
		$id     = $post->ID;
		$terms  = get_the_terms( $id, 'kuca' );
		$policy = Container::policy();
		return new Unit(
			$id,
			(string) $this->field( $id, 'unit_code', '' ),
			get_the_title( $post ),
			max( 1, (int) apply_filters( 'mbk_unit_capacity', (int) $this->field( $id, 'max_guests', 1 ), $id ) ),
			max( 0, (int) $this->field( $id, 'max_children', 0 ) ),
			// Empty unit times = the booking settings (policy); a unit only stores its exceptions.
			self::time( $this->field( $id, 'checkin_from', '' ), $policy->checkinFrom ),
			self::time( $this->field( $id, 'checkin_to', '' ), $policy->checkinTo ),
			self::time( $this->field( $id, 'checkout_until', '' ), $policy->checkoutUntil ),
			is_array( $terms ) && $terms ? $terms[0]->slug : null,
			(string) get_permalink( $post ),
			(int) get_post_thumbnail_id( $post )
		);
	}

	/** ACF value when ACF is loaded, raw post meta otherwise. */
	private function field( int $id, string $name, mixed $fallback ): mixed {
		$value = function_exists( 'get_field' ) ? get_field( $name, $id, false ) : get_post_meta( $id, $name, true );
		return ( null === $value || '' === $value || false === $value ) ? $fallback : $value;
	}

	private static function time( mixed $value, string $fallback ): string {
		return is_string( $value ) && preg_match( '/^(\d{1,2}):(\d{2})/', $value, $m )
			? sprintf( '%02d:%s', (int) $m[1], $m[2] )
			: $fallback;
	}
}
