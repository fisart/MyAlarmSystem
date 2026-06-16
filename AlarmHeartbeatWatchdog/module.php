<?php

declare(strict_types=1);

// AlarmHeartbeatWatchdog module.php
// Version: 1.5.3
// Heartbeat token mode: milliseconds since midnight used as an integer correlation token
// Pulse model: token is active only for PulseDurationMs/ResetDelayMs, then HeartbeatInputVariableID is reset to 0
// Delivery confirmation mode: module-managed triggered events plus internal RegisterMessage/MessageSink fallback for Module 3 output variables
// Notes: Adds atomic heartbeat-history updates only, based on the user-supplied v1.5.1 code base.
// Atomic fix: shared history semaphore, no OK/LATE downgrade by later checks, downstream Module 3 confirmation can infer Module 1 OK.

class AlarmHeartbeatWatchdog extends IPSModule
{
    private const STATUS_ACTIVE = 102;
    private const STATUS_NO_INPUT = 201;
    private const STATUS_NO_WATCHES = 202;

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyBoolean('HeartbeatEnabled', true);
        $this->RegisterPropertyInteger('HeartbeatInputVariableID', 0);
        $this->RegisterPropertyInteger('CycleIntervalSeconds', 60);
        $this->RegisterPropertyInteger('ResetDelayMs', 2000);
        $this->RegisterPropertyInteger('DeliveryTimeoutSeconds', 60);
        $this->RegisterPropertyInteger('Module1MaxAgeSeconds', 180);
        $this->RegisterPropertyInteger('SmtpInstanceID', 0);
        $this->RegisterPropertyString('EmailTo', '');
        $this->RegisterPropertyBoolean('SendEmail', false);
        $this->RegisterPropertyBoolean('DebugMode', false);
        $this->RegisterPropertyString('WatchList', json_encode([
            ['Active' => true, 'Name' => 'Module 3 Intrusion Server', 'OutputVariableID' => 42253, 'MaxAgeSeconds' => 180],
            ['Active' => true, 'Name' => 'Module 3 Hazard Server',    'OutputVariableID' => 53052, 'MaxAgeSeconds' => 180],
            ['Active' => true, 'Name' => 'Module 3 Technical Server', 'OutputVariableID' => 50425, 'MaxAgeSeconds' => 180]
        ]));

        $this->RegisterAttributeInteger('PendingTimestamp', 0);
        $this->RegisterAttributeInteger('PendingSentAt', 0);
        $this->RegisterAttributeInteger('PendingDeadline', 0);
        $this->RegisterAttributeBoolean('LastOverallOK', true);
        $this->RegisterAttributeString('LastFailureText', '');
        $this->RegisterAttributeString('LastCheckJson', '{}');
        $this->RegisterAttributeString('RegisteredWatchVariableIDsJson', '[]');
        $this->RegisterAttributeString('HeartbeatHistoryJson', '[]');
        $this->RegisterAttributeBoolean('HeartbeatActiveInitialized', false);
        $this->RegisterAttributeBoolean('LastPropertyHeartbeatEnabled', true);
        $this->RegisterAttributeBoolean('LastEffectiveHeartbeatActive', false);

        $this->RegisterVariableBoolean('HeartbeatActive', 'Heartbeat Active', '~Switch', 5);
        $this->EnableAction('HeartbeatActive');

        $this->RegisterVariableBoolean('OverallOK', 'Overall OK', '~Switch', 10);
        $this->RegisterVariableString('LastCheckText', 'Last Check', '', 20);
        $this->RegisterVariableString('StatusHTML', 'Status HTML', '~HTMLBox', 30);
        $this->RegisterVariableInteger('LastSentTimestamp', 'Last Sent Timestamp', '', 40);
        $this->RegisterVariableString('LastSentText', 'Last Sent Text', '', 50);
        $this->RegisterVariableInteger('PendingTimestamp', 'Pending Timestamp', '', 60);
        $this->RegisterVariableInteger('PendingDeadline', 'Pending Deadline', '', 70);
        $this->RegisterVariableInteger('Module1LastSeen', 'Module 1 Last Seen', '', 80);
        $this->RegisterVariableString('Module1LastSeenText', 'Module 1 Last Seen Text', '', 90);
        $this->RegisterVariableInteger('Module1LastHeartbeatTimestamp', 'Module 1 Last Heartbeat Timestamp', '', 100);
        $this->RegisterVariableInteger('Module1RuntimeSeconds', 'Module 1 Runtime Seconds', '', 110);
        $this->RegisterVariableInteger('Module1Counter', 'Module 1 Counter', '', 120);
        $this->RegisterVariableString('Module1LastPayload', 'Module 1 Last Payload', '', 130);
        $this->RegisterVariableString('AlarmStateJson', 'Alarm State JSON', '', 140);
        $this->RegisterVariableString('HeartbeatHistoryJson', 'Heartbeat History JSON', '', 150);

        $this->RegisterTimer('HeartbeatTimer', 0, 'AHW_RunCycle($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->InitializeHeartbeatActiveVariable();

        $inputID = $this->ReadPropertyInteger('HeartbeatInputVariableID');
        $watchList = $this->GetActiveWatchList();

        if ($inputID <= 0 || !IPS_VariableExists($inputID)) {
            $this->SetStatus(self::STATUS_NO_INPUT);
            $this->SetTimerInterval('HeartbeatTimer', 0);
            return;
        }

        if (count($watchList) === 0) {
            $this->SetStatus(self::STATUS_NO_WATCHES);
        } else {
            $this->SetStatus(self::STATUS_ACTIVE);
        }

        $this->ApplyHeartbeatActivationState(true);
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'HeartbeatActive':
                $this->SetHeartbeatActive((bool)$Value);
                return;

            case 'ReceivePayload':
                $this->ReceivePayload((string)$Value);
                return;
        }

        throw new Exception('Invalid ident: ' . $Ident);
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data): void
    {
        if ($Message !== VM_UPDATE) {
            return;
        }

        $varID = (int)$SenderID;
        if ($varID <= 0 || !IPS_VariableExists($varID)) {
            return;
        }

        $this->NotifyOutputVariableChanged($varID, (int)GetValue($varID));
    }

    public function RunCycle(): void
    {
        if (!$this->IsHeartbeatEffectivelyActive()) {
            $this->LogDebug('RunCycle skipped: heartbeat function disabled');
            return;
        }
        $lockName = 'AHW_RunCycle_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($lockName, 1000)) {
            $this->LogDebug('RunCycle skipped: semaphore busy');
            return;
        }

        try {
            $previousReport = $this->CheckPendingHeartbeat();
            $newTimestamp = $this->SendHeartbeat();
            $report = $this->BuildCombinedReport($previousReport, $newTimestamp);
            $this->StoreReport($report);
        } finally {
            IPS_SemaphoreLeave($lockName);
        }
    }

    public function SendHeartbeat(): int
    {
        $inputID = $this->ReadPropertyInteger('HeartbeatInputVariableID');
        if ($inputID <= 0 || !IPS_VariableExists($inputID)) {
            throw new Exception('Heartbeat input variable is not configured or missing.');
        }

        if (!$this->ReadPropertyBoolean('HeartbeatEnabled')) {
            throw new Exception('Heartbeat function is disabled.');
        }

        // Backward compatibility: the existing property ResetDelayMs is now interpreted as
        // pulse duration. The heartbeat token is active only for this period, then the
        // input variable is reset to 0 and remains inactive until the next cycle.
        $pulseDurationMs = max(0, $this->ReadPropertyInteger('ResetDelayMs'));
        $timeoutMs = $this->GetDeliveryTimeoutMilliseconds();

        $tokenMs = $this->GetDayMilliseconds();
        $deadlineMs = $this->AddDayMilliseconds($tokenMs, $timeoutMs);

        $this->WriteAttributeInteger('PendingTimestamp', $tokenMs);
        $this->WriteAttributeInteger('PendingSentAt', $tokenMs);
        $this->WriteAttributeInteger('PendingDeadline', $deadlineMs);

        $this->AddHeartbeatToHistory($tokenMs, $tokenMs, $deadlineMs);

        $this->SetValueSafe('LastSentTimestamp', $tokenMs);
        $this->SetValueSafe('LastSentText', $this->FormatDayMilliseconds($tokenMs));
        $this->SetValueSafe('PendingTimestamp', $tokenMs);
        $this->SetValueSafe('PendingDeadline', $deadlineMs);

        SetValue($inputID, $tokenMs);
        if ($pulseDurationMs > 0) {
            IPS_Sleep($pulseDurationMs);
        }
        SetValue($inputID, 0);

        $this->LogDebug(
            'Heartbeat pulse sent token_ms=' . $tokenMs .
                ' / ' . $this->FormatDayMilliseconds($tokenMs) .
                ', pulse_duration_ms=' . $pulseDurationMs .
                ', then reset to 0'
        );
        return $tokenMs;
    }

    public function ReceivePayload(string $payloadJson): bool
    {
        $nowSeconds = time();
        $nowMs = $this->GetDayMilliseconds();
        $payload = json_decode($payloadJson, true);
        if (!is_array($payload)) {
            $this->LogMessage('ReceivePayload: invalid JSON payload', KL_WARNING);
            return false;
        }

        $inputID = $this->ReadPropertyInteger('HeartbeatInputVariableID');
        $heartbeatTokenMs = $this->ExtractHeartbeatTimestampFromPayload($payload, $inputID);

        $counter = (int)$this->GetValueSafe('Module1Counter', 0) + 1;
        $this->SetValueSafe('Module1Counter', $counter);
        $this->SetValueSafe('Module1LastPayload', $payloadJson);

        // Pulse model:
        // Module 1 will normally report the active heartbeat token first and the reset value 0 shortly afterwards.
        // The reset payload proves that the inactive phase reached Module 1, but it must not overwrite the
        // last valid heartbeat-token result used by CheckModule1() and the status HTML.
        if ($heartbeatTokenMs <= 0) {
            $this->LogDebug('ReceivePayload: reset/inactive payload received, heartbeat token state not overwritten, counter=' . $counter);
            $this->RebuildStatus();
            return true;
        }

        $runtimeMs = $this->GetElapsedMilliseconds($heartbeatTokenMs, $nowMs);

        $this->SetValueSafe('Module1LastSeen', $nowSeconds);
        $this->SetValueSafe('Module1LastSeenText', date('Y-m-d H:i:s', $nowSeconds));
        $this->SetValueSafe('Module1LastHeartbeatTimestamp', $heartbeatTokenMs);
        $this->SetValueSafe('Module1RuntimeSeconds', $runtimeMs);

        $historyEntry = $this->FindHeartbeatHistoryEntry($heartbeatTokenMs);
        if (count($historyEntry) > 0) {
            $sentAtMs = (int)($historyEntry['sent_at'] ?? $heartbeatTokenMs);
            $deadlineMs = (int)($historyEntry['deadline'] ?? 0);
            $lateMs = $this->GetLateMilliseconds($sentAtMs, $deadlineMs, $nowMs);
            $state = ($lateMs > 0) ? 'LATE' : 'OK';
            $message = ($state === 'LATE')
                ? 'Module 1 callback arrived late by ' . $this->FormatRuntimeMilliseconds($lateMs) . '.'
                : 'OK';

            $this->UpdateHeartbeatHistoryTarget(
                $heartbeatTokenMs,
                'Module 1 callback',
                $state,
                $heartbeatTokenMs,
                $nowMs,
                $runtimeMs,
                $lateMs,
                $message
            );
        } else {
            $this->LogDebug('ReceivePayload: heartbeat token not found in retained history: token_ms=' . $heartbeatTokenMs);
        }

        $this->LogDebug('ReceivePayload: token_ms=' . $heartbeatTokenMs . ', runtime=' . $this->FormatRuntimeMilliseconds($runtimeMs) . ', counter=' . $counter);
        $this->RebuildStatus();
        return true;
    }

    public function RebuildStatus(): void
    {
        $report = json_decode($this->ReadAttributeString('LastCheckJson'), true);
        if (!is_array($report)) {
            $report = $this->BuildCombinedReport([], 0);
        }
        $this->StoreReport($report, false);
    }

    public function ClearRuntime(): void
    {
        $this->WriteAttributeInteger('PendingTimestamp', 0);
        $this->WriteAttributeInteger('PendingSentAt', 0);
        $this->WriteAttributeInteger('PendingDeadline', 0);
        $this->WriteAttributeBoolean('LastOverallOK', true);
        $this->WriteAttributeString('LastFailureText', '');
        $this->WriteAttributeString('LastCheckJson', '{}');
        $this->WriteAttributeString('HeartbeatHistoryJson', '[]');
        $this->WriteAttributeString('RegisteredWatchVariableIDsJson', $this->ReadAttributeString('RegisteredWatchVariableIDsJson'));

        foreach (
            [
                'LastCheckText',
                'StatusHTML',
                'LastSentText',
                'Module1LastSeenText',
                'Module1LastPayload',
                'AlarmStateJson',
                'HeartbeatHistoryJson'
            ] as $ident
        ) {
            $this->SetValueSafe($ident, '');
        }

        foreach (
            [
                'LastSentTimestamp',
                'PendingTimestamp',
                'PendingDeadline',
                'Module1LastSeen',
                'Module1LastHeartbeatTimestamp',
                'Module1RuntimeSeconds',
                'Module1Counter'
            ] as $ident
        ) {
            $this->SetValueSafe($ident, 0);
        }

        $this->SetValueSafe('OverallOK', true);
        $this->RebuildStatus();
    }

    public function GetHeartbeatHistory(): string
    {
        $historyJson = $this->ReadAttributeString('HeartbeatHistoryJson');
        $decoded = json_decode($historyJson, true);

        if (!is_array($decoded)) {
            return '[]';
        }

        return json_encode(
            $decoded,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ) ?: '[]';
    }

    public function GetCurrentStatus(): string
    {
        $statusJson = $this->ReadAttributeString('LastCheckJson');
        $decoded = json_decode($statusJson, true);

        if (!is_array($decoded)) {
            return '{}';
        }

        return json_encode(
            $decoded,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ) ?: '{}';
    }

    public function GetDiagnosticSnapshot(): string
    {
        $snapshot = [
            'generated_at' => time(),
            'generated_at_text' => date('Y-m-d H:i:s'),
            'current_status' => json_decode($this->GetCurrentStatus(), true),
            'heartbeat_history' => json_decode($this->GetHeartbeatHistory(), true),
            'managed_output_events' => $this->ReadManagedOutputEvents()
        ];

        return json_encode(
            $snapshot,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ) ?: '{}';
    }

    public function NotifyOutputVariableChanged(int $variableID, int $value): bool
    {
        $lockName = 'AHW_OutputEvent_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($lockName, 5000)) {
            $this->LogMessage('NotifyOutputVariableChanged skipped: semaphore busy for variable ' . $variableID, KL_WARNING);
            return false;
        }

        try {
            if ($variableID <= 0 || !IPS_VariableExists($variableID)) {
                $this->LogDebug('NotifyOutputVariableChanged ignored: variable missing, variable=' . $variableID);
                return false;
            }

            $watch = $this->FindWatchByOutputVariableID($variableID);
            if (count($watch) === 0) {
                $this->LogDebug('NotifyOutputVariableChanged ignored: variable not in active WatchList, variable=' . $variableID);
                return false;
            }

            if ($value <= 0) {
                $value = (int)GetValue($variableID);
            }

            if ($value <= 0) {
                $this->LogDebug('NotifyOutputVariableChanged ignored reset/invalid value for variable=' . $variableID . ', value=' . $value);
                return true;
            }

            $this->HandleWatchedOutputUpdate($watch, $value);
            return true;
        } finally {
            IPS_SemaphoreLeave($lockName);
        }
    }

    public function SetHeartbeatActive(bool $active): void
    {
        $this->SetValueSafe('HeartbeatActive', $active);
        $this->ApplyHeartbeatActivationState(true);
    }



    private function InitializeHeartbeatActiveVariable(): void
    {
        $propertyEnabled = $this->ReadPropertyBoolean('HeartbeatEnabled');

        if (!$this->ReadAttributeBoolean('HeartbeatActiveInitialized')) {
            $this->SetValueSafe('HeartbeatActive', $propertyEnabled);
            $this->WriteAttributeBoolean('HeartbeatActiveInitialized', true);
            $this->WriteAttributeBoolean('LastPropertyHeartbeatEnabled', $propertyEnabled);
            return;
        }

        $lastPropertyEnabled = $this->ReadAttributeBoolean('LastPropertyHeartbeatEnabled');
        if ($propertyEnabled !== $lastPropertyEnabled) {
            $this->SetValueSafe('HeartbeatActive', $propertyEnabled);
            $this->WriteAttributeBoolean('LastPropertyHeartbeatEnabled', $propertyEnabled);
        }
    }

    private function IsHeartbeatEffectivelyActive(): bool
    {
        return $this->ReadPropertyBoolean('HeartbeatEnabled') && (bool)$this->GetValueSafe('HeartbeatActive', false);
    }

    private function ApplyHeartbeatActivationState(bool $initializeWhenActivated): void
    {
        $active = $this->IsHeartbeatEffectivelyActive();
        $wasActive = $this->ReadAttributeBoolean('LastEffectiveHeartbeatActive');

        if (!$active) {
            $this->SetTimerInterval('HeartbeatTimer', 0);
            $this->ResetHeartbeatInputVariable();

            $this->WriteAttributeInteger('PendingTimestamp', 0);
            $this->WriteAttributeInteger('PendingSentAt', 0);
            $this->WriteAttributeInteger('PendingDeadline', 0);

            $this->SetValueSafe('PendingTimestamp', 0);
            $this->SetValueSafe('PendingDeadline', 0);
            $this->SetValueSafe('OverallOK', true);
            $this->SetValueSafe('LastCheckText', 'Heartbeat disabled / ' . date('Y-m-d H:i:s'));

            $this->WriteAttributeBoolean('LastEffectiveHeartbeatActive', false);
            $this->RebuildStatus();
            return;
        }

        $interval = max(10, $this->ReadPropertyInteger('CycleIntervalSeconds')) * 1000;
        $this->SetTimerInterval('HeartbeatTimer', $interval);

        if (!$wasActive && $initializeWhenActivated) {
            $this->WriteAttributeBoolean('LastEffectiveHeartbeatActive', true);
            $this->InitializeHeartbeatFromScratch();
            return;
        }

        $this->WriteAttributeBoolean('LastEffectiveHeartbeatActive', true);
        $this->RebuildStatus();
    }

    private function InitializeHeartbeatFromScratch(): void
    {
        $lockName = 'AHW_Initialize_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($lockName, 3000)) {
            $this->LogDebug('InitializeHeartbeatFromScratch skipped: semaphore busy');
            return;
        }

        try {
            $this->SetTimerInterval('HeartbeatTimer', 0);
            $this->ResetHeartbeatInputVariable();

            $this->ClearRuntime();

            $interval = max(10, $this->ReadPropertyInteger('CycleIntervalSeconds')) * 1000;
            $this->SetTimerInterval('HeartbeatTimer', $interval);

            $this->RunCycle();
        } finally {
            IPS_SemaphoreLeave($lockName);
        }
    }

    private function ResetHeartbeatInputVariable(): void
    {
        $inputID = $this->ReadPropertyInteger('HeartbeatInputVariableID');
        if ($inputID > 0 && IPS_VariableExists($inputID)) {
            SetValue($inputID, 0);
        }
    }

    private function CheckPendingHeartbeat(): array
    {
        $pending = $this->ReadAttributeInteger('PendingTimestamp');
        $sentAt = $this->ReadAttributeInteger('PendingSentAt');
        $deadline = $this->ReadAttributeInteger('PendingDeadline');
        $nowSeconds = time();
        $nowMs = $this->GetDayMilliseconds();

        if ($pending <= 0) {
            return [
                'checked' => false,
                'overall_ok' => true,
                'overall_warning' => false,
                'summary' => 'No pending heartbeat to check.',
                'pending' => 0,
                'sent_at' => 0,
                'deadline' => 0,
                'module1' => [],
                'targets' => []
            ];
        }

        $module1 = $this->CheckModule1($pending, $sentAt, $deadline, $nowSeconds, $nowMs);
        $targets = [];
        foreach ($this->GetActiveWatchList() as $watch) {
            $targets[] = $this->CheckWatchTarget($watch, $pending, $sentAt, $deadline, $nowSeconds, $nowMs);
        }

        $overallOK = $module1['ok'];
        $overallWarning = !empty($module1['warning']);
        foreach ($targets as $target) {
            $overallOK = $overallOK && $target['ok'];
            $overallWarning = $overallWarning || !empty($target['warning']);
        }

        if (!$overallOK) {
            $summary = 'ERROR: pending heartbeat missing, stale or delayed.';
        } elseif ($overallWarning) {
            $summary = 'WARNING: heartbeat value present, but at least one update event was not recorded.';
        } else {
            $summary = 'OK: pending heartbeat delivered to all active targets.';
        }

        return [
            'checked' => true,
            'overall_ok' => $overallOK,
            'overall_warning' => $overallWarning,
            'summary' => $summary,
            'pending' => $pending,
            'sent_at' => $sentAt,
            'deadline' => $deadline,
            'checked_at' => $nowSeconds,
            'checked_at_ms' => $nowMs,
            'module1' => $module1,
            'targets' => $targets
        ];
    }

    private function CheckModule1(int $pending, int $sentAt, int $deadline, int $now): array
    {
        $lastSeen = (int)$this->GetValueSafe('Module1LastSeen', 0);
        $lastHeartbeat = (int)$this->GetValueSafe('Module1LastHeartbeatTimestamp', 0);
        $runtime = (int)$this->GetValueSafe('Module1RuntimeSeconds', -1);
        $maxAge = max(5, $this->ReadPropertyInteger('Module1MaxAgeSeconds'));

        $seenAge = ($lastSeen > 0) ? $now - $lastSeen : PHP_INT_MAX;
        $state = 'UNKNOWN';
        $message = 'Unknown Module 1 callback state.';
        $lateSeconds = -1;

        /*
     * Important for the short-pulse heartbeat model:
     *
     * A heartbeat token is valid once Module 1 has confirmed the expected token.
     * The later reset-to-0 callback and the age of the last callback must not turn
     * an already matched heartbeat token into MISSING or STALE.
     */
        $historyEntry = $this->FindHeartbeatHistoryEntry($pending);
        $historyTargets = $historyEntry['targets'] ?? [];
        $historyModule1 = [];

        if (is_array($historyTargets) && isset($historyTargets['Module 1 callback']) && is_array($historyTargets['Module 1 callback'])) {
            $historyModule1 = $historyTargets['Module 1 callback'];
        }

        if (count($historyModule1) > 0) {
            $historyState = (string)($historyModule1['state'] ?? '');
            $historyValue = (int)($historyModule1['value'] ?? 0);
            $historyUpdated = (int)($historyModule1['updated'] ?? 0);
            $historyRuntime = (int)($historyModule1['runtime_seconds'] ?? -1);
            $historyLateSeconds = (int)($historyModule1['late_seconds'] ?? -1);
            $historyMessage = (string)($historyModule1['message'] ?? 'OK');

            if ($historyValue === $pending && ($historyState === 'OK' || $historyState === 'LATE')) {
                return [
                    'name' => 'Module 1 callback',
                    'ok' => ($historyState === 'OK'),
                    'state' => $historyState,
                    'last_seen' => $historyUpdated,
                    'last_seen_text' => $historyUpdated > 0 ? date('Y-m-d H:i:s', $historyUpdated) : '-',
                    'last_heartbeat' => $historyValue,
                    'expected' => $pending,
                    'runtime_seconds' => $historyRuntime,
                    'age_seconds' => $historyUpdated > 0 ? ($now - $historyUpdated) : -1,
                    'late_seconds' => $historyLateSeconds,
                    'message' => $historyMessage
                ];
            }
        }

        if ($lastHeartbeat === $pending) {
            if ($lastSeen > $deadline) {
                $state = 'LATE';
                $lateSeconds = $lastSeen - $deadline;
                $message = 'Module 1 callback arrived late by ' . $lateSeconds . ' seconds.';
            } else {
                /*
             * Corrected:
             * Do not mark the expected token as STALE merely because the check
             * happens after the callback is older than Module1MaxAgeSeconds.
             *
             * For this specific pending token, equality is the decisive proof.
             */
                $state = 'OK';
                $message = 'OK';
            }
        } else {
            if ($lastHeartbeat > 0) {
                $oldHistoryEntry = $this->FindHeartbeatHistoryEntry($lastHeartbeat);
                if (count($oldHistoryEntry) > 0) {
                    $oldDeadline = (int)($oldHistoryEntry['deadline'] ?? 0);
                    $oldLateSeconds = ($oldDeadline > 0 && $lastSeen > $oldDeadline) ? ($lastSeen - $oldDeadline) : -1;
                    $oldState = ($oldLateSeconds > 0) ? 'LATE' : 'OK';
                    $oldMessage = ($oldState === 'LATE')
                        ? 'Module 1 callback arrived late by ' . $oldLateSeconds . ' seconds.'
                        : 'OK';

                    $this->UpdateHeartbeatHistoryTarget(
                        $lastHeartbeat,
                        'Module 1 callback',
                        $oldState,
                        $lastHeartbeat,
                        $lastSeen,
                        $runtime,
                        $oldLateSeconds,
                        $oldMessage
                    );
                }
            }

            if ($now > $deadline) {
                $state = 'MISSING';
                $message = 'Module 1 callback for expected heartbeat is missing.';
            } else {
                $state = 'WAITING';
                $message = 'Waiting for Module 1 callback.';
            }
        }

        $ok = ($state === 'OK');

        $this->UpdateHeartbeatHistoryTarget(
            $pending,
            'Module 1 callback',
            $state,
            $lastHeartbeat,
            $lastSeen,
            $runtime,
            $lateSeconds,
            $message
        );

        return [
            'name' => 'Module 1 callback',
            'ok' => $ok,
            'state' => $state,
            'last_seen' => $lastSeen,
            'last_seen_text' => $lastSeen > 0 ? date('Y-m-d H:i:s', $lastSeen) : '-',
            'last_heartbeat' => $lastHeartbeat,
            'expected' => $pending,
            'runtime_seconds' => $runtime,
            'age_seconds' => $seenAge === PHP_INT_MAX ? -1 : $seenAge,
            'late_seconds' => $lateSeconds,
            'message' => $message
        ];
    }

    private function CheckWatchTarget(array $watch, int $pending, int $sentAt, int $deadline, int $nowSeconds, int $nowMs): array
    {
        $name = (string)($watch['Name'] ?? 'Unnamed target');
        $varID = (int)($watch['OutputVariableID'] ?? 0);

        if ($varID <= 0 || !IPS_VariableExists($varID)) {
            $this->UpdateHeartbeatHistoryTarget(
                $pending,
                $name,
                'VARIABLE_MISSING',
                0,
                0,
                -1,
                -1,
                'Output variable missing.'
            );

            return [
                'name' => $name,
                'ok' => false,
                'warning' => false,
                'state' => 'VARIABLE_MISSING',
                'variable_id' => $varID,
                'value' => null,
                'updated' => 0,
                'updated_ms' => 0,
                'updated_text' => '-',
                'runtime_ms' => -1,
                'runtime_seconds' => -1,
                'age_seconds' => -1,
                'late_ms' => -1,
                'late_seconds' => -1,
                'message' => 'Output variable missing.'
            ];
        }

        $value = (int)GetValue($varID);

        // Primary success path: the output variable change is recorded immediately by MessageSink().
        // This avoids using IPS_GetVariable()['VariableUpdated'], which only has second precision.
        $recordedTarget = $this->FindHeartbeatHistoryTarget($pending, $name);
        if (count($recordedTarget) > 0) {
            $state = (string)($recordedTarget['state'] ?? 'UNKNOWN');
            $runtimeMs = (int)($recordedTarget['runtime_ms'] ?? -1);
            $lateMs = (int)($recordedTarget['late_ms'] ?? -1);
            $updatedMs = (int)($recordedTarget['updated'] ?? 0);
            $message = (string)($recordedTarget['message'] ?? '-');

            return [
                'name' => $name,
                'ok' => ($state === 'OK' || $state === 'VALUE_PRESENT_NO_EVENT'),
                'warning' => ($state === 'VALUE_PRESENT_NO_EVENT'),
                'state' => $state,
                'variable_id' => $varID,
                'value' => $value,
                'expected' => $pending,
                'updated' => 0,
                'updated_ms' => $updatedMs,
                'updated_text' => $updatedMs > 0 ? $this->FormatDayMilliseconds($updatedMs) : '-',
                'runtime_ms' => $runtimeMs,
                'runtime_seconds' => $runtimeMs >= 0 ? (int)floor($runtimeMs / 1000) : -1,
                'age_seconds' => -1,
                'late_ms' => $lateMs,
                'late_seconds' => $lateMs >= 0 ? (int)floor($lateMs / 1000) : -1,
                'message' => $message
            ];
        }

        // Fallback: if the value has changed but no MessageSink record exists, do not use VariableUpdated
        // for latency. Report the missing event subscription/arrival information explicitly.
        $warning = false;
        if ($value === $pending) {
            if ($this->IsDeadlineExceeded($sentAt, $deadline, $nowMs)) {
                $state = 'VALUE_PRESENT_NO_EVENT';
                $message = 'Expected token is present, but no Module 3 update event was recorded. Delivery succeeded, but precise runtime could not be measured.';
                $ok = true;
                $warning = true;
            } else {
                $state = 'WAITING';
                $message = 'Expected token is present, waiting for Module 3 update event.';
                $ok = false;
            }
        } else {
            if ($this->IsDeadlineExceeded($sentAt, $deadline, $nowMs)) {
                $state = 'MISSING';
                $message = 'Expected heartbeat token is missing.';
            } else {
                $state = 'WAITING';
                $message = 'Waiting for expected heartbeat token.';
            }
            $ok = false;
        }

        $this->UpdateHeartbeatHistoryTarget(
            $pending,
            $name,
            $state,
            $value,
            0,
            -1,
            -1,
            $message
        );

        return [
            'name' => $name,
            'ok' => $ok,
            'warning' => $warning,
            'state' => $state,
            'variable_id' => $varID,
            'value' => $value,
            'expected' => $pending,
            'updated' => 0,
            'updated_ms' => 0,
            'updated_text' => '-',
            'runtime_ms' => -1,
            'runtime_seconds' => -1,
            'age_seconds' => -1,
            'late_ms' => -1,
            'late_seconds' => -1,
            'message' => $message
        ];
    }

    private function BuildCombinedReport(array $previousReport, int $newTimestamp): array
    {
        return [
            'generated_at' => time(),
            'generated_at_text' => date('Y-m-d H:i:s'),
            'generated_at_ms' => $this->GetDayMilliseconds(),
            'previous_check' => $previousReport,
            'new_heartbeat_timestamp' => $newTimestamp,
            'new_heartbeat_text' => $newTimestamp > 0 ? $this->FormatDayMilliseconds($newTimestamp) : '-',
            'token_mode' => 'milliseconds_since_midnight',
            'config' => [
                'heartbeat_input_variable_id' => $this->ReadPropertyInteger('HeartbeatInputVariableID'),
                'cycle_interval_seconds' => $this->ReadPropertyInteger('CycleIntervalSeconds'),
                'pulse_duration_ms' => $this->ReadPropertyInteger('ResetDelayMs'),
                'delivery_timeout_seconds' => $this->ReadPropertyInteger('DeliveryTimeoutSeconds'),
                'module1_max_age_seconds' => $this->ReadPropertyInteger('Module1MaxAgeSeconds')
            ]
        ];
    }

    private function StoreReport(array $report, bool $sendNotifications = true): void
    {
        $previous = $report['previous_check'] ?? [];
        $overallOK = (bool)($previous['overall_ok'] ?? true);
        $overallWarning = (bool)($previous['overall_warning'] ?? false);
        $summary = (string)($previous['summary'] ?? 'No pending heartbeat checked yet.');

        $html = $this->BuildStatusHtml($report);
        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $this->SetValueSafe('OverallOK', $overallOK);
        $this->SetValueSafe('LastCheckText', $summary . ' / ' . date('Y-m-d H:i:s'));
        $this->SetValueSafe('StatusHTML', $html);
        $this->SetValueSafe('AlarmStateJson', $json ?: '{}');
        $this->WriteAttributeString('LastCheckJson', $json ?: '{}');

        if ($sendNotifications) {
            $this->HandleNotificationState($overallOK, $overallWarning, $summary, $html);
        }
    }

    private function HandleNotificationState(bool $overallOK, bool $overallWarning, string $summary, string $html): void
    {
        // Warning-only states are diagnostic only. They must not trigger failure or recovery email.
        if ($overallWarning) {
            return;
        }

        if (!$this->ReadPropertyBoolean('SendEmail')) {
            $this->WriteAttributeBoolean('LastOverallOK', $overallOK);
            $this->WriteAttributeString('LastFailureText', $overallOK ? '' : $summary);
            return;
        }

        $lastOK = $this->ReadAttributeBoolean('LastOverallOK');
        if ($lastOK === $overallOK) {
            return;
        }

        if ($overallOK) {
            $this->SendEmail('Alarm System Heartbeat wieder OK', $summary . "\n\n" . strip_tags($html));
        } else {
            $this->SendEmail('Alarm System Heartbeat Fehler', $summary . "\n\n" . strip_tags($html));
        }

        $this->WriteAttributeBoolean('LastOverallOK', $overallOK);
        $this->WriteAttributeString('LastFailureText', $overallOK ? '' : $summary);
    }

    private function SendEmail(string $subject, string $body): void
    {
        $smtpID = $this->ReadPropertyInteger('SmtpInstanceID');
        if ($smtpID <= 0 || !IPS_InstanceExists($smtpID)) {
            $this->LogMessage('Email not sent: SMTP instance missing.', KL_WARNING);
            return;
        }

        $to = trim($this->ReadPropertyString('EmailTo'));
        try {
            if ($to !== '' && function_exists('SMTP_SendMailEx')) {
                @SMTP_SendMailEx($smtpID, $to, $subject, $body);
            } else {
                @SMTP_SendMail($smtpID, $subject, $body);
            }
        } catch (Throwable $e) {
            $this->LogMessage('Email send failed: ' . $e->getMessage(), KL_WARNING);
        }
    }

    private function BuildStatusHtml(array $report): string
    {
        $previous = $report['previous_check'] ?? [];
        $overallOK = (bool)($previous['overall_ok'] ?? true);
        $overallWarning = (bool)($previous['overall_warning'] ?? false);
        $summary = htmlspecialchars((string)($previous['summary'] ?? 'No pending heartbeat checked yet.'), ENT_QUOTES, 'UTF-8');
        if (!$overallOK) {
            $statusColor = '#c62828';
            $statusText = 'ERROR';
        } elseif ($overallWarning) {
            $statusColor = '#ef6c00';
            $statusText = 'WARNING';
        } else {
            $statusColor = '#2e7d32';
            $statusText = 'OK';
        }

        $html = '<div style="font-family:Segoe UI,Arial,sans-serif;background:#1e1e1e;color:#e0e0e0;padding:12px;border-radius:8px;">';
        $html .= '<h2 style="margin:0 0 8px 0;color:#fff;">Alarm Heartbeat Watchdog</h2>';
        $html .= '<div style="padding:8px;border-radius:6px;background:' . $statusColor . ';color:#fff;font-weight:bold;">' . $statusText . ' - ' . $summary . '</div>';
        $html .= '<table style="margin-top:12px;border-collapse:collapse;width:100%;">';
        $html .= $this->HtmlRow('Generated at', (string)($report['generated_at_text'] ?? '-'));
        $html .= $this->HtmlRow('Token mode', 'milliseconds since midnight');
        $html .= $this->HtmlRow('New heartbeat token', (string)($report['new_heartbeat_timestamp'] ?? '-'));
        $html .= $this->HtmlRow('New heartbeat time', (string)($report['new_heartbeat_text'] ?? '-'));
        $html .= $this->HtmlRow('Pulse duration', (string)$this->ReadPropertyInteger('ResetDelayMs') . ' ms');
        $html .= '</table>';

        if (!empty($previous['checked'])) {
            $html .= '<h3 style="color:#fff;margin-top:14px;">Previous heartbeat check</h3>';
            $html .= '<table style="border-collapse:collapse;width:100%;">';
            $html .= $this->HtmlRow('Expected token', (string)($previous['pending'] ?? '-'));
            $html .= $this->HtmlRow('Sent at', isset($previous['sent_at']) && $previous['sent_at'] > 0 ? $this->FormatDayMilliseconds((int)$previous['sent_at']) : '-');
            $html .= $this->HtmlRow('Deadline', isset($previous['deadline']) && $previous['deadline'] > 0 ? $this->FormatDayMilliseconds((int)$previous['deadline']) : '-');
            $html .= '</table>';

            $module1 = $previous['module1'] ?? [];
            if (is_array($module1) && count($module1) > 0) {
                $html .= '<h3 style="color:#fff;margin-top:14px;">Module 1 callback</h3>';
                $html .= '<table style="border-collapse:collapse;width:100%;">';
                $html .= $this->HtmlRow('Status', (string)($module1['state'] ?? (!empty($module1['ok']) ? 'OK' : 'ERROR')));
                $html .= $this->HtmlRow('Expected', (string)($module1['expected'] ?? '-'));
                $html .= $this->HtmlRow('Received', (string)($module1['last_heartbeat'] ?? '-'));
                $html .= $this->HtmlRow('Last seen', (string)($module1['last_seen_text'] ?? '-'));
                $html .= $this->HtmlRow('Runtime', $this->FormatRuntimeMilliseconds((int)($module1['runtime_ms'] ?? -1)));
                $html .= $this->HtmlRow('Late by', $this->FormatRuntimeMilliseconds((int)($module1['late_ms'] ?? -1)));
                $html .= $this->HtmlRow('Message', (string)($module1['message'] ?? '-'));
                $html .= '</table>';
            }

            $targets = $previous['targets'] ?? [];
            if (is_array($targets) && count($targets) > 0) {
                $html .= '<h3 style="color:#fff;margin-top:14px;">Module 3 targets</h3>';
                $html .= '<table style="border-collapse:collapse;width:100%;">';
                $html .= '<tr style="background:#333;"><th style="padding:6px;border:1px solid #555;text-align:left;">Target</th><th style="padding:6px;border:1px solid #555;text-align:left;">State</th><th style="padding:6px;border:1px solid #555;text-align:left;">Expected</th><th style="padding:6px;border:1px solid #555;text-align:left;">Value</th><th style="padding:6px;border:1px solid #555;text-align:left;">Runtime</th><th style="padding:6px;border:1px solid #555;text-align:left;">Late by</th><th style="padding:6px;border:1px solid #555;text-align:left;">Updated</th><th style="padding:6px;border:1px solid #555;text-align:left;">Message</th></tr>';
                foreach ($targets as $target) {
                    $ok = !empty($target['ok']);
                    $warning = !empty($target['warning']);
                    $stateColor = $warning ? '#ffb74d' : ($ok ? '#81c784' : '#ef9a9a');
                    $html .= '<tr>';
                    $html .= '<td style="padding:6px;border:1px solid #555;">' . htmlspecialchars((string)($target['name'] ?? '-'), ENT_QUOTES, 'UTF-8') . '</td>';
                    $html .= '<td style="padding:6px;border:1px solid #555;color:' . $stateColor . ';font-weight:bold;">' . htmlspecialchars((string)($target['state'] ?? ($ok ? 'OK' : 'ERROR')), ENT_QUOTES, 'UTF-8') . '</td>';
                    $html .= '<td style="padding:6px;border:1px solid #555;">' . htmlspecialchars((string)($target['expected'] ?? '-'), ENT_QUOTES, 'UTF-8') . '</td>';
                    $html .= '<td style="padding:6px;border:1px solid #555;">' . htmlspecialchars((string)($target['value'] ?? '-'), ENT_QUOTES, 'UTF-8') . '</td>';
                    $html .= '<td style="padding:6px;border:1px solid #555;">' . htmlspecialchars($this->FormatRuntimeMilliseconds((int)($target['runtime_ms'] ?? -1)), ENT_QUOTES, 'UTF-8') . '</td>';
                    $html .= '<td style="padding:6px;border:1px solid #555;">' . htmlspecialchars($this->FormatRuntimeMilliseconds((int)($target['late_ms'] ?? -1)), ENT_QUOTES, 'UTF-8') . '</td>';
                    $html .= '<td style="padding:6px;border:1px solid #555;">' . htmlspecialchars((string)($target['updated_text'] ?? '-'), ENT_QUOTES, 'UTF-8') . '</td>';
                    $html .= '<td style="padding:6px;border:1px solid #555;">' . htmlspecialchars((string)($target['message'] ?? '-'), ENT_QUOTES, 'UTF-8') . '</td>';
                    $html .= '</tr>';
                }
                $html .= '</table>';
            }
        }

        $html .= $this->BuildHeartbeatHistoryHtml();
        $html .= '</div>';
        return $html;
    }

    private function HtmlRow(string $label, string $value): string
    {
        return '<tr><td style="padding:6px;border:1px solid #555;background:#2b2b2b;width:260px;font-weight:bold;">' .
            htmlspecialchars($label, ENT_QUOTES, 'UTF-8') .
            '</td><td style="padding:6px;border:1px solid #555;">' .
            htmlspecialchars($value, ENT_QUOTES, 'UTF-8') .
            '</td></tr>';
    }

    private function ExtractHeartbeatTimestampFromPayload(array $payload, int $heartbeatVariableID): int
    {
        $value = $this->FindHeartbeatValueRecursive($payload, $heartbeatVariableID);
        if ($value !== null) {
            return (int)$value;
        }

        $json = json_encode($payload);
        if (is_string($json)) {
            if (preg_match('/\b(1[0-9]{9})\b/', $json, $m)) {
                return (int)$m[1];
            }
            if (preg_match('/"(?:value_raw|ValueRaw|value|Value)"\s*:\s*([1-9][0-9]{0,7})\b/', $json, $m)) {
                return (int)$m[1];
            }
        }

        return 0;
    }

    private function FindHeartbeatValueRecursive($node, int $heartbeatVariableID)
    {
        if (!is_array($node)) {
            return null;
        }

        $varID = $node['VariableID'] ?? $node['variable_id'] ?? $node['VariableId'] ?? null;
        if ((int)$varID === $heartbeatVariableID) {
            foreach (['value_raw', 'ValueRaw', 'value', 'Value', 'value_human', 'ValueHuman'] as $key) {
                if (array_key_exists($key, $node) && is_numeric($node[$key])) {
                    return $node[$key];
                }
            }
        }

        foreach ($node as $child) {
            if (is_array($child)) {
                $found = $this->FindHeartbeatValueRecursive($child, $heartbeatVariableID);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    private function GetActiveWatchList(): array
    {
        $raw = $this->ReadPropertyString('WatchList');
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $result = [];
        foreach ($decoded as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (!($row['Active'] ?? false)) {
                continue;
            }
            $result[] = [
                'Active' => true,
                'Name' => (string)($row['Name'] ?? 'Unnamed target'),
                'OutputVariableID' => (int)($row['OutputVariableID'] ?? 0),
                'MaxAgeSeconds' => (int)($row['MaxAgeSeconds'] ?? 180)
            ];
        }
        return $result;
    }

    private function AddHeartbeatToHistory(int $timestamp, int $sentAt, int $deadline): void
    {
        $lockName = 'AHW_History_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($lockName, 5000)) {
            $this->LogMessage('AddHeartbeatToHistory skipped: heartbeat-history semaphore busy.', KL_WARNING);
            return;
        }

        try {
            $history = $this->ReadHeartbeatHistory();

            $history[] = [
                'timestamp' => $timestamp,
                'sent_at' => $sentAt,
                'sent_at_text' => $this->FormatDayMilliseconds($sentAt),
                'deadline' => $deadline,
                'deadline_text' => $this->FormatDayMilliseconds($deadline),
                'overall_state' => 'SENT',
                'targets' => []
            ];

            $history = array_slice($history, -10);
            $this->WriteHeartbeatHistory($history);
        } finally {
            IPS_SemaphoreLeave($lockName);
        }
    }

    private function UpdateHeartbeatHistoryTarget(
        int $timestamp,
        string $targetName,
        string $state,
        int $value,
        int $updated,
        int $runtime,
        int $lateMs,
        string $message
    ): void {
        $lockName = 'AHW_History_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($lockName, 5000)) {
            $this->LogMessage('UpdateHeartbeatHistoryTarget skipped: heartbeat-history semaphore busy for token ' . $timestamp . ' / ' . $targetName, KL_WARNING);
            return;
        }

        try {
            $history = $this->ReadHeartbeatHistory();

            foreach ($history as &$entry) {
                if ((int)($entry['timestamp'] ?? 0) !== $timestamp) {
                    continue;
                }

                if (!isset($entry['targets']) || !is_array($entry['targets'])) {
                    $entry['targets'] = [];
                }

                $existingTarget = $entry['targets'][$targetName] ?? null;
                if (is_array($existingTarget)) {
                    $existingState = (string)($existingTarget['state'] ?? 'UNKNOWN');
                    $existingIsFinalGood = in_array($existingState, ['OK', 'LATE'], true);
                    $newIsDowngradeCheck = in_array($state, ['WAITING', 'MISSING', 'VALUE_PRESENT_NO_EVENT'], true);

                    // Atomic hardening: once an event/callback has positively confirmed a token,
                    // a later polling/check pass must not downgrade that same target back to
                    // WAITING/MISSING or warning-only VALUE_PRESENT_NO_EVENT.
                    if ($existingIsFinalGood && $newIsDowngradeCheck) {
                        $entry['overall_state'] = $this->CalculateHeartbeatHistoryOverallState($entry['targets']);
                        break;
                    }
                }

                $entry['targets'][$targetName] = [
                    'state' => $state,
                    'value' => $value,
                    'updated' => $updated,
                    'updated_text' => $updated > 0 ? $this->FormatDayMilliseconds($updated) : '-',
                    'runtime_ms' => $runtime,
                    'runtime_text' => $this->FormatRuntimeMilliseconds($runtime),
                    'late_ms' => $lateMs,
                    'late_text' => $this->FormatRuntimeMilliseconds($lateMs),
                    'message' => $message
                ];

                // In this architecture, Module 3 can only receive the heartbeat after Module 1
                // processed and forwarded it. Therefore a downstream OK/LATE confirmation must
                // prevent Module 1 from remaining logically MISSING/WAITING for the same token.
                if ($targetName !== 'Module 1 callback' && in_array($state, ['OK', 'LATE'], true)) {
                    $module1Target = $entry['targets']['Module 1 callback'] ?? null;
                    $module1State = is_array($module1Target) ? (string)($module1Target['state'] ?? 'UNKNOWN') : '';
                    if (!is_array($module1Target) || in_array($module1State, ['WAITING', 'MISSING'], true)) {
                        $entry['targets']['Module 1 callback'] = [
                            'state' => 'OK',
                            'value' => $timestamp,
                            'updated' => 0,
                            'updated_text' => '-',
                            'runtime_ms' => -1,
                            'runtime_text' => '-',
                            'late_ms' => -1,
                            'late_text' => '-',
                            'message' => 'OK inferred from downstream Module 3 confirmation.'
                        ];
                    }
                }

                $entry['overall_state'] = $this->CalculateHeartbeatHistoryOverallState($entry['targets']);
                break;
            }

            unset($entry);
            $this->WriteHeartbeatHistory($history);
        } finally {
            IPS_SemaphoreLeave($lockName);
        }
    }

    private function CalculateHeartbeatHistoryOverallState(array $targets): string
    {
        if (count($targets) === 0) {
            return 'SENT';
        }

        $hasMissing = false;
        $hasLate = false;
        $hasWaiting = false;
        $hasWarning = false;
        $hasError = false;

        foreach ($targets as $target) {
            $state = (string)($target['state'] ?? 'UNKNOWN');

            if ($state === 'MISSING') {
                $hasMissing = true;
            } elseif ($state === 'LATE') {
                $hasLate = true;
            } elseif ($state === 'WAITING') {
                $hasWaiting = true;
            } elseif ($state === 'VALUE_PRESENT_NO_EVENT') {
                $hasWarning = true;
            } elseif ($state !== 'OK') {
                $hasError = true;
            }
        }

        if ($hasMissing) {
            return 'MISSING';
        }

        if ($hasLate) {
            return 'LATE';
        }

        if ($hasError) {
            return 'ERROR';
        }

        if ($hasWaiting) {
            return 'WAITING';
        }

        if ($hasWarning) {
            return 'WARNING';
        }

        return 'OK';
    }

    private function ReadHeartbeatHistory(): array
    {
        $raw = $this->ReadAttributeString('HeartbeatHistoryJson');
        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            return [];
        }

        return $decoded;
    }

    private function FindHeartbeatHistoryEntry(int $timestamp): array
    {
        if ($timestamp <= 0) {
            return [];
        }

        foreach ($this->ReadHeartbeatHistory() as $entry) {
            if ((int)($entry['timestamp'] ?? 0) === $timestamp) {
                return is_array($entry) ? $entry : [];
            }
        }

        return [];
    }

    private function WriteHeartbeatHistory(array $history): void
    {
        $json = json_encode($history, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            $json = '[]';
        }

        $this->WriteAttributeString('HeartbeatHistoryJson', $json);
        $this->SetValueSafe('HeartbeatHistoryJson', $json);
    }


    private function BuildHeartbeatHistoryHtml(): string
    {
        $history = array_reverse($this->ReadHeartbeatHistory());
        if (count($history) === 0) {
            return '';
        }

        $html = '<h3 style="color:#fff;margin-top:14px;">Heartbeat history</h3>';
        $html .= '<table style="border-collapse:collapse;width:100%;">';
        $html .= '<tr style="background:#333;"><th style="padding:6px;border:1px solid #555;text-align:left;">Token</th><th style="padding:6px;border:1px solid #555;text-align:left;">Sent</th><th style="padding:6px;border:1px solid #555;text-align:left;">Deadline</th><th style="padding:6px;border:1px solid #555;text-align:left;">State</th><th style="padding:6px;border:1px solid #555;text-align:left;">Module 1</th><th style="padding:6px;border:1px solid #555;text-align:left;">Module 3 targets</th></tr>';

        foreach ($history as $entry) {
            $targets = is_array($entry['targets'] ?? null) ? $entry['targets'] : [];
            $module1Text = '-';
            $targetTexts = [];

            foreach ($targets as $targetName => $target) {
                $state = (string)($target['state'] ?? '-');
                $runtime = (string)($target['runtime_text'] ?? '-');
                $text = $targetName . ': ' . $state;
                if ($runtime !== '-') {
                    $text .= ' (' . $runtime . ')';
                }

                if ($targetName === 'Module 1 callback') {
                    $module1Text = $text;
                } else {
                    $targetTexts[] = $text;
                }
            }

            $entryState = (string)($entry['overall_state'] ?? '-');
            $entryStateColor = ($entryState === 'WARNING') ? '#ffb74d' : (($entryState === 'OK') ? '#81c784' : '#ef9a9a');
            $html .= '<tr>';
            $html .= '<td style="padding:6px;border:1px solid #555;">' . htmlspecialchars((string)($entry['timestamp'] ?? '-'), ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '<td style="padding:6px;border:1px solid #555;">' . htmlspecialchars((string)($entry['sent_at_text'] ?? '-'), ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '<td style="padding:6px;border:1px solid #555;">' . htmlspecialchars((string)($entry['deadline_text'] ?? '-'), ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '<td style="padding:6px;border:1px solid #555;color:' . $entryStateColor . ';font-weight:bold;">' . htmlspecialchars($entryState, ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '<td style="padding:6px;border:1px solid #555;">' . htmlspecialchars($module1Text, ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '<td style="padding:6px;border:1px solid #555;">' . htmlspecialchars(count($targetTexts) > 0 ? implode(' | ', $targetTexts) : '-', ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '</tr>';
        }

        $html .= '</table>';
        return $html;
    }

    private function GetDayMilliseconds(): int
    {
        $now = microtime(true);
        $secondsToday = ((int)date('G', (int)$now) * 3600)
            + ((int)date('i', (int)$now) * 60)
            + (int)date('s', (int)$now);
        $milliseconds = (int)floor(($now - floor($now)) * 1000);
        $result = ($secondsToday * 1000) + $milliseconds;
        return $result > 0 ? $result : 1;
    }

    private function GetDayMillisecondsFromEpochSeconds(int $epochSeconds): int
    {
        if ($epochSeconds <= 0) {
            return 0;
        }

        return ((int)date('G', $epochSeconds) * 3600000)
            + ((int)date('i', $epochSeconds) * 60000)
            + ((int)date('s', $epochSeconds) * 1000);
    }

    private function AddDayMilliseconds(int $startMs, int $deltaMs): int
    {
        $result = ($startMs + $deltaMs) % 86400000;
        return $result > 0 ? $result : 1;
    }

    private function GetElapsedMilliseconds(int $startMs, int $endMs): int
    {
        if ($startMs <= 0 || $endMs <= 0) {
            return -1;
        }

        $elapsed = $endMs - $startMs;
        if ($elapsed < 0) {
            $elapsed += 86400000;
        }

        return $elapsed;
    }

    private function GetDeliveryTimeoutMilliseconds(): int
    {
        return max(5, $this->ReadPropertyInteger('DeliveryTimeoutSeconds')) * 1000;
    }

    private function IsDeadlineExceeded(int $sentAtMs, int $deadlineMs, int $observedMs): bool
    {
        if ($sentAtMs <= 0 || $deadlineMs <= 0 || $observedMs <= 0) {
            return false;
        }

        $allowedMs = $this->GetElapsedMilliseconds($sentAtMs, $deadlineMs);
        $actualMs = $this->GetElapsedMilliseconds($sentAtMs, $observedMs);

        return $actualMs > $allowedMs;
    }

    private function GetLateMilliseconds(int $sentAtMs, int $deadlineMs, int $observedMs): int
    {
        if (!$this->IsDeadlineExceeded($sentAtMs, $deadlineMs, $observedMs)) {
            return -1;
        }

        $allowedMs = $this->GetElapsedMilliseconds($sentAtMs, $deadlineMs);
        $actualMs = $this->GetElapsedMilliseconds($sentAtMs, $observedMs);
        return max(0, $actualMs - $allowedMs);
    }

    private function FormatDayMilliseconds(int $milliseconds): string
    {
        if ($milliseconds <= 0) {
            return '-';
        }

        $milliseconds = $milliseconds % 86400000;
        $hours = intdiv($milliseconds, 3600000);
        $milliseconds %= 3600000;
        $minutes = intdiv($milliseconds, 60000);
        $milliseconds %= 60000;
        $seconds = intdiv($milliseconds, 1000);
        $ms = $milliseconds % 1000;

        return sprintf('%02d:%02d:%02d.%03d', $hours, $minutes, $seconds, $ms);
    }

    private function FormatRuntimeMilliseconds(int $milliseconds): string
    {
        if ($milliseconds < 0) {
            return '-';
        }

        if ($milliseconds < 1000) {
            return $milliseconds . ' ms';
        }

        return number_format($milliseconds / 1000, 3, '.', '') . ' s';
    }

    private function SyncManagedOutputEvents(array $activeWatchList): void
    {
        $managed = $this->ReadManagedOutputEvents();
        $current = [];
        $updatedManaged = [];

        foreach ($activeWatchList as $watch) {
            $varID = (int)($watch['OutputVariableID'] ?? 0);
            if ($varID <= 0 || !IPS_VariableExists($varID)) {
                continue;
            }
            $current[] = $varID;
        }

        $current = array_values(array_unique($current));

        foreach ($managed as $oldVarID => $eventID) {
            $oldVarID = (int)$oldVarID;
            $eventID = (int)$eventID;
            if (!in_array($oldVarID, $current, true)) {
                if ($eventID > 0 && IPS_EventExists($eventID)) {
                    IPS_DeleteEvent($eventID);
                    $this->LogDebug('Deleted managed output event ' . $eventID . ' for removed variable ' . $oldVarID);
                }
            }
        }

        foreach ($current as $varID) {
            $eventID = isset($managed[(string)$varID]) ? (int)$managed[(string)$varID] : 0;
            if ($eventID <= 0 || !IPS_EventExists($eventID)) {
                $eventID = IPS_CreateEvent(0);
                $this->LogDebug('Created managed output event ' . $eventID . ' for variable ' . $varID);
            }

            $this->ConfigureManagedOutputEvent($eventID, $varID);
            $updatedManaged[(string)$varID] = $eventID;
        }

        $this->WriteAttributeString('ManagedOutputEventsJson', json_encode($updatedManaged, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function ConfigureManagedOutputEvent(int $eventID, int $variableID): void
    {
        $script =
            '$watchdogID = ' . $this->InstanceID . ';' . "\n" .
            '$variableID = ' . $variableID . ';' . "\n" .
            '$value = isset($_IPS[\'VALUE\']) ? (int)$_IPS[\'VALUE\'] : (IPS_VariableExists($variableID) ? (int)GetValue($variableID) : 0);' . "\n" .
            'AHW_NotifyOutputVariableChanged($watchdogID, $variableID, $value);';

        IPS_SetName($eventID, 'AHW Output Update ' . $variableID);
        IPS_SetParent($eventID, $this->InstanceID);

        // Trigger type 0 = On Variable Update / Bei Variablenaktualisierung.
        // We want every Module 3 write to be observed, not only value changes.
        IPS_SetEventTrigger($eventID, 0, $variableID);

        // Important: IPS_SetEventScript expects the PHP code body only, without <?php tags.
        // It also sets the event action to "Execute PHP Code".
        IPS_SetEventScript($eventID, $script);

        IPS_SetEventActive($eventID, true);
    }

    private function ReadManagedOutputEvents(): array
    {
        $raw = $this->ReadAttributeString('ManagedOutputEventsJson');
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $result = [];
        foreach ($decoded as $varID => $eventID) {
            $varID = (int)$varID;
            $eventID = (int)$eventID;
            if ($varID > 0 && $eventID > 0) {
                $result[(string)$varID] = $eventID;
            }
        }

        return $result;
    }

    private function SyncWatchedOutputMessages(array $activeWatchList): void
    {
        $previous = $this->ReadRegisteredWatchVariableIDs();
        $current = [];

        foreach ($activeWatchList as $watch) {
            $varID = (int)($watch['OutputVariableID'] ?? 0);
            if ($varID > 0 && IPS_VariableExists($varID)) {
                $current[] = $varID;
            }
        }

        $current = array_values(array_unique($current));

        foreach ($previous as $oldVarID) {
            if (!in_array($oldVarID, $current, true)) {
                $this->UnregisterMessage($oldVarID, VM_UPDATE);
                $this->LogDebug('Unregistered Module 3 output update message for variable ' . $oldVarID);
            }
        }

        foreach ($current as $newVarID) {
            if (!in_array($newVarID, $previous, true)) {
                $this->RegisterMessage($newVarID, VM_UPDATE);
                $this->LogDebug('Registered Module 3 output update message for variable ' . $newVarID);
            }
        }

        $this->WriteAttributeString('RegisteredWatchVariableIDsJson', json_encode($current, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function ReadRegisteredWatchVariableIDs(): array
    {
        $raw = $this->ReadAttributeString('RegisteredWatchVariableIDsJson');
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $result = [];
        foreach ($decoded as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $result[] = $id;
            }
        }

        return array_values(array_unique($result));
    }

    private function FindWatchByOutputVariableID(int $varID): array
    {
        if ($varID <= 0) {
            return [];
        }

        foreach ($this->GetActiveWatchList() as $watch) {
            if ((int)($watch['OutputVariableID'] ?? 0) === $varID) {
                return $watch;
            }
        }

        return [];
    }

    private function HandleWatchedOutputUpdate(array $watch, int $value): void
    {
        $name = (string)($watch['Name'] ?? 'Unnamed target');
        $varID = (int)($watch['OutputVariableID'] ?? 0);
        $observedMs = $this->GetDayMilliseconds();

        if ($value <= 0) {
            $this->LogDebug('Module 3 output update ignored for ' . $name . ' (' . $varID . '): value=' . $value);
            return;
        }

        $historyEntry = $this->FindHeartbeatHistoryEntry($value);
        if (count($historyEntry) === 0) {
            $this->LogDebug('Module 3 output update ignored for ' . $name . ' (' . $varID . '): token not in history=' . $value);
            return;
        }

        $sentAt = (int)($historyEntry['sent_at'] ?? $value);
        $deadline = (int)($historyEntry['deadline'] ?? 0);
        $runtimeMs = $this->GetElapsedMilliseconds($sentAt, $observedMs);
        $lateMs = $this->GetLateMilliseconds($sentAt, $deadline, $observedMs);
        $state = ($lateMs > 0) ? 'LATE' : 'OK';
        $message = ($state === 'LATE')
            ? 'Module 3 output event arrived late by ' . $this->FormatRuntimeMilliseconds($lateMs) . '.'
            : 'OK';

        $this->UpdateHeartbeatHistoryTarget(
            $value,
            $name,
            $state,
            $value,
            $observedMs,
            $runtimeMs,
            $lateMs,
            $message
        );

        $this->LogDebug('Module 3 output event: ' . $name . ', token=' . $value . ', runtime=' . $this->FormatRuntimeMilliseconds($runtimeMs));
        $this->RebuildStatus();
    }

    private function FindDownstreamHeartbeatConfirmation(int $timestamp): array
    {
        $entry = $this->FindHeartbeatHistoryEntry($timestamp);
        if (count($entry) === 0) {
            return [];
        }

        $targets = $entry['targets'] ?? [];
        if (!is_array($targets)) {
            return [];
        }

        foreach ($targets as $targetName => $target) {
            if ($targetName === 'Module 1 callback' || !is_array($target)) {
                continue;
            }

            $state = (string)($target['state'] ?? 'UNKNOWN');
            if (in_array($state, ['OK', 'LATE', 'VALUE_PRESENT_NO_EVENT'], true)) {
                return $target;
            }
        }

        return [];
    }

    private function FindHeartbeatHistoryTarget(int $timestamp, string $targetName): array
    {
        if ($timestamp <= 0 || $targetName === '') {
            return [];
        }

        $entry = $this->FindHeartbeatHistoryEntry($timestamp);
        if (count($entry) === 0) {
            return [];
        }

        $targets = $entry['targets'] ?? [];
        if (!is_array($targets) || !isset($targets[$targetName]) || !is_array($targets[$targetName])) {
            return [];
        }

        return $targets[$targetName];
    }

    private function SetValueSafe(string $ident, $value): void
    {
        $id = @$this->GetIDForIdent($ident);
        if ($id > 0) {
            SetValue($id, $value);
        }
    }

    private function GetValueSafe(string $ident, $default)
    {
        $id = @$this->GetIDForIdent($ident);
        if ($id > 0) {
            return GetValue($id);
        }
        return $default;
    }

    private function LogDebug(string $message): void
    {
        if ($this->ReadPropertyBoolean('DebugMode')) {
            $this->LogMessage('DEBUG: ' . $message, KL_MESSAGE);
        }
    }
}
