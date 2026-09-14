# FIFO activation guard correction

Build: `Version2.14.0-fifo.4`. Scope: Module 1 only. The previous production recovery loop remains unresolved; this patch repairs a separate activation race and makes the activation state explicit.

## Observed blocker and confirmed defect

Artur's native diagnostic shows applied `EnableInputFifo=true`, no unapplied changes, configuration Healthy, actual FIFO disabled, empty session metrics and no retained fault. Health identifies the retained legacy concurrency guard. This proves FIFO never started in the displayed diagnostic session; it does not identify which invocation originally set the guard. The requested library-reload script's success was not explicitly confirmed.

Static review and a failing regression establish a false-latch path: CheckLogic set `FifoLegacyOverlap` immediately on worker-lock contention, before discovering that the call should route through established FIFO ownership or skip evaluation at an Apply boundary. Such a call never invokes the legacy evaluator but could prevent subsequent activation indefinitely.

## Narrow repair

CheckLogic now checks cutover and FIFO ownership before setting the overlap latch. If neither applies and worker ownership failed, it retains the genuine safety latch and checks **cutover first, then ownership again** before invoking the legacy evaluator. Apply publishes cutover before reading the overlap latch. The ordered second checks ensure that either Apply sees the latch and blocks activation, or the caller sees active cutover/completed FIFO ownership and avoids legacy evaluation. The genuine latch is never cleared by ordinary Apply, elapsed time or an idle worker, since those cannot establish that an untracked invocation ended. A small intervening race may still latch conservatively; this repair does not claim every retained guard denotes an invocation that ultimately evaluated.

The report adds applied `configured_enabled`, actual-runtime `enabled`, `activation_blocked` and current `health`. These are on-demand fields, not new polling or per-input diagnostics. Healthy FIFO admission, heartbeat handling, evaluator/output policy, recovery and queue bounds are unchanged. The additional attribute reads occur only in contended legacy entry; there are no new semaphore waits, JSON parsing, event logs, timers or archive writes on that path. CPU/resident RAM remain unmeasured.

## Current production constraint: no restart

The earlier library-reload and service-restart instructions are withdrawn. Artur cannot interrupt unrelated production services. Keep live FIFO disabled and use the existing evaluator; do not restart IP-Symcon, call Create manually or edit internal guard attributes.

The retained guard cannot safely be cleared by new tracking, an idle worker or a timeout: invocations started by the older code were not tracked and may still be evaluating or dispatching synchronously. Independent review rejected that proposed restart-free transition. Current input evidence must be gathered without activation. The new `Version2.14.0-fifo.5` [passive input check](module1-fifo-passive-input-diagnostics.md) provides that next step without clearing the guard.

## Validation

The original code fails the failed-owner/established-FIFO regression. The corrected FIFO suite passes 89 checks; five suites total 350. Targeted checks cover safe FIFO routing without a false latch, skipped cutover without a false latch, retained protection after genuine unowned legacy evaluation and reset through Create. Independent design review also checked all 210 operation interleavings of one unowned caller and one serialized Apply, with no unsafe legacy/FIFO overlap under sequentially consistent attribute reads/writes.
