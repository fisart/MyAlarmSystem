<?php

declare(strict_types=1);

class MYALARM_SensorGroup extends IPSModule
{
    public function Create()
    {
        // Never delete this line!
        parent::Create();

        // 1. Register Configuration Properties
        // "AlarmClassList" allows dynamic definition of tags (JSON array)
        $this->RegisterPropertyString('AlarmClassList', '[]');

        // Logic Settings
        $this->RegisterPropertyInteger('LogicMode', 0); // 0=OR, 1=AND, 2=COUNT
        $this->RegisterPropertyInteger('TriggerThreshold', 1);
        $this->RegisterPropertyInteger('TimeWindow', 10);

        // Sensor Lists
        $this->RegisterPropertyString('SensorList', '[]'); // The main rule table
        $this->RegisterPropertyString('TamperList', '[]'); // The sabotage table

        // Modes
        $this->RegisterPropertyBoolean('MaintenanceMode', false);

        // 2. Internal Memory (Attributes)
        // Used to store timestamps for the COUNT logic
        $this->RegisterAttributeString('EventBuffer', '[]');

        // 3. Register Output Variables

        // Status: Visual indicator (Red/Green)
        $this->RegisterVariableBoolean('Status', 'Status', '~Alert', 10);

        // Sabotage: Visual indicator for tamper
        $this->RegisterVariableBoolean('Sabotage', 'Sabotage', '~Alert', 20);

        // EventData: The "Enriched" JSON payload for the Controller (Hidden)
        $this->RegisterVariableString('EventData', 'Event Payload', '', 30);
        IPS_SetHidden($this->GetIDForIdent('EventData'), true);
    }

    public function ApplyChanges()
    {
        // Never delete this line!
        parent::ApplyChanges();

        // 1. Unregister old messages
        // (Implementation needed in next step)

        // 2. Register new messages based on SensorList and TamperList
        // (Implementation needed in next step)

        // 3. Reset Status to False on apply
    }

    // The Logic Engine
    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        // This function will handle the incoming trigger signals
        $this->SendDebug('MessageSink', "Sender: $SenderID, Message: $Message", 0);

        // We will call the logic verification function here
        // $this->CheckLogic();
    }

    // Helper function (Skeleton)
    private function CheckLogic()
    {
        // 1. Loop through all sensors in SensorList
        // 2. Apply Operators (=, >, <)
        // 3. Check AND/OR/COUNT logic
        // 4. Update Status and EventData
    }
}
