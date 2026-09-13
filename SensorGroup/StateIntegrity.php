<?php

declare(strict_types=1);
require_once __DIR__ . '/../libs/AlarmSafety.php';

trait SensorGroupStateIntegrity
{
    private ?array $activeConfigCache = null;

    private function ActiveConfig(): array
    {
        if ($this->activeConfigCache === null) {
            $this->activeConfigCache = json_decode($this->ReadAttributeString('ActiveConfiguration'), true) ?: [];
        }
        return $this->activeConfigCache;
    }

    private function ActiveList(string $name): array
    {
        return $this->ActiveConfig()[$name] ?? [];
    }

    private function IdentityBaseline(): array
    {
        $previous = $this->ActiveConfig();
        $state = json_decode($this->ReadAttributeString('ClassStateAttribute'), true) ?: [];
        foreach (['ClassList' => ['ClassName', 'ClassID', 'IDMap'], 'GroupList' => ['GroupName', 'GroupID', 'GroupIDMap']] as $list => $keys) {
            [$nameKey, $idKey, $mapKey] = $keys;
            $known = array_column($previous[$list] ?? [], null, $nameKey);
            $draft = json_decode($this->ReadAttributeString($list . 'Buffer'), true) ?: [];
            foreach ($draft as $row) {
                if (is_array($row) && !empty($row[$nameKey]) && !empty($row[$idKey]) && !isset($known[$row[$nameKey]])) $known[$row[$nameKey]] = $row;
            }
            foreach ($state[$mapKey] ?? [] as $name => $id) if (!isset($known[$name])) $known[$name] = [$nameKey => $name, $idKey => $id];
            $previous[$list] = array_values($known);
        }
        return $previous;
    }

    private function ConfigurationCandidate(bool $pending = false): array
    {
        $config = [];
        foreach (AlarmSafety::LISTS as $key) {
            $raw = $pending ? IPS_GetProperty($this->InstanceID, $key) : $this->ReadPropertyString($key);
            $config[$key] = json_decode((string)$raw, true);
        }
        $config['TargetThrottleList'] = json_decode((string)($pending ? IPS_GetProperty($this->InstanceID, 'TargetThrottleList') : $this->ReadPropertyString('TargetThrottleList')), true);
        $config['BedroomTarget'] = $pending ? (int)IPS_GetProperty($this->InstanceID, 'BedroomTarget') : $this->ReadPropertyInteger('BedroomTarget');
        $config['MaintenanceMode'] = $pending ? (bool)IPS_GetProperty($this->InstanceID, 'MaintenanceMode') : $this->ReadPropertyBoolean('MaintenanceMode');
        return AlarmSafety::normalize($config, $this->IdentityBaseline());
    }

    private function ReportConfigurationError(string $message): void
    {
        if ($this->ReadAttributeString('ConfigurationError') !== $message) {
            $this->WriteAttributeString('ConfigurationError', $message);
            $this->SetValue('ConfigurationHealth', $message === '' ? 'Healthy' : $message);
            $this->LogMessage($message === '' ? 'Configuration integrity restored.' : $message, $message === '' ? KL_MESSAGE : KL_WARNING);
        }
    }

    private function WriteDraftList(string $name, string $json): void
    {
        $this->WriteAttributeString($name, $json);
        // Custom editors must also stage the corresponding property for ordinary Apply.
        $property = substr($name, 0, -6);
        if (in_array($property, AlarmSafety::LISTS, true)) IPS_SetProperty($this->InstanceID, $property, $json);
        $dirty = json_decode($this->ReadAttributeString('DraftSections'), true) ?: [];
        $dirty[$name] = true;
        $this->WriteAttributeString('DraftSections', json_encode($dirty));
    }

    private function WorkingList(string $name): array
    {
        $dirty = json_decode($this->ReadAttributeString('DraftSections'), true) ?: [];
        $buffer = $name . 'Buffer';
        // Standard form properties are the explicit draft, including intentional empty lists.
        // Custom editors mark their own buffers; an unmarked legacy [] is never authoritative.
        $raw = isset($dirty[$buffer]) && !in_array($name, ['ClassList', 'DispatchTargets', 'GroupDispatch'], true) ? $this->ReadAttributeString($buffer) : IPS_GetProperty($this->InstanceID, $name);
        $list = json_decode((string)$raw, true);
        if (!is_array($list)) return [];
        if (in_array($name, ['ClassList', 'GroupList'], true)) {
            $list = AlarmSafety::normalize([$name => $list], $this->IdentityBaseline())[$name];
            $this->WriteAttributeString($buffer, json_encode($list));
        }
        return $list;
    }

    /** New control API. It does not dispatch events, update COUNT/pulses, or wait on a semaphore. */
    public function GetSafetySnapshot(string $Mapping, int $TargetID, bool $Remember = true): string
    {
        $revision = $this->ReadAttributeString('ActiveRevision');
        if (!is_string($revision) || $revision === '' || $revision === 'updating') return json_encode(['schema' => 1, 'source_id' => $this->InstanceID, 'target_id' => $TargetID, 'valid' => false, 'sources' => [], 'bedrooms' => [], 'errors' => ['Module 1 has no validated active configuration']]);
        $mapping = json_decode($Mapping, true);
        if (!is_array($mapping) || count($mapping) > 128) throw new InvalidArgumentException('Invalid safety mapping');
        $key = hash('sha256', $revision . '|' . $TargetID . '|' . $Mapping);
        $cached = json_decode($this->ReadAttributeString('SafetyPlan'), true);
        if (!is_array($cached) || ($cached['key'] ?? '') !== $key) {
            $cached = ['key' => $key, 'target' => $TargetID, 'maintenance' => (bool)($this->ActiveConfig()['MaintenanceMode'] ?? false), 'plan' => AlarmSafety::compile($this->ActiveConfig(), $mapping, $TargetID)];
            // One bounded consumer cache. The installation has one PSM.
            if ($Remember) $this->WriteAttributeString('SafetyPlan', json_encode($cached));
        }
        $result = AlarmSafety::evaluate($cached['plan'], static function (int $id) {
            return $id > 0 && IPS_VariableExists($id) ? GetValue($id) : null;
        });
        $error = $this->ReadAttributeString('ConfigurationError');
        // A rejected candidate is diagnostic only; the validated active graph is unchanged.
        if ($cached['maintenance']) $result['errors'][] = 'Module 1 maintenance mode';
        // Refuse to combine values from different activation generations.
        if ($this->ReadAttributeString('ActiveRevision') !== $revision) {
            $result = ['sources' => [], 'bedrooms' => [], 'errors' => ['Configuration changed during safety evaluation']];
        }
        return json_encode(['schema' => 1, 'source_id' => $this->InstanceID, 'target_id' => $TargetID, 'revision' => $revision, 'valid' => !$result['errors'], 'configuration_valid' => !$cached['plan']['errors'] && $this->ReadAttributeString('ActiveRevision') === $revision] + $result);
    }

    private function NotifySafetyConsumer(int $trigger, array $alreadySent = []): void
    {
        $cached = json_decode($this->ReadAttributeString('SafetyPlan'), true);
        $target = (int)($cached['target'] ?? ($this->ActiveConfig()['BedroomTarget'] ?? 0));
        if ($target <= 0 || in_array($target, $alreadySent, true) || !IPS_InstanceExists($target)) return;
        if ($trigger > 0 && !isset($cached['plan']['dependencies'][$trigger])) return;
        // This is a control notification to a PSM, never an alarm output to an arbitrary target.
        $instance = IPS_GetInstance($target);
        if (($instance['ModuleInfo']['ModuleID'] ?? '') !== '{D90786C5-5A3E-4B0F-935A-3A3A9D1C9E9A}') return;
        try { IPS_RequestAction($target, 'RefreshSafetyState', 0); }
        catch (Throwable $e) { $this->SendDebug('Safety refresh', $e->getMessage(), 0); }
    }
}
