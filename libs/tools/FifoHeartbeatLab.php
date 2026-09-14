<?php
declare(strict_types=1);

/** One ordinary production watchdog cycle, correlated with bounded isolated lab producer intervals. */
final class FifoHeartbeatLab
{
    public const WATCHDOG = 35750;
    public const PRODUCTION_MODULE = 23172;
    private const WATCHDOG_MODULE = '{02B51F4D-6B3F-4CB5-A9D5-2F1F123C646B}';

    private static function history(): array
    {
        $raw=AHW_GetHeartbeatHistory(self::WATCHDOG);
        if(strlen($raw)>65536)throw new RuntimeException('Heartbeat history outside lab read bound.');
        $history=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
        if(!is_array($history) || count($history)>10)throw new RuntimeException('Unexpected watchdog history schema.');
        return $history;
    }

    public static function prepare(array $lab): array
    {
        if(!function_exists('AHW_RunCycle') || !function_exists('AHW_GetHeartbeatHistory')
            || (IPS_GetInstance(self::WATCHDOG)['ModuleInfo']['ModuleID']??'')!==self::WATCHDOG_MODULE
            || (IPS_GetInstance(self::PRODUCTION_MODULE)['ModuleInfo']['ModuleID']??'')!==FifoLab::MODULE)throw new RuntimeException('Expected production watchdog/Module 1 APIs or instances unavailable.');
        foreach(['EnableInputFifo','EnableFifoShadow','EnableFifoFailureCapture']as$key)if(IPS_GetProperty(self::PRODUCTION_MODULE,$key))throw new RuntimeException('Keep production FIFO/shadow/failure capture OFF for this lab.');
        if(IPS_HasChanges(self::WATCHDOG) || IPS_HasChanges(self::PRODUCTION_MODULE))throw new RuntimeException('Production/watchdog has unapplied changes; do not change them during this lab.');
        $productionRaw=MYALARM_GetInputFifoReport(self::PRODUCTION_MODULE);
        if(strlen($productionRaw)>32768)throw new RuntimeException('Production FIFO report outside read bound.');
        $production=json_decode($productionRaw,true,32,JSON_THROW_ON_ERROR);
        if(($production['enabled']??true) || ($production['test']['active']??true))throw new RuntimeException('Production FIFO/test remains actually active; wait for its pending Disable before this lab.');
        if(!IPS_GetProperty(self::WATCHDOG,'HeartbeatEnabled'))throw new RuntimeException('Production heartbeat disabled.');
        $pulse=IPS_GetProperty(self::WATCHDOG,'ResetDelayMs');
        if(!is_int($pulse) || $pulse<0 || $pulse>3000)throw new RuntimeException('Normal heartbeat pulse exceeds this supervised lab bound.');
        $active=[];
        $children=IPS_GetChildrenIDs(self::WATCHDOG);
        if(count($children)>128)throw new RuntimeException('Watchdog child inventory outside read bound.');
        foreach($children as$id)if((IPS_GetObject($id)['ObjectIdent']??'')==='HeartbeatActive')$active[]=$id;
        if(count($active)!==1 || (IPS_GetVariable($active[0])['VariableType']??-1)!==0 || GetValue($active[0])!==true)throw new RuntimeException('Production heartbeat is not effectively active.');
        $input=IPS_GetProperty(self::WATCHDOG,'HeartbeatInputVariableID');
        if(!is_int($input) || $input<=0 || !IPS_VariableExists($input) || (IPS_GetVariable($input)['VariableType']??-1)!==1
            || IPS_GetParent($input)===$lab['root'] || GetValue($input)!==0)throw new RuntimeException('Production heartbeat input missing, lab-owned or already pulsing; inspect before retrying.');
        $raw=IPS_GetProperty(self::WATCHDOG,'WatchList');
        if(strlen($raw)>16384)throw new RuntimeException('Watch list outside lab read bound.');
        $watches=json_decode($raw,true,16,JSON_THROW_ON_ERROR);$names=['Module 1 callback'];
        if(!is_array($watches) || count($watches)>8)throw new RuntimeException('Unexpected production watch list.');
        foreach($watches as$row)if($row['Active']??false){
            $id=$row['OutputVariableID']??0;$name=$row['Name']??'';
            if(!is_int($id) || $id<=0 || !IPS_VariableExists($id) || (IPS_GetVariable($id)['VariableType']??-1)!==1 || IPS_GetParent($id)===$lab['root'] || !is_string($name) || $name==='' || strlen($name)>128)throw new RuntimeException('Production watch target missing or lab-owned.');
            $names[]=$name;
        }
        if(count($names)<2 || count(array_unique($names))!==count($names))throw new RuntimeException('Production watch names missing or ambiguous.');
        return ['watchdog_id'=>self::WATCHDOG,'production_module_id'=>self::PRODUCTION_MODULE,'required_targets'=>$names,
            'previous_tokens'=>array_column(self::history(),'timestamp'),'pulse_duration_ms'=>$pulse,
            'mode'=>'One extra ordinary AHW_RunCycle; production timer, routes and heartbeat handling unchanged.'];
    }

    private static function dayMs(): int
    {
        $now=microtime(true);
        return (int)date('G',(int)$now)*3600000+(int)date('i',(int)$now)*60000+(int)date('s',(int)$now)*1000+(int)floor(($now-floor($now))*1000);
    }

    public static function relativeMs(int $day,int $reference): int
    {
        $delta=$day-$reference;
        if($delta>43200000)$delta-=86400000;
        elseif($delta< -43200000)$delta+=86400000;
        return $delta;
    }

    public static function invoke(array $context): array
    {
        $before=hrtime(true);$day=self::dayMs();$after=hrtime(true);
        $context['reference_ns']=(int)(($before+$after)/2);$context['reference_day_ms']=$day;
        $context['reference_date']=date('Y-m-d');$context['reference_timezone']=date_default_timezone_get();
        $context['mapping_uncertainty_ms']=1+($after-$before)/1000000;
        // Use the normal watchdog's own cycle lock and token/reset path; never write its input directly.
        try { AHW_RunCycle(self::WATCHDOG); }
        catch(Throwable $e) { $context['request_error']=json_decode(json_encode(substr($e->getMessage(),0,512),JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR),true); }
        $context['returned_ns']=hrtime(true);$context['returned_day_ms']=self::dayMs();
        $context['clock_consistent']=abs(self::relativeMs($context['returned_day_ms'],$day)-($context['returned_ns']-$context['reference_ns'])/1000000)<20
            && date_default_timezone_get()===$context['reference_timezone'];
        return $context;
    }

    /** No repeat cycle, history polling or claim based solely on coordinator start/end. */
    public static function finish(array $context,array $labResult): array
    {
        $new=[];
        foreach(self::history()as$entry)if(is_array($entry) && !in_array($entry['timestamp']??null,$context['previous_tokens'],true))$new[]=$entry;
        $assessment=self::assess($context,$labResult,$new);
        $assessment['request']=$context;unset($assessment['request']['previous_tokens']);
        $assessment['new_heartbeat_entries']=array_slice($new,0,3);
        return $assessment;
    }

    public static function assess(array $context,array $labResult,array $entries): array
    {
        $uncertainty=$context['mapping_uncertainty_ms']??99;
        $clock=($context['clock_consistent']??false) && is_numeric($uncertainty) && $uncertainty>=1 && $uncertainty<=5
            && is_int($context['reference_ns']??null) && is_int($context['reference_day_ms']??null)
            && $context['reference_day_ms']>=0 && $context['reference_day_ms']<86400000;
        $intervals=[];
        foreach($labResult['producers']??[]as$p)if(($p['completed']??false) && ($p['writes']??0)>0 && is_int($p['started_ns']??null) && is_int($p['ended_ns']??null) && $p['ended_ns']>$p['started_ns'])$intervals[]=[$p['started_ns'],$p['ended_ns']];
        $assessed=[];$passed=false;$definiteFailure=false;$overlapPending=false;
        foreach(array_slice($entries,0,3)as$entry){
            $token=$entry['timestamp']??null;$sent=$entry['sent_at']??null;$overlap=false;$health=true;$pending=false;$terminal=false;
            $valid=is_int($token) && $token>0 && $token<86400000 && $sent===$token;
            if($clock && $valid){
                $ns=$context['reference_ns']+self::relativeMs($sent,$context['reference_day_ms'])*1000000;
                $margin=$context['mapping_uncertainty_ms']*1000000;
                foreach($intervals as[$start,$end])if($ns-$margin>=$start && $ns+$margin<=$end)$overlap=true;
            }
            foreach($context['required_targets']??[]as$name){
                $t=$entry['targets'][$name]??[];$state=$t['state']??'PENDING';$updated=$t['updated']??null;$runtime=$t['runtime_ms']??null;
                $ok=$valid && $state==='OK' && ($t['value']??null)===$token && is_int($updated) && $updated>=0 && $updated<86400000
                    && is_int($runtime) && $runtime>=0 && $runtime===(($updated-$token+86400000)%86400000);
                $health=$health && $ok;
                $pending=$pending || in_array($state,['SENT','PENDING','WAITING'],true);
                $terminal=$terminal || in_array($state,['MISSING','LATE','ERROR','VARIABLE_MISSING'],true);
            }
            $ok=$overlap && $health && ($entry['overall_state']??'')==='OK';$passed=$passed || $ok;
            if($overlap && ($terminal || (!$health && !$pending)))$definiteFailure=true;
            if($overlap && $pending)$overlapPending=true;
            $assessed[]=['timestamp'=>$token,'sent_during_measured_producer_execution'=>$overlap,'all_required_targets_ok'=>$health,'pending'=>$pending];
        }
        $labOk=!($labResult['comparison_incomplete']??true) && ($labResult['fifo_after']['fault']??'unknown')===''
            && !($labResult['fifo_after']['test']['paused']??true);
        $requestOk=!isset($context['request_error']);
        $passed=$passed && $requestOk && !$definiteFailure && !$overlapPending && $clock && $labOk && count($entries)<=3 && count($context['required_targets']??[])>=2;
        return ['passed'=>$passed,'status'=>$passed?'passed':(($definiteFailure || !$requestOk)?'failed':'inconclusive'),
            'clock_mapping_valid'=>$clock,'ordinary_cycle_call_completed'=>$requestOk,'lab_accounting_complete'=>$labOk,'cycles'=>$assessed,
            'scope'=>'Heartbeat send during measured lab producer execution plus correlated target confirmations; not CPU/RAM, continuous CPU overlap or production FIFO routing proof.'];
    }
}
