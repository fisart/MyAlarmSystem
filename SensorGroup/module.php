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
        $this->RegisterPropertyBoolean('MaintenanceMode', false);
        $this->RegisterPropertyBoolean('DebugMode', false);

        // RAM Buffers for Blueprint Strategy 2.0
        $this->RegisterAttributeString('ClassListBuffer', '[]');
        $this->RegisterAttributeString('GroupListBuffer', '[]'); // Added to resolve registration error
        $this->RegisterAttributeString('SensorListBuffer', '[]');
        $this->RegisterAttributeString('BedroomListBuffer', '[]');
        $this->RegisterAttributeString('GroupMembersBuffer', '[]');
        $this->RegisterAttributeString('GroupDispatchBuffer', '[]');


        $this->RegisterAttributeString('ClassStateAttribute', '{}');
        $this->RegisterAttributeString('ScanCache', '[]');
        $this->RegisterVariableBoolean('Status', 'Status', '~Alert', 10);
        $this->RegisterVariableBoolean('Sabotage', 'Sabotage', '~Alert', 90);
        $this->RegisterVariableString('EventData', 'Event Payload', '', 99);

        $this->RegisterPropertyString('DispatchTargets', '[]');   // list of Module2 targets


        $this->RegisterAttributeString('DispatchTargetsBuffer', '[]');

        $this->RegisterAttributeString('LastMainStatus', '0');
        IPS_SetHidden($this->GetIDForIdent('EventData'), true);
    }


    public function ApplyChanges()
    {
        if ($this->ReadPropertyBoolean('DebugMode')) $this->LogMessage("DEBUG: ApplyChanges - START", KL_MESSAGE);
        parent::ApplyChanges();
        // DEBUG: What does ApplyChanges see right after parent lifecycle?
        $gProp = json_decode($this->ReadPropertyString('GroupList'), true) ?: [];
        $gBuf  = json_decode($this->ReadAttributeString('GroupListBuffer'), true) ?: [];
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
        // FIX: Prioritize Buffer for stateless Group definitions to prevent GC wipeout
        $groupList = json_decode($this->ReadAttributeString('GroupListBuffer'), true) ?: json_decode($this->ReadPropertyString('GroupList'), true) ?: [];

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
        $dispatchTargets = json_decode($this->ReadAttributeString('DispatchTargetsBuffer'), true)
            ?: json_decode($this->ReadPropertyString('DispatchTargets'), true)
            ?: [];
        if (!is_array($dispatchTargets)) $dispatchTargets = [];

        // NEW agreed router: flat list
        $groupDispatch = json_decode($this->ReadAttributeString('GroupDispatchBuffer'), true)
            ?: json_decode($this->ReadPropertyString('GroupDispatch'), true)
            ?: [];
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
        $this->WriteAttributeString('GroupListBuffer', json_encode($groupList)); // Sync Group Buffer
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
            IPS_SetProperty($this->InstanceID, 'GroupList', json_encode($groupList));
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

        $groupMembers = json_decode($this->ReadAttributeString('GroupMembersBuffer'), true) ?: json_decode($this->ReadPropertyString('GroupMembers'), true) ?: [];
        $cleanMembers = [];

        foreach ($groupMembers as $m) {
            $gName = $m['GroupName'] ?? '';
            $cID   = $m['ClassID'] ?? '';

            // Group must exist
            if (!in_array($gName, $validGroupNames, true)) {
                continue;
            }

            // --- HEAL: if membership references ClassName (pre-ID), map it to the current ClassID ---
            if (!in_array($cID, $validClassIDs, true) && is_string($cID) && isset($classNameMap[$cID])) {
                $cID = $classNameMap[$cID];
                $m['ClassID'] = $cID;
                $idsChanged = true;
            }

            // Keep only if class now exists
            if (in_array($cID, $validClassIDs, true)) {
                $cleanMembers[] = $m;
            }
        }

        $jsonMem = json_encode($cleanMembers);
        IPS_SetProperty($this->InstanceID, 'GroupMembers', $jsonMem);
        $this->WriteAttributeString('GroupMembersBuffer', $jsonMem);

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
                    $this->WriteAttributeString('DispatchTargetsBuffer', $json);
                    IPS_SetProperty($this->InstanceID, 'DispatchTargets', $json);

                    $this->ReloadForm();
                    break;
                }
            case 'UpdateGroupList': {
                    if ($this->ReadPropertyBoolean('DebugMode')) IPS_LogMessage('SensorGroup', "DEBUG: UpdateGroupList START raw=" . (is_string($Value) ? $Value : json_encode($Value)));

                    $incoming = json_decode($Value, true);
                    if ($this->ReadPropertyBoolean('DebugMode')) IPS_LogMessage(
                        'SensorGroup',
                        "DEBUG: UpdateGroupList decoded_type=" . gettype($incoming) .
                            " decoded_count=" . (is_array($incoming) ? count($incoming) : -1)
                    );

                    // === DEBUG: Show first item if it's an array of rows ===
                    if (is_array($incoming) && !isset($incoming['GroupName']) && count($incoming) > 0) {
                        if ($this->ReadPropertyBoolean('DebugMode')) IPS_LogMessage('SensorGroup', "DEBUG: UpdateGroupList firstRow=" . json_encode($incoming[0]));
                    }
                    // === DEBUG: Show single row if it's a single object ===
                    if (is_array($incoming) && isset($incoming['GroupName'])) {
                        if ($this->ReadPropertyBoolean('DebugMode')) IPS_LogMessage('SensorGroup', "DEBUG: UpdateGroupList singleRow=" . json_encode($incoming));
                    }

                    if (!is_array($incoming)) {
                        if ($this->ReadPropertyBoolean('DebugMode')) IPS_LogMessage('SensorGroup', "DEBUG: UpdateGroupList ABORT - incoming not array");
                        return;
                    }
                    $rowsToProcess = isset($incoming['GroupName']) ? [$incoming] : $incoming;

                    // Load master list (buffer is source of truth)
                    $master = json_decode($this->ReadAttributeString('GroupListBuffer'), true)
                        ?: json_decode($this->ReadPropertyString('GroupList'), true)
                        ?: [];
                    if (!is_array($master)) {
                        $master = [];
                    }

                    // === DEBUG: Master before merge ===
                    if ($this->ReadPropertyBoolean('DebugMode')) IPS_LogMessage(
                        'SensorGroup',
                        "DEBUG: UpdateGroupList master_before count=" . count($master) .
                            " buffer_len=" . strlen($this->ReadAttributeString('GroupListBuffer')) .
                            " prop_len=" . strlen($this->ReadPropertyString('GroupList'))
                    );
                    if (count($master) > 0) {
                        if ($this->ReadPropertyBoolean('DebugMode')) IPS_LogMessage('SensorGroup', "DEBUG: UpdateGroupList master_before lastRow=" . json_encode(end($master)));
                    }

                    // Load GroupIDMap to survive "readOnly/invisible column sends empty"
                    $stateData  = json_decode($this->ReadAttributeString('ClassStateAttribute'), true) ?: [];
                    $groupIDMap = $stateData['GroupIDMap'] ?? [];
                    if (!is_array($groupIDMap)) {
                        $groupIDMap = [];
                    }

                    if ($this->ReadPropertyBoolean('DebugMode')) IPS_LogMessage('SensorGroup', "DEBUG: UpdateGroupList groupIDMap_count=" . count($groupIDMap));

                    // Build quick indexes for master
                    $idxById   = [];
                    $idxByName = [];
                    foreach ($master as $i => $row) {
                        $name = trim((string)($row['GroupName'] ?? ''));
                        $gid  = (string)($row['GroupID'] ?? '');
                        if ($gid !== '') {
                            $idxById[$gid] = $i;
                        }
                        if ($name !== '') {
                            $idxByName[$name] = $i;
                        }
                    }

                    $mergedExisting = 0;
                    $addedNew = 0;

                    foreach ($rowsToProcess as $inRow) {
                        if (!is_array($inRow)) {
                            continue;
                        }

                        // === DEBUG: log each incoming row ===
                        if ($this->ReadPropertyBoolean('DebugMode')) IPS_LogMessage('SensorGroup', "DEBUG: UpdateGroupList inRow=" . json_encode($inRow));

                        // UI spacer is not persisted
                        unset($inRow['Spacer']);

                        $gName = trim((string)($inRow['GroupName'] ?? ''));
                        $gId   = (string)($inRow['GroupID'] ?? '');

                        // Ignore empty placeholder rows
                        if ($gName === '') {
                            if ($this->ReadPropertyBoolean('DebugMode')) IPS_LogMessage('SensorGroup', "DEBUG: UpdateGroupList skip empty GroupName row");
                            continue;
                        }
                        $inRow['GroupName'] = $gName;

                        // If UI dropped GroupID, recover/assign on server side
                        if ($gId === '') {
                            if (isset($groupIDMap[$gName]) && $groupIDMap[$gName] !== '') {
                                $gId = (string)$groupIDMap[$gName];
                                if ($this->ReadPropertyBoolean('DebugMode')) IPS_LogMessage('SensorGroup', "DEBUG: UpdateGroupList recovered GroupID={$gId} for GroupName={$gName}");
                            } else {
                                $gId = uniqid('grp_');
                                $groupIDMap[$gName] = $gId;
                                if ($this->ReadPropertyBoolean('DebugMode')) IPS_LogMessage('SensorGroup', "DEBUG: UpdateGroupList assigned NEW GroupID={$gId} for GroupName={$gName}");
                            }
                            $inRow['GroupID'] = $gId;
                        } else {
                            $groupIDMap[$gName] = $gId;
                            if ($this->ReadPropertyBoolean('DebugMode')) IPS_LogMessage('SensorGroup', "DEBUG: UpdateGroupList incoming GroupID={$gId} for GroupName={$gName}");
                        }

                        // Keyed merge: prefer GroupID, fallback to GroupName
                        if ($gId !== '' && isset($idxById[$gId])) {
                            $pos = $idxById[$gId];
                            $master[$pos] = array_merge($master[$pos], $inRow);
                            $idxByName[$gName] = $pos;
                            $mergedExisting++;
                            continue;
                        }

                        if (isset($idxByName[$gName])) {
                            $pos = $idxByName[$gName];
                            $master[$pos] = array_merge($master[$pos], $inRow);
                            if ($gId !== '') {
                                $idxById[$gId] = $pos;
                            }
                            $mergedExisting++;
                            continue;
                        }

                        // New group
                        $master[] = $inRow;
                        $newPos = count($master) - 1;
                        $idxByName[$gName] = $newPos;
                        if ($gId !== '') {
                            $idxById[$gId] = $newPos;
                        }
                        $addedNew++;
                    }

                    if ($this->ReadPropertyBoolean('DebugMode')) IPS_LogMessage('SensorGroup', "DEBUG: UpdateGroupList merge_result mergedExisting={$mergedExisting} addedNew={$addedNew}");

                    // Persist back to buffer + property, and update GroupIDMap
                    $master = array_values($master);

                    $stateData['GroupIDMap'] = $groupIDMap;
                    $this->WriteAttributeString('ClassStateAttribute', json_encode($stateData));

                    if ($this->ReadPropertyBoolean('DebugMode')) IPS_LogMessage('SensorGroup', "DEBUG: UpdateGroupList Persist MasterCount=" . count($master));
                    if (count($master) > 0) {
                        $last = end($master);
                        if ($this->ReadPropertyBoolean('DebugMode')) IPS_LogMessage('SensorGroup', "DEBUG: UpdateGroupList LastMasterRow=" . json_encode($last));
                    }

                    $json = json_encode($master);
                    $this->WriteAttributeString('GroupListBuffer', $json);
                    IPS_SetProperty($this->InstanceID, 'GroupList', $json);

                    if ($this->ReadPropertyBoolean('DebugMode')) IPS_LogMessage(
                        'SensorGroup',
                        "DEBUG: UpdateGroupList END GroupListBufferLen=" . strlen($this->ReadAttributeString('GroupListBuffer')) .
                            " GroupListPropertyLen=" . strlen($this->ReadPropertyString('GroupList')) .
                            " master_count=" . count($master)
                    );

                    $this->ReloadForm();
                    break;
                }

            case 'DeleteGroupListItemByName': {
                    if ($this->ReadPropertyBoolean('DebugMode')) IPS_LogMessage('SensorGroup', "DEBUG: DeleteGroupListItemByName START raw=" . (is_string($Value) ? $Value : json_encode($Value)));

                    $gNameToDelete = (string)$Value;
                    $master = json_decode($this->ReadAttributeString('GroupListBuffer'), true)
                        ?: json_decode($this->ReadPropertyString('GroupList'), true)
                        ?: [];

                    if ($this->ReadPropertyBoolean('DebugMode')) IPS_LogMessage('SensorGroup', "DEBUG: DeleteGroupListItemByName master_before=" . (is_array($master) ? count($master) : -1));

                    $newMaster = [];
                    $removed = 0;
                    foreach ($master as $row) {
                        if (($row['GroupName'] ?? '') !== $gNameToDelete) {
                            $newMaster[] = $row;
                        } else {
                            $removed++;
                        }
                    }

                    if ($this->ReadPropertyBoolean('DebugMode')) IPS_LogMessage('SensorGroup', "DEBUG: DeleteGroupListItemByName removed={$removed} remaining=" . count($newMaster));

                    if ($gNameToDelete !== '') {
                        $stateData  = json_decode($this->ReadAttributeString('ClassStateAttribute'), true) ?: [];
                        $groupIDMap = $stateData['GroupIDMap'] ?? [];
                        if (is_array($groupIDMap) && isset($groupIDMap[$gNameToDelete])) {
                            unset($groupIDMap[$gNameToDelete]);
                            $stateData['GroupIDMap'] = $groupIDMap;
                            $this->WriteAttributeString('ClassStateAttribute', json_encode($stateData));
                            if ($this->ReadPropertyBoolean('DebugMode')) IPS_LogMessage('SensorGroup', "DEBUG: DeleteGroupListItemByName removed from GroupIDMap name={$gNameToDelete}");
                        }
                    }

                    $json = json_encode($newMaster);
                    $this->WriteAttributeString('GroupListBuffer', $json);
                    IPS_SetProperty($this->InstanceID, 'GroupList', $json);

                    if ($this->ReadPropertyBoolean('DebugMode')) IPS_LogMessage(
                        'SensorGroup',
                        "DEBUG: DeleteGroupListItemByName END GroupListBufferLen=" . strlen($this->ReadAttributeString('GroupListBuffer')) .
                            " GroupListPropertyLen=" . strlen($this->ReadPropertyString('GroupList'))
                    );
                    IPS_ApplyChanges($this->InstanceID);
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
                    $gName = $data['GroupName'] ?? '';
                    $matrixValues = (isset($data['Values']['ClassID'])) ? [$data['Values']] : ($data['Values'] ?? []);
                    $master = json_decode($this->ReadAttributeString('GroupMembersBuffer'), true)
                        ?: json_decode($this->ReadPropertyString('GroupMembers'), true)
                        ?: [];
                    foreach ((array)$matrixValues as $row) {
                        if (!is_array($row)) {
                            continue;
                        }
                        $cID = $row['ClassID'] ?? '';
                        $master = array_values(array_filter($master, function ($m) use ($gName, $cID) {
                            return !(($m['GroupName'] ?? '') === $gName && ($m['ClassID'] ?? '') === $cID);
                        }));
                        if (!empty($row['Assigned'])) {
                            $master[] = ['GroupName' => $gName, 'ClassID' => $cID];
                        }
                    }
                    $json = json_encode($master);
                    $this->WriteAttributeString('GroupMembersBuffer', $json);
                    IPS_SetProperty($this->InstanceID, 'GroupMembers', $json);
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
        $classList    = json_decode($this->ReadAttributeString('ClassListBuffer'), true) ?: [];
        $groupList    = json_decode($this->ReadAttributeString('GroupListBuffer'), true) ?: [];
        $sensorList   = json_decode($this->ReadAttributeString('SensorListBuffer'), true) ?: [];
        $bedroomList  = json_decode($this->ReadAttributeString('BedroomListBuffer'), true) ?: [];
        $groupMembers = json_decode($this->ReadAttributeString('GroupMembersBuffer'), true) ?: [];
        $dispatchTargets = json_decode($this->ReadAttributeString('DispatchTargetsBuffer'), true) ?: [];
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

        // 2. Final Label Healing (Source of Truth)
        $metadata = $this->GetMasterMetadata();
        foreach ($sensorList as &$s) {
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
        foreach ($groupList as &$g) {
            if (!is_array($g)) continue;

            $gName = trim((string)($g['GroupName'] ?? ''));
            if ($gName === '') continue;

            $gId = (string)($g['GroupID'] ?? '');
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
        }
        unset($g);

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
        $groupDispatch = json_decode($this->ReadAttributeString('GroupDispatchBuffer'), true)
            ?: json_decode($this->ReadPropertyString('GroupDispatch'), true)
            ?: [];
        if (!is_array($groupDispatch)) $groupDispatch = [];

        // 4. Persist all verified data to Properties (Physical Disk)
        IPS_SetProperty($this->InstanceID, 'ClassList', json_encode($classList));
        IPS_SetProperty($this->InstanceID, 'GroupList', json_encode($groupList));
        IPS_SetProperty($this->InstanceID, 'SensorList', json_encode($sensorList));
        IPS_SetProperty($this->InstanceID, 'BedroomList', json_encode($bedroomList));
        IPS_SetProperty($this->InstanceID, 'GroupMembers', json_encode($groupMembers));
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

            // Also log exact IDs used for matching (first entries)
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
                if ($this->ReadPropertyBoolean('DebugMode')) $this->LogMessage(
                    "CHK: CLASS className=" . json_encode($className) .
                        " classID=" . json_encode($classID) .
                        " sensorsInClass=" . count($classSensors) .
                        " firstSensorClassID=" . json_encode($sensorList[0]['ClassID'] ?? null) .
                        " firstSensorVID=" . json_encode($sensorList[0]['VariableID'] ?? null),
                    KL_MESSAGE
                );
                $activeCount = 0;
                $total = count($classSensors);
                $triggerInClass = false;
                $lastTriggerDetails = null;

                foreach ($classSensors as $s) {
                    $match = $this->CheckSensorRule($s);
                    if ($this->ReadPropertyBoolean('DebugMode')) $this->LogMessage(
                        "CHK: AND_EVAL class=" . json_encode($className) .
                            " vid=" . (int)($s['VariableID'] ?? 0) .
                            " curType=" . (IPS_VariableExists((int)($s['VariableID'] ?? 0)) ? gettype(GetValue((int)$s['VariableID'])) : 'NA') .
                            " curVal=" . (IPS_VariableExists((int)($s['VariableID'] ?? 0)) ? json_encode(GetValue((int)$s['VariableID'])) : 'NA') .
                            " op=" . json_encode($s['Operator'] ?? null) .
                            " target=" . json_encode($s['ComparisonValue'] ?? null) .
                            " match=" . (int)$match,
                        KL_MESSAGE
                    );
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
                        $activeCount++;

                        // === BUGFIX 1: Capture details for ANY active sensor, prioritize specific trigger ===
                        if ($lastTriggerDetails === null || ($s['VariableID'] ?? 0) == $TriggeringID) {
                            if (($s['VariableID'] ?? 0) == $TriggeringID) {
                                $triggerInClass = true;
                            }
                            $parentID = IPS_GetParent($s['VariableID']);
                            $grandParentID = ($parentID > 0) ? IPS_GetParent($parentID) : 0;

                            $lastTriggerDetails = [
                                'variable_id' => $s['VariableID'],
                                'value_raw'   => GetValue($s['VariableID']),
                                'tag'         => $className,
                                'class_id'    => $classID,
                                'var_name'    => IPS_GetName($s['VariableID']),
                                'parent_name' => ($parentID > 0) ? IPS_GetName($parentID) : "Root",
                                'grandparent_name' => ($grandParentID > 0) ? IPS_GetName($grandParentID) : "",
                                'value_human' => GetValueFormatted($s['VariableID']),
                                'smart_label' => $this->GetSmartLabel($s['VariableID'], $labelMode)
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
                    if ($primaryPayload === null) $primaryPayload = $activeClasses[$reqClassID];
                }
            }

            $groupActive = false;
            if ($targetClassCount > 0) {
                if ($gLogic == 0) $groupActive = ($activeClassCount > 0);
                elseif ($gLogic == 1) $groupActive = ($activeClassCount == $targetClassCount);
            }
            if ($this->ReadPropertyBoolean('DebugMode')) $this->LogMessage(
                "CHK: GROUP g=" . json_encode($gName) .
                    " logic={$gLogic} activeClasses={$activeClassCount}/{$targetClassCount} active=" . (int)$groupActive,
                KL_MESSAGE
            );
            $ident = "Status_" . $this->SanitizeIdent($gName);
            if (@$this->GetIDForIdent($ident)) $this->SetValue($ident, $groupActive);

            if ($groupActive) {
                $activeGroups[] = $gName;
                // === BUGFIX 2: Global status is TRUE if ANY group is active ===
                $mainStatus = true;
            }
        }

        $this->SetValue('Status', $mainStatus);
        if ($this->ReadPropertyBoolean('DebugMode')) $this->LogMessage(
            "CHK: RESULT main=" . (int)$mainStatus .
                " activeGroups=" . json_encode($activeGroups) .
                " activeClassIDs=" . json_encode(array_keys($activeClasses)) .
                " primaryNull=" . (int)($primaryPayload === null),
            KL_MESSAGE
        );

        // === DISPATCH (Module 2 Routing) - load routing table ===
        // GroupDispatch rows: { GroupName, InstanceID }
        $groupDispatch = json_decode($this->ReadPropertyString('GroupDispatch'), true);
        if (!is_array($groupDispatch)) $groupDispatch = [];

        // Build: GroupName -> [InstanceID => true]
        $dispatchMap = [];
        foreach ($groupDispatch as $row) {
            if (!is_array($row)) continue;
            $g = trim((string)($row['GroupName'] ?? ''));
            $iid = (int)($row['InstanceID'] ?? 0);
            if ($g === '' || $iid <= 0) continue;
            $dispatchMap[$g][$iid] = true;
        }
        // === BUGFIX 3: Removed strict check for $primaryPayload, relies cleanly on mainStatus ===
        if ($mainStatus) {
            $readableActiveClasses = [];
            foreach (array_keys($activeClasses) as $aid) {
                $readableActiveClasses[] = $classNameMap[$aid] ?? $aid;
            }

            $payload = [
                'event_id' => uniqid(),
                'timestamp' => time(),
                'source_id' => $this->InstanceID,
                'source_name' => IPS_GetName($this->InstanceID),
                'primary_class' => $primaryPayload['tag'] ?? 'General',
                'primary_group' => $activeGroups[0] ?? 'General',
                'active_classes' => $readableActiveClasses,
                'active_groups' => $activeGroups,
                'is_maintenance' => $this->ReadPropertyBoolean('MaintenanceMode'),
                'trigger_details' => $primaryPayload
            ];
            $this->SetValue('EventData', json_encode($payload));
            // === DISPATCH: Edge-triggered "ALARM" per active group ===
            $payloadJson = json_encode($payload);

            foreach ($activeGroups as $gName) {
                if (!isset($dispatchMap[$gName])) continue;

                foreach (array_keys($dispatchMap[$gName]) as $iid) {
                    if (!IPS_InstanceExists((int)$iid)) continue;

                    // keep payload as-is; only send it
                    IPS_RequestAction((int)$iid, 'ReceivePayload', $payloadJson);
                }
            }
        } else {
            // Explicit Reset Payload
            $payload = [
                'event_id' => uniqid(),
                'timestamp' => time(),
                'source_id' => $this->InstanceID,
                'source_name' => IPS_GetName($this->InstanceID),
                'primary_class' => '',
                'primary_group' => '',
                'active_classes' => [],
                'active_groups' => [],
                'is_maintenance' => $this->ReadPropertyBoolean('MaintenanceMode'),
                'trigger_details' => null
            ];
            $this->SetValue('EventData', json_encode($payload));
            // === DISPATCH: Reset once on clear (recommended) ===
            // Send reset to targets of ALL configured groups (so Module2 can reliably clear state)
            $payloadJson = json_encode($payload);

            $allTargets = [];
            foreach ($dispatchMap as $g => $targets) {
                foreach (array_keys($targets) as $iid) {
                    $allTargets[(int)$iid] = true;
                }
            }

            foreach (array_keys($allTargets) as $iid) {
                if (!IPS_InstanceExists((int)$iid)) continue;
                IPS_RequestAction((int)$iid, 'ReceivePayload', $payloadJson);
            }
        }
    }

    private function CheckSensorRule($row)
    {
        $id = $row['VariableID'] ?? 0;
        if (!$id || !IPS_VariableExists($id)) return false;
        $val = GetValue($id);
        if (isset($row['Invert'])) return $row['Invert'] ? !$val : $val;
        return $this->EvaluateRule($val, $row['Operator'], $row['ComparisonValue']);
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

        // Groups: prefer PROPERTY if it contains newer/more rows than buffer
        $grBuf  = json_decode($this->ReadAttributeString('GroupListBuffer'), true);
        $grProp = json_decode($this->ReadPropertyString('GroupList'), true);
        if (!is_array($grBuf))  $grBuf  = [];
        if (!is_array($grProp)) $grProp = [];
        $definedGroups = (count($grProp) >= count($grBuf)) ? $grProp : $grBuf;
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

        $sensorList   = json_decode($this->ReadAttributeString('SensorListBuffer'), true) ?: json_decode($this->ReadPropertyString('SensorList'), true) ?: [];
        $bedroomList  = json_decode($this->ReadAttributeString('BedroomListBuffer'), true) ?: json_decode($this->ReadPropertyString('BedroomList'), true) ?: [];
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
        $groupMembers = json_decode($this->ReadAttributeString('GroupMembersBuffer'), true) ?: json_decode($this->ReadPropertyString('GroupMembers'), true) ?: [];
        // DispatchTargets: prefer PROPERTY if it contains newer/more rows than buffer (buffer can be stale)
        $dtBuf  = json_decode($this->ReadAttributeString('DispatchTargetsBuffer'), true);
        $dtProp = json_decode($this->ReadPropertyString('DispatchTargets'), true);

        if (!is_array($dtBuf))  $dtBuf  = [];
        if (!is_array($dtProp)) $dtProp = [];

        $dispatchTargets = (count($dtBuf) > 0) ? $dtBuf : $dtProp;
        // GroupDispatch: prefer PROPERTY if it contains newer/more rows than buffer
        $gdBuf  = json_decode($this->ReadAttributeString('GroupDispatchBuffer'), true);
        $gdProp = json_decode($this->ReadPropertyString('GroupDispatch'), true);

        if (!is_array($gdBuf))  $gdBuf  = [];
        if (!is_array($gdProp)) $gdProp = [];

        $groupDispatch = (count($gdBuf) > 0) ? $gdBuf : $gdProp;

        // === DEBUG: other list counts ===
        if ($this->ReadPropertyBoolean('DebugMode')) IPS_LogMessage(
            'SensorGroup',
            'DEBUG: GetConfigurationForm list counts ' .
                'sensorList=' . (is_array($sensorList) ? count($sensorList) : -1) .
                ' bedroomList=' . (is_array($bedroomList) ? count($bedroomList) : -1) .
                ' groupMembers=' . (is_array($groupMembers) ? count($groupMembers) : -1)
        );

        // Blueprint 2.0 - Step 2: Label Healing
        $metadata = $this->GetMasterMetadata();
        foreach ($sensorList as &$s) {
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
        $this->WriteAttributeString('GroupListBuffer', json_encode($definedGroups));
        $this->WriteAttributeString('SensorListBuffer', json_encode($sensorList));
        $this->WriteAttributeString('BedroomListBuffer', json_encode($bedroomList));
        $this->WriteAttributeString('GroupMembersBuffer', json_encode($groupMembers));
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
                                    "add"      => false,   // CHANGED: disable Symcon add-popup (doesn't reliably fire onEdit)
                                    "delete"   => false,
                                    "onEdit"   => "IPS_RequestAction(\$id, 'UpdateBedroomProperty', json_encode(['GroupName' => '$gName', 'Values' => \$Bed_" . $safe . "]));",
                                    "columns"  => [
                                        ["caption" => "Active Var (IPSView)", "name" => "ActiveVariableID", "width" => "200px", "edit" => ["type" => "SelectVariable"]],
                                        ["caption" => "Door Class (Trigger)", "name" => "BedroomDoorClassID", "width" => "200px", "edit" => ["type" => "Select", "options" => $classOptions]],
                                        ["caption" => "Action", "name" => "Action", "width" => "80px","edit" => ["type" => "Button", "caption" => "Delete","onClick" => "IPS_RequestAction(\$id, 'DeleteBedroomListItemByVarID', json_encode(['GroupName' => '$gName', 'ActiveVariableID' => \$ActiveVariableID]));"]
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
        }
        unset($e);


        return json_encode($form);
    }


    public function UI_LoadBackup()
    {
        // Blueprint 2.0: Backup from RAM Buffer (Source of Truth) to ensure export matches visual UI state

        // DispatchTargets: prefer PROPERTY if it has >= rows than buffer (buffer can be stale)
        $dtBuf  = json_decode($this->ReadAttributeString('DispatchTargetsBuffer'), true);
        $dtProp = json_decode($this->ReadPropertyString('DispatchTargets'), true);
        if (!is_array($dtBuf))  $dtBuf  = [];
        if (!is_array($dtProp)) $dtProp = [];
        $dispatchTargets = (count($dtBuf) > 0) ? $dtBuf : $dtProp;

        // GroupDispatch: prefer PROPERTY if it has >= rows than buffer (buffer can be stale)
        $gdBuf  = json_decode($this->ReadAttributeString('GroupDispatchBuffer'), true);
        $gdProp = json_decode($this->ReadPropertyString('GroupDispatch'), true);
        if (!is_array($gdBuf))  $gdBuf  = [];
        if (!is_array($gdProp)) $gdProp = [];
        $groupDispatch = (count($gdBuf) > 0) ? $gdBuf : $gdProp;

        $config = [
            'ClassList'       => json_decode($this->ReadAttributeString('ClassListBuffer'), true) ?: json_decode($this->ReadPropertyString('ClassList'), true) ?: [],
            'GroupList'       => json_decode($this->ReadAttributeString('GroupListBuffer'), true) ?: json_decode($this->ReadPropertyString('GroupList'), true) ?: [],
            'SensorList'      => json_decode($this->ReadAttributeString('SensorListBuffer'), true) ?: json_decode($this->ReadPropertyString('SensorList'), true) ?: [],
            'BedroomList'     => json_decode($this->ReadAttributeString('BedroomListBuffer'), true) ?: json_decode($this->ReadPropertyString('BedroomList'), true) ?: [],
            'GroupMembers'    => json_decode($this->ReadAttributeString('GroupMembersBuffer'), true) ?: json_decode($this->ReadPropertyString('GroupMembers'), true) ?: [],
            'TamperList'      => json_decode($this->ReadPropertyString('TamperList'), true) ?: [],

            // NEW: Module 2 routing
            'DispatchTargets' => $dispatchTargets,
            'GroupDispatch'   => $groupDispatch,

            // OPTIONAL but recommended (boolean property)
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

        // OPTIONAL boolean
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
            return ($s['ClassID'] ?? '') === $classID; // Should use $ClassID (case check)
        }));

        // Correction for variable casing to match function argument
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
            $values[] = ['VariableID' => $id, 'Name' => IPS_GetName($id), 'Selected' => false];
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

            // 2. Update Property (Marks instance as "Dirty" -> Apply button appears)
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
