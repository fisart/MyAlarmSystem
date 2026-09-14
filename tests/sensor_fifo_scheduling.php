<?php
declare(strict_types=1);
ob_start(); require __DIR__.'/sensor_fifo_runtime.php'; ob_end_clean();
$checks=0;
function schedulingFixture(int $id, bool $test=true): SensorGroup {
    $m=fifoFixture($id);$m->attributes['FifoTestActive']=$test;return $m;
}
function schedulingBurst(SensorGroup $m): void {
    for($i=0;$i<70;++$i)fifoSend($m,101,$i%2===0,$i+1);
}
$m=schedulingFixture(7700);$writes=[];
$GLOBALS['on_set_timer_interval']=function($module,$name,$value)use(&$writes){if($name==='InputFifoWorker')$writes[]=$value;};
schedulingBurst($m);
fifoCheck($writes===[50] && fifoReport($m)['worker_interval_ms']===50,'Diagnostic idle wake remains50ms; admissions do not reset scheduled timer');
$m->RunInputFifo();$r=fifoReport($m);
fifoCheck($r['metrics']['count']>0 && $writes===[50,10] && $r['worker_interval_ms']===10,'First nonempty diagnostic shutdown switches continuation once');
fifoCheck($r['metrics']['processed']<=32,'Faster continuation does not enlarge record budget');
$before=$r['metrics'];$m->RecoverInputFifo();
fifoCheck($writes===[50,10] && fifoReport($m)['metrics']===$before,'Recovery retains fast pending tail without resetting timer/baseline');
$m->RunInputFifo();fifoCheck($m->attributes['FifoHasPending'] && $writes===[50,10],'Subsequent busy batch does not rewrite10ms timer');
fifoDrain($m);$r=fifoReport($m);
fifoCheck($r['metrics']['processed']===70 && $r['metrics']['count']===0 && $writes===[50,10,0],'All70 changes drain exactly once then stop periodic timer');
fifoCheck($r['diagnostic_timing']['covers_processed_snapshot'] && $r['fault']==='','Faster continuation preserves complete committed timing and clean state');
fifoSend($m,101,true,100);
fifoCheck($writes===[50,10,0,50],'New isolated admission after empty stop starts with50ms wake');fifoDrain($m);unset($GLOBALS['on_set_timer_interval']);
$m=schedulingFixture(7701,false);schedulingBurst($m);$m->RunInputFifo();
fifoCheck($m->attributes['FifoHasPending'] && $m->GetTimerInterval('InputFifoWorker')===50,'Ordinary applied FIFO retains50ms continuation');
fifoDrain($m);fifoCheck(fifoReport($m)['metrics']['processed']===70 && $m->GetTimerInterval('InputFifoWorker')===0,'Ordinary queue drains and stops unchanged');
// Draft property edits cannot activate fast continuation before Apply.
$m=schedulingFixture(7702,false);$m->properties['EnableFifoFailureCapture']=true;schedulingBurst($m);$m->RunInputFifo();
fifoCheck($m->GetTimerInterval('InputFifoWorker')===50,'Unapplied capture flag cannot alter active scheduling policy');fifoDrain($m);
// Timer requests while a worker is busy never evaluate concurrently.
$m=schedulingFixture(7703);schedulingBurst($m);$m->RunInputFifo();$before=fifoReport($m)['metrics'];
$GLOBALS['semaphore_busy']['Mod1_InputWorker_7703']=true;$m->RunInputFifo();unset($GLOBALS['semaphore_busy']['Mod1_InputWorker_7703']);
fifoCheck(fifoReport($m)['metrics']===$before && $m->GetTimerInterval('InputFifoWorker')===10,'Busy worker request yields without dequeue/evaluation/reset');fifoDrain($m);
// Preserve serialized idle stop/new admission even while the worker owner remains held.
$m=schedulingFixture(7704);schedulingBurst($m);$m->RunInputFifo();
$GLOBALS['on_semaphore_leave']=function($name)use($m){if($name==='Mod1_InputQueue_7704' && !$m->attributes['FifoHasPending'] && $m->GetTimerInterval('InputFifoWorker')===0){unset($GLOBALS['on_semaphore_leave']);fifoSend($m,103,123,100);}};
fifoDrain($m);unset($GLOBALS['on_semaphore_leave']);$r=fifoReport($m);
fifoCheck($r['metrics']['processed']===71 && $r['metrics']['count']===0 && $r['fault']==='','Admission immediately after fast tail idle shutdown is not stranded');
// Disabling must wait for the admitted prefix and then remove applied fast policy.
$m=schedulingFixture(7705);$m->pending['EnableFifoFailureCapture']=true;IPS_ApplyChanges(7705);schedulingBurst($m);$m->RunInputFifo();
$m->pending['EnableInputFifo']=false;$m->pending['EnableFifoFailureCapture']=false;IPS_ApplyChanges(7705);
fifoCheck($m->attributes['FifoApplyPending'] && $m->attributes['FifoOwned'] && $m->GetTimerInterval('InputFifoWorker')===10,'Disable request retains fast active prefix while Apply is deferred');
fifoDrain($m);$m->RunPostApply();$r=fifoReport($m);
fifoCheck(!$r['enabled'] && !$r['test']['active'] && $r['metrics']['processed']===70 && $r['worker_interval_ms']===0,'Deferred Disable finishes after full prefix with timer stopped');
// Diagnostic admission fault pauses later inputs, but trusted queued prefix still drains.
$m=schedulingFixture(7706);schedulingBurst($m);$m->RunInputFifo();
$m->MessageSink(200,103,VM_UPDATE,[9,true,7]);$r=fifoReport($m);
fifoCheck($r['test']['paused'] && $r['test']['first_fault']['stage']==='admission_continuity','Forced invalid admission retains explicit diagnostic pause');
fifoDrain($m);$r=fifoReport($m);
fifoCheck($r['metrics']['processed']===70 && $r['metrics']['count']===0 && $m->GetTimerInterval('InputFifoWorker')===0 && $m->GetTimerInterval('InputFifoRecovery')===0,'Paused test drains only trusted prefix and stops without automatic recovery');
// An unsuccessful native timer update is explicit; evaluated prefix is not replayed.
$m=schedulingFixture(7707);schedulingBurst($m);$GLOBALS['reject_timer_interval']['7707:InputFifoWorker']=10;$m->RunInputFifo();unset($GLOBALS['reject_timer_interval']['7707:InputFifoWorker']);$r=fifoReport($m);$processed=$r['metrics']['processed'];
fifoCheck(!$r['ready'] && $r['test']['paused'] && str_contains($r['fault'],'continuation timer update failed'),'False timer-update return faults explicitly and pauses diagnostic processing');
fifoCheck($processed>0 && $m->GetTimerInterval('InputFifoWorker')===0 && !$r['diagnostic_timing']['covers_processed_snapshot'],'Failed timer transition stops worker and marks evidence incomplete');
$m->RunInputFifo();fifoCheck(fifoReport($m)['metrics']['processed']===$processed,'Failed timer transition does not replay evaluated prefix');
$m=schedulingFixture(7708);schedulingBurst($m);$GLOBALS['on_set_timer_interval']=function($module,$name,$value){if($name==='InputFifoWorker' && $value===10){unset($GLOBALS['on_set_timer_interval']);throw new RuntimeException('Timer interface failure');}};$m->RunInputFifo();unset($GLOBALS['on_set_timer_interval']);
fifoCheck(!fifoReport($m)['ready'] && $m->GetTimerInterval('InputFifoWorker')===0,'Throwing native timer transition follows explicit no-replay fault path');
$limits=fifoReport($m)['limits'];
fifoCheck($limits['worker_idle_wake_ms']===50 && $limits['worker_diagnostic_continuation_ms']===10 && $limits['worker_ordinary_continuation_ms']===50 && $limits['worker_batch_target_ms']===20 && $limits['worker_batch_max_records']===32,'Report exposes timer and unchanged batch-policy bounds');
echo "FIFO scheduling: $checks checks passed. Native timing/thread-load evidence remains required.\n";
