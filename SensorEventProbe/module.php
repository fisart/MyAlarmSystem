<?php
declare(strict_types=1);

// Optional diagnostic observer, v0.1.1. Never evaluates or dispatches an alarm.
class SensorEventProbe extends IPSModule
{
    private const MAX_SAMPLES = 256;
    private const MAX_RECORD_BYTES = 4096;
    private const MAX_BYTES = 1048576;

    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyString('VariableIDs', '[]');
        $this->RegisterPropertyInteger('DurationSeconds', 120);
        $this->RegisterPropertyInteger('MaxSamples', 256);
        $this->RegisterAttributeBoolean('Capturing', false);
        $this->RegisterAttributeString('SubscribedIDs', '[]');
        $this->RegisterAttributeString('LifecycleFence', '');
        $this->RegisterVariableString('CaptureStatus', 'Capture Status', '', 10);
        $this->RegisterTimer('CaptureTimer', 0, 'FIFOPROBE_Tick($_IPS[\'TARGET\']);');
        // Runtime buffers are deliberately not accessed during creation.
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        // No runtime-buffer or cross-module access during interface creation.
        $this->LifecycleStop();
    }

    public function Destroy()
    {
        // Native unload has already withdrawn InstanceInterface. Do not access
        // attributes, variables, messages or timers here; the host removes them.
        parent::Destroy();
    }

    public function Start(): void
    {
        $ids = json_decode($this->ReadPropertyString('VariableIDs'), true);
        if (!is_array($ids) || !array_is_list($ids) || count($ids) < 1 || count($ids) > 16) {
            throw new InvalidArgumentException('Choose 1–16 variable IDs as a JSON array.');
        }
        foreach ($ids as $id) {
            if (!is_int($id) || $id <= 0 || !IPS_VariableExists($id)) {
                throw new InvalidArgumentException('Each ID must be an existing integer variable ID.');
            }
            if ($id === $this->GetIDForIdent('CaptureStatus')) {
                throw new InvalidArgumentException('The probe cannot observe its own status.');
            }
        }
        $ids = array_values(array_unique($ids));
        $duration = $this->ReadPropertyInteger('DurationSeconds');
        $limit = $this->ReadPropertyInteger('MaxSamples');
        if ($duration < 10 || $duration > 300 || $limit < 1 || $limit > self::MAX_SAMPLES) {
            throw new InvalidArgumentException('Duration must be 10–300 s; samples 1–256.');
        }
        $fence = $this->ReadAttributeString('LifecycleFence');
        if (!IPS_SemaphoreEnter($this->LockName(), 1)) {
            throw new RuntimeException('Probe busy; retry Start.');
        }
        $ownsStart = false;
        try {
            if ($this->ReadAttributeBoolean('Capturing')) {
                throw new RuntimeException('Capture already running; Stop before starting again.');
            }
            $ownsStart = true;
            if ($fence !== $this->ReadAttributeString('LifecycleFence')) {
                throw new RuntimeException('Lifecycle changed; retry Start after Apply completes.');
            }
            $now = $this->NowNs();
            $meta = [
                'schema' => 1, 'session' => bin2hex(random_bytes(8)), 'fence' => $fence, 'active' => true,
                'php_version' => PHP_VERSION,
                'kernel_version' => function_exists('IPS_GetKernelVersion') ? IPS_GetKernelVersion() : 'not available in test model',
                'started_at' => date(DATE_ATOM), 'start_ns' => $now,
                'deadline_ns' => $now + $duration * 1000000000,
                'ids' => $ids, 'limit' => $limit, 'count' => 0, 'bytes' => 0,
                'sources' => [], 'previous_counter' => null, 'counter_regressions' => 0,
                'callback_max_us' => 0, 'last_tick_ns' => $now, 'timer_ticks' => 0,
                'max_timer_lateness_ms' => 0, 'stop_reason' => null
            ];
            $this->SetBuffer('Contention', '');
            $this->SetBuffer('Session', $meta['session']);
            $this->SaveMeta($meta);
            $this->WriteAttributeString('SubscribedIDs', $this->Encode($ids));
            // Register while inactive; the measured session starts after subscription.
            foreach ($ids as $id) {
                $this->RegisterMessage($id, VM_UPDATE);
            }
            $now = $this->NowNs();
            $meta['start_ns'] = $now;
            $meta['deadline_ns'] = $now + $duration * 1000000000;
            $meta['last_tick_ns'] = $now;
            $this->SaveMeta($meta);
            $this->WriteAttributeBoolean('Capturing', true);
            $this->SetTimerInterval('CaptureTimer', 1000);
            $this->SetValue('CaptureStatus', 'Capturing; stops after ' . $duration . ' s or ' . $limit . ' samples.');
            if ($fence !== $this->ReadAttributeString('LifecycleFence')) {
                throw new RuntimeException('Lifecycle changed; retry Start after Apply completes.');
            }
        } catch (Throwable $e) {
            // An already-running session must survive a duplicate Start request.
            if ($ownsStart) {
                $this->DisableCapture();
            }
            throw $e;
        } finally {
            IPS_SemaphoreLeave($this->LockName());
        }
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        $entered = $this->NowNs();
        if ((int)$Message !== VM_UPDATE || !$this->ReadAttributeBoolean('Capturing')) {
            return;
        }
        $entrySession = $this->GetBuffer('Session');
        // This read is a diagnostic comparison, never the decoded native event value.
        $live = ['unavailable' => true];
        try {
            if (IPS_VariableExists((int)$SenderID)) {
                $live = $this->Describe(GetValue((int)$SenderID));
            }
        } catch (Throwable $e) {
            $live = ['unavailable' => true];
        }
        $sample = [
            'sender_id' => (int)$SenderID, 'native_counter' => $TimeStamp,
            'entered_ns' => $entered, 'native_data' => $this->Describe($Data),
            'live_read_comparison' => $live
        ];
        if (!IPS_SemaphoreEnter($this->LockName(), 1)) {
            // A bounded idempotent flag; no unsafe read/modify/write loss counter.
            $this->SetBuffer('Contention', 'At least one callback was omitted because the probe mutex was busy.');
            return;
        }
        try {
            $meta = $this->Meta();
            if (!$this->ReadAttributeBoolean('Capturing') || empty($meta['active']) ||
                !in_array((int)$SenderID, $meta['ids'], true)) {
                return;
            }
            if ($entrySession !== $meta['session'] || $entered < $meta['start_ns']) {
                return; // A callback from an older capture cannot enter a new one.
            }
            if ($meta['fence'] !== $this->ReadAttributeString('LifecycleFence')) {
                $this->Finish($meta, 'lifecycle interruption');
                return;
            }
            if ($entered >= $meta['deadline_ns']) {
                $this->Finish($meta, 'duration');
                return;
            }
            $sample['index'] = $meta['count'];
            $sample['elapsed_ms'] = ($entered - $meta['start_ns']) / 1000000;
            $sample['session'] = $meta['session'];
            $sample['admitted_ns'] = $this->NowNs();
            $raw = $this->Encode($sample);
            if (strlen($raw) > self::MAX_RECORD_BYTES) {
                $this->Finish($meta, 'oversized diagnostic record; capture incomplete');
                return;
            }
            if ($meta['bytes'] + strlen($raw) > self::MAX_BYTES) {
                $this->Finish($meta, 'byte limit');
                return;
            }
            $this->SetBuffer('Sample' . $meta['count'], $raw);
            ++$meta['count'];
            $meta['bytes'] += strlen($raw);
            if (is_int($TimeStamp)) {
                if ($meta['previous_counter'] !== null && $TimeStamp < $meta['previous_counter']) {
                    ++$meta['counter_regressions'];
                }
                $meta['previous_counter'] = $TimeStamp;
            }
            $key = (string)(int)$SenderID;
            $source = $meta['sources'][$key] ?? ['count' => 0, 'first_ms' => $sample['elapsed_ms'], 'last_ms' => 0];
            ++$source['count'];
            $source['last_ms'] = $sample['elapsed_ms'];
            $meta['sources'][$key] = $source;
            $meta['callback_max_us'] = max($meta['callback_max_us'], ($this->NowNs() - $entered) / 1000);
            if ($meta['count'] >= $meta['limit']) {
                $this->Finish($meta, 'sample limit');
            } else {
                $this->SaveMeta($meta);
            }
        } finally {
            IPS_SemaphoreLeave($this->LockName());
        }
    }

    public function Tick(): void
    {
        if (!IPS_SemaphoreEnter($this->LockName(), 1)) {
            return; // Retry at the next one-second diagnostic tick.
        }
        try {
            if (!$this->ReadAttributeBoolean('Capturing')) {
                $this->SetTimerInterval('CaptureTimer', 0);
                return;
            }
            $meta = $this->Meta();
            if (empty($meta['active'])) {
                return;
            }
            if ($meta['fence'] !== $this->ReadAttributeString('LifecycleFence')) {
                $this->Finish($meta, 'lifecycle interruption');
                return;
            }
            $now = $this->NowNs();
            ++$meta['timer_ticks'];
            $meta['max_timer_lateness_ms'] = max($meta['max_timer_lateness_ms'],
                max(0, ($now - $meta['last_tick_ns']) / 1000000 - 1000));
            $meta['last_tick_ns'] = $now;
            if ($now >= $meta['deadline_ns']) {
                $this->Finish($meta, 'duration');
            } else {
                $this->SaveMeta($meta);
            }
        } finally {
            IPS_SemaphoreLeave($this->LockName());
        }
    }

    public function Stop(): void
    {
        if (!IPS_SemaphoreEnter($this->LockName(), 1)) {
            throw new RuntimeException('Probe busy; retry Stop.');
        }
        try {
            $meta = $this->Meta();
            if (!empty($meta['active']) && $this->ReadAttributeBoolean('Capturing')) {
                $this->Finish($meta, 'manual stop');
            } else {
                $this->DisableCapture();
            }
        } finally {
            IPS_SemaphoreLeave($this->LockName());
        }
    }

    public function GetReport(): string
    {
        if (!IPS_SemaphoreEnter($this->LockName(), 1)) {
            return $this->Encode(['error' => 'Probe busy; retry report.']);
        }
        try {
            if ($this->ReadAttributeBoolean('Capturing')) {
                return $this->Encode(['error' => 'Capture running; wait for automatic stop or call Stop.']);
            }
            $meta = $this->Meta();
            $samples = [];
            for ($i = 0; $i < min(self::MAX_SAMPLES, (int)($meta['count'] ?? 0)); ++$i) {
                $sample = json_decode($this->GetBuffer('Sample' . $i), true);
                if (($sample['session'] ?? null) !== ($meta['session'] ?? null)) {
                    return $this->Encode(['error' => 'Runtime sample/session mismatch; capture incomplete.']);
                }
                $samples[] = $sample;
            }
            return $this->Encode([
                'probe_version' => '0.1.0', 'metadata' => $meta, 'samples' => $samples,
                'contention_notice' => $this->GetBuffer('Contention'),
                'lifecycle_interruption' => ($meta['stop_reason'] ?? null) === 'lifecycle interruption' ||
                    (isset($meta['fence']) && $meta['fence'] !== $this->ReadAttributeString('LifecycleFence')),
                'unfinished_session' => !empty($meta['active']),
                'limits' => ['sample_bytes' => self::MAX_RECORD_BYTES, 'total_sample_bytes' => self::MAX_BYTES],
                'interpretation' => 'Native Data is typed evidence, not an assumed value-field layout. Counter gaps do not prove loss. Live reads are later comparisons. Callback duration excludes final metadata/stop writes. No physical-time ordering or lossless-capture claim.'
            ]);
        } finally {
            IPS_SemaphoreLeave($this->LockName());
        }
    }

    private function Finish(array $meta, string $reason): void
    {
        $meta['active'] = false;
        $meta['stop_reason'] = $reason;
        $meta['stopped_at'] = date(DATE_ATOM);
        $this->SaveMeta($meta);
        $this->DisableCapture();
        $this->SetValue('CaptureStatus', 'Stopped: ' . $reason . '; ' . $meta['count'] . ' samples.');
    }

    private function DisableCapture(): void
    {
        $this->WriteAttributeBoolean('Capturing', false);
        $this->SetTimerInterval('CaptureTimer', 0);
        $ids = json_decode($this->ReadAttributeString('SubscribedIDs'), true);
        foreach (is_array($ids) ? $ids : [] as $id) {
            $this->UnregisterMessage((int)$id, VM_UPDATE);
        }
        $this->WriteAttributeString('SubscribedIDs', '[]');
    }

    private function LifecycleStop(): void
    {
        // Works without runtime buffers. A fence invalidates in-flight work even
        // when the one-millisecond control lock cannot be acquired in Apply.
        $this->WriteAttributeString('LifecycleFence', bin2hex(random_bytes(8)));
        if (!IPS_SemaphoreEnter($this->LockName(), 1)) {
            $this->SetValue('CaptureStatus', 'Lifecycle stop requested; capture will stop at the next callback/tick.');
            return;
        }
        try {
            $this->DisableCapture();
            $this->SetValue('CaptureStatus', 'Stopped by Apply/restart; start manually.');
        } finally {
            IPS_SemaphoreLeave($this->LockName());
        }
    }

    private function Meta(): array
    {
        return json_decode($this->GetBuffer('Meta'), true) ?: [];
    }

    private function SaveMeta(array $meta): void
    {
        $this->SetBuffer('Meta', $this->Encode($meta));
    }

    private function LockName(): string
    {
        return 'FIFOPROBE_' . $this->InstanceID;
    }

    protected function NowNs(): int
    {
        return hrtime(true);
    }

    private function Encode($value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    private function Describe($value): array
    {
        $budget = 24;
        return $this->DescribeNode($value, 0, $budget);
    }

    private function DescribeNode($value, int $depth, int &$budget): array
    {
        if (--$budget < 0) {
            return ['truncated' => true];
        }
        $result = ['type' => gettype($value)];
        if (is_array($value)) {
            $result['count'] = count($value);
            $result['items'] = [];
            if ($depth < 3) {
                foreach ($value as $key => $item) {
                    if ($budget < 1 || count($result['items']) >= 8) {
                        break;
                    }
                    $result['items'][] = ['key' => is_int($key) ? $key : substr($key, 0, 64),
                        'data' => $this->DescribeNode($item, $depth + 1, $budget)];
                }
            }
            $result['truncated'] = count($result['items']) !== count($value);
        } elseif (is_string($value)) {
            $result['bytes'] = strlen($value);
            $result['value'] = substr($value, 0, 96);
            $result['truncated'] = strlen($value) > 96;
        } elseif (is_scalar($value) || $value === null) {
            $result['value'] = $value;
        } else {
            $result['unsupported'] = true;
        }
        return $result;
    }
}
