<?php

declare(strict_types=1);
require_once __DIR__ . '/PiiCryptoService.php';

/**
 * Protect names at the shared PDO boundary, including older parameterized writers.
 * Existing SQL captions (CONCAT/GROUP_CONCAT) remain readable to authorized callers.
 * Contact/address encryption stays in the existing model services. Raw ciphertext
 * is available explicitly to migration/verification queries with /* pii:raw * /.
 */
final class PiiNameStatement extends PDOStatement
{
    private ?array $nameParameters = null;
    private ?array $readableColumns = null;
    private int $defaultFetchMode = PDO::FETCH_ASSOC;
    private array $defaultFetchArgs = [];

    // Only computed person-name captions may contain several encrypted names.
    // A stored narrative aliased to one of these still has a source table and
    // must not be treated as a computed name.
    private const NAME_CAPTIONS = [
        'name', 'full_name', 'display_name', 'parties', 'complainant_name', 'complainant_names',
        'respondent_name', 'respondent_names', 'resident_name', 'member_name', 'officer_name',
        'approver_name', 'attested_by_name', 'attesting_officer_name', 'author_name',
        'chairman_name', 'challenged_name', 'decided_by_name', 'deleted_by_name',
        'encoded_by_name', 'encoder_name', 'evaluated_by_name', 'evaluator_name',
        'finalized_by_name', 'generated_by_name', 'head_name', 'issued_by_name', 'pb_name',
        'prepared_by_name', 'presider_name', 'presiding_officer_name', 'ready_by_name',
        'recorded_by_name', 'referring_officer_name', 'released_by_name', 'replacement_name',
        'repudiated_by_name', 'repudiating_party_name', 'resolver_name', 'reviewed_by_name',
        'scheduled_by_name', 'served_by_name', 'server_name', 'serving_officer_name',
        'sub_name', 'substitute_name', 'substitute_presider_name', 'target_party_name',
        'transmitting_officer_name', 'updated_by_name', 'uploaded_by_name', 'user_name',
    ];

    private function nameParameters(): array
    {
        if ($this->nameParameters !== null) return $this->nameParameters;
        $this->nameParameters = [];
        $sql = $this->queryString;
        if (str_contains($sql, '/* pii:raw */')) return [];
        if (preg_match('/\bINSERT\s+INTO\s+`?(?:residents|users)`?\s*\(([^)]+)\)\s*VALUES\s*\(/is', $sql, $match, PREG_OFFSET_CAPTURE)) {
            $columns = array_map(static fn ($column) => strtolower(trim($column, " \t\r\n`")), explode(',', $match[1][0]));
            $valuesStart = $match[0][1] + strlen($match[0][0]);
            $values = self::valueExpressions(substr($sql, $valuesStart));
            $parameter = 0;
            foreach ($values as $index => $expression) {
                if (in_array($columns[$index] ?? '', ['first_name', 'middle_name', 'last_name', 'suffix'], true)) {
                    if (trim($expression) === '?') $this->nameParameters[$parameter] = true;
                    elseif (preg_match('/^:\w+$/', trim($expression))) $this->nameParameters[trim($expression)] = true;
                }
                $parameter += substr_count($expression, '?');
            }
        } elseif (preg_match('/\bUPDATE\s+`?(?:residents|users)`?\b/is', $sql)) {
            // Only assignments in SET; never encrypt parameters used for WHERE IDs.
            if (preg_match('/\bSET\b/i', $sql, $setMatch, PREG_OFFSET_CAPTURE)) {
                $setStart = $setMatch[0][1];
                $where = preg_match('/\bWHERE\b/i', $sql, $whereMatch, PREG_OFFSET_CAPTURE, $setStart + 3) ? $whereMatch[0][1] : false;
                $set = substr($sql, $setStart, $where === false ? null : $where - $setStart);
                preg_match_all('/`?(first_name|middle_name|last_name|suffix)`?\s*=\s*(\?|:\w+)/i', $set, $assignments, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
                foreach ($assignments as $assignment) {
                    $placeholder = $assignment[2][0];
                    $key = $placeholder === '?' ? substr_count(substr($sql, 0, $setStart + $assignment[2][1]), '?') : $placeholder;
                    $this->nameParameters[$key] = true;
                }
            }
        }
        return $this->nameParameters;
    }

    /** Split one VALUES tuple without splitting commas inside strings/functions. */
    private static function valueExpressions(string $sql): array
    {
        $parts = []; $part = ''; $depth = 0; $quote = null;
        for ($i = 0, $length = strlen($sql); $i < $length; $i++) {
            $ch = $sql[$i];
            if ($quote !== null) {
                $part .= $ch;
                if ($ch === '\\' && $i + 1 < $length) $part .= $sql[++$i];
                elseif ($ch === $quote) {
                    if (($sql[$i + 1] ?? '') === $quote) $part .= $sql[++$i];
                    else $quote = null;
                }
            } elseif ($ch === "'" || $ch === '"') { $quote = $ch; $part .= $ch;
            } elseif ($ch === '(') { $depth++; $part .= $ch;
            } elseif ($ch === ')' && $depth === 0) { $parts[] = $part; break;
            } elseif ($ch === ')') { $depth--; $part .= $ch;
            } elseif ($ch === ',' && $depth === 0) { $parts[] = $part; $part = '';
            } else $part .= $ch;
        }
        return $parts;
    }

    private function protect(mixed $parameter, mixed $value): mixed
    {
        $parameters = $this->nameParameters();
        $key = is_int($parameter) ? $parameter : ':' . ltrim((string) $parameter, ':');
        return isset($parameters[$key]) && $value !== null && $value !== ''
            ? (new PiiCryptoService())->encryptName((string) $value) : $value;
    }

    public function execute(?array $params = null): bool
    {
        $this->readableColumns = null;
        if ($params !== null) foreach ($params as $key => $value) $params[$key] = $this->protect($key, $value);
        return parent::execute($params);
    }

    public function bindValue(string|int $param, mixed $value, int $type = PDO::PARAM_STR): bool
    {
        return parent::bindValue($param, $this->protect(is_int($param) ? $param - 1 : $param, $value), $type);
    }

    /** Match result columns, never the contents of arbitrary returned text. */
    private function readableColumns(): array
    {
        if ($this->readableColumns !== null) return $this->readableColumns;
        $this->readableColumns = [];
        if (str_contains($this->queryString, '/* pii:raw */')) return [];
        $nameSources = ['users' => true, 'residents' => true];
        preg_match_all('/\b(?:FROM|JOIN)\s+`?(users|residents)`?(?:\s+(?:AS\s+)?`?([a-z_][a-z0-9_]*)`?)?/i', $this->queryString, $sources, PREG_SET_ORDER);
        foreach ($sources as $source) if (isset($source[2])) $nameSources[strtolower($source[2])] = true;
        for ($column = 0; $column < $this->columnCount(); $column++) {
            $meta = $this->getColumnMeta($column);
            if ($meta === false) continue;
            $field = strtolower($meta['name']);
            $directName = isset($nameSources[strtolower($meta['table'] ?? '')])
                && preg_match('/(?:^|_)(?:first_name|middle_name|last_name|suffix)$/', $field) === 1;
            $computedName = ($meta['table'] ?? '') === ''
                && $sources !== []
                && preg_match('/\b(?:first_name|middle_name|last_name)\b/i', $this->queryString)
                && (in_array($field, self::NAME_CAPTIONS, true)
                    // Existing scalar name queries do not all declare an alias.
                    || (preg_match('/^(?:trim|concat|concat_ws|coalesce)\s*\(/i', $field)
                        && preg_match('/\b(?:first_name|middle_name|last_name)\b/i', $field)));
            $kind = $directName ? 'name' : ($computedName ? 'caption' : null);
            if ($kind === null && !in_array($field, PiiCryptoService::NON_PII_SKIP_KEYS, true)) {
                $kind = 'enc';
            }
            $this->readableColumns[$column] = $kind;
            // PDO associative rows keep the last column with a duplicate label.
            $this->readableColumns[$meta['name']] = $kind;
        }
        return $this->readableColumns;
    }

    private function readableValue(mixed $value, string|int $column): mixed
    {
        $kind = $this->readableColumns()[$column] ?? null;
        if ($kind === null || !is_string($value)) return $value;
        if (($kind === 'name' || $kind === 'caption') && str_contains($value, 'name:v1:')) {
            $crypto = new PiiCryptoService();
            $value = $kind === 'name'
                ? $crypto->decrypt($value)
                : preg_replace_callback('/name:v1:[A-Za-z0-9+\/=]+/', static fn ($match) => $crypto->decrypt($match[0]), $value);
        }
        if (is_string($value) && str_contains($value, 'enc:v1:')) {
            return PiiCryptoService::decryptStringTokens($value);
        }
        return $value;
    }

    private function readableRow(mixed $value, int $columnOffset = 0): mixed
    {
        if (is_array($value)) {
            foreach ($value as $field => $item) $value[$field] = $this->readableValue($item, is_int($field) ? $field + $columnOffset : $field);
        } elseif ($value instanceof stdClass) {
            foreach ($value as $field => $item) $value->$field = $this->readableValue($item, $field);
        }
        return $value;
    }

    #[\ReturnTypeWillChange]
    public function setFetchMode(int $mode, mixed ...$args): bool
    {
        $success = parent::setFetchMode($mode, ...$args);
        if ($success) { $this->defaultFetchMode = $mode; $this->defaultFetchArgs = $args; }
        return $success;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        $value = parent::fetch($mode, $cursorOrientation, $cursorOffset);
        $effectiveMode = $mode === PDO::FETCH_DEFAULT ? $this->defaultFetchMode : $mode;
        return $effectiveMode === PDO::FETCH_COLUMN
            ? $this->readableValue($value, $mode === PDO::FETCH_DEFAULT ? ($this->defaultFetchArgs[0] ?? 0) : 0)
            : $this->readableRow($value);
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        $rows = parent::fetchAll($mode, ...$args);
        $effectiveMode = $mode === PDO::FETCH_DEFAULT ? $this->defaultFetchMode : $mode;
        $effectiveArgs = $mode === PDO::FETCH_DEFAULT ? $this->defaultFetchArgs : $args;
        $baseMode = $effectiveMode & 0xffff;
        $unique = ($effectiveMode & PDO::FETCH_UNIQUE) === PDO::FETCH_UNIQUE;
        $grouped = !$unique && ($effectiveMode & PDO::FETCH_GROUP) === PDO::FETCH_GROUP;
        $result = [];
        foreach ($rows as $key => $value) {
            if ($baseMode === PDO::FETCH_KEY_PAIR) {
                $result[$this->readableValue($key, 0)] = $this->readableValue($value, 1);
            } elseif ($unique || $grouped) {
                $key = $this->readableValue($key, 0);
                if ($unique) $result[$key] = $baseMode === PDO::FETCH_COLUMN
                    ? $this->readableValue($value, $effectiveArgs[0] ?? 1) : $this->readableRow($value, 1);
                else foreach ($value as $item) $result[$key][] = $baseMode === PDO::FETCH_COLUMN
                    ? $this->readableValue($item, $effectiveArgs[0] ?? 1) : $this->readableRow($item, 1);
            } else {
                $result[$key] = $baseMode === PDO::FETCH_COLUMN
                    ? $this->readableValue($value, $effectiveArgs[0] ?? 0) : $this->readableRow($value);
            }
        }
        return $result;
    }

    public function fetchColumn(int $column = 0): mixed
    { return $this->readableValue(parent::fetchColumn($column), $column); }
}
