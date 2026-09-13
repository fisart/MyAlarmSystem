# FIFO activation guard correction

Build: `Version2.14.0-fifo.4`. Scope: Module 1 only. The previous production recovery loop remains unresolved; this patch repairs a separate activation race and makes the activation state explicit.

## Observed blocker and confirmed defect

Artur's native diagnostic shows applied `EnableInputFifo=true`, no unapplied changes, configuration Healthy, actual FIFO disabled, empty session metrics and no retained fault. Health identifies the retained legacy concurrency guard. This proves FIFO never started in the displayed diagnostic session; it does not identify which invocation originally set the guard. The requested library-reload script's success was not explicitly confirmed.

Static review and a failing regression establish a false-latch path: CheckLogic set `FifoLegacyOverlap` immediately on worker-lock contention, before discovering that the call should route through established FIFO ownership or skip evaluation at an Apply boundary. Such a call never invokes the legacy evaluator but could prevent subsequent activation indefinitely.

## Narrow repair

CheckLogic now checks cutover and FIFO ownership before setting the overlap latch. If neither applies and worker ownership failed, it retains the genuine safety latch and checks **cutover first, then ownership again** before invoking the legacy evaluator. Apply publishes cutover before reading the overlap latch. The ordered second checks ensure that either Apply sees the latch and blocks activation, or the caller sees active cutover/completed FIFO ownership and avoids legacy evaluation. The genuine latch is never cleared by ordinary Apply, elapsed time or an idle worker, since those cannot establish that an untracked invocation ended. A small intervening race may still latch conservatively; this repair does not claim every retained guard denotes an invocation that ultimately evaluated.

The report adds applied `configured_enabled`, actual-runtime `enabled`, `activation_blocked` and current `health`. These are on-demand fields, not new polling or per-input diagnostics. Healthy FIFO admission, heartbeat handling, evaluator/output policy, recovery and queue bounds are unchanged. The additional attribute reads occur only in contended legacy entry; there are no new semaphore waits, JSON parsing, event logs, timers or archive writes on that path. CPU/resident RAM remain unmeasured.

## Correct reset and supervised capture

The earlier advice to reload the library as a guaranteed reset was incorrect. The reset is implemented in Create; Symcon documents [Create](https://www.symcon.de/en/service/documentation/developer-area/sdk-tools/sdk-php/module/create/) for new instances and IP-Symcon startup, not as a guaranteed library-reload callback. A restart is the documented lifecycle reset. Do not call Create manually or edit internal guard attributes to bypass the protection.

1. Update to this build with live FIFO disabled and confirm ordinary heartbeat.
2. While present, enable the live FIFO setting and Apply. A pre-existing guard may still block it; the patch deliberately does not clear uncertain historical overlap.
3. Restart the IP-Symcon service with the live FIFO setting enabled. Restart briefly interrupts all Symcon automation. Keeping the applied setting enabled lets startup activate FIFO before subsequent ordinary legacy traffic can latch another genuine blocker.
4. After about 15 seconds, print `MYALARM_GetInputFifoReport(23172)` and save it. If a fault/recovery appears earlier, capture immediately.
5. Disable live FIFO and Apply. Confirm heartbeat and send the report. If activation is still blocked after restart, leave it disabled and send the report; do not repeat restarts.

No continued live-FIFO production acceptance is granted by this diagnostic patch. If you cannot supervise a restart, leave FIFO disabled and use the existing evaluator.

## Validation

The original code fails the failed-owner/established-FIFO regression. The corrected FIFO suite passes 89 checks; five suites total 350. Targeted checks cover safe FIFO routing without a false latch, skipped cutover without a false latch, retained protection after genuine unowned legacy evaluation and reset through Create. Independent design review also checked all 210 operation interleavings of one unowned caller and one serialized Apply, with no unsafe legacy/FIFO overlap under sequentially consistent attribute reads/writes.
