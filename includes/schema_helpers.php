<?php
if (!function_exists('wuc_table_columns')) {
    function wuc_table_columns(mysqli $db, string $table): array
    {
        static $cache = [];
        $key = strtolower($table);
        if (isset($cache[$key])) {
            return $cache[$key];
        }

        $columns = [];
        $safeTable = preg_replace('/[^A-Za-z0-9_]/', '', $table);
        if ($safeTable !== '' && ($result = @$db->query("SHOW COLUMNS FROM `{$safeTable}`"))) {
            while ($row = $result->fetch_assoc()) {
                $field = (string)($row['Field'] ?? '');
                if ($field !== '') {
                    $columns[strtolower($field)] = $field;
                }
            }
            $result->free();
        }

        return $cache[$key] = $columns;
    }
}

if (!function_exists('wuc_detect_column')) {
    function wuc_detect_column(mysqli $db, string $table, array $candidates): ?string
    {
        $columns = wuc_table_columns($db, $table);
        foreach ($candidates as $candidate) {
            $key = strtolower((string)$candidate);
            if (isset($columns[$key])) {
                return $columns[$key];
            }
        }
        return null;
    }
}

if (!function_exists('wuc_object_value')) {
    function wuc_object_value(?object $record, string $property, string $default = ''): string
    {
        if (!$record || !property_exists($record, $property) || $record->{$property} === null) {
            return $default;
        }
        return (string)$record->{$property};
    }
}

if (!function_exists('wuc_bind_param_array')) {
    function wuc_bind_param_array(mysqli_stmt $stmt, string $types, array &$params): bool
    {
        if ($types === '') {
            return true;
        }

        $refs = [$types];
        foreach ($params as $idx => &$value) {
            $refs[] = &$params[$idx];
        }
        return call_user_func_array([$stmt, 'bind_param'], $refs);
    }
}
