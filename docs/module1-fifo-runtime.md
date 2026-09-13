# Module 1 live input FIFO — supervised opt-in build

Build marker: `Version2.14.0-fifo.3`. This includes the [bedroom form/COMMIT repair](module1-bedroom-commit-recovery.md) and [retained recovery diagnostics](module1-fifo-recovery-diagnostics.md). Default: **disabled** (`EnableInputFifo=false`). Production revealed repeated recovery despite resolved unknown inputs; keep live FIFO disabled except for the short supervised diagnostic capture described in that document. The cause is not yet established. This is a live alarm-processing option, unlike the preceding shadow comparator. No Module 2, Module 3 or heartbeat watchdog runtime files change.

## Evidence and limits

Artur supplied two clean shadow captures on 13 September 2026: 61 and 174 admitted/evaluated records, zero decision mismatches, empty queues and no lifecycle fault. All heartbeat targets were OK during both captures. In the second capture, Module 1 callback latency was 113–196 ms against the existing 45-second timeout. The second ring wrapped, but its peak occupancy was only two: it did not establish heavy concurrent-load behavior.

Artur has no CPU/resident RAM records and authorized proceeding without them. Their production impact remains **unmeasured**; serialized sizes and worker elapsed time are not substitutes. Previous shadow timing is evidence for the comparator alongside the old live path, not a measurement of this new FIFO's synchronous delivery latency.

The executable model passes 343 checks: safety 129, read-only probe 44, shadow 64, live FIFO 82 and configuration form 24. The new suite covers captured raw/formatted payloads, bedroom mirrors, refresh suppression, ordinary heartbeat-shaped token/reset frames, COUNT references/sync, pulse expiry, saturation/prefix drain, Apply cutover, interface recreation, dynamic tamper subscriptions, missing/disabled dependencies, recovery seeding, ownership races, consumer reentrancy, shutdown wake, partial frame/metadata failures, baseline callback failure and UTF-8 fault handling. Diagnostics also retain later failure evidence across repeated zero-processing recoveries, incident acknowledgement, disabling and interface recreation, without storing oversized input payloads. Tests model selected interleavings; they are not a native concurrency stress test.

## Runtime behavior

- Native input admission uses a fixed 128-slot ring, individually serialized slots and 128 small dependency buckets. No whole-queue string rewrite or configuration parsing in the producer. Admission mutex waits at most 1 ms; values retain their PHP scalar types. Ordinary unchanged values are suppressed before queue admission. Rapid A→B→A changes remain separate.
- A single worker runs the existing Module 1 rule evaluator, payload formatting, routing and synchronous delivery. Sensor, comparison-reference and bedroom reads use its captured mirror. Captured values are formatted with [GetValueFormattedEx](https://www.symcon.de/en/service/documentation/command-reference/access-variables/getvalueformattedex/), which does not reread the current sensor value. The existing payload schema and receiver policies remain.
- Pulse controls and requested state sync enter the same queue. Evaluation uses admitted wall seconds for pulse and COUNT rules, preserving legacy integer-wall-second behavior and shared per-variable caches. Runtime maps are cached in the worker and persisted once per batch, rather than parsed/written by every pulse rule.
- Worker wake is 50 ms; maximum batch is 32 records with a 20 ms budget checked between records. Synchronous receiver calls cannot be interrupted by this budget. Worker timers stop at an empty queue, and shutdown shares queue ownership with producer wake scheduling.
- Heartbeat has no priority, reserved capacity or bypass. Tokens and resets use the same admission, worker, evaluator, throttles and delivery as every other sensor input.
- Apply waits for worker ownership and a drained old-configuration prefix before activating a new graph. Startup/recovery samples current values twice and checks a setup-observation generation. This detects observed movement but is not atomic physical sampling. Inputs crossing the restart/configuration baseline boundary may be unavailable as historical edges; the incident remains visible.
- Legacy evaluation remains the default. A short ownership marker allows safe opt-in cutover. If legacy calls overlap, existing concurrent behavior continues while FIFO activation is blocked until the module interface is recreated. This prevents activating over an untracked running legacy evaluator.

## Fault and recovery policy

Admission failure, invalid observations, prior-value gaps and overflow latch a bounded time/reason incident. No inline out-of-order evaluator is used. Admission freezes after known loss; the trustworthy retained prefix drains once. The worker then rebuilds a current baseline automatically. COUNT timestamps from completed records remain eligible within their original window. CHANGE/ONCE caches are seeded without manufacturing events; known pulses retain their original deadlines and a trustworthy false ONCE condition clears its pulse.

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

## Bounds and performance

Queue: 128 records / 256 KiB combined serialized bytes / 16 KiB per record. Configuration, evaluation state (mirror + maps) and ingress each cap at 512 KiB. At most 1,024 dependencies/sensor rows, 256 classes/groups and 16 source entries per ingress bucket. Strings cap at 1,024 valid UTF-8 bytes; floats must be finite. COUNT history caps at 2,048 per class and 8,192 total. Limits cause an explicit degraded condition, never unbounded allocation. A configuration exceeding global supported capacities cannot activate this evaluator successfully; disable FIFO to return to the existing evaluator.

These are serialized bounds, not a PHP resident-memory budget. Arrays, copy-on-write state snapshots, current payload strings, formatting and synchronous receivers add overhead. A whole evaluation-state size check is performed before dispatch for each evaluated frame; persistent runtime-map/mirror writes occur once per completed batch. No routine event logging, archive writes, resident idle polling or full-queue rewrites are added. Recovery polls once per second only while fault/startup acquisition is pending. The existing webpage gains one lightweight status request every five seconds while open.

The diagnostic hotfix adds one bounded retained fault record, with a reason capped at the existing 512-byte prefix (UTF-8 replacement can add a few bytes). It writes only on faults or when retaining an unavailable-detail fallback at recovery, suppressing identical record writes. Native type/byte descriptions are built only for rejected inputs; sensor strings are not retained. Healthy input admission, queue ordering and recovery/dispatch policy are unchanged. CPU/resident RAM remain unmeasured; these anomaly-only diagnostics do not establish FIFO performance or correct the production recovery loop.

## Supervised production procedure

1. Keep the working `Version2.14.0-shadow.3` / commit `874ac87e3e17fb165b1387637e942ffef9e2eb1f` as the return point. The existing main branch is a second known fallback. Export configuration before native update.
2. Update Module Control from `design/module1-fifo`. Initially leave **Use FIFO for live Module 1 alarm processing** disabled. Stop/disable the shadow comparator for this test. Confirm Module 1 creates normally, configuration integrity is healthy, and all four heartbeat targets complete normally.
3. While present, enable the new checkbox and Apply. Confirm Input FIFO Health is running, `ready=true`, no unknown input/fault, and no pending Apply. Monitor at least five complete heartbeat cycles with every target OK.
4. Under the existing alarm policy, exercise a normal door open/close and approved house-state transitions. Inspect live Module 2 behavior, Module 3 results and the FIFO report. Existing M2 live rereads can still miss an open/close that completes before its own evaluation; this is an accepted narrower-scope limitation.
5. Continue supervision through representative normal traffic. Native heavy bursts and slow synchronous receiver behavior remain production-validation items. Do not deliberately overload live sensors or introduce additional siren/ASK outputs to prove capacity; bounded overflow is already tested in the executable model.
6. If heartbeat becomes missing, latency grows persistently, an instance fails to create or current monitoring cannot recover, disable FIFO and Apply after the retained prefix drains. If that cannot complete, return Module Control to the prior working build. Rollback cannot recover observations or undo actions already sent.

This branch is not automatically merged or deployed. Modules 2/3, receiver acceptance/retry/deduplication, historical PSM evidence, intrusion cancellation and expanded PSM webpage/acknowledgement controls remain outside scope; see the [accepted design](module1-fifo-design.md) and [deferred backlog](module1-fifo-coordinated-backlog.md).
