# Module 1 FIFO and event integrity: proposed design

Status: independently reviewed and approved as a staged architecture on 2026-09-13. Native-runtime validation, measured budgets and the pending-output cancellation decision remains a prerequisite to implementation acceptance; unattended recovery and webpage visibility were accepted by Artur. No implementation or production change.
Baseline: main commit `59e413ead4d6a85a5336583af7788ee9175d0a49`, Module 1 v2.13.3 / Module 2 v7.3.2.
User: Artur Fischer. Installation: one Module 1, one PSM, separate Intrusion/Hazard/Technical response instances and a heartbeat destination. Production checks for PR #2 were accepted by Artur.

## Decision and scope

Use a bounded inbound FIFO with one state evaluator, followed by independent per-target delivery queues. Capture the value carried by the sensor update, rather than rereading a newer value when processing it. Extend the Module 2 and Module 3 contracts where necessary to preserve event meaning and confirm acceptance. A Module 1 queue alone is an intermediate milestone, not a complete alarm-delivery guarantee.

The original earlier FIFO proposal was not recovered from personal-context search. This document is a replacement proposal grounded in the merged code, not a claim to reproduce previously agreed details. Numeric limits below are starting budgets for measurement, not validated production settings.

Guarantee: within a running session, admitted records are processed in admission order. Committed evaluations are never intentionally repeated; an ambiguous partial commit stops with a fault. Delivery retries retain immutable intents within explicit capacity/age limits; inability to retain one produces an explicit target gap. Acceptance, evaluation and output execution are different stages. This design does not promise physical sensor-time ordering, indefinite buffering, crash-proof delivery, or exactly-once siren/email/security-service execution.

## Evidence in the current code

| Finding | Consequence | Baseline location |
|---|---|---|
| MessageSink ignores message Data and rereads GetValue; CheckLogic and rule evaluation read again | A rapid open/close may be represented only by the final value | SensorGroup/module.php: MessageSink, CheckLogic, CheckSensorRule |
| Only token allocation is serialized; state maps and evaluation are not protected as a single transaction | Overlapping executions can overwrite state or deliver out of order | SensorGroup/module.php: GetNextEventToken, CheckLogic |
| Token epoch is wall-clock milliseconds per event; lock failure returns seq=0 | Clock changes and fallback tokens undermine ordering | SensorGroup/module.php: GetNextEventToken |
| Dispatch is synchronous; target throttle skips delivery; non-throwing RequestAction is treated as success | Slow receivers affect processing; no explicit acceptance guarantee or automatic retained retry | SensorGroup/module.php: CanSendToTargetNow, DispatchPayloadToTarget |
| Module 2 decides from a fresh live snapshot even when handling an older event | A FIFO cannot by itself recover a brief intrusion already closed | PropertyStateManager/StateIntegrity.php: ReadSafetyInputs, EvaluateState |
| Module 3 deduplicates only pending events, clears its queue before processing, and drops oldest entries at cap 100 | Retries can duplicate work; in-flight work and overflow can lose events | Alarm Response Manager/module.php: ReceivePayload, queue processing |
| Module 3 may use any cached/latest house snapshot | An old event can be evaluated under a newer house state | Alarm Response Manager/module.php: house snapshot selection |
| Module 3 enqueue during an active drain has no guaranteed follow-up drain | A tail can remain queued until another event arrives | Alarm Response Manager/module.php: processor semaphore / queue drain |
| Module 2 push cache advances after any target succeeds | A failed peer can miss an unchanged house-state update | PropertyStateManager/module.php: MaybePushHouseStateSnapshot |

These are code-path risks identified in review, not claims that every failure occurred in Artur's installation.

## 1. Admission and ordering

MessageSink becomes a small producer. Filter by registered message kind and dependency ID; decode and validate the native event value; record the native message counter; enqueue a compact record; request a worker wake. No full configuration scan, formatting lookup, output call, or sensor reread belongs in this path. Debug formatting runs only when explicitly enabled.

Native contract must be established first: Symcon documents MessageSink TimeStamp as an incrementing message counter, not Unix time. Its SDK page does not specify Data layouts. A bounded diagnostic probe must verify the installed version's VM_UPDATE value field, type and change indicator, update ordering, callback concurrency, and startup delivery. Do not silently substitute GetValue when an event value is missing: record an integrity fault. Do not infer packet loss from gaps in a global counter shared with unrelated messages.

Each admitted record contains schema, producer session, input_seq, native counter (when applicable), admission monotonic time, diagnostic wall time, configuration revision, kind, variable ID, typed value, and cause. There is no full sensor graph or formatted payload in each record. input_seq is allocated with the enqueue transaction. The contract is admission order; it is not a claim that concurrent devices have a common physical clock. Native counter regressions within the supported contract are reported as an ordering fault, not repaired by dropping the older event. Sorting only the current batch cannot prove that an earlier callback is not still delayed.

Suppress typed unchanged refreshes against the last successfully admitted value under the same lock. Suppression must occur only for explicitly state-only duplicates. Never coalesce A→B→A into A. Never move the suppression baseline when enqueue fails. Keep PR #2's ordinary unchanged-update policy; do not redefine COUNT as counting every identical VM_UPDATE. Rule-level CHANGE comparison retains its existing float tolerance separately from exact typed ingress comparison.

Use a fixed-capacity ring of individually serialized runtime slots plus small head/tail/count/byte metadata, not a JSON array rewritten in full for every event. Startup-only allocation is bounded. Enqueue and dequeue locks cover slot/metadata updates only, never evaluation or external calls. A short lock timeout is a fault, not permission to process inline out of order. No seq=0 fallback. A worker-ownership semaphore is separate; producers never wait for it.

All operations that change interpreted event state enter this ordering domain: sensor changes, dynamic references, bedroom usage, object deletion/invalidation, pulse-expiry controls, requested sync, delay completion and accepted configuration cutover. Apply only stages/schedules work, retaining the lifecycle fix from PR #2. No remote PHP module calls inside Apply/Create.

## 2. Single evaluator and event-state mirror

One worker owns the mirror of dependency values and per-rule state. Evaluate a record by updating that mirror and evaluating affected rules/classes/groups under its pinned configuration revision. Reads inside historical evaluation use the mirror for both sensor and dynamic comparison reference. Mixing a captured sensor value with a current reference is prohibited.

Compile reverse dependency indexes on activation, including tamper and bedroom dependencies. Rule state is keyed by stable rule identity, not just VariableID: a variable may occur in multiple classes/rules. Preserve LEVEL/CHANGE/ONCE semantics, baseline-only first observation, direct-trigger COUNT qualification, and the rule that reference changes do not increment COUNT. Configuration migration must define identities for duplicate rows; do not silently merge their pulse state.

Use admission monotonic time for elapsed windows and pulse deadlines within a session. Native message counters are not durations. Before an input/control at time T, process expiries due before T; define equality consistently as expiry-before-input. Thus a backlog cannot stretch COUNT windows or revive expired pulses. A captured pulse still produces a historical edge record even if its live indication has expired by drain time. Sync, restart baselines and recovery scans manufacture no edges or COUNT hits.

After each record, commit the new mirror/rule state and immutable output intent once. Delivery retries reuse the intent; they never rerun the evaluator. The worker retains the head until its state-and-intent commit is complete. If an exception makes commit status ambiguous, stop replay of that head and latch an integrity fault; do not guess whether to increment COUNT again. Process termination is handled by the session-gap policy below, not claimed to be atomic across native buffer writes.

Wake protocol: arm a bounded retry timer when work exists; a worker that loses ownership returns, leaving a wake pending. Under the queue lock, the worker observes empty and disables its timer atomically with the producer's empty→nonempty scheduling decision. Recheck before releasing ownership. A tail added during drain must get a later turn even if all producers then become idle. Do not start a script for every event. No blocking sleeps.

## 3. Module 2: ordered evidence and current authority

Introduce a negotiated protocol alongside the existing live safety API. A safety frame contains producer/session, input cursor, per-target delivery sequence, configuration revision, evaluated role/bedroom values with unknown flags, and event cause. Module 2 consumes these frames and its control barriers through one state-machine owner. Apply, sync, recovery, manual reevaluation and DelayTimer may request a barrier; they may not bypass queued evidence with an out-of-band state transition.

Process historical observations in order. A window breach while the stream's state is Armed is recorded as an alarm before a later entrance unlock is applied. Freeze a decision record (state before, state after, armed origin, relevant presence, state version and triggering cursor) for Module 3. A later live read must not rewrite what happened. A stale historical unlock must not clear a newer alarm: all accepted disarm/arming controls carry a generation/state-version check and follow earlier admitted frames.

New arming and armed-mode completion require both a drained ordering barrier and a fresh valid live safety snapshot under the same configuration revision. If live values disagree with the mirror, postpone the transition and reconcile the discrepancy; never use a later secure value to jump over a queued breach. Events admitted during the freshness check invalidate the barrier and require another pass. This is a logical admission barrier, not an atomic physical-house snapshot; changes after the final check remain normal subsequent events.

Retain existing policy: trustworthy entrance unlock disarms; a used-bedroom opening disarms Internal; unknown inputs alone preserve Armed/Alarm; trustworthy intrusion remains actionable when another input is unknown. Internal↔External changes retain existing protection until the full delay completes. Normal live display can show newer observations, clearly separate from the ordered decision cursor.

The existing GetSafetySnapshot remains useful for fresh validation and recovery. Add event-integrity health separately from sensor readability. A readable snapshot after lost events cannot prove that no intrusion happened in the missing interval. Repair Module 2's per-target state-push acknowledgement/cache when adding the new protocol; one successful recipient cannot acknowledge another.

## 4. Per-target delivery and Module 3 acceptance

The evaluator emits immutable intents into separate bounded queues for PSM, Intrusion, Hazard, Technical and any other configured target. One unavailable target must not hold the input lock, evaluator, or another target's delivery queue. Shared payload content may be stored once with bounded references; target projections are immutable. Each sender worker releases queue locks before calling a receiver. Limit concurrent sender workers to one per configured target, with a configured overall cap. Never hold M1 evaluator ownership during Module 3 output I/O.

If an outbox cannot retain a new intent, commit the input evaluation and healthy-target intents once, record a bounded gap marker for the failed target, and quarantine further delivery to that target's session until recovery. Do not hold the input head indefinitely or rerun its COUNT/pulse effects. Already-retained target work remains owned and explicitly dispositioned; it is not evicted to make room. The gap marker contains first/last affected input cursors, a count and reason, not an unbounded list of lost payloads. Subsequent quarantined intents extend that marker. Recovery automatically resolves or explicitly dispositions the retained prefix, records the gap, negotiates a new target session/baseline, and resumes future delivery. User acknowledgement is not a prerequisite to protocol recovery. This sacrifices continuity for the saturated target visibly while protecting independent routes. If that target is PSM, state-dependent M3 deliveries cannot proceed without context; state-independent Hazard/Technical routes still can.

Use explicit acceptance responses: accepted, duplicate-accepted, busy/retry, invalid, unsupported-session. Acceptance means the receiver owns a retained queue/in-flight record, not that an output has run or survived a process crash. Normal return from void ReceivePayload is not an acknowledgement. Legacy scripts and receivers remain explicitly best-effort until they implement the protocol; no automatic blind retries of side-effecting legacy handlers.

Identity is (source, session, target, delivery_seq). delivery_seq is contiguous per target; input_seq/global event_seq may have routing gaps. A session includes a fresh random 128-bit boot nonce plus a persisted diagnostic generation advanced once per new session, independent of wall-clock time. The nonce prevents identity reuse even if a backup rewinds the generation. Consumers negotiate the current session with the configured live source; receiving an unknown-session packet is not by itself permission to adopt it. Do not compare the new identity against the old wall-clock epoch comparator. Old-session messages cannot replace a current session or clear its alarms.

The sender retains the head until explicit acceptance. Retry that exact identity with bounded backoff; do not overtake a failed head for the same target. Capacity and retry age bound retention. A permanent invalid response or expiry enters a visible fault/quarantine state; no silent success or oldest-item eviction. A separate compact control path carries target/session status so failure of the affected queue cannot hide its own fault.

Module 3 must deduplicate pending, in-flight and recently completed accepted identities. Define a bounded accepted-sequence window and reject unknown old identities; the dedup window must cover the sender's maximum retry window. Keep in-flight ownership until processing reaches a recorded result; do not clear the whole queue before executing it. Schedule another drain when a producer arrives during processing. Admission must return promptly and never perform siren/network/output work itself.

Bind each alarm to the matching Module 2 decision context. Direct M1→M3 payloads may arrive first; M3 holds them in a bounded context-wait state, without substituting ANY latest snapshot. Hazard/Technical routes that do not depend on house state may proceed independently. Preserve configured severity grouping and output aggregation; FIFO governs event admission and state evolution, not a promise to execute every physical output in raw event order.

Every event requiring house-state context gets an ordered PSM context barrier for that input cursor, including motion events that change none of PSM's mapped roles. The barrier is a protocol control frame and is not suppressed as an unchanged state update. PSM emits an immutable context for that cursor after all earlier delivered frames/controls. Capability/configuration negotiation identifies state-independent routes; absent an explicit exemption, request context. A context acknowledgement releases only the context record after all intended consumers have accepted it; receiver ownership covers the record while outputs remain pending. Missing context is a visible bounded delivery fault, not an indefinite wait or permission to use a newer state.

Accepted stale-output policy (Artur, 2026-09-13): a newer trustworthy disarm cancels not-yet-started intrusion siren/ASK actions from the older armed generation; retain the intrusion record and eligible notifications with their original context/time. Classify cancellable intrusion actions explicitly by configured OutputID/bundle; never infer from device type or cancel unrelated Hazard outputs. Cancellation is explicit and recorded, never reinterpretation as 'no intrusion'. Actions already executed cannot be undone.

The separate control path has fixed per-source/target slots: current negotiated session, latched fault, highest authenticated state/control version, and the highest cancelled armed generation for that session. Superseding an older fault/state advertisement is permitted; cancellation is cumulative and monotonic, so a later arm does not erase an earlier disarm fence. Reject regressing/old-session controls and acknowledge accepted versions. Check the cancellation fence immediately before initiating an action. A disarm can prevent work not yet started at the receiver only after that control is admitted; no claim is made to cancel I/O already in progress or beat an undelivered network/control message. Control-delivery failure remains visible independently of a full data queue.

Output results distinguish executed, policy-suppressed, cancelled, failed and ambiguous. Current M3 latch-after-failure behavior must be addressed before any output retry is enabled. Automatic retries apply to acceptance only. External outputs without idempotency support cannot have an exactly-once guarantee, especially if the process dies after sending but before recording success.

## 5. Failure, restart and configuration policy

| Condition | Required behavior |
|---|---|
| Input lock timeout, ring full, oversize/malformed event, detected ordering regression | Do not silently overwrite an admitted record. Latch integrity fault, preserve admitted evidence, block new arming, continue trustworthy protection and raise a technical indication. |
| Slow/full target | Isolate that target; retain within its limits; expose backlog/age. Other targets continue. |
| Target acceptance is uncertain | Retry same identity only with deduplicating protocol; legacy path reports uncertainty instead. |
| Clean planned restart/update | Drain admitted work through a quiescence barrier and record a clean checkpoint; establish a fresh baseline after restart and recover automatically. The downtime remains visible as a monitoring interval, not proof that no physical event occurred. |
| Unclean restart or known abandoned work | Start a new session, record loss of continuity, seed fresh state, preserve prior protection and recover automatically once the ordered baseline and current inputs are valid; never replay unknown old siren actions. |
| Configuration change | Validate/stage first. Order a cutover after old records; finish old records with their old plan. If bounded draining cannot finish, reject/defer activation or explicitly abandon with a gap—never reinterpret old rows using the new plan. |
| Fresh values recover | Restore current readability separately from continuity; keep incident visible. After ordering/baseline recovery and fresh valid inputs, allow automatic arming with a complete new delay; retain the incident until acknowledged. |

Loss reporting must not depend on obtaining the failed input lock or enqueueing into the full ring. Use an idempotent latch plus independent diagnostics; no unprotected increment is claimed to count every concurrent failure. Separate the active continuity fault from the persistent incident acknowledgement. Clear the active fault only through a successful recovery/session handshake after dispositioning retained callbacks and establishing a new baseline. A timed-out/incomplete handshake leaves the active fault set. Incident acknowledgement does not perform that recovery or authorize an unsafe transition. Never race an automatic 'healthy' clear against new producer failures.

Persist a small session-unclean marker before enabling admission, and a clean checkpoint only after a successful controlled drain/quiescence of producers, in-flight decisions and receiver ownership. The handshake must establish that no producer from the previous session can subsequently publish; if native shutdown cannot establish that, classify the restart as unclean. Do not write a durable marker for every sensor update. A clean checkpoint proves absence of known abandoned work, not absence of sensor activity during downtime. Artur accepted automatic unattended recovery on 2026-09-13: acknowledgement clears an incident and never gates arming. Already-armed protection and trustworthy disarming remain available throughout degraded recovery. Disarmed/Exit Delay returns to arming eligibility only after fresh valid inputs and ordered baseline recovery, starting a full delay. Persistent invalid inputs still block new arming.

Bootstrap is also a cutover: subscribe/capture into the bounded ring, seed values with per-dependency admission versions, discard/retry reads invalidated by concurrent admissions, then reconcile the queued stream against the chosen baseline before arming. Do not seed with a newer live value and blindly replay an older queued value over it. A native callback delayed before admission cannot be solved by this handshake; report the admission-order guarantee honestly and test counter regressions. On mismatch or a non-converging baseline, stay degraded and preserve prior protection.

The seed/uncertain overlap is baseline-only: do not manufacture historical edges or infer 'no intrusion' across it. Mark continuity uncertain until a usable baseline/cutover is established. The exact probe-supported seeding algorithm is a prerequisite to implementation acceptance, not a claim that per-variable version checks supply an atomic physical snapshot.

Volatile input storage is deliberate to avoid synchronous disk writes per sensor event. Lossless recovery across power failure would require a durable journal and measured fsync/storage behavior; it is a separate decision, not something obtained for free from FIFO. Restore must rotate protocol identity so old accepted-sequence state cannot silently suppress new alarms.

## 6. Load budgets and observability

Starting prototype limits: input ring 512 records AND 256 KiB total, maximum individual input 2 KiB; worker at most 32 records or 20 ms of evaluation per turn (whichever first, checked between records). Per-target outbox initially 32 records AND 256 KiB; reject oversize output intent visibly rather than truncate it. Bounds include retained in-flight records, metadata and dedup/context-wait storage. These figures require payload-size and burst measurements against all 393 rules before acceptance; the smallest byte/record limit wins.

Add a module-wide byte ceiling: initially 2 MiB for all queue payloads/references, decision contexts, dedup records and protocol control slots combined, including in-flight ownership. Reference sharing does not bypass accounting. Mirror/configuration caches and PHP object/JSON decoding overhead are measured separately against the thread's memory limit. Global-cap exhaustion uses the same explicit gap/quarantine policy; it never allocates an unlimited context ledger or retry list.

Queue lock wait target is at most 5 ms, with lock-held work targeted below 1 ms; ownership acquisition is nonblocking. These are measurement targets, not deadlines guaranteed by PHP or Symcon. One record or a synchronous foreign call cannot be preempted by a 20-ms loop check. Keep output I/O in isolated receiver workers and measure stalled calls/thread consumption. No lock stealing on an elapsed lease while an old worker might still run.

Initial wake/backlog continuation target: 50 ms while work exists, stopped when empty. Delivery retry candidates: 250 ms, 1 s, 5 s, 30 s capped, with a maximum retention-age policy chosen per target during design validation. Respect existing target/output rate limits: a rate limit defers delivery within bounds, it does not silently discard an alarm or bypass configured output throttling. A queue cannot absorb sustained arrivals above service rate; record latency/high-water marks and fail visibly before memory grows without bound.

Measure CPU, peak PHP memory, serialized bytes, native slot overhead, evaluation p50/p95/p99, oldest age, lock contention, active workers, and throughput with real rule/payload shapes. Ensure complete idle shutdown of new timers, no filesystem/network calls in admission, no full-string queue rewrite, no archive logging of queue counters by default, and no routine per-event log lines. A low-rate summary while active (e.g. once per 30 seconds) and immediate health transitions are sufficient. Cache graph/labels by revision; avoid repeated 393-rule scans and unnecessary formatting reads.

A small native probe is mandatory because the previous stub missed wrapper defaults and interface lifecycle behavior. The probe reports metadata/value shapes and timings only; it must not inject alarms, call outputs, or alter sensor values. Capture duration and log volume are capped and stop automatically.

## Visible status and required action (accepted user requirement)

Artur requires status and the required action to always be visible, ideally on the existing Module 2 house-state/Mermaid webpage (`/hook/psm_logic_<InstanceID>`). Place a persistent status panel beside the diagram, visible without expanding logs or hovering. Show current house protection, sensor/input health, event-processing/delivery health, affected destinations, recovery progress, the last successful status refresh, and outstanding incidents with time/reason. Never equate Armed with fully healthy delivery.

| Situation | Visible status and action |
|---|---|
| Fully healthy, no incident | Monitoring healthy. No action required. |
| Recovery running | Protection state plus degraded/recovering status. Automatic recovery in progress; no acknowledgement required to resume. |
| Invalid inputs or failed destination persist | Identify the input/destination, explain the affected protection, show retries and the concrete corrective action. New arming blocked only while required validity/recovery conditions fail. |
| Recovered with an unacknowledged gap | Monitoring restored; incident remains visible. Review/acknowledge the recorded gap when available. Automatic arming is enabled under normal rules. |
| Webpage refresh fails or becomes stale | Status unavailable/stale with last refresh time. Check connection; never leave an old Healthy status displayed as current. |

Acknowledgement is an explicit action on the displayed incident ID/version. It marks that incident reviewed; it does not disable the alarm, clear an active fault, erase evidence, or acknowledge a newer concurrently raised incident. Store bounded incident summaries and unresolved incident count; combine repeated equivalent faults without unbounded growth. Expose the same status/action fields as native variables for other dashboards. Use the existing webpage refresh mechanism, changed-only state publication and anomaly-only logging rather than new steady sensor polling. Status must still be accessible if a response destination fails; if PSM itself cannot run, page refresh failure must be obvious. A notification is supplementary to this persistent display and follows configured technical-monitoring outputs.

## 7. Implementation stages and acceptance

1. Native contract/load probe and failing executable scenarios. Verify VM_UPDATE data and ordering, timer wake latency and lock behavior. No event-path replacement yet.
2. M1 capture, mirror, rule identities, worker and control/cutover ordering. Run shadow evaluation with outputs disabled for the shadow path; compare to production without duplicate actions. Do not advertise end-to-end reliability at this milestone.
3. Coordinated M2 historical evidence/control barriers and M3 acknowledgement/context/dedup/drain protocol. Include per-target PSM state pushes and output-result semantics. Negotiate capabilities; incompatible combinations must show degraded/best-effort status.
4. Enable the coordinated path in supervised production with rollback of all affected modules together. Record new session on rollback; no incompatible queued-event replay. User's production-only sensor access is accepted.

Required scenarios: rapid false→true→false preserved; concurrent callbacks and counter regressions; unchanged refresh suppression; same variable in multiple rules; dynamic references; COUNT windows and ONCE/CHANGE pulses under backlog; expiry ties; all PR #2 state behaviors; breach-before-unlock versus unlock-before-breach; intrusion during initial/armed-mode delay; fresh snapshot overtaking queued evidence; bootstrap under changing sensors; config cutover with old events; one blocked M3 while other targets run; ambiguous ack and duplicate after completion; arrival during drain with no next event; queue/byte cap; lock timeout; missing decision context; disarm cancellation of pending actions; process crash at every ownership stage; clock rollback; backup restore/session rotation; initialization without cross-module calls in Apply; CPU/memory/timer/thread budgets; unattended recovery without acknowledgement; persistent post-recovery incident visibility; stale webpage detection; acknowledgement racing a newer incident.

Acceptance requires expected state/output outcomes for each scenario, not only FIFO sequence assertions. Production burst/latency results determine capacities and retention ages. No live FIFO code is authorized by this design document alone.

## Other retained backlog items

- Stale telemetry: current value readability is not freshness. Review device-specific watchdog coverage separately; unchanged healthy sensors must not be declared stale merely because their state has not changed.
- Atomic physical-house sampling: not supplied by this FIFO; requires an upstream acquisition guarantee if ever required.
- Multiple PSM consumers: not required for Artur's current installation; needs separate bounded registration and context routing.
- Wider logging/transport redesign: change only the points required for event integrity and measured load; other cleanup remains separate.

## Sources

- [Merged baseline](https://github.com/fisart/MyAlarmSystem/tree/59e413ead4d6a85a5336583af7788ee9175d0a49): SensorGroup/module.php, PropertyStateManager/module.php and StateIntegrity.php, Alarm Response Manager/module.php.
- [Symcon MessageSink](https://www.symcon.de/en/service/documentation/developer-area/sdk-tools/sdk-php/module/messagesink/): timestamp is an incrementing message counter; Data layout is not documented there.
- [Symcon messages](https://www.symcon.de/en/service/documentation/developer-area/sdk-tools/sdk-php/messages/): VM_UPDATE and kernel/interface lifecycle identifiers.
- [Symcon data management](https://www.symcon.de/en/service/documentation/developer-area/sdk-tools/sdk-php/data-management/): runtime buffers and persistent attributes have different persistence semantics.

Independent review and disposition are recorded in [module1-fifo-review.md](module1-fifo-review.md).
