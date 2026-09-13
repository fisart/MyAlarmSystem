# Independent review of the FIFO/event-integrity design

Date: 2026-09-13.
Baseline: `59e413ead4d6a85a5336583af7788ee9175d0a49` (merged PR #2).
Reviewed design: [deferred coordinated architecture](module1-fifo-coordinated-backlog.md).
Current scope: [Module 1-only design](module1-fifo-design.md).

## Scope change after review (2026-09-13)

Artur subsequently limited PR #3 to Module 1 and explicitly accepted the downstream live-state, context, delivery and restart limitations. Modules 2/3 protocol changes, cancellation and expanded webpage status are deferred. The historical verdict and findings below apply to the coordinated proposal; they do not establish independent approval of the revised Module 1-only design or its implementation. Native probing, compatibility checks and focused implementation review remain necessary.

## Historical verdict

Approved as a staged architecture/design. No remaining design-blocking findings after revision. This is not approval to deploy an implementation or claim lossless delivery. The independent reviewer was a separate agent, read the source and complete draft, and did not edit the design or code. The author incorporated the findings and the reviewer reread the revised document before approval.

## Optional native probe review (2026-09-13)

The separate reviewer independently reviewed SensorEventProbe and its [production instructions](module1-fifo-probe.md). Initial callback/session, idle timer/Start and Apply/Start races were reproduced and then resolved through pinned session identity/start-time checks, mutex-owned timer changes and an attribute lifecycle fence. Lifecycle-interruption reporting was corrected. The reviewer reread the fixes and approved the optional observer with no remaining blockers; 43 focused probe checks and PHP lint passed independently. The existing safety regression suite passed 124 checks locally after the test-stub additions.

The probe has bounded capture/formatting, no sensor writes or alarm dispatch, and identical observation handling for heartbeat and other selected inputs. Native callback layouts, semaphore/lifecycle behavior and scheduling latency remain to be observed in Symcon. This approval covers only the diagnostic probe; no FIFO runtime is enabled or approved for production.

## Received native evidence: independent review (2026-09-13)

A separate reviewer independently parsed Artur's production observer report and watchdog history. The [aggregated results](module1-fifo-native-results.md) agree with the independent analysis: 83 typed observations consistently support native value/changed/prior fields, both heartbeat token/reset pairs were distinct, and ten retained cycles were OK at all four monitored targets. No reported capture fault or counter regression occurred. The 714 ms callback was after capture ended and cannot be attributed to the observer capture.

Verdict: proceed to an opt-in, read-only Module 1 shadow implementation; do not enable production FIFO. No further probe is necessary before shadow development for the observed boolean/integer inputs. Float/string dependencies actually used, rapid transitions, concurrent admission, startup/configuration boundaries, full evaluation/dispatch cost and the future worker's shorter scheduling interval still require validation before activation. This evidence does not establish lossless capture or revise the accepted downstream limitations.

## Independent code findings

- Module 1's current dispatch treats a normal return from RequestAction as success without a receiver acceptance acknowledgement.
- Module 2 rereads current sensor state, so an inbound FIFO alone cannot retain a brief intrusion's meaning after the sensor closes.
- Module 3 deduplicates only queued entries, empties its queue before processing, caps the queue at 100 by dropping oldest records, and may leave a newly queued tail without a scheduled follow-up drain.
- Module 3 may use any cached/latest house state, so queued events need matching decision context rather than just FIFO transport.
- Module 3 can update an output latch even after output failure; blindly retrying entire events is not a safe output-recovery policy.
- Module 2's state-push cache is not independently acknowledged per target.

These describe code behavior and risks, not observed occurrences of every failure on the production installation.

## Required design revisions and disposition

| Review finding | Resolution |
|---|---|
| Retaining the input head when any outbox is full conflicts with destination isolation | Commit the evaluation and healthy-target intents once; keep bounded gap/quarantine state for the saturated target. No repeated COUNT effects or global indefinite stall. |
| Motion can need PSM context without changing any mapped safety input | Emit an ordered context barrier for each event requiring context, even if role values are unchanged. Classify context-independent routes explicitly. |
| Per-queue caps omit shared payloads, contexts and control storage | Add a total byte ceiling and fixed control slots, including in-flight ownership and dedup/context records. Cancellation fences are cumulative. |
| Historical breach can be erased by newer live unlock, or stale unlock can clear a newer alarm | Order observations and controls through the same state-machine domain; freeze event decisions and validate control generations. |
| Snapshot seeding can overwrite older queued evidence with newer live state | Treat bootstrap overlap as uncertain/baseline-only, validate a cutover, and remain degraded if it cannot converge. Do not claim atomic physical sampling. |
| Ordinary restarts would become indefinite arming blocks | Distinguish a controlled, drained restart from an unclean restart/known event loss; the latter acknowledgement policy remains a user decision. |
| Cancelling sirens by device type could suppress a Hazard action | Cancellation is proposed only for explicitly classified intrusion OutputIDs/bundles; unrelated Hazard outputs are excluded. |
| Backup restore can reuse sequence generations | Include a fresh boot nonce and negotiate session identity; do not reuse the legacy wall-clock comparator. |

## Prerequisites of the deferred coordinated architecture

1. Verify native Symcon VM_UPDATE data, ordering/concurrency, bootstrap and timer/lifecycle behavior with a bounded read-only probe. The SDK page leaves Data layout unspecified, and the prior regression stub missed native initialization behavior.
2. Measure real rule/payload sizes, burst throughput, latency, memory and lock/thread usage before fixing capacities, wake intervals and retention ages.
3. Implement and verify the subsequently accepted cancellation policy: a newer trustworthy disarm cancels pending intrusion siren/ASK actions, preserving the alarm record and eligible notifications and excluding unrelated Hazard outputs.

## Subsequent user-directed policy revision (2026-09-13)

After the independent architecture review, Artur raised unattended travel as a constraint. He accepted automatic recovery after an unclean restart/known loss once current inputs and the ordered baseline are valid, with a full new arming delay where applicable. Incident acknowledgement does not gate recovery or arming; known gaps remain visible until reviewed. Artur also requires persistent status and required-action display on the existing house-state/Mermaid webpage. He subsequently accepted cancellation of pending intrusion siren/ASK actions after a newer trustworthy disarm, retaining the alarm record and eligible notifications; unrelated Hazard outputs remain excluded. These revisions are recorded in the design and were not part of the earlier independent review. Implementation review must cover unattended recovery, active-fault versus incident separation, stale page status, bounded incident retention, acknowledgement races and cancellation fences, including preservation of notifications and Hazard actions.

No runtime files changed and no live alarm tests were performed as part of this design review. Implementation acceptance must include outcomes and failure cases across all three modules, not only FIFO ordering assertions.

## Module 1 shadow implementation review (2026-09-13)

A separate read-only reviewer inspected the actual Module 1 hooks, bounded ring, session/lifecycle controls, pure evaluator and regression tests. Initial review found native Apply would delete the diagnostic variables; both are now preserved, with actual child/deletion and repeated Apply/start/publish regressions. Stale callbacks/completions/faults are session-bound; a separate short fault mutex serializes publication with new-session setup. Pending/uncommitted comparisons are explicitly incomplete after stop. Ready-head wakeup and queue/worker ownership were verified, and slow-host batches attempt at least one ready frame before checking the elapsed budget.

Final verdict: approved for bounded, opt-in Module 1 shadow testing, with no remaining code-review blockers. All 55 focused shadow checks pass independently. The existing 124 safety checks and 43 probe checks pass locally. The shadow worker performs no alarm dispatch, and heartbeat has no priority or bypass. Approval excludes actual production FIFO activation, native burst/load acceptance and resident-memory claims. [Production instructions and measurement limits](module1-fifo-shadow.md) accompany the build.

## Follow-up shadow gap diagnostics (2026-09-13)

The first production shadow report had 35 admitted/processed records with no projection mismatches but a baseline/event gap. A follow-up diagnostic build adds first-fault time, sensor/counter and typed conflicting-value details, plus stop lateness. The strict admission check and existing alarm path remain unchanged. Independent review caught a UTF-8 boundary problem in clipped fault values; descriptions now retain a valid prefix within 96 bytes. Real boolean/string native-gap regressions verify evidence retention, live dispatch and diagnostic stop. The focused suite passes 64 checks.

Final follow-up verdict: independently approved for diagnostic-only shadow testing, with no remaining targeted code-review blockers. The cause of the production gap remains unproven; actual FIFO activation is not approved.

## Native reload recovery review (2026-09-13)

After the native log identified a rejected bedroom draft and optional-probe Destroy failure during library reload, the author reproduced lost input subscriptions after a rejected Apply on a recreated interface. Module 1 now restores missing subscriptions from the revalidated last-active graph and a stopped pulse-expiry wake without promoting rejected properties or generating events. Probe Destroy performs no withdrawn-interface access.

A separate read-only reviewer approved this hotfix for guarded recovery testing, finding no implementation blocker. It independently ran 127 safety, 44 probe and 64 shadow checks; two additional author checks exercise the exact production malformed bedroom types and draft/active/COUNT separation, yielding 129 safety checks in the final suite. The reviewer confirmed work is confined to rejected Apply rather than per-event processing. Native causation is code-supported/reproduced, not directly proven from an unrecorded subscription state. Shadow testing stays paused until fresh heartbeat cycles are OK at all four targets. [Recovery instructions](module1-fifo-reload-recovery.md).


## Live FIFO implementation review — 13 September 2026

The independent read-only reviewer first rejected the runtime for ownership/cutover races, recovery traversal of disabled rows, missing dynamic tamper subscriptions, fault-latch contention and inherited volatile ownership. Root corrected these and added executable interleaving tests. A second review caught baseline failure after a reentrant enqueue; the recovery path now classifies that dependent queue and restores the seeded maps instead of deadlocking. Bounded UTF-8 fault summaries and previous-session discarded evidence are covered.

Final independent rerun: 129 safety + 44 probe + 64 shadow + 69 runtime checks passed; `git diff --check` clean. No blocking correctness findings remain. Approval is for publication and supervised opt-in production testing only. Native live FIFO synchronous dispatch timing, heavy concurrency, CPU/resident RAM and downstream detection are not established by the earlier shadow captures. See the [runtime/testing instructions](module1-fifo-runtime.md). No M2/M3/watchdog runtime change.


## Bedroom form/COMMIT hotfix review — 13 September 2026

The independent reviewer confirmed that non-editable hidden BedroomList columns lacked explicit persistence under the documented Symcon List contract. Static and generated fallback now set save:true and use supplied working rows. Explicit RestoreActiveBedroomDraft stages only validated running bedroom rows, preserving sensor deletions and runtime state until COMMIT. Legitimate edits/intentional deletion still follow explicit draft authority; invalid active configuration cannot be used for restoration.

Independent rerun passed 330 checks: 129 safety, 44 probe, 64 shadow, 69 FIFO runtime and 24 form tests. Approved for publication; native console persistence still requires confirmation. This hotfix does not change the FIFO evaluator/heartbeat path or M2/M3 runtime. See [recovery instructions](module1-bedroom-commit-recovery.md).

## Recovery diagnostic hotfix: Version2.14.0-fifo.3

Artur's post-cleanup production report reached 149 recoveries, with zero admitted/processed events in both displayed sessions and seven omitted observations after a fault in the previous session. The original startup incident masked the later recovery reason because current faults were cleared at baseline replacement. No sustained production acceptance is granted; keep FIFO disabled outside a brief supervised diagnostic capture.

Independent read-only review approved the diagnostic repair and reran the initial 79-check FIFO suite. It verified one bounded durable fault record, prior-session evidence before clearing, malformed native types/lengths without sensor-value retention, existing lock ordering and safe Mermaid text rendering. Review clarified that `last_fault` is the last successfully published fault: later observations can coalesce under fault-lock contention. Root incorporated that limitation into documentation and added a targeted coalescing regression. Final FIFO suite passes 82 checks; the five suites total 343 checks, with changed PHP lint and whitespace checks clean.

Healthy admission, heartbeat handling, recovery and output policy remain unchanged. Attribute writes occur only on anomalies/recovery and identical retained records are suppressed; CPU/resident RAM are unmeasured. This is approval to publish diagnostics, not a claim that the production recovery loop is corrected. See [short capture instructions](module1-fifo-recovery-diagnostics.md).

## Activation guard correction: Version2.14.0-fifo.4

Applied FIFO=true with Healthy configuration still reported runtime disabled and a retained concurrency blocker. Root reproduced a false latch when worker acquisition failed but ownership had already switched to FIFO. The patch checks Cutover/Owned before latching, then latches genuine unowned legacy entry and rechecks Cutover before Owned again. Independent read-only design review examined all 210 operation interleavings of one caller and one serialized Apply, with no unsafe overlap under sequentially consistent attributes; actual diff review approved the ordered protocol and independently reran all 89 FIFO checks without blockers.

All five suites total 350 passing checks, with changed PHP lint and whitespace validation clean. Reports distinguish applied configuration, runtime ownership, activation blocking and health. No new waits/logging/parsing/polling enter the event path. Genuine historical overlap is retained; ordinary Apply does not reset it. Earlier library-reload and restart/capture advice is superseded by the no-restart constraint below. The separate production recovery loop remains unresolved, and continued live-FIFO acceptance is deferred.

## Restart-free design and passive diagnostic review: Version2.14.0-fifo.5

Artur rejected a production Symcon restart because unrelated services must continue. Independent review rejected clearing the historical guard using new tracking, a timeout or an idle worker: earlier untracked evaluations have no proven completion boundary. Keep live FIFO disabled; no guard reset or service restart is approved/requested.

Root added a bounded passive native-input contract check alongside existing evaluation, sharing the exact live admission predicate and treating heartbeat like other inputs. Independent implementation review caught a diagnostic completeness race when settings/revision changed and returned before reporting. The correction tags Apply interruption by session token, publishes Start metadata before its deadline and rechecks settings/ownership/Cutover/revision. Apply never waits for diagnostic ownership. Observer/report retain interruption, including Apply during Start publication.

Final independent verdict: approved, no remaining blocking findings. Reviewer reran 39 passive diagnostic and 89 runtime checks (128 total). All six suites pass 389 checks. Changed PHP lint and whitespace validation pass. Guard, evaluator/output policy and M2/M3/watchdog runtime remain unchanged. Active diagnostics add bounded short capture overhead; CPU/resident RAM remain unmeasured. This is approval for publication and passive production evidence gathering, not FIFO activation, quiescence proof or recovery-loop resolution. See [current no-restart instructions](module1-fifo-passive-input-diagnostics.md).

## Native execution boundary investigation — 13 September 2026

Artur supplied the completed 84-update passive capture with no rejected contract or interruption. Independent review again rejected historical guard clearing: official native thread stubs establish APIs but do not prove callback/nested dispatch coverage, queued code-generation boundaries, thread reuse or actual completion semantics. Native module lifecycle pages do not supply that barrier either.

The current [design investigation](module1-fifo-restart-free-boundary.md) lists the required guarantees and provides a one-shot read-only execution inventory (maximum eight detail reads, 32 redacted field/type entries per row). No runtime module changes, restart, guard reset, automatic polling, logging, sensor writes or output dispatch are introduced. Existing 389 runtime regression checks remain unchanged; script syntax and isolated missing-API, normal/redaction/caps, wrong-shape, native-warning and completion-exception scenarios pass. Independent review approved the actual script/document and verified detail caps, secret redaction, nonfinite numeric exclusion and native warning handling. The native response allocation is not bounded by the output cap. This is evidence gathering only; actual restart-free activation is still unapproved.

Native follow-up: the 16:59:11 inventory returned 100 entries and sampled eight. Seven had zero StartTime/ScriptID; the eighth began script 44773 in the capture's second. This suggests reusable worker slots; it does not identify an old Module 1 call or prove completion. Root recorded the evidence and prepared specific native coverage, idle/identity, update-boundary and supported-transition questions. The earlier independent verdict remains applicable: no historical guard reset is justified by these samples. No additional runtime tests or repeated native captures are requested for this documentation-only update.
