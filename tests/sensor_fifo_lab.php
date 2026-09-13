<?php
declare(strict_types=1);
require __DIR__.'/symcon_stub.php';
require __DIR__.'/../SensorGroup/module.php';
require __DIR__.'/../PropertyStateManager/module.php';
require __DIR__.'/../libs/tools/FifoLab.php';
$GLOBALS['lab_next']=8000;
function labObject(int $type):int { $id=++$GLOBALS['lab_next'];$GLOBALS['model_objects'][$id]=['ObjectType'=>$type,'ObjectIdent'=>'','ObjectName'=>''];$GLOBALS['objects'][$id]=new IPSModule($id);return $id; }
function IPS_CreateCategory(){return labObject(0);}
function IPS_CreateVariable($type){$id=labObject(2);$GLOBALS['model_variable_types'][$id]=$type;$GLOBALS['variables'][$id]=$type===3?'':($type===1?0:false);return $id;}
function IPS_CreateScript($type){return labObject(3);}
function IPS_CreateInstance($module){$id=labObject(1);$GLOBALS['model_module_ids'][$id]=$module;$m=new SensorGroup($id);$m->Create();return $id;}
function IPS_SetParent($id,$parent){$GLOBALS['model_parents'][$id]=$parent;$GLOBALS['model_children'][$parent][]=$id;}
function IPS_SetIdent($id,$ident){$GLOBALS['model_objects'][$id]['ObjectIdent']=$ident;}
function IPS_SetName($id,$name){$GLOBALS['model_objects'][$id]['ObjectName']=$name;}
function IPS_SetScriptContent($id,$content){$GLOBALS['lab_scripts'][$id]=$content;}
function IPS_GetScriptContent($id){return $GLOBALS['lab_scripts'][$id];}
function SetValue($id,$value){if(isset($GLOBALS['on_lab_write']))($GLOBALS['on_lab_write'])($id,$value);$old=GetValue($id);$GLOBALS['variables'][$id]=$value;if(!empty($GLOBALS['lab_defer_native']) && in_array($id,$GLOBALS['lab_inputs'],true) && GetValue($GLOBALS['lab_active'])!==''){$GLOBALS['lab_deferred'][]=[$id,$value,$old];return;}foreach($GLOBALS['objects']as$m)if($m instanceof SensorGroup && isset($m->messages[$id]))$m->MessageSink(1,$id,VM_UPDATE,[$value,$old!==$value,$old]);}
function MYALARM_GetInputFifoReport($id){return $GLOBALS['objects'][$id]->GetInputFifoReport();}
function MYALARM_RecoverInputFifo($id){$GLOBALS['objects'][$id]->RecoverInputFifo();}
function IPS_RunScriptEx($id,$params){$GLOBALS['lab_launches']=($GLOBALS['lab_launches']??0)+1;$root=IPS_GetParent($id);FifoLab::execute($root,['SENDER'=>'RunScript']+$params);return true;}
function IPS_Sleep($ms){if(isset($GLOBALS['on_lab_sleep']))($GLOBALS['on_lab_sleep'])($ms);if(!empty($GLOBALS['lab_defer_native']) && count(array_filter($GLOBALS['lab_done'],static fn($id)=>GetValue($id)!==''))===3){$pending=$GLOBALS['lab_deferred']??[];$GLOBALS['lab_deferred']=[];foreach($pending as[$id,$v,$old])foreach($GLOBALS['objects']as$mod)if($mod instanceof SensorGroup && isset($mod->messages[$id]))$mod->MessageSink(2,$id,VM_UPDATE,[$v,$old!==$v,$old]);}foreach($GLOBALS['objects']as$m)if($m instanceof SensorGroup)$m->RunInputFifo();}
$checks=0;
function labCheck(bool $ok,string $why):void { global $checks;++$checks;if(!$ok)throw new RuntimeException('FIFO lab: '.$why); }
$m=FifoLab::install();$module=$GLOBALS['objects'][$m['module']];
labCheck(AlarmSafety::validate(FifoLab::configuration($m['inputs']))===[],'Lab config validates without dispatch targets');
labCheck(count($m['inputs'])===8 && count($m['done'])===3,'Installer creates bounded input and producer inventory');
labCheck(!MYALARM_GetInputFifoReport($m['module']) || !json_decode(MYALARM_GetInputFifoReport($m['module']),true)['enabled'],'Installer leaves FIFO disabled');
labCheck(FifoLab::install()===$m,'Repeated installation is read-only and reuses validated lab');
// Match Symcon's documented module-discovery exclusions, not a duplicate FIFO.
$invalid=[];
foreach(new DirectoryIterator(dirname(__DIR__)) as$entry) {
    $name=$entry->getFilename();
    if(!$entry->isDir() || str_starts_with($name,'.') || in_array($name,['libs','docs','imgs','tests','actions'],true)) continue;
    if(!is_file($entry->getPathname().'/module.json')) $invalid[]=$name;
}
labCheck($invalid===[],'Every root directory is a documented helper exception or has module.json');
$scripts=$GLOBALS['lab_scripts'];$legacyFile=dirname(__DIR__).'/tools/FifoLab.php';
foreach([$m['runner'],$m['stop']] as$id) $GLOBALS['lab_scripts'][$id]=str_replace(var_export(realpath(__DIR__.'/../libs/tools/FifoLab.php'),true),var_export($legacyFile,true),$scripts[$id]);
$legacyScripts=$GLOBALS['lab_scripts'];SetValue($m['scenario'],'interleaved');SetValue($m['result'],'saved-result');$beforeInputs=array_map('GetValue',$m['inputs']);$beforeProperties=$module->pending;
labCheck(FifoLab::install()===$m && $GLOBALS['lab_scripts']===$scripts,'Installer repairs exact legacy runner/stop helper paths on existing lab');
labCheck(GetValue($m['result'])==='saved-result' && GetValue($m['scenario'])==='interleaved' && array_map('GetValue',$m['inputs'])===$beforeInputs && $module->pending===$beforeProperties,'Script path repair preserves results/scenario/inputs/configuration and IDs');
labCheck(FifoLab::install()===$m && $GLOBALS['lab_scripts']===$scripts,'Current generated script paths remain unchanged on reinstall');
$GLOBALS['lab_scripts']=$legacyScripts;$GLOBALS['lab_scripts'][$m['stop']]="<?php // custom script\n";$beforeScripts=$GLOBALS['lab_scripts'];$blocked=false;
try{FifoLab::install();}catch(RuntimeException $e){$blocked=true;}
labCheck($blocked && $GLOBALS['lab_scripts']===$beforeScripts,'Customized script refuses migration before either script is overwritten');
$GLOBALS['lab_scripts']=$legacyScripts;SetValue($m['active'],str_repeat('a',16));$blocked=false;
try{FifoLab::install();}catch(RuntimeException $e){$blocked=true;}
labCheck($blocked && $GLOBALS['lab_scripts']===$legacyScripts,'Active session refuses script-path migration');SetValue($m['active'],'');
$GLOBALS['semaphore_busy']['MyAlarmFifoLabRun_'.$m['root']]=true;$blocked=false;
try{FifoLab::install();}catch(RuntimeException $e){$blocked=true;}
labCheck($blocked && $GLOBALS['lab_scripts']===$legacyScripts,'Coordinator ownership refuses migration even before session publication');unset($GLOBALS['semaphore_busy']['MyAlarmFifoLabRun_'.$m['root']]);
$module->attributes['FifoOwned']=true;$blocked=false;try{FifoLab::install();}catch(RuntimeException $e){$blocked=true;}
labCheck($blocked && $GLOBALS['lab_scripts']===$legacyScripts,'Actually enabled FIFO refuses migration even when requested settings are off');$module->attributes['FifoOwned']=false;
FifoLab::install();SetValue($m['scenario'],'sequential');SetValue($m['result'],'');
labCheck($GLOBALS['calls']===[],'Installing lab makes no downstream dispatch calls');
foreach(FifoLab::SCENARIOS as$scenario){$operations=0;$valid=true;for($role=0;$role<3;++$role){$plan=FifoLab::plan($scenario,$role);$operations+=count($plan);foreach($plan as[$name,$v,$delay])$valid=$valid && isset($m['inputs'][$name]) && $delay>=1 && $delay<=50;}labCheck($valid && $operations<=128,'Scenario bounds variable set, delays and write count: '.$scenario);}
$blocked=false;try{FifoLab::plan('invalid',0);}catch(RuntimeException $e){$blocked=true;}labCheck($blocked,'Invalid scenario refuses execution');
$blocked=false;try{FifoLab::plan('sequential',3);}catch(RuntimeException $e){$blocked=true;}labCheck($blocked,'Invalid producer refuses execution');
// Inject an external route: runner must refuse before changing anything.
$before=$module->pending;$module->pending['DispatchTargets']='[{"InstanceID":7000}]';IPS_ApplyChanges($m['module']);
$blocked=false;try{FifoLab::execute($m['root'],['SENDER'=>'Execute']);}catch(RuntimeException $e){$blocked=true;}
labCheck($blocked && $module->pending['EnableInputFifo']===false,'Modified output config cannot start test');
foreach($before as$key=>$v)$module->pending[$key]=$v;IPS_ApplyChanges($m['module']);
// Manifest cannot point any writable input outside lab.
$manifestID=0;foreach(IPS_GetChildrenIDs($m['root'])as$id)if(IPS_GetObject($id)['ObjectIdent']==='Manifest')$manifestID=$id;
$bad=$m;$bad['inputs']['Door']=23172;SetValue($manifestID,json_encode($bad));$blocked=false;
try{FifoLab::execute($m['root'],['SENDER'=>'Execute']);}catch(RuntimeException $e){$blocked=true;}
labCheck($blocked,'Manifest rewrite cannot point at production IDs');SetValue($manifestID,json_encode($m));
$blocked=false;$module->pending['MaintenanceMode']=true;try{FifoLab::execute($m['root'],['SENDER'=>'Execute']);}catch(RuntimeException $e){$blocked=true;}
labCheck($blocked,'Unapplied settings refuse test');$module->pending['MaintenanceMode']=false;
$before=GetValue($m['inputs']['Door']);FifoLab::execute($m['root'],['SENDER'=>'RunScript','lab_action'=>'produce','scenario'=>'sequential','session'=>'','role'=>0]);
labCheck(GetValue($m['inputs']['Door'])===$before,'Forged empty producer session cannot write inputs');
labCheck(!str_contains($GLOBALS['lab_scripts'][$m['runner']],'23172'),'Generated runner binds only lab root');

foreach(['sequential','interleaved','concurrent_flicker','refresh_noise']as$scenario){SetValue($m['scenario'],$scenario);ob_start();FifoLab::execute($m['root'],['SENDER'=>'Execute']);$printed=ob_get_clean();$r=json_decode($printed,true,64,JSON_THROW_ON_ERROR);labCheck(!isset($r['error']) && !$r['comparison_incomplete'] && $r['changed_write_count_matches_processed'],'Mock run preserves changed-write count and comparison completeness: '.$scenario);labCheck(!$r['after_disable']['configured_enabled'] && !$r['after_disable']['enabled'] && GetValue($m['active'])==='','Mock run disables lab FIFO and cancels active session');}
labCheck($GLOBALS['calls']===[],'Scenario generation causes no downstream output dispatch');

SetValue($m['scenario'],'sequential');$launches=$GLOBALS['lab_launches'];
$GLOBALS['on_lab_sleep']=function($ms)use($m){if($ms===150){unset($GLOBALS['on_lab_sleep']);FifoLab::execute($m['root'],['SENDER'=>'RunScript','lab_action'=>'stop']);}};
ob_start();FifoLab::execute($m['root'],['SENDER'=>'Execute']);$r=json_decode(ob_get_clean(),true);
labCheck($GLOBALS['lab_launches']===$launches && $r['comparison_incomplete'] && !$r['after_disable']['enabled'],'Stop during setup prevents activation and producer launch');
// A partially staged enable flag must not prevent a stop request.
$module->pending['EnableInputFifo']=true;SetValue($m['active'],str_repeat('a',16));FifoLab::execute($m['root'],['SENDER'=>'RunScript','lab_action'=>'stop']);
labCheck(GetValue($m['active'])==='' && !json_decode(MYALARM_GetInputFifoReport($m['module']),true)['enabled'],'Stop works during control-only pending window');
// Stale producer completion cannot overwrite next-session status.
SetValue($m['done'][0],'next-session-status');SetValue($m['active'],str_repeat('b',16));
FifoLab::execute($m['root'],['SENDER'=>'RunScript','lab_action'=>'produce','scenario'=>'sequential','session'=>str_repeat('a',16),'role'=>0]);
labCheck(GetValue($m['done'][0])==='next-session-status','Stale producer refuses writes/status completion');SetValue($m['active'],'');
// Even a failure to persist the final report must release the run mutex.
$leaves=0;$GLOBALS['on_semaphore_leave']=function($name)use($m,&$leaves){if($name==='MyAlarmFifoLabRun_'.$m['root'])++$leaves;};$GLOBALS['on_lab_write']=function($id,$v)use($m){if($id===$m['result']){unset($GLOBALS['on_lab_write']);throw new RuntimeException('Injected result persistence failure');}};
$failed=false;try{ob_start();FifoLab::execute($m['root'],['SENDER'=>'Execute']);ob_end_clean();}catch(RuntimeException $e){if(ob_get_level())ob_end_clean();$failed=true;}
labCheck($failed && GetValue($m['active'])==='' && $leaves===1,'Report persistence failure still cancels owned session and releases run semaphore');unset($GLOBALS['on_semaphore_leave']);
ob_start();FifoLab::execute($m['root'],['SENDER'=>'Execute']);$r=json_decode(ob_get_clean(),true);labCheck(!$r['comparison_incomplete'],'Next run remains possible after final report failure');

$GLOBALS['lab_defer_native']=true;$GLOBALS['lab_inputs']=array_values($m['inputs']);$GLOBALS['lab_active']=$m['active'];$GLOBALS['lab_done']=$m['done'];
ob_start();FifoLab::execute($m['root'],['SENDER'=>'Execute']);$r=json_decode(ob_get_clean(),true);unset($GLOBALS['lab_defer_native']);
labCheck(!$r['comparison_incomplete'] && $r['changed_write_count_matches_processed'],'Empty queue before delayed callbacks arrives does not prematurely end capture');
$bad=$m;$bad['result']=$bad['inputs']['Door'];SetValue($manifestID,json_encode($bad));$blocked=false;
try{FifoLab::execute($m['root'],['SENDER'=>'Execute']);}catch(RuntimeException $e){$blocked=true;}
labCheck($blocked,'Diagnostic status cannot alias input variables');SetValue($manifestID,json_encode($m));
// Production-size profile is separate, generic and bounded; it uses only local inputs.
$smallInputs=array_map('GetValue',$m['inputs']);$smallProperties=$module->pending;$smallResult=GetValue($m['result']);
$load=FifoLab::install('production_size');$lc=FifoLab::configuration($load['inputs'],'production_size');
labCheck($load['root']!==$m['root'] && $load['module']!==$m['module'] && IPS_GetObject($load['root'])['ObjectIdent']===FifoLab::LOAD_IDENT,'Load lab is a separate Module 1 instance/root');
labCheck(count($lc['ClassList'])===61 && count($lc['SensorList'])===392 && count($lc['GroupList'])===55 && count($lc['GroupMembers'])===69 && count($lc['BedroomList'])===0,'Load fixture matches graph counts while omitting routed bedrooms');
labCheck(count(array_unique(array_column($lc['SensorList'],'VariableID')))===370 && count($load['inputs'])===377,'Load fixture has 370 unique rule inputs plus reference and six local integer references');
labCheck(AlarmSafety::validate($lc)===[] && $lc['DispatchTargets']===[] && $lc['GroupDispatch']===[] && $lc['BedroomTarget']===0,'Larger graph validates and has no output routes');
labCheck(array_count_values(array_column($lc['ClassList'],'LogicMode'))===[0=>58,2=>2,1=>1],'Generic load has the exported class-mode counts');
labCheck(FifoLab::install('production_size')===$load && !json_decode(MYALARM_GetInputFifoReport($load['module']),true)['enabled'],'Load reinstall preserves IDs and leaves actual FIFO disabled');
labCheck(array_map('GetValue',$m['inputs'])===$smallInputs && $module->pending===$smallProperties && GetValue($m['result'])===$smallResult,'Installing larger lab preserves existing small lab state');
$blocked=false;try{FifoLab::install('invalid');}catch(RuntimeException $e){$blocked=true;}labCheck($blocked,'Unknown load profile refused');
$loadManifest=0;foreach(IPS_GetChildrenIDs($load['root'])as$id)if(IPS_GetObject($id)['ObjectIdent']==='Manifest')$loadManifest=$id;
$bad=$load;$bad['profile']='small';SetValue($loadManifest,json_encode($bad));$blocked=false;
try{FifoLab::execute($load['root'],['SENDER'=>'Execute']);}catch(RuntimeException $e){$blocked=true;}
labCheck($blocked,'Load manifest cannot masquerade as small root');SetValue($loadManifest,json_encode($load));
$loadWrites=0;$GLOBALS['on_lab_write']=function($id,$v)use($load,&$loadWrites){if(in_array($id,array_slice(array_values($load['inputs']),8),true))++$loadWrites;};
ob_start();FifoLab::execute($load['root'],['SENDER'=>'Execute']);$r=json_decode(ob_get_clean(),true);unset($GLOBALS['on_lab_write']);
labCheck(!isset($r['error']) && !$r['comparison_incomplete'] && $r['processed_delta']===9,'Larger lab runs initial nine-change sequential scenario');
labCheck($r['profile']==='production_size' && $r['module_id']===$load['module'] && $r['graph_counts']['SensorList']===392,'Result identifies larger instance/profile and graph size');
labCheck($loadWrites===0,'Scenario setup never rewrites hundreds of filler/usage inputs');
labCheck(!$r['after_disable']['enabled'] && !$r['after_disable']['configured_enabled'] && GetValue($load['active'])==='','Larger lab cleans up to disabled FIFO');
labCheck($r['fifo_after']['diagnostic_timing']['covers_processed_snapshot'],'Larger lab captures diagnostic timing for committed work');
labCheck($GLOBALS['calls']===[],'Larger lab performs no downstream dispatch');
echo "FIFO lab: $checks checks passed. Mock timing is not native concurrency evidence.\n";
