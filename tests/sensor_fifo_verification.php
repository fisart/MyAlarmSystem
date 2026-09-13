<?php
declare(strict_types=1);
ob_start();require __DIR__.'/sensor_fifo_lab.php';ob_end_clean();
$checks=0;
function verificationCheck(bool $ok,string $why):void { global $checks;++$checks;if(!$ok)throw new RuntimeException('Verification: '.$why); }
$lab=FifoLab::install('production_size');$mod=$GLOBALS['objects'][$lab['module']];
$selection=json_encode(['sources'=>array_intersect_key($lab['inputs'],FifoLab::INPUTS),'classes'=>['lab-Door','lab-Count','lab-Once','lab-Token']],JSON_THROW_ON_ERROR);
$blocked=false;try{$mod->StartFifoVerification($selection);}catch(RuntimeException $e){$blocked=true;}
verificationCheck($blocked,'Disabled lab refuses capture');
SetValue($lab['scenario'],'rule_verification');
$GLOBALS['lab_defer_native']=true;$GLOBALS['lab_inputs']=array_values($lab['inputs']);$GLOBALS['lab_active']=$lab['active'];$GLOBALS['lab_done']=$lab['done'];
ob_start();FifoLab::execute($lab['root'],['SENDER'=>'Execute']);$result=json_decode(ob_get_clean(),true,64,JSON_THROW_ON_ERROR);unset($GLOBALS['lab_defer_native']);
verificationCheck(!isset($result['error']) && !$result['comparison_incomplete'],'Nine rapid writes complete with delayed native callbacks');
verificationCheck($result['rule_verification']['passed'],'Independent fixed-plan semantic expectations pass');
verificationCheck(count($result['rule_verification']['assertions'])===10 && !in_array(false,$result['rule_verification']['assertions'],true),'All ten assertions reported');
verificationCheck($result['fifo_after']['verification']['covers_processed_snapshot'],'Capture covers completed current prefix');
verificationCheck(!$result['after_disable']['verification']['is_current_baseline'] && !$result['after_disable']['enabled'],'Disable retains historical evidence without current coverage');
verificationCheck(count($result['fifo_after']['verification']['records'])===9,'Captures nine frames without ring overwrite');
verificationCheck(strlen($mod->buffers['InputFifoVerification'])<=24576 && strlen(GetValue($lab['result']))<=65536,'Capture and final report respect byte bounds');
verificationCheck($GLOBALS['calls']===[],'No alarm receivers called');
foreach(['seq','previous','value','values','counts','conditions','pulses','active_classes']as$field){
    $bad=$result['fifo_after'];
    switch($field){
        case 'seq':$bad['verification']['records'][0]['seq']=999;break;
        case 'previous':$bad['verification']['records'][0]['previous']=true;break;
        case 'value':$bad['verification']['records'][0]['value']=false;break;
        case 'values':$bad['verification']['records'][0]['values']['Door']=false;break;
        case 'counts':$bad['verification']['records'][2]['counts']['lab-Count']=999;break;
        case 'conditions':$bad['verification']['records'][5]['conditions']['Once']=false;break;
        case 'pulses':$bad['verification']['records'][7]['pulses']['Token']=0;break;
        case 'active_classes':$bad['verification']['records'][0]['active_classes']=[];break;
    }
    verificationCheck(!FifoLab::verifyRules($lab,$bad)['passed'],'Independent verifier rejects corrupted '.$field);
}
$bad=$result['fifo_after'];array_pop($bad['verification']['records']);verificationCheck(!FifoLab::verifyRules($lab,$bad)['passed'],'Missing frame fails verification');
$bad=$result['fifo_after'];$bad['verification']['covers_processed_snapshot']=false;verificationCheck(!FifoLab::verifyRules($lab,$bad)['passed'],'Stale coverage fails verification');
// Ordinary diagnostic scenario does not reuse a prior capture under a new baseline.
SetValue($lab['scenario'],'sequential');ob_start();FifoLab::execute($lab['root'],['SENDER'=>'Execute']);$ordinary=json_decode(ob_get_clean(),true);
verificationCheck(!isset($ordinary['rule_verification']) && !$ordinary['fifo_after']['verification']['is_current_baseline'],'Explicit capture does not activate on ordinary subsequent run');
// Metrics can finish before the optional capture write; retain the existing bounded wait.
SetValue($lab['scenario'],'rule_verification');$coverageReads=0;
$GLOBALS['on_lab_fifo_report']=function($id,$raw)use($lab,&$coverageReads){$r=json_decode($raw,true);if($id===$lab['module'] && ($r['verification']['is_current_baseline']??false) && ($r['metrics']['processed']??0)===9){++$coverageReads;if($coverageReads===1){$r['verification']['covers_processed_snapshot']=false;return json_encode($r);}}return $raw;};
ob_start();FifoLab::execute($lab['root'],['SENDER'=>'Execute']);$coverageResult=json_decode(ob_get_clean(),true);unset($GLOBALS['on_lab_fifo_report']);
verificationCheck($coverageReads>=2 && $coverageResult['rule_verification']['passed'] && !$coverageResult['comparison_incomplete'],'Existing bounded drain wait rejects prematurely ready metrics until capture coverage arrives');
// Start/refusal and capacity gates on an explicitly enabled lab.
IPS_SetProperty($lab['module'],'EnableInputFifo',true);IPS_SetProperty($lab['module'],'EnableFifoFailureCapture',true);IPS_ApplyChanges($lab['module']);
$GLOBALS['semaphore_busy']['Mod1_InputWorker_'.$lab['module']]=true;verificationCheck(!$mod->StartFifoVerification($selection),'Busy worker refuses reset');unset($GLOBALS['semaphore_busy']['Mod1_InputWorker_'.$lab['module']]);
$blocked=false;try{$mod->StartFifoVerification(str_repeat('x',2049));}catch(RuntimeException $e){$blocked=true;}verificationCheck($blocked,'Oversized selection rejected');
$badSelection=json_encode(['sources'=>['Outside'=>23172],'classes'=>['lab-Door']]);$blocked=false;try{$mod->StartFifoVerification($badSelection);}catch(RuntimeException $e){$blocked=true;}verificationCheck($blocked,'External source rejected');
$rootObject=$GLOBALS['model_objects'][$lab['root']];$GLOBALS['model_objects'][$lab['root']]['ObjectIdent']='Production';$blocked=false;try{$mod->StartFifoVerification($selection);}catch(RuntimeException $e){$blocked=true;}verificationCheck($blocked,'Production category refuses capture');$GLOBALS['model_objects'][$lab['root']]=$rootObject;
verificationCheck($mod->StartFifoVerification($selection),'Explicit isolated idle lab starts capture');
for($i=0;$i<18;++$i)SetValue($lab['inputs']['Door'],$i%2===0);
verificationCheck(!$mod->StartFifoVerification($selection),'Queued prefix cannot be erased by capture reset');
for($i=0;$i<30 && $mod->attributes['FifoHasPending'];++$i)$mod->RunInputFifo();
$r=json_decode($mod->GetInputFifoReport(),true);verificationCheck($r['metrics']['processed']===18 && $r['fault']==='','Capture frame limit does not fault or drop evaluator work');
verificationCheck(count($r['verification']['records'])===16 && !$r['verification']['complete'] && !$r['verification']['covers_processed_snapshot'],'Capture stops retaining frames at sixteen and fails coverage');
// An optional persistence error must not fault, replay or hide missing evidence.
verificationCheck($mod->StartFifoVerification($selection),'Next explicit capture can start after drained queue');
$auditBefore=$mod->buffers['InputFifoVerification'];$GLOBALS['on_set_buffer']=function($o,$name,$value)use($auditBefore){if($name==='InputFifoVerification'){unset($GLOBALS['on_set_buffer']);$o->buffers[$name]=$auditBefore;throw new RuntimeException('Injected optional capture write failure');}};
SetValue($lab['inputs']['Door'],true);$mod->RunInputFifo();$r=json_decode($mod->GetInputFifoReport(),true);
verificationCheck($r['metrics']['processed']===19 && $r['fault']==='' && $mod->buffers['InputFifoVerification']===$auditBefore,'Optional write failure preserves evaluator progress and prior evidence');
verificationCheck(!$r['verification']['covers_processed_snapshot'],'Missing batch write is visibly uncovered');
SetValue($lab['inputs']['Door'],false);$mod->RunInputFifo();$r=json_decode($mod->GetInputFifoReport(),true);
verificationCheck($r['metrics']['processed']===20 && !$r['verification']['covers_processed_snapshot'],'Later summary cannot conceal omitted capture frame');
echo "FIFO verification: {$checks} checks passed. Mock execution is not native semantic evidence.\n";
