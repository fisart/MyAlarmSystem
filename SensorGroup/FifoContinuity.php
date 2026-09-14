<?php
declare(strict_types=1);

/** Explicit local Float lab only; never changes continuity checks or recovery. */
trait SensorGroupFifoContinuity
{
    public function StartFifoContinuityDiagnostic(int $VariableID): bool
    {
        if (!IPS_SemaphoreEnter($this->FifoWorkerLock(), 1)) return false;
        try {
            $root = IPS_GetParent($this->InstanceID); $config = $this->ActiveConfig();
            if ((IPS_GetObject($root)['ObjectIdent'] ?? '') !== 'MyAlarmFifoTemperatureLab'
                || IPS_GetParent($root) !== 0 || IPS_GetParent($VariableID) !== $root
                || (IPS_GetVariable($VariableID)['VariableType'] ?? -1) !== 2
                || !in_array($VariableID, $this->FifoDependencies($config), true)
                || !$this->ReadAttributeBoolean('FifoTestActive') || !$this->ReadAttributeBoolean('FifoOwned')
                || !$this->ReadAttributeBoolean('FifoReady') || $this->FifoTestPaused()
                || !empty($config['DispatchTargets']) || !empty($config['GroupDispatch'])
                || !empty($config['BedroomList']) || !empty($config['TamperList'])
                || !empty($config['BedroomTarget']) || $this->ReadPropertyInteger('VaultInstanceID') !== 0) {
                throw new RuntimeException('Continuity capture requires a local Float variable in the isolated temperature lab.');
            }
            if (!IPS_SemaphoreEnter($this->FifoQueueLock(), self::FIFO_QUEUE_WAIT_MS)) return false;
            try {
                $meta = $this->FifoMeta();
                if ($meta['count'] !== 0 || $this->ReadAttributeBoolean('FifoFrameInFlight')) return false;
                $audit = ['schema'=>1, 'variable_id'=>$VariableID, 'fence'=>$meta['fence'],
                    'baseline_start_ns'=>$meta['start_ns'], 'deadline_ns'=>hrtime(true)+30000000000,
                    'complete'=>true, 'samples'=>[], 'limits'=>['samples'=>64,'bytes'=>16384,'seconds'=>30],
                    'scope'=>'Admission evidence for one isolated lab Float input; native counter is opaque, entry_ns is monotonic callback-entry time, admission order is capture_index.'];
                $this->SetBuffer('InputFifoContinuity', $this->FifoEncode($audit));
                return true;
            } finally { IPS_SemaphoreLeave($this->FifoQueueLock()); }
        } finally { IPS_SemaphoreLeave($this->FifoWorkerLock()); }
    }

    /** Caller owns admission mutex. Optional diagnostic failure never faults the evaluator. */
    private function CaptureFifoContinuity(array $record, array $entry, array $meta, int $entered, ?bool $changed): void
    {
        try {
            $raw = $this->GetBuffer('InputFifoContinuity');
            if ($raw === '' || strlen($raw)>16384) return;
            $audit = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            if (($audit['fence']??'')!==$meta['fence'] || ($audit['baseline_start_ns']??0)!==$meta['start_ns']
                || ($audit['variable_id']??0)!==$record['variable_id'] || !$audit['complete']) return;
            if (count($audit['samples'])>=64 || hrtime(true)>$audit['deadline_ns']) {
                $audit['complete']=false; $audit['notice']='Capture bound reached.';
            } else {
                $describe=static fn($value): array => ['type'=>get_debug_type($value),
                    'value'=>(is_int($value)||is_float($value))?$value:null];
                $audit['samples'][]=['capture_index'=>count($audit['samples'])+1,
                    'candidate_seq'=>$meta['next_seq'], 'entry_ns'=>$entered, 'captured_at'=>date(DATE_ATOM),
                    'native_counter'=>$record['native_counter'], 'native_changed'=>$changed,
                    'stored'=>$describe($entry['value']), 'previous'=>$describe($record['previous']),
                    'current'=>$describe($record['value']), 'prior_matches'=>$entry['value']===$record['previous'],
                    'same_as_stored'=>$entry['value']===$record['value']];
            }
            $encoded=$this->FifoEncode($audit);
            if (strlen($encoded)>16384) {
                array_pop($audit['samples']); $audit['complete']=false; $audit['notice']='Capture byte bound reached.';
                $encoded=$this->FifoEncode($audit);
            }
            $this->SetBuffer('InputFifoContinuity', $encoded);
        } catch (Throwable $ignored) { /* Diagnostic is optional; no alarm processing side effects. */ }
    }

    private function FifoContinuityReport(array $meta): ?array
    {
        $raw=$this->GetBuffer('InputFifoContinuity');
        if ($raw==='' || strlen($raw)>16384) return null;
        $audit=json_decode($raw,true);
        if (!is_array($audit)) return null;
        $audit['is_current_baseline']=$this->ReadAttributeBoolean('FifoOwned')
            && ($audit['fence']??'')===($meta['fence']??'')
            && ($audit['baseline_start_ns']??0)===($meta['start_ns']??0);
        return $audit;
    }
}
