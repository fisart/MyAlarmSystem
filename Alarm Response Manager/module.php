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

        $this->RegisterPropertyString('OutputResources', '[]');
        $this->RegisterPropertyString('GroupStateRules', '[]');
        $this->RegisterPropertyString('RuleOutputAssignments', '[]');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->RegisterHook('/hook/psm_output_' . $this->InstanceID);

        $this->SetStatus(102);
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

            default:
                throw new Exception('Invalid Ident');
        }
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
        $groupStateRules = $this->EnsureListRowIDsPersisted('GroupStateRules', 'RuleID', 'rule_');
        $ruleOutputAssignments = $this->EnsureListRowIDsPersisted('RuleOutputAssignments', 'AssignmentID', 'asg_');

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
        $this->setListFormFieldOptions($form, 'OutputResources', 'TypeID', $typeOptions);
        $this->setListFormFieldOptions($form, 'GroupStateRules', 'GroupKey', $groupOptions);
        $this->setListFormFieldOptions($form, 'GroupStateRules', 'HouseState', self::HOUSE_STATES);
        $this->setListFormFieldOptions($form, 'GroupStateRules', 'Severity', self::SEVERITY_LEVELS);
        $this->setListFormFieldOptions($form, 'RuleOutputAssignments', 'RuleID', $ruleOptions);
        $this->setListFormFieldOptions($form, 'RuleOutputAssignments', 'OutputID', $outputOptions);

        $resourceValues = [];
        foreach ($outputResources as $row) {
            $outputID = trim((string) ($row['OutputID'] ?? ''));

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

        // 2. API mode = return only Mermaid graph text
        if (isset($_GET['api'])) {
            header('Content-Type: text/plain; charset=utf-8');
            echo $this->BuildMappingGraph();
            return;
        }

        // 3. Full HTML shell
        header('Content-Type: text/html; charset=utf-8');

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

async function fetchAndUpdateGraph() {
    if (isRendering) return;

    try {
        const response = await fetch("?api=1&t=" + Date.now(), { credentials: "same-origin" });
        const graphString = await response.text();

        if (!graphString.trim().startsWith("graph ")) return;

        if (graphString !== lastGraphString) {
            isRendering = true;
            lastGraphString = graphString;

            const container = document.getElementById("mermaid-container");
            const pzContainer = document.querySelector(".container");
            const oldZoom = pzInstance ? pzInstance.getZoom() : null;
            const oldPan  = pzInstance ? pzInstance.getPan()  : null;

            if (pzInstance) pzInstance.destroy();

            container.innerHTML = graphString;
            await mermaid.run({ nodes: [container] });

            const svgEl = container.querySelector("svg");
            if (!svgEl) return;

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
        }
    } catch (err) {
        console.error("Rendering failed", err);
    } finally {
        isRendering = false;
    }
}

fetchAndUpdateGraph();
setInterval(fetchAndUpdateGraph, 2000);
</script>
</head>

<body>
<div class="header">
    <h2>Module 3 Mapping (Live)</h2>
    <small>Instance ID: ' . $this->InstanceID . '</small>
</div>
<div class="container">
    <div id="mermaid-container">Initializing Live View...</div>
</div>
</body>
</html>';
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

    public function ReceivePayload(string $payloadJson): void
    {
        $payload = json_decode($payloadJson, true);
        if (!is_array($payload)) {
            $this->LogMessage('ReceivePayload: invalid JSON payload', KL_MESSAGE);
            return;
        }

        $eventEpoch = (int) ($payload['event_epoch'] ?? 0);
        $eventSeq = (int) ($payload['event_seq'] ?? 0);

        $targetGroups = $payload['target_active_groups'] ?? [];
        if (!is_array($targetGroups) || count($targetGroups) === 0) {
            $this->LogMessage('ReceivePayload: no target_active_groups', KL_MESSAGE);
            return;
        }

        $house = $this->GetSynchronizedHouseStateSnapshot($eventEpoch, $eventSeq);
        if ($house === null) {
            $this->LogMessage('ReceivePayload: no synchronized house snapshot available', KL_MESSAGE);
            return;
        }

        $houseState = (string) ((int) ($house['system_state_id'] ?? 0));
        $this->LogMessage('ReceivePayload: houseState=' . $houseState, KL_MESSAGE);

        $executedOutputIDs = [];

        foreach ($targetGroups as $groupLabelRaw) {
            $groupLabel = trim((string) $groupLabelRaw);
            if ($groupLabel === '') {
                continue;
            }

            $ruleIDs = $this->FindMatchingRuleIDsForGroupAndState($groupLabel, $houseState);
            $this->LogMessage('ReceivePayload: group=' . $groupLabel . ' matched rules=' . json_encode($ruleIDs), KL_MESSAGE);

            foreach ($ruleIDs as $ruleID) {
                $assignments = $this->FindAssignmentsForRuleID($ruleID);

                foreach ($assignments as $assignment) {
                    $outputID = trim((string) ($assignment['OutputID'] ?? ''));
                    if ($outputID === '') {
                        continue;
                    }

                    if (isset($executedOutputIDs[$outputID])) {
                        continue;
                    }

                    $resource = $this->FindOutputResourceByID($outputID);
                    if ($resource === null) {
                        $this->LogMessage('ReceivePayload: OutputID not found: ' . $outputID, KL_MESSAGE);
                        continue;
                    }

                    $ok = $this->ExecuteOutputResource($resource, $payload, $house, $groupLabel);
                    $this->LogMessage(
                        'ReceivePayload: execute OutputID=' . $outputID . ' result=' . ($ok ? 'true' : 'false'),
                        KL_MESSAGE
                    );

                    $executedOutputIDs[$outputID] = true;
                }
            }
        }
    }

    private function GetSynchronizedHouseStateSnapshot(int $eventEpoch, int $eventSeq): ?array
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

        for ($i = 0; $i < 5; $i++) {
            $json = @PSM_GetHouseStateSnapshot($module2ID);
            $house = json_decode((string) $json, true);

            if (!is_array($house)) {
                $this->LogMessage('GetSynchronizedHouseStateSnapshot: invalid JSON from Module 2', KL_MESSAGE);
                usleep(300000);
                continue;
            }

            $processedEpoch = (int) ($house['sync']['last_processed_event_epoch'] ?? 0);
            $processedSeq = (int) ($house['sync']['last_processed_event_seq'] ?? 0);

            $this->LogMessage(
                'GetSynchronizedHouseStateSnapshot: try=' . $i .
                    ' event=(' . $eventEpoch . ',' . $eventSeq . ')' .
                    ' snapshot=(' . $processedEpoch . ',' . $processedSeq . ')',
                KL_MESSAGE
            );

            if ($this->IsHouseSnapshotSynchronized($house, $eventEpoch, $eventSeq)) {
                return $house;
            }

            usleep(300000);
        }

        return null;
    }

    private function IsHouseSnapshotSynchronized(array $house, int $eventEpoch, int $eventSeq): bool
    {
        $processedEpoch = (int) ($house['sync']['last_processed_event_epoch'] ?? 0);
        $processedSeq = (int) ($house['sync']['last_processed_event_seq'] ?? 0);

        if ($processedEpoch > $eventEpoch) {
            return true;
        }

        if ($processedEpoch === $eventEpoch && $processedSeq >= $eventSeq) {
            return true;
        }

        return false;
    }

    private function FindMatchingRuleIDsForGroupAndState(string $groupLabel, string $houseState): array
    {
        $groupKey = $this->MakeGroupKey($groupLabel);
        $rows = $this->readListProperty('GroupStateRules');

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
        $rows = $this->readListProperty('RuleOutputAssignments');
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

        $trigger = $payload['target_trigger_details'] ?? [];
        if (is_array($trigger)) {
            $sensorName = trim((string) ($trigger['smart_label'] ?? $trigger['SensorName'] ?? ''));
            $parentName = trim((string) ($trigger['ParentName'] ?? $trigger['parent_name'] ?? ''));
            $grandparentName = trim((string) ($trigger['GrandParentName'] ?? $trigger['grandparent_name'] ?? ''));
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


    private function BuildMappingGraph(): string
    {
        $groups = $this->ExtractImportedGroupsFromConfig();
        $rules = $this->readListProperty('GroupStateRules');
        $assignments = $this->readListProperty('RuleOutputAssignments');
        $outputs = $this->readListProperty('OutputResources');

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
        foreach ($rules as $rule) {
            if (!(bool) ($rule['Active'] ?? false)) {
                continue;
            }
            $groupKey = trim((string) ($rule['GroupKey'] ?? ''));
            if ($groupKey !== '') {
                $activeGroups[$groupKey] = true;
            }
        }

        $lines = [];
        $lines[] = 'graph LR';
        $lines[] = 'classDef green fill:#2e7d32,stroke:#a5d6a7,stroke-width:2px,color:#fff;';
        $lines[] = 'classDef red fill:#c62828,stroke:#ff8a80,stroke-width:2px,color:#fff;';
        $lines[] = 'classDef grey fill:#37474f,stroke:#546e7a,stroke-width:1px,color:#eee;';

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
            $ruleActive = (bool) ($rule['Active'] ?? false);

            $ruleLabel = $this->buildRuleLabel($rule, $groupLabels);
            $severity = trim((string) ($rule['Severity'] ?? ''));
            $bypass = (bool) ($rule['Bypass'] ?? false);

            $parts = [];
            $parts[] = $ruleLabel !== '' ? $ruleLabel : $ruleID;
            if ($severity !== '') {
                $parts[] = 'Severity: ' . $severity;
            }
            if ($bypass) {
                $parts[] = 'Bypass';
            }

            $lines[] = $ruleNode . '["' . $this->MermaidEscape(implode("\n", $parts)) . '"]';
            $lines[] = $groupNode . ' --> ' . $ruleNode;
            $lines[] = 'class ' . $ruleNode . ' ' . ($ruleActive ? 'red' : 'green') . ';';
            if ($ruleActive) {
                $lines[] = 'linkStyle ' . $linkCounter . ' stroke:#ff8a80,stroke-width:2px;';
            } else {
                $lines[] = 'linkStyle ' . $linkCounter . ' stroke:#a5d6a7,stroke-width:2px;';
            }
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

            $assignmentActive = (bool) ($assignment['Active'] ?? false);

            $assignmentLabel = 'Assignment';
            if ($outputID !== '') {
                $assignmentLabel .= "\n" . $outputID;
            }

            $lines[] = $assignmentNode . '["' . $this->MermaidEscape($assignmentLabel) . '"]';
            $lines[] = $ruleNode . ' --> ' . $assignmentNode;
            $lines[] = 'class ' . $assignmentNode . ' ' . ($assignmentActive ? 'red' : 'green') . ';';

            if ($assignmentActive) {
                $lines[] = 'linkStyle ' . $linkCounter . ' stroke:#ff8a80,stroke-width:2px;';
            } else {
                $lines[] = 'linkStyle ' . $linkCounter . ' stroke:#a5d6a7,stroke-width:2px;';
            }
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
            $outputActive = (bool) ($output['Active'] ?? false);
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

            $isActivePath = $assignmentActive && $outputActive;

            $lines[] = $outputNode . '["' . $this->MermaidEscape(implode("\n", $parts)) . '"]';
            $lines[] = $assignmentNode . ' --> ' . $outputNode;
            $lines[] = 'class ' . $outputNode . ' ' . ($isActivePath ? 'red' : 'green') . ';';

            if ($isActivePath) {
                $lines[] = 'linkStyle ' . $linkCounter . ' stroke:#ff8a80,stroke-width:2px;';
            } else {
                $lines[] = 'linkStyle ' . $linkCounter . ' stroke:#a5d6a7,stroke-width:2px;';
            }
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
