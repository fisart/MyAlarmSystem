# Module 1 v2.14.4: lifecycle-safe sensor-rule pulses

## Problem and evidence

The 2026-09-14 live export contains 13 Evidence Status variables, each used by both `Cameras Clip Error Class` (`failed`, ONCE, 10 seconds) and `Cameras Clip Ready Class` (`done`, ONCE, 1 second). Example: 28097, 119 Back.

Previous Module 1 versions keyed condition and pulse caches only by VariableID. The failed predicate reset the condition shared with done, and the done predicate generated another false-to-true edge at every evaluation, including the expiry callback. The class/group could stay active indefinitely. A local reproduction using the actual evaluator and FIFO plus the Symcon stub demonstrated the fault; a done-only control expired normally.

Mermaid also keyed both nodes/activity by VariableID, which could color different predicates alike.

The first correction made the identity conditional on a process-local count of rules per variable. Production evidence on 2026-09-15 showed that an IP-Symcon module lifecycle can retain the current active revision while that volatile count cache is empty. In that state, the evaluator fell back to VariableID again. The 13 camera `done` pulses stayed active for hours although the source variables had not changed, and manually invoking pulse expiry did not clear the ready group.

## Change

`SensorRuleIdentity` derives stable identities from variable, class and rule semantics. UI labels and row order do not affect identity. ONCE and CHANGE rules now always receive independent deterministic rule keys, including separate CHANGE history, ONCE condition and pulse deadline. Their correctness no longer depends on the volatile rule-count cache. Byte-identical rules within the same class have identical semantics and may share state.

Unique LEVEL inputs retain their lightweight numeric key. Repeated LEVEL inputs use deterministic rule keys for dashboard and Mermaid identity, as before.

Runtime evaluation, FIFO baseline/recovery and the pure diagnostic shadow use the same key definition. Mermaid uses a committed per-rule activity map and separate nodes. Aggregate sensor IDs and downstream payload formats remain unchanged. Dashboard enable/disable clicks retain their existing variable-wide scope.

On configuration revision changes, deleted/disabled rule entries are pruned and changed predicates initialized from their current values. Legacy numeric state belonging to ONCE or CHANGE rules is discarded rather than copied to a new stateful identity. This can end an in-progress pre-update pulse early; it does not manufacture an alarm. A sensor already at `done` during migration must leave `done` and return before generating its next pulse.

Existing integer wall-second timing and scheduling semantics remain; this is not a precision-timer redesign. The page's refresh cadence can miss displaying a short red pulse.

## Performance and scope

No extra polling, threads, semaphores, network calls, archive writes or per-event logging. ONCE and CHANGE rules require a small deterministic hash. Ordinary unique LEVEL sensors retain constant-time numeric keys, so the common LEVEL path does not gain hashing work. State is limited by configured rules, pruned on revision changes, and remains subject to existing FIFO state byte limits. Additional manifest and dashboard state scale with configured rules. Production CPU and resident memory have not been measured.

Modules 2, 3 and the watchdog are unchanged. Heartbeat uses the ordinary sensor path. The pulse fix was merged through PR #6 before the Float continuity fix in PR #7. Diagnostic PR #5 was closed without merge.

## Validation and installation check

The executable regression covers both class orders with FIFO enabled and disabled; done expiry and rearming; failed's separate ten-second pulse; unchanged refreshes and unrelated inputs; state sync; configuration reordering; FIFO recovery; shared CHANGE/ONCE inputs with different durations; rule addition/deletion and predicate edits; migration from old numeric caches; real wall-clock one-second expiry with a manually invoked timer callback; per-rule Mermaid colors; and shadow expiry semantics.

The v2.14.4 regression additionally mirrors the production graph with 13 camera variables, each used by `failed` and `done` ONCE rules. It deliberately empties the volatile rule-count cache while retaining the current configuration revision, expires all pulses, and verifies that both classes and groups clear while every source string remains `done`. This is the native lifecycle condition missing from the original correction.

GitHub CI results are recorded in the v2.14.4 PR. Native production verification is required before merging this correction to `main`.
