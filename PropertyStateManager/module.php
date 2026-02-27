<?php

declare(strict_types=1);

class PropertyStateManager extends IPSModule
{
    public function Create()
    {
        parent::Create();

        // Properties
        $this->RegisterPropertyInteger("SensorGroupInstanceID", 0);
        $this->RegisterPropertyString("GroupMapping", "[]");
        $this->RegisterPropertyString("DecisionMap", "[]"); // Stores the 128-rule JSON

        // Attributes for Data Buffers
        $this->RegisterAttributeString("ActiveAlarms", "[]");
        $this->RegisterAttributeString("PresenceMap", "[]");

        // Profiles and Variables
        if (!IPS_VariableProfileExists('PSM.State')) {
            IPS_CreateVariableProfile('PSM.State', 1);
            IPS_SetVariableProfileAssociation('PSM.State', 0, "Disarmed", "", -1);
            IPS_SetVariableProfileAssociation('PSM.State', 1, "Armed (Internal)", "", -1);
            IPS_SetVariableProfileAssociation('PSM.State', 2, "Armed (External)", "", -1);
            IPS_SetVariableProfileAssociation('PSM.State', 3, "Alarm Triggered!", "", -1);
        }
        $this->RegisterVariableInteger("SystemState", "System State", "PSM.State", 0);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
    }

    /**
     * This is called by the UI button. 
     * Simply calling it forces IP-Symcon to reload GetConfigurationForm.
     */
    public function UI_Refresh()
    {
        // No code needed, the act of calling this via onClick refreshes the form.
    }

    public function ReceivePayload(string $Payload)
    {
        $data = json_decode($Payload, true);
        if (!$data) return;

        // Update Presence from Bedroom Sync
        if (isset($data['event_type']) && $data['event_type'] === 'BEDROOM_SYNC') {
            $this->WriteAttributeString("PresenceMap", json_encode($data['bedrooms']));
        }

        // Update Active Groups
        $activeGroups = $data['active_groups'] ?? [];
        $this->WriteAttributeString("ActiveAlarms", json_encode($activeGroups));

        $this->EvaluateState();
    }

    private function EvaluateState()
    {
        $mapping = json_decode($this->ReadPropertyString("GroupMapping"), true);
        $activeGroups = json_decode($this->ReadAttributeString("ActiveAlarms"), true);
        $presenceMap = json_decode($this->ReadAttributeString("PresenceMap"), true);
        $decisionMap = json_decode($this->ReadPropertyString("DecisionMap"), true);

        $bits = 0;

        // Bits 0-3: Door/Lock Status (Structural Mapping)
        foreach ($mapping as $item) {
            if (in_array($item['SourceKey'], $activeGroups)) {
                switch ($item['LogicalRole']) {
                    case 'Front Door Lock':
                        $bits |= (1 << 0);
                        break;
                    case 'Front Door Contact':
                        $bits |= (1 << 1);
                        break;
                    case 'Basement Door Lock':
                        $bits |= (1 << 2);
                        break;
                    case 'Basement Door Contact':
                        $bits |= (1 << 3);
                        break;
                }
            }
        }

        // Bit 4: Presence
        foreach ($presenceMap as $room) {
            if ($room['SwitchState'] ?? false) {
                $bits |= (1 << 4);
                break;
            }
        }

        // Bit 5: Delay Timer (Corrected: Check if timer is actually running)
        if ($this->GetTimerInterval("DelayTimer") > 0) {
            $bits |= (1 << 5);
        }

        // Bit 6: Alarm System Feedback (Feedback bit)
        if ($this->GetValue("SystemState") > 0) {
            $bits |= (1 << 6);
        }

        // Safe Lookup: Convert integer $bits to string key for JSON array
        $bitKey = (string)$bits;
        $newState = $decisionMap[$bitKey] ?? 0;

        if ($this->GetValue("SystemState") !== $newState) {
            $this->SetValue("SystemState", $newState);
            $this->SendDebug("LogicEngine", "Bitmask: $bits -> New State: $newState", 0);
        }
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . "/form.json"), true);

        // 1. DYNAMICALLY POPULATE SOURCE KEYS
        $sensorGroupId = $this->ReadPropertyInteger("SensorGroupInstanceID");
        $options = [];
        if ($sensorGroupId > 0 && IPS_InstanceExists($sensorGroupId)) {
            $config = json_decode(IPS_GetConfiguration($sensorGroupId), true);
            // Search common list names
            $list = $config['Groups'] ?? $config['SensorList'] ?? $config['List'] ?? [];
            foreach ($list as $item) {
                $name = $item['Name'] ?? $item['Caption'] ?? 'Unknown';
                $options[] = ["caption" => $name, "value" => $name];
            }
        }
        $form['elements'][2]['columns'][0]['edit']['options'] = $options;

        return json_encode($form);
    }

    public function ExportLogicForAI()
    {
        $mapping = json_decode($this->ReadPropertyString("GroupMapping"), true);
        $decisionMap = json_decode($this->ReadPropertyString("DecisionMap"), true);

        echo "ALARM SYSTEM LOGIC EXPORT\n";
        echo "==========================\n\n";

        echo "BIT DEFINITIONS:\n";
        echo "0: Front Lock, 1: Front Contact, 2: Basement Lock, 3: Basement Contact,\n";
        echo "4: Presence, 5: Delay Timer, 6: System Armed (Feedback)\n\n";

        echo "INPUT MAPPING:\n";
        foreach ($mapping as $m) {
            echo "- Hardware '{$m['SourceKey']}' -> Role '{$m['LogicalRole']}'\n";
        }

        echo "\nDECISION RULES (Only active/non-zero rules shown):\n";
        // Iterate through all 128 possible states
        for ($i = 0; $i < 128; $i++) {
            $state = $decisionMap[(string)$i] ?? 0;

            // We only show non-zero states to keep the AI output clean
            if ($state !== 0) {
                $details = [];
                if ($i & (1 << 0)) $details[] = "Front Lock: LOCKED";
                if ($i & (1 << 1)) $details[] = "Front Contact: CLOSED";
                if ($i & (1 << 2)) $details[] = "Basement Lock: LOCKED";
                if ($i & (1 << 3)) $details[] = "Basement Contact: CLOSED";
                if ($i & (1 << 4)) $details[] = "Presence: SOMEONE HOME";
                if ($i & (1 << 5)) $details[] = "Timer: ACTIVE";
                if ($i & (1 << 6)) $details[] = "System: ALREADY ARMED";

                $cond = !empty($details) ? implode(" AND ", $details) : "All False";
                echo "Rule #$i: IF [$cond] THEN Result: State $state\n";
            }
        }
    }
}
