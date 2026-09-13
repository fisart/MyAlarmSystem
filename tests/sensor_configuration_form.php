<?php
declare(strict_types=1);
require __DIR__ . '/symcon_stub.php';
require __DIR__ . '/../SensorGroup/module.php';
$checks=0;
function formCheck(bool $ok,string $why):void { global $checks; ++$checks; if(!$ok)throw new RuntimeException('Configuration form: '.$why); }
function bedroomField(array $form):array { foreach($form['elements'] as $field)if(($field['name']??'')==='BedroomList')return $field; throw new RuntimeException('Missing bedroom backing field'); }
/** SDK List saving contract: editable/explicit-save columns and non-column fields are persisted. */
function serializeFormList(array $field):array {
    $result=[]; $columns=array_column($field['columns'],null,'name');
    foreach($field['values']??[] as $row) {
        $saved=[];
        foreach($row as $key=>$value) if(!isset($columns[$key]) || ($columns[$key]['save']??isset($columns[$key]['edit'])))$saved[$key]=$value;
        $result[]=$saved;
    }
    return $result;
}
function sameTypedRows(array $left,array $right):bool {
    foreach($left as&$row)ksort($row);unset($row);foreach($right as&$row)ksort($row);unset($row);return $left===$right;
}
function formFixture(int $id):SensorGroup {
    $m=new SensorGroup($id);$m->Create();$c=array_fill_keys(AlarmSafety::LISTS,[]);
    $c['ClassList']=[['ClassID'=>'fob','ClassName'=>'Ubiquity Device Status Class','LogicMode'=>0,'Threshold'=>1,'TimeWindow'=>10],['ClassID'=>'bed','ClassName'=>'Bedroom door','LogicMode'=>0,'Threshold'=>1,'TimeWindow'=>10]];
    $c['GroupList']=[['GroupID'=>'gf','GroupName'=>'Ubiquity Devices Status Group','GroupLogic'=>0],['GroupID'=>'gb','GroupName'=>'Bedroom','GroupLogic'=>0]];
    $c['GroupMembers']=[['GroupName'=>'Ubiquity Devices Status Group','ClassID'=>'fob'],['GroupName'=>'Bedroom','ClassID'=>'bed']];
    foreach([40696,41873,40697]as$vid)$c['SensorList'][]=['VariableID'=>$vid,'ClassID'=>'fob','Operator'=>0,'ComparisonValue'=>'DISCONNECTED','TriggerMode'=>2,'PulseSeconds'=>10];
    $c['SensorList'][]=['VariableID'=>101,'ClassID'=>'bed','Operator'=>0,'ComparisonValue'=>'1','TriggerMode'=>0];
    $c['BedroomList']=[['GroupName'=>'Bedroom','BedroomDoorClassID'=>'bed','ActiveVariableID'=>10822]];
    $c['DispatchTargets']=[['InstanceID'=>7000]];$c['GroupDispatch']=[['GroupName'=>'Ubiquity Devices Status Group','InstanceID'=>7000]];
    $c['BedroomTarget']=7000;$c['MaintenanceMode']=false;$c['TargetThrottleList']=[];
    $GLOBALS['variables'][101]=false;$GLOBALS['variables'][40697]='CONNECTED';$GLOBALS['variables'][10822]=2;$GLOBALS['variables'][10823]=0;
    unset($GLOBALS['variables'][40696],$GLOBALS['variables'][41873]);new IPSModule(7000);
    foreach($c as$key=>$value)$m->pending[$key]=is_array($value)?json_encode($value):$value;
    IPS_ApplyChanges($id);return $m;
}
$m=formFixture(7300);$active=json_decode($m->GetConfiguration(),true);$running=$m->GetConfiguration();
formCheck(!IPS_VariableExists(40696) && !IPS_VariableExists(41873),'Fixture matches missing active Fob variables');
$static=bedroomField(json_decode(file_get_contents(__DIR__.'/../SensorGroup/form.json'),true));
foreach($static['columns']as$column)formCheck(($column['save']??false)===true,'Backing column persists '.$column['name']);
$form=json_decode($m->GetConfigurationForm(),true);$field=bedroomField($form);
formCheck(($field['loadValuesFromConfiguration']??true)===false,'Working rows override stored form list during reload');
formCheck(serializeFormList($field)===$active['BedroomList'],'Generated backing field round-trips typed bedroom rows');
// Reproduce old non-editable defaults: unrelated form save strips all required bedroom fields.
$old=$field;foreach($old['columns']as&$column)unset($column['save']);unset($column);
formCheck(serializeFormList($old)===[[]],'SDK default without save flags drops required bedroom columns');
// Delete through the same public method used by sensor-row Delete buttons.
$m->UI_DeleteSensorListItem('fob',0);$m->UI_DeleteSensorListItem('fob',0);
$sensorDraft=$m->pending['SensorList'];$sensorBuffer=$m->attributes['SensorListBuffer'];
$ids=array_column(json_decode($sensorDraft,true),'VariableID');sort($ids);
formCheck($ids===[101,40697],'Two selected missing sensors deleted from draft; other sensors retained');
formCheck($m->GetConfiguration()===$running,'Sensor deletion does not activate before commit');
IPS_SetProperty(7300,'BedroomList',json_encode(serializeFormList($old)));
ob_start();$m->SaveConfiguration();$message=ob_get_clean();
formCheck(str_contains($message,'BedroomList.GroupName')&&$m->GetConfiguration()===$running,'COMMIT rejects stripped bedroom draft and retains running graph');
// Updating Module Control recreates the interface while the invalid staged list is still present.
$m->Create();$m->buffers=[];$m->messages=[];IPS_ApplyChanges(7300);
formCheck($m->GetConfiguration()===$running&&$m->pending['SensorList']===$sensorDraft,'Rejected draft across interface reload retains running graph and pending sensor deletion');
// Explicit restoration repairs this section only and retains sensor deletion.
$state=$m->attributes['ClassStateAttribute'];$revision=$m->attributes['ActiveRevision'];
$result=$m->RestoreActiveBedroomDraft();
formCheck(str_contains($result,'Other edits are retained'),'Recovery explains section-only staging');
formCheck($m->pending['SensorList']===$sensorDraft&&$m->attributes['SensorListBuffer']===$sensorBuffer,'Bedroom recovery preserves sensor draft deletion');
formCheck($m->GetConfiguration()===$running&&$m->attributes['ActiveRevision']===$revision&&$m->attributes['ClassStateAttribute']===$state,'Bedroom restore leaves active graph/COUNT untouched');
formCheck(json_decode($m->pending['BedroomList'],true)===$active['BedroomList'],'Recovered bedroom property matches validated running configuration');
$form=json_decode($m->GetConfigurationForm(),true);$field=bedroomField($form);
formCheck(serializeFormList($field)===$active['BedroomList'],'Reloaded fixed form preserves restored typed bedroom rows');
// An already-open old form cannot defeat the explicitly marked recovered buffer.
IPS_SetProperty(7300,'BedroomList','[{"GroupName":0,"BedroomDoorClassID":false,"ActiveVariableID":""}]');
ob_start();$m->SaveConfiguration();$message=ob_get_clean();$saved=json_decode($m->GetConfiguration(),true);
formCheck(str_contains($message,'successfully')&&$saved['BedroomList']===$active['BedroomList'],'COMMIT activates validated bedroom restoration rather than corrupt property');
$ids=array_column($saved['SensorList'],'VariableID');sort($ids);
formCheck($ids===[101,40697]&&isset($m->messages[101])&&isset($m->messages[40697]),'Commit removes only selected sensors and retains remaining sensor subscriptions');
// Legitimate compact bedroom edits and intentional empty list remain authoritative.
$m=formFixture(7301);$active=json_decode($m->GetConfiguration(),true);$edit=$active['BedroomList'];$edit[0]['ActiveVariableID']=10823;
$m->RequestAction('UpdateBedroomListCompact',json_encode($edit));IPS_SetProperty(7301,'BedroomList','[{}]');
$field=bedroomField(json_decode($m->GetConfigurationForm(),true));
formCheck(sameTypedRows(serializeFormList($field),$edit),'Marked legitimate bedroom edit survives form property corruption without automatic active restore');
ob_start();$m->SaveConfiguration();ob_end_clean();
formCheck(sameTypedRows(json_decode($m->GetConfiguration(),true)['BedroomList'],$edit),'Explicit bedroom edits commit normally');
$m->RequestAction('UpdateBedroomListCompact','[]');$field=bedroomField(json_decode($m->GetConfigurationForm(),true));
formCheck(serializeFormList($field)===[],'Intentional empty bedroom edit remains empty');
ob_start();$m->SaveConfiguration();ob_end_clean();formCheck(json_decode($m->GetConfiguration(),true)['BedroomList']===[],'Intentional bedroom deletion commits without resurrection');
// Unavailable/updating/invalid active graph is never used to bless a draft.
$m=formFixture(7302);$pending=$m->pending;$attrs=$m->attributes;$m->attributes['ActiveRevision']='updating';$blocked=false;
try{$m->RestoreActiveBedroomDraft();}catch(RuntimeException $e){$blocked=true;}
formCheck($blocked&&$m->pending===$pending&&$m->attributes['BedroomListBuffer']===$attrs['BedroomListBuffer'],'Restore refuses updating configuration without touching draft');
$m->attributes['ActiveRevision']='invalid';$bad=json_decode($m->GetConfiguration(),true);$bad['BedroomList']=[['GroupName'=>0]];$m->attributes['ActiveConfiguration']=json_encode($bad);
(new ReflectionProperty(SensorGroup::class,'activeConfigCache'))->setValue($m,null);$blocked=false;
try{$m->RestoreActiveBedroomDraft();}catch(RuntimeException $e){$blocked=true;}
formCheck($blocked&&$m->pending===$pending,'Restore rejects invalid running graph before touching draft');
echo "Sensor configuration form: $checks checks passed. Native console persistence must still be confirmed.\n";
