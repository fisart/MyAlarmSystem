<?php
declare(strict_types=1);
require_once __DIR__.'/FifoHeartbeatLab.php';

/** Native inputs exercise the actual Module 1 FIFO, without any dispatch targets. */
final class FifoLab
{
    public const IDENT = 'MyAlarmFifoLab';
    public const LOAD_IDENT = 'MyAlarmFifoLoadLab';
    public const TEMPERATURE_IDENT = 'MyAlarmFifoTemperatureLab';
    public const TEMPERATURE_SCENARIOS = ['temperature_simple', 'temperature_refresh', 'temperature_parallel'];
    public const MODULE = '{43FBB397-C412-41B2-192E-97054481316D}';
    public const SCENARIOS = ['sequential', 'interleaved', 'concurrent_flicker', 'refresh_noise', 'baseline_race', 'admission_contention', 'rule_verification', 'heartbeat_overlap'];
    public const INPUTS = ['Door'=>0, 'NoiseA'=>0, 'NoiseB'=>0, 'NoiseC'=>0, 'Token'=>1, 'Count'=>0, 'Once'=>0, 'Reference'=>0];

    private static function rootIdent(string $profile): string
    {
        return match ($profile) { 'small'=>self::IDENT, 'production_size'=>self::LOAD_IDENT, 'temperature'=>self::TEMPERATURE_IDENT,
            default=>throw new RuntimeException('Unknown lab profile.') };
    }

    public static function inputTypes(string $profile = 'small'): array
    {
        self::rootIdent($profile);
        $types=self::INPUTS;
        if($profile==='temperature')$types['Temperature']=2;
        if($profile==='production_size') {
            for($i=1;$i<=363;++$i)$types[sprintf('Load%03d',$i)]=0;
            for($i=1;$i<=6;++$i)$types['Usage'.$i]=1;
        }
        return $types;
    }

    private static function child(string $ident, int $parent): int
    {
        foreach (IPS_GetChildrenIDs($parent) as $id) if ((IPS_GetObject($id)['ObjectIdent'] ?? '') === $ident) return $id;
        return 0;
    }

    private static function create(string $ident, int $parent, int $type, callable $factory): int
    {
        $id = self::child($ident, $parent);
        if ($id) {
            if ((IPS_GetObject($id)['ObjectType'] ?? -1) !== $type) throw new RuntimeException('Lab identifier has an unexpected object type: ' . $ident);
            return $id;
        }
        $id = $factory(); IPS_SetParent($id, $parent); IPS_SetIdent($id, $ident); IPS_SetName($id, $ident);
        return $id;
    }

    public static function install(string $profile = 'small'): array
    {
        if (!function_exists('MYALARM_GetInputFifoReport')) throw new RuntimeException('Load the FIFO diagnostic branch before installing the lab.');
        $ident=self::rootIdent($profile);$types=self::inputTypes($profile);
        $existing=self::child($ident,0);
        if($existing){$manifest=self::load($existing);self::validate($manifest);self::updateScriptPaths($manifest);return $manifest;}
        $root = self::create($ident, 0, 0, static fn() => IPS_CreateCategory());
        $manifestID = self::create('Manifest', $root, 2, static fn() => IPS_CreateVariable(3));
        if (GetValue($manifestID) !== '') { $manifest=self::load($root); self::validate($manifest); return $manifest; }
        $inputs=[];
        foreach ($types as $name=>$type) $inputs[$name]=self::create($name,$root,2,static fn()=>IPS_CreateVariable($type));
        foreach ($inputs as $name=>$id) SetValue($id,$types[$name]===2 ? 54.0 : ($types[$name]===1 ? 0 : false));
        $active=self::create('ActiveSession',$root,2,static fn()=>IPS_CreateVariable(3));
        $result=self::create('Result',$root,2,static fn()=>IPS_CreateVariable(3));
        $scenario=self::create('Scenario',$root,2,static fn()=>IPS_CreateVariable(3));
        $done=[]; for($i=0;$i<3;++$i) $done[]=self::create('Producer'.$i,$root,2,static fn()=>IPS_CreateVariable(3));
        $runner=self::create('RunScenario',$root,3,static fn()=>IPS_CreateScript(0));
        $stop=self::create('StopScenario',$root,3,static fn()=>IPS_CreateScript(0));
        $module=self::create('Module1Test',$root,1,static fn()=>IPS_CreateInstance(self::MODULE));
        // Only newly installed lab state is initialized. Existing installation is read-only above.
        IPS_SetScriptContent($runner,"<?php\nrequire_once " . var_export(__FILE__,true) . ";\nFifoLab::execute(".$root.", \$_IPS);\n");
        IPS_SetScriptContent($stop,"<?php\nrequire_once " . var_export(__FILE__,true) . ";\nFifoLab::execute(".$root.", ['SENDER'=>'RunScript','lab_action'=>'stop']);\n");
        SetValue($scenario,$profile==='temperature'?'temperature_simple':'sequential'); SetValue($active,''); SetValue($result,'');
        $config=self::configuration($inputs,$profile);
        foreach ($config as $name=>$value) IPS_SetProperty($module,$name,is_array($value)?json_encode($value,JSON_THROW_ON_ERROR):$value);
        foreach (['EnableInputFifo','EnableFifoFailureCapture','EnableFifoShadow','DebugMode','EnableTrafficDiagnostics'] as $name) IPS_SetProperty($module,$name,false);
        IPS_ApplyChanges($module);
        $manifest=['schema'=>1,'root'=>$root,'module'=>$module,'inputs'=>$inputs,'active'=>$active,'result'=>$result,'scenario'=>$scenario,'done'=>$done,'runner'=>$runner,'stop'=>$stop];
        if($profile!=='small')$manifest['profile']=$profile;
        SetValue($manifestID,json_encode($manifest,JSON_THROW_ON_ERROR)); self::validate($manifest);
        return $manifest;
    }

    /** Repair only our exact previously generated scripts, without resetting lab state. */
    private static function updateScriptPaths(array $m): void
    {
        $legacyFile = dirname(__DIR__, 2) . '/tools/FifoLab.php';
        $templates = [
            $m['runner'] => "FifoLab::execute(".$m['root'].", \$_IPS);\n",
            $m['stop'] => "FifoLab::execute(".$m['root'].", ['SENDER'=>'RunScript','lab_action'=>'stop']);\n"
        ];
        $updates = [];
        foreach ($templates as $id => $tail) {
            $current = "<?php\nrequire_once " . var_export(__FILE__, true) . ";\n" . $tail;
            $existing = IPS_GetScriptContent($id);
            if ($existing === $current) continue;
            $legacy = "<?php\nrequire_once " . var_export($legacyFile, true) . ";\n" . $tail;
            if ($existing !== $legacy) throw new RuntimeException('Lab script was customized; refusing to overwrite it.');
            $updates[$id] = $current;
        }
        if (!$updates) return;
        if (GetValue($m['active']) !== '' || self::report($m)['enabled']) throw new RuntimeException('Disable and finish the lab run before updating script paths.');
        $lock = 'MyAlarmFifoLabRun_' . $m['root'];
        if (!IPS_SemaphoreEnter($lock, 1)) throw new RuntimeException('Lab run is busy; retry script-path update after it finishes.');
        try {
            if (GetValue($m['active']) !== '' || self::report($m)['enabled']) throw new RuntimeException('Lab run became active; refusing script-path update.');
            foreach ($updates as $id => $content) IPS_SetScriptContent($id, $content);
        } finally { IPS_SemaphoreLeave($lock); }
    }

    public static function configuration(array $inputs, string $profile = 'small'): array
    {
        self::rootIdent($profile);
        $config=array_fill_keys(['ClassList','GroupList','SensorList','BedroomList','GroupMembers','TamperList','DispatchTargets','GroupDispatch'],[]);
        foreach (['Door','NoiseA','NoiseB','NoiseC','Token','Count','Once'] as $name) {
            $cid='lab-'.$name; $config['ClassList'][]=['ClassID'=>$cid,'ClassName'=>$name,'LogicMode'=>$name==='Count'?2:0,'Threshold'=>2,'TimeWindow'=>10];
            $config['SensorList'][]=['ClassID'=>$cid,'VariableID'=>$inputs[$name],'Operator'=>0,'ComparisonValue'=>'1','TriggerMode'=>$name==='Token'?1:($name==='Once'?2:0),'PulseSeconds'=>30];
            $config['GroupList'][]=['GroupID'=>'lab-group-'.$name,'GroupName'=>$name,'GroupLogic'=>0];
            $config['GroupMembers'][]=['GroupName'=>$name,'ClassID'=>$cid];
        }
        if($profile==='temperature') {
            $config['ClassList'][]=['ClassID'=>'lab-Temperature','ClassName'=>'Temperature','LogicMode'=>0,'Threshold'=>1,'TimeWindow'=>10];
            $config['SensorList'][]=['ClassID'=>'lab-Temperature','VariableID'=>$inputs['Temperature'],'Operator'=>3,'ComparisonValue'=>'45','TriggerMode'=>2,'PulseSeconds'=>1];
            $config['GroupList'][]=['GroupID'=>'lab-group-Temperature','GroupName'=>'Temperature','GroupLogic'=>0];
            $config['GroupMembers'][]=['GroupName'=>'Temperature','ClassID'=>'lab-Temperature'];
        }
        $config['SensorList'][0]['ComparisonSource']=1; $config['SensorList'][0]['ComparisonVariableID']=$inputs['Reference'];
        if($profile==='production_size') {
            // Generic count-shaped fixture; no production names, IDs, rules or routes.
            for($i=1;$i<=54;++$i)$config['ClassList'][]=['ClassID'=>'lab-load-'.$i,'ClassName'=>'LoadClass'.$i,'LogicMode'=>$i===1?2:($i===2?1:0),'Threshold'=>2,'TimeWindow'=>10];
            for($i=1;$i<=363;++$i)$config['SensorList'][]=['ClassID'=>'lab-load-'.(1+($i-1)%54),'VariableID'=>$inputs[sprintf('Load%03d',$i)],'Operator'=>0,'ComparisonValue'=>'1','TriggerMode'=>0,'PulseSeconds'=>30];
            for($i=1;$i<=22;++$i)$config['SensorList'][]=['ClassID'=>'lab-load-'.(1+$i%54),'VariableID'=>$inputs[sprintf('Load%03d',$i)],'Operator'=>0,'ComparisonValue'=>'1','TriggerMode'=>0,'PulseSeconds'=>30];
            for($i=1;$i<=48;++$i)$config['GroupList'][]=['GroupID'=>'lab-load-group-'.$i,'GroupName'=>'LoadGroup'.$i,'GroupLogic'=>0];
            for($i=1;$i<=54;++$i)$config['GroupMembers'][]=['GroupName'=>'LoadGroup'.(1+($i-1)%48),'ClassID'=>'lab-load-'.$i];
            for($i=1;$i<=8;++$i)$config['GroupMembers'][]=['GroupName'=>'LoadGroup'.(1+$i%48),'ClassID'=>'lab-load-'.$i];
            // Bedroom rules require a real dispatch target; this zero-route lab omits them.
            // Six local integer references retain the extra input inventory without outputs.
            for($i=1;$i<=6;++$i){$config['SensorList'][6+$i]['ComparisonSource']=1;$config['SensorList'][6+$i]['ComparisonVariableID']=$inputs['Usage'.$i];$config['SensorList'][6+$i]['Operator']=2;}
        }
        $config['BedroomTarget']=0; $config['MaintenanceMode']=false; $config['TargetThrottleList']=[];
        return $config;
    }

    private static function load(int $root): array
    {
        if ($root<=0 || !in_array(IPS_GetObject($root)['ObjectIdent']??'',[self::IDENT,self::LOAD_IDENT,self::TEMPERATURE_IDENT],true) || IPS_GetParent($root)!==0) throw new RuntimeException('Invalid lab root.');
        $id=self::child('Manifest',$root); if(!$id)throw new RuntimeException('Run lab installer first.');
        $raw=GetValue($id);if(!is_string($raw)||strlen($raw)>32768)throw new RuntimeException('Lab manifest exceeds its size limit.');
        $m=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
        if(!is_array($m) || ($m['schema']??0)!==1 || ($m['root']??0)!==$root)throw new RuntimeException('Invalid lab manifest.');
        if((IPS_GetObject($root)['ObjectIdent']??'')!==self::rootIdent($m['profile']??'small'))throw new RuntimeException('Lab profile does not match root.');
        return $m;
    }

    /** Every writable object must be a direct child of this dedicated lab. */
    private static function validate(array $m, bool $allowControlDraft = false): void
    {
        $root=$m['root'];
        $profile=$m['profile']??'small';$types=self::inputTypes($profile);
        foreach(['module'=>'Module1Test','active'=>'ActiveSession','result'=>'Result','scenario'=>'Scenario','runner'=>'RunScenario','stop'=>'StopScenario'] as $key=>$ident)if((IPS_GetObject($m[$key]??0)['ObjectIdent']??'')!==$ident)throw new RuntimeException('Lab object identifier mismatch.');
        foreach($m['inputs']??[] as$name=>$id)if((IPS_GetObject($id)['ObjectIdent']??'')!==$name)throw new RuntimeException('Lab input identifier mismatch.');
        foreach($m['done']??[] as$i=>$id)if((IPS_GetObject($id)['ObjectIdent']??'')!=='Producer'.$i)throw new RuntimeException('Lab producer identifier mismatch.');
        foreach (['module','active','result','scenario','runner','stop'] as $key) if(!is_int($m[$key]??null)||IPS_GetParent($m[$key])!==$root) throw new RuntimeException('Lab object ownership mismatch.');
        foreach (['inputs','done'] as $key) foreach($m[$key]??[] as $id) if(!is_int($id)||IPS_GetParent($id)!==$root)throw new RuntimeException('Lab input/status ownership mismatch.');
        if(array_keys($m['inputs'])!==array_keys($types)||count($m['done'])!==3)throw new RuntimeException('Lab input inventory changed.');
        $ids=array_merge([$m['module'],$m['active'],$m['result'],$m['scenario'],$m['runner'],$m['stop']],array_values($m['inputs']),$m['done']);if(count(array_unique($ids))!==count($ids))throw new RuntimeException('Lab writable objects must be distinct.');
        foreach(array_merge([$m['active'],$m['result'],$m['scenario']],$m['done']) as$id)if((IPS_GetVariable($id)['VariableType']??-1)!==3)throw new RuntimeException('Lab status objects must be string variables.');
        if((IPS_GetObject($m['runner'])['ObjectType']??-1)!==3 || (IPS_GetObject($m['stop'])['ObjectType']??-1)!==3)throw new RuntimeException('Lab runner must be a script.');
        foreach($types as $name=>$type) if((IPS_GetVariable($m['inputs'][$name])['VariableType']??-1)!==$type)throw new RuntimeException('Lab input type changed.');
        if((IPS_GetInstance($m['module'])['ModuleInfo']['ModuleID']??'')!==self::MODULE)throw new RuntimeException('Lab instance is not Module 1.');
        $actual=json_decode(MYALARM_GetConfiguration($m['module']),true,64,JSON_THROW_ON_ERROR);
        $expected=self::configuration($m['inputs'],$profile);
        foreach($expected as $key=>$value) if(($actual[$key]??null)!==$value)throw new RuntimeException('Lab configuration changed; refusing to run, including any added dispatch routes.');
        foreach(['VaultInstanceID','BedroomTarget'] as $name) if((int)IPS_GetProperty($m['module'],$name)!==0)throw new RuntimeException('Lab external target must remain zero.');
        foreach($expected as$key=>$value){$raw=IPS_GetProperty($m['module'],$key);$pending=is_array($value)?json_decode($raw,true,64,JSON_THROW_ON_ERROR):$raw;if($pending!==$value)throw new RuntimeException('Pending lab configuration changed; refusing Apply.');}
        foreach(['EnableFifoShadow','DebugMode','EnableTrafficDiagnostics'] as$key)if(IPS_GetProperty($m['module'],$key))throw new RuntimeException('Lab shadow/debug/traffic diagnostics must stay disabled.');
        if(!$allowControlDraft && IPS_HasChanges($m['module']))throw new RuntimeException('Lab has unapplied configuration changes.');
    }

    /** Deliberate input plans, not a duplicate FIFO implementation. Millisecond requests are not real-time guarantees. */
    public static function plan(string $scenario, int $role): array
    {
        if(!in_array($scenario,array_merge(self::SCENARIOS,self::TEMPERATURE_SCENARIOS),true)||$role<0||$role>2)throw new RuntimeException('Unknown scenario/producer.');
        $ops=[];
        if(in_array($scenario,self::TEMPERATURE_SCENARIOS,true)) {
            if($role===0) {
                if($scenario!=='temperature_simple')for($i=0;$i<8;++$i)$ops[]=['Temperature',54.0,25];
                $ops[]=['Temperature',53.0,25];
                if($scenario!=='temperature_simple')for($i=0;$i<4;++$i)$ops[]=['Temperature',53.0,25];
            }
            if($scenario==='temperature_parallel' && $role>0)for($i=0;$i<32;++$i)$ops[]=[$role===1?'NoiseA':'NoiseB',$i%2===0,5];
        } elseif(in_array($scenario,['sequential','rule_verification'],true)) {
            if($role===0)foreach([['Door',true],['Door',false],['Count',true],['Count',false],['Count',true],['Once',true],['Once',false],['Token',71001],['Token',0]] as [$name,$v])$ops[]=[$name,$v,$scenario==='rule_verification'?1:50];
        } elseif($scenario==='interleaved') {
            if($role===0)for($i=0;$i<16;++$i){$ops[]=['NoiseA',$i%2===0,5];$ops[]=['NoiseB',$i%2===0,5];if($i===4||$i===12){$ops[]=['Token',71000+$i,20];$ops[]=['Token',0,5];}}
        } elseif($scenario==='refresh_noise') {
            if($role===0)for($i=0;$i<48;++$i)$ops[]=['NoiseA',false,5];
            if($role===1)foreach([['Token',72001],['Token',0],['Token',72002],['Token',0]]as[$n,$v])$ops[]=[$n,$v,50];
        } else {
            if($role<2)for($i=0;$i<32;++$i)$ops[]=[$role===0?'NoiseA':'NoiseB',$i%2===0,$scenario==='admission_contention'?1:5];
            if($role===2)foreach([['Token',73001],['Token',0],['Door',true],['Door',false],['Token',73002],['Token',0]]as[$n,$v])$ops[]=[$n,$v,25];
        }
        return $ops;
    }

    private static function graphCounts(array $m): array
    {
        $config=self::configuration($m['inputs'],$m['profile']??'small');
        $counts=[];
        foreach(['ClassList','SensorList','GroupList','GroupMembers','BedroomList'] as$key)$counts[$key]=count($config[$key]);
        $counts['unique_sensor_variables']=count(array_unique(array_column($config['SensorList'],'VariableID')));
        $counts['input_variables']=count($m['inputs']);
        return $counts;
    }

    private static function report(array $m): array { return json_decode(MYALARM_GetInputFifoReport($m['module']),true,64,JSON_THROW_ON_ERROR); }
    private static function disabled(array $m): void { self::validate($m,true); IPS_SetProperty($m['module'],'EnableInputFifo',false); IPS_SetProperty($m['module'],'EnableFifoFailureCapture',false); IPS_ApplyChanges($m['module']); }
    private static function waitMs(int $ms): void { if($ms>0)IPS_Sleep($ms); }

    public static function execute(int $root,array $params): void
    {
        $m=self::load($root);$stop=($params['SENDER']??'')==='RunScript' && ($params['lab_action']??'')==='stop';self::validate($m,$stop);
        if(($params['SENDER']??'')==='RunScript' && ($params['lab_action']??'')==='produce') {
            self::produce($m,(string)($params['session']??''),(string)($params['scenario']??''),(int)($params['role']??-1));return;
        }
        if($stop) { SetValue($m['active'],''); self::disabled($m);return; }
        $lock='MyAlarmFifoLabRun_'.$root;
        if(!IPS_SemaphoreEnter($lock,1))throw new RuntimeException('A lab scenario is already running.');
        $session=bin2hex(random_bytes(8));$started=hrtime(true);$heldQueue=false;$report=[];$ownsSession=false;$heartbeat=null;
        try {
            if(GetValue($m['active'])!=='')throw new RuntimeException('Previous lab session is still active; stop it before another run.');
            SetValue($m['active'],$session);$ownsSession=true;
            $allowed=($m['profile']??'small')==='temperature'?self::TEMPERATURE_SCENARIOS:self::SCENARIOS;
            $scenario=(string)GetValue($m['scenario']);if(!in_array($scenario,$allowed,true))throw new RuntimeException('Choose one of: '.implode(', ',$allowed));
            if($scenario==='heartbeat_overlap')$heartbeat=FifoHeartbeatLab::prepare($m);
            // Only the eight scenario inputs are reset. Never flood the load graph.
            foreach(self::INPUTS as$name=>$type)SetValue($m['inputs'][$name],$type===1?0:false);
            if(($m['profile']??'small')==='temperature')SetValue($m['inputs']['Temperature'],54.0);
            SetValue($m['inputs']['Reference'],true);self::disabled($m);self::waitMs(150);
            if(GetValue($m['active'])!==$session)throw new RuntimeException('Lab session cancelled during setup.');
            IPS_SetProperty($m['module'],'EnableInputFifo',true);IPS_SetProperty($m['module'],'EnableFifoFailureCapture',true);IPS_ApplyChanges($m['module']);
            // Deferred baseline is allowed two seconds; poll only for this bounded manual run.
            $deadline=hrtime(true)+2000000000;
            do { $before=self::report($m);if(($before['ready']??false)||($before['test']['paused']??false))break;self::waitMs(25); }while(hrtime(true)<$deadline);
            if(!($before['ready']??false)||($before['test']['paused']??false))throw new RuntimeException('Lab startup did not establish a clean baseline; inspect retained first fault.');
            if(GetValue($m['active'])!==$session)throw new RuntimeException('Lab session cancelled before producer launch.');
            if(in_array($scenario,self::TEMPERATURE_SCENARIOS,true)) {
                if(!MYALARM_StartFifoContinuityDiagnostic($m['module'],$m['inputs']['Temperature']))throw new RuntimeException('Temperature capture requires an idle isolated diagnostic baseline.');
                $before=self::report($m);
            }
            if($scenario==='rule_verification') {
                $selection=['sources'=>array_intersect_key($m['inputs'],self::INPUTS),'classes'=>array_map(static fn($n)=>'lab-'.$n,['Door','NoiseA','NoiseB','NoiseC','Token','Count','Once'])];
                if(!MYALARM_StartFifoVerification($m['module'],json_encode($selection,JSON_THROW_ON_ERROR)))throw new RuntimeException('Lab verification could not acquire an idle baseline; inspect report before retrying.');
                $before=self::report($m);
            }
            foreach($m['done'] as $id)SetValue($id,'');
            if($scenario==='admission_contention') { $heldQueue=IPS_SemaphoreEnter('Mod1_InputQueue_'.$m['module'],1);if(!$heldQueue)throw new RuntimeException('Could not acquire the lab-only admission mutex for this scenario.'); }
            for($role=0;$role<3;++$role){if(GetValue($m['active'])!==$session)throw new RuntimeException('Lab session cancelled during producer launch.');if(!IPS_RunScriptEx($m['runner'],['lab_action'=>'produce','session'=>$session,'scenario'=>$scenario,'role'=>$role]))throw new RuntimeException('Producer launch failed.');}
            if($scenario==='heartbeat_overlap'){self::waitMs(25);if(GetValue($m['active'])!==$session)throw new RuntimeException('Lab cancelled before ordinary heartbeat request.');$heartbeat=FifoHeartbeatLab::invoke($heartbeat);}
            if($heldQueue) { self::waitMs(40); IPS_SemaphoreLeave('Mod1_InputQueue_'.$m['module']);$heldQueue=false; }
            if($scenario==='baseline_race')for($i=0;$i<3;++$i){MYALARM_RecoverInputFifo($m['module']);self::waitMs(10);}
            $deadline=hrtime(true)+4000000000;$producers=[];
            do { $producers=self::producerReports($m,$session);if(count($producers)===3 || GetValue($m['active'])!==$session)break;self::waitMs(25); }while(hrtime(true)<$deadline);
            $changes=array_sum(array_column($producers,'changes'));
            $deadline=hrtime(true)+2000000000;
            do { $after=self::report($m);if(($after['test']['paused']??false)||(($after['metrics']['count']??0)===0 && ($after['metrics']['processed']??0)-($before['metrics']['processed']??0)>=$changes && ($scenario!=='rule_verification' || ($after['verification']['covers_processed_snapshot']??false))))break;self::waitMs(25); }while(hrtime(true)<$deadline);
            $processed=($after['metrics']['processed']??0)-($before['metrics']['processed']??0);
            $report=['schema'=>1,'session'=>$session,'scenario'=>$scenario,'module_id'=>$m['module'],'profile'=>$m['profile']??'small','graph_counts'=>self::graphCounts($m),'mode'=>'isolated native Module 1 FIFO; no dispatch targets','elapsed_ms'=>(hrtime(true)-$started)/1000000,'producers'=>$producers,'expected_changed_writes'=>$changes,'processed_delta'=>$processed,'cancelled'=>GetValue($m['active'])!==$session,'comparison_incomplete'=>GetValue($m['active'])!==$session||$processed!==$changes||count($producers)!==3||in_array(false,array_column($producers,'completed'),true)||($after['test']['paused']??false)||($after['metrics']['recoveries']??0)!==($before['metrics']['recoveries']??0),'changed_write_count_matches_processed'=>count($producers)===3 && !in_array(false,array_column($producers,'completed'),true) && $changes===$processed && !($after['test']['paused']??false) && ($after['metrics']['recoveries']??0)===($before['metrics']['recoveries']??0),'fifo_before'=>$before,'fifo_after'=>$after];
            if(in_array($scenario,self::TEMPERATURE_SCENARIOS,true)) {
                $trace=$after['continuity_diagnostic']??null;
                $samples=$trace['samples']??[];
                $passed=is_array($trace) && !empty($trace['complete']) && !empty($trace['is_current_baseline']) && count($samples)===($scenario==='temperature_simple'?1:13) && GetValue($m['inputs']['Temperature'])===53.0;
                foreach($samples as$sample)$passed=$passed && $sample['prior_matches'] && $sample['stored']['type']==='float' && $sample['previous']['type']==='float' && $sample['current']['type']==='float';
                $report['temperature_check']=['passed'=>$passed,'expected_final_value'=>53.0,'actual_final_value'=>GetValue($m['inputs']['Temperature']),'scope'=>'Native Float input and refresh/parallel admission evidence; not a reconstruction of production callbacks.'];
                if(!$passed)$report['comparison_incomplete']=true;
            }
            if($scenario==='heartbeat_overlap') {
                $report['mode']='Isolated native Module 1 FIFO plus one ordinary production watchdog cycle';
                $report['heartbeat_overlap']=FifoHeartbeatLab::finish($heartbeat,$report);
                if(!$report['heartbeat_overlap']['passed'])$report['comparison_incomplete']=true;
            }
            if($scenario==='rule_verification') {
                $report['rule_verification']=self::verifyRules($m,$after);
                if(!$report['rule_verification']['passed'])$report['comparison_incomplete']=true;
            }
        } catch(Throwable $e) {
            $report=['schema'=>1,'session'=>$session,'comparison_incomplete'=>true,'error'=>substr($e->getMessage(),0,512)];
            try{$report['fifo_after']=self::report($m);}catch(Throwable $ignored){$report['report_unavailable']=true;}
        } finally {
            try {
                if($heldQueue)IPS_SemaphoreLeave('Mod1_InputQueue_'.$m['module']);
                if($ownsSession){
                    if(GetValue($m['active'])===$session)SetValue($m['active'],'');
                    try { self::disabled($m);$report['after_disable']=self::report($m); }catch(Throwable $e){$report['disable_error']=substr($e->getMessage(),0,512);}
                }
                $serialized=json_encode($report,JSON_PRETTY_PRINT|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);
                if(strlen($serialized)>65536)throw new RuntimeException('Lab report exceeded 64 KB; report not persisted.');
                SetValue($m['result'],$serialized);
            } finally { IPS_SemaphoreLeave($lock); }
        }
        echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);
    }

    /** Independent expectations for the fixed nine-write lab plan, not another FIFO engine. */
    public static function verifyRules(array $m,array $report): array
    {
        $audit=$report['verification']??null;$assertions=[];
        $check=static function(string $name,bool $ok)use(&$assertions):void{$assertions[$name]=$ok;};
        if(!is_array($audit))return ['passed'=>false,'assertions'=>['capture_available'=>false]];
        $rows=$audit['records']??[];$ops=self::plan('rule_verification',0);
        $check('complete_current_capture',($audit['complete']??false) && ($audit['covers_processed_snapshot']??false)
            && !($report['test']['paused']??true) && ($report['fault']??'unknown')===''
            && ($audit['sources']??[])===array_intersect_key($m['inputs'],self::INPUTS));
        $check('nine_frames_and_empty_queue',count($rows)===9 && ($report['metrics']['count']??-1)===0 && ($report['metrics']['processed']??-1)-($audit['start_processed']??0)===9);
        $values=array_fill_keys(array_keys(self::INPUTS),false);$values['Token']=0;$values['Reference']=true;
        $check('initial_input_values',($audit['initial_values']??[])===$values);
        $countBuffer=$audit['initial_buffers']['lab-Count']??[];
        $tokenUntil=(int)($audit['initial_pulses'][$m['inputs']['Token']]??0);$onceUntil=0;
        $ordered=true;$mirrors=true;$countCorrect=true;$onceCorrect=true;$tokenCorrect=true;$classesCorrect=true;
        foreach($rows as$i=>$row){
            if(!isset($ops[$i]) || !is_array($row)){ $ordered=false;continue; }
            [$name,$value]=$ops[$i];$prior=$values[$name];$values[$name]=$value;$wall=(int)($row['wall_s']??0);
            $ordered=$ordered && ($row['seq']??null)===($audit['start_seq']??0)+$i && ($row['kind']??'')==='input'
                && ($row['variable_id']??0)===$m['inputs'][$name] && ($row['previous']??null)===$prior && ($row['value']??null)===$value;
            $mirrors=$mirrors && ($row['values']??[])===$values;
            $countBuffer=array_values(array_filter($countBuffer,static fn($ts)=>$wall-$ts<=10));
            if($name==='Count' && $value===true)$countBuffer[]=$wall;
            $countCorrect=$countCorrect && ($row['counts']['lab-Count']??-1)===count($countBuffer);
            if($name==='Once')$onceUntil=$value===true?$wall+30:0;
            if($name==='Token')$tokenUntil=$wall+30;
            $onceCorrect=$onceCorrect && ($row['conditions']['Once']??null)===$values['Once'] && ($row['pulses']['Once']??-1)===$onceUntil;
            $tokenCorrect=$tokenCorrect && ($row['pulses']['Token']??-1)===$tokenUntil;
            $expected=[];
            if($values['Door']===$values['Reference'])$expected[]='lab-Door';
            if(count($countBuffer)>=2)$expected[]='lab-Count';
            if($onceUntil>$wall)$expected[]='lab-Once';
            if($tokenUntil>$wall)$expected[]='lab-Token';
            $actual=$row['active_classes']??[];sort($actual);sort($expected);
            $classesCorrect=$classesCorrect && $actual===$expected;
        }
        $check('input_sequence_and_previous_values',$ordered && count($rows)===9);
        $check('all_selected_mirror_values_per_frame',$mirrors && count($rows)===9);
        $check('count_only_increments_on_two_direct_activations',$countCorrect && count($rows)===9);
        $check('once_pulse_created_and_cleared',$onceCorrect && count($rows)===9);
        $check('token_and_reset_each_preserve_change_pulse',$tokenCorrect && count($rows)===9);
        $check('class_decisions_match_each_frame',$classesCorrect && count($rows)===9);
        $live=[];foreach(self::INPUTS as$name=>$type)$live[$name]=GetValue($m['inputs'][$name]);
        $check('final_native_values_match_plan',$live===$values);
        return ['passed'=>!in_array(false,$assertions,true),'assertions'=>$assertions,
            'scope'=>'Single-producer evaluated decisions; no cross-producer physical order, downstream delivery, pulse-expiry timer or real-heartbeat proof.'];
    }

    private static function producerReports(array $m,string $session): array
    {
        $reports=[];foreach($m['done']as$id){$r=json_decode(GetValue($id),true);if(is_array($r)&&($r['session']??'')===$session)$reports[]=$r;}return $reports;
    }

    private static function produce(array $m,string $session,string $scenario,int $role): void
    {
        if(preg_match('/^[a-f0-9]{16}$/D',$session)!==1 || GetValue($m['active'])!==$session)return;
        $ops=self::plan($scenario,$role);$writes=0;$changes=0;$lateMax=0.0;$started=hrtime(true);$planned=$started;
        $error=null;
        try { foreach($ops as[$name,$value,$delay]) {
            if(GetValue($m['active'])!==$session)break;
            $id=$m['inputs'][$name];if(GetValue($id)!==$value)++$changes;
            SetValue($id,$value);++$writes;
            $planned+=(int)$delay*1000000;$remaining=$planned-hrtime(true);if($remaining>0)self::waitMs((int)min(50,ceil($remaining/1000000)));
            $lateMax=max($lateMax,max(0,(hrtime(true)-$planned)/1000000));
        }
        } catch(Throwable $e) {$error=substr($e->getMessage(),0,512);}
        if(GetValue($m['active'])!==$session)return;
        $ended=hrtime(true);
        SetValue($m['done'][$role],json_encode(['started_ns'=>$started,'ended_ns'=>$ended,'error'=>$error,'session'=>$session,'role'=>$role,'writes'=>$writes,'changes'=>$changes,'completed'=>$error===null && $writes===count($ops),'elapsed_ms'=>($ended-$started)/1000000,'timing_lateness_max_ms'=>$lateMax],JSON_THROW_ON_ERROR));
    }
}
