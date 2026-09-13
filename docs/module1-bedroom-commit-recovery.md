# Bedroom backing-list persistence and COMMIT recovery

Build: `Version2.14.0-fifo.2`, branch `design/module1-fifo` / PR #3. Module 1 only; the FIFO worker, heartbeat path and Module 2/3 runtime are unchanged by this hotfix.

## Problem and correction

Artur deleted missing sensor entries 40696 and 41873, then pressed **COMMIT ALL CHANGES TO DISK**. `SaveConfiguration()` correctly rejected the complete draft because BedroomList required fields had become missing/invalid. Rejection preserves the validated running configuration; it does not approve the sensor deletion.

The hidden property-bound BedroomList had three non-editable columns without `save:true`. The [official Symcon List documentation](https://www.symcon.de/en/service/documentation/developer-area/sdk-tools/sdk-php/configuration-forms/list/) specifies that such columns default to unsaved. The static form and generated fallback now explicitly persist GroupName, ActiveVariableID and BedroomDoorClassID. `loadValuesFromConfiguration:false` lets supplied WorkingList rows retain authority on reload, including explicitly marked edits. Validation remains strict.

The earlier native malformed-bedroom draft and current rejection are consistent with this persistence defect. Tests model the documented serialization contract; exact native console serialization still needs confirmation after update.

## Repair the already-corrupt draft

1. Update Module Control from `design/module1-fifo` and reopen Module 1 instance 23172. Verify build `Version2.14.0-fifo.2` if checking source.
2. Expand **Step 3b: Bedroom Behavioral Settings**.
3. Click **Restore bedroom draft from running configuration**. This replaces the pending bedroom section with its validated running version. It preserves other pending edits, including the two sensor deletions. Re-enter any intentional bedroom edits after restoration if applicable. The action does not Apply, dispatch or modify the FIFO/COUNT state.
4. Press **COMMIT ALL CHANGES TO DISK**. The entire resulting draft is validated and activated through the normal Apply/FIFO boundary.
5. Print the input FIFO report and check that `unknown_inputs` is empty; confirm normal heartbeat continues. Historical-loss incidents stay visible until explicitly cleared after valid current monitoring.

The restoration can also be invoked explicitly after the update:

```php
<?php
echo MYALARM_RestoreActiveBedroomDraft(23172);
```

It refuses an unavailable, updating or invalid active configuration. There is no automatic fallback that silently accepts a malformed bedroom edit or resurrects an intentionally empty bedroom list.

## Independent review and checks

The new form suite passes 24 checks. It reproduces stripped required fields, deletes the selected Fob rows through the real UI method, rejects malformed COMMIT, models interface recreation, restores only the bedroom draft, preserves sensor/FIFO/COUNT state, and confirms final COMMIT removes only the selected sensors with `unknown_inputs=[]`. Legitimate compact edits, intentional empty lists and invalid-running-graph rejection are covered.

Independent read-only review reran all five suites: **330 checks passed** (129 safety, 44 probe, 64 shadow, 69 live FIFO, 24 configuration form). Native console persistence remains the remaining verification for this hotfix. Saving flags add no per-sensor processing cost; restoration does configuration validation/writes only when explicitly invoked.
