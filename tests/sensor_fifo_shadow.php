<?php
declare(strict_types=1);
require __DIR__ . '/symcon_stub.php';
require __DIR__ . '/../SensorGroup/module.php';

$checks = 0;
function shadowCheck(bool $condition, string $why): void {
    global $checks; ++$checks;
    if (!$condition) throw new RuntimeException('Shadow: ' . $why);
}
function shadowFixtureConfig(): array {
    $c = array_fill_keys(AlarmSafety::LISTS, []);
    $c['BedroomTarget'] = 0; $c['MaintenanceMode'] = false;
    foreach ([['door',101,0,0],['count',102,2,0],['change',103,0,1],['once',104,0,2]] as [$cid,$id,$logic,$trigger]) {
        $c['ClassList'][] = ['ClassID'=>$cid,'ClassName'=>$cid,'LogicMode'=>$logic,'TimeWindow'=>10,'Threshold'=>2];
        $c['SensorList'][] = ['ClassID'=>$cid,'VariableID'=>$id,'Operator'=>0,'ComparisonValue'=>'1','TriggerMode'=>$trigger,'PulseSeconds'=>100];
        $c['GroupList'][] = ['GroupName'=>$cid,'GroupLogic'=>0];
        $c['GroupMembers'][] = ['GroupName'=>$cid,'ClassID'=>$cid];
    }
    $c['SensorList'][1]['ComparisonSource'] = 1; $c['SensorList'][1]['ComparisonVariableID'] = 105;
    $c['BedroomList'] = [['ActiveVariableID'=>106]];
    $c['DispatchTargets'] = [['InstanceID'=>7000]];
    foreach ($c['GroupList'] as $g) $c['GroupDispatch'][] = ['GroupName'=>$g['GroupName'],'InstanceID'=>7000];
    return $c;
}
function shadowValues(): array { return [101=>false,102=>false,103=>0,104=>false,105=>true,106=>0]; }
function pureShadow(array $config = [], array $extra = []): SensorFifoShadow {
    return new SensorFifoShadow($config ?: shadowFixtureConfig(), $extra + ['values'=>shadowValues()]);
}
function shadowInput(SensorFifoShadow $e, int $id, $value, int $wall): array {
    $previous = $e->exportState()['values'][$id];
    return $e->process(['kind'=>'input','variable_id'=>$id,'value'=>$value,'previous'=>$previous,'wall_s'=>$wall]);
}
function hasShadow(array $result, string $key, $value): bool { return in_array($value,$result['projection'][$key] ?? [],true); }
$e = pureShadow();
$r = shadowInput($e,101,true,100);
shadowCheck(hasShadow($r,'classes','door') && hasShadow($r,'groups','door'), 'Captured open evaluated');
$r = shadowInput($e,101,false,100);
shadowCheck(!hasShadow($r,'classes','door'), 'Captured close retained in same second');
$r = shadowInput($e,101,false,100);
shadowCheck(!$r['evaluated'], 'Exact unchanged suppressed');
shadowInput($e,101,true,100);
shadowCheck($e->exportState()['values'][101] === true, 'A→B→A retained');
shadowInput($e,102,true,100);
shadowInput($e,105,false,101); shadowInput($e,105,true,102);
shadowCheck(count($e->exportState()['classes']['count']['Buffer'])===1, 'Reference changes do not add COUNT');
shadowInput($e,102,false,102); $r = shadowInput($e,102,true,103);
shadowCheck(hasShadow($r,'classes','count'), 'Two matching direct events reach COUNT');
$r = $e->process(['kind'=>'state_sync','wall_s'=>110]);
shadowCheck(hasShadow($r,'classes','count'), 'Inclusive COUNT window');
$r = $e->process(['kind'=>'evaluation','wall_s'=>111]);
shadowCheck(!hasShadow($r,'classes','count'), 'Expired COUNT history pruned on evaluation');

$e = pureShadow();
$r = shadowInput($e,104,true,100);
shadowCheck(!hasShadow($r,'classes','once'), 'ONCE initializes without manufactured pulse');
shadowInput($e,104,false,101); $r=shadowInput($e,104,true,102);
shadowCheck(hasShadow($r,'classes','once'), 'ONCE rising edge pulses');
$before=$e->exportState();
$e->process(['kind'=>'state_sync','wall_s'=>103]); $after=$e->exportState();
shadowCheck($before['last']===$after['last'] && $before['conditions']===$after['conditions'] && $before['pulses']===$after['pulses'], 'Sync leaves pulse caches unchanged');
$r=shadowInput($e,104,false,103);
shadowCheck(!hasShadow($r,'classes','once') && !isset($e->exportState()['pulses'][104]), 'ONCE false immediately clears pulse');
$r=shadowInput($e,104,true,104);
shadowCheck(hasShadow($r,'classes','once'), 'ONCE rearms');

$e=pureShadow();
shadowInput($e,103,1,100); $r=shadowInput($e,103,2,101);
shadowCheck(hasShadow($r,'classes','change'), 'CHANGE typed transition pulses');
$r=$e->process(['kind'=>'evaluation','wall_s'=>201]);
shadowCheck(!hasShadow($r,'classes','change'), 'Pulse expires exactly at deadline');
$state=['values'=>array_replace(shadowValues(),[103=>0.0]),'last'=>[103=>['type'=>'float','value'=>0.0]]];
$e=pureShadow([],$state);
$r=shadowInput($e,103,0.0000001,100);
shadowCheck(!hasShadow($r,'classes','change'), 'CHANGE float tolerance retained');
$r=shadowInput($e,103,0.0000013,101);
shadowCheck(hasShadow($r,'classes','change'), 'CHANGE above tolerance pulses');
$e=pureShadow([],['values'=>array_replace(shadowValues(),[103=>1.0]),'ingress'=>[103=>['type'=>'float','value'=>1]]]);
$r=shadowInput($e,103,1.0,100);
shadowCheck(!$r['evaluated'], 'Legacy float JSON cache type preserved');

$c=shadowFixtureConfig();
$c['ClassList'][]=['ClassID'=>'empty','ClassName'=>'empty','LogicMode'=>1];
$c['ClassList'][0]['Active']=false;
$c['GroupList'][]=['GroupName'=>'empty','GroupLogic'=>1];
$e=pureShadow($c); $r=shadowInput($e,101,true,100);
shadowCheck(!hasShadow($r,'classes','door') && !hasShadow($r,'classes','empty') && !hasShadow($r,'groups','empty'), 'Disabled class and empty AND false');
$e=pureShadow([],['values'=>array_replace(shadowValues(),[102=>true,105=>'1'])]);
$r=$e->process(['kind'=>'evaluation','wall_s'=>100]);
shadowCheck(!hasShadow($r,'sensors',102), 'Dynamic incompatible types fail comparison');
$e=pureShadow();
try { $e->process(['kind'=>'input','variable_id'=>101,'previous'=>true,'value'=>false,'wall_s'=>100]); throw new LogicException('Gap accepted'); }
catch (RuntimeException $ex) { shadowCheck(str_contains($ex->getMessage(),'gap'), 'Prior-value gap explicit'); }

// Regression for legacy null COUNT details: active key, but no active group.
$e=pureShadow([],['classes'=>['count'=>['Buffer'=>[99,100]]]]);
$r=$e->process(['kind'=>'evaluation','wall_s'=>100]);
shadowCheck(hasShadow($r,'classes','count') && !hasShadow($r,'groups','count'), 'Legacy COUNT/null-detail group behavior preserved');

new IPSModule(7000);
function liveShadow(int $id, bool $enable = true): SensorGroup {
    $m=new SensorGroup($id); $m->Create();
    $c=shadowFixtureConfig();
    $m->attributes['ActiveConfiguration']=json_encode($c);
    $m->attributes['ActiveRevision']=hash('sha256',json_encode($c));
    foreach(shadowValues() as $vid=>$value) $GLOBALS['variables'][$vid]=$value;
    foreach(shadowValues() as $vid=>$value) $m->MessageSink(1,$vid,VM_UPDATE,[$value,false,$value]);
    $m->properties['EnableFifoShadow']=$enable;
    return $m;
}
function sendShadow(SensorGroup $m,int $id,$value,int $counter,bool $drain=true): void {
    $old=$GLOBALS['variables'][$id]; $GLOBALS['variables'][$id]=$value;
    $m->MessageSink($counter,$id,VM_UPDATE,[$value,$value!==$old,$old]);
    if($drain) $m->RunFifoShadow();
}
function liveReport(SensorGroup $m): array { return json_decode($m->GetFifoShadowReport(),true,512,JSON_THROW_ON_ERROR); }
$m=liveShadow(7100,false);
shadowCheck($m->GetTimerInterval('FifoShadowWorker')===0 && $m->GetTimerInterval('FifoShadowPublish')===0, 'Shadow disabled by default');
try { $m->StartFifoShadow(); throw new LogicException('Disabled Start accepted'); }
catch(RuntimeException $ex) { shadowCheck(!$m->attributes['FifoShadowActive'],'Disabled Start rejected'); }
$m->properties['EnableFifoShadow']=true; $m->StartFifoShadow();
shadowCheck($m->GetTimerInterval('FifoShadowWorker')===0 && $m->GetTimerInterval('FifoShadowPublish')===5000,'Idle worker stopped, bounded reporting enabled');
$outputCount=count($GLOBALS['calls']); sendShadow($m,101,true,10,false);
shadowCheck(count($GLOBALS['calls'])>$outputCount,'Existing dispatch still executes');
$outputCount=count($GLOBALS['calls']); $m->RunFifoShadow();
shadowCheck(count($GLOBALS['calls'])===$outputCount,'Shadow never adds output calls');
sendShadow($m,101,false,11); sendShadow($m,102,true,12); sendShadow($m,105,false,13);
sendShadow($m,105,true,14); sendShadow($m,102,false,15); sendShadow($m,102,true,16);
sendShadow($m,103,123456,17); sendShadow($m,103,0,18); // Ordinary integer token/reset path.
sendShadow($m,104,true,19); sendShadow($m,104,false,20);
sendShadow($m,106,2,21);
$r=liveReport($m);
shadowCheck($r['fault']==='' && $r['metrics']['mismatches']===0,'Live/shadow class/group/pulse/COUNT projections agree');
shadowCheck($r['metrics']['processed']===12,'Every admitted transition processed');
$count=$r['metrics']['admitted'];
for($i=22;$i<220;++$i) sendShadow($m,101,false,$i,false);
$r=liveReport($m);
shadowCheck($r['metrics']['admitted']===$count && $r['metrics']['count']===0 && $r['metrics']['ingress_suppressed']===198,'Unchanged burst suppressed at admission');
shadowCheck($m->GetTimerInterval('FifoShadowWorker')===0,'Suppressed refreshes do not wake worker');
$m->StopFifoShadow();
shadowCheck(!$m->attributes['FifoShadowActive'] && $m->GetTimerInterval('FifoShadowPublish')===0,'Manual Stop stops timers');

$m=liveShadow(7101); $m->StartFifoShadow();
$t1=invokePrivate($m,'ShadowAdmit',['kind'=>'input','variable_id'=>101,'value'=>true,'previous'=>false,'native_counter'=>30]);
$t2=invokePrivate($m,'ShadowAdmit',['kind'=>'input','variable_id'=>101,'value'=>false,'previous'=>true,'native_counter'=>31]);
$engine=new SensorFifoShadow(json_decode($m->buffers['FifoShadowConfig'],true),json_decode($m->buffers['FifoShadowState'],true));
$r1=$engine->process(['kind'=>'input','variable_id'=>101,'value'=>true,'previous'=>false,'wall_s'=>time()]);
$r2=$engine->process(['kind'=>'input','variable_id'=>101,'value'=>false,'previous'=>true,'wall_s'=>time()]);
invokePrivate($m,'FifoShadowComplete',$t2,$r2['projection']); $m->RunFifoShadow();
shadowCheck(liveReport($m)['metrics']['processed']===0 && $m->GetTimerInterval('FifoShadowWorker')===0,'Unready prefix is not overtaken');
invokePrivate($m,'FifoShadowComplete',$t1,$r1['projection']);
shadowCheck($m->GetTimerInterval('FifoShadowWorker')===50,'Completing head guarantees wake');
$m->RunFifoShadow();
shadowCheck(liveReport($m)['metrics']['processed']===2 && liveReport($m)['metrics']['mismatches']===0,'Ready prefix drains once in order');
shadowCheck($m->buffers['FifoShadowSlot0']==='' && $m->buffers['FifoShadowSlot1']==='','Processed slots released');
$oldMeta=liveReport($m)['metrics']; $m->StopFifoShadow(); $m->StartFifoShadow();
$old=invokePrivate($m,'ShadowAdmit',['kind'=>'input','variable_id'=>101,'value'=>true,'previous'=>false,'native_counter'=>32],$oldMeta['start_ns']);
shadowCheck($old===null && liveReport($m)['metrics']['admitted']===0,'Old callback cannot enter newer session');
invokePrivate($m,'FifoShadowComplete',$t1,$r1['projection']);
shadowCheck(liveReport($m)['fault']==='' && liveReport($m)['metrics']['admitted']===0,'Late old completion cannot alter new session');

invokePrivate($m,'ShadowFault','old validation failure',$oldMeta['start_ns']);
invokePrivate($m,'ShadowFault','old completion failure',null,$oldMeta['session']);
shadowCheck(liveReport($m)['fault']==='', 'Old fault cannot invalidate a new session');
invokePrivate($m,'StopShadowSession',$oldMeta['session']);
shadowCheck($m->attributes['FifoShadowActive'], 'Old expiry cannot stop a new session');

$GLOBALS['unavailable_buffer_interface']=7101;
invokePrivate($m,'FifoShadowApplyBegin');
$m->attributes['FifoShadowApplying']=false;
$m->RunFifoShadow();
unset($GLOBALS['unavailable_buffer_interface']);
shadowCheck(!$m->attributes['FifoShadowActive'] && $m->GetTimerInterval('FifoShadowWorker')===0,'Lifecycle and inactive worker avoid unavailable buffers');
shadowCheck(liveReport($m)['lifecycle_interruption'],'Lifecycle interruption remains visible');

$m=liveShadow(7102); $m->StartFifoShadow();
for($i=0;$i<128;++$i) sendShadow($m,101,($i%2)===0,1000+$i,false);
shadowCheck(liveReport($m)['metrics']['count']===128,'Queue capacity retained');
$outputCount=count($GLOBALS['calls']); sendShadow($m,101,true,1128,false);
$r=liveReport($m);
shadowCheck($r['metrics']['count']===128 && str_contains($r['fault'],'capacity'),'Overflow explicit; retained prefix not silently removed');
shadowCheck(count($GLOBALS['calls'])>$outputCount,'Production dispatch continues during shadow overflow');
$m->PublishFifoShadow();
shadowCheck(!$m->attributes['FifoShadowActive'] && $m->GetTimerInterval('FifoShadowWorker')===0,'Fault stops shadow diagnostics');
shadowCheck(liveReport($m)['comparison_incomplete'], 'Stopped pending comparisons explicitly incomplete');

$m=liveShadow(7103); $m->StartFifoShadow();
$GLOBALS['semaphore_busy']['M1FifoShadowQueue_7103']=true;
sendShadow($m,101,true,2000,false);
unset($GLOBALS['semaphore_busy']['M1FifoShadowQueue_7103']);
shadowCheck(str_contains(liveReport($m)['fault'],'contention'),'Mutex omission explicit');
$m->PublishFifoShadow();
$m=liveShadow(7104); $m->StartFifoShadow();
$meta=json_decode($m->buffers['FifoShadowMeta'],true); $meta['deadline_ns']=0;
$m->buffers['FifoShadowMeta']=json_encode($meta); $m->PublishFifoShadow();
shadowCheck(!$m->attributes['FifoShadowActive'] && $m->GetTimerInterval('FifoShadowPublish')===0,'Automatic duration stop');

$m=liveShadow(7105);
$GLOBALS['semaphore_busy']['M1FifoShadowFault_7105']=true;
try { $m->StartFifoShadow(); throw new LogicException('Start crossed fault publication'); }
catch(RuntimeException $ex) { shadowCheck(!$m->attributes['FifoShadowActive'] && str_contains($ex->getMessage(),'fault publication'), 'Start cannot replace session during fault publication'); }
unset($GLOBALS['semaphore_busy']['M1FifoShadowFault_7105']);
$m->StartFifoShadow(); invokePrivate($m,'ShadowFault','current incident');
shadowCheck(liveReport($m)['fault']==='current incident', 'Current fault publishes across serialized boundary');
$m->StopFifoShadow();

$m=liveShadow(7106); $m->StartFifoShadow();
$outputCount=count($GLOBALS['calls']);
$GLOBALS['variables'][101]=true;
$m->MessageSink(3000,101,VM_UPDATE,[true,true,true]); // Deliberately inconsistent native previous value.
$r=liveReport($m); $detail=$r['fault_details']['details'];
shadowCheck($detail['variable_id']===101 && $detail['native_counter']===3000, 'First gap identifies source and counter');
shadowCheck($detail['baseline']===['type'=>'boolean','value'=>false] && $detail['native_previous']===['type'=>'boolean','value'=>true] && $detail['native_value']===['type'=>'boolean','value'=>true], 'Gap retains typed conflicting values');
shadowCheck(isset($r['fault_details']['recorded_at'],$r['fault_details']['recorded_ns'],$r['fault_details']['callback_ns']), 'Fault timing retained separately from stop timing');
shadowCheck(count($GLOBALS['calls'])>$outputCount && $r['metrics']['admitted']===0, 'Gap diagnostic adds no record and leaves live dispatch running');
invokePrivate($m,'ShadowFault','later fault');
shadowCheck(liveReport($m)['fault_details']===$r['fault_details'], 'First fault evidence not overwritten');
$m->PublishFifoShadow();
shadowCheck(isset(liveReport($m)['metrics']['stop_lateness_ms']), 'Stop reports lateness independently of fault time');

$m=liveShadow(7107);
$GLOBALS['variables'][101]='baseline';
$m->MessageSink(3100,101,VM_UPDATE,['baseline',true,false]);
$m->StartFifoShadow();
$unicodePrevious=str_repeat('a',95).'é';
$GLOBALS['variables'][101]='1';
$outputCount=count($GLOBALS['calls']);
$m->MessageSink(3101,101,VM_UPDATE,['1',true,$unicodePrevious]);
$r=liveReport($m);
shadowCheck($r['fault']!=='' && $r['fault_details']['details']['native_previous']===['type'=>'string','value'=>str_repeat('a',95)], 'Unicode clipping cannot lose a native string gap fault');
shadowCheck(count($GLOBALS['calls'])>$outputCount && $r['metrics']['admitted']===0, 'Unicode gap retains live dispatch with no shadow admission');
$m->PublishFifoShadow();
shadowCheck(!$m->attributes['FifoShadowActive'], 'Unicode gap stops diagnostics rather than continuing a broken baseline');

// Native Apply removes unowned variables: model real children and deletion.
$m=liveShadow(7110,false);
$c=shadowFixtureConfig(); $c['BedroomList']=[];
foreach(AlarmSafety::LISTS as $list) $m->pending[$list]=json_encode($c[$list]);
$m->RegisterVariableString('ObsoleteTest','obsolete');
$GLOBALS['model_variable_cleanup']=true;
foreach($m->idents as $ident=>$id) $GLOBALS['model_idents'][$id]=$ident;
$GLOBALS['model_children'][7110]=array_values($m->idents);
IPS_ApplyChanges(7110);
shadowCheck(!isset($m->idents['ObsoleteTest']), 'Native cleanup model actually removes obsolete variables');
shadowCheck(isset($m->idents['FifoShadowHealth'],$m->idents['FifoShadowReport']), 'Shadow variables survive native Apply cleanup');
IPS_ApplyChanges(7110);
shadowCheck(IPS_VariableExists($m->idents['FifoShadowHealth']) && IPS_VariableExists($m->idents['FifoShadowReport']), 'Repeated Apply preserves diagnostic variables');
$m->properties['EnableFifoShadow']=true; $m->StartFifoShadow(); $m->PublishFifoShadow();
shadowCheck(str_contains($m->GetValue('FifoShadowHealth'),'Running shadow'), 'Shadow starts and publishes after actual Apply');
$m->StopFifoShadow();
unset($GLOBALS['model_variable_cleanup'],$GLOBALS['model_idents'],$GLOBALS['model_children']);

echo "FIFO shadow: $checks checks passed. Native shadow/load validation remains required.\n";
