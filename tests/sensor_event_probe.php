<?php
declare(strict_types=1);
require __DIR__ . '/symcon_stub.php';
require __DIR__ . '/../SensorEventProbe/module.php';

// These tests verify isolation/bounds/control behavior, not the native Data layout.
class ClockedSensorEventProbe extends SensorEventProbe
{
    public int $now = 1000000000;
    protected function NowNs(): int { return $this->now; }
}
$checks = 0;
function checkProbe(bool $condition, string $message): void
{
    global $checks;
    ++$checks;
    if (!$condition) throw new RuntimeException($message);
}
function reportProbe(ClockedSensorEventProbe $p): array { return json_decode($p->GetReport(), true, 512, JSON_THROW_ON_ERROR); }
function configureProbe(int $id, array $ids = [101], int $limit = 256): ClockedSensorEventProbe
{
    $p = new ClockedSensorEventProbe($id);
    $p->Create();
    $p->properties['VariableIDs'] = json_encode($ids);
    $p->properties['MaxSamples'] = $limit;
    $p->ApplyChanges();
    return $p;
}
$GLOBALS['variables'][101] = false;
$GLOBALS['variables'][102] = 0;
$p = configureProbe(201, [101, 102]);
checkProbe(!$p->attributes['Capturing'] && $p->GetTimerInterval('CaptureTimer') === 0, 'Default idle');
checkProbe($p->messages === [] && $p->buffers === [], 'No subscriptions/runtime buffers in lifecycle');
$p->Start();
checkProbe($p->GetTimerInterval('CaptureTimer') === 1000 && count($p->messages) === 2, 'Bounded active timer/subscriptions');
checkProbe(isset(reportProbe($p)['error']), 'No report during active capture');
$raised = false;
try { $p->Start(); } catch (RuntimeException $e) { $raised = true; }
checkProbe($raised && $p->attributes['Capturing'], 'Duplicate Start rejected while preserving capture');
$p->MessageSink(10, 101, VM_UPDATE, [true, true]); // Deliberately differs from later live false.
$p->now += 1000000;
$p->MessageSink(11, 101, VM_UPDATE, [false, true]);
$p->now += 1000000;
$p->MessageSink(12, 101, VM_UPDATE, [false, false]); // Probe captures unchanged refresh evidence too.
$p->MessageSink(13, 999, VM_UPDATE, [true]);
$p->MessageSink(14, 101, -1, [true]);
$p->MessageSink(15, 102, VM_UPDATE, [123456, true]);
$p->MessageSink(16, 102, VM_UPDATE, [0, true]);
$p->Stop();
$r = reportProbe($p);
checkProbe(count($r['samples']) === 5, 'Only registered updates captured');
checkProbe($r['samples'][0]['native_data']['items'][0]['data']['value'] === true, 'Native value retained');
checkProbe($r['samples'][0]['live_read_comparison']['value'] === false, 'Later live value remains separately labelled');
checkProbe($r['samples'][1]['native_data']['items'][0]['data']['value'] === false, 'Return transition retained');
checkProbe($r['samples'][2]['native_data']['items'][1]['data']['value'] === false, 'Unchanged indicator evidence retained');
checkProbe($r['samples'][3]['native_data']['items'][0]['data']['value'] === 123456 &&
    $r['samples'][4]['native_data']['items'][0]['data']['value'] === 0, 'Integer token and reset use identical capture path');
checkProbe($r['metadata']['stop_reason'] === 'manual stop' && !$r['lifecycle_interruption'], 'Normal stop report');
checkProbe($p->messages === [] && $p->GetTimerInterval('CaptureTimer') === 0, 'Stop removes subscriptions/timer');
checkProbe($GLOBALS['variables'][101] === false && $GLOBALS['variables'][102] === 0 && $GLOBALS['calls'] === [], 'No observed-input writes or dispatch');

$p = configureProbe(202, [101], 2);
$p->Start();
$p->MessageSink(20, 101, VM_UPDATE, [true]);
$p->MessageSink(19, 101, VM_UPDATE, [false]);
$p->MessageSink(21, 101, VM_UPDATE, [true]);
$r = reportProbe($p);
checkProbe($r['metadata']['count'] === 2 && $r['metadata']['stop_reason'] === 'sample limit', 'Sample cap auto-stop preserves prefix');
checkProbe($r['metadata']['counter_regressions'] === 1, 'Counter regressions observed, not sorted away');
checkProbe($p->messages === [] && $p->GetTimerInterval('CaptureTimer') === 0, 'Cap removes active load');
$p->Start();
$p->MessageSink(30, 101, VM_UPDATE, [false]);
$p->Stop();
$fresh = reportProbe($p);
checkProbe(count($fresh['samples']) === 1 && $fresh['metadata']['session'] !== $r['metadata']['session'], 'New session excludes stale slots');

$p = configureProbe(203);
$p->Start();
$GLOBALS['semaphore_busy']['FIFOPROBE_203'] = true;
$p->MessageSink(40, 101, VM_UPDATE, [true]);
checkProbe(isset(reportProbe($p)['error']), 'Report mutex failure explicit');
$raised = false;
try { $p->Stop(); } catch (RuntimeException $e) { $raised = true; }
checkProbe($raised && $p->attributes['Capturing'], 'Busy Stop rejected without corrupting state');
$p->Tick();
unset($GLOBALS['semaphore_busy']['FIFOPROBE_203']);
$p->Stop();
$r = reportProbe($p);
checkProbe($r['metadata']['count'] === 0 && $r['contention_notice'] !== '', 'Mutex omission visibly latched');

$p = configureProbe(204);
$p->properties['DurationSeconds'] = 10;
$p->Start();
$p->now += 1500000000;
$p->Tick();
$p->now += 9000000000;
$p->Tick();
$r = reportProbe($p);
checkProbe($r['metadata']['stop_reason'] === 'duration' && $r['metadata']['timer_ticks'] === 2, 'Duration bounded without events');
checkProbe($r['metadata']['max_timer_lateness_ms'] === 8000, 'Timer scheduling lateness recorded');
$p->Tick();
checkProbe($p->GetTimerInterval('CaptureTimer') === 0, 'Late idle tick stays stopped');

$p = configureProbe(205);
$p->Start();
$GLOBALS['unavailable_buffer_interface'] = 205;
$p->ApplyChanges();
unset($GLOBALS['unavailable_buffer_interface']);
$r = reportProbe($p);
checkProbe(!$p->attributes['Capturing'] && $p->messages === [], 'Apply safe without buffer interface');
checkProbe($r['lifecycle_interruption'], 'Apply interruption not disguised as completed capture');
$p->Destroy();
checkProbe($p->GetTimerInterval('CaptureTimer') === 0, 'Destroy stops observer');

$p = configureProbe(209);
$p->Start();
$GLOBALS['on_get_value'] = static function ($id) use ($p): void {
    unset($GLOBALS['on_get_value']);
    $p->Stop();
    $p->now += 1000000000;
    $p->Start();
};
$p->MessageSink(49, 101, VM_UPDATE, [true]);
$p->MessageSink(50, 101, VM_UPDATE, [false]);
$p->Stop();
$r = reportProbe($p);
checkProbe(count($r['samples']) === 1 && $r['samples'][0]['native_counter'] === 50, 'Old callback cannot cross Stop/Start session boundary');
checkProbe($r['samples'][0]['elapsed_ms'] >= 0, 'New capture has no pre-start observations');

$p = configureProbe(210);
$GLOBALS['on_semaphore_enter'] = static function ($name) use ($p): void {
    unset($GLOBALS['on_semaphore_enter']);
    $p->Start(); // Starts after Tick entry, before Tick obtains its control lock.
};
$p->Tick();
checkProbe($p->attributes['Capturing'] && $p->GetTimerInterval('CaptureTimer') === 1000, 'Old idle Tick cannot disable a newly started session');
$p->Stop();

$p = configureProbe(211);
$p->Start();
$GLOBALS['semaphore_busy']['FIFOPROBE_211'] = true;
$GLOBALS['unavailable_buffer_interface'] = 211;
$p->ApplyChanges();
unset($GLOBALS['unavailable_buffer_interface'], $GLOBALS['semaphore_busy']['FIFOPROBE_211']);
$p->Tick();
$r = reportProbe($p);
checkProbe($r['metadata']['stop_reason'] === 'lifecycle interruption' && $r['lifecycle_interruption'] && !$p->attributes['Capturing'], 'Contended Apply fence stops capture on next tick and labels report');
checkProbe($p->messages === [] && $p->GetTimerInterval('CaptureTimer') === 0, 'Contended lifecycle stop removes subscriptions/timer');

$p = configureProbe(212);
$GLOBALS['on_semaphore_enter'] = static function ($name) use ($p): void {
    unset($GLOBALS['on_semaphore_enter']);
    $p->ApplyChanges(); // Invalidates Start's pinned lifecycle fence.
};
$raised = false;
try { $p->Start(); } catch (RuntimeException $e) { $raised = true; }
checkProbe($raised && !$p->attributes['Capturing'] && $p->messages === [] && $p->GetTimerInterval('CaptureTimer') === 0, 'Start cannot cross an Apply fence');

$p = configureProbe(206);
$p->Start();
$p->MessageSink(50, 101, VM_UPDATE, [str_repeat('x', 100000), range(1, 100000)]);
$p->Stop();
$r = reportProbe($p);
checkProbe($r['metadata']['bytes'] < 4096, 'Huge input bounded before encoding');
checkProbe($r['samples'][0]['native_data']['items'][0]['data']['truncated'] &&
    $r['samples'][0]['native_data']['items'][1]['data']['truncated'], 'Truncation explicit');

foreach (['[]', '[999999]', '["101"]', '{}', 'null', '[101,102,103,104,105,106,107,108,109,110,111,112,113,114,115,116,117]'] as $ids) {
    $p = configureProbe(207);
    $p->properties['VariableIDs'] = $ids;
    try { $p->Start(); throw new RuntimeException('Invalid configuration accepted'); }
    catch (InvalidArgumentException $e) { checkProbe(!$p->attributes['Capturing'] && $p->messages === [], 'Invalid config stays idle'); }
}
$p = configureProbe(208);
$p->properties['VariableIDs'] = json_encode([$p->idents['CaptureStatus']]);
try { $p->Start(); throw new RuntimeException('Own status accepted'); }
catch (InvalidArgumentException $e) { checkProbe($p->messages === [], 'Self-feedback rejected'); }

checkProbe($GLOBALS['variables'][101] === false && $GLOBALS['variables'][102] === 0 && $GLOBALS['calls'] === [], 'All fault paths preserve input/output isolation');
echo "Sensor event probe: $checks checks passed. Native runtime verification remains required.\n";
