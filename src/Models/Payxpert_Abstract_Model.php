<?php

namespace Payxpert\Models;

defined('ABSPATH') || exit;

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
     * @var array
     */
    protected $data = [];

    protected $primary_key;

    protected $fillable = [];

    /**
     * Sets the data to persist.
     */
    public function set(array $data): void {
        $filtered = array_intersect_key($data, array_flip($this->fillable ?? []));
        $this->data = array_merge($this->data, $filtered);
    }

    /**
     * Returns the current data bag.
     */
    public function get(): array {
        return $this->data;
    }

    /**
     * Inserts or updates rows in the database.
     */
    public function save(): bool {
        global $wpdb;

        if (!empty($this->data[$this->primary_key])) {
             // Update
            if (in_array('date_upd', $this->fillable, true)) {
                $this->data['date_upd'] = current_time('mysql');
            }

            $where = [ $this->primary_key => $this->data[$this->primary_key] ];
            return false !== $wpdb->update($this->table, $this->data, $where);
        } else {
            // Insert
            return false !== $wpdb->insert($this->table, $this->data);
        }
    }

    /**
     * @return string[]
     */
    protected static function get_allowed_condition_columns(): array {
        $instance = new static();

        return array_merge($instance->fillable ?? [], array_filter([$instance->primary_key]));
    }

    public static function findBy(array $conditions): array {
        global $wpdb;
        $instance = new static();

        $allowed = array_flip(static::get_allowed_condition_columns());

        $where = [];
        $values = [];
        foreach ($conditions as $key => $value) {
            if (!isset($allowed[$key])) {
                continue;
            }
            $where[] = "{$key} = %s";
            $values[] = $value;
        }

        if ($where === []) {
            return [];
        }

        $sql = "SELECT * FROM {$instance->table} WHERE " . implode(' AND ', $where);
        return $wpdb->get_results($wpdb->prepare($sql, ...$values), ARRAY_A);
    }

    public static function findAll(): array {
        global $wpdb;
        $instance = new static();
        $sql = "SELECT * FROM {$instance->table}";
        return $wpdb->get_results($sql, ARRAY_A);
    }

    public static function deleteBy(array $conditions): bool {
        global $wpdb;
        $instance = new static();

        $allowed = array_flip(static::get_allowed_condition_columns());

        $where = [];
        $values = [];
        foreach ($conditions as $key => $value) {
            if (!isset($allowed[$key])) {
                continue;
            }
            $where[] = "{$key} = %s";
            $values[] = $value;
        }

        if ($where === []) {
            return false;
        }

        $sql = "DELETE FROM {$instance->table} WHERE " . implode(' AND ', $where);
        return (bool) $wpdb->query($wpdb->prepare($sql, ...$values));
    }

    public static function findOneBy(array $conditions): ?array {
        $results = static::findBy($conditions);
        return $results[0] ?? null;
    }

    public static function get_instance_from_array(array $data): self {
        $obj = new static();
        $obj->set($data);
        return $obj;
    }
}
