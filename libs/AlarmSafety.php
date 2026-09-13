<?php

declare(strict_types=1);

/** Configuration and three-valued LEVEL evaluation shared by the safety API and tests. */
final class AlarmSafety
{
    public const LISTS = ['ClassList', 'GroupList', 'SensorList', 'BedroomList', 'GroupMembers', 'TamperList', 'DispatchTargets', 'GroupDispatch'];
    public const ROLES = ['Front Door Lock', 'Front Door Contact', 'Basement Door Lock', 'Basement Door Contact', 'Generic Door', 'Window Contact', 'Presence'];

    public static function enabled(array $row): bool
    {
        $value = $row['Active'] ?? true;
        if (is_string($value)) return !in_array(strtolower(trim($value)), ['', '0', 'false', 'no', 'off'], true);
        return is_numeric($value) ? (int)$value === 1 : (bool)$value;
    }

    /** Only recover identity by an unambiguous name. Never guess by row position. */
    public static function normalize(array $config, array $previous = []): array
    {
        foreach (['ClassList' => ['ClassName', 'ClassID', 'cls_'], 'GroupList' => ['GroupName', 'GroupID', 'grp_']] as $list => $fields) {
            [$nameKey, $idKey, $prefix] = $fields;
            $known = [];
            foreach ($previous[$list] ?? [] as $row) {
                $name = trim((string)($row[$nameKey] ?? ''));
                $known[$name][] = (string)($row[$idKey] ?? '');
            }
            if (!isset($config[$list]) || !is_array($config[$list])) continue;
            foreach ($config[$list] as &$row) {
                if (!is_array($row)) continue;
                if ((isset($row[$nameKey]) && !is_string($row[$nameKey])) || (isset($row[$idKey]) && !is_string($row[$idKey]))) continue;
                $name = trim((string)($row[$nameKey] ?? ''));
                $row[$nameKey] = $name;
                if (empty($row[$idKey]) && $name !== '') {
                    $ids = array_values(array_unique($known[$name] ?? []));
                    $row[$idKey] = count($ids) === 1 && $ids[0] !== '' ? $ids[0] : uniqid($prefix);
                }
            }
            unset($row);
        }
        $names = [];
        foreach ($config['ClassList'] ?? [] as $row) {
            if (is_array($row) && is_string($row['ClassName'] ?? '') && is_string($row['ClassID'] ?? '')) $names[$row['ClassName'] ?? ''][] = $row['ClassID'] ?? '';
        }
        foreach (['SensorList' => 'ClassID', 'GroupMembers' => 'ClassID', 'BedroomList' => 'BedroomDoorClassID'] as $list => $key) {
            if (!isset($config[$list]) || !is_array($config[$list])) continue;
            foreach ($config[$list] as &$row) {
                if (!is_array($row)) continue;
                $id = $row[$key] ?? '';
                if (!is_string($id)) continue;
                if (isset($names[$id]) && count($names[$id]) === 1) $row[$key] = $names[$id][0];
            }
            unset($row);
        }
        return $config;
    }

    public static function validate(array $config): array
    {
        $errors = [];
        foreach (self::LISTS as $key) {
            if (!isset($config[$key]) || !is_array($config[$key]) || array_values($config[$key]) !== $config[$key]) {
                $errors[] = "$key must be a JSON list";
            } else {
                foreach ($config[$key] as $row) if (!is_array($row)) $errors[] = "$key contains an invalid row";
            }
        }
        if (isset($config['TargetThrottleList']) && (!is_array($config['TargetThrottleList']) || array_values($config['TargetThrottleList']) !== $config['TargetThrottleList'])) $errors[] = 'TargetThrottleList must be a list';
        if ($errors) return $errors;
        foreach (['ClassList' => ['ClassID', 'ClassName'], 'GroupList' => ['GroupID', 'GroupName'], 'SensorList' => ['ClassID'], 'GroupMembers' => ['GroupName', 'ClassID'], 'GroupDispatch' => ['GroupName'], 'BedroomList' => ['GroupName', 'BedroomDoorClassID']] as $list => $keys) {
            foreach ($config[$list] as $row) foreach ($keys as $key) if (!isset($row[$key]) || !is_string($row[$key])) $errors[] = "$list.$key must be a string";
        }
        foreach (['SensorList' => 'VariableID', 'TamperList' => 'VariableID', 'DispatchTargets' => 'InstanceID', 'GroupDispatch' => 'InstanceID', 'BedroomList' => 'ActiveVariableID'] as $list => $key) {
            foreach ($config[$list] as $row) if (!isset($row[$key]) || (!is_int($row[$key]) && !(is_string($row[$key]) && ctype_digit($row[$key])))) $errors[] = "$list.$key must be an integer ID";
        }
        if ($errors) return array_values(array_unique($errors));
        $classes = []; $groups = []; $targets = []; $classNames = []; $groupIDs = [];
        foreach ($config['ClassList'] as $row) {
            $id = (string)($row['ClassID'] ?? ''); $name = (string)($row['ClassName'] ?? '');
            if ($id === '' || $name === '' || isset($classes[$id]) || isset($classNames[$name])) $errors[] = "Missing/duplicate class identity: $name";
            if (!in_array((int)($row['LogicMode'] ?? -1), [0, 1, 2], true)) $errors[] = "Invalid class logic: $name";
            if ((int)($row['LogicMode'] ?? -1) === 2 && ((int)($row['Threshold'] ?? 0) < 1 || (int)($row['TimeWindow'] ?? 0) < 1)) $errors[] = "Invalid COUNT threshold/window: $name";
            $classes[$id] = true; $classNames[$name] = true;
        }
        foreach ($config['GroupList'] as $row) {
            $id = (string)($row['GroupID'] ?? ''); $name = (string)($row['GroupName'] ?? '');
            if ($id === '' || $name === '' || isset($groups[$name]) || isset($groupIDs[$id])) $errors[] = "Missing/duplicate group identity: $name";
            if (!in_array((int)($row['GroupLogic'] ?? -1), [0, 1], true)) $errors[] = "Invalid group logic: $name";
            $groups[$name] = true; $groupIDs[$id] = true;
        }
        foreach ($config['DispatchTargets'] as $row) {
            $id = (int)($row['InstanceID'] ?? 0);
            if ($id <= 0 || isset($targets[$id])) $errors[] = "Missing/duplicate dispatch target: $id";
            $targets[$id] = true;
        }
        foreach ($config['SensorList'] as $row) {
            if (!isset($classes[$row['ClassID'] ?? ''])) $errors[] = 'Unresolved sensor class: ' . ($row['VariableID'] ?? '?');
            if ((int)($row['VariableID'] ?? 0) <= 0) $errors[] = 'Invalid sensor variable ID';
            if (!in_array((int)($row['TriggerMode'] ?? 0), [0, 1, 2], true)) $errors[] = 'Invalid sensor trigger mode';
            if (!isset($row['Invert']) && !in_array((int)($row['Operator'] ?? -1), [0, 1, 2, 3, 4, 5], true)) $errors[] = 'Invalid comparison operator';
            $source = (int)($row['ComparisonSource'] ?? 0);
            if (!in_array($source, [0, 1], true)) $errors[] = 'Invalid comparison source';
            if ((int)($row['TriggerMode'] ?? 0) !== 1 && $source === 1 && (int)($row['ComparisonVariableID'] ?? 0) <= 0) $errors[] = 'Invalid dynamic comparison reference';
            if ((int)($row['TriggerMode'] ?? 0) !== 0 && (int)($row['PulseSeconds'] ?? 1) < 1) $errors[] = 'Invalid pulse duration';
        }
        foreach ($config['TamperList'] as $row) {
            if ((int)($row['VariableID'] ?? 0) <= 0 || (!isset($row['Invert']) && !in_array((int)($row['Operator'] ?? -1), [0, 1, 2, 3, 4, 5], true))) $errors[] = 'Invalid tamper sensor rule';
            if ((int)($row['ComparisonSource'] ?? 0) === 1 && (int)($row['ComparisonVariableID'] ?? 0) <= 0) $errors[] = 'Invalid tamper comparison reference';
        }
        foreach ($config['GroupMembers'] as $row) {
            if (!isset($groups[$row['GroupName'] ?? ''], $classes[$row['ClassID'] ?? ''])) $errors[] = 'Unresolved group membership: ' . ($row['GroupName'] ?? '?');
        }
        foreach ($config['GroupDispatch'] as $row) {
            if (!isset($groups[$row['GroupName'] ?? ''], $targets[(int)($row['InstanceID'] ?? 0)])) $errors[] = 'Unresolved dispatch route: ' . ($row['GroupName'] ?? '?');
        }
        $bedrooms = [];
        foreach ($config['BedroomList'] as $row) {
            $name = (string)($row['GroupName'] ?? '');
            if (!isset($groups[$name], $classes[$row['BedroomDoorClassID'] ?? '']) || (int)($row['ActiveVariableID'] ?? 0) <= 0 || isset($bedrooms[$name])) $errors[] = "Invalid bedroom reference: $name";
            $bedrooms[$name] = true;
        }
        $bedTarget = (int)($config['BedroomTarget'] ?? 0);
        if ($config['BedroomList'] && !isset($targets[$bedTarget])) $errors[] = 'BedroomTarget is not configured in DispatchTargets';
        return array_values(array_unique($errors));
    }

    /** Compile only dependencies required by this consumer; cached until config or mapping changes. */
    public static function compile(array $config, array $mapping, int $target): array
    {
        $classes = []; $groups = []; $sensors = []; $members = []; $routes = []; $targetEnabled = false;
        foreach ($config['ClassList'] ?? [] as $row) $classes[$row['ClassID']] = $row;
        foreach ($config['GroupList'] ?? [] as $row) $groups[$row['GroupName']] = $row;
        foreach ($config['SensorList'] ?? [] as $row) $sensors[$row['ClassID']][] = $row;
        foreach ($config['GroupMembers'] ?? [] as $row) $members[$row['GroupName']][] = $row['ClassID'];
        foreach ($config['GroupDispatch'] ?? [] as $row) if ((int)$row['InstanceID'] === $target) $routes[$row['GroupName']] = true;
        foreach ($config['DispatchTargets'] ?? [] as $row) if ((int)$row['InstanceID'] === $target) $targetEnabled = self::enabled($row);
        $classNode = static function (string $id) use ($classes, $sensors): array {
            return ['kind' => 'class', 'enabled' => isset($classes[$id]) && self::enabled($classes[$id]), 'logic' => (int)($classes[$id]['LogicMode'] ?? -1), 'rows' => $sensors[$id] ?? []];
        };
        $usableNode = static function (array $node) use (&$usableNode): bool {
            if (!$node['enabled'] || !in_array($node['logic'], [0, 1], true)) return false;
            if ($node['kind'] === 'group') {
                if (!$node['children']) return false;
                foreach ($node['children'] as $child) if (!$usableNode($child)) return false;
            } else {
                if (!$node['rows']) return false;
                foreach ($node['rows'] as $row) if (!self::enabled($row) || (int)($row['TriggerMode'] ?? 0) !== 0) return false;
            }
            return true;
        };
        $plan = ['sources' => [], 'bedrooms' => [], 'errors' => [], 'dependencies' => []];
        $roles = [];
        foreach ($mapping as $item) {
            if (!is_array($item)) { $plan['errors'][] = 'Invalid mapping row'; continue; }
            $src = (string)($item['SourceKey'] ?? ''); $role = (string)($item['LogicalRole'] ?? '');
            $pol = (string)($item['Polarity'] ?? '');
            if ($pol === '') $pol = in_array($role, ['Generic Door', 'Window Contact'], true) ? 'breach' : 'secure';
            if (!in_array($role, self::ROLES, true) || !in_array($pol, ['breach', 'secure'], true) || $src === '') { $plan['errors'][] = "Invalid mapping: $role/$src"; continue; }
            $roles[$role] = ($roles[$role] ?? 0) + 1;
            $node = ['kind' => 'group', 'enabled' => false, 'logic' => 0, 'children' => []];
            if (ctype_digit($src)) {
                $rows = [];
                foreach ($sensors as $cid => $classRows) foreach ($classRows as $row) {
                    if ((int)$row['VariableID'] !== (int)$src) continue;
                    $routed = false;
                    foreach ($members as $gn => $ids) if (isset($routes[$gn]) && self::enabled($groups[$gn]) && in_array($cid, $ids, true)) $routed = true;
                    $row['Active'] = self::enabled($row) && self::enabled($classes[$cid]) && $routed && $targetEnabled;
                    $rows[] = $row;
                }
                // Multiple rules for a raw mapped ID are ambiguous. Group mappings support reuse.
                $node = ['kind' => 'class', 'enabled' => count($rows) === 1, 'logic' => 0, 'rows' => $rows];
            } elseif (isset($groups[$src])) {
                $node['enabled'] = self::enabled($groups[$src]) && isset($routes[$src]) && $targetEnabled;
                $node['logic'] = (int)$groups[$src]['GroupLogic'];
                foreach ($members[$src] ?? [] as $cid) $node['children'][] = $classNode($cid);
            }
            if (!$usableNode($node)) $plan['errors'][] = "Unavailable configured path: $role ($src)";
            $plan['sources'][] = ['key' => $src, 'role' => $role, 'polarity' => $pol, 'node' => $node];
        }
        foreach (self::ROLES as $role) {
            if (!isset($roles[$role])) $plan['errors'][] = "Required role missing: $role";
            elseif ($roles[$role] > 1 && !in_array($role, ['Generic Door', 'Window Contact'], true)) $plan['errors'][] = "Ambiguous role: $role";
        }
        foreach ($config['BedroomList'] ?? [] as $row) {
            if (!$targetEnabled || (int)($config['BedroomTarget'] ?? 0) !== $target || !$usableNode($classNode($row['BedroomDoorClassID']))) $plan['errors'][] = 'Unavailable bedroom path: ' . $row['GroupName'];
            $plan['bedrooms'][] = ['name' => $row['GroupName'], 'switch' => (int)$row['ActiveVariableID'], 'enabled' => $targetEnabled && (int)($config['BedroomTarget'] ?? 0) === $target, 'node' => $classNode($row['BedroomDoorClassID'])];
        }
        $collect = static function (array $node) use (&$collect, &$plan): void {
            foreach ($node['rows'] ?? [] as $row) {
                $plan['dependencies'][(int)$row['VariableID']] = true;
                if ((int)($row['ComparisonSource'] ?? 0) === 1) $plan['dependencies'][(int)($row['ComparisonVariableID'] ?? 0)] = true;
            }
            foreach ($node['children'] ?? [] as $child) $collect($child);
        };
        foreach ($plan['sources'] as $source) $collect($source['node']);
        foreach ($plan['bedrooms'] as $bed) { $collect($bed['node']); $plan['dependencies'][$bed['switch']] = true; }
        unset($plan['dependencies'][0]);
        return $plan;
    }

    /** TRUE evidence survives another unknown input; FALSE is established only when every OR input is known. */
    public static function combine(array $values, int $logic): ?bool
    {
        if (!$values || !in_array($logic, [0, 1], true)) return null;
        if ($logic === 0 && in_array(true, $values, true)) return true;
        if ($logic === 1 && in_array(false, $values, true)) return false;
        if (in_array(null, $values, true)) return null;
        return $logic === 1;
    }

    public static function evaluate(array $plan, callable $read): array
    {
        $cache = []; $errors = $plan['errors'];
        $value = static function (int $id) use (&$cache, $read) {
            if (!array_key_exists($id, $cache)) {
                try { $cache[$id] = $read($id); } catch (Throwable $e) { $cache[$id] = null; }
            }
            return $cache[$id];
        };
        $unknown = false;
        $nodeValue = static function (array $node) use (&$nodeValue, $value, &$unknown): ?bool {
            if (!$node['enabled']) { $unknown = true; return null; }
            $values = [];
            if ($node['kind'] === 'group') {
                foreach ($node['children'] as $child) $values[] = $nodeValue($child);
            } else {
                if (!in_array($node['logic'], [0, 1], true)) { $unknown = true; return null; } // COUNT is not a current-state arming input.
                foreach ($node['rows'] as $row) {
                    if (!self::enabled($row) || (int)($row['TriggerMode'] ?? 0) !== 0) { $values[] = null; continue; }
                    $current = $value((int)$row['VariableID']);
                    if ($current === null) { $values[] = null; continue; }
                    if (isset($row['Invert'])) { $values[] = (bool)($row['Invert'] ? !$current : $current); continue; }
                    $dynamic = (int)($row['ComparisonSource'] ?? 0) === 1;
                    $target = $dynamic ? $value((int)($row['ComparisonVariableID'] ?? 0)) : ($row['ComparisonValue'] ?? null);
                    $compatible = !$dynamic || (is_bool($current) && is_bool($target)) || ((is_int($current) || is_float($current)) && (is_int($target) || is_float($target))) || (is_string($current) && is_string($target));
                    if ($target === null || !$compatible) { $values[] = null; continue; }
                    if (is_bool($current)) $target = $target === 'true' || $target === '1' || $target === 1 || $target === true;
                    elseif (is_float($current) || is_int($current)) { if (!is_numeric($target)) { $values[] = null; continue; } $target = (float)$target; }
                    switch ((int)($row['Operator'] ?? -1)) {
                        case 0: $values[] = $current == $target; break;
                        case 1: $values[] = $current != $target; break;
                        case 2: $values[] = $current > $target; break;
                        case 3: $values[] = $current < $target; break;
                        case 4: $values[] = $current >= $target; break;
                        case 5: $values[] = $current <= $target; break;
                        default: $values[] = null;
                    }
                }
            }
            if (!$values || in_array(null, $values, true)) $unknown = true;
            return self::combine($values, $node['logic']);
        };
        $sources = [];
        foreach ($plan['sources'] as $source) {
            $unknown = false;
            $raw = $nodeValue($source['node']);
            if ($raw === null || $unknown) $errors[] = 'Unknown input: ' . $source['role'] . ' (' . $source['key'] . ')';
            $sources[] = ['key' => $source['key'], 'role' => $source['role'], 'value' => $raw === null ? null : ($source['polarity'] === 'secure' ? $raw : !$raw)];
        }
        $bedrooms = [];
        foreach ($plan['bedrooms'] as $bed) {
            $unknown = false;
            $used = $bed['enabled'] ? $value($bed['switch']) : null;
            $door = $bed['enabled'] ? $nodeValue($bed['node']) : null;
            // Legacy BEDROOM_SYNC casts integer selectors to Boolean: 0=false, nonzero=true.
            // Keep missing values and unsupported types unknown instead of treating them as secure.
            if (!is_bool($used) && !is_int($used)) $used = null;
            if ($used === null || $door === null || $unknown) $errors[] = 'Unknown bedroom input: ' . $bed['name'];
            $bedrooms[] = ['GroupName' => $bed['name'], 'SwitchState' => $used === null ? null : (bool)$used, 'DoorTripped' => $door];
        }
        return ['sources' => $sources, 'bedrooms' => $bedrooms, 'errors' => array_values(array_unique($errors))];
    }
}
