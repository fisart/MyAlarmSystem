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
