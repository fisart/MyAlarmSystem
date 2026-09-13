# Independent review of the FIFO/event-integrity design

Date: 2026-09-13.
Baseline: `59e413ead4d6a85a5336583af7788ee9175d0a49` (merged PR #2).
Design: [module1-fifo-design.md](module1-fifo-design.md).

## Verdict

Approved as a staged architecture/design. No remaining design-blocking findings after revision. This is not approval to deploy an implementation or claim lossless delivery. The independent reviewer was a separate agent, read the source and complete draft, and did not edit the design or code. The author incorporated the findings and the reviewer reread the revised document before approval.

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

## Remaining prerequisites

1. Verify native Symcon VM_UPDATE data, ordering/concurrency, bootstrap and timer/lifecycle behavior with a bounded read-only probe. The SDK page leaves Data layout unspecified, and the prior regression stub missed native initialization behavior.
2. Measure real rule/payload sizes, burst throughput, latency, memory and lock/thread usage before fixing capacities, wake intervals and retention ages.
3. Cancellation of queued intrusion siren/ASK actions after a newer trustworthy disarm remains a user decision.

## Subsequent user-directed policy revision (2026-09-13)

After the independent architecture review, Artur raised unattended travel as a constraint. He accepted automatic recovery after an unclean restart/known loss once current inputs and the ordered baseline are valid, with a full new arming delay where applicable. Incident acknowledgement does not gate recovery or arming; known gaps remain visible until reviewed. Artur also requires persistent status and required-action display on the existing house-state/Mermaid webpage. These revisions are recorded in the design and were not part of the earlier independent review. Implementation review must cover unattended recovery, active-fault versus incident separation, stale page status, bounded incident retention and acknowledgement races.

No runtime files changed and no live alarm tests were performed as part of this design review. Implementation acceptance must include outcomes and failure cases across all three modules, not only FIFO ordering assertions.
