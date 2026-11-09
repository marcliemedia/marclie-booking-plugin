<?php
/**
 * mbk_email_templates: admin-edited overrides only (no row = file default).
 */

namespace Marclie\Booking\Repository;

defined( 'ABSPATH' ) || exit;

final class EmailTemplateRepository {

	private function table(): string {
		return Db::table( 'mbk_email_templates' );
	}

	/** @return array<string, mixed>|null */
	public function find( string $code, string $lang ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE code = %s AND lang = %s", $code, $lang ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $row ? self::hydrate( $row ) : null;
	}

	/** @return list<array<string, mixed>> */
	public function all(): array {
		global $wpdb;

		$rows = (array) $wpdb->get_results( "SELECT * FROM {$this->table()} ORDER BY code, lang", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array_map( array( self::class, 'hydrate' ), $rows );
	}

	/**
	 * @param array{subject?:string, preheader?:string, heading?:string, body_html?:string, enabled?:bool} $fields
	 */
	public function upsert( string $code, string $lang, array $fields, ?int $userId = null ): bool {
		global $wpdb;

		$data = array_intersect_key( $fields, array_flip( array( 'subject', 'preheader', 'heading', 'body_html', 'enabled' ) ) );
		if ( array_key_exists( 'enabled', $data ) ) {
			$data['enabled'] = $data['enabled'] ? 1 : 0;
		}
		$data['updated_by'] = $userId;
		$data['updated_at'] = Db::nowUtc();

		$key = array(
			'code' => $code,
			'lang' => $lang,
		);

		if ( null !== $this->find( $code, $lang ) ) {
			return false !== $wpdb->update( $this->table(), $data, $key );
		}

		return false !== $wpdb->insert( $this->table(), $key + $data );
	}

	public function delete( string $code, string $lang ): bool {
		global $wpdb;

		return false !== $wpdb->delete(
			$this->table(),
			array(
				'code' => $code,
				'lang' => $lang,
			),
			array( '%s', '%s' )
		);
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private static function hydrate( array $row ): array {
		$row['enabled']    = (bool) (int) $row['enabled'];
		$row['updated_by'] = null === $row['updated_by'] ? null : (int) $row['updated_by'];

		return $row;
	}
}
