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

    private const SEVERITY_LEVELS = [
        'Low',
        'Medium',
        'High',
        'Critical'
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

        // Rule editor helper properties
        $this->RegisterPropertyString('EditorRuleID', '');
        $this->RegisterPropertyBoolean('EditorEnabled', false);
        $this->RegisterPropertyString('EditorGroupIDs', '[]');
        $this->RegisterPropertyString('EditorHouseState', '0');
        $this->RegisterPropertyString('EditorSeverity', 'Medium');
        $this->RegisterPropertyBoolean('EditorBypassThrottle', false);
        $this->RegisterPropertyString('EditorOutputResourceIDs', '[]');
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

        $normalizedGroupRows = $this->NormalizeGroupRows($groupRows);
        if (json_encode($normalizedGroupRows) !== json_encode($groupRows)) {
            IPS_SetProperty($this->InstanceID, 'GroupStateMappings', json_encode($normalizedGroupRows));
            IPS_ApplyChanges($this->InstanceID);
            return;
        }

        $this->NormalizeEditorProperties($normalizedGroupRows, $normalizedOutputRows);

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

        $outputRows = json_decode($this->ReadPropertyString('OutputResources'), true);
        if (!is_array($outputRows)) {
            $outputRows = [];
        }
        $outputRows = $this->NormalizeOutputRows($outputRows);

        $groupRows = json_decode($this->ReadPropertyString('GroupStateMappings'), true);
        if (!is_array($groupRows)) {
            $groupRows = [];
        }
        $groupRows = $this->NormalizeGroupRows($groupRows);

        $groupOptions = $this->BuildGroupOptionsFromImportedConfig();
        $outputResourceOptions = $this->BuildOutputResourceOptions($outputRows);

        $stateOptions = [];
        foreach (self::HOUSE_STATES as $id => $label) {
            $stateOptions[] = [
                'caption' => $label,
                'value'   => (string) $id
            ];
        }

        $severityOptions = [];
        foreach (self::SEVERITY_LEVELS as $level) {
            $severityOptions[] = [
                'caption' => $level,
                'value'   => $level
            ];
        }

        $outputTypeOptions = [];
        foreach (self::OUTPUT_TYPES as $type) {
            $outputTypeOptions[] = [
                'caption' => $type,
                'value'   => $type
            ];
        }

        $groupNameMap = $this->BuildGroupNameMap();
        $outputNameMap = $this->BuildOutputNameMap($outputRows);

        foreach ($groupRows as &$row) {
            $row['GroupsSummary'] = $this->BuildSummaryFromIDs($row['GroupIDs'], $groupNameMap);
            $row['OutputsSummary'] = $this->BuildSummaryFromIDs($row['OutputResourceIDs'], $outputNameMap);
        }
        unset($row);

        foreach ($form['elements'] as &$element) {
            if (($element['type'] ?? '') === 'List' && ($element['name'] ?? '') === 'GroupStateMappings') {
                $element['values'] = $groupRows;

                foreach ($element['columns'] as &$column) {
                    if (($column['name'] ?? '') === 'HouseState') {
                        $column['edit']['options'] = $stateOptions;
                    }
                    if (($column['name'] ?? '') === 'Severity') {
                        $column['edit']['options'] = $severityOptions;
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

            if (($element['type'] ?? '') === 'Select' && ($element['name'] ?? '') === 'EditorHouseState') {
                $element['options'] = $stateOptions;
            }

            if (($element['type'] ?? '') === 'Select' && ($element['name'] ?? '') === 'EditorSeverity') {
                $element['options'] = $severityOptions;
            }

            if (($element['type'] ?? '') === 'Select' && ($element['name'] ?? '') === 'EditorGroupIDs') {
                $element['options'] = $groupOptions;
            }

            if (($element['type'] ?? '') === 'Select' && ($element['name'] ?? '') === 'EditorOutputResourceIDs') {
                $element['options'] = $outputResourceOptions;
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

            $groupID = $this->MakeGroupID($groupName);
            $result[$groupID] = [
                'GroupID'   => $groupID,
                'GroupName' => $groupName
            ];
        }

        uasort($result, static function (array $a, array $b): int {
            return strnatcasecmp($a['GroupName'], $b['GroupName']);
        });

        return array_values($result);
    }

    private function BuildRowsFromImportedGroups(array $groups): array
    {
        $rows = [];

        foreach ($groups as $group) {
            $groupID = (string) ($group['GroupID'] ?? '');
            foreach (self::HOUSE_STATES as $stateId => $stateLabel) {
                $rows[] = [
                    'RuleID'             => $this->GenerateStableID('RULE_'),
                    'Enabled'            => false,
                    'GroupIDs'           => $groupID === '' ? [] : [$groupID],
                    'HouseState'         => (string) $stateId,
                    'Severity'           => 'Medium',
                    'BypassThrottle'     => false,
                    'OutputResourceIDs'  => [],
                    'GroupsSummary'      => '',
                    'OutputsSummary'     => ''
                ];
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

            $severity = (string) ($row['Severity'] ?? 'Medium');
            if (!in_array($severity, self::SEVERITY_LEVELS, true)) {
                $severity = 'Medium';
            }

            $groupIDs = $this->NormalizeStringArray($row['GroupIDs'] ?? []);
            $outputResourceIDs = $this->NormalizeStringArray($row['OutputResourceIDs'] ?? []);

            $normalized[] = [
                'RuleID'            => $this->NormalizeID((string) ($row['RuleID'] ?? ''), 'RULE_'),
                'Enabled'           => (bool) ($row['Enabled'] ?? false),
                'GroupIDs'          => $groupIDs,
                'HouseState'        => (string) ($row['HouseState'] ?? '0'),
                'Severity'          => $severity,
                'BypassThrottle'    => (bool) ($row['BypassThrottle'] ?? false),
                'OutputResourceIDs' => $outputResourceIDs,
                'GroupsSummary'     => (string) ($row['GroupsSummary'] ?? ''),
                'OutputsSummary'    => (string) ($row['OutputsSummary'] ?? '')
            ];
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
                'ResourceID'         => $this->NormalizeID((string) ($row['ResourceID'] ?? ''), 'OUT_'),
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

    private function NormalizeEditorProperties(array $groupRows, array $outputRows): void
    {
        $editorRuleID = $this->NormalizeID($this->ReadPropertyString('EditorRuleID'), '');
        $editorGroupIDs = $this->NormalizeStringArray(json_decode($this->ReadPropertyString('EditorGroupIDs'), true) ?? []);
        $editorOutputIDs = $this->NormalizeStringArray(json_decode($this->ReadPropertyString('EditorOutputResourceIDs'), true) ?? []);

        $validGroupIDs = array_keys($this->BuildGroupNameMap());
        $validOutputIDs = array_keys($this->BuildOutputNameMap($outputRows));

        $editorGroupIDs = array_values(array_intersect($editorGroupIDs, $validGroupIDs));
        $editorOutputIDs = array_values(array_intersect($editorOutputIDs, $validOutputIDs));

        IPS_SetProperty($this->InstanceID, 'EditorRuleID', $editorRuleID);
        IPS_SetProperty($this->InstanceID, 'EditorGroupIDs', json_encode($editorGroupIDs));
        IPS_SetProperty($this->InstanceID, 'EditorOutputResourceIDs', json_encode($editorOutputIDs));
    }

    private function BuildGroupOptionsFromImportedConfig(): array
    {
        $json = $this->ReadPropertyString('ImportedModule1ConfigJson');
        $config = json_decode($json, true);
        if (!is_array($config)) {
            return [];
        }

        $groups = $this->ExtractGroupsForTargetInstance($config, $this->InstanceID);
        $options = [];

        foreach ($groups as $group) {
            $options[] = [
                'caption' => (string) ($group['GroupName'] ?? ''),
                'value'   => (string) ($group['GroupID'] ?? '')
            ];
        }

        return $options;
    }

    private function BuildGroupNameMap(): array
    {
        $map = [];
        foreach ($this->BuildGroupOptionsFromImportedConfig() as $option) {
            $map[(string) $option['value']] = (string) $option['caption'];
        }
        return $map;
    }

    private function BuildOutputResourceOptions(array $outputRows): array
    {
        $options = [];

        foreach ($outputRows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $resourceID = (string) ($row['ResourceID'] ?? '');
            $outputName = trim((string) ($row['OutputName'] ?? ''));
            if ($resourceID === '' || $outputName === '') {
                continue;
            }

            $options[] = [
                'caption' => $outputName,
                'value'   => $resourceID
            ];
        }

        usort($options, static function (array $a, array $b): int {
            return strnatcasecmp((string) $a['caption'], (string) $b['caption']);
        });

        return $options;
    }

    private function BuildOutputNameMap(array $outputRows): array
    {
        $map = [];
        foreach ($outputRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $resourceID = (string) ($row['ResourceID'] ?? '');
            $outputName = trim((string) ($row['OutputName'] ?? ''));
            if ($resourceID !== '' && $outputName !== '') {
                $map[$resourceID] = $outputName;
            }
        }
        return $map;
    }

    private function BuildSummaryFromIDs(array $ids, array $nameMap): string
    {
        $parts = [];
        foreach ($ids as $id) {
            $parts[] = $nameMap[$id] ?? $id;
        }
        return implode(', ', $parts);
    }

    private function NormalizeStringArray($value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $result = [];
        $seen = [];

        foreach ($value as $item) {
            $item = trim((string) $item);
            if ($item === '') {
                continue;
            }
            if (isset($seen[$item])) {
                continue;
            }
            $seen[$item] = true;
            $result[] = $item;
        }

        return $result;
    }

    private function NormalizeID(string $id, string $prefix): string
    {
        $id = trim($id);
        if ($id !== '') {
            return $id;
        }
        return $prefix === '' ? '' : $this->GenerateStableID($prefix);
    }

    private function GenerateStableID(string $prefix): string
    {
        return $prefix . bin2hex(random_bytes(6));
    }

    private function MakeGroupID(string $groupName): string
    {
        return 'GRP_' . md5(mb_strtolower(trim($groupName)));
    }

    private function IsAllowedTargetObject(int $objectID): bool
    {
        if ($objectID <= 0 || !@IPS_ObjectExists($objectID)) {
            return false;
        }

        $object = IPS_GetObject($objectID);
        $type = (int) ($object['ObjectType'] ?? -1);

        return in_array($type, [1, 3], true);
    }
}
