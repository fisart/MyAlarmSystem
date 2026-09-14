<?php
declare(strict_types=1);

/** Stable rule identity; UI ordering and display fields are deliberately excluded. */
final class SensorRuleIdentity
{
    public static function signature(array $row): string
    {
        return hash('sha256', json_encode([
            (int)($row['VariableID'] ?? 0), (string)($row['ClassID'] ?? ''),
            (int)($row['TriggerMode'] ?? 0), max(1, (int)($row['PulseSeconds'] ?? 1)),
            (int)($row['Operator'] ?? 0), (int)($row['ComparisonSource'] ?? 0),
            (string)($row['ComparisonValue'] ?? ''), (int)($row['ComparisonVariableID'] ?? 0),
            isset($row['Invert']) ? (bool)$row['Invert'] : null
        ], JSON_THROW_ON_ERROR));
    }

    public static function counts(array $config): array
    {
        $counts = [];
        foreach (array_merge($config['SensorList'] ?? [], $config['TamperList'] ?? []) as $row) {
            $id = (int)($row['VariableID'] ?? 0);
            $counts[$id] = ($counts[$id] ?? 0) + 1;
        }
        return $counts;
    }

    public static function key(array $row, array $counts): string
    {
        $id = (int)($row['VariableID'] ?? 0);
        // Stateful rules must never depend on the process-local count cache. Native
        // module lifecycles can retain the active revision while rebuilding that
        // cache, and falling back to VariableID would make different predicates
        // overwrite one another until the next ApplyChanges().
        if ((int)($row['TriggerMode'] ?? 0) !== 0) {
            return 'r_' . self::signature($row);
        }

        // Keep the lightweight numeric identity for ordinary, unique LEVEL inputs.
        return ($counts[$id] ?? 1) <= 1 ? (string)$id : 'r_' . self::signature($row);
    }
}
