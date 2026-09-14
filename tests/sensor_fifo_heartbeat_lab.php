<?php
declare(strict_types=1);
ob_start();require __DIR__.'/sensor_fifo_lab.php';ob_end_clean();$checks=0;
function heartbeatLabCheck(bool $ok,string $why):void { global $checks;++$checks;if(!$ok)throw new RuntimeException('Heartbeat lab: '.$why); }
function AHW_GetHeartbeatHistory($id){return $GLOBALS['heartbeat_history_raw']??json_encode($GLOBALS['heartbeat_lab_history']??[]);}
function AHW_RunCycle($id){$GLOBALS['ordinary_heartbeat_requests'][]=$id;if(!empty($GLOBALS['throw_ordinary_heartbeat']))throw new RuntimeException('Injected normal watchdog API failure');$now=microtime(true);$day=(int)date('G',(int)$now)*3600000+(int)date('i',(int)$now)*60000+(int)date('s',(int)$now)*1000+(int)floor(($now-floor($now))*1000);$targets=[];foreach(['Module 1 callback','Module 3 Intrusion Server']as$name)$targets[$name]=['state'=>'OK','value'=>$day,'updated'=>$day+100,'runtime_ms'=>100];$GLOBALS['heartbeat_lab_history'][]=['timestamp'=>$day,'sent_at'=>$day,'overall_state'=>'OK','targets'=>$targets];}
$reference=1000000000;$context=['clock_consistent'=>true,'mapping_uncertainty_ms'=>1.0,'reference_ns'=>$reference,'reference_day_ms'=>1000000,'required_targets'=>['Module 1 callback','Module 3 Intrusion Server']];
$goodLab=['comparison_incomplete'=>false,'fifo_after'=>['fault'=>'','test'=>['paused'=>false]],'producers'=>[['completed'=>true,'writes'=>32,'started_ns'=>$reference-25000000,'ended_ns'=>$reference+135000000]]];
$entry=['timestamp'=>1000005,'sent_at'=>1000005,'overall_state'=>'OK','targets'=>[]];foreach($context['required_targets']as$name)$entry['targets'][$name]=['state'=>'OK','value'=>1000005,'updated'=>1000105,'runtime_ms'=>100];
heartbeatLabCheck(FifoHeartbeatLab::assess($context,$goodLab,[$entry])['passed'],'Correlated send inside measured producer interval and all targets OK passes');
$late=$entry;$late['timestamp']=$late['sent_at']=1000500;heartbeatLabCheck(!FifoHeartbeatLab::assess($context,$goodLab,[$late])['passed'],'Send after producer execution does not prove overlap');
$boundary=$entry;$boundary['timestamp']=$boundary['sent_at']=999976;heartbeatLabCheck(!FifoHeartbeatLab::assess($context,$goodLab,[$boundary])['passed'],'Boundary within timestamp uncertainty does not pass');
$missing=$entry;$missing['targets']['Module 1 callback']['state']='MISSING';$r=FifoHeartbeatLab::assess($context,$goodLab,[$missing]);heartbeatLabCheck(!$r['passed'] && $r['status']==='failed','Overlapping definite heartbeat failure reported');
$mixed=$missing;$mixed['targets']['Module 3 Intrusion Server']['state']='PENDING';$r=FifoHeartbeatLab::assess($context,$goodLab,[$mixed]);heartbeatLabCheck(!$r['passed'] && $r['status']==='failed','Known terminal failure wins over another pending target');
$pending=$entry;$pending['targets']['Module 1 callback']['state']='PENDING';$r=FifoHeartbeatLab::assess($context,$goodLab,[$pending]);heartbeatLabCheck(!$r['passed'] && $r['status']==='inconclusive','Pending confirmation is not a successful heartbeat');
$waiting=$entry;$waiting['targets']['Module 1 callback']['state']='WAITING';$r=FifoHeartbeatLab::assess($context,$goodLab,[$waiting]);heartbeatLabCheck(!$r['passed'] && $r['status']==='inconclusive','Native WAITING confirmation remains inconclusive');
foreach(['value','updated','runtime_ms']as$key){$bad=$entry;$bad['targets']['Module 1 callback'][$key]=-1;heartbeatLabCheck(!FifoHeartbeatLab::assess($context,$goodLab,[$bad])['passed'],'Contradictory OK '.$key.' rejected');}
$badClock=$context;$badClock['clock_consistent']=false;heartbeatLabCheck(!FifoHeartbeatLab::assess($badClock,$goodLab,[$entry])['passed'],'Clock drift invalidates overlap evidence');
$badClock=$context;$badClock['mapping_uncertainty_ms']=6;heartbeatLabCheck(!FifoHeartbeatLab::assess($badClock,$goodLab,[$entry])['passed'],'Large mapping uncertainty invalidates evidence');
$badLab=$goodLab;$badLab['comparison_incomplete']=true;heartbeatLabCheck(!FifoHeartbeatLab::assess($context,$badLab,[$entry])['passed'],'Incomplete FIFO accounting cannot pass shared-service gate');
heartbeatLabCheck(!FifoHeartbeatLab::assess($context,$goodLab,[])['passed'],'No new cycle is inconclusive');
heartbeatLabCheck(!FifoHeartbeatLab::assess($context,$goodLab,[$entry,$missing])['passed'],'One OK cycle does not conceal another overlapping failure');
heartbeatLabCheck(!FifoHeartbeatLab::assess($context,$goodLab,[$entry,$pending])['passed'],'One OK cycle does not conceal another pending overlap');
heartbeatLabCheck(!FifoHeartbeatLab::assess($context,$goodLab,array_fill(0,4,$entry))['passed'],'Excess cycle evidence fails bounded gate');
heartbeatLabCheck(FifoHeartbeatLab::relativeMs(20,86399975)===45 && FifoHeartbeatLab::relativeMs(86399975,20)===-45,'Timestamp mapping handles midnight');
$midnight=$context;$midnight['reference_day_ms']=86399975;$mid=$entry;$mid['timestamp']=$mid['sent_at']=20;foreach($midnight['required_targets']as$name)$mid['targets'][$name]=['state'=>'OK','value'=>20,'updated'=>120,'runtime_ms'=>100];heartbeatLabCheck(FifoHeartbeatLab::assess($midnight,$goodLab,[$mid])['passed'],'Midnight correlation preserves bounded producer overlap');
// Only spies for normal watchdog API; actual FIFO remains the lab fixture.
$production=new SensorGroup(23172);$production->Create();IPS_ApplyChanges(23172);$GLOBALS['model_module_ids'][23172]=FifoLab::MODULE;
$watchdog=new IPSModule(35750);$GLOBALS['model_module_ids'][35750]='{02B51F4D-6B3F-4CB5-A9D5-2F1F123C646B}';
$input=50100;$target=50101;$active=50102;$GLOBALS['variables'][$input]=0;$GLOBALS['variables'][$target]=0;$GLOBALS['variables'][$active]=true;$GLOBALS['model_variable_types'][$active]=0;$GLOBALS['model_variable_types'][$input]=1;$GLOBALS['model_objects'][$active]=['ObjectType'=>2,'ObjectIdent'=>'HeartbeatActive'];$GLOBALS['model_children'][35750]=[$active];$GLOBALS['model_parents'][$input]=90000;$GLOBALS['model_parents'][$target]=90000;
$watchdog->pending=$watchdog->properties=['HeartbeatEnabled'=>true,'ResetDelayMs'=>2000,'HeartbeatInputVariableID'=>$input,'WatchList'=>json_encode([['Active'=>true,'Name'=>'Module 3 Intrusion Server','OutputVariableID'=>$target]])];
$lab=FifoLab::install('production_size');$snapshot=[$watchdog->pending,$watchdog->properties,$production->pending,$production->properties,GetValue($input),GetValue($target)];
$prepared=FifoHeartbeatLab::prepare($lab);heartbeatLabCheck(count($prepared['required_targets'])===2 && empty($GLOBALS['ordinary_heartbeat_requests']),'Preflight is read-only and never sends heartbeat');
$production->pending['EnableInputFifo']=true;$blocked=false;try{FifoHeartbeatLab::prepare($lab);}catch(RuntimeException $e){$blocked=true;}heartbeatLabCheck($blocked,'Production experimental flags block test');$production->pending['EnableInputFifo']=false;
$production->attributes['FifoOwned']=true;$blocked=false;try{FifoHeartbeatLab::prepare($lab);}catch(RuntimeException $e){$blocked=true;}heartbeatLabCheck($blocked,'Actually enabled production FIFO blocks test even when requested flags are off');$production->attributes['FifoOwned']=false;
$GLOBALS['variables'][$active]=false;$blocked=false;try{FifoHeartbeatLab::prepare($lab);}catch(RuntimeException $e){$blocked=true;}heartbeatLabCheck($blocked,'Disabled runtime heartbeat blocks request');$GLOBALS['variables'][$active]=true;
$GLOBALS['variables'][$input]=100;$blocked=false;try{FifoHeartbeatLab::prepare($lab);}catch(RuntimeException $e){$blocked=true;}heartbeatLabCheck($blocked,'Existing pulse is not overwritten by preflight');$GLOBALS['variables'][$input]=0;
$GLOBALS['heartbeat_history_raw']=str_repeat('x',65537);$blocked=false;try{FifoHeartbeatLab::prepare($lab);}catch(RuntimeException $e){$blocked=true;}heartbeatLabCheck($blocked,'Oversized history refused');unset($GLOBALS['heartbeat_history_raw']);
$watchdog->pending['ResetDelayMs']=4000;$watchdog->properties['ResetDelayMs']=4000;$blocked=false;try{FifoHeartbeatLab::prepare($lab);}catch(RuntimeException $e){$blocked=true;}heartbeatLabCheck($blocked,'Excessive ordinary pulse duration refused');$watchdog->pending['ResetDelayMs']=$watchdog->properties['ResetDelayMs']=2000;
SetValue($lab['scenario'],'heartbeat_overlap');ob_start();FifoLab::execute($lab['root'],['SENDER'=>'Execute']);$r=json_decode(ob_get_clean(),true);
heartbeatLabCheck(($GLOBALS['ordinary_heartbeat_requests']??[])===[35750],'Scenario requests normal RunCycle once without direct SendHeartbeat/input write');
heartbeatLabCheck(!isset($r['error']) && $r['changed_write_count_matches_processed'] && $r['processed_delta']===70,'Mock scenario preserves seventy isolated changed writes');
heartbeatLabCheck(!$r['heartbeat_overlap']['passed'] && $r['comparison_incomplete'],'Synchronous mock producers do not falsely establish real overlap');
heartbeatLabCheck(!$r['after_disable']['enabled'] && !$r['after_disable']['test']['active'],'Scenario disables lab after request');
heartbeatLabCheck($snapshot===[$watchdog->pending,$watchdog->properties,$production->pending,$production->properties,GetValue($input),GetValue($target)],'Helper leaves production settings and input/output variables unchanged');
heartbeatLabCheck($GLOBALS['calls']===[],'No lab alarm payload dispatch');
heartbeatLabCheck(isset($r['producers'][0]['started_ns'],$r['producers'][0]['ended_ns']),'Producer intervals recorded only in final completion status');
$GLOBALS['throw_ordinary_heartbeat']=true;ob_start();FifoLab::execute($lab['root'],['SENDER'=>'Execute']);$errorResult=json_decode(ob_get_clean(),true);unset($GLOBALS['throw_ordinary_heartbeat']);
heartbeatLabCheck(!$errorResult['heartbeat_overlap']['passed'] && isset($errorResult['heartbeat_overlap']['request']['request_error']) && !$errorResult['after_disable']['enabled'],'Normal cycle exception is explicit, cannot pass and still cleans lab');
$oldRequests=count($GLOBALS['ordinary_heartbeat_requests']);$GLOBALS['on_lab_sleep']=function($ms)use($lab){if($ms===25){unset($GLOBALS['on_lab_sleep']);FifoLab::execute($lab['root'],['SENDER'=>'RunScript','lab_action'=>'stop']);}};
ob_start();FifoLab::execute($lab['root'],['SENDER'=>'Execute']);$cancelled=json_decode(ob_get_clean(),true);
heartbeatLabCheck(count($GLOBALS['ordinary_heartbeat_requests'])===$oldRequests && isset($cancelled['error']) && !$cancelled['after_disable']['enabled'],'Cancellation before request prevents extra production cycle and cleans up');
echo "FIFO heartbeat lab: {$checks} checks passed. API spies and clock fixtures do not prove native overlap.\n";
