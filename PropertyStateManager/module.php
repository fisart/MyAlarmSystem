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

        $bits = $this->GetCurrentBitmask();

        // VALIDATION 1: Perimeter Integrity
        // We require Bits 0, 1, and 2 to be TRUE (1). Binary 111 = 7.
        if (($bits & 7) !== 7) {
            $this->LogMessage("[PSM-Timer] Arming ABORTED. Perimeter Fault detected (Bitmask: $bits).", KL_ERROR);
            $this->SetValue("SystemState", 0);
            $this->EvaluateState();
            return;
        }

        // VALIDATION 2: Internal Constraint
        // If Presence(Bit 3) is TRUE, BedroomOpen(Bit 6) must be FALSE.
        if (($bits & 8) && ($bits & 64)) {
            $this->LogMessage("[PSM-Timer] Internal Arming ABORTED. Bedroom Door Open.", KL_ERROR);
            $this->SetValue("SystemState", 0);
            $this->EvaluateState();
            return;
        }

        // Success: Proceed to Arm
        $targetState = ($bits & 8) ? 6 : 3;
        $this->SetValue("SystemState", $targetState);
        $this->LogMessage("[PSM-Timer] Validation Passed. System Armed (State $targetState).", KL_MESSAGE);

        // Re-evaluate to stabilize logic
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
        // 1. Authentication
        $vaultID = $this->ReadPropertyInteger("VaultInstanceID");
        if ($vaultID > 0 && @IPS_InstanceExists($vaultID)) {
            if (function_exists('SEC_IsPortalAuthenticated')) {
                if (!SEC_IsPortalAuthenticated($vaultID)) {
                    $currentUrl = $_SERVER['REQUEST_URI'] ?? '';
                    // Strip query params for clean return
                    $currentUrl = strtok($currentUrl, '?');
                    $loginUrl = "/hook/secrets_" . $vaultID . "?portal=1&return=" . urlencode($currentUrl);
                    header("Location: " . $loginUrl);
                    exit;
                }
            }
        }

        // 2. Logic Calculation (Shared by HTML and API)
        $bits = $this->GetCurrentBitmask();
        $targetState = $this->GetValue("SystemState");
        $displayState = $this->GetStateName($targetState);

        // Timer Calculation (Timestamp Based)
        $remainingSeconds = 0;
        $isDelayState = ($targetState === 2);

        if ($isDelayState) {
            $varID = $this->GetIDForIdent("SystemState");
            $varInfo = IPS_GetVariable($varID);
            $lastUpdate = $varInfo['VariableUpdated'];
            $durationSeconds = $this->ReadPropertyInteger("ArmingDelayDuration") * 60;
            $remainingSeconds = ($lastUpdate + $durationSeconds) - time();
            if ($remainingSeconds < 0) $remainingSeconds = 0;
        }

        // Diagnostic: Unmapped Sensors
        $activeSensors = json_decode($this->ReadAttributeString("ActiveSensors"), true);
        $mapping = json_decode($this->ReadPropertyString("GroupMapping"), true);
        $mappedIDs = array_column($mapping, 'SourceKey');
        $unmappedSensors = array_diff($activeSensors, $mappedIDs);

        // 3. API Mode (AJAX Request)
        if (isset($_GET['api'])) {
            header("Content-Type: application/json");
            echo json_encode([
                'bits' => $bits,
                'state' => $displayState,
                'timer' => $remainingSeconds,
                'showTimer' => $isDelayState,
                'unmapped' => array_values($unmappedSensors)
            ]);
            return; // Stop here for API requests
        }

        // 4. HTML Mode (Frontend)
        echo "<html><head>
              <meta name='viewport' content='width=device-width, initial-scale=1'>
              <style>
                body { font-family: sans-serif; background: #111; color: #eee; padding: 20px; }
                .bit-row { display: flex; justify-content: space-between; padding: 10px; border-bottom: 1px solid #333; }
                .active { color: #4caf50; font-weight: bold; }
                .inactive { color: #f44336; }
                .warning { color: #ffeb3b; font-weight: bold; margin-top: 20px; border: 1px solid #ffeb3b; padding: 10px; display: none; }
                .timer { background: #e91e63; color: white; padding: 15px; text-align: center; font-size: 1.2em; font-weight: bold; border-radius: 5px; margin-bottom: 20px; display: none; }
                .header { font-size: 1.5em; margin-bottom: 20px; color: #2196f3; }
                .footer { margin-top: 30px; padding: 20px; background: #222; border-radius: 8px; text-align: center; }
              </style>
              <script>
                function updateDashboard() {
                    fetch('?api=1')
                        .then(response => response.json())
                        .then(data => {
                            // Update State Text
                            document.getElementById('stateText').innerText = data.state;

                            // Update Bits
                            for (let i = 0; i < 7; i++) {
                                let isActive = (data.bits & (1 << i));
                                let el = document.getElementById('bit_' + i);
                                el.innerText = isActive ? 'ON' : 'OFF';
                                el.className = isActive ? 'active' : 'inactive';
                            }

                            // Update Timer
                            let timerBox = document.getElementById('timerBox');
                            if (data.showTimer && data.timer > 0) {
                                timerBox.style.display = 'block';
                                timerBox.innerText = 'Arming in ' + Math.ceil(data.timer) + ' seconds...';
                            } else {
                                timerBox.style.display = 'none';
                            }

                            // Update Unmapped Warning
                            let warnBox = document.getElementById('warnBox');
                            if (data.unmapped.length > 0) {
                                warnBox.style.display = 'block';
                                warnBox.innerHTML = '⚠️ Unmapped Sensors: ' + data.unmapped.join(', ');
                            } else {
                                warnBox.style.display = 'none';
                            }
                        })
                        .catch(err => console.error('API Error:', err));
                }
                // Poll every 2 seconds
                setInterval(updateDashboard, 2000);
                // Run immediately on load
                window.onload = updateDashboard;
              </script>
              </head><body>";

        echo "<div class='header'>Logic Analysis Dashboard</div>";

        // Timer Container (Hidden by default, toggled by JS)
        echo "<div id='timerBox' class='timer'></div>";

        echo "<h3>Sensor Status</h3>";

        $labels = [
            "Front Door Lock",
            "Front Door Contact",
            "Basement Door Lock",
            "Presence Detected",
            "Delay Timer Active",
            "System Currently Armed",
            "Bedroom Door Open"
        ];

        for ($i = 0; $i < 7; $i++) {
            // Initial render uses placeholders, JS updates them immediately
            echo "<div class='bit-row'>
                    <span>Bit $i: " . ($labels[$i] ?? "Bit $i") . "</span>
                    <span id='bit_$i' class='inactive'>...</span>
                  </div>";
        }

        // Warning Container
        echo "<div id='warnBox' class='warning'></div>";

        echo "<div class='footer'>
                <strong>System State:</strong><br>
                <span id='stateText' style='font-size: 2em; color: #ff9800;'>Loading...</span>
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
        // Bits 0-2: Hardware Sensors & Presence Mapping
        foreach ($mapping as $item) {
            $isTripped = in_array($item['SourceKey'], $activeSensors);

            switch ($item['LogicalRole']) {
                case 'Front Door Lock':
                    if ($isTripped) $bits |= (1 << 0);
                    break;
                case 'Front Door Contact':
                    if ($isTripped) $bits |= (1 << 1);
                    break;
                case 'Basement Door Lock':
                    if ($isTripped) $bits |= (1 << 2);
                    break;
                case 'Presence':
                    if ($isTripped) $bits |= (1 << 3);
                    break;
            }
        }

        // Bit 3: Presence (Intent) & Bit 6: Bedroom Door Open
        foreach ($presenceMap as $room) {
            if ($room['SwitchState'] ?? false) {
                $bits |= (1 << 3);
            }
            // NEW: Set Bit 6 if any bedroom door is open
            if ($room['DoorTripped'] ?? false) {
                $bits |= (1 << 6);
            }
        }

        // Bit 4: Timer
        if ($this->GetTimerInterval("DelayTimer") > 0) $bits |= (1 << 4);

        // Bit 5: Feedback
        if ($this->GetValue("SystemState") > 0) $bits |= (1 << 5);

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
        // Increased buffer from 20 to 100 to capture bursts
        if (count($history) > 100) $history = array_slice($history, 0, 100);
        $this->WriteAttributeString("PayloadHistory", json_encode($history));
        // --- HISTORY LOGGING END ---

        // Debug storage
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

        // Load current list
        $activeSensors = json_decode($this->ReadAttributeString("ActiveSensors"), true);
        if (!is_array($activeSensors)) $activeSensors = [];

        // 1. Global Reset Logic
        if (isset($data['active_groups']) && empty($data['active_groups'])) {
            $activeSensors = [];
            $this->LogMessage("[PSM-Rx] Global Reset: All sensors cleared.", KL_MESSAGE);
        }

        // 2. Handle specific Sensor Event
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

        // Save and Log Status
        $count = count($activeSensors);
        $this->LogMessage("[PSM-Rx] Active Sensors Count: $count", KL_MESSAGE);

        $this->WriteAttributeString("ActiveSensors", json_encode($activeSensors));
        $this->EvaluateState();
    }

    public function ResetPayloadHistory()
    {
        // Clear Debug Logs
        $this->WriteAttributeString("PayloadHistory", "[]");
        $this->WriteAttributeString("LastPayload", "");
        $this->WriteAttributeInteger("LastPayloadTime", 0);

        // Clear Active Sensor Memory (Fix for "Stale" sensors)
        $this->WriteAttributeString("ActiveSensors", "[]");
        $this->WriteAttributeString("PresenceMap", "[]");

        // Reset State
        $this->SetValue("SystemState", 0);

        $this->LogMessage("System Reset: Logs, Sensor Memory, and State cleared.", KL_MESSAGE);
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
        // 1. Gather Inputs
        $mapping = json_decode($this->ReadPropertyString("GroupMapping"), true);
        $activeSensors = json_decode($this->ReadAttributeString("ActiveSensors"), true);
        $presenceMap = json_decode($this->ReadAttributeString("PresenceMap"), true);

        // Reset Flags
        $frontLocked = false;
        $frontClosed = false;
        $baseLocked = false;
        $presence = false;
        $bedroomOpen = false;

        // Parse Hardware Sensors
        foreach ($mapping as $item) {
            $isActive = in_array($item['SourceKey'], $activeSensors);
            switch ($item['LogicalRole']) {
                case 'Front Door Lock':
                    if ($isActive) $frontLocked = true;
                    break;
                case 'Front Door Contact':
                    if ($isActive) $frontClosed = true;
                    break;
                case 'Basement Door Lock':
                    if ($isActive) $baseLocked = true;
                    break;
                case 'Presence':
                    if ($isActive) $presence = true;
                    break;
            }
        }

        // Parse Bedroom Metadata
        foreach ($presenceMap as $room) {
            if ($room['SwitchState'] ?? false) $presence = true;
            if ($room['DoorTripped'] ?? false) $bedroomOpen = true;
        }

        // Derived Conditions
        $perimeterSecure = ($frontLocked && $frontClosed && $baseLocked);
        $readyToSleep = ($presence && !$bedroomOpen);
        $readyToLeave = (!$presence);

        // DEBUG REPORTING (New)
        $this->LogMessage(sprintf(
            "[PSM-Logic] Inputs: F-Lock:%d F-Close:%d B-Lock:%d | Pres:%d BedOpen:%d || Secure:%s",
            $frontLocked,
            $frontClosed,
            $baseLocked,
            $presence,
            $bedroomOpen,
            $perimeterSecure ? "YES" : "NO"
        ), KL_MESSAGE);

        // 2. State Machine Logic
        $currentState = $this->GetValue("SystemState");
        $newState = $currentState;

        switch ($currentState) {
            case 0: // DISARMED
                if ($perimeterSecure) {
                    if ($readyToLeave || $readyToSleep) {
                        $newState = 2;
                    }
                }
                break;

            case 2: // EXIT DELAY
                if (!$perimeterSecure) {
                    $newState = 0;
                    $this->LogMessage("[PSM-Logic] Abort Delay: Perimeter Unsecure", KL_WARNING);
                }
                if ($presence && $bedroomOpen) {
                    $newState = 0;
                    $this->LogMessage("[PSM-Logic] Abort Delay: Bedroom Open", KL_WARNING);
                }
                break;

            case 3: // ARMED EXTERNAL
                if (!$perimeterSecure) {
                    $newState = 0;
                    $this->LogMessage("[PSM-Logic] Alarm/Disarm: Perimeter Breach", KL_WARNING);
                }
                break;

            case 6: // ARMED INTERNAL
                if (!$perimeterSecure || $bedroomOpen) {
                    $newState = 0;
                    $this->LogMessage("[PSM-Logic] Alarm/Disarm: Security Breach", KL_WARNING);
                }
                break;
        }

        // 3. Execute Transition
        if ($currentState !== $newState) {
            $this->SetValue("SystemState", $newState);
            $this->LogMessage("[PSM-Logic] State change: $currentState -> $newState", KL_MESSAGE);
        }

        // 4. Timer Management
        if ($newState == 2) {
            if ($currentState != 2) {
                $duration = $this->ReadPropertyInteger("ArmingDelayDuration");
                $this->SetTimerInterval("DelayTimer", $duration * 60 * 1000);
                $this->LogMessage("[PSM-Timer] Exit Delay Started ($duration min)", KL_MESSAGE);
            }
        } elseif ($this->GetTimerInterval("DelayTimer") > 0) {
            $this->SetTimerInterval("DelayTimer", 0);
            $this->LogMessage("[PSM-Timer] Exit Delay Cancelled", KL_MESSAGE);
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
