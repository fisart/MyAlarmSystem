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
        $triggerDetails = null; // Store info about the specific trigger

        if (is_array($sensorList)) {
            foreach ($sensorList as $row) {
                $id = $row['VariableID'];
                if (!$id || !IPS_VariableExists($id)) continue;

                $currentVal = GetValue($id);
                $operator = $row['Operator'];
                $targetVal = $row['ComparisonValue'];

                if ($this->EvaluateRule($currentVal, $operator, $targetVal)) {
                    $activeCount++;
                    // If this is the sensor that triggered the event, capture its details and Tag
                    if ($triggerDetails === null || $id == $TriggeringID) {
                        $triggerDetails = [
                            'variable_id' => $id,
                            'value' => $currentVal,
                            'tag' => $row['Tag'] ?? 'General' // <--- Capture the Tag/Class
                        ];
                    }
                }
            }
        }

        // 3. Group Logic Processing (OR/AND/COUNT)
        $alarmState = false;
        if ($mode == 0) { // OR
            $alarmState = ($activeCount > 0);
        } elseif ($mode == 1) { // AND
            $alarmState = ($totalSensors > 0 && $activeCount == $totalSensors);
        } elseif ($mode == 2) { // COUNT
            // Count Logic: If active sensors > 0, we log a "hit"
            if ($activeCount > 0) {
                $buffer = json_decode($this->ReadAttributeString('EventBuffer'), true);
                if (!is_array($buffer)) $buffer = [];

                // Clean old events
                $window = $this->ReadPropertyInteger('TimeWindow');
                $now = time();
                $buffer = array_filter($buffer, function ($ts) use ($now, $window) {
                    return ($now - $ts) <= $window;
                });

                // Add new hit if this call was triggered by a message (not just manual apply)
                if ($TriggeringID > 0) {
                    $buffer[] = $now;
                }

                $this->WriteAttributeString('EventBuffer', json_encode(array_values($buffer)));

                if (count($buffer) >= $this->ReadPropertyInteger('TriggerThreshold')) {
                    $alarmState = true;
                    $triggerDetails['tag'] = "Count Threshold Met"; // Override tag for count events?
                }
            } else {
                $alarmState = false; // Reset if no sensors active? Or latch? usually aggregators reset.
            }
        }

        // 4. Update Status and Payload
        // We update payload if state goes True OR if it's already True and a new distinct trigger happened
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
        // Type Casting
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
        // Use the Tag from the specific sensor rule if available, otherwise default to first Class
        $classes = json_decode($this->ReadPropertyString('AlarmClassList'), true);
        $defaultClass = (is_array($classes) && count($classes) > 0) ? $classes[0]['ClassName'] : "General";

        $finalClass = $details['tag'] ?? $defaultClass;

        $payload = [
            'event_id' => uniqid(),
            'timestamp' => time(),
            'source_name' => IPS_GetName($this->InstanceID),
            'alarm_class' => $finalClass, // <--- Using the dynamic tag
            'is_maintenance' => $this->ReadPropertyBoolean('MaintenanceMode'),
            'trigger_details' => $details
        ];
        $this->SetValue('EventData', json_encode($payload));
    }

    // --- CONFIGURATION FORM WIZARD (UI LOGIC) ---

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);

        // Populate "ImportClass" dropdown with User's Alarm Classes
        $userClasses = json_decode($this->ReadPropertyString('AlarmClassList'), true);
        $classOptions = [];
        if (is_array($userClasses)) {
            foreach ($userClasses as $c) {
                $classOptions[] = ['caption' => $c['ClassName'], 'value' => $c['ClassName']];
            }
        }

        // Inject into the Form (Searching by name 'ImportClass')
        // We know it is inside the ExpansionPanel at the end.
        // Direct path navigation: elements -> last -> items -> Select named ImportClass
        $panelIndex = count($form['elements']) - 2; // Second to last element (ExpansionPanel)
        // Note: Using hardcoded index is risky if form changes, but standard for this snippet.
        // A robust way iterates.
        foreach ($form['elements'][$panelIndex]['items'] as &$item) {
            if (isset($item['name']) && $item['name'] == 'ImportClass') {
                $item['options'] = $classOptions;
                break;
            }
        }

        return json_encode($form);
    }

    public function UI_Scan($ImportRootID)
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

    public function UI_SelectAll($CurrentListValues)
    {
        $list = json_decode($CurrentListValues, true);
        foreach ($list as &$row) $row['Selected'] = true;
        $this->UpdateFormField('ImportCandidates', 'values', json_encode($list));
    }

    public function UI_SelectNone($CurrentListValues)
    {
        $list = json_decode($CurrentListValues, true);
        foreach ($list as &$row) $row['Selected'] = false;
        $this->UpdateFormField('ImportCandidates', 'values', json_encode($list));
    }

    public function UI_Import($CurrentListValues, $TargetClass)
    {
        $candidates = json_decode($CurrentListValues, true);
        $currentRules = json_decode($this->ReadPropertyString('SensorList'), true);
        if (!is_array($currentRules)) $currentRules = [];

        $count = 0;
        foreach ($candidates as $row) {
            if ($row['Selected']) {
                $currentRules[] = [
                    'VariableID' => $row['VariableID'],
                    'Operator' => 0, // Default: Equals
                    'ComparisonValue' => "1", // Default: True
                    'Tag' => $TargetClass // <--- Apply the wizard selection here
                ];
                $count++;
            }
        }

        IPS_SetProperty($this->InstanceID, 'SensorList', json_encode($currentRules));
        IPS_ApplyChanges($this->InstanceID);

        // echo "Imported $count sensors.";
        // Reset Wizard
        $this->UpdateFormField('ImportCandidates', 'values', json_encode([]));
        $this->UpdateFormField('ImportCandidates', 'visible', false);
    }
}
