<?php

declare(strict_types=1);

class ARMResponseManagerMock extends IPSModule
{
    private const HOUSE_STATES = [
        ['caption' => '',                'value' => ''],
        ['caption' => 'Disarmed',        'value' => '0'],
        ['caption' => 'Exit Delay',      'value' => '2'],
        ['caption' => 'Armed External',  'value' => '3'],
        ['caption' => 'Armed Internal',  'value' => '6'],
        ['caption' => 'Alarm',           'value' => '9']
    ];

    private const SEVERITY_LEVELS = [
        ['caption' => '',         'value' => ''],
        ['caption' => 'Low',      'value' => 'Low'],
        ['caption' => 'Medium',   'value' => 'Medium'],
        ['caption' => 'High',     'value' => 'High'],
        ['caption' => 'Critical', 'value' => 'Critical']
    ];

    private const OUTPUT_TYPE_CATALOG = [
        ['TypeID' => 'bell',         'TypeName' => 'Bell'],
        ['TypeID' => 'siren',        'TypeName' => 'Siren'],
        ['TypeID' => 'email',        'TypeName' => 'Email'],
        ['TypeID' => 'sms',          'TypeName' => 'SMS'],
        ['TypeID' => 'notification', 'TypeName' => 'Notification'],
        ['TypeID' => 'voice',        'TypeName' => 'Voice'],
        ['TypeID' => 'voip',         'TypeName' => 'VOIP'],
        ['TypeID' => 'screen',       'TypeName' => 'Screen'],
        ['TypeID' => 'script',       'TypeName' => 'Script'],
        ['TypeID' => 'external',     'TypeName' => 'External Service'],
        ['TypeID' => 'remote_voice', 'TypeName' => 'Remote Voice'],
        ['TypeID' => 'remote_bell',  'TypeName' => 'Remote Alarm Bell'],
        ['TypeID' => 'op1',          'TypeName' => 'OP1'],
        ['TypeID' => 'op2',          'TypeName' => 'OP2'],
        ['TypeID' => 'op3',          'TypeName' => 'OP3']
    ];

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyInteger('Module1InstanceID', 0);
        $this->RegisterPropertyInteger('Module2InstanceID', 0);
        $this->RegisterPropertyInteger('VaultInstanceID', 0);
        $this->RegisterPropertyString('ImportedModule1ConfigJson', '');

        $this->RegisterPropertyString('OutputResources', '[]');
        $this->RegisterPropertyString('GroupStateRules', '[]');
        $this->RegisterPropertyString('RuleOutputAssignments', '[]');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->SetStatus(102);
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

        $importedGroups = $this->ExtractImportedGroupsFromConfig();
        $outputTypes = $this->GetBuiltInOutputTypes();

        $outputResources = $this->readListProperty('OutputResources');
        $groupStateRules = $this->readListProperty('GroupStateRules');
        $ruleOutputAssignments = $this->readListProperty('RuleOutputAssignments');

        $groupOptions = $this->buildGroupOptions($importedGroups, $groupStateRules);
        $groupLabels = $this->buildGroupLabels($importedGroups, $groupStateRules);

        $typeOptions = $this->buildTypeOptions($outputTypes, $outputResources);
        $typeLabels = $this->buildTypeLabels($outputTypes, $outputResources);

        $ruleOptions = $this->buildRuleOptions($groupStateRules, $groupLabels);
        $ruleLabels = $this->buildRuleLabels($groupStateRules, $groupLabels);

        $outputOptions = $this->buildOutputOptions($outputResources, $typeLabels);
        $outputLabels = $this->buildOutputLabels($outputResources);
        $outputTypeLabels = $this->buildOutputTypeLabels($outputResources, $typeLabels);

        $this->setListColumnOptions($form, 'OutputResources', 'TypeID', $typeOptions);
        $this->setListColumnOptions($form, 'GroupStateRules', 'GroupKey', $groupOptions);
        $this->setListColumnOptions($form, 'GroupStateRules', 'HouseState', self::HOUSE_STATES);
        $this->setListColumnOptions($form, 'GroupStateRules', 'Severity', self::SEVERITY_LEVELS);
        $this->setListColumnOptions($form, 'RuleOutputAssignments', 'RuleID', $ruleOptions);
        $this->setListColumnOptions($form, 'RuleOutputAssignments', 'OutputID', $outputOptions);

        $resourceValues = [];
        foreach ($outputResources as $row) {
            $outputID = trim((string) ($row['OutputID'] ?? ''));
            if ($outputID === '') {
                $outputID = $this->GenerateTechnicalID('out_');
            }

            $typeID = trim((string) ($row['TypeID'] ?? ''));
            $resourceValues[] = [
                'Active'            => (bool) ($row['Active'] ?? true),
                'OutputID'          => $outputID,
                'Name'              => (string) ($row['Name'] ?? ''),
                'TypeID'            => $typeID,
                'TargetObjectID'    => (int) ($row['TargetObjectID'] ?? 0),
                'MaxMessages'       => (int) ($row['MaxMessages'] ?? 1),
                'PerSeconds'        => (int) ($row['PerSeconds'] ?? 60),
                'PrefixText'        => (string) ($row['PrefixText'] ?? ''),
                'UseSensorName'     => (bool) ($row['UseSensorName'] ?? true),
                'UseParentName'     => (bool) ($row['UseParentName'] ?? false),
                'UseGrandparentName' => (bool) ($row['UseGrandparentName'] ?? false),
                'SuffixText'        => (string) ($row['SuffixText'] ?? ''),
                'EmailAddress'      => (string) ($row['EmailAddress'] ?? ''),
                'PhoneNumber'       => (string) ($row['PhoneNumber'] ?? ''),
                'Volume'            => (string) ($row['Volume'] ?? ''),
                'TypeLabel'         => $typeLabels[$typeID] ?? '[missing type]',
                'RowSummary'        => $this->buildOutputSummary($row, $typeLabels)
            ];
        }
        $this->setListValues($form, 'OutputResources', $resourceValues);

        $ruleValues = [];
        foreach ($groupStateRules as $row) {
            $ruleID = trim((string) ($row['RuleID'] ?? ''));
            if ($ruleID === '') {
                $ruleID = $this->GenerateTechnicalID('rule_');
            }

            $groupKey = trim((string) ($row['GroupKey'] ?? ''));
            $houseState = trim((string) ($row['HouseState'] ?? ''));

            $ruleValues[] = [
                'Active'      => (bool) ($row['Active'] ?? true),
                'RuleID'      => $ruleID,
                'GroupKey'    => $groupKey,
                'HouseState'  => $houseState,
                'Severity'    => (string) ($row['Severity'] ?? ''),
                'Bypass'      => (bool) ($row['Bypass'] ?? false),
                'GroupLabel'  => $groupLabels[$groupKey] ?? '[missing group]',
                'RuleLabel'   => $this->buildRuleLabel($row, $groupLabels),
                'RowSummary'  => $this->buildRuleSummary($row, $groupLabels)
            ];
        }
        $this->setListValues($form, 'GroupStateRules', $ruleValues);

        $assignmentValues = [];
        foreach ($ruleOutputAssignments as $row) {
            $assignmentID = trim((string) ($row['AssignmentID'] ?? ''));
            if ($assignmentID === '') {
                $assignmentID = $this->GenerateTechnicalID('asg_');
            }

            $ruleID = trim((string) ($row['RuleID'] ?? ''));
            $outputID = trim((string) ($row['OutputID'] ?? ''));

            $assignmentValues[] = [
                'Active'          => (bool) ($row['Active'] ?? true),
                'AssignmentID'    => $assignmentID,
                'RuleID'          => $ruleID,
                'OutputID'        => $outputID,
                'RuleLabel'       => $ruleLabels[$ruleID] ?? '[missing rule]',
                'OutputName'      => $outputLabels[$outputID] ?? '[missing output]',
                'OutputTypeLabel' => $outputTypeLabels[$outputID] ?? '[missing type]',
                'RowSummary'      => $this->buildAssignmentSummary(
                    $ruleLabels[$ruleID] ?? '[missing rule]',
                    $outputLabels[$outputID] ?? '[missing output]',
                    $outputTypeLabels[$outputID] ?? '[missing type]'
                )
            ];
        }
        $this->setListValues($form, 'RuleOutputAssignments', $assignmentValues);

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
        $groups = $this->ExtractImportedGroupsFromConfig();
        if (count($groups) === 0) {
            $this->SetStatus(201);
            throw new Exception('No GroupDispatch entry in Module 1 routes to this Module 3 instance ID (' . $this->InstanceID . ').');
        }

        $existingRules = $this->readListProperty('GroupStateRules');
        $existingMap = [];

        foreach ($existingRules as $row) {
            $groupKey = trim((string) ($row['GroupKey'] ?? ''));
            $houseState = trim((string) ($row['HouseState'] ?? ''));
            if ($groupKey === '' || $houseState === '') {
                continue;
            }
            $existingMap[$groupKey . '|' . $houseState] = $row;
        }

        foreach ($groups as $group) {
            $groupKey = (string) ($group['GroupKey'] ?? '');
            if ($groupKey === '') {
                continue;
            }

            foreach (self::HOUSE_STATES as $stateOption) {
                $houseState = (string) ($stateOption['value'] ?? '');
                if ($houseState === '') {
                    continue;
                }

                $compositeKey = $groupKey . '|' . $houseState;
                if (isset($existingMap[$compositeKey])) {
                    continue;
                }

                $existingRules[] = [
                    'Active'     => false,
                    'RuleID'     => $this->GenerateTechnicalID('rule_'),
                    'GroupKey'   => $groupKey,
                    'HouseState' => $houseState,
                    'Severity'   => 'Medium',
                    'Bypass'     => false
                ];
            }
        }

        IPS_SetProperty($this->InstanceID, 'GroupStateRules', json_encode(array_values($existingRules)));
        IPS_ApplyChanges($this->InstanceID);

        $this->SetStatus(203);
    }

    private function readListProperty(string $propertyName): array
    {
        $raw = $this->ReadPropertyString($propertyName);
        $data = json_decode($raw, true);
        return is_array($data) ? array_values($data) : [];
    }

    private function GetBuiltInOutputTypes(): array
    {
        return self::OUTPUT_TYPE_CATALOG;
    }

    private function ExtractImportedGroupsFromConfig(): array
    {
        $json = $this->ReadPropertyString('ImportedModule1ConfigJson');
        if (trim($json) === '') {
            return [];
        }

        $config = json_decode($json, true);
        if (!is_array($config)) {
            return [];
        }

        $dispatchList = $config['GroupDispatch'] ?? [];
        if (!is_array($dispatchList)) {
            return [];
        }

        $result = [];
        foreach ($dispatchList as $dispatchRow) {
            if (!is_array($dispatchRow)) {
                continue;
            }

            $instanceID = (int) ($dispatchRow['InstanceID'] ?? 0);
            if ($instanceID !== $this->InstanceID) {
                continue;
            }

            $groupLabel = trim((string) ($dispatchRow['GroupName'] ?? ''));
            if ($groupLabel === '') {
                continue;
            }

            $groupKey = $this->MakeGroupKey($groupLabel);
            $result[$groupKey] = [
                'GroupKey'   => $groupKey,
                'GroupLabel' => $groupLabel
            ];
        }

        uasort($result, static function (array $a, array $b): int {
            return strnatcasecmp($a['GroupLabel'], $b['GroupLabel']);
        });

        return array_values($result);
    }

    private function buildGroupOptions(array $groups, array $rules): array
    {
        $options = [['caption' => '', 'value' => '']];
        $used = ['' => true];

        foreach ($groups as $row) {
            $key = trim((string) ($row['GroupKey'] ?? ''));
            $label = trim((string) ($row['GroupLabel'] ?? ''));
            if ($key === '' || isset($used[$key])) {
                continue;
            }

            $options[] = [
                'caption' => $label !== '' ? $label : $key,
                'value'   => $key
            ];
            $used[$key] = true;
        }

        foreach ($rules as $row) {
            $key = trim((string) ($row['GroupKey'] ?? ''));
            if ($key === '' || isset($used[$key])) {
                continue;
            }

            $options[] = [
                'caption' => '[missing group] ' . $key,
                'value'   => $key
            ];
            $used[$key] = true;
        }

        return $options;
    }

    private function buildGroupLabels(array $groups, array $rules): array
    {
        $labels = [];

        foreach ($groups as $row) {
            $key = trim((string) ($row['GroupKey'] ?? ''));
            $label = trim((string) ($row['GroupLabel'] ?? ''));
            if ($key === '') {
                continue;
            }
            $labels[$key] = $label !== '' ? $label : $key;
        }

        foreach ($rules as $row) {
            $key = trim((string) ($row['GroupKey'] ?? ''));
            if ($key === '' || isset($labels[$key])) {
                continue;
            }
            $labels[$key] = '[missing group]';
        }

        return $labels;
    }

    private function buildTypeOptions(array $outputTypes, array $outputResources): array
    {
        $options = [['caption' => '', 'value' => '']];
        $used = ['' => true];

        foreach ($outputTypes as $row) {
            $typeID = trim((string) ($row['TypeID'] ?? ''));
            $typeName = trim((string) ($row['TypeName'] ?? ''));
            if ($typeID === '' || isset($used[$typeID])) {
                continue;
            }

            $options[] = [
                'caption' => $typeName !== '' ? $typeName : $typeID,
                'value'   => $typeID
            ];
            $used[$typeID] = true;
        }

        foreach ($outputResources as $row) {
            $typeID = trim((string) ($row['TypeID'] ?? ''));
            if ($typeID === '' || isset($used[$typeID])) {
                continue;
            }

            $options[] = [
                'caption' => '[missing type] ' . $typeID,
                'value'   => $typeID
            ];
            $used[$typeID] = true;
        }

        return $options;
    }

    private function buildTypeLabels(array $outputTypes, array $outputResources): array
    {
        $labels = [];

        foreach ($outputTypes as $row) {
            $typeID = trim((string) ($row['TypeID'] ?? ''));
            $typeName = trim((string) ($row['TypeName'] ?? ''));
            if ($typeID === '') {
                continue;
            }
            $labels[$typeID] = $typeName !== '' ? $typeName : $typeID;
        }

        foreach ($outputResources as $row) {
            $typeID = trim((string) ($row['TypeID'] ?? ''));
            if ($typeID === '' || isset($labels[$typeID])) {
                continue;
            }
            $labels[$typeID] = '[missing type]';
        }

        return $labels;
    }

    private function buildRuleOptions(array $rules, array $groupLabels): array
    {
        $options = [['caption' => '', 'value' => '']];

        foreach ($rules as $row) {
            $ruleID = trim((string) ($row['RuleID'] ?? ''));
            if ($ruleID === '') {
                continue;
            }

            $options[] = [
                'caption' => $this->buildRuleLabel($row, $groupLabels),
                'value'   => $ruleID
            ];
        }

        return $options;
    }

    private function buildRuleLabels(array $rules, array $groupLabels): array
    {
        $labels = [];

        foreach ($rules as $row) {
            $ruleID = trim((string) ($row['RuleID'] ?? ''));
            if ($ruleID === '') {
                continue;
            }
            $labels[$ruleID] = $this->buildRuleLabel($row, $groupLabels);
        }

        return $labels;
    }

    private function buildOutputOptions(array $resources, array $typeLabels): array
    {
        $options = [['caption' => '', 'value' => '']];

        foreach ($resources as $row) {
            $outputID = trim((string) ($row['OutputID'] ?? ''));
            $name = trim((string) ($row['Name'] ?? ''));
            $typeID = trim((string) ($row['TypeID'] ?? ''));
            if ($outputID === '') {
                continue;
            }

            $typeLabel = $typeLabels[$typeID] ?? '[missing type]';
            $caption = $name !== '' ? $name : $outputID;
            if ($typeLabel !== '') {
                $caption .= ' [' . $typeLabel . ']';
            }

            $options[] = [
                'caption' => $caption,
                'value'   => $outputID
            ];
        }

        return $options;
    }

    private function buildOutputLabels(array $resources): array
    {
        $labels = [];

        foreach ($resources as $row) {
            $outputID = trim((string) ($row['OutputID'] ?? ''));
            $name = trim((string) ($row['Name'] ?? ''));
            if ($outputID === '') {
                continue;
            }
            $labels[$outputID] = $name !== '' ? $name : $outputID;
        }

        return $labels;
    }

    private function buildOutputTypeLabels(array $resources, array $typeLabels): array
    {
        $labels = [];

        foreach ($resources as $row) {
            $outputID = trim((string) ($row['OutputID'] ?? ''));
            $typeID = trim((string) ($row['TypeID'] ?? ''));
            if ($outputID === '') {
                continue;
            }
            $labels[$outputID] = $typeLabels[$typeID] ?? '[missing type]';
        }

        return $labels;
    }

    private function buildOutputSummary(array $row, array $typeLabels): string
    {
        $outputID = trim((string) ($row['OutputID'] ?? ''));
        $name = trim((string) ($row['Name'] ?? ''));
        $typeID = trim((string) ($row['TypeID'] ?? ''));
        $typeLabel = $typeLabels[$typeID] ?? '[missing type]';
        $targetObjectID = (int) ($row['TargetObjectID'] ?? 0);

        $parts = [];
        if ($outputID !== '') {
            $parts[] = $outputID;
        }
        if ($name !== '') {
            $parts[] = $name;
        }
        if ($typeLabel !== '') {
            $parts[] = $typeLabel;
        }
        if ($targetObjectID > 0) {
            $parts[] = 'ObjID: ' . $targetObjectID;
        }

        return implode(' / ', $parts);
    }

    private function buildRuleLabel(array $row, array $groupLabels): string
    {
        $groupKey = trim((string) ($row['GroupKey'] ?? ''));
        $houseState = trim((string) ($row['HouseState'] ?? ''));
        $groupLabel = $groupLabels[$groupKey] ?? '[missing group]';
        $stateLabel = $this->labelFromOptions(self::HOUSE_STATES, $houseState);

        if ($groupKey === '' && $houseState === '') {
            return '';
        }
        if ($stateLabel === '') {
            return $groupLabel;
        }

        return $groupLabel . ' / ' . $stateLabel;
    }

    private function buildRuleSummary(array $row, array $groupLabels): string
    {
        $parts = [];
        $label = $this->buildRuleLabel($row, $groupLabels);
        $severity = trim((string) ($row['Severity'] ?? ''));
        $bypass = (bool) ($row['Bypass'] ?? false);

        if ($label !== '') {
            $parts[] = $label;
        }
        if ($severity !== '') {
            $parts[] = 'severity: ' . $severity;
        }
        if ($bypass) {
            $parts[] = 'bypass';
        }

        return implode(' / ', $parts);
    }

    private function buildAssignmentSummary(string $ruleLabel, string $outputName, string $outputTypeLabel): string
    {
        $parts = [];
        if ($ruleLabel !== '') {
            $parts[] = $ruleLabel;
        }
        if ($outputName !== '') {
            $parts[] = $outputName;
        }
        if ($outputTypeLabel !== '') {
            $parts[] = '[' . $outputTypeLabel . ']';
        }

        return implode(' -> ', $parts);
    }

    private function labelFromOptions(array $options, string $value): string
    {
        foreach ($options as $option) {
            if ((string) ($option['value'] ?? '') === $value) {
                return (string) ($option['caption'] ?? '');
            }
        }
        return $value;
    }

    private function setListValues(array &$form, string $listName, array $values): void
    {
        $this->walkAndModifyList($form, $listName, function (&$element) use ($values) {
            $element['values'] = $values;
        });
    }

    private function setListColumnOptions(array &$form, string $listName, string $columnName, array $options): void
    {
        $this->walkAndModifyList($form, $listName, function (&$element) use ($columnName, $options) {
            if (!isset($element['columns']) || !is_array($element['columns'])) {
                return;
            }

            foreach ($element['columns'] as &$column) {
                if (($column['name'] ?? '') !== $columnName) {
                    continue;
                }

                if (!isset($column['edit']) || !is_array($column['edit'])) {
                    $column['edit'] = ['type' => 'Select'];
                }

                $column['edit']['options'] = $options;
                return;
            }
        });
    }

    private function walkAndModifyList(array &$node, string $listName, callable $callback): bool
    {
        if (($node['type'] ?? '') === 'List' && ($node['name'] ?? '') === $listName) {
            $callback($node);
            return true;
        }

        if (isset($node['elements']) && is_array($node['elements'])) {
            foreach ($node['elements'] as &$child) {
                if ($this->walkAndModifyList($child, $listName, $callback)) {
                    return true;
                }
            }
        }

        if (isset($node['items']) && is_array($node['items'])) {
            foreach ($node['items'] as &$child) {
                if ($this->walkAndModifyList($child, $listName, $callback)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function GenerateTechnicalID(string $prefix): string
    {
        return $prefix . bin2hex(random_bytes(6));
    }

    private function MakeGroupKey(string $groupLabel): string
    {
        return 'grp_' . md5(mb_strtolower(trim($groupLabel)));
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
