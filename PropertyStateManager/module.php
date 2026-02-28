<?php

declare(strict_types=1);

class PropertyStateManager extends IPSModule
{
    public function Create()
    {
        // Never delete this line!
        parent::Create();

        // Properties
        $this->RegisterPropertyInteger("SensorGroupInstanceID", 0);
        $this->RegisterPropertyInteger("DispatchTargetID", 0);
        $this->RegisterPropertyString("GroupMapping", "[]");
        $this->RegisterPropertyString("DecisionMap", "[]");
        $this->RegisterPropertyInteger("SyncTimestamp", 0);
        $this->RegisterPropertyInteger("ArmingDelayDuration", 1);
        $this->RegisterPropertyInteger("VaultInstanceID", 0);

        // Attributes (RAM Buffers)
        $this->RegisterAttributeString("ActiveSensors", "[]");
        $this->RegisterAttributeString("PresenceMap", "[]");

        // Debug Attributes
        $this->RegisterAttributeString("LastPayload", "");
        $this->RegisterAttributeInteger("LastPayloadTime", 0);
        $this->RegisterAttributeString("PayloadHistory", "[]"); // NEW: History Buffer

        // Variable Profiles
        if (!IPS_VariableProfileExists('PSM.State')) {
            IPS_CreateVariableProfile('PSM.State', 1);
            IPS_SetVariableProfileAssociation('PSM.State', 0, "Disarmed", "", -1);
            IPS_SetVariableProfileAssociation('PSM.State', 1, "Armed (Internal)", "", -1);
            IPS_SetVariableProfileAssociation('PSM.State', 2, "Armed (External)", "", -1);
            IPS_SetVariableProfileAssociation('PSM.State', 3, "Alarm Triggered!", "", -1);
        }

        // Variables
        $this->RegisterVariableInteger("SystemState", "System State", "PSM.State", 0);

        // Timers
        $this->RegisterTimer("DelayTimer", 0, 'PSM_HandleTimer($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        // Never delete this line!
        parent::ApplyChanges();

        // Register the Webhook using the manual helper
        $this->RegisterHook('/hook/psm_logic_' . $this->InstanceID);
    }

    private function RegisterHook($WebHook)
    {
        $ids = IPS_GetInstanceListByModuleID("{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}");

        if (count($ids) > 0) {
            $hooks = json_decode(IPS_GetProperty($ids[0], "Hooks"), true);
            $found = false;

            if (!is_array($hooks)) {
                $hooks = [];
            }

            foreach ($hooks as $index => $hook) {
                if ($hook['Hook'] == $WebHook) {
                    if ($hook['TargetID'] == $this->InstanceID) {
                        return;
                    }
                    $hooks[$index]['TargetID'] = $this->InstanceID;
                    $found = true;
                }
            }
            if (!$found) {
                $hooks[] = ["Hook" => $WebHook, "TargetID" => $this->InstanceID];
            }
            IPS_SetProperty($ids[0], "Hooks", json_encode($hooks));
            IPS_ApplyChanges($ids[0]);
        }
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
            if (function_exists('SEC_IsPortalAuthenticated')) {
                if (!SEC_IsPortalAuthenticated($vaultID)) {
                    $currentUrl = $_SERVER['REQUEST_URI'] ?? '';
                    $loginUrl = "/hook/secrets_" . $vaultID . "?portal=1&return=" . urlencode($currentUrl);
                    header("Location: " . $loginUrl);
                    exit;
                }
            }
        }

        $bits = $this->GetCurrentBitmask();
        $decisionMap = json_decode($this->ReadPropertyString("DecisionMap"), true);

        // Calculate Target State
        if (empty($decisionMap)) {
            // Replicate default matrix logic for display
            $defaultMatrix = [
                1,
                -1,
                1,
                1,
                1,
                -1,
                1,
                3,
                0,
                -1,
                0,
                0,
                0,
                -1,
                0,
                0,
                -1,
                -1,
                -1,
                0,
                1,
                1,
                1,
                2,
                -1,
                -1,
                -1,
                -1,
                -1,
                -1,
                -1,
                2,
                0,
                -1,
                0,
                0,
                -1,
                -1,
                0,
                3,
                -1,
                -1,
                -1,
                -1,
                -1,
                -1,
                -1,
                6,
                -1,
                -1,
                -1,
                -1,
                -1,
                -1,
                -1,
                -1,
                -1,
                -1,
                -1,
                -1,
                -1,
                -1,
                -1,
                -1
            ];
            $targetState = $defaultMatrix[$bits] ?? 0;
        } else {
            $targetState = $decisionMap[(string)$bits] ?? 0;
        }

        // Display -1 as Illogical
        $displayState = ($targetState === -1) ? "Illogical (-1)" : $this->GetStateName($targetState);

        // Diagnostic: Find Active Sensors that are NOT mapped
        $activeSensors = json_decode($this->ReadAttributeString("ActiveSensors"), true);
        $mapping = json_decode($this->ReadPropertyString("GroupMapping"), true);
        $mappedIDs = array_column($mapping, 'SourceKey');
        $unmappedSensors = array_diff($activeSensors, $mappedIDs);

        echo "<html><head><style>
                body { font-family: sans-serif; background: #111; color: #eee; padding: 20px; }
                .bit-row { display: flex; justify-content: space-between; padding: 10px; border-bottom: 1px solid #333; }
                .active { color: #4caf50; font-weight: bold; }
                .inactive { color: #f44336; }
                .warning { color: #ffeb3b; font-weight: bold; margin-top: 20px; border: 1px solid #ffeb3b; padding: 10px; }
                .header { font-size: 1.5em; margin-bottom: 20px; color: #2196f3; }
                .footer { margin-top: 30px; padding: 20px; background: #222; border-radius: 8px; text-align: center; }
              </style></head><body>";

        echo "<div class='header'>Logic Analysis Dashboard</div>";
        echo "<h3>Current Sensor Status (Bitmask: $bits)</h3>";

        // UPDATED LABELS TO MATCH 6-BIT LOGIC
        $labels = [
            "Front Door Lock",       // Bit 0
            "Front Door Contact",    // Bit 1
            "Basement Door Lock",    // Bit 2
            "Presence Detected",     // Bit 3
            "Delay Timer Active",    // Bit 4
            "System Currently Armed" // Bit 5
        ];

        for ($i = 0; $i < 6; $i++) {
            $isActive = ($bits & (1 << $i));
            $status = $isActive ? "ON" : "OFF";
            $class = $isActive ? "active" : "inactive";
            echo "<div class='bit-row'><span>Bit $i: $labels[$i]</span><span class='$class'>$status</span></div>";
        }

        if (!empty($unmappedSensors)) {
            echo "<div class='warning'>⚠️ Unmapped Active Sensors Detected:<br>";
            foreach ($unmappedSensors as $id) {
                echo "ID: $id (Ignored by Logic)<br>";
            }
            echo "</div>";
        }

        echo "<div class='footer'>
                <strong>Resulting Decision:</strong><br>
                <span style='font-size: 2em; color: #ff9800;'>" . $displayState . "</span>
              </div>";
        echo "</body></html>";
    }

    private function GetStateName(int $id)
    {
        $profiles = IPS_GetVariableProfile("PSM.State");
        foreach ($profiles['Associations'] as $assoc) {
            if ($assoc['Value'] == $id) return $assoc['Name'];
        }
        return "Unknown State";
    }
    public function GetActiveSensorList()
    {
        $list = json_decode($this->ReadAttributeString("ActiveSensors"), true);
        return json_encode($list ?: []);
    }
    protected function GetCurrentBitmask()
    {
        $mapping = json_decode($this->ReadPropertyString("GroupMapping"), true);
        $activeSensors = json_decode($this->ReadAttributeString("ActiveSensors"), true);
        $presenceMap = json_decode($this->ReadAttributeString("PresenceMap"), true);

        $bits = 0;
        // Bits 0-2: Hardware Sensors (Aligned to User's 64-Rule Table)
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
                    // Removed 'Basement Door Contact' to restore 6-bit alignment
                    case 'Presence':
                        $bits |= (1 << 3); // Shifted from 4 to 3
                        break;
                }
            }
        }
        // Bit 3: Presence (Bedroom Sync)
        foreach ($presenceMap as $room) {
            if ($room['SwitchState'] ?? false) {
                $bits |= (1 << 3); // Shifted from 4 to 3
                break;
            }
        }
        // Bit 4: Timer
        if ($this->GetTimerInterval("DelayTimer") > 0) $bits |= (1 << 4); // Shifted from 5 to 4

        // Bit 5: Feedback
        if ($this->GetValue("SystemState") > 0) $bits |= (1 << 5); // Shifted from 6 to 5

        return $bits;
    }
    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'ReceivePayload':
                // Forward the generic action to the specific logic function
                $this->ReceivePayload($Value);
                break;
            default:
                throw new Exception("Invalid Ident: $Ident");
        }
    }
    public function GetPayloadHistory()
    {
        return $this->ReadAttributeString("PayloadHistory");
    }

    public function ReceivePayload(string $Payload)
    {
        $data = json_decode($Payload, true);
        if (!$data) return;

        // --- HISTORY LOGGING START ---
        $history = json_decode($this->ReadAttributeString("PayloadHistory"), true);
        if (!is_array($history)) $history = [];
        array_unshift($history, [
            'Time' => date('Y-m-d H:i:s'),
            'Data' => $data
        ]);
        if (count($history) > 20) $history = array_slice($history, 0, 20); // Keep last 20
        $this->WriteAttributeString("PayloadHistory", json_encode($history));
        // --- HISTORY LOGGING END ---

        // Debug storage (Legacy single value)
        $this->WriteAttributeString("LastPayload", $Payload);
        $this->WriteAttributeInteger("LastPayloadTime", time());

        $type = $data['event_type'] ?? 'ALARM';
        $source = $data['source_name'] ?? 'Unknown Source';

        $this->LogMessage("[PSM-Rx] Received '$type' from '$source'", KL_MESSAGE);

        // Handle Bedroom Sync
        if ($type === 'BEDROOM_SYNC') {
            $this->WriteAttributeString("PresenceMap", json_encode($data['bedrooms'] ?? []));
            $this->EvaluateState();
            return;
        }

        // Handle Sensor Events
        $activeSensors = json_decode($this->ReadAttributeString("ActiveSensors"), true);
        if (!is_array($activeSensors)) $activeSensors = [];

        if (isset($data['trigger_details']['variable_id'])) {
            $vID = (string)$data['trigger_details']['variable_id'];
            $val = $data['trigger_details']['value_raw'] ?? false;

            // DIAGNOSTIC: Check mapping
            $mapping = json_decode($this->ReadPropertyString("GroupMapping"), true);
            $isMapped = false;
            foreach ($mapping as $m) {
                if ($m['SourceKey'] == $vID) {
                    $isMapped = true;
                    $this->LogMessage("[PSM-Rx] Diagnostic: Sensor $vID matched to Role '" . $m['LogicalRole'] . "'", KL_MESSAGE);
                    break;
                }
            }
            if (!$isMapped) {
                $this->LogMessage("[PSM-Rx] Diagnostic: WARNING - Sensor $vID received but NOT MAPPED in configuration.", KL_WARNING);
            }

            // Update Active List
            if ($val) {
                if (!in_array($vID, $activeSensors)) $activeSensors[] = $vID;
            } else {
                $activeSensors = array_values(array_diff($activeSensors, [$vID]));
            }
        }


        $this->WriteAttributeString("ActiveSensors", json_encode($activeSensors));
        $this->EvaluateState();
    }

    public function GetLastPayload()
    {
        $payload = $this->ReadAttributeString("LastPayload");
        $timestamp = $this->ReadAttributeInteger("LastPayloadTime");

        return json_encode([
            "Time" => $timestamp > 0 ? date("Y-m-d H:i:s", $timestamp) : "Never",
            "RawData" => $payload,
            "Decoded" => json_decode($payload, true)
        ], JSON_PRETTY_PRINT);
    }

    private function EvaluateState()
    {
        $decisionMap = json_decode($this->ReadPropertyString("DecisionMap"), true);
        $bits = $this->GetCurrentBitmask();

        // Fallback: Use Default Logic if Configuration is empty
        if (empty($decisionMap)) {
            $defaultMatrix = [
                1,
                -1,
                1,
                1,
                1,
                -1,
                1,
                3,
                0,
                -1,
                0,
                0,
                0,
                -1,
                0,
                0, // 0-15
                -1,
                -1,
                -1,
                0,
                1,
                1,
                1,
                2,
                -1,
                -1,
                -1,
                -1,
                -1,
                -1,
                -1,
                2, // 16-31
                0,
                -1,
                0,
                0,
                -1,
                -1,
                0,
                3,
                -1,
                -1,
                -1,
                -1,
                -1,
                -1,
                -1,
                6, // 32-47
                -1,
                -1,
                -1,
                -1,
                -1,
                -1,
                -1,
                -1,
                -1,
                -1,
                -1,
                -1,
                -1,
                -1,
                -1,
                -1  // 48-63
            ];
            $newState = $defaultMatrix[$bits] ?? 0;
        } else {
            $bitKey = (string)$bits;
            $newState = $decisionMap[$bitKey] ?? 0;
        }

        // Fix: If state is Illogical (-1), force Disarmed (0) to prevent deadlocks
        if ($newState === -1) {
            $this->SendDebug("LogicEngine", "Illogical Condition (Bitmask: $bits). Defaulting to Disarmed.", 0);
            $newState = 0;
        }

        if ($this->GetValue("SystemState") !== $newState) {
            $this->SetValue("SystemState", $newState);
            $this->LogMessage("[PSM-Logic] State transitioned to ID $newState (Bitmask: $bits)", KL_MESSAGE);
        }

        // Timer Control Logic
        if ($newState == 2) {
            if ($this->GetTimerInterval("DelayTimer") == 0) {
                $duration = $this->ReadPropertyInteger("ArmingDelayDuration");
                $this->SetTimerInterval("DelayTimer", $duration * 60 * 1000);
                $this->LogMessage("[PSM-Timer] Exit Delay Started ($duration min)", KL_MESSAGE);
            }
        } else {
            if ($this->GetTimerInterval("DelayTimer") > 0) {
                $this->SetTimerInterval("DelayTimer", 0);
                $this->LogMessage("[PSM-Timer] Exit Delay Cancelled", KL_MESSAGE);
            }
        }
    }

    public function GetSystemState()
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
                    // Logic to populate options (unchanged)
                    $targetGroups = [];
                    foreach ($config['GroupDispatch'] ?? [] as $gd) {
                        if ((int)$gd['InstanceID'] === $targetID) $targetGroups[] = $gd['GroupName'];
                    }
                    $targetClasses = [];
                    foreach ($config['GroupMembers'] ?? [] as $gm) {
                        if (in_array($gm['GroupName'], $targetGroups)) $targetClasses[] = $gm['ClassID'];
                    }
                    foreach ($config['SensorList'] ?? [] as $sensor) {
                        $vid = (int)$sensor['VariableID'];
                        if (in_array($sensor['ClassID'], $targetClasses)) {
                            $name = IPS_ObjectExists($vid) ? IPS_GetName($vid) : "Unknown";
                            $caption = sprintf("%s > %s > %s (%d)", $sensor['GrandParentName'] ?? '?', $sensor['ParentName'] ?? '?', $name, $vid);
                            $options[] = ["caption" => $caption, "value" => (string)$vid];
                        }
                    }
                }
            }
        }

        // FIX: Add current Target ID to options if missing (prevents "Invalid configuration" error)
        if ($targetID > 0) {
            $found = false;
            foreach ($targetOptions as $opt) {
                if ($opt['value'] == $targetID) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $targetOptions[] = ["caption" => "⚠️ Unavailable / Old Target ($targetID)", "value" => $targetID];
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
