<?php

declare(strict_types=1);

class SensorGroup extends IPSModule
{
    public function Create()
    {
        parent::Create();

        // --- LEVEL 1: GLOBAL AGGREGATION ---
        // 0 = Any Class (OR), 1 = All Classes (AND), 2 = Correlation (>= 2 Classes)
        $this->RegisterPropertyInteger('AggregationMode', 0);
        $this->RegisterPropertyInteger('CorrelationWindow', 0); // Reserved for future advanced correlation

        // --- LEVEL 2: CLASS DEFINITIONS (The "Buckets") ---
        // Columns: ClassName, LogicMode (0=OR, 1=AND, 2=COUNT), Threshold, TimeWindow
        $this->RegisterPropertyString('ClassList', '[]');

        // --- LEVEL 3: SENSOR INPUTS ---
        // Columns: VariableID, Operator, Value, Tag (Link to ClassName)
        $this->RegisterPropertyString('SensorList', '[]');
        $this->RegisterPropertyString('TamperList', '[]');
        $this->RegisterPropertyBoolean('MaintenanceMode', false);

        // --- INTERNAL MEMORY ---
        // Stores the state and event buffers for EACH Class separately
        // Structure: { "ClassName": { "Active": bool, "Buffer": [ts, ts] } }
        $this->RegisterAttributeString('ClassStateAttribute', '{}');

        // Cache for Wizard
        $this->RegisterAttributeString('ScanCache', '[]');

        // --- OUTPUTS ---
        $this->RegisterVariableBoolean('Status', 'Status', '~Alert', 10);
        $this->RegisterVariableBoolean('Sabotage', 'Sabotage', '~Alert', 20);
        $this->RegisterVariableString('EventData', 'Event Payload', '', 30);
        IPS_SetHidden($this->GetIDForIdent('EventData'), true);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Unregister everything first
        $messages = $this->GetMessageList();
        foreach ($messages as $senderID => $messageList) {
            $this->UnregisterMessage($senderID, VM_UPDATE);
        }

        // Register Sensors
        $sensorList = json_decode($this->ReadPropertyString('SensorList'), true);
        if (is_array($sensorList)) {
            foreach ($sensorList as $row) {
                if ($row['VariableID'] > 0 && IPS_VariableExists($row['VariableID'])) {
                    $this->RegisterMessage($row['VariableID'], VM_UPDATE);
                }
            }
        }

        // Register Tamper
        $tamperList = json_decode($this->ReadPropertyString('TamperList'), true);
        if (is_array($tamperList)) {
            foreach ($tamperList as $row) {
                if ($row['VariableID'] > 0 && IPS_VariableExists($row['VariableID'])) {
                    $this->RegisterMessage($row['VariableID'], VM_UPDATE);
                }
            }
        }

        // Initialize Class States if empty
        if ($this->ReadAttributeString('ClassStateAttribute') == '') {
            $this->WriteAttributeString('ClassStateAttribute', '{}');
        }

        $this->CheckLogic();
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        $this->CheckLogic($SenderID);
    }

    // --- THE FUNNEL LOGIC ENGINE ---
    private function CheckLogic($TriggeringID = 0)
    {
        // Load Configuration
        $classList = json_decode($this->ReadPropertyString('ClassList'), true);
        $sensorList = json_decode($this->ReadPropertyString('SensorList'), true);
        $tamperList = json_decode($this->ReadPropertyString('TamperList'), true);
        $aggMode = $this->ReadPropertyInteger('AggregationMode');

        // Load Internal State (Buffers)
        $classStates = json_decode($this->ReadAttributeString('ClassStateAttribute'), true);
        if (!is_array($classStates)) $classStates = [];

        // 1. SABOTAGE CHECK (Global Priority)
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

        // 2. CLASS EVALUATION (Level 2)
        $activeClasses = []; // List of names of currently active classes
        $triggerDetails = null; // Info about what caused the check

        if (is_array($classList)) {
            foreach ($classList as $classDef) {
                $className = $classDef['ClassName'];
                $logicMode = $classDef['LogicMode']; // 0=OR, 1=AND, 2=COUNT

                // Get State Memory for this class
                if (!isset($classStates[$className])) {
                    $classStates[$className] = ['Buffer' => []];
                }

                // Filter sensors belonging to this class
                $classSensors = [];
                if (is_array($sensorList)) {
                    foreach ($sensorList as $sensor) {
                        if (($sensor['Tag'] ?? '') === $className) {
                            $classSensors[] = $sensor;
                        }
                    }
                }

                // Evaluate Sensors for this Class
                $activeSensorCount = 0;
                $totalClassSensors = count($classSensors);
                $triggerInClass = false; // Did the TriggeringID belong to this class?

                foreach ($classSensors as $sensor) {
                    $id = $sensor['VariableID'];
                    if (!$id || !IPS_VariableExists($id)) continue;

                    $val = GetValue($id);
                    $match = $this->EvaluateRule($val, $sensor['Operator'], $sensor['ComparisonValue']);

                    if ($match) {
                        $activeSensorCount++;
                        if ($id == $TriggeringID) {
                            $triggerInClass = true;
                            // Capture details for payload
                            if ($triggerDetails === null) {
                                $triggerDetails = ['variable_id' => $id, 'value' => $val, 'tag' => $className];
                            }
                        }
                    }
                }

                // Determine Class Status based on Logic Mode
                $isClassActive = false;

                if ($logicMode == 0) { // OR
                    $isClassActive = ($activeSensorCount > 0);
                } elseif ($logicMode == 1) { // AND
                    $isClassActive = ($totalClassSensors > 0 && $activeSensorCount == $totalClassSensors);
                } elseif ($logicMode == 2) { // COUNT
                    // Get Buffer
                    $buffer = $classStates[$className]['Buffer'] ?? [];
                    $window = $classDef['TimeWindow'];
                    $threshold = $classDef['Threshold'];
                    $now = time();

                    // Clean Buffer
                    $buffer = array_filter($buffer, function ($ts) use ($now, $window) {
                        return ($now - $ts) <= $window;
                    });

                    // Add Event if this class was triggered
                    // Note: We only count if the specific sensor triggering this update belongs to this class
                    // AND that sensor is actually in a triggered state.
                    if ($triggerInClass && $TriggeringID > 0) {
                        $buffer[] = $now;
                    }

                    // Check Threshold
                    if (count($buffer) >= $threshold) {
                        $isClassActive = true;
                        // If this count triggered it, update payload tag to be specific
                        if ($triggerInClass) $triggerDetails['tag'] = "$className (Count Met)";
                    }

                    // Save Buffer back to memory
                    $classStates[$className]['Buffer'] = array_values($buffer);
                }

                if ($isClassActive) {
                    $activeClasses[] = $className;
                }
            }
        }

        // Save states
        $this->WriteAttributeString('ClassStateAttribute', json_encode($classStates));

        // 3. GLOBAL AGGREGATION (Level 1)
        $moduleAlarmState = false;
        $countActive = count($activeClasses);
        $countDefined = is_array($classList) ? count($classList) : 0;

        if ($aggMode == 0) { // OR (Any Class)
            $moduleAlarmState = ($countActive > 0);
        } elseif ($aggMode == 1) { // AND (All Classes)
            $moduleAlarmState = ($countDefined > 0 && $countActive == $countDefined);
        } elseif ($aggMode == 2) { // MULTI-CLASS (Correlation)
            // Triggers if at least 2 DISTINCT classes are active
            $moduleAlarmState = ($countActive >= 2);
        }

        // 4. OUTPUT
        $oldState = $this->GetValue('Status');
        if ($moduleAlarmState != $oldState || ($moduleAlarmState && $TriggeringID > 0)) {
            $this->SetValue('Status', $moduleAlarmState);
            if ($moduleAlarmState) {
                // If Multi-Class, the tag should reflect that
                if ($aggMode == 2 && count($activeClasses) > 1) {
                    $triggerDetails['tag'] = "Multi-Class: " . implode(' + ', $activeClasses);
                }
                $this->UpdatePayload($triggerDetails, $activeClasses);
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

    private function UpdatePayload($details, $activeClasses)
    {
        // Primary class is the one from the details, or the first active one
        $primaryClass = $details['tag'] ?? ($activeClasses[0] ?? 'General');

        $payload = [
            'event_id' => uniqid(),
            'timestamp' => time(),
            'source_name' => IPS_GetName($this->InstanceID),
            'alarm_class' => $primaryClass,
            'active_classes' => $activeClasses, // List of all currently active classes
            'is_maintenance' => $this->ReadPropertyBoolean('MaintenanceMode'),
            'trigger_details' => $details
        ];
        $this->SetValue('EventData', json_encode($payload));
    }

    // --- WIZARD UI LOGIC ---

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);

        // 1. Populate Class Options for Wizard
        $definedClasses = json_decode($this->ReadPropertyString('ClassList'), true);
        $classOptions = [];
        if (is_array($definedClasses)) {
            foreach ($definedClasses as $c) {
                $classOptions[] = ['caption' => $c['ClassName'], 'value' => $c['ClassName']];
            }
        }

        // Inject into Wizard Dropdown (ImportClass)
        // Path: Elements -> ExpansionPanel (last) -> Items -> Select (ImportClass)
        $elements = &$form['elements'];
        $lastIndex = count($elements) - 2;
        if (isset($elements[$lastIndex]['items'])) {
            foreach ($elements[$lastIndex]['items'] as &$item) {
                if (isset($item['name']) && $item['name'] === 'ImportClass') {
                    $item['options'] = $classOptions;
                }
            }
        }

        return json_encode($form);
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
            foreach ($list as &$row) $row['Selected'] = true;
        }
        $this->WriteAttributeString('ScanCache', json_encode($list));
        $this->UpdateFormField('ImportCandidates', 'values', json_encode($list));
    }

    public function UI_SelectNone()
    {
        $list = json_decode($this->ReadAttributeString('ScanCache'), true);
        if (is_array($list)) {
            foreach ($list as &$row) $row['Selected'] = false;
        }
        $this->WriteAttributeString('ScanCache', json_encode($list));
        $this->UpdateFormField('ImportCandidates', 'values', json_encode($list));
    }

    public function UI_Import(string $TargetClass)
    {
        $candidates = json_decode($this->ReadAttributeString('ScanCache'), true);
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
        $this->WriteAttributeString('ScanCache', '[]');
        $this->UpdateFormField('ImportCandidates', 'values', json_encode([]));
        $this->UpdateFormField('ImportCandidates', 'visible', false);
    }
}
