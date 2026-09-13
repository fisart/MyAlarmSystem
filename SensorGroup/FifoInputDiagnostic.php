<?php
declare(strict_types=1);

/** Opt-in passive native-input checks. Never acquire evaluator ownership or change outputs. */
trait SensorGroupFifoInputDiagnostic
{
    private function InputFifoDiagnosticLock(): string { return 'Mod1_InputDiagnostic_' . $this->InstanceID; }

    public function StartInputFifoDiagnostic(int $durationSeconds = 15): string
    {
        if ($durationSeconds < 1 || $durationSeconds > 30) throw new InvalidArgumentException('Choose a passive capture duration from 1 to 30 seconds.');
        if (!IPS_SemaphoreEnter($this->InputFifoDiagnosticLock(), 1)) throw new RuntimeException('Passive input check busy; retry Start.');
        try {
            if ($this->ReadPropertyBoolean('EnableInputFifo') || $this->ReadAttributeBoolean('FifoOwned') || $this->ReadAttributeBoolean('FifoCutover')) throw new RuntimeException('Disable live FIFO and finish Apply before starting the passive input check.');
            $now = hrtime(true);
            if ((int)$this->GetBuffer('InputFifoDiagnosticDeadline') > $now) throw new RuntimeException('Passive input check already running; print its report.');
            $revision = $this->ReadAttributeString('ActiveRevision');
            $config = $this->ActiveConfig();
            if ($revision === '' || $revision === 'updating' || !$config || AlarmSafety::validate($config)) throw new RuntimeException('A valid running configuration is required for the passive input check.');
            $ids = $this->FifoDependencies($config);
            if (count($ids) > 1024) throw new RuntimeException('Passive diagnostic dependency limit reached.');
            $token = bin2hex(random_bytes(8));
            $deadline = $now + $durationSeconds * 1000000000;
            $meta = ['schema' => 1, 'session' => $token, 'revision' => $revision, 'started_at' => date(DATE_ATOM), 'duration_seconds' => $durationSeconds, 'deadline_ns' => $deadline];
            $this->SetBuffer('InputFifoDiagnosticMeta', $this->FifoEncode($meta));
            $this->SetBuffer('InputFifoDiagnosticDependencies', $this->FifoEncode($ids));
            $this->SetBuffer('InputFifoDiagnosticExamples', '[]');
            $this->SetBuffer('InputFifoDiagnosticExampleCount', '0');
            $this->SetBuffer('InputFifoDiagnosticObserved', '0');
            $this->SetBuffer('InputFifoDiagnosticRejected', '0');
            $this->SetBuffer('InputFifoDiagnosticIncomplete', '');
            $this->SetBuffer('InputFifoDiagnosticStopReason', '');
            $this->SetBuffer('InputFifoDiagnosticToken', $token);
            if ($this->ReadPropertyBoolean('EnableInputFifo') || $this->ReadAttributeBoolean('FifoOwned') || $this->ReadAttributeBoolean('FifoCutover') || $revision !== $this->ReadAttributeString('ActiveRevision') || $token === $this->GetBuffer('InputFifoDiagnosticInterruptedToken')) {
                $this->SetBuffer('InputFifoDiagnosticDeadline', '');
                $this->SetBuffer('InputFifoDiagnosticIncomplete', 'Configuration Apply interrupted passive Start; retry after Apply finishes.');
                throw new RuntimeException('Configuration changed during passive Start; retry after Apply finishes.');
            }
            $this->SetBuffer('InputFifoDiagnosticDeadline', (string)$deadline);
            return 'Passive FIFO input check started. Keep live FIFO disabled; print the input FIFO report after ' . $durationSeconds . ' seconds. Existing alarm evaluation continues.';
        } finally { IPS_SemaphoreLeave($this->InputFifoDiagnosticLock()); }
    }

    private function InterruptInputFifoDiagnosticOnApply(): void
    {
        // Apply already owns its worker and has set Cutover. Never wait for diagnostic ownership.
        // Meta is published before the deadline, covering an in-progress Start as well.
        $raw = $this->GetBuffer('InputFifoDiagnosticMeta');
        if ($raw === '') return;
        $meta = json_decode($raw, true) ?: [];
        if (($meta['deadline_ns'] ?? 0) > hrtime(true) && is_string($meta['session'] ?? null)) {
            // Retain this token across subsequent Starts; a different session ignores it.
            $this->SetBuffer('InputFifoDiagnosticInterruptedToken', $meta['session']);
        }
    }

    public function StopInputFifoDiagnostic(): void
    {
        if (!IPS_SemaphoreEnter($this->InputFifoDiagnosticLock(), 1)) throw new RuntimeException('Passive input check busy; retry Stop.');
        try {
            $this->SetBuffer('InputFifoDiagnosticDeadline', '');
            $this->SetBuffer('InputFifoDiagnosticStopReason', 'manual stop');
        } finally { IPS_SemaphoreLeave($this->InputFifoDiagnosticLock()); }
    }

    private function ObserveInputFifoDiagnostic($counter, int $id, $data): void
    {
        // Off by default. One buffer read; the live FIFO branch does not call this observer.
        try {
            $deadline = $this->GetBuffer('InputFifoDiagnosticDeadline');
            if ($deadline === '') return;
            $token = $this->GetBuffer('InputFifoDiagnosticToken');
            if (!IPS_SemaphoreEnter($this->InputFifoDiagnosticLock(), 1)) {
                $this->SetBuffer('InputFifoDiagnosticIncomplete', 'At least one passive observation was omitted due to diagnostic contention.');
                return;
            }
            try {
                if ($deadline !== $this->GetBuffer('InputFifoDiagnosticDeadline') || $token !== $this->GetBuffer('InputFifoDiagnosticToken')) return;
                if ($token === $this->GetBuffer('InputFifoDiagnosticInterruptedToken')) {
                    $this->SetBuffer('InputFifoDiagnosticDeadline', '');
                    $this->SetBuffer('InputFifoDiagnosticStopReason', 'configuration Apply interrupted capture');
                    return;
                }
                if (hrtime(true) >= (int)$deadline) {
                    $this->SetBuffer('InputFifoDiagnosticDeadline', '');
                    $this->SetBuffer('InputFifoDiagnosticStopReason', 'duration complete');
                    return;
                }
                $observed = (int)$this->GetBuffer('InputFifoDiagnosticObserved') + 1;
                $this->SetBuffer('InputFifoDiagnosticObserved', (string)$observed);
                $error = $this->FifoNativeInputError($counter, $id, $data);
                if ($error !== '') {
                    $this->SetBuffer('InputFifoDiagnosticRejected', (string)((int)$this->GetBuffer('InputFifoDiagnosticRejected') + 1));
                    if ((int)$this->GetBuffer('InputFifoDiagnosticExampleCount') < 8) {
                        $examples = json_decode($this->GetBuffer('InputFifoDiagnosticExamples'), true) ?: [];
                        if (!in_array($id, array_column($examples, 'variable_id'), true)) {
                            $ids = json_decode($this->GetBuffer('InputFifoDiagnosticDependencies'), true) ?: [];
                            $examples[] = ['variable_id' => $id, 'observed_at' => date(DATE_ATOM), 'required_by_fifo_at_start' => in_array($id, $ids, true), 'reason' => $this->FifoReason($error)];
                            $this->SetBuffer('InputFifoDiagnosticExamples', $this->FifoEncode($examples));
                            $this->SetBuffer('InputFifoDiagnosticExampleCount', (string)count($examples));
                        }
                    }
                }
                if ($observed >= 10000) {
                    $this->SetBuffer('InputFifoDiagnosticDeadline', '');
                    $this->SetBuffer('InputFifoDiagnosticStopReason', 'observation limit');
                }
            } finally { IPS_SemaphoreLeave($this->InputFifoDiagnosticLock()); }
        } catch (Throwable $e) {
            // Diagnostic failures must not stop the existing evaluator or invoke FIFO recovery.
            try { $this->SetBuffer('InputFifoDiagnosticIncomplete', 'Passive diagnostic error; capture is incomplete.'); } catch (Throwable $ignored) {}
        }
    }

    private function InputFifoDiagnosticReport(): ?array
    {
        if ($this->GetBuffer('InputFifoDiagnosticMeta') === '') return null;
        if (!IPS_SemaphoreEnter($this->InputFifoDiagnosticLock(), 1)) return ['report_busy' => true, 'incomplete' => true, 'notice' => 'Passive counters are updating; retry the report.'];
        try {
            $meta = json_decode($this->GetBuffer('InputFifoDiagnosticMeta'), true) ?: [];
            if (!$meta) return null;
            $fifoActive = $this->ReadAttributeBoolean('FifoOwned');
            $interrupted = $meta['session'] === $this->GetBuffer('InputFifoDiagnosticInterruptedToken');
            $active = !$interrupted && !$fifoActive && (int)$this->GetBuffer('InputFifoDiagnosticDeadline') > hrtime(true);
            $observed = (int)$this->GetBuffer('InputFifoDiagnosticObserved');
            $rejected = (int)$this->GetBuffer('InputFifoDiagnosticRejected');
            $changed = $meta['revision'] !== $this->ReadAttributeString('ActiveRevision');
            return $meta + ['mode' => 'passive native-input contract only; does not activate FIFO', 'active' => $active,
                'observed' => $observed, 'rejected' => $rejected, 'accepted_contract' => $observed - $rejected,
                'incomplete' => $interrupted || $fifoActive || $changed || $this->GetBuffer('InputFifoDiagnosticIncomplete') !== '',
                'configuration_changed' => $changed, 'notice' => $interrupted ? 'Configuration Apply interrupted passive capture; repeat without changing settings.' : $this->GetBuffer('InputFifoDiagnosticIncomplete'),
                'stop_reason' => $interrupted ? 'configuration Apply interrupted capture' : ($this->GetBuffer('InputFifoDiagnosticStopReason') ?: ($active ? '' : ($fifoActive ? 'live FIFO active; passive capture interrupted' : 'duration complete'))),
                'examples' => json_decode($this->GetBuffer('InputFifoDiagnosticExamples'), true) ?: [],
                'limits' => ['duration_seconds' => 30, 'observations' => 10000, 'distinct_sources' => 8],
                'scope' => 'No admission-continuity, baseline, evaluator, delivery or quiescence validation. Results are volatile across library reload.'];
        } finally { IPS_SemaphoreLeave($this->InputFifoDiagnosticLock()); }
    }
}
