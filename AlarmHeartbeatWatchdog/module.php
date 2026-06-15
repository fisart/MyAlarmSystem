<?php

declare(strict_types=1);

// AlarmHeartbeatWatchdog module.php
// Version: 1.2.0
// Heartbeat token mode: milliseconds since midnight
// Notes: Keeps the existing HeartbeatEnabled switch and adds runtime-aware heartbeat history/status output.

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
        $this->RegisterPropertyInteger('ResetDelayMs', 1500);
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
        $this->RegisterAttributeString('HeartbeatHistoryJson', '[]');

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

        $inputID = $this->ReadPropertyInteger('HeartbeatInputVariableID');
        $watchList = $this->GetActiveWatchList();
        if (!$this->ReadPropertyBoolean('HeartbeatEnabled')) {
            $this->SetStatus(self::STATUS_ACTIVE);
            $this->SetTimerInterval('HeartbeatTimer', 0);
            $this->SetValueSafe('LastCheckText', 'Heartbeat function disabled / ' . date('Y-m-d H:i:s'));
            $this->RebuildStatus();
            return;
        }
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

        $interval = max(10, $this->ReadPropertyInteger('CycleIntervalSeconds')) * 1000;
        $this->SetTimerInterval('HeartbeatTimer', $interval);
        $this->RebuildStatus();
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'ReceivePayload':
                $this->ReceivePayload((string)$Value);
                return;
        }

        throw new Exception('Invalid ident: ' . $Ident);
    }

    public function RunCycle(): void
    {
        if (!$this->ReadPropertyBoolean('HeartbeatEnabled')) {
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

        $resetDelayMs = max(0, $this->ReadPropertyInteger('ResetDelayMs'));
        $timeoutMs = $this->GetDeliveryTimeoutMilliseconds();

        SetValue($inputID, 0);
        if ($resetDelayMs > 0) {
            IPS_Sleep($resetDelayMs);
        }

        $tokenMs = $this->GetDayMilliseconds();
        $deadlineMs = $this->AddDayMilliseconds($tokenMs, $timeoutMs);
        SetValue($inputID, $tokenMs);

        $this->WriteAttributeInteger('PendingTimestamp', $tokenMs);
        $this->WriteAttributeInteger('PendingSentAt', $tokenMs);
        $this->WriteAttributeInteger('PendingDeadline', $deadlineMs);

        $this->AddHeartbeatToHistory($tokenMs, $tokenMs, $deadlineMs);

        $this->SetValueSafe('LastSentTimestamp', $tokenMs);
        $this->SetValueSafe('LastSentText', $this->FormatDayMilliseconds($tokenMs));
        $this->SetValueSafe('PendingTimestamp', $tokenMs);
        $this->SetValueSafe('PendingDeadline', $deadlineMs);

        $this->LogDebug('Heartbeat sent token_ms=' . $tokenMs . ' / ' . $this->FormatDayMilliseconds($tokenMs));
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
        $runtimeMs = ($heartbeatTokenMs > 0) ? $this->GetElapsedMilliseconds($heartbeatTokenMs, $nowMs) : -1;

        $counter = (int)$this->GetValueSafe('Module1Counter', 0) + 1;
        $this->SetValueSafe('Module1Counter', $counter);
        $this->SetValueSafe('Module1LastSeen', $nowSeconds);
        $this->SetValueSafe('Module1LastSeenText', date('Y-m-d H:i:s', $nowSeconds));
        $this->SetValueSafe('Module1LastHeartbeatTimestamp', $heartbeatTokenMs);
        $this->SetValueSafe('Module1RuntimeSeconds', $runtimeMs);
        $this->SetValueSafe('Module1LastPayload', $payloadJson);

        if ($heartbeatTokenMs > 0) {
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
            }
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
        foreach ($targets as $target) {
            $overallOK = $overallOK && $target['ok'];
        }

        $summary = $overallOK
            ? 'OK: pending heartbeat delivered to all active targets.'
            : 'ERROR: pending heartbeat missing, stale or delayed.';

        return [
            'checked' => true,
            'overall_ok' => $overallOK,
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

    private function CheckModule1(int $pending, int $sentAt, int $deadline, int $nowSeconds, int $nowMs): array
    {
        $lastSeen = (int)$this->GetValueSafe('Module1LastSeen', 0);
        $lastHeartbeat = (int)$this->GetValueSafe('Module1LastHeartbeatTimestamp', 0);
        $runtimeMs = (int)$this->GetValueSafe('Module1RuntimeSeconds', -1);
        $maxAge = max(5, $this->ReadPropertyInteger('Module1MaxAgeSeconds'));

        $seenAge = ($lastSeen > 0) ? $nowSeconds - $lastSeen : PHP_INT_MAX;
        $state = 'UNKNOWN';
        $message = 'Unknown Module 1 callback state.';
        $lateMs = -1;

        if ($lastHeartbeat === $pending) {
            $lateMs = $this->GetLateMilliseconds($sentAt, $deadline, $this->GetDayMillisecondsFromEpochSeconds($lastSeen));
            if ($lateMs <= 0) {
                if ($seenAge <= $maxAge) {
                    $state = 'OK';
                    $message = 'OK';
                } else {
                    $state = 'STALE';
                    $message = 'Module 1 callback is stale.';
                }
            } else {
                $state = 'LATE';
                $message = 'Module 1 callback arrived late by ' . $this->FormatRuntimeMilliseconds($lateMs) . '.';
            }
        } else {
            if ($lastHeartbeat > 0) {
                $historyEntry = $this->FindHeartbeatHistoryEntry($lastHeartbeat);
                if (count($historyEntry) > 0) {
                    $oldSentAt = (int)($historyEntry['sent_at'] ?? $lastHeartbeat);
                    $oldDeadline = (int)($historyEntry['deadline'] ?? 0);
                    $oldObservedMs = $this->GetDayMillisecondsFromEpochSeconds($lastSeen);
                    $oldRuntimeMs = $this->GetElapsedMilliseconds($oldSentAt, $oldObservedMs);
                    $oldLateMs = $this->GetLateMilliseconds($oldSentAt, $oldDeadline, $oldObservedMs);
                    $oldState = ($oldLateMs > 0) ? 'LATE' : 'OK';
                    $oldMessage = ($oldState === 'LATE')
                        ? 'Module 1 callback arrived late by ' . $this->FormatRuntimeMilliseconds($oldLateMs) . '.'
                        : 'OK';

                    $this->UpdateHeartbeatHistoryTarget(
                        $lastHeartbeat,
                        'Module 1 callback',
                        $oldState,
                        $lastHeartbeat,
                        $oldObservedMs,
                        $oldRuntimeMs,
                        $oldLateMs,
                        $oldMessage
                    );
                }
            }

            if ($this->IsDeadlineExceeded($sentAt, $deadline, $nowMs)) {
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
            $lastSeen > 0 ? $this->GetDayMillisecondsFromEpochSeconds($lastSeen) : 0,
            $runtimeMs,
            $lateMs,
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
            'runtime_ms' => $runtimeMs,
            'runtime_seconds' => $runtimeMs >= 0 ? (int)floor($runtimeMs / 1000) : -1,
            'age_seconds' => $seenAge === PHP_INT_MAX ? -1 : $seenAge,
            'late_ms' => $lateMs,
            'late_seconds' => $lateMs >= 0 ? (int)floor($lateMs / 1000) : -1,
            'message' => $message
        ];
    }

    private function CheckWatchTarget(array $watch, int $pending, int $sentAt, int $deadline, int $nowSeconds, int $nowMs): array
    {
        $name = (string)($watch['Name'] ?? 'Unnamed target');
        $varID = (int)($watch['OutputVariableID'] ?? 0);
        $maxAge = max(5, (int)($watch['MaxAgeSeconds'] ?? 180));

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
                'state' => 'VARIABLE_MISSING',
                'variable_id' => $varID,
                'value' => null,
                'updated' => 0,
                'runtime_ms' => -1,
                'runtime_seconds' => -1,
                'age_seconds' => -1,
                'late_ms' => -1,
                'late_seconds' => -1,
                'message' => 'Output variable missing.'
            ];
        }

        $value = (int)GetValue($varID);
        $varInfo = IPS_GetVariable($varID);
        $updated = (int)($varInfo['VariableUpdated'] ?? 0);
        $updatedMs = $updated > 0 ? $this->GetDayMillisecondsFromEpochSeconds($updated) : 0;
        $age = ($updated > 0) ? $nowSeconds - $updated : PHP_INT_MAX;
        $runtimeMs = ($updated > 0) ? max(0, $this->GetElapsedMilliseconds($sentAt, $updatedMs)) : -1;
        $state = 'UNKNOWN';
        $message = 'Unknown heartbeat state.';
        $lateMs = -1;

        if ($value === $pending) {
            $lateMs = $this->GetLateMilliseconds($sentAt, $deadline, $updatedMs);
            if ($lateMs <= 0) {
                if ($age <= $maxAge) {
                    $state = 'OK';
                    $message = 'OK';
                } else {
                    $state = 'STALE';
                    $message = 'Expected token present but stale.';
                }
            } else {
                $state = 'LATE';
                $message = 'Heartbeat arrived late by ' . $this->FormatRuntimeMilliseconds($lateMs) . '.';
            }
        } else {
            if ($value > 0) {
                $historyEntry = $this->FindHeartbeatHistoryEntry($value);
                if (count($historyEntry) > 0) {
                    $oldSentAt = (int)($historyEntry['sent_at'] ?? $value);
                    $oldDeadline = (int)($historyEntry['deadline'] ?? 0);
                    $oldRuntimeMs = $updated > 0 ? max(0, $this->GetElapsedMilliseconds($oldSentAt, $updatedMs)) : -1;
                    $oldLateMs = $updated > 0 ? $this->GetLateMilliseconds($oldSentAt, $oldDeadline, $updatedMs) : -1;
                    $oldState = ($oldLateMs > 0) ? 'LATE' : 'OK';
                    $oldMessage = ($oldState === 'LATE')
                        ? 'Heartbeat arrived late by ' . $this->FormatRuntimeMilliseconds($oldLateMs) . '.'
                        : 'OK';

                    $this->UpdateHeartbeatHistoryTarget(
                        $value,
                        $name,
                        $oldState,
                        $value,
                        $updatedMs,
                        $oldRuntimeMs,
                        $oldLateMs,
                        $oldMessage
                    );
                }
            }

            if ($this->IsDeadlineExceeded($sentAt, $deadline, $nowMs)) {
                $state = 'MISSING';
                $message = 'Expected heartbeat token is missing.';
            } else {
                $state = 'WAITING';
                $message = 'Waiting for expected heartbeat token.';
            }
        }

        $ok = ($state === 'OK');

        $this->UpdateHeartbeatHistoryTarget(
            $pending,
            $name,
            $state,
            $value,
            $updatedMs,
            $runtimeMs,
            $lateMs,
            $message
        );

        return [
            'name' => $name,
            'ok' => $ok,
            'state' => $state,
            'variable_id' => $varID,
            'value' => $value,
            'expected' => $pending,
            'updated' => $updated,
            'updated_ms' => $updatedMs,
            'updated_text' => $updated > 0 ? date('Y-m-d H:i:s', $updated) : '-',
            'runtime_ms' => $runtimeMs,
            'runtime_seconds' => $runtimeMs >= 0 ? (int)floor($runtimeMs / 1000) : -1,
            'age_seconds' => $age === PHP_INT_MAX ? -1 : $age,
            'late_ms' => $lateMs,
            'late_seconds' => $lateMs >= 0 ? (int)floor($lateMs / 1000) : -1,
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
                'reset_delay_ms' => $this->ReadPropertyInteger('ResetDelayMs'),
                'delivery_timeout_seconds' => $this->ReadPropertyInteger('DeliveryTimeoutSeconds'),
                'module1_max_age_seconds' => $this->ReadPropertyInteger('Module1MaxAgeSeconds')
            ]
        ];
    }

    private function StoreReport(array $report, bool $sendNotifications = true): void
    {
        $previous = $report['previous_check'] ?? [];
        $overallOK = (bool)($previous['overall_ok'] ?? true);
        $summary = (string)($previous['summary'] ?? 'No pending heartbeat checked yet.');

        $html = $this->BuildStatusHtml($report);
        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $this->SetValueSafe('OverallOK', $overallOK);
        $this->SetValueSafe('LastCheckText', $summary . ' / ' . date('Y-m-d H:i:s'));
        $this->SetValueSafe('StatusHTML', $html);
        $this->SetValueSafe('AlarmStateJson', $json ?: '{}');
        $this->WriteAttributeString('LastCheckJson', $json ?: '{}');

        if ($sendNotifications) {
            $this->HandleNotificationState($overallOK, $summary, $html);
        }
    }

    private function HandleNotificationState(bool $overallOK, string $summary, string $html): void
    {
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
        $summary = htmlspecialchars((string)($previous['summary'] ?? 'No pending heartbeat checked yet.'), ENT_QUOTES, 'UTF-8');
        $statusColor = $overallOK ? '#2e7d32' : '#c62828';
        $statusText = $overallOK ? 'OK' : 'ERROR';

        $html = '<div style="font-family:Segoe UI,Arial,sans-serif;background:#1e1e1e;color:#e0e0e0;padding:12px;border-radius:8px;">';
        $html .= '<h2 style="margin:0 0 8px 0;color:#fff;">Alarm Heartbeat Watchdog</h2>';
        $html .= '<div style="padding:8px;border-radius:6px;background:' . $statusColor . ';color:#fff;font-weight:bold;">' . $statusText . ' - ' . $summary . '</div>';
        $html .= '<table style="margin-top:12px;border-collapse:collapse;width:100%;">';
        $html .= $this->HtmlRow('Generated at', (string)($report['generated_at_text'] ?? '-'));
        $html .= $this->HtmlRow('Token mode', 'milliseconds since midnight');
        $html .= $this->HtmlRow('New heartbeat token', (string)($report['new_heartbeat_timestamp'] ?? '-'));
        $html .= $this->HtmlRow('New heartbeat time', (string)($report['new_heartbeat_text'] ?? '-'));
        $html .= $this->HtmlRow('Reset delay', (string)$this->ReadPropertyInteger('ResetDelayMs') . ' ms');
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
                    $html .= '<tr>';
                    $html .= '<td style="padding:6px;border:1px solid #555;">' . htmlspecialchars((string)($target['name'] ?? '-'), ENT_QUOTES, 'UTF-8') . '</td>';
                    $html .= '<td style="padding:6px;border:1px solid #555;color:' . ($ok ? '#81c784' : '#ef9a9a') . ';font-weight:bold;">' . htmlspecialchars((string)($target['state'] ?? ($ok ? 'OK' : 'ERROR')), ENT_QUOTES, 'UTF-8') . '</td>';
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
        $history = $this->ReadHeartbeatHistory();

        foreach ($history as &$entry) {
            if ((int)($entry['timestamp'] ?? 0) !== $timestamp) {
                continue;
            }

            if (!isset($entry['targets']) || !is_array($entry['targets'])) {
                $entry['targets'] = [];
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

            $entry['overall_state'] = $this->CalculateHeartbeatHistoryOverallState($entry['targets']);
            break;
        }

        unset($entry);
        $this->WriteHeartbeatHistory($history);
    }

    private function CalculateHeartbeatHistoryOverallState(array $targets): string
    {
        if (count($targets) === 0) {
            return 'SENT';
        }

        $hasMissing = false;
        $hasLate = false;
        $hasWaiting = false;
        $hasError = false;

        foreach ($targets as $target) {
            $state = (string)($target['state'] ?? 'UNKNOWN');

            if ($state === 'MISSING') {
                $hasMissing = true;
            } elseif ($state === 'LATE') {
                $hasLate = true;
            } elseif ($state === 'WAITING') {
                $hasWaiting = true;
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

            $html .= '<tr>';
            $html .= '<td style="padding:6px;border:1px solid #555;">' . htmlspecialchars((string)($entry['timestamp'] ?? '-'), ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '<td style="padding:6px;border:1px solid #555;">' . htmlspecialchars((string)($entry['sent_at_text'] ?? '-'), ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '<td style="padding:6px;border:1px solid #555;">' . htmlspecialchars((string)($entry['deadline_text'] ?? '-'), ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '<td style="padding:6px;border:1px solid #555;font-weight:bold;">' . htmlspecialchars((string)($entry['overall_state'] ?? '-'), ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '<td style="padding:6px;border:1px solid #555;">' . htmlspecialchars($module1Text, ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '<td style="padding:6px;border:1px solid #555;">' . htmlspecialchars(count($targetTexts) > 0 ? implode(' | ', $targetTexts) : '-', ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '</tr>';
        }

        $html .= '</table>';
        return $html;
    }

    private function GetDayMilliseconds(): int
    {
        $milliseconds = (int)floor(microtime(true) * 1000) % 86400000;
        return $milliseconds > 0 ? $milliseconds : 1;
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
