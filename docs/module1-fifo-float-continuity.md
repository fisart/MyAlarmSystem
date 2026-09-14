# Module 1 v2.14.3: Float FIFO continuity normalization

## Problem

Production repeatedly reported `Prior-value continuity gap at variable 34157` for the Float `Speichertemperatur`, although the queue remained shallow and automatic recovery succeeded. Admission compared the stored baseline and native previous callback member with strict PHP identity. Therefore an integral Float represented as integer `53` did not match stored Float `53.0`.

The archive confirms Float values and their changed-value timestamps, but does not expose the PHP types in the native VM_UPDATE callback. Production counters later confirmed the intermittent representation change directly, so the isolated diagnostic PR #5 was closed without merge.

## Change

When the FIFO baseline value obtained through `GetValue()` is Float, integral numeric representations of the callback's current and previous values are normalized to Float before strict continuity comparison, unchanged suppression, queue encoding and mirror evaluation.

Normalization is deliberately narrow:

- Float baseline `53.0`, native previous `53` -> equal.
- Float baseline `53.1`, native previous `53` -> unequal and still a continuity fault.
- Float baseline and numeric string `"53"` -> unequal and still a fault.
- Integer, Boolean and String baselines keep exact existing type comparison.
- Non-finite Float values remain rejected by existing native scalar validation.

## Scope and performance

The comparison adds two local type checks and, only for Float baselines, casts numeric callback members to Float. It adds no native API call, JSON parse, buffer access, timer, semaphore, polling, logging, archive write or queue entry. Existing FIFO metadata reports bounded counters `float_representation_normalized`, `float_previous_normalized`, `float_current_normalized` and `last_float_normalized_variable`; no extra write is performed. Queue and state bounds, ordering, recovery, heartbeat processing and downstream payload formats remain unchanged. Modules 2, 3 and the watchdog are unchanged.

The independent sensor-rule pulse fix was merged first through PR #6. The Float normalization was then rebased directly onto `main` and merged through PR #7 as Module 1 v2.14.2.

## v2.14.3 status acknowledgement

The Mermaid FIFO status request uses a cache-busting timestamp, browser `no-store` mode and server no-cache headers. It therefore cannot reuse a status response captured while FIFO was disabled.

`Acknowledge and clear recovered FIFO history` remains available only while FIFO ownership, baseline and all active inputs are trustworthy. It now deletes the acknowledged incident, retained last fault and old diagnostic-test fault together. Current or degraded monitoring still blocks acknowledgement. No event-path work or routine status write is added.

## Validation

Targeted regression cases cover integral current/previous representations, normalized queue JSON, worker mirror type, unchanged refresh suppression, unequal numeric values, numeric strings and unchanged strict behavior for Integer baselines. The complete project workflow must pass on the exact published tree. Native production verification uses these counters because local tests cannot prove the VM_UPDATE payload supplied by the EBUS source. A rising counter for variable 34157 confirms the representation hypothesis; a new continuity fault with a zero counter disproves it for that event.
