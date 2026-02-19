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
        $this->RegisterPropertyBoolean('MaintenanceMode', false);

        // RAM Buffers for Blueprint Strategy 2.0
        $this->RegisterAttributeString('ClassListBuffer', '[]'); // Added for Class Synchronization
        $this->RegisterAttributeString('SensorListBuffer', '[]');
        $this->RegisterAttributeString('BedroomListBuffer', '[]');
        $this->RegisterAttributeString('GroupMembersBuffer', '[]');

        $this->RegisterAttributeString('ClassStateAttribute', '{}');
        $this->RegisterAttributeString('ScanCache', '[]');
        $this->RegisterVariableBoolean('Status', 'Status', '~Alert', 10);
        $this->RegisterVariableBoolean('Sabotage', 'Sabotage', '~Alert', 90);
        $this->RegisterVariableString('EventData', 'Event Payload', '', 99);
        IPS_SetHidden($this->GetIDForIdent('EventData'), true);
    }


    public function ApplyChanges()
    {
        $this->LogMessage("DEBUG: ApplyChanges - START", KL_MESSAGE);
        parent::ApplyChanges();

        // 1. LIFECYCLE: ID STABILIZATION (Sticky IDs)
        $classList = json_decode($this->ReadPropertyString('ClassList'), true) ?: [];
        $groupList = json_decode($this->ReadPropertyString('GroupList'), true) ?: [];

        $stateData = json_decode($this->ReadAttributeString('ClassStateAttribute'), true) ?: [];
        $idMap = $stateData['IDMap'] ?? [];

        $idsChanged = false;
        $regenCount = 0; // Track how many IDs we had to touch

        $validClassIDs = [];
        $classNameMap = [];

        foreach ($classList as &$c) {
            $name = $c['ClassName'] ?? '';

            if (empty($c['ClassID'])) {
                // Count this as a regeneration/restoration event
                $regenCount++;

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

        // SAFETY CHECK: If ALL classes came in without IDs, it's a UI transmission error.
        // We save the restored IDs but ABORT Garbage Collection to protect sensors.
        $totalClasses = count($classList);
        if ($totalClasses > 0 && $regenCount == $totalClasses) {
            $this->LogMessage("CRITICAL: UI Glitch detected (All Class IDs missing). Restoring IDs and aborting GC.", KL_WARNING);

            // Save the restored IDs to disk
            IPS_SetProperty($this->InstanceID, 'ClassList', json_encode($classList));

            // Update the UI Buffer immediately
            $this->WriteAttributeString('ClassListBuffer', json_encode($classList));

            // Save the ID Map
            $stateData['IDMap'] = $idMap;
            $this->WriteAttributeString('ClassStateAttribute', json_encode($stateData));

            // Reload and Exit - DO NOT DELETE SENSORS
            $this->ReloadForm();
            return;
        }

        // Regular Save if we didn't abort
        $this->WriteAttributeString('ClassListBuffer', json_encode($classList));
        $stateData['IDMap'] = $idMap;
        $this->WriteAttributeString('ClassStateAttribute', json_encode($stateData));

        $validGroupNames = [];
        foreach ($groupList as &$g) {
            if (empty($g['GroupID'])) {
                $g['GroupID'] = uniqid('grp_');
                $idsChanged = true;
            }
            $validGroupNames[] = $g['GroupName'];
        }
        unset($g);

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

            if (!in_array($sID, $validClassIDs) && isset($classNameMap[$sID])) {
                $s['ClassID'] = $classNameMap[$sID];
                $sID = $s['ClassID'];
                $sensorsDirty = true;
            }

            if (in_array($sID, $validClassIDs)) {
                $cleanSensors[] = $s;
            } else {
                $sensorsDirty = true;
                $this->LogMessage("DEBUG: GC - Deleting Orphan Sensor: " . ($s['VariableID'] ?? 0) . " (ClassID: $sID)", KL_MESSAGE);
            }
        }

        // Always sync Buffer and Property
        $json = json_encode($cleanSensors);
        IPS_SetProperty($this->InstanceID, 'SensorList', $json);
        $this->WriteAttributeString('SensorListBuffer', $json);

        // Cleanup Bedroom List
        $bedroomList = json_decode($this->ReadAttributeString('BedroomListBuffer'), true) ?: json_decode($this->ReadPropertyString('BedroomList'), true) ?: [];
        $cleanBedrooms = [];
        foreach ($bedroomList as $b) {
            if (in_array(($b['GroupName'] ?? ''), $validGroupNames)) {
                $cleanBedrooms[] = $b;
            }
        }
        $jsonBed = json_encode($cleanBedrooms);
        IPS_SetProperty($this->InstanceID, 'BedroomList', $jsonBed);
        $this->WriteAttributeString('BedroomListBuffer', $jsonBed);

        // Cleanup Group Members
        $groupMembers = json_decode($this->ReadAttributeString('GroupMembersBuffer'), true) ?: json_decode($this->ReadPropertyString('GroupMembers'), true) ?: [];
        $cleanMembers = [];
        foreach ($groupMembers as $m) {
            if (in_array(($m['GroupName'] ?? ''), $validGroupNames)) {
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

        // 4. VARIABLES
        $keepIdents = ['Status', 'Sabotage', 'EventData'];
        $pos = 20;
        foreach ($groupList as $group) {
            if (empty($group['GroupName'])) continue;
            $cleanName = $this->SanitizeIdent($group['GroupName']);
            $ident = "Status_" . $cleanName;
            $this->RegisterVariableBoolean($ident, "Status (" . $group['GroupName'] . ")", "~Alert", $pos++);
            $keepIdents[] = $ident;
        }

        $children = IPS_GetChildrenIDs($this->InstanceID);
        foreach ($children as $child) {
            $obj = IPS_GetObject($child);
            if ($obj['ObjectType'] == 2) {
                if (!in_array($obj['ObjectIdent'], $keepIdents)) $this->UnregisterVariable($obj['ObjectIdent']);
            }
        }
        if ($this->ReadAttributeString('ClassStateAttribute') == '') $this->WriteAttributeString('ClassStateAttribute', '{}');

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
        // 1. DYNAMIC IDENTIFIER HANDLING (Step 2 Folders)
        if (strpos($Ident, 'UPD_SENS_') === 0 || strpos($Ident, 'DEL_SENS_') === 0) {
            $isDelete = (strpos($Ident, 'DEL_SENS_') === 0);
            $safeID = substr($Ident, 9);

            // Map MD5 SafeID back to ClassID
            $classList = json_decode($this->ReadPropertyString('ClassList'), true) ?: [];
            $classID = '';
            foreach ($classList as $c) {
                $checkID = !empty($c['ClassID']) ? $c['ClassID'] : $c['ClassName'];
                if (md5($checkID) === $safeID) {
                    $classID = $checkID;
                    break;
                }
            }

            if ($classID === '') return;

            $fullBuffer = json_decode($this->ReadAttributeString('SensorListBuffer'), true) ?: [];

            if ($isDelete) {
                // DELETE LOGIC: $Value is the VariableID (Integer)
                $vidToDelete = (int)$Value;
                $newBuffer = [];
                foreach ($fullBuffer as $row) {
                    // Filter out the specific sensor
                    if (($row['ClassID'] ?? '') === $classID && ($row['VariableID'] ?? 0) === $vidToDelete) {
                        continue;
                    }
                    $newBuffer[] = $row;
                }

                // Update Buffer AND Property to trigger Apply button
                $json = json_encode($newBuffer);
                $this->WriteAttributeString('SensorListBuffer', $json);
                IPS_SetProperty($this->InstanceID, 'SensorList', $json);
                $this->ReloadForm();
            } else {
                // UPDATE LOGIC: $Value is JSON row data
                $newValues = json_decode($Value, true);
                $others = array_values(array_filter($fullBuffer, function ($s) use ($classID) {
                    return ($s['ClassID'] ?? '') !== $classID;
                }));
                $metadata = $this->GetMasterMetadata();
                $updatedClass = [];
                if (is_array($newValues)) {
                    foreach ($newValues as $row) {
                        if (is_array($row)) {
                            $row['ClassID'] = $classID;
                            $vid = $row['VariableID'] ?? 0;
                            if ($vid > 0 && isset($metadata[$vid])) {
                                $row['DisplayID'] = $metadata[$vid]['DisplayID'];
                                $row['ParentName'] = $metadata[$vid]['ParentName'];
                                $row['GrandParentName'] = $metadata[$vid]['GrandParentName'];
                            }
                            $updatedClass[] = $row;
                        }
                    }
                }

                // Update Buffer AND Property to trigger Apply button
                $json = json_encode(array_merge($others, $updatedClass));
                $this->WriteAttributeString('SensorListBuffer', $json);
                IPS_SetProperty($this->InstanceID, 'SensorList', $json);
                $this->ReloadForm();
            }
            return;
        }

        switch ($Ident) {
            case 'UpdateWizardList':
                $changes = json_decode($Value, true);
                $cache = json_decode($this->ReadAttributeString('ScanCache'), true);
                if (!is_array($cache)) $cache = [];
                $map = [];
                foreach ($cache as $idx => $row) $map[$row['VariableID']] = $idx;
                if (is_array($changes)) {
                    if (isset($changes['VariableID'])) $changes = [$changes];
                    foreach ($changes as $change) {
                        if (isset($change['VariableID']) && isset($map[$change['VariableID']])) {
                            $idx = $map[$change['VariableID']];
                            $cache[$idx]['Selected'] = $change['Selected'];
                        }
                    }
                }
                $this->WriteAttributeString('ScanCache', json_encode(array_values($cache)));
                break;

            case 'FilterWizard':
                $FilterText = (string)$Value;
                $fullList = json_decode($this->ReadAttributeString('ScanCache'), true);
                if (!is_array($fullList)) return;
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
    }
    public function SaveConfiguration()
    {
        // 1. Load data from RAM Buffers
        $sensorList = json_decode($this->ReadAttributeString('SensorListBuffer'), true) ?: [];
        $bedroomList = json_decode($this->ReadAttributeString('BedroomListBuffer'), true) ?: [];
        $groupMembers = json_decode($this->ReadAttributeString('GroupMembersBuffer'), true) ?: [];

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

        // 3. Persist to Properties
        IPS_SetProperty($this->InstanceID, 'SensorList', json_encode($sensorList));
        IPS_SetProperty($this->InstanceID, 'BedroomList', json_encode($bedroomList));
        IPS_SetProperty($this->InstanceID, 'GroupMembers', json_encode($groupMembers));

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

    private function SanitizeIdent($string)
    {
        $string = preg_replace('/[^a-zA-Z0-9]/', '', $string);
        return $string ?: 'Group';
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
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
                // NEW: Read the LabelMode setting (default to 0=Name)
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
                    $match = $this->CheckSensorRule($s);
                    if ($match) {
                        $activeCount++;
                        // Inside CheckLogic, update the $lastTriggerDetails block:
                        if (($s['VariableID'] ?? 0) == $TriggeringID) {
                            $triggerInClass = true;
                            $parentID = IPS_GetParent($s['VariableID']);
                            $grandParentID = ($parentID > 0) ? IPS_GetParent($parentID) : 0;

                            $lastTriggerDetails = [
                                'variable_id' => $s['VariableID'],
                                'value_raw'   => GetValue($s['VariableID']),
                                'tag'         => $className,
                                'class_id'    => $classID,
                                'var_name'    => IPS_GetName($s['VariableID']),
                                'parent_name' => ($parentID > 0) ? IPS_GetName($parentID) : "Root",
                                'grandparent_name' => ($grandParentID > 0) ? IPS_GetName($grandParentID) : "", // NEW
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
        $activeGroups = []; // Track names of active groups

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

        $firstGroupProcessed = false;
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

            $ident = "Status_" . $this->SanitizeIdent($gName);
            if (@$this->GetIDForIdent($ident)) $this->SetValue($ident, $groupActive);

            if ($groupActive) {
                $activeGroups[] = $gName;
            }

            if (!$firstGroupProcessed) {
                $mainStatus = $groupActive;
                $firstGroupProcessed = true;
            }
        }

        $this->SetValue('Status', $mainStatus);

        if ($mainStatus && $primaryPayload) {
            $readableActiveClasses = [];
            foreach (array_keys($activeClasses) as $aid) {
                $readableActiveClasses[] = $classNameMap[$aid] ?? $aid;
            }

            // NEW: Updated JSON Structure matching Tier 2 Requirements
            $payload = [
                'event_id' => uniqid(),
                'timestamp' => time(),
                'source_id' => $this->InstanceID, // Added Source ID
                'source_name' => IPS_GetName($this->InstanceID),

                'primary_class' => $primaryPayload['tag'] ?? 'General',
                'primary_group' => $activeGroups[0] ?? 'General', // Added Primary Group

                'active_classes' => $readableActiveClasses,
                'active_groups' => $activeGroups, // Added List of Active Groups

                'is_maintenance' => $this->ReadPropertyBoolean('MaintenanceMode'),
                'trigger_details' => $primaryPayload
            ];
            $this->SetValue('EventData', json_encode($payload));
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
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);

        // Blueprint 2.0: Prioritize RAM Buffer
        $sensorList = json_decode($this->ReadAttributeString('SensorListBuffer'), true) ?: json_decode($this->ReadPropertyString('SensorList'), true) ?: [];
        $bedroomList = json_decode($this->ReadAttributeString('BedroomListBuffer'), true) ?: json_decode($this->ReadPropertyString('BedroomList'), true) ?: [];
        $groupMembers = json_decode($this->ReadAttributeString('GroupMembersBuffer'), true) ?: json_decode($this->ReadPropertyString('GroupMembers'), true) ?: [];

        // Blueprint 2.0 - Step 2: Label Healing
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

        // Sync to RAM Buffers
        $this->WriteAttributeString('SensorListBuffer', json_encode($sensorList));
        $this->WriteAttributeString('BedroomListBuffer', json_encode($bedroomList));
        $this->WriteAttributeString('GroupMembersBuffer', json_encode($groupMembers));

        // Options
        $definedClasses = json_decode($this->ReadPropertyString('ClassList'), true) ?: [];
        $classOptions = [];
        foreach ($definedClasses as $c) {
            $val = !empty($c['ClassID']) ? $c['ClassID'] : $c['ClassName'];
            if (!empty($c['ClassName'])) {
                $classOptions[] = ['caption' => $c['ClassName'], 'value' => $val];
            }
        }
        if (count($classOptions) == 0) $classOptions[] = ['caption' => '- No Classes -', 'value' => ''];

        $definedGroups = json_decode($this->ReadPropertyString('GroupList'), true) ?: [];
        $groupOptions = [];
        foreach ($definedGroups as $g) {
            if (!empty($g['GroupName'])) {
                $groupOptions[] = ['caption' => $g['GroupName'], 'value' => $g['GroupName']];
            }
        }

        foreach ($form['elements'] as &$element) {

            // --- STEP 2: SENSORS ---
            if (isset($element['name']) && $element['name'] === 'DynamicSensorContainer') {
                foreach ($definedClasses as $class) {
                    $classID = !empty($class['ClassID']) ? $class['ClassID'] : $class['ClassName'];
                    $safeID = md5($classID);
                    $className = $class['ClassName'];
                    $classSensors = array_values(array_filter($sensorList, function ($s) use ($classID) {
                        return ($s['ClassID'] ?? '') === $classID;
                    }));

                    $element['items'][] = [
                        "type" => "ExpansionPanel",
                        "caption" => $className . " (" . count($classSensors) . ")",
                        "items" => [[
                            "type" => "List",
                            "name" => "List_" . $safeID,
                            "rowCount" => 8,
                            "add" => false,
                            "delete" => false,
                            "onEdit" => "IPS_RequestAction(\$id, 'UPD_SENS_$safeID', \$List_$safeID);",
                            "columns" => [
                                ["caption" => "ID", "name" => "DisplayID", "width" => "70px"],
                                ["caption" => "Variable", "name" => "VariableID", "width" => "200px", "edit" => ["type" => "SelectVariable"]],
                                ["caption" => "Location (P)", "name" => "ParentName", "width" => "120px"],
                                ["caption" => "Area (GP)", "name" => "GrandParentName", "width" => "120px"],
                                ["caption" => "Op", "name" => "Operator", "width" => "70px", "edit" => ["type" => "Select", "options" => [["caption" => "=", "value" => 0], ["caption" => "!=", "value" => 1], ["caption" => ">", "value" => 2], ["caption" => "<", "value" => 3], ["caption" => ">=", "value" => 4], ["caption" => "<=", "value" => 5]]]],
                                ["caption" => "Value", "name" => "ComparisonValue", "width" => "80px", "edit" => ["type" => "ValidationTextBox"]],
                                ["caption" => "Action", "width" => "80px", "edit" => ["type" => "Button", "caption" => "Del", "onClick" => "IPS_RequestAction(\$id, 'DEL_SENS_$safeID', \$VariableID);"]]
                            ],
                            "values" => $classSensors
                        ]]
                    ];
                }
            }

            // --- STEP 3b: BEDROOMS ---
            if (isset($element['name']) && $element['name'] === 'DynamicBedroomContainer') {
                foreach ($definedGroups as $group) {
                    $gName = $group['GroupName'];
                    $bedData = array_values(array_filter($bedroomList, function ($b) use ($gName) {
                        return ($b['GroupName'] ?? '') === $gName;
                    }));

                    $element['items'][] = [
                        "type" => "ExpansionPanel",
                        "caption" => "Group: " . $gName,
                        "items" => [[
                            "type" => "List",
                            "name" => "Bed_" . md5($gName),
                            "rowCount" => 2,
                            "add" => true,
                            "delete" => false,
                            // Manual JSON construction to safely pass $index
                            "onEdit" => "IPS_RequestAction(\$id, 'UpdateBedroomProperty', json_encode(['GroupName' => '$gName', 'Values' => \$Bed_" . md5($gName) . "]));",
                            "columns" => [
                                ["caption" => "Active Var (IPSView)", "name" => "ActiveVariableID", "width" => "250px", "add" => 0, "edit" => ["type" => "SelectVariable"]],
                                ["caption" => "Door Class (Trigger)", "name" => "BedroomDoorClassID", "width" => "250px", "add" => "", "edit" => ["type" => "Select", "options" => $classOptions]],
                                ["caption" => "Action", "width" => "80px", "edit" => ["type" => "Button", "caption" => "Del", "onClick" => "IPS_RequestAction(\$id, 'DeleteBedroomListItem', '{\"GroupName\":\"$gName\",\"Index\":' . \$index . '}');"]]
                            ],
                            "values" => $bedData
                        ]]
                    ];
                }
            }

            // --- STEP B: MEMBERS ---
            if (isset($element['name']) && $element['name'] === 'DynamicGroupMemberContainer') {
                foreach ($definedGroups as $group) {
                    $gName = $group['GroupName'];
                    $members = array_values(array_filter($groupMembers, function ($m) use ($gName) {
                        return ($m['GroupName'] ?? '') === $gName;
                    }));

                    $element['items'][] = [
                        "type" => "ExpansionPanel",
                        "caption" => "Members for " . $gName,
                        "items" => [[
                            "type" => "List",
                            "name" => "Mem_" . md5($gName),
                            "rowCount" => 5,
                            "add" => true,
                            "delete" => false,
                            // Manual JSON construction to safely pass $index
                            "onEdit" => "IPS_RequestAction(\$id, 'UpdateMemberProperty', json_encode(['GroupName' => '$gName', 'Values' => \$Mem_" . md5($gName) . "]));",
                            "columns" => [
                                ["caption" => "Assigned Class", "name" => "ClassID", "width" => "400px", "add" => "", "edit" => ["type" => "Select", "options" => $classOptions]],
                                ["caption" => "Action", "width" => "80px", "edit" => ["type" => "Button", "caption" => "Del", "onClick" => "IPS_RequestAction(\$id, 'DeleteMemberListItem', '{\"GroupName\":\"$gName\",\"Index\":' . \$index . '}');"]]
                            ],
                            "values" => $members
                        ]]
                    ];
                }
            }
        }

        $this->UpdateFormOption($form['elements'], 'ImportClass', $classOptions);
        if (isset($form['actions'])) $this->UpdateFormOption($form['actions'], 'ImportClass', $classOptions);
        $this->UpdateListColumnOption($form['elements'], 'GroupMembers', 'GroupName', $groupOptions);
        $this->UpdateListColumnOption($form['elements'], 'GroupMembers', 'ClassID', $classOptions);
        $this->UpdateListColumnOption($form['elements'], 'BedroomList', 'GroupName', $groupOptions);
        $this->UpdateListColumnOption($form['elements'], 'BedroomList', 'BedroomDoorClassID', $classOptions);

        return json_encode($form);
    }

    public function UI_LoadBackup()
    {
        // Blueprint 2.0: Backup from RAM Buffer (Source of Truth) to ensure export matches visual UI state
        $config = [
            'ClassList'    => json_decode($this->ReadAttributeString('ClassListBuffer'), true) ?: json_decode($this->ReadPropertyString('ClassList'), true) ?: [],
            'GroupList'    => json_decode($this->ReadPropertyString('GroupList'), true) ?: [],
            'SensorList'   => json_decode($this->ReadAttributeString('SensorListBuffer'), true) ?: json_decode($this->ReadPropertyString('SensorList'), true) ?: [],
            'BedroomList'  => json_decode($this->ReadAttributeString('BedroomListBuffer'), true) ?: json_decode($this->ReadPropertyString('BedroomList'), true) ?: [],
            'GroupMembers' => json_decode($this->ReadAttributeString('GroupMembersBuffer'), true) ?: json_decode($this->ReadPropertyString('GroupMembers'), true) ?: [],
            'TamperList'   => json_decode($this->ReadPropertyString('TamperList'), true) ?: []
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

        $keys = ['ClassList', 'GroupList', 'SensorList', 'BedroomList', 'GroupMembers', 'TamperList'];
        $restoredCount = 0;

        foreach ($keys as $key) {
            if (isset($config[$key]) && is_array($config[$key])) {
                $json = json_encode($config[$key]);
                IPS_SetProperty($this->InstanceID, $key, $json);

                // Blueprint 2.0: Sync RAM Buffers to ensure dynamic folders show restored data immediately
                if ($key === 'SensorList') $this->WriteAttributeString('SensorListBuffer', $json);
                if ($key === 'BedroomList') $this->WriteAttributeString('BedroomListBuffer', $json);
                if ($key === 'GroupMembers') $this->WriteAttributeString('GroupMembersBuffer', $json);

                $restoredCount++;
            }
        }

        if ($restoredCount > 0) {
            IPS_ApplyChanges($this->InstanceID);
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
