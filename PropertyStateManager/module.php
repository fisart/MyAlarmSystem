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
        $this->RegisterPropertyInteger("VaultInstanceID", 0);
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

        $this->RegisterHook("/hook/psm_logic_" . $this->InstanceID);
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

    protected function ProcessHookData()
    {
        $vaultID = $this->ReadPropertyInteger("VaultInstanceID");
        if ($vaultID > 0 && @IPS_InstanceExists($vaultID)) {
            if (!SEC_IsPortalAuthenticated($vaultID)) {
                $currentUrl = $_SERVER['REQUEST_URI'] ?? '';
                $loginUrl = "/hook/secrets_" . $vaultID . "?portal=1&return=" . urlencode($currentUrl);
                header("Location: " . $loginUrl);
                exit;
            }
        }

        $bits = $this->GetCurrentBitmask();
        $decisionMap = json_decode($this->ReadPropertyString("DecisionMap"), true);
        $targetState = $decisionMap[(string)$bits] ?? 0;

        echo "<html><head><style>
                body { font-family: sans-serif; background: #111; color: #eee; padding: 20px; }
                .bit-row { display: flex; justify-content: space-between; padding: 10px; border-bottom: 1px solid #333; }
                .active { color: #4caf50; font-weight: bold; }
                .inactive { color: #f44336; }
                .header { font-size: 1.5em; margin-bottom: 20px; color: #2196f3; }
                .footer { margin-top: 30px; padding: 20px; background: #222; border-radius: 8px; text-align: center; }
              </style></head><body>";

        echo "<div class='header'>Logic Analysis Dashboard</div>";
        echo "<h3>Current Sensor Status (Bitmask: $bits)</h3>";

        $labels = [
            "Front Door Lock",
            "Front Door Contact",
            "Basement Door Lock",
            "Basement Door Contact",
            "Presence Detected",
            "Delay Timer Active",
            "System Currently Armed"
        ];

        for ($i = 0; $i < 7; $i++) {
            $isActive = ($bits & (1 << $i));
            $status = $isActive ? "ON" : "OFF";
            $class = $isActive ? "active" : "inactive";
            echo "<div class='bit-row'><span>Bit $i: $labels[$i]</span><span class='$class'>$status</span></div>";
        }

        echo "<div class='footer'>
                <strong>Resulting Decision:</strong><br>
                <span style='font-size: 2em; color: #ff9800;'>" . $this->GetStateName($targetState) . "</span>
              </div>";
        echo "</body></html>";
    }

    private function GetStateName(int $id): string
    {
        $profiles = IPS_GetVariableProfile("PSM.State");
        foreach ($profiles['Associations'] as $assoc) {
            if ($assoc['Value'] == $id) return $assoc['Name'];
        }
        return "Unknown State";
    }

    protected function GetCurrentBitmask(): int
    {
        $mapping = json_decode($this->ReadPropertyString("GroupMapping"), true);
        $activeSensors = json_decode($this->ReadAttributeString("ActiveSensors"), true);
        $presenceMap = json_decode($this->ReadAttributeString("PresenceMap"), true);

        $bits = 0;
        // Bits 0-3: Hardware Sensors
        foreach ($mapping as $item) {
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
        // Bit 5: Timer
        if ($this->GetTimerInterval("DelayTimer") > 0) $bits |= (1 << 5);
        // Bit 6: Feedback
        if ($this->GetValue("SystemState") > 0) $bits |= (1 << 6);

        return $bits;
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
        $decisionMap = json_decode($this->ReadPropertyString("DecisionMap"), true);

        $bits = $this->GetCurrentBitmask();

        // Safe Lookup: Convert integer $bits to string key for JSON array
        $bitKey = (string)$bits;
        $newState = $decisionMap[$bitKey] ?? 0;

        if ($this->GetValue("SystemState") !== $newState) {
            $this->SetValue("SystemState", $newState);
            $this->SendDebug("LogicEngine", "Bitmask: $bits -> New State: $newState", 0);
        }

        // Timer Control Logic
        if ($newState == 2) {
            // Start timer if entering "Exit Delay" and it's not already running
            if ($this->GetTimerInterval("DelayTimer") == 0) {
                $duration = $this->ReadPropertyInteger("ArmingDelayDuration");
                $this->SetTimerInterval("DelayTimer", $duration * 60 * 1000);
            }
        } else {
            // Stop timer if state is no longer "Exit Delay" (e.g. Disarmed or Interrupted)
            if ($this->GetTimerInterval("DelayTimer") > 0) {
                $this->SetTimerInterval("DelayTimer", 0);
            }
        }
    }

    public function GetSystemState(): string
    {
        $status = [
            'StateID'       => $this->GetValue('SystemState'),
            'PresenceMap'   => json_decode($this->ReadAttributeString('PresenceMap'), true),
            'ActiveSensors' => json_decode($this->ReadAttributeString('ActiveSensors'), true),
            'IsDelayActive' => ($this->GetTimerInterval('DelayTimer') > 0)
        ];

        return json_encode($status);
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
