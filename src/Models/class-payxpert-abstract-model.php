<?php
/**
 * Payxpert Abstract Model.
 *
 * @package Payxpert
 */

namespace Payxpert\Models;

defined( 'ABSPATH' ) || exit;

/**
 * Payxpert Abstract Model.
 *
 * @phpstan-consistent-constructor
 */
abstract class Payxpert_Abstract_Model {
	/**
	 * Table name, defined by child classes.
	 *
	 * @var string
	 */
	protected $table;

	/**
	 * Data to insert or update.
	 *
	 * @var array<string, mixed>
	 */
	protected $data = array();

	/**
	 * Primary key.
	 *
	 * @var mixed
	 */
	protected $primary_key;

	/**
	 * Fillable.
	 *
	 * @var list<string>
	 */
	protected $fillable = array();

	/**
	 * Creates a new instance of the concrete model class.
	 *
	 * @return static
	 */
	protected static function create_instance() {
		// @phpstan-ignore new.static
		return new static();
	}

	/**
	 * Sets the data to persist.
	 *
	 * @param array<string, mixed> $data Data to merge into the model (filtered by fillable).
	 */
	public function set( array $data ): void {
		$filtered   = array_intersect_key( $data, array_flip( $this->fillable ) );
		$this->data = array_merge( $this->data, $filtered );
	}

	/**
	 * Returns the current data bag.
	 *
	 * @return array<string, mixed>
	 */
	public function get(): array {
		return $this->data;
	}

	/**
	 * Inserts or updates rows in the database.
	 */
	public function save(): bool {
		global $wpdb;

		if ( ! empty( $this->data[ $this->primary_key ] ) ) {
			// Update.
			if ( in_array( 'date_upd', $this->fillable, true ) ) {
				$this->data['date_upd'] = current_time( 'mysql' );
			}

			$where = array( $this->primary_key => $this->data[ $this->primary_key ] );
			return false !== $wpdb->update( $this->table, $this->data, $where );
		} else {
			// Insert.
			return false !== $wpdb->insert( $this->table, $this->data );
		}
	}

	/**
	 * Returns the columns allowed in WHERE conditions.
	 *
	 * @return list<string>
	 */
	protected static function get_allowed_condition_columns(): array {
		$instance = static::create_instance();

		return array_merge( $instance->fillable, array_filter( array( $instance->primary_key ) ) );
	}

	/**
	 * Find by.
	 *
	 * @param array<string, mixed> $conditions Conditions.
	 * @return list<array<string, mixed>>
	 */
	public static function find_by( array $conditions ): array {
		global $wpdb;
		$instance = static::create_instance();

		$allowed = array_flip( static::get_allowed_condition_columns() );

		$where  = array();
		$values = array();
		foreach ( $conditions as $key => $value ) {
			if ( ! isset( $allowed[ $key ] ) ) {
				continue;
			}
			$where[]  = "{$key} = %s";
			$values[] = $value;
		}

		if ( array() === $where ) {
			return array();
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Table name is a safe internal identifier; column names are whitelisted and values bound through $wpdb->prepare().
		$sql = "SELECT * FROM {$instance->table} WHERE " . implode( ' AND ', $where );
		return $wpdb->get_results( $wpdb->prepare( $sql, ...$values ), ARRAY_A );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Find all.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function find_all(): array {
		global $wpdb;
		$instance = static::create_instance();
		$sql      = "SELECT * FROM {$instance->table}";
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Full-table read on a safe internal table name; no user input.
		return $wpdb->get_results( $sql, ARRAY_A );
	}

	/**
	 * Delete by.
	 *
	 * @param array<string, mixed> $conditions Conditions.
	 */
	public static function delete_by( array $conditions ): bool {
		global $wpdb;
		$instance = static::create_instance();

		$allowed = array_flip( static::get_allowed_condition_columns() );

		$where  = array();
		$values = array();
		foreach ( $conditions as $key => $value ) {
			if ( ! isset( $allowed[ $key ] ) ) {
				continue;
			}
			$where[]  = "{$key} = %s";
			$values[] = $value;
		}

		if ( array() === $where ) {
			return false;
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Table name is a safe internal identifier; column names are whitelisted and values bound through $wpdb->prepare().
		$sql = "DELETE FROM {$instance->table} WHERE " . implode( ' AND ', $where );
		return (bool) $wpdb->query( $wpdb->prepare( $sql, ...$values ) );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Find one by.
	 *
	 * @param array<string, mixed> $conditions Conditions.
	 * @return array<string, mixed>|null
	 */
	public static function find_one_by( array $conditions ): ?array {
		$results = static::find_by( $conditions );
		return $results[0] ?? null;
	}

	/**
	 * Get instance from array.
	 *
	 * @param array<string, mixed> $data Data.
	 * @return static
	 */
	public static function get_instance_from_array( array $data ) {
		$obj = static::create_instance();
		$obj->set( $data );
		return $obj;
	}
}
