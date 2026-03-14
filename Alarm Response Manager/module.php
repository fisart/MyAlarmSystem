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
        ['TypeID' => 'email_1',      'TypeName' => 'Email 1'],
        ['TypeID' => 'email_2',      'TypeName' => 'Email 2'],
        ['TypeID' => 'email_3',      'TypeName' => 'Email 3'],
        ['TypeID' => 'email_4',      'TypeName' => 'Email 4'],
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
        $this->RegisterPropertyString('ConfigBackupJson', '');
        $this->RegisterPropertyInteger('ScreenLogMaxEntries', 100);
        $this->RegisterPropertyString('VisibleGraphGroups', '[]');
        $this->RegisterPropertyString('AssignmentMatrixConfig', '[]');
        $this->RegisterPropertyString('GroupStateMatrixConfig', '[]');

        $this->RegisterPropertyString('OutputResources', '[]');
        $this->RegisterPropertyString('GroupStateRules', '[]');
        $this->RegisterPropertyString('RuleOutputAssignments', '[]');


        $this->RegisterAttributeString('CachedHouseStateSnapshot', '');
        $this->RegisterAttributeInteger('CachedHouseStateReceivedAt', 0);
        $this->RegisterAttributeString('LastEventEpoch', '0');
        $this->RegisterAttributeInteger('LastEventSeq', 0);
        $this->RegisterAttributeString('LastActiveGroups', '[]');
        $this->RegisterAttributeString('LastActiveRuleIDs', '[]');
        $this->RegisterAttributeString('LastActiveOutputIDs', '[]');
        $this->RegisterAttributeString('LastReceivedPayloadRaw', '');

        $this->RegisterAttributeString('CachedModule1PayloadRaw', '');
        $this->RegisterAttributeString('CachedTargetActiveGroups', '[]');
        $this->RegisterAttributeString('CachedTargetActiveClasses', '[]');
        $this->RegisterAttributeString('CachedTargetActiveSensorDetails', '[]');

        $this->RegisterAttributeString('ActiveOutputMatchKeys', '[]');
        $this->RegisterAttributeString('OutputThrottleHistory', '{}');

        $this->RegisterVariableString('OutputScreenHtml', 'Output Screen', '~HTMLBox', 0);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->RegisterHook('/hook/psm_output_' . $this->InstanceID);

        $this->SetStatus(102);
    }

    private function GetVisibleGraphGroupKeys(array $groups): array
    {
        $available = [];
        foreach ($groups as $group) {
            $groupKey = trim((string) ($group['GroupKey'] ?? ''));
            if ($groupKey !== '') {
                $available[$groupKey] = true;
            }
        }

        $raw = $this->ReadPropertyString('VisibleGraphGroups');
        $stored = json_decode($raw, true);

        if (!is_array($stored) || count($stored) === 0) {
            return array_keys($available);
        }

        $result = [];
        foreach ($stored as $groupKeyRaw) {
            $groupKey = trim((string) $groupKeyRaw);
            if ($groupKey !== '' && isset($available[$groupKey])) {
                $result[] = $groupKey;
            }
        }

        return array_values(array_unique($result));
    }

    private function SaveVisibleGraphGroupKeys(array $groupKeys, array $groups): void
    {
        $available = [];
        foreach ($groups as $group) {
            $groupKey = trim((string) ($group['GroupKey'] ?? ''));
            if ($groupKey !== '') {
                $available[$groupKey] = true;
            }
        }

        $clean = [];
        foreach ($groupKeys as $groupKeyRaw) {
            $groupKey = trim((string) $groupKeyRaw);
            if ($groupKey !== '' && isset($available[$groupKey])) {
                $clean[] = $groupKey;
            }
        }

        IPS_SetProperty($this->InstanceID, 'VisibleGraphGroups', json_encode(array_values(array_unique($clean))));
        IPS_ApplyChanges($this->InstanceID);
    }

    private function BuildGroupFilterBarHtml(array $groups): string
    {
        $visibleKeys = array_fill_keys($this->GetVisibleGraphGroupKeys($groups), true);

        $parts = [];
        $parts[] = '<div id="group-filter-bar" style="margin-bottom:12px;padding:10px 12px;border:1px solid #333;border-radius:8px;background:#252526;">';
        $parts[] = '<a href="#" id="show-all-groups" style="color:#9ecbff;margin-right:18px;text-decoration:underline;">All</a>';
        $parts[] = '<a href="#" id="show-no-groups" style="color:#9ecbff;margin-right:18px;text-decoration:underline;">None</a>';

        foreach ($groups as $group) {
            $groupKey = trim((string) ($group['GroupKey'] ?? ''));
            $groupLabel = trim((string) ($group['GroupLabel'] ?? $groupKey));
            if ($groupKey === '') {
                continue;
            }

            $checked = isset($visibleKeys[$groupKey]) ? ' checked' : '';
            $parts[] = '<label style="margin-right:18px;white-space:nowrap;">'
                . '<input type="checkbox" class="group-toggle" value="' . htmlspecialchars($groupKey, ENT_QUOTES, 'UTF-8') . '"' . $checked . '> '
                . htmlspecialchars($groupLabel, ENT_QUOTES, 'UTF-8')
                . '</label>';
        }

        $parts[] = '</div>';

        return implode('', $parts);
    }

    private function RegisterHook($WebHook)
    {
        $ids = IPS_GetInstanceListByModuleID('{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}');
        if (count($ids) > 0) {
            $hooks = json_decode(IPS_GetProperty($ids[0], 'Hooks'), true) ?: [];
            $found = false;

            foreach ($hooks as $index => $hook) {
                if (($hook['Hook'] ?? '') == $WebHook) {
                    if ((int) ($hook['TargetID'] ?? 0) == $this->InstanceID) {
                        return;
                    }
                    $hooks[$index]['TargetID'] = $this->InstanceID;
                    $found = true;
                }
            }

            if (!$found) {
                $hooks[] = ['Hook' => $WebHook, 'TargetID' => $this->InstanceID];
            }

            IPS_SetProperty($ids[0], 'Hooks', json_encode($hooks));
            IPS_ApplyChanges($ids[0]);
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

            case 'ReceivePayload':
                $this->ReceivePayload((string) $Value);
                break;

            case 'ReceiveHouseStateSnapshot':
                $this->ReceiveHouseStateSnapshot((string) $Value);
                break;

            case 'ExportConfiguration':
                try {
                    $this->ExportConfiguration();
                } catch (Throwable $e) {
                    $this->LogMessage('ExportConfiguration failed: ' . $e->getMessage(), KL_MESSAGE);
                    throw $e;
                }
                break;

            case 'ImportConfiguration':
                try {
                    $this->ImportConfiguration();
                } catch (Throwable $e) {
                    $this->LogMessage('ImportConfiguration failed: ' . $e->getMessage(), KL_MESSAGE);
                    throw $e;
                }
                break;

            default:
                throw new Exception('Invalid Ident');
        }
    }

    private function BuildOutputScreenEntryHtml(array $resource, array $payload, array $house, string $groupLabel): string
    {
        $eventTimestamp = (int) ($payload['timestamp'] ?? time());
        if ($eventTimestamp <= 0) {
            $eventTimestamp = time();
        }

        $dateTimeText = date('Y-m-d H:i:s', $eventTimestamp);

        $stateID = (string) ((int) ($house['system_state_id'] ?? 0));
        $stateLabel = $this->labelFromOptions(self::HOUSE_STATES, $stateID);
        $houseStateText = $stateLabel !== '' ? $stateLabel . ' [' . $stateID . ']' : $stateID;

        $outputName = trim((string) ($resource['Name'] ?? ''));
        if ($outputName === '') {
            $outputName = trim((string) ($resource['OutputID'] ?? 'screen'));
        }

        $message = $this->BuildOutputMessageText($resource, $payload);

        $lines = [];
        $lines[] = '<div style="background:#252526;border:1px solid #444;border-radius:8px;padding:12px;margin-bottom:10px;">';
        $lines[] = '<div style="font-weight:bold;color:#4CAF50;margin-bottom:6px;">' . htmlspecialchars($dateTimeText, ENT_QUOTES, 'UTF-8') . '</div>';
        $lines[] = '<div><b>House State:</b> ' . htmlspecialchars($houseStateText, ENT_QUOTES, 'UTF-8') . '</div>';
        $lines[] = '<div><b>Group:</b> ' . htmlspecialchars($groupLabel, ENT_QUOTES, 'UTF-8') . '</div>';
        $lines[] = '<div><b>Output:</b> ' . htmlspecialchars($outputName, ENT_QUOTES, 'UTF-8') . '</div>';
        $lines[] = '<div><b>Message:</b> ' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</div>';
        $lines[] = '</div>';

        return implode('', $lines);
    }


    private function WriteScreenOutputResource(array $resource, array $payload, array $house, string $groupLabel): bool
    {
        $varID = @$this->GetIDForIdent('OutputScreenHtml');
        if ($varID === false || $varID <= 0) {
            $this->LogMessage('WriteScreenOutputResource: OutputScreenHtml variable not found', KL_MESSAGE);
            return false;
        }

        $entryHtml = $this->BuildOutputScreenEntryHtml($resource, $payload, $house, $groupLabel);
        $currentHtml = GetValueString($varID);

        $entries = $this->ExtractOutputScreenEntries($currentHtml);
        array_unshift($entries, $entryHtml);

        $maxEntries = (int) $this->ReadPropertyInteger('ScreenLogMaxEntries');
        if ($maxEntries < 1) {
            $maxEntries = 1;
        }

        $entries = array_slice($entries, 0, $maxEntries);

        SetValueString($varID, $this->BuildOutputScreenWrapper($entries));
        $this->LogMessage('WriteScreenOutputResource: screen entry added', KL_MESSAGE);

        return true;
    }


    private function BuildOutputScreenWrapper(array $entries): string
    {
        $body = '';
        if (count($entries) === 0) {
            $body = '<div style="color:#999;">No screen output entries yet.</div>';
        } else {
            $body = implode('<!--ENTRY-->', $entries);
        }

        return '<div style="background:#1e1e1e;color:#cfcfcf;font-family:Segoe UI,sans-serif;padding:10px;">'
            . '<div id="m3-screen-entries">' . $body . '</div>'
            . '</div>';
    }

    private function ExtractOutputScreenEntries(string $html): array
    {
        $html = trim($html);
        if ($html === '') {
            return [];
        }

        if (preg_match('~<div id="m3-screen-entries">(.*)</div>\s*</div>\s*$~s', $html, $matches) !== 1) {
            return [];
        }

        $entriesHtml = (string) ($matches[1] ?? '');
        if ($entriesHtml === '') {
            return [];
        }

        $parts = explode('<!--ENTRY-->', $entriesHtml);
        $entries = [];

        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '') {
                $entries[] = $part;
            }
        }

        return $entries;
    }


    private function EnsureListRowIDsPersisted(string $propertyName, string $idField, string $prefix): array
    {
        $rows = $this->readListProperty($propertyName);
        $changed = false;

        foreach ($rows as &$row) {
            if (!is_array($row)) {
                continue;
            }

            $current = trim((string) ($row[$idField] ?? ''));
            if ($current !== '') {
                continue;
            }

            $row[$idField] = $this->GenerateTechnicalID($prefix);
            $changed = true;
        }
        unset($row);

        if ($changed) {
            IPS_SetProperty($this->InstanceID, $propertyName, json_encode(array_values($rows)));
            IPS_ApplyChanges($this->InstanceID);
        }

        return $rows;
    }

    public function ImportConfiguration(): void
    {
        $raw = $this->ReadPropertyString('ConfigBackupJson');
        if (trim($raw) === '') {
            throw new Exception('ConfigBackupJson is empty.');
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new Exception('ConfigBackupJson does not contain valid JSON.');
        }

        $this->ValidateImportConfiguration($data);

        $config = $data['config'];

        $outputResources = $config['OutputResources'];
        $groupStateRules = $config['GroupStateRules'];
        $ruleOutputAssignments = $config['RuleOutputAssignments'];
        $assignmentMatrixConfig = $config['AssignmentMatrixConfig'] ?? [];

        IPS_SetProperty($this->InstanceID, 'OutputResources', json_encode(array_values($outputResources)));
        IPS_SetProperty($this->InstanceID, 'GroupStateRules', json_encode(array_values($groupStateRules)));
        IPS_SetProperty($this->InstanceID, 'RuleOutputAssignments', json_encode(array_values($ruleOutputAssignments)));
        IPS_SetProperty($this->InstanceID, 'AssignmentMatrixConfig', json_encode(array_values($assignmentMatrixConfig)));
        IPS_ApplyChanges($this->InstanceID);

        $this->SetStatus(205);
        $this->LogMessage('ImportConfiguration: backup JSON imported from ConfigBackupJson', KL_MESSAGE);
    }


    private function ValidateImportConfiguration(array $data): void
    {
        if (($data['schema'] ?? '') !== 'ARMM.ConfigBackup.v1') {
            throw new Exception('Unsupported backup schema.');
        }

        $config = $data['config'] ?? null;
        if (!is_array($config)) {
            throw new Exception('Backup JSON is missing config block.');
        }

        foreach (['OutputResources', 'GroupStateRules', 'RuleOutputAssignments'] as $key) {
            if (!array_key_exists($key, $config)) {
                throw new Exception('Backup JSON is missing ' . $key . '.');
            }
            if (!is_array($config[$key])) {
                throw new Exception('Backup JSON field ' . $key . ' must be an array.');
            }
        }

        if (array_key_exists('AssignmentMatrixConfig', $config) && !is_array($config['AssignmentMatrixConfig'])) {
            throw new Exception('Backup JSON field AssignmentMatrixConfig must be an array.');
        }

        foreach ($config['OutputResources'] as $index => $row) {
            if (!is_array($row)) {
                throw new Exception('OutputResources row ' . $index . ' is invalid.');
            }
        }

        foreach ($config['GroupStateRules'] as $index => $row) {
            if (!is_array($row)) {
                throw new Exception('GroupStateRules row ' . $index . ' is invalid.');
            }
        }

        foreach ($config['RuleOutputAssignments'] as $index => $row) {
            if (!is_array($row)) {
                throw new Exception('RuleOutputAssignments row ' . $index . ' is invalid.');
            }
        }

        if (array_key_exists('AssignmentMatrixConfig', $config)) {
            foreach ($config['AssignmentMatrixConfig'] as $index => $row) {
                if (!is_array($row)) {
                    throw new Exception('AssignmentMatrixConfig row ' . $index . ' is invalid.');
                }
            }
        }
    }

    public function ExportConfiguration(): void
    {
        $export = [
            'schema' => 'ARMM.ConfigBackup.v1',
            'module' => 'ARMResponseManagerMock',
            'exported_at' => time(),
            'instance_id' => $this->InstanceID,
            'target_name' => $this->GetModule1TargetDisplayName(),
            'config' => [
                'OutputResources'        => $this->readListProperty('OutputResources'),
                'GroupStateRules'        => $this->readListProperty('GroupStateRules'),
                'RuleOutputAssignments'  => $this->readListProperty('RuleOutputAssignments'),
                'AssignmentMatrixConfig' => $this->readListProperty('AssignmentMatrixConfig')
            ]
        ];

        IPS_SetProperty($this->InstanceID, 'ConfigBackupJson', json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        IPS_ApplyChanges($this->InstanceID);
        $this->SetStatus(204);
        $this->LogMessage('ExportConfiguration: backup JSON written to ConfigBackupJson', KL_MESSAGE);
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

        $outputResources = $this->EnsureListRowIDsPersisted('OutputResources', 'OutputID', 'out_');
        $flatGroupStateRules = $this->EnsureListRowIDsPersisted('GroupStateRules', 'RuleID', 'rule_');
        $groupStateRules = $this->GetEffectiveGroupStateRules();
        $groupStateRules = $this->EnsureEffectiveRuleIDs($groupStateRules);
        $this->PersistEffectiveRulesToLegacyProperty($groupStateRules);

        $groupOptions = $this->buildGroupOptions($importedGroups, $groupStateRules);
        $groupLabels = $this->buildGroupLabels($importedGroups, $groupStateRules);

        $typeOptions = $this->buildTypeOptions($outputTypes, $outputResources);
        $typeLabels = $this->buildTypeLabels($outputTypes, $outputResources);

        $this->setListColumnOptions($form, 'OutputResources', 'TypeID', $typeOptions);
        $this->setListFormFieldOptions($form, 'OutputResources', 'TypeID', $typeOptions);

        $resourceValues = [];
        foreach ($outputResources as $row) {
            $outputID = trim((string) ($row['OutputID'] ?? ''));
            $typeID = trim((string) ($row['TypeID'] ?? ''));

            $resourceValues[] = [
                'Active'             => (bool) ($row['Active'] ?? true),
                'OutputID'           => $outputID,
                'Name'               => (string) ($row['Name'] ?? ''),
                'TypeID'             => $typeID,
                'TargetObjectID'     => (int) ($row['TargetObjectID'] ?? 0),
                'MaxMessages'        => (int) ($row['MaxMessages'] ?? 1),
                'PerSeconds'         => (int) ($row['PerSeconds'] ?? 60),
                'PrefixText'         => (string) ($row['PrefixText'] ?? ''),
                'UseSensorName'      => (bool) ($row['UseSensorName'] ?? true),
                'UseParentName'      => (bool) ($row['UseParentName'] ?? false),
                'UseGrandparentName' => (bool) ($row['UseGrandparentName'] ?? false),
                'UseContent'         => (bool) ($row['UseContent'] ?? false),
                'SuffixText'         => (string) ($row['SuffixText'] ?? ''),
                'EmailAddress'       => (string) ($row['EmailAddress'] ?? ''),
                'PhoneNumber'        => (string) ($row['PhoneNumber'] ?? ''),
                'Volume'             => (string) ($row['Volume'] ?? ''),
                'TypeLabel'          => $typeLabels[$typeID] ?? '[missing type]',
                'RowSummary'         => $this->buildOutputSummary($row, $typeLabels)
            ];
        }
        $this->setListValues($form, 'OutputResources', $resourceValues);

        $ruleMatrixValues = $this->BuildGroupStateMatrixPropertyValues($importedGroups, $flatGroupStateRules);
        $this->setListValues($form, 'GroupStateMatrixConfig', $ruleMatrixValues);

        $assignmentMatrixValues = $this->BuildAssignmentMatrixPropertyValues($importedGroups, $groupStateRules);
        $this->setListValues($form, 'AssignmentMatrixConfig', $assignmentMatrixValues);

        return json_encode($form);
    }



    private function BuildGroupStateMatrixPropertyValues(array $importedGroups, array $flatGroupStateRules): array
    {
        $stored = $this->readListProperty('GroupStateMatrixConfig');
        if (count($stored) > 0) {
            return $this->NormalizeGroupStateMatrixRows($stored, $importedGroups);
        }

        return $this->BuildGroupStateMatrixFromFlatRules($importedGroups, $flatGroupStateRules);
    }

    private function NormalizeGroupStateMatrixRow(array $row): array
    {
        foreach (['0', '2', '3', '6', '9'] as $state) {
            $row['S_' . $state . '_Active'] = (bool) ($row['S_' . $state . '_Active'] ?? false);
            $row['S_' . $state . '_Severity'] = trim((string) ($row['S_' . $state . '_Severity'] ?? ''));
        }

        $row['All_On'] = (bool) ($row['All_On'] ?? false);
        $row['All_Severity'] = trim((string) ($row['All_Severity'] ?? ''));

        if ($row['All_On']) {
            foreach (['0', '2', '3', '6', '9'] as $state) {
                $row['S_' . $state . '_Active'] = true;
            }
        }

        if ($row['All_Severity'] !== '') {
            foreach (['0', '2', '3', '6', '9'] as $state) {
                $row['S_' . $state . '_Severity'] = $row['All_Severity'];
            }
        }

        $allOn = true;
        $firstSeverity = null;
        $sameSeverity = true;

        foreach (['0', '2', '3', '6', '9'] as $state) {
            if (!$row['S_' . $state . '_Active']) {
                $allOn = false;
            }

            if ($firstSeverity === null) {
                $firstSeverity = $row['S_' . $state . '_Severity'];
            } elseif ($row['S_' . $state . '_Severity'] !== $firstSeverity) {
                $sameSeverity = false;
            }
        }

        $row['All_On'] = $allOn;
        $row['All_Severity'] = $sameSeverity ? (string) $firstSeverity : '';

        return $row;
    }

    private function BuildGroupStateMatrixFromFlatRules(array $importedGroups, array $flatGroupStateRules): array
    {
        $ruleMap = [];
        foreach ($flatGroupStateRules as $row) {
            if (!is_array($row)) {
                continue;
            }

            $groupKey = trim((string) ($row['GroupKey'] ?? ''));
            $houseState = trim((string) ($row['HouseState'] ?? ''));
            if ($groupKey === '' || $houseState === '') {
                continue;
            }

            $ruleMap[$groupKey . '|' . $houseState] = [
                'Active'   => (bool) ($row['Active'] ?? true),
                'Severity' => trim((string) ($row['Severity'] ?? ''))
            ];
        }

        $rows = [];
        foreach ($importedGroups as $group) {
            $groupKey = trim((string) ($group['GroupKey'] ?? ''));
            $groupLabel = trim((string) ($group['GroupLabel'] ?? $groupKey));
            if ($groupKey === '') {
                continue;
            }

            $row = [
                'GroupKey'     => $groupKey,
                'GroupLabel'   => $groupLabel,
                'All_On'       => false,
                'All_Severity' => ''
            ];

            foreach (['0', '2', '3', '6', '9'] as $state) {
                $cell = $ruleMap[$groupKey . '|' . $state] ?? ['Active' => false, 'Severity' => ''];
                $row['S_' . $state . '_Active'] = (bool) ($cell['Active'] ?? false);
                $row['S_' . $state . '_Severity'] = trim((string) ($cell['Severity'] ?? ''));
            }

            $rows[] = $this->NormalizeGroupStateMatrixRow($row);
        }

        return $rows;
    }

    private function NormalizeGroupStateMatrixRows(array $rows, array $importedGroups): array
    {
        $validGroups = [];
        foreach ($importedGroups as $group) {
            $groupKey = trim((string) ($group['GroupKey'] ?? ''));
            $groupLabel = trim((string) ($group['GroupLabel'] ?? $groupKey));
            if ($groupKey !== '') {
                $validGroups[$groupKey] = $groupLabel;
            }
        }

        $normalized = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $groupKey = trim((string) ($row['GroupKey'] ?? ''));
            if ($groupKey === '' || !isset($validGroups[$groupKey])) {
                continue;
            }

            $row['GroupLabel'] = $validGroups[$groupKey];
            $normalized[] = $this->NormalizeGroupStateMatrixRow($row);
        }

        return array_values($normalized);
    }

    private function GetEffectiveGroupStateRules(): array
    {
        $matrixRows = $this->readListProperty('GroupStateMatrixConfig');
        if (count($matrixRows) === 0) {
            return $this->readListProperty('GroupStateRules');
        }

        $importedGroups = $this->ExtractImportedGroupsFromConfig();
        $matrixRows = $this->NormalizeGroupStateMatrixRows($matrixRows, $importedGroups);

        $result = [];
        foreach ($matrixRows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $groupKey = trim((string) ($row['GroupKey'] ?? ''));
            if ($groupKey === '') {
                continue;
            }

            foreach (['0', '2', '3', '6', '9'] as $state) {
                $active = (bool) ($row['S_' . $state . '_Active'] ?? false);
                $severity = trim((string) ($row['S_' . $state . '_Severity'] ?? ''));

                $result[] = [
                    'Active'     => $active,
                    'RuleID'     => 'rulemat_' . substr(md5($groupKey . '|' . $state), 0, 12),
                    'GroupKey'   => $groupKey,
                    'HouseState' => $state,
                    'Severity'   => $severity
                ];
            }
        }

        return $result;
    }

    private function EnsureEffectiveRuleIDs(array $rules): array
    {
        foreach ($rules as &$row) {
            if (!is_array($row)) {
                continue;
            }

            $ruleID = trim((string) ($row['RuleID'] ?? ''));
            $groupKey = trim((string) ($row['GroupKey'] ?? ''));
            $houseState = trim((string) ($row['HouseState'] ?? ''));

            if ($ruleID === '' && $groupKey !== '' && $houseState !== '') {
                $row['RuleID'] = 'rulemat_' . substr(md5($groupKey . '|' . $houseState), 0, 12);
            }
        }
        unset($row);

        return $rules;
    }

    private function PersistEffectiveRulesToLegacyProperty(array $rules): void
    {
        $currentRaw = $this->ReadPropertyString('GroupStateRules');
        $newRaw = json_encode(array_values($rules));

        if ($currentRaw === $newRaw) {
            return;
        }

        IPS_SetProperty($this->InstanceID, 'GroupStateRules', $newRaw);
        IPS_ApplyChanges($this->InstanceID);
    }


    private function BuildAssignmentMatrixPropertyValues(array $importedGroups, array $groupStateRules): array
    {
        $baseRows = $this->BuildAssignmentMatrixFromFlatAssignments($importedGroups, $groupStateRules);
        $storedRows = $this->readListProperty('AssignmentMatrixConfig');

        if (count($storedRows) === 0) {
            return $baseRows;
        }

        $storedRows = $this->NormalizeAssignmentMatrixRows($storedRows, $importedGroups);

        $storedMap = [];
        foreach ($storedRows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $outputID = trim((string) ($row['OutputID'] ?? ''));
            $groupKey = trim((string) ($row['GroupKey'] ?? ''));
            if ($outputID === '' || $groupKey === '') {
                continue;
            }

            $storedMap[$outputID . '|' . $groupKey] = $row;
        }

        $merged = [];
        foreach ($baseRows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $outputID = trim((string) ($row['OutputID'] ?? ''));
            $groupKey = trim((string) ($row['GroupKey'] ?? ''));
            $key = $outputID . '|' . $groupKey;

            if (isset($storedMap[$key])) {
                $stored = $storedMap[$key];

                $row['HS_0'] = (bool) ($stored['HS_0'] ?? false);
                $row['HS_2'] = (bool) ($stored['HS_2'] ?? false);
                $row['HS_3'] = (bool) ($stored['HS_3'] ?? false);
                $row['HS_6'] = (bool) ($stored['HS_6'] ?? false);
                $row['HS_9'] = (bool) ($stored['HS_9'] ?? false);
                $row['AllStates'] = (bool) ($stored['AllStates'] ?? false);
                $row['NoneStates'] = (bool) ($stored['NoneStates'] ?? false);

                $row = $this->NormalizeAssignmentMatrixRow($row);
            }

            $merged[] = $row;
        }

        return array_values($merged);
    }

    private function BuildAssignmentMatrixFromFlatAssignments(array $importedGroups, array $groupStateRules): array
    {
        $existingAssignments = $this->readListProperty('RuleOutputAssignments');
        $assignedByOutputAndRule = [];

        foreach ($existingAssignments as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (!(bool) ($row['Active'] ?? true)) {
                continue;
            }

            $outputID = trim((string) ($row['OutputID'] ?? ''));
            $ruleID = trim((string) ($row['RuleID'] ?? ''));
            if ($outputID === '' || $ruleID === '') {
                continue;
            }

            $assignedByOutputAndRule[$outputID][$ruleID] = true;
        }

        $ruleMap = $this->BuildGroupStateRuleMap($groupStateRules);

        $rows = [];
        foreach ($importedGroups as $group) {
            $groupKey = trim((string) ($group['GroupKey'] ?? ''));
            $groupLabel = trim((string) ($group['GroupLabel'] ?? $groupKey));
            if ($groupKey === '') {
                continue;
            }

            foreach ($this->readListProperty('OutputResources') as $outputRow) {
                if (!is_array($outputRow)) {
                    continue;
                }

                $outputID = trim((string) ($outputRow['OutputID'] ?? ''));
                if ($outputID === '') {
                    continue;
                }

                $row = [
                    'OutputID'    => $outputID,
                    'OutputLabel' => trim((string) ($outputRow['Name'] ?? $outputID)),
                    'GroupKey'    => $groupKey,
                    'GroupLabel'  => $groupLabel,
                    'AllStates'   => false,
                    'NoneStates'  => false,
                    'HS_0'        => false,
                    'HS_2'        => false,
                    'HS_3'        => false,
                    'HS_6'        => false,
                    'HS_9'        => false
                ];

                foreach (['0', '2', '3', '6', '9'] as $state) {
                    $ruleID = $ruleMap[$groupKey . '|' . $state] ?? '';
                    if ($ruleID !== '' && isset($assignedByOutputAndRule[$outputID][$ruleID])) {
                        $row['HS_' . $state] = true;
                    }
                }

                $rows[] = $this->NormalizeAssignmentMatrixRow($row);
            }
        }

        return $rows;
    }

    private function NormalizeAssignmentMatrixRows(array $rows, array $importedGroups): array
    {
        $validGroups = [];
        foreach ($importedGroups as $group) {
            $groupKey = trim((string) ($group['GroupKey'] ?? ''));
            $groupLabel = trim((string) ($group['GroupLabel'] ?? $groupKey));
            if ($groupKey !== '') {
                $validGroups[$groupKey] = $groupLabel;
            }
        }

        $validOutputs = [];
        foreach ($this->readListProperty('OutputResources') as $row) {
            if (!is_array($row)) {
                continue;
            }

            $outputID = trim((string) ($row['OutputID'] ?? ''));
            if ($outputID === '') {
                continue;
            }

            $validOutputs[$outputID] = trim((string) ($row['Name'] ?? $outputID));
        }

        $normalized = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $outputID = trim((string) ($row['OutputID'] ?? ''));
            $groupKey = trim((string) ($row['GroupKey'] ?? ''));
            if ($outputID === '' || $groupKey === '') {
                continue;
            }

            if (!isset($validOutputs[$outputID]) || !isset($validGroups[$groupKey])) {
                continue;
            }

            $row['OutputLabel'] = $validOutputs[$outputID];
            $row['GroupLabel'] = $validGroups[$groupKey];
            $normalized[] = $this->NormalizeAssignmentMatrixRow($row);
        }

        return array_values($normalized);
    }

    private function NormalizeAssignmentMatrixRow(array $row): array
    {
        foreach (['HS_0', 'HS_2', 'HS_3', 'HS_6', 'HS_9', 'AllStates', 'NoneStates'] as $key) {
            $row[$key] = (bool) ($row[$key] ?? false);
        }

        if ($row['AllStates']) {
            $row['HS_0'] = true;
            $row['HS_2'] = true;
            $row['HS_3'] = true;
            $row['HS_6'] = true;
            $row['HS_9'] = true;
            $row['NoneStates'] = false;
        } elseif ($row['NoneStates']) {
            $row['HS_0'] = false;
            $row['HS_2'] = false;
            $row['HS_3'] = false;
            $row['HS_6'] = false;
            $row['HS_9'] = false;
            $row['AllStates'] = false;
        } else {
            $all = $row['HS_0'] && $row['HS_2'] && $row['HS_3'] && $row['HS_6'] && $row['HS_9'];
            $none = !$row['HS_0'] && !$row['HS_2'] && !$row['HS_3'] && !$row['HS_6'] && !$row['HS_9'];
            $row['AllStates'] = $all;
            $row['NoneStates'] = $none;
        }

        return $row;
    }

    private function BuildGroupStateRuleMap(array $groupStateRules): array
    {
        $ruleMap = [];
        foreach ($groupStateRules as $row) {
            if (!is_array($row)) {
                continue;
            }

            $groupKey = trim((string) ($row['GroupKey'] ?? ''));
            $houseState = trim((string) ($row['HouseState'] ?? ''));
            $ruleID = trim((string) ($row['RuleID'] ?? ''));

            if ($groupKey === '' || $houseState === '' || $ruleID === '') {
                continue;
            }

            $ruleMap[$groupKey . '|' . $houseState] = $ruleID;
        }

        return $ruleMap;
    }

    private function GetEffectiveRuleOutputAssignments(): array
    {
        $matrixRows = $this->readListProperty('AssignmentMatrixConfig');
        if (count($matrixRows) === 0) {
            return $this->readListProperty('RuleOutputAssignments');
        }

        $groupStateRules = $this->readListProperty('GroupStateRules');
        $ruleMap = $this->BuildGroupStateRuleMap($groupStateRules);

        $result = [];
        foreach ($matrixRows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $row = $this->NormalizeAssignmentMatrixRow($row);

            $outputID = trim((string) ($row['OutputID'] ?? ''));
            $groupKey = trim((string) ($row['GroupKey'] ?? ''));
            if ($outputID === '' || $groupKey === '') {
                continue;
            }

            foreach (['0', '2', '3', '6', '9'] as $state) {
                if (!(bool) ($row['HS_' . $state] ?? false)) {
                    continue;
                }

                $ruleID = $ruleMap[$groupKey . '|' . $state] ?? '';
                if ($ruleID === '') {
                    continue;
                }

                $result[] = [
                    'Active'       => true,
                    'AssignmentID' => 'asgmat_' . substr(md5($outputID . '|' . $groupKey . '|' . $state), 0, 12),
                    'RuleID'       => $ruleID,
                    'OutputID'     => $outputID
                ];
            }
        }

        return $result;
    }

    protected function ProcessHookData()
    {
        // 1. Authentication (Secrets / Vault)
        $vaultID = $this->ReadPropertyInteger('VaultInstanceID');
        if ($vaultID > 0 && @IPS_InstanceExists($vaultID)) {
            if (function_exists('SEC_IsPortalAuthenticated')) {
                if (!SEC_IsPortalAuthenticated($vaultID)) {
                    $currentUrl = $_SERVER['REQUEST_URI'] ?? '';
                    $currentUrl = strtok($currentUrl, '?');
                    $loginUrl = '/hook/secrets_' . $vaultID . '?portal=1&return=' . urlencode($currentUrl);
                    header('Location: ' . $loginUrl);
                    exit;
                }
            }
        }

        $groups = $this->ExtractImportedGroupsFromConfig();

        // 2. Graph filter save API
        if (isset($_GET['filter_api'])) {
            $selected = [];
            $raw = file_get_contents('php://input');
            $data = json_decode((string) $raw, true);
            if (is_array($data) && isset($data['visible_groups']) && is_array($data['visible_groups'])) {
                $selected = $data['visible_groups'];
            }

            $this->SaveVisibleGraphGroupKeys($selected, $groups);

            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => true]);
            return;
        }

        // 3. Screen API mode
        if (isset($_GET['screen_api'])) {
            header('Content-Type: text/html; charset=utf-8');
            echo $this->GetOutputScreenHtml();
            return;
        }

        // 4. Screen page mode
        if (isset($_GET['screen'])) {
            header('Content-Type: text/html; charset=utf-8');
            echo '<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Module 3 Output Screen</title>
<style>
body{
    background-color:#1e1e1e;color:#cfcfcf;font-family:"Segoe UI",sans-serif;
    margin:0;padding:20px;box-sizing:border-box;
}
.header{margin-bottom:16px;border-bottom:1px solid #333;padding-bottom:10px;}
.header h2{margin:0;color:#4CAF50;}
#screen-container{margin-top:16px;}
</style>
<script>
async function refreshScreen() {
    try {
        const response = await fetch("?screen_api=1&t=" + Date.now(), { credentials: "same-origin" });
        const html = await response.text();
        document.getElementById("screen-container").innerHTML = html;
    } catch (err) {
        console.error("Screen refresh failed", err);
    }
}
refreshScreen();
setInterval(refreshScreen, 2000);
</script>
</head>
<body>
<div class="header">
    <h2>' . htmlspecialchars($this->GetModule1TargetDisplayName(), ENT_QUOTES, 'UTF-8') . ' (Output Screen)</h2>
    <small>Instance ID: ' . $this->InstanceID . '</small>
</div>
<div id="screen-container">' . $this->GetOutputScreenHtml() . '</div>
</body>
</html>';
            return;
        }

        // 5. Mermaid graph API
        if (isset($_GET['api'])) {
            header('Content-Type: text/plain; charset=utf-8');
            echo $this->BuildMappingGraph();
            return;
        }

        // 6. Full Mermaid page
        header('Content-Type: text/html; charset=utf-8');

        $filterBarHtml = $this->BuildGroupFilterBarHtml($groups);

        echo '<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Module 3 Mapping</title>
<style>
body{
    background-color:#1e1e1e;color:#cfcfcf;font-family:"Segoe UI",sans-serif;
    margin:0;padding:20px;height:100vh;box-sizing:border-box;
    overflow:hidden;display:flex;flex-direction:column;
}
.header{flex-shrink:0;text-align:center;margin-bottom:12px;border-bottom:1px solid #333;padding-bottom:10px;}
.header h2{margin:0;color:#4CAF50;}
.container{
    flex-grow:1;background:#252526;border-radius:8px;width:100%;
    border:1px solid #444;overflow:hidden;position:relative;
}
#mermaid-container { position:absolute; inset:0; overflow:hidden; }
#mermaid-container svg { width:100% !important; height:100% !important; max-width:none !important; display:block; }
</style>

<script src="https://unpkg.com/svg-pan-zoom@3.6.1/dist/svg-pan-zoom.min.js"></script>

<script type="module">
import mermaid from "https://cdn.jsdelivr.net/npm/mermaid@10/dist/mermaid.esm.min.mjs";

mermaid.initialize({
    startOnLoad:false,
    theme:"dark",
    flowchart:{ curve:"basis", nodeSpacing:60, rankSpacing:120 }
});

let isRendering = false;
let lastGraphString = "";
let pzInstance = null;

async function saveGroupFilter() {
    const selected = Array.from(document.querySelectorAll(".group-toggle:checked")).map(cb => cb.value);

    await fetch("?filter_api=1&t=" + Date.now(), {
        method: "POST",
        credentials: "same-origin",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ visible_groups: selected })
    });
}

async function fetchAndUpdateGraph() {
    if (isRendering) return;

    try {
        const response = await fetch("?api=1&t=" + Date.now(), { credentials: "same-origin" });
        const graphString = await response.text();

        if (!graphString.trim().startsWith("graph ")) return;

        if (graphString !== lastGraphString) {
            isRendering = true;

            const container = document.getElementById("mermaid-container");
            const pzContainer = document.querySelector(".container");
            const oldZoom = pzInstance ? pzInstance.getZoom() : null;
            const oldPan  = pzInstance ? pzInstance.getPan()  : null;

            if (pzInstance) pzInstance.destroy();

            const renderId = "graph_" + Date.now();
            const { svg } = await mermaid.render(renderId, graphString);
            container.innerHTML = svg;

            const svgEl = container.querySelector("svg");
            if (!svgEl) {
                throw new Error("Mermaid render returned no SVG");
            }

            svgEl.removeAttribute("width");
            svgEl.removeAttribute("height");
            svgEl.style.width = "100%";
            svgEl.style.height = "100%";
            svgEl.style.maxWidth = "none";

            pzInstance = svgPanZoom(svgEl, {
                zoomEnabled: true,
                controlIconsEnabled: true,
                fit: (oldZoom === null),
                center: (oldPan === null),
                minZoom: 0.2,
                maxZoom: 10,
                eventsListenerElement: pzContainer
            });

            if (oldZoom !== null) {
                pzInstance.zoom(oldZoom);
                pzInstance.pan(oldPan);
            }

            lastGraphString = graphString;
        }
    } catch (err) {
        console.error("Rendering failed", err);
    } finally {
        isRendering = false;
    }
}

async function applyFilterAndRefresh() {
    await saveGroupFilter();
    lastGraphString = "";
    await fetchAndUpdateGraph();
}

document.addEventListener("DOMContentLoaded", () => {
    document.querySelectorAll(".group-toggle").forEach(cb => {
        cb.addEventListener("change", applyFilterAndRefresh);
    });

    const allLink = document.getElementById("show-all-groups");
    if (allLink) {
        allLink.addEventListener("click", async (ev) => {
            ev.preventDefault();
            document.querySelectorAll(".group-toggle").forEach(cb => cb.checked = true);
            await applyFilterAndRefresh();
        });
    }

    const noneLink = document.getElementById("show-no-groups");
    if (noneLink) {
        noneLink.addEventListener("click", async (ev) => {
            ev.preventDefault();
            document.querySelectorAll(".group-toggle").forEach(cb => cb.checked = false);
            await applyFilterAndRefresh();
        });
    }

    fetchAndUpdateGraph();
    setInterval(fetchAndUpdateGraph, 2000);
});
</script>
</head>

<body>
<div class="header">
    <h2>' . htmlspecialchars($this->GetModule1TargetDisplayName(), ENT_QUOTES, 'UTF-8') . ' (Live)</h2>
    <small>Instance ID: ' . $this->InstanceID . '</small>
</div>
' . $filterBarHtml . '
<div class="container">
    <div id="mermaid-container">Initializing Live View...</div>
</div>
</body>
</html>';
    }

    private function GetOutputScreenHtml(): string
    {
        $varID = @$this->GetIDForIdent('OutputScreenHtml');
        if ($varID === false || $varID <= 0) {
            return $this->BuildOutputScreenWrapper([]);
        }

        $html = GetValueString($varID);
        if (trim($html) === '') {
            return $this->BuildOutputScreenWrapper([]);
        }

        return $html;
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
    // 
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

    private function GetModule1TargetDisplayName(): string
    {
        $json = $this->ReadPropertyString('ImportedModule1ConfigJson');
        if (trim($json) === '') {
            return IPS_GetName($this->InstanceID);
        }

        $config = json_decode($json, true);
        if (!is_array($config)) {
            return IPS_GetName($this->InstanceID);
        }

        $targets = $config['DispatchTargets'] ?? [];
        if (!is_array($targets)) {
            return IPS_GetName($this->InstanceID);
        }

        foreach ($targets as $target) {
            if (!is_array($target)) {
                continue;
            }

            $instanceID = (int) ($target['InstanceID'] ?? 0);
            if ($instanceID !== $this->InstanceID) {
                continue;
            }

            $name = trim((string) ($target['Name'] ?? ''));
            if ($name !== '') {
                return $name;
            }
        }

        return IPS_GetName($this->InstanceID);
    }

    public function ReceivePayload(string $payloadJson): void
    {
        $this->WriteAttributeString('LastReceivedPayloadRaw', $payloadJson);
        $payload = json_decode($payloadJson, true);
        if (!is_array($payload)) {
            $this->LogMessage('ReceivePayload: invalid JSON payload', KL_MESSAGE);
            return;
        }

        $eventEpoch = (string) ($payload['event_epoch'] ?? '0');
        $eventSeq = (int) ($payload['event_seq'] ?? 0);
        $this->WriteAttributeString('LastEventEpoch', $eventEpoch);
        $this->WriteAttributeInteger('LastEventSeq', $eventSeq);

        $eventType = strtoupper(trim((string) ($payload['event_type'] ?? '')));
        $targetGroups = $payload['target_active_groups'] ?? [];
        $targetClasses = $payload['target_active_classes'] ?? [];
        $targetSensorDetails = $payload['target_active_sensor_details'] ?? [];

        $this->WriteAttributeString('CachedModule1PayloadRaw', $payloadJson);
        $this->WriteAttributeString('CachedTargetActiveGroups', json_encode(is_array($targetGroups) ? array_values($targetGroups) : []));
        $this->WriteAttributeString('CachedTargetActiveClasses', json_encode(is_array($targetClasses) ? array_values($targetClasses) : []));
        $this->WriteAttributeString('CachedTargetActiveSensorDetails', json_encode(is_array($targetSensorDetails) ? array_values($targetSensorDetails) : []));

        if ($eventType !== 'ALARM') {
            $this->LogMessage('ReceivePayload: ignored non-ALARM event_type=' . $eventType, KL_MESSAGE);
            return;
        }

        if (!is_array($targetGroups) || count($targetGroups) === 0) {
            $this->WriteAttributeString('CachedModule1PayloadRaw', $payloadJson);
            $this->WriteAttributeString('CachedTargetActiveGroups', '[]');
            $this->WriteAttributeString('CachedTargetActiveClasses', '[]');
            $this->WriteAttributeString('CachedTargetActiveSensorDetails', '[]');

            $this->WriteAttributeString('LastActiveGroups', '[]');
            $this->WriteAttributeString('LastActiveRuleIDs', '[]');
            $this->WriteAttributeString('LastActiveOutputIDs', '[]');
            $this->WriteAttributeString('ActiveOutputMatchKeys', '[]');
            $this->LogMessage('ReceivePayload: reset/all-clear detected, live path and active output matches cleared', KL_MESSAGE);
            return;
        }

        $house = $this->GetUsableHouseStateSnapshot($eventEpoch, $eventSeq);
        if ($house === null) {
            $this->LogMessage('ReceivePayload: no usable house snapshot available', KL_MESSAGE);
            return;
        }

        $this->ReevaluateCurrentAlarmContext($payload, $house);
    }
    public function DebugGetLastReceivedPayloadRaw(): string
    {
        return $this->ReadAttributeString('LastReceivedPayloadRaw');
    }


    public function DebugGetLastActiveGroups(): string
    {
        return $this->ReadAttributeString('LastActiveGroups');
    }

    public function DebugGetLastActiveRuleIDs(): string
    {
        return $this->ReadAttributeString('LastActiveRuleIDs');
    }

    public function DebugGetLastActiveOutputIDs(): string
    {
        return $this->ReadAttributeString('LastActiveOutputIDs');
    }


    private function ReevaluateCurrentAlarmContext(array $payload, array $house): void
    {
        $targetGroups = $payload['target_active_groups'] ?? [];
        if (!is_array($targetGroups) || count($targetGroups) === 0) {
            $this->WriteAttributeString('LastActiveGroups', '[]');
            $this->WriteAttributeString('LastActiveRuleIDs', '[]');
            $this->WriteAttributeString('LastActiveOutputIDs', '[]');
            $this->WriteAttributeString('ActiveOutputMatchKeys', '[]');
            $this->LogMessage('ReevaluateCurrentAlarmContext: no active target groups, live path and active output matches cleared', KL_MESSAGE);
            return;
        }

        $houseState = (string) ((int) ($house['system_state_id'] ?? 0));
        $this->LogMessage('ReevaluateCurrentAlarmContext: houseState=' . $houseState, KL_MESSAGE);

        $previousMatchKeys = $this->GetActiveOutputMatchKeySet();

        $currentMatchKeys = [];
        $activeGroupsForView = [];
        $activeRuleIDsForView = [];
        $activeOutputIDsForView = [];
        $candidatesByOutputID = [];
        $queueSeq = 0;

        foreach ($targetGroups as $groupLabelRaw) {
            $groupLabel = trim((string) $groupLabelRaw);
            if ($groupLabel === '') {
                continue;
            }

            $groupKey = $this->MakeGroupKey($groupLabel);
            $activeGroupsForView[$groupLabel] = true;

            $ruleIDs = $this->FindMatchingRuleIDsForGroupAndState($groupLabel, $houseState);
            $this->LogMessage('ReevaluateCurrentAlarmContext: group=' . $groupLabel . ' matched rules=' . json_encode($ruleIDs), KL_MESSAGE);

            foreach ($ruleIDs as $ruleID) {
                $activeRuleIDsForView[$ruleID] = true;

                $rule = $this->FindRuleByID($ruleID);
                $severity = trim((string) (($rule['Severity'] ?? '')));
                $priority = $this->GetSeverityPriority($severity);

                $assignments = $this->FindAssignmentsForRuleID($ruleID);

                foreach ($assignments as $assignment) {
                    $outputID = trim((string) ($assignment['OutputID'] ?? ''));
                    if ($outputID === '') {
                        continue;
                    }

                    $resource = $this->FindOutputResourceByID($outputID);
                    if ($resource === null) {
                        $this->LogMessage('ReevaluateCurrentAlarmContext: OutputID not found: ' . $outputID, KL_MESSAGE);
                        continue;
                    }

                    if (!(bool) ($resource['Active'] ?? false)) {
                        continue;
                    }

                    $matchKey = $this->BuildOutputMatchKey($groupKey, $outputID);
                    $currentMatchKeys[$matchKey] = true;
                    $activeOutputIDsForView[$outputID] = true;

                    if (isset($previousMatchKeys[$matchKey])) {
                        continue;
                    }

                    $queueSeq++;
                    $candidate = [
                        'MatchKey'   => $matchKey,
                        'OutputID'   => $outputID,
                        'GroupLabel' => $groupLabel,
                        'GroupKey'   => $groupKey,
                        'RuleID'     => $ruleID,
                        'Severity'   => $severity,
                        'Priority'   => $priority,
                        'QueueSeq'   => $queueSeq,
                        'Resource'   => $resource
                    ];

                    if (!isset($candidatesByOutputID[$outputID])) {
                        $candidatesByOutputID[$outputID] = [];
                    }

                    if (!isset($candidatesByOutputID[$outputID][$matchKey])) {
                        $candidatesByOutputID[$outputID][$matchKey] = $candidate;
                        continue;
                    }

                    $existing = $candidatesByOutputID[$outputID][$matchKey];
                    $replace = false;

                    if ($candidate['Priority'] > $existing['Priority']) {
                        $replace = true;
                    } elseif ($candidate['Priority'] === $existing['Priority'] && $candidate['QueueSeq'] > $existing['QueueSeq']) {
                        $replace = true;
                    }

                    if ($replace) {
                        $candidatesByOutputID[$outputID][$matchKey] = $candidate;
                    }
                }
            }
        }

        $now = time();

        foreach ($candidatesByOutputID as $outputID => $candidateMap) {
            $candidates = array_values($candidateMap);
            if (count($candidates) === 0) {
                continue;
            }

            usort($candidates, function (array $a, array $b): int {
                $pa = (int) ($a['Priority'] ?? 0);
                $pb = (int) ($b['Priority'] ?? 0);

                if ($pa !== $pb) {
                    return $pb <=> $pa;
                }

                $qa = (int) ($a['QueueSeq'] ?? 0);
                $qb = (int) ($b['QueueSeq'] ?? 0);

                return $qb <=> $qa;
            });

            $resource = $candidates[0]['Resource'] ?? null;
            if (!is_array($resource)) {
                continue;
            }

            $remainingSlots = $this->GetRemainingThrottleSlots($resource, $outputID, $now);
            $throttlingEnabled = $this->IsThrottlingEnabledForResource($resource);

            $this->LogMessage(
                'ReevaluateCurrentAlarmContext: OutputID=' . $outputID
                    . ' candidates=' . count($candidates)
                    . ' remainingSlots=' . ($throttlingEnabled ? (string) $remainingSlots : 'unlimited'),
                KL_MESSAGE
            );

            foreach ($candidates as $candidate) {
                $matchKey = (string) ($candidate['MatchKey'] ?? '');
                $groupLabel = (string) ($candidate['GroupLabel'] ?? '');
                $ruleID = (string) ($candidate['RuleID'] ?? '');
                $severity = (string) ($candidate['Severity'] ?? '');
                $resource = $candidate['Resource'] ?? null;

                if (!is_array($resource) || $matchKey === '' || $groupLabel === '') {
                    continue;
                }

                if ($throttlingEnabled && $remainingSlots <= 0) {
                    $this->LogMessage(
                        'ReevaluateCurrentAlarmContext: throttled OutputID=' . $outputID
                            . ' match=' . $matchKey
                            . ' rule=' . $ruleID
                            . ' severity=' . $severity,
                        KL_MESSAGE
                    );
                    continue;
                }

                $ok = $this->ExecuteOutputResource($resource, $payload, $house, $groupLabel);
                $this->LogMessage(
                    'ReevaluateCurrentAlarmContext: execute match=' . $matchKey
                        . ' OutputID=' . $outputID
                        . ' rule=' . $ruleID
                        . ' severity=' . $severity
                        . ' result=' . ($ok ? 'true' : 'false'),
                    KL_MESSAGE
                );

                if ($ok && $throttlingEnabled) {
                    $this->RegisterSuccessfulOutputSend($outputID, $now);
                    $remainingSlots--;
                }
            }
        }

        $this->WriteAttributeString('LastActiveGroups', json_encode(array_values(array_keys($activeGroupsForView))));
        $this->WriteAttributeString('LastActiveRuleIDs', json_encode(array_values(array_keys($activeRuleIDsForView))));
        $this->WriteAttributeString('LastActiveOutputIDs', json_encode(array_values(array_keys($activeOutputIDsForView))));
        $this->WriteAttributeString('ActiveOutputMatchKeys', json_encode(array_values(array_keys($currentMatchKeys))));

        $this->LogMessage(
            'ReevaluateCurrentAlarmContext: previousMatches=' . count($previousMatchKeys)
                . ' currentMatches=' . count($currentMatchKeys)
                . ' newCandidates=' . array_sum(array_map('count', $candidatesByOutputID)),
            KL_MESSAGE
        );
    }


    private function FindRuleByID(string $ruleID): ?array
    {
        $rows = $this->GetEffectiveGroupStateRules();

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            if ((string) ($row['RuleID'] ?? '') !== $ruleID) {
                continue;
            }

            return $row;
        }

        return null;
    }
    private function GetSeverityPriority(string $severity): int
    {
        switch (trim($severity)) {
            case 'Critical':
                return 400;

            case 'High':
                return 300;

            case 'Medium':
                return 200;

            case 'Low':
                return 100;

            default:
                return 0;
        }
    }

    private function IsThrottlingEnabledForResource(array $resource): bool
    {
        $maxMessages = (int) ($resource['MaxMessages'] ?? 0);
        $perSeconds = (int) ($resource['PerSeconds'] ?? 0);

        return ($maxMessages > 0) && ($perSeconds > 0);
    }

    private function GetRemainingThrottleSlots(array $resource, string $outputID, int $now): int
    {
        if (!$this->IsThrottlingEnabledForResource($resource)) {
            return PHP_INT_MAX;
        }

        $maxMessages = (int) ($resource['MaxMessages'] ?? 0);
        $perSeconds = (int) ($resource['PerSeconds'] ?? 0);

        $recentSends = $this->GetRecentSuccessfulSendTimestamps($outputID, $perSeconds, $now);
        $remaining = $maxMessages - count($recentSends);

        return max(0, $remaining);
    }

    private function GetRecentSuccessfulSendTimestamps(string $outputID, int $perSeconds, int $now): array
    {
        $history = $this->ReadOutputThrottleHistory();
        $entries = $history[$outputID] ?? [];

        if (!is_array($entries)) {
            return [];
        }

        $result = [];
        $cutoff = $now - $perSeconds;

        foreach ($entries as $timestampRaw) {
            $timestamp = (int) $timestampRaw;
            if ($timestamp > $cutoff) {
                $result[] = $timestamp;
            }
        }

        return $result;
    }

    private function RegisterSuccessfulOutputSend(string $outputID, int $timestamp): void
    {
        $history = $this->ReadOutputThrottleHistory();

        if (!isset($history[$outputID]) || !is_array($history[$outputID])) {
            $history[$outputID] = [];
        }

        $history[$outputID][] = $timestamp;

        foreach ($this->readListProperty('OutputResources') as $resource) {
            if (!is_array($resource)) {
                continue;
            }

            if ((string) ($resource['OutputID'] ?? '') !== $outputID) {
                continue;
            }

            $perSeconds = (int) ($resource['PerSeconds'] ?? 0);
            if ($perSeconds > 0) {
                $cutoff = $timestamp - $perSeconds;
                $history[$outputID] = array_values(array_filter(
                    $history[$outputID],
                    static function ($item) use ($cutoff): bool {
                        return (int) $item > $cutoff;
                    }
                ));
            }

            break;
        }

        $this->WriteOutputThrottleHistory($history);
    }

    private function ReadOutputThrottleHistory(): array
    {
        $raw = $this->ReadAttributeString('OutputThrottleHistory');
        $data = json_decode($raw, true);

        return is_array($data) ? $data : [];
    }

    private function WriteOutputThrottleHistory(array $history): void
    {
        $normalized = [];

        foreach ($history as $outputID => $entries) {
            $outputID = trim((string) $outputID);
            if ($outputID === '' || !is_array($entries)) {
                continue;
            }

            $clean = [];
            foreach ($entries as $timestampRaw) {
                $timestamp = (int) $timestampRaw;
                if ($timestamp > 0) {
                    $clean[] = $timestamp;
                }
            }

            $normalized[$outputID] = array_values($clean);
        }

        $this->WriteAttributeString('OutputThrottleHistory', json_encode($normalized));
    }



    private function BuildOutputMatchKey(string $groupKey, string $outputID): string
    {
        return $groupKey . '|' . $outputID;
    }

    public function DebugGetActiveOutputMatchKeys(): string
    {
        return $this->ReadAttributeString('ActiveOutputMatchKeys');
    }

    public function DebugGetOutputThrottleHistory(): string
    {
        return $this->ReadAttributeString('OutputThrottleHistory');
    }
    private function GetActiveOutputMatchKeySet(): array
    {
        $raw = $this->ReadAttributeString('ActiveOutputMatchKeys');
        $data = json_decode($raw, true);

        $result = [];
        if (!is_array($data)) {
            return $result;
        }

        foreach ($data as $matchKeyRaw) {
            $matchKey = trim((string) $matchKeyRaw);
            if ($matchKey === '') {
                continue;
            }
            $result[$matchKey] = true;
        }

        return $result;
    }



    public function ReceiveHouseStateSnapshot(string $snapshotJson): void
    {
        $data = json_decode($snapshotJson, true);
        if (!is_array($data)) {
            $this->LogMessage('ReceiveHouseStateSnapshot: invalid JSON', KL_MESSAGE);
            return;
        }

        if (!$this->ValidateHouseStateSnapshot($data)) {
            $this->LogMessage('ReceiveHouseStateSnapshot: snapshot validation failed', KL_MESSAGE);
            return;
        }

        $this->WriteAttributeString('CachedHouseStateSnapshot', $snapshotJson);
        $this->WriteAttributeInteger('CachedHouseStateReceivedAt', time());

        $stateID = (int) ($data['system_state_id'] ?? 0);
        $epoch = (string) ($data['sync']['last_processed_event_epoch'] ?? '0');
        $seq = (int) ($data['sync']['last_processed_event_seq'] ?? 0);

        $this->LogMessage(
            'ReceiveHouseStateSnapshot: cached state=' . $stateID . ' sync=(' . $epoch . ',' . $seq . ')',
            KL_MESSAGE
        );

        $cachedPayloadJson = $this->ReadAttributeString('CachedModule1PayloadRaw');
        $cachedPayload = json_decode($cachedPayloadJson, true);

        if (is_array($cachedPayload)) {
            $cachedGroups = $cachedPayload['target_active_groups'] ?? [];
            if (is_array($cachedGroups) && count($cachedGroups) > 0) {
                $this->LogMessage(
                    'ReceiveHouseStateSnapshot: reevaluating cached Module 1 context for house state ' . $stateID,
                    KL_MESSAGE
                );
                $this->ReevaluateCurrentAlarmContext($cachedPayload, $data);
            }
        }
    }

    private function ValidateHouseStateSnapshot(array $data): bool
    {
        if (($data['schema'] ?? '') !== 'PSM.HouseState.v2') {
            return false;
        }

        $requiredTop = [
            'timestamp',
            'source_instance_id',
            'sync',
            'system_state_id',
            'system_state_name',
            'delay_active',
            'delay_remaining_seconds',
            'derived',
            'blocking_reasons',
            'blocking_details'
        ];

        foreach ($requiredTop as $key) {
            if (!array_key_exists($key, $data)) {
                return false;
            }
        }

        if (!is_array($data['sync'])) {
            return false;
        }

        if (!array_key_exists('last_processed_event_epoch', $data['sync'])) {
            return false;
        }

        if (!array_key_exists('last_processed_event_seq', $data['sync'])) {
            return false;
        }

        return true;
    }

    private function GetCachedHouseStateSnapshot(): ?array
    {
        $json = $this->ReadAttributeString('CachedHouseStateSnapshot');
        if (trim($json) === '') {
            return null;
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            return null;
        }

        if (!$this->ValidateHouseStateSnapshot($data)) {
            return null;
        }

        return $data;
    }

    private function GetUsableHouseStateSnapshot(string $eventEpoch, int $eventSeq): ?array
    {
        $cached = $this->GetCachedHouseStateSnapshot();
        if ($cached !== null) {
            $cachedEpoch = (string) ($cached['sync']['last_processed_event_epoch'] ?? '0');
            $cachedSeq = (int) ($cached['sync']['last_processed_event_seq'] ?? 0);

            $this->LogMessage(
                'GetUsableHouseStateSnapshot: RELAXED MODE using cached snapshot event=(' . $eventEpoch . ',' . $eventSeq . ') cached=(' . $cachedEpoch . ',' . $cachedSeq . ')',
                KL_MESSAGE
            );

            return $cached;
        }

        $this->LogMessage('GetUsableHouseStateSnapshot: RELAXED MODE no cached snapshot, trying fallback pull', KL_MESSAGE);

        $pulled = $this->GetSynchronizedHouseStateSnapshot($eventEpoch, $eventSeq);
        if ($pulled !== null) {
            $this->WriteAttributeString('CachedHouseStateSnapshot', json_encode($pulled));
            $this->WriteAttributeInteger('CachedHouseStateReceivedAt', time());
            $this->LogMessage('GetUsableHouseStateSnapshot: RELAXED MODE fallback pull succeeded', KL_MESSAGE);
            return $pulled;
        }

        $this->LogMessage('GetUsableHouseStateSnapshot: RELAXED MODE fallback pull failed', KL_MESSAGE);
        return null;
    }

    private function IsSnapshotFreshEnoughForEvent(array $snapshot, string $eventEpoch, int $eventSeq): bool
    {
        $processedEpoch = (string) ($snapshot['sync']['last_processed_event_epoch'] ?? '0');
        $processedSeq = (int) ($snapshot['sync']['last_processed_event_seq'] ?? 0);

        $epochCompare = $this->CompareNumericStrings($processedEpoch, $eventEpoch);

        if ($epochCompare > 0) {
            return true;
        }

        if ($epochCompare === 0 && $processedSeq >= $eventSeq) {
            return true;
        }

        return false;
    }

    private function GetSynchronizedHouseStateSnapshot(string $eventEpoch, int $eventSeq): ?array
    {
        $module2ID = $this->ReadPropertyInteger('Module2InstanceID');
        if ($module2ID <= 0 || !@IPS_InstanceExists($module2ID)) {
            $this->LogMessage('GetSynchronizedHouseStateSnapshot: Module2InstanceID invalid', KL_MESSAGE);
            return null;
        }

        if (!function_exists('PSM_GetHouseStateSnapshot')) {
            $this->LogMessage('GetSynchronizedHouseStateSnapshot: PSM_GetHouseStateSnapshot not available', KL_MESSAGE);
            return null;
        }

        $json = @PSM_GetHouseStateSnapshot($module2ID);
        $house = json_decode((string) $json, true);

        if (!is_array($house)) {
            $this->LogMessage('GetSynchronizedHouseStateSnapshot: invalid JSON from Module 2', KL_MESSAGE);
            return null;
        }

        $processedEpoch = (string) ($house['sync']['last_processed_event_epoch'] ?? '0');
        $processedSeq = (int) ($house['sync']['last_processed_event_seq'] ?? 0);

        $this->LogMessage(
            'GetSynchronizedHouseStateSnapshot: RELAXED MODE returning latest snapshot event=(' . $eventEpoch . ',' . $eventSeq . ') snapshot=(' . $processedEpoch . ',' . $processedSeq . ')',
            KL_MESSAGE
        );

        return $house;
    }

    private function IsHouseSnapshotSynchronized(array $house, string $eventEpoch, int $eventSeq): bool
    {
        $processedEpoch = (string) ($house['sync']['last_processed_event_epoch'] ?? '0');
        $processedSeq = (int) ($house['sync']['last_processed_event_seq'] ?? 0);

        $epochCompare = $this->CompareNumericStrings($processedEpoch, $eventEpoch);

        if ($epochCompare > 0) {
            return true;
        }

        if ($epochCompare === 0 && $processedSeq >= $eventSeq) {
            return true;
        }

        return false;
    }

    private function FindMatchingRuleIDsForGroupAndState(string $groupLabel, string $houseState): array
    {
        $groupKey = $this->MakeGroupKey($groupLabel);
        $rows = $this->GetEffectiveGroupStateRules();

        $ruleIDs = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            if (!(bool) ($row['Active'] ?? false)) {
                continue;
            }

            if ((string) ($row['GroupKey'] ?? '') !== $groupKey) {
                continue;
            }

            if ((string) ($row['HouseState'] ?? '') !== $houseState) {
                continue;
            }

            $ruleID = trim((string) ($row['RuleID'] ?? ''));
            if ($ruleID !== '') {
                $ruleIDs[] = $ruleID;
            }
        }

        return array_values(array_unique($ruleIDs));
    }

    private function FindAssignmentsForRuleID(string $ruleID): array
    {
        $rows = $this->GetEffectiveRuleOutputAssignments();
        $result = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            if (!(bool) ($row['Active'] ?? false)) {
                continue;
            }

            if ((string) ($row['RuleID'] ?? '') !== $ruleID) {
                continue;
            }

            $result[] = $row;
        }

        return $result;
    }

    private function ExecuteOutputResource(array $resource, array $payload, array $house, string $groupLabel): bool
    {
        $typeID = trim((string) ($resource['TypeID'] ?? ''));

        if ($this->IsEmailTypeID($typeID)) {
            $subject = $this->BuildEmailSubject($groupLabel, $house);
            $body = $this->BuildEmailBody($resource, $payload, $house, $groupLabel);
            return $this->SendEmailOutputResource($resource, $subject, $body);
        }

        if ($typeID === 'screen') {
            return $this->WriteScreenOutputResource($resource, $payload, $house, $groupLabel);
        }

        $this->LogMessage('ExecuteOutputResource: unsupported TypeID=' . $typeID, KL_MESSAGE);
        return false;
    }

    private function BuildEmailSubject(string $groupLabel, array $house): string
    {
        $stateID = (string) ((int) ($house['system_state_id'] ?? 0));
        $stateLabel = $this->labelFromOptions(self::HOUSE_STATES, $stateID);

        if ($stateLabel === '') {
            return 'Alarm: ' . $groupLabel;
        }

        return 'Alarm: ' . $groupLabel . ' / ' . $stateLabel;
    }

    private function BuildEmailBody(array $resource, array $payload, array $house, string $groupLabel): string
    {
        $message = $this->BuildOutputMessageText($resource, $payload);

        $stateID = (string) ((int) ($house['system_state_id'] ?? 0));
        $stateLabel = $this->labelFromOptions(self::HOUSE_STATES, $stateID);

        $lines = [];
        $lines[] = 'Module 3 alarm notification';
        $lines[] = '';
        $lines[] = 'Group: ' . $groupLabel;
        $lines[] = 'House State: ' . ($stateLabel !== '' ? $stateLabel : $stateID);
        $lines[] = '';
        $lines[] = 'Message:';
        $lines[] = $message;

        return implode("\n", $lines);
    }

    private function BuildOutputMessageText(array $resource, array $payload): string
    {
        $prefix = trim((string) ($resource['PrefixText'] ?? ''));
        $suffix = trim((string) ($resource['SuffixText'] ?? ''));

        $sensorName = '';
        $parentName = '';
        $grandparentName = '';
        $content = '';

        $trigger = $payload['target_trigger_details'] ?? [];
        if (is_array($trigger)) {
            $sensorName = trim((string) ($trigger['smart_label'] ?? $trigger['SensorName'] ?? ''));
            $parentName = trim((string) ($trigger['ParentName'] ?? $trigger['parent_name'] ?? ''));
            $grandparentName = trim((string) ($trigger['GrandParentName'] ?? $trigger['grandparent_name'] ?? ''));

            $valueHuman = trim((string) ($trigger['value_human'] ?? ''));
            if ($valueHuman !== '') {
                $content = $valueHuman;
            } elseif (array_key_exists('value_raw', $trigger)) {
                $content = trim((string) $trigger['value_raw']);
            }
        }

        $sensorDetails = $payload['target_active_sensor_details'] ?? [];
        if (is_array($sensorDetails) && count($sensorDetails) > 0 && is_array($sensorDetails[0])) {
            $first = $sensorDetails[0];

            if ($sensorName === '') {
                $sensorName = trim((string) ($first['smart_label'] ?? $first['SensorName'] ?? ''));
            }
            if ($parentName === '') {
                $parentName = trim((string) ($first['ParentName'] ?? $first['parent_name'] ?? ''));
            }
            if ($grandparentName === '') {
                $grandparentName = trim((string) ($first['GrandParentName'] ?? $first['grandparent_name'] ?? ''));
            }
            if ($content === '') {
                $valueHuman = trim((string) ($first['value_human'] ?? ''));
                if ($valueHuman !== '') {
                    $content = $valueHuman;
                } elseif (array_key_exists('value_raw', $first)) {
                    $content = trim((string) $first['value_raw']);
                }
            }
        }

        $parts = [];

        if ($prefix !== '') {
            $parts[] = $prefix;
        }

        if ((bool) ($resource['UseSensorName'] ?? false) && $sensorName !== '') {
            $parts[] = $sensorName;
        }

        if ((bool) ($resource['UseParentName'] ?? false) && $parentName !== '') {
            $parts[] = $parentName;
        }

        if ((bool) ($resource['UseGrandparentName'] ?? false) && $grandparentName !== '') {
            $parts[] = $grandparentName;
        }

        if ((bool) ($resource['UseContent'] ?? false) && $content !== '') {
            $parts[] = $content;
        }

        if ($suffix !== '') {
            $parts[] = $suffix;
        }

        $text = trim(implode(' ', $parts));
        if ($text !== '') {
            return $text;
        }

        return 'Alarm event detected';
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

        if ($label !== '') {
            $parts[] = $label;
        }
        if ($severity !== '') {
            $parts[] = 'severity: ' . $severity;
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


    private function BuildMappingGraph(): string
    {
        $groups = $this->ExtractImportedGroupsFromConfig();
        $visibleGroupKeys = array_fill_keys($this->GetVisibleGraphGroupKeys($groups), true);

        $rules = $this->GetEffectiveGroupStateRules();
        $assignments = $this->GetEffectiveRuleOutputAssignments();
        $outputs = $this->readListProperty('OutputResources');

        $groups = array_values(array_filter($groups, function (array $group) use ($visibleGroupKeys): bool {
            $groupKey = trim((string) ($group['GroupKey'] ?? ''));
            return $groupKey !== '' && isset($visibleGroupKeys[$groupKey]);
        }));

        $rules = array_values(array_filter($rules, function (array $rule) use ($visibleGroupKeys): bool {
            $groupKey = trim((string) ($rule['GroupKey'] ?? ''));
            return $groupKey !== '' && isset($visibleGroupKeys[$groupKey]);
        }));

        $visibleRuleIDs = [];
        foreach ($rules as $rule) {
            $ruleID = trim((string) ($rule['RuleID'] ?? ''));
            if ($ruleID !== '') {
                $visibleRuleIDs[$ruleID] = true;
            }
        }

        $assignments = array_values(array_filter($assignments, function (array $assignment) use ($visibleRuleIDs): bool {
            $ruleID = trim((string) ($assignment['RuleID'] ?? ''));
            return $ruleID !== '' && isset($visibleRuleIDs[$ruleID]);
        }));

        usort($groups, static function (array $a, array $b): int {
            return strnatcasecmp((string) ($a['GroupLabel'] ?? ''), (string) ($b['GroupLabel'] ?? ''));
        });

        usort($rules, static function (array $a, array $b): int {
            $ak = (string) ($a['GroupKey'] ?? '') . '|' . (string) ($a['HouseState'] ?? '');
            $bk = (string) ($b['GroupKey'] ?? '') . '|' . (string) ($b['HouseState'] ?? '');
            return strnatcasecmp($ak, $bk);
        });

        usort($assignments, static function (array $a, array $b): int {
            $ak = (string) ($a['RuleID'] ?? '') . '|' . (string) ($a['OutputID'] ?? '');
            $bk = (string) ($b['RuleID'] ?? '') . '|' . (string) ($b['OutputID'] ?? '');
            return strnatcasecmp($ak, $bk);
        });

        usort($outputs, static function (array $a, array $b): int {
            return strnatcasecmp((string) ($a['Name'] ?? ''), (string) ($b['Name'] ?? ''));
        });

        $groupLabels = $this->buildGroupLabels($groups, $rules);
        $typeLabels = $this->buildTypeLabels($this->GetBuiltInOutputTypes(), $outputs);

        $rulesByID = [];
        foreach ($rules as $row) {
            $ruleID = trim((string) ($row['RuleID'] ?? ''));
            if ($ruleID !== '') {
                $rulesByID[$ruleID] = $row;
            }
        }

        $outputsByID = [];
        foreach ($outputs as $row) {
            $outputID = trim((string) ($row['OutputID'] ?? ''));
            if ($outputID !== '') {
                $outputsByID[$outputID] = $row;
            }
        }

        $activeGroups = [];
        $lastActiveGroups = json_decode($this->ReadAttributeString('LastActiveGroups'), true);
        if (is_array($lastActiveGroups)) {
            foreach ($lastActiveGroups as $groupLabelRaw) {
                $groupLabel = trim((string) $groupLabelRaw);
                if ($groupLabel === '') {
                    continue;
                }
                $groupKey = $this->MakeGroupKey($groupLabel);
                if (isset($visibleGroupKeys[$groupKey])) {
                    $activeGroups[$groupKey] = true;
                }
            }
        }

        $activeRuleIDs = [];
        $lastActiveRuleIDs = json_decode($this->ReadAttributeString('LastActiveRuleIDs'), true);
        if (is_array($lastActiveRuleIDs)) {
            foreach ($lastActiveRuleIDs as $ruleIDRaw) {
                $ruleID = trim((string) $ruleIDRaw);
                if ($ruleID !== '' && isset($visibleRuleIDs[$ruleID])) {
                    $activeRuleIDs[$ruleID] = true;
                }
            }
        }

        $visibleOutputIDs = [];
        foreach ($assignments as $assignment) {
            $outputID = trim((string) ($assignment['OutputID'] ?? ''));
            if ($outputID !== '') {
                $visibleOutputIDs[$outputID] = true;
            }
        }

        $activeOutputIDs = [];
        $lastActiveOutputIDs = json_decode($this->ReadAttributeString('LastActiveOutputIDs'), true);
        if (is_array($lastActiveOutputIDs)) {
            foreach ($lastActiveOutputIDs as $outputIDRaw) {
                $outputID = trim((string) $outputIDRaw);
                if ($outputID !== '' && isset($visibleOutputIDs[$outputID])) {
                    $activeOutputIDs[$outputID] = true;
                }
            }
        }

        $lines = [];
        $lines[] = 'graph LR';
        $lines[] = 'classDef green fill:#2e7d32,stroke:#a5d6a7,stroke-width:2px,color:#fff;';
        $lines[] = 'classDef red fill:#c62828,stroke:#ff8a80,stroke-width:2px,color:#fff;';
        $lines[] = 'classDef grey fill:#37474f,stroke:#546e7a,stroke-width:1px,color:#eee;';
        $lines[] = 'classDef info fill:#1565c0,stroke:#90caf9,stroke-width:2px,color:#fff;';

        $cachedHouse = $this->GetCachedHouseStateSnapshot();
        $houseStateNode = 'HS_' . substr(md5((string) $this->InstanceID), 0, 10);
        if ($cachedHouse !== null) {
            $houseStateID = (string) ((int) ($cachedHouse['system_state_id'] ?? 0));
            $houseStateName = trim((string) ($cachedHouse['system_state_name'] ?? ''));
            $houseEpoch = (string) ($cachedHouse['sync']['last_processed_event_epoch'] ?? '0');
            $houseSeq = (int) ($cachedHouse['sync']['last_processed_event_seq'] ?? 0);

            $houseParts = [];
            $houseParts[] = 'House State';
            $houseParts[] = ($houseStateName !== '' ? $houseStateName : 'Unknown') . ' [' . $houseStateID . ']';
            $houseParts[] = 'Sync: ' . $houseEpoch . ' / ' . $houseSeq;

            $lines[] = $houseStateNode . '["' . $this->MermaidEscape(implode("\n", $houseParts)) . '"]';
            $lines[] = 'class ' . $houseStateNode . ' info;';
        } else {
            $lines[] = $houseStateNode . '["' . $this->MermaidEscape("House State\nNo cached snapshot") . '"]';
            $lines[] = 'class ' . $houseStateNode . ' grey;';
        }

        if (count($groups) === 0) {
            $noteNode = 'N_' . substr(md5('no_groups_' . (string) $this->InstanceID), 0, 10);
            $lines[] = $noteNode . '["' . $this->MermaidEscape('No groups selected') . '"]';
            $lines[] = 'class ' . $noteNode . ' grey;';
            return implode("\n", $lines) . "\n";
        }

        $linkCounter = 0;

        foreach ($groups as $group) {
            $groupKey = trim((string) ($group['GroupKey'] ?? ''));
            if ($groupKey === '') {
                continue;
            }

            $groupNode = 'G_' . substr(md5($groupKey), 0, 10);
            $groupLabel = $groupLabels[$groupKey] ?? $groupKey;

            $lines[] = $groupNode . '["' . $this->MermaidEscape($groupLabel) . '"]';
            $lines[] = 'class ' . $groupNode . ' ' . (isset($activeGroups[$groupKey]) ? 'red' : 'green') . ';';
        }

        foreach ($rules as $rule) {
            $ruleID = trim((string) ($rule['RuleID'] ?? ''));
            $groupKey = trim((string) ($rule['GroupKey'] ?? ''));
            if ($ruleID === '' || $groupKey === '') {
                continue;
            }

            $groupNode = 'G_' . substr(md5($groupKey), 0, 10);
            $ruleNode = 'R_' . substr(md5($ruleID), 0, 10);
            $ruleActive = isset($activeRuleIDs[$ruleID]);

            $ruleLabel = $this->buildRuleLabel($rule, $groupLabels);
            $severity = trim((string) ($rule['Severity'] ?? ''));

            $parts = [];
            $parts[] = $ruleLabel !== '' ? $ruleLabel : $ruleID;
            if ($severity !== '') {
                $parts[] = 'Severity: ' . $severity;
            }

            $lines[] = $ruleNode . '["' . $this->MermaidEscape(implode("\n", $parts)) . '"]';
            $lines[] = $groupNode . ' --> ' . $ruleNode;
            $lines[] = 'class ' . $ruleNode . ' ' . ($ruleActive ? 'red' : 'green') . ';';
            $lines[] = 'linkStyle ' . $linkCounter . ' stroke:' . ($ruleActive ? '#ff8a80' : '#a5d6a7') . ',stroke-width:2px;';
            $linkCounter++;
        }

        foreach ($assignments as $assignment) {
            $assignmentID = trim((string) ($assignment['AssignmentID'] ?? ''));
            $ruleID = trim((string) ($assignment['RuleID'] ?? ''));
            $outputID = trim((string) ($assignment['OutputID'] ?? ''));

            if ($assignmentID === '' || $ruleID === '') {
                continue;
            }

            $assignmentNode = 'A_' . substr(md5($assignmentID), 0, 10);
            $ruleNode = 'R_' . substr(md5($ruleID), 0, 10);

            $assignmentActive = isset($activeRuleIDs[$ruleID]) && $outputID !== '' && isset($activeOutputIDs[$outputID]);

            $assignmentLabel = 'Output Action';
            if ($outputID !== '' && isset($outputsByID[$outputID])) {
                $assignmentOutputName = trim((string) ($outputsByID[$outputID]['Name'] ?? ''));
                $assignmentLabel .= "\n" . ($assignmentOutputName !== '' ? $assignmentOutputName : $outputID);
            } elseif ($outputID !== '') {
                $assignmentLabel .= "\n" . $outputID;
            }

            $lines[] = $assignmentNode . '["' . $this->MermaidEscape($assignmentLabel) . '"]';
            $lines[] = $ruleNode . ' --> ' . $assignmentNode;
            $lines[] = 'class ' . $assignmentNode . ' ' . ($assignmentActive ? 'red' : 'green') . ';';
            $lines[] = 'linkStyle ' . $linkCounter . ' stroke:' . ($assignmentActive ? '#ff8a80' : '#a5d6a7') . ',stroke-width:2px;';
            $linkCounter++;

            if ($outputID === '') {
                continue;
            }

            $outputNode = 'O_' . substr(md5($outputID), 0, 10);

            if (!isset($outputsByID[$outputID])) {
                $lines[] = $outputNode . '["' . $this->MermaidEscape('[missing output]' . "\n" . $outputID) . '"]';
                $lines[] = $assignmentNode . ' --> ' . $outputNode;
                $lines[] = 'class ' . $outputNode . ' grey;';
                $lines[] = 'linkStyle ' . $linkCounter . ' stroke:#546e7a,stroke-width:2px;';
                $linkCounter++;
                continue;
            }

            $output = $outputsByID[$outputID];
            $typeID = trim((string) ($output['TypeID'] ?? ''));
            $typeLabel = $typeLabels[$typeID] ?? '[missing type]';
            $targetObjectID = (int) ($output['TargetObjectID'] ?? 0);
            $outputName = trim((string) ($output['Name'] ?? ''));

            $parts = [];
            $parts[] = $outputName !== '' ? $outputName : $outputID;
            $parts[] = 'Type: ' . $typeLabel;
            if ($targetObjectID > 0) {
                $parts[] = 'ObjID: ' . $targetObjectID;
            }

            $outputNodeActive = isset($activeOutputIDs[$outputID]);

            $lines[] = $outputNode . '["' . $this->MermaidEscape(implode("\n", $parts)) . '"]';
            $lines[] = $assignmentNode . ' --> ' . $outputNode;
            $lines[] = 'class ' . $outputNode . ' ' . ($outputNodeActive ? 'red' : 'green') . ';';
            $lines[] = 'linkStyle ' . $linkCounter . ' stroke:' . ($assignmentActive ? '#ff8a80' : '#a5d6a7') . ',stroke-width:2px;';
            $linkCounter++;
        }

        return implode("\n", $lines) . "\n";
    }

    private function MermaidEscape(string $value): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = str_replace('"', '\\"', $value);
        $value = str_replace(['[', ']'], ['(', ')'], $value);
        return $value;
    }

    private function GenerateTechnicalID(string $prefix): string
    {
        return $prefix . bin2hex(random_bytes(6));
    }

    private function MakeGroupKey(string $groupLabel): string
    {
        return 'grp_' . md5(mb_strtolower(trim($groupLabel)));
    }


    private function FindOutputResourceByID(string $outputID): ?array
    {
        $rows = $this->readListProperty('OutputResources');

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            if ((string) ($row['OutputID'] ?? '') !== $outputID) {
                continue;
            }

            return $row;
        }

        return null;
    }

    private function IsEmailTypeID(string $typeID): bool
    {
        return in_array($typeID, ['email_1', 'email_2', 'email_3', 'email_4'], true);
    }

    public function TestSendEmailByOutputID(string $outputID, string $subject, string $body): bool
    {
        $this->LogMessage('TestSendEmailByOutputID called with OutputID=' . $outputID, KL_MESSAGE);

        $resource = $this->FindOutputResourceByID($outputID);
        if ($resource === null) {
            $this->LogMessage('TestSendEmailByOutputID: OutputID not found', KL_MESSAGE);
            return false;
        }

        $this->LogMessage('TestSendEmailByOutputID: matched Name=' . (string)($resource['Name'] ?? ''), KL_MESSAGE);
        $this->LogMessage('TestSendEmailByOutputID: matched TypeID=' . (string)($resource['TypeID'] ?? ''), KL_MESSAGE);
        $this->LogMessage('TestSendEmailByOutputID: matched TargetObjectID=' . (string)($resource['TargetObjectID'] ?? 0), KL_MESSAGE);

        return $this->SendEmailOutputResource($resource, $subject, $body);
    }

    private function SendEmailOutputResource(array $resource, string $subject, string $body): bool
    {
        $typeID = trim((string) ($resource['TypeID'] ?? ''));
        $targetObjectID = (int) ($resource['TargetObjectID'] ?? 0);

        $this->LogMessage('SendEmailOutputResource: TypeID=' . $typeID, KL_MESSAGE);
        $this->LogMessage('SendEmailOutputResource: TargetObjectID=' . $targetObjectID, KL_MESSAGE);
        $this->LogMessage('SendEmailOutputResource: Subject=' . $subject, KL_MESSAGE);
        $this->LogMessage('SendEmailOutputResource: Body=' . $body, KL_MESSAGE);

        if (!$this->IsEmailTypeID($typeID)) {
            $this->LogMessage('SendEmailOutputResource: invalid email type', KL_MESSAGE);
            return false;
        }

        if ($targetObjectID <= 0 || !@IPS_InstanceExists($targetObjectID)) {
            $this->LogMessage('SendEmailOutputResource: invalid target instance', KL_MESSAGE);
            return false;
        }

        if (!function_exists('SMTP_SendMail')) {
            $this->LogMessage('SendEmailOutputResource: SMTP_SendMail not available', KL_MESSAGE);
            return false;
        }

        $result = SMTP_SendMail($targetObjectID, $subject, $body);
        $this->LogMessage('SendEmailOutputResource: SMTP_SendMail returned ' . ($result ? 'true' : 'false'), KL_MESSAGE);

        return $result;
    }
    private function setListFormFieldOptions(array &$form, string $listName, string $fieldName, array $options): void
    {
        $this->walkAndModifyList($form, $listName, function (&$element) use ($fieldName, $options) {
            if (!isset($element['form']) || !is_array($element['form'])) {
                return;
            }

            foreach ($element['form'] as &$subElement) {
                if (($subElement['name'] ?? '') !== $fieldName) {
                    continue;
                }

                $subElement['options'] = $options;
                return;
            }
        });
    }

    private function CompareNumericStrings(string $a, string $b): int
    {
        $a = ltrim(trim($a), '0');
        $b = ltrim(trim($b), '0');

        if ($a === '') {
            $a = '0';
        }
        if ($b === '') {
            $b = '0';
        }

        if (strlen($a) > strlen($b)) {
            return 1;
        }
        if (strlen($a) < strlen($b)) {
            return -1;
        }

        return strcmp($a, $b);
    }

    public function DebugGetCachedHouseStateSnapshot(): string
    {
        return $this->ReadAttributeString('CachedHouseStateSnapshot');
    }

    public function DebugGetCachedHouseStateReceivedAt(): int
    {
        return $this->ReadAttributeInteger('CachedHouseStateReceivedAt');
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
