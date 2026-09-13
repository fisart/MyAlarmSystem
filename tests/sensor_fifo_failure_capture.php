<?php
declare(strict_types=1);
require __DIR__ . '/symcon_stub.php';
require __DIR__ . '/../SensorGroup/module.php';
$checks = 0;
function fifoCheck(bool $ok, string $why): void { global $checks; ++$checks; if (!$ok) throw new RuntimeException('FIFO: ' . $why); }
function fifoFixture(int $instance = 7200): SensorGroup {
    $m = new SensorGroup($instance); $m->Create();
    $c = array_fill_keys(AlarmSafety::LISTS, []);
    foreach ([['door',101,0,0],['count',102,2,0],['change',103,0,1],['once',104,0,2]] as [$cid,$id,$logic,$mode]) {
        $c['ClassList'][] = ['ClassID'=>$cid,'ClassName'=>$cid,'LogicMode'=>$logic,'TimeWindow'=>10,'Threshold'=>2];
        $c['SensorList'][] = ['ClassID'=>$cid,'VariableID'=>$id,'Operator'=>0,'ComparisonValue'=>'1','TriggerMode'=>$mode,'PulseSeconds'=>3];
        $c['GroupList'][] = ['GroupName'=>$cid,'GroupID'=>'group-'.$cid,'GroupLogic'=>0];
        $c['GroupMembers'][] = ['GroupName'=>$cid,'ClassID'=>$cid];
        $c['GroupDispatch'][] = ['GroupName'=>$cid,'InstanceID'=>7000];
    }
    $c['SensorList'][1]['ComparisonSource']=1; $c['SensorList'][1]['ComparisonVariableID']=105;
    $c['BedroomList']=[['GroupName'=>'door','BedroomDoorClassID'=>'door','ActiveVariableID'=>106]];
    $c['DispatchTargets']=[['InstanceID'=>7000]]; $c['BedroomTarget']=7000; $c['MaintenanceMode']=false; $c['TargetThrottleList']=[];
    $GLOBALS['variables'] = array_replace($GLOBALS['variables'], [101=>false,102=>false,103=>0,104=>false,105=>true,106=>0]);
    $GLOBALS['objects'][7000] = new IPSModule(7000);
    foreach ($c as $k=>$v) $m->pending[$k] = is_array($v) ? json_encode($v) : $v;
    $m->pending['EnableInputFifo']=true; IPS_ApplyChanges($instance);
    fifoCheck(json_decode($m->GetInputFifoReport(),true)['ready'], 'Opt-in Apply establishes deferred valid baseline');
    return $m;
}
function fifoSend(SensorGroup $m, int $id, $value, int $counter = 1): void { $previous=$GLOBALS['variables'][$id]; $GLOBALS['variables'][$id]=$value; $m->MessageSink($counter,$id,VM_UPDATE,[$value,$value!==$previous,$previous]); }
function fifoDrain(SensorGroup $m): void { for($i=0;$i<100 && $m->attributes['FifoHasPending'];++$i) $m->RunInputFifo(); }
function fifoReport(SensorGroup $m): array { return json_decode($m->GetInputFifoReport(),true); }
function fifoPayloads(): array { return array_map(static fn($c)=>json_decode($c[2],true), array_values(array_filter($GLOBALS['calls'],static fn($c)=>$c[1]==='ReceivePayload'))); }

function testFixture(int $id): SensorGroup {
    $m=fifoFixture($id);
    $m->attributes['FifoLegacyOverlap']=true;
    $m->pending['EnableFifoFailureCapture']=true;
    IPS_ApplyChanges($id);
    fifoCheck(fifoReport($m)['ready'] && fifoReport($m)['test']['active'] && $m->attributes['FifoLegacyOverlap'], 'Explicit applied diagnostic mode permits activation without clearing historical guard');
    return $m;
}
function testDisable(SensorGroup $m): void {
    $m->pending['EnableInputFifo']=false;
    $m->pending['EnableFifoFailureCapture']=false;
    IPS_ApplyChanges($m->InstanceID);
}
$m=fifoFixture(7300);
fifoCheck(!$m->properties['EnableFifoFailureCapture'] && !fifoReport($m)['test']['active'], 'Diagnostic policy defaults off');
$m->attributes['FifoLegacyOverlap']=true; IPS_ApplyChanges(7300);
fifoCheck(!fifoReport($m)['enabled'], 'Ordinary activation guard remains effective');
$m->pending['EnableInputFifo']=false; $m->pending['EnableFifoFailureCapture']=true; IPS_ApplyChanges(7300);
fifoCheck(!fifoReport($m)['enabled'] && !fifoReport($m)['test']['active'], 'Test checkbox alone does not enable FIFO');
$m=testFixture(7301); $GLOBALS['calls']=[];
fifoSend($m,101,true); $GLOBALS['variables'][103]=9;
$m->MessageSink(2,103,VM_UPDATE,[9,true,7]); $r=fifoReport($m); $first=$r['test']['first_fault'];
fifoCheck($r['enabled'] && $r['ready'] && $r['test']['paused'] && $m->GetTimerInterval('InputFifoRecovery')===0, 'Admission fault freezes new admission without falling back to legacy');
fifoCheck($first['stage']==='admission_continuity' && $first['variable_id']===103 && $first['native_counter']===2 && !isset($first['seq']), 'First failed admission retains bounded stage and source without claiming an admitted sequence');
fifoCheck($r['metrics']['count']===1 && count($GLOBALS['calls'])===0, 'Trusted queued prefix retained for worker');
fifoSend($m,101,false); $m->MessageSink(3,102,VM_UPDATE,[true,1,false]);
fifoCheck(fifoReport($m)['test']['first_fault']===$first && fifoReport($m)['metrics']['count']===1, 'Subsequent omitted/malformed updates do not overwrite first evidence');
fifoDrain($m); $r=fifoReport($m);
fifoCheck($r['metrics']['processed']===1 && $r['metrics']['count']===0 && $m->GetTimerInterval('InputFifoRecovery')===0, 'Trusted prefix finishes once without scheduling recovery');
$baseline=$r['metrics']; for($i=0;$i<5;++$i) $m->RecoverInputFifo(); $m->RunPostApply();
fifoCheck(fifoReport($m)['metrics']===$baseline && $m->GetTimerInterval('InputFifoRecovery')===0, 'Manual/timer recovery cannot rebuild paused session');
fifoCheck(str_contains($r['health'],'new inputs/heartbeat are not processed') && str_contains($r['health'],'Disable live FIFO and Apply'), 'Paused action visible in health');
testDisable($m); $r=fifoReport($m);
fifoCheck(!$r['enabled'] && !$r['test']['active'] && $r['test']['first_fault']===$first && !$r['test']['first_fault_is_current'], 'Disable and Apply restore legacy while preserving historical evidence');
$GLOBALS['calls']=[]; fifoSend($m,103,100);
fifoCheck(count($GLOBALS['calls'])>0, 'Ordinary evaluation resumes after disabling live FIFO');
$m->buffers=[]; $m->messages=[]; $m->Create(); IPS_ApplyChanges(7301);
fifoCheck(fifoReport($m)['test']['first_fault']===$first, 'First evidence survives buffer/interface recreation');
$m->pending['EnableInputFifo']=true; $m->pending['EnableFifoFailureCapture']=true; IPS_ApplyChanges(7301);
fifoCheck(fifoReport($m)['test']['first_fault']===$first && !fifoReport($m)['test']['first_fault_is_current'], 'New test retains previous record until a new actual fault');
$m->MessageSink(4,102,VM_UPDATE,[true,1,false]); $next=fifoReport($m)['test']['first_fault'];
fifoCheck($next['fence']!==$first['fence'] && $next['stage']==='native_input_validation' && $next['variable_id']===102, 'New test first failure replaces previous-session evidence');
// Queue and fault locks are independently contended; capture must stay conservative.
$m=testFixture(7302); $GLOBALS['semaphore_busy']['Mod1_InputQueue_7302']=true;
$GLOBALS['semaphore_busy']['Mod1_InputFault_7302']=true; fifoSend($m,101,true);
unset($GLOBALS['semaphore_busy']['Mod1_InputQueue_7302'],$GLOBALS['semaphore_busy']['Mod1_InputFault_7302']);
$r=fifoReport($m);
fifoCheck($r['test']['paused'] && $r['test']['first_fault']['stage']==='admission_lock' && !$r['test']['capture_incomplete'], 'Dedicated capture retains precise admission-lock failure despite existing fault-lock contention');
$m=testFixture(7303); $GLOBALS['semaphore_busy']['Mod1_InputTestFault_7303']=true;
$m->MessageSink(10,103,VM_UPDATE,[1,1,0]); $r=fifoReport($m);
fifoCheck($r['test']['paused'] && $r['test']['capture_incomplete'] && $m->GetTimerInterval('InputFifoRecovery')===0, 'Capture contention pauses without falsely claiming precise first details');
unset($GLOBALS['semaphore_busy']['Mod1_InputTestFault_7303']); $r=fifoReport($m); $fallback=$r['test']['first_fault'];
fifoCheck($fallback['details_unavailable'] && $fallback['observed_at']===null && $fallback['stage']==='unavailable', 'On-demand fallback explicitly marks unknown first failure');
$m->MessageSink(11,102,VM_UPDATE,[true,1,false]);
fifoCheck(fifoReport($m)['test']['first_fault']===$fallback, 'Later precise failure cannot replace unavailable first-failure fallback');
testDisable($m); $m->buffers=[]; $m->Create(); IPS_ApplyChanges(7303);
fifoCheck(fifoReport($m)['test']['first_fault']===$fallback, 'Unavailable first-failure fallback persists after disabling and recreation');
// Native payload values are not copied into diagnostic context.
$m=testFixture(7304); $secret=str_repeat('PRIVATE-',256);
$m->MessageSink('PRIVATE-COUNTER',103,VM_UPDATE,[$secret,true,0]); $r=fifoReport($m);
fifoCheck($r['test']['first_fault']['native_counter_type']==='string' && !isset($r['test']['first_fault']['native_counter']) && !str_contains(json_encode($r['test']['first_fault']),'PRIVATE-') && strlen($m->attributes['FifoFirstTestFault'])<2048, 'Invalid payload diagnostics remain bounded and exclude sensor/counter strings');
// Changing a draft property cannot change applied diagnostic policy.
$m=testFixture(7305); $m->properties['EnableFifoFailureCapture']=false;
$m->MessageSink(12,103,VM_UPDATE,[1,1,0]);
fifoCheck(fifoReport($m)['test']['paused'] && fifoReport($m)['test']['active'] && $m->GetTimerInterval('InputFifoRecovery')===0, 'Applied test policy remains stable until Apply');
// Baseline activity and uncertain evaluation are different captured stages.
$m=testFixture(7306);
$GLOBALS['on_get_value']=function($id) use($m) { if($id===101) { unset($GLOBALS['on_get_value']); fifoSend($m,101,true); } };
$m->RecoverInputFifo(); unset($GLOBALS['on_get_value']); $r=fifoReport($m);
fifoCheck(!$r['ready'] && $r['test']['paused'] && $r['test']['first_fault']['stage']==='baseline_generation', 'Callback during sampling captures generation invalidation');
$m->attributes['FifoHasPending']=true; testDisable($m);
fifoCheck(!fifoReport($m)['enabled'], 'Paused not-ready state cannot strand Disable Apply behind unusable pending flag');
$m=testFixture(7307); fifoSend($m,102,true); fifoSend($m,103,1); fifoSend($m,101,true);
$GLOBALS['on_get_name']=function($id) { if($id===103) { unset($GLOBALS['on_get_name']); throw new RuntimeException('Injected payload metadata failure'); } };
$m->RunInputFifo(); unset($GLOBALS['on_get_name']); $r=fifoReport($m);
fifoCheck(!$r['ready'] && $r['test']['paused'] && $r['test']['first_fault']['stage']==='worker_evaluation' && $r['test']['first_fault']['seq']===2 && $r['test']['first_fault']['variable_id']===103, 'Uncertain evaluated frame retains sequence/source before pausing');
fifoCheck($r['metrics']['processed']===1 && $r['metrics']['discarded']===1 && $m->GetTimerInterval('InputFifoRecovery')===0, 'Uncertain state preserves completed prefix and discards dependent tail without replay');
$m=testFixture(7308); fifoSend($m,102,true); $entries=0;
$GLOBALS['on_semaphore_enter']=function($name) use(&$entries) { if($name==='Mod1_InputQueue_7308' && ++$entries===2) { unset($GLOBALS['on_semaphore_enter']); $GLOBALS['semaphore_busy'][$name]=true; } };
$m->RunInputFifo(); unset($GLOBALS['on_semaphore_enter'],$GLOBALS['semaphore_busy']['Mod1_InputQueue_7308']); $r=fifoReport($m);
fifoCheck($r['test']['paused'] && $r['test']['first_fault']['stage']==='worker_progress_commit' && $r['test']['first_fault']['seq']===1, 'Progress metadata contention captures phase and evaluated sequence');
testDisable($m); fifoCheck(!fifoReport($m)['enabled'], 'Disable remains available after metadata failure');
// Heartbeat-shaped token and reset use ordinary queue frames, without special handling.
$m=testFixture(7309); fifoSend($m,103,53040503); fifoSend($m,103,0); fifoDrain($m);
fifoCheck(fifoReport($m)['metrics']['processed']===2 && !fifoReport($m)['test']['paused'], 'Ordinary token and reset both evaluated in diagnostic mode');
$before=fifoReport($m)['test']; invokePrivate($m,'FifoFault','stale failure','old-fence',['stage'=>'worker_evaluation']);
fifoCheck(fifoReport($m)['test']===$before, 'Stale session failure cannot pause or publish current evidence');
invokePrivate($m,'FifoFault',str_repeat('é',255).'😀X',$m->attributes['FifoFence'],['stage'=>'bad-'.str_repeat('é',80),'native_counter_type'=>'bad-type']); $r=fifoReport($m);
fifoCheck($r['test']['first_fault']['stage']==='unspecified' && preg_match('//u',$r['test']['first_fault']['reason'])===1 && strlen($m->attributes['FifoFirstTestFault'])<2048, 'Malformed phase labels and split UTF-8 reason cannot break bounded capture');

$m=testFixture(7310); $before=fifoReport($m)['metrics']['recoveries'];
$GLOBALS['on_get_value']=function($id) use($m) { if($id===101) { unset($GLOBALS['on_get_value']); $m->MessageSink(20,103,VM_UPDATE,[1,1,0]); } };
$m->RecoverInputFifo(); unset($GLOBALS['on_get_value']); $r=fifoReport($m);
fifoCheck(!$r['ready'] && $r['test']['paused'] && $r['test']['first_fault']['stage']==='native_input_validation' && $r['metrics']['recoveries']===$before && $m->GetTimerInterval('InputFifoRecovery')===0, 'Invalid callback during sampling pauses before another baseline can publish');
$last=$r['last_fault']; $locks=0;
$GLOBALS['on_semaphore_enter']=function($name) use(&$locks) { if(str_contains($name,'InputTestFault_7310')) ++$locks; };
for($i=0;$i<10;++$i) { $m->MessageSink(21+$i,103,VM_UPDATE,[1,1,0]); $m->RequestStateSync(); fifoReport($m); }
unset($GLOBALS['on_semaphore_enter']);
fifoCheck($locks===0 && fifoReport($m)['last_fault']===$last, 'Paused callbacks and repeated report polls do not republish fault evidence or acquire capture mutex');
$m->pending['EnableInputFifo']=true; $m->pending['EnableFifoFailureCapture']=true;
$GLOBALS['hold_post_apply']=true; IPS_ApplyChanges(7310); unset($GLOBALS['hold_post_apply']);
$GLOBALS['semaphore_busy']['Mod1_InputQueue_7310']=true;
$m->RecoverInputFifo(); unset($GLOBALS['semaphore_busy']['Mod1_InputQueue_7310']); $r=fifoReport($m);
fifoCheck($r['test']['first_fault_is_current'] && !$r['test']['first_fault']['details_unavailable'] && $r['test']['first_fault']['stage']==='baseline_sampling_lock', 'Retained prior generic fault latch cannot erase precise first failure of a new test');
testDisable($m);

$m=testFixture(7311); $before=fifoReport($m)['metrics']['recoveries'];
$GLOBALS['on_semaphore_enter']=function($name) use($m) { if($name==='Mod1_InputFault_7311') { unset($GLOBALS['on_semaphore_enter']); $m->MessageSink(31,103,VM_UPDATE,[1,1,0]); } };
$m->RecoverInputFifo(); unset($GLOBALS['on_semaphore_enter']); $r=fifoReport($m);
fifoCheck($r['test']['paused'] && !$r['ready'] && $r['metrics']['recoveries']===$before && $m->GetTimerInterval('InputFifoRecovery')===0, 'Fault during baseline fault-lock acquisition prevents another baseline publication');
testDisable($m); fifoCheck(!fifoReport($m)['enabled'], 'Disable after publication-boundary fault restores existing evaluation');
echo "FIFO first-failure test: $checks checks passed. Native production behavior remains to be observed.\n";
