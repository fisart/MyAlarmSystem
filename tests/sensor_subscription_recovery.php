<?php
declare(strict_types=1);
require __DIR__ . '/symcon_stub.php';
require __DIR__ . '/../SensorGroup/module.php';
$checks=0;
function subscriptionCheck(bool $ok,string $why):void { global $checks; ++$checks; if(!$ok)throw new RuntimeException($why); }
$m=new SensorGroup(7400);$m->Create();$c=array_fill_keys(AlarmSafety::LISTS,[]);
$c['ClassList']=[['ClassID'=>'door','ClassName'=>'Door','LogicMode'=>0,'Threshold'=>1,'TimeWindow'=>10]];
$c['GroupList']=[['GroupID'=>'door-group','GroupName'=>'Door','GroupLogic'=>0]];
$c['GroupMembers']=[['GroupName'=>'Door','ClassID'=>'door']];
$c['SensorList']=[['ClassID'=>'door','VariableID'=>101,'Operator'=>0,'ComparisonValue'=>'1','TriggerMode'=>0]];
$c['TamperList']=[['VariableID'=>107,'ComparisonSource'=>1,'ComparisonVariableID'=>108,'Operator'=>0,'ComparisonValue'=>'1','TriggerMode'=>0],['VariableID'=>109,'ComparisonSource'=>1,'ComparisonVariableID'=>110,'Operator'=>0,'ComparisonValue'=>'1','TriggerMode'=>1]];
$c['BedroomTarget']=0;$c['MaintenanceMode']=false;$c['TargetThrottleList']=[];
$GLOBALS['variables']=array_replace($GLOBALS['variables'],[101=>false,107=>false,108=>true,109=>false,110=>false]);
foreach($c as$key=>$value)$m->pending[$key]=is_array($value)?json_encode($value):$value;
IPS_ApplyChanges(7400);
subscriptionCheck(isset($m->messages[108])&&!isset($m->messages[110]),'LEVEL tamper comparison reference subscribed; CHANGE comparison remains ignored');
$GLOBALS['variables'][108]=false;$m->MessageSink(1,108,VM_UPDATE,[false,true,true]);
subscriptionCheck($m->GetValue('Sabotage')===true,'Tamper reference update reevaluates existing comparison rule');
$active=$m->GetConfiguration();$state=$m->attributes['ClassStateAttribute'];
$m->pending['BedroomList']='[{"GroupName":0}]';$m->messages=[];IPS_ApplyChanges(7400);
subscriptionCheck(isset($m->messages[101])&&isset($m->messages[108])&&!isset($m->messages[110]),'Rejected Apply reattaches retained primary/tamper dependencies after interface recreation');
subscriptionCheck($m->GetConfiguration()===$active&&$m->attributes['ClassStateAttribute']===$state,'Reattached subscriptions preserve active graph and temporal state');
$GLOBALS['calls']=[];$GLOBALS['variables'][101]=true;$m->MessageSink(2,101,VM_UPDATE,[true,true,false]);
subscriptionCheck(method_exists($m,'RunInputFifo')&&method_exists($m,'GetInputFifoReport'),'Release retains FIFO control APIs');
subscriptionCheck($m->GetValue('Status')===true&&!json_decode($m->GetInputFifoReport(),true)['enabled'],'Default-off FIFO keeps ordinary inputs on the existing evaluator');
subscriptionCheck(!$m->properties['EnableInputFifo']&&!$m->properties['EnableFifoFailureCapture']&&!$m->properties['EnableFifoShadow'],'Release diagnostics and FIFO remain off by default');
echo "Release subscriptions: $checks checks passed. Native update recovery is supported by prior production tests.\n";
