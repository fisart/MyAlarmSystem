<?php

declare(strict_types=1);

class ARMResponseManagerMock extends IPSModule
{
    private const HOUSE_STATES = [
        0 => 'Disarmed',
        2 => 'Exit Delay',
        3 => 'Armed External',
        6 => 'Armed Internal',
        9 => 'Alarm'
    ];

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyInteger('Module1InstanceID', 0);
        $this->RegisterPropertyInteger('Module2InstanceID', 0);
        $this->RegisterPropertyInteger('VaultInstanceID', 0);
        $this->RegisterPropertyString('ImportedModule1ConfigJson', '');
        $this->RegisterPropertyString('GroupStateMappings', '[]');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $rows = json_decode($this->ReadPropertyString('GroupStateMappings'), true);
        if (!is_array($rows)) {
            IPS_SetProperty($this->InstanceID, 'GroupStateMappings', '[]');
            IPS_ApplyChanges($this->InstanceID);
            return;
        }

        if ($this->GetStatus() === 100 || $this->GetStatus() === 101) {
            $this->SetStatus(102);
        }
    }

    public function GetConfigurationForm()
    {
        $formPath = __DIR__ . '/form.json';
        $form = json_decode((string) file_get_contents($formPath), true);

        if (!is_array($form)) {
            return json_encode([
                'elements' => [
                    [
                        'type'    => 'Label',
                        'caption' => 'Error loading form.json'
                    ]
                ]
            ]);
        }

        $rows = json_decode($this->ReadPropertyString('GroupStateMappings'), true);
        if (!is_array($rows)) {
            $rows = [];
        }

        $stateOptions = [];
        foreach (self::HOUSE_STATES as $id => $label) {
            $stateOptions[] = [
                'caption' => $label,
                'value'   => (string) $id
            ];
        }

        foreach ($form['elements'] as &$element) {
            if (($element['type'] ?? '') === 'List' && ($element['name'] ?? '') === 'GroupStateMappings') {
                $element['values'] = $rows;

                foreach ($element['columns'] as &$column) {
                    if (($column['name'] ?? '') === 'HouseState') {
                        $column['edit']['options'] = $stateOptions;
                    }
                }
                unset($column);
            }
        }
        unset($element);

        return json_encode($form);
    }

    public function ReadModule1Configuration(): void
    {
        $module1ID = $this->ReadPropertyInteger('Module1InstanceID');
        if ($module1ID <= 0 || !@IPS_InstanceExists($module1ID)) {
            $this->SetStatus(200);
            throw new Exception('Module 1 instance is not selected or does not exist.');
        }

        if (!function_exists('MYALARM_GetConfiguration')) {
            $this->SetStatus(200);
            throw new Exception('Function MYALARM_GetConfiguration() is not available.');
        }

        $json = MYALARM_GetConfiguration($module1ID);
        if (!is_string($json) || trim($json) === '') {
            $this->SetStatus(200);
            throw new Exception('Module 1 returned an empty configuration payload.');
        }

        $config = json_decode($json, true);
        if (!is_array($config)) {
            $this->SetStatus(200);
            throw new Exception('Module 1 returned invalid JSON.');
        }

        IPS_SetProperty($this->InstanceID, 'ImportedModule1ConfigJson', $json);
        IPS_ApplyChanges($this->InstanceID);

        $this->SetStatus(202);
    }

    public function BuildRowsFromMyRouting(): void
    {
        $json = $this->ReadPropertyString('ImportedModule1ConfigJson');
        if (trim($json) === '') {
            $this->SetStatus(200);
            throw new Exception('No imported Module 1 configuration is available. Read Module 1 configuration first.');
        }

        $config = json_decode($json, true);
        if (!is_array($config)) {
            $this->SetStatus(200);
            throw new Exception('Imported Module 1 configuration is invalid JSON.');
        }

        $myInstanceID = $this->InstanceID;
        $groups = $this->ExtractGroupsForTargetInstance($config, $myInstanceID);

        if (count($groups) === 0) {
            $this->SetStatus(201);
            throw new Exception('No GroupDispatch entry in Module 1 routes to this Module 3 instance ID (' . $myInstanceID . ').');
        }

        $rows = $this->BuildRowsFromImportedGroups($groups);

        IPS_SetProperty($this->InstanceID, 'GroupStateMappings', json_encode($rows));
        IPS_ApplyChanges($this->InstanceID);

        $this->SetStatus(203);
    }

    private function ExtractGroupsForTargetInstance(array $config, int $targetInstanceID): array
    {
        $result = [];
        $dispatchList = $config['GroupDispatch'] ?? [];

        if (!is_array($dispatchList)) {
            return [];
        }

        foreach ($dispatchList as $dispatchRow) {
            if (!is_array($dispatchRow)) {
                continue;
            }

            $instanceID = (int) ($dispatchRow['InstanceID'] ?? 0);
            if ($instanceID !== $targetInstanceID) {
                continue;
            }

            $groupName = trim((string) ($dispatchRow['GroupName'] ?? ''));
            if ($groupName === '') {
                continue;
            }

            $result[] = $groupName;
        }

        $result = array_values(array_unique($result));
        sort($result, SORT_NATURAL | SORT_FLAG_CASE);

        return $result;
    }

    private function BuildRowsFromImportedGroups(array $groups): array
    {
        $rows = [];

        foreach ($groups as $groupName) {
            foreach (self::HOUSE_STATES as $stateId => $stateLabel) {
                $rows[] = [
                    'Enabled'         => false,
                    'GroupName'       => $groupName,
                    'HouseState'      => (string) $stateId,
                    'AssignedOutputs' => '',
                    'MinSeconds'      => 60
                ];
            }
        }

        return $rows;
    }
}

if (!function_exists('ARMM_ReadModule1Configuration')) {
    function ARMM_ReadModule1Configuration(int $InstanceID): void
    {
        if (!IPS_InstanceExists($InstanceID)) {
            throw new Exception('Instance does not exist.');
        }

        $instance = IPS_GetInstance($InstanceID);
        if (($instance['ModuleInfo']['ModuleID'] ?? '') !== '{A6C3F4B1-7E8D-4E66-8D39-11F2D6E21001}') {
            throw new Exception('Instance is not ARMResponseManagerMock.');
        }

        $script = '
            $instanceID = ' . $InstanceID . ';
            $instance = IPS_GetInstance($instanceID);
            if (($instance["ModuleInfo"]["ModuleID"] ?? "") !== "{A6C3F4B1-7E8D-4E66-8D39-11F2D6E21001}") {
                throw new Exception("Instance is not ARMResponseManagerMock.");
            }
            $module = IPSModule::getInstance($instanceID);
            if (!($module instanceof ARMResponseManagerMock)) {
                throw new Exception("Unable to access module instance.");
            }
            $module->ReadModule1Configuration();
        ';
        eval($script);
    }
}

if (!function_exists('ARMM_BuildRowsFromMyRouting')) {
    function ARMM_BuildRowsFromMyRouting(int $InstanceID): void
    {
        if (!IPS_InstanceExists($InstanceID)) {
            throw new Exception('Instance does not exist.');
        }

        $instance = IPS_GetInstance($InstanceID);
        if (($instance['ModuleInfo']['ModuleID'] ?? '') !== '{A6C3F4B1-7E8D-4E66-8D39-11F2D6E21001}') {
            throw new Exception('Instance is not ARMResponseManagerMock.');
        }

        $script = '
            $instanceID = ' . $InstanceID . ';
            $instance = IPS_GetInstance($instanceID);
            if (($instance["ModuleInfo"]["ModuleID"] ?? "") !== "{A6C3F4B1-7E8D-4E66-8D39-11F2D6E21001}") {
                throw new Exception("Instance is not ARMResponseManagerMock.");
            }
            $module = IPSModule::getInstance($instanceID);
            if (!($module instanceof ARMResponseManagerMock)) {
                throw new Exception("Unable to access module instance.");
            }
            $module->BuildRowsFromMyRouting();
        ';
        eval($script);
    }
}
