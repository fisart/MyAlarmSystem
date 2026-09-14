<?php
declare(strict_types=1);
ob_start(); require __DIR__.'/sensor_fifo_lab.php'; ob_end_clean();
$checks=0;
function temperatureCheck(bool $ok,string $why):void { global $checks;++$checks;if(!$ok)throw new RuntimeException('Temperature lab: '.$why); }
function MYALARM_StartFifoContinuityDiagnostic($id,$variable){return $GLOBALS['objects'][$id]->StartFifoContinuityDiagnostic($variable);}
$lab=FifoLab::install('temperature'); $mod=$GLOBALS['objects'][$lab['module']];
temperatureCheck($lab['root']!==$m['root'] && $lab['module']!==$m['module'],'Dedicated temperature profile preserves existing small lab');
temperatureCheck(IPS_GetVariable($lab['inputs']['Temperature'])['VariableType']===2 && GetValue($lab['inputs']['Temperature'])===54.0,'Native Float input starts at 54.0');
temperatureCheck(FifoLab::install('temperature')===$lab,'Reinstall preserves existing IDs/configuration');
temperatureCheck(AlarmSafety::validate(FifoLab::configuration($lab['inputs'],'temperature'))===[],'Temperature rule graph valid without output targets');
foreach(FifoLab::TEMPERATURE_SCENARIOS as$scenario){
    SetValue($lab['scenario'],$scenario);ob_start();FifoLab::execute($lab['root'],['SENDER'=>'Execute']);$r=json_decode(ob_get_clean(),true);
    temperatureCheck(!isset($r['error']) && !$r['comparison_incomplete'] && $r['temperature_check']['passed'],'Native Float scenario checks pass: '.$scenario);
    temperatureCheck($r['fifo_after']['metrics']['processed']===($scenario==='temperature_parallel'?65:1),'Refreshes are suppressed, changed Float input and noise processed: '.$scenario);
    $samples=$r['fifo_after']['continuity_diagnostic']['samples'];
    temperatureCheck(count($samples)===($scenario==='temperature_simple'?1:13),'Captured selected source observations include unchanged refreshes: '.$scenario);
    temperatureCheck(!$r['after_disable']['enabled'] && !$r['after_disable']['continuity_diagnostic']['is_current_baseline'],'Lab disables FIFO while preserving explicitly historical trace: '.$scenario);
    temperatureCheck($samples[0]['stored']['value']===54.0 && $samples[0]['previous']['value']===54.0,'Integral Float values survive JSON round trip: '.$scenario);
}
temperatureCheck($GLOBALS['calls']===[],'No downstream dispatch across all temperature runs');
// Failures cannot silently report complete evidence.
$corrupt=$r['fifo_after']['continuity_diagnostic'];
temperatureCheck($corrupt['samples'][8]['current']['value']===53.0 && $corrupt['samples'][8]['native_changed']===true,'Temperature edge is captured after eight refresh observations');
IPS_SetProperty($lab['module'],'EnableInputFifo',true);IPS_SetProperty($lab['module'],'EnableFifoFailureCapture',true);IPS_ApplyChanges($lab['module']);
$mod->RecoverInputFifo();
temperatureCheck($mod->StartFifoContinuityDiagnostic($lab['inputs']['Temperature']),'Explicit capture can start on idle ready lab');
$blocked=false;try{$mod->StartFifoContinuityDiagnostic($lab['inputs']['Door']);}catch(RuntimeException $e){$blocked=true;}
temperatureCheck($blocked,'Boolean source refused');
$blocked=false;try{$mod->StartFifoContinuityDiagnostic(34157);}catch(Throwable $e){$blocked=true;}
temperatureCheck($blocked,'Production sensor refused');
$saved=$GLOBALS['model_objects'][$lab['root']];$GLOBALS['model_objects'][$lab['root']]['ObjectIdent']='Production';
$blocked=false;try{$mod->StartFifoContinuityDiagnostic($lab['inputs']['Temperature']);}catch(Throwable $e){$blocked=true;}
temperatureCheck($blocked,'Production category refused');$GLOBALS['model_objects'][$lab['root']]=$saved;
// Controlled synthetic native payload: proves the diagnostic can distinguish types;
// it is NOT evidence that Symcon actually delivers this payload for Float variables.
$temp=$lab['inputs']['Temperature'];$mod->MessageSink(99,$temp,VM_UPDATE,[52.0,true,53]);
$r=json_decode($mod->GetInputFifoReport(),true);$sample=$r['continuity_diagnostic']['samples'][0];
temperatureCheck($r['test']['paused'] && str_contains($r['test']['first_fault']['reason'],'Prior-value continuity gap'),'Synthetic int/Float mismatch preserves existing fault/pause policy');
temperatureCheck(!$sample['prior_matches'] && $sample['stored']['type']==='float' && $sample['previous']['type']==='int','Fault evidence preserves both exact types');
IPS_SetProperty($lab['module'],'EnableInputFifo',false);IPS_SetProperty($lab['module'],'EnableFifoFailureCapture',false);IPS_ApplyChanges($lab['module']);
// No production diagnostic activation and no diagnostic state written in ordinary mode.
SetValue($temp,54.0);IPS_SetProperty($lab['module'],'EnableInputFifo',true);IPS_ApplyChanges($lab['module']);$mod->RecoverInputFifo();
$raw=$mod->buffers['InputFifoContinuity'];SetValue($temp,53.0);$mod->RunInputFifo();
temperatureCheck($mod->buffers['InputFifoContinuity']===$raw && !$mod->attributes['FifoTestActive'],'Ordinary processing does not capture or rewrite diagnostic buffer');
// Incomplete capture stays bounded even with many unchanged callbacks.
IPS_SetProperty($lab['module'],'EnableInputFifo',false);IPS_ApplyChanges($lab['module']);
IPS_SetProperty($lab['module'],'EnableInputFifo',true);IPS_SetProperty($lab['module'],'EnableFifoFailureCapture',true);IPS_ApplyChanges($lab['module']);$mod->RecoverInputFifo();
temperatureCheck($mod->StartFifoContinuityDiagnostic($temp),'Bound test begins cleanly');
for($i=0;$i<80;++$i)SetValue($temp,53.0);
$r=json_decode($mod->GetInputFifoReport(),true);
temperatureCheck(!$r['continuity_diagnostic']['complete'] && strlen($mod->buffers['InputFifoContinuity'])<=16384 && count($r['continuity_diagnostic']['samples'])<=64,'Trace has bounded bytes/samples and visible incompleteness');
temperatureCheck($r['fault']==='' && !$r['test']['paused'],'Capture capacity never faults normal evaluator');
echo "FIFO temperature: {$checks} checks passed. Mock cases are not production callback evidence.\n";
