# Native FIFO timing lab

## fifo.9 scheduling experiment (current next step)

`Version2.14.0-fifo.9` retains the **50 ms initial idle wake** and ordinary FIFO continuation. During **applied diagnostic failure-capture mode only**, a batch that leaves queued work requests a **10 ms continuation interval**. The switch occurs once under the existing queue mutex; later busy batches query the interval and do not reset it. An empty queue stops the timer under the same lock as the next admission. Pending recovery retains an already-fast diagnostic timer. A false return/exception from the new continuation update follows the existing explicit fault/pause/no-replay path.

Worker ownership, one-attempt semaphore waits (admission/essential commit10ms, dequeue/worker owner1ms), queue capacities,32-record maximum/soft20msbatch target, rule semantics and all-source/heartbeat treatment remain unchanged. A single slow frame or native call can exceed the target. No per-event asynchronous script, spin loop, larger batch or timer per sensor is introduced. Diagnostic mode includes any instance where failure capture is explicitly applied; **keep production FIFO/shadow/capture off** and run this experiment only on the zero-route lab.

The SDK documents [GetTimerInterval](https://www.symcon.de/en/service/documentation/developer-area/sdk-tools/sdk-php/module/gettimerinterval/) (available since5.2) as an integer millisecond query and [SetTimerInterval](https://www.symcon.de/en/service/documentation/developer-area/sdk-tools/sdk-php/module/settimerinterval/) as a millisecond update returning success/failure. These establish API compatibility with Symcon9, not actual10ms precision, timer reentrancy or native thread fairness. Faster timer requests can consume additional shared threads/ownership attempts and concentrate evaluation work. There is no CPU/residentRAM claim.

Next native actions:

1. Confirm lab54312 has drained (`processed70`, `count0`, actual `enabledfalse`, already supplied for fifo.8). Update **design/module1-fifo**, retaining production FIFO/shadow/capture OFF. No Symcon restart.
2. Existing **MyAlarmFifoLoadLab** / **Module1Test54312**: set **Scenario** to `concurrent_flicker`, run **RunScenario once**, send **Result**. No reinstall or full initial test sequence.
3. Send fresh **real heartbeat history covering the run** (the synthetic Token input is not the actual watchdog). Obtain it from the watchdog UI or a separate script:

```php
<?php
echo AHW_GetHeartbeatHistory(35750);
```

Keep that script separate from RunScenario. Heartbeat observations are shared-service health evidence, not measured CPU/RAM or proof of every alarm policy.

Report `limits` now exposes50msidle/ordinary,10msdiagnostic continuation and20ms/32records batch targets. `worker_interval_ms` queries the currently requested native interval (not measured timer latency); it is normally0 in fifo_before and after_disable, and can also be0 in fifo_after after a completed drain. Use policy fields and `diagnostic_timing.pending_gap_*` / recent gap samples to assess the experiment. The original2/4/2second bounded startup/producer/drain deadlines remain; do not extend them to hide backlog. Require all70 admissions/processed, stable baseline, no new fault, empty queue and actual Disable. If unfinished, obtain a later lab-only `MYALARM_GetInputFifoReport(54312)` before another run/update.

After this targeted result, review whether reduced lag costs real heartbeat/shared-service health. The deliberate40ms admission-contention check and native per-source/value/order/COUNT/ONCE verification remain later gates. Aggregate counts alone cannot validate exact rule/output behavior. A supervised production trial is later, after those gates; Modules2/3/watchdog remain unchanged.

Local verification: **580 checks** across10suites (new scheduling31);22workflow PHP syntax files; clean whitespace. Independent review/native fifo.9 results recorded in current handoff/PR Conversation. Native fifo.9 results pending.

## Completed fifo.8 graph-size evidence

- Small concurrent session03cbe197edbe3e4f:70/70,21batches, evaluation479.550ms, pending gaps1027.883ms, maxlag1421.263ms, empty and actualOFF.
- Larger sequential09c15b83f2f95a06:9/9,5batches, evaluation73.428ms, queuepeak2, maxlag63.807ms, no backlog after each batch, complete/current coverage, actualOFF.
- Larger interleaved1f54ffc92d418317:36/36,16batches, evaluation360.554ms, pending gaps769.601ms, queuepeak30, maxlag1011.725ms, complete/current coverage, actualOFF.
- Larger concurrent2c3a65515455ee7b:70admitted,55processed at the2sdrain snapshot (14queued/1dequeued),57processed/13queued at deferred Disable snapshot. **Initial comparison remains incomplete/timely acceptance failed.** Later same-baseline report confirms processed70/count0/actualOFF/nofault/recoveries3unchanged. Final34batches, evaluation871.713ms, worker953.437ms, worker queue waits1.162ms/0misses, pending gaps1699.285ms, maxlag2527.698ms. This shows delayed completed admission accounting, not recorded loss. Historical timing becomes not-current after Disable/Apply; retained legacy guard remains.

Generic larger graph61classes392rules55groups69members370unique rule inputs377totalinputs;0bedrooms/routes. Private production topology/types/activation/bedroom/receiver costs are not replicated. The recorded ~51ms pending gaps dominate measured walltime, but include diagnostic persistence/owner-release/native scheduling; they are not pure timer latency, admission-lock wait or CPU utilization. No claim that this lab establishes original production legacy event-loss causality.


## Historical fifo.8 measurement instructions (completed)

`Version2.14.0-fifo.8` adds worker timing **only while the applied failure-capture test mode is active**. Ordinary FIFO receives no timing history. Existing queue waits, timer interval, batch target, trigger semantics and routing remain unchanged. No production activation or Symcon restart is required.

1. Update `design/module1-fifo` in module control. Keep production FIFO, shadow and failure capture disabled.
2. In the existing **MyAlarmFifoLab**, set **Scenario** to `concurrent_flicker`, run **RunScenario** once, and provide **Result**. This repeats the small seven-class/seven-rule fixture with timing, before changing graph size.
3. After that result is reviewed, install the separate larger fixture using a temporary script:

```php
<?php
$labToolsDirectory = rtrim(IPS_GetKernelDir(), '/\\') . '/modules/MyAlarmSystem/libs/tools';
require_once $labToolsDirectory . '/FifoLab.php';
echo json_encode(FifoLab::install('production_size'), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
```

The checked-in equivalent is `libs/tools/symcon_fifo_load_lab_install.php`. It creates **MyAlarmFifoLoadLab**, its own **Module1Test**, **Scenario**, **RunScenario**, **StopScenario** and **Result**. Existing small lab IDs/state are preserved. Initial Scenario is `sequential`: **nine changed writes**, not a 370-input burst. Start with this scenario and review Result before a larger burst. Existing startup/producer/drain limits remain 2/4/2 seconds; missed deadlines produce incomplete results rather than an unbounded retry.

The generic fixture has **61 classes, 392 sensor-rule rows, 55 groups, 69 memberships, 370 distinct left-hand sensor variables and 377 total input variables**. The extra seven inputs are one Boolean reference and six integer comparison references. Boolean filler inputs and integer references start false/zero; six strict-greater comparisons keep filler rules inactive. Class modes are 58 OR, two COUNT and one AND. It approximates the exported graph counts, **not production rule types, activation patterns, topology or delivery**. It has zero dispatch routes and **zero bedroom rules**: the existing safety validator requires bedroom rules to have a configured dispatch target, so the fixture omits the export's six bedroom rules instead of weakening that gate. Bedroom-sync and receiver costs are not measured.

Installation performs one bounded creation/configuration pass. There is no archive or recurring input generator. Scenario setup resets only its eight base inputs; it does not rewrite hundreds of fillers. Entry/cleanup validation checks every local input and the whole expected graph, which itself consumes native server calls. Keep the fixture configuration and filler inputs unchanged. Generated plans can write only the eight base inputs. Both lab instances share the production library, CPU and script threads even though their inputs/routes are separate.

Read **fifo_after.diagnostic_timing** in Result:

- `evaluation_total_ms` and recent `evaluation_max_ms`: full graph evaluation, including native status writes and any configured synchronous calls. Failed evaluation samples can include fault-handling time and are marked incomplete.
- `queue_wait_total_ms`, attempts/misses: **worker** dequeue/progress/shutdown lock acquisition; these exclude producer admission waits.
- `state_commit_total_ms`: completed batch map/state persistence and pulse-timer update.
- `pending_gap_total_ms` / maximum and recent `gap_before_ms`: time between observed batches when the previous batch left queued work. It includes summary persistence, owner release and scheduling; it is not a pure timer-latency measurement. Idle time is excluded. Work arriving after an empty shutdown is not included in this gap metric.
- `worker_elapsed_total_ms`: measured worker wall time through health publication, excluding the optional timing-summary write. Overlapping phase totals are not independent CPU measurements.
- `is_current_baseline` and `covers_processed_snapshot`: only compare summaries for the current baseline and completed committed batches. Missing/failed summaries, an in-flight frame, replaced baseline or disabled Apply must not be treated as complete evidence. Coverage refers to processed progress, not queue ordering, admission coverage or downstream delivery.

A separate volatile timing buffer is written **once per batch outside the queue lock**, capped at **8 KiB / eight recent samples**. It contains only numeric timing/counter fields, stage and baseline identity, not sensor values or event logs. Optional capture failure must not fault processing or replay a record. CPU and resident RAM remain unmeasured; fifo.7's 1.348-second delay is neither a CPU figure nor solely a mutex wait.


Branch `design/module1-fifo`, actual Module 1 fifo.9 diagnostics. Main remains stable Module 1 v2.13.4. The lab exercises the actual runtime; fifo.8 adds test-mode-only timing and a generic larger profile. It does not implement a different FIFO.

## Purpose and scope

Artur proposed a standalone FIFO emulator plus synthetic inputs/timing scripts. A copied queue can reproduce its own defects while missing the actual message sink/worker behavior. The installer and scenario scripts therefore exercise the real SensorGroup instance through native `SetValue` callbacks and native asynchronous script execution. They create a separate test instance and eight input variables under the dedicated root category `MyAlarmFifoLab`.

The test instance has no dispatch targets/routes, no bedroom target and no vault. It sends no input/payload to production Module 2/3 or the watchdog. The token input is an ordinary CHANGE sensor with token/reset pairs, with the same FIFO treatment as other sensors; it does not mark the actual watchdog healthy. End-to-end heartbeat and receiver delivery are outside this lab's scope.

Lab and production share the installed module library and server. Switching the library branch changes the code loaded for production instances too. Keep **production live FIFO, diagnostic mode and shadow disabled** while using this branch; the lab alone enables FIFO. A separate instance isolates configuration and routing, not PHP-library code or CPU/memory/thread resources. No Symcon restart is requested. Native library updates use normal lifecycle and can briefly interrupt callbacks.

## Install and run

1. Update the library from `design/module1-fifo` while production testing is disabled. Confirm fresh actual heartbeat/monitoring health after the update.
2. Paste [libs/tools/symcon_fifo_lab_install.php](../libs/tools/symcon_fifo_lab_install.php) into a temporary Symcon PHP script and run it. Its default folder is `IPS_GetKernelDir()/modules/MyAlarmSystem/libs/tools`; adjust that folder only if your actual library path differs. The helper [libs/tools/FifoLab.php](../libs/tools/FifoLab.php) is loaded from the installed branch.
3. The installer prints IDs and creates `Module1Test`, eight inputs, `Scenario`, `RunScenario`, `StopScenario`, `Result`, `Manifest`, `ActiveSession` and three producer-status variables. It leaves lab FIFO disabled. Re-running installation reuses a validated existing lab without resetting it. It updates only our exact generated RunScenario/StopScenario scripts from the former tools path to libs/tools, after checking the lab is inactive; customized scripts are refused. An incomplete or modified existing lab is refused; inspect/remove only that dedicated category if you need a fresh installation.
4. Set the string variable `Scenario` to `sequential`, then run `RunScenario`. Read/copy `Result` after the run; the script also prints the report. No need to stop before reading it. Each run requests that lab live FIFO and diagnostic capture be disabled afterward. Verify `after_disable.configured_enabled=false` and `after_disable.enabled=false`; if `enabled` remains true, wait for its retained prefix/Apply to finish and inspect the lab health. Never infer successful disabling from a requested property change alone.
5. Repeat `interleaved`, `concurrent_flicker`, then `refresh_noise`, saving each Result. Use `StopScenario` to cancel a run. Do not edit the lab manifest/configuration or manually change its inputs during a scenario.
6. After ordinary tests, run `baseline_race` and `admission_contention`. A deliberate fault/pause is acceptable here because the instance has no alarm outputs. Send the reports before changing the FIFO design. Finish with fresh real watchdog history to check shared-server effects.

The module configuration is validated against the exact expected lab graph, pending settings and local object ownership. Adding routes, redirecting a manifest ID outside the lab, aliasing statuses with inputs, changing types or staging unrelated configuration changes makes the scripts refuse operation. Cancellation is established before startup; it is checked before activation/producer launch. Old producer sessions do not publish completion into a different active session. Run ownership is released even when cleanup/report persistence fails.

## Scenarios

| Scenario | Input pattern | What it can reveal |
|---|---|---|
| sequential | Door open/close, COUNT activations, ONCE transition and token/reset at requested 50 ms gaps | Basic native admission/worker consistency and existing rule execution |
| interleaved | Two alternating sources mixed with token/reset pairs | Competing source processing in one writer |
| concurrent_flicker | Two simultaneous noise writers and a third token/door writer | Native ordering/continuity or admission contention under parallel activity |
| refresh_noise | 48 unchanged updates alongside token/reset pairs | Refresh suppression and progress of another source |
| baseline_race | Concurrent inputs while current-state recovery is requested three times | Baseline sample/generation failure and first-fault stage |
| admission_contention | Hold only the lab instance's admission semaphore for requested 40 ms while producers run | Intentional omitted-observation fault, diagnostic pause and retained-prefix behavior |

Each plan has at most 128 writes in total, usually 70 or fewer. Requested gaps are 1–50 ms. Native scheduling can delay them; producer reports retain elapsed time and maximum schedule lateness. Asynchronous producers may start at different times, so this is not a precise physical-timing simulator. Forced admission contention demonstrates fault handling, not that contention caused the historical production failure. The initial lab's seven classes are smaller than the production graph.

## Reports and comparison limits

Result contains the scenario/session, three producer reports, actual changed-write count, worker processed delta, timing, `comparison_incomplete`, `changed_write_count_matches_processed`, FIFO snapshots and first-failure evidence. Paused state or another baseline publication makes comparison incomplete. Incomplete/missing producer reports or count mismatch also remain explicitly uncertain.

After producers finish, the coordinator waits up to two seconds for the expected aggregate processed count **and** an empty queue, or a fault. Queue emptiness alone does not prove that delayed callbacks have arrived. A mismatch at the deadline is evidence to investigate, not definitive proof of event loss.

Count agreement supports aggregate processing for this synthetic workload; it does not prove exact per-input delivery, downstream receiver acceptance, physical event order, COUNT/ONCE output policies or complete sensor history. Pulse expiry is set to 30 seconds, beyond the short scenarios, to avoid routine expiry controls contaminating the comparison. Other externally requested control frames could still change counts; leave the test instance untouched while running it. First-fault observation time is publication time, not physical sensor time.

Result is one bounded report (maximum 64 KB), overwritten on the next run. Copy reports you want to keep. First-fault evidence remains in the test instance even after disabling. It is not a per-event archive. Standalone baseline/MessageSink observations and simulated thread behavior cannot establish safe production concurrency guarantees.

## Performance and persistence

A manual run uses one coordinator and up to three short-lived producer scripts, plus the actual Module 1 worker. There is no permanent scenario timer, external I/O, per-input logging or archive registration. Producer waits are bounded to 50 ms per operation; setup polls every 25 ms for at most two seconds, producer completion for at most four seconds, and aggregate drain for at most two seconds. These bounds cannot preempt a blocked native API call or a server execution timeout.

Configuration validation occurs at script entry and before configuration activation, not for every input write. Producer completion writes one small status variable each; Result writes once at run completion. Inputs themselves use native variable writes and actual FIFO code. The intentional admission mutex affects only the lab instance and is held for requested 40 ms, never a production instance's lock. Scripts and variables consume shared server resources; CPU/resident RAM remain unmeasured. Do not run repeated stress loops or leave a scenario unattended.

Native API signatures were checked against the official [Symcon GlobalStubs](https://github.com/symcon/SymconStubs/blob/master/GlobalStubs.php): instance/variable/script creation, script content installation, `IPS_RunScriptEx`, `SetValue` and `IPS_Sleep`. Stubs establish API shape, not native ordering, timing or completion guarantees. Mock verification supplements the existing suites; live results remain required.

## Historical fifo.8 review and checks

All nine local suites pass **549 checks**, including **57 lab checks and 24 timing checks**. Independent read-only review reran the final 57/24 suites and approved only supervised isolated lab testing. PHP syntax (21 workflow files) and whitespace checks pass. Those checks preceded the completed fifo.8 evidence above; current fifo.9 acceptance is pending.

## Historical fifo.7 review and evidence

The following records the completed earlier milestones. The current next action is the fifo.8 small concurrent measurement above, not a restart of the old sequence.

All eight local suites pass **510 checks**; the harness contributes **42** covering installation, scope/plan bounds, route/manifest/pending-property refusal, four successful scenario lifecycles, startup cancellation, partial-control stop, stale completion refusal, cleanup-write failure and delayed callbacks. Changed PHP lint and whitespace checks pass. Independent read-only review reran the 34 checks and approved this harness for supervised testing with no remaining blockers. Mock script execution is deterministic and does not validate native parallel scheduling.

## Current native evidence and fifo.7 retest

On fifo.6, sequential passed 9/9 and interleaved passed 36/36. Ordinary concurrent_flicker generated 70 changed writes but captured `admission_lock` omission at the old 1 ms timeout. The queue peaked at 41/128 entries, not overflow. Diagnostic pause blocked later admission; all 50 admitted records subsequently processed, and a later lab report confirmed actual FIFO disablement and an empty queue. The initial 16 processed was an unfinished snapshot. This does not prove the cause of the original production legacy heartbeat loss.

fifo.7 removes redundant timer/pending writes from admissions while the worker is already scheduled; the idle wake and empty shutdown remain serialized under the queue lock. Admission and required worker progress/shutdown commits request a single 10 ms semaphore wait, while dequeue retains its 1 ms quick yield. No retry loop, additional polling/logging, heartbeat priority or queue enlargement is added. The report `limits` exposes these wait policies so the native build can be checked. 10 ms is experimental, not a guaranteed sufficient or actual measured wait. It can occupy each contended native callback longer; worker elapsed budget remains soft and downstream calls can exceed it.

Keep production FIFO/shadow/capture off, update the diagnostic branch and confirm fresh real heartbeat after the shared-library update. The existing lab scripts load the updated helper from the library; do not reinstall/reset the lab unnecessarily. Rerun sequential and interleaved, then concurrent_flicker, saving each Result and verifying after_disable. If concurrent_flicker passes, run refresh_noise. Stop on an unexpected first fault and obtain a later lab-only FIFO report if cleanup is initially deferred. Do not clear the historical fault merely to make the next test look clean: `first_fault_is_current` distinguishes old evidence. Intentional baseline/contention scenarios remain later steps.

## Packaging correction: helpers under libs/tools

Symcon's [documented directory structure](https://www.symcon.de/en/service/documentation/developer-area/sdk-tools/sdk-php/structure/) does not exempt a top-level tools folder from module discovery. Helpers now live in libs/tools; no dummy module.json or new alarm module is created. The FIFO runtime remains fifo.7.

After updating the diagnostic branch, replace your temporary installer script with the current [installer](../libs/tools/symcon_fifo_lab_install.php) and execute it once. Its helper path ends with /modules/MyAlarmSystem/libs/tools/FifoLab.php. This repairs the existing generated lab scripts without recreating the category, resetting inputs/configuration or clearing Scenario/Result/fault evidence. Verify the printed Module1Test ID remains the same, then resume fifo.7 retesting. Do not run an old saved installer still pointing to /tools/FifoLab.php.

Eight additional local checks cover module-discovery layout, exact legacy path migration, state/ID preservation, current-path idempotence, refusal of customized scripts before any overwrite, active sessions, busy coordinator ownership and actual enabled FIFO despite off settings. The lab suite now passes 42 checks; full suite total 510. Migration adds a one-shot 1 ms coordinator acquisition only when script paths need repair; no routine input/worker overhead.
