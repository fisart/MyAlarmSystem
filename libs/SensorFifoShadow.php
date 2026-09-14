<?php
declare(strict_types=1);
require_once __DIR__ . '/SensorRuleIdentity.php';

/** Pure diagnostic evaluator. No Symcon calls, outputs, logging or timers. */
final class SensorFifoShadow
{
    private array $config;
    private array $ruleCounts;
    private array $state;
    private array $classes = [];
    private array $groups = [];

    public function __construct(array $config, array $state)
    {
        $this->config = $config;
        $this->ruleCounts = SensorRuleIdentity::counts($config);
        $this->state = $state + ['values' => [], 'ingress' => [], 'last' => [],
            'pulses' => [], 'conditions' => [], 'classes' => [], 'projection' => []];
        $byClass = [];
        foreach ($config['SensorList'] ?? [] as $row) {
            if (self::active($row)) $byClass[(string)($row['ClassID'] ?? '')][] = $row;
        }
        foreach ($config['ClassList'] ?? [] as $class) {
            if (empty($class['ClassID']) || !self::active($class)) continue;
            $rows = array_values(array_filter($byClass[(string)$class['ClassID']] ?? [],
                static fn(array $row): bool => ($row['ClassID'] ?? '') === $class['ClassID']));
            $this->classes[] = [$class, $rows];
        }
        foreach ($config['GroupList'] ?? [] as $group) {
            $name = trim((string)($group['GroupName'] ?? ''));
            if ($name !== '' && self::active($group)) {
                $this->groups[$name] = ['logic' => $group['GroupLogic'] ?? 0, 'classes' => []];
            }
        }
        foreach ($config['GroupMembers'] ?? [] as $member) {
            $name = (string)($member['GroupName'] ?? '');
            if (isset($this->groups[$name])) $this->groups[$name]['classes'][] = $member['ClassID'];
        }
    }

    public static function dependencies(array $config): array
    {
        $ids = [];
        foreach (['SensorList', 'TamperList'] as $list) {
            foreach ($config[$list] ?? [] as $row) {
                $ids[(int)($row['VariableID'] ?? 0)] = true;
                if (self::dynamic($row)) $ids[(int)($row['ComparisonVariableID'] ?? 0)] = true;
            }
        }
        foreach ($config['BedroomList'] ?? [] as $bed) $ids[(int)($bed['ActiveVariableID'] ?? 0)] = true;
        unset($ids[0]);
        return array_keys($ids);
    }

    public function exportState(): array { return $this->state; }

    public function process(array $record): array
    {
        $trigger = 0;
        if ($record['kind'] === 'input') {
            $trigger = (int)$record['variable_id'];
            $key = (string)$trigger;
            if (!array_key_exists($key, $this->state['values']) ||
                !self::same($this->state['values'][$key], $record['previous'])) {
                throw new RuntimeException('Captured prior value does not match the shadow mirror; baseline/event gap.');
            }
            $this->state['values'][$key] = $record['value'];
            $entry = $this->state['ingress'][$key] ?? null;
            $evaluate = !is_array($entry) || !array_key_exists('value', $entry) ||
                ($entry['type'] ?? self::type($entry['value'])) !== self::type($record['value']) ||
                (is_float($record['value']) ? (float)$entry['value'] !== $record['value'] : $entry['value'] !== $record['value']);
            $this->state['ingress'][$key] = ['type' => self::type($record['value']), 'value' => $record['value']];
            if (!$evaluate) return ['evaluated' => false, 'projection' => $this->state['projection']];
        }
        $now = (int)$record['wall_s'];
        $stateOnly = ($record['kind'] === 'state_sync');
        $sabotage = false;
        // Match runtime tamper order and short circuit; temporal state belongs to each rule.
        foreach ($this->config['TamperList'] ?? [] as $row) {
            if ($this->rule($row, $now, $stateOnly)) { $sabotage = true; break; }
        }
        $active = [];
        $sensors = [];
        $countHistory = 0;
        foreach ($this->classes as [$class, $rows]) {
            $matches = 0;
            $direct = false;
            foreach ($rows as $row) {
                if ($this->rule($row, $now, $stateOnly)) {
                    ++$matches;
                    $sensors[(int)$row['VariableID']] = true;
                    if ($trigger > 0 && (int)$row['VariableID'] === $trigger) $direct = true;
                }
            }
            $cid = (string)$class['ClassID'];
            $mode = (int)$class['LogicMode'];
            $isActive = false;
            if ($mode === 0) $isActive = $matches > 0;
            elseif ($mode === 1) $isActive = count($rows) > 0 && $matches === count($rows);
            elseif ($mode === 2) {
                $buffer = array_values(array_filter($this->state['classes'][$cid]['Buffer'] ?? [],
                    static fn($ts): bool => ($now - $ts) <= $class['TimeWindow']));
                if ($direct) $buffer[] = $now;
                // Shadow stops rather than allowing a pathological COUNT buffer to grow.
                if (count($buffer) > 2048) throw new RuntimeException('Shadow COUNT history limit reached.');
                $countHistory += count($buffer);
                if ($countHistory > 8192) throw new RuntimeException('Total shadow COUNT history limit reached.');
                $isActive = count($buffer) >= $class['Threshold'];
                $this->state['classes'][$cid]['Buffer'] = $buffer;
            }
            if ($isActive) $active[$cid] = $matches > 0;
        }
        $groups = [];
        foreach ($this->groups as $name => $group) {
            $matched = 0;
            foreach ($group['classes'] as $cid) if (!empty($active[$cid])) ++$matched;
            $total = count($group['classes']);
            if ($total > 0 && (($group['logic'] == 0 && $matched > 0) ||
                ($group['logic'] == 1 && $matched === $total))) $groups[] = $name;
        }
        $projection = ['classes' => array_keys($active), 'groups' => $groups,
            'sensors' => array_keys($sensors), 'sabotage' => $sabotage];
        sort($projection['classes'], SORT_STRING);
        sort($projection['groups'], SORT_STRING);
        sort($projection['sensors'], SORT_NUMERIC);
        $this->state['projection'] = $projection;
        return ['evaluated' => true, 'projection' => $projection];
    }

    private function rule(array $row, int $now, bool $stateOnly): bool
    {
        $id = (int)($row['VariableID'] ?? 0);
        $key = SensorRuleIdentity::key($row, $this->ruleCounts);
        if (!array_key_exists($id, $this->state['values'])) return false;
        $mode = (int)($row['TriggerMode'] ?? 0);
        $duration = max(1, (int)($row['PulseSeconds'] ?? 1));
        if ($stateOnly && $mode !== 0) return (int)($this->state['pulses'][$key] ?? 0) > $now;
        $value = $this->state['values'][$id];
        if ($mode === 0) return $this->condition($row, $value);
        if ($mode === 2) {
            // Invalid dynamic comparison returns false without mutating legacy caches.
            $valid = true;
            $condition = $this->condition($row, $value, $valid);
            if (!$valid) return false;
            $hadPrevious = array_key_exists($key, $this->state['conditions']);
            $wasActive = $hadPrevious ? (bool)$this->state['conditions'][$key] : false;
            $this->state['conditions'][$key] = $condition;
            if (!$hadPrevious) {
                if ((int)($this->state['pulses'][$key] ?? 0) <= $now) unset($this->state['pulses'][$key]);
                return false;
            }
            if (!$condition) { unset($this->state['pulses'][$key]); return false; }
            if (!$wasActive) { $this->state['pulses'][$key] = $now + $duration; return true; }
        } else {
            $old = $this->state['last'][$key] ?? null;
            $type = self::type($value);
            $this->state['last'][$key] = ['type' => $type, 'value' => $value];
            if (!is_array($old) || !isset($old['type']) || !array_key_exists('value', $old)) {
                if ((int)($this->state['pulses'][$key] ?? 0) <= $now) unset($this->state['pulses'][$key]);
                return false;
            }
            $changed = $old['type'] !== $type || (is_float($value)
                ? abs((float)$old['value'] - $value) > 0.000001 : !self::same($old['value'], $value));
            if ($changed) { $this->state['pulses'][$key] = $now + $duration; return true; }
        }
        $until = (int)($this->state['pulses'][$key] ?? 0);
        if ($until > $now) return true;
        if ($until > 0) unset($this->state['pulses'][$key]);
        return false;
    }

    private function condition(array $row, $value, ?bool &$valid = null): bool
    {
        $valid = true;
        if (isset($row['Invert'])) return (bool)($row['Invert'] ? !$value : $value);
        $target = $row['ComparisonValue'] ?? '';
        if (self::dynamic($row)) {
            $key = (string)(int)($row['ComparisonVariableID'] ?? 0);
            if (!array_key_exists($key, $this->state['values'])) { $valid = false; return false; }
            $target = $this->state['values'][$key];
            $compatible = (is_bool($value) || is_bool($target)) ? is_bool($value) && is_bool($target) :
                ((is_string($value) || is_string($target)) ? is_string($value) && is_string($target) :
                (is_int($value) || is_float($value)) && (is_int($target) || is_float($target)));
            if (!$compatible) { $valid = false; return false; }
            if (is_bool($value)) $target = $target ? '1' : '0';
        }
        if (is_bool($value)) $target = ($target === 'true' || $target === '1' || $target === 1);
        elseif (is_int($value) || is_float($value)) $target = (float)$target;
        switch ((int)($row['Operator'] ?? -1)) {
            case 0: return $value == $target;
            case 1: return $value != $target;
            case 2: return $value > $target;
            case 3: return $value < $target;
            case 4: return $value >= $target;
            case 5: return $value <= $target;
            default: return false;
        }
    }

    private static function active(array $row): bool
    {
        if (!array_key_exists('Active', $row)) return true;
        $value = $row['Active'];
        if (is_int($value) || is_float($value)) return (int)$value === 1;
        if (is_string($value)) return !in_array(strtolower(trim($value)), ['', '0', 'false', 'no', 'off'], true);
        return (bool)$value;
    }

    private static function dynamic(array $row): bool
    {
        return (int)($row['TriggerMode'] ?? 0) !== 1 && (int)($row['ComparisonSource'] ?? 0) === 1;
    }

    private static function same($a, $b): bool { return gettype($a) === gettype($b) && $a === $b; }
    private static function type($value): string { return is_float($value) ? 'float' : gettype($value); }
}
