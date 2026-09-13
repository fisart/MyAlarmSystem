# Module 1 live input FIFO — supervised opt-in build

**Current next step:** the [isolated native input/timing lab](module1-fifo-native-lab.md), using a separate Module 1 instance and no downstream outputs. Production live FIFO/diagnostic/shadow remain disabled. The earlier production first-failure procedure remains available for a separately supervised test if later needed.

Build marker: `Version2.14.0-fifo.6`. Default: **disabled**. Current next step: the [supervised first-failure test](module1-fifo-first-failure-test.md). Artur accepts missed messages/overlapping old evaluations during test switching; the explicit diagnostic checkbox permits activation without clearing the retained guard. On a fault, admission and automatic recovery pause; disable live FIFO and Apply to restore existing processing. Do not leave the test unattended. No service restart, vendor guarantee or debounce is required for this authorized diagnostic test. Normal activation/recovery policy remains when diagnostic mode is off. Module 2, Module 3 and watchdog runtime do not change.

The prior [native execution boundary investigation](module1-fifo-restart-free-boundary.md) is historical and shelved. Its 84-update passive capture checked sample payload compatibility only. The recovery cause and production FIFO acceptance remain unresolved; CPU/resident RAM are unmeasured.

## Evidence and limits

Artur supplied two clean shadow captures on 13 September 2026: 61 and 174 admitted/evaluated records, zero decision mismatches, empty queues and no lifecycle fault. All heartbeat targets were OK during both captures. In the second capture, Module 1 callback latency was 113–196 ms against the existing 45-second timeout. The second ring wrapped, but its peak occupancy was only two: it did not establish heavy concurrent-load behavior.

Artur has no CPU/resident RAM records and authorized proceeding without them. Their production impact remains **unmeasured**; serialized sizes and worker elapsed time are not substitutes. Previous shadow timing is evidence for the comparator alongside the old live path, not a measurement of this new FIFO's synchronous delivery latency.

The preceding executable model passed 389 checks: safety 129, read-only probe 44, shadow 64, live FIFO 89, configuration form 24 and passive diagnostics 39. The new suite covers captured raw/formatted payloads, bedroom mirrors, refresh suppression, ordinary heartbeat-shaped token/reset frames, COUNT references/sync, pulse expiry, saturation/prefix drain, Apply cutover, interface recreation, dynamic tamper subscriptions, missing/disabled dependencies, recovery seeding, ownership races, consumer reentrancy, shutdown wake, partial frame/metadata failures, baseline callback failure and UTF-8 fault handling. Diagnostics also retain later failure evidence across repeated zero-processing recoveries, incident acknowledgement, disabling and interface recreation, without storing oversized input payloads. Activation regressions distinguish safely routed/skipped calls from genuinely untracked legacy evaluation. Tests model selected interleavings; they are not a native concurrency stress test.

## Runtime behavior

- Native input admission uses a fixed 128-slot ring, individually serialized slots and 128 small dependency buckets. No whole-queue string rewrite or configuration parsing in the producer. Admission mutex waits at most 1 ms; values retain their PHP scalar types. Ordinary unchanged values are suppressed before queue admission. Rapid A→B→A changes remain separate.
- A single worker runs the existing Module 1 rule evaluator, payload formatting, routing and synchronous delivery. Sensor, comparison-reference and bedroom reads use its captured mirror. Captured values are formatted with [GetValueFormattedEx](https://www.symcon.de/en/service/documentation/command-reference/access-variables/getvalueformattedex/), which does not reread the current sensor value. The existing payload schema and receiver policies remain.
- Pulse controls and requested state sync enter the same queue. Evaluation uses admitted wall seconds for pulse and COUNT rules, preserving legacy integer-wall-second behavior and shared per-variable caches. Runtime maps are cached in the worker and persisted once per batch, rather than parsed/written by every pulse rule.
- Worker wake is 50 ms; maximum batch is 32 records with a 20 ms budget checked between records. Synchronous receiver calls cannot be interrupted by this budget. Worker timers stop at an empty queue, and shutdown shares queue ownership with producer wake scheduling.
- Heartbeat has no priority, reserved capacity or bypass. Tokens and resets use the same admission, worker, evaluator, throttles and delivery as every other sensor input.
- Apply waits for worker ownership and a drained old-configuration prefix before activating a new graph. Startup/recovery samples current values twice and checks a setup-observation generation. This detects observed movement but is not atomic physical sampling. Inputs crossing the restart/configuration baseline boundary may be unavailable as historical edges; the incident remains visible.
- Legacy evaluation remains the default. A short ownership marker allows safe opt-in cutover. If legacy calls overlap, existing concurrent behavior continues while FIFO activation is blocked while the historical guard is retained. The explicit supervised diagnostic option overrides this activation restriction while retaining the guard; it does not prove old evaluations finished.

## Fault and recovery policy

Admission failure, invalid observations, prior-value gaps and overflow latch a bounded time/reason incident. No inline out-of-order evaluator is used. Admission freezes after known loss; the trustworthy retained prefix drains once. Outside diagnostic mode, the worker then rebuilds a current baseline automatically. Diagnostic mode instead retains the first fault and pauses until live FIFO is disabled and Apply completes. COUNT timestamps from completed records remain eligible within their original window. CHANGE/ONCE caches are seeded without manufacturing events; known pulses retain their original deadlines and a trustworthy false ONCE condition clears its pulse.

An exception can leave delivery uncertain. The failed frame is not replayed; its partial rule-map changes are rolled back, completed prefix maps are retained, and the dependent tail is classified as discarded. One previous-session summary records the counters, including discarded/unprocessed records. A baseline/dispatch exception cannot strand a not-ready queue. A fresh state-only baseline uses the existing projection/throttle behavior; this is not an exactly-once receiver guarantee.

Missing or unsupported **active** inputs remain identified as unknown. Their rules are not presented as trustworthy; existing safety snapshots report their underlying invalidity. Trustworthy unrelated inputs continue processing. Disabled/non-evaluated sensors do not block baseline acquisition. Restore unknown inputs and use **Retry input FIFO baseline** (or Apply) to resample/re-register where necessary. The bounded incident cannot be cleared while an active input remains unknown.

Recovery does **not** require acknowledgement to continue processing and adds no Module 2 arming gate. Historical loss cannot be repaired from current values. The incident survives recovery until explicitly cleared.

| Visible item | Meaning/action |
|---|---|
| Input FIFO Health | Disabled, running, Apply waiting, unknown inputs or recovery pending. |
| Input FIFO Incident | Retained timestamp/reason. Restore unknown inputs or inspect known loss before clearing. |
| Module 1 Mermaid webpage | Authenticated status panel refreshes every five seconds, outside graph filters. It shows health, incident and the last retained fault. |
| Retry input FIFO baseline | Retry current-state acquisition after restoring inputs. Does not invent historical edges. |
| Clear recovered FIFO incident | Clears only the warning after valid current monitoring; it is not required for automatic recovery. |
| Print input FIFO report | Read-on-demand JSON with session/previous-session metrics, unknown inputs and limits; last_fault retains the last published fault through disabling/recreation, and previous_session.recovery_fault identifies available evidence captured before recovery cleared the current fault. Later observations can coalesce under fault-lock contention. |

The report distinguishes applied `configured_enabled`, actual-runtime `enabled`, `activation_blocked` and `health`. It now includes `input_diagnostic`, with bounded native contract observations collected while live FIFO is disabled. An enabled checkbox is not evidence of activation. Ordinary Apply, an idle worker, elapsed time and library reload do not prove that old untracked evaluations ended. Do not clear the retained guard.

## Bounds and performance

Queue: 128 records / 256 KiB combined serialized bytes / 16 KiB per record. Configuration, evaluation state (mirror + maps) and ingress each cap at 512 KiB. At most 1,024 dependencies/sensor rows, 256 classes/groups and 16 source entries per ingress bucket. Strings cap at 1,024 valid UTF-8 bytes; floats must be finite. COUNT history caps at 2,048 per class and 8,192 total. Limits cause an explicit degraded condition, never unbounded allocation. A configuration exceeding global supported capacities cannot activate this evaluator successfully; disable FIFO to return to the existing evaluator.

These are serialized bounds, not a PHP resident-memory budget. Arrays, copy-on-write state snapshots, current payload strings, formatting and synchronous receivers add overhead. A whole evaluation-state size check is performed before dispatch for each evaluated frame; persistent runtime-map/mirror writes occur once per completed batch. No routine event logging, archive writes, resident idle polling or full-queue rewrites are added. Recovery polls once per second only while fault/startup acquisition is pending. The existing webpage gains one lightweight status request every five seconds while open.

The diagnostic hotfix adds one bounded retained fault record, with a reason capped at the existing 512-byte prefix (UTF-8 replacement can add a few bytes). It writes only on faults or when retaining an unavailable-detail fallback at recovery, suppressing identical record writes. Native type/byte descriptions are built only for rejected inputs; sensor strings are not retained. Healthy input admission, queue ordering and recovery/dispatch policy are unchanged. CPU/resident RAM remain unmeasured; these anomaly-only diagnostics do not establish FIFO performance or correct the production recovery loop.

## Current production procedure

Leave live FIFO disabled, Apply and confirm ordinary heartbeat. Update from `design/module1-fifo`, start **Start passive FIFO input check (15 seconds)**, then use **Print input FIFO report** after about 15 seconds. Do not change configuration during capture. Send the complete report. No extra alarm outputs, event injection, service restart or live FIFO activation are requested. Detailed limits and API calls are in the [passive diagnostic instructions](module1-fifo-passive-input-diagnostics.md).

Actual live FIFO acceptance, recovery-loop resolution and restart-free cutover remain deferred. Rollback cannot recover observations or undo actions already sent.

This branch is not automatically merged or deployed. Modules 2/3, receiver acceptance/retry/deduplication, historical PSM evidence, intrusion cancellation and expanded PSM webpage/acknowledgement controls remain outside scope; see the [accepted design](module1-fifo-design.md) and [deferred backlog](module1-fifo-coordinated-backlog.md).
