<?php

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
        $this->RegisterPropertyBoolean('MaintenanceMode', false);
        $this->RegisterAttributeString('ClassStateAttribute', '{}');
        $this->RegisterAttributeString('ScanCache', '[]');
        $this->RegisterVariableBoolean('Status', 'Status', '~Alert', 10);
        $this->RegisterVariableBoolean('Sabotage', 'Sabotage', '~Alert', 90);
        $this->RegisterVariableString('EventData', 'Event Payload', '', 99);
        IPS_SetHidden($this->GetIDForIdent('EventData'), true);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // 1. LIFECYCLE: ID GENERATION
        $classList = json_decode($this->ReadPropertyString('ClassList'), true);
        $idsChanged = false;

        if (is_array($classList)) {
            foreach ($classList as &$c) {
                if (empty($c['ClassID'])) {
                    $c['ClassID'] = uniqid('cls_');
                    $idsChanged = true;
                }
            }
            unset($c);
        }

        if ($idsChanged) {
            IPS_SetProperty($this->InstanceID, 'ClassList', json_encode($classList));
            // CRITICAL FIX: Push the new IDs back to the form immediately
            // This prevents the browser from overwriting the ID with "" on the next edit
            $this->UpdateFormField('ClassList', 'values', json_encode($classList));
        }

        // 2. REGISTRATION
        $messages = $this->GetMessageList();
        foreach ($messages as $senderID => $messageList) {
            $this->UnregisterMessage($senderID, VM_UPDATE);
        }
        $this->RegisterSensors('SensorList');
        $this->RegisterSensors('TamperList');

        // 3. GROUP VARIABLES
        $groupList = json_decode($this->ReadPropertyString('GroupList'), true);
        $keepIdents = ['Status', 'Sabotage', 'EventData'];

        if (is_array($groupList)) {
            $pos = 20;
            foreach ($groupList as $group) {
                if (empty($group['GroupName'])) continue;
                $cleanName = $this->SanitizeIdent($group['GroupName']);
                $ident = "Status_" . $cleanName;
                $this->RegisterVariableBoolean($ident, "Status (" . $group['GroupName'] . ")", "~Alert", $pos++);
                $keepIdents[] = $ident;
            }
        }
        $children = IPS_GetChildrenIDs($this->InstanceID);
        foreach ($children as $child) {
            $obj = IPS_GetObject($child);
            if ($obj['ObjectType'] == 2) {
                if (!in_array($obj['ObjectIdent'], $keepIdents)) $this->UnregisterVariable($obj['ObjectIdent']);
            }
        }
        if ($this->ReadAttributeString('ClassStateAttribute') == '') $this->WriteAttributeString('ClassStateAttribute', '{}');

        // 4. RELOAD FORM
        // Ensures Dropdowns are updated with new Names
        $this->ReloadForm();

        $this->CheckLogic();
    }

    public function RequestAction($Ident, $Value)
    {
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
                        if (($s['VariableID'] ?? 0) == $TriggeringID) {
                            $triggerInClass = true;
                            $lastTriggerDetails = ['variable_id' => $s['VariableID'], 'value' => GetValue($s['VariableID']), 'tag' => $className];
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
                        if ($triggerInClass) $lastTriggerDetails['tag'] = "$className (Count)";
                    }
                    $classStates[$classID]['Buffer'] = array_values($buffer);
                }
                if ($isActive) $activeClasses[$classID] = $lastTriggerDetails;
            }
        }
        $this->WriteAttributeString('ClassStateAttribute', json_encode($classStates));

        $primaryPayload = null;
        $mainStatus = false;

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

            $payload = [
                'event_id' => uniqid(),
                'timestamp' => time(),
                'source_name' => IPS_GetName($this->InstanceID),
                'alarm_class' => $primaryPayload['tag'] ?? 'General',
                'active_classes' => $readableActiveClasses,
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

        // 1. Prepare Data & Maps
        $definedClasses = json_decode($this->ReadPropertyString('ClassList'), true);
        $classOptions = [];
        $nameToIdMap = [];

        if (is_array($definedClasses)) {
            foreach ($definedClasses as $c) {
                if (!empty($c['ClassName']) && !empty($c['ClassID'])) {
                    $classOptions[] = ['caption' => $c['ClassName'], 'value' => $c['ClassID']];
                    $nameToIdMap[$c['ClassName']] = $c['ClassID'];
                }
            }
        }
        if (count($classOptions) == 0) $classOptions[] = ['caption' => '- No Classes -', 'value' => ''];

        $definedGroups = json_decode($this->ReadPropertyString('GroupList'), true);
        $groupOptions = [];
        if (is_array($definedGroups)) {
            foreach ($definedGroups as $g) {
                if (!empty($g['GroupName'])) $groupOptions[] = ['caption' => $g['GroupName'], 'value' => $g['GroupName']];
            }
        }
        if (count($groupOptions) == 0) $groupOptions[] = ['caption' => '- Save Group First -', 'value' => ''];

        // 2. INJECT OPTIONS (Dynamic Search)

        // Wizard Dropdown (Actions)
        if (isset($form['actions'])) {
            $this->UpdateFormOption($form['actions'], 'ImportClass', $classOptions);
        }
        // Wizard Dropdown (Elements fallback)
        $this->UpdateFormOption($form['elements'], 'ImportClass', $classOptions);

        // Sensor List Dropdown
        $this->UpdateListColumnOption($form['elements'], 'SensorList', 'ClassID', $classOptions);

        // Group Members Dropdowns
        $this->UpdateListColumnOption($form['elements'], 'GroupMembers', 'GroupName', $groupOptions);
        $this->UpdateListColumnOption($form['elements'], 'GroupMembers', 'ClassID', $classOptions);


        // 3. JUST-IN-TIME RESOLUTION & INJECTION

        // A. SensorList (Map 'Tag' -> 'ClassID')
        $sensorList = json_decode($this->ReadPropertyString('SensorList'), true);
        if (is_array($sensorList)) {
            foreach ($sensorList as &$s) {
                $tag = $s['Tag'] ?? '';
                // If ClassID is missing but we recognize the Name, fill it in
                if (empty($s['ClassID']) && !empty($tag) && isset($nameToIdMap[$tag])) {
                    $s['ClassID'] = $nameToIdMap[$tag];
                }
            }
            // Inject corrected values into the form
            $this->SetFormElementValues($form['elements'], 'SensorList', $sensorList);
        }

        // B. GroupMembers (Map 'ClassName' -> 'ClassID')
        $groupMembers = json_decode($this->ReadPropertyString('GroupMembers'), true);
        if (is_array($groupMembers)) {
            foreach ($groupMembers as &$gm) {
                $cName = $gm['ClassName'] ?? '';
                if (empty($gm['ClassID']) && !empty($cName) && isset($nameToIdMap[$cName])) {
                    $gm['ClassID'] = $nameToIdMap[$cName];
                }
            }
            $this->SetFormElementValues($form['elements'], 'GroupMembers', $groupMembers);
        }

        return json_encode($form);
    }

    // --- HELPER FUNCTIONS ---

    // Finds a List by name and sets its 'values'
    private function SetFormElementValues(&$elements, $name, $values)
    {
        foreach ($elements as &$element) {
            if (isset($element['name']) && $element['name'] === $name) {
                $element['values'] = $values;
                return true;
            }
            if (isset($element['items'])) {
                if ($this->SetFormElementValues($element['items'], $name, $values)) return true;
            }
        }
        return false;
    }

    // Finds a Select element by name and sets 'options'
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

    // Finds a List, then a specific Column, and sets 'options'
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
            if (isset($element['items'])) {
                if ($this->UpdateListColumnOption($element['items'], $listName, $columnName, $options)) return true;
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
        $children = IPS_GetChildrenIDs($parentID);
        foreach ($children as $child) {
            if (IPS_VariableExists($child)) {
                $result[] = $child;
            }
            if (IPS_HasChildren($child)) {
                $this->ScanRecursive($child, $result);
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
        $currentRules = json_decode($this->ReadPropertyString('SensorList'), true);
        if (!is_array($currentRules)) $currentRules = [];

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
            IPS_SetProperty($this->InstanceID, 'SensorList', json_encode($currentRules));
            IPS_ApplyChanges($this->InstanceID);
            $this->UpdateFormField('SensorList', 'values', json_encode($currentRules));
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
