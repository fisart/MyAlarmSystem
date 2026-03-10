<?php

declare(strict_types=1);

class DynamicListFullPatternTest extends IPSModule
{
    private const HOUSE_STATES = [
        ['caption' => '',               'value' => ''],
        ['caption' => 'Disarmed',       'value' => 'disarmed'],
        ['caption' => 'Exit Delay',     'value' => 'exit_delay'],
        ['caption' => 'Armed Internal', 'value' => 'armed_internal'],
        ['caption' => 'Armed External', 'value' => 'armed_external'],
        ['caption' => 'Alarm',          'value' => 'alarm']
    ];

    public function Create()
    {
        parent::Create();

        // Simulated imported Module 1 groups
        $this->RegisterPropertyString('ImportedGroups', '[
            {"GroupKey":"fire_alarm","GroupLabel":"Fire Alarm"},
            {"GroupKey":"water_alarm","GroupLabel":"Water Alarm"},
            {"GroupKey":"burglar_alarm","GroupLabel":"Burglar Alarm"}
        ]');

        // Authoritative persisted data
        $this->RegisterPropertyString('OutputTypes', '[]');
        $this->RegisterPropertyString('OutputResources', '[]');
        $this->RegisterPropertyString('GroupStateRules', '[]');
        $this->RegisterPropertyString('RuleOutputAssignments', '[]');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->SetStatus(102);
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);

        $importedGroups = $this->readListProperty('ImportedGroups');
        $outputTypes = $this->readListProperty('OutputTypes');
        $outputResources = $this->readListProperty('OutputResources');
        $groupStateRules = $this->readListProperty('GroupStateRules');
        $ruleOutputAssignments = $this->readListProperty('RuleOutputAssignments');

        $groupOptions = $this->buildGroupOptions($importedGroups);
        $groupLabels = $this->buildGroupLabels($importedGroups);

        $typeOptions = $this->buildTypeOptions($outputTypes);
        $typeLabels = $this->buildTypeLabels($outputTypes);

        $ruleOptions = $this->buildRuleOptions($groupStateRules, $groupLabels);
        $ruleLabels = $this->buildRuleLabels($groupStateRules, $groupLabels);

        $outputOptions = $this->buildOutputOptions($outputResources, $typeLabels);
        $outputLabels = $this->buildOutputLabels($outputResources);
        $outputTypeLabels = $this->buildOutputTypeLabels($outputResources, $typeLabels);

        // Inject options into nested lists
        $this->setListColumnOptions($form, 'OutputResources', 'TypeID', $typeOptions);
        $this->setListColumnOptions($form, 'GroupStateRules', 'GroupKey', $groupOptions);
        $this->setListColumnOptions($form, 'GroupStateRules', 'HouseState', self::HOUSE_STATES);
        $this->setListColumnOptions($form, 'RuleOutputAssignments', 'RuleID', $ruleOptions);
        $this->setListColumnOptions($form, 'RuleOutputAssignments', 'OutputID', $outputOptions);

        // Rebuild dynamic OutputResources list
        $resourceValues = [];
        foreach ($outputResources as $row) {
            $typeID = (string)($row['TypeID'] ?? '');

            $resourceValues[] = [
                'Active'     => (bool)($row['Active'] ?? true),
                'OutputID'   => (string)($row['OutputID'] ?? ''),
                'Name'       => (string)($row['Name'] ?? ''),
                'TypeID'     => $typeID,
                'Recipient'  => (string)($row['Recipient'] ?? ''),
                'Sender'     => (string)($row['Sender'] ?? ''),
                'TypeLabel'  => $typeLabels[$typeID] ?? '',
                'RowSummary' => $this->buildOutputSummary($row, $typeLabels)
            ];
        }
        $this->setListValues($form, 'OutputResources', $resourceValues);

        // Rebuild dynamic GroupStateRules list
        $ruleValues = [];
        foreach ($groupStateRules as $row) {
            $groupKey = (string)($row['GroupKey'] ?? '');
            $houseState = (string)($row['HouseState'] ?? '');

            $ruleValues[] = [
                'Active'      => (bool)($row['Active'] ?? true),
                'RuleID'      => (string)($row['RuleID'] ?? ''),
                'GroupKey'    => $groupKey,
                'HouseState'  => $houseState,
                'Severity'    => (string)($row['Severity'] ?? ''),
                'Bypass'      => (bool)($row['Bypass'] ?? false),
                'GroupLabel'  => $groupLabels[$groupKey] ?? '',
                'RuleLabel'   => $this->buildRuleLabel($row, $groupLabels),
                'RowSummary'  => $this->buildRuleSummary($row, $groupLabels)
            ];
        }
        $this->setListValues($form, 'GroupStateRules', $ruleValues);

        // Rebuild dynamic RuleOutputAssignments list
        $assignmentValues = [];
        foreach ($ruleOutputAssignments as $row) {
            $ruleID = (string)($row['RuleID'] ?? '');
            $outputID = (string)($row['OutputID'] ?? '');

            $assignmentValues[] = [
                'Active'          => (bool)($row['Active'] ?? true),
                'AssignmentID'    => (string)($row['AssignmentID'] ?? ''),
                'RuleID'          => $ruleID,
                'OutputID'        => $outputID,
                'RuleLabel'       => $ruleLabels[$ruleID] ?? '',
                'OutputName'      => $outputLabels[$outputID] ?? '',
                'OutputTypeLabel' => $outputTypeLabels[$outputID] ?? '',
                'RowSummary'      => $this->buildAssignmentSummary($ruleLabels[$ruleID] ?? '', $outputLabels[$outputID] ?? '', $outputTypeLabels[$outputID] ?? '')
            ];
        }
        $this->setListValues($form, 'RuleOutputAssignments', $assignmentValues);

        return json_encode($form);
    }

    private function readListProperty(string $propertyName): array
    {
        $raw = $this->ReadPropertyString($propertyName);
        $data = json_decode($raw, true);
        return is_array($data) ? array_values($data) : [];
    }

    private function buildGroupOptions(array $groups): array
    {
        $options = [['caption' => '', 'value' => '']];
        foreach ($groups as $row) {
            $key = trim((string)($row['GroupKey'] ?? ''));
            $label = trim((string)($row['GroupLabel'] ?? ''));
            if ($key === '') {
                continue;
            }
            $options[] = [
                'caption' => $label !== '' ? $label : $key,
                'value'   => $key
            ];
        }
        return $options;
    }

    private function buildGroupLabels(array $groups): array
    {
        $labels = [];
        foreach ($groups as $row) {
            $key = trim((string)($row['GroupKey'] ?? ''));
            $label = trim((string)($row['GroupLabel'] ?? ''));
            if ($key === '') {
                continue;
            }
            $labels[$key] = $label !== '' ? $label : $key;
        }
        return $labels;
    }

    private function buildTypeOptions(array $outputTypes): array
    {
        $options = [['caption' => '', 'value' => '']];
        foreach ($outputTypes as $row) {
            $typeID = trim((string)($row['TypeID'] ?? ''));
            $typeName = trim((string)($row['TypeName'] ?? ''));
            if ($typeID === '') {
                continue;
            }
            $options[] = [
                'caption' => $typeName !== '' ? $typeName : $typeID,
                'value'   => $typeID
            ];
        }
        return $options;
    }

    private function buildTypeLabels(array $outputTypes): array
    {
        $labels = [];
        foreach ($outputTypes as $row) {
            $typeID = trim((string)($row['TypeID'] ?? ''));
            $typeName = trim((string)($row['TypeName'] ?? ''));
            if ($typeID === '') {
                continue;
            }
            $labels[$typeID] = $typeName !== '' ? $typeName : $typeID;
        }
        return $labels;
    }

    private function buildRuleOptions(array $rules, array $groupLabels): array
    {
        $options = [['caption' => '', 'value' => '']];
        foreach ($rules as $row) {
            $ruleID = trim((string)($row['RuleID'] ?? ''));
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
            $ruleID = trim((string)($row['RuleID'] ?? ''));
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
            $outputID = trim((string)($row['OutputID'] ?? ''));
            $name = trim((string)($row['Name'] ?? ''));
            $typeID = trim((string)($row['TypeID'] ?? ''));
            if ($outputID === '') {
                continue;
            }
            $typeLabel = $typeLabels[$typeID] ?? $typeID;
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
            $outputID = trim((string)($row['OutputID'] ?? ''));
            $name = trim((string)($row['Name'] ?? ''));
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
            $outputID = trim((string)($row['OutputID'] ?? ''));
            $typeID = trim((string)($row['TypeID'] ?? ''));
            if ($outputID === '') {
                continue;
            }
            $labels[$outputID] = $typeLabels[$typeID] ?? $typeID;
        }
        return $labels;
    }

    private function buildOutputSummary(array $row, array $typeLabels): string
    {
        $typeID = (string)($row['TypeID'] ?? '');
        $typeLabel = $typeLabels[$typeID] ?? $typeID;
        $name = trim((string)($row['Name'] ?? ''));
        $recipient = trim((string)($row['Recipient'] ?? ''));
        $sender = trim((string)($row['Sender'] ?? ''));

        $parts = [];
        if ($typeLabel !== '') {
            $parts[] = $typeLabel;
        }
        if ($name !== '') {
            $parts[] = $name;
        }
        if ($recipient !== '') {
            $parts[] = 'to: ' . $recipient;
        }
        if ($sender !== '') {
            $parts[] = 'from: ' . $sender;
        }
        return implode(' / ', $parts);
    }

    private function buildRuleLabel(array $row, array $groupLabels): string
    {
        $groupKey = (string)($row['GroupKey'] ?? '');
        $houseState = (string)($row['HouseState'] ?? '');
        $groupLabel = $groupLabels[$groupKey] ?? $groupKey;
        $stateLabel = $this->labelFromOptions(self::HOUSE_STATES, $houseState);

        if ($groupLabel === '' && $stateLabel === '') {
            return '';
        }
        if ($groupLabel === '') {
            return $stateLabel;
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
        $severity = trim((string)($row['Severity'] ?? ''));
        $bypass = (bool)($row['Bypass'] ?? false);

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
            if ((string)$option['value'] === $value) {
                return (string)$option['caption'];
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
}
