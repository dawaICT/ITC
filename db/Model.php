<?php
/**
 * Base Model Class
 * Provides common database operations for all models
 */

require_once __DIR__ . '/Database.php';

abstract class Model {
    protected static string $table = '';
    protected static string $primaryKey = 'id';
    protected array $attributes = [];
    protected array $original = [];
    protected bool $exists = false;
    
    /**
     * Constructor
     */
    public function __construct(array $attributes = []) {
        $this->fill($attributes);
    }
    
    /**
     * Get the table name
     */
    public static function getTable(): string {
        return static::$table;
    }
    
    /**
     * Get primary key name
     */
    public static function getPrimaryKey(): string {
        return static::$primaryKey;
    }
    
    /**
     * Fill attributes
     */
    public function fill(array $attributes): self {
        $this->attributes = array_merge($this->attributes, $attributes);
        return $this;
    }
    
    /**
     * Get attribute
     */
    public function __get(string $name) {
        return $this->attributes[$name] ?? null;
    }
    
    /**
     * Set attribute
     */
    public function __set(string $name, $value): void {
        $this->attributes[$name] = $value;
    }
    
    /**
     * Check if attribute exists
     */
    public function __isset(string $name): bool {
        return isset($this->attributes[$name]);
    }
    
    /**
     * Get all attributes
     */
    public function toArray(): array {
        return $this->attributes;
    }
    
    /**
     * Get JSON representation
     */
    public function toJson(): string {
        return json_encode($this->attributes);
    }
    
    /**
     * Find by primary key
     */
    public static function find($id): ?static {
        $db = Database::getInstance();
        $table = static::$table;
        $pk = static::$primaryKey;
        
        $row = $db->fetchOne("SELECT * FROM `$table` WHERE `$pk` = ?", [$id]);
        
        if ($row) {
            $model = new static($row);
            $model->exists = true;
            $model->original = $row;
            return $model;
        }
        
        return null;
    }
    
    /**
     * Find or fail
     */
    public static function findOrFail($id): static {
        $model = static::find($id);
        if ($model === null) {
            throw new Exception(static::class . " with ID $id not found");
        }
        return $model;
    }
    
    /**
     * Get all records
     */
    public static function all(): array {
        $db = Database::getInstance();
        $table = static::$table;
        
        $rows = $db->fetchAll("SELECT * FROM `$table`");
        
        return array_map(function($row) {
            $model = new static($row);
            $model->exists = true;
            $model->original = $row;
            return $model;
        }, $rows);
    }
    
    /**
     * Find by conditions
     */
    public static function where(string $column, $operator, $value = null): QueryBuilder {
        return (new QueryBuilder(static::class))->where($column, $operator, $value);
    }
    
    /**
     * Create new record
     */
    public static function create(array $attributes): static {
        $model = new static($attributes);
        $model->save();
        return $model;
    }
    
    /**
     * Save the model
     */
    public function save(): bool {
        $db = Database::getInstance();
        $table = static::$table;
        $pk = static::$primaryKey;
        
        if ($this->exists) {
            // Update
            $data = array_diff_assoc($this->attributes, $this->original);
            if (empty($data)) {
                return true; // Nothing to update
            }
            
            $db->update($table, $data, "`$pk` = ?", [$this->attributes[$pk]]);
            $this->original = $this->attributes;
        } else {
            // Insert
            $id = $db->insert($table, $this->attributes);
            if ($id !== false) {
                $this->attributes[$pk] = $id;
                $this->exists = true;
                $this->original = $this->attributes;
            }
        }
        
        return true;
    }
    
    /**
     * Delete the model
     */
    public function delete(): bool {
        if (!$this->exists) {
            return false;
        }
        
        $db = Database::getInstance();
        $table = static::$table;
        $pk = static::$primaryKey;
        
        $db->delete($table, "`$pk` = ?", [$this->attributes[$pk]]);
        $this->exists = false;
        
        return true;
    }
    
    /**
     * Refresh from database
     */
    public function refresh(): self {
        if ($this->exists) {
            $pk = static::$primaryKey;
            $fresh = static::find($this->attributes[$pk]);
            if ($fresh) {
                $this->attributes = $fresh->attributes;
                $this->original = $fresh->original;
            }
        }
        return $this;
    }
    
    /**
     * Count records
     */
    public static function count(): int {
        $db = Database::getInstance();
        $table = static::$table;
        return (int)$db->fetchValue("SELECT COUNT(*) FROM `$table`");
    }
}

/**
 * Query Builder for fluent queries
 */
class QueryBuilder {
    private string $modelClass;
    private string $table;
    private array $wheres = [];
    private array $bindings = [];
    private ?string $orderBy = null;
    private ?int $limit = null;
    private ?int $offset = null;
    private array $select = ['*'];
    
    public function __construct(string $modelClass) {
        $this->modelClass = $modelClass;
        $this->table = $modelClass::getTable();
    }
    
    public function select(...$columns): self {
        $this->select = $columns;
        return $this;
    }
    
    public function where(string $column, $operator, $value = null): self {
        if ($value === null) {
            $value = $operator;
            $operator = '=';
        }
        
        $this->wheres[] = "`$column` $operator ?";
        $this->bindings[] = $value;
        return $this;
    }
    
    public function whereIn(string $column, array $values): self {
        $placeholders = implode(', ', array_fill(0, count($values), '?'));
        $this->wheres[] = "`$column` IN ($placeholders)";
        $this->bindings = array_merge($this->bindings, $values);
        return $this;
    }
    
    public function whereNull(string $column): self {
        $this->wheres[] = "`$column` IS NULL";
        return $this;
    }
    
    public function whereNotNull(string $column): self {
        $this->wheres[] = "`$column` IS NOT NULL";
        return $this;
    }
    
    public function orderBy(string $column, string $direction = 'ASC'): self {
        $this->orderBy = "`$column` $direction";
        return $this;
    }
    
    public function limit(int $limit): self {
        $this->limit = $limit;
        return $this;
    }
    
    public function offset(int $offset): self {
        $this->offset = $offset;
        return $this;
    }
    
    public function get(): array {
        $sql = $this->buildQuery();
        $db = Database::getInstance();
        $rows = $db->fetchAll($sql, $this->bindings);
        
        $modelClass = $this->modelClass;
        return array_map(function($row) use ($modelClass) {
            $model = new $modelClass($row);
            $model->exists = true;
            $model->original = $row;
            return $model;
        }, $rows);
    }
    
    public function first(): ?object {
        $this->limit = 1;
        $results = $this->get();
        return $results[0] ?? null;
    }
    
    public function count(): int {
        $sql = "SELECT COUNT(*) FROM `{$this->table}`";
        if (!empty($this->wheres)) {
            $sql .= " WHERE " . implode(' AND ', $this->wheres);
        }
        
        $db = Database::getInstance();
        return (int)$db->fetchValue($sql, $this->bindings);
    }
    
    public function delete(): int {
        $sql = "DELETE FROM `{$this->table}`";
        if (!empty($this->wheres)) {
            $sql .= " WHERE " . implode(' AND ', $this->wheres);
        }
        
        $db = Database::getInstance();
        $db->query($sql, $this->bindings);
        return $db->affectedRows();
    }
    
    public function update(array $data): int {
        $setParts = [];
        $values = [];
        
        foreach ($data as $column => $value) {
            $setParts[] = "`$column` = ?";
            $values[] = $value;
        }
        
        $sql = "UPDATE `{$this->table}` SET " . implode(', ', $setParts);
        if (!empty($this->wheres)) {
            $sql .= " WHERE " . implode(' AND ', $this->wheres);
        }
        
        $db = Database::getInstance();
        $db->query($sql, array_merge($values, $this->bindings));
        return $db->affectedRows();
    }
    
    private function buildQuery(): string {
        $columns = implode(', ', $this->select);
        $sql = "SELECT $columns FROM `{$this->table}`";
        
        if (!empty($this->wheres)) {
            $sql .= " WHERE " . implode(' AND ', $this->wheres);
        }
        
        if ($this->orderBy) {
            $sql .= " ORDER BY {$this->orderBy}";
        }
        
        if ($this->limit !== null) {
            $sql .= " LIMIT {$this->limit}";
        }
        
        if ($this->offset !== null) {
            $sql .= " OFFSET {$this->offset}";
        }
        
        return $sql;
    }
}
