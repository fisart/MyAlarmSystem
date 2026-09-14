<?php
declare(strict_types=1);

/** Volatile, bounded worker summaries only during the explicitly applied FIFO test. */
trait SensorGroupFifoTiming
{
    private function FifoTimingBegin(string $fence): ?array
    {
        try {
            if (!$this->ReadAttributeBoolean('FifoTestActive')) return null;
            $meta = $this->FifoMeta();
            return ['fence' => $fence, 'baseline_start_ns' => $meta['start_ns'] ?? 0,
                'evaluation_ns' => 0, 'evaluation_max_ns' => 0, 'evaluation_attempts' => 0,
                'queue_wait_ns' => 0, 'queue_attempts' => 0, 'queue_misses' => 0, 'state_commit_ns' => 0];
        } catch (Throwable $ignored) { return null; }
    }

    private function FifoTimedQueueEnter(int $wait, ?array &$timing): bool
    {
        $started = $timing === null ? 0 : hrtime(true);
        $entered = IPS_SemaphoreEnter($this->FifoQueueLock(), $wait);
        if ($timing !== null) {
            $timing['queue_wait_ns'] += hrtime(true) - $started;
            ++$timing['queue_attempts'];
            if (!$entered) ++$timing['queue_misses'];
        }
        return $entered;
    }

    /** Diagnostics must neither cause an alarm-processing fault nor retry a frame. */
    private function FifoTimingEnd(?array $timing, int $started, bool $completed, string $stage, int $done): void
    {
        if ($timing === null) return;
        try {
            $ended = hrtime(true);
            $meta = $this->FifoMeta();
            if (($meta['fence'] ?? '') !== $timing['fence'] || ($meta['start_ns'] ?? 0) !== $timing['baseline_start_ns']) return;
            $raw = $this->GetBuffer('InputFifoTiming');
            $summary = strlen($raw) <= 8192 ? (json_decode($raw, true) ?: []) : [];
            if (($summary['fence'] ?? '') !== $timing['fence'] || ($summary['baseline_start_ns'] ?? 0) !== $timing['baseline_start_ns']) {
                $summary = ['schema' => 1, 'fence' => $timing['fence'], 'baseline_start_ns' => $timing['baseline_start_ns'],
                    'batches' => 0, 'complete' => true, 'evaluation_attempts' => 0, 'queue_attempts' => 0, 'queue_misses' => 0,
                    'evaluation_total_ms' => 0.0, 'queue_wait_total_ms' => 0.0, 'state_commit_total_ms' => 0.0,
                    'worker_elapsed_total_ms' => 0.0, 'pending_gap_total_ms' => 0.0, 'pending_gap_max_ms' => 0.0, 'samples' => []];
            }
            $gap = isset($summary['ended_ns']) && ($summary['pending_after'] ?? 0) > 0 ? max(0, $started - $summary['ended_ns']) / 1000000 : null;
            $sample = ['records_evaluated' => $done, 'evaluation_attempts' => $timing['evaluation_attempts'],
                'evaluation_ms' => $timing['evaluation_ns'] / 1000000, 'evaluation_max_ms' => $timing['evaluation_max_ns'] / 1000000,
                'queue_wait_ms' => $timing['queue_wait_ns'] / 1000000, 'state_commit_ms' => $timing['state_commit_ns'] / 1000000,
                'worker_elapsed_ms' => ($ended - $started) / 1000000, 'gap_before_ms' => $gap,
                'pending_after' => (int)($meta['count'] ?? 0), 'completed' => $completed, 'stage' => $stage];
            ++$summary['batches'];
            $summary['complete'] = $summary['complete'] && $completed;
            foreach (['evaluation_attempts', 'queue_attempts', 'queue_misses'] as $key) $summary[$key] += $timing[$key];
            foreach (['evaluation', 'queue_wait', 'state_commit'] as $key) $summary[$key . '_total_ms'] += $timing[$key . '_ns'] / 1000000;
            $summary['worker_elapsed_total_ms'] += $sample['worker_elapsed_ms'];
            if ($gap !== null) {
                $summary['pending_gap_total_ms'] += $gap;
                $summary['pending_gap_max_ms'] = max($summary['pending_gap_max_ms'], $gap);
            }
            $summary['ended_ns'] = $ended;
            $summary['pending_after'] = $sample['pending_after'];
            $summary['processed_at_last_batch'] = (int)($meta['processed'] ?? 0);
            $summary['samples'][] = $sample;
            $summary['samples'] = array_slice($summary['samples'], -8);
            $encoded = $this->FifoEncode($summary);
            if (strlen($encoded) <= 8192) $this->SetBuffer('InputFifoTiming', $encoded);
        } catch (Throwable $ignored) {
            // Optional evidence unavailable; report coverage checks reject stale summaries.
        }
    }

    private function FifoTimingReport(array $meta): ?array
    {
        $raw = $this->GetBuffer('InputFifoTiming');
        if ($raw === '' || strlen($raw) > 8192) return null;
        $summary = json_decode($raw, true);
        if (!is_array($summary) || ($summary['schema'] ?? 0) !== 1) return null;
        $current = ($summary['fence'] ?? '') === $this->ReadAttributeString('FifoFence')
            && ($summary['baseline_start_ns'] ?? 0) === ($meta['start_ns'] ?? 0);
        $summary['is_current_baseline'] = $current;
        $summary['covers_processed_snapshot'] = $current && ($summary['complete'] ?? false)
            && ($summary['processed_at_last_batch'] ?? -1) === ($meta['processed'] ?? 0)
            && ($summary['batches'] ?? -1) === ($meta['batches'] ?? 0) && !$this->ReadAttributeBoolean('FifoFrameInFlight');
        return $summary;
    }
}
