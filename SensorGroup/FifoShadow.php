<?php
declare(strict_types=1);
require_once __DIR__ . '/../libs/SensorFifoShadow.php';

/** Opt-in diagnostics only. The queue never owns the production alarm path. */
trait SensorGroupFifoShadow
{
    private const SHADOW_SLOTS = 128;
    private const SHADOW_QUEUE_BYTES = 262144;
    private const SHADOW_RECORD_BYTES = 16384;
    private const SHADOW_STATE_BYTES = 524288;
    private const SHADOW_INGRESS_BUCKETS = 128;

    private function FifoShadowCreate(): void
    {
        $this->RegisterPropertyBoolean('EnableFifoShadow', false);
        $this->RegisterPropertyInteger('FifoShadowDurationSeconds', 300);
        $this->RegisterAttributeBoolean('FifoShadowActive', false);
        $this->RegisterAttributeBoolean('FifoShadowApplying', false);
        $this->RegisterAttributeString('FifoShadowFence', '');
        $this->RegisterVariableString('FifoShadowHealth', 'FIFO Shadow Health', '', 97);
        $this->RegisterVariableString('FifoShadowReport', 'FIFO Shadow Report', '', 98);
        $this->RegisterTimer('FifoShadowWorker', 0, 'MYALARM_RunFifoShadow($_IPS[\'TARGET\']);');
        $this->RegisterTimer('FifoShadowPublish', 0, 'MYALARM_PublishFifoShadow($_IPS[\'TARGET\']);');
    }

    private function FifoShadowApplyBegin(): void
    {
        // Attributes/timers only during native interface creation. No buffers.
        $this->WriteAttributeBoolean('FifoShadowApplying', true);
        $this->WriteAttributeString('FifoShadowFence', bin2hex(random_bytes(8)));
        $this->WriteAttributeBoolean('FifoShadowActive', false);
        $this->SetTimerInterval('FifoShadowWorker', 0);
        $this->SetTimerInterval('FifoShadowPublish', 0);
        $this->SetValue('FifoShadowHealth', 'Stopped by Apply/restart; start manually if enabled.');
    }

    public function StartFifoShadow(): void
    {
        if (!$this->ReadPropertyBoolean('EnableFifoShadow')) throw new RuntimeException('Enable FIFO shadow in the form and Apply first.');
        $duration = $this->ReadPropertyInteger('FifoShadowDurationSeconds');
        if ($duration < 30 || $duration > 900) throw new InvalidArgumentException('Shadow duration must be 30–900 seconds.');
        $fence = $this->ReadAttributeString('FifoShadowFence');
        $revision = $this->ReadAttributeString('ActiveRevision');
        if ($revision === '' || $revision === 'updating' || $this->ReadAttributeBoolean('FifoShadowApplying')) throw new RuntimeException('Wait for configuration Apply to complete.');
        if (!IPS_SemaphoreEnter($this->ShadowWorkerLock(), 1)) throw new RuntimeException('Shadow worker busy; retry Start.');
        try {
            if ($this->ReadAttributeBoolean('FifoShadowActive')) throw new RuntimeException('Shadow already running; Stop first.');
            $config = $this->ShadowConfig();
            $ids = SensorFifoShadow::dependencies($config);
            if (count($ids) > 1024 || count($config['SensorList']) > 1024 || count($config['ClassList']) > 256 || count($config['GroupList']) > 256) throw new RuntimeException('Shadow configuration size limit reached.');
            $before = $this->ShadowLegacyRuntime();
            $values = [];
            $ingress = [];
            foreach ($ids as $id) {
                if (IPS_VariableExists($id)) {
                    $value = GetValue($id);
                    if (!$this->ShadowScalar($value)) throw new RuntimeException('Unsupported/oversized shadow input at variable ' . $id);
                    $values[(string)$id] = $value;
                }
                $entry = json_decode($this->GetBuffer('MessageSinkLastValue_' . $id), true);
                if (is_array($entry) && array_key_exists('value', $entry)) $ingress[(string)$id] = $entry;
            }
            // A second pass detects observed baseline motion; it is not atomic sampling.
            foreach ($values as $id => $value) {
                if (!IPS_VariableExists((int)$id) || GetValue((int)$id) !== $value) throw new RuntimeException('Baseline changing; retry Start during quieter traffic.');
            }
            if ($before !== $this->ShadowLegacyRuntime()) throw new RuntimeException('Evaluation state changing; retry Start during quieter traffic.');
            $state = $before + ['values' => $values, 'ingress' => $ingress];
            $session = bin2hex(random_bytes(8));
            $rawConfig = $this->ShadowEncode($config);
            $rawState = $this->ShadowEncode($state);
            if (strlen($rawConfig) > self::SHADOW_STATE_BYTES || strlen($rawState) > self::SHADOW_STATE_BYTES) throw new RuntimeException('Shadow configuration/state byte limit reached.');
            $buckets = array_fill(0, self::SHADOW_INGRESS_BUCKETS, []);
            foreach ($values as $id => $value) {
                $bucket = (int)$id % self::SHADOW_INGRESS_BUCKETS;
                $buckets[$bucket][$id] = ['value' => $value, 'filter' => $ingress[$id] ?? null];
                if (count($buckets[$bucket]) > 16) throw new RuntimeException('Shadow ingress bucket capacity reached.');
            }
            $ingressBytes = 0;
            foreach ($buckets as $index => $entries) {
                $raw = $this->ShadowEncode(['session' => $session, 'values' => $entries]);
                $ingressBytes += strlen($raw);
                $this->SetBuffer('FifoShadowIngress' . $index, $raw);
            }
            if ($ingressBytes > self::SHADOW_STATE_BYTES) throw new RuntimeException('Shadow ingress state byte limit reached.');
            if (!IPS_SemaphoreEnter($this->ShadowQueueLock(), 1)) throw new RuntimeException('Shadow admission busy; retry Start.');
            try {
                $this->ShadowCheckStartFence($fence, $revision);
                $now = hrtime(true);
                $meta = ['schema' => 1, 'session' => $session, 'fence' => $fence,
                    'revision' => $revision, 'start_ns' => $now, 'started_at' => date(DATE_ATOM),
                    'deadline_ns' => $now + $duration * 1000000000, 'head' => 0, 'tail' => 0,
                    'count' => 0, 'bytes' => 0, 'next_seq' => 1, 'admitted' => 0, 'processed' => 0,
                    'observed' => 0, 'ingress_suppressed' => 0,
                    'evaluated' => 0, 'suppressed' => 0, 'mismatches' => 0, 'examples' => [],
                    'queue_peak' => 0, 'queue_bytes_peak' => 0, 'queue_lag_max_ms' => 0,
                    'batch_max_ms' => 0, 'worker_total_ms' => 0, 'batches' => 0,
                    'live_max_ms' => 0, 'state_bytes' => strlen($rawState), 'config_bytes' => strlen($rawConfig),
                    'worker_php_delta_max_bytes' => 0,
                    'ingress_bytes' => $ingressBytes,
                    'previous_counter' => null, 'stop_reason' => null];
                $this->SetBuffer('FifoShadowConfig', $rawConfig);
                $this->SetBuffer('FifoShadowState', $rawState);
                for ($i = 0; $i < self::SHADOW_SLOTS; ++$i) $this->SetBuffer('FifoShadowSlot' . $i, '');
                if (!IPS_SemaphoreEnter($this->ShadowFaultLock(), 1)) throw new RuntimeException('Shadow fault publication busy; retry Start.');
                try {
                    $this->SetBuffer('FifoShadowFault', '');
                    $this->ShadowSaveMeta($meta);
                    $this->WriteAttributeBoolean('FifoShadowActive', true);
                } finally { IPS_SemaphoreLeave($this->ShadowFaultLock()); }
                $this->SetTimerInterval('FifoShadowPublish', 5000);
                $this->ShadowCheckStartFence($fence, $revision);
            } catch (Throwable $e) {
                $this->WriteAttributeBoolean('FifoShadowActive', false);
                $this->SetTimerInterval('FifoShadowWorker', 0);
                $this->SetTimerInterval('FifoShadowPublish', 0);
                throw $e;
            } finally { IPS_SemaphoreLeave($this->ShadowQueueLock()); }
            $this->SetValue('FifoShadowHealth', 'Running shadow only; existing alarm path active.');
        } finally { IPS_SemaphoreLeave($this->ShadowWorkerLock()); }
    }

    private function ShadowCheckStartFence(string $fence, string $revision): void
    {
        if ($this->ReadAttributeBoolean('FifoShadowApplying') || $fence !== $this->ReadAttributeString('FifoShadowFence') || $revision !== $this->ReadAttributeString('ActiveRevision')) throw new RuntimeException('Configuration lifecycle changed; retry Start after Apply.');
    }

    private function ShadowScalar($value): bool
    {
        return is_bool($value) || is_int($value) || (is_float($value) && is_finite($value)) || (is_string($value) && strlen($value) <= 1024);
    }

    private function ShadowConfig(): array
    {
        $keys = [
            'ClassList' => ['ClassID', 'Active', 'LogicMode', 'TimeWindow', 'Threshold'],
            'SensorList' => ['VariableID', 'ClassID', 'Active', 'Operator', 'ComparisonValue', 'ComparisonSource', 'ComparisonVariableID', 'TriggerMode', 'PulseSeconds', 'Invert'],
            'TamperList' => ['VariableID', 'Operator', 'ComparisonValue', 'ComparisonSource', 'ComparisonVariableID', 'TriggerMode', 'PulseSeconds', 'Invert'],
            'GroupList' => ['GroupName', 'Active', 'GroupLogic'], 'GroupMembers' => ['GroupName', 'ClassID'],
            'BedroomList' => ['ActiveVariableID']
        ];
        $config = [];
        foreach ($keys as $list => $fields) {
            $mask = array_fill_keys($fields, true);
            $config[$list] = array_map(static fn(array $row): array => array_intersect_key($row, $mask), $this->ActiveList($list));
        }
        return $config;
    }

    private function ShadowLegacyRuntime(): array
    {
        $data = [];
        foreach (['classes' => 'ClassStateAttribute', 'last' => 'LastSensorValueMap',
            'pulses' => 'SensorPulseUntilMap', 'conditions' => 'SensorConditionStateMap'] as $key => $attribute) {
            $data[$key] = json_decode($this->ReadAttributeString($attribute), true) ?: [];
        }
        return $data;
    }

    private function FifoShadowInput($counter, int $id, $data): ?array
    {
        $entered = hrtime(true);
        if (!$this->ReadPropertyBoolean('EnableFifoShadow') || !$this->ReadAttributeBoolean('FifoShadowActive')) return null;
        try {
            if (!is_int($counter) || !is_array($data) || !array_key_exists(0, $data) || !isset($data[1]) || !is_bool($data[1]) || !array_key_exists(2, $data) || !$this->ShadowScalar($data[0]) || !$this->ShadowScalar($data[2])) throw new RuntimeException('Invalid/oversized native shadow observation.');
            return $this->ShadowAdmit(['kind' => 'input', 'variable_id' => $id,
                'value' => $data[0], 'previous' => $data[2], 'native_counter' => $counter], $entered);
        } catch (Throwable $e) { $this->ShadowFault($e->getMessage(), $entered); return null; }
    }

    private function FifoShadowControl(string $source): ?array
    {
        $entered = hrtime(true);
        if (!$this->ReadPropertyBoolean('EnableFifoShadow') || !$this->ReadAttributeBoolean('FifoShadowActive')) return null;
        try { return $this->ShadowAdmit(['kind' => $source === 'state_sync' ? 'state_sync' : 'evaluation', 'variable_id' => 0], $entered); }
        catch (Throwable $e) { $this->ShadowFault($e->getMessage(), $entered); return null; }
    }

    private function ShadowAdmit(array $record, ?int $entered = null): ?array
    {
        $entered ??= hrtime(true);
        $wall = time();
        if (!IPS_SemaphoreEnter($this->ShadowQueueLock(), 1)) throw new RuntimeException('Shadow admission mutex contention; diagnostic observation omitted.');
        try {
            $meta = $this->ShadowMeta();
            if ($this->ShadowFaultReason() !== '') return null;
            if (!$this->ShadowSessionActive($meta) || $entered < $meta['start_ns']) return null;
            if ($entered >= $meta['deadline_ns']) return null;
            if (isset($record['native_counter']) && $meta['previous_counter'] !== null && $record['native_counter'] < $meta['previous_counter']) throw new RuntimeException('Native callback counter regression observed.');
            if ($record['kind'] === 'input') {
                $name = 'FifoShadowIngress' . ($record['variable_id'] % self::SHADOW_INGRESS_BUCKETS);
                $oldBucket = $this->GetBuffer($name);
                $bucket = json_decode($oldBucket, true);
                $entry = $bucket['values'][$record['variable_id']] ?? null;
                if (($bucket['session'] ?? null) !== $meta['session'] || !is_array($entry) || !array_key_exists('value', $entry) || $entry['value'] !== $record['previous']) {
                    $detail = ['variable_id' => $record['variable_id'], 'native_counter' => $record['native_counter'],
                        'baseline_present' => is_array($entry) && array_key_exists('value', $entry),
                        'bucket_session_matches' => ($bucket['session'] ?? null) === $meta['session'],
                        'baseline' => $this->ShadowDescribe($entry['value'] ?? null),
                        'native_previous' => $this->ShadowDescribe($record['previous']),
                        'native_value' => $this->ShadowDescribe($record['value'])];
                    $this->ShadowFault('Captured prior value does not match admission baseline; diagnostic baseline/event gap.', $entered, $meta['session'], $detail);
                    return null;
                }
                ++$meta['observed'];
                $filter = $entry['filter'] ?? null;
                $type = $this->GetVariableTypeName($record['value']);
                $same = is_array($filter) && isset($filter['type']) && array_key_exists('value', $filter) &&
                    $filter['type'] === $type && !$this->MessageSinkValuesAreDifferent($type, $filter['value'], $record['value']);
                if ($same) {
                    ++$meta['ingress_suppressed'];
                    $meta['previous_counter'] = $record['native_counter'];
                    $this->ShadowSaveMeta($meta);
                    return ['session' => $meta['session'], 'suppression' => true,
                        'variable_id' => $record['variable_id'], 'admission_ns' => $entered];
                }
            }
            if ($meta['count'] >= self::SHADOW_SLOTS) throw new RuntimeException('Shadow FIFO record capacity reached.');
            $ticket = ['session' => $meta['session'], 'seq' => $meta['next_seq'], 'slot' => $meta['tail']];
            $record += $ticket + ['admission_ns' => $entered, 'wall_s' => $wall, 'ready' => false];
            $raw = $this->ShadowEncode($record);
            if (strlen($raw) > self::SHADOW_RECORD_BYTES || $meta['bytes'] + strlen($raw) > self::SHADOW_QUEUE_BYTES) throw new RuntimeException('Shadow FIFO byte capacity reached.');
            $this->SetBuffer('FifoShadowSlot' . $ticket['slot'], $raw);
            $meta['tail'] = ($meta['tail'] + 1) % self::SHADOW_SLOTS;
            ++$meta['count']; ++$meta['next_seq']; ++$meta['admitted'];
            $meta['bytes'] += strlen($raw);
            $meta['queue_peak'] = max($meta['queue_peak'], $meta['count']);
            $meta['queue_bytes_peak'] = max($meta['queue_bytes_peak'], $meta['bytes']);
            if (isset($record['native_counter'])) $meta['previous_counter'] = $record['native_counter'];
            if ($record['kind'] === 'input') {
                $bucket['values'][$record['variable_id']] = ['value' => $record['value'],
                    'filter' => ['type' => $type, 'value' => $record['value']]];
                $rawBucket = $this->ShadowEncode($bucket);
                $meta['ingress_bytes'] += strlen($rawBucket) - strlen($oldBucket);
                if ($meta['ingress_bytes'] > self::SHADOW_STATE_BYTES) throw new RuntimeException('Shadow ingress state byte limit reached.');
                $this->SetBuffer($name, $rawBucket);
            }
            $this->ShadowSaveMeta($meta);
            // A pending head waits for its live decision before waking the worker.
            return $ticket;
        } finally { IPS_SemaphoreLeave($this->ShadowQueueLock()); }
    }

    private function FifoShadowComplete(?array $ticket, ?array $projection, bool $success = true): void
    {
        if ($ticket === null) return;
        try {
            if (!$this->ReadAttributeBoolean('FifoShadowActive')) return;
            if (!IPS_SemaphoreEnter($this->ShadowQueueLock(), 1)) throw new RuntimeException('Shadow completion mutex contention; diagnostic comparison incomplete.');
            try {
                $meta = $this->ShadowMeta();
                if (!$this->ShadowSessionActive($meta) || $ticket['session'] !== $meta['session']) return;
                if (!empty($ticket['suppression'])) {
                    ++$meta['suppressed'];
                    $meta['live_max_ms'] = max($meta['live_max_ms'], (hrtime(true) - $ticket['admission_ns']) / 1000000);
                    if (!$success) throw new RuntimeException('Live evaluation aborted during suppression comparison.');
                    if ($projection !== null) {
                        ++$meta['mismatches'];
                        if (count($meta['examples']) < 8) $meta['examples'][] = [
                            'kind' => 'suppression divergence', 'variable_id' => $ticket['variable_id'], 'live' => $this->ShadowBrief($projection)];
                    }
                    $this->ShadowSaveMeta($meta);
                    return;
                }
                $old = $this->GetBuffer('FifoShadowSlot' . $ticket['slot']);
                $record = json_decode($old, true);
                if (($record['seq'] ?? null) !== $ticket['seq']) throw new RuntimeException('Shadow slot identity mismatch.');
                if (!$success) throw new RuntimeException('Live evaluation aborted; diagnostic comparison incomplete.');
                $record['ready'] = true;
                $record['live'] = $projection;
                $record['live_ms'] = (hrtime(true) - $record['admission_ns']) / 1000000;
                $raw = $this->ShadowEncode($record);
                $bytes = $meta['bytes'] - strlen($old) + strlen($raw);
                if (strlen($raw) > self::SHADOW_RECORD_BYTES || $bytes > self::SHADOW_QUEUE_BYTES) throw new RuntimeException('Shadow comparison byte capacity reached.');
                $this->SetBuffer('FifoShadowSlot' . $ticket['slot'], $raw);
                $meta['bytes'] = $bytes;
                $meta['queue_bytes_peak'] = max($meta['queue_bytes_peak'], $bytes);
                $this->ShadowSaveMeta($meta);
                if ($ticket['slot'] === $meta['head']) $this->SetTimerInterval('FifoShadowWorker', 50);
            } finally { IPS_SemaphoreLeave($this->ShadowQueueLock()); }
        } catch (Throwable $e) { $this->ShadowFault($e->getMessage(), null, $ticket['session']); }
    }

    public function RunFifoShadow(): void
    {
        if (!IPS_SemaphoreEnter($this->ShadowWorkerLock(), 1)) return;
        try {
            $start = hrtime(true);
            $memoryStart = memory_get_usage(false);
            if (!$this->ReadAttributeBoolean('FifoShadowActive') || $this->ReadAttributeBoolean('FifoShadowApplying')) { $this->SetTimerInterval('FifoShadowWorker', 0); return; }
            $meta = $this->ShadowMeta();
            if (!$this->ShadowSessionActive($meta) || $this->ShadowFaultReason() !== '') { $this->SetTimerInterval('FifoShadowWorker', 0); return; }
            $engine = new SensorFifoShadow(json_decode($this->GetBuffer('FifoShadowConfig'), true), json_decode($this->GetBuffer('FifoShadowState'), true));
            $updates = [];
            for ($n = 0; $n < 32 && ($n === 0 || (hrtime(true) - $start) < 20000000); ++$n) {
                if (!IPS_SemaphoreEnter($this->ShadowQueueLock(), 1)) throw new RuntimeException('Shadow dequeue mutex contention.');
                try {
                    $meta = $this->ShadowMeta();
                    if (!$this->ShadowSessionActive($meta) || $meta['count'] === 0) break;
                    $raw = $this->GetBuffer('FifoShadowSlot' . $meta['head']);
                    $record = json_decode($raw, true);
                    if (empty($record['ready'])) break;
                    $meta['head'] = ($meta['head'] + 1) % self::SHADOW_SLOTS;
                    --$meta['count']; $meta['bytes'] -= strlen($raw);
                    $this->SetBuffer('FifoShadowSlot' . $record['slot'], '');
                    $this->ShadowSaveMeta($meta);
                } finally { IPS_SemaphoreLeave($this->ShadowQueueLock()); }
                $result = $engine->process($record);
                $updates[] = [$record, $result, (hrtime(true) - $record['admission_ns']) / 1000000];
            }
            $state = $this->ShadowEncode($engine->exportState());
            if (strlen($state) > self::SHADOW_STATE_BYTES) throw new RuntimeException('Shadow evaluator state byte limit reached.');
            if (!IPS_SemaphoreEnter($this->ShadowQueueLock(), 1)) throw new RuntimeException('Shadow commit mutex contention.');
            try {
                $meta = $this->ShadowMeta();
                if (!$this->ShadowSessionActive($meta)) return;
                $this->SetBuffer('FifoShadowState', $state);
                foreach ($updates as [$record, $result, $lag]) {
                    ++$meta['processed'];
                    ++$meta[$result['evaluated'] ? 'evaluated' : 'suppressed'];
                    $meta['queue_lag_max_ms'] = max($meta['queue_lag_max_ms'], $lag);
                    $meta['live_max_ms'] = max($meta['live_max_ms'], $record['live_ms']);
                    $difference = $result['evaluated'] !== ($record['live'] !== null) ||
                        ($result['evaluated'] && $result['projection'] !== $record['live']);
                    if ($difference) {
                        ++$meta['mismatches'];
                        if (count($meta['examples']) < 8) $meta['examples'][] = ['seq' => $record['seq'], 'kind' => $record['kind'],
                            'variable_id' => $record['variable_id'], 'shadow_evaluated' => $result['evaluated'],
                            'live_evaluated' => $record['live'] !== null, 'shadow' => $this->ShadowBrief($result['projection']), 'live' => $this->ShadowBrief($record['live'] ?? [])];
                    }
                }
                $elapsed = (hrtime(true) - $start) / 1000000;
                ++$meta['batches']; $meta['worker_total_ms'] += $elapsed;
                $meta['batch_max_ms'] = max($meta['batch_max_ms'], $elapsed);
                $meta['state_bytes'] = strlen($state);
                $meta['worker_php_delta_max_bytes'] = max($meta['worker_php_delta_max_bytes'], max(0, memory_get_usage(false) - $memoryStart));
                $this->ShadowSaveMeta($meta);
                $head = $meta['count'] > 0 ? json_decode($this->GetBuffer('FifoShadowSlot' . $meta['head']), true) : [];
                $this->SetTimerInterval('FifoShadowWorker', !empty($head['ready']) ? 50 : 0);
            } finally { IPS_SemaphoreLeave($this->ShadowQueueLock()); }
        } catch (Throwable $e) { $this->ShadowFault($e->getMessage(), $start); $this->SetTimerInterval('FifoShadowWorker', 0); }
        finally { IPS_SemaphoreLeave($this->ShadowWorkerLock()); }
    }

    private function ShadowBrief(array $projection): array
    {
        $out = [];
        foreach (['classes', 'groups', 'sensors'] as $key) $out[$key] = array_map(
            static fn($value) => is_string($value) ? substr($value, 0, 96) : $value,
            array_slice($projection[$key] ?? [], 0, 5));
        $out['sabotage'] = $projection['sabotage'] ?? null;
        return $out;
    }

    public function StopFifoShadow(): void
    {
        $this->StopShadowSession(null);
    }

    private function StopShadowSession(?string $expectedSession): void
    {
        if (!IPS_SemaphoreEnter($this->ShadowWorkerLock(), 1)) throw new RuntimeException('Shadow worker busy; retry Stop.');
        try {
            if (!IPS_SemaphoreEnter($this->ShadowQueueLock(), 1)) throw new RuntimeException('Shadow admission busy; retry Stop.');
            try {
                $meta = $this->ShadowMeta();
                if ($expectedSession !== null && ($meta['session'] ?? null) !== $expectedSession) return;
                $meta['stop_reason'] = $this->ShadowFaultReason() !== '' ? $this->ShadowFaultReason() : 'manual/duration stop';
                $meta['stopped_at'] = date(DATE_ATOM);
                $meta['stop_lateness_ms'] = max(0, (hrtime(true) - ($meta['deadline_ns'] ?? hrtime(true))) / 1000000);
                $this->ShadowSaveMeta($meta);
                $this->WriteAttributeBoolean('FifoShadowActive', false);
                $this->SetTimerInterval('FifoShadowWorker', 0);
                $this->SetTimerInterval('FifoShadowPublish', 0);
            } finally { IPS_SemaphoreLeave($this->ShadowQueueLock()); }
        } finally { IPS_SemaphoreLeave($this->ShadowWorkerLock()); }
        $this->PublishFifoShadow();
    }

    public function PublishFifoShadow(): void
    {
        $entered = hrtime(true);
        try {
            if ($this->ReadAttributeBoolean('FifoShadowApplying')) return;
            $report = $this->GetFifoShadowReport();
            $snapshot = json_decode($report, true);
            if (isset($snapshot['error'])) return;
            $meta = $snapshot['metrics'];
            if ($snapshot['active'] && ($snapshot['fault'] !== '' || hrtime(true) >= ($meta['deadline_ns'] ?? 0))) { $this->StopShadowSession($meta['session']); return; }
            $health = $snapshot['active'] ? 'Running shadow only' : 'Stopped shadow';
            if (!empty($snapshot['comparison_incomplete'])) $health .= '; comparison incomplete';
            $health .= '; mismatches=' . ($meta['mismatches'] ?? 0) . '; pending=' . ($meta['count'] ?? 0);
            if ($snapshot['fault'] !== '') $health .= '; fault: ' . $snapshot['fault'];
            if (!IPS_SemaphoreEnter($this->ShadowQueueLock(), 1)) return;
            try {
                $current = $this->ShadowMeta();
                if (($current['session'] ?? null) !== ($meta['session'] ?? null) || $this->ReadAttributeBoolean('FifoShadowApplying')) return;
                $this->SetValue('FifoShadowReport', $report);
                $this->SetValue('FifoShadowHealth', $health . '; existing alarm path active.');
            } finally { IPS_SemaphoreLeave($this->ShadowQueueLock()); }
        } catch (Throwable $e) { $this->ShadowFault($e->getMessage(), $entered); }
    }

    public function GetFifoShadowReport(): string
    {
        if ($this->ReadAttributeBoolean('FifoShadowApplying')) return $this->ShadowEncode(['error' => 'Configuration Apply in progress.']);
        if (!IPS_SemaphoreEnter($this->ShadowQueueLock(), 1)) return $this->ShadowEncode(['error' => 'Shadow admission busy; retry report.']);
        try {
            $meta = $this->ShadowMeta();
            return $this->ShadowEncode(['mode' => 'shadow only; no additional alarm outputs', 'active' => $this->ReadAttributeBoolean('FifoShadowActive'),
                'comparison_incomplete' => !$this->ReadAttributeBoolean('FifoShadowActive') && (($meta['count'] ?? 0) > 0 || ($meta['admitted'] ?? 0) !== ($meta['processed'] ?? 0)),
                'metrics' => $meta, 'fault' => $this->ShadowFaultReason(), 'fault_details' => $this->ShadowCurrentFault(),
                'lifecycle_interruption' => isset($meta['fence']) && $meta['fence'] !== $this->ReadAttributeString('FifoShadowFence'),
                'limits' => ['slots' => self::SHADOW_SLOTS, 'queue_bytes' => self::SHADOW_QUEUE_BYTES, 'state_bytes' => self::SHADOW_STATE_BYTES],
                'comparison_limits' => 'Decision projections only, not formatted payloads/delivery. Legacy shared variable pulse maps and integer wall-second semantics preserved. Mirror/live races can differ. Serialized bytes are not PHP resident memory. Worker elapsed time is not CPU utilization.']);
        } finally { IPS_SemaphoreLeave($this->ShadowQueueLock()); }
    }

    private function ShadowSessionActive(array $meta): bool
    {
        return $this->ReadAttributeBoolean('FifoShadowActive') && ($meta['fence'] ?? null) === $this->ReadAttributeString('FifoShadowFence') && ($meta['revision'] ?? null) === $this->ReadAttributeString('ActiveRevision');
    }
    private function ShadowFault(string $reason, ?int $entered = null, ?string $session = null, array $details = []): void
    {
        $locked = false;
        try {
            if (!IPS_SemaphoreEnter($this->ShadowFaultLock(), 1)) return; // Another publisher/start owns this short boundary.
            $locked = true;
            $meta = $this->ShadowMeta();
            if (!$this->ShadowSessionActive($meta) || ($entered !== null && $entered < $meta['start_ns']) ||
                ($session !== null && $session !== $meta['session'])) return;
            if ($this->ShadowFaultReason() === '') $this->SetBuffer('FifoShadowFault', $this->ShadowEncode([
                'session' => $meta['session'], 'reason' => substr($reason, 0, 256),
                'recorded_at' => date(DATE_ATOM), 'recorded_ns' => hrtime(true), 'callback_ns' => $entered, 'details' => $details]));
        } catch (Throwable $ignored) { /* Diagnostics must never throw into production. */ }
        finally { if ($locked) IPS_SemaphoreLeave($this->ShadowFaultLock()); }
    }
    private function ShadowDescribe($value): array
    {
        $type = gettype($value);
        if (is_string($value)) {
            $value = substr($value, 0, 96);
            // Keep a valid UTF-8 prefix so diagnostic JSON cannot lose the fault.
            while ($value !== '' && preg_match('//u', $value) !== 1) $value = substr($value, 0, -1);
        }
        return ['type' => $type, 'value' => $value];
    }
    private function ShadowFaultReason(): string
    {
        return $this->ShadowCurrentFault()['reason'] ?? '';
    }
    private function ShadowCurrentFault(): array
    {
        $fault = json_decode($this->GetBuffer('FifoShadowFault'), true);
        $meta = $this->ShadowMeta();
        return is_array($fault) && ($fault['session'] ?? null) === ($meta['session'] ?? null) ? $fault : [];
    }
    private function ShadowMeta(): array { return json_decode($this->GetBuffer('FifoShadowMeta'), true) ?: []; }
    private function ShadowSaveMeta(array $meta): void { $this->SetBuffer('FifoShadowMeta', $this->ShadowEncode($meta)); }
    private function ShadowEncode($value): string { return json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION); }
    private function ShadowFaultLock(): string { return 'M1FifoShadowFault_' . $this->InstanceID; }
    private function ShadowQueueLock(): string { return 'M1FifoShadowQueue_' . $this->InstanceID; }
    private function ShadowWorkerLock(): string { return 'M1FifoShadowWorker_' . $this->InstanceID; }
}
