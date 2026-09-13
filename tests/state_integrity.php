<?php

declare(strict_types=1);
require __DIR__ . '/symcon_stub.php';
require __DIR__ . '/../SensorGroup/module.php';
require __DIR__ . '/../PropertyStateManager/module.php';

$count = 0;
function check(bool $condition, string $message): void {
    global $count;
    if (!$condition) throw new RuntimeException('FAIL: ' . $message);
    $count++;
}
function mapping(): array {
    return array_map(static function ($row) { return ['SourceKey' => $row[0], 'LogicalRole' => $row[1], 'Polarity' => $row[2]]; }, [
        ['23917','Basement Door Contact','breach'], ['16297','Basement Door Lock','breach'], ['16860','Front Door Contact','breach'],
        ['29023','Front Door Lock','breach'], ['House State Doors','Generic Door','breach'], ['House State Windows','Window Contact','breach'], ['Presence','Presence','secure']
    ]);
}
function syntheticConfig(): array {
    $config = array_fill_keys(AlarmSafety::LISTS, []);
    $config['BedroomTarget'] = 56438; $config['MaintenanceMode'] = false;
    $definitions = [
        ['Entrance', 'Entrance Doors', [23917,16297,16860,29023]], ['Doors', 'House State Doors', [22324,18882]],
        ['Windows', 'House State Windows', [41447,20533]], ['Presence', 'Presence', [17939]],
        ['Artur', 'Master Bedroom Artur', [57544]], ['Penny', 'Master Bedroom Penny', [15312]],
        ['Count', 'Motion inside', [41000]], ['Pulse', 'Pulse', [41001]], ['Once', 'Once', [41002]]
    ];
    foreach ($definitions as [$name,$group,$ids]) {
        $config['ClassList'][] = ['ClassID'=>'cls_'.$name,'ClassName'=>$name,'Active'=>true,'LogicMode'=>$name==='Count'?2:0,'LabelMode'=>0,'Threshold'=>2,'TimeWindow'=>30];
        $config['GroupList'][] = ['GroupID'=>'grp_'.$name,'GroupName'=>$group,'GroupLogic'=>0,'Active'=>true];
        $config['GroupMembers'][] = ['GroupName'=>$group,'ClassID'=>'cls_'.$name];
        foreach ($ids as $id) $config['SensorList'][] = ['VariableID'=>$id,'ClassID'=>'cls_'.$name,'Operator'=>0,'ComparisonValue'=>'1','TriggerMode'=>$name==='Pulse'?1:($name==='Once'?2:0),'PulseSeconds'=>5,'Active'=>true];
        if (!in_array($name,['Artur','Penny'],true)) $config['GroupDispatch'][] = ['GroupName'=>$group,'InstanceID'=>in_array($name,['Count','Pulse','Once'],true)?57380:56438];
    }
    $config['BedroomList'] = [['GroupName'=>'Master Bedroom Artur','BedroomDoorClassID'=>'cls_Artur','ActiveVariableID'=>10822],['GroupName'=>'Master Bedroom Penny','BedroomDoorClassID'=>'cls_Penny','ActiveVariableID'=>10822]];
    $config['DispatchTargets'] = [['InstanceID'=>56438,'Active'=>true],['InstanceID'=>57380,'Active'=>true]];
    return $config;
}
$config = syntheticConfig();
check(AlarmSafety::validate($config) === [], 'synthetic graph valid');
$livePath = getenv('ALARM_LIVE_CONFIG');
if ($livePath) {
    $live = json_decode(file_get_contents($livePath), true, 512, JSON_THROW_ON_ERROR);
    check(AlarmSafety::validate($live) === [], 'uploaded live graph valid');
    check(count($live['SensorList']) === (int)(getenv('ALARM_LIVE_SENSOR_COUNT') ?: 393) && count($live['ClassList']) === 61 && count($live['GroupMembers']) === 69 && count($live['GroupDispatch']) === 57, 'live regression counts');
    $plan = AlarmSafety::compile($live, mapping(), 56438);
    check(!$plan['errors'] && count($plan['sources']) === 7 && count($plan['bedrooms']) === 6, 'live mappings and separate bedroom routes compile');
    $values = array_fill_keys(array_keys($plan['dependencies']), false);
    $result = AlarmSafety::evaluate($plan, static fn($id) => $values[$id] ?? null);
    check(!$result['errors'], 'all live dependencies evaluate when available');
    // Values reported from production: integer bedroom selectors and Boolean door contacts.
    foreach ([10822=>0,51095=>2,37976=>2,17066=>2,41330=>2,24493=>false,31261=>true,10730=>true,54877=>true,21441=>true] as $id=>$value) $values[$id]=$value;
    $result = AlarmSafety::evaluate($plan, static fn($id) => $values[$id] ?? null);
    check(!$result['errors'], 'reported production bedroom values are known');
    $rooms = array_column($result['bedrooms'], null, 'GroupName');
    check($rooms['Master Bedroom Artur']['SwitchState'] === false && $rooms['Big Guest Bedroom']['SwitchState'] === true && $rooms['Big Guest Bedroom']['DoorTripped'] === true, 'production integer selectors retain legacy Boolean meaning without hiding open doors');
    $values[41447] = 1; unset($values[17939]);
    $result = AlarmSafety::evaluate($plan, static fn($id) => $values[$id] ?? null);
    check($result['sources'][5]['value'] === false && $result['errors'], 'live window breach survives missing presence');
}
$m1 = new SensorGroup(23172); $m1->Create();
$m2 = new PropertyStateManager(56438); $m2->Create();
new IPSModule(57380);
foreach ($config['SensorList'] as $row) $GLOBALS['variables'][$row['VariableID']] = false;
$GLOBALS['variables'][10822] = false;
foreach (AlarmSafety::LISTS as $key) IPS_SetProperty(23172, $key, json_encode($config[$key]));
IPS_SetProperty(23172, 'BedroomTarget', 56438);
foreach (['SensorGroupInstanceID'=>23172,'DispatchTargetID'=>56438,'GroupMapping'=>json_encode(mapping()),'BedroomPresencePolarity'=>'unused','ArmingDelayDuration'=>5] as $key=>$value) IPS_SetProperty(56438,$key,$value);
IPS_ApplyChanges(23172); IPS_ApplyChanges(56438);
$active = $m1->GetConfiguration();
check($m2->GetValue('SystemState') === 2 && $m2->GetTimerInterval('DelayTimer') === 300000, 'healthy baseline enters five-minute exit delay');
// Cross-module calls while an interface is recreated must not require its runtime buffers.
$m1->WriteAttributeString('SafetyPlan', ''); // Include rebuilding/registering the consumer plan.
$GLOBALS['unavailable_buffer_interface'] = 23172;
IPS_ApplyChanges(56438);
check($m2->GetValue('MonitoringHealthy') && $m2->attributes['SafetyConfigurationError'] === '', 'PSM Apply validates and reads snapshots without Module 1 buffer interface');
$revision = $m1->ReadAttributeString('ActiveRevision');
foreach (['', 'updating'] as $unreadyRevision) {
    $m1->WriteAttributeString('ActiveRevision', $unreadyRevision);
    $unready = json_decode($m1->GetSafetySnapshot(json_encode(mapping()), 56438, true), true);
    check(!$unready['valid'] && !$unready['sources'], 'uninitialized or updating configuration never supplies secure inputs');
}
$m1->WriteAttributeString('ActiveRevision', $revision);
$GLOBALS['unavailable_buffer_interface'] = 0;
$GLOBALS['hold_post_apply'] = true;
$beforeCalls = count($GLOBALS['calls']);
IPS_ApplyChanges(23172); IPS_ApplyChanges(56438);
check(count($GLOBALS['calls']) === $beforeCalls && $m2->attributes['SafetyApplyPending'], 'Apply defers outbound dispatch and snapshot validation');
check(!$m2->GetValue('MonitoringHealthy') && str_starts_with($m2->GetValue('InputHealth'), 'Initializing:'), 'pending initialization does not retain a healthy indicator');
$GLOBALS['kernel_runlevel'] = 0;
drainPostApplyTimers(); $m2->RefreshSafetyState();
check($m1->GetTimerInterval('PostApplyTimer') === 1000 && $m2->GetTimerInterval('SafetyApplyTimer') === 1000 && count($GLOBALS['calls']) === $beforeCalls, 'startup callbacks wait for kernel readiness without cross-module calls');
$GLOBALS['kernel_runlevel'] = KR_READY;
drainPostApplyTimers();
check($m1->GetTimerInterval('PostApplyTimer') === 0 && $m2->GetTimerInterval('SafetyApplyTimer') === 0 && $m2->GetValue('MonitoringHealthy'), 'deferred initialization completes and disables both one-shot timers');
$GLOBALS['hold_post_apply'] = false;
$m2->HandleTimer(); check($m2->GetValue('SystemState') === 3, 'timer arms external');
// Empty payloads are never used as evidence; current sensor state decides.
$GLOBALS['variables'][41447] = true;
$m2->ReceivePayload(json_encode(['source_id'=>23172,'event_type'=>'ALARM','active_groups'=>[],'active_sensor_details'=>[]]));
check($m2->GetValue('SystemState') === 9, 'empty snapshot cannot hide a currently open window');
$GLOBALS['variables'][41447] = false;
unset($GLOBALS['variables'][17939]);
$m2->RefreshSafetyState(); check($m2->GetValue('SystemState') === 9, 'alarm remains latched with unknown presence');
$GLOBALS['variables'][16297] = true;
$m2->RefreshSafetyState(); check($m2->GetValue('SystemState') === 0, 'trustworthy unlock disarms despite another unknown');
$GLOBALS['variables'][16297] = false;
$m2->RefreshSafetyState(); check($m2->GetValue('SystemState') === 0, 'unknown presence blocks new arming');
$GLOBALS['variables'][17939] = false;
$m2->RefreshSafetyState(); check($m2->GetValue('SystemState') === 2 && $m2->GetTimerInterval('DelayTimer') === 300000, 'recovery restarts full arming delay');
unset($GLOBALS['variables'][29023]); $m2->RefreshSafetyState();
check($m2->GetValue('SystemState') === 0 && $m2->GetTimerInterval('DelayTimer') === 0, 'lost lock input cancels exit delay');
$GLOBALS['variables'][29023] = false;
$m2->RefreshSafetyState(); $m2->HandleTimer();
$GLOBALS['variables'][17939] = true; $m2->RefreshSafetyState();
check($m2->GetValue('SystemState') === 3 && $m2->attributes['PendingArmedState'] === 6, 'mode switch retains external protection');
$GLOBALS['variables'][41447] = true; $m2->RefreshSafetyState();
check($m2->GetValue('SystemState') === 9, 'opening during mode-change delay triggers alarm');
$GLOBALS['variables'][41447] = false; $m2->SetValue('SystemState',3); $m2->RefreshSafetyState();
unset($GLOBALS['variables'][17939]); $m2->RefreshSafetyState();
check($m2->GetValue('SystemState') === 3 && $m2->attributes['PendingArmedState'] === 0, 'invalid mode-change input cancels pending transition only');
$GLOBALS['variables'][17939] = true; $m2->RefreshSafetyState(); $m2->HandleTimer();
check($m2->GetValue('SystemState') === 6, 'mode switch completes after full timer');
$GLOBALS['variables'][57544] = true; $m2->RefreshSafetyState();
check($m2->GetValue('SystemState') === 0, 'used bedroom opening disarms internal');
$GLOBALS['variables'][10822] = true; $m2->RefreshSafetyState();
check($m2->GetValue('SystemState') === 2, 'unused bedrooms do not block internal arming');
$GLOBALS['variables'][10822] = 2; $m2->RefreshSafetyState();
check($m2->GetValue('MonitoringHealthy') && $m2->GetValue('SystemState') === 2, 'integer 2 means unused under unused polarity and permits internal arming');
$m2->HandleTimer();
check($m2->GetValue('SystemState') === 6, 'integer 2 usage permits completion of internal arming with an unused bedroom door open');
$GLOBALS['variables'][10822] = 0; $m2->RefreshSafetyState();
check($m2->GetValue('SystemState') === 0, 'integer zero means used and an open bedroom disarms internal');
$GLOBALS['variables'][10822] = 'invalid'; $m2->RefreshSafetyState();
check(!$m2->GetValue('MonitoringHealthy') && $m2->GetValue('SystemState') === 0, 'malformed usage value remains unknown and blocks arming');
unset($GLOBALS['variables'][10822]); $m2->RefreshSafetyState();
check(!$m2->GetValue('MonitoringHealthy') && $m2->GetValue('SystemState') === 0, 'missing usage variable remains unknown and blocks arming');
$GLOBALS['variables'][10822] = true; $m2->RefreshSafetyState();
$GLOBALS['variables'][57544] = false;
// Global API failures preserve protected states and block new arming.
$GLOBALS['snapshot_failure'] = true;
foreach ([0=>0,2=>0,3=>3,6=>6,9=>9] as $initial=>$expected) { $m2->SetValue('SystemState',$initial); $m2->RefreshSafetyState(); check($m2->GetValue('SystemState') === $expected, "API failure state $initial"); }
$GLOBALS['snapshot_failure'] = false;
// Draft isolation and all activation entrypoints.
$bad = $config; $bad['SensorList'][0]['ClassID'] = 'does_not_exist';
IPS_SetProperty(23172,'SensorList',json_encode($bad['SensorList']));
check($m1->GetConfiguration() === $active, 'pending property cannot leak into discovery');
IPS_ApplyChanges(23172);
check($m1->GetConfiguration() === $active && $m1->attributes['ConfigurationError'] !== '', 'invalid ordinary Apply retains last valid graph');
// Native module reload discards interface-owned subscriptions and timers.
$subscriptionsBeforeReload = $m1->messages;
$m1->messages = []; $m1->timers['PulseExpireTimer'] = 0;
$pulseBeforeReload = json_encode([41447 => time() + 100]);
$m1->attributes['SensorPulseUntilMap'] = $pulseBeforeReload;
IPS_ApplyChanges(23172); // Rejected candidate must rebuild from the validated graph.
check($m1->messages === $subscriptionsBeforeReload, 'rejected Apply after reload restores last-active subscriptions');
check($m1->GetTimerInterval('PulseExpireTimer') > 0 && $m1->attributes['SensorPulseUntilMap'] === $pulseBeforeReload, 'rejected reload restores pulse wake without modifying pulse state');
check($m1->GetConfiguration() === $active && $m1->attributes['ConfigurationError'] !== '', 'runtime recovery does not activate rejected properties');
$m1->attributes['SensorPulseUntilMap'] = '{}'; $m1->timers['PulseExpireTimer'] = 0;
IPS_SetProperty(23172, 'SensorList', json_encode($config['SensorList']));
$badBedroomDraft = json_encode([['GroupName'=>0, 'BedroomDoorClassID'=>false, 'ActiveVariableID'=>'']]);
IPS_SetProperty(23172, 'BedroomList', $badBedroomDraft);
$classesBeforeRecovery = $m1->attributes['ClassStateAttribute'];
$m1->messages = [];
IPS_ApplyChanges(23172);
check($m1->messages === $subscriptionsBeforeReload && str_contains($m1->attributes['ConfigurationError'], 'BedroomList'), 'malformed production bedroom draft cannot strand recreated subscriptions');
check($m1->pending['BedroomList'] === $badBedroomDraft && $m1->GetConfiguration() === $active && $m1->attributes['ClassStateAttribute'] === $classesBeforeRecovery, 'recovery preserves draft, active graph and COUNT state separately');
IPS_SetProperty(23172, 'BedroomList', json_encode($config['BedroomList']));


$m2->SetValue('SystemState',3); $GLOBALS['variables'][41447]=true; $m2->RefreshSafetyState();
check($m2->GetValue('SystemState') === 9, 'valid intrusion survives rejected configuration');
$GLOBALS['variables'][41447]=false;
IPS_SetProperty(23172,'SensorList',json_encode($config['SensorList'])); IPS_ApplyChanges(23172);
$m1->attributes['SensorListBuffer']='[]';
check(count(invokePrivate($m1,'WorkingList','SensorList')) === count($config['SensorList']), 'uninitialized empty draft recovers applied sensors');
invokePrivate($m1,'WriteDraftList','SensorListBuffer','[]');
check(invokePrivate($m1,'WorkingList','SensorList') === [], 'intentional empty draft remains empty');
check($m1->GetConfiguration() === $active, 'explicit deletion remains private until activation');
$m1->attributes['DraftSections']='{}'; $m1->attributes['SensorListBuffer']=json_encode($bad['SensorList']);
invokePrivate($m1,'WriteDraftList','SensorListBuffer',json_encode($bad['SensorList']));
ob_start(); $m1->SaveConfiguration(); $message=ob_get_clean();
check(str_contains($message,'rejected') && $m1->GetConfiguration()===$active, 'COMMIT rejects invalid graph without activation');
$pendingBefore=$m1->pending; ob_start(); $m1->UI_RestoreBackup(json_encode($bad)); $message=ob_get_clean();
check(str_contains($message,'rejected') && $m1->pending===$pendingBefore, 'restore rejects before touching properties');
$toggle=invokePrivate($m1,'ToggleActiveState','sensor','23917');
check(!$toggle['success'], 'dashboard toggle cannot commit pending draft');
$m1->attributes['DraftSections']='{}'; IPS_SetProperty(23172,'SensorList',json_encode($config['SensorList'])); IPS_ApplyChanges(23172);
// Identity recovery does not rely on row counts or discard sensors.
$missingIDs=$config; foreach ($missingIDs['ClassList'] as &$row) unset($row['ClassID']); unset($row);
$healed=AlarmSafety::normalize($missingIDs,$config);
check(array_column($healed['ClassList'],'ClassID')===array_column($config['ClassList'],'ClassID'), 'hidden IDs recovered by stable name');
check(!$healed['SensorList'] || $healed['SensorList']===$config['SensorList'], 'healing preserves all associations');
// Failed group input must be visible even when another sensor proves breach.
$plan=AlarmSafety::compile($config,mapping(),56438);
$values=$GLOBALS['variables']; $values[41447]=true; unset($values[20533]);
$result=AlarmSafety::evaluate($plan,static fn($id)=>$values[$id]??null);
check($result['sources'][5]['value']===false && $result['errors'], 'OR retains valid intrusion and reports missing peer');
// State sync cannot fabricate motion counts or new pulse edges.
$GLOBALS['variables'][41000]=true;
$GLOBALS['variables'][41001]=false; $GLOBALS['variables'][41002]=false;
invokePrivate($m1,'CheckLogic',41000,'manual');
$before=json_decode($m1->attributes['ClassStateAttribute'],true)['cls_Count']['Buffer'];
$condition=$m1->attributes['SensorConditionStateMap']; $last=$m1->attributes['LastSensorValueMap']; $pulses=$m1->attributes['SensorPulseUntilMap'];
$GLOBALS['variables'][41001]=true; $GLOBALS['variables'][41002]=true;
$m1->RequestStateSync(); $m1->RequestStateSync();
$after=json_decode($m1->attributes['ClassStateAttribute'],true)['cls_Count']['Buffer'];
check($before===$after && count($after)===1, 'sync never increments COUNT');
check($condition===$m1->attributes['SensorConditionStateMap'] && $last===$m1->attributes['LastSensorValueMap'] && $pulses===$m1->attributes['SensorPulseUntilMap'], 'sync never creates CHANGE/ONCE pulses or consumes edges');
check(count($GLOBALS['calls'])>0, 'integration dispatch executed');
// A rejected candidate has no effect on healthy delay or active throttle.
$GLOBALS['variables'][17939]=false; $GLOBALS['variables'][10822]=false;
$GLOBALS['variables'][57544]=false; $GLOBALS['variables'][15312]=false;
$m2->SetValue('SystemState',0); $m2->RefreshSafetyState();
check($m2->GetValue('SystemState')===2, 'baseline delay before rejected Apply');
IPS_SetProperty(23172,'SensorList',json_encode($bad['SensorList']));
IPS_SetProperty(23172,'TargetThrottleList',json_encode([['InstanceID'=>57380,'MaxMessages'=>1,'WindowSeconds'=>3600]]));
IPS_ApplyChanges(23172);
check($m2->GetValue('SystemState')===2 && $m2->GetValue('MonitoringHealthy'), 'rejected candidate cannot cancel healthy exit delay');
check(invokePrivate($m1,'ReadTargetThrottleConfig')===[], 'rejected candidate cannot activate throttle');
check(json_decode($m1->GetActiveSensors(),true)!==null, 'active-sensor API remains readable after rejection');
IPS_SetProperty(23172,'SensorList',json_encode($config['SensorList'])); IPS_SetProperty(23172,'TargetThrottleList','[]'); IPS_ApplyChanges(23172);
// Reject Module 2 Apply/import without losing window coverage or the consumer notification plan.
$m2->SetValue('SystemState',3); $savedSettings=$m2->attributes['ActiveSafetySettings'];
$badMapping=array_values(array_filter(mapping(),static fn($r)=>$r['LogicalRole']!=='Window Contact'));
IPS_SetProperty(56438,'GroupMapping',json_encode($badMapping)); IPS_ApplyChanges(56438);
check($m2->attributes['ActiveSafetySettings']===$savedSettings, 'PSM rejects incomplete mapping and retains prior settings');
$GLOBALS['variables'][41447]=true; $m2->RefreshSafetyState();
check($m2->GetValue('SystemState')===9, 'PSM window protection survives rejected mapping');
$GLOBALS['variables'][41447]=false;
$oldPending=$m2->pending; $oldPlan=$m1->ReadAttributeString('SafetyPlan');
invokePrivate($m2,'ImportConfiguration',json_encode(['SensorGroupInstanceID'=>23172,'DispatchTargetID'=>56438,'ArmingDelayDuration'=>5,'GroupMapping'=>$badMapping]));
check($m2->pending===$oldPending && $m1->ReadAttributeString('SafetyPlan')===$oldPlan, 'invalid PSM import is nonmutating and cannot replace active dependencies');
IPS_SetProperty(56438,'GroupMapping',json_encode(mapping())); IPS_ApplyChanges(56438);
// Each required input independently unknown in armed/disarmed states.
foreach ([23917,16297,16860,29023,17939,22324,41447,57544,10822] as $id) {
    $saved=$GLOBALS['variables'][$id]; unset($GLOBALS['variables'][$id]);
    $m2->SetValue('SystemState',0); $m2->RefreshSafetyState(); check($m2->GetValue('SystemState')===0,"missing $id blocks new arming");
    foreach ([3,6,9] as $state) { $m2->SetValue('SystemState',$state); $m2->RefreshSafetyState(); check($m2->GetValue('SystemState')===$state,"missing $id preserves state $state"); }
    $GLOBALS['variables'][$id]=$saved;
}
// Missing peer, disabled route and malformed API all retain valid intrusion evaluation.
$disabled=$config; $disabled['SensorList'][0]['Active']=false;
foreach (AlarmSafety::LISTS as $key) IPS_SetProperty(23172,$key,json_encode($disabled[$key])); IPS_ApplyChanges(23172);
$m2->SetValue('SystemState',3); $GLOBALS['variables'][41447]=true; $m2->RefreshSafetyState();
check($m2->GetValue('SystemState')===9 && !$m2->GetValue('MonitoringHealthy'), 'disabled required input does not suppress valid unrelated intrusion');
$GLOBALS['variables'][41447]=false;
foreach (AlarmSafety::LISTS as $key) IPS_SetProperty(23172,$key,json_encode($config[$key])); IPS_ApplyChanges(23172);
// Recovery timer exists only while degraded and retries a failed snapshot with no new sensor event.
$GLOBALS['snapshot_failure']=true; $m2->RefreshSafetyState();
check(str_contains($m2->GetValue('InputHealth'), 'RuntimeException: Simulated API failure'), 'snapshot failure reports the underlying exception');
check($m2->GetTimerInterval('SafetyRetryTimer')===30000, 'degraded-only retry is bounded to 30 seconds');
$GLOBALS['snapshot_failure']=false; $m2->RefreshSafetyState();
check($m2->GetTimerInterval('SafetyRetryTimer')===0, 'recovery stops retry timer');
// Established pulses must not be replayed by unchanged sync.
$GLOBALS['variables'][41001]=false; invokePrivate($m1,'CheckLogic',41001,'manual');
$GLOBALS['variables'][41001]=true; invokePrivate($m1,'CheckLogic',41001,'manual');
$dispatchCount=static fn()=>count(array_filter($GLOBALS['calls'],static fn($c)=>$c[0]===57380 && $c[1]==='ReceivePayload'));
$before=$dispatchCount(); $m1->RequestStateSync(); $m1->RequestStateSync();
check($dispatchCount()===$before, 'unchanged sync does not replay an active pulse');
// Delivery failure and throttling retain last delivered projection, allowing retry.
$GLOBALS['variables'][41000]=false; $GLOBALS['variables'][41001]=false; $GLOBALS['variables'][41002]=false;
$m1->attributes['SensorPulseUntilMap']='{}'; $m1->attributes['ClassStateAttribute']='{}'; invokePrivate($m1,'CheckLogic',0,'manual');
$delivered=$m1->attributes['LastTargetProjectionState'];
$GLOBALS['fail_dispatch'][57380]=true;
$GLOBALS['variables'][41001]=true; invokePrivate($m1,'CheckLogic',41001,'manual');
check(json_decode($m1->attributes['LastTargetProjectionState'],true)['57380']===json_decode($delivered,true)['57380'], 'failed delivery retains old projection');
$GLOBALS['fail_dispatch'][57380]=false;
$before=$dispatchCount(); $m1->RequestStateSync(); check($dispatchCount()>$before, 'sync retries changed undelivered state');
// Repeated snapshot reads reuse the compiled graph and do not mutate motion state.
$before=$m1->ReadAttributeString('SafetyPlan'); $runtime=$m1->attributes['ClassStateAttribute'];
for ($i=0;$i<100;$i++) $m1->GetSafetySnapshot(json_encode(mapping()),56438);
check($m1->ReadAttributeString('SafetyPlan')===$before && $m1->attributes['ClassStateAttribute']===$runtime, '100 safety reads reuse bounded plan without touching motion state');
// Pending edits can be previewed repeatedly without regenerating a new class identity.
$new=$config['ClassList']; $new[]=['ClassName'=>'New draft class','LogicMode'=>0,'Threshold'=>1,'TimeWindow'=>10,'LabelMode'=>0];
IPS_SetProperty(23172,'ClassList',json_encode($new));
$normalized=AlarmSafety::normalize(['ClassList'=>invokePrivate($m1,'WorkingList','ClassList')],invokePrivate($m1,'IdentityBaseline'));
$m1->attributes['ClassListBuffer']=json_encode($normalized['ClassList']);
$again=AlarmSafety::normalize(['ClassList'=>invokePrivate($m1,'WorkingList','ClassList')],invokePrivate($m1,'IdentityBaseline'));
check($again===$normalized, 'new draft class keeps stable ID across form reloads');
IPS_SetProperty(23172,'ClassList',json_encode($config['ClassList']));
// Invalid source paths and malformed restores are rejected, preserving active PSM settings.
foreach (['House State Windowz', 'Motion inside'] as $source) {
    $candidate=mapping(); $candidate[5]['SourceKey']=$source;
    $settings=json_decode($m2->attributes['ActiveSafetySettings'],true); $settings['GroupMapping']=json_encode($candidate);
    check(invokePrivate($m2,'ValidateSafetySettings',$settings)!=='', "reject missing/non-LEVEL path $source");
}
$settings=json_decode($m2->attributes['ActiveSafetySettings'],true); $settings['GroupMapping']=json_encode(mapping());
$settings['BedroomDoorPolarity']='broken'; check(invokePrivate($m2,'ValidateSafetySettings',$settings)!=='', 'invalid bedroom polarity rejected');
$before=$m2->pending;
invokePrivate($m2,'ImportConfiguration',json_encode(['SensorGroupInstanceID'=>23172,'DispatchTargetID'=>56438,'ArmingDelayDuration'=>5,'GroupMapping'=>mapping(),'BedroomDoorPolarity'=>'broken','StatePushTargets'=>[]]));
check($before===$m2->pending,'malformed restore polarity cannot be silently normalized');
foreach (['reference','count','identity','tamper'] as $fault) {
    $invalid=$config;
    if ($fault==='reference') { $invalid['SensorList'][0]['ComparisonSource']=1; $invalid['SensorList'][0]['ComparisonVariableID']=0; }
    if ($fault==='count') $invalid['ClassList'][6]['Threshold']=0;
    if ($fault==='identity') $invalid['ClassList'][0]['ClassID']=[];
    if ($fault==='tamper') $invalid['TamperList']=[['VariableID'=>0,'Operator'=>0]];
    check((bool)AlarmSafety::validate(AlarmSafety::normalize($invalid,$config)), "reject invalid $fault configuration");
}
// Missing dynamic reference and incompatible types remain unknown, without mutating an evaluation edge.
$dynamic=$config; $dynamic['SensorList'][0]['ComparisonSource']=1; $dynamic['SensorList'][0]['ComparisonVariableID']=70000;
$plan=AlarmSafety::compile($dynamic,mapping(),56438);
$result=AlarmSafety::evaluate($plan,static fn($id)=>$GLOBALS['variables'][$id]??null);
check($result['sources'][0]['value']===null,'missing dynamic reference is unknown');
$GLOBALS['variables'][70000]='false';
$result=AlarmSafety::evaluate($plan,static fn($id)=>$GLOBALS['variables'][$id]??null);
check($result['sources'][0]['value']===null,'incompatible dynamic reference type is unknown');
// Malformed and wrong-source payloads cannot clear an alarm or seed token state.
$m2->SetValue('SystemState',9); $token=$m2->attributes['LastProcessedEventSeq'];
$m2->ReceivePayload('{not json'); $m2->ReceivePayload(json_encode(['source_id'=>12345,'event_epoch'=>99999999999999,'event_seq'=>999,'active_groups'=>[]]));
check($m2->GetValue('SystemState')===9 && $m2->attributes['LastProcessedEventSeq']===$token,'malformed/wrong-source events are inert');
// Restart/Apply never downgrades armed or alarm state; partial delay starts anew.
$GLOBALS['variables'][17939]=false;
foreach ([3,6,9] as $state) { $m2->SetValue('SystemState',$state); IPS_ApplyChanges(56438); check($m2->GetValue('SystemState')===$state,"Apply preserves protected state $state"); }
$m2->SetValue('SystemState',2); $m2->attributes['DelayExpired']=1; IPS_ApplyChanges(56438);
check($m2->GetValue('SystemState')===2 && $m2->attributes['DelayExpired']===0 && $m2->GetTimerInterval('DelayTimer')===300000,'Apply restarts partial initial arming delay');
// Malformed buffer JSON cannot silently become an empty committed sensor list.
$activeBefore=$m1->GetConfiguration(); $pendingBefore=$m1->pending;
$m1->attributes['SensorListBuffer']='invalid'; $m1->attributes['DraftSections']=json_encode(['SensorListBuffer'=>true]);
ob_start(); $m1->SaveConfiguration(); $message=ob_get_clean();
check(str_contains($message,'rejected') && $m1->GetConfiguration()===$activeBefore && $m1->pending===$pendingBefore,'malformed draft JSON cannot erase sensor rules');
$m1->attributes['DraftSections']='{}';
// Exercise the actual generated form after hidden identity loss and a draft class addition.
$classes=$config['ClassList']; foreach ($classes as &$row) unset($row['ClassID']); unset($row);
$classes[]=['ClassName'=>'UI test class','LogicMode'=>0,'Threshold'=>1,'TimeWindow'=>10,'LabelMode'=>0,'Active'=>true];
IPS_SetProperty(23172,'ClassList',json_encode($classes));
$form=json_decode($m1->GetConfigurationForm(),true);
check(is_array($form), 'configuration form still renders with hidden IDs missing');
$hydrated=json_decode($m1->attributes['ClassListBuffer'],true);
check(count($hydrated)===count($config['ClassList'])+1 && $hydrated[0]['ClassID']===$config['ClassList'][0]['ClassID'],'form heals IDs and preserves added draft class');
check($m1->GetConfiguration()===$activeBefore,'form rendering cannot publish draft configuration');
IPS_SetProperty(23172,'ClassList',json_encode($config['ClassList']));
// Both initial and armed-mode delays expose the real remaining time through the unchanged schema.
$m2->SetValue('SystemState',3); $GLOBALS['variables'][17939]=true; $m2->RefreshSafetyState();
$snapshot=json_decode($m2->GetHouseStateSnapshot(),true);
check($snapshot['system_state_id']===3 && $snapshot['delay_active'] && $snapshot['delay_remaining_seconds']>=299,'armed-mode delay exports correct remaining time');
// Throttle backup round-trip explicitly restores an empty list, clearing a later throttle.
$m1->UI_LoadBackup();
// UI_LoadBackup puts its complete JSON in a form field; the stub records it below.
$backup=json_decode($m1->buffers['test_form_BackupData'] ?? '{}',true);
check(array_key_exists('TargetThrottleList',$backup),'Module 1 backup includes throttle list');
$backup['TargetThrottleList']=[];
IPS_SetProperty(23172,'TargetThrottleList',json_encode([['InstanceID'=>57380,'MaxMessages'=>1,'WindowSeconds'=>3600]])); IPS_ApplyChanges(23172);
ob_start(); $m1->UI_RestoreBackup(json_encode($backup)); ob_end_clean();
check(invokePrivate($m1,'ReadTargetThrottleConfig')===[],'backup restore clears throttle added after export');
// Healthy snapshot API benchmark is informational; assertions concern bounded behavior, not host speed.
$GLOBALS['hold_post_apply'] = true;
$m2->SetValue('SystemState', 3);
IPS_ApplyChanges(56438);
check($m2->GetValue('SystemState') === 3, 'deferred Apply retains armed protection while pending');
$GLOBALS['variables'][41447] = true;
$m2->RefreshSafetyState();
check($m2->GetValue('SystemState') === 9 && !$m2->attributes['SafetyApplyPending'], 'valid intrusion during pending initialization completes validation and alarms');
$GLOBALS['variables'][41447] = false;
$GLOBALS['hold_post_apply'] = false;
check(($GLOBALS['lifecycle_cross_calls'] ?? 0) === 0, 'no PHP module API or dispatch calls occurred inside Apply throughout the suite');
$start=microtime(true); for ($i=0;$i<1000;$i++) $m1->GetSafetySnapshot(json_encode(mapping()),56438);
printf("Safety API: 1000 reads in %.1f ms; cache %.1f KiB\n",(microtime(true)-$start)*1000,strlen($m1->ReadAttributeString('SafetyPlan'))/1024);
echo "PASS: $count checks\n";
