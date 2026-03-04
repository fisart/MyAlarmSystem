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
        $this->RegisterAttributeInteger("DelayExpired", 0);

        // Attributes (RAM Buffers)
        $this->RegisterAttributeString("ActiveSensors", "[]");
        $this->RegisterAttributeString("PresenceMap", "[]");
        $this->RegisterAttributeString("ActiveGroups", "[]");

        // Debug Attributes
        $this->RegisterAttributeString("LastPayload", "");
        $this->RegisterAttributeInteger("LastPayloadTime", 0);
        $this->RegisterAttributeString("PayloadHistory", "[]"); // NEW: History Buffer

        // Variable Profiles
        // Variable Profiles
        $this->EnsureStateProfile();

        // Variables
        $this->RegisterVariableInteger("SystemState", "System State", "PSM.State", 0);

        // Timers
        $this->RegisterTimer("DelayTimer", 0, 'PSM_HandleTimer($_IPS[\'TARGET\']);');
    }


    private function EnsureStateProfile(): void
    {
        if (!IPS_VariableProfileExists('PSM.State')) {
            IPS_CreateVariableProfile('PSM.State', 1); // Integer
        }

        // Keep associations aligned with the logic engine: 0,2,3,6
        IPS_SetVariableProfileAssociation('PSM.State', 0, "Disarmed", "", -1);
        IPS_SetVariableProfileAssociation('PSM.State', 2, "Exit Delay", "", -1);
        IPS_SetVariableProfileAssociation('PSM.State', 3, "Armed (External)", "", -1);
        IPS_SetVariableProfileAssociation('PSM.State', 6, "Armed (Internal)", "", -1);
        IPS_SetVariableProfileAssociation('PSM.State', 9, "Alarm Triggered!", "", -1);

        // Optional but useful: keep 1 from showing misleading old meaning if it exists somewhere
        IPS_SetVariableProfileAssociation('PSM.State', 1, "Legacy/Unused", "", -1);
    }
    public function ApplyChanges()
    {
        // Never delete this line!
        parent::ApplyChanges();
        $this->EnsureStateProfile();
        // Register the Webhook using the manual helper
        $this->RegisterHook('/hook/psm_logic_' . $this->InstanceID);

        // SYNC: Request current sensor state from Module 1 on startup/change
        $sensorGroupID = $this->ReadPropertyInteger("SensorGroupInstanceID");
        if ($sensorGroupID > 0 && @IPS_InstanceExists($sensorGroupID)) {
            if (function_exists('MYALARM_RequestStateSync')) {
                @MYALARM_RequestStateSync($sensorGroupID);
            }
        }
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
        // Mark that the delay has actually expired (not cancelled)
        $this->WriteAttributeInteger("DelayExpired", 1);

        // Stop timer (it has fired)
        $this->SetTimerInterval("DelayTimer", 0);

        // Single rules engine decides next state
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

        // NEW: Handle Manual Sync Request from HTML Button
        if (isset($_GET['sync'])) {
            $sensorGroupID = $this->ReadPropertyInteger("SensorGroupInstanceID");
            if ($sensorGroupID > 0 && @IPS_InstanceExists($sensorGroupID)) {
                if (function_exists('MYALARM_RequestStateSync')) {
                    @MYALARM_RequestStateSync($sensorGroupID);
                }
            }
            // Redirect to clear query parameter
            $cleanUrl = strtok($_SERVER['REQUEST_URI'], '?');
            header("Location: " . $cleanUrl);
            exit;
        }

        // ... (Existing Logic Calculation) ...
        $bits = $this->GetCurrentBitmask();
        $targetState = $this->GetValue("SystemState");
        $displayState = $this->GetStateName($targetState);

        $isDelayState = ($targetState === 2);
        $remainingSeconds = 0;

        if ($isDelayState) {
            $varID = $this->GetIDForIdent("SystemState");
            $varInfo = IPS_GetVariable($varID);
            $lastUpdate = $varInfo['VariableUpdated'];
            $durationSeconds = $this->ReadPropertyInteger("ArmingDelayDuration") * 60;
            $remainingSeconds = ($lastUpdate + $durationSeconds) - time();
            if ($remainingSeconds < 0) $remainingSeconds = 0;
        }

        $activeSensors = json_decode($this->ReadAttributeString("ActiveSensors"), true);
        $mapping = json_decode($this->ReadPropertyString("GroupMapping"), true);
        $mappedIDs = array_column($mapping, 'SourceKey');
        $unmappedSensors = array_diff($activeSensors, $mappedIDs);
        // Dashboard ON/OFF texts (polarity-aware for Window/Generic)
        $bitText = [
            0 => ['on' => 'Locked',       'off' => 'Unlocked'],
            1 => ['on' => 'Closed',       'off' => 'Open'],
            2 => ['on' => 'Locked',       'off' => 'Unlocked'],
            3 => ['on' => 'Someone Home', 'off' => 'Nobody Home'],
            4 => ['on' => 'Running',      'off' => 'Inactive'],
            5 => ['on' => 'Not Disarmed', 'off' => 'Disarmed'],
            6 => ['on' => 'Open',         'off' => 'Closed'],
            7 => ['on' => 'Closed',       'off' => 'Open'],
            8 => ['on' => 'Open',         'off' => 'Closed'], // will be adjusted below
            9 => ['on' => 'Open',         'off' => 'Closed'], // will be adjusted below
        ];

        // Decide label meaning for bit 8/9 based on mapping polarity.
        // Default: current convention (active means breach => ON=Open)
        $windowPolarity  = 'breach';
        $genericPolarity = 'breach';

        foreach ($mapping as $m) {
            $role = (string)($m['LogicalRole'] ?? '');
            $pol  = (string)($m['Polarity'] ?? '');
            if ($pol === '') continue;

            if ($role === 'Window Contact' && $pol === 'secure')  $windowPolarity  = 'secure';
            if ($role === 'Generic Door'   && $pol === 'secure')  $genericPolarity = 'secure';
        }

        // If polarity is "secure", then ON means Closed (secure)
        if ($windowPolarity === 'secure')  $bitText[8] = ['on' => 'Closed', 'off' => 'Open'];
        if ($genericPolarity === 'secure') $bitText[9] = ['on' => 'Closed', 'off' => 'Open'];
        // API Mode
        if (isset($_GET['api'])) {
            header("Content-Type: application/json");
            echo json_encode([
                'bits' => $bits,
                'state' => $displayState,
                'timer' => $remainingSeconds,
                'showTimer' => $isDelayState,
                'unmapped' => array_values($unmappedSensors),
                'bitText' => $bitText
            ]);
            return;
        }

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
                .btn-sync { float: right; background: #2196f3; color: white; border: none; padding: 8px 15px; border-radius: 4px; cursor: pointer; text-decoration: none; font-size: 0.6em; vertical-align: middle; }
                .panel { border: 1px solid #333; border-radius: 8px; padding: 12px; margin: 14px 0; background: #151515; }
.panel-title { font-size: 1.05em; font-weight: 600; color: #9ecbff; margin-bottom: 8px; }
.panel .bit-row:last-child { border-bottom: none; }
              </style>
              <script>
                function updateDashboard() {
                    fetch('?api=1&t=' + Date.now())
                        .then(response => response.json())
                        .then(data => {
                            document.getElementById('stateText').innerText = data.state;
                            // Updated loop to 10 to include Generic Door Bit
                            for (let i = 0; i < 10; i++) {
                                let isActive = (data.bits & (1 << i));
                                let el = document.getElementById('bit_' + i);
                                if (el) {
                                    // Prefer server-provided bitText (polarity-aware), fallback to local defaults
                                    const fallback = {
                                    0: { on: 'Locked',       off: 'Unlocked' },
                                    1: { on: 'Closed',       off: 'Open'     },
                                    2: { on: 'Locked',       off: 'Unlocked' },
                                    3: { on: 'Someone Home', off: 'Nobody Home' },
                                    4: { on: 'Running',      off: 'Inactive' },
                                    5: { on: 'Not Disarmed', off: 'Disarmed' },
                                    6: { on: 'Open',         off: 'Closed'   },
                                    7: { on: 'Closed',       off: 'Open'     },
                                    8: { on: 'Open',         off: 'Closed'   },
                                    9: { on: 'Open',         off: 'Closed'   }
                                    };

                                    const txt = (data.bitText && data.bitText[i]) ? data.bitText[i] : (fallback[i] || { on: 'ON', off: 'OFF' });

                                    el.innerText = isActive ? txt.on : txt.off;
                                    el.className = isActive ? 'active' : 'inactive';
                                }
                            }
                            let timerBox = document.getElementById('timerBox');
                            if (data.showTimer && data.timer > 0) {
                                timerBox.style.display = 'block';
                                timerBox.innerText = 'Arming in ' + Math.ceil(data.timer) + ' seconds...';
                            } else {
                                timerBox.style.display = 'none';
                            }
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
                setInterval(updateDashboard, 2000);
                window.onload = updateDashboard;
              </script>
              </head><body>";

        // HEADER with Sync Button
        echo "<div class='header'>
                Logic Analysis 
                <a href='?sync=1' class='btn-sync'>↻ Sync</a>
              </div>";

        echo "<div id='timerBox' class='timer'></div>";

        echo "<h3>Sensor Status</h3>";

        $labels = [
            "Front Door Lock",        // Bit 0
            "Front Door Contact",     // Bit 1
            "Basement Door Lock",     // Bit 2
            "Presence Detected",      // Bit 3
            "Delay Timer Active",     // Bit 4
            "System Currently Armed", // Bit 5
            "Bedroom Door Open",      // Bit 6
            "Basement Door Contact",  // Bit 7
            "Window Open",            // Bit 8
            "Generic Door Open"       // Bit 9 (NEW)
        ];

        // Grouped Panels (display only; keeps bit_0..bit_9 IDs unchanged)

        // Entrance Doors and Locks: 0,1,2,7
        echo "<div class='panel'><div class='panel-title'>Entrance Doors and Locks</div>";
        foreach ([0, 1, 2, 7] as $i) {
            echo "<div class='bit-row'>
            <span>Bit $i: " . ($labels[$i] ?? "Bit $i") . "</span>
            <span id='bit_$i' class='inactive'>...</span>
          </div>";
        }
        echo "</div>";

        // Presence: 3
        echo "<div class='panel'><div class='panel-title'>Presence</div>";
        foreach ([3] as $i) {
            echo "<div class='bit-row'>
            <span>Bit $i: " . ($labels[$i] ?? "Bit $i") . "</span>
            <span id='bit_$i' class='inactive'>...</span>
          </div>";
        }
        echo "</div>";

        // Bedroom Doors: 6
        echo "<div class='panel'><div class='panel-title'>Bedroom Doors</div>";
        foreach ([6] as $i) {
            echo "<div class='bit-row'>
            <span>Bit $i: " . ($labels[$i] ?? "Bit $i") . "</span>
            <span id='bit_$i' class='inactive'>...</span>
          </div>";
        }
        echo "</div>";

        // Generic Doors and Windows: 8,9
        echo "<div class='panel'><div class='panel-title'>Generic Doors and Windows</div>";
        foreach ([8, 9] as $i) {
            echo "<div class='bit-row'>
            <span>Bit $i: " . ($labels[$i] ?? "Bit $i") . "</span>
            <span id='bit_$i' class='inactive'>...</span>
          </div>";
        }
        echo "</div>";

        // System: 4,5 (recommended so they don’t “float” ungrouped)
        echo "<div class='panel'><div class='panel-title'>System</div>";
        foreach ([4, 5] as $i) {
            echo "<div class='bit-row'>
            <span>Bit $i: " . ($labels[$i] ?? "Bit $i") . "</span>
            <span id='bit_$i' class='inactive'>...</span>
          </div>";
        }
        echo "</div>";

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
        $mapping       = json_decode($this->ReadPropertyString("GroupMapping"), true);
        $activeSensors = json_decode($this->ReadAttributeString("ActiveSensors"), true);
        $activeGroups  = json_decode($this->ReadAttributeString("ActiveGroups"), true);
        $presenceMap   = json_decode($this->ReadAttributeString("PresenceMap"), true);

        $bits = 0;

        foreach ($mapping as $item) {
            $isTripped = in_array($item['SourceKey'], $activeSensors) || in_array($item['SourceKey'], $activeGroups);

            // --- Polarity handling (display only) ---
            $role     = (string)($item['LogicalRole'] ?? '');
            $polarity = (string)($item['Polarity'] ?? '');

            // Default polarity by role (keeps existing behavior if Polarity is missing)
            if ($polarity === '') {
                if ($role === 'Window Contact' || $role === 'Generic Door') {
                    $polarity = 'breach'; // current convention: active means open
                } else {
                    $polarity = 'secure'; // locks/contacts/presence: active means secure/true
                }
            }

            // What the RAW signal currently means by role
            $rawMeans = ($role === 'Window Contact' || $role === 'Generic Door') ? 'breach' : 'secure';

            // If user polarity differs from raw meaning, invert
            $isOn = ($polarity === $rawMeans) ? $isTripped : !$isTripped;
            // --- end polarity handling ---

            switch ($role) {
                case 'Front Door Lock':
                    if ($isOn) $bits |= (1 << 0);
                    break;
                case 'Front Door Contact':
                    if ($isOn) $bits |= (1 << 1);
                    break;
                case 'Basement Door Lock':
                    if ($isOn) $bits |= (1 << 2);
                    break;
                case 'Presence':
                    // Presence is not a secure/breach sensor, but polarity default keeps current display
                    if ($isOn) $bits |= (1 << 3);
                    break;
                case 'Basement Door Contact':
                    if ($isOn) $bits |= (1 << 7);
                    break;
                case 'Window Contact':
                    if ($isOn) $bits |= (1 << 8);
                    break;
                case 'Generic Door':
                    if ($isOn) $bits |= (1 << 9);
                    break;
            }
        }

        // Bit 3: Presence (Intent) & Bit 6: Bedroom Door Open
        foreach ($presenceMap as $room) {
            if ($room['SwitchState'] ?? false) {
                $bits |= (1 << 3);
            }
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
                if (!in_array($vID, $activeSensors)) {
                    $activeSensors[] = $vID;
                    $this->LogMessage("[PSM-Rx] Added Sensor $vID to Active List.", KL_MESSAGE);
                }
            } else {
                if (in_array($vID, $activeSensors)) {
                    $this->LogMessage("[PSM-Rx] Removed Sensor $vID from Active List.", KL_MESSAGE);
                }
                $activeSensors = array_values(array_diff($activeSensors, [$vID]));
            }
        }

        // Save and Log Status
        $count = count($activeSensors);
        $this->LogMessage("[PSM-Rx] Active Sensors Count: $count", KL_MESSAGE);

        $this->WriteAttributeString("ActiveSensors", json_encode($activeSensors));
        // 3. Save Active Groups (For Group-Level Logic like "Windows")
        if (isset($data['active_groups'])) {
            $this->WriteAttributeString("ActiveGroups", json_encode($data['active_groups']));
        }
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
        $this->WriteAttributeString("ActiveGroups", "[]");
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
        $mapping       = json_decode($this->ReadPropertyString("GroupMapping"), true);
        $activeSensors = json_decode($this->ReadAttributeString("ActiveSensors"), true);
        $activeGroups  = json_decode($this->ReadAttributeString("ActiveGroups"), true);
        $presenceMap   = json_decode($this->ReadAttributeString("PresenceMap"), true);

        // Reset Flags
        $frontLocked   = false;
        $frontClosed   = false;
        $baseLocked    = false;
        $baseClosed    = false;
        $windowsClosed = true; // Default to Closed (Secure)
        $presence      = false;
        $bedroomOpen   = false;

        // Parse Hardware Sensors
        foreach ($mapping as $item) {
            // Check if SourceKey is an Active Sensor ID OR an Active Group Name
            $isActive = in_array($item['SourceKey'], $activeSensors) || in_array($item['SourceKey'], $activeGroups);

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
                case 'Basement Door Contact':
                    if ($isActive) $baseClosed = true;
                    break;

                case 'Generic Door':
                case 'Window Contact':
                    // Polarity-aware interpretation:
                    // raw meaning for these roles is currently "breach" (active = open).
                    // If user sets Polarity="secure", we invert (active means closed).
                    $polarity = (string)($item['Polarity'] ?? '');
                    if ($polarity === '') $polarity = 'breach'; // keep old behavior if missing

                    // Determine whether this row indicates "open" (breach) after applying polarity
                    $isOpen = ($polarity === 'breach') ? $isActive : !$isActive;

                    if ($isOpen) $windowsClosed = false;
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
        $perimeterSecure = ($frontLocked && $frontClosed && $baseLocked && $baseClosed && $windowsClosed);
        // Derived Flags for ARMED behavior (per your guidance)
        $entranceUnlocked = (!$frontLocked || !$baseLocked);   // unlocking any entrance lock disarms
        $groupLevelOpen   = (!$windowsClosed);                 // opening any group-level window/door triggers alarm (when armed)
        $readyToSleep = ($presence && !$bedroomOpen);
        $readyToLeave = (!$presence);

        // DEBUG REPORTING
        $this->LogMessage(sprintf(
            "[PSM-Logic] Inputs: F-Lock:%d F-Close:%d B-Lock:%d B-Close:%d Win/GenClose:%d | Pres:%d BedOpen:%d || Secure:%s",
            $frontLocked,
            $frontClosed,
            $baseLocked,
            $baseClosed,
            $windowsClosed,
            $presence,
            $bedroomOpen,
            $perimeterSecure ? "YES" : "NO"
        ), KL_MESSAGE);

        // 2. State Machine Logic
        $currentState = $this->GetValue("SystemState");
        $newState     = $currentState;

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
                    break;
                }

                if ($presence && $bedroomOpen) {
                    $newState = 0;
                    $this->LogMessage("[PSM-Logic] Abort Delay: Bedroom Open", KL_WARNING);
                    break;
                }

                // Arm ONLY if the timer actually expired (not merely cancelled)
                if ($this->ReadAttributeInteger("DelayExpired") === 1) {
                    $newState = $presence ? 6 : 3; // Presence=true => Armed Internal, else Armed External
                    $this->LogMessage("[PSM-Logic] Exit Delay Expired: System Armed", KL_MESSAGE);
                }
                break;

            case 3: // ARMED EXTERNAL
                if ($entranceUnlocked) {
                    $newState = 0;
                    $this->LogMessage("[PSM-Logic] Disarm: Entrance lock unlocked (External Armed)", KL_MESSAGE);
                    break;
                }
                if ($groupLevelOpen) {
                    $newState = 9;
                    $this->LogMessage("[PSM-Logic] ALARM TRIGGERED: Group-level opening detected (External Armed)", KL_WARNING);
                    break;
                }
                break;

            case 6: // ARMED INTERNAL
                if ($entranceUnlocked) {
                    $newState = 0;
                    $this->LogMessage("[PSM-Logic] Disarm: Entrance lock unlocked (Internal Armed)", KL_MESSAGE);
                    break;
                }
                if ($bedroomOpen) {
                    $newState = 0;
                    $this->LogMessage("[PSM-Logic] Disarm: Bedroom door opened (Internal Armed)", KL_MESSAGE);
                    break;
                }
                if ($groupLevelOpen) {
                    $newState = 9;
                    $this->LogMessage("[PSM-Logic] ALARM TRIGGERED: Group-level opening detected (Internal Armed)", KL_WARNING);
                    break;
                }
                break;

            case 9: // ALARM TRIGGERED (latched, but can be cleared by authorized actions)
                // Authorized reset: unlocking any entrance lock always disarms (your rule)
                if ($entranceUnlocked) {
                    $newState = 0;
                    $this->LogMessage("[PSM-Logic] Alarm Cleared: Entrance lock unlocked", KL_MESSAGE);
                    break;
                }

                // Authorized reset in internal mode: opening a bedroom door disarms (your rule)
                // (We use $presence as the indicator for internal/home context)
                if ($presence && $bedroomOpen) {
                    $newState = 0;
                    $this->LogMessage("[PSM-Logic] Alarm Cleared: Bedroom door opened (Internal)", KL_MESSAGE);
                    break;
                }

                // Otherwise remain in alarm
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
                $this->WriteAttributeInteger("DelayExpired", 0);
                $duration = $this->ReadPropertyInteger("ArmingDelayDuration");
                $this->SetTimerInterval("DelayTimer", $duration * 60 * 1000);
                $this->LogMessage("[PSM-Timer] Exit Delay Started ($duration min)", KL_MESSAGE);
            }
        } elseif ($this->GetTimerInterval("DelayTimer") > 0) {
            $this->WriteAttributeInteger("DelayExpired", 0);
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
                    $this->LogMessage("GF: Filtering for Target ID: " . $targetID, KL_MESSAGE);

                    // 1. Identify Target Groups and Classes
                    $targetGroups = []; // Names of groups sent to us
                    foreach ($config['GroupDispatch'] ?? [] as $gd) {
                        if ((int)$gd['InstanceID'] === $targetID) {
                            $targetGroups[] = $gd['GroupName'];

                            // ADD GROUP OPTION (New)
                            // Allows mapping entire groups (e.g. "Windows")
                            $options[] = [
                                "caption" => "[GROUP] " . $gd['GroupName'],
                                "value" => $gd['GroupName']
                            ];
                        }
                    }

                    $targetClasses = [];
                    foreach ($config['GroupMembers'] ?? [] as $gm) {
                        if (in_array($gm['GroupName'], $targetGroups)) $targetClasses[] = $gm['ClassID'];
                    }

                    // 2. Add Sensor Options (Existing)
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

        // Fix: Add current Target ID if missing
        if ($targetID > 0) {
            $found = false;
            foreach ($targetOptions as $opt) {
                if ($opt['value'] == $targetID) {
                    $found = true;
                    break;
                }
            }
            if (!$found) $targetOptions[] = ["caption" => "⚠️ Unavailable / Old Target ($targetID)", "value" => $targetID];
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
