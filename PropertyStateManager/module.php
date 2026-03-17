<?php

declare(strict_types=1);

class PropertyStateManager extends IPSModule
{
    public function Create()
    {
        // Never delete this line!
        parent::Create();

        // Properties
        $this->RegisterPropertyInteger("SensorGroupInstanceID", 0);
        $this->RegisterPropertyInteger("DispatchTargetID", 0);
        $this->RegisterPropertyString("GroupMapping", "[]");
        $this->RegisterPropertyString("DecisionMap", "[]");
        $this->RegisterPropertyInteger("SyncTimestamp", 0);
        $this->RegisterPropertyInteger("ArmingDelayDuration", 1);
        $this->RegisterPropertyInteger("VaultInstanceID", 0);
        $this->RegisterPropertyString("BedroomDoorPolarity", "breach");
        $this->RegisterPropertyString("StatePushTargets", "[]"); // Module 3 instance IDs
        $this->RegisterAttributeInteger("DelayExpired", 0);


        // Attributes (RAM Buffers)
        $this->RegisterAttributeString("ActiveSensors", "[]");
        $this->RegisterAttributeString("PresenceMap", "[]");
        $this->RegisterAttributeString("ActiveGroups", "[]");
        $this->RegisterAttributeInteger("RelevantBedroomDoorOpen", 0);

        $this->RegisterAttributeString("ImportedConfig", "");   // raw config snapshot from Module 1 (json string)
        $this->RegisterAttributeString("IgnoredSensors", "[]"); // variable IDs to ignore (handled via group-level mapping)
        $this->RegisterAttributeString("SensorCaptionMap", "{}"); // varID(string) -> caption
        $this->RegisterAttributeString("GroupSensorMap", "{}");   // groupName -> [varID(string), ...]
        $this->RegisterPropertyString("ConfigBackupJson", "");
        // Debug Attributes
        $this->RegisterAttributeString("LastPayload", "");
        $this->RegisterAttributeInteger("LastPayloadTime", 0);
        $this->RegisterAttributeString("PayloadHistory", "[]"); // NEW: History Buffer
        $this->RegisterAttributeString("LastPushedHouseStateSnapshot", ""); // normalized snapshot cache for push diffing
        // ---- Sync token: "processed up to" marker (restart-safe ordering comes from Module 1) ----
        $this->RegisterAttributeString("LastProcessedEventEpoch", "0");
        $this->RegisterAttributeInteger("LastProcessedEventSeq", 0);

        // Optional: diagnostics
        $this->RegisterAttributeString("LastSeenEventEpoch", "0");
        $this->RegisterAttributeInteger("LastSeenEventSeq", 0);
        $this->RegisterAttributeInteger("LastTokenMissing", 0); // 1 if last payload had no token

        // Variable Profiles
        // Variable Profiles
        $this->EnsureStateProfile();

        // Variables
        $this->RegisterVariableInteger("SystemState", "System State", "PSM.State", 0);

        // Timers
        $this->RegisterTimer("DelayTimer", 0, 'PSM_HandleTimer($_IPS[\'TARGET\']);');
    }


    private function EnsureStateProfile(): void
    {
        if (!IPS_VariableProfileExists('PSM.State')) {
            IPS_CreateVariableProfile('PSM.State', 1); // Integer
        }

        // Keep associations aligned with the logic engine: 0,2,3,6
        IPS_SetVariableProfileAssociation('PSM.State', 0, "Disarmed", "", -1);
        IPS_SetVariableProfileAssociation('PSM.State', 2, "Exit Delay", "", -1);
        IPS_SetVariableProfileAssociation('PSM.State', 3, "Armed (External)", "", -1);
        IPS_SetVariableProfileAssociation('PSM.State', 6, "Armed (Internal)", "", -1);
        IPS_SetVariableProfileAssociation('PSM.State', 9, "Alarm Triggered!", "", -1);

        // Optional but useful: keep 1 from showing misleading old meaning if it exists somewhere
        IPS_SetVariableProfileAssociation('PSM.State', 1, "Legacy/Unused", "", -1);
    }
    public function ApplyChanges()
    {
        // Never delete this line!
        parent::ApplyChanges();
        $this->EnsureStateProfile();

        // Register the Webhook using the manual helper
        $this->RegisterHook('/hook/psm_logic_' . $this->InstanceID);

        // SYNC: Request current sensor state from Module 1 on startup/change
        // Safeguard: Module 2 must still start even if Module 1 is slow or not ready.
        $sensorGroupID = $this->ReadPropertyInteger("SensorGroupInstanceID");
        if ($sensorGroupID > 0 && @IPS_InstanceExists($sensorGroupID)) {
            if (function_exists('MYALARM_RequestStateSync')) {
                try {
                    @MYALARM_RequestStateSync($sensorGroupID);
                } catch (\Throwable $e) {
                    $this->LogMessage(
                        "[PSM-Apply] Startup sync skipped because Module 1 RequestStateSync failed: " . $e->getMessage(),
                        KL_WARNING
                    );
                }
            }
        }

        // --- Step 1: Import config snapshot + compute IgnoredSensors (no behavior change yet) ---
        $this->WriteAttributeString("ImportedConfig", "");
        $this->WriteAttributeString("IgnoredSensors", "[]");

        if ($sensorGroupID > 0 && @IPS_InstanceExists($sensorGroupID) && function_exists('MYALARM_GetConfiguration')) {

            $configJSON = false;
            try {
                $configJSON = @MYALARM_GetConfiguration($sensorGroupID);
            } catch (\Throwable $e) {
                $this->LogMessage(
                    "[PSM-Apply] Module 1 configuration fetch failed: " . $e->getMessage(),
                    KL_WARNING
                );
            }

            if ($configJSON !== false && $configJSON !== "") {

                $this->WriteAttributeString("ImportedConfig", (string)$configJSON);

                $config = json_decode((string)$configJSON, true);
                if (is_array($config)) {

                    // Identify group-names that are handled on group-level in THIS module (SourceKey is a non-numeric string)
                    $mapping = json_decode($this->ReadPropertyString("GroupMapping"), true);
                    if (!is_array($mapping)) $mapping = [];

                    $groupLevelNames = [];
                    foreach ($mapping as $m) {
                        $role = (string)($m['LogicalRole'] ?? '');
                        $src  = (string)($m['SourceKey'] ?? '');

                        // Group-level mapping: SourceKey is group-name (not a numeric VariableID)
                        if (($role === 'Generic Door' || $role === 'Window Contact') && $src !== '' && !ctype_digit($src)) {
                            $groupLevelNames[$src] = true;
                        }
                    }
                    $groupLevelNames = array_keys($groupLevelNames);

                    // Build IgnoredSensors = member sensors of those group-level groups
                    $ignored = [];

                    if (count($groupLevelNames) > 0) {

                        // Collect ClassIDs that belong to those groups
                        $classIDs = [];
                        foreach (($config['GroupMembers'] ?? []) as $gm) {
                            $gName = (string)($gm['GroupName'] ?? '');
                            if (in_array($gName, $groupLevelNames, true)) {
                                $cid = (string)($gm['ClassID'] ?? '');
                                if ($cid !== '') $classIDs[$cid] = true;
                            }
                        }

                        // Collect VariableIDs of sensors whose ClassID is in those ClassIDs
                        foreach (($config['SensorList'] ?? []) as $s) {
                            $cid = (string)($s['ClassID'] ?? '');
                            if ($cid !== '' && isset($classIDs[$cid])) {
                                $vid = (string)($s['VariableID'] ?? '');
                                if ($vid !== '') $ignored[$vid] = true;
                            }
                        }
                    }

                    // ===================== INSERT START: ignore bedroom-related sensors =====================

                    // 1) Collect bedroom door ClassIDs + bedroom presence VariableIDs from BedroomList
                    $bedroomDoorClassIDs = [];
                    $bedroomPresenceVIDs = [];

                    foreach (($config['BedroomList'] ?? []) as $bed) {
                        $cID = (string)($bed['BedroomDoorClassID'] ?? '');
                        if ($cID !== '') $bedroomDoorClassIDs[$cID] = true;

                        $pVid = (string)($bed['ActiveVariableID'] ?? '');
                        if ($pVid !== '' && ctype_digit($pVid)) $bedroomPresenceVIDs[$pVid] = true;
                    }

                    // 2) Ignore all bedroom presence variables (they are handled via PresenceMap / BEDROOM_SYNC)
                    foreach (array_keys($bedroomPresenceVIDs) as $vid) {
                        $ignored[$vid] = true;
                    }

                    // 3) Ignore all sensors whose ClassID is one of the bedroom door classes
                    if (count($bedroomDoorClassIDs) > 0) {
                        foreach (($config['SensorList'] ?? []) as $s) {
                            $cid = (string)($s['ClassID'] ?? '');
                            if ($cid !== '' && isset($bedroomDoorClassIDs[$cid])) {
                                $vid = (string)($s['VariableID'] ?? '');
                                if ($vid !== '') $ignored[$vid] = true;
                            }
                        }
                    }

                    // ===================== INSERT END =====================

                    $this->WriteAttributeString("IgnoredSensors", json_encode(array_values(array_keys($ignored))));
                }
            }
        }
        // --- Step 1 end ---
    }

    private function RegisterHook($WebHook)
    {
        $ids = IPS_GetInstanceListByModuleID("{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}");

        if (count($ids) > 0) {
            $hooks = json_decode(IPS_GetProperty($ids[0], "Hooks"), true);
            $found = false;

            if (!is_array($hooks)) {
                $hooks = [];
            }

            foreach ($hooks as $index => $hook) {
                if ($hook['Hook'] == $WebHook) {
                    if ($hook['TargetID'] == $this->InstanceID) {
                        return;
                    }
                    $hooks[$index]['TargetID'] = $this->InstanceID;
                    $found = true;
                }
            }
            if (!$found) {
                $hooks[] = ["Hook" => $WebHook, "TargetID" => $this->InstanceID];
            }
            IPS_SetProperty($ids[0], "Hooks", json_encode($hooks));
            IPS_ApplyChanges($ids[0]);
        }
    }

    public function HandleTimer()
    {
        // Mark that the delay has actually expired (not cancelled)
        $this->WriteAttributeInteger("DelayExpired", 1);

        // Stop timer (it has fired)
        $this->SetTimerInterval("DelayTimer", 0);

        // Single rules engine decides next state
        $this->EvaluateState();
    }
    /**
     * This is called by the UI button. 
     * Simply calling it forces IP-Symcon to reload GetConfigurationForm.
     */
    public function UI_Refresh()
    {
        // 0) Keep existing behavior: trigger Apply button
        $this->UpdateFormField("SyncTimestamp", "value", time());

        // 1) Build UI caches from Module 1 configuration (DISPLAY ONLY)
        $sensorGroupId = $this->ReadPropertyInteger("SensorGroupInstanceID");
        if ($sensorGroupId <= 0 || !@IPS_InstanceExists($sensorGroupId) || !function_exists('MYALARM_GetConfiguration')) {
            $this->LogMessage("[PSM-UI] Refresh: cannot load Module 1 config (missing instance or function).", KL_WARNING);
            return;
        }

        $configJSON = @MYALARM_GetConfiguration($sensorGroupId);
        $config = json_decode((string)$configJSON, true);
        if (!is_array($config)) {
            $this->LogMessage("[PSM-UI] Refresh: Module 1 config JSON invalid.", KL_WARNING);
            return;
        }

        // 2) Build SensorCaptionMap: varID(string) -> "GrandParent > Parent > Name (ID)"
        $captionMap = [];
        foreach (($config['SensorList'] ?? []) as $sensor) {
            $vid = (int)($sensor['VariableID'] ?? 0);
            if ($vid <= 0) continue;

            $name = IPS_ObjectExists($vid) ? IPS_GetName($vid) : "Unknown";
            $gp   = (string)($sensor['GrandParentName'] ?? '?');
            $p    = (string)($sensor['ParentName'] ?? '?');

            $captionMap[(string)$vid] = sprintf("%s > %s > %s (%d)", $gp, $p, $name, $vid);
        }

        // 3) Build GroupSensorMap for THIS target: groupName -> [varID(string)...]
        $targetID = $this->ReadPropertyInteger("DispatchTargetID");

        // 3a) groups dispatched to this target
        $targetGroups = [];
        foreach (($config['GroupDispatch'] ?? []) as $gd) {
            if ((int)($gd['InstanceID'] ?? 0) === (int)$targetID) {
                $gn = (string)($gd['GroupName'] ?? '');
                if ($gn !== '') $targetGroups[$gn] = true;
            }
        }

        // 3b) classes in those groups
        $classesByGroup = []; // groupName -> [classID...]
        foreach (($config['GroupMembers'] ?? []) as $gm) {
            $gn  = (string)($gm['GroupName'] ?? '');
            $cid = (string)($gm['ClassID'] ?? '');
            if ($gn === '' || $cid === '') continue;
            if (!isset($targetGroups[$gn])) continue;

            if (!isset($classesByGroup[$gn])) $classesByGroup[$gn] = [];
            $classesByGroup[$gn][$cid] = true;
        }

        // 3c) sensors in those classes
        $groupSensorMap = []; // groupName -> [varID(string)...]
        foreach (($config['SensorList'] ?? []) as $sensor) {
            $vid = (int)($sensor['VariableID'] ?? 0);
            $cid = (string)($sensor['ClassID'] ?? '');
            if ($vid <= 0 || $cid === '') continue;

            foreach ($classesByGroup as $gn => $classSet) {
                if (isset($classSet[$cid])) {
                    if (!isset($groupSensorMap[$gn])) $groupSensorMap[$gn] = [];
                    $groupSensorMap[$gn][] = (string)$vid;
                }
            }
        }

        // 3d) de-duplicate lists
        foreach ($groupSensorMap as $gn => $list) {
            $groupSensorMap[$gn] = array_values(array_unique($list));
        }

        // 4) Save caches
        $this->WriteAttributeString("SensorCaptionMap", json_encode($captionMap));
        $this->WriteAttributeString("GroupSensorMap", json_encode($groupSensorMap));

        $this->LogMessage(
            "[PSM-UI] Refresh: cached captions=" . count($captionMap) . " | groups=" . count($groupSensorMap),
            KL_MESSAGE
        );
    }

    protected function ProcessHookData()
    {
        $vaultID = $this->ReadPropertyInteger("VaultInstanceID");
        if ($vaultID > 0 && @IPS_InstanceExists($vaultID)) {
            if (function_exists('SEC_IsPortalAuthenticated')) {
                if (!SEC_IsPortalAuthenticated($vaultID)) {
                    $currentUrl = $_SERVER['REQUEST_URI'] ?? '';
                    $loginUrl = "/hook/secrets_" . $vaultID . "?portal=1&return=" . urlencode($currentUrl);
                    header("Location: " . $loginUrl);
                    exit;
                }
            }
        }

        // NEW: Handle Manual Sync Request from HTML Button
        if (isset($_GET['sync'])) {
            $sensorGroupID = $this->ReadPropertyInteger("SensorGroupInstanceID");
            if ($sensorGroupID > 0 && @IPS_InstanceExists($sensorGroupID)) {
                if (function_exists('MYALARM_RequestStateSync')) {
                    @MYALARM_RequestStateSync($sensorGroupID);
                }
            }
            // Redirect to clear query parameter
            $cleanUrl = strtok($_SERVER['REQUEST_URI'], '?');
            header("Location: " . $cleanUrl);
            exit;
        }

        // ... (Existing Logic Calculation) ...
        $bits = $this->GetCurrentBitmask();
        $targetState = $this->GetValue("SystemState");
        $displayState = $this->GetStateName($targetState);

        $isDelayState = ($targetState === 2);
        $remainingSeconds = 0;

        if ($isDelayState) {
            $varID = $this->GetIDForIdent("SystemState");
            $varInfo = IPS_GetVariable($varID);
            $lastUpdate = $varInfo['VariableUpdated'];
            $durationSeconds = $this->ReadPropertyInteger("ArmingDelayDuration") * 60;
            $remainingSeconds = ($lastUpdate + $durationSeconds) - time();
            if ($remainingSeconds < 0) $remainingSeconds = 0;
        }

        $activeSensors = json_decode($this->ReadAttributeString("ActiveSensors"), true);
        if (!is_array($activeSensors)) $activeSensors = [];

        $mapping = json_decode($this->ReadPropertyString("GroupMapping"), true);
        if (!is_array($mapping)) $mapping = [];

        $mappedIDs = array_column($mapping, 'SourceKey');
        $unmappedSensors = array_diff($activeSensors, $mappedIDs);
        // Bedroom details for UI (display only; does not change logic)
        $presenceMap = json_decode($this->ReadAttributeString("PresenceMap"), true);
        if (!is_array($presenceMap)) $presenceMap = [];

        $bedrooms = [];
        $bedroomPolarity = (string)$this->ReadPropertyString("BedroomDoorPolarity");
        if ($bedroomPolarity === '') $bedroomPolarity = 'breach'; // keep old behavior if missing

        $bedrooms = [];
        foreach ($presenceMap as $room) {
            $name       = (string)($room['GroupName'] ?? 'Unknown');
            $roomUsed   = (bool)($room['SwitchState'] ?? false);
            $doorTrip   = (bool)($room['DoorTripped'] ?? false);
            $doorOpen   = ($bedroomPolarity === 'breach') ? $doorTrip : !$doorTrip;

            $bedrooms[] = [
                'name'      => $name,
                'used'      => $roomUsed,
                'doorOpen'  => $doorOpen,
                'blocking'  => ($roomUsed && $doorOpen) // Option A gating
            ];
        }
        // NEW: Hide ignored member-sensors (handled via group-level mapping) from "unmapped"
        $ignoredSensors = json_decode($this->ReadAttributeString("IgnoredSensors"), true);
        if (is_array($ignoredSensors) && count($ignoredSensors) > 0) {
            $unmappedSensors = array_diff($unmappedSensors, $ignoredSensors);
        }
        // Dashboard ON/OFF texts (polarity-aware for Window/Generic)
        $bitText = [
            0 => ['on' => 'Locked',       'off' => 'Unlocked'],
            1 => ['on' => 'Closed',       'off' => 'Open'],
            2 => ['on' => 'Locked',       'off' => 'Unlocked'],
            3 => ['on' => 'Someone Home', 'off' => 'Nobody Home'],
            4 => ['on' => 'Running',      'off' => 'Inactive'],
            5 => ['on' => 'Not Disarmed', 'off' => 'Disarmed'],
            6 => ['on' => 'Open',         'off' => 'Closed'],
            7 => ['on' => 'Closed',       'off' => 'Open'],
            8 => ['on' => 'Open',         'off' => 'Closed'], // will be adjusted below
            9 => ['on' => 'Open',         'off' => 'Closed'], // will be adjusted below
        ];

        // Decide label meaning for bit 8/9 based on mapping polarity.
        // IMPORTANT: Prefer GROUP-level rows (SourceKey is non-numeric) because those define group-level handling.
        // Default: current convention (active means breach => ON=Open)
        $windowPolarity  = 'breach';
        $genericPolarity = 'breach';

        // 1) First pass: prefer group-level mapping rows (SourceKey is group-name, not numeric)
        foreach ($mapping as $m) {
            $role = (string)($m['LogicalRole'] ?? '');
            $pol  = (string)($m['Polarity'] ?? '');
            $src  = (string)($m['SourceKey'] ?? '');
            if ($pol === '' || $src === '') continue;

            if (!ctype_digit($src)) {
                if ($role === 'Window Contact') $windowPolarity  = $pol;   // 'secure' or 'breach'
                if ($role === 'Generic Door')   $genericPolarity = $pol;   // 'secure' or 'breach'
            }
        }

        // 2) Fallback: if no group-level row exists for a role, allow any row to define it
        if ($windowPolarity !== 'secure') {
            foreach ($mapping as $m) {
                if ((string)($m['LogicalRole'] ?? '') === 'Window Contact' && (string)($m['Polarity'] ?? '') === 'secure') {
                    $windowPolarity = 'secure';
                    break;
                }
            }
        }
        if ($genericPolarity !== 'secure') {
            foreach ($mapping as $m) {
                if ((string)($m['LogicalRole'] ?? '') === 'Generic Door' && (string)($m['Polarity'] ?? '') === 'secure') {
                    $genericPolarity = 'secure';
                    break;
                }
            }
        }

        // If polarity is "secure", then ON means Closed (secure)
        if ($windowPolarity === 'secure')  $bitText[8] = ['on' => 'Closed', 'off' => 'Open'];
        if ($genericPolarity === 'secure') $bitText[9] = ['on' => 'Closed', 'off' => 'Open'];
        // Build semantic UI texts (Option A): server decides what to display per bit.
        // This is DISPLAY ONLY; it does NOT change any rules/state transitions.
        $uiBits = [
            0 => ['text' => ($bits & (1 << 0)) ? 'Locked' : 'Unlocked', 'ok' => (($bits & (1 << 0)) !== 0)],
            1 => ['text' => ($bits & (1 << 1)) ? 'Closed' : 'Open',     'ok' => (($bits & (1 << 1)) !== 0)],
            2 => ['text' => ($bits & (1 << 2)) ? 'Locked' : 'Unlocked', 'ok' => (($bits & (1 << 2)) !== 0)],
            3 => ['text' => ($bits & (1 << 3)) ? 'Someone Home' : 'Nobody Home', 'ok' => true],
            4 => ['text' => ($bits & (1 << 4)) ? 'Running' : 'Inactive', 'ok' => true],
            5 => ['text' => ($bits & (1 << 5)) ? 'Not Disarmed' : 'Disarmed', 'ok' => true],
            6 => ['text' => ($bits & (1 << 6)) ? 'Open' : 'Closed',     'ok' => (($bits & (1 << 6)) === 0)],
            7 => ['text' => ($bits & (1 << 7)) ? 'Closed' : 'Open',     'ok' => (($bits & (1 << 7)) !== 0)],
        ];

        // IMPORTANT: For group-level Window/Generic (bits 8/9), do NOT derive meaning from bits+polarity,
        // because   ActiveGroups is effectively a "breach list" (group becomes active when something opens).
        // So: bit ON => Open (breach), bit OFF => Closed (secure), always.
        $uiBits[8] = ['text' => ($bits & (1 << 8)) ? 'Open' : 'Closed', 'ok' => (($bits & (1 << 8)) === 0)];
        $uiBits[9] = ['text' => ($bits & (1 << 9)) ? 'Open' : 'Closed', 'ok' => (($bits & (1 << 9)) === 0)];
        // -------------------- NEW: Perimeter details (which group + which sensors are open) --------------------
        // Display-only, authoritative: recompute "open" from Module 1 config + live GetValue().
        // This avoids ActiveSensors inversion / history issues.

        $perimeterDetails = []; // array of {group, role, sensors:[caption...]}

        $sensorGroupID = $this->ReadPropertyInteger("SensorGroupInstanceID");
        $targetID      = $this->ReadPropertyInteger("DispatchTargetID");

        $config = null;
        if ($sensorGroupID > 0 && @IPS_InstanceExists($sensorGroupID) && function_exists('MYALARM_GetConfiguration')) {
            $cfgJson = @MYALARM_GetConfiguration($sensorGroupID);
            if ($cfgJson !== false) {
                $tmp = json_decode($cfgJson, true);
                if (is_array($tmp)) $config = $tmp;
            }
        }

        // helper: evaluate Module-1 style compare (Operator + ComparisonValue)
        $evalTrip = function ($currentValue, int $op, $cmpRaw): bool {
            // normalize cmp to number if possible, else string
            $cmp = $cmpRaw;
            if (is_string($cmpRaw) && is_numeric(str_replace(',', '.', $cmpRaw))) {
                $cmp = (float)str_replace(',', '.', $cmpRaw);
            }
            // normalize current similarly
            $cur = $currentValue;
            if (is_string($currentValue) && is_numeric(str_replace(',', '.', $currentValue))) {
                $cur = (float)str_replace(',', '.', $currentValue);
            }

            switch ($op) {
                case 0:
                    return ($cur == $cmp);  // Equal
                case 1:
                    return ($cur != $cmp);  // Not Equal
                case 2:
                    return ($cur >  $cmp);  // >
                case 3:
                    return ($cur <  $cmp);  // <
                case 4:
                    return ($cur >= $cmp);  // >=
                case 5:
                    return ($cur <= $cmp);  // <=
                default:
                    return false;
            }
        };

        if (is_array($config)) {
            // 1) Find groups routed to THIS module 2 instance (target)
            $targetGroups = [];
            foreach ($config['GroupDispatch'] ?? [] as $gd) {
                if ((int)($gd['InstanceID'] ?? 0) === (int)$targetID) {
                    $targetGroups[] = (string)($gd['GroupName'] ?? '');
                }
            }
            $targetGroups = array_values(array_filter($targetGroups));

            // 2) Build group -> classIDs
            $groupClasses = [];
            foreach ($config['GroupMembers'] ?? [] as $gm) {
                $gName = (string)($gm['GroupName'] ?? '');
                $cID   = (string)($gm['ClassID'] ?? '');
                if ($gName === '' || $cID === '') continue;
                if (!in_array($gName, $targetGroups, true)) continue;
                $groupClasses[$gName][] = $cID;
            }

            // 3) For each mapping row that is group-level Window/Generic, evaluate member sensors live
            foreach ($mapping as $m) {
                $src  = (string)($m['SourceKey'] ?? '');
                $role = (string)($m['LogicalRole'] ?? '');

                if ($src === '' || ctype_digit($src)) continue; // group-level only
                if ($role !== 'Window Contact' && $role !== 'Generic Door') continue;

                $classIDs = $groupClasses[$src] ?? [];
                if (!is_array($classIDs) || count($classIDs) === 0) continue;

                $captions = [];

                foreach ($config['SensorList'] ?? [] as $s) {
                    $vid = (int)($s['VariableID'] ?? 0);
                    $cID = (string)($s['ClassID'] ?? '');
                    if ($vid <= 0 || $cID === '') continue;
                    if (!in_array($cID, $classIDs, true)) continue;
                    if (!IPS_VariableExists($vid)) continue;

                    $op  = (int)($s['Operator'] ?? 0);
                    $cmp = $s['ComparisonValue'] ?? null;
                    $cur = GetValue($vid);

                    // "tripped" as Module 1 would interpret it
                    $isTripped = $evalTrip($cur, $op, $cmp);

                    // UI inversion only for these perimeter roles (display-only)
                    if ($role === 'Window Contact' || $role === 'Generic Door') {
                        $isTripped = !$isTripped;
                    }

                    if ($isTripped) {
                        $name = IPS_GetName($vid);
                        $cap  = sprintf(
                            "%s > %s > %s (%d)",
                            $s['GrandParentName'] ?? '?',
                            $s['ParentName'] ?? '?',
                            $name,
                            $vid
                        );
                        $captions[] = $cap;
                    }
                }

                // Only show the group if something is actually open
                if (count($captions) > 0) {
                    $perimeterDetails[] = [
                        'group'   => $src,
                        'role'    => $role,
                        'sensors' => $captions
                    ];
                }
            }
        }
        // -------------------- END NEW: Perimeter details --------------------
        // API Mode
        if (isset($_GET['api'])) {
            header("Content-Type: application/json");
            echo json_encode([
                'bits' => $bits,
                'state' => $displayState,
                'timer' => $remainingSeconds,
                'showTimer' => $isDelayState,
                'unmapped' => array_values($unmappedSensors),
                'bitText' => $bitText,
                'uiBits' => $uiBits,
                'bedrooms' => $bedrooms,
                'perimeterDetails' => $perimeterDetails,
                'last_processed_event_epoch' => (string)$this->ReadAttributeString('LastProcessedEventEpoch'),
                'last_processed_event_seq'   => (int)$this->ReadAttributeInteger('LastProcessedEventSeq'),
                'last_token_missing'         => (int)$this->ReadAttributeInteger('LastTokenMissing')
            ]);
            return;
        }

        echo "<html><head>
              <meta name='viewport' content='width=device-width, initial-scale=1'>
              <style>
                body { font-family: sans-serif; background: #111; color: #eee; padding: 20px; }
                .bit-row { display: flex; justify-content: space-between; padding: 10px; border-bottom: 1px solid #333; }
                .active { color: #4caf50; font-weight: bold; }
                .inactive { color: #f44336; }
                .warning { color: #ffeb3b; font-weight: bold; margin-top: 20px; border: 1px solid #ffeb3b; padding: 10px; display: none; }
                .timer { background: #e91e63; color: white; padding: 15px; text-align: center; font-size: 1.2em; font-weight: bold; border-radius: 5px; margin-bottom: 20px; display: none; }
                .header { font-size: 1.5em; margin-bottom: 20px; color: #2196f3; }
                .footer { margin-top: 30px; padding: 20px; background: #222; border-radius: 8px; text-align: center; }
                .btn-sync { float: right; background: #2196f3; color: white; border: none; padding: 8px 15px; border-radius: 4px; cursor: pointer; text-decoration: none; font-size: 0.6em; vertical-align: middle; }
                .panel { border: 1px solid #333; border-radius: 8px; padding: 12px; margin: 14px 0; background: #151515; }
            .panel-title { font-size: 1.05em; font-weight: 600; color: #9ecbff; margin-bottom: 8px; }
            .panel .bit-row:last-child { border-bottom: none; }
              </style>
              <script>
                function updateDashboard() {
                    fetch('?api=1&t=' + Date.now())
                        .then(response => response.json())
                        .then(data => {
                            document.getElementById('stateText').innerText = data.state;
                            // Updated loop to 10 to include Generic Door Bit
                            for (let i = 0; i < 10; i++) {
                                let el = document.getElementById('bit_' + i);
                                if (el) {
                                    // Prefer server-provided semantic UI (Option A)
                                    if (data.uiBits && data.uiBits[i]) {
                                        el.innerText = data.uiBits[i].text;
                                        el.className = data.uiBits[i].ok ? 'active' : 'inactive';
                                        continue;
                                    }

                                    // Fallback (older servers): keep previous behavior
                                    let isActive = (data.bits & (1 << i));
                                    const fallback = {
                                    0: { on: 'Locked',       off: 'Unlocked' },
                                    1: { on: 'Closed',       off: 'Open'     },
                                    2: { on: 'Locked',       off: 'Unlocked' },
                                    3: { on: 'Someone Home', off: 'Nobody Home' },
                                    4: { on: 'Running',      off: 'Inactive' },
                                    5: { on: 'Not Disarmed', off: 'Disarmed' },
                                    6: { on: 'Open',         off: 'Closed'   },
                                    7: { on: 'Closed',       off: 'Open'     },
                                    8: { on: 'Open',         off: 'Closed'   },
                                    9: { on: 'Open',         off: 'Closed'   }
                                    };

                                    const txt = (data.bitText && data.bitText[i]) ? data.bitText[i] : (fallback[i] || { on: 'ON', off: 'OFF' });
                                    el.innerText = isActive ? txt.on : txt.off;
                                    el.className = isActive ? 'active' : 'inactive';
                                }
                            }
                            let timerBox = document.getElementById('timerBox');
                            if (data.showTimer && data.timer > 0) {
                                timerBox.style.display = 'block';
                                timerBox.innerText = 'Arming in ' + Math.ceil(data.timer) + ' seconds...';
                            } else {
                                timerBox.style.display = 'none';
                            }
                                // Bedroom detail rendering (Option A: blocking if used && doorOpen)
                            let bd = document.getElementById('bedroomDetails');
                            if (bd) {
                                if (data.bedrooms && data.bedrooms.length > 0) {
                                    const lines = data.bedrooms.map(r => {
                                        const status = r.used ? (r.doorOpen ? 'BLOCKING (used + door open)' : 'OK (used + door closed)') : (r.doorOpen ? 'Bypassed (unused + door open)' : 'Bypassed (unused + door closed)');
                                        const icon = r.blocking ? '🚫' : (r.used ? '✅' : '➖');
                                        return icon + ' ' + r.name + ': ' + status;
                                    });
                                    bd.innerHTML = '<strong>Bedrooms:</strong><br>' + lines.join('<br>');
                                } else {
                                    bd.innerHTML = '<strong>Bedrooms:</strong><br>No bedroom data (no BEDROOM_SYNC received yet).';
                                }
                            }
                                // Perimeter detail rendering: show which group + which sensors are responsible
                            let pd = document.getElementById('perimeterDetails');
                            if (pd) {
                                if (data.perimeterDetails && data.perimeterDetails.length > 0) {
                                    const lines = [];
                                    data.perimeterDetails.forEach(g => {
                                        lines.push('🚪 ' + g.group + ' (' + g.role + '):');
                                        if (g.sensors && g.sensors.length > 0) {
                                            g.sensors.forEach(s => lines.push('&nbsp;&nbsp;• ' + s));
                                        }
                                    });
                                    pd.innerHTML = '<strong>Perimeter Blocking:</strong><br>' + lines.join('<br>');
                                } else {
                                    pd.innerHTML = '<strong>Perimeter Blocking:</strong><br>None.';
                                }
                            }
                            let warnBox = document.getElementById('warnBox');
                            if (data.unmapped.length > 0) {
                                warnBox.style.display = 'block';
                                warnBox.innerHTML = '⚠️ Unmapped Sensors: ' + data.unmapped.join(', ');
                            } else {
                                warnBox.style.display = 'none';
                            }
                        })
                        .catch(err => console.error('API Error:', err));
                }
                setInterval(updateDashboard, 2000);
                window.onload = updateDashboard;
              </script>
              </head><body>";

        // HEADER with Sync Button
        echo "<div class='header'>
                Logic Analysis 
                <a href='?sync=1' class='btn-sync'>↻ Sync</a>
              </div>";

        echo "<div id='timerBox' class='timer'></div>";

        echo "<h3>Sensor Status</h3>";

        $labels = [
            "Front Door Lock",        // Bit 0
            "Front Door Contact",     // Bit 1
            "Basement Door Lock",     // Bit 2
            "Presence Detected",      // Bit 3
            "Delay Timer Active",     // Bit 4
            "System Currently Armed", // Bit 5
            "Bedroom Door Open",      // Bit 6
            "Basement Door Contact",  // Bit 7
            "Window Open",            // Bit 8
            "Generic Door Open"       // Bit 9 (NEW)
        ];

        // Grouped Panels (display only; keeps bit_0..bit_9 IDs unchanged)

        // Entrance Doors and Locks: 0,1,2,7
        echo "<div class='panel'><div class='panel-title'>Entrance Doors and Locks</div>";
        foreach ([0, 1, 2, 7] as $i) {
            echo "<div class='bit-row'>
            <span>Bit $i: " . ($labels[$i] ?? "Bit $i") . "</span>
            <span id='bit_$i' class='inactive'>...</span>
          </div>";
        }
        echo "</div>";

        // Presence: 3
        echo "<div class='panel'><div class='panel-title'>Presence</div>";
        foreach ([3] as $i) {
            echo "<div class='bit-row'>
            <span>Bit $i: " . ($labels[$i] ?? "Bit $i") . "</span>
            <span id='bit_$i' class='inactive'>...</span>
          </div>";
        }
        echo "</div>";

        // Bedroom Doors: 6
        echo "<div class='panel'><div class='panel-title'>Bedroom Doors</div>";
        foreach ([6] as $i) {
            echo "<div class='bit-row'>
            <span>Bit $i: " . ($labels[$i] ?? "Bit $i") . "</span>
            <span id='bit_$i' class='inactive'>...</span>
          </div>";
        }
        echo "<div id='bedroomDetails' style='margin-top:10px; font-size:0.95em; line-height:1.35;'></div>";
        echo "</div>";

        // Generic Doors and Windows: 8,9
        echo "<div id='perimeterDetails' style='margin-top:10px; font-size:0.95em; line-height:1.35;'></div>";
        echo "<div class='panel'><div class='panel-title'>Generic Doors and Windows</div>";
        foreach ([8, 9] as $i) {
            echo "<div class='bit-row'>
        <span>Bit $i: " . ($labels[$i] ?? "Bit $i") . "</span>
        <span id='bit_$i' class='inactive'>...</span>
        </div>";
        }
        echo "<div id='perimeterDetails' style='margin-top:10px; font-size:0.95em; line-height:1.35;'></div>";
        echo "</div>";

        // System: 4,5 (recommended so they don’t “float” ungrouped)
        echo "<div class='panel'><div class='panel-title'>System</div>";
        foreach ([4, 5] as $i) {
            echo "<div class='bit-row'>
            <span>Bit $i: " . ($labels[$i] ?? "Bit $i") . "</span>
            <span id='bit_$i' class='inactive'>...</span>
          </div>";
        }
        echo "</div>";

        echo "<div id='warnBox' class='warning'></div>";

        echo "<div class='footer'>
                <strong>System State:</strong><br>
                <span id='stateText' style='font-size: 2em; color: #ff9800;'>Loading...</span>
              </div>";
        echo "</body></html>";
    }

    private function GetStateName(int $id)
    {
        $profiles = IPS_GetVariableProfile("PSM.State");
        foreach ($profiles['Associations'] as $assoc) {
            if ($assoc['Value'] == $id) return $assoc['Name'];
        }
        return "Unknown State";
    }
    public function GetActiveSensorList()
    {
        $list = json_decode($this->ReadAttributeString("ActiveSensors"), true);
        return json_encode($list ?: []);
    }
    protected function GetCurrentBitmask()
    {
        $mapping       = json_decode($this->ReadPropertyString("GroupMapping"), true);
        $activeSensors = json_decode($this->ReadAttributeString("ActiveSensors"), true);
        $activeGroups  = json_decode($this->ReadAttributeString("ActiveGroups"), true);
        $presenceMap   = json_decode($this->ReadAttributeString("PresenceMap"), true);

        $bits = 0;

        foreach ($mapping as $item) {
            $isTripped = in_array($item['SourceKey'], $activeSensors) || in_array($item['SourceKey'], $activeGroups);

            // --- Polarity handling (display only) ---
            $role     = (string)($item['LogicalRole'] ?? '');
            $polarity = (string)($item['Polarity'] ?? '');

            // Default polarity by role (keeps existing behavior if Polarity is missing)
            if ($polarity === '') {
                if ($role === 'Window Contact' || $role === 'Generic Door') {
                    $polarity = 'breach'; // current convention: active means open
                } else {
                    $polarity = 'secure'; // locks/contacts/presence: active means secure/true
                }
            }

            // What the RAW signal currently means by role
            $rawMeans = ($role === 'Window Contact' || $role === 'Generic Door') ? 'breach' : 'secure';

            // If user polarity differs from raw meaning, invert
            $isOn = ($polarity === $rawMeans) ? $isTripped : !$isTripped;
            // --- end polarity handling ---

            switch ($role) {
                case 'Front Door Lock':
                    if ($isOn) $bits |= (1 << 0);
                    break;
                case 'Front Door Contact':
                    if ($isOn) $bits |= (1 << 1);
                    break;
                case 'Basement Door Lock':
                    if ($isOn) $bits |= (1 << 2);
                    break;
                case 'Presence':
                    // Presence is not a secure/breach sensor, but polarity default keeps current display
                    if ($isOn) $bits |= (1 << 3);
                    break;
                case 'Basement Door Contact':
                    if ($isOn) $bits |= (1 << 7);
                    break;
                case 'Window Contact':
                    // Polarity-aware + group-level-aware
                    $pol = (string)($item['Polarity'] ?? 'breach');

                    // If SourceKey is a group-name, ActiveGroups usually means "breach active" (not "secure").
                    // For secure polarity we invert for group-level entries.
                    $src = (string)($item['SourceKey'] ?? '');
                    $isGroupKey = ($src !== '' && !ctype_digit($src));

                    $bitOn = $isTripped;
                    if ($isGroupKey && $pol === 'secure') {
                        $bitOn = !$isTripped;
                    }

                    if ($bitOn) $bits |= (1 << 8);
                    break;

                case 'Generic Door':
                    // Polarity-aware + group-level-aware
                    $pol = (string)($item['Polarity'] ?? 'breach');

                    $src = (string)($item['SourceKey'] ?? '');
                    $isGroupKey = ($src !== '' && !ctype_digit($src));

                    $bitOn = $isTripped;
                    if ($isGroupKey && $pol === 'secure') {
                        $bitOn = !$isTripped;
                    }

                    if ($bitOn) $bits |= (1 << 9);
                    break;
            }
        }

        // Bit 6: Relevant Bedroom Door Open
        // IMPORTANT: Bit 3 (Presence) must come only from the mapped Presence role above.
        // The relevance decision is made by EvaluateState() and published via attribute,
        // so the UI/bitmask does not replicate the business logic.
        if ((int)$this->ReadAttributeInteger("RelevantBedroomDoorOpen") === 1) {
            $bits |= (1 << 6);
        }

        // Bit 4: Timer
        if ($this->GetTimerInterval("DelayTimer") > 0) $bits |= (1 << 4);

        // Bit 5: Feedback
        if ($this->GetValue("SystemState") > 0) $bits |= (1 << 5);

        return $bits;
    }


    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {

            case 'ReceivePayload':
                $this->ReceivePayload((string)$Value);
                break;

            case 'UI_ExportConfig':
                $json = $this->ExportConfiguration();

                // 1) show in UI
                $this->UpdateFormField('ConfigBackupJson', 'value', $json);

                // 2) also store into property so Import works even with your current "Value=1" button
                IPS_SetProperty($this->InstanceID, 'ConfigBackupJson', $json);
                IPS_ApplyChanges($this->InstanceID);
                break;

            case 'UI_ImportConfig':
                // Prefer value passed from UI (if you later change form.json to pass the field)
                $json = trim((string)$Value);

                // If button passes "1", fall back to the saved property
                if ($json === '' || $json === '1') {
                    $json = trim($this->ReadPropertyString('ConfigBackupJson'));
                }

                $this->ImportConfiguration($json);
                break;

            default:
                throw new Exception("Invalid Ident: $Ident");
        }
    }



    private function TokenIsNewer(string $epoch, int $seq, string $curEpoch, int $curSeq): bool
    {
        $epoch = trim($epoch);
        $curEpoch = trim($curEpoch);

        if ($epoch === '') $epoch = '0';
        if ($curEpoch === '') $curEpoch = '0';

        // Safe decimal-string comparison for millisecond epochs
        $lenA = strlen($epoch);
        $lenB = strlen($curEpoch);

        if ($lenA > $lenB) return true;
        if ($lenA < $lenB) return false;

        if ($epoch > $curEpoch) return true;
        if ($epoch < $curEpoch) return false;

        return ($seq > $curSeq);
    }

    private function UpdateLastProcessedToken(string $epoch, int $seq): void
    {
        $curEpoch = (string)$this->ReadAttributeString("LastProcessedEventEpoch");
        $curSeq   = (int)$this->ReadAttributeInteger("LastProcessedEventSeq");

        if ($this->TokenIsNewer($epoch, $seq, $curEpoch, $curSeq)) {
            $this->WriteAttributeString("LastProcessedEventEpoch", $epoch);
            $this->WriteAttributeInteger("LastProcessedEventSeq", $seq);
        }
    }


    public function GetPayloadHistory()
    {
        return $this->ReadAttributeString("PayloadHistory");
    }





    public function ReceivePayload(string $Payload)
    {
        $data = json_decode($Payload, true);
        if (!is_array($data)) {
            return;
        }

        // ---- Event token from Module 1 (may be missing for legacy payloads) ----
        $hasToken = isset($data['event_epoch'], $data['event_seq']);
        $evtEpoch = $hasToken ? trim((string)$data['event_epoch']) : '0';
        $evtSeq   = $hasToken ? (int)$data['event_seq'] : 0;

        // Optional diagnostics: record "seen" token as highest token observed (NOT merely last arrival)
        if ($hasToken) {
            $seenEpoch = (string)$this->ReadAttributeString("LastSeenEventEpoch");
            $seenSeq   = (int)$this->ReadAttributeInteger("LastSeenEventSeq");

            if ($this->TokenIsNewer($evtEpoch, $evtSeq, $seenEpoch, $seenSeq)) {
                $this->WriteAttributeString("LastSeenEventEpoch", $evtEpoch);
                $this->WriteAttributeInteger("LastSeenEventSeq", $evtSeq);
            }

            $this->WriteAttributeInteger("LastTokenMissing", 0);

            // Minimum-stability token gate:
            // reject duplicates and stale/out-of-order events BEFORE any state mutation
            $curEpoch = (string)$this->ReadAttributeString("LastProcessedEventEpoch");
            $curSeq   = (int)$this->ReadAttributeInteger("LastProcessedEventSeq");

            $isNewer = $this->TokenIsNewer($evtEpoch, $evtSeq, $curEpoch, $curSeq);
            $isEqual = ($evtEpoch === $curEpoch && $evtSeq === $curSeq);

            if ($isEqual) {
                $this->LogMessage("[PSM-Rx] Ignored duplicate event token: epoch=$evtEpoch seq=$evtSeq", KL_MESSAGE);
                return;
            }

            if (!$isNewer) {
                $this->LogMessage(
                    "[PSM-Rx] Ignored stale/out-of-order event token: epoch=$evtEpoch seq=$evtSeq | last_processed=$curEpoch/$curSeq",
                    KL_WARNING
                );
                return;
            }
        } else {
            $this->WriteAttributeInteger("LastTokenMissing", 1);
        }

        // --- HISTORY LOGGING START ---
        $history = json_decode($this->ReadAttributeString("PayloadHistory"), true);
        if (!is_array($history)) $history = [];

        array_unshift($history, [
            'Time' => date('Y-m-d H:i:s'),
            'Data' => $data
        ]);

        if (count($history) > 20) {
            $history = array_slice($history, 0, 20);
        }
        $this->WriteAttributeString("PayloadHistory", json_encode($history));
        // --- HISTORY LOGGING END ---

        // Debug storage
        $this->WriteAttributeString("LastPayload", $Payload);
        $this->WriteAttributeInteger("LastPayloadTime", time());

        $type   = $data['event_type'] ?? 'ALARM';
        $source = $data['source_name'] ?? 'Unknown Source';

        $this->LogMessage("[PSM-Rx] Received '$type' from '$source'", KL_MESSAGE);

        // -------------------- BEDROOM_SYNC --------------------
        if ($type === 'BEDROOM_SYNC') {
            $this->WriteAttributeString("PresenceMap", json_encode($data['bedrooms'] ?? []));
            $this->EvaluateState();

            // Mark processed only after buffers were updated and state machine ran
            if ($hasToken) {
                $this->UpdateLastProcessedToken($evtEpoch, $evtSeq);
            }
            return;
        }

        // -------------------- ALARM / RESET / SENSOR EVENT --------------------
        $activeSensors = json_decode($this->ReadAttributeString("ActiveSensors"), true);
        if (!is_array($activeSensors)) $activeSensors = [];

        // Module 2 is the global house-state authority.
        // Therefore it must rebuild runtime state from the GLOBAL full-snapshot fields,
        // not from target-local projection fields intended for Module 3.
        $projectedGroups = null;
        if (isset($data['active_groups']) && is_array($data['active_groups'])) {
            $projectedGroups = $data['active_groups'];
        }

        $projectedSensorDetails = null;
        if (isset($data['active_sensor_details']) && is_array($data['active_sensor_details'])) {
            $projectedSensorDetails = $data['active_sensor_details'];
        }

        // 1) Global Reset Logic (dedicated early path)
        if (is_array($projectedGroups) && count($projectedGroups) === 0) {
            $this->WriteAttributeString("ActiveSensors", "[]");
            $this->WriteAttributeString("ActiveGroups", "[]");
            $this->LogMessage("[PSM-Rx] Global Reset: ActiveSensors and ActiveGroups cleared.", KL_MESSAGE);

            $this->EvaluateState();

            // Mark processed only after buffers were updated and state machine ran
            if ($hasToken) {
                $this->UpdateLastProcessedToken($evtEpoch, $evtSeq);
            }
            return;
        }

        // 2) Rebuild ActiveSensors from global active sensor snapshot when available.
        if (is_array($projectedSensorDetails)) {
            $rebuiltActiveSensors = [];

            foreach ($projectedSensorDetails as $sensor) {
                $vid = (string)($sensor['variable_id'] ?? '');
                if ($vid !== '' && ctype_digit($vid)) {
                    $rebuiltActiveSensors[$vid] = true;
                }
            }

            $activeSensors = array_values(array_keys($rebuiltActiveSensors));
            $this->LogMessage("[PSM-Rx] Rebuilt ActiveSensors from projected sensor list.", KL_MESSAGE);
        }
        // 3) Backward-compatible fallback: legacy single-sensor delta
        elseif (isset($data['trigger_details']['variable_id'])) {
            $vID = (string)$data['trigger_details']['variable_id'];
            $val = $data['trigger_details']['value_raw'] ?? false;

            // DIAGNOSTIC: Check mapping
            $mapping = json_decode($this->ReadPropertyString("GroupMapping"), true);
            if (!is_array($mapping)) $mapping = [];

            $isMapped = false;
            foreach ($mapping as $m) {
                if (($m['SourceKey'] ?? null) == $vID) {
                    $isMapped = true;
                    $this->LogMessage(
                        "[PSM-Rx] Diagnostic: Sensor $vID matched to Role '" . ($m['LogicalRole'] ?? '?') . "'",
                        KL_MESSAGE
                    );
                    break;
                }
            }
            if (!$isMapped) {
                $this->LogMessage("[PSM-Rx] Diagnostic: WARNING - Sensor $vID received but NOT MAPPED in configuration.", KL_WARNING);
            }

            // Legacy incremental update
            if ($val) {
                if (!in_array($vID, $activeSensors, true)) {
                    $activeSensors[] = $vID;
                    $this->LogMessage("[PSM-Rx] Added Sensor $vID to Active List.", KL_MESSAGE);
                }
            } else {
                if (in_array($vID, $activeSensors, true)) {
                    $this->LogMessage("[PSM-Rx] Removed Sensor $vID from Active List.", KL_MESSAGE);
                }
                $activeSensors = array_values(array_diff($activeSensors, [$vID]));
            }
        }

        // Save and Log Status
        $count = count($activeSensors);
        $this->LogMessage("[PSM-Rx] Active Sensors Count: $count", KL_MESSAGE);

        $this->WriteAttributeString("ActiveSensors", json_encode($activeSensors));

        // 4) Save global active groups snapshot
        if (is_array($projectedGroups)) {
            $this->WriteAttributeString("ActiveGroups", json_encode(array_values($projectedGroups)));
        }

        $this->EvaluateState();

        // Mark processed only after buffers were updated and state machine ran
        if ($hasToken) {
            $this->UpdateLastProcessedToken($evtEpoch, $evtSeq);
        }
    }

    public function ResetPayloadHistory()
    {
        // Clear Debug Logs
        $this->WriteAttributeString("PayloadHistory", "[]");
        $this->WriteAttributeString("LastPayload", "");
        $this->WriteAttributeInteger("LastPayloadTime", 0);

        // Clear Active Sensor Memory (Fix for "Stale" sensors)
        $this->WriteAttributeString("ActiveSensors", "[]");
        $this->WriteAttributeString("ActiveGroups", "[]");
        $this->WriteAttributeString("PresenceMap", "[]");

        // Reset State
        $this->SetValue("SystemState", 0);

        $this->LogMessage("System Reset: Logs, Sensor Memory, and State cleared.", KL_MESSAGE);
    }

    public function GetLastPayload()
    {
        $payload = $this->ReadAttributeString("LastPayload");
        $timestamp = $this->ReadAttributeInteger("LastPayloadTime");

        return json_encode([
            "Time" => $timestamp > 0 ? date("Y-m-d H:i:s", $timestamp) : "Never",
            "RawData" => $payload,
            "Decoded" => json_decode($payload, true)
        ], JSON_PRETTY_PRINT);
    }

    private function EvaluateState()
    {
        // 1. Gather Inputs
        $mapping       = json_decode($this->ReadPropertyString("GroupMapping"), true);
        $activeSensors = json_decode($this->ReadAttributeString("ActiveSensors"), true);
        $activeGroups  = json_decode($this->ReadAttributeString("ActiveGroups"), true);
        $presenceMap   = json_decode($this->ReadAttributeString("PresenceMap"), true);

        if (!is_array($mapping)) $mapping = [];
        if (!is_array($activeSensors)) $activeSensors = [];
        if (!is_array($activeGroups)) $activeGroups = [];
        if (!is_array($presenceMap)) $presenceMap = [];

        // Reset Flags
        $frontLocked   = false;
        $frontClosed   = false;
        $baseLocked    = false;
        $baseClosed    = false;
        $windowsClosed = true; // Default to Closed (Secure)
        $presence      = false;
        $bedroomOpen   = false;

        // Parse Hardware Sensors
        foreach ($mapping as $item) {
            // Check if SourceKey is an Active Sensor ID OR an Active Group Name
            $isActive = in_array($item['SourceKey'], $activeSensors) || in_array($item['SourceKey'], $activeGroups);

            switch ($item['LogicalRole']) {
                case 'Front Door Lock':
                    if ($isActive) $frontLocked = true;
                    break;
                case 'Front Door Contact':
                    if ($isActive) $frontClosed = true;
                    break;
                case 'Basement Door Lock':
                    if ($isActive) $baseLocked = true;
                    break;
                case 'Basement Door Contact':
                    if ($isActive) $baseClosed = true;
                    break;

                case 'Generic Door':
                case 'Window Contact':
                    // Polarity-aware interpretation:
                    // raw meaning for these roles is currently "breach" (active = open).
                    // If user sets Polarity="secure", we invert (active means closed).
                    $polarity = (string)($item['Polarity'] ?? '');
                    if ($polarity === '') $polarity = 'breach'; // keep old behavior if missing

                    // Determine whether this row indicates "open" (breach) after applying polarity
                    $isOpen = ($polarity === 'breach') ? $isActive : !$isActive;

                    if ($isOpen) $windowsClosed = false;
                    break;

                case 'Presence':
                    if ($isActive) $presence = true;
                    break;
            }
        }

        // Parse Bedroom Metadata (Option A: door only relevant if room is used)
        $bedroomPolarity = (string)$this->ReadPropertyString("BedroomDoorPolarity");
        if ($bedroomPolarity === '') $bedroomPolarity = 'breach'; // keep old behavior if missing

        foreach ($presenceMap as $room) {
            $roomUsed    = (bool)($room['SwitchState'] ?? false);
            $doorTripped = (bool)($room['DoorTripped'] ?? false);

            // Normalize bedroom door meaning:
            // breach = active means open
            // secure = active means closed
            $doorOpen = ($bedroomPolarity === 'breach') ? $doorTripped : !$doorTripped;

            // IMPORTANT:
            // Bedroom usage must NOT redefine global house presence.
            // It only tells us whether this bedroom door is relevant for
            // internal-mode gating/disarm logic.
            if ($roomUsed && $doorOpen) {
                $bedroomOpen = true; // only relevant if the room is used
            }
        }

        // Publish derived bedroom-door relevance for UI / bitmask display.
        // This is computed by the logic layer so the HTML does not need to replicate the rule.
        $this->WriteAttributeInteger("RelevantBedroomDoorOpen", $bedroomOpen ? 1 : 0);

        // Derived Conditions
        $perimeterSecure = ($frontLocked && $frontClosed && $baseLocked && $baseClosed && $windowsClosed);        // Derived Flags for ARMED behavior (per your guidance)
        $entranceUnlocked = (!$frontLocked || !$baseLocked);   // unlocking any entrance lock disarms
        $groupLevelOpen   = (!$windowsClosed);                 // opening any group-level window/door triggers alarm (when armed)
        $readyToSleep = ($presence && !$bedroomOpen);
        $readyToLeave = (!$presence);

        // DEBUG REPORTING
        $this->LogMessage(sprintf(
            "[PSM-Logic] Inputs: F-Lock:%d F-Close:%d B-Lock:%d B-Close:%d Win/GenClose:%d | Pres:%d BedOpen:%d || Secure:%s",
            $frontLocked,
            $frontClosed,
            $baseLocked,
            $baseClosed,
            $windowsClosed,
            $presence,
            $bedroomOpen,
            $perimeterSecure ? "YES" : "NO"
        ), KL_MESSAGE);

        // 2. State Machine Logic
        $currentState = $this->GetValue("SystemState");
        $newState     = $currentState;

        switch ($currentState) {
            case 0: // DISARMED
                if ($perimeterSecure) {
                    if ($readyToLeave || $readyToSleep) {
                        $newState = 2;
                    }
                }
                break;

            case 2: // EXIT DELAY
                if (!$perimeterSecure) {
                    $newState = 0;
                    $this->LogMessage("[PSM-Logic] Abort Delay: Perimeter Unsecure", KL_WARNING);
                    break;
                }

                if ($presence && $bedroomOpen) {
                    $newState = 0;
                    $this->LogMessage("[PSM-Logic] Abort Delay: Bedroom Open", KL_WARNING);
                    break;
                }

                // Arm ONLY if the timer actually expired (not merely cancelled)
                if ($this->ReadAttributeInteger("DelayExpired") === 1) {
                    $newState = $presence ? 6 : 3; // Presence=true => Armed Internal, else Armed External
                    $this->LogMessage("[PSM-Logic] Exit Delay Expired: System Armed", KL_MESSAGE);
                }
                break;

            case 3: // ARMED EXTERNAL
                if ($entranceUnlocked) {
                    $newState = 0;
                    $this->LogMessage("[PSM-Logic] Disarm: Entrance lock unlocked (External Armed)", KL_MESSAGE);
                    break;
                }
                if ($groupLevelOpen) {
                    $newState = 9;
                    $this->LogMessage("[PSM-Logic] ALARM TRIGGERED: Group-level opening detected (External Armed)", KL_WARNING);
                    break;
                }
                break;

            case 6: // ARMED INTERNAL
                if ($entranceUnlocked) {
                    $newState = 0;
                    $this->LogMessage("[PSM-Logic] Disarm: Entrance lock unlocked (Internal Armed)", KL_MESSAGE);
                    break;
                }
                if ($bedroomOpen) {
                    $newState = 0;
                    $this->LogMessage("[PSM-Logic] Disarm: Bedroom door opened (Internal Armed)", KL_MESSAGE);
                    break;
                }
                if ($groupLevelOpen) {
                    $newState = 9;
                    $this->LogMessage("[PSM-Logic] ALARM TRIGGERED: Group-level opening detected (Internal Armed)", KL_WARNING);
                    break;
                }
                break;

            case 9: // ALARM TRIGGERED (latched, but can be cleared by authorized actions)
                // Authorized reset: unlocking any entrance lock always disarms (your rule)
                if ($entranceUnlocked) {
                    $newState = 0;
                    $this->LogMessage("[PSM-Logic] Alarm Cleared: Entrance lock unlocked", KL_MESSAGE);
                    break;
                }

                // Authorized reset in internal mode: opening a bedroom door disarms (your rule)
                // (We use $presence as the indicator for internal/home context)
                if ($presence && $bedroomOpen) {
                    $newState = 0;
                    $this->LogMessage("[PSM-Logic] Alarm Cleared: Bedroom door opened (Internal)", KL_MESSAGE);
                    break;
                }

                // Otherwise remain in alarm
                break;
        }

        // 3. Execute Transition
        if ($currentState !== $newState) {
            $this->SetValue("SystemState", $newState);
            $this->LogMessage("[PSM-Logic] State change: $currentState -> $newState", KL_MESSAGE);
        }

        // 4. Timer Management
        if ($newState == 2) {
            if ($currentState != 2) {
                $this->WriteAttributeInteger("DelayExpired", 0);
                $duration = $this->ReadPropertyInteger("ArmingDelayDuration");
                $this->SetTimerInterval("DelayTimer", $duration * 60 * 1000);
                $this->LogMessage("[PSM-Timer] Exit Delay Started ($duration min)", KL_MESSAGE);
            }
        } elseif ($this->GetTimerInterval("DelayTimer") > 0) {
            $this->WriteAttributeInteger("DelayExpired", 0);
            $this->SetTimerInterval("DelayTimer", 0);
            $this->LogMessage("[PSM-Timer] Exit Delay Cancelled", KL_MESSAGE);
        }

        // Push-first integration: publish updated authoritative house state
        // to configured Module 3 targets when the exported snapshot meaning changed.
        $this->MaybePushHouseStateSnapshot();
    }

    /**
     * Export PSM configuration as JSON (properties only).
     * Safe: does not include runtime attributes/state.
     */
    private function ExportConfiguration(): string
    {
        $snapshot = [
            'export_type'     => 'PSM_CONFIG',
            'schema_version'  => 1,
            'module_version'  => '7.2.0', // optional, keep or remove
            'exported_at'     => time(),

            // --- Properties you actually want to backup ---
            'SensorGroupInstanceID' => $this->ReadPropertyInteger('SensorGroupInstanceID'),
            'DispatchTargetID'      => $this->ReadPropertyInteger('DispatchTargetID'),
            'GroupMapping'          => json_decode($this->ReadPropertyString('GroupMapping'), true),
            'DecisionMap'           => json_decode($this->ReadPropertyString('DecisionMap'), true),
            'ArmingDelayDuration'   => $this->ReadPropertyInteger('ArmingDelayDuration'),
            'VaultInstanceID'       => $this->ReadPropertyInteger('VaultInstanceID'),
            'BedroomDoorPolarity'   => $this->ReadPropertyString('BedroomDoorPolarity'),
        ];

        // make sure arrays are arrays (not null)
        if (!is_array($snapshot['GroupMapping'])) $snapshot['GroupMapping'] = [];
        if (!is_array($snapshot['DecisionMap'])) $snapshot['DecisionMap'] = [];

        return json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Import PSM configuration from JSON (properties only).
     * Safe: does not restore runtime attributes/state.
     */
    private function ImportConfiguration(string $json): void
    {
        $json = trim($json);
        if ($json === '') {
            $this->LogMessage("[PSM-Import] Empty JSON.", KL_WARNING);
            return;
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            $this->LogMessage("[PSM-Import] JSON invalid (decode failed).", KL_ERROR);
            return;
        }

        // Accept both formats:
        // A) New export: { schema:"PSM.ConfigExport", ..., properties:{...} }
        // B) Legacy/flat: { SensorGroupInstanceID:..., DispatchTargetID:..., ... }
        $props = $data['properties'] ?? $data;
        if (!is_array($props)) $props = [];

        // Soft schema check (do not fail hard)
        if (isset($data['schema']) && (string)$data['schema'] !== 'PSM.ConfigExport') {
            $this->LogMessage("[PSM-Import] Warning: unexpected schema '" . (string)$data['schema'] . "'.", KL_WARNING);
        }

        // Pull fields with safe defaults
        $sensorGroup = (int)($props['SensorGroupInstanceID'] ?? 0);
        $targetID         = (int)($props['DispatchTargetID'] ?? 0);
        $delayMin         = (int)($props['ArmingDelayDuration'] ?? 1);
        $vaultID          = (int)($props['VaultInstanceID'] ?? 0);
        $bedroomPolarity  = (string)($props['BedroomDoorPolarity'] ?? 'breach');
        if ($bedroomPolarity !== 'secure' && $bedroomPolarity !== 'breach') $bedroomPolarity = 'breach';

        $groupMapping = $props['GroupMapping'] ?? [];
        if (!is_array($groupMapping)) $groupMapping = [];

        $decisionMap = $props['DecisionMap'] ?? [];
        if (!is_array($decisionMap)) $decisionMap = [];

        // Basic sanity: keep module usable even if import is incomplete
        if ($sensorGroup <= 0) {
            $this->LogMessage("[PSM-Import] Warning: SensorGroupInstanceID is 0/invalid in import.", KL_WARNING);
        }

        // IMPORTANT sequencing to avoid "Current value X is not available" in Select fields:
        // Phase 1: set SensorGroup first so GetConfigurationForm can populate DispatchTarget options.
        IPS_SetProperty($this->InstanceID, 'SensorGroupInstanceID', $sensorGroup);
        IPS_ApplyChanges($this->InstanceID);

        // Phase 2: set remaining properties
        IPS_SetProperty($this->InstanceID, 'DispatchTargetID',       $targetID);
        IPS_SetProperty($this->InstanceID, 'ArmingDelayDuration',    max(1, $delayMin));
        IPS_SetProperty($this->InstanceID, 'VaultInstanceID',        $vaultID);
        IPS_SetProperty($this->InstanceID, 'BedroomDoorPolarity',    $bedroomPolarity);

        IPS_SetProperty($this->InstanceID, 'GroupMapping', json_encode($groupMapping));
        IPS_SetProperty($this->InstanceID, 'DecisionMap',  json_encode($decisionMap));

        // Optional: persist the JSON in the UI field/property (so the pasted content remains)
        // Only do this if you've registered the property "ConfigBackupJson"
        if (property_exists($this, 'InstanceID')) {
            // keep safe: only set if the property exists in this module (won't throw if missing in most cases,
            // but if you want stricter, remove this block)
            @IPS_SetProperty($this->InstanceID, 'ConfigBackupJson', $json);
        }

        IPS_ApplyChanges($this->InstanceID);

        $this->LogMessage(
            "[PSM-Import] Imported: SensorGroup=" . $sensorGroup .
                " Target=" . $targetID .
                " MappingRows=" . count($groupMapping) .
                " DelayMin=" . max(1, $delayMin),
            KL_MESSAGE
        );
    }


    public function GetSystemState()
    {
        $status = [
            'StateID'       => $this->GetValue('SystemState'),
            'PresenceMap'   => json_decode($this->ReadAttributeString('PresenceMap'), true),
            'ActiveSensors' => json_decode($this->ReadAttributeString('ActiveSensors'), true),
            'IsDelayActive' => ($this->GetTimerInterval('DelayTimer') > 0),
            'last_processed_event_epoch' => (string)$this->ReadAttributeString('LastProcessedEventEpoch'),
            'last_processed_event_seq'   => (int)$this->ReadAttributeInteger('LastProcessedEventSeq'),
            'last_token_missing'         => (int)$this->ReadAttributeInteger('LastTokenMissing'),
            'last_seen_event_epoch'      => (string)$this->ReadAttributeString('LastSeenEventEpoch'),
            'last_seen_event_seq'        => (int)$this->ReadAttributeInteger('LastSeenEventSeq')
        ];

        return json_encode($status);
    }

    public function GetMappingHints()
    {
        $schemaVersion = 1;
        $module2Version = "7.2.0"; // keep aligned with your module versioning
        $warnings = [];

        // --- Inputs (read-only) ---
        $mapping = json_decode($this->ReadPropertyString("GroupMapping"), true);
        if (!is_array($mapping)) $mapping = [];

        $captionMap = json_decode($this->ReadAttributeString("SensorCaptionMap"), true);
        if (!is_array($captionMap)) $captionMap = [];

        $groupSensorMap = json_decode($this->ReadAttributeString("GroupSensorMap"), true);
        if (!is_array($groupSensorMap)) $groupSensorMap = [];

        $presenceMap = json_decode($this->ReadAttributeString("PresenceMap"), true);
        if (!is_array($presenceMap)) $presenceMap = [];

        if (count($captionMap) === 0) {
            $warnings[] = "SensorCaptionMap cache is empty. Run PSM_UI_Refresh() to populate captions.";
        }
        if (count($groupSensorMap) === 0) {
            $warnings[] = "GroupSensorMap cache is empty. Run PSM_UI_Refresh() to populate group members.";
        }
        if (count($mapping) === 0) {
            $warnings[] = "GroupMapping is empty. No semantic roles configured in Module 2.";
        }

        // --- Buckets (stable contract: always include all keys) ---
        $buckets = [
            "entry_locks"       => [],
            "perimeter_windows" => [],
            "perimeter_doors"   => [],
            "interior_motion"   => [],
            "presence_sources"  => [],
            "bedroom_related"   => []
        ];

        // --- Build enriched mapping rows ---
        $mappingsOut = [];

        foreach ($mapping as $m) {
            $src  = (string)($m["SourceKey"] ?? "");
            $role = (string)($m["LogicalRole"] ?? "");
            $pol  = $m["Polarity"] ?? null;
            if (is_string($pol) && $pol === "") $pol = null;

            if ($src === "" || $role === "") {
                $warnings[] = "Found incomplete mapping row (missing SourceKey or LogicalRole).";
                continue;
            }

            $isSensor = ctype_digit($src);
            $kind = $isSensor ? "sensor" : "group";
            $origin = $isSensor ? "module1_variable" : "module1_group";

            // caption
            if ($isSensor) {
                $caption = $captionMap[$src] ?? ("Variable " . $src);
            } else {
                $caption = $src . " (Group)";
            }

            $row = [
                "source_key"   => $src,
                "source_kind"  => $kind,
                "logical_role" => $role,
                "polarity"     => $pol,
                "caption"      => $caption,
                "origin"       => $origin
            ];

            // If group, add members[]
            if (!$isSensor) {
                $members = [];
                $vids = $groupSensorMap[$src] ?? [];
                if (!is_array($vids)) $vids = [];

                foreach ($vids as $vid) {
                    $k = (string)$vid;
                    $members[] = [
                        "variable_id" => (int)$vid,
                        "caption"     => $captionMap[$k] ?? ("Variable " . $k)
                    ];
                }

                $row["members"] = $members;
            }

            $mappingsOut[] = $row;

            // --- Bucket assignment (normalized for Module 3) ---
            switch ($role) {
                case "Front Door Lock":
                case "Basement Door Lock":
                    $buckets["entry_locks"][] = $src;
                    break;

                case "Window Contact":
                    $buckets["perimeter_windows"][] = $src;
                    break;

                case "Generic Door":
                    $buckets["perimeter_doors"][] = $src;
                    break;

                case "Presence":
                    $buckets["presence_sources"][] = $src;
                    break;

                // Motion is not implemented in your current role set,
                // but keep the bucket stable and allow future roles.
                case "Motion":
                case "Interior Motion":
                    $buckets["interior_motion"][] = $src;
                    break;
            }
        }

        // --- Bedroom-related signals (currently come from PresenceMap/BEDROOM_SYNC, not GroupMapping) ---
        $bedroomNames = [];
        foreach ($presenceMap as $room) {
            $gn = (string)($room["GroupName"] ?? "");
            if ($gn !== "") $bedroomNames[$gn] = true;
        }
        $buckets["bedroom_related"] = array_values(array_keys($bedroomNames));

        // Optional extra metadata for Module 3 UI (does not affect buckets contract)
        $bedroomSyncSources = [];
        foreach ($presenceMap as $room) {
            $bedroomSyncSources[] = [
                "group_name"   => (string)($room["GroupName"] ?? "Unknown"),
                "switch_state" => (bool)($room["SwitchState"] ?? false),
                "door_tripped" => (bool)($room["DoorTripped"] ?? false)
            ];
        }

        return json_encode([
            "schema_version"   => $schemaVersion,
            "module2_version"  => $module2Version,
            "generated_at"     => time(),
            "buckets"          => $buckets,
            "mappings"         => $mappingsOut,
            "bedroom_sync_sources" => $bedroomSyncSources,
            "warnings"         => $warnings
        ]);
    }

    public function GetHouseStateSnapshot()
    {
        // Snapshot for Module 3 (pull model). Read-only. No state changes.

        $now = time();

        // --- Core state ---
        $stateId   = (int)$this->GetValue('SystemState');
        $stateName = $this->GetStateName($stateId);

        // Delay remaining seconds (same idea as in ProcessHookData)
        $delayActive = ($this->GetTimerInterval("DelayTimer") > 0);
        $delayRemainingSeconds = 0;

        if ($stateId === 2) {
            $varID = $this->GetIDForIdent("SystemState");
            if ($varID > 0 && IPS_VariableExists($varID)) {
                $varInfo = IPS_GetVariable($varID);
                $lastUpdate = (int)($varInfo['VariableUpdated'] ?? 0);
                $durationSeconds = (int)$this->ReadPropertyInteger("ArmingDelayDuration") * 60;
                $delayRemainingSeconds = ($lastUpdate + $durationSeconds) - $now;
                if ($delayRemainingSeconds < 0) $delayRemainingSeconds = 0;
            }
        }

        // --- Raw buffers ---
        $mapping       = json_decode($this->ReadPropertyString("GroupMapping"), true);
        if (!is_array($mapping)) $mapping = [];

        $activeSensors = json_decode($this->ReadAttributeString("ActiveSensors"), true);
        if (!is_array($activeSensors)) $activeSensors = [];

        $activeGroups  = json_decode($this->ReadAttributeString("ActiveGroups"), true);
        if (!is_array($activeGroups)) $activeGroups = [];

        $presenceMap   = json_decode($this->ReadAttributeString("PresenceMap"), true);
        if (!is_array($presenceMap)) $presenceMap = [];

        // --- Derived flags (same semantics as EvaluateState) ---
        $frontLocked   = false;
        $frontClosed   = false;
        $baseLocked    = false;
        $baseClosed    = false;
        $windowsClosed = true; // default secure
        $presence      = false;
        $bedroomOpen   = false;

        foreach ($mapping as $item) {
            $src = (string)($item['SourceKey'] ?? '');
            $role = (string)($item['LogicalRole'] ?? '');

            $isActive = ($src !== '') && (in_array($src, $activeSensors, true) || in_array($src, $activeGroups, true));

            switch ($role) {
                case 'Front Door Lock':
                    if ($isActive) $frontLocked = true;
                    break;
                case 'Front Door Contact':
                    if ($isActive) $frontClosed = true;
                    break;
                case 'Basement Door Lock':
                    if ($isActive) $baseLocked = true;
                    break;
                case 'Basement Door Contact':
                    if ($isActive) $baseClosed = true;
                    break;

                case 'Generic Door':
                case 'Window Contact':
                    // Same polarity logic as EvaluateState:
                    $polarity = (string)($item['Polarity'] ?? '');
                    if ($polarity === '') $polarity = 'breach'; // default keeps old behavior
                    $isOpen = ($polarity === 'breach') ? $isActive : !$isActive;
                    if ($isOpen) $windowsClosed = false;
                    break;

                case 'Presence':
                    if ($isActive) $presence = true;
                    break;
            }
        }

        // Bedrooms (Option A gating)
        $bedroomPolarity = (string)$this->ReadPropertyString("BedroomDoorPolarity");
        if ($bedroomPolarity === '') $bedroomPolarity = 'breach'; // keep old behavior if missing

        $bedrooms = [];
        foreach ($presenceMap as $room) {
            $name        = (string)($room['GroupName'] ?? 'Unknown');
            $roomUsed    = (bool)($room['SwitchState'] ?? false);
            $doorTripped = (bool)($room['DoorTripped'] ?? false);
            $doorOpen    = ($bedroomPolarity === 'breach') ? $doorTripped : !$doorTripped;

            $blocking = ($roomUsed && $doorOpen);

            $bedrooms[] = [
                'name'     => $name,
                'used'     => $roomUsed,
                'doorOpen' => $doorOpen,
                'blocking' => $blocking
            ];

            // IMPORTANT:
            // Bedroom usage must NOT redefine global house presence.
            // It only determines whether this bedroom door is relevant
            // for internal-mode blocking / disarm logic.
            if ($roomUsed && $doorOpen) {
                $bedroomOpen = true;
            }
        }

        $perimeterSecure   = ($frontLocked && $frontClosed && $baseLocked && $baseClosed && $windowsClosed);
        $entranceUnlocked  = (!$frontLocked || !$baseLocked);
        $groupLevelOpen    = (!$windowsClosed);
        $readyToSleep      = ($presence && !$bedroomOpen);
        $readyToLeave      = (!$presence);

        // Blocking reasons (simple, useful for Module 3 decisions/UI)
        $blockingReasons = [];
        if (!$perimeterSecure) $blockingReasons[] = "Perimeter not secure";
        if ($presence && $bedroomOpen) $blockingReasons[] = "Bedroom door open while someone home";

        $bedroomsBlocking = [];
        foreach ($bedrooms as $b) {
            if (!empty($b['blocking'])) $bedroomsBlocking[] = $b['name'];
        }

        $snapshot = [
            'schema'              => 'PSM.HouseState.v2',
            'timestamp'           => $now,
            'source_instance_id'  => $this->InstanceID,

            'sync' => [
                'last_processed_event_epoch' => (string)$this->ReadAttributeString('LastProcessedEventEpoch'),
                'last_processed_event_seq'   => (int)$this->ReadAttributeInteger('LastProcessedEventSeq')
            ],

            'system_state_id'     => $stateId,
            'system_state_name'   => $stateName,
            'delay_active'        => $delayActive,
            'delay_remaining_seconds' => $delayRemainingSeconds,

            'active_sensors'      => array_values($activeSensors),
            'active_groups'       => array_values($activeGroups),
            'presence_map'        => $presenceMap,

            'bedrooms'            => $bedrooms,

            'derived' => [
                'frontLocked'      => $frontLocked,
                'frontClosed'      => $frontClosed,
                'baseLocked'       => $baseLocked,
                'baseClosed'       => $baseClosed,
                'windowsClosed'    => $windowsClosed,
                'presence'         => $presence,
                'bedroomOpen'      => $bedroomOpen,

                'perimeterSecure'  => $perimeterSecure,
                'readyToSleep'     => $readyToSleep,
                'readyToLeave'     => $readyToLeave,
                'entranceUnlocked' => $entranceUnlocked,
                'groupLevelOpen'   => $groupLevelOpen
            ],

            'blocking_reasons' => $blockingReasons,
            'blocking_details' => [
                'bedrooms_blocking' => $bedroomsBlocking
            ]
        ];

        return json_encode($snapshot);
    }

    private function NormalizeHouseStateSnapshotForPushDiff(string $snapshotJson): string
    {
        $data = json_decode($snapshotJson, true);
        if (!is_array($data)) {
            return '';
        }

        // Timestamp changes on every build and must not trigger push spam
        unset($data['timestamp']);

        return json_encode($data, JSON_UNESCAPED_SLASHES);
    }

    private function MaybePushHouseStateSnapshot(): void
    {
        $targets = json_decode($this->ReadPropertyString("StatePushTargets"), true);
        if (!is_array($targets) || count($targets) === 0) {
            return;
        }

        $snapshotJson = $this->GetHouseStateSnapshot();
        $normalizedSnapshot = $this->NormalizeHouseStateSnapshotForPushDiff($snapshotJson);
        if ($normalizedSnapshot === '') {
            $this->LogMessage("[PSM-Push] Snapshot normalization failed, push skipped.", KL_WARNING);
            return;
        }

        $lastPushed = $this->ReadAttributeString("LastPushedHouseStateSnapshot");

        // Push only when exported state meaning changed
        if ($lastPushed === $normalizedSnapshot) {
            return;
        }

        $pushedCount = 0;

        foreach ($targets as $row) {
            $targetID = (int)($row['InstanceID'] ?? 0);
            if ($targetID <= 0 || !@IPS_InstanceExists($targetID)) {
                $this->LogMessage("[PSM-Push] Skipping invalid target instance ID: " . $targetID, KL_WARNING);
                continue;
            }

            try {
                IPS_RequestAction($targetID, 'ReceiveHouseStateSnapshot', $snapshotJson);
                $pushedCount++;
            } catch (\Throwable $e) {
                $this->LogMessage(
                    "[PSM-Push] Push to target " . $targetID . " failed: " . $e->getMessage(),
                    KL_WARNING
                );
            }
        }

        // Only advance cache if at least one push was attempted successfully
        if ($pushedCount > 0) {
            $this->WriteAttributeString("LastPushedHouseStateSnapshot", $normalizedSnapshot);
            $this->LogMessage("[PSM-Push] House-state snapshot pushed to " . $pushedCount . " target(s).", KL_MESSAGE);
        }
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . "/form.json"), true);
        $sensorGroupId = $this->ReadPropertyInteger("SensorGroupInstanceID");
        $targetID = $this->ReadPropertyInteger("DispatchTargetID");
        $options = [];
        $targetOptions = [
            ["caption" => "— Not set / Select —", "value" => 0]
        ];

        if ($sensorGroupId > 0 && @IPS_InstanceExists($sensorGroupId)) {
            $configJSON = @MYALARM_GetConfiguration($sensorGroupId);
            if ($configJSON !== false) {
                $config = json_decode($configJSON, true);

                // Populate Target List
                foreach ($config['DispatchTargets'] ?? [] as $t) {
                    $targetOptions[] = ["caption" => $t['Name'], "value" => (int)$t['InstanceID']];
                }

                if ($targetID > 0) {
                    $this->LogMessage("GF: Filtering for Target ID: " . $targetID, KL_MESSAGE);

                    // 1. Identify Target Groups and Classes
                    $targetGroups = []; // Names of groups sent to us
                    foreach ($config['GroupDispatch'] ?? [] as $gd) {
                        if ((int)$gd['InstanceID'] === $targetID) {
                            $targetGroups[] = $gd['GroupName'];

                            // ADD GROUP OPTION (New)
                            // Allows mapping entire groups (e.g. "Windows")
                            $options[] = [
                                "caption" => "[GROUP] " . $gd['GroupName'],
                                "value" => $gd['GroupName']
                            ];
                        }
                    }

                    $targetClasses = [];
                    foreach ($config['GroupMembers'] ?? [] as $gm) {
                        if (in_array($gm['GroupName'], $targetGroups)) $targetClasses[] = $gm['ClassID'];
                    }

                    // 2. Add Sensor Options (Existing)
                    foreach ($config['SensorList'] ?? [] as $sensor) {
                        $vid = (int)$sensor['VariableID'];
                        if (in_array($sensor['ClassID'], $targetClasses)) {
                            $name = IPS_ObjectExists($vid) ? IPS_GetName($vid) : "Unknown";
                            $caption = sprintf("%s > %s > %s (%d)", $sensor['GrandParentName'] ?? '?', $sensor['ParentName'] ?? '?', $name, $vid);
                            $options[] = ["caption" => $caption, "value" => (string)$vid];
                        }
                    }
                }
            }
        }

        // Fix: Add current Target ID if missing
        if ($targetID > 0) {
            $found = false;
            foreach ($targetOptions as $opt) {
                if ($opt['value'] == $targetID) {
                    $found = true;
                    break;
                }
            }
            if (!$found) $targetOptions[] = ["caption" => "⚠️ Unavailable / Old Target ($targetID)", "value" => $targetID];
        }
        if (!isset($form['elements']) || !is_array($form['elements'])) {
            $form['elements'] = [];
        }
        foreach ($form['elements'] as &$element) {
            if (isset($element['name']) && $element['name'] === 'DispatchTargetID') $element['options'] = $targetOptions;
            if (isset($element['name']) && $element['name'] === 'GroupMapping') {
                $element['columns'][0]['edit']['options'] = $options;
            }
        }
        return json_encode($form);
    }
}
