# Module 1 FIFO — current AI handoff

Updated: 2026-09-13 UTC. Repository: `fisart/MyAlarmSystem`. Working branch: `design/module1-fifo`, draft [PR #3](https://github.com/fisart/MyAlarmSystem/pull/3).

## Current position

Native synthetic-input lab code is published at `435c5b03baae85211b9350284c122686d82cc80c`. Runtime marker remains `Version2.14.0-fifo.6`; the lab commit changes tools, tests and documentation, not alarm runtime. Independent review approved supervised branch testing. **No native lab run or Result report has been received. The original event-loss cause remains unresolved.**

Stable main is `a4c63a813cf55852c5f36966bac5e6357f88a82c` (merged PR #4), Module 1 v2.13.4 and Module 2 v7.3.2. It contains tested rejected-Apply subscription recovery, dynamic tamper reference recovery and bedroom backing-list/isolated draft repair. Main has no FIFO/shadow/probe runtime. Its original competing-input event-loss problem remains unresolved. Installation of this main release on the user's server has not been confirmed in this session.

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

Follow [native lab instructions](module1-fifo-native-lab.md). `tools/symcon_fifo_lab_install.php` loads `tools/FifoLab.php` from the installed module library. The installer creates a dedicated SensorGroup instance and eight synthetic inputs under `MyAlarmFifoLab`, with no external dispatch, bedroom or vault routes. Existing valid installation is returned without resetting it; malformed/partial ownership or pending configuration is rejected.

This drives the actual MessageSink, FIFO and worker, rather than duplicating their implementation in an emulator. Inputs/routes are isolated, **library code and server CPU/threads are shared with production**. Switching library branches therefore also changes code loaded by production instances, which use the branch's legacy evaluator with FIFO disabled.

Six short scenarios: `sequential`, `interleaved`, `concurrent_flicker`, `refresh_noise`, `baseline_race`, `admission_contention`. At most 128 planned writes and three asynchronous producers per run; bounded startup, producer and drain polling. Baseline-race/contention scenarios deliberately provoke fault paths and cannot prove the original cause.

RunScenario stores one bounded JSON report in Result, overwritten by the next run. Copy each report. It records producer completion/errors/timing, before/after FIFO reports, changed-write counts and aggregate processed agreement. Count agreement alone does not prove per-source delivery, physical ordering, downstream acceptance or production heartbeat/alarm behavior. Native calls cannot be preempted by PHP deadlines.

The coordinator requests disabling FIFO and failure capture on the **lab instance only** after a run and includes `after_disable`. Verify actual disabled state; a requested setting alone is insufficient if Apply was deferred. StopScenario cooperatively cancels that lab run. No permanent scenario timer, dispatch outputs or archive/log stream is installed.

fifo.6 failure capture overrides the activation guard only for explicitly configured diagnostic testing, retaining the guard. First fault pauses new admissions and automatic recovery, preserving bounded stage/revision/time/available integer IDs without payload strings. A trustworthy retained prefix can finish; uncertain evaluator state stops dependent work. The existing production diagnostic mode itself has no automatic expiry or fallback: disable both FIFO/capture and Apply to resume legacy processing. The lab coordinator adds a bounded cleanup request for its own test instance.

## Verification

482 local checks pass: safety 129, probe 44, shadow 64, FIFO runtime 89, configuration form 24, passive input diagnostic 39, first-failure capture 59, native lab 34. Changed PHP lint and whitespace checks pass.

Independent review approved after verifying startup cancellation, lock release even when cleanup/result persistence fails, stale producer status handling and waiting for delayed native callbacks despite an initially empty queue. The new harness tests use mocked asynchronous execution and do not establish native parallel behavior.

[GitHub regression CI passed for the lab code commit](https://github.com/fisart/MyAlarmSystem/actions/runs/34770330861). No native lab report, CPU/RAM record or production deployment by the assistant exists.

## Next actions

1. User installs/updates the diagnostic branch while production FIFO/shadow/capture remain off. No Symcon restart is requested.
2. Paste/run the installer script from the instructions. Open the dedicated lab category, run RunScenario with default `sequential`, and copy Result. Verify `after_disable`.
3. Analyze first fault stage and producer/count/timing evidence. If normal sequence succeeds, continue ordinary interleaved/concurrent/refresh scenarios before intentional baseline/contention tests.
4. Compare native evidence with the smaller mock fixture; production has 61 classes and 392 sensor rules, so a successful small lab does not establish production load readiness.
5. Revise admission/baseline handling only when evidence supports it; then reconsider per-source pending state/fair scheduling with existing trigger semantics preserved. Keep Module 2/3 deferred risks visible.
6. Reconcile stable main backports and review before any eventual PR #3 merge. Do not automatically merge or deploy diagnostic work based on a successful lab count.

Reference: [design](module1-fifo-design.md), [first-failure diagnostic](module1-fifo-first-failure-test.md), [runtime](module1-fifo-runtime.md), [independent review](module1-fifo-review.md), [coordinated deferred backlog](module1-fifo-coordinated-backlog.md).

## GitHub checkpoint practice

The user explicitly requests frequent GitHub status reports so another AI can continue. Post PR #3 Conversation checkpoints at significant design/code/review/test/publication/native-evidence milestones and when blocked or stopping active work. Include commit IDs, verified results, uncertainty, constraints and concrete next actions. Keep this handoff current when the strategy or evidence changes. This is milestone reporting during authorized work, not unsolicited background activity while the user is away.

Initial lab checkpoint: [2026-09-13](https://github.com/fisart/MyAlarmSystem/pull/3#issuecomment-5654725984).
