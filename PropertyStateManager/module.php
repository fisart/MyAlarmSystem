<?php

declare(strict_types=1);

class PropertyStateManager extends IPSModule
{
    public function Create()
    {
        parent::Create();

        // Properties
        $this->RegisterPropertyInteger("SensorGroupInstanceID", 0);
        $this->RegisterPropertyInteger("DispatchTargetID", 0);
        $this->RegisterPropertyString("GroupMapping", "[]");
        $this->RegisterPropertyString("DecisionMap", "[]");
        $this->RegisterPropertyInteger("SyncTimestamp", 0); // New property for Apply trigger
        $this->RegisterPropertyInteger("ArmingDelayDuration", 1);
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

    public function HandleTimer()
    {
        // Stop the timer
        $this->SetTimerInterval("DelayTimer", 0);

        // Log the event
        $this->LogMessage("Arming delay finished. System is now transitioning to Armed state.", KL_MESSAGE);

        // Re-evaluate state now that Bit 5 (Timer) will be 0
        $this->EvaluateState();
    }

    /**
     * This is called by the UI button. 
     * Simply calling it forces IP-Symcon to reload GetConfigurationForm.
     */
    public function UI_Refresh()
    {
        // Update the property value in the UI to trigger the 'Apply' button
        $this->UpdateFormField("SyncTimestamp", "value", time());
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
        $options = [];
        $targetOptions = [];

        if ($sensorGroupId > 0 && @IPS_InstanceExists($sensorGroupId)) {
            $configJSON = @MYALARM_GetConfiguration($sensorGroupId);
            if ($configJSON !== false) {
                $config = json_decode($configJSON, true);

                // Populate Target List
                foreach ($config['DispatchTargets'] ?? [] as $t) {
                    $targetOptions[] = ["caption" => $t['Name'], "value" => (int)$t['InstanceID']];
                }

                if ($targetID > 0) {
                    $this->LogMessage("GF: Filtering for Target ID: " . $targetID, KL_MESSAGE);

                    $targetGroups = [];
                    foreach ($config['GroupDispatch'] ?? [] as $gd) {
                        if ((int)$gd['InstanceID'] === $targetID) $targetGroups[] = $gd['GroupName'];
                    }

                    $targetClasses = [];
                    foreach ($config['GroupMembers'] ?? [] as $gm) {
                        if (in_array($gm['GroupName'], $targetGroups)) $targetClasses[] = $gm['ClassID'];
                    }
                    $this->LogMessage("GF: Allowed Classes: " . implode(", ", $targetClasses), KL_MESSAGE);

                    foreach ($config['SensorList'] ?? [] as $sensor) {
                        $vid = (int)$sensor['VariableID'];
                        if (in_array($sensor['ClassID'], $targetClasses)) {
                            $name = IPS_ObjectExists($vid) ? IPS_GetName($vid) : "Unknown";
                            $caption = sprintf("%s > %s > %s (%d)", $sensor['GrandParentName'] ?? '?', $sensor['ParentName'] ?? '?', $name, $vid);
                            $options[] = ["caption" => $caption, "value" => (string)$vid];
                        } else {
                        }
                    }
                }
            }
        }

        foreach ($form['elements'] as &$element) {
            if (isset($element['name']) && $element['name'] === 'DispatchTargetID') $element['options'] = $targetOptions;
            if (isset($element['name']) && $element['name'] === 'GroupMapping') {
                $element['columns'][0]['edit']['options'] = $options;
            }
        }
        return json_encode($form);
    }
}
