<?php
declare(strict_types=1);
require __DIR__ . '/symcon_stub.php';
require __DIR__ . '/../SensorGroup/module.php';
$checks = 0;
function ruleCheck(bool $ok, string $why): void { global $checks; ++$checks; if (!$ok) throw new RuntimeException($why); }
function ruleFixture(bool $fifo, array $rules, string $initial = 'running'): SensorGroup {
    static $next = 7800; $m = new SensorGroup(++$next); $m->Create();
    $c = array_fill_keys(AlarmSafety::LISTS, []);
    foreach ($rules as $r) {
        $cid = $r['ClassID'];
        $c['ClassList'][] = ['ClassID'=>$cid, 'ClassName'=>$cid, 'LogicMode'=>0, 'Threshold'=>1, 'TimeWindow'=>10];
        $c['SensorList'][] = $r + ['VariableID'=>28097,'Operator'=>0,'ComparisonSource'=>0,'ComparisonVariableID'=>0,'TriggerMode'=>2,'PulseSeconds'=>1,'Active'=>true];
        $c['GroupList'][] = ['GroupID'=>'g-'.$cid,'GroupName'=>$cid,'GroupLogic'=>0];
        $c['GroupMembers'][] = ['ClassID'=>$cid,'GroupName'=>$cid];
    }
    $GLOBALS['variables'][28097] = $initial;
    $GLOBALS['variables'][101] = false;
    $c['ClassList'][] = ['ClassID'=>'other','ClassName'=>'other','LogicMode'=>0,'Threshold'=>1,'TimeWindow'=>10];
    $c['SensorList'][] = ['ClassID'=>'other','VariableID'=>101,'Operator'=>0,'ComparisonValue'=>'1','TriggerMode'=>0];
    foreach ($c as $k=>$v) $m->pending[$k] = json_encode($v);
    $m->pending['EnableInputFifo'] = $fifo;
    IPS_ApplyChanges($m->InstanceID);
    return $m;
}
function cameraRuleFixture(bool $fifo, int $count = 13): SensorGroup {
    static $next = 7900; $m = new SensorGroup(++$next); $m->Create();
    $c = array_fill_keys(AlarmSafety::LISTS, []);
    foreach ([['failed', 'failed', 10], ['done', 'done', 1]] as [$cid, $value, $seconds]) {
        $group = 'camera_' . $cid;
        $c['ClassList'][] = ['ClassID'=>$cid,'ClassName'=>$cid,'LogicMode'=>0,'Threshold'=>1,'TimeWindow'=>10];
        $c['GroupList'][] = ['GroupID'=>'g-'.$cid,'GroupName'=>$group,'GroupLogic'=>0];
        $c['GroupMembers'][] = ['ClassID'=>$cid,'GroupName'=>$group];
        for ($i=0; $i<$count; ++$i) {
            $id = 28100 + $i;
            $c['SensorList'][] = ['ClassID'=>$cid,'VariableID'=>$id,'Operator'=>0,
                'ComparisonSource'=>0,'ComparisonValue'=>$value,'ComparisonVariableID'=>0,
                'TriggerMode'=>2,'PulseSeconds'=>$seconds,'Active'=>true];
            $GLOBALS['variables'][$id] = 'running';
        }
    }
    $c['BedroomTarget']=0; $c['MaintenanceMode']=false; $c['TargetThrottleList']=[];
    foreach ($c as $k=>$v) $m->pending[$k] = is_array($v) ? json_encode($v) : $v;
    $m->pending['EnableInputFifo'] = $fifo;
    IPS_ApplyChanges($m->InstanceID);
    return $m;
}
function ruleDrain(SensorGroup $m): void { for ($i=0;$i<40 && $m->attributes['FifoHasPending'];++$i) $m->RunInputFifo(); }
function ruleSend(SensorGroup $m, $value, int $id = 28097): void {
    static $counter=0;
    $prev=$GLOBALS['variables'][$id]; $GLOBALS['variables'][$id]=$value;
    $m->MessageSink(++$counter,$id,VM_UPDATE,[$value,$prev!==$value,$prev]); ruleDrain($m);
}
function activeRule(SensorGroup $m, string $cid): bool { return in_array($cid,json_decode($m->attributes['ActiveClassesBuffer'],true),true); }
function expireRules(SensorGroup $m): void {
    // Expire deadlines, then exercise the real timer admission/evaluation path without wall-clock sleeps.
    $p=json_decode($m->attributes['SensorPulseUntilMap'],true);
    foreach ($p as &$until) $until=time()-1;
    $m->attributes['SensorPulseUntilMap']=json_encode($p);
    $m->CheckPulseExpiry(); ruleDrain($m);
}
$rules=[['ClassID'=>'failed','ComparisonValue'=>'failed','PulseSeconds'=>10],['ClassID'=>'done','ComparisonValue'=>'done']];
foreach ([false,true] as $fifo) {
    foreach ([$rules,array_reverse($rules)] as $ordered) {
        $m=ruleFixture($fifo,$ordered);
        ruleSend($m,'done');
        ruleCheck(activeRule($m,'done') && !activeRule($m,'failed'),'Only done rule activates, independent of class order and FIFO');
        $before=$m->attributes['SensorPulseUntilMap'];
        for ($i=0;$i<3;++$i) { ruleSend($m,$i%2===0,101); ruleSend($m,'done'); }
        ruleCheck($m->attributes['SensorPulseUntilMap']===$before,'Unrelated inputs and unchanged done do not renew pulse');
        $m->RequestStateSync(); ruleDrain($m);
        ruleCheck($m->attributes['SensorPulseUntilMap']===$before,'State sync does not renew pulse');
        expireRules($m);
        ruleCheck(!activeRule($m,'done') && !activeRule($m,'failed'),'Expiry clears both class states while input remains done');
        ruleCheck(!$m->GetValue('Status_done') && !$m->GetValue('Status_failed'),'OR groups clear after pulse');
        ruleCheck(!in_array(28097,json_decode($m->attributes['ActiveSensorsBuffer'],true),true),'Sensor aggregate clears after pulse');
        ruleCheck(json_decode($m->attributes['SensorPulseUntilMap'],true)===[],'Expiry does not manufacture another pulse');
        ruleCheck($m->GetTimerInterval('PulseExpireTimer')===0,'Pulse timer stops when no pulses remain');
        ruleSend($m,'waiting'); ruleSend($m,'running'); ruleSend($m,'done');
        ruleCheck(activeRule($m,'done'),'Next genuine done edge activates again');
        expireRules($m); ruleSend($m,'failed');
        ruleCheck(activeRule($m,'failed') && !activeRule($m,'done'),'Failed pulse is independent of done');
        $p=json_decode($m->attributes['SensorPulseUntilMap'],true);
        ruleCheck(count($p)===1 && reset($p)>=time()+9,'Failed keeps its own ten-second duration');
        expireRules($m);
        ruleCheck(!activeRule($m,'failed'),'Failed expires without retriggering');
        // Preserve per-rule pulses through configuration reordering.
        ruleSend($m,'running'); ruleSend($m,'done'); $p=$m->attributes['SensorPulseUntilMap'];
        $classes=json_decode($m->pending['ClassList'],true); $m->pending['ClassList']=json_encode(array_reverse($classes));
        IPS_ApplyChanges($m->InstanceID); ruleDrain($m);
        ruleCheck($m->attributes['SensorPulseUntilMap']===$p,'Reordering configuration preserves identified pulse');
        if ($fifo) {
            $m->RecoverInputFifo();
            ruleCheck($m->attributes['SensorPulseUntilMap']===$p,'Recovery preserves unexpired independent pulse');
            expireRules($m); $m->RecoverInputFifo();
            ruleCheck(!activeRule($m,'done'),'Recovery at stable done does not create pulse');
        }
    }
    // CHANGE and ONCE on the same variable no longer consume one another's state.
    $m=ruleFixture($fifo,[['ClassID'=>'change','ComparisonValue'=>'ignored','TriggerMode'=>1,'PulseSeconds'=>10],$rules[1]]);
    ruleSend($m,'done');
    ruleCheck(activeRule($m,'change') && activeRule($m,'done'),'Both CHANGE and ONCE observe same edge');
    $p=json_decode($m->attributes['SensorPulseUntilMap'],true); $keys=array_keys($p); sort($p);
    ruleCheck(count($p)===2 && $p[1]-$p[0]===9,'Independent pulse durations for shared variable');
    foreach ($keys as $key) if (json_decode($m->attributes['SensorPulseUntilMap'],true)[$key]===min($p)) {
        $map=json_decode($m->attributes['SensorPulseUntilMap'],true); $map[$key]=time()-1; $m->attributes['SensorPulseUntilMap']=json_encode($map);
    }
    $m->CheckPulseExpiry(); ruleDrain($m);
    ruleCheck(activeRule($m,'change') && !activeRule($m,'done'),'Short pulse expiry does not clear longer CHANGE pulse');
    // Adding a second predicate cannot change the stable identity of the first rule.
    $m=ruleFixture($fifo,[$rules[1]]); ruleSend($m,'done');
    $config=json_decode($m->pending['SensorList'],true);
    $extra=$config[0]; $extra['ComparisonValue']='failed'; $config[]=$extra;
    $m->pending['SensorList']=json_encode($config); IPS_ApplyChanges($m->InstanceID); ruleDrain($m);
    ruleCheck(activeRule($m,'done') && count(json_decode($m->attributes['SensorPulseUntilMap'],true))===1,
        'Adding another predicate preserves the identified stateful pulse');
    ruleSend($m,'running'); ruleSend($m,'done'); ruleCheck(activeRule($m,'done'),'Migrated rules rearm normally');
    // Deleting the extra rule must prune only its state and preserve the survivor.
    array_pop($config); $m->pending['SensorList']=json_encode($config); IPS_ApplyChanges($m->InstanceID); ruleDrain($m);
    ruleCheck(activeRule($m,'done'),'Deleting another predicate preserves the surviving stateful pulse');
    ruleCheck(count(json_decode($m->attributes['SensorRuleManifest'],true))===2,'Deleted rule entries are pruned');
    // Predicate edit of a single rule while new predicate is already true.
    $config[0]['ComparisonValue']='running'; $m->pending['SensorList']=json_encode($config);
    $GLOBALS['variables'][28097]='running'; IPS_ApplyChanges($m->InstanceID); ruleDrain($m);
    ruleCheck(!activeRule($m,'done'),'Predicate edit initializes current condition without an artificial pulse');
}
// Simulated library upgrade from old variable-only state: no copying to either predicate.
foreach ([false,true] as $fifo) {
    $m=ruleFixture($fifo,$rules,'done');
    $m->attributes['SensorRuleManifest']='{}';
    $m->attributes['SensorConditionStateMap']=json_encode([28097=>true]);
    $m->attributes['SensorPulseUntilMap']=json_encode([28097=>time()+10]);
    $rp=new ReflectionProperty($m,'sensorRuleRevision'); $rp->setValue($m,null);
    if ($fifo) $m->RecoverInputFifo(); else invokePrivate($m,'CheckLogic',0,'apply_changes');
    ruleCheck(!activeRule($m,'done') && !activeRule($m,'failed'),'Upgrade drops ambiguous shared pulse without a synthetic edge');
    ruleCheck(!array_key_exists(28097,json_decode($m->attributes['SensorPulseUntilMap'],true)),'Old ambiguous numeric pulse key removed');
    ruleSend($m,'running'); ruleSend($m,'done'); ruleCheck(activeRule($m,'done'),'Upgrade permits next genuine done edge');
    $m=ruleFixture($fifo,[$rules[1]]); ruleSend($m,'done');
    $m->attributes['SensorRuleManifest']='{}';
    $m->attributes['SensorConditionStateMap']=json_encode([28097=>true]);
    $m->attributes['SensorPulseUntilMap']=json_encode([28097=>time()+10]);
    $rp=new ReflectionProperty($m,'sensorRuleRevision'); $rp->setValue($m,null);
    if ($fifo) $m->RecoverInputFifo(); else invokePrivate($m,'CheckLogic',0,'apply_changes');
    ruleCheck(!activeRule($m,'done') && json_decode($m->attributes['SensorPulseUntilMap'],true)===[],
        'Upgrade replaces legacy numeric stateful identity without manufacturing a pulse');
    ruleSend($m,'running'); ruleSend($m,'done');
    ruleCheck(activeRule($m,'done'),'Migrated single stateful rule rearms on its next genuine edge');
}
// Native lifecycle regression: a current revision can coexist with an empty volatile
// count cache. The production-shaped 13-camera failed/done graph must still expire.
foreach ([false,true] as $fifo) {
    $m=cameraRuleFixture($fifo);
    for ($id=28100; $id<28113; ++$id) ruleSend($m,'done',$id);
    ruleCheck(activeRule($m,'done') && !activeRule($m,'failed'),'Production camera graph activates only ready pulses');
    $countsProperty=new ReflectionProperty($m,'sensorRuleCounts');
    $countsProperty->setValue($m,[]);
    $revisionProperty=new ReflectionProperty($m,'sensorRuleRevision');
    $revisionProperty->setValue($m,$m->attributes['ActiveRevision']);
    expireRules($m);
    ruleCheck(!activeRule($m,'done') && !activeRule($m,'failed'),
        'Camera pulses expire even when native lifecycle loses volatile rule counts');
    ruleCheck(!$m->GetValue('Status_cameradone') && !$m->GetValue('Status_camerafailed'),
        'Production camera groups clear while all source strings remain done');
    ruleCheck(json_decode($m->attributes['SensorPulseUntilMap'],true)===[],
        'Native lifecycle expiry cannot recreate any of the 13 camera pulses');
    for ($id=28100; $id<28113; ++$id) ruleCheck($GLOBALS['variables'][$id]==='done','Expiry does not alter camera source strings');
    ruleSend($m,'running',28100); ruleSend($m,'done',28100);
    ruleCheck(activeRule($m,'done'),'Camera rule rearms after a genuine later running-to-done edge');
}
// Real one-second timer deadline under the legacy path, then explicit native-style expiry callback.
$m=ruleFixture(false,$rules); ruleSend($m,'done'); usleep(1100000); $m->CheckPulseExpiry();
ruleCheck(!activeRule($m,'done') && $GLOBALS['variables'][28097]==='done','One-second pulse ends while string stays done');
// Mermaid must not reuse S_VariableID for different predicates or paint the failed rule red.
$m=ruleFixture(true,$rules); ruleSend($m,'done');
$_GET=['api'=>'1','depth'=>'sensors']; $_SERVER['REQUEST_URI']='/hook/test';
ob_start(); invokePrivate($m,'ProcessHookData'); $graph=ob_get_clean(); $_GET=[];
$counts=SensorRuleIdentity::counts(invokePrivate($m,'ActiveConfig'));
foreach (json_decode($m->pending['SensorList'],true) as $row) {
    if ($row['VariableID']!==28097) continue;
    $key=SensorRuleIdentity::key($row,$counts); $color=$row['ComparisonValue']==='done'?'red':'green';
    ruleCheck((bool)preg_match('/S_'.preg_quote($key,'/').'\\[.*?\\]:::'.$color.' -->/',$graph),'Mermaid shows each predicate with its own activity');
}
// Mermaid class and group dropdowns support independent multiple selections.
$keysByValue=[];
foreach (json_decode($m->pending['SensorList'],true) as $row) {
    if ($row['VariableID']===28097) $keysByValue[$row['ComparisonValue']]=SensorRuleIdentity::key($row,$counts);
}
$_GET=['api'=>'1','depth'=>'sensors','groupFilter'=>json_encode(['g-failed','g-done']),'classFilter'=>json_encode(['done'])];
ob_start(); invokePrivate($m,'ProcessHookData'); $classGraph=ob_get_clean();
ruleCheck(str_contains($classGraph,'S_'.$keysByValue['done'].'[') && !str_contains($classGraph,'S_'.$keysByValue['failed'].'['),
    'Class filter intersects a multiple group selection');
$_GET=['api'=>'1','depth'=>'sensors','groupFilter'=>json_encode(['g-failed','g-done']),'classFilter'=>json_encode(['failed','done'])];
ob_start(); invokePrivate($m,'ProcessHookData'); $multiClassGraph=ob_get_clean();
ruleCheck(str_contains($multiClassGraph,'S_'.$keysByValue['done'].'[') && str_contains($multiClassGraph,'S_'.$keysByValue['failed'].'['),
    'Class dropdown accepts multiple selected classes');
$_GET=['api'=>'1','depth'=>'sensors','groupFilter'=>json_encode(['g-done']),'classFilter'=>json_encode(['failed','done'])];
ob_start(); invokePrivate($m,'ProcessHookData'); $singleGroupGraph=ob_get_clean();
ruleCheck(str_contains($singleGroupGraph,'S_'.$keysByValue['done'].'[') && !str_contains($singleGroupGraph,'S_'.$keysByValue['failed'].'['),
    'Group dropdown accepts an independent selected subset');
$_GET=['api'=>'1','depth'=>'sensors','classFilter'=>'NONE'];
ob_start(); invokePrivate($m,'ProcessHookData'); $emptyClassGraph=ob_get_clean();
ruleCheck(str_contains($emptyClassGraph,'No Classes Selected'),'Empty class selection returns a valid Mermaid placeholder');
$_GET=[];
ob_start(); invokePrivate($m,'ProcessHookData'); $page=ob_get_clean();
ruleCheck(str_contains($page,'Classes: <span id="class-filter-summary">All</span>') && str_contains($page,'Groups: <span id="group-filter-summary">All</span>'),
    'Mermaid page exposes class and group multi-select dropdowns');
ruleCheck(substr_count($page,'class="class-filter"')===3 && str_contains($page,'&classFilter='),
    'Class dropdown contains every configured class and sends its selection to the API');
// Diagnostic shadow must use the same independent semantics.
$shadow=new SensorFifoShadow(invokePrivate($m,'ShadowConfig'),['values'=>[28097=>'done',101=>false]]+invokePrivate($m,'ShadowLegacyRuntime'));
$out=$shadow->process(['kind'=>'evaluation','wall_s'=>time()+20,'variable_id'=>0]);
ruleCheck(!in_array('done',$out['projection']['classes'],true),'Shadow comparison expires independent rule without false mismatch');
echo "Sensor rule pulses: $checks checks passed. Native installation test remains required.\n";
