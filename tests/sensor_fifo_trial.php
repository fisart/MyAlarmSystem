<?php
declare(strict_types=1);
ob_start(); require __DIR__.'/sensor_fifo_runtime.php'; ob_end_clean();
$checks=0;
function trialFixture(int $id): SensorGroup {
    $m=fifoFixture($id);
    $m->pending['EnableInputFifo']=false; IPS_ApplyChanges($id);
    $m->attributes['FifoLegacyOverlap']=true;
    return $m;
}
function trialStart(SensorGroup $m): void {
    $m->pending['EnableInputFifo']=true;
    $m->pending['AllowFifoTrialCutover']=true;
    IPS_ApplyChanges($m->InstanceID);
}
$m=trialFixture(7900);$r=fifoReport($m);
fifoCheck(!$r['trial']['configured']&&!$r['trial']['active']&&!$r['enabled'],'New permission defaults off and does not activate FIFO');
$m->pending['EnableInputFifo']=true;IPS_ApplyChanges(7900);$r=fifoReport($m);
fifoCheck(!$r['enabled']&&$r['activation_blocked']&&$r['trial']['legacy_overlap_retained'],'Ordinary activation still respects retained guard');
$m->pending['AllowFifoTrialCutover']=true;$r=fifoReport($m);
fifoCheck(!$r['trial']['configured']&&!$r['trial']['active']&&!$r['enabled'],'Draft permission cannot activate FIFO before Apply');
IPS_ApplyChanges(7900);$r=fifoReport($m);
fifoCheck($r['enabled']&&$r['ready']&&$r['trial']['active']&&!$r['activation_blocked'],'Explicit applied trial starts with retained guard without restart');
fifoCheck($r['trial']['legacy_overlap_retained']&&$m->attributes['FifoLegacyOverlap'],'Trial never resets historical concurrency guard');
fifoCheck($r['trial']['automatic_recovery']&&!$r['trial']['automatic_expiry']&&!$r['test']['active'],'Trial has normal recovery and no diagnostic pause or implicit expiry');
fifoCheck(str_contains($r['health'],'FIFO activation compatibility enabled')&&str_contains($r['health'],'Legacy overlap warning retained')&&!str_contains($r['health'],'Disable FIFO after testing'),'Release health exposes compatibility and retained warning without obsolete manual-stop instruction');
fifoCheck(str_contains($r['incident'],'accepts uncertain legacy overlap'),'Accepted switching uncertainty is latched visibly');
$GLOBALS['calls']=[];fifoSend($m,103,71001,1);fifoSend($m,103,0,2);
fifoCheck(fifoReport($m)['metrics']['count']===2&&count($GLOBALS['calls'])===0,'Token and reset use ordinary admission with no inline receiver dispatch');
fifoDrain($m);$p=fifoPayloads();$tokenValues=[];
foreach($p as$row)if(($row['trigger_details']['variable_id']??0)===103)$tokenValues[]=$row['trigger_details']['value_raw'];
fifoCheck(fifoReport($m)['metrics']['processed']===2&&($tokenValues===[71001,0]),'Ordinary token/reset preserve both captured values through receiver path');
fifoCheck(fifoReport($m)['diagnostic_timing']===null&&!isset($m->buffers['InputFifoTiming']),'Trial does not activate per-batch diagnostic timing storage');
$m->ClearInputFifoIncident();$r=fifoReport($m);
fifoCheck($r['incident']===''&&$r['trial']['legacy_overlap_retained']&&str_contains($r['health'],'Legacy overlap warning retained'),'Clearing recovered incident cannot hide retained overlap warning');

$m=trialFixture(7901);trialStart($m);$writes=[];
$GLOBALS['on_set_timer_interval']=function($module,$name,$value)use(&$writes){if($module->InstanceID===7901&&$name==='InputFifoWorker')$writes[]=$value;};
for($i=0;$i<70;++$i)fifoSend($m,101,$i%2===0,$i+1);
fifoCheck($writes===[50],'Trial uses unchanged50ms initial wake and does not rewrite it on each admission');
$m->RunInputFifo();fifoCheck($m->GetTimerInterval('InputFifoWorker')===10&&$m->attributes['FifoHasPending'],'Busy trial adopts native-tested10ms continuation');
$before=fifoReport($m)['metrics'];$m->RecoverInputFifo();
fifoCheck($m->GetTimerInterval('InputFifoWorker')===10&&fifoReport($m)['metrics']===$before,'Normal recovery request preserves fast trustworthy pending tail');
$m->pending['AllowFifoTrialCutover']=false;$m->pending['EnableInputFifo']=false;IPS_ApplyChanges(7901);$r=fifoReport($m);
fifoCheck($m->attributes['FifoApplyPending']&&$r['enabled']&&$r['trial']['active']&&$r['worker_interval_ms']===10,'Rollback defers until admitted prefix finishes and retains applied trial scheduling');
fifoDrain($m);$m->RunPostApply();unset($GLOBALS['on_set_timer_interval']);$r=fifoReport($m);
fifoCheck(!$r['enabled']&&!$r['trial']['active']&&!$r['test']['active']&&$r['worker_interval_ms']===0&&$r['metrics']['processed']===70,'Rollback drains70once then disables actual ownership/timer');
fifoCheck($writes===[50,10,0,0]&&$r['trial']['legacy_overlap_retained'],'Trial timer is written once per transition; rollback retains guard');

$m=trialFixture(7902);trialStart($m);$recoveries=fifoReport($m)['metrics']['recoveries'];fifoSend($m,101,true,1);
$GLOBALS['semaphore_busy']['Mod1_InputQueue_7902']=true;fifoSend($m,103,7,2);unset($GLOBALS['semaphore_busy']['Mod1_InputQueue_7902']);$r=fifoReport($m);
fifoCheck($r['fault']!==''&&!$r['test']['paused']&&$m->GetTimerInterval('InputFifoRecovery')===1000,'Admission fault schedules ordinary recovery instead of diagnostic freeze');
fifoDrain($m);$before=fifoReport($m)['metrics']['processed'];$m->RecoverInputFifo();$r=fifoReport($m);
fifoCheck($before===1&&$r['ready']&&$r['fault']===''&&$r['metrics']['recoveries']===$recoveries+1,'Trustworthy admitted prefix drains before new baseline recovers current values');
fifoCheck($r['last_fault']!==null&&$r['incident']!==''&&$r['trial']['legacy_overlap_retained'],'Normal recovery retains incident and historical guard');
fifoSend($m,103,0,3);fifoDrain($m);
fifoCheck(fifoReport($m)['metrics']['processed']===1&&!fifoReport($m)['test']['paused'],'Ordinary sensor processing resumes after trial recovery');

$m=trialFixture(7903);trialStart($m);for($i=0;$i<70;++$i)fifoSend($m,101,$i%2===0,$i+1);
$GLOBALS['reject_timer_interval']['7903:InputFifoWorker']=10;$m->RunInputFifo();unset($GLOBALS['reject_timer_interval']['7903:InputFifoWorker']);$r=fifoReport($m);$processed=$r['metrics']['processed'];
fifoCheck(!$r['ready']&&!$r['test']['paused']&&$r['fault']!==''&&$m->GetTimerInterval('InputFifoRecovery')===1000,'Failed fast timer transition degrades trial with ordinary recovery');
$m->RunInputFifo();fifoCheck(fifoReport($m)['metrics']['processed']===$processed,'Uncertain worker state never replays completed prefix');
$m->RecoverInputFifo();fifoCheck(fifoReport($m)['ready']&&fifoReport($m)['fault']==='','Trial can recover after timer transition failure');

$m=trialFixture(7904);$m->pending['AllowFifoTrialCutover']=true;IPS_ApplyChanges(7904);
fifoCheck(!fifoReport($m)['enabled']&&!fifoReport($m)['trial']['active'],'Permission alone cannot enable FIFO');
$m->pending['EnableInputFifo']=true;$m->pending['EnableFifoFailureCapture']=true;IPS_ApplyChanges(7904);$r=fifoReport($m);
fifoCheck(!$r['enabled']&&!$r['trial']['active']&&!$r['test']['active']&&$r['trial']['conflicting_modes']&&str_contains($r['health'],'cannot be enabled together'),'Conflicting trial/capture flags cannot accidentally activate pause mode');
$m->pending['AllowFifoTrialCutover']=false;IPS_ApplyChanges(7904);$r=fifoReport($m);
fifoCheck($r['enabled']&&$r['test']['active']&&!$r['trial']['active'],'Existing explicit isolated failure capture remains available separately');
$GLOBALS['semaphore_busy']['Mod1_InputQueue_7904']=true;fifoSend($m,103,1,1);unset($GLOBALS['semaphore_busy']['Mod1_InputQueue_7904']);
fifoCheck(fifoReport($m)['test']['paused']&&$m->GetTimerInterval('InputFifoRecovery')===0,'Capture mode still pauses and disables automatic recovery');
$m->pending['EnableInputFifo']=false;$m->pending['EnableFifoFailureCapture']=false;IPS_ApplyChanges(7904);

$m=trialFixture(7905);$m->pending['EnableInputFifo']=true;$m->pending['AllowFifoTrialCutover']=true;
$GLOBALS['semaphore_busy']['Mod1_InputWorker_7905']=true;$GLOBALS['hold_post_apply']=true;IPS_ApplyChanges(7905);unset($GLOBALS['hold_post_apply'],$GLOBALS['semaphore_busy']['Mod1_InputWorker_7905']);
fifoCheck(!fifoReport($m)['enabled']&&!fifoReport($m)['trial']['active']&&$m->attributes['FifoApplyPending'],'Trial permission cannot bypass current worker owner semaphore');
$m->RunPostApply();$m->RunPostApply();fifoCheck(fifoReport($m)['enabled']&&fifoReport($m)['ready'],'Deferred activation establishes baseline only after owner is free');
$m->pending['AllowFifoTrialCutover']=false;IPS_ApplyChanges(7905);$r=fifoReport($m);
fifoCheck(!$r['enabled']&&$r['activation_blocked']&&!$r['trial']['active'],'Removing permission restores guard-controlled activation');
$form=$m->GetConfigurationForm();
fifoCheck(str_contains($form,'AllowFifoTrialCutover')&&str_contains($form,'Advanced activation and diagnostics')&&str_contains($form,'no automatic expiry'),'Release form retains compatibility and places no-expiry pause mode in advanced diagnostics');
$limits=$r['limits'];fifoCheck($limits['worker_trial_continuation_ms']===10&&$limits['slots']===128&&$limits['admission_wait_ms']===10,'Trial report retains bounded queue/waits and exposes tested continuation');
$m=fifoFixture(7906);$m->pending['AllowFifoTrialCutover']=true;$m->pending['EnableFifoFailureCapture']=true;IPS_ApplyChanges(7906);$r=fifoReport($m);
fifoCheck(!$r['trial']['legacy_overlap_retained']&&!$r['enabled']&&$r['trial']['conflicting_modes']&&$r['activation_blocked'],'Mode conflict is reported as blocked even without historical legacy guard');
echo "FIFO supervised trial: $checks checks passed. Production delivery/load remains unverified.\n";
