<?php
declare(strict_types=1);

/** Explicit diagnostic policy. Never clear the retained legacy-overlap guard. */
trait SensorGroupFifoFailureCapture
{
    private function FifoFailureCaptureCreate(): void
    {
        $this->RegisterPropertyBoolean('EnableFifoFailureCapture', false);
        $this->RegisterAttributeBoolean('FifoTestActive', false);
        foreach (['FifoTestPauseFence', 'FifoTestOmittedFence', 'FifoFirstTestFault'] as $name) $this->RegisterAttributeString($name, '');
    }

    private function FifoTestFaultLock(): string { return 'Mod1_InputTestFault_' . $this->InstanceID; }

    private function FifoTestPaused(): bool
    {
        if (!$this->ReadAttributeBoolean('FifoTestActive')) return false;
        $fence = $this->ReadAttributeString('FifoFence');
        return $fence !== '' && ($this->ReadAttributeString('FifoTestPauseFence') === $fence || $this->ReadAttributeString('FifoTestOmittedFence') === $fence);
    }

    private function CaptureFirstFifoTestFault(string $reason, string $fence, array $context, bool $unavailable = false): bool
    {
        if (!IPS_SemaphoreEnter($this->FifoTestFaultLock(), 1)) {
            if ($fence === $this->ReadAttributeString('FifoFence') && $this->ReadAttributeBoolean('FifoTestActive') && $this->ReadAttributeString('FifoTestOmittedFence') !== $fence) $this->WriteAttributeString('FifoTestOmittedFence', $fence);
            return false;
        }
        try {
            if ($fence !== $this->ReadAttributeString('FifoFence') || !$this->ReadAttributeBoolean('FifoTestActive')) return true;
            if ($this->ReadAttributeString('FifoTestPauseFence') !== $fence) $this->WriteAttributeString('FifoTestPauseFence', $fence);
            $old = json_decode($this->ReadAttributeString('FifoFirstTestFault'), true) ?: [];
            if (($old['fence'] ?? '') === $fence) return true;
            $unavailable = $unavailable || $this->ReadAttributeString('FifoTestOmittedFence') === $fence;
            $record = ['schema' => 1, 'fence' => $fence, 'revision' => $this->ReadAttributeString('ActiveRevision'), 'observed_at' => $unavailable ? null : date(DATE_ATOM), 'details_unavailable' => $unavailable];
            if ($unavailable) {
                $record['stage'] = 'unavailable';
                $record['reason'] = 'FIFO test paused after a fault; first-failure details were unavailable due to concurrent fault publication.';
            } else {
                $stage = $context['stage'] ?? 'unspecified';
                $record['stage'] = is_string($stage) && preg_match('/^[a-z_]{1,64}$/D', $stage) === 1 ? $stage : 'unspecified';
                $record['reason'] = $this->FifoReason($reason);
                foreach (['variable_id', 'seq', 'native_counter'] as $key) if (isset($context[$key]) && is_int($context[$key])) $record[$key] = $context[$key];
                if (in_array($context['native_counter_type'] ?? '', ['integer', 'double', 'boolean', 'string', 'array', 'object', 'resource', 'resource (closed)', 'NULL'], true)) $record['native_counter_type'] = $context['native_counter_type'];
            }
            $this->WriteAttributeString('FifoFirstTestFault', $this->FifoEncode($record));
            return true;
        } finally { IPS_SemaphoreLeave($this->FifoTestFaultLock()); }
    }

    private function FinalizeFifoTestFault(): bool
    {
        if (!$this->FifoTestPaused()) return true;
        $record = json_decode($this->ReadAttributeString('FifoFirstTestFault'), true) ?: [];
        if (($record['fence'] ?? '') === $this->ReadAttributeString('FifoFence')) return true;
        return $this->CaptureFirstFifoTestFault('', $this->ReadAttributeString('FifoFence'), [], true);
    }

    private function FifoTestReport(): array
    {
        // At most one fallback publication, on demand; normal input/worker processing never calls this.
        $this->FinalizeFifoTestFault();
        $record = json_decode($this->ReadAttributeString('FifoFirstTestFault'), true);
        $current = $this->ReadAttributeBoolean('FifoTestActive') && ($record['fence'] ?? '') === $this->ReadAttributeString('FifoFence');
        return ['configured' => $this->ReadPropertyBoolean('EnableFifoFailureCapture'), 'active' => $this->ReadAttributeBoolean('FifoTestActive'), 'paused' => $this->FifoTestPaused(), 'legacy_overlap_retained' => $this->ReadAttributeBoolean('FifoLegacyOverlap'), 'first_fault' => $record, 'first_fault_is_current' => $current, 'capture_incomplete' => $this->FifoTestPaused() && (!$current || !empty($record['details_unavailable']))];
    }
}
