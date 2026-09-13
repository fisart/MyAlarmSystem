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
$m=fifoFixture(); $GLOBALS['calls']=[];
fifoSend($m,101,true); fifoSend($m,101,false,2);
fifoCheck(count($GLOBALS['calls'])===0, 'Ingress does not dispatch inline');
fifoDrain($m); $payloads=fifoPayloads();
$alarms=array_values(array_filter($payloads,static fn($p)=>$p['event_type']==='ALARM'));
fifoCheck(count($alarms)===2 && $alarms[0]['active_groups']===['door'] && $alarms[1]['active_groups']===[], 'Rapid captured open/close dispatches ordered active then clear');
fifoCheck($alarms[0]['trigger_details']['value_raw']===true && $alarms[0]['trigger_details']['value_human']==='1', 'Payload raw and formatted values use captured open while live is closed');
fifoCheck($payloads[0]['event_type']==='BEDROOM_SYNC' && $payloads[0]['bedrooms'][0]['DoorTripped']===true, 'Bedroom door sync uses captured class');
fifoCheck(fifoReport($m)['metrics']['processed']===2 && $m->GetTimerInterval('InputFifoWorker')===0, 'Idle worker stops after committed prefix');
$before=fifoReport($m)['metrics']['admitted'];
for($i=0;$i<300;++$i) fifoSend($m,101,false,10+$i);
fifoCheck(fifoReport($m)['metrics']['admitted']===$before && fifoReport($m)['metrics']['suppressed']===300 && $m->GetTimerInterval('InputFifoWorker')===0, 'Typed unchanged refresh burst suppressed before queue and wake');
// Native counters on unrelated sources do not define FIFO admission order.
fifoSend($m,103,1,900); fifoSend($m,106,2,100); fifoDrain($m);
fifoCheck(fifoReport($m)['fault']==='', 'Unrelated native counter regression is accepted in own admission order');
$bed=array_values(array_filter(fifoPayloads(),static fn($p)=>$p['event_type']==='BEDROOM_SYNC'));
fifoCheck(end($bed)['bedrooms'][0]['SwitchState']===true, 'Bedroom usage mirror uses captured switch');
// COUNT direct input, reference and sync semantics.
fifoSend($m,102,true); fifoSend($m,105,false); fifoSend($m,105,true); $m->RequestStateSync(); fifoDrain($m);
$state=json_decode($m->attributes['ClassStateAttribute'],true);
fifoCheck(count($state['count']['Buffer'])===1, 'Comparison references and requested sync do not add COUNT');
fifoSend($m,102,false); fifoSend($m,102,true); fifoDrain($m);
fifoCheck(count(json_decode($m->attributes['ClassStateAttribute'],true)['count']['Buffer'])===2, 'Second direct COUNT event retained');
// Pulse clock is captured frame time, expiry mutation is queued.
fifoSend($m,104,true); fifoDrain($m);
$until=json_decode($m->attributes['SensorPulseUntilMap'],true)[104];
$m->CheckPulseExpiry(); fifoCheck(json_decode($m->attributes['SensorPulseUntilMap'],true)[104]===$until, 'Pulse timer does not mutate evaluator caches before its turn');
$meta=fifoReport($m)['metrics']; $slot='InputFifoSlot'.$meta['head']; $rec=json_decode($m->buffers[$slot],true); $rec['wall_s']=$until; $m->buffers[$slot]=json_encode($rec); fifoDrain($m);
fifoCheck(!isset(json_decode($m->attributes['SensorPulseUntilMap'],true)[104]), 'Pulse expires at frame deadline');
// Admission loss retains prefix; recovery does not fabricate CHANGE/ONCE edges.
$m=fifoFixture(7201); $GLOBALS['calls']=[];
fifoSend($m,101,true); $GLOBALS['variables'][103]=9;
$m->MessageSink(2,103,VM_UPDATE,[9,true,7]);
fifoCheck(str_contains(fifoReport($m)['fault'],'continuity') && fifoReport($m)['metrics']['count']===1, 'Prior gap reports explicit loss while retaining prefix');
fifoDrain($m); fifoCheck(fifoReport($m)['metrics']['processed']===1, 'Trustworthy retained prefix evaluated once after admission fault');
$m->RecoverInputFifo(); $r=fifoReport($m);
fifoCheck($r['ready'] && $r['fault']==='' && $r['incident']!=='', 'Automatic recovery restores current processing and retains incident');
fifoCheck(!isset(json_decode($m->attributes['SensorPulseUntilMap'],true)[103]), 'Recovery seeds CHANGE without inventing an edge');
$m->RequestStateSync(); fifoDrain($m); fifoCheck(count(json_decode($m->attributes['ClassStateAttribute'],true)['count']['Buffer'])===0,'Recovery/sync do not fabricate COUNT');
$m->ClearInputFifoIncident(); fifoCheck(fifoReport($m)['incident']==='', 'User may clear warning after trustworthy recovery');
// Ring capacity fault, drain original prefix, never inline fallback.
$m=fifoFixture(7202); $GLOBALS['calls']=[];
for($i=0;$i<128;++$i) fifoSend($m,101,$i%2===0,1000+$i);
fifoSend($m,101,true,1128); $r=fifoReport($m);
fifoCheck($r['metrics']['count']===128 && str_contains($r['fault'],'capacity') && count($GLOBALS['calls'])===0, 'Saturation retains bounded prefix and no live bypass');
fifoDrain($m); fifoCheck(fifoReport($m)['metrics']['processed']===128 && fifoReport($m)['metrics']['count']===0,'Full retained ring drains once');
$m->RecoverInputFifo(); fifoCheck(fifoReport($m)['ready'] && fifoReport($m)['incident']!=='','Overflow recovers without pretending history complete');
// Apply cannot pass queued observations; old graph remains effective through drain.
$m=fifoFixture(7203); fifoSend($m,101,true); $old=$m->attributes['ActiveRevision'];
$c=json_decode($m->pending['ClassList'],true); $c[0]['ClassName']='renamed'; $m->pending['ClassList']=json_encode($c);
$GLOBALS['hold_post_apply']=true; IPS_ApplyChanges(7203);
fifoCheck($m->attributes['ActiveRevision']===$old && $m->attributes['FifoApplyPending'], 'Apply waits for pending old configuration prefix');
fifoDrain($m); $m->RunPostApply(); $m->RunPostApply(); unset($GLOBALS['hold_post_apply']);
fifoCheck($m->attributes['ActiveRevision']!==$old && fifoReport($m)['ready'],'Cutover activates new graph and current baseline after drain');
// Recreated interface has no buffers/subscriptions/timers: no stale pending deadlock.
$m->attributes['FifoHasPending']=true; $m->attributes['FifoFrameInFlight']=true; $m->buffers=[]; $m->messages=[];
$m->Create(); IPS_ApplyChanges(7203);
fifoCheck(fifoReport($m)['ready'] && $m->messages && fifoReport($m)['incident']!=='','Restart detects interrupted work and reacquires monitoring without waiting for vanished queue');
// Metadata lock contention is explicit, no inline alarm output.
$m=fifoFixture(7204); $GLOBALS['calls']=[]; $GLOBALS['semaphore_busy']['Mod1_InputQueue_7204']=true;
fifoSend($m,101,true); unset($GLOBALS['semaphore_busy']['Mod1_InputQueue_7204']);
fifoCheck(str_contains(fifoReport($m)['fault'],'contention') && count($GLOBALS['calls'])===0,'Admission contention visible without inline fallback');
$m->RecoverInputFifo(); fifoCheck(fifoReport($m)['ready'],'Contention recovers to trustworthy current input');
// Fault latch contention cannot hide omitted inputs.
$m=fifoFixture(7205); $GLOBALS['semaphore_busy']['Mod1_InputQueue_7205']=true; $GLOBALS['semaphore_busy']['Mod1_InputFault_7205']=true;
fifoSend($m,101,true); unset($GLOBALS['semaphore_busy']['Mod1_InputQueue_7205'],$GLOBALS['semaphore_busy']['Mod1_InputFault_7205']);
fifoCheck(fifoReport($m)['fault']!=='' && fifoReport($m)['incident']!=='','Fault publication contention retains conservative loss signal');
$m->RecoverInputFifo(); fifoCheck(fifoReport($m)['ready'] && fifoReport($m)['fault']==='','Fault fallback recovers');
// Missing active input degrades only its rules; trustworthy doors and heartbeat-shaped tokens continue.
$m=fifoFixture(7206); unset($GLOBALS['variables'][104]); $m->RecoverInputFifo(); $r=fifoReport($m);
fifoCheck($r['ready'] && $r['unknown_inputs']===[104], 'Missing active input preserves trustworthy monitoring and identifies unknown source');
$blocked=false; try { $m->ClearInputFifoIncident(); } catch (RuntimeException $e) { $blocked=true; }
fifoCheck($blocked, 'Unknown active input prevents clearing degraded warning');
$GLOBALS['calls']=[]; fifoSend($m,101,true); fifoSend($m,103,53040503); fifoSend($m,103,0); fifoDrain($m);
$alarms=array_values(array_filter(fifoPayloads(),static fn($p)=>$p['event_type']==='ALARM'));
fifoCheck(count($alarms)>=2 && in_array('door',$alarms[0]['active_groups'],true), 'Trustworthy intrusion input dispatch continues during unrelated unknown input');
fifoCheck(fifoReport($m)['metrics']['processed']===3, 'Heartbeat-shaped token and reset use ordinary admitted frames during degradation');
$GLOBALS['variables'][104]=false; $m->RecoverInputFifo(); fifoCheck(fifoReport($m)['unknown_inputs']===[], 'Explicit baseline retry recovers restored missing sensor');
// Disabled/non-evaluated sensors cannot prevent startup or overwrite shared ONCE maps.
$m=fifoFixture(7207); $config=json_decode($m->attributes['ActiveConfiguration'],true);
$config['SensorList'][]=['ClassID'=>'once','VariableID'=>99999,'Active'=>false,'Operator'=>0,'ComparisonValue'=>'1','TriggerMode'=>2];
$config['SensorList'][]=['ClassID'=>'once','VariableID'=>104,'Active'=>false,'Operator'=>0,'ComparisonValue'=>'0','TriggerMode'=>2];
$m->attributes['ActiveConfiguration']=json_encode($config); invokePrivate($m,'FifoApplyEnter'); IPS_SemaphoreLeave('Mod1_InputWorker_7207'); $m->attributes['FifoCutover']=false;
$m->RecoverInputFifo();
fifoCheck(fifoReport($m)['ready'] && fifoReport($m)['unknown_inputs']===[], 'Missing disabled sensor does not block FIFO baseline');
fifoCheck(json_decode($m->attributes['SensorConditionStateMap'],true)[104]===false, 'Disabled duplicate ONCE row cannot overwrite active recovery condition');
// Dynamic tamper reference subscriptions attach in normal and rejected Apply.
$config['TamperList']=[['VariableID'=>107,'ComparisonSource'=>1,'ComparisonVariableID'=>108,'Operator'=>0,'ComparisonValue'=>'1','TriggerMode'=>0]];
$GLOBALS['variables'][107]=false; $GLOBALS['variables'][108]=true;
$m->pending['SensorList']=json_encode($config['SensorList']); $m->pending['TamperList']=json_encode($config['TamperList']); IPS_ApplyChanges(7207);
fifoCheck(isset($m->messages[108]), 'Dynamic tamper comparison reference receives native updates');
fifoSend($m,108,false); fifoDrain($m); fifoCheck($m->GetValue('Sabotage')===true,'Tamper comparison mirror updates instead of remaining frozen');
$m->messages=[]; $m->pending['BedroomList']='[{"GroupName":42}]'; IPS_ApplyChanges(7207);
fifoCheck(isset($m->messages[108]) && fifoReport($m)['ready'], 'Rejected draft reattaches dynamic tamper reference on retained graph');
// A legacy invocation paused across activation must not run live evaluator afterward.
$m=fifoFixture(7208); $m->attributes['FifoOwned']=false; $GLOBALS['calls']=[];
$GLOBALS['on_semaphore_enter']=function($name) use($m) {
    if ($name==='Mod1_InputWorker_7208') { unset($GLOBALS['on_semaphore_enter']); $m->attributes['FifoOwned']=true; }
};
invokePrivate($m,'CheckLogic',0,'manual');
fifoCheck(count($GLOBALS['calls'])===0 && fifoReport($m)['metrics']['count']===1, 'Post-owner recheck routes cutover invocation to FIFO'); fifoDrain($m);
// Reentrant consumer sync queues behind current frame, never reacquires evaluator ownership.
$m=fifoFixture(7209); $GLOBALS['calls']=[];
$GLOBALS['on_request_action']=function($id,$ident,$value) use($m) { unset($GLOBALS['on_request_action']); $m->RequestStateSync(); };
fifoSend($m,101,true); fifoDrain($m);
fifoCheck(fifoReport($m)['metrics']['processed']===2 && fifoReport($m)['fault']==='', 'Synchronous consumer request queues sync without recursive evaluator');
// Producer arriving at shutdown cannot leave an idle tail stranded.
$m=fifoFixture(7210); fifoSend($m,101,true); $entries=0;
$GLOBALS['on_semaphore_enter']=function($name) use($m,&$entries) {
    if($name==='Mod1_InputQueue_7210' && ++$entries===4) { unset($GLOBALS['on_semaphore_enter']); fifoSend($m,101,false); }
};
$m->RunInputFifo(); unset($GLOBALS['on_semaphore_enter']);
fifoCheck(fifoReport($m)['metrics']['count']===1 && $m->GetTimerInterval('InputFifoWorker')===50, 'Shutdown sees producer tail and retains wake'); fifoDrain($m);
// A later frame failure keeps completed prefix state, drops uncertainty and never retries outputs.
$m=fifoFixture(7211); $GLOBALS['calls']=[];
fifoSend($m,102,true); fifoSend($m,103,1); fifoSend($m,101,true);
$GLOBALS['on_get_name']=function($id) { if($id===103) { unset($GLOBALS['on_get_name']); throw new RuntimeException('Injected payload metadata failure'); } };
$m->RunInputFifo(); unset($GLOBALS['on_get_name']);
fifoCheck(count(json_decode($m->attributes['ClassStateAttribute'],true)['count']['Buffer'])===1, 'Later frame exception preserves completed prefix COUNT');
fifoCheck(!isset(json_decode($m->attributes['SensorPulseUntilMap'],true)[103]), 'Failed frame pulse mutations are not promoted');
fifoCheck(!fifoReport($m)['ready'] && fifoReport($m)['metrics']['discarded']===1, 'Uncertain evaluator state discards dependent tail explicitly');
$m->RecoverInputFifo(); fifoCheck(fifoReport($m)['ready'] && fifoReport($m)['previous_session']['discarded']===1 && fifoReport($m)['incident']!=='', 'Recovery retains bounded failed-session evidence');
// Baseline acquisition detects native callbacks occurring during the sample.
$m=fifoFixture(7212);
$GLOBALS['on_get_value']=function($id) use($m) { if($id===101) { unset($GLOBALS['on_get_value']); fifoSend($m,101,true); } };
$m->RecoverInputFifo(); unset($GLOBALS['on_get_value']);
fifoCheck(!fifoReport($m)['ready'] && str_contains(fifoReport($m)['fault'],'baseline'), 'Startup generation rejects concurrent observation during baseline acquisition');
$m->RecoverInputFifo(); fifoCheck(fifoReport($m)['ready'], 'Quiet retry completes current baseline after startup race');
// A worker metadata commit failure preserves successful evaluation state without delivery replay.
$m=fifoFixture(7213); fifoSend($m,102,true); $entries=0;
$GLOBALS['on_semaphore_enter']=function($name) use(&$entries) {
    if($name==='Mod1_InputQueue_7213' && ++$entries===2) { unset($GLOBALS['on_semaphore_enter']); $GLOBALS['semaphore_busy'][$name]=true; }
};
$m->RunInputFifo(); unset($GLOBALS['on_semaphore_enter'],$GLOBALS['semaphore_busy']['Mod1_InputQueue_7213']);
fifoCheck(count(json_decode($m->attributes['ClassStateAttribute'],true)['count']['Buffer'])===1 && !fifoReport($m)['ready'], 'Metadata contention keeps completed COUNT prefix and reports uncertainty');
$m->RecoverInputFifo(); fifoCheck(fifoReport($m)['ready'],'Metadata failure recovers without replaying failed delivery');
// A baseline failure after reentrant enqueue cannot leave a not-ready pending queue deadlocked.
$m=fifoFixture(7214); $GLOBALS['variables'][101]=true;
$GLOBALS['on_request_action']=function($id,$ident,$value) use($m) {
    unset($GLOBALS['on_request_action']); fifoSend($m,103,1);
    throw new RuntimeException('Receiver failure before baseline completion');
};
// BEDROOM_SYNC catches receiver exceptions, so fail a later metadata lookup after it enqueues.
$GLOBALS['on_get_name']=function($id) { if($id===7214) { unset($GLOBALS['on_get_name']); throw new RuntimeException('Baseline metadata failure'); } };
$m->RecoverInputFifo(); unset($GLOBALS['on_get_name'],$GLOBALS['on_request_action']);
fifoCheck(!fifoReport($m)['ready'] && !$m->attributes['FifoHasPending'] && fifoReport($m)['metrics']['count']===1, 'Failed baseline classifies queued dependent work without a recovery deadlock');
$m->RecoverInputFifo(); fifoCheck(fifoReport($m)['ready'] && fifoReport($m)['previous_session']['discarded']===1, 'Failed baseline retries current state and retains discarded-queue count');
// Arbitrary exception text cannot break the fault handler by splitting UTF-8.
$m=fifoFixture(7215); invokePrivate($m,'FifoFault',str_repeat('é',255).'😀X',$m->attributes['FifoFence']);
fifoCheck(fifoReport($m)['fault']!=='' && preg_match('//u',fifoReport($m)['incident'])===1, 'Bounded fault publication handles split UTF-8 suffix');
// The legacy shadow comparator cannot silently run without native samples beside the live FIFO.
$m->properties['EnableFifoShadow']=true; $blocked=false;
try { $m->StartFifoShadow(); } catch(RuntimeException $e) { $blocked=str_contains($e->getMessage(),'live input FIFO'); }
fifoCheck($blocked,'Live FIFO and legacy shadow comparator are mutually exclusive');
echo "Input FIFO runtime: $checks checks passed. CPU/resident RAM impact remains unmeasured.\n";
