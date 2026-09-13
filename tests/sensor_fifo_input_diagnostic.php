<?php
declare(strict_types=1);
require __DIR__ . '/symcon_stub.php';
require __DIR__ . '/../SensorGroup/module.php';
$checks = 0;
function inputCheck(bool $ok, string $why): void { global $checks; ++$checks; if (!$ok) throw new RuntimeException('Passive input check: ' . $why); }
function inputFixture(int $id): SensorGroup {
    $m = new SensorGroup($id); $m->Create();
    $c = array_fill_keys(AlarmSafety::LISTS, []);
    foreach ([['door',101,0],['token',103,1]] as [$cid,$vid,$mode]) {
        $c['ClassList'][]=['ClassID'=>$cid,'ClassName'=>$cid,'LogicMode'=>0];
        $c['SensorList'][]=['ClassID'=>$cid,'VariableID'=>$vid,'Operator'=>0,'ComparisonValue'=>'1','TriggerMode'=>$mode,'PulseSeconds'=>3];
        $c['GroupList'][]=['GroupName'=>$cid,'GroupID'=>'group-'.$cid,'GroupLogic'=>0];
        $c['GroupMembers'][]=['GroupName'=>$cid,'ClassID'=>$cid];
        $c['GroupDispatch'][]=['GroupName'=>$cid,'InstanceID'=>7000];
    }
    $c['ClassList'][]=['ClassID'=>'disabled','ClassName'=>'Disabled network','LogicMode'=>0,'Active'=>false];
    $c['SensorList'][]=['ClassID'=>'disabled','VariableID'=>999,'Operator'=>0,'ComparisonValue'=>'1','TriggerMode'=>0];
    $c['TamperList']=[['VariableID'=>105,'ComparisonSource'=>1,'ComparisonVariableID'=>106,'Operator'=>0,'ComparisonValue'=>'1']];
    $c['BedroomList']=[['GroupName'=>'door','BedroomDoorClassID'=>'door','ActiveVariableID'=>10822]];
    $c['DispatchTargets']=[['InstanceID'=>7000]]; $c['BedroomTarget']=7000; $c['MaintenanceMode']=false; $c['TargetThrottleList']=[];
    $GLOBALS['variables']=array_replace($GLOBALS['variables'],[101=>false,103=>0,105=>false,106=>true,10822=>0,999=>false]);
    $GLOBALS['objects'][7000]=new IPSModule(7000);
    foreach($c as $k=>$v)$m->pending[$k]=is_array($v)?json_encode($v):$v;
    IPS_ApplyChanges($id); $m->attributes['FifoLegacyOverlap']=true;
    return $m;
}
function inputReport(SensorGroup $m): ?array { return json_decode($m->GetInputFifoReport(),true)['input_diagnostic']; }
function inputSend(SensorGroup $m, int $id, $value, int $counter=1): void {
    $previous=$GLOBALS['variables'][$id]; $GLOBALS['variables'][$id]=$value;
    $m->MessageSink($counter,$id,VM_UPDATE,[$value,$value!==$previous,$previous]);
}
function inputProjection(): array {
    return array_map(static function($call) { $p=json_decode($call[2],true); return [$call[0],$call[1],$p['event_type'],$p['active_groups']??null,$p['bedrooms']??null]; },array_values(array_filter($GLOBALS['calls'],static fn($c)=>$c[1]==='ReceivePayload')));
}
$m=inputFixture(7400); inputCheck(inputReport($m)===null,'Off by default with no diagnostic counters');
$before=[$m->attributes,$m->properties,$m->pending,$m->timers]; $GLOBALS['calls']=[];
$m->StartInputFifoDiagnostic(); $r=inputReport($m);
inputCheck($before===[$m->attributes,$m->properties,$m->pending,$m->timers] && !$m->attributes['FifoOwned'] && $m->attributes['FifoLegacyOverlap'] && $GLOBALS['calls']===[], 'Start leaves guard, evaluator state, timers, configuration and outputs unchanged');
inputCheck($r['active'] && $r['observed']===0 && $r['rejected']===0,'Started session does not invent observations');
$token=$m->buffers['InputFifoDiagnosticToken']; $blocked=false;
try {$m->StartInputFifoDiagnostic();}catch(RuntimeException $e){$blocked=true;}
inputCheck($blocked && $m->buffers['InputFifoDiagnosticToken']===$token,'Duplicate Start preserves running capture');
foreach([[101,true],[101,false],[103,53040503],[103,0]] as [$id,$value])inputSend($m,$id,$value);
$r=inputReport($m); inputCheck($r['observed']===4 && $r['rejected']===0,'Boolean transitions and heartbeat-shaped token/reset use identical native checks');
$dispatchCount=count($GLOBALS['calls']); for($i=0;$i<50;++$i)inputSend($m,101,false);
inputCheck(inputReport($m)['observed']===54 && count($GLOBALS['calls'])===$dispatchCount,'Passive capture observes refreshes before existing suppression without extra outputs');
// Compare actual legacy outputs for identical traffic with passive capture off/on.
$off=inputFixture(7401); $GLOBALS['calls']=[]; inputSend($off,101,true);inputSend($off,101,false);inputSend($off,103,53040503);inputSend($off,103,0);$expected=inputProjection();
$on=inputFixture(7402);$on->StartInputFifoDiagnostic();$GLOBALS['calls']=[];inputSend($on,101,true);inputSend($on,101,false);inputSend($on,103,53040503);inputSend($on,103,0);
inputCheck(inputProjection()===$expected,'Passive checks preserve actual legacy event types, recipients, active groups and bedroom decisions');
$m=inputFixture(7403);$m->StartInputFifoDiagnostic();$before=[$m->attributes,$m->properties,$m->pending,$m->timers];$GLOBALS['calls']=[];
invokePrivate($m,'ObserveInputFifoDiagnostic',1,999,[str_repeat('PRIVATE-',256),true,false]);$r=inputReport($m);
inputCheck($r['rejected']===1 && !$r['examples'][0]['required_by_fifo_at_start'] && str_contains($r['examples'][0]['reason'],'string(2048 bytes)') && !str_contains(json_encode($r),'PRIVATE-'),'Unused subscribed source reports unsupported size without storing sensor contents');
inputCheck($before===[$m->attributes,$m->properties,$m->pending,$m->timers] && $GLOBALS['calls']===[], 'Passive rejection never faults/rebaselines FIFO, clears guard, changes runtime or sends outputs');
invokePrivate($m,'ObserveInputFifoDiagnostic',2,999,[str_repeat('PRIVATE-',257),true,false]);
inputCheck(inputReport($m)['rejected']===2 && count(inputReport($m)['examples'])===1,'Repeated rejection counts without growing distinct-source examples');
// Exercise exact shared live-admission contracts, including boundaries and invalid UTF-8.
$cases=[
    [1,[true,true,false],true],[1,[1.5,true,0.0],true],[1,[str_repeat('x',1024),false,str_repeat('x',1024)],true],
    ['1',[true,true,false],false],[1,null,false],[1,[1=>true,2=>false],false],[1,[true,1,false],false],
    [1,[0=>true,1=>true],false],[1,[INF,true,0],false],[1,[NAN,true,0],false],
    [1,[str_repeat('x',1025),true,''],false],[1,["\xff",true,''],false],[1,[[1],true,0],false],
];
foreach($cases as $i=>[$counter,$data,$valid]) {
    $beforeRejected=inputReport($m)['rejected'];invokePrivate($m,'ObserveInputFifoDiagnostic',$counter,600+$i,$data);
    inputCheck((inputReport($m)['rejected']===$beforeRejected+($valid?0:1)) && (invokePrivate($m,'FifoNativeInputError',$counter,600+$i,$data)==='')===$valid,'Native contract case '.$i.' agrees with live admission');
}
$r=inputReport($m);inputCheck(count($r['examples'])===8 && strlen($m->buffers['InputFifoDiagnosticExamples'])<8192,'Distinct sources and serialized example storage stay bounded');
$m->StopInputFifoDiagnostic();$count=inputReport($m)['observed'];invokePrivate($m,'ObserveInputFifoDiagnostic',1,101,[true,true,false]);
inputCheck(!inputReport($m)['active'] && inputReport($m)['observed']===$count,'Manual Stop retains report and ignores later observations');
$m->StartInputFifoDiagnostic();inputCheck(inputReport($m)['observed']===0 && inputReport($m)['examples']===[],'New completed-session capture resets only passive buffers');
$m->buffers['InputFifoDiagnosticDeadline']='1';invokePrivate($m,'ObserveInputFifoDiagnostic',1,101,[true,true,false]);
inputCheck(!inputReport($m)['active'] && inputReport($m)['observed']===0 && inputReport($m)['stop_reason']==='duration complete','Expired capture stops without counting a late observation or a polling timer');
$m->StartInputFifoDiagnostic();$m->buffers['InputFifoDiagnosticObserved']='9999';invokePrivate($m,'ObserveInputFifoDiagnostic',1,101,[true,true,false]);
inputCheck(inputReport($m)['observed']===10000 && !inputReport($m)['active'] && inputReport($m)['stop_reason']==='observation limit','Observation bound terminates diagnostic only');
$m->StartInputFifoDiagnostic();$GLOBALS['semaphore_busy']['Mod1_InputDiagnostic_7403']=true;
$GLOBALS['calls']=[]; inputSend($m,101,true); unset($GLOBALS['semaphore_busy']['Mod1_InputDiagnostic_7403']);
inputCheck(inputReport($m)['incomplete'] && $m->GetValue('Status')===true && count($GLOBALS['calls'])>0,'Diagnostic contention marks incomplete while ordinary legacy alarm processing continues');
$GLOBALS['semaphore_busy']['Mod1_InputDiagnostic_7403']=true;$busy=inputReport($m);unset($GLOBALS['semaphore_busy']['Mod1_InputDiagnostic_7403']);
inputCheck($busy['report_busy'] && !isset($busy['observed']),'Busy report does not fabricate counter values');
$m->attributes['ActiveRevision']='changed';inputCheck(inputReport($m)['configuration_changed'] && inputReport($m)['incomplete'],'Configuration changes invalidate dependency-snapshot interpretation');
$m->StopInputFifoDiagnostic();$m->properties['EnableInputFifo']=true;$blocked=false;
try{$m->StartInputFifoDiagnostic();}catch(RuntimeException $e){$blocked=true;}
inputCheck($blocked,'Applied live-FIFO setting must be disabled for passive capture');
$m->properties['EnableInputFifo']=false;$m->attributes['FifoOwned']=true;$blocked=false;
try{$m->StartInputFifoDiagnostic();}catch(RuntimeException $e){$blocked=true;}
inputCheck($blocked,'Active FIFO ownership rejects passive Start');
$m->buffers=[];inputCheck(inputReport($m)===null,'Volatile capture disappears on interface-buffer recreation without inventing a clean result');
$m=inputFixture(7404);$m->StartInputFifoDiagnostic();$revision=$m->attributes['ActiveRevision'];
$m->pending['EnableInputFifo']=true;IPS_ApplyChanges(7404);
$m->pending['EnableInputFifo']=false;IPS_ApplyChanges(7404);$r=inputReport($m);
inputCheck(!$m->attributes['FifoOwned'] && $m->attributes['ActiveRevision']===$revision && $r['incomplete'] && !$r['active'],'Apply interruption remains latched after setting and revision return to their originals');
invokePrivate($m,'ObserveInputFifoDiagnostic',1,101,[true,true,false]);
inputCheck(inputReport($m)['observed']===0,'Interrupted capture ignores subsequent observations');
$m->StartInputFifoDiagnostic();inputCheck(inputReport($m)['active'] && !inputReport($m)['incomplete'],'New capture ignores prior session interruption token');
$m=inputFixture(7405);
$GLOBALS['on_set_buffer']=static function($object,$name,$value):void {
    if($object->InstanceID===7405 && $name==='InputFifoDiagnosticMeta') {
        unset($GLOBALS['on_set_buffer']);IPS_ApplyChanges(7405);
    }
};
$blocked=false;try{$m->StartInputFifoDiagnostic();}catch(RuntimeException $e){$blocked=true;}
unset($GLOBALS['on_set_buffer']);
inputCheck($blocked && !inputReport($m)['active'] && inputReport($m)['incomplete'] && $m->attributes['FifoLegacyOverlap'],'Apply during Start metadata publication rejects capture without clearing historical guard');
$m->attributes['FifoCutover']=true;$blocked=false;try{$m->StartInputFifoDiagnostic();}catch(RuntimeException $e){$blocked=true;}
inputCheck($blocked,'Start refuses in-progress Apply cutover');
echo "Passive FIFO input diagnostic: $checks checks passed. No quiescence or production CPU/RAM claim.\n";
