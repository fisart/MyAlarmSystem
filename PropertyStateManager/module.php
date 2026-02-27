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
        $this->RegisterPropertyString("GroupMapping", "[]");

        // Attributes (RAM Buffers - Blueprint Strategy 2.0)
        $this->RegisterAttributeString("PresenceMap", "[]");
        $this->RegisterAttributeString("ActiveAlarms", "[]");
        $this->RegisterAttributeString("RuleConfiguration", "[]");

        // Variable Profiles
        if (!IPS_VariableProfileExists('PSM.State')) {
            IPS_CreateVariableProfile('PSM.State', 1); // Integer
            IPS_SetVariableProfileAssociation('PSM.State', 0, "Disarmed", "Information", -1);
            IPS_SetVariableProfileAssociation('PSM.State', 1, "Intent Leaving", "Motion", -1);
            IPS_SetVariableProfileAssociation('PSM.State', 2, "Exit Delay", "Clock", -1);
            IPS_SetVariableProfileAssociation('PSM.State', 3, "Armed Away", "Shield", -1);
            IPS_SetVariableProfileAssociation('PSM.State', 4, "Armed Holiday", "Airplane", -1);
            IPS_SetVariableProfileAssociation('PSM.State', 5, "Intent Bedtime", "Moon", -1);
            IPS_SetVariableProfileAssociation('PSM.State', 6, "Armed Night", "Moon", -1);
        }

        // Variables
        $this->RegisterVariableInteger("SystemState", "System State", "PSM.State", 0);

        // Timers
        $this->RegisterTimer("DelayTimer", 0, 'PSM_HandleTimer($_IPS[\'TARGET\']);');
    }

    public function ReceivePayload(string $Payload)
    {
        $data = json_decode($Payload, true);
        if (!$data) {
            $this->LogMessage("ReceivePayload: Invalid JSON received.", KL_MESSAGE);
            return;
        }

        // Case 1: Bedroom Sync (Presence Tracking)
        if (isset($data['event_type']) && $data['event_type'] === 'BEDROOM_SYNC') {
            $this->WriteAttributeString("PresenceMap", json_encode($data['bedrooms']));
            $this->LogMessage("Presence Map updated via BEDROOM_SYNC.", KL_MESSAGE);
            $this->EvaluateState();
            return;
        }

        // Case 2: Standard Alarm / Reset Event
        // Update the list of currently active groups
        $activeGroups = isset($data['active_groups']) ? $data['active_groups'] : [];
        $this->WriteAttributeString("ActiveAlarms", json_encode($activeGroups));

        $this->LogMessage("Active Alarms updated. Primary Group: " . ($data['primary_group'] ?? 'None'), KL_MESSAGE);

        // Trigger the state machine logic
        $this->EvaluateState();
    }

    private function EvaluateState()
    {
        $mapping = json_decode($this->ReadPropertyString("GroupMapping"), true);
        $activeGroups = json_decode($this->ReadAttributeString("ActiveAlarms"), true);
        $presenceMap = json_decode($this->ReadAttributeString("PresenceMap"), true);

        $bits = 0;

        // Bit 0: Front Door Lock, Bit 1: Front Door Contact, Bit 2: Basement Door
        foreach ($mapping as $item) {
            if (in_array($item['GroupName'], $activeGroups)) {
                switch ($item['LogicalRole']) {
                    case 'bit0':
                        $bits |= 1;
                        break;
                    case 'bit1':
                        $bits |= 2;
                        break;
                    case 'bit2':
                        $bits |= 4;
                        break;
                }
            }
        }

        // Bit 3: Presence (True if any bedroom SwitchState is true)
        foreach ($presenceMap as $room) {
            if (isset($room['SwitchState']) && $room['SwitchState']) {
                $bits |= 8;
                break;
            }
        }

        // Bit 4: Delay Timer (True if IP-Symcon timer is active)
        if ($this->GetTimerInterval("DelayTimer") > 0) {
            $bits |= 16;
        }

        // Bit 5: Alarm System (True if currently Armed in any way)
        if ($this->GetValue("SystemState") > 0) {
            $bits |= 32;
        }

        // The 64-Rule Matrix (Mapped to State IDs 0-6, -1 for Illogical)
        $matrix = [
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

        $newState = $matrix[$bits];

        if ($newState !== -1) {
            if ($this->GetValue("SystemState") !== $newState) {
                $this->SetValue("SystemState", $newState);
                $this->LogMessage("System State transitioned to: " . $newState . " (Bitmask: $bits)", KL_MESSAGE);
            }
        } else {
            $this->LogMessage("EvaluateState: Illogical condition detected (Bitmask: $bits). No state change.", KL_MESSAGE);
        }
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . "/form.json"), true);

        $sensorGroupId = $this->ReadPropertyInteger("SensorGroupInstanceID");
        $options = [];

        if ($sensorGroupId > 0 && IPS_InstanceExists($sensorGroupId)) {
            // Fetch ALL configuration properties from Module 1
            $rawConfig = IPS_GetConfiguration($sensorGroupId);
            $settings = json_decode($rawConfig, true);

            // We search for the list of groups. 
            // It's likely named 'Groups', 'SensorList', 'List', or 'VariableList'.
            $foundList = [];
            foreach (['Groups', 'SensorList', 'List', 'VariableList'] as $key) {
                if (isset($settings[$key]) && is_array($settings[$key])) {
                    $foundList = $settings[$key];
                    break;
                }
            }

            if (!empty($foundList)) {
                foreach ($foundList as $item) {
                    // We look for a 'Name' or 'Caption' field in the list
                    $name = $item['Name'] ?? $item['Caption'] ?? $item['GroupName'] ?? 'Unknown Item';
                    $options[] = ["caption" => $name, "value" => $name];
                }
            } else {
                // Fallback if we can't find the list automatically
                $options[] = ["caption" => "No Groups found in Module 1", "value" => ""];
            }
        }

        // Inject the found options into the "Source Key" column (column index 0)
        if (isset($form['elements'][2]['columns'][0])) {
            $form['elements'][2]['columns'][0]['edit']['options'] = $options;
        }

        return json_encode($form);
    }


    /**
     * Updated Metadata: Now includes both Basement Lock and Contact.
     * Total: 7 Inputs = 128 possible logic states.
     */
    function getInputsMetadata(): array
    {
        return [
            ['name' => 'Front Door Lock',    'trueText' => 'Locked',   'falseText' => 'Unlocked'],
            ['name' => 'Front Door Contact', 'trueText' => 'Closed',   'falseText' => 'Open'],
            ['name' => 'Basement Door Lock', 'trueText' => 'Locked',   'falseText' => 'Unlocked'],
            ['name' => 'Basement Door Contact', 'trueText' => 'Closed', 'falseText' => 'Open'],
            ['name' => 'Presence',           'trueText' => 'Somebody Home', 'falseText' => 'Nobody Home'],
            ['name' => 'Delay Timer',        'trueText' => 'Active',   'falseText' => 'Inactive'],
            ['name' => 'Alarm System',       'trueText' => 'Armed',    'falseText' => 'Disarmed']
        ];
    }

    function getCurrentInputState(array $variableIds): array
    {
        // Important: The sequence here must perfectly match getInputsMetadata
        return [
            !GetValueBoolean($variableIds['Front Door Lock']),    // Inverted logic if 0=Locked
            !GetValueBoolean($variableIds['Front Door Contact']),
            !GetValueBoolean($variableIds['Basement Door Lock']),
            !GetValueBoolean($variableIds['Basement Door Contact']),
            GetValueBoolean($variableIds['Presence']),
            GetValueBoolean($variableIds['Delay Timer']),
            !GetValueBoolean($variableIds['Alarm System']),
        ];
    }
    public function ApplyChanges()
    {
        // Never delete this line!
        parent::ApplyChanges();
    }


    public function SyncFromSensorGroup()
    {
        $sensorGroupID = $this->ReadPropertyInteger("SensorGroupInstanceID");

        // Option A: Log error and stop if ID is invalid
        if ($sensorGroupID == 0 || !@IPS_InstanceExists($sensorGroupID)) {
            $this->LogMessage("Sync failed: No valid SensorGroup Instance ID configured.", KL_MESSAGE);
            return;
        }

        // Request configuration from Module 1
        $configJSON = @MYALARM_GetConfiguration($sensorGroupID);

        if ($configJSON === false) {
            $this->LogMessage("Sync failed: Function MYALARM_GetConfiguration not found or returned an error for Instance " . $sensorGroupID, KL_MESSAGE);
            return;
        }

        // Store the result in the RAM Buffer (Attribute)
        $this->WriteAttributeString("RuleConfiguration", $configJSON);

        $this->LogMessage("Successfully synced configuration from SensorGroup " . $sensorGroupID, KL_MESSAGE);
    }
}
