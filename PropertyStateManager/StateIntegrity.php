<?php

declare(strict_types=1);
require_once __DIR__ . '/../libs/AlarmSafety.php';

trait PropertyStateIntegrity
{
    private const SAFETY_SETTINGS = ['SensorGroupInstanceID', 'DispatchTargetID', 'GroupMapping', 'BedroomDoorPolarity', 'BedroomPresencePolarity', 'ArmingDelayDuration', 'StatePushTargets'];
    private ?array $safetySettingsCache = null;

    private function SafetySetting(string $name)
    {
        if ($this->safetySettingsCache === null) $this->safetySettingsCache = json_decode($this->ReadAttributeString('ActiveSafetySettings'), true) ?: [];
        return $this->safetySettingsCache[$name] ?? (in_array($name, ['SensorGroupInstanceID', 'DispatchTargetID', 'ArmingDelayDuration'], true) ? 0 : '[]');
    }

    private function SafetySettingsCandidate(): array
    {
        $candidate = [];
        foreach (self::SAFETY_SETTINGS as $key) {
            $candidate[$key] = in_array($key, ['SensorGroupInstanceID', 'DispatchTargetID', 'ArmingDelayDuration'], true) ? $this->ReadPropertyInteger($key) : $this->ReadPropertyString($key);
        }
        return $candidate;
    }

    private function ValidateSafetySettings(array $candidate): string
    {
        if ((int)($candidate['DispatchTargetID'] ?? 0) !== $this->InstanceID) return 'DispatchTargetID must identify this Module 2';
        if ((int)($candidate['ArmingDelayDuration'] ?? 0) < 1) return 'Arming delay must be at least one minute';
        if (!in_array($candidate['BedroomDoorPolarity'] ?? '', ['secure', 'breach'], true) || !in_array($candidate['BedroomPresencePolarity'] ?? '', ['used', 'unused'], true)) return 'Invalid bedroom polarity';
        if (!is_array(json_decode((string)($candidate['StatePushTargets'] ?? ''), true))) return 'Invalid state-push target list';
        $source = (int)($candidate['SensorGroupInstanceID'] ?? 0);
        try {
            if ($source <= 0 || !IPS_InstanceExists($source) || !function_exists('MYALARM_GetSafetySnapshot')) return 'Module 1 safety API unavailable';
            // Validation must not replace the active consumer's dependency cache.
            $result = json_decode(MYALARM_GetSafetySnapshot($source, (string)($candidate['GroupMapping'] ?? ''), $this->InstanceID, false), true);
            if (!is_array($result) || ($result['configuration_valid'] ?? false) !== true) return 'Invalid Module 1 source/mapping: ' . implode('; ', array_slice($result['errors'] ?? ['snapshot unavailable'], 0, 8));
        } catch (Throwable $e) { return 'Module 1 configuration validation unavailable'; }
        return '';
    }

    private function ActivateSafetySettings(): bool
    {
        $candidate = $this->SafetySettingsCandidate();
        $error = $this->ValidateSafetySettings($candidate);
        if ($this->ReadAttributeString('SafetyConfigurationError') !== $error) {
            $this->WriteAttributeString('SafetyConfigurationError', $error);
            $this->SetValue('ConfigurationHealth', $error === '' ? 'Healthy' : 'Apply rejected: ' . $error);
            $this->LogMessage($error === '' ? 'Configuration integrity restored.' : 'Apply rejected: ' . $error, $error === '' ? KL_MESSAGE : KL_WARNING);
        }
        if ($error !== '') return false;
        $this->WriteAttributeString('ActiveSafetySettings', json_encode($candidate));
        $this->safetySettingsCache = $candidate;
        return true;
    }

    /** Pull coherent, current LEVEL inputs. Legacy event absence can never authorize a transition. */
    private function ReadSafetyInputs(): array
    {
        $source = $this->SafetySetting('SensorGroupInstanceID');
        $snapshot = null;
        try {
            if ($source > 0 && IPS_InstanceExists($source) && function_exists('MYALARM_GetSafetySnapshot')) {
                $snapshot = json_decode(MYALARM_GetSafetySnapshot($source, $this->SafetySetting('GroupMapping'), $this->InstanceID), true);
            }
        } catch (Throwable $e) { /* A failed synchronous read is unknown, never secure. */ }
        if (!is_array($snapshot) || ($snapshot['schema'] ?? 0) !== 1 || (int)($snapshot['source_id'] ?? 0) !== $source || (int)($snapshot['target_id'] ?? 0) !== $this->InstanceID || !is_array($snapshot['sources'] ?? null) || !is_array($snapshot['bedrooms'] ?? null) || !is_array($snapshot['errors'] ?? null)) {
            $snapshot = ['valid' => false, 'sources' => [], 'bedrooms' => [], 'errors' => ['Module 1 safety snapshot unavailable or invalid']];
        }
        if ($this->SafetySetting('DispatchTargetID') !== $this->InstanceID) {
            $snapshot['valid'] = false;
            $snapshot['errors'][] = 'DispatchTargetID must identify this Module 2 instance';
        }
        // Keep legacy display/export buffers aligned with known current inputs. Unknowns retain
        // their last display value; InputHealth and blocking_reasons explicitly mark them untrusted.
        $mapping = json_decode($this->SafetySetting('GroupMapping'), true) ?: [];
        $sensorSet = array_fill_keys(json_decode($this->ReadAttributeString('ActiveSensors'), true) ?: [], true);
        $groupSet = array_fill_keys(json_decode($this->ReadAttributeString('ActiveGroups'), true) ?: [], true);
        foreach ($snapshot['sources'] as $input) {
            if (!is_bool($input['value'] ?? null)) continue;
            foreach ($mapping as $row) {
                if ((string)($row['SourceKey'] ?? '') !== (string)$input['key'] || ($row['LogicalRole'] ?? '') !== $input['role']) continue;
                $pol = $row['Polarity'] ?? '';
                if ($pol === '') $pol = in_array($input['role'], ['Generic Door', 'Window Contact'], true) ? 'breach' : 'secure';
                $active = $pol === 'secure' ? $input['value'] : !$input['value'];
                if (ctype_digit((string)$input['key'])) {
                    if ($active) $sensorSet[$input['key']] = true; else unset($sensorSet[$input['key']]);
                } else {
                    if ($active) $groupSet[$input['key']] = true; else unset($groupSet[$input['key']]);
                }
            }
        }
        foreach (['ActiveSensors' => array_keys($sensorSet), 'ActiveGroups' => array_keys($groupSet)] as $name => $list) {
            $json = json_encode($list);
            if ($this->ReadAttributeString($name) !== $json) $this->WriteAttributeString($name, $json);
        }
        $oldRooms = array_column(json_decode($this->ReadAttributeString('PresenceMap'), true) ?: [], null, 'GroupName');
        foreach ($snapshot['bedrooms'] as $room) {
            if (is_bool($room['SwitchState'] ?? null) && is_bool($room['DoorTripped'] ?? null)) $oldRooms[$room['GroupName']] = $room;
        }
        if ($snapshot['valid'] ?? false) $oldRooms = array_column($snapshot['bedrooms'], null, 'GroupName');
        $roomsJson = json_encode(array_values($oldRooms));
        if ($this->ReadAttributeString('PresenceMap') !== $roomsJson) $this->WriteAttributeString('PresenceMap', $roomsJson);

        $configurationError = $this->ReadAttributeString('SafetyConfigurationError');
        // Rejected settings are reported separately and cannot invalidate the old active settings.

        $roles = [];
        foreach ($snapshot['sources'] as $input) {
            $role = (string)($input['role'] ?? '');
            $value = $input['value'] ?? null;
            $roles[$role][] = is_bool($value) ? $value : null;
        }
        $inputs = [];
        foreach (AlarmSafety::ROLES as $role) {
            $values = $roles[$role] ?? [];
            // Perimeter groups combine as AND: every configured opening must be secure.
            $inputs[$role] = AlarmSafety::combine($values, 1);
            if (!$values || $inputs[$role] === null || (count($values) > 1 && !in_array($role, ['Generic Door', 'Window Contact'], true))) {
                $snapshot['errors'][] = 'Missing, unknown or ambiguous role: ' . $role;
                if (count($values) > 1 && !in_array($role, ['Generic Door', 'Window Contact'], true)) $inputs[$role] = null;
            }
        }
        $bedOpen = [];
        $usagePolarity = $this->SafetySetting('BedroomPresencePolarity');
        $doorPolarity = $this->SafetySetting('BedroomDoorPolarity');
        if (!in_array($usagePolarity, ['used', 'unused'], true) || !in_array($doorPolarity, ['breach', 'secure'], true)) $snapshot['errors'][] = 'Invalid bedroom polarity';
        foreach ($snapshot['bedrooms'] as $room) {
            $usage = $room['SwitchState'] ?? null; $door = $room['DoorTripped'] ?? null;
            $used = is_bool($usage) ? ($usagePolarity === 'unused' ? !$usage : $usage) : null;
            $open = is_bool($door) ? ($doorPolarity === 'secure' ? !$door : $door) : null;
            $bedOpen[] = AlarmSafety::combine([$used, $open], 1);
        }
        $inputs['BedroomOpen'] = $bedOpen ? AlarmSafety::combine($bedOpen, 0) : false;
        $inputs['valid'] = ($snapshot['valid'] ?? false) === true && !$snapshot['errors'];
        $errors = array_values(array_unique($snapshot['errors']));
        $health = $inputs['valid'] ? 'Healthy' : 'Degraded: ' . implode('; ', array_slice($errors, 0, 12));
        if ($this->GetValue('InputHealth') !== $health) {
            $this->SetValue('InputHealth', $health);
            $this->LogMessage($health, $inputs['valid'] ? KL_MESSAGE : KL_WARNING);
        }
        if ($this->GetValue('MonitoringHealthy') !== $inputs['valid']) $this->SetValue('MonitoringHealthy', $inputs['valid']);
        $this->SetTimerInterval('SafetyRetryTimer', $inputs['valid'] ? 0 : 30000);
        // A compact read-only record for diagnostics; no payload schema changes for Module 3.
        $json = json_encode($inputs);
        if ($this->ReadAttributeString('SafetyInputs') !== $json) $this->WriteAttributeString('SafetyInputs', $json);
        return $inputs;
    }

    public function RefreshSafetyState(): void
    {
        if (!(json_decode($this->ReadAttributeString('ActiveSafetySettings'), true) ?: [])) $this->ActivateSafetySettings();
        $this->EvaluateState();
    }

    private function EvaluateState()
    {
        $inputs = $this->ReadSafetyInputs();
        $state = (int)$this->GetValue('SystemState');
        $next = $state;
        $valid = $inputs['valid'];
        $presence = $inputs['Presence'];
        $bedOpen = $inputs['BedroomOpen'];
        $frontLocked = $inputs['Front Door Lock'];
        $baseLocked = $inputs['Basement Door Lock'];
        $perimeter = AlarmSafety::combine([$frontLocked, $baseLocked, $inputs['Front Door Contact'], $inputs['Basement Door Contact'], $inputs['Generic Door'], $inputs['Window Contact']], 1);
        $opening = $inputs['Generic Door'] === false || $inputs['Window Contact'] === false;
        $unlocked = $frontLocked === false || $baseLocked === false;
        $desired = $presence === true ? 6 : ($presence === false ? 3 : 0);
        $ready = $valid && $perimeter === true && ($presence === false || ($presence === true && $bedOpen === false));
        $pending = $this->ReadAttributeInteger('PendingArmedState');
        $expired = $this->ReadAttributeInteger('DelayExpired') === 1;
        $this->WriteAttributeInteger('RelevantBedroomDoorOpen', $bedOpen === true ? 1 : 0);

        if ($state === 0) {
            $pending = 0;
            if ($ready) $next = 2;
        } elseif ($state === 2) {
            if (!$ready) $next = 0;
            elseif ($expired) $next = $desired;
        } elseif ($state === 3 || $state === 6) {
            // Preserve the existing authorized-disarm priority, but only on trustworthy evidence.
            if ($unlocked || ($state === 6 && $bedOpen === true)) $next = 0;
            elseif ($opening) $next = 9; // A separate unknown input must never mask a valid opening.
            elseif ($ready && $desired !== $state) {
                if ($pending !== $desired) {
                    $pending = $desired;
                    $this->StartSafetyDelay();
                } elseif ($expired) {
                    $next = $desired;
                    $pending = 0;
                }
            } else {
                $pending = 0; // Loss of validity cancels mode change, not existing armed protection.
            }
        } elseif ($state === 9) {
            if ($unlocked || ($presence === true && $bedOpen === true)) $next = 0;
        }
        if ($next !== $state) {
            $this->SetValue('SystemState', $next);
            $this->LogMessage("[PSM-Logic] State change: $state -> $next", KL_MESSAGE);
            if ($next !== 2) $pending = 0;
        }
        if ($next === 2 && $state !== 2) $this->StartSafetyDelay();
        if ($next !== 2 && $pending === 0) {
            $this->SetTimerInterval('DelayTimer', 0);
            $this->WriteAttributeInteger('DelayExpired', 0);
            $this->WriteAttributeInteger('DelayDeadline', 0);
        }
        $this->WriteAttributeInteger('PendingArmedState', $pending);
        $this->SetValue('PendingMode', $pending === 3 ? 'Armed External pending' : ($pending === 6 ? 'Armed Internal pending' : ''));
        $this->MaybePushHouseStateSnapshot();
    }

    private function StartSafetyDelay(): void
    {
        $this->WriteAttributeInteger('DelayExpired', 0);
        $seconds = max(1, $this->SafetySetting('ArmingDelayDuration')) * 60;
        $this->WriteAttributeInteger('DelayDeadline', time() + $seconds);
        $this->SetTimerInterval('DelayTimer', $seconds * 1000);
    }
}
