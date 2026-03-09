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

    private const OUTPUT_COLUMNS = [
        'Bell',
        'Siren',
        'Email',
        'SMS',
        'Notif',
        'Voice',
        'VOIP',
        'Screen',
        'Script',
        'ExtSvc',
        'RemoteVoice',
        'RemoteBell',
        'OP1',
        'OP2',
        'OP3'
    ];

    private const OUTPUT_TYPES = [
        'Bell',
        'Siren',
        'Email',
        'SMS',
        'Notification',
        'Voice',
        'VOIP',
        'Screen',
        'Script',
        'External Service',
        'Remote Voice',
        'Remote Alarm Bell',
        'OP1',
        'OP2',
        'OP3'
    ];

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyInteger('Module1InstanceID', 0);
        $this->RegisterPropertyInteger('Module2InstanceID', 0);
        $this->RegisterPropertyInteger('VaultInstanceID', 0);
        $this->RegisterPropertyString('ImportedModule1ConfigJson', '');
        $this->RegisterPropertyString('GroupStateMappings', '[]');
        $this->RegisterPropertyString('OutputResources', '[]');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $groupRows = json_decode($this->ReadPropertyString('GroupStateMappings'), true);
        if (!is_array($groupRows)) {
            IPS_SetProperty($this->InstanceID, 'GroupStateMappings', '[]');
            IPS_ApplyChanges($this->InstanceID);
            return;
        }

        $outputRows = json_decode($this->ReadPropertyString('OutputResources'), true);
        if (!is_array($outputRows)) {
            IPS_SetProperty($this->InstanceID, 'OutputResources', '[]');
            IPS_ApplyChanges($this->InstanceID);
            return;
        }

        $normalizedOutputRows = $this->NormalizeOutputRows($outputRows);
        if (json_encode($normalizedOutputRows) !== json_encode($outputRows)) {
            IPS_SetProperty($this->InstanceID, 'OutputResources', json_encode($normalizedOutputRows));
            IPS_ApplyChanges($this->InstanceID);
            return;
        }

        if ($this->GetStatus() === 100 || $this->GetStatus() === 101) {
            $this->SetStatus(102);
        }
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'ReadModule1Configuration':
                $this->ReadModule1Configuration();
                break;

            case 'BuildRowsFromMyRouting':
                $this->BuildRowsFromMyRouting();
                break;

            default:
                throw new Exception('Invalid Ident');
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

        $groupRows = json_decode($this->ReadPropertyString('GroupStateMappings'), true);
        if (!is_array($groupRows)) {
            $groupRows = [];
        }
        $groupRows = $this->NormalizeGroupRows($groupRows);

        $outputRows = json_decode($this->ReadPropertyString('OutputResources'), true);
        if (!is_array($outputRows)) {
            $outputRows = [];
        }
        $outputRows = $this->NormalizeOutputRows($outputRows);

        $stateOptions = [];
        foreach (self::HOUSE_STATES as $id => $label) {
            $stateOptions[] = [
                'caption' => $label,
                'value'   => (string) $id
            ];
        }

        $outputTypeOptions = [];
        foreach (self::OUTPUT_TYPES as $type) {
            $outputTypeOptions[] = [
                'caption' => $type,
                'value'   => $type
            ];
        }

        foreach ($form['elements'] as &$element) {
            if (($element['type'] ?? '') === 'List' && ($element['name'] ?? '') === 'GroupStateMappings') {
                $element['values'] = $groupRows;

                foreach ($element['columns'] as &$column) {
                    if (($column['name'] ?? '') === 'HouseState') {
                        $column['edit']['options'] = $stateOptions;
                    }
                }
                unset($column);
            }

            if (($element['type'] ?? '') === 'List' && ($element['name'] ?? '') === 'OutputResources') {
                $element['values'] = $outputRows;

                foreach ($element['columns'] as &$column) {
                    if (($column['name'] ?? '') === 'OutputType') {
                        $column['edit']['options'] = $outputTypeOptions;
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
                $row = [
                    'Enabled'    => false,
                    'GroupName'  => $groupName,
                    'HouseState' => (string) $stateId
                ];

                foreach (self::OUTPUT_COLUMNS as $columnName) {
                    $row[$columnName] = false;
                }

                $rows[] = $row;
            }
        }

        return $rows;
    }

    private function NormalizeGroupRows(array $rows): array
    {
        $normalized = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $newRow = [
                'Enabled'    => (bool) ($row['Enabled'] ?? false),
                'GroupName'  => (string) ($row['GroupName'] ?? ''),
                'HouseState' => (string) ($row['HouseState'] ?? '0')
            ];

            foreach (self::OUTPUT_COLUMNS as $columnName) {
                $newRow[$columnName] = (bool) ($row[$columnName] ?? false);
            }

            $normalized[] = $newRow;
        }

        return $normalized;
    }

    private function NormalizeOutputRows(array $rows): array
    {
        $normalized = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $targetObjectID = (int) ($row['TargetObjectID'] ?? 0);
            if (!$this->IsAllowedTargetObject($targetObjectID)) {
                $targetObjectID = 0;
            }

            $outputType = (string) ($row['OutputType'] ?? 'Email');
            if (!in_array($outputType, self::OUTPUT_TYPES, true)) {
                $outputType = 'Email';
            }

            $normalized[] = [
                'Enabled'            => (bool) ($row['Enabled'] ?? true),
                'OutputName'         => (string) ($row['OutputName'] ?? ''),
                'OutputType'         => $outputType,
                'TargetObjectID'     => $targetObjectID,
                'MaxMessages'        => max(1, (int) ($row['MaxMessages'] ?? 1)),
                'PerSeconds'         => max(1, (int) ($row['PerSeconds'] ?? 60)),
                'PrefixText'         => (string) ($row['PrefixText'] ?? ''),
                'UseSensorName'      => (bool) ($row['UseSensorName'] ?? true),
                'UseParentName'      => (bool) ($row['UseParentName'] ?? false),
                'UseGrandparentName' => (bool) ($row['UseGrandparentName'] ?? false),
                'SuffixText'         => (string) ($row['SuffixText'] ?? ''),
                'EmailAddress'       => (string) ($row['EmailAddress'] ?? ''),
                'PhoneNumber'        => (string) ($row['PhoneNumber'] ?? ''),
                'Volume'             => (string) ($row['Volume'] ?? '')
            ];
        }

        return $normalized;
    }

    private function IsAllowedTargetObject(int $objectID): bool
    {
        if ($objectID <= 0 || !@IPS_ObjectExists($objectID)) {
            return false;
        }

        $object = IPS_GetObject($objectID);
        $type = (int) ($object['ObjectType'] ?? -1);

        // 1 = Instance, 3 = Script
        return in_array($type, [1, 3], true);
    }
}
