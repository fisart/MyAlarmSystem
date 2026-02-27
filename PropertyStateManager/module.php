<?php

declare(strict_types=1);

class PropertyStateManager extends IPSModule
{
public function Create()
    {
        parent::Create();

        // Properties
        $this->RegisterPropertyInteger("SensorGroupInstanceID", 0);
        $this->RegisterPropertyInteger("DispatchTargetID", 0); // Added for filtering
        $this->RegisterPropertyString("GroupMapping", "[]");
        $this->RegisterPropertyString("DecisionMap", "[]");

        // Attributes (ActiveSensors instead of ActiveAlarms as agreed)
        $this->RegisterAttributeString("ActiveSensors", "[]");
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

        // Timers
        $this->RegisterTimer("DelayTimer", 0, 'PSM_HandleTimer($_IPS[\'TARGET\']);');
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

        // Update Active Sensors (Variable IDs)
        $activeSensors = json_decode($this->ReadAttributeString("ActiveSensors"), true);

        if (isset($data['trigger_details']['variable_id'])) {
            $vID = $data['trigger_details']['variable_id'];
            // If the sensor is tripped, add to list; otherwise, remove it
            if ($data['trigger_details']['value_raw'] ?? false) {
                if (!in_array($vID, $activeSensors)) $activeSensors[] = $vID;
            } else {
                $activeSensors = array_values(array_diff($activeSensors, [$vID]));
            }
        }

        // Global Reset Logic: If no groups are active, clear the sensor list
        if (empty($data['active_groups'] ?? [])) {
            $activeSensors = [];
        }

        $this->WriteAttributeString("ActiveSensors", json_encode($activeSensors));

        $this->EvaluateState();
    }

    private function EvaluateState()
    {
        $mapping = json_decode($this->ReadPropertyString("GroupMapping"), true);
        $activeSensors = json_decode($this->ReadAttributeString("ActiveSensors"), true);
        $presenceMap = json_decode($this->ReadAttributeString("PresenceMap"), true);
        $decisionMap = json_decode($this->ReadPropertyString("DecisionMap"), true);

        $bits = 0;

        // Bits 0-3: Door/Lock Status (ID-based Mapping)
        foreach ($mapping as $item) {
            // Check if the mapped Variable ID (SourceKey) is in the active list
            if (in_array($item['SourceKey'], $activeSensors)) {
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

        // Bit 5: Delay Timer
        if ($this->GetTimerInterval("DelayTimer") > 0) {
            $bits |= (1 << 5);
        }

        // Bit 6: Alarm System Feedback
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
        $sensorGroupId = $this->ReadPropertyInteger("SensorGroupInstanceID");
        $targetID = $this->ReadPropertyInteger("DispatchTargetID");

        $targetOptions = [];
        $sensorOptions = [];

        if ($sensorGroupId > 0 && @IPS_InstanceExists($sensorGroupId)) {
            $configJSON = @MYALARM_GetConfiguration($sensorGroupId);
            if ($configJSON !== false) {
                $config = json_decode($configJSON, true);

                // 1. Populate Target Dropdown from DispatchTargets
                foreach ($config['DispatchTargets'] ?? [] as $t) {
                    $targetOptions[] = ["caption" => $t['Name'], "value" => (int)$t['InstanceID']];
                }

                // 2. Filter Logic (identical to your sample script)
                if ($targetID > 0) {
                    // Find GroupNames routed to this Target
                    $targetGroups = [];
                    foreach ($config['GroupDispatch'] ?? [] as $gd) {
                        if ((int)$gd['InstanceID'] === $targetID) {
                            $targetGroups[] = $gd['GroupName'];
                        }
                    }

                    // Find ClassIDs belonging to those Groups
                    $targetClasses = [];
                    foreach ($config['GroupMembers'] ?? [] as $gm) {
                        if (in_array($gm['GroupName'], $targetGroups)) {
                            $targetClasses[] = $gm['ClassID'];
                        }
                    }

                    // Get Sensors and format for Option A (Flat Hierarchy)
                    foreach ($config['SensorList'] ?? [] as $sensor) {
                        if (in_array($sensor['ClassID'], $targetClasses)) {
                            $vid = (int)$sensor['VariableID'];
                            $vName = IPS_ObjectExists($vid) ? IPS_GetName($vid) : "OBJECT DELETED";
                            
                            $caption = sprintf("%s > %s > %s (%d)", 
                                $sensor['GrandParentName'] ?? '?', 
                                $sensor['ParentName'] ?? '?', 
                                $vName, 
                                $vid
                            );

                            $sensorOptions[] = ["caption" => $caption, "value" => (string)$vid];
                        }
                    }
                }
            }
        }

        // 3. Inject into Form
        foreach ($form['elements'] as &$element) {
            if (isset($element['name']) && $element['name'] === 'DispatchTargetID') {
                $element['options'] = $targetOptions;
            }
            if (isset($element['name']) && $element['name'] === 'GroupMapping') {
                $element['columns'][0]['edit']['options'] = $sensorOptions;
            }
        }

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
