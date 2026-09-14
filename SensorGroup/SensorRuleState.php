<?php
declare(strict_types=1);
require_once __DIR__ . '/../libs/SensorRuleIdentity.php';

trait SensorGroupRuleState
{
    private ?string $sensorRuleRevision = null;
    private array $sensorRuleCounts = [];

    private function SensorRuleKey(array $row): string
    {
        return SensorRuleIdentity::key($row, $this->sensorRuleCounts);
    }

    /** Called only by the serialized evaluator/baseline owner, never by the web page. */
    private function PrepareSensorRuleState(array $config): void
    {
        $revision = $this->ReadAttributeString('ActiveRevision');
        if ($this->sensorRuleRevision === $revision) return;
        $this->sensorRuleCounts = SensorRuleIdentity::counts($config);
        $previous = json_decode($this->ReadAttributeString('SensorRuleManifest'), true) ?: [];
        $manifest = [];
        $last = $this->ReadLastSensorValueMap();
        $conditions = $this->ReadSensorConditionStateMap();
        $pulses = $this->ReadSensorPulseUntilMap();
        foreach ($this->FifoRuntimeRows($config) as $row) {
            $id = (int)$row['VariableID']; $key = $this->SensorRuleKey($row);
            $signature = SensorRuleIdentity::signature($row);
            $manifest[$key] = $signature;
            // A changed single rule must not inherit the old predicate's state.
            if (isset($previous[$key]) && $previous[$key] !== $signature) {
                unset($last[$key], $conditions[$key], $pulses[$key]);
            }
            if (!$this->EvaluationInputAvailable($id)) continue;
            $value = $this->EvaluationValue($id);
            $mode = (int)($row['TriggerMode'] ?? 0);
            if ($mode === 1 && !array_key_exists($key, $last)) {
                $last[$key] = ['type' => $this->GetVariableTypeName($value), 'value' => $value];
            } elseif ($mode === 2 && !array_key_exists($key, $conditions)) {
                $target = null;
                if (isset($row['Invert']) || $this->ResolveSensorComparisonTarget($row, $value, $target)) {
                    $conditions[$key] = isset($row['Invert']) ? (bool)($row['Invert'] ? !$value : $value)
                        : (bool)$this->EvaluateRule($value, $row['Operator'], $target);
                }
            }
        }
        // Drop deleted/disabled rules and ambiguous old variable-only entries. Never clone an old pulse.
        $this->WriteLastSensorValueMap(array_intersect_key($last, $manifest));
        $this->WriteSensorConditionStateMap(array_intersect_key($conditions, $manifest));
        $this->WriteSensorPulseUntilMap(array_intersect_key($pulses, $manifest));
        $this->WriteAttributeString('SensorRuleManifest', json_encode($manifest, JSON_THROW_ON_ERROR));
        $this->sensorRuleRevision = $revision;
    }
}
