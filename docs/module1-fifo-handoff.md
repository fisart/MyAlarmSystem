# Module 1 FIFO — current AI handoff

Updated: 2026-09-13 UTC. Repository: `fisart/MyAlarmSystem`. Working branch: `design/module1-fifo`, draft [PR #3](https://github.com/fisart/MyAlarmSystem/pull/3).

## Current position

Current follow-up: **Version2.14.0-fifo.9**,10ms pending continuation only in applied diagnostic mode; initial50mswake/ordinary50mscontinuation and worker32-record/soft20msbatch limits remain. Timed fifo.8 larger-graph runs completed: sequential9/9, interleaved36/36, concurrent70/70 eventually. Concurrent missed the2sdrain deadline (55processed, later57/13queued); subsequent same-run70/count0/actualOFF confirms delayed completion without recorded fault. Final34batches, maxlag2.528s, evaluation872ms, pending gaps1699ms, worker queue waits1.162ms/0misses. Production legacy event-loss cause remains unresolved.

**Next native action:** after updating design/module1-fifo, keep production FIFO/shadow/capture OFF; existing **MyAlarmFifoLoadLab54312**, Scenario=concurrent_flicker, RunScenario once; provide Result and fresh real heartbeat history from watchdog35750 covering run. No reinstall/restart/guard reset. Review reduced lag and shared-server health before deliberate contention/per-source verification or production trial. Do not repeat the old unchanged scenario sequence. Current [instructions](module1-fifo-native-lab.md) override historical fifo.8/fifo.7 sections.

Local verification: **580 checks** across10suites (prior549 + scheduling31),22PHP syntax files, clean whitespace; independent runtime/scheduling/timing/failure review in [review](module1-fifo-review.md). Native fifo.9 results pending. Queue/rule/delivery behavior, Modules2/3/watchdog unchanged. Published SHA and GitHub CI checkpoints are in PR#3 Conversation.

Stable main is `a4c63a813cf55852c5f36966bac5e6357f88a82c` (merged PR #4), Module 1 v2.13.4 and Module 2 v7.3.2. It contains tested rejected-Apply subscription recovery, dynamic tamper reference recovery and bedroom backing-list/isolated draft repair. Main has no FIFO/shadow/probe runtime. Its original competing-input event-loss problem remains unresolved. Installation of this main release on the user's server has not been confirmed in this session.

## fifo.9 implementation and remaining gates

Diagnostic pending continuation uses native GetTimerInterval to avoid repeated timer writes and retain existing fast timer through pending recovery; false/throwing SetTimerInterval enters explicit uncertain-state/pause/no-replay handling. Empty stop and idle50mswake serialized under queue lock, worker ownership unchanged; no new scripts/spin/logging/history or growing state. Faster requests can collide with worker ownership and concentrate existing work, so actual heartbeat/shared-service evidence is needed; CPU/RAM unmeasured. Applied diagnostic gate covers all explicitly configured test instances, not merely lab name; production test flags stayOFF. Per-source native value/order/COUNT/ONCE and forced contention remain later gates. No production/main approval.

## Problem and accepted strategy

The original concern was whole sensor events, particularly heartbeat, disappearing when other inputs became active. Later live FIFO reports showed repeated baseline publication with little queue occupancy. The reported 149 recoveries count baseline publications, not necessarily 149 separate failures; overflow and the historical cause have not been established.

The user distinguishes losing an entire source's event from discarding unnecessary history for a source while retaining its latest status. Future per-source pending state and fair scheduling must still preserve existing LEVEL/CHANGE/ONCE/COUNT trigger, count and timing semantics. No speculative coalescing redesign is implemented yet. First obtain bounded native failure evidence.

User requirements:

- Runtime changes only in Module 1. Module 2, Module 3 and watchdog changes remain deferred.
- Heartbeat uses the ordinary sensor path, without priority, reserved capacity or bypass.
- Missed messages and overlapping old evaluations during supervised test switching are explicitly accepted.
- No Symcon service restart. Historical thread-inventory/vendor-contract investigation is shelved; it is not a prerequisite for this test. Do not blindly reset the retained legacy guard.
- Debounce/minimum-active-duration proposal is shelved.
- Production live FIFO, shadow and failure capture stay disabled during the isolated lab tests.
- Account for native thread load, JSON/state writes, synchronous receiver calls, waits and logging. CPU and resident RAM are unmeasured; serialized byte metrics are not a substitute.

Environment: Symcon 9.0, Ubuntu Docker amd64, build 15.06.2026 `f2880badc0d6`, PHP 8.5.5. Official Symcon stubs verify API signatures, not native callback ordering or completion guarantees.

## Published implementation

FIFO timing is active only in applied failure-capture test mode: local worker phase counters, one separate volatile ≤8 KiB summary write per batch, eight recent samples, no per-record timing JSON or logging. Existing 10/10/1 ms waits, 50 ms worker timer and soft 20 ms batch target remain. Queue timing excludes admission waits; pending gaps include profile persistence/owner-release/scheduling. Complete coverage flags require current baseline and matching completed progress; elapsed timing is not CPU/RAM evidence.

The separate generic large profile has 61 classes, 392 rules, 55 groups, 69 memberships, 370 distinct rule inputs / 377 input variables. Six local integer references approximate the additional inventory. It **omits six production bedroom rules**, because they require a real dispatch target under the unchanged validator, and has no output targets. It does not clone private production rules/types/topology or account for bedroom/receiver costs. One bounded installation pass; scenario setup touches only eight base inputs. Lab entry/cleanup validate all local objects/config; this is native overhead. Helpers remain under `libs/tools`, including new `symcon_fifo_load_lab_install.php`; profile-root ownership is strict and small installation remains backward compatible.

Follow [native lab instructions](module1-fifo-native-lab.md). `libs/tools/symcon_fifo_lab_install.php` loads `libs/tools/FifoLab.php` from the installed module library. The installer creates a dedicated SensorGroup instance and eight synthetic inputs under `MyAlarmFifoLab`, with no external dispatch, bedroom or vault routes. Existing valid installation is returned without resetting it; an inactive validated lab can have only its exact formerly generated runner/stop helper paths migrated from tools to libs/tools. Customized/active/busy lab script migration is refused; malformed/partial ownership or pending configuration is rejected.

This drives the actual MessageSink, FIFO and worker, rather than duplicating their implementation in an emulator. Inputs/routes are isolated, **library code and server CPU/threads are shared with production**. Switching library branches therefore also changes code loaded by production instances, which use the branch's legacy evaluator with FIFO disabled.

Six short scenarios: `sequential`, `interleaved`, `concurrent_flicker`, `refresh_noise`, `baseline_race`, `admission_contention`. At most 128 planned writes and three asynchronous producers per run; bounded startup, producer and drain polling. Baseline-race/contention scenarios deliberately provoke fault paths and cannot prove the original cause.

RunScenario stores one bounded JSON report in Result, overwritten by the next run. Copy each report. It records producer completion/errors/timing, before/after FIFO reports, changed-write counts and aggregate processed agreement. Count agreement alone does not prove per-source delivery, physical ordering, downstream acceptance or production heartbeat/alarm behavior. Native calls cannot be preempted by PHP deadlines.

The coordinator requests disabling FIFO and failure capture on the **lab instance only** after a run and includes `after_disable`. Verify actual disabled state; a requested setting alone is insufficient if Apply was deferred. StopScenario cooperatively cancels that lab run. No permanent scenario timer, dispatch outputs or archive/log stream is installed.

fifo.6 failure capture overrides the activation guard only for explicitly configured diagnostic testing, retaining the guard. First fault pauses new admissions and automatic recovery, preserving bounded stage/revision/time/available integer IDs without payload strings. A trustworthy retained prefix can finish; uncertain evaluator state stops dependent work. The existing production diagnostic mode itself has no automatic expiry or fallback: disable both FIFO/capture and Apply to resume legacy processing. The lab coordinator adds a bounded cleanup request for its own test instance.

## Verification

510 local checks pass: safety 129, probe 44, shadow 64, FIFO runtime 109, configuration form 24, passive input diagnostic 39, first-failure capture 59, native lab 42. Runtime includes idle wake/stop, deterministic multi-batch timer retention, in-flight and post-shutdown admission, single bounded transient/persistent lock policy and report bounds. Native semaphore timing is not modelled. Changed PHP lint and whitespace checks pass.

Independent review approved after verifying startup cancellation, lock release even when cleanup/result persistence fails, stale producer status handling and waiting for delayed native callbacks despite an initially empty queue. The new harness tests use mocked asynchronous execution and do not establish native parallel behavior.

[GitHub regression CI passed for the lab code commit](https://github.com/fisart/MyAlarmSystem/actions/runs/34770330861). Native fifo.6 reports are summarized below; no CPU/RAM record or production deployment by the assistant exists. GitHub CI passed for fifo.7 and the packaging correction; documentation-only follow-ups do not change runtime.

## Next actions

1. Keep production FIFO/shadow/capture disabled. Corrected fifo.7 native lab runs now succeeded; no new library update or Symcon restart is needed just to continue scenarios.
2. Initial scenarios are completed. Do not ask the user to repeat refresh_noise, baseline_race or admission_contention without a concrete remaining risk. Ordinary workloads pass and forced contention fault/cleanup works; baseline_race did not prove a fault-causing sampling overlap.
3. Inspect worker scheduling/batch throughput and native timer cadence before larger production-shaped synthetic load. Interleaved lag reached463/625 ms and concurrent1.348 s despite small graph/no receivers. No CPU/resident RAM records exist. If additional diagnostics are needed, choose opt-in bounded summaries, avoiding routine per-event logs/archive/state rewrites or broad polling. Do not infer cause from count matches or lag alone.

4. Evaluate admission critical-section work and native behavior before further changes. fifo.7 skips redundant timer/pending updates and permits one bounded native wait; it does not prove losslessness, source ordering or production load readiness. Each contended callback/essential commit can occupy a thread up to 9 ms longer than before; worker elapsed budget remains soft.
5. Consider per-source pending state/fair scheduling only with existing trigger/count/timing semantics preserved. The lab is smaller than production's 61 classes/392 sensor rules. Keep Module 2/3 deferred risks visible.
6. Reconcile stable main backports and review before any eventual PR #3 merge. Do not automatically merge or deploy diagnostic work based on a successful lab count.

## Native fifo.6 evidence

- Sequential session `6ac38bf01d4954a3`, 21:25:37 +02:00: 9 generated/admitted/processed changes; no fault or rebaseline; queue peak 2, max lag 59.912 ms; actual disable confirmed.
- Interleaved session `60034053e19f6f1a`, 21:27:14: 36/36/36; one active writer, no fault or rebaseline; queue peak 11, max lag 87.218 ms; actual disable confirmed.
- Concurrent session `a15823d84df28a3b`, 21:28:00: three producers completed 32+32+6 changes in about 150–161 ms. First fault stage `admission_lock`, variable 28328, integer native counter 398950839, precise current capture. Admission semaphore requested 1 ms. 50 admitted, initially 16 processed with 33 queued/one in flight; peak 41 entries and 7,415 bytes, below limits. Later report retained the original fence and showed all 50 processed/empty queue; final lag 323.605 ms, batch 29.188 ms. Actual FIFO/diagnostic disablement confirmed. Remaining 20-write difference includes initial omission and later diagnostic pause, not 20 independently proven mutex failures.
- Later report contains another baseline at 21:28:55 (4 changed records processed, 48 refreshes suppressed); its cause is not established by the report, and it does not reconstruct missed original edges. Historical first-fault capture remains intact, explicitly not current.
- This localizes a failure in the experimental FIFO admission path. Original production legacy/heartbeat causality remains unproven. No queue enlargement, fault suppression, heartbeat exception, guard reset or service restart is justified.

[Sequential checkpoint](https://github.com/fisart/MyAlarmSystem/pull/3#issuecomment-5655540706), [interleaved](https://github.com/fisart/MyAlarmSystem/pull/3#issuecomment-5655550794), [concurrent failure](https://github.com/fisart/MyAlarmSystem/pull/3#issuecomment-5655558117), [drain/disable confirmation](https://github.com/fisart/MyAlarmSystem/pull/3#issuecomment-5655573442), [selected change](https://github.com/fisart/MyAlarmSystem/pull/3#issuecomment-5655583839).

Reference: [design](module1-fifo-design.md), [first-failure diagnostic](module1-fifo-first-failure-test.md), [runtime](module1-fifo-runtime.md), [independent review](module1-fifo-review.md), [coordinated deferred backlog](module1-fifo-coordinated-backlog.md).

## GitHub checkpoint practice

The user explicitly requests frequent GitHub status reports so another AI can continue. Post PR #3 Conversation checkpoints at significant design/code/review/test/publication/native-evidence milestones and when blocked or stopping active work. Include commit IDs, verified results, uncertainty, constraints and concrete next actions. Keep this handoff current when the strategy or evidence changes. This is milestone reporting during authorized work, not unsolicited background activity while the user is away.

Initial lab checkpoint: [2026-09-13](https://github.com/fisart/MyAlarmSystem/pull/3#issuecomment-5654725984).

## Packaging follow-up

Artur reported the module-discovery error for tools on update. The official Symcon directory exclusions explain this publication mistake. All three helpers moved to libs/tools; alarm runtime still fifo.7, main unchanged. CI now lints the corrected paths and the lab suite checks every root directory against documented module/helper layout. The installer migrates only exact generated old script contents after validating lab ownership/configuration/inactive state and coordinator ownership; it preserves lab IDs and state. Latest packaging commit/CI results are in PR Conversation checkpoints. Native lab execution resumed after packaging correction; four fifo.7 results now pass aggregate processing. Explicit confirmation that the library warning disappeared was not separately supplied. Refresh-noise, requested baseline refresh and deliberate contention scenarios have now run. Larger-load/latency evaluation remains pending.

## Native fifo.7 evidence — latest milestone

The three attached reports plus pasted sequential result supply four distinct sessions, all with10/10/1 ms wait bounds, complete producers/no errors, matching aggregate counts, empty queues, no new fault/pause/unknown inputs and actual FIFO disablement. Historical21:28 fault remains explicitly not current. Recovery counter stays unchanged within each session;1→4 reflects separate run baselines.

| Scenario/session | Started (+02:00) | Changed/admitted/processed | Queue peak | Maximum recorded lag | Maximum batch |
|---|---|---|---|---|---|
| sequential 2bc97b564b673326 |22:10:27|9/9/9|2|59.493 ms|17.492 ms|
| interleaved 81a6ec0059db7112 |22:11:00|36/36/36|23|462.939 ms|28.390 ms|
| interleaved 1f08bf8d09a76854 |22:11:26|36/36/36|28|625.025 ms|28.686 ms|
| concurrent_flicker 18d55b62c904a56d |22:11:54|70/70/70|64|1,347.903 ms|27.206 ms|

Concurrent producers completed32+32+6 changes in about150–161 ms; worker20 batches, queue peak11,572 bytes, whole run2.538 s. The corresponding fifo.6 run failed admission; this fifo.7 run did not. This supports the mitigation for this workload, but does not isolate whether timer reduction or wait extension caused improvement, establish per-source/downstream policy, production load readiness or original legacy heartbeat-loss causality. Recorded lag spans callback entry through evaluation/progress, so includes scheduling, queueing, waiting and processing rather than measuring lock wait alone. No new runtime change is justified solely by this success. Refresh_noise subsequently passed; see the completed initial sequence below. Next assess throughput/latency before deployment.

[GitHub evidence checkpoint](https://github.com/fisart/MyAlarmSystem/pull/3#issuecomment-5655820919).

## Initial lab sequence complete — latest evidence

- refresh_noise session e7cc73adbf00ab2b,22:14:23:52observed,48unchanged refreshes suppressed,4token/reset changes admitted/processed. No current fault/pause/rebaseline. Queuepeak2/354bytes,lag58.572ms,batch16.028ms; actual FIFO disablement confirmed. Across ordinary fifo.7 runs155changed writes admitted/processed,48refreshes suppressed. Synthetic token evidence does not mark the real watchdog healthy or prove downstream policy.
- baseline_race session6eb31774cdf2139c,22:16:08:all70changed writes admitted/processed and cleanup confirmed, no new fault/pause. Recovery counter6→7 with baseline start shifted13.728ms; same Apply fence/revision. Previous baseline had0admissions/observations. Cross-baseline comparison is intentionally incomplete despite numeric70/70; the harness also requires unchanged recovery counter. This does not establish the difficult callback-during-sampling fault path. Queuepeak57,lag432.788ms,batch32.524ms.
- admission_contention sessionc073732402759036,22:17:24:deliberate requested40ms lab queue hold produced precise current admission_lock fault at variable52940/native counter399544567 with10msrequested timeout. Diagnostic pause occurred before any admitted record:0observed/admitted/processed. Observation counter increments only after lock acquisition, so0observed does not mean no native callback. All producers completed70changed writes; later pause explains further omissions, not70separate timeouts. Recoveries8unchanged. Actual configured/owned FIFO false, diagnostics inactive/unpaused, queueempty and legacy evaluator active after cleanup. Retained first fault is historical after Disable Apply; generic FIFO-loss fault field while FIFO off is not proof the legacy evaluator is broken. Actual lock hold/wait duration was not measured.

Remaining: throughput/native timer scheduling and maximum1.348sconcurrent delay, larger392-rule graph and burst/load behavior, per-source/downstream alarm semantics, original production legacy heartbeat-loss causality. No main merge or production acceptance. Keep only Module1 in scope, heartbeat ordinary, no restart/guard reset. At that historical checkpoint runtime stayed reviewed fifo.7. The current fifo.8 measurement follow-up and next action are at the top of this handoff.

[Refresh checkpoint](https://github.com/fisart/MyAlarmSystem/pull/3#issuecomment-5655834207), [baseline refresh](https://github.com/fisart/MyAlarmSystem/pull/3#issuecomment-5655845492), [forced contention/cleanup](https://github.com/fisart/MyAlarmSystem/pull/3#issuecomment-5655852569).
