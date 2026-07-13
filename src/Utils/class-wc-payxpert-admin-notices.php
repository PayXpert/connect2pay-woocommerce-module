<?php
/**
 * Admin flash notices stored in user meta (avoids set_transient / setted_transient on WP 6.8+).
 *
 * @package Payxpert
 */

namespace Payxpert\Utils;

defined( 'ABSPATH' ) || exit();

/**
 * WC Payxpert Admin Notices.
 */
class WC_Payxpert_Admin_Notices {
	const META_PREFIX = '_payxpert_admin_notice_';

	/**
	 * Store a notice for the current (or given) admin user.
	 *
	 * @param string   $key     Notice key.
	 * @param mixed    $value   Notice payload.
	 * @param int|null $user_id Optional user ID.
	 */
	public static function set( string $key, $value, ?int $user_id = null ): void {
		$user_id = self::resolve_user_id( $user_id );
		if ( $user_id <= 0 ) {
			return;
		}

		update_user_meta( $user_id, self::meta_key( $key ), $value );
	}

	/**
	 * Read a notice without removing it.
	 *
	 * @param string   $key     Notice key.
	 * @param int|null $user_id Optional user ID.
	 * @return mixed
	 */
	public static function get( string $key, ?int $user_id = null ) {
		$user_id = self::resolve_user_id( $user_id );
		if ( $user_id <= 0 ) {
			return false;
		}

		return get_user_meta( $user_id, self::meta_key( $key ), true );
	}

	/**
	 * Read a notice and delete it.
	 *
	 * @param string   $key     Notice key.
	 * @param int|null $user_id Optional user ID.
	 * @return mixed
	 */
	public static function get_and_delete( string $key, ?int $user_id = null ) {
		$value = self::get( $key, $user_id );
		if ( false !== $value && '' !== $value ) {
			self::delete( $key, $user_id );
		}

		return $value;
	}

	/**
	 * Delete a stored notice.
	 *
	 * @param string   $key     Notice key.
	 * @param int|null $user_id Optional user ID.
	 */
	public static function delete( string $key, ?int $user_id = null ): void {
		$user_id = self::resolve_user_id( $user_id );
		if ( $user_id <= 0 ) {
			return;
		}

		delete_user_meta( $user_id, self::meta_key( $key ) );
	}

	/**
	 * Build the user-meta key for a notice.
	 *
	 * @param string $key Notice key.
	 * @return string
	 */
	private static function meta_key( string $key ): string {
		return self::META_PREFIX . $key;
	}

	/**
	 * Resolve the target user ID.
	 *
	 * @param int|null $user_id Optional user ID.
	 * @return int
	 */
	private static function resolve_user_id( ?int $user_id ): int {
		if ( null !== $user_id && $user_id > 0 ) {
			return $user_id;
		}

		return (int) get_current_user_id();
	}
}
