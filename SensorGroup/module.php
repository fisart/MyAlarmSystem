<?php

declare(strict_types=1);

class SensorGroup extends IPSModule
{
    public function Create()
    {
        // Never delete this line!
        parent::Create();

        // 1. Properties
        $this->RegisterPropertyString('AlarmClassList', '[]');
        $this->RegisterPropertyInteger('LogicMode', 0);
        $this->RegisterPropertyInteger('TriggerThreshold', 1);
        $this->RegisterPropertyInteger('TimeWindow', 10);
        $this->RegisterPropertyString('SensorList', '[]');
        $this->RegisterPropertyString('TamperList', '[]');
        $this->RegisterPropertyBoolean('MaintenanceMode', false);

        // 2. Attributes & Output
        $this->RegisterAttributeString('EventBuffer', '[]');
        $this->RegisterVariableBoolean('Status', 'Status', '~Alert', 10);
        $this->RegisterVariableBoolean('Sabotage', 'Sabotage', '~Alert', 20);
        $this->RegisterVariableString('EventData', 'Event Payload', '', 30);
        IPS_SetHidden($this->GetIDForIdent('EventData'), true);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Clean Message Registration
        $messages = $this->GetMessageList();
        foreach ($messages as $senderID => $messageList) {
            $this->UnregisterMessage($senderID, VM_UPDATE);
        }

        // Register Main Sensors
        $sensorList = json_decode($this->ReadPropertyString('SensorList'), true);
        if (is_array($sensorList)) {
            foreach ($sensorList as $row) {
                if ($row['VariableID'] > 0 && IPS_VariableExists($row['VariableID'])) {
                    $this->RegisterMessage($row['VariableID'], VM_UPDATE);
                }
            }
        }

        // Register Tamper Sensors
        $tamperList = json_decode($this->ReadPropertyString('TamperList'), true);
        if (is_array($tamperList)) {
            foreach ($tamperList as $row) {
                if ($row['VariableID'] > 0 && IPS_VariableExists($row['VariableID'])) {
                    $this->RegisterMessage($row['VariableID'], VM_UPDATE);
                }
            }
        }

        // Initial Logic Check
        $this->CheckLogic();
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        $this->CheckLogic($SenderID);
    }

    // --- LOGIC ENGINE ---
    private function CheckLogic($TriggeringID = 0)
    {
        $sensorList = json_decode($this->ReadPropertyString('SensorList'), true);
        $tamperList = json_decode($this->ReadPropertyString('TamperList'), true);
        $mode = $this->ReadPropertyInteger('LogicMode');

        // 1. Sabotage Check (High Priority)
        $sabotageActive = false;
        if (is_array($tamperList)) {
            foreach ($tamperList as $row) {
                $id = $row['VariableID'];
                if (!$id || !IPS_VariableExists($id)) continue;
                $val = GetValue($id);
                $invert = $row['Invert'] ?? false;
                if ($invert ? (!$val) : ($val)) {
                    $sabotageActive = true;
                    break;
                }
            }
        }
        $this->SetValue('Sabotage', $sabotageActive);

        // 2. Main Sensor Check
        $activeCount = 0;
        $totalSensors = is_array($sensorList) ? count($sensorList) : 0;
        $triggerDetails = null;

        if (is_array($sensorList)) {
            foreach ($sensorList as $row) {
                $id = $row['VariableID'];
                if (!$id || !IPS_VariableExists($id)) continue;

                $currentVal = GetValue($id);
                $operator = $row['Operator'];
                $targetVal = $row['ComparisonValue'];

                if ($this->EvaluateRule($currentVal, $operator, $targetVal)) {
                    $activeCount++;
                    if ($triggerDetails === null || $id == $TriggeringID) {
                        $triggerDetails = [
                            'variable_id' => $id,
                            'value' => $currentVal,
                            'tag' => $row['Tag'] ?? 'General'
                        ];
                    }
                }
            }
        }

        // 3. Group Logic Processing
        $alarmState = false;
        if ($mode == 0) { // OR
            $alarmState = ($activeCount > 0);
        } elseif ($mode == 1) { // AND
            $alarmState = ($totalSensors > 0 && $activeCount == $totalSensors);
        } elseif ($mode == 2) { // COUNT
            if ($activeCount > 0) {
                $buffer = json_decode($this->ReadAttributeString('EventBuffer'), true);
                if (!is_array($buffer)) $buffer = [];

                $window = $this->ReadPropertyInteger('TimeWindow');
                $now = time();
                $buffer = array_filter($buffer, function ($ts) use ($now, $window) {
                    return ($now - $ts) <= $window;
                });

                if ($TriggeringID > 0) {
                    $buffer[] = $now;
                }

                $this->WriteAttributeString('EventBuffer', json_encode(array_values($buffer)));

                if (count($buffer) >= $this->ReadPropertyInteger('TriggerThreshold')) {
                    $alarmState = true;
                    $triggerDetails['tag'] = "Count Threshold Met";
                }
            } else {
                $alarmState = false;
            }
        }

        // 4. Update Status and Payload
        $oldState = $this->GetValue('Status');
        if ($alarmState != $oldState || ($alarmState && $TriggeringID > 0)) {
            $this->SetValue('Status', $alarmState);
            if ($alarmState) {
                $this->UpdatePayload($triggerDetails);
            }
        }
    }

    private function EvaluateRule($current, $operator, $target)
    {
        if (is_bool($current)) {
            $target = ($target === 'true' || $target === '1' || $target === 1);
        } elseif (is_float($current) || is_int($current)) {
            $target = (float)$target;
        }

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

    private function UpdatePayload($details)
    {
        $classes = json_decode($this->ReadPropertyString('AlarmClassList'), true);
        $defaultClass = (is_array($classes) && count($classes) > 0) ? $classes[0]['ClassName'] : "General";
        $finalClass = $details['tag'] ?? $defaultClass;

        $payload = [
            'event_id' => uniqid(),
            'timestamp' => time(),
            'source_name' => IPS_GetName($this->InstanceID),
            'alarm_class' => $finalClass,
            'is_maintenance' => $this->ReadPropertyBoolean('MaintenanceMode'),
            'trigger_details' => $details
        ];
        $this->SetValue('EventData', json_encode($payload));
    }

    // --- CONFIGURATION FORM WIZARD (UI LOGIC) ---

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);

        $userClasses = json_decode($this->ReadPropertyString('AlarmClassList'), true);
        $classOptions = [];
        if (is_array($userClasses)) {
            foreach ($userClasses as $c) {
                $classOptions[] = ['caption' => $c['ClassName'], 'value' => $c['ClassName']];
            }
        }

        // Populate Dropdown (Scan for name='ImportClass')
        // We look into the ExpansionPanel (last element) -> items -> name='ImportClass'
        $elements = &$form['elements'];
        $lastIndex = count($elements) - 2; // Adjust if you change form structure!

        if (isset($elements[$lastIndex]['items'])) {
            foreach ($elements[$lastIndex]['items'] as &$item) {
                if (isset($item['name']) && $item['name'] === 'ImportClass') {
                    $item['options'] = $classOptions;
                }
            }
        }

        return json_encode($form);
    }

    // FIXED: Added Type Hint (int)
    public function UI_Scan(int $ImportRootID)
    {
        if ($ImportRootID <= 0) return;

        $candidates = [];
        $this->ScanRecursive($ImportRootID, $candidates);

        $values = [];
        foreach ($candidates as $id) {
            $values[] = [
                'VariableID' => $id,
                'Name' => IPS_GetName($id),
                'Selected' => false
            ];
        }

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

    // FIXED: Added Type Hint (string)
    public function UI_SelectAll(string $CurrentListValues)
    {
        $list = json_decode($CurrentListValues, true);
        if (is_array($list)) {
            foreach ($list as &$row) $row['Selected'] = true;
        }
        $this->UpdateFormField('ImportCandidates', 'values', json_encode($list));
    }

    // FIXED: Added Type Hint (string)
    public function UI_SelectNone(string $CurrentListValues)
    {
        $list = json_decode($CurrentListValues, true);
        if (is_array($list)) {
            foreach ($list as &$row) $row['Selected'] = false;
        }
        $this->UpdateFormField('ImportCandidates', 'values', json_encode($list));
    }

    // FIXED: Added Type Hints (string, string)
    public function UI_Import(string $CurrentListValues, string $TargetClass)
    {
        $candidates = json_decode($CurrentListValues, true);
        $currentRules = json_decode($this->ReadPropertyString('SensorList'), true);
        if (!is_array($currentRules)) $currentRules = [];

        if (is_array($candidates)) {
            foreach ($candidates as $row) {
                if ($row['Selected']) {
                    $currentRules[] = [
                        'VariableID' => $row['VariableID'],
                        'Operator' => 0,
                        'ComparisonValue' => "1",
                        'Tag' => $TargetClass
                    ];
                }
            }
        }

        IPS_SetProperty($this->InstanceID, 'SensorList', json_encode($currentRules));
        IPS_ApplyChanges($this->InstanceID);

        // Reset Wizard
        $this->UpdateFormField('ImportCandidates', 'values', json_encode([]));
        $this->UpdateFormField('ImportCandidates', 'visible', false);
    }
}
