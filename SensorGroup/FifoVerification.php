<?php
declare(strict_types=1);

/** Explicit isolated-lab capture: at most sixteen frames, one volatile write per batch. */
trait SensorGroupFifoVerification
{
    private const FIFO_VERIFICATION_BYTES = 24576;

    public function StartFifoVerification(string $selection): bool
    {
        if (strlen($selection) > 2048) throw new RuntimeException('Verification selection too large.');
        $selected = json_decode($selection, true, 16, JSON_THROW_ON_ERROR);
        $sources = $selected['sources'] ?? []; $classes = $selected['classes'] ?? [];
        if (!is_array($sources) || !is_array($classes) || count($sources) < 1 || count($sources) > 8 || count($classes) < 1 || count($classes) > 8) throw new RuntimeException('Verification selection outside bounds.');
        if (!IPS_SemaphoreEnter($this->FifoWorkerLock(), 1)) return false;
        try {
            $root = IPS_GetParent($this->InstanceID); $config = $this->ActiveConfig();
            if (!in_array(IPS_GetObject($root)['ObjectIdent'] ?? '', ['MyAlarmFifoLab', 'MyAlarmFifoLoadLab'], true)
                || !$this->ReadAttributeBoolean('FifoTestActive') || !$this->ReadAttributeBoolean('FifoOwned')
                || !$this->ReadAttributeBoolean('FifoReady') || $this->FifoTestPaused()
                || !empty($config['DispatchTargets']) || !empty($config['GroupDispatch']) || !empty($config['BedroomList'])
                || !empty($config['TamperList']) || !empty($config['BedroomTarget']) || !empty($config['VaultInstanceID'])) throw new RuntimeException('Verification requires an isolated, ready diagnostic lab.');
            $dependencies = $this->FifoDependencies($config);
            foreach ($sources as $name => $id) if (!is_string($name) || preg_match('/^[A-Za-z][A-Za-z0-9]{0,23}$/D', $name) !== 1 || !is_int($id) || !in_array($id, $dependencies, true) || IPS_GetParent($id) !== $root) throw new RuntimeException('Verification source is not a local dependency.');
            $knownClasses = array_column($config['ClassList'], 'ClassID');
            foreach ($classes as $id) if (!is_string($id) || !in_array($id, $knownClasses, true)) throw new RuntimeException('Unknown verification class.');
            if (!IPS_SemaphoreEnter($this->FifoQueueLock(), self::FIFO_QUEUE_WAIT_MS)) return false;
            try {
                $meta = $this->FifoMeta();
                if (($meta['count'] ?? 0) !== 0 || $this->ReadAttributeBoolean('FifoFrameInFlight')) return false;
                $values = (json_decode($this->GetBuffer('InputFifoState'), true) ?: [])['values'] ?? [];
                $maps = $this->FifoLoadMaps(); $initial = [];
                foreach ($sources as $name => $id) {
                    if (!array_key_exists($id, $values)) throw new RuntimeException('Unknown verification input.');
                    $initial[$name] = $values[$id];
                }
                $buffers = [];
                foreach ($classes as $id) {
                    $buffers[$id] = $maps['classes'][$id]['Buffer'] ?? [];
                    if (count($buffers[$id]) > 16) throw new RuntimeException('Retained lab COUNT history exceeds verification bound; wait for expiry.');
                }
                $audit = ['schema' => 1, 'fence' => $meta['fence'], 'baseline_start_ns' => $meta['start_ns'],
                    'start_seq' => $meta['next_seq'], 'start_processed' => $meta['processed'], 'deadline_ns' => hrtime(true) + 30000000000,
                    'sources' => $sources, 'classes' => array_values($classes), 'initial_values' => $initial, 'initial_buffers' => $buffers,
                    'initial_pulses' => array_intersect_key($maps['pulses'], array_flip(array_values($sources))),
                    'complete' => true, 'records' => [], 'processed_at_last_batch' => $meta['processed'],
                    'limits' => ['frames' => 16, 'bytes' => self::FIFO_VERIFICATION_BYTES, 'duration_seconds' => 30],
                    'scope' => 'Evaluated mirror and class/pulse decisions only; no downstream delivery proof.'];
                $raw = $this->FifoEncode($audit);
                if (strlen($raw) > self::FIFO_VERIFICATION_BYTES) throw new RuntimeException('Verification initial state too large.');
                $this->SetBuffer('InputFifoVerification', $raw);
                return true;
            } finally { IPS_SemaphoreLeave($this->FifoQueueLock()); }
        } finally { IPS_SemaphoreLeave($this->FifoWorkerLock()); }
    }

    private function FifoVerificationBegin(string $fence): ?array
    {
        try {
            if (!$this->ReadAttributeBoolean('FifoTestActive')) return null;
            $raw = $this->GetBuffer('InputFifoVerification');
            if ($raw === '' || strlen($raw) > self::FIFO_VERIFICATION_BYTES) return null;
            $audit = json_decode($raw, true, 32, JSON_THROW_ON_ERROR); $meta = $this->FifoMeta();
            if (($audit['fence'] ?? '') !== $fence || ($audit['baseline_start_ns'] ?? 0) !== ($meta['start_ns'] ?? 0)) return null;
            if (hrtime(true) > $audit['deadline_ns']) { $audit['complete'] = false; $audit['notice'] = 'Verification duration exceeded.'; }
            return $audit;
        } catch (Throwable $ignored) { return null; }
    }

    private function FifoVerificationFrame(?array &$audit, array $record): void
    {
        if ($audit === null || !$audit['complete']) return;
        try {
            if (count($audit['records']) >= 16 || hrtime(true) > $audit['deadline_ns']) { $audit['complete'] = false; $audit['notice'] = 'Verification capture limit reached.'; return; }
            $values = []; $counts = []; $pulses = []; $conditions = [];
            foreach ($audit['sources'] as $name => $id) {
                $values[$name] = $this->fifoValues[$id] ?? null;
                $pulses[$name] = (int)($this->fifoMaps['pulses'][$id] ?? 0);
                $conditions[$name] = $this->fifoMaps['conditions'][$id] ?? null;
            }
            foreach ($audit['classes'] as $id) $counts[$id] = count($this->fifoMaps['classes'][$id]['Buffer'] ?? []);
            $active = json_decode($this->ReadAttributeString('ActiveClassesBuffer'), true, 32, JSON_THROW_ON_ERROR);
            $audit['records'][] = ['seq' => $record['seq'], 'kind' => $record['kind'], 'variable_id' => $record['variable_id'],
                'previous' => $record['previous'] ?? null, 'value' => $record['value'] ?? null, 'wall_s' => $record['wall_s'],
                'values' => $values, 'counts' => $counts, 'pulses' => $pulses, 'conditions' => $conditions,
                'active_classes' => array_values(array_intersect($active, $audit['classes']))];
        } catch (Throwable $ignored) { $audit['complete'] = false; $audit['notice'] = 'Verification frame unavailable.'; }
    }

    private function FifoVerificationEnd(?array $audit, bool $completed): void
    {
        if ($audit === null) return;
        try {
            $meta = $this->FifoMeta();
            $audit['complete'] = $audit['complete'] && $completed;
            $audit['processed_at_last_batch'] = $meta['processed'];
            $raw = $this->FifoEncode($audit);
            if (strlen($raw) <= self::FIFO_VERIFICATION_BYTES) $this->SetBuffer('InputFifoVerification', $raw);
        } catch (Throwable $ignored) { /* Missing batch evidence fails coverage; never fault/replay the evaluator. */ }
    }

    private function FifoVerificationReport(array $meta): ?array
    {
        try {
            $raw = $this->GetBuffer('InputFifoVerification');
            if ($raw === '' || strlen($raw) > self::FIFO_VERIFICATION_BYTES) return null;
            $audit = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            $current = ($audit['fence'] ?? '') === $this->ReadAttributeString('FifoFence') && ($audit['baseline_start_ns'] ?? 0) === ($meta['start_ns'] ?? 0);
            $audit['is_current_baseline'] = $current;
            $audit['covers_processed_snapshot'] = $current && $audit['complete'] && !$this->ReadAttributeBoolean('FifoFrameInFlight')
                && $audit['processed_at_last_batch'] === ($meta['processed'] ?? -1)
                && count($audit['records']) === ($meta['processed'] ?? 0) - $audit['start_processed'];
            $audit['captured_frames'] = count($audit['records']);
            // Retain one full capture in Result, without repeating historical values in snapshots.
            if (!$current) unset($audit['records'], $audit['initial_values'], $audit['initial_buffers'], $audit['initial_pulses']);
            return $audit;
        } catch (Throwable $ignored) { return null; }
    }
}
