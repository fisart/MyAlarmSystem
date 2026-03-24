<?php
// Looks like a stable version
declare(strict_types=1);

class SensorGroup extends IPSModule
{
    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyString('ClassList', '[]');
        $this->RegisterPropertyString('SensorList', '[]');
        $this->RegisterPropertyString('GroupList', '[]');
        $this->RegisterPropertyString('GroupMembers', '[]');
        $this->RegisterPropertyString('TamperList', '[]');
        $this->RegisterPropertyString('BedroomList', '[]');
        $this->RegisterPropertyString('GroupDispatch', '[]');
        $this->RegisterPropertyInteger('BedroomTarget', 0); // NEW: Global Bedroom Router
        $this->RegisterPropertyInteger('VaultInstanceID', 0); // NEW: Security Vault for Webhook
        $this->RegisterPropertyBoolean('MaintenanceMode', false);
        $this->RegisterPropertyBoolean('DebugMode', false);

        // RAM Buffers for Blueprint Strategy 2.0
        $this->RegisterAttributeString('ClassListBuffer', '[]');
        $this->RegisterAttributeString('GroupListBuffer', '[]'); // Added to resolve registration error
        $this->RegisterAttributeString('SensorListBuffer', '[]');
        $this->RegisterAttributeString('BedroomListBuffer', '[]');
        $this->RegisterAttributeString('GroupMembersBuffer', '[]');
        $this->RegisterAttributeString('GroupDispatchBuffer', '[]');

        // NEW: Export Buffers for the Dashboard
        $this->RegisterAttributeString('ActiveClassesBuffer', '[]');
        $this->RegisterAttributeString('ActiveSensorsBuffer', '[]');

        $this->RegisterAttributeString('ClassStateAttribute', '{}');
        $this->RegisterAttributeString('ScanCache', '[]');
        $this->RegisterVariableBoolean('Status', 'Status', '~Alert', 10);
        $this->RegisterVariableBoolean('Sabotage', 'Sabotage', '~Alert', 90);
        $this->RegisterVariableString('EventData', 'Event Payload', '', 99);

        $this->RegisterPropertyString('DispatchTargets', '[]');   // list of Module2 targets

        $this->RegisterPropertyString('TargetThrottleList', '[]'); // per-target throttle config
        $this->RegisterAttributeString('TargetThrottleState', '{}'); // runtime timestamps per target
        $this->RegisterAttributeString('DispatchTargetsBuffer', '[]');
        $this->RegisterAttributeString('LastTargetProjectionState', '{}');
        $this->RegisterAttributeString('LastSensorValueMap', '{}');
        $this->RegisterAttributeString('SensorPulseUntilMap', '{}');

        $this->RegisterAttributeString('LastMainStatus', '0');
        IPS_SetHidden($this->GetIDForIdent('EventData'), true);
    }


    public function ApplyChanges()
    {
        if ($this->ReadPropertyBoolean('DebugMode')) $this->LogMessage("DEBUG: ApplyChanges - START", KL_MESSAGE);
        parent::ApplyChanges();

        // Init Event Token Epoch (RAM Buffer - No Disk Write)
        $this->SetBuffer('EventEpoch', (string)round(microtime(true) * 1000));
        $this->SetBuffer('EventSeq', '0');

        // Register the Webhook for the HTML Dashboard
        $this->RegisterHook('/hook/MyAlarmFlow_' . $this->InstanceID);

        // DEBUG: What does ApplyChanges see right after parent lifecycle?
        $tmp = json_decode((string)$this->ReadPropertyString('GroupList'), true);
        $gProp = is_array($tmp) ? $tmp : [];
        unset($tmp);
        $tmp = json_decode((string)$this->ReadAttributeString('GroupListBuffer'), true);
        $gBuf = is_array($tmp) ? $tmp : [];
        unset($tmp);
        if ($this->ReadPropertyBoolean('DebugMode')) $this->LogMessage("DEBUG: ApplyChanges - GroupListProperty=" . count($gProp) . " | GroupListBuffer=" . count($gBuf), KL_MESSAGE);
        if (count($gProp) > 0) if ($this->ReadPropertyBoolean('DebugMode')) $this->LogMessage("DEBUG: ApplyChanges - LastPropGroup=" . json_encode(end($gProp)), KL_MESSAGE);
        if (count($gBuf) > 0)  if ($this->ReadPropertyBoolean('DebugMode')) $this->LogMessage("DEBUG: ApplyChanges - LastBufGroup=" . json_encode(end($gBuf)), KL_MESSAGE);

        // === AGREED and CHANGE: Heal missing GroupID after APPLY (in case UI Add popup didn't trigger onEdit/RequestAction) ===
        $stateData_tmp  = json_decode($this->ReadAttributeString('ClassStateAttribute'), true) ?: [];
        $groupIDMap_tmp = $stateData_tmp['GroupIDMap'] ?? [];
        if (!is_array($groupIDMap_tmp)) {
            $groupIDMap_tmp = [];
        }

        $groupIDsFixed = false;

        if (is_array($gProp)) {
            foreach ($gProp as $i => &$gRow) {
                if (!is_array($gRow)) {
                    continue;
                }
                $gName = trim((string)($gRow['GroupName'] ?? ''));
                if ($gName === '') {
                    continue;
                }

                $gId = (string)($gRow['GroupID'] ?? '');

                if ($gId === '') {
                    if (isset($groupIDMap_tmp[$gName]) && (string)$groupIDMap_tmp[$gName] !== '') {
                        $gId = (string)$groupIDMap_tmp[$gName];
                    } else {
                        $gId = uniqid('grp_');
                    }
                    $gRow['GroupID'] = $gId;
                    $groupIDsFixed = true;
                }

                $groupIDMap_tmp[$gName] = $gId;
            }
            unset($gRow);
        }

        if ($groupIDsFixed) {
            $stateData_tmp['GroupIDMap'] = $groupIDMap_tmp;
            $this->WriteAttributeString('ClassStateAttribute', json_encode($stateData_tmp));

            $json_fixed = json_encode(array_values($gProp));
            IPS_SetProperty($this->InstanceID, 'GroupList', $json_fixed);
            $this->WriteAttributeString('GroupListBuffer', $json_fixed);

            if ($this->ReadPropertyBoolean('DebugMode')) $this->LogMessage("DEBUG: ApplyChanges - Healed missing GroupIDs in GroupListProperty and synced GroupListBuffer", KL_MESSAGE);
        }
        // === END AGREED CHANGE ===

        // 1. LIFECYCLE: ID STABILIZATION (Sticky IDs)
        $classList = json_decode($this->ReadPropertyString('ClassList'), true)
            ?: json_decode($this->ReadAttributeString('ClassListBuffer'), true)
            ?: [];
        $groupList = $this->ReadConfigPropertyList('GroupList');

        // Load persistent ID Map
        $stateData = json_decode($this->ReadAttributeString('ClassStateAttribute'), true) ?: [];
        $idMap = $stateData['IDMap'] ?? [];
        $groupIDMap = $stateData['GroupIDMap'] ?? []; // Sticky Group IDs

        // NEW: Load Previous Group State from Buffer for high-reliability recovery
        $bufferGroups = json_decode($this->ReadAttributeString('GroupListBuffer'), true) ?: [];
        $recoveryMapGroups = [];
        foreach ($bufferGroups as $bg) {
            if (!empty($bg['GroupName']) && !empty($bg['GroupID'])) {
                $recoveryMapGroups[$bg['GroupName']] = $bg['GroupID'];
            }
        }

        $idsChanged = false;
        $regenCountClasses = 0;
        $regenCountGroups = 0;

        $validClassIDs = [];
        $classNameMap = [];

        foreach ($classList as &$c) {
            $name = $c['ClassName'] ?? '';
            if (empty($c['ClassID'])) {
                $regenCountClasses++;
                if (!empty($name) && isset($idMap[$name])) {
                    $c['ClassID'] = $idMap[$name];
                    $idsChanged = true;
                } else {
                    $c['ClassID'] = uniqid('cls_');
                    $idsChanged = true;
                }
            }
            if (!empty($name)) {
                $idMap[$name] = $c['ClassID'];
                $classNameMap[$name] = $c['ClassID'];
            }
            $validClassIDs[] = $c['ClassID'];
        }
        unset($c);
        // --- FIX: Purge sticky ClassName→ClassID mappings for deleted classes ---
        // If a class name no longer exists in the current ClassList, remove it from the IDMap.
        // This prevents recreating a class with the same name from reusing the old ClassID (and re-attaching old sensors).
        foreach (array_keys($idMap) as $mappedName) {
            if (!isset($classNameMap[$mappedName])) {
                unset($idMap[$mappedName]);
                $idsChanged = true;
            }
        }

        $validGroupNames = [];
        foreach ($groupList as $idx => &$g) {
            $gName = trim((string)($g['GroupName'] ?? ''));

            if ($gName === '') {
                // Really delete empty placeholder groups so they cannot become persistent
                unset($groupList[$idx]);
                $idsChanged = true;
                continue;
            }

            $g['GroupName'] = $gName;

            if (empty($g['GroupID'])) {
                $regenCountGroups++;

                if (isset($groupIDMap[$gName])) {
                    $g['GroupID'] = $groupIDMap[$gName];
                    $idsChanged = true;
                } elseif (isset($recoveryMapGroups[$gName])) { // Recover from Buffer
                    $g['GroupID'] = $recoveryMapGroups[$gName];
                    $idsChanged = true;
                } else {
                    $g['GroupID'] = uniqid('grp_');
                    $idsChanged = true;
                }
            }

            // keep list of valid groups
            $groupIDMap[$gName] = $g['GroupID'];
            $validGroupNames[] = $gName;
        }
        unset($g);

        // Re-index after unsets
        $groupList = array_values($groupList);
        // =========================
        // DISPATCH ROUTER: GC + Buffer/Property sync  (FLAT LIST: GroupDispatch)
        // =========================
        // FIX: Read directly from Property. List deletions do not trigger onEdit, so buffer becomes stale.
        $dispatchTargets = json_decode($this->ReadPropertyString('DispatchTargets'), true);
        if (!is_array($dispatchTargets)) $dispatchTargets = [];

        // NEW agreed router: flat list
        $groupDispatch = json_decode($this->ReadPropertyString('GroupDispatch'), true);
        if (!is_array($groupDispatch)) $groupDispatch = [];

        /*
        Expected shapes:
        DispatchTargets: [
        ["Name" => "Einbruch", "InstanceID" => 12345],
        ...
        ]
        GroupDispatch: [
        ["GroupName" => "Wasser Alarm", "InstanceID" => 12345],
        ...
        ]
        */

        // Build set of valid target IDs
        $validTargetIDs = [];
        foreach ($dispatchTargets as $t) {
            $tid = (int)($t['InstanceID'] ?? 0);
            if ($tid > 0) $validTargetIDs[$tid] = true;
        }

        // Clean routes: keep only existing groups + existing target IDs
        $cleanDispatch = [];
        $seen = [];
        foreach ($groupDispatch as $r) {
            if (!is_array($r)) continue;

            $gName = trim((string)($r['GroupName'] ?? ''));
            $iid   = (int)($r['InstanceID'] ?? 0);

            if ($gName === '') continue;
            if (!in_array($gName, $validGroupNames, true)) continue;
            if ($iid <= 0 || !isset($validTargetIDs[$iid])) continue;

            // de-dup exact pair (GroupName + InstanceID)
            $key = $gName . '|' . $iid;
            if (isset($seen[$key])) continue;
            $seen[$key] = true;

            $cleanDispatch[] = [
                'GroupName'  => $gName,
                'InstanceID' => $iid
            ];
        }

        // Persist router (buffers + properties)
        $jsonTargets   = json_encode(array_values($dispatchTargets));
        $jsonDispatch  = json_encode(array_values($cleanDispatch));

        $this->WriteAttributeString('DispatchTargetsBuffer', $jsonTargets);
        $this->WriteAttributeString('GroupDispatchBuffer',  $jsonDispatch);

        // Keep properties in sync (so backup/restore + Apply works)
        IPS_SetProperty($this->InstanceID, 'DispatchTargets', $jsonTargets);
        IPS_SetProperty($this->InstanceID, 'GroupDispatch',  $jsonDispatch);
        if ($this->ReadPropertyBoolean('DebugMode')) {
            $this->LogMessage(
                "DEBUG: ApplyChanges - Router Sync targets=" . count($dispatchTargets) .
                    " routes=" . count($cleanDispatch),
                KL_MESSAGE
            );
        }
        // Throttle config cleanup: keep only existing targets
        $targetThrottleList = json_decode((string)$this->ReadPropertyString('TargetThrottleList'), true);
        if (!is_array($targetThrottleList)) {
            $targetThrottleList = [];
        }

        $cleanThrottleList = [];
        foreach ($targetThrottleList as $row) {
            if (!is_array($row)) {
                continue;
            }

            $iid = (int)($row['InstanceID'] ?? 0);
            if ($iid <= 0 || !isset($validTargetIDs[$iid])) {
                continue;
            }

            $max = (int)($row['MaxMessages'] ?? 0);
            $win = (int)($row['WindowSeconds'] ?? 0);
            if ($max <= 0 || $win <= 0) {
                continue;
            }

            $cleanThrottleList[] = [
                'InstanceID'    => $iid,
                'MaxMessages'   => $max,
                'WindowSeconds' => $win
            ];
        }

        IPS_SetProperty($this->InstanceID, 'TargetThrottleList', json_encode(array_values($cleanThrottleList)));
        foreach (array_keys($groupIDMap) as $mappedGroupName) {
            if (!in_array($mappedGroupName, $validGroupNames, true)) {
                unset($groupIDMap[$mappedGroupName]);
                $idsChanged = true;
            }
        }

        // UI Glitch Notification
        $totalClasses = count($classList);
        $totalGroups = count($groupList);
        if (($totalClasses > 0 && $regenCountClasses == $totalClasses) || ($totalGroups > 0 && $regenCountGroups == $totalGroups)) {
            if ($this->ReadPropertyBoolean('DebugMode')) $this->LogMessage("CRITICAL: UI Data Loss detected. Restoring IDs and continuing synchronization.", KL_WARNING);
        }

        // Save Maps and Buffers
        $this->WriteAttributeString('ClassListBuffer', json_encode($classList));
        // --- FIX 2: Purge state entries for deleted classes (COUNT buffers etc.) ---
        // Keep only runtime state keys that are still valid ClassIDs.
        // Do NOT touch the mapping keys.
        foreach (array_keys($stateData) as $k) {
            if ($k === 'IDMap' || $k === 'GroupIDMap') {
                continue;
            }
            if (!in_array($k, $validClassIDs, true)) {
                unset($stateData[$k]);
                $idsChanged = true;
            }
        }
        $stateData['IDMap'] = $idMap;
        $stateData['GroupIDMap'] = $groupIDMap;
        $this->WriteAttributeString('ClassStateAttribute', json_encode($stateData));

        if ($idsChanged) {
            IPS_SetProperty($this->InstanceID, 'ClassList', json_encode($classList));
        }

        $originalGroupJson = json_encode(array_values($this->ReadConfigPropertyList('GroupList')));
        $healedGroupJson = json_encode(array_values($groupList));
        if ($healedGroupJson !== $originalGroupJson) {
            IPS_SetProperty($this->InstanceID, 'GroupList', $healedGroupJson);
        }

        // 2. GARBAGE COLLECTION & REPAIR
        $bufferJson = $this->ReadAttributeString('SensorListBuffer');
        $propJson = $this->ReadPropertyString('SensorList');
        $sensorList = json_decode($bufferJson, true) ?: json_decode($propJson, true) ?: [];

        $cleanSensors = [];
        $sensorsDirty = false;

        foreach ($sensorList as $s) {
            $sID = $s['ClassID'] ?? '';
            // Legacy support: in very old configs ClassID was stored as ClassName
            if (!in_array($sID, $validClassIDs, true) && is_string($sID) && isset($classNameMap[$sID])) {
                $s['ClassID'] = $classNameMap[$sID];
                $sID = $s['ClassID'];
                $sensorsDirty = true;
            }

            if (in_array($sID, $validClassIDs, true)) {
                $cleanSensors[] = $s;
            } else {
                $sensorsDirty = true;
            }
        }

        $json = json_encode($cleanSensors);
        IPS_SetProperty($this->InstanceID, 'SensorList', $json);
        $this->WriteAttributeString('SensorListBuffer', $json);

        $bedroomList = json_decode($this->ReadAttributeString('BedroomListBuffer'), true) ?: json_decode($this->ReadPropertyString('BedroomList'), true) ?: [];
        $cleanBedrooms = [];
        foreach ($bedroomList as $b) {
            if (in_array(($b['GroupName'] ?? ''), $validGroupNames, true)) {
                $cleanBedrooms[] = $b;
            }
        }
        $jsonBed = json_encode($cleanBedrooms);
        IPS_SetProperty($this->InstanceID, 'BedroomList', $jsonBed);
        $this->WriteAttributeString('BedroomListBuffer', $jsonBed);

        $groupMembers = $this->ReadConfigPropertyList('GroupMembers');
        $cleanMembers = [];

        foreach ($groupMembers as $m) {
            $gName = trim((string)($m['GroupName'] ?? ''));
            $cID   = trim((string)($m['ClassID'] ?? ''));

            if ($gName === '' || $cID === '') {
                continue;
            }

            // Conservative legacy heal only: old ClassName reference -> current ClassID
            if (!in_array($cID, $validClassIDs, true) && isset($classNameMap[$cID])) {
                $cID = $classNameMap[$cID];
                $m['ClassID'] = $cID;
                $idsChanged = true;
            }

            // Keep structurally meaningful rows; do not prune aggressively in this migration step
            $m['GroupName'] = $gName;
            $m['ClassID']   = $cID;
            $cleanMembers[] = $m;
        }

        $originalJsonMem = json_encode(array_values($groupMembers));
        $jsonMem = json_encode(array_values($cleanMembers));

        if ($jsonMem !== $originalJsonMem) {
            IPS_SetProperty($this->InstanceID, 'GroupMembers', $jsonMem);
        }
        // 3. REGISTRATION
        $messages = $this->GetMessageList();
        foreach ($messages as $senderID => $messageList) {
            $this->UnregisterMessage($senderID, VM_UPDATE);
        }
        foreach ($cleanSensors as $row) {
            if (($row['VariableID'] ?? 0) > 0 && IPS_VariableExists($row['VariableID'])) $this->RegisterMessage($row['VariableID'], VM_UPDATE);
        }
        $this->RegisterSensors('TamperList');
        foreach ($cleanBedrooms as $bed) {
            $vid = $bed['ActiveVariableID'] ?? 0;
            if ($vid > 0 && IPS_VariableExists($vid)) {
                $this->RegisterMessage($vid, VM_UPDATE);
            }
        }

        // 4. VARIABLES (Status)
        $keepIdents = ['Status', 'Sabotage', 'EventData'];
        $pos = 20;
        foreach ($groupList as $group) {
            if (empty($group['GroupName'])) continue;
            if ($this->ReadPropertyBoolean('DebugMode')) IPS_LogMessage('SensorGroup', 'DEBUG: ApplyChanges groupList sample=' . json_encode($groupList));
            $cleanName = $this->SanitizeIdent($group['GroupName']);
            $ident = "Status_" . $cleanName;
            $this->RegisterVariableBoolean($ident, "Status (" . $group['GroupName'] . ")", "~Alert", $pos++);
            $keepIdents[] = $ident;
        }

        $children = IPS_GetChildrenIDs($this->InstanceID);
        foreach ($children as $child) {
            $obj = IPS_GetObject($child);
            if ($obj['ObjectType'] == 2 && !in_array($obj['ObjectIdent'], $keepIdents)) $this->UnregisterVariable($obj['ObjectIdent']);
        }
        if ($this->ReadAttributeString('ClassStateAttribute') == '') $this->WriteAttributeString('ClassStateAttribute', '{}');
        if ($this->ReadAttributeString('LastMainStatus') === '') {
            $this->WriteAttributeString('LastMainStatus', '0');
        }
        // 5. RELOAD FORM
        $this->ReloadForm();
        $this->CheckLogic();
    }


    private function ReadTargetThrottleConfig(): array
    {
        $raw = (string)$this->ReadPropertyString('TargetThrottleList');
        $tmp = json_decode($raw, true);
        if (!is_array($tmp)) {
            return [];
        }

        $result = [];
        foreach ($tmp as $row) {
            if (!is_array($row)) {
                continue;
            }

            $iid = (int)($row['InstanceID'] ?? 0);
            $max = (int)($row['MaxMessages'] ?? 0);
            $win = (int)($row['WindowSeconds'] ?? 0);

            if ($iid <= 0 || $max <= 0 || $win <= 0) {
                continue;
            }

            $result[$iid] = [
                'InstanceID'    => $iid,
                'MaxMessages'   => $max,
                'WindowSeconds' => $win
            ];
        }

        return $result;
    }


    private function CanSendToTargetNow(int $instanceID): bool
    {
        $config = $this->ReadTargetThrottleConfig();

        // No throttle configured for this target -> always allow
        if (!isset($config[$instanceID])) {
            return true;
        }

        $maxMessages   = (int)$config[$instanceID]['MaxMessages'];
        $windowSeconds = (int)$config[$instanceID]['WindowSeconds'];

        if ($maxMessages <= 0 || $windowSeconds <= 0) {
            return true;
        }

        $rawState = (string)$this->ReadAttributeString('TargetThrottleState');
        $state = json_decode($rawState, true);
        if (!is_array($state)) {
            $state = [];
        }

        $now = microtime(true);
        $key = (string)$instanceID;

        $timestamps = $state[$key] ?? [];
        if (!is_array($timestamps)) {
            $timestamps = [];
        }

        // Keep only timestamps inside window
        $timestamps = array_values(array_filter($timestamps, function ($ts) use ($now, $windowSeconds) {
            return is_numeric($ts) && (($now - (float)$ts) < $windowSeconds);
        }));

        if (count($timestamps) >= $maxMessages) {
            // persist cleaned state even on reject
            $state[$key] = $timestamps;
            $this->WriteAttributeString('TargetThrottleState', json_encode($state));
            return false;
        }

        $timestamps[] = $now;
        $state[$key] = $timestamps;
        $this->WriteAttributeString('TargetThrottleState', json_encode($state));

        return true;
    }

    private function ReadConfigPropertyList(string $propName): array
    {
        $tmp = json_decode((string)$this->ReadPropertyString($propName), true);
        return is_array($tmp) ? $tmp : [];
    }
    private function GetMasterMetadata()
    {
        // FIX: Read from Buffer first to include unsaved (newly added) sensors
        $sensorList = json_decode($this->ReadAttributeString('SensorListBuffer'), true) ?:
            json_decode($this->ReadPropertyString('SensorList'), true) ?: [];

        $metadata = [];
        foreach ($sensorList as $s) {
            $vid = $s['VariableID'] ?? 0;
            if ($vid > 0 && IPS_VariableExists($vid)) {
                $parentID = IPS_GetParent($vid);
                $grandParentID = ($parentID > 0) ? IPS_GetParent($parentID) : 0;
                $metadata[$vid] = [
                    'DisplayID'       => (string)$vid,
                    'ParentName'      => ($parentID > 0) ? IPS_GetName($parentID) : "Root",
                    'GrandParentName' => ($grandParentID > 0) ? IPS_GetName($grandParentID) : "-"
                ];
            }
        }
        return $metadata;
    }


    public function RequestAction($Ident, $Value)
    {
        // === DEBUG: Flight recorder (ALWAYS log raw payload) ===
        if ($this->ReadPropertyBoolean('DebugMode')) IPS_LogMessage(
            'SensorGroup',
            "DEBUG: RequestAction ENTER Ident={$Ident} ValueType=" . gettype($Value) .
                " Value=" . (is_string($Value) ? $Value : json_encode($Value))
        );

        // === DEBUG: Snapshot of relevant buffers/properties at entry (quick state check) ===
        if ($this->ReadPropertyBoolean('DebugMode')) IPS_LogMessage(
            'SensorGroup',
            "DEBUG: STATE@ENTER " .
                "ClassListBufferLen=" . strlen($this->ReadAttributeString('ClassListBuffer')) .
                " ClassListPropLen=" . strlen($this->ReadPropertyString('ClassList')) .
                " GroupListBufferLen=" . strlen($this->ReadAttributeString('GroupListBuffer')) .
                " GroupListPropLen=" . strlen($this->ReadPropertyString('GroupList')) .
                " SensorListBufferLen=" . strlen($this->ReadAttributeString('SensorListBuffer')) .
                " SensorListPropLen=" . strlen($this->ReadPropertyString('SensorList'))
        );

        // 1) DYNAMIC IDENTIFIER HANDLING (Step 2 Folders: Sensors)
        if (strpos($Ident, 'UPD_SENS_') === 0 || strpos($Ident, 'DEL_SENS_') === 0 || strpos($Ident, 'DELETE_BY_INDEX_') === 0) {

            $isDeleteByID    = (strpos($Ident, 'DEL_SENS_') === 0);
            $isDeleteByIndex = (strpos($Ident, 'DELETE_BY_INDEX_') === 0);

            $safeID = $isDeleteByIndex ? substr($Ident, 16) : substr($Ident, 9);

            // === DEBUG: Identify sensor action type and safeID ===
            if ($this->ReadPropertyBoolean('DebugMode')) IPS_LogMessage(
                'SensorGroup',
                "DEBUG: SENSOR_ACTION Ident={$Ident} isDeleteByID=" . (int)$isDeleteByID .
                    " isDeleteByIndex=" . (int)$isDeleteByIndex . " safeID={$safeID}"
            );

            $classList = json_decode($this->ReadAttributeString('ClassListBuffer'), true)
                ?: json_decode($this->ReadPropertyString('ClassList'), true)
                ?: [];

            // === DEBUG: ClassList loaded size ===
            if ($this->ReadPropertyBoolean('DebugMode')) IPS_LogMessage('SensorGroup', "DEBUG: SENSOR_ACTION ClassListCount=" . (is_array($classList) ? count($classList) : -1));

            $classID = '';
            foreach ($classList as $c) {
                $checkID = !empty($c['ClassID']) ? $c['ClassID'] : ($c['ClassName'] ?? '');
                if ($checkID !== '' && md5($checkID) === $safeID) {
                    $classID = $checkID;
                    break;
                }
            }

            // === DEBUG: Resolved classID ===
            if ($this->ReadPropertyBoolean('DebugMode')) IPS_LogMessage('SensorGroup', "DEBUG: SENSOR_ACTION ResolvedClassID=" . ($classID !== '' ? $classID : 'NONE'));

            if ($classID === '') {
                if ($this->ReadPropertyBoolean('DebugMode')) IPS_LogMessage('SensorGroup', "DEBUG: SENSOR_ACTION ABORT - classID not found for safeID={$safeID}");
                return;
            }

            $fullBuffer = json_decode($this->ReadAttributeString('SensorListBuffer'), true) ?: [];

            // === DEBUG: Sensor buffer size before operation ===
            if ($this->ReadPropertyBoolean('DebugMode')) IPS_LogMessage('SensorGroup', "DEBUG: SENSOR_ACTION SensorListBufferCountBefore=" . (is_array($fullBuffer) ? count($fullBuffer) : -1));

            // Delete by VariableID
            if ($isDeleteByID) {
                $vidToDelete = (int)$Value;

                if ($this->ReadPropertyBoolean('DebugMode')) IPS_LogMessage('SensorGroup', "DEBUG: SENSOR_ACTION DeleteByID vidToDelete={$vidToDelete}");

                $newBuffer = [];
                $removed = 0;
                foreach ($fullBuffer as $row) {
                    if (($row['ClassID'] ?? '') === $classID && (int)($row['VariableID'] ?? 0) === $vidToDelete) {
                        $removed++;
                        continue;
                    }
                    $newBuffer[] = $row;
                }

                if ($this->ReadPropertyBoolean('DebugMode')) IPS_LogMessage('SensorGroup', "DEBUG: SENSOR_ACTION DeleteByID removed={$removed} newCount=" . count($newBuffer));

                $json = json_encode($newBuffer);
                $this->WriteAttributeString('SensorListBuffer', $json);
                IPS_SetProperty($this->InstanceID, 'SensorList', $json);

                // === DEBUG: After write ===
                if ($this->ReadPropertyBoolean('DebugMode')) IPS_LogMessage(
                    'SensorGroup',
                    "DEBUG: SENSOR_ACTION AfterWrite SensorListBufferLen=" . strlen($this->ReadAttributeString('SensorListBuffer')) .
                        " SensorListPropLen=" . strlen($this->ReadPropertyString('SensorList'))
                );

                $this->ReloadForm();
                return;
            }

            // Delete by index
            if ($isDeleteByIndex) {
                if ($Value === null || $Value === "") {
                    if ($this->ReadPropertyBoolean('DebugMode')) IPS_LogMessage('SensorGroup', "DEBUG: Deletion aborted - Index variable was null or empty.");
                    return;
                }

                $index  = (int)$Value;

                if ($this->ReadPropertyBoolean('DebugMode')) IPS_LogMessage('SensorGroup', "DEBUG: SENSOR_ACTION DeleteByIndex index={$index}");

                $others = array_values(array_filter($fullBuffer, function ($s) use ($classID) {
                    return ($s['ClassID'] ?? '') !== $classID;
                }));
                $target = array_values(array_filter($fullBuffer, function ($s) use ($classID) {
                    return ($s['ClassID'] ?? '') === $classID;
                }));

                if ($this->ReadPropertyBoolean('DebugMode')) IPS_LogMessage('SensorGroup', "DEBUG: SENSOR_ACTION DeleteByIndex targetCountBefore=" . count($target) . " othersCount=" . count($others));

                $didRemove = 0;
                if (isset($target[$index])) {
                    array_splice($target, $index, 1);
                    $didRemove = 1;
                }

                if ($this->ReadPropertyBoolean('DebugMode')) IPS_LogMessage('SensorGroup', "DEBUG: SENSOR_ACTION DeleteByIndex didRemove={$didRemove} targetCountAfter=" . count($target));

                $json = json_encode(array_merge($others, $target));
                $this->WriteAttributeString('SensorListBuffer', $json);
                IPS_SetProperty($this->InstanceID, 'SensorList', $json);

                // === DEBUG: After write ===
                if ($this->ReadPropertyBoolean('DebugMode')) IPS_LogMessage(
                    'SensorGroup',
                    "DEBUG: SENSOR_ACTION AfterWrite SensorListBufferLen=" . strlen($this->ReadAttributeString('SensorListBuffer')) .
                        " SensorListPropLen=" . strlen($this->ReadPropertyString('SensorList'))
                );

                $this->ReloadForm();
                return;
            }

            // UPDATE LOGIC: Keyed Merge - Only update if the VariableID exists in the buffer
            $incoming = json_decode($Value, true);

            // === DEBUG: Decode result ===
            if ($this->ReadPropertyBoolean('DebugMode')) IPS_LogMessage(
                'SensorGroup',
                "DEBUG: SENSOR_ACTION Update DecodeOk=" . (is_array($incoming) ? 1 : 0) .
                    " decodedType=" . gettype($incoming)
            );

            if (!$incoming) {
                if ($this->ReadPropertyBoolean('DebugMode')) IPS_LogMessage('SensorGroup', "DEBUG: SENSOR_ACTION Update ABORT - incoming not decodable");
                return;
            }

            $rowsToProcess = isset($incoming['VariableID']) ? [$incoming] : $incoming;

            if ($this->ReadPropertyBoolean('DebugMode')) IPS_LogMessage('SensorGroup', "DEBUG: SENSOR_ACTION Update rowsToProcessCount=" . (is_array($rowsToProcess) ? count($rowsToProcess) : -1));

            $metadata = $this->GetMasterMetadata();

            $updated = 0;
            foreach ($rowsToProcess as $inRow) {
                if (!is_array($inRow)) {
                    continue;
                }
                $inVID = (int)($inRow['VariableID'] ?? 0);
                foreach ($fullBuffer as &$exRow) {
                    if (($exRow['ClassID'] ?? '') === $classID && (int)($exRow['VariableID'] ?? 0) === $inVID) {
                        $exRow = array_merge($exRow, $inRow);
                        $exRow['ClassID'] = $classID;

                        if (!isset($exRow['TriggerMode'])) {
                            $exRow['TriggerMode'] = 0;
                        } else {
                            $exRow['TriggerMode'] = (int)$exRow['TriggerMode'];
                        }

                        if (!isset($exRow['PulseSeconds']) || (int)$exRow['PulseSeconds'] <= 0) {
                            $exRow['PulseSeconds'] = 1;
                        } else {
                            $exRow['PulseSeconds'] = (int)$exRow['PulseSeconds'];
                        }

                        if (isset($metadata[$inVID])) {
                            $exRow['DisplayID']       = $metadata[$inVID]['DisplayID'];
                            $exRow['ParentName']      = $metadata[$inVID]['ParentName'];
                            $exRow['GrandParentName'] = $metadata[$inVID]['GrandParentName'];
                        }
                        $updated++;
                        break;
                    }
                }
                unset($exRow);
            }

            if ($this->ReadPropertyBoolean('DebugMode')) IPS_LogMessage('SensorGroup', "DEBUG: SENSOR_ACTION Update updatedRows={$updated}");

            $json = json_encode($fullBuffer);
            $this->WriteAttributeString('SensorListBuffer', $json);
            IPS_SetProperty($this->InstanceID, 'SensorList', $json);

            // === DEBUG: After write ===
            if ($this->ReadPropertyBoolean('DebugMode')) IPS_LogMessage(
                'SensorGroup',
                "DEBUG: SENSOR_ACTION AfterWrite SensorListBufferLen=" . strlen($this->ReadAttributeString('SensorListBuffer')) .
                    " SensorListPropLen=" . strlen($this->ReadPropertyString('SensorList'))
            );

            return;
        }

        switch ($Ident) {

            // =========================
            // GROUP DEFINITIONS
            // =========================
            case 'UpdateGroupDispatch': {
                    $incoming = json_decode($Value, true);
                    if (!is_array($incoming)) {
                        if ($this->ReadPropertyBoolean('DebugMode')) IPS_LogMessage('SensorGroup', "DEBUG: UpdateGroupDispatch ABORT - incoming not array");
                        return;
                    }

                    // Normalize + validate rows (allow many rows per group, allow same InstanceID across groups)
                    $definedGroups = json_decode($this->ReadAttributeString('GroupListBuffer'), true)
                        ?: json_decode($this->ReadPropertyString('GroupList'), true)
                        ?: [];
                    $validGroupNames = [];
                    if (is_array($definedGroups)) {
                        foreach ($definedGroups as $g) {
                            $n = trim((string)($g['GroupName'] ?? ''));
                            if ($n !== '') $validGroupNames[$n] = true;
                        }
                    }

                    $clean = [];
                    $seen = []; // de-dup by GroupName||InstanceID
                    foreach ($incoming as $row) {
                        if (!is_array($row)) continue;

                        $gName = trim((string)($row['GroupName'] ?? ''));
                        $iid   = (int)($row['InstanceID'] ?? 0);

                        if ($gName === '' || $iid <= 0) continue;
                        if (!isset($validGroupNames[$gName])) continue;
                        // must be one of the defined DispatchTargets (Module 2 interfaces)
                        $dispatchTargets = json_decode($this->ReadAttributeString('DispatchTargetsBuffer'), true)
                            ?: json_decode($this->ReadPropertyString('DispatchTargets'), true)
                            ?: [];
                        $validTargetIDs = [];
                        if (is_array($dispatchTargets)) {
                            foreach ($dispatchTargets as $t) {
                                $tid = (int)($t['InstanceID'] ?? 0);
                                if ($tid > 0) $validTargetIDs[$tid] = true;
                            }
                        }
                        if (!isset($validTargetIDs[$iid])) continue;

                        $k = $gName . '||' . $iid;
                        if (isset($seen[$k])) continue;
                        $seen[$k] = true;

                        $clean[] = ['GroupName' => $gName, 'InstanceID' => $iid];
                    }

                    $json = json_encode(array_values($clean));
                    $this->WriteAttributeString('GroupDispatchBuffer', $json);
                    IPS_SetProperty($this->InstanceID, 'GroupDispatch', $json);

                    $this->ReloadForm();
                    break;
                }
            case 'UpdateDispatchTargets': {
                    $this->LogMessage("DEBUG [Dispatch]: UI sent UpdateDispatchTargets with payload: " . $Value, KL_MESSAGE);
                    $incoming = json_decode($Value, true);
                    if (!is_array($incoming)) {
                        if ($this->ReadPropertyBoolean('DebugMode')) IPS_LogMessage('SensorGroup', "DEBUG: UpdateDispatchTargets ABORT - incoming not array");
                        return;
                    }

                    // normalize rows (optional but safe)
                    $clean = [];
                    foreach ($incoming as $row) {
                        if (!is_array($row)) continue;
                        $name = trim((string)($row['Name'] ?? ''));
                        $iid  = (int)($row['InstanceID'] ?? 0);
                        if ($name === '' || $iid <= 0) continue;
                        $clean[] = ['Name' => $name, 'InstanceID' => $iid];
                    }

                    $json = json_encode(array_values($clean));
                    $this->LogMessage("DEBUG[Dispatch]: Saving to Buffer. Cleaned payload: " . $json, KL_MESSAGE);
                    $this->WriteAttributeString('DispatchTargetsBuffer', $json);
                    IPS_SetProperty($this->InstanceID, 'DispatchTargets', $json);

                    $this->ReloadForm();
                    break;
                }
            case 'UpdateGroupList': {
                    $incoming = json_decode($Value, true);
                    if (!is_array($incoming)) {
                        return;
                    }

                    $rowsToProcess = isset($incoming['GroupName']) ? [$incoming] : $incoming;
                    $master = $this->GetBufferedSectionList('GroupListBuffer', 'GroupList');

                    $stateData  = json_decode($this->ReadAttributeString('ClassStateAttribute'), true) ?: [];
                    $groupIDMap = $stateData['GroupIDMap'] ?? [];
                    if (!is_array($groupIDMap)) {
                        $groupIDMap = [];
                    }

                    $idxById   = [];
                    $idxByName = [];

                    foreach ($master as $i => $row) {
                        $name = trim((string)($row['GroupName'] ?? ''));
                        $gid  = trim((string)($row['GroupID'] ?? ''));

                        if ($gid !== '') {
                            $idxById[$gid] = $i;
                        }
                        if ($name !== '') {
                            $idxByName[$name] = $i;
                        }
                    }

                    foreach ($rowsToProcess as $inRow) {
                        if (!is_array($inRow)) {
                            continue;
                        }

                        unset($inRow['Spacer']);

                        $gName = trim((string)($inRow['GroupName'] ?? ''));
                        $gId   = trim((string)($inRow['GroupID'] ?? ''));

                        // Placeholder row -> ignore for session persistence
                        if ($gName === '') {
                            continue;
                        }

                        $inRow['GroupName'] = $gName;

                        // Stable technical identity inside the session
                        if ($gId === '') {
                            if (isset($groupIDMap[$gName]) && $groupIDMap[$gName] !== '') {
                                $gId = (string)$groupIDMap[$gName];
                            } else {
                                $gId = uniqid('grp_');
                                $groupIDMap[$gName] = $gId;
                            }
                            $inRow['GroupID'] = $gId;
                        } else {
                            $groupIDMap[$gName] = $gId;
                        }

                        if ($gId !== '' && isset($idxById[$gId])) {
                            $pos = $idxById[$gId];
                            $master[$pos] = array_merge($master[$pos], $inRow);
                            $idxByName[$gName] = $pos;
                            continue;
                        }

                        if (isset($idxByName[$gName])) {
                            $pos = $idxByName[$gName];
                            $existingGroupID = trim((string)($master[$pos]['GroupID'] ?? ''));
                            $master[$pos] = array_merge($master[$pos], $inRow);

                            // Preserve existing stable row identity if already present
                            if ($existingGroupID !== '') {
                                $master[$pos]['GroupID'] = $existingGroupID;
                                $gId = $existingGroupID;
                            }

                            if ($gId !== '') {
                                $idxById[$gId] = $pos;
                            }
                            $idxByName[$gName] = $pos;
                            continue;
                        }

                        $master[] = $inRow;
                        $newPos = count($master) - 1;
                        $idxByName[$gName] = $newPos;
                        if ($gId !== '') {
                            $idxById[$gId] = $newPos;
                        }
                    }

                    $stateData['GroupIDMap'] = $groupIDMap;
                    $this->WriteAttributeString('ClassStateAttribute', json_encode($stateData));

                    $this->WriteBufferedSectionList('GroupListBuffer', array_values($master));
                    $this->ReloadForm();
                    break;
                }

            case 'DeleteGroupListItemByName': {
                    $gNameToDelete = trim((string)$Value);
                    $master = $this->GetBufferedSectionList('GroupListBuffer', 'GroupList');

                    $newMaster = [];
                    foreach ($master as $row) {
                        if (trim((string)($row['GroupName'] ?? '')) !== $gNameToDelete) {
                            $newMaster[] = $row;
                        }
                    }

                    // Intentionally do NOT clean up GroupIDMap here.
                    // Mapping cleanup, if any, belongs to commit-time only.
                    $this->WriteBufferedSectionList('GroupListBuffer', array_values($newMaster));
                    $this->ReloadForm();
                    break;
                }

                // =========================
                // BEDROOMS
                // =========================
            case 'BedroomAdd': {
                    if ($this->ReadPropertyBoolean('DebugMode')) IPS_LogMessage('SensorGroup', "DEBUG: BedroomAdd START raw=" . (is_string($Value) ? $Value : json_encode($Value)));

                    $data = json_decode($Value, true);
                    if (!is_array($data)) {
                        if ($this->ReadPropertyBoolean('DebugMode')) IPS_LogMessage('SensorGroup', "DEBUG: BedroomAdd ABORT - payload not decodable");
                        return;
                    }

                    $gName = trim((string)($data['GroupName'] ?? ''));
                    $vid   = (int)($data['ActiveVariableID'] ?? 0);
                    $door  = (string)($data['BedroomDoorClassID'] ?? '');

                    if ($gName === '' || $vid <= 0) {
                        if ($this->ReadPropertyBoolean('DebugMode')) IPS_LogMessage('SensorGroup', "DEBUG: BedroomAdd ABORT - missing GroupName or ActiveVariableID (gName='{$gName}', vid={$vid})");
                        return;
                    }

                    // Load master from buffer (source of truth), fallback to property
                    $master = json_decode($this->ReadAttributeString('BedroomListBuffer'), true)
                        ?: json_decode($this->ReadPropertyString('BedroomList'), true)
                        ?: [];
                    if (!is_array($master)) {
                        $master = [];
                    }

                    // De-dup: if same GroupName + ActiveVariableID exists, update it; else append
                    $updated = false;
                    foreach ($master as &$row) {
                        if (!is_array($row)) {
                            continue;
                        }
                        if (($row['GroupName'] ?? '') === $gName && (int)($row['ActiveVariableID'] ?? 0) === $vid) {
                            $row['BedroomDoorClassID'] = $door;
                            $updated = true;
                            break;
                        }
                    }
                    unset($row);

                    if (!$updated) {
                        $master[] = [
                            'GroupName'          => $gName,
                            'ActiveVariableID'   => $vid,
                            'BedroomDoorClassID' => $door
                        ];
                    }

                    $json = json_encode(array_values($master));

                    $this->WriteAttributeString('BedroomListBuffer', $json);
                    IPS_SetProperty($this->InstanceID, 'BedroomList', $json);

                    // Trigger Symcon "dirty" flag via hidden property-bound field (MUST be JSON string)
                    $this->UpdateFormField('BedroomList', 'values', json_encode(json_decode($json, true) ?: []));

                    if ($this->ReadPropertyBoolean('DebugMode')) IPS_LogMessage('SensorGroup', "DEBUG: BedroomAdd END count=" . count($master) . " jsonLen=" . strlen($json));

                    // Optional: refresh UI immediately (doesn't persist by itself; persistence is via Apply)
                    $this->ReloadForm();
                    break;
                }
            case 'DeleteBedroomListItemByVarID': {
                    // Expect JSON payload: {"GroupName":"...", "ActiveVariableID":12345}
                    $data = json_decode($Value, true);
                    if (!is_array($data)) {
                        if ($this->ReadPropertyBoolean('DebugMode')) IPS_LogMessage('SensorGroup', "DEBUG: DeleteBedroomListItemByVarID ABORT - payload not decodable");
                        return;
                    }

                    $gName = trim((string)($data['GroupName'] ?? ''));
                    $vid   = (int)($data['ActiveVariableID'] ?? 0);

                    if ($gName === '' || $vid <= 0) {
                        if ($this->ReadPropertyBoolean('DebugMode')) IPS_LogMessage('SensorGroup', "DEBUG: DeleteBedroomListItemByVarID ABORT - missing GroupName or ActiveVariableID");
                        return;
                    }

                    $master = json_decode($this->ReadAttributeString('BedroomListBuffer'), true)
                        ?: json_decode($this->ReadPropertyString('BedroomList'), true)
                        ?: [];
                    if (!is_array($master)) {
                        $master = [];
                    }

                    $newMaster = [];
                    foreach ($master as $row) {
                        if (!is_array($row)) {
                            continue;
                        }
                        $rowGName = (string)($row['GroupName'] ?? '');
                        $rowVid   = (int)($row['ActiveVariableID'] ?? 0);

                        // remove only the matching row
                        if ($rowGName === $gName && $rowVid === $vid) {
                            continue;
                        }
                        $newMaster[] = $row;
                    }

                    $json = json_encode(array_values($newMaster));
                    $this->WriteAttributeString('BedroomListBuffer', $json);
                    IPS_SetProperty($this->InstanceID, 'BedroomList', $json);

                    // Trigger Symcon "dirty" flag via hidden property-bound field
                    $this->UpdateFormField('BedroomList', 'values', json_encode(json_decode($json, true) ?: []));

                    $this->ReloadForm();
                    break;
                }
            case 'UpdateBedroomProperty': {
                    $data  = json_decode($Value, true);
                    $gName = $data['GroupName'] ?? '';

                    $newValues = $data['Values'] ?? [];
                    if (isset($newValues['ActiveVariableID'])) {
                        $newValues = [$newValues];
                    } else {
                        $newValues = array_values($newValues);
                    }

                    $master = json_decode($this->ReadAttributeString('BedroomListBuffer'), true)
                        ?: json_decode($this->ReadPropertyString('BedroomList'), true)
                        ?: [];

                    $others = array_values(array_filter($master, function ($b) use ($gName) {
                        return ($b['GroupName'] ?? '') !== $gName;
                    }));

                    foreach ($newValues as &$row) {
                        if (is_array($row)) {
                            $row['GroupName'] = $gName;
                        }
                    }
                    unset($row);

                    $json = json_encode(array_merge($others, (array)$newValues));
                    $this->WriteAttributeString('BedroomListBuffer', $json);
                    IPS_SetProperty($this->InstanceID, 'BedroomList', $json);

                    // Trigger Symcon "dirty" flag via hidden property-bound field
                    $this->UpdateFormField('BedroomList', 'values', json_encode(json_decode($json, true) ?: []));
                    $this->ReloadForm();
                    break;
                }

            case 'UpdateMemberProperty': {
                    $data = json_decode($Value, true);
                    $gName = trim((string)($data['GroupName'] ?? ''));
                    $matrixValues = (isset($data['Values']['ClassID'])) ? [$data['Values']] : ($data['Values'] ?? []);

                    $master = $this->GetBufferedSectionList('GroupMembersBuffer', 'GroupMembers');

                    foreach ((array)$matrixValues as $row) {
                        if (!is_array($row)) {
                            continue;
                        }

                        $cID = trim((string)($row['ClassID'] ?? ''));
                        if ($gName === '' || $cID === '') {
                            continue;
                        }

                        $master = array_values(array_filter($master, function ($m) use ($gName, $cID) {
                            return !(
                                trim((string)($m['GroupName'] ?? '')) === $gName &&
                                trim((string)($m['ClassID'] ?? '')) === $cID
                            );
                        }));

                        if (!empty($row['Assigned'])) {
                            $master[] = [
                                'GroupName' => $gName,
                                'ClassID'   => $cID
                            ];
                        }
                    }

                    $this->WriteBufferedSectionList('GroupMembersBuffer', $master);

                    if ($this->ReadPropertyBoolean('DebugMode')) {
                        $this->LogMessage("DEBUG: GroupMembersBuffer updated rows=" . count($master), KL_MESSAGE);
                    }

                    $this->ReloadForm();
                    break;
                }

            case 'DeleteMemberListItem': {
                    $data = json_decode($Value, true);
                    $gName  = $data['GroupName'] ?? '';
                    $index  = (int)($data['Index'] ?? -1);
                    $master = json_decode($this->ReadAttributeString('GroupMembersBuffer'), true)
                        ?: json_decode($this->ReadPropertyString('GroupMembers'), true)
                        ?: [];
                    $others = array_values(array_filter($master, function ($m) use ($gName) {
                        return ($m['GroupName'] ?? '') !== $gName;
                    }));
                    $target = array_values(array_filter($master, function ($m) use ($gName) {
                        return ($m['GroupName'] ?? '') === $gName;
                    }));
                    if ($index >= 0 && isset($target[$index])) {
                        array_splice($target, $index, 1);
                    }
                    $json = json_encode(array_merge($others, $target));
                    $this->WriteAttributeString('GroupMembersBuffer', $json);
                    IPS_SetProperty($this->InstanceID, 'GroupMembers', $json);
                    $this->ReloadForm();
                    break;
                }

                // =========================
                // WIZARD
                // =========================
            case 'UpdateWizardList': {
                    $changes = json_decode($Value, true);
                    $cache = json_decode($this->ReadAttributeString('ScanCache'), true);
                    if (!is_array($cache)) {
                        $cache = [];
                    }
                    $map = [];
                    foreach ($cache as $idx => $row) {
                        $map[$row['VariableID']] = $idx;
                    }
                    if (is_array($changes)) {
                        if (isset($changes['VariableID'])) {
                            $changes = [$changes];
                        }
                        foreach ($changes as $change) {
                            if (isset($change['VariableID']) && isset($map[$change['VariableID']])) {
                                $idx = $map[$change['VariableID']];
                                $cache[$idx]['Selected'] = $change['Selected'];
                            }
                        }
                    }
                    $this->WriteAttributeString('ScanCache', json_encode(array_values($cache)));
                    break;
                }

            case 'FilterWizard': {
                    $FilterText = (string)$Value;
                    $fullList = json_decode($this->ReadAttributeString('ScanCache'), true);
                    if (!is_array($fullList)) {
                        return;
                    }
                    $filtered = [];
                    if (trim($FilterText) == "") {
                        $filtered = $fullList;
                    } else {
                        foreach ($fullList as $row) {
                            if (is_array($row) && (stripos($row['Name'], $FilterText) !== false || stripos((string)$row['VariableID'], $FilterText) !== false)) {
                                $filtered[] = $row;
                            }
                        }
                    }
                    $this->UpdateFormField('ImportCandidates', 'values', json_encode($filtered));
                    break;
                }

            default: {
                    // === DEBUG: catch unhandled idents ===
                    if ($this->ReadPropertyBoolean('DebugMode')) IPS_LogMessage('SensorGroup', "DEBUG: RequestAction DEFAULT (unhandled Ident={$Ident})");
                    break;
                }
        }
    }






    public function SaveConfiguration()
    {
        // 1. Load data from RAM Buffers (The stateless Source of Truth)
        // 1. Load data from RAM Buffers (The stateless Source of Truth)
        // IMPORTANT: do NOT use ?: [] on arrays, because [] is "falsey" and breaks empty-list semantics.
        $rawClassList       = (string)$this->ReadAttributeString('ClassListBuffer');
        $rawGroupList       = (string)$this->ReadAttributeString('GroupListBuffer');
        $rawSensorList      = (string)$this->ReadAttributeString('SensorListBuffer');
        $rawBedroomList     = (string)$this->ReadAttributeString('BedroomListBuffer');
        $rawGroupMembers    = (string)$this->ReadAttributeString('GroupMembersBuffer');
        $rawDispatchTargets = (string)$this->ReadPropertyString('DispatchTargets');

        $tmp = json_decode($rawClassList, true);
        $classList = (is_array($tmp) && $rawClassList !== '') ? $tmp : [];

        $tmp = json_decode($rawGroupList, true);
        $groupList = (is_array($tmp) && $rawGroupList !== '') ? $tmp : [];

        $tmp = json_decode($rawSensorList, true);
        $sensorList = (is_array($tmp) && $rawSensorList !== '') ? $tmp : [];

        $tmp = json_decode($rawBedroomList, true);
        $bedroomList = (is_array($tmp) && $rawBedroomList !== '') ? $tmp : [];

        $tmp = json_decode($rawGroupMembers, true);
        $groupMembers = (is_array($tmp) && $rawGroupMembers !== '') ? $tmp : [];

        $tmp = json_decode($rawDispatchTargets, true);
        $dispatchTargets = (is_array($tmp) && $rawDispatchTargets !== '') ? $tmp : [];
        unset($tmp);
        if ($this->ReadPropertyBoolean('DebugMode')) $this->LogMessage(
            "DEBUG: COMMIT PRECHECK - ClassListBuffer firstRow=" . json_encode($classList[0] ?? null),
            KL_MESSAGE
        );
        // DEBUG: Compare buffer vs property just before COMMIT writes anything
        $propGroupList = json_decode($this->ReadPropertyString('GroupList'), true) ?: [];
        if ($this->ReadPropertyBoolean('DebugMode')) $this->LogMessage(
            "DEBUG: COMMIT PRECHECK - GroupListBuffer=" . count($groupList) . " | GroupListProperty=" . count($propGroupList),
            KL_MESSAGE
        );
        if (count($groupList) > 0) {
            $last = end($groupList);
            if ($this->ReadPropertyBoolean('DebugMode')) $this->LogMessage("DEBUG: COMMIT PRECHECK - LastBufferGroup=" . json_encode($last), KL_MESSAGE);
        }
        if (count($propGroupList) > 0) {
            $lastP = end($propGroupList);
            if ($this->ReadPropertyBoolean('DebugMode')) $this->LogMessage("DEBUG: COMMIT PRECHECK - LastPropertyGroup=" . json_encode($lastP), KL_MESSAGE);
        }

        // DEBUG LOG: Verify buffer contents before committing to disk
        if ($this->ReadPropertyBoolean('DebugMode')) $this->LogMessage("DEBUG: COMMIT - Classes: " . count($classList) . " | Groups: " . count($groupList), KL_MESSAGE);
        if ($this->ReadPropertyBoolean('DebugMode')) $this->LogMessage("DEBUG: COMMIT - Sensors: " . count($sensorList) . " | Bedrooms: " . count($bedroomList) . " | Members: " . count($groupMembers), KL_MESSAGE);

        // 2. Final Label Healing (Source of Truth) + Sensor defaults
        $metadata = $this->GetMasterMetadata();
        foreach ($sensorList as &$s) {
            if (!isset($s['TriggerMode'])) {
                $s['TriggerMode'] = 0;
            } else {
                $s['TriggerMode'] = (int)$s['TriggerMode'];
            }

            if (!isset($s['PulseSeconds']) || (int)$s['PulseSeconds'] <= 0) {
                $s['PulseSeconds'] = 1;
            } else {
                $s['PulseSeconds'] = (int)$s['PulseSeconds'];
            }

            $vid = $s['VariableID'] ?? 0;
            if (isset($metadata[$vid])) {
                $s['DisplayID'] = $metadata[$vid]['DisplayID'];
                $s['ParentName'] = $metadata[$vid]['ParentName'];
                $s['GrandParentName'] = $metadata[$vid]['GrandParentName'];
            }
        }
        unset($s);


        // 3. FINAL STABILIZATION BEFORE COMMIT
        // Ensure ClassIDs/GroupIDs exist even if the UI list edit dropped hidden columns.

        // Load state maps (sticky IDs)
        $stateData = json_decode($this->ReadAttributeString('ClassStateAttribute'), true) ?: [];
        $idMap     = $stateData['IDMap'] ?? [];
        $groupIDMap = $stateData['GroupIDMap'] ?? [];

        if (!is_array($idMap)) $idMap = [];
        if (!is_array($groupIDMap)) $groupIDMap = [];

        // --- Heal ClassList IDs (by ClassName) ---
        $classNameToId = [];
        $classIdsFixed = false;

        foreach ($classList as &$c) {
            if (!is_array($c)) continue;

            $name = trim((string)($c['ClassName'] ?? ''));
            if ($name === '') continue;

            $cid = (string)($c['ClassID'] ?? '');

            if ($cid === '') {
                if (isset($idMap[$name]) && (string)$idMap[$name] !== '') {
                    $cid = (string)$idMap[$name];
                } else {
                    $cid = uniqid('cls_');
                }
                $c['ClassID'] = $cid;
                $classIdsFixed = true;
            }

            $idMap[$name] = $cid;
            $classNameToId[$name] = $cid;
        }
        unset($c);

        // --- Heal GroupList IDs (by GroupName) ---
        $groupIdsFixed = false;
        $cleanGroupList = [];

        foreach ($groupList as $g) {
            if (!is_array($g)) continue;

            $gName = trim((string)($g['GroupName'] ?? ''));
            if ($gName === '') continue; // placeholder row: do not persist

            $g['GroupName'] = $gName;

            $gId = trim((string)($g['GroupID'] ?? ''));
            if ($gId === '') {
                if (isset($groupIDMap[$gName]) && (string)$groupIDMap[$gName] !== '') {
                    $gId = (string)$groupIDMap[$gName];
                } else {
                    $gId = uniqid('grp_');
                }
                $g['GroupID'] = $gId;
                $groupIdsFixed = true;
            }

            $groupIDMap[$gName] = $gId;
            $cleanGroupList[] = $g;
        }

        $groupList = array_values($cleanGroupList);

        // --- Normalize SensorList ClassID: convert legacy ClassName references to real ClassID ---
        $sensorsFixed = false;
        foreach ($sensorList as &$s) {
            if (!is_array($s)) continue;

            $sid = $s['ClassID'] ?? '';
            // If it's not a cls_* id, treat it as legacy ClassName and map it
            if (is_string($sid) && $sid !== '' && strpos($sid, 'cls_') !== 0) {
                if (isset($classNameToId[$sid])) {
                    $s['ClassID'] = $classNameToId[$sid];
                    $sensorsFixed = true;
                }
            }
        }
        unset($s);

        // Persist updated maps if we changed anything
        if ($classIdsFixed || $groupIdsFixed) {
            $stateData['IDMap'] = $idMap;
            $stateData['GroupIDMap'] = $groupIDMap;
            $this->WriteAttributeString('ClassStateAttribute', json_encode($stateData));
        }

        // Keep buffers consistent with what we will commit
        if ($classIdsFixed) $this->WriteAttributeString('ClassListBuffer', json_encode($classList));
        if ($groupIdsFixed) $this->WriteAttributeString('GroupListBuffer', json_encode($groupList));
        if ($sensorsFixed)  $this->WriteAttributeString('SensorListBuffer', json_encode($sensorList));

        // Also include GroupDispatch in commit (you have it as a property)
        $groupDispatch = json_decode($this->ReadPropertyString('GroupDispatch'), true);
        if (!is_array($groupDispatch)) $groupDispatch = [];

        // --- FIXED: Deduplicate GroupMembers to prevent hidden stacking ---
        $uniqueMembers = [];
        $seenMembers = [];
        foreach ($groupMembers as $m) {
            $key = ($m['GroupName'] ?? '') . '::' . ($m['ClassID'] ?? '');
            if (!isset($seenMembers[$key])) {
                $seenMembers[$key] = true;
                $uniqueMembers[] = $m;
            }
        }
        $groupMembers = $uniqueMembers;
        $this->WriteAttributeString('GroupMembersBuffer', json_encode($groupMembers));

        // 4. Persist all verified data to Properties (Physical Disk)
        IPS_SetProperty($this->InstanceID, 'ClassList', json_encode($classList));
        $jsonGroupList = json_encode(array_values($groupList));
        IPS_SetProperty($this->InstanceID, 'GroupList', $jsonGroupList);
        $this->WriteAttributeString('GroupListBuffer', $jsonGroupList);
        IPS_SetProperty($this->InstanceID, 'SensorList', json_encode($sensorList));
        IPS_SetProperty($this->InstanceID, 'BedroomList', json_encode($bedroomList));
        $jsonGroupMembers = json_encode(array_values($groupMembers));
        IPS_SetProperty($this->InstanceID, 'GroupMembers', $jsonGroupMembers);
        $this->WriteAttributeString('GroupMembersBuffer', $jsonGroupMembers);
        IPS_SetProperty($this->InstanceID, 'DispatchTargets', json_encode($dispatchTargets));
        IPS_SetProperty($this->InstanceID, 'GroupDispatch', json_encode($groupDispatch));
        // 4. Force System Apply
        if (IPS_HasChanges($this->InstanceID)) {
            IPS_ApplyChanges($this->InstanceID);
            echo "Configuration saved and applied successfully.";
        } else {
            echo "No changes detected.";
        }
    }


    private function RegisterSensors($propName)
    {
        $list = json_decode($this->ReadPropertyString($propName), true);
        if (is_array($list)) {
            foreach ($list as $row) {
                if (($row['VariableID'] ?? 0) > 0 && IPS_VariableExists($row['VariableID'])) $this->RegisterMessage($row['VariableID'], VM_UPDATE);
            }
        }
    }

    private function SanitizeIdent($name)
    {
        $name = (string)$name; // <-- add this line
        return preg_replace('/[^a-zA-Z0-9]/', '', $name);
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        $val = null;
        $valType = 'n/a';
        $valFmt = 'n/a';

        if (IPS_VariableExists($SenderID)) {
            $val = GetValue($SenderID);
            $valType = gettype($val);
            $valFmt = @GetValueFormatted($SenderID);
        }

        if ($this->ReadPropertyBoolean('DebugMode')) $this->LogMessage(
            "MSINK: InstanceID={$this->InstanceID} SenderID={$SenderID} Msg={$Message} ValType={$valType} Val=" . json_encode($val) .
                " ValFmt=" . json_encode($valFmt) . " TS={$TimeStamp}",
            KL_MESSAGE
        );

        $this->CheckLogic($SenderID);
    }

    // Helper to determine the text based on Class Settings
    private function GetSmartLabel($vid, $mode)
    {
        if (!IPS_VariableExists($vid)) return "Unknown";
        switch ($mode) {
            case 1: // Parent Name (Location)
                $parentID = IPS_GetParent($vid);
                return ($parentID > 0) ? IPS_GetName($parentID) : "Root";
            case 2: // Status Text (Formatted Value)
                return GetValueFormatted($vid);
            case 0: // Sensor Name (Default)
            default:
                return IPS_GetName($vid);
        }
    }

    /**
     * Generates a restart-safe, monotonic token in RAM.
     * Epoch = Current Milliseconds.
     * Seq   = Monotonic counter (increments +1 for every event since module start).
     */
    private function GetNextEventToken()
    {
        $lockName = "Mod1_SeqLock_" . $this->InstanceID;

        if (IPS_SemaphoreEnter($lockName, 1000)) {

            // 1. Epoch is always NOW (Realtime Milliseconds)
            // This provides absolute wall-clock context for the event.
            $epoch = (string)round(microtime(true) * 1000);

            // 2. Increment Sequence in RAM
            // We read the buffer (defaults to 0 if empty), increment it, and write it back.
            // This ensures strict ordering even if two events happen in the same millisecond.
            $seq = (int)$this->GetBuffer('EventSeq');
            $seq++;
            $this->SetBuffer('EventSeq', (string)$seq);

            IPS_SemaphoreLeave($lockName);
            return ['epoch' => (int)$epoch, 'seq' => $seq];
        } else {
            // Fallback if semaphore fails
            if ($this->ReadPropertyBoolean('DebugMode')) {
                $this->LogMessage("CRITICAL: Could not acquire Semaphore for Event Token!", KL_ERROR);
            }
            return ['epoch' => (int)round(microtime(true) * 1000), 'seq' => 0];
        }
    }

    private function CheckLogic($TriggeringID = 0)
    {
        $classList = json_decode($this->ReadPropertyString('ClassList'), true);
        $sensorList = json_decode($this->ReadPropertyString('SensorList'), true);
        $tamperList = json_decode($this->ReadPropertyString('TamperList'), true);
        $groupList = json_decode($this->ReadPropertyString('GroupList'), true);
        $groupMembers = json_decode($this->ReadPropertyString('GroupMembers'), true);

        if ((int)$TriggeringID === 17847) {
            $rawClass = (string)$this->ReadPropertyString('ClassList');
            $rawSens  = (string)$this->ReadPropertyString('SensorList');
            $rawGroup = (string)$this->ReadPropertyString('GroupList');
            $rawMem   = (string)$this->ReadPropertyString('GroupMembers');

            if ($this->ReadPropertyBoolean('DebugMode')) $this->LogMessage("RAW: ClassList(len=" . strlen($rawClass) . ")=" . substr($rawClass, 0, 500), KL_MESSAGE);
            if ($this->ReadPropertyBoolean('DebugMode')) $this->LogMessage("RAW: SensorList(len=" . strlen($rawSens) . ")=" . substr($rawSens, 0, 500), KL_MESSAGE);
            if ($this->ReadPropertyBoolean('DebugMode')) $this->LogMessage("RAW: GroupList(len=" . strlen($rawGroup) . ")=" . substr($rawGroup, 0, 500), KL_MESSAGE);
            if ($this->ReadPropertyBoolean('DebugMode')) $this->LogMessage("RAW: GroupMembers(len=" . strlen($rawMem) . ")=" . substr($rawMem, 0, 500), KL_MESSAGE);

            if ($this->ReadPropertyBoolean('DebugMode')) $this->LogMessage(
                "RAW: FirstIDs classID=" . json_encode($classList[0]['ClassID'] ?? null) .
                    " className=" . json_encode($classList[0]['ClassName'] ?? null) .
                    " sensorVID=" . json_encode($sensorList[0]['VariableID'] ?? null) .
                    " sensorClassID=" . json_encode($sensorList[0]['ClassID'] ?? null) .
                    " memGroup=" . json_encode($groupMembers[0]['GroupName'] ?? null) .
                    " memClassID=" . json_encode($groupMembers[0]['ClassID'] ?? null),
                KL_MESSAGE
            );
        }

        $trigVal = null;
        $trigType = 'n/a';
        if ($TriggeringID > 0 && IPS_VariableExists($TriggeringID)) {
            $trigVal = GetValue($TriggeringID);
            $trigType = gettype($trigVal);
        }

        if ($this->ReadPropertyBoolean('DebugMode')) $this->LogMessage(
            "CHK: ENTER TrigID={$TriggeringID} TrigType={$trigType} TrigVal=" . json_encode($trigVal) .
                " classes=" . (is_array($classList) ? count($classList) : -1) .
                " sensors=" . (is_array($sensorList) ? count($sensorList) : -1) .
                " groups=" . (is_array($groupList) ? count($groupList) : -1) .
                " members=" . (is_array($groupMembers) ? count($groupMembers) : -1) .
                " maint=" . (int)$this->ReadPropertyBoolean('MaintenanceMode'),
            KL_MESSAGE
        );
        if ($this->ReadPropertyBoolean('DebugMode') && $TriggeringID > 0) {
            $this->LogMessage("DEBUG: CheckLogic called for TriggeringID={$TriggeringID}", KL_MESSAGE);
        }
        $classStates = json_decode($this->ReadAttributeString('ClassStateAttribute'), true);
        if (!is_array($classStates)) $classStates = [];

        $sabotageActive = false;
        if (is_array($tamperList)) {
            foreach ($tamperList as $row) {
                if ($this->CheckSensorRule($row)) {
                    $sabotageActive = true;
                    break;
                }
            }
        }
        $this->SetValue('Sabotage', $sabotageActive);

        $activeClasses = [];
        $classNameMap = [];
        $engineActiveSensors = []; // NEW: Keep track of every individual sensor that matches its rule
        $activeSensorDetailsMap = []; // variable_id => full sensor detail for all currently active concrete sensors        $activeSensorDetailsMap = []; // variable_id => full sensor detail for all currently active concrete sensors
        if (is_array($classList)) {
            foreach ($classList as $classDef) {
                $classID = $classDef['ClassID'] ?? '';
                if (empty($classID)) continue;

                $className = $classDef['ClassName'];
                $classNameMap[$classID] = $className;

                $logicMode = $classDef['LogicMode'];
                $labelMode = $classDef['LabelMode'] ?? 0;

                if (!isset($classStates[$classID])) $classStates[$classID] = ['Buffer' => []];

                $classSensors = [];
                if (is_array($sensorList)) {
                    foreach ($sensorList as $s) {
                        if (($s['ClassID'] ?? '') === $classID) {
                            $classSensors[] = $s;
                        }
                    }
                }

                $activeCount = 0;
                $total = count($classSensors);
                $triggerInClass = false;
                $lastTriggerDetails = null;

                foreach ($classSensors as $s) {
                    if (
                        $this->ReadPropertyBoolean('DebugMode') &&
                        $TriggeringID > 0 &&
                        (int)($s['VariableID'] ?? 0) === (int)$TriggeringID
                    ) {
                        $this->LogMessage(
                            "DEBUG: Trigger sensor row found in class loop VariableID=" . (int)$s['VariableID'] .
                                " ClassID=" . (string)($s['ClassID'] ?? '') .
                                " TriggerMode=" . (int)($s['TriggerMode'] ?? 0) .
                                " PulseSeconds=" . (int)($s['PulseSeconds'] ?? 0),
                            KL_MESSAGE
                        );
                    }
                    $match = $this->CheckSensorRule($s);

                    // FIX: Capture the Specific Trigger Event (Even if Match is FALSE/OFF)
                    // We need this because the "Group Loop" later only looks at Active classes.
                    // If we don't capture this here, an "OFF" event is masked by other "ON" sensors.
                    if ($TriggeringID > 0 && (int)($s['VariableID'] ?? 0) === (int)$TriggeringID) {
                        $pID = IPS_GetParent($TriggeringID);
                        $gpID = ($pID > 0) ? IPS_GetParent($pID) : 0;
                        $specificTriggerEvent = [
                            'variable_id' => $TriggeringID,
                            'value_raw'   => GetValue($TriggeringID),
                            'tag'         => $className,
                            'class_id'    => $classID,
                            'class_name'  => $className,
                            'var_name'    => IPS_GetName($TriggeringID),
                            'parent_name' => ($pID > 0) ? IPS_GetName($pID) : "Root",
                            'grandparent_name' => ($gpID > 0) ? IPS_GetName($gpID) : "",
                            'value_human' => GetValueFormatted($TriggeringID),
                            'smart_label' => $this->GetSmartLabel($TriggeringID, $labelMode)
                        ];
                    }

                    if ($TriggeringID > 0 && (int)($s['VariableID'] ?? 0) === (int)$TriggeringID) {
                        $curVal = null;
                        $curType = 'n/a';
                        if (IPS_VariableExists($TriggeringID)) {
                            $curVal = GetValue($TriggeringID);
                            $curType = gettype($curVal);
                        }

                        if ($this->ReadPropertyBoolean('DebugMode')) $this->LogMessage(
                            "CHK: EVAL TrigSensor vid={$TriggeringID} class={$className} classID={$classID}" .
                                " op=" . (string)($s['Operator'] ?? '') .
                                " target=" . json_encode($s['ComparisonValue'] ?? null) .
                                " curType={$curType} curVal=" . json_encode($curVal) .
                                " match=" . (int)$match,
                            KL_MESSAGE
                        );
                    }
                    if ($match) {
                        $vid = (int)($s['VariableID'] ?? 0);
                        $engineActiveSensors[] = $vid; // Capture the sensor ID for the dashboard
                        if ($this->ReadPropertyBoolean('DebugMode') && $vid === (int)$TriggeringID) {
                            $this->LogMessage("DEBUG: Trigger sensor added to engineActiveSensors VariableID={$vid}", KL_MESSAGE);
                        }
                        $activeCount++;

                        $parentID = IPS_GetParent($vid);
                        $grandParentID = ($parentID > 0) ? IPS_GetParent($parentID) : 0;

                        $sensorDetail = [
                            'variable_id' => $vid,
                            'value_raw'   => GetValue($vid),
                            'tag'         => $className,
                            'class_id'    => $classID,
                            'class_name'  => $className,
                            'var_name'    => IPS_GetName($vid),
                            'parent_name' => ($parentID > 0) ? IPS_GetName($parentID) : "Root",
                            'grandparent_name' => ($grandParentID > 0) ? IPS_GetName($grandParentID) : "",
                            'value_human' => GetValueFormatted($vid),
                            'smart_label' => $this->GetSmartLabel($vid, $labelMode)
                        ];

                        $activeSensorDetailsMap[$vid] = $sensorDetail;

                        // === BUGFIX 1: Capture details for ANY active sensor, prioritize specific trigger ===
                        if ($lastTriggerDetails === null || $vid == $TriggeringID) {
                            if ($vid == $TriggeringID) {
                                $triggerInClass = true;
                            }

                            $lastTriggerDetails = [
                                'variable_id' => $sensorDetail['variable_id'],
                                'value_raw'   => $sensorDetail['value_raw'],
                                'tag'         => $sensorDetail['tag'],
                                'class_id'    => $sensorDetail['class_id'],
                                'var_name'    => $sensorDetail['var_name'],
                                'parent_name' => $sensorDetail['parent_name'],
                                'grandparent_name' => $sensorDetail['grandparent_name'],
                                'value_human' => $sensorDetail['value_human'],
                                'smart_label' => $sensorDetail['smart_label']
                            ];
                        }
                    }
                }

                $isActive = false;
                if ($logicMode == 0) $isActive = ($activeCount > 0);
                elseif ($logicMode == 1) $isActive = ($total > 0 && $activeCount == $total);
                elseif ($logicMode == 2) {
                    $buffer = $classStates[$classID]['Buffer'] ?? [];
                    $window = $classDef['TimeWindow'];
                    $thresh = $classDef['Threshold'];
                    $now = time();
                    $buffer = array_filter($buffer, function ($ts) use ($now, $window) {
                        return ($now - $ts) <= $window;
                    });
                    if ($triggerInClass && $TriggeringID > 0) $buffer[] = $now;
                    if (count($buffer) >= $thresh) {
                        $isActive = true;
                        if ($triggerInClass && $lastTriggerDetails) {
                            $lastTriggerDetails['tag'] = "$className (Count)";
                        }
                    }
                    $classStates[$classID]['Buffer'] = array_values($buffer);
                }
                if ($isActive) $activeClasses[$classID] = $lastTriggerDetails;
            }
        }
        $this->WriteAttributeString('ClassStateAttribute', json_encode($classStates));

        // EXPORT THE PATH TO THE DASHBOARD
        $this->WriteAttributeString('ActiveClassesBuffer', json_encode(array_keys($activeClasses)));
        $this->WriteAttributeString('ActiveSensorsBuffer', json_encode(array_values(array_unique($engineActiveSensors))));

        $primaryPayload = null;
        $mainStatus = false;
        $activeGroups = [];

        $mergedGroups = [];
        if (is_array($groupList)) {
            foreach ($groupList as $row) {
                $name = $row['GroupName'];
                if (empty($name)) continue;
                $mergedGroups[$name] = [
                    'Classes' => [],
                    'Logic' => $row['GroupLogic']
                ];
            }
        }

        if (is_array($groupMembers)) {
            foreach ($groupMembers as $mem) {
                $gName = $mem['GroupName'];
                $cID = $mem['ClassID'];
                if (isset($mergedGroups[$gName])) $mergedGroups[$gName]['Classes'][] = $cID;
            }
        }

        foreach ($mergedGroups as $gName => $gData) {
            $gClasses = $gData['Classes'];
            $gLogic = $gData['Logic'];
            $activeClassCount = 0;
            $targetClassCount = count($gClasses);

            foreach ($gClasses as $reqClassID) {
                if (isset($activeClasses[$reqClassID])) {
                    $activeClassCount++;

                    // FIX: Priority Check.   If this class contains the specific TriggeringID, it overrides any previous "First Found" payload.
                    $details = $activeClasses[$reqClassID];
                    $isTrigger = ($TriggeringID > 0 && (int)($details['variable_id'] ?? 0) === (int)$TriggeringID);

                    if ($isTrigger) {
                        $primaryPayload = $details; // High Priority: This specific sensor caused the event
                    } elseif ($primaryPayload === null) {
                        $primaryPayload = $details; // Low Priority: First active sensor found (fallback)
                    }
                }
            }

            $groupActive = false;
            if ($targetClassCount > 0) {
                if ($gLogic == 0) $groupActive = ($activeClassCount > 0);
                elseif ($gLogic == 1) $groupActive = ($activeClassCount == $targetClassCount);
            }

            $ident = "Status_" . $this->SanitizeIdent($gName);
            if (@$this->GetIDForIdent($ident)) $this->SetValue($ident, $groupActive);

            if ($groupActive) {
                $activeGroups[] = $gName;
                $mainStatus = true;
            }
        }

        $this->SetValue('Status', $mainStatus);

        $classToActiveGroups = []; // class_id => [groupName => true]
        foreach ($mergedGroups as $gName => $gData) {
            if (!in_array($gName, $activeGroups, true)) {
                continue;
            }
            foreach (($gData['Classes'] ?? []) as $cID) {
                if (!isset($activeClasses[$cID])) {
                    continue;
                }
                if (!isset($classToActiveGroups[$cID])) {
                    $classToActiveGroups[$cID] = [];
                }
                $classToActiveGroups[$cID][$gName] = true;
            }
        }

        // === BEDROOM DISPATCH (Presence Sync) ===
        $bedTarget = (int)$this->ReadPropertyInteger('BedroomTarget');
        if ($bedTarget > 0 && IPS_InstanceExists($bedTarget)) {
            $bedList = json_decode($this->ReadPropertyString('BedroomList'), true) ?: [];

            // CHANGE B: Relevance Check
            // Only send Sync if Trigger is 0 (Full Sync), matches a Bedroom Switch, or matches a Bedroom Door Class
            $shouldSendSync = ($TriggeringID === 0);

            if (!$shouldSendSync) {
                // 1. Check Active Switches
                foreach ($bedList as $b) {
                    if ((int)($b['ActiveVariableID'] ?? 0) === $TriggeringID) {
                        $shouldSendSync = true;
                        break;
                    }
                }

                // 2. Check Door Sensors (via ClassID lookup)
                if (!$shouldSendSync) {
                    // Find which Class the trigger belongs to
                    $triggerClassID = '';
                    foreach ($sensorList as $s) {
                        if ((int)($s['VariableID'] ?? 0) === $TriggeringID) {
                            $triggerClassID = $s['ClassID'];
                            break;
                        }
                    }

                    if ($triggerClassID !== '') {
                        foreach ($bedList as $b) {
                            if (($b['BedroomDoorClassID'] ?? '') === $triggerClassID) {
                                $shouldSendSync = true;
                                break;
                            }
                        }
                    }
                }
            }

            if ($shouldSendSync) {
                $bedStates = [];
                foreach ($bedList as $bed) {
                    $cID = $bed['BedroomDoorClassID'] ?? '';
                    if ($cID === '') continue;

                    $vid = (int)($bed['ActiveVariableID'] ?? 0);
                    $bedStates[] = [
                        'GroupName'   => $bed['GroupName'],
                        'SwitchState' => ($vid > 0 && IPS_VariableExists($vid)) ? (bool)GetValue($vid) : false,
                        'DoorTripped' => isset($activeClasses[$cID]) // Checked: activeClasses uses ClassID as key
                    ];
                }

                if (count($bedStates) > 0) {
                    $token = $this->GetNextEventToken();
                    $payloadJson = json_encode([
                        'event_type'  => 'BEDROOM_SYNC',
                        'event_epoch' => $token['epoch'],
                        'event_seq'   => $token['seq'],
                        'timestamp'   => time(),
                        'source_id'   => $this->InstanceID,
                        'bedrooms'    => $bedStates
                    ]);

                    $this->SetValue('EventData', $payloadJson);
                    try {
                        @IPS_RequestAction($bedTarget, 'ReceivePayload', $payloadJson);
                    } catch (Throwable $e) {
                    }
                }
            }
        }

        // === DISPATCH (Module 2 Routing) ===
        $groupDispatch = json_decode($this->ReadPropertyString('GroupDispatch'), true) ?: [];
        $dispatchMap = [];
        foreach ($groupDispatch as $row) {
            if (!is_array($row)) continue;
            $g = trim((string)($row['GroupName'] ?? ''));
            $iid = (int)($row['InstanceID'] ?? 0);
            if ($g === '' || $iid <= 0) continue;
            $dispatchMap[$g][$iid] = true;
        }

        // Initialize targets container for both ALARM and RESET
        $targetsToSend = [];
        $payloadJson = '';
        ksort($activeSensorDetailsMap, SORT_NUMERIC);
        $globalActiveSensorDetails = [];
        foreach ($activeSensorDetailsMap as $vid => $detail) {
            $cID = (string)($detail['class_id'] ?? '');
            $groupNames = isset($classToActiveGroups[$cID]) ? array_values(array_keys($classToActiveGroups[$cID])) : [];
            sort($groupNames, SORT_STRING);
            $detail['group_names'] = $groupNames;
            $globalActiveSensorDetails[] = $detail;
        }
        if ($mainStatus) {
            $readableActiveClasses = [];
            foreach (array_keys($activeClasses) as $aid) {
                $readableActiveClasses[] = $classNameMap[$aid] ?? $aid;
            }

            $finalTriggerDetails = (isset($specificTriggerEvent) && $specificTriggerEvent !== null)
                ? $specificTriggerEvent
                : $primaryPayload;

            $token = $this->GetNextEventToken();
            $payload = [
                'event_type'  => 'ALARM',
                'event_epoch' => $token['epoch'],
                'event_seq'   => $token['seq'],
                'event_id'    => uniqid(),
                'timestamp'   => time(),
                'source_id' => $this->InstanceID,
                'source_name' => IPS_GetName($this->InstanceID),
                'primary_class' => $finalTriggerDetails['tag'] ?? 'General',
                'primary_group' => $activeGroups[0] ?? 'General',
                'active_classes' => $readableActiveClasses,
                'active_groups' => $activeGroups,
                'is_maintenance' => $this->ReadPropertyBoolean('MaintenanceMode'),
                'trigger_details' => $finalTriggerDetails,
                'active_sensor_details' => array_values($activeSensorDetailsMap)
            ];
            $this->SetValue('EventData', json_encode($payload));
            $payloadJson = json_encode($payload);

            foreach ($activeGroups as $gName) {
                if (isset($dispatchMap[$gName])) {
                    foreach (array_keys($dispatchMap[$gName]) as $iid) {
                        $targetsToSend[(int)$iid] = true;
                    }
                } elseif ($this->ReadPropertyBoolean('DebugMode')) {
                    $this->LogMessage("DEBUG [Dispatch]: Group '{$gName}' is Active but has NO route.", KL_WARNING);
                }
            }
        } else {
            $token = $this->GetNextEventToken();
            $payload = [
                'event_type'  => 'ALARM',
                'event_epoch' => $token['epoch'],
                'event_seq'   => $token['seq'],
                'event_id'    => uniqid(),
                'timestamp'   => time(),
                'source_id' => $this->InstanceID,
                'source_name' => IPS_GetName($this->InstanceID),
                'primary_class' => '',
                'primary_group' => '',
                'active_classes' => [],
                'active_groups' => [],
                'is_maintenance' => $this->ReadPropertyBoolean('MaintenanceMode'),
                'trigger_details' => null,
                'active_sensor_details' => []
            ];
            $this->SetValue('EventData', json_encode($payload));
            $payloadJson = json_encode($payload);

            foreach ($dispatchMap as $targets) {
                foreach (array_keys($targets) as $iid) {
                    $targetsToSend[(int)$iid] = true;
                }
            }
        }

        // Final Dispatch Execution
        $lastPayloadJson = '';

        // Build authoritative target list from all routed targets
        $allRoutedTargets = [];
        foreach ($dispatchMap as $groupTargets) {
            foreach (array_keys($groupTargets) as $iid) {
                $allRoutedTargets[(int)$iid] = true;
            }
        }

        // Load previous target-local projection state
        $lastTargetProjectionState = json_decode($this->ReadAttributeString('LastTargetProjectionState'), true);
        if (!is_array($lastTargetProjectionState)) {
            $lastTargetProjectionState = [];
        }

        $newTargetProjectionState = [];

        foreach (array_keys($allRoutedTargets) as $iid) {
            $iid = (int)$iid;
            if (!IPS_InstanceExists($iid)) {
                continue;
            }

            $targetActiveGroups = [];
            foreach ($activeGroups as $gName) {
                if (isset($dispatchMap[$gName][$iid])) {
                    $targetActiveGroups[] = $gName;
                }
            }

            $targetActiveClassesMap = [];
            $targetActiveSensorDetailsMap = [];

            foreach ($activeSensorDetailsMap as $vid => $detail) {
                $cID = (string)($detail['class_id'] ?? '');
                $groupNamesForTarget = [];

                if (isset($classToActiveGroups[$cID])) {
                    foreach (array_keys($classToActiveGroups[$cID]) as $gName) {
                        if (isset($dispatchMap[$gName][$iid])) {
                            $groupNamesForTarget[] = $gName;
                        }
                    }
                }

                if (count($groupNamesForTarget) > 0) {
                    sort($groupNamesForTarget, SORT_STRING);
                    $detail['group_names'] = array_values($groupNamesForTarget);
                    $targetActiveSensorDetailsMap[(int)$vid] = $detail;
                    $targetActiveClassesMap[$cID] = $classNameMap[$cID] ?? $cID;
                }
            }

            ksort($targetActiveSensorDetailsMap, SORT_NUMERIC);
            $targetActiveSensorDetails = array_values($targetActiveSensorDetailsMap);

            $targetActiveClasses = array_values($targetActiveClassesMap);
            sort($targetActiveClasses, SORT_STRING);

            $targetTriggerDetails = null;
            if ($mainStatus) {
                if (is_array($finalTriggerDetails)) {
                    $anchorVid = (int)($finalTriggerDetails['variable_id'] ?? 0);
                    if ($anchorVid > 0 && isset($targetActiveSensorDetailsMap[$anchorVid])) {
                        $targetTriggerDetails = $finalTriggerDetails;
                    }
                }

                if ($targetTriggerDetails === null && count($targetActiveSensorDetails) > 0) {
                    $first = $targetActiveSensorDetails[0];
                    $targetTriggerDetails = [
                        'variable_id' => $first['variable_id'],
                        'value_raw'   => $first['value_raw'],
                        'tag'         => $first['tag'],
                        'class_id'    => $first['class_id'],
                        'class_name'  => $first['class_name'],
                        'var_name'    => $first['var_name'],
                        'parent_name' => $first['parent_name'],
                        'grandparent_name' => $first['grandparent_name'],
                        'value_human' => $first['value_human'],
                        'smart_label' => $first['smart_label']
                    ];
                }
            }

            $projection = [
                'target_active_groups' => $mainStatus ? $targetActiveGroups : [],
                'target_active_classes' => $mainStatus ? $targetActiveClasses : [],
                'target_trigger_details' => $mainStatus ? $targetTriggerDetails : null,
                'target_active_sensor_details' => $mainStatus ? $targetActiveSensorDetails : []
            ];

            $newTargetProjectionState[(string)$iid] = $projection;

            $oldProjection = $lastTargetProjectionState[(string)$iid] ?? null;
            $projectionChanged = (json_encode($projection) !== json_encode($oldProjection));

            if (!$projectionChanged) {
                if ($this->ReadPropertyBoolean('DebugMode')) {
                    $this->LogMessage("DEBUG [Dispatch EXEC]: Skipping InstanceID=$iid (target projection unchanged)", KL_MESSAGE);
                }
                continue;
            }

            if ($this->ReadPropertyBoolean('DebugMode')) {
                $this->LogMessage("DEBUG [Dispatch EXEC]: Sending to InstanceID=$iid", KL_MESSAGE);
            }

            $payloadForTarget = $payload;
            $payloadForTarget['target_instance_id'] = $iid;
            $payloadForTarget['target_active_groups'] = $projection['target_active_groups'];
            $payloadForTarget['target_active_classes'] = $projection['target_active_classes'];
            $payloadForTarget['target_trigger_details'] = $projection['target_trigger_details'];
            $payloadForTarget['target_active_sensor_details'] = $projection['target_active_sensor_details'];

            $payloadJsonForTarget = json_encode($payloadForTarget);
            $lastPayloadJson = $payloadJsonForTarget;

            if ($this->ReadPropertyBoolean('DebugMode')) {
                $this->LogMessage(
                    "DEBUG [Dispatch EXEC]: InstanceID=$iid target_groups=" . count($payloadForTarget['target_active_groups']) .
                        " target_classes=" . count($payloadForTarget['target_active_classes']) .
                        " target_sensors=" . count($payloadForTarget['target_active_sensor_details']),
                    KL_MESSAGE
                );
            }

            if (!$this->CanSendToTargetNow($iid)) {
                if ($this->ReadPropertyBoolean('DebugMode')) {
                    $this->LogMessage(
                        "DEBUG [Throttle]: Skipping dispatch to InstanceID={$iid} because target rate limit is active",
                        KL_WARNING
                    );
                }
                continue;
            }

            try {
                @IPS_RequestAction($iid, 'ReceivePayload', $payloadJsonForTarget);
            } catch (Throwable $e) {
                if ($this->ReadPropertyBoolean('DebugMode')) {
                    $this->LogMessage("DISPATCH ERROR: Target {$iid} exception: " . $e->getMessage(), KL_WARNING);
                }
            }
        }

        // Persist latest target-local projection state
        $this->WriteAttributeString('LastTargetProjectionState', json_encode($newTargetProjectionState));

        if ($lastPayloadJson !== '') {
            $this->SetValue('EventData', $lastPayloadJson);
        }
    }

    private function ReadLastSensorValueMap(): array
    {
        $data = json_decode($this->ReadAttributeString('LastSensorValueMap'), true);
        return is_array($data) ? $data : [];
    }

    private function WriteLastSensorValueMap(array $map): void
    {
        $this->WriteAttributeString('LastSensorValueMap', json_encode($map));
    }

    private function ReadSensorPulseUntilMap(): array
    {
        $data = json_decode($this->ReadAttributeString('SensorPulseUntilMap'), true);
        return is_array($data) ? $data : [];
    }

    private function WriteSensorPulseUntilMap(array $map): void
    {
        $this->WriteAttributeString('SensorPulseUntilMap', json_encode($map));
    }

    private function GetVariableTypeName($value): string
    {
        if (is_bool($value)) {
            return 'boolean';
        }
        if (is_int($value)) {
            return 'integer';
        }
        if (is_float($value)) {
            return 'float';
        }
        return 'string';
    }

    private function ValuesAreDifferent(string $oldType, $oldValue, $newValue): bool
    {
        switch ($oldType) {
            case 'boolean':
                return ((bool)$oldValue !== (bool)$newValue);

            case 'integer':
                return ((int)$oldValue !== (int)$newValue);

            case 'float':
                return (abs((float)$oldValue - (float)$newValue) > 0.000001);

            case 'string':
            default:
                return ((string)$oldValue !== (string)$newValue);
        }
    }

    private function CheckSensorRule($row)
    {
        $id = (int)($row['VariableID'] ?? 0);
        if ($id <= 0 || !IPS_VariableExists($id)) {
            return false;
        }

        $triggerMode = isset($row['TriggerMode']) ? (int)$row['TriggerMode'] : 0;
        $pulseSeconds = isset($row['PulseSeconds']) ? (int)$row['PulseSeconds'] : 1;
        if ($pulseSeconds <= 0) {
            $pulseSeconds = 1;
        }

        $val = GetValue($id);

        // LEVEL = existing behavior
        if ($triggerMode === 0) {
            if (isset($row['Invert'])) {
                return $row['Invert'] ? !$val : $val;
            }
            return $this->EvaluateRule($val, $row['Operator'], $row['ComparisonValue']);
        }

        // CHANGE = pulse on typed value change
        $typeName = $this->GetVariableTypeName($val);
        $lastValueMap = $this->ReadLastSensorValueMap();
        $pulseUntilMap = $this->ReadSensorPulseUntilMap();
        $now = time();

        $key = (string)$id;
        $lastEntry = $lastValueMap[$key] ?? null;
        if ($this->ReadPropertyBoolean('DebugMode')) {
            $this->LogMessage(
                "DEBUG: CSR ENTER VariableID={$id}" .
                    " TriggerMode={$triggerMode}" .
                    " PulseSeconds={$pulseSeconds}" .
                    " CurrentValue=" . json_encode($val) .
                    " LastEntry=" . json_encode($lastEntry) .
                    " PulseUntil=" . json_encode($pulseUntilMap[$key] ?? null),
                KL_MESSAGE
            );
        }
        // First observation: initialize cache only, no trigger
        if (!is_array($lastEntry) || !array_key_exists('type', $lastEntry) || !array_key_exists('value', $lastEntry)) {
            $lastValueMap[$key] = [
                'type'  => $typeName,
                'value' => $val
            ];
            $this->WriteLastSensorValueMap($lastValueMap);

            if (isset($pulseUntilMap[$key]) && (int)$pulseUntilMap[$key] <= $now) {
                unset($pulseUntilMap[$key]);
                $this->WriteSensorPulseUntilMap($pulseUntilMap);
            }

            return false;
        }

        $oldType = (string)$lastEntry['type'];
        $oldValue = $lastEntry['value'];
        $changed = ($oldType !== $typeName) || $this->ValuesAreDifferent($oldType, $oldValue, $val);
        if ($this->ReadPropertyBoolean('DebugMode')) {
            $this->LogMessage(
                "DEBUG: CSR CHANGE CHECK VariableID={$id}" .
                    " OldType={$oldType}" .
                    " NewType={$typeName}" .
                    " OldValue=" . json_encode($oldValue) .
                    " NewValue=" . json_encode($val) .
                    " Changed=" . (int)$changed,
                KL_MESSAGE
            );
        }
        // Always refresh cached last value/type
        $lastValueMap[$key] = [
            'type'  => $typeName,
            'value' => $val
        ];
        $this->WriteLastSensorValueMap($lastValueMap);

        if ($changed) {
            $pulseUntilMap[$key] = $now + $pulseSeconds;
            $this->WriteSensorPulseUntilMap($pulseUntilMap);

            if ($this->ReadPropertyBoolean('DebugMode')) {
                $this->LogMessage(
                    "DEBUG: CHANGE sensor triggered VariableID={$id} type={$typeName} pulseUntil=" . $pulseUntilMap[$key],
                    KL_MESSAGE
                );
            }

            return true;
        }

        $pulseUntil = isset($pulseUntilMap[$key]) ? (int)$pulseUntilMap[$key] : 0;
        if ($this->ReadPropertyBoolean('DebugMode')) {
            $this->LogMessage(
                "DEBUG: CSR PULSE WINDOW VariableID={$id}" .
                    " Now={$now}" .
                    " PulseUntil={$pulseUntil}" .
                    " Active=" . (int)($pulseUntil > $now),
                KL_MESSAGE
            );
        }
        if ($pulseUntil > $now) {
            return true;
        }

        if ($pulseUntil > 0) {
            unset($pulseUntilMap[$key]);
            $this->WriteSensorPulseUntilMap($pulseUntilMap);
        }

        return false;
    }

    private function EvaluateRule($current, $operator, $target)
    {
        if (is_bool($current)) $target = ($target === 'true' || $target === '1' || $target === 1);
        elseif (is_float($current) || is_int($current)) $target = (float)$target;
        switch ($operator) {
            case 0:
                return $current == $target;
            case 1:
                return $current != $target;
            case 2:
                return $current > $target;
            case 3:
                return $current < $target;
            case 4:
                return $current >= $target;
            case 5:
                return $current <= $target;
            default:
                return false;
        }
    }

    private function RegisterHook($WebHook)
    {
        $ids = IPS_GetInstanceListByModuleID("{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}"); // Webhook Control Core Module
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


    /**
     * Webhook Entry Point: Renders the Hierarchy Chart
     */
    protected function ProcessHookData()
    {
        $this->linkCounter = 0;

        // 1. Authentication (Secrets / Vault)
        $vaultID = $this->ReadPropertyInteger("VaultInstanceID");
        if ($vaultID > 0 && @IPS_InstanceExists($vaultID)) {
            if (function_exists('SEC_IsPortalAuthenticated')) {
                if (!SEC_IsPortalAuthenticated($vaultID)) {
                    $currentUrl = $_SERVER['REQUEST_URI'] ?? '';
                    $currentUrl = strtok($currentUrl, '?');
                    $loginUrl = "/hook/secrets_" . $vaultID . "?portal=1&return=" . urlencode($currentUrl);
                    header("Location: " . $loginUrl);
                    exit;
                }
            }
        }

        // 2. Build the Graph Data
        $graph = "graph RL\n";

        $graph .= "classDef red fill:#c62828,stroke:#ff8a80,stroke-width:2px,color:#fff;\n";
        $graph .= "classDef green fill:#2e7d32,stroke:#a5d6a7,stroke-width:2px,color:#fff;\n";
        $graph .= "classDef grey fill:#37474f,stroke:#546e7a,stroke-width:1px,color:#eee;\n";
        $graph .= "classDef target fill:#1565c0,stroke:#90caf9,stroke-width:2px,color:#fff;\n";

        $conf = json_decode($this->GetConfiguration(), true);

        // FIX: Initialize ALL lists globally so they exist even if filtering is skipped
        $bedroomList     = $conf['BedroomList'] ?? [];
        $dispatchTargets = $conf['DispatchTargets'] ?? [];
        $groupDispatch   = $conf['GroupDispatch'] ?? [];
        $groupMembers    = $conf['GroupMembers'] ?? [];
        $sensorList      = $conf['SensorList'] ?? [];
        $depth = isset($_GET['depth']) ? strtolower((string)$_GET['depth']) : 'sensors';
        if (!in_array($depth, ['groups', 'classes', 'sensors'], true)) {
            $depth = 'sensors';
        }

        $showBedrooms = isset($_GET['showBedrooms']) ? ((string)$_GET['showBedrooms'] === '1') : true;

        $stateFilter = isset($_GET['stateFilter']) ? strtolower((string)$_GET['stateFilter']) : 'both';
        if (!in_array($stateFilter, ['both', 'active', 'passive'], true)) {
            $stateFilter = 'both';
        }
        // --- OPTIONAL TARGET FILTER (API ONLY) ---
        if (isset($_GET['api']) && isset($_GET['targetFilter'])) {
            $filterStr = (string)$_GET['targetFilter'];
            $allowedTargets = ($filterStr === '' || $filterStr === 'NONE') ? [] : array_filter(explode(',', $filterStr), 'strlen');
            // --- FILTERING LOGIC ---
            $bedroomList     = $conf['BedroomList'] ?? [];

            // --- FILTERING LOGIC ---

            // 1) Targets
            $dispatchTargets = array_values(array_filter($dispatchTargets, function ($t) use ($allowedTargets) {
                return in_array((string)($t['InstanceID'] ?? ''), $allowedTargets, true);
            }));

            // If nothing selected -> return a tiny placeholder graph
            if (count($dispatchTargets) === 0) {
                header("Content-Type: text/plain");
                echo "graph RL\nEMPTY[\"No Targets Selected\"]:::grey\n";
                return;
            }

            // 2) Groups routed to allowed targets
            $allowedGroups = [];
            $groupDispatch = array_values(array_filter($groupDispatch, function ($d) use ($allowedTargets, &$allowedGroups) {
                $ok = in_array((string)($d['InstanceID'] ?? ''), $allowedTargets, true);
                if ($ok) $allowedGroups[(string)($d['GroupName'] ?? '')] = true;
                return $ok;
            }));

            // FIX: Explicitly allow Bedroom Groups if the Bedroom Target is selected
            $bedTargetID = $conf['BedroomTarget'] ?? 0;
            if ($bedTargetID > 0 && in_array((string)$bedTargetID, $allowedTargets, true)) {
                foreach ($bedroomList as $b) {
                    $gName = (string)($b['GroupName'] ?? '');
                    if ($gName !== '') $allowedGroups[$gName] = true;
                }
            }

            // 3) Classes belonging to allowed groups
            $allowedClasses = [];
            $groupMembers = array_values(array_filter($groupMembers, function ($m) use ($allowedGroups, &$allowedClasses) {
                $gName = (string)($m['GroupName'] ?? '');
                if (isset($allowedGroups[$gName])) {
                    $allowedClasses[(string)($m['ClassID'] ?? '')] = true;
                    return true;
                }
                return false;
            }));

            // 4) Sensors belonging to allowed classes
            $sensorList = array_values(array_filter($sensorList, function ($s) use ($allowedClasses) {
                return isset($allowedClasses[(string)($s['ClassID'] ?? '')]);
            }));

            // 5) Bedroom settings belonging to allowed groups
            $bedroomList = array_values(array_filter($bedroomList, function ($b) use ($allowedGroups) {
                return isset($allowedGroups[(string)($b['GroupName'] ?? '')]);
            }));

            // Write back pruned arrays so your existing loops stay unchanged

            // Write back pruned arrays so your existing loops stay unchanged
            $conf['DispatchTargets'] = $dispatchTargets;
            $conf['GroupDispatch']   = $groupDispatch;
            $conf['GroupMembers']    = $groupMembers;
            $conf['SensorList']      = $sensorList;
        }

        $classMap = [];
        foreach ($conf['ClassList'] as $c) $classMap[$c['ClassID']] = $c;

        $groupMap = [];
        foreach ($conf['GroupList'] as $g) $groupMap[$g['GroupName']] = $g;

        // PULL LIVE STATE FROM THE RULE ENGINE
        $engineActiveClasses = json_decode($this->ReadAttributeString('ActiveClassesBuffer'), true) ?: [];
        $engineActiveSensors = json_decode($this->ReadAttributeString('ActiveSensorsBuffer'), true) ?: [];
        $showByState = function (bool $isActive) use ($stateFilter): bool {
            if ($stateFilter === 'active') {
                return $isActive;
            }
            if ($stateFilter === 'passive') {
                return !$isActive;
            }
            return true;
        };

        $targetHasActiveGroup = [];
        foreach ($groupDispatch as $d) {
            $gName = (string)($d['GroupName'] ?? '');
            $iid   = (int)($d['InstanceID'] ?? 0);
            if ($gName === '' || $iid <= 0) {
                continue;
            }

            $ident = "Status_" . $this->SanitizeIdent($gName);
            $isGroupActive = (@$this->GetIDForIdent($ident) && GetValue($this->GetIDForIdent($ident)));
            if ($isGroupActive) {
                $targetHasActiveGroup[$iid] = true;
            }
        }
        // A. Dispatch Targets
        foreach ($conf['DispatchTargets'] as $t) {
            $iid = (int)($t['InstanceID'] ?? 0);
            $isTargetActive = isset($targetHasActiveGroup[$iid]);

            if (!$showByState($isTargetActive)) {
                continue;
            }

            $tid = "T_" . $iid;
            $label = $t['Name'] . "<br/>[" . $iid . "]";
            $graph .= $tid . "[\"" . $label . "\"]:::target\n";
        }

        // B1. Define ALL Groups (Nodes)
        // This ensures internal groups (like Bedroom) get labels even if they aren't in GroupDispatch
        $allGroups = $conf['GroupList'] ?? [];
        foreach ($allGroups as $g) {
            $gName = $g['GroupName'];

            // If filtering is active, skip groups that aren't in the allowed list
            if (isset($allowedGroups) && !isset($allowedGroups[$gName])) continue;

            $gid = "G_" . md5($gName);
            $ident = "Status_" . $this->SanitizeIdent($gName);
            $isActive = (@$this->GetIDForIdent($ident) && GetValue($this->GetIDForIdent($ident)));
            $style = $isActive ? "red" : "green";
            if (!$showByState($isActive)) continue;
            $gLogic = ((int)($g['GroupLogic'] ?? 0) == 1) ? "AND" : "OR";
            $label = "$gName<br/>[$gLogic]";

            $graph .= $gid . "[\"" . $label . "\"]:::" . $style . "\n";
        }

        // B2. Draw Group -> Target Connections
        foreach ($groupDispatch as $d) {
            $gName = (string)($d['GroupName'] ?? '');
            $iid   = (int)($d['InstanceID'] ?? 0);

            $ident = "Status_" . $this->SanitizeIdent($gName);
            $isActive = (@$this->GetIDForIdent($ident) && GetValue($this->GetIDForIdent($ident)));

            if (!$showByState($isActive)) {
                continue;
            }

            $isTargetActive = isset($targetHasActiveGroup[$iid]);
            if (!$showByState($isTargetActive)) {
                continue;
            }

            $tid = "T_" . $iid;
            $gid = "G_" . md5($gName);

            $graph .= $gid . " --> " . $tid . "\n";

            $linkIdx = $this->linkCounter++;
            if ($isActive) {
                $graph .= "linkStyle $linkIdx stroke:#ff8a80,stroke-width:2px;\n";
            }
        }

        // E. Draw Bedroom Active Switches
        if ($showBedrooms && $depth === 'sensors') {
            foreach ($bedroomList as $b) {
                $gName = $b['GroupName'];
                $gid = "G_" . md5($gName);
                $vid = (int)($b['ActiveVariableID'] ?? 0);
                $sid = "BS_" . $vid;

                $val = ($vid > 0 && IPS_VariableExists($vid)) ? GetValue($vid) : false;
                $style = $val ? "red" : "green";
                $name = ($vid > 0 && IPS_VariableExists($vid)) ? IPS_GetName($vid) : "MISSING";
                if (!$showByState((bool)$val)) continue;
                $label = "$name ($vid)<br/>[Bedroom Switch]";
                $graph .= $sid . "[\"" . $label . "\"]:::" . $style . " --> " . $gid . "\n";

                $linkIdx = $this->linkCounter++;
                if ($val) $graph .= "linkStyle $linkIdx stroke:#ff8a80,stroke-width:2px;\n";
            }
        }

        // B3. Draw Bedroom Group -> Global Target Connections
        $bedTargetID = (int)($conf['BedroomTarget'] ?? 0);
        if ($showBedrooms && $bedTargetID > 0) {
            $targetIsVisible = false;
            foreach ($dispatchTargets as $dt) {
                if ((int)$dt['InstanceID'] === $bedTargetID) {
                    $targetIsVisible = true;
                    break;
                }
            }

            if ($targetIsVisible) {
                $tid = "T_" . $bedTargetID;
                foreach ($bedroomList as $b) {
                    $gName = $b['GroupName'];
                    $gid = "G_" . md5($gName);

                    $ident = "Status_" . $this->SanitizeIdent($gName);
                    $isActive = (@$this->GetIDForIdent($ident) && GetValue($this->GetIDForIdent($ident)));

                    if (!$showByState($isActive)) {
                        continue;
                    }

                    $isTargetActive = isset($targetHasActiveGroup[$bedTargetID]);
                    if (!$showByState($isTargetActive)) {
                        continue;
                    }

                    $graph .= $gid . " --> " . $tid . "\n";

                    $linkIdx = $this->linkCounter++;
                    if ($isActive) {
                        $graph .= "linkStyle $linkIdx stroke:#ff8a80,stroke-width:2px;\n";
                    }
                }
            }
        }

        // C. Draw Classes
        if ($depth === 'classes' || $depth === 'sensors') {
            foreach ($conf['GroupMembers'] as $m) {
                $gName = $m['GroupName'];
                $cID = $m['ClassID'];
                $gid = "G_" . md5($gName);
                $cidNode = "C_" . substr(md5($cID), 0, 8);

                if (!isset($classMap[$cID])) continue;

                $cDef = $classMap[$cID];

                $lMode = (int)($cDef['LogicMode'] ?? 0);
                if ($lMode === 1) {
                    $cLogic = "AND";
                } elseif ($lMode === 2) {
                    $cLogic = "COUNT:" . ($cDef['Threshold'] ?? 1);
                } else {
                    $cLogic = "OR";
                }

                $cLabel = $cDef['ClassName'] . "<br/>[$cLogic | " . $cDef['TimeWindow'] . "s]";

                $isClassActive = in_array($cID, $engineActiveClasses);
                $cStyle = $isClassActive ? "red" : "grey";
                if (!$showByState($isClassActive)) continue;
                $graph .= $cidNode . "[\"" . $cLabel . "\"]:::" . $cStyle . " --> " . $gid . "\n";

                $linkIdx = $this->linkCounter++;
                if ($isClassActive) $graph .= "linkStyle $linkIdx stroke:#ff8a80,stroke-width:2px;\n";
            }
        }

        // D. Sensors -> Classes
        if ($depth === 'sensors') {
            foreach ($conf['SensorList'] as $s) {
                $cID = $s['ClassID'];
                $cidNode = "C_" . substr(md5($cID), 0, 8);
                $vid = $s['VariableID'];
                $sid = "S_" . $vid;

                $isActive = in_array($vid, $engineActiveSensors);
                $style = $isActive ? "red" : "green";
                if (!$showByState($isActive)) continue;
                $opMap = ['=', '!=', '>', '<', '>=', '<='];
                $rule = $opMap[$s['Operator']] . " " . $s['ComparisonValue'];
                $name = IPS_VariableExists($vid) ? IPS_GetName($vid) : "MISSING";

                $pName = $s['ParentName'] ?? 'Unknown';
                $gpName = $s['GrandParentName'] ?? 'Unknown';

                $label = "$name ($vid)<br/>[$gpName / $pName]<br/>$rule";
                $graph .= $sid . "[\"" . $label . "\"]:::" . $style . " --> " . $cidNode . "\n";

                $linkIdx = $this->linkCounter++;
                if ($isActive) $graph .= "linkStyle $linkIdx stroke:#ff8a80,stroke-width:2px;\n";
            }
        }

        // 3. API Mode (AJAX Request)
        if (isset($_GET['api'])) {
            header("Content-Type: text/plain");
            echo $graph;
            return;
        }

        // 4. Output HTML Framework
        $checkboxesHTML = '';
        if (isset($conf['DispatchTargets']) && is_array($conf['DispatchTargets'])) {
            foreach ($conf['DispatchTargets'] as $t) {
                $iid  = $t['InstanceID'];
                $name = htmlspecialchars((string)($t['Name'] ?? ('Target ' . $iid)));
                $checkboxesHTML .= "<label style=\"margin:0 12px; cursor:pointer;\">
            <input type=\"checkbox\" class=\"target-filter\" value=\"{$iid}\" checked onchange=\"forceRefresh()\"> {$name}
        </label>";
            }
        }
        echo '<!DOCTYPE html>
                            <html>
                            <head>
                            <meta charset="utf-8">
                            <title>Sensor Flow</title>
                            <style>
                                body{
                                background-color:#1e1e1e;color:#cfcfcf;font-family:"Segoe UI",sans-serif;
                                margin:0;padding:20px;height:100vh;box-sizing:border-box;
                                overflow:hidden;display:flex;flex-direction:column;
                                }
                                .header{flex-shrink:0;text-align:center;margin-bottom:12px;border-bottom:1px solid #333;padding-bottom:10px;}
                                .header h2{margin:0;color:#4CAF50;}
                            .filter-bar{ background:#333; padding:10px; border-radius:6px; display:inline-block; margin-top:10px; }
                            .filter-bar input{ transform:scale(1.15); margin-right:6px; }
                            .container{
                                        flex-grow:1;background:#252526;border-radius:8px;width:100%;
                                        border:1px solid #444;overflow:hidden;position:relative;
                                        }
                                        /* FIX: Tighter container bounds and explicit block display for SVG */
                                        #mermaid-container { position:absolute; inset:0; overflow:hidden; }
                                        #mermaid-container svg { width:100% !important; height:100% !important; max-width:none !important; display:block; }
                            </style>

                            <!-- Pan/Zoom lib (creates global svgPanZoom()) -->
                            <script src="https://unpkg.com/svg-pan-zoom@3.6.1/dist/svg-pan-zoom.min.js"></script>

                            <script type="module">
                                import mermaid from "https://cdn.jsdelivr.net/npm/mermaid@10/dist/mermaid.esm.min.mjs";

                                mermaid.initialize({
                                startOnLoad:false,
                                theme:"dark",
                                flowchart:{ curve:"basis", nodeSpacing:60, rankSpacing:120 }
                                });

                            let isRendering=false;
                            let lastGraphString="";
                            let pzInstance=null;

                            window.getFilterString = function () {
                            const boxes = document.querySelectorAll(".target-filter:checked");
                            if (boxes.length === 0) return "NONE";
                            return Array.from(boxes).map(b => b.value).join(",");
                            };
                            window.getDepthFilter = function () {
                                const el = document.getElementById("depth-filter");
                                return el ? el.value : "sensors";
                            };

                            window.getBedroomFilter = function () {
                                const el = document.getElementById("bedroom-filter");
                                return (el && el.checked) ? "1" : "0";
                            };
                            window.getStateFilter = function () {
                                const el = document.getElementById("state-filter");
                                return el ? el.value : "both";
                            };
                            window.forceRefresh = function () {
                            lastGraphString = "";
                            fetchAndUpdateGraph();
                            };

                            window.setAllTargets = function (checked) {
                            document.querySelectorAll(".target-filter").forEach(b => b.checked = checked);
                            window.forceRefresh();
                            };

                                async function fetchAndUpdateGraph(){
                                if(isRendering) return;

                                try{
                                    // IMPORTANT: keep this on the same hook URL; only add query params
                                    const url = location.pathname
                                    + "?api=1&t=" + Date.now()
                                    + "&targetFilter=" + encodeURIComponent(window.getFilterString())
                                    + "&depth=" + encodeURIComponent(window.getDepthFilter())
                                    + "&showBedrooms=" + encodeURIComponent(window.getBedroomFilter())
                                    + "&stateFilter=" + encodeURIComponent(window.getStateFilter());
                                    const response = await fetch(url);
                                    const graphString = await response.text();

                                    if(graphString !== lastGraphString){
                                    isRendering=true;
                                    lastGraphString=graphString;

                                    const container = document.getElementById("mermaid-container");

                                    // preserve zoom/pan between rerenders
                                    const oldZoom = pzInstance ? pzInstance.getZoom() : null;
                                    const oldPan  = pzInstance ? pzInstance.getPan()  : null;

                                    if(pzInstance){ pzInstance.destroy(); pzInstance=null; }

                                    const renderId = "graph_" + Date.now();
                                    const { svg } = await mermaid.render(renderId, graphString);

                                    container.innerHTML = svg;

                            const svgEl = container.querySelector("svg");
                                            if (svgEl) {
                                                // Ensure a viewBox exists (svg-pan-zoom works best with it)
                                                if (!svgEl.getAttribute("viewBox")) {
                                                const wRaw = (svgEl.width && svgEl.width.baseVal && svgEl.width.baseVal.value) || svgEl.getAttribute("width") || 1000;
                                                const hRaw = (svgEl.height && svgEl.height.baseVal && svgEl.height.baseVal.value) || svgEl.getAttribute("height") || 1000;

                                                const w = Number(String(wRaw).replace(/[^0-9.]/g, "")) || 1000;
                                                const h = Number(String(hRaw).replace(/[^0-9.]/g, "")) || 1000;

                                                svgEl.setAttribute("viewBox", `0 0 ${w} ${h}`);
                                                }

                                                // Remove hard pixel sizing so CSS 100% can take over
                                                svgEl.removeAttribute("width");
                                                svgEl.removeAttribute("height");

                                                // Don’t nuke the entire style; just neutralize sizing constraints if present
                                                svgEl.style.width = "100%";
                                                svgEl.style.height = "100%";
                                                svgEl.style.maxWidth = "none";

                            // Determine if this is the initial load or a live update
                                                let isFirstLoad = (oldZoom === null || oldPan === null);

                                                pzInstance = svgPanZoom(svgEl, {
                                                zoomEnabled: true,
                                                controlIconsEnabled: true,
                                                fit: isFirstLoad,       // Only auto-fit on initial load
                                                center: isFirstLoad,    // Only auto-center on initial load
                                                minZoom: 0.2,
                                                maxZoom: 10,
                                                eventsListenerElement: container
                                                });

                                                // Important: force recalculation of container bounds
                                                pzInstance.resize();

                                                if (isFirstLoad) {
                                                // First time seeing the chart -> center it beautifully
                                                pzInstance.fit();
                                                pzInstance.center();
                                                } else {
                                                // Live update -> freeze the camera exactly where the user left it
                                                pzInstance.zoom(oldZoom);
                                                pzInstance.pan(oldPan);
                                                }
                                            }
                                    }
                                }catch(err){
                                    console.error("Failed to render graph:", err);
                                }finally{
                                    isRendering=false;
                                }
                                }

                                fetchAndUpdateGraph();
                                setInterval(fetchAndUpdateGraph, 2000);
                            </script>
                            </head>

                            <body>
                            <div class="header">
                                <h2>System Hierarchy (Live)</h2>
                                <small>Instance ID: ' . $this->InstanceID . '</small>
                            <br>
                            <div class="filter-bar">
                            <a href="#" onclick="setAllTargets(true); return false;" style="color:#9ecbff; margin-right:12px;">All</a>
                            <a href="#" onclick="setAllTargets(false); return false;" style="color:#9ecbff; margin-right:18px;">None</a>
                            ' . $checkboxesHTML . '
                            <span style="margin-left:18px;">Depth:</span>
                            <select id="depth-filter" onchange="forceRefresh()" style="margin-left:8px;">
                                <option value="groups">Groups</option>
                                <option value="classes">Classes</option>
                                <option value="sensors" selected>Sensors</option>
                            </select>

                            <span style="margin-left:18px;">State:</span>
                            <select id="state-filter" onchange="forceRefresh()" style="margin-left:8px;">
                                <option value="both" selected>Both</option>
                                <option value="active">Only Active</option>
                                <option value="passive">Only Passive</option>
                            </select>

                            <label style="margin-left:18px; cursor:pointer;">
                                <input type="checkbox" id="bedroom-filter" checked onchange="forceRefresh()"> Bedrooms
                            </label>
                            </div>
                            </div>
                            <div class="container">
                                <div id="mermaid-container">Initializing Live View...</div>
                            </div>
                            </body>
                            </html>';
    }




    public function GetConfigurationForm()
    {
        // === DEBUG: Enter GetConfigurationForm ===
        if ($this->ReadPropertyBoolean('DebugMode')) IPS_LogMessage('SensorGroup', 'DEBUG: GetConfigurationForm ENTER InstanceID=' . $this->InstanceID);

        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);

        // === DEBUG: form.json loaded ===
        if ($this->ReadPropertyBoolean('DebugMode')) IPS_LogMessage(
            'SensorGroup',
            'DEBUG: GetConfigurationForm form.json loaded ' .
                'elements=' . (isset($form['elements']) && is_array($form['elements']) ? count($form['elements']) : -1) .
                ' actions=' . (isset($form['actions']) && is_array($form['actions']) ? count($form['actions']) : 0)
        );

        // Blueprint 2.0: Prioritize RAM Buffers (Source of Truth)
        // Classes: prefer PROPERTY if it contains newer/more rows than buffer
        $clBuf  = json_decode($this->ReadAttributeString('ClassListBuffer'), true);
        $clProp = json_decode($this->ReadPropertyString('ClassList'), true);
        if (!is_array($clBuf))  $clBuf  = [];
        if (!is_array($clProp)) $clProp = [];
        $definedClasses = (count($clProp) >= count($clBuf)) ? $clProp : $clBuf;

        $definedGroups = $this->GetBufferedSectionList('GroupListBuffer', 'GroupList');

        if ($this->ReadPropertyBoolean('DebugMode')) IPS_LogMessage('SensorGroup', 'DEBUG: ClassListBuffer RAW=' . $this->ReadAttributeString('ClassListBuffer'));
        if ($this->ReadPropertyBoolean('DebugMode')) IPS_LogMessage('SensorGroup', 'DEBUG: ClassListProperty RAW=' . $this->ReadPropertyString('ClassList'));
        // === DEBUG: initial source-of-truth counts ===
        if ($this->ReadPropertyBoolean('DebugMode')) IPS_LogMessage(
            'SensorGroup',
            'DEBUG: GetConfigurationForm initial SoT counts ' .
                'definedClasses=' . (is_array($definedClasses) ? count($definedClasses) : -1) .
                ' definedGroups=' . (is_array($definedGroups) ? count($definedGroups) : -1) .
                ' attr_ClassListBufferLen=' . strlen((string)$this->ReadAttributeString('ClassListBuffer')) .
                ' prop_ClassListLen=' . strlen((string)$this->ReadPropertyString('ClassList')) .
                ' attr_GroupListBufferLen=' . strlen((string)$this->ReadAttributeString('GroupListBuffer')) .
                ' prop_GroupListLen=' . strlen((string)$this->ReadPropertyString('GroupList'))
        );
        // SensorList: prefer buffer if it is a valid array (even if empty). Else fallback to property.
        $raw = (string)$this->ReadAttributeString('SensorListBuffer');
        $tmp = json_decode($raw, true);
        if (is_array($tmp)) {
            $sensorList = $tmp;
        } else {
            $tmp2 = json_decode((string)$this->ReadPropertyString('SensorList'), true);
            $sensorList = is_array($tmp2) ? $tmp2 : [];
        }
        unset($raw, $tmp, $tmp2);

        // BedroomList: prefer buffer if it is a valid array (even if empty). Else fallback to property.
        $raw = (string)$this->ReadAttributeString('BedroomListBuffer');
        $tmp = json_decode($raw, true);
        if (is_array($tmp)) {
            $bedroomList = $tmp;
        } else {
            $tmp2 = json_decode((string)$this->ReadPropertyString('BedroomList'), true);
            $bedroomList = is_array($tmp2) ? $tmp2 : [];
        }
        unset($raw, $tmp, $tmp2);
        // === FIX: Ensure a property-bound (hidden) BedroomList field exists in "elements"
        // This is required so Symcon can show the orange "Apply changes" button when bedrooms change.
        $hasBedroomField = false;
        foreach ($form['elements'] as $e) {
            if (isset($e['name']) && $e['name'] === 'BedroomList') {
                $hasBedroomField = true;
                break;
            }
        }

        if (!$hasBedroomField) {
            $form['elements'][] = [
                "type"     => "List",
                "name"     => "BedroomList",
                "visible"  => false,
                "rowCount" => 1,
                "add"      => false,
                "delete"   => false,
                "columns"  => [
                    ["caption" => "GroupName",          "name" => "GroupName",          "width" => "150px"],
                    ["caption" => "ActiveVariableID",   "name" => "ActiveVariableID",   "width" => "120px"],
                    ["caption" => "BedroomDoorClassID", "name" => "BedroomDoorClassID", "width" => "150px"]
                ],
                "values" => []
            ];
        }
        $groupMembers = $this->GetBufferedSectionList('GroupMembersBuffer', 'GroupMembers');
        // FIX: Dispatch lists are standard properties. Buffer fallback resurrects deleted "zombie" rows.
        $dispatchTargets = json_decode($this->ReadPropertyString('DispatchTargets'), true);
        if (!is_array($dispatchTargets)) $dispatchTargets = [];

        $groupDispatch = json_decode($this->ReadPropertyString('GroupDispatch'), true);
        if (!is_array($groupDispatch)) $groupDispatch = [];

        // === DEBUG: other list counts ===
        if ($this->ReadPropertyBoolean('DebugMode')) IPS_LogMessage(
            'SensorGroup',
            'DEBUG: GetConfigurationForm list counts ' .
                'sensorList=' . (is_array($sensorList) ? count($sensorList) : -1) .
                ' bedroomList=' . (is_array($bedroomList) ? count($bedroomList) : -1) .
                ' groupMembers=' . (is_array($groupMembers) ? count($groupMembers) : -1)
        );

        // Blueprint 2.0 - Step 2: Label Healing + Sensor defaults
        $metadata = $this->GetMasterMetadata();
        foreach ($sensorList as &$s) {
            if (!isset($s['TriggerMode'])) {
                $s['TriggerMode'] = 0;
            } else {
                $s['TriggerMode'] = (int)$s['TriggerMode'];
            }

            if (!isset($s['PulseSeconds']) || (int)$s['PulseSeconds'] <= 0) {
                $s['PulseSeconds'] = 1;
            } else {
                $s['PulseSeconds'] = (int)$s['PulseSeconds'];
            }

            $vid = $s['VariableID'] ?? 0;
            if (isset($metadata[$vid])) {
                $s['DisplayID']       = $metadata[$vid]['DisplayID'];
                $s['ParentName']      = $metadata[$vid]['ParentName'];
                $s['GrandParentName'] = $metadata[$vid]['GrandParentName'];
            }
        }
        unset($s);

        // Sync to RAM Buffers immediately on form load
        $this->WriteAttributeString('ClassListBuffer', json_encode($definedClasses));
        $this->WriteAttributeString('SensorListBuffer', json_encode($sensorList));
        $this->WriteAttributeString('BedroomListBuffer', json_encode($bedroomList));
        $this->WriteAttributeString('DispatchTargetsBuffer', json_encode($dispatchTargets));
        $this->WriteAttributeString('GroupDispatchBuffer', json_encode($groupDispatch));
        // === DEBUG: after sync to RAM buffers ===
        if ($this->ReadPropertyBoolean('DebugMode')) IPS_LogMessage(
            'SensorGroup',
            'DEBUG: GetConfigurationForm after buffer sync ' .
                'attr_ClassListBufferLen=' . strlen((string)$this->ReadAttributeString('ClassListBuffer')) .
                ' attr_GroupListBufferLen=' . strlen((string)$this->ReadAttributeString('GroupListBuffer')) .
                ' attr_SensorListBufferLen=' . strlen((string)$this->ReadAttributeString('SensorListBuffer')) .
                ' attr_BedroomListBufferLen=' . strlen((string)$this->ReadAttributeString('BedroomListBuffer')) .
                ' attr_GroupMembersBufferLen=' . strlen((string)$this->ReadAttributeString('GroupMembersBuffer'))
        );

        // Options for dynamic dropdowns
        $classOptions = [];
        foreach ($definedClasses as $c) {
            $val = !empty($c['ClassID']) ? $c['ClassID'] : $c['ClassName'];
            if (!empty($c['ClassName'])) {
                $classOptions[] = ['caption' => $c['ClassName'], 'value' => $val];
            }
        }
        if (count($classOptions) == 0) $classOptions[] = ['caption' => '- No Classes -', 'value' => ''];
        // --- DISPATCH OPTIONS ---
        // GroupName dropdown (from definedGroups)
        $groupOptions = [];
        foreach ($definedGroups as $g) {
            $gn = trim((string)($g['GroupName'] ?? ''));
            if ($gn !== '') $groupOptions[] = ['caption' => $gn, 'value' => $gn];
        }
        if (count($groupOptions) == 0) $groupOptions[] = ['caption' => '- No Groups -', 'value' => ''];

        // InstanceID dropdown (from DispatchTargets)
        $targetOptions = [
            ['caption' => '- Select target -', 'value' => 0]
        ];

        foreach ($dispatchTargets as $t) {
            if (!is_array($t)) {
                continue;
            }

            $name = trim((string)($t['Name'] ?? ''));

            // Normalize InstanceID safely (accept numeric strings too)
            $iidRaw = $t['InstanceID'] ?? 0;
            $iid = is_numeric($iidRaw) ? (int)$iidRaw : 0;
            if ($iid <= 0) {
                continue;
            }

            // Build caption even if instance is missing (don't filter it out)
            if (IPS_InstanceExists($iid)) {
                $instCaption = IPS_GetName($iid);
            } else {
                $instCaption = 'Missing Instance #' . $iid;
            }

            $cap = ($name !== '') ? ($name . ' (' . $instCaption . ')') : $instCaption;

            $targetOptions[] = ['caption' => $cap, 'value' => $iid];
        }
        // If only the default entry exists, show "No Targets"
        if (count($targetOptions) === 1) {
            $targetOptions = [['caption' => '- No Targets -', 'value' => 0]];
        }
        // === DEBUG: classOptions count ===
        if ($this->ReadPropertyBoolean('DebugMode')) IPS_LogMessage('SensorGroup', 'DEBUG: GetConfigurationForm classOptions=' . count($classOptions));

        // Build list of containers to search (Elements and Actions)
        $sections = [&$form['elements']];
        if (isset($form['actions'])) $sections[] = &$form['actions'];

        foreach ($sections as &$elements) {
            foreach ($elements as &$element) {
                // --- STEP 2: DYNAMIC SENSORS ---
                if (isset($element['name']) && $element['name'] === 'DynamicSensorContainer') {

                    // === DEBUG: found DynamicSensorContainer ===
                    if ($this->ReadPropertyBoolean('DebugMode')) IPS_LogMessage('SensorGroup', 'DEBUG: GetConfigurationForm found container DynamicSensorContainer; building class panels=' . count($definedClasses));

                    foreach ($definedClasses as $class) {
                        $classID = !empty($class['ClassID']) ? $class['ClassID'] : $class['ClassName'];
                        $safeID  = md5($classID);
                        $classSensors = array_values(array_filter($sensorList, function ($s) use ($classID) {
                            return ($s['ClassID'] ?? '') === $classID;
                        }));

                        // === DEBUG: per-class sensors ===
                        if ($this->ReadPropertyBoolean('DebugMode')) IPS_LogMessage(
                            'SensorGroup',
                            'DEBUG: GetConfigurationForm build sensors panel class=' . ($class['ClassName'] ?? '') .
                                ' classID=' . $classID . ' safeID=' . $safeID . ' sensors=' . count($classSensors)
                        );

                        $element['items'][] = [
                            "type"    => "ExpansionPanel",
                            "caption" => $class['ClassName'] . " (" . count($classSensors) . ")",
                            "items"   => [[
                                "type"    => "List",
                                "name"    => "List_" . $safeID,
                                "rowCount" => 8,
                                "add"     => false,
                                "delete"  => false,
                                "onEdit"  => "IPS_RequestAction(\$id, 'UPD_SENS_$safeID', json_encode(\$List_$safeID));",
                                "columns" => [
                                    ["caption" => "ID", "name" => "DisplayID", "width" => "70px"],
                                    ["caption" => "Variable", "name" => "VariableID", "width" => "200px", "edit" => ["type" => "SelectVariable"]],
                                    ["caption" => "Loc (P)", "name" => "ParentName", "width" => "100px"],
                                    ["caption" => "Area (GP)", "name" => "GrandParentName", "width" => "100px"],
                                    ["caption" => "Op", "name" => "Operator", "width" => "100px", "edit" => ["type" => "Select", "options" => [["caption" => "=", "value" => 0], ["caption" => "!=", "value" => 1], ["caption" => ">", "value" => 2], ["caption" => "<", "value" => 3], ["caption" => ">=", "value" => 4], ["caption" => "<=", "value" => 5]]]],
                                    ["caption" => "Value", "name" => "ComparisonValue", "width" => "100px", "edit" => ["type" => "ValidationTextBox"]],
                                    ["caption" => "Mode", "name" => "TriggerMode", "width" => "100px", "edit" => ["type" => "Select", "options" => [["caption" => "LEVEL", "value" => 0], ["caption" => "CHANGE", "value" => 1]]]],
                                    ["caption" => "Pulse Width in Seconds", "name" => "PulseSeconds", "width" => "80px", "edit" => ["type" => "ValidationTextBox"]],
                                    ["caption" => "Action", "name" => "Action", "width" => "80px", "edit" => ["type" => "Button", "caption" => "Delete", "onClick" => "IPS_RequestAction(\$id, 'DEL_SENS_$safeID', \$VariableID);"]]
                                ],
                                "values"  => $classSensors
                            ]]
                        ];
                    }
                }

                // --- STEP 3a: DYNAMIC GROUP DEFINITIONS (Stateless) ---
                if (isset($element['name']) && $element['name'] === 'DynamicGroupContainer') {

                    // === DEBUG: found DynamicGroupContainer + confirm onEdit ===
                    if ($this->ReadPropertyBoolean('DebugMode')) IPS_LogMessage(
                        'SensorGroup',
                        'DEBUG: GetConfigurationForm found container DynamicGroupContainer; injecting List_Groups with values=' . (is_array($definedGroups) ? count($definedGroups) : -1)
                    );
                    if ($this->ReadPropertyBoolean('DebugMode')) IPS_LogMessage(
                        'SensorGroup',
                        "DEBUG: GetConfigurationForm Step3 List_Groups onEdit=" . "IPS_RequestAction(\$id, 'UpdateGroupList', json_encode(\$GroupList));"
                    );

                    $element['items'][] = [
                        "type"     => "List",
                        "name"     => "GroupList",
                        "rowCount" => 5,
                        "add"      => true,
                        "delete"   => false,
                        "onEdit"   => "IPS_RequestAction(\$id, 'UpdateGroupList', json_encode(\$GroupList));",
                        "columns"  => [
                            ["caption" => "ID", "name" => "GroupID", "width" => "0px", "add" => "", "visible" => false],
                            ["caption" => "Group Name", "name" => "GroupName", "width" => "200px", "add" => "", "edit" => ["type" => "ValidationTextBox"]],
                            ["caption" => "Alignment Spacer", "name" => "Spacer", "width" => "200px", "add" => ""],
                            ["caption" => "Logic", "name" => "GroupLogic", "width" => "200px", "add" => 0, "edit" => ["type" => "Select", "options" => [["caption" => "OR (Any Member)", "value" => 0], ["caption" => "AND (All Members)", "value" => 1]]]],
                            ["caption" => "Action", "name" => "Action", "width" => "80px", "add" => "", "edit" => ["type" => "Button", "caption" => "Delete", "onClick" => "IPS_RequestAction(\$id, 'DeleteGroupListItemByName', \$GroupName);"]]
                        ],
                        "values"   => $definedGroups
                    ];
                }

                // --- STEP 3b: DYNAMIC BEDROOMS ---
                if (isset($element['name']) && $element['name'] === 'DynamicBedroomContainer') {

                    // === DEBUG: found DynamicBedroomContainer ===
                    if ($this->ReadPropertyBoolean('DebugMode')) IPS_LogMessage('SensorGroup', 'DEBUG: GetConfigurationForm found container DynamicBedroomContainer; groups=' . (is_array($definedGroups) ? count($definedGroups) : -1));

                    foreach ($definedGroups as $group) {
                        $gName = $group['GroupName'];

                        $bedData = array_values(array_filter($bedroomList, function ($b) use ($gName) {
                            return ($b['GroupName'] ?? '') === $gName;
                        }));

                        // === DEBUG: per-group bedroom rows ===
                        if ($this->ReadPropertyBoolean('DebugMode')) IPS_LogMessage('SensorGroup', 'DEBUG: GetConfigurationForm build bedrooms panel group=' . $gName . ' rows=' . count($bedData));

                        $safe = md5($gName);

                        $element['items'][] = [
                            "type"    => "ExpansionPanel",
                            "caption" => "Group: " . $gName,
                            "items"   => [
                                [
                                    // NEW: Add via RequestAction (reliable) instead of List add-popup
                                    "type"    => "PopupButton",
                                    "caption" => "Add Bedroom Rule",
                                    "popup"   => [
                                        "caption" => "Add Bedroom Rule for Group: " . $gName,
                                        "items"   => [
                                            ["type" => "SelectVariable", "name" => "ActiveVariableID", "caption" => "Active Var (IPSView)"],
                                            ["type" => "Select", "name" => "BedroomDoorClassID", "caption" => "Door Class (Trigger)", "options" => $classOptions]
                                        ]
                                    ],
                                    "onClick" => "IPS_RequestAction(\$id, 'BedroomAdd', json_encode(['GroupName' => '$gName', 'ActiveVariableID' => \$ActiveVariableID, 'BedroomDoorClassID' => \$BedroomDoorClassID]));"
                                ],
                                [
                                    "type"     => "List",
                                    "name"     => "Bed_" . $safe,
                                    "rowCount" => 3,
                                    "add"      => false,
                                    "delete"   => false,
                                    "onEdit"   => "IPS_RequestAction(\$id, 'UpdateBedroomProperty', json_encode(['GroupName' => '$gName', 'Values' => \$Bed_" . $safe . "]));",
                                    "columns"  => [
                                        ["caption" => "Active Var (IPSView)", "name" => "ActiveVariableID", "width" => "200px", "edit" => ["type" => "SelectVariable"]],
                                        ["caption" => "Door Class (Trigger)", "name" => "BedroomDoorClassID", "width" => "200px", "edit" => ["type" => "Select", "options" => $classOptions]],
                                        ["caption" => "Action", "name" => "Action", "width" => "80px", "edit" => ["type" => "Button", "caption" => "Delete", "onClick" => "IPS_RequestAction(\$id, 'DeleteBedroomListItemByVarID', json_encode(['GroupName' => '$gName', 'ActiveVariableID' => \$ActiveVariableID]));"]]
                                    ],
                                    "values"   => $bedData
                                ]
                            ]
                        ];
                    }
                }
                // --- STEP B: DYNAMIC GROUP MEMBERS (Checkbox Matrix Strategy) ---
                if (isset($element['name']) && $element['name'] === 'DynamicGroupMemberContainer') {

                    // === DEBUG: found DynamicGroupMemberContainer ===
                    if ($this->ReadPropertyBoolean('DebugMode')) IPS_LogMessage('SensorGroup', 'DEBUG: GetConfigurationForm found container DynamicGroupMemberContainer; groups=' . (is_array($definedGroups) ? count($definedGroups) : -1) . ' classes=' . (is_array($definedClasses) ? count($definedClasses) : -1));

                    foreach ($definedGroups as $group) {
                        $gName = $group['GroupName'];

                        // Build the Checkbox Matrix values based on all available classes
                        $matrixValues = [];
                        foreach ($definedClasses as $class) {
                            $cID = !empty($class['ClassID']) ? $class['ClassID'] : $class['ClassName'];
                            $isMember = false;
                            foreach ($groupMembers as $m) {
                                if (($m['GroupName'] ?? '') === $gName && ($m['ClassID'] ?? '') === $cID) {
                                    $isMember = true;
                                    break;
                                }
                            }
                            $matrixValues[] = [
                                'ClassName' => $class['ClassName'],
                                'ClassID'   => $cID,
                                'Assigned'  => $isMember
                            ];
                        }

                        // === DEBUG: per-group member matrix rows ===
                        if ($this->ReadPropertyBoolean('DebugMode')) IPS_LogMessage('SensorGroup', 'DEBUG: GetConfigurationForm build members panel group=' . $gName . ' rows=' . count($matrixValues));

                        $element['items'][] = [
                            "type"    => "ExpansionPanel",
                            "caption" => "Members for " . $gName,
                            "items"   => [[
                                "type"     => "List",
                                "name"     => "Mem_" . md5($gName),
                                "rowCount" => count($matrixValues),
                                "add"      => false,
                                "delete"   => false,
                                "onEdit"   => "IPS_RequestAction(\$id, 'UpdateMemberProperty', json_encode(['GroupName' => '$gName', 'Values' => \$Mem_" . md5($gName) . "]));",
                                "columns"  => [
                                    ["caption" => "Class Name", "name" => "ClassName", "width" => "300px"],
                                    ["caption" => "ID", "name" => "ClassID", "width" => "0px", "visible" => false],
                                    ["caption" => "Assigned", "name" => "Assigned", "width" => "100px", "edit" => ["type" => "CheckBox"]]
                                ],
                                "values"   => $matrixValues
                            ]]
                        ];
                    }
                }
            }
        }

        $this->UpdateFormOption($form['elements'], 'ImportClass', $classOptions);
        if (isset($form['actions'])) $this->UpdateFormOption($form['actions'], 'ImportClass', $classOptions);

        // === FINAL DEBUG TRACE ===
        // Find the GroupList element and log what we are sending to the browser
        foreach ($form['elements'] as $el) {
            if (($el['name'] ?? '') === 'GroupList') {
                $firstGroup = $el['values'][0]['GroupName'] ?? 'MISSING';
                if ($this->ReadPropertyBoolean('DebugMode')) {
                    $this->LogMessage("DEBUG [FormOutput]: Sending GroupList. First Group Name: '{$firstGroup}'", KL_MESSAGE);
                }
            }
        }

        // === DEBUG: exit GetConfigurationForm ===
        if ($this->ReadPropertyBoolean('DebugMode')) IPS_LogMessage('SensorGroup', 'DEBUG: GetConfigurationForm EXIT returning json');
        // === FIX: Push current bedroomList into the hidden property-bound field
        foreach ($form['elements'] as &$e) {

            // Keep hidden property-bound BedroomList filled
            if (isset($e['name']) && $e['name'] === 'BedroomList') {
                $e['values'] = $bedroomList;
                continue;
            }

            // Step 4a: targets list values
            if (isset($e['name']) && $e['name'] === 'DispatchTargets') {
                $e['values'] = $dispatchTargets;
                continue;
            }

            // NEW: Global Bedroom Target dropdown options
            if (isset($e['name']) && $e['name'] === 'BedroomTarget') {
                $e['options'] = $targetOptions;
                continue;
            }

            // Step 3c: GroupDispatch list: inject dropdown options + values
            if (isset($e['name']) && $e['name'] === 'GroupDispatch') {
                $e['values'] = $groupDispatch;

                if (isset($e['columns']) && is_array($e['columns'])) {
                    foreach ($e['columns'] as &$col) {
                        if (($col['name'] ?? '') === 'GroupName' && isset($col['edit']) && is_array($col['edit'])) {
                            $col['edit']['options'] = $groupOptions;
                        }
                        if (($col['name'] ?? '') === 'InstanceID' && isset($col['edit']) && is_array($col['edit'])) {
                            $col['edit']['options'] = $targetOptions;
                        }
                    }
                    unset($col);
                }
            }
            // NEW: TargetThrottleList list: inject dropdown options + values
            if (isset($e['name']) && $e['name'] === 'TargetThrottleList') {
                $targetThrottleList = json_decode($this->ReadPropertyString('TargetThrottleList'), true);
                if (!is_array($targetThrottleList)) {
                    $targetThrottleList = [];
                }

                $e['values'] = $targetThrottleList;

                if (isset($e['columns']) && is_array($e['columns'])) {
                    foreach ($e['columns'] as &$col) {
                        if (($col['name'] ?? '') === 'InstanceID' && isset($col['edit']) && is_array($col['edit'])) {
                            $col['edit']['options'] = $targetOptions;
                        }
                    }
                    unset($col);
                }

                continue;
            }
        }
        unset($e);


        return json_encode($form);
    }

    /**
     * Public function for Module 2 to discover the configuration of this instance.
     * Returns the same "Healed" JSON structure used by the backup system.
     */
    public function GetConfiguration()
    {
        // 1. Load Data
        $classList    = json_decode($this->ReadAttributeString('ClassListBuffer'), true) ?: json_decode($this->ReadPropertyString('ClassList'), true) ?: [];
        $groupList    = json_decode($this->ReadAttributeString('GroupListBuffer'), true) ?: json_decode($this->ReadPropertyString('GroupList'), true) ?: [];
        $stateData    = json_decode($this->ReadAttributeString('ClassStateAttribute'), true) ?: [];
        $idMap        = $stateData['IDMap'] ?? [];
        $groupIDMap   = $stateData['GroupIDMap'] ?? [];

        // 2. HEAL: Re-attach hidden IDs from persistent maps
        foreach ($classList as &$c) {
            $name = $c['ClassName'] ?? '';
            if (($c['ClassID'] ?? '') === '' && isset($idMap[$name])) $c['ClassID'] = $idMap[$name];
        }
        unset($c);

        foreach ($groupList as &$g) {
            $name = $g['GroupName'] ?? '';
            if (($g['GroupID'] ?? '') === '' && isset($groupIDMap[$name])) $g['GroupID'] = $groupIDMap[$name];
        }
        unset($g);

        $dispatchTargets = json_decode($this->ReadPropertyString('DispatchTargets'), true);
        if (!is_array($dispatchTargets)) $dispatchTargets = [];

        $groupDispatch = json_decode($this->ReadPropertyString('GroupDispatch'), true);
        if (!is_array($groupDispatch)) $groupDispatch = [];

        $config = [
            'ClassList'       => $classList,
            'GroupList'       => $groupList,
            'SensorList'      => json_decode($this->ReadAttributeString('SensorListBuffer'), true) ?: json_decode($this->ReadPropertyString('SensorList'), true) ?: [],
            'BedroomList'     => json_decode($this->ReadAttributeString('BedroomListBuffer'), true) ?: json_decode($this->ReadPropertyString('BedroomList'), true) ?: [],
            'GroupMembers'    => json_decode($this->ReadAttributeString('GroupMembersBuffer'), true) ?: json_decode($this->ReadPropertyString('GroupMembers'), true) ?: [],
            'TamperList'      => json_decode($this->ReadPropertyString('TamperList'), true) ?: [],
            'DispatchTargets' => $dispatchTargets,
            'GroupDispatch'   => $groupDispatch,
            'BedroomTarget'   => $this->ReadPropertyInteger('BedroomTarget'),
            'MaintenanceMode' => $this->ReadPropertyBoolean('MaintenanceMode')
        ];

        return json_encode($config);
    }

    /**
     * Public function for Module 2 to request an immediate state broadcast.
     * Forces Module 1 to evaluate all sensors and push current ALARM/RESET and BEDROOM_SYNC payloads.
     */
    public function RequestStateSync()
    {
        if ($this->ReadPropertyBoolean('DebugMode')) {
            $this->LogMessage("SYNC REQUEST: Executing Full Active Iteration.", KL_MESSAGE);
        }

        $rawSensorList = $this->ReadPropertyString('SensorList');
        if (!is_string($rawSensorList) || $rawSensorList === '') {
            $sensorList = [];
        } else {
            $tmp = json_decode($rawSensorList, true);
            $sensorList = is_array($tmp) ? $tmp : [];
        }
        $activeCount = 0;

        // Iterate through ALL defined sensors
        foreach ($sensorList as $s) {
            if ($this->CheckSensorRule($s)) {
                $vid = (int)($s['VariableID'] ?? 0);
                if ($vid > 0) {
                    // Simulate a direct trigger for every individual active sensor
                    $this->CheckLogic($vid);
                    $activeCount++;
                }
            }
        }

        // If absolutely nothing is active, send a standard evaluation (to trigger RESET and BEDROOM_SYNC)
        if ($activeCount === 0) {
            $this->CheckLogic(0);
        }
    }

    public function UI_LoadBackup()
    {
        // 1. Load Data
        $classList    = json_decode($this->ReadAttributeString('ClassListBuffer'), true) ?: json_decode($this->ReadPropertyString('ClassList'), true) ?: [];
        $groupList    = json_decode($this->ReadAttributeString('GroupListBuffer'), true) ?: json_decode($this->ReadPropertyString('GroupList'), true) ?: [];
        $stateData    = json_decode($this->ReadAttributeString('ClassStateAttribute'), true) ?: [];
        $idMap        = $stateData['IDMap'] ?? [];
        $groupIDMap   = $stateData['GroupIDMap'] ?? [];

        // 2. HEAL: Re-attach hidden IDs from persistent maps before export
        foreach ($classList as &$c) {
            $name = $c['ClassName'] ?? '';
            if (($c['ClassID'] ?? '') === '' && isset($idMap[$name])) $c['ClassID'] = $idMap[$name];
        }
        unset($c);

        foreach ($groupList as &$g) {
            $name = $g['GroupName'] ?? '';
            if (($g['GroupID'] ?? '') === '' && isset($groupIDMap[$name])) $g['GroupID'] = $groupIDMap[$name];
        }
        unset($g);

        $dispatchTargets = json_decode($this->ReadPropertyString('DispatchTargets'), true);
        if (!is_array($dispatchTargets)) $dispatchTargets = [];

        $groupDispatch = json_decode($this->ReadPropertyString('GroupDispatch'), true);
        if (!is_array($groupDispatch)) $groupDispatch = [];

        $config = [
            'ClassList'       => $classList,
            'GroupList'       => $groupList,
            'SensorList'      => json_decode($this->ReadAttributeString('SensorListBuffer'), true) ?: json_decode($this->ReadPropertyString('SensorList'), true) ?: [],
            'BedroomList'     => json_decode($this->ReadAttributeString('BedroomListBuffer'), true) ?: json_decode($this->ReadPropertyString('BedroomList'), true) ?: [],
            'GroupMembers'    => json_decode($this->ReadAttributeString('GroupMembersBuffer'), true) ?: json_decode($this->ReadPropertyString('GroupMembers'), true) ?: [],
            'TamperList'      => json_decode($this->ReadPropertyString('TamperList'), true) ?: [],
            // NEW: Module 2 routing
            'DispatchTargets' => $dispatchTargets,
            'GroupDispatch'   => $groupDispatch,

            // OPTIONAL properties
            'BedroomTarget'   => $this->ReadPropertyInteger('BedroomTarget'),
            'MaintenanceMode' => $this->ReadPropertyBoolean('MaintenanceMode')
        ];
        $json = json_encode($config, JSON_PRETTY_PRINT);
        $this->UpdateFormField('BackupData', 'value', $json);
    }

    public function UI_RestoreBackup(string $JsonData)
    {
        $config = json_decode($JsonData, true);
        if (!is_array($config)) {
            echo "Error: Invalid JSON data.";
            return;
        }

        // Arrays (JSON-encoded string properties)
        $keysArray = [
            'ClassList',
            'GroupList',
            'SensorList',
            'BedroomList',
            'GroupMembers',
            'TamperList',

            // NEW
            'DispatchTargets',
            'GroupDispatch'
        ];

        $restoredCount = 0;

        foreach ($keysArray as $key) {
            if (isset($config[$key]) && is_array($config[$key])) {
                $json = json_encode($config[$key]);

                IPS_SetProperty($this->InstanceID, $key, $json);

                // Sync RAM Buffers so dynamic UI shows restored data immediately
                if ($key === 'ClassList')        $this->WriteAttributeString('ClassListBuffer', $json);
                if ($key === 'GroupList')        $this->WriteAttributeString('GroupListBuffer', $json);
                if ($key === 'SensorList')       $this->WriteAttributeString('SensorListBuffer', $json);
                if ($key === 'BedroomList')      $this->WriteAttributeString('BedroomListBuffer', $json);
                if ($key === 'GroupMembers')     $this->WriteAttributeString('GroupMembersBuffer', $json);

                // NEW buffers
                if ($key === 'DispatchTargets')  $this->WriteAttributeString('DispatchTargetsBuffer', $json);
                if ($key === 'GroupDispatch')    $this->WriteAttributeString('GroupDispatchBuffer', $json);

                $restoredCount++;
            }
        }

        // OPTIONAL properties
        if (array_key_exists('BedroomTarget', $config)) {
            IPS_SetProperty($this->InstanceID, 'BedroomTarget', (int)$config['BedroomTarget']);
            $restoredCount++;
        }
        if (array_key_exists('MaintenanceMode', $config)) {
            IPS_SetProperty($this->InstanceID, 'MaintenanceMode', (bool)$config['MaintenanceMode']);
            $restoredCount++;
        }

        if ($restoredCount > 0) {
            IPS_ApplyChanges($this->InstanceID);
            $this->ReloadForm();
            echo "Configuration restored successfully.";
        } else {
            echo "No valid configuration keys found.";
        }
    }


    public function UI_UpdateSensorList(string $ClassID, string $Values)
    {
        $newValues = json_decode($Values, true);

        // 1. Load the full RAM Buffer (Blueprint Strategy 2.0)
        $fullBuffer = json_decode($this->ReadAttributeString('SensorListBuffer'), true) ?: [];

        // 2. Separate sensors for other classes
        $others = array_values(array_filter($fullBuffer, function ($s) use ($ClassID) {
            return ($s['ClassID'] ?? '') !== $ClassID;
        }));

        // 3. Process new values with Label Healing
        $metadata = $this->GetMasterMetadata();
        $updatedClass = [];

        if (is_array($newValues)) {
            foreach ($newValues as $row) {
                if (is_array($row)) {
                    $row['ClassID'] = $ClassID;
                    $vid = $row['VariableID'] ?? 0;

                    // Heal labels from Source of Truth
                    if ($vid > 0 && isset($metadata[$vid])) {
                        $row['DisplayID'] = $metadata[$vid]['DisplayID'];
                        $row['ParentName'] = $metadata[$vid]['ParentName'];
                        $row['GrandParentName'] = $metadata[$vid]['GrandParentName'];
                    }
                    $updatedClass[] = $row;
                }
            }
        }

        // 4. Update the RAM Buffer
        $this->WriteAttributeString('SensorListBuffer', json_encode(array_merge($others, $updatedClass)));
    }
    // New Helper: Finds list column definition by name
    private function UpdateListColumnOption(&$elements, $listName, $columnName, $options)
    {
        foreach ($elements as &$element) {
            if (isset($element['name']) && $element['name'] === $listName && isset($element['columns'])) {
                foreach ($element['columns'] as &$col) {
                    if ($col['name'] === $columnName && isset($col['edit']['type']) && $col['edit']['type'] === 'Select') {
                        $col['edit']['options'] = $options;
                        return true;
                    }
                }
            }
        }
        return false;
    }
    public function UI_DeleteSensorListItem(string $ClassID, int $Index)
    {
        // 1. Load the full RAM Buffer (Blueprint Strategy 2.0)
        $fullBuffer = json_decode($this->ReadAttributeString('SensorListBuffer'), true) ?: [];

        // 2. Separate sensors for this class and all others to maintain class integrity
        $others = array_values(array_filter($fullBuffer, function ($s) use ($ClassID) {
            return ($s['ClassID'] ?? '') !== $ClassID;
        }));
        $target = array_values(array_filter($fullBuffer, function ($s) use ($ClassID) {
            return ($s['ClassID'] ?? '') === $ClassID;
        }));
        // 3. Remove only the specific index from the target class
        if (isset($target[$Index])) {
            array_splice($target, $Index, 1);
        }

        // 4. Merge back and update the RAM Buffer
        $newBuffer = array_merge($others, $target);
        $this->WriteAttributeString('SensorListBuffer', json_encode($newBuffer));

        // 5. Refresh the UI to reflect that the row is gone
        $this->ReloadForm();
    }
    // New Helper: Finds select element by name (Recursive)
    private function UpdateFormOption(&$elements, $name, $options)
    {
        foreach ($elements as &$element) {
            if (isset($element['name']) && $element['name'] === $name) {
                $element['options'] = $options;
                return true;
            }
            if (isset($element['items'])) {
                if ($this->UpdateFormOption($element['items'], $name, $options)) return true;
            }
        }
        return false;
    }

    public function UI_Scan(int $ImportRootID)
    {
        if ($ImportRootID <= 0) return;
        $candidates = [];
        $this->ScanRecursive($ImportRootID, $candidates);
        $values = [];
        foreach ($candidates as $id) {
            $pID = IPS_GetParent($id);
            $gpID = ($pID > 0) ? IPS_GetParent($pID) : 0;

            $values[] = [
                'VariableID'      => $id,
                'Name'            => IPS_GetName($id),
                'ParentName'      => ($pID > 0) ? IPS_GetName($pID) : "Root",
                'GrandParentName' => ($gpID > 0) ? IPS_GetName($gpID) : "-",
                'Selected'        => false
            ];
        }
        $this->WriteAttributeString('ScanCache', json_encode($values));
        $this->UpdateFormField('WizardFilterText', 'value', '');
        $this->UpdateFormField('ImportCandidates', 'values', json_encode($values));
        $this->UpdateFormField('ImportCandidates', 'visible', true);
    }

    private function ScanRecursive($parentID, &$result)
    {
        // FIX: Verify object existence to prevent crashes on invalid Root IDs
        if (!IPS_ObjectExists($parentID)) return;

        $children = IPS_GetChildrenIDs($parentID);
        if (is_array($children)) {
            foreach ($children as $child) {
                if (IPS_VariableExists($child)) {
                    $result[] = $child;
                }
                if (IPS_HasChildren($child)) {
                    $this->ScanRecursive($child, $result);
                }
            }
        }
    }

    private function GetBufferedSectionList(string $attrName, string $propName): array
    {
        $rawBuffer = (string) $this->ReadAttributeString($attrName);
        $tmpBuffer = json_decode($rawBuffer, true);

        // Valid buffer, including empty [], is authoritative for the UI session
        if (is_array($tmpBuffer)) {
            return $tmpBuffer;
        }

        $rawProp = (string) $this->ReadPropertyString($propName);
        $tmpProp = json_decode($rawProp, true);
        $list = is_array($tmpProp) ? $tmpProp : [];

        // Initialize buffer from property once if buffer is missing/invalid
        $this->WriteAttributeString($attrName, json_encode(array_values($list)));

        return $list;
    }

    private function WriteBufferedSectionList(string $attrName, array $list): void
    {
        $this->WriteAttributeString($attrName, json_encode(array_values($list)));
    }

    public function UI_SelectAll()
    {
        $list = json_decode($this->ReadAttributeString('ScanCache'), true);
        if (is_array($list)) {
            foreach ($list as &$row) if (is_array($row)) $row['Selected'] = true;
        }
        $this->WriteAttributeString('ScanCache', json_encode($list));
        $this->UpdateFormField('ImportCandidates', 'values', json_encode($list));
    }

    public function UI_SelectNone()
    {
        $list = json_decode($this->ReadAttributeString('ScanCache'), true);
        if (is_array($list)) {
            foreach ($list as &$row) if (is_array($row)) $row['Selected'] = false;
        }
        $this->WriteAttributeString('ScanCache', json_encode($list));
        $this->UpdateFormField('ImportCandidates', 'values', json_encode($list));
    }

    public function UI_Import(string $TargetClassID)
    {
        $candidates = json_decode($this->ReadAttributeString('ScanCache'), true);

        // Blueprint 2.0: Load from Buffer first to preserve existing unsaved edits
        $currentRules = json_decode($this->ReadAttributeString('SensorListBuffer'), true) ?:
            json_decode($this->ReadPropertyString('SensorList'), true) ?: [];

        $added = false;
        if (is_array($candidates)) {
            foreach ($candidates as $row) {
                if (is_array($row) && isset($row['Selected']) && $row['Selected']) {
                    $currentRules[] = [
                        'VariableID' => $row['VariableID'],
                        'Operator' => 0,
                        'ComparisonValue' => "1",
                        'ClassID' => $TargetClassID
                    ];
                    $added = true;
                }
            }
        }

        if ($added) {
            // 1. Update Buffer (Source of Truth for Dynamic UI)
            $this->WriteAttributeString('SensorListBuffer', json_encode($currentRules));

            // 2. Update Property  (Marks instance as "Dirty" -> Apply button appears)
            IPS_SetProperty($this->InstanceID, 'SensorList', json_encode($currentRules));

            // 3. Refresh Form to show new sensors in folders
            $this->ReloadForm();
        }

        $this->WriteAttributeString('ScanCache', '[]');
        $this->UpdateFormField('ImportCandidates', 'values', json_encode([]));
        $this->UpdateFormField('ImportCandidates', 'visible', false);
    }

    public function UI_FilterWizard(string $FilterText)
    {
        $fullList = json_decode($this->ReadAttributeString('ScanCache'), true);
        if (!is_array($fullList)) return;
        $filtered = [];
        if (trim($FilterText) == "") $filtered = $fullList;
        else {
            foreach ($fullList as $row) {
                if (is_array($row) && (stripos($row['Name'], $FilterText) !== false || stripos((string)$row['VariableID'], $FilterText) !== false)) {
                    $filtered[] = $row;
                }
            }
        }
        $this->UpdateFormField('ImportCandidates', 'values', json_encode($filtered));
    }
}
