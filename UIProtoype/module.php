<?php

declare(strict_types=1);

class AlarmResponseManagerPrototype extends IPSModule
{
    private const HOUSE_STATES = [
        ['label' => 'Disarmed',       'value' => 'disarmed'],
        ['label' => 'Exit Delay',     'value' => 'exit_delay'],
        ['label' => 'Armed Internal', 'value' => 'armed_internal'],
        ['label' => 'Armed External', 'value' => 'armed_external'],
        ['label' => 'Alarm',          'value' => 'alarm']
    ];

    private const SEVERITIES = [
        ['label' => 'Low',      'value' => 'low'],
        ['label' => 'Medium',   'value' => 'medium'],
        ['label' => 'High',     'value' => 'high'],
        ['label' => 'Critical', 'value' => 'critical']
    ];

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('ImportedModule1Groups', '[]');
        $this->RegisterPropertyString('OutputTypes', '[]');
        $this->RegisterPropertyString('OutputResources', '[]');
        $this->RegisterPropertyString('GroupStateRules', '[]');
        $this->RegisterPropertyString('RuleOutputAssignments', '[]');

        $this->RegisterAttributeInteger('NormalizationGuard', 0);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        if ($this->ReadAttributeInteger('NormalizationGuard') === 1) {
            $this->WriteAttributeInteger('NormalizationGuard', 0);
            return;
        }

        $groups      = $this->normalizeImportedGroups($this->readJsonProperty('ImportedModule1Groups'));
        $outputTypes = $this->normalizeOutputTypes($this->readJsonProperty('OutputTypes'));
        $resources   = $this->normalizeOutputResources($this->readJsonProperty('OutputResources'), $outputTypes);
        $rules       = $this->normalizeGroupStateRules($this->readJsonProperty('GroupStateRules'), $groups);
        $links       = $this->normalizeRuleOutputAssignments($this->readJsonProperty('RuleOutputAssignments'), $rules, $resources);

        $updates = [];
        $encodedGroups = $this->encodeJson($groups);
        $encodedTypes  = $this->encodeJson($outputTypes);
        $encodedRes    = $this->encodeJson($resources);
        $encodedRules  = $this->encodeJson($rules);
        $encodedLinks  = $this->encodeJson($links);

        if ($encodedGroups !== $this->ReadPropertyString('ImportedModule1Groups')) {
            $updates['ImportedModule1Groups'] = $encodedGroups;
        }
        if ($encodedTypes !== $this->ReadPropertyString('OutputTypes')) {
            $updates['OutputTypes'] = $encodedTypes;
        }
        if ($encodedRes !== $this->ReadPropertyString('OutputResources')) {
            $updates['OutputResources'] = $encodedRes;
        }
        if ($encodedRules !== $this->ReadPropertyString('GroupStateRules')) {
            $updates['GroupStateRules'] = $encodedRules;
        }
        if ($encodedLinks !== $this->ReadPropertyString('RuleOutputAssignments')) {
            $updates['RuleOutputAssignments'] = $encodedLinks;
        }

        if (count($updates) > 0) {
            $this->WriteAttributeInteger('NormalizationGuard', 1);
            foreach ($updates as $property => $value) {
                IPS_SetProperty($this->InstanceID, $property, $value);
            }
            IPS_ApplyChanges($this->InstanceID);
        }
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'LoadPrototypeData':
                $this->loadPrototypeData();
                break;

            case 'ClearPrototypeData':
                $this->clearPrototypeData();
                break;

            default:
                throw new Exception('Invalid Ident: ' . $Ident);
        }
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);

        $groups      = $this->normalizeImportedGroups($this->readJsonProperty('ImportedModule1Groups'));
        $outputTypes = $this->normalizeOutputTypes($this->readJsonProperty('OutputTypes'));
        $resources   = $this->normalizeOutputResources($this->readJsonProperty('OutputResources'), $outputTypes);
        $rules       = $this->normalizeGroupStateRules($this->readJsonProperty('GroupStateRules'), $groups);
        $links       = $this->normalizeRuleOutputAssignments($this->readJsonProperty('RuleOutputAssignments'), $rules, $resources);

        $this->injectListValues($form, 'OutputTypes', $outputTypes);
        $this->injectListValues($form, 'OutputResources', $resources);
        $this->injectListValues($form, 'GroupStateRules', $rules);
        $this->injectListValues($form, 'RuleOutputAssignments', $links);

        $this->injectColumnOptions($form, 'OutputResources', 'TypeID', $this->buildOutputTypeOptions($outputTypes));
        $this->injectColumnOptions($form, 'GroupStateRules', 'GroupKey', $this->buildGroupOptions($groups));
        $this->injectColumnOptions($form, 'GroupStateRules', 'HouseState', self::HOUSE_STATES);
        $this->injectColumnOptions($form, 'GroupStateRules', 'Severity', self::SEVERITIES);
        $this->injectColumnOptions($form, 'RuleOutputAssignments', 'RuleID', $this->buildRuleOptions($rules));
        $this->injectColumnOptions($form, 'RuleOutputAssignments', 'OutputID', $this->buildOutputResourceOptions($resources));

        $info = sprintf(
            'Prototype status: %d output type(s), %d customized output(s), %d rule(s), %d assignment(s), %d imported Module 1 group(s).',
            count($outputTypes),
            count($resources),
            count($rules),
            count($links),
            count($groups)
        );
        $this->injectElementCaption($form, 'PrototypeInfo', $info);

        return json_encode($form);
    }

    private function loadPrototypeData(): void
    {
        $groups = [
            ['GroupKey' => 'fire_alarm',    'GroupLabel' => 'Fire Alarm',    'Active' => true],
            ['GroupKey' => 'water_alarm',   'GroupLabel' => 'Water Alarm',   'Active' => true],
            ['GroupKey' => 'burglar_alarm', 'GroupLabel' => 'Burglar Alarm', 'Active' => true]
        ];

        $types = [
            ['TypeID' => 'type_email', 'Active' => true, 'TypeName' => 'Email', 'BaseObjectID' => 0, 'TechnicalSummary' => 'Symcon mail instance'],
            ['TypeID' => 'type_siren', 'Active' => true, 'TypeName' => 'Siren', 'BaseObjectID' => 0, 'TechnicalSummary' => 'Relay or script'],
            ['TypeID' => 'type_voip',  'Active' => true, 'TypeName' => 'VOIP',  'BaseObjectID' => 0, 'TechnicalSummary' => 'Call script']
        ];

        $resources = [
            [
                'OutputID' => 'out_0001',
                'Active' => true,
                'Name' => 'Email Artur',
                'TypeID' => 'type_email',
                'Recipient' => '1234fisart@gmail.com',
                'Sender' => 'house@ap-fischer.de',
                'ConfigSummary' => 'Recipient Artur / house sender'
            ],
            [
                'OutputID' => 'out_0002',
                'Active' => true,
                'Name' => 'Email Penny',
                'TypeID' => 'type_email',
                'Recipient' => 'penny@example.com',
                'Sender' => 'house@ap-fischer.de',
                'ConfigSummary' => 'Recipient Penny / house sender'
            ],
            [
                'OutputID' => 'out_0003',
                'Active' => true,
                'Name' => 'Siren Outside',
                'TypeID' => 'type_siren',
                'Recipient' => '',
                'Sender' => '',
                'ConfigSummary' => 'Outside siren'
            ]
        ];

        $rules = [
            [
                'RuleID' => 'rule_0001',
                'Active' => true,
                'GroupKey' => 'fire_alarm',
                'HouseState' => 'disarmed',
                'Severity' => 'critical',
                'BypassThrottle' => true,
                'RuleLabel' => 'Fire Alarm / Disarmed',
                'OutputsSummary' => '3 outputs'
            ],
            [
                'RuleID' => 'rule_0002',
                'Active' => true,
                'GroupKey' => 'water_alarm',
                'HouseState' => 'disarmed',
                'Severity' => 'high',
                'BypassThrottle' => false,
                'RuleLabel' => 'Water Alarm / Disarmed',
                'OutputsSummary' => '1 output'
            ]
        ];

        $links = [
            [
                'AssignmentID' => 'assign_0001',
                'Active' => true,
                'RuleID' => 'rule_0001',
                'OutputID' => 'out_0001',
                'RuleLabel' => 'Fire Alarm / Disarmed',
                'OutputName' => 'Email Artur',
                'OutputTypeName' => 'Email'
            ],
            [
                'AssignmentID' => 'assign_0002',
                'Active' => true,
                'RuleID' => 'rule_0001',
                'OutputID' => 'out_0002',
                'RuleLabel' => 'Fire Alarm / Disarmed',
                'OutputName' => 'Email Penny',
                'OutputTypeName' => 'Email'
            ],
            [
                'AssignmentID' => 'assign_0003',
                'Active' => true,
                'RuleID' => 'rule_0001',
                'OutputID' => 'out_0003',
                'RuleLabel' => 'Fire Alarm / Disarmed',
                'OutputName' => 'Siren Outside',
                'OutputTypeName' => 'Siren'
            ],
            [
                'AssignmentID' => 'assign_0004',
                'Active' => true,
                'RuleID' => 'rule_0002',
                'OutputID' => 'out_0001',
                'RuleLabel' => 'Water Alarm / Disarmed',
                'OutputName' => 'Email Artur',
                'OutputTypeName' => 'Email'
            ]
        ];

        IPS_SetProperty($this->InstanceID, 'ImportedModule1Groups', $this->encodeJson($groups));
        IPS_SetProperty($this->InstanceID, 'OutputTypes', $this->encodeJson($types));
        IPS_SetProperty($this->InstanceID, 'OutputResources', $this->encodeJson($resources));
        IPS_SetProperty($this->InstanceID, 'GroupStateRules', $this->encodeJson($rules));
        IPS_SetProperty($this->InstanceID, 'RuleOutputAssignments', $this->encodeJson($links));
        IPS_ApplyChanges($this->InstanceID);
    }

    private function clearPrototypeData(): void
    {
        IPS_SetProperty($this->InstanceID, 'ImportedModule1Groups', '[]');
        IPS_SetProperty($this->InstanceID, 'OutputTypes', '[]');
        IPS_SetProperty($this->InstanceID, 'OutputResources', '[]');
        IPS_SetProperty($this->InstanceID, 'GroupStateRules', '[]');
        IPS_SetProperty($this->InstanceID, 'RuleOutputAssignments', '[]');
        IPS_ApplyChanges($this->InstanceID);
    }

    private function readJsonProperty(string $property): array
    {
        $raw = $this->ReadPropertyString($property);
        $data = json_decode($raw, true);
        return is_array($data) ? array_values($data) : [];
    }

    private function encodeJson(array $data): string
    {
        return json_encode(array_values($data), JSON_UNESCAPED_SLASHES);
    }

    private function normalizeImportedGroups(array $groups): array
    {
        $result = [];
        foreach ($groups as $group) {
            if (!is_array($group)) {
                continue;
            }

            $key   = trim((string)($group['GroupKey'] ?? ''));
            $label = trim((string)($group['GroupLabel'] ?? ''));

            if ($key === '' && $label !== '') {
                $key = $this->slugify($label);
            }
            if ($label === '' && $key !== '') {
                $label = $this->humanizeKey($key);
            }
            if ($key === '' && $label === '') {
                continue;
            }

            $result[] = [
                'Active'     => (bool)($group['Active'] ?? true),
                'GroupKey'   => $key,
                'GroupLabel' => $label
            ];
        }
        return $result;
    }

    private function normalizeOutputTypes(array $types): array
    {
        $result = [];
        $counter = 1;

        foreach ($types as $row) {
            if (!is_array($row)) {
                continue;
            }

            $typeID = trim((string)($row['TypeID'] ?? ''));
            $name   = trim((string)($row['TypeName'] ?? ''));
            if ($typeID === '' && $name !== '') {
                $typeID = 'type_' . $this->slugify($name);
            }
            if ($typeID === '') {
                $typeID = sprintf('type_%04d', $counter);
            }
            if ($name === '') {
                $name = 'Type ' . $counter;
            }

            $result[] = [
                'TypeID'            => $typeID,
                'Active'            => (bool)($row['Active'] ?? true),
                'TypeName'          => $name,
                'BaseObjectID'      => (int)($row['BaseObjectID'] ?? 0),
                'TechnicalSummary'  => trim((string)($row['TechnicalSummary'] ?? ''))
            ];
            $counter++;
        }

        return $result;
    }

    private function normalizeOutputResources(array $resources, array $types): array
    {
        $typeMap = [];
        foreach ($types as $type) {
            $typeMap[$type['TypeID']] = $type;
        }

        $result = [];
        $counter = 1;

        foreach ($resources as $row) {
            if (!is_array($row)) {
                continue;
            }

            $outputID = trim((string)($row['OutputID'] ?? ''));
            $name     = trim((string)($row['Name'] ?? ''));
            $typeID   = trim((string)($row['TypeID'] ?? ''));
            $recipient = trim((string)($row['Recipient'] ?? ''));
            $sender    = trim((string)($row['Sender'] ?? ''));

            if ($outputID === '' && $name !== '') {
                $outputID = 'out_' . $this->slugify($name);
            }
            if ($outputID === '') {
                $outputID = sprintf('out_%04d', $counter);
            }
            if ($name === '') {
                $name = 'Output ' . $counter;
            }

            $summary = trim((string)($row['ConfigSummary'] ?? ''));
            if ($summary === '') {
                $parts = [];
                if ($recipient !== '') {
                    $parts[] = 'to: ' . $recipient;
                }
                if ($sender !== '') {
                    $parts[] = 'from: ' . $sender;
                }
                $summary = implode(' / ', $parts);
            }

            $result[] = [
                'OutputID'      => $outputID,
                'Active'        => (bool)($row['Active'] ?? true),
                'Name'          => $name,
                'TypeID'        => $typeID,
                'Recipient'     => $recipient,
                'Sender'        => $sender,
                'ConfigSummary' => $summary
            ];
            $counter++;
        }

        return $result;
    }

    private function normalizeGroupStateRules(array $rules, array $groups): array
    {
        $groupMap = [];
        foreach ($groups as $group) {
            $groupMap[$group['GroupKey']] = $group['GroupLabel'];
        }

        $result = [];
        $counter = 1;

        foreach ($rules as $row) {
            if (!is_array($row)) {
                continue;
            }

            $ruleID      = trim((string)($row['RuleID'] ?? ''));
            $groupKey    = trim((string)($row['GroupKey'] ?? ''));
            $houseState  = trim((string)($row['HouseState'] ?? 'disarmed'));
            $severity    = trim((string)($row['Severity'] ?? 'medium'));

            if ($ruleID === '') {
                $ruleID = sprintf('rule_%04d', $counter);
            }

            $groupLabel = $groupMap[$groupKey] ?? $this->humanizeKey($groupKey);
            $stateLabel = $this->labelFromOptions(self::HOUSE_STATES, $houseState);
            $ruleLabel  = trim((string)($row['RuleLabel'] ?? ''));
            if ($ruleLabel === '') {
                $ruleLabel = trim($groupLabel . ' / ' . $stateLabel, ' /');
            }

            $result[] = [
                'RuleID'          => $ruleID,
                'Active'          => (bool)($row['Active'] ?? true),
                'GroupKey'        => $groupKey,
                'HouseState'      => $houseState,
                'Severity'        => $severity,
                'BypassThrottle'  => (bool)($row['BypassThrottle'] ?? false),
                'RuleLabel'       => $ruleLabel,
                'OutputsSummary'  => ''
            ];
            $counter++;
        }

        return $this->applyOutputCountsToRules($result, $this->readJsonProperty('RuleOutputAssignments'));
    }

    private function normalizeRuleOutputAssignments(array $links, array $rules, array $resources): array
    {
        $ruleMap = [];
        foreach ($rules as $rule) {
            $ruleMap[$rule['RuleID']] = $rule;
        }

        $resourceMap = [];
        $typeMap = [];
        foreach ($this->normalizeOutputTypes($this->readJsonProperty('OutputTypes')) as $type) {
            $typeMap[$type['TypeID']] = $type['TypeName'];
        }
        foreach ($resources as $resource) {
            $resourceMap[$resource['OutputID']] = $resource;
        }

        $result = [];
        $counter = 1;

        foreach ($links as $row) {
            if (!is_array($row)) {
                continue;
            }

            $assignmentID = trim((string)($row['AssignmentID'] ?? ''));
            $ruleID       = trim((string)($row['RuleID'] ?? ''));
            $outputID     = trim((string)($row['OutputID'] ?? ''));

            if ($assignmentID === '') {
                $assignmentID = sprintf('assign_%04d', $counter);
            }

            $ruleLabel = $ruleMap[$ruleID]['RuleLabel'] ?? '';
            $outputName = $resourceMap[$outputID]['Name'] ?? '';
            $outputType = '';
            if (isset($resourceMap[$outputID])) {
                $outputTypeID = $resourceMap[$outputID]['TypeID'] ?? '';
                $outputType = $typeMap[$outputTypeID] ?? $outputTypeID;
            }

            $result[] = [
                'AssignmentID'   => $assignmentID,
                'Active'         => (bool)($row['Active'] ?? true),
                'RuleID'         => $ruleID,
                'OutputID'       => $outputID,
                'RuleLabel'      => $ruleLabel,
                'OutputName'     => $outputName,
                'OutputTypeName' => $outputType
            ];
            $counter++;
        }

        return $result;
    }

    private function applyOutputCountsToRules(array $rules, array $rawLinks): array
    {
        $counts = [];
        foreach ($rawLinks as $link) {
            if (!is_array($link)) {
                continue;
            }
            if (!(bool)($link['Active'] ?? true)) {
                continue;
            }
            $ruleID = (string)($link['RuleID'] ?? '');
            if ($ruleID === '') {
                continue;
            }
            if (!isset($counts[$ruleID])) {
                $counts[$ruleID] = 0;
            }
            $counts[$ruleID]++;
        }

        foreach ($rules as &$rule) {
            $count = $counts[$rule['RuleID']] ?? 0;
            $rule['OutputsSummary'] = $count . ' output' . ($count === 1 ? '' : 's');
        }
        unset($rule);

        return $rules;
    }

    private function buildOutputTypeOptions(array $types): array
    {
        $options = [];
        foreach ($types as $type) {
            $options[] = [
                'label' => (string)$type['TypeName'],
                'value' => (string)$type['TypeID']
            ];
        }
        return $options;
    }

    private function buildGroupOptions(array $groups): array
    {
        $options = [];
        foreach ($groups as $group) {
            $options[] = [
                'label' => (string)$group['GroupLabel'],
                'value' => (string)$group['GroupKey']
            ];
        }
        return $options;
    }

    private function buildRuleOptions(array $rules): array
    {
        $options = [];
        foreach ($rules as $rule) {
            $options[] = [
                'label' => (string)$rule['RuleLabel'],
                'value' => (string)$rule['RuleID']
            ];
        }
        return $options;
    }

    private function buildOutputResourceOptions(array $resources): array
    {
        $typeNames = [];
        foreach ($this->normalizeOutputTypes($this->readJsonProperty('OutputTypes')) as $type) {
            $typeNames[$type['TypeID']] = $type['TypeName'];
        }

        $options = [];
        foreach ($resources as $resource) {
            $typeLabel = $typeNames[$resource['TypeID']] ?? $resource['TypeID'];
            $options[] = [
                'label' => (string)$resource['Name'] . ' [' . $typeLabel . ']',
                'value' => (string)$resource['OutputID']
            ];
        }
        return $options;
    }

    private function injectListValues(array &$form, string $listName, array $values): void
    {
        if (!isset($form['elements']) || !is_array($form['elements'])) {
            return;
        }

        foreach ($form['elements'] as &$element) {
            if (($element['type'] ?? '') === 'List' && ($element['name'] ?? '') === $listName) {
                $element['values'] = array_values($values);
                return;
            }
        }
    }

    private function injectColumnOptions(array &$form, string $listName, string $columnName, array $options): void
    {
        if (!isset($form['elements']) || !is_array($form['elements'])) {
            return;
        }

        foreach ($form['elements'] as &$element) {
            if (($element['type'] ?? '') !== 'List' || ($element['name'] ?? '') !== $listName) {
                continue;
            }

            if (!isset($element['columns']) || !is_array($element['columns'])) {
                continue;
            }

            foreach ($element['columns'] as &$column) {
                if (($column['name'] ?? '') !== $columnName) {
                    continue;
                }
                if (!isset($column['edit']) || !is_array($column['edit'])) {
                    $column['edit'] = ['type' => 'Select'];
                }
                $column['edit']['options'] = array_values($options);
                return;
            }
        }
    }

    private function injectElementCaption(array &$form, string $elementName, string $caption): void
    {
        if (!isset($form['elements']) || !is_array($form['elements'])) {
            return;
        }

        foreach ($form['elements'] as &$element) {
            if (($element['name'] ?? '') === $elementName) {
                $element['caption'] = $caption;
                return;
            }
        }
    }

    private function slugify(string $value): string
    {
        $value = trim(mb_strtolower($value));
        $value = preg_replace('/[^a-z0-9]+/u', '_', $value);
        $value = trim((string)$value, '_');
        return $value === '' ? 'item' : $value;
    }

    private function humanizeKey(string $key): string
    {
        $key = str_replace(['_', '-'], ' ', $key);
        $key = trim($key);
        return $key === '' ? '' : ucwords($key);
    }

    private function labelFromOptions(array $options, string $value): string
    {
        foreach ($options as $option) {
            if ((string)$option['value'] === $value) {
                return (string)$option['label'];
            }
        }
        return $value;
    }
}
