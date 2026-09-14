# Module 1 v2.14.2: Float FIFO continuity normalization

## Problem

Production repeatedly reported `Prior-value continuity gap at variable 34157` for the Float `Speichertemperatur`, although the queue remained shallow and automatic recovery succeeded. Admission compared the stored baseline and native previous callback member with strict PHP identity. Therefore an integral Float represented as integer `53` did not match stored Float `53.0`.

The archive confirms Float values and their changed-value timestamps, but does not expose the PHP types in the native VM_UPDATE callback. The isolated diagnostic in PR #5 remains useful evidence and is not part of this fix.

## Change

When the FIFO baseline value obtained through `GetValue()` is Float, integral numeric representations of the callback's current and previous values are normalized to Float before strict continuity comparison, unchanged suppression, queue encoding and mirror evaluation.

Normalization is deliberately narrow:

- Float baseline `53.0`, native previous `53` -> equal.
- Float baseline `53.1`, native previous `53` -> unequal and still a continuity fault.
- Float baseline and numeric string `"53"` -> unequal and still a fault.
- Integer, Boolean and String baselines keep exact existing type comparison.
- Non-finite Float values remain rejected by existing native scalar validation.

The raw diagnostic capture in PR #5, when present on a diagnostic branch, runs before normalization and can still reveal the native callback types.

## Scope and performance

The comparison adds two local type checks and, only for Float baselines, casts numeric callback members to Float. It adds no native API call, JSON parse, buffer access, timer, semaphore, polling, logging, archive write or queue entry. Existing FIFO metadata reports bounded counters `float_representation_normalized`, `float_previous_normalized`, `float_current_normalized` and `last_float_normalized_variable`; no extra write is performed. Queue and state bounds, ordering, recovery, heartbeat processing and downstream payload formats remain unchanged. Modules 2, 3 and the watchdog are unchanged.

This branch is stacked on the independent sensor-rule pulse fix in PR #6 so installing it cannot restore the pulse defect. Merge PR #6 first; then rebase this change onto main and retain the 2.14.2 marker.

## Validation

Targeted regression cases cover integral current/previous representations, normalized queue JSON, worker mirror type, unchanged refresh suppression, unequal numeric values, numeric strings and unchanged strict behavior for Integer baselines. The complete project workflow must pass on the exact published tree. Native production verification uses these counters because local tests cannot prove the VM_UPDATE payload supplied by the EBUS source. A rising counter for variable 34157 confirms the representation hypothesis; a new continuity fault with a zero counter disproves it for that event.
