<?php
declare(strict_types=1);

/** Opt-in ordered Module 1 evaluator. Existing receivers and output policy remain unchanged. */
trait SensorGroupFifoRuntime
{
    private const FIFO_SLOTS = 128;
    private const FIFO_BYTES = 262144;
    private const FIFO_STATE_BYTES = 524288;
    // One bounded native wait; no spin/retry loop or source-specific bypass.
    private const FIFO_QUEUE_WAIT_MS = 10;
    private const FIFO_WORKER_IDLE_MS = 50;
    private const FIFO_WORKER_TEST_CONTINUATION_MS = 10;
    private ?array $fifoFrame = null;
    private ?array $fifoValues = null;
    private ?array $fifoMaps = null;

    private function FifoCreate(): void
    {
        $this->RegisterPropertyBoolean('EnableInputFifo', false);
        $this->RegisterPropertyBoolean('AllowFifoTrialCutover', false);
        foreach (['FifoOwned', 'FifoReady', 'FifoHasPending', 'FifoApplyPending', 'FifoLegacyOverlap', 'FifoFrameInFlight', 'FifoFaultPending', 'FifoCutover', 'FifoTrialActive'] as $name) $this->RegisterAttributeBoolean($name, false);
        $this->RegisterAttributeString('FifoFence', '');
        $this->RegisterAttributeString('FifoIncident', '');
        $this->RegisterAttributeString('FifoLastFault', '');
        $this->RegisterAttributeString('FifoUnknownInputs', '[]');
        // A recreated interface cannot inherit an executing legacy PHP invocation.
        $this->WriteAttributeBoolean('FifoLegacyOverlap', false);
        $this->WriteAttributeBoolean('FifoOwned', false);
        $this->WriteAttributeBoolean('FifoReady', false);
        $this->WriteAttributeBoolean('FifoTrialActive', false);
        $this->RegisterVariableString('InputFifoHealth', 'Input FIFO Health', '', 93);
        $this->RegisterVariableString('InputFifoIncident', 'Input FIFO Incident', '', 94);
        $this->RegisterTimer('InputFifoWorker', 0, 'MYALARM_RunInputFifo($_IPS[\'TARGET\']);');
        $this->RegisterTimer('InputFifoRecovery', 0, 'MYALARM_RecoverInputFifo($_IPS[\'TARGET\']);');
    }

    private function FifoWorkerLock(): string { return 'Mod1_InputWorker_' . $this->InstanceID; }
    private function FifoQueueLock(): string { return 'Mod1_InputQueue_' . $this->InstanceID; }
    private function FifoFaultLock(): string { return 'Mod1_InputFault_' . $this->InstanceID; }
    private function FifoEncode(array $data): string { return json_encode($data, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION); }
    private function FifoMeta(): array { return json_decode($this->GetBuffer('InputFifoMeta'), true) ?: []; }
    private function FifoSaveMeta(array $meta): void { $this->SetBuffer('InputFifoMeta', $this->FifoEncode($meta)); }
    private function FifoFastContinuation(): bool { return $this->ReadAttributeBoolean('FifoTrialActive') || $this->ReadAttributeBoolean('FifoTestActive'); }
    private function FifoScalar($v): bool { return is_bool($v) || is_int($v) || (is_float($v) && is_finite($v)) || (is_string($v) && strlen($v) <= 1024 && preg_match('//u', $v) === 1); }

    /** Called before any applied graph/state mutation; caller retains owner until Apply returns. */
    private function FifoApplyEnter(): bool
    {
        if (!IPS_SemaphoreEnter($this->FifoWorkerLock(), 1)) {
            $this->WriteAttributeBoolean('FifoApplyPending', true);
            $this->SetTimerInterval('PostApplyTimer', 1000);
            return false;
        }
        if (($this->ReadAttributeBoolean('FifoOwned') && $this->ReadAttributeBoolean('FifoHasPending') && !($this->FifoTestPaused() && !$this->ReadAttributeBoolean('FifoReady'))) || !$this->FinalizeFifoTestFault()) {
            IPS_SemaphoreLeave($this->FifoWorkerLock());
            $this->WriteAttributeBoolean('FifoApplyPending', true);
            $this->SetTimerInterval('PostApplyTimer', 1000);
            return false;
        }
        $this->WriteAttributeBoolean('FifoCutover', true);
        $this->InterruptInputFifoDiagnosticOnApply();
        $this->WriteAttributeBoolean('FifoApplyPending', false);
        if ($this->ReadAttributeBoolean('FifoHasPending') || $this->ReadAttributeBoolean('FifoFrameInFlight')) {
            $this->FifoRecordIncident('Apply/restart interrupted pending evaluation; historical observations may be lost.');
        }
        $this->WriteAttributeString('FifoFence', bin2hex(random_bytes(8)));
        $test = $this->ReadPropertyBoolean('EnableFifoFailureCapture');
        $trial = $this->ReadPropertyBoolean('AllowFifoTrialCutover');
        $conflict = $test && $trial;
        $enable = $this->ReadPropertyBoolean('EnableInputFifo') && !$conflict && ($test || $trial || !$this->ReadAttributeBoolean('FifoLegacyOverlap'));
        $this->WriteAttributeBoolean('FifoTrialActive', $enable && $trial);
        $this->WriteAttributeBoolean('FifoTestActive', $enable && $test);
        if ($enable && $trial && $this->ReadAttributeBoolean('FifoLegacyOverlap')) {
            $this->FifoRecordIncident('Supervised FIFO trial accepts uncertain legacy overlap during switching; historical input edges may be lost.');
        }
        $this->WriteAttributeString('FifoTestPauseFence', '');
        $this->WriteAttributeString('FifoTestOmittedFence', '');
        $this->WriteAttributeBoolean('FifoOwned', $enable);
        $this->WriteAttributeBoolean('FifoReady', false);
        $this->WriteAttributeBoolean('FifoHasPending', false);
        $this->WriteAttributeBoolean('FifoFrameInFlight', false);
        $this->SetTimerInterval('InputFifoWorker', 0);
        $this->SetTimerInterval('InputFifoRecovery', 0);
        $this->SetValue('InputFifoIncident', $this->ReadAttributeString('FifoIncident'));
        $this->SetValue('InputFifoHealth', $enable ? 'Starting FIFO; acquiring current input baseline.' : ($this->ReadPropertyBoolean('EnableInputFifo') ? ($conflict ? 'FIFO activation blocked: supervised trial and failure capture cannot be enabled together. Existing evaluator active.' : 'FIFO activation blocked by retained legacy concurrency guard; keep live FIFO disabled and run the passive input check.') : 'FIFO disabled; existing evaluator active.'));
        return true;
    }

    private function FifoReason(string $reason): string
    {
        $prefix = substr($reason, 0, 512);
        // A split UTF-8 suffix must never make fault publication throw. Invalid interior data is replaced.
        $prefix = json_decode(json_encode($prefix, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR), true);
        return $prefix;
    }

    private function FifoRecordIncident(string $reason): void
    {
        // One latched bounded summary. No per-event logging/archive writes.
        if ($this->ReadAttributeString('FifoIncident') === '') {
            $incident = date(DATE_ATOM) . ' | ' . $this->FifoReason($reason);
            $this->WriteAttributeString('FifoIncident', $incident);
            $this->SetValue('InputFifoIncident', $incident);
        }
    }

    private function FifoFault(string $reason, string $fence, array $context = []): void
    {
        if ($fence !== $this->ReadAttributeString('FifoFence') || !$this->ReadAttributeBoolean('FifoOwned')) return;
        // No queue acquisition here: producer may already own it. Separate session-tagged latch.
        // Conservative fallback: even contention/stale races cannot report healthy after an omitted input.
        $alreadyPending = $this->FifoTestPaused();
        $this->WriteAttributeBoolean('FifoFaultPending', true);
        if ($this->ReadAttributeBoolean('FifoTestActive')) $this->CaptureFirstFifoTestFault($reason, $fence, $context, $alreadyPending);
        $this->FifoRecordIncident($reason);
        $this->SetTimerInterval('InputFifoRecovery', $this->ReadAttributeBoolean('FifoTestActive') ? 0 : 1000);
        if (!IPS_SemaphoreEnter($this->FifoFaultLock(), 1)) return;
        try {
            if ($fence !== $this->ReadAttributeString('FifoFence')) return;
            $raw = $this->FifoEncode(['fence' => $fence, 'revision' => $this->ReadAttributeString('ActiveRevision'), 'observed_at' => date(DATE_ATOM), 'reason' => $this->FifoReason($reason)]);
            $this->SetBuffer('InputFifoFault', $raw);
            // One bounded anomaly record survives recovery, disabling and interface recreation.
            if ($this->ReadAttributeString('FifoLastFault') !== $raw) $this->WriteAttributeString('FifoLastFault', $raw);
            $this->FifoRecordIncident($reason);
            $this->SetTimerInterval('InputFifoRecovery', $this->ReadAttributeBoolean('FifoTestActive') ? 0 : 1000);
        } finally { IPS_SemaphoreLeave($this->FifoFaultLock()); }
    }

    private function FifoFaultReason(string $fence): string
    {
        $fault = json_decode($this->GetBuffer('InputFifoFault'), true) ?: [];
        return ($fault['fence'] ?? '') === $fence ? (string)$fault['reason'] : ($this->ReadAttributeBoolean('FifoFaultPending') ? 'Input loss recorded; current baseline recovery required.' : '');
    }

    private function FifoAdmitInput($counter, int $id, $data, ?int $entered = null): void
    {
        if ($this->FifoTestPaused()) return;
        $entered ??= hrtime(true);
        $fence = $this->ReadAttributeString('FifoFence');
        $stage = 'native_input_validation'; $seq = null;
        try {
            $error = $this->FifoNativeInputError($counter, $id, $data);
            if ($error !== '') throw new RuntimeException($error);
            $this->FifoAdmit(['kind' => 'input', 'variable_id' => $id, 'value' => $data[0], 'previous' => $data[2], 'native_counter' => $counter], $fence, $entered, $stage, $seq);
        } catch (Throwable $e) { $this->FifoFault($e->getMessage(), $fence, ['stage' => $stage, 'variable_id' => $id, 'seq' => $seq, 'native_counter' => $counter, 'native_counter_type' => gettype($counter)]); }
    }

    private function FifoNativeInputError($counter, int $id, $data): string
    {
        if (is_int($counter) && is_array($data) && array_key_exists(0, $data) && isset($data[1]) && is_bool($data[1]) && array_key_exists(2, $data) && $this->FifoScalar($data[0]) && $this->FifoScalar($data[2])) return '';
        // Shared by live admission and passive diagnostics; never retain input string contents.
        $describe = static fn($v): string => get_debug_type($v) . (is_string($v) ? '(' . strlen($v) . ' bytes)' : '');
        $shape = 'counter=' . $describe($counter) . '; data=' . $describe($data);
        foreach ([0 => 'current', 1 => 'changed', 2 => 'previous'] as $key => $label) $shape .= '; ' . $label . '=' . (is_array($data) && array_key_exists($key, $data) ? $describe($data[$key]) : 'missing');
        return 'Invalid native input at variable ' . $id . ': ' . $shape . '; current baseline recovery required.';
    }

    private function FifoAdmitControl(string $source): void
    {
        if ($this->FifoTestPaused()) return;
        $fence = $this->ReadAttributeString('FifoFence');
        $stage = 'control_admission'; $seq = null;
        try { $this->FifoAdmit(['kind' => 'control', 'source' => $source, 'variable_id' => 0], $fence, hrtime(true), $stage, $seq); }
        catch (Throwable $e) { $this->FifoFault($e->getMessage(), $fence, ['stage' => $stage, 'seq' => $seq]); }
    }

    private function FifoAdmit(array $record, string $fence, int $entered, string &$stage, ?int &$seq): void
    {
        $stage = 'admission_lock';
        if (!IPS_SemaphoreEnter($this->FifoQueueLock(), self::FIFO_QUEUE_WAIT_MS)) throw new RuntimeException('Input admission mutex contention; observation omitted.');
        try {
            $meta = $this->FifoMeta();
            if ($fence !== $this->ReadAttributeString('FifoFence')) return;
            if ($this->FifoTestPaused()) return;
            if (!$this->ReadAttributeBoolean('FifoReady') || ($meta['fence'] ?? '') !== $fence) {
                $this->SetBuffer('InputFifoSetupGeneration', (string)((int)$this->GetBuffer('InputFifoSetupGeneration') + 1));
                $this->FifoRecordIncident('Input/control arrived before current FIFO baseline; historical edges are unavailable.');
                return;
            }
            // Ignore callbacks captured before this baseline, never apply old events to a new session.
            if ($entered < $meta['start_ns']) return;
            if ($this->FifoFaultReason($fence) !== '') {
                $meta['omitted_after_fault'] = ($meta['omitted_after_fault'] ?? 0) + 1;
                $this->FifoSaveMeta($meta); return;
            }
            if ($record['kind'] === 'input') {
                $stage = 'admission_continuity';
                $name = 'InputFifoIngress' . ($record['variable_id'] % 128);
                $old = $this->GetBuffer($name);
                $bucket = json_decode($old, true) ?: [];
                $entry = $bucket['values'][$record['variable_id']] ?? null;
                if (($bucket['fence'] ?? '') !== $fence || !is_array($entry)) return; // unsubscribed source
                ++$meta['observed'];
                if (empty($entry['known'])) throw new RuntimeException('Previously unknown input changed at variable ' . $record['variable_id'] . '; rebaseline required.');
                if ($entry['value'] !== $record['previous']) throw new RuntimeException('Prior-value continuity gap at variable ' . $record['variable_id'] . '.');
                if ($entry['value'] === $record['value']) {
                    ++$meta['suppressed']; $this->FifoSaveMeta($meta); return;
                }
                $bucket['values'][$record['variable_id']] = ['known' => true, 'value' => $record['value']];
                $stage = 'admission_encoding';
                $rawBucket = $this->FifoEncode($bucket);
                $newIngressBytes = $meta['ingress_bytes'] + strlen($rawBucket) - strlen($old);
                if ($newIngressBytes > self::FIFO_STATE_BYTES) throw new RuntimeException('Input ingress byte capacity reached.');
            }
            $stage = 'admission_capacity'; $seq = $meta['next_seq'];
            if ($meta['count'] >= self::FIFO_SLOTS) throw new RuntimeException('Input FIFO record capacity reached.');
            $record += ['seq' => $meta['next_seq'], 'fence' => $fence, 'wall_s' => time(), 'admission_ns' => $entered];
            $raw = $this->FifoEncode($record);
            if (strlen($raw) > 16384 || $meta['bytes'] + strlen($raw) > self::FIFO_BYTES) throw new RuntimeException('Input FIFO byte capacity reached.');
            $stage = 'admission_publication';
            // An empty queue can still have a frame in flight. That worker is already
            // scheduled and owns the pending flag until its shutdown commit.
            $wake = $meta['count'] === 0 && !$this->ReadAttributeBoolean('FifoHasPending');
            $this->SetBuffer('InputFifoSlot' . $meta['tail'], $raw);
            $meta['tail'] = ($meta['tail'] + 1) % self::FIFO_SLOTS;
            if ($wake) $this->WriteAttributeBoolean('FifoHasPending', true);
            ++$meta['count']; ++$meta['next_seq']; ++$meta['admitted']; $meta['bytes'] += strlen($raw);
            $meta['queue_peak'] = max($meta['queue_peak'], $meta['count']);
            $meta['queue_bytes_peak'] = max($meta['queue_bytes_peak'], $meta['bytes']);
            if ($record['kind'] === 'input') {
                $this->SetBuffer($name, $rawBucket); $meta['ingress_bytes'] = $newIngressBytes;
            }
            $this->FifoSaveMeta($meta);
            // Queue and idle-to-running transition share ownership with worker shutdown.
            if ($wake) $this->SetTimerInterval('InputFifoWorker', self::FIFO_WORKER_IDLE_MS);
        } finally { IPS_SemaphoreLeave($this->FifoQueueLock()); }
    }

    private function FifoRuntimeRows(array $config): array
    {
        $rows = $config['TamperList'];
        foreach ($config['ClassList'] as $class) {
            if (empty($class['ClassID']) || !$this->IsConfigRowActive($class)) continue;
            foreach ($config['SensorList'] as $row) if (($row['ClassID'] ?? '') === $class['ClassID'] && $this->IsConfigRowActive($row)) $rows[] = $row;
        }
        return $rows;
    }

    private function FifoDependencies(array $config): array
    {
        $ids = [];
        foreach ($this->FifoRuntimeRows($config) as $row) {
            $ids[(int)$row['VariableID']] = true;
            if ($this->IsDynamicComparisonRow($row)) $ids[(int)($row['ComparisonVariableID'] ?? 0)] = true;
        }
        foreach ($config['BedroomList'] as $bed) $ids[(int)$bed['ActiveVariableID']] = true;
        unset($ids[0]); return array_keys($ids);
    }

    private function RegisterFifoDependencies(): void
    {
        if (!$this->ReadAttributeBoolean('FifoOwned')) return;
        $messages = $this->GetMessageList();
        foreach ($this->FifoDependencies($this->ActiveConfig()) as $id) if (IPS_VariableExists($id) && !in_array(VM_UPDATE, $messages[$id] ?? [], true)) $this->RegisterMessage($id, VM_UPDATE);
    }

    private function FifoLoadMaps(): array
    {
        $maps = [];
        foreach (['classes' => 'ClassStateAttribute', 'last' => 'LastSensorValueMap', 'pulses' => 'SensorPulseUntilMap', 'conditions' => 'SensorConditionStateMap'] as $key => $name) $maps[$key] = json_decode($this->ReadAttributeString($name), true) ?: [];
        return $maps;
    }

    private function FifoFlushMaps(): void
    {
        foreach (['classes' => 'ClassStateAttribute', 'last' => 'LastSensorValueMap', 'pulses' => 'SensorPulseUntilMap', 'conditions' => 'SensorConditionStateMap'] as $key => $name) $this->WriteAttributeString($name, $this->FifoEncode($this->fifoMaps[$key]));
    }

    /** Deferred startup/recovery. Retained trustworthy prefix drains before resampling. */
    public function RecoverInputFifo(): void
    {
        if (!$this->ReadAttributeBoolean('FifoOwned')) { $this->SetTimerInterval('InputFifoRecovery', 0); return; }
        if ($this->FifoTestPaused()) { $this->SetTimerInterval('InputFifoRecovery', 0); $this->PublishInputFifo(); return; }
        if (!IPS_SemaphoreEnter($this->FifoWorkerLock(), 1)) return;
        $fence = $this->ReadAttributeString('FifoFence');
        $stage = 'baseline_configuration'; $failureVariable = 0;
        try {
            if ($this->FifoTestPaused()) { $this->SetTimerInterval('InputFifoRecovery', 0); $this->PublishInputFifo(); return; }
            if ($this->ReadAttributeBoolean('FifoHasPending')) {
                // Recovery must not postpone an already scheduled fast trial/diagnostic tail.
                if (!$this->FifoFastContinuation() || $this->GetTimerInterval('InputFifoWorker') !== self::FIFO_WORKER_TEST_CONTINUATION_MS) {
                    $this->SetTimerInterval('InputFifoWorker', self::FIFO_WORKER_IDLE_MS);
                }
                return;
            }
            $config = $this->ActiveConfig();
            if (!$config || AlarmSafety::validate($config)) throw new RuntimeException('No valid active configuration for FIFO baseline.');
            $ids = $this->FifoDependencies($config);
            if (count($ids) > 1024 || count($config['SensorList']) > 1024 || count($config['ClassList']) > 256 || count($config['GroupList']) > 256 || strlen($this->FifoEncode($config)) > self::FIFO_STATE_BYTES) throw new RuntimeException('FIFO active configuration capacity reached.');
            $stage = 'baseline_sampling_lock';
            if (!IPS_SemaphoreEnter($this->FifoQueueLock(), 1)) throw new RuntimeException('FIFO baseline admission busy; recovery will retry.');
            try {
                $this->WriteAttributeBoolean('FifoReady', false);
                $setupGeneration = (int)$this->GetBuffer('InputFifoSetupGeneration');
                $samplingStart = hrtime(true);
            } finally { IPS_SemaphoreLeave($this->FifoQueueLock()); }
            $values = []; $unknown = [];
            foreach ($ids as $id) {
                $stage = 'baseline_sample'; $failureVariable = $id;
                if (!IPS_VariableExists($id)) { $unknown[] = $id; continue; }
                $v = GetValue($id);
                if (!$this->FifoScalar($v)) { $unknown[] = $id; continue; }
                $values[$id] = $v;
            }
            foreach ($values as $id => $v) {
                $stage = 'baseline_verify'; $failureVariable = (int)$id;
                if (!IPS_VariableExists((int)$id) || GetValue((int)$id) !== $v) throw new RuntimeException('FIFO baseline changing; automatic recovery will retry.');
            }
            $stage = 'baseline_seed'; $failureVariable = 0;
            $this->fifoValues = $values; $this->fifoMaps = $this->FifoLoadMaps();
            $now = time();
            $this->fifoFrame = ['wall_s' => $now, 'fence' => $fence];
            // Seed temporal caches without generating an event. Preserve shared-variable legacy row order.
            foreach ($this->FifoRuntimeRows($config) as $row) {
                $id = (int)$row['VariableID'];
                if (!array_key_exists($id, $values)) continue;
                $v = $values[$id]; $mode = (int)($row['TriggerMode'] ?? 0);
                if ($mode === 1) $this->fifoMaps['last'][$id] = ['type' => $this->GetVariableTypeName($v), 'value' => $v];
                elseif ($mode === 2) {
                    $target = null;
                    $valid = isset($row['Invert']) || $this->ResolveSensorComparisonTarget($row, $v, $target);
                    if ($valid) {
                        $condition = isset($row['Invert']) ? (bool)($row['Invert'] ? !$v : $v) : (bool)$this->EvaluateRule($v, $row['Operator'], $target);
                        $this->fifoMaps['conditions'][$id] = $condition;
                        if (!$condition) unset($this->fifoMaps['pulses'][$id]);
                    }
                }
            }
            foreach ($this->fifoMaps['pulses'] as $id => $until) if ((int)$until <= $now || !isset($values[$id])) unset($this->fifoMaps['pulses'][$id]);
            $stage = 'baseline_encoding';
            $this->FifoCheckStateSize();
            $buckets = array_fill(0, 128, []);
            foreach ($ids as $id) {
                $buckets[$id % 128][$id] = ['known' => array_key_exists($id, $values), 'value' => $values[$id] ?? null];
                if (count($buckets[$id % 128]) > 16) throw new RuntimeException('FIFO dependency bucket capacity reached.');
            }
            $rawBuckets = []; $ingressBytes = 0;
            foreach ($buckets as $i => $entries) { $rawBuckets[$i] = $this->FifoEncode(['fence' => $fence, 'values' => $entries]); $ingressBytes += strlen($rawBuckets[$i]); }
            if ($ingressBytes > self::FIFO_STATE_BYTES) throw new RuntimeException('FIFO baseline ingress capacity reached.');
            $stage = 'baseline_publish_lock';
            if (!IPS_SemaphoreEnter($this->FifoQueueLock(), 1)) throw new RuntimeException('FIFO baseline admission busy; recovery will retry.');
            try {
                if ($fence !== $this->ReadAttributeString('FifoFence')) return;
                if ($this->FifoTestPaused()) { $this->SetTimerInterval('InputFifoRecovery', 0); return; }
                $stage = 'baseline_generation';
                if ((int)$this->GetBuffer('InputFifoSetupGeneration') !== $setupGeneration) throw new RuntimeException('Input arrived during FIFO baseline acquisition; automatic recovery will retry.');
                $stage = 'baseline_fault_lock';
                if (!IPS_SemaphoreEnter($this->FifoFaultLock(), 1)) throw new RuntimeException('FIFO fault publication busy; recovery will retry.');
                try {
                    if ($this->FifoTestPaused()) { $this->SetTimerInterval('InputFifoRecovery', 0); return; }
                    $stage = 'baseline_publication';
                    $old = $this->FifoMeta();
                    $priorFault = $this->FifoFaultReason($fence);
                    if ($priorFault !== '') {
                        $fault = json_decode($this->GetBuffer('InputFifoFault'), true) ?: [];
                        if (($fault['fence'] ?? '') !== $fence) $fault = ['fence' => $fence, 'revision' => $this->ReadAttributeString('ActiveRevision'), 'observed_at' => null, 'reason' => $this->FifoReason($priorFault), 'details_unavailable' => true];
                        $old['recovery_fault'] = $fault;
                        $old['recovered_at'] = date(DATE_ATOM);
                        $raw = $this->FifoEncode($fault);
                        if ($this->ReadAttributeString('FifoLastFault') !== $raw) $this->WriteAttributeString('FifoLastFault', $raw);
                    }
                    if ($old) {
                        if (($old['count'] ?? 0) > 0) {
                            $old['discarded'] = ($old['discarded'] ?? 0) + $old['count'];
                            $old['unprocessed_at_recovery'] = $old['count'];
                        }
                        $this->SetBuffer('InputFifoPreviousSession', $this->FifoEncode($old));
                    }
                    $meta = ['schema' => 1, 'fence' => $fence, 'revision' => $this->ReadAttributeString('ActiveRevision'), 'start_ns' => $samplingStart, 'started_at' => date(DATE_ATOM), 'head' => 0, 'tail' => 0, 'count' => 0, 'bytes' => 0, 'next_seq' => 1, 'admitted' => 0, 'processed' => 0, 'observed' => 0, 'suppressed' => 0, 'queue_peak' => 0, 'queue_bytes_peak' => 0, 'lag_max_ms' => 0, 'batch_max_ms' => 0, 'batches' => 0, 'recoveries' => ($old['recoveries'] ?? 0) + 1, 'ingress_bytes' => $ingressBytes];
                    foreach ($rawBuckets as $i => $raw) $this->SetBuffer('InputFifoIngress' . $i, $raw);
                    for ($i = 0; $i < self::FIFO_SLOTS; ++$i) $this->SetBuffer('InputFifoSlot' . $i, '');
                    $this->SetBuffer('InputFifoState', $this->FifoEncode(['values' => $values]));
                    if ($priorFault !== '') $this->FifoRecordIncident($priorFault);
                    $this->SetBuffer('InputFifoFault', '');
                    $this->WriteAttributeBoolean('FifoFaultPending', false);
                    $this->FifoSaveMeta($meta);
                    $this->WriteAttributeString('FifoUnknownInputs', json_encode($unknown));
                    if ($unknown) $this->FifoRecordIncident('Unknown active inputs: ' . implode(', ', $unknown) . '. Trustworthy inputs continue; missing edges cannot be recovered.');
                    $this->WriteAttributeBoolean('FifoReady', true);
                    $this->SetTimerInterval('InputFifoRecovery', 0);
                } finally { IPS_SemaphoreLeave($this->FifoFaultLock()); }
            } finally { IPS_SemaphoreLeave($this->FifoQueueLock()); }
            $baselineMaps = $this->fifoMaps;
            $this->FifoFlushMaps();
            $this->WriteAttributeBoolean('FifoFrameInFlight', true);
            // Baseline frame owns evaluation before admitted input frames. No manufactured COUNT/pulses.
            $stage = 'baseline_evaluation';
            $this->EvaluateLogicFrame(0, 'state_sync');
            $stage = 'baseline_state_commit';
            $this->FifoCheckStateSize();
            $this->FifoFlushMaps();
            $this->WriteAttributeBoolean('FifoFrameInFlight', false);
            $this->fifoFrame = null;
            $this->UpdatePulseExpireTimer();
            $this->PublishInputFifo();
        } catch (Throwable $e) {
            $this->WriteAttributeBoolean('FifoReady', false);
            // Baseline/dispatch can have partially executed. Dependent queue cannot drain against uncertain state.
            $this->WriteAttributeBoolean('FifoHasPending', false);
            $this->SetTimerInterval('InputFifoWorker', 0);
            if (isset($baselineMaps)) {
                $this->fifoMaps = $baselineMaps;
                try { $this->FifoFlushMaps(); } catch (Throwable $ignored) {}
            }
            $this->FifoFault($e->getMessage(), $fence, ['stage' => $stage, 'variable_id' => $failureVariable]);
            if ($this->FifoTestPaused()) $this->PublishInputFifo();
            else $this->SetValue('InputFifoHealth', 'Degraded: ' . $e->getMessage() . ' Automatic current-baseline recovery pending.');
        } finally {
            $this->fifoFrame = null; $this->fifoValues = null; $this->fifoMaps = null;
            IPS_SemaphoreLeave($this->FifoWorkerLock());
        }
    }

    public function RunInputFifo(): void
    {
        if (!$this->ReadAttributeBoolean('FifoOwned') || !$this->ReadAttributeBoolean('FifoReady')) return;
        if (!IPS_SemaphoreEnter($this->FifoWorkerLock(), 1)) return;
        $fence = $this->ReadAttributeString('FifoFence'); $start = hrtime(true); $done = 0;
        $timing = null; $verification = null; $completed = false;
        $stage = 'worker_load'; $record = null;
        try {
            $timing = $this->FifoTimingBegin($fence);
            $verification = $this->FifoVerificationBegin($fence);
            $this->fifoValues = (json_decode($this->GetBuffer('InputFifoState'), true) ?: [])['values'] ?? [];
            $this->fifoMaps = $this->FifoLoadMaps();
            while ($done < 32 && ($done === 0 || hrtime(true) - $start < 20000000)) {
                $stage = 'worker_dequeue'; $record = null;
                if (!$this->FifoTimedQueueEnter(1, $timing)) break;
                try {
                    $meta = $this->FifoMeta();
                    if (($meta['fence'] ?? '') !== $fence || $meta['count'] === 0) break;
                    $raw = $this->GetBuffer('InputFifoSlot' . $meta['head']);
                    $record = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                    $this->SetBuffer('InputFifoSlot' . $meta['head'], '');
                    $meta['head'] = ($meta['head'] + 1) % self::FIFO_SLOTS; --$meta['count']; $meta['bytes'] -= strlen($raw);
                    if ($done === 0) $this->WriteAttributeBoolean('FifoFrameInFlight', true);
                    // Keep FifoHasPending true through the in-flight frame and end-of-batch commit.
                    $this->FifoSaveMeta($meta);
                } finally { IPS_SemaphoreLeave($this->FifoQueueLock()); }
                $beforeMaps = $this->fifoMaps; $beforeValues = $this->fifoValues;
                $this->fifoFrame = $record;
                $evaluationStart = 0;
                try {
                    $stage = 'worker_mirror';
                    if ($record['kind'] === 'input') {
                        $id = (int)$record['variable_id'];
                        if (!array_key_exists($id, $this->fifoValues) || $this->fifoValues[$id] !== $record['previous']) throw new RuntimeException('FIFO mirror continuity gap at variable ' . $id . '.');
                        $this->fifoValues[$id] = $record['value'];
                    }
                    $source = $record['kind'] === 'input'
                        ? (string)$this->ClassifyTrafficDiagnosticVariableUpdate((int)$record['variable_id'], $record['value'], true)['source']
                        : (string)$record['source'];
                    if ($source === 'pulse_expiry') {
                        foreach ($this->fifoMaps['pulses'] as $id => $until) if ((int)$until <= $record['wall_s']) unset($this->fifoMaps['pulses'][$id]);
                    }
                    $stage = 'worker_evaluation';
                    if ($timing !== null) $evaluationStart = hrtime(true);
                    $this->EvaluateLogicFrame((int)$record['variable_id'], $source, $record['kind'] === 'input' ? true : null);
                } catch (Throwable $e) {
                    // Downstream calls may already have happened: never retry this uncertain frame.
                    $this->fifoMaps = $beforeMaps; $this->fifoValues = $beforeValues;
                    $this->FifoFault('Uncertain input evaluation seq ' . $record['seq'] . ': ' . $e->getMessage() . '; no automatic delivery replay.', $fence, ['stage' => $stage, 'variable_id' => $record['variable_id'], 'seq' => $record['seq']]);
                    // Subsequent records depend on the failed mirror/state and cannot be treated as trustworthy.
                    $this->WriteAttributeBoolean('FifoReady', false);
                    break;
                } finally {
                    if ($timing !== null && $evaluationStart > 0) {
                        $elapsed = hrtime(true) - $evaluationStart;
                        $timing['evaluation_ns'] += $elapsed;
                        $timing['evaluation_max_ns'] = max($timing['evaluation_max_ns'], $elapsed);
                        ++$timing['evaluation_attempts'];
                    }
                }
                ++$done;
                $stage = 'worker_progress_commit';
                if (!$this->FifoTimedQueueEnter(self::FIFO_QUEUE_WAIT_MS, $timing)) throw new RuntimeException('FIFO progress commit contention after evaluation.');
                try {
                    $meta = $this->FifoMeta(); ++$meta['processed'];
                    $meta['lag_max_ms'] = max($meta['lag_max_ms'], (hrtime(true) - $record['admission_ns']) / 1000000);
                    $this->FifoSaveMeta($meta);
                } finally { IPS_SemaphoreLeave($this->FifoQueueLock()); }
                // Optional evidence follows successful progress accounting, outside the queue lock.
                $this->FifoVerificationFrame($verification, $record);
            }
            $stage = 'worker_state_commit';
            $stateCommitStart = $timing === null ? 0 : hrtime(true);
            $this->FifoFlushMaps();
            $this->SetBuffer('InputFifoState', $this->FifoEncode(['values' => $this->fifoValues]));
            $this->fifoFrame = null; // Timer delay is relative to actual wall time, not delayed frame time.
            $this->UpdatePulseExpireTimer();
            if ($timing !== null) $timing['state_commit_ns'] += hrtime(true) - $stateCommitStart;
            $stage = 'worker_shutdown';
            if (!$this->FifoTimedQueueEnter(self::FIFO_QUEUE_WAIT_MS, $timing)) throw new RuntimeException('FIFO shutdown admission contention.');
            try {
                $meta = $this->FifoMeta();
                ++$meta['batches']; $meta['batch_max_ms'] = max($meta['batch_max_ms'], (hrtime(true) - $start) / 1000000);
                if (!$this->ReadAttributeBoolean('FifoReady')) {
                    $this->FifoRecordIncident('Remaining records discarded after uncertain evaluator state: ' . $meta['count'] . '.');
                    $meta['discarded'] = ($meta['discarded'] ?? 0) + $meta['count']; $meta['count'] = 0; $meta['bytes'] = 0;
                }
                $this->FifoSaveMeta($meta);
                $this->WriteAttributeBoolean('FifoFrameInFlight', false);
                $this->WriteAttributeBoolean('FifoHasPending', $meta['count'] > 0);
                // Serialize idle stop and one-time fast continuation change
                // with admissions. Retain the interval on subsequent busy batches.
                if ($meta['count'] === 0) $this->SetTimerInterval('InputFifoWorker', 0);
                elseif ($this->FifoFastContinuation() && $this->GetTimerInterval('InputFifoWorker') !== self::FIFO_WORKER_TEST_CONTINUATION_MS) {
                    if ($this->SetTimerInterval('InputFifoWorker', self::FIFO_WORKER_TEST_CONTINUATION_MS) === false) throw new RuntimeException('FIFO continuation timer update failed.');
                }
                if ($this->FifoFaultReason($fence) !== '') $this->SetTimerInterval('InputFifoRecovery', $this->FifoTestPaused() ? 0 : 1000);
            } finally { IPS_SemaphoreLeave($this->FifoQueueLock()); }
            $this->PublishInputFifo();
            $completed = $this->ReadAttributeBoolean('FifoReady');
        } catch (Throwable $e) {
            $this->WriteAttributeBoolean('FifoReady', false);
            $this->FifoFault('FIFO worker state uncertain: ' . $e->getMessage(), $fence, ['stage' => $stage, 'variable_id' => $record['variable_id'] ?? null, 'seq' => $record['seq'] ?? null]);
            if ($this->fifoMaps !== null && $this->fifoValues !== null) {
                // Preserve successfully evaluated prefix even if later metadata acquisition failed.
                try { $this->FifoFlushMaps(); $this->SetBuffer('InputFifoState', $this->FifoEncode(['values' => $this->fifoValues])); }
                catch (Throwable $ignored) { $this->FifoRecordIncident('Could not persist completed evaluator prefix.'); }
            }
            // On exceptional metadata failure recovery must not wait forever for an unusable queue.
            $this->WriteAttributeBoolean('FifoHasPending', false);
            $this->SetTimerInterval('InputFifoWorker', 0);
            if ($this->FifoTestPaused()) $this->PublishInputFifo();
        } finally {
            $this->FifoTimingEnd($timing, $start, $completed, $stage, $done);
            $this->FifoVerificationEnd($verification, $completed);
            $this->fifoFrame = null; $this->fifoValues = null; $this->fifoMaps = null;
            IPS_SemaphoreLeave($this->FifoWorkerLock());
        }
    }

    private function FifoCheckStateSize(): void
    {
        if (strlen($this->FifoEncode(['values' => $this->fifoValues, 'maps' => $this->fifoMaps])) > self::FIFO_STATE_BYTES) throw new RuntimeException('FIFO evaluation state byte capacity reached.');
    }

    private function EvaluationInputAvailable(int $id): bool { return IPS_VariableExists($id) && ($this->fifoValues === null || array_key_exists($id, $this->fifoValues)); }

    private function EvaluationValue(int $id)
    {
        if ($this->fifoValues !== null) {
            if (!array_key_exists($id, $this->fifoValues)) throw new RuntimeException('Missing captured evaluator input ' . $id . '.');
            return $this->fifoValues[$id];
        }
        return GetValue($id);
    }
    private function EvaluationFormatted(int $id): string { return $this->fifoValues !== null ? GetValueFormattedEx($id, $this->EvaluationValue($id)) : GetValueFormatted($id); }
    private function EvaluationTime(): int { return (int)($this->fifoFrame['wall_s'] ?? time()); }
    private function ReadEvaluationClassState(): array { return $this->fifoMaps !== null ? $this->fifoMaps['classes'] : (json_decode($this->ReadAttributeString('ClassStateAttribute'), true) ?: []); }
    private function WriteEvaluationClassState(array $state): void { if ($this->fifoMaps !== null) $this->fifoMaps['classes'] = $state; else $this->WriteAttributeString('ClassStateAttribute', json_encode($state)); }

    private function FifoTrialReport(): array
    {
        $active = $this->ReadAttributeBoolean('FifoTrialActive');
        return [
            'configured' => $this->ReadPropertyBoolean('AllowFifoTrialCutover'),
            'active' => $active,
            'legacy_overlap_retained' => $this->ReadAttributeBoolean('FifoLegacyOverlap'),
            'conflicting_modes' => $this->ReadPropertyBoolean('AllowFifoTrialCutover') && $this->ReadPropertyBoolean('EnableFifoFailureCapture'),
            'automatic_recovery' => $active && !$this->ReadAttributeBoolean('FifoTestActive'),
            'automatic_expiry' => false,
        ];
    }

    public function GetInputFifoReport(): string
    {
        if ($this->ReadAttributeBoolean('FifoOwned')) $this->PublishInputFifo();
        $meta = $this->FifoMeta();
        $trial = $this->FifoTrialReport();
        return $this->FifoEncode(['configured_enabled' => $this->ReadPropertyBoolean('EnableInputFifo'), 'enabled' => $this->ReadAttributeBoolean('FifoOwned'), 'activation_blocked' => ($this->ReadAttributeBoolean('FifoLegacyOverlap') || ($this->ReadPropertyBoolean('EnableInputFifo') && $trial['conflicting_modes'])) && !$this->ReadAttributeBoolean('FifoOwned'), 'health' => $this->GetValue('InputFifoHealth'), 'ready' => $this->ReadAttributeBoolean('FifoReady'), 'incident' => $this->ReadAttributeString('FifoIncident'), 'last_fault' => json_decode($this->ReadAttributeString('FifoLastFault'), true), 'trial' => $trial, 'test' => $this->FifoTestReport(), 'input_diagnostic' => $this->InputFifoDiagnosticReport(), 'unknown_inputs' => json_decode($this->ReadAttributeString('FifoUnknownInputs'), true), 'metrics' => $meta, 'worker_interval_ms' => $this->GetTimerInterval('InputFifoWorker'), 'diagnostic_timing' => $this->FifoTimingReport($meta), 'verification' => $this->FifoVerificationReport($meta), 'previous_session' => json_decode($this->GetBuffer('InputFifoPreviousSession'), true), 'fault' => $this->FifoFaultReason($this->ReadAttributeString('FifoFence')), 'limits' => ['worker_idle_wake_ms' => self::FIFO_WORKER_IDLE_MS, 'worker_ordinary_continuation_ms' => self::FIFO_WORKER_IDLE_MS, 'worker_diagnostic_continuation_ms' => self::FIFO_WORKER_TEST_CONTINUATION_MS, 'worker_trial_continuation_ms' => self::FIFO_WORKER_TEST_CONTINUATION_MS, 'worker_batch_target_ms' => 20, 'worker_batch_max_records' => 32, 'slots' => self::FIFO_SLOTS, 'queue_bytes' => self::FIFO_BYTES, 'evaluation_state_bytes' => self::FIFO_STATE_BYTES, 'admission_wait_ms' => self::FIFO_QUEUE_WAIT_MS, 'worker_commit_wait_ms' => self::FIFO_QUEUE_WAIT_MS, 'worker_dequeue_wait_ms' => 1], 'performance' => 'CPU and resident RAM unmeasured; synchronous receiver calls can exceed batch budget.']);
    }
    private function PublishInputFifo(): void
    {
        $fault = $this->FifoFaultReason($this->ReadAttributeString('FifoFence'));
        $incident = $this->ReadAttributeString('FifoIncident');
        $unknown = json_decode($this->ReadAttributeString('FifoUnknownInputs'), true) ?: [];
        if (!$this->ReadAttributeBoolean('FifoOwned')) $health = 'FIFO disabled; existing evaluator active.';
        elseif ($this->FifoTestPaused()) $health = 'FIFO diagnostic test paused after a fault; new inputs/heartbeat are not processed.' . ($this->ReadAttributeBoolean('FifoReady') && $this->ReadAttributeBoolean('FifoHasPending') ? ' Retained trustworthy prefix draining.' : '') . ' Disable live FIFO and Apply to restore existing alarm processing.';
        elseif (!$this->ReadAttributeBoolean('FifoReady')) $health = 'Degraded: FIFO baseline recovery pending.';
        elseif ($fault !== '') $health = 'Degraded: ' . $fault . ' Automatic baseline recovery pending.';
        elseif ($this->ReadAttributeBoolean('FifoApplyPending')) $health = 'FIFO running; configuration Apply waiting for queued inputs.';
        elseif ($unknown) $health = 'Degraded: unknown active inputs ' . implode(', ', $unknown) . '. Trustworthy inputs continue; restore inputs and retry the baseline.';
        elseif ($incident !== '') $health = 'FIFO running; historical-loss warning retained. Inspect the incident before clearing it.';
        else $health = 'FIFO running.';
        if ($this->ReadAttributeBoolean('FifoTestActive') && !$this->FifoTestPaused()) $health .= ' Diagnostic test active; uncertain legacy overlap permitted.';
        if ($this->ReadAttributeBoolean('FifoTrialActive')) {
            $health .= ' Supervised FIFO trial active; normal recovery enabled. Disable FIFO after testing.';
            if ($this->ReadAttributeBoolean('FifoLegacyOverlap')) $health .= ' Legacy overlap warning retained.';
        }
        if ($this->GetValue('InputFifoHealth') !== $health) $this->SetValue('InputFifoHealth', $health);
        // Read-on-demand report: no routine per-event status-variable/archive writes.
    }
    public function ClearInputFifoIncident(): void
    {
        if (!$this->ReadAttributeBoolean('FifoOwned') || !$this->ReadAttributeBoolean('FifoReady') || $this->FifoFaultReason($this->ReadAttributeString('FifoFence')) !== '' || (json_decode($this->ReadAttributeString('FifoUnknownInputs'), true) ?: [])) throw new RuntimeException('Wait for current input monitoring to recover before clearing the warning.');
        if (!IPS_SemaphoreEnter($this->FifoWorkerLock(), 1)) throw new RuntimeException('Evaluator busy; retry clearing the recovered incident.');
        try {
            if (!IPS_SemaphoreEnter($this->FifoQueueLock(), 1)) throw new RuntimeException('Input admission busy; retry clearing the recovered incident.');
            try {
                if (!IPS_SemaphoreEnter($this->FifoFaultLock(), 1)) throw new RuntimeException('Fault publication busy; retry clearing the recovered incident.');
                try {
                    if (!$this->ReadAttributeBoolean('FifoReady') || $this->FifoFaultReason($this->ReadAttributeString('FifoFence')) !== '') throw new RuntimeException('Monitoring degraded; incident cannot be cleared.');
                    $this->WriteAttributeString('FifoIncident', '');
                    $this->SetValue('InputFifoIncident', '');
                } finally { IPS_SemaphoreLeave($this->FifoFaultLock()); }
            } finally { IPS_SemaphoreLeave($this->FifoQueueLock()); }
            $this->PublishInputFifo();
        } finally { IPS_SemaphoreLeave($this->FifoWorkerLock()); }
    }
}
