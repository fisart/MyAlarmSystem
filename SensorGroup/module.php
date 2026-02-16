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
        $messages = $this->GetMessageList();
        foreach ($messages as $senderID => $messageList) {
            $this->UnregisterMessage($senderID, VM_UPDATE);
        }
        $this->RegisterSensors('SensorList');
        $this->RegisterSensors('TamperList');

        $groupList = json_decode($this->ReadPropertyString('GroupList'), true);
        $keepIdents = ['Status', 'Sabotage', 'EventData'];

        if (is_array($groupList)) {
            $pos = 20;
            foreach ($groupList as $group) {
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
        $this->CheckLogic();
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

        $classStates = json_decode($this->ReadAttributeString('ClassStateAttribute'), true);
        if (!is_array($classStates)) $classStates = [];

        // SABOTAGE
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

        // CLASSES
        $activeClasses = [];
        if (is_array($classList)) {
            foreach ($classList as $classDef) {
                $className = $classDef['ClassName'];
                $logicMode = $classDef['LogicMode'];
                if (!isset($classStates[$className])) $classStates[$className] = ['Buffer' => []];

                $classSensors = [];
                if (is_array($sensorList)) {
                    foreach ($sensorList as $s) {
                        if (($s['Tag'] ?? '') === $className) $classSensors[] = $s;
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
                    $buffer = $classStates[$className]['Buffer'] ?? [];
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
                    $classStates[$className]['Buffer'] = array_values($buffer);
                }

                if ($isActive) $activeClasses[$className] = $lastTriggerDetails;
            }
        }
        $this->WriteAttributeString('ClassStateAttribute', json_encode($classStates));

        // GROUPS
        $primaryPayload = null;
        $mainStatus = false;

        if (is_array($groupList)) {
            foreach ($groupList as $index => $group) {
                $gName = $group['GroupName'];
                $gClasses = array_map('trim', explode(',', $group['Classes']));
                $gLogic = $group['GroupLogic'];

                $activeClassCount = 0;
                $targetClassCount = 0;

                foreach ($gClasses as $reqClass) {
                    if ($reqClass === "") continue;
                    $targetClassCount++;
                    if (isset($activeClasses[$reqClass])) {
                        $activeClassCount++;
                        if ($primaryPayload === null) $primaryPayload = $activeClasses[$reqClass];
                    }
                }

                $groupActive = false;
                if ($targetClassCount > 0) {
                    if ($gLogic == 0) $groupActive = ($activeClassCount > 0);
                    elseif ($gLogic == 1) $groupActive = ($activeClassCount == $targetClassCount);
                }

                $ident = "Status_" . $this->SanitizeIdent($gName);
                if (@$this->GetIDForIdent($ident)) $this->SetValue($ident, $groupActive);
                if ($index === 0) $mainStatus = $groupActive;
            }
        }

        $this->SetValue('Status', $mainStatus);

        if ($mainStatus && $primaryPayload) {
            $payload = [
                'event_id' => uniqid(),
                'timestamp' => time(),
                'source_name' => IPS_GetName($this->InstanceID),
                'alarm_class' => $primaryPayload['tag'] ?? 'General',
                'active_classes' => array_keys($activeClasses),
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
        $definedClasses = json_decode($this->ReadPropertyString('ClassList'), true);
        $classOptions = [];
        if (is_array($definedClasses)) {
            foreach ($definedClasses as $c) {
                if (!empty($c['ClassName'])) $classOptions[] = ['caption' => $c['ClassName'], 'value' => $c['ClassName']];
            }
        }
        if (count($classOptions) == 0) $classOptions[] = ['caption' => '- No Classes -', 'value' => ''];

        $this->UpdateFormOption($form['elements'], 'ImportClass', $classOptions);
        if (isset($form['actions'])) {
            $this->UpdateFormOption($form['actions'], 'ImportClass', $classOptions);
        }

        return json_encode($form);
    }

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

    // --- WIZARD ACTIONS ---

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
            foreach ($list as &$row) {
                if (is_array($row)) $row['Selected'] = true;
            }
        }
        $this->WriteAttributeString('ScanCache', json_encode($list));
        $this->UpdateFormField('ImportCandidates', 'values', json_encode($list));
    }

    public function UI_SelectNone()
    {
        $list = json_decode($this->ReadAttributeString('ScanCache'), true);
        if (is_array($list)) {
            foreach ($list as &$row) {
                if (is_array($row)) $row['Selected'] = false;
            }
        }
        $this->WriteAttributeString('ScanCache', json_encode($list));
        $this->UpdateFormField('ImportCandidates', 'values', json_encode($list));
    }

    // FIXED: Strict Data Normalization
    public function UI_Import($ListValues, string $TargetClass)
    {
        $candidates = [];

        // 1. Data Normalization (Handle String/JSON/Object/Array)
        if (is_string($ListValues)) {
            $candidates = json_decode($ListValues, true);
        } elseif (is_object($ListValues)) {
            $candidates = json_decode(json_encode($ListValues), true);
        } elseif (is_array($ListValues)) {
            $candidates = $ListValues;
        }

        // 2. Read existing rules
        $currentRules = json_decode($this->ReadPropertyString('SensorList'), true);
        if (!is_array($currentRules)) $currentRules = [];

        // 3. Process new candidates
        $added = false;
        if (is_array($candidates)) {
            foreach ($candidates as $row) {
                if (is_array($row) && isset($row['Selected']) && $row['Selected']) {
                    $currentRules[] = [
                        'VariableID' => $row['VariableID'],
                        'Operator' => 0,
                        'ComparisonValue' => "1",
                        'Tag' => $TargetClass
                    ];
                    $added = true;
                }
            }
        }

        // 4. Save and Refresh Visuals
        if ($added) {
            IPS_SetProperty($this->InstanceID, 'SensorList', json_encode($currentRules));
            IPS_ApplyChanges($this->InstanceID);

            // Force the Main List to update on screen immediately
            $this->UpdateFormField('SensorList', 'values', json_encode($currentRules));
        }

        // 5. Cleanup
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
