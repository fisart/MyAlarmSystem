# Module 1 supervised first-failure test


Current build: **fifo.7**, genuine-idle-only worker wake and bounded 10 ms admission/essential commit waits. Native fifo.6 concurrency reproduced admission-lock contention; all admitted records drained and disablement completed. Follow the [native lab retest](module1-fifo-native-lab.md#current-native-evidence-and-fifo7-retest); keep production FIFO/capture/shadow off. [Current handoff](module1-fifo-handoff.md).
**Current next step:** the [isolated native input/timing lab](module1-fifo-native-lab.md), using a separate Module 1 instance and no downstream outputs. Production live FIFO/diagnostic/shadow remain disabled. The earlier production first-failure procedure remains available for a separately supervised test if later needed.

Build: `Version2.14.0-fifo.6`, branch `design/module1-fifo`, draft PR #3. Defaults remain off. Module 2, Module 3 and watchdog runtime are unchanged.

## Why this test

The original target is loss of entire sensor events during competing input activity, particularly heartbeat. Earlier FIFO sessions reached 149 baseline publications with almost no processing; that number alone does not prove 149 distinct failures. The actual first cause remains unproven. A later historical legacy concurrency guard blocked activation before any FIFO evidence could be collected.

Artur explicitly accepts missed messages and overlapping old/new evaluations while switching during a supervised test. The prior thread-inventory/vendor-contract investigation is shelved. This build permits that test without clearing the retained guard, restarting Symcon, introducing debounce, or changing alarm rules. Ordinary non-test activation still respects the guard. This is diagnostic authorization, not evidence of reliable production FIFO operation.

## Run and return to normal processing

1. Leave live FIFO and shadow disabled while updating the library from this branch. Back up the applied configuration using existing export tools. No Symcon service restart is requested.
2. Open Module 1 instance 23172 and expand **Ordered input FIFO (supervised testing)**. Enable **Use FIFO for live Module 1 alarm processing** and **FIFO diagnostic test: override activation guard and pause recovery at first fault**, then Apply.
3. Run only while present. After roughly 15 seconds, or immediately on a fault, use **Print input FIFO report**. Printing does not require stopping FIFO. Save the entire report, including `test.first_fault`, metrics and previous session.
4. Disable **both** live FIFO and the diagnostic checkbox, then Apply. This restores the existing evaluator while retaining the bounded first-failure record. Confirm configuration health and fresh completed heartbeat cycles at all four targets. Pending trustworthy FIFO records may drain before Apply completes; an unusable paused/not-ready tail cannot block the exit.
5. Send the report and fresh heartbeat history before another test.

**There is no automatic test duration or automatic return to the existing evaluator. Do not leave this diagnostic test unattended.** After a fault, new sensor inputs and heartbeat are not processed until you disable live FIFO and Apply. A trustworthy already-admitted prefix may finish; uncertain evaluator state stops dependent records. Existing alarm variables remaining populated do not indicate continued processing during a pause.

The form health label refreshes when opened. The Mermaid page uses its existing five-second polling to show the paused state, required action and bounded first-fault stage/source/sequence, or an explicitly labelled previous-session record. No new polling loop or timer is introduced.

Optional report script:

```php
<?php
echo MYALARM_GetInputFifoReport(23172);
```

## Captured evidence and bounds

`test` distinguishes configured/applied diagnostic policy, pause, retained legacy guard, previous/current first record and incomplete capture. The first actual fault per Apply fence is retained; later callbacks cannot replace it. A new Apply retains the previous record until the next actual fault, and disabling/library recreation preserves it in an attribute. Only one first-failure record is retained, not an event archive.

The record includes fence, revision, observation timestamp, internal stage and available integer variable ID, sequence and native counter. Sequence is present only when a candidate/processed sequence is known; a failed admission is not presented as admitted. Sensor and counter string contents are excluded. Reason is bounded to 512 bytes with UTF-8 substitution; stage/type labels are allowlisted. Records remain under 2 KB in regression tests.

Capture-lock contention conservatively pauses and marks the first details unavailable; later report/Apply publishes one bounded fallback with a null observation timestamp. A later precise failure cannot replace this fallback. The timestamp is publication time, not physical sensor time. Existing `last_fault` remains separate from the immutable first-test record.

Paused input/control callbacks return before validation and queue work; reports with a published first record do not repeatedly acquire its mutex or rewrite persistent evidence. Recovery entry and baseline publication recheck the pause. Recovery timers are stopped, and manual Retry cannot rebuild a paused session. A baseline failure or uncertain evaluated frame remains not ready; admission failures allow the trustworthy queued prefix to finish.

## Cost, limits and next design

Healthy FIFO processing adds local phase annotations and applied-policy checks, without per-event diagnostic payload persistence. The new capture mutex waits up to 1 ms only on a fault; the existing fault mutex can add another 1 ms. Persistent first-failure/pause writes occur at the anomaly, with an on-demand fallback if necessary. CPU and resident RAM are unmeasured; synchronous receiver calls can still exceed batch budgets.

Heartbeat tokens and resets use the same sensor queue and evaluator as other inputs. No priority, reserved capacity, special merging or bypass is added. Existing previous-value checks, fixed ring limits, COUNT/CHANGE/ONCE semantics, target throttles and receiver behavior remain.

After native fault evidence, separately review a per-sensor pending-state/fair scheduling design: preserve each source's latest value and the trigger values/counts/timing required by its existing rules; collapse intermediate history only where those semantics allow. Do not simply replace every sensor's pending events with its latest value, because this can erase short trigger/token events. Debounce remains shelved. No speculative merging is implemented by this patch.

## Stable branch distinction

`main` at `59e413e` contains merged PR #2: Module 1 v2.13.3 and Module 2 v7.3.2, including the initialization and safety fixes Artur tested. PR #3 remains unmerged. Its later rejected-Apply subscription restoration and bedroom backing-list COMMIT fixes are therefore absent from main. The original competing-input loss remains unresolved on main. A successful heartbeat checks its own route, not every configured alarm rule/output.
