# Issue 1: validated configuration and arming inputs

## Design

Module 1 v2.13.0 publishes a single validated active configuration. Runtime evaluation, discovery, active-sensor lookup and throttling use this configuration. UI buffers are drafts with explicit dirty markers; custom editors also stage their corresponding properties. Form rendering does not mark edits. Missing IDs are recovered by unambiguous names from active/draft identity maps, never row counts. Apply, COMMIT, restore and dashboard toggles validate before activation. Invalid candidates remain visible for correction; they do not prune or replace the active graph. Dashboard toggles refuse to apply unrelated pending edits.

Module 2 v7.3.0 likewise retains a validated set of source, mapping, polarity, delay and state-push settings. Restore prevalidates the complete candidate and applies once. A rejected candidate is reported through ConfigurationHealth separately from actual monitoring health.

The new `MYALARM_GetSafetySnapshot(InstanceID, MappingJSON, TargetID, Remember=true)` control API validates and evaluates current LEVEL dependencies together, including bedroom usage switches and door classes. A validation-only request (`Remember=false`) cannot replace the active consumer cache. Existing ALARM, RESET, BEDROOM_SYNC and Module 3 snapshot field names remain unchanged. No historical event payload, empty active list, or missing variable is accepted as proof of security.

The API uses three-valued logic. Unknown data prevents new arming. A valid opening remains actionable even when a peer is unknown. Current authorized-disarm priority is preserved: a trustworthy entrance unlock disarms; a trustworthy used-bedroom opening disarms Internal; alarm reset through bedroom opening also requires trustworthy Home presence. Faults alone cannot disarm or clear an alarm. Rejected edits cannot cancel a healthy arming delay.

Armed mode changes retain the existing armed state for the full delay. PendingMode exposes the target. A valid intrusion during that interval still triggers state 9. Loss of readiness cancels only the pending mode change. A new arming delay from Disarmed is cancelled when inputs become invalid; recovery starts a complete new delay. Apply/restart discards partial delay progress, retaining Armed/Alarm states.

Explicit state sync evaluates once with no fabricated COUNT triggers or CHANGE/ONCE edges. Unchanged sync cannot replay an active pulse merely because the trigger anchor differs. Failed/throttled event deliveries do not advance the last-delivered projection. The dedicated PSM control refresh bypasses event throttling and unchanged suppression without bypassing alarm-output rate limits.

## Performance and failure recovery

A single bounded consumer plan is cached by active configuration revision, target and mapping. Normal calls evaluate only required variables, reading shared variables once. The API does not dispatch, acquire a semaphore or mutate motion state. Structural compile happens on configuration/mapping change or cache loss. Ordinary event processing gains no full-house polling loop. A 30-second retry timer runs only while PSM monitoring is degraded, allowing recovery after a failed snapshot without a later sensor event. Logs for health and rejected configuration change only on transitions. Existing event transport, logging and queue architecture are otherwise outside this change.

There is one PSM consumer cache, matching the supported installation. Multiple independent PSM consumers would need separate bounded registration. This change does not implement the proposed FIFO/event-integrity architecture or hard real-time transactional sensor reads. Variable values are sampled once during one synchronous evaluation; physical devices may change afterward. Missing variables and incompatible comparison values are detected, but stale device telemetry requires the existing watchdog/technical monitoring.

## Verification

Run `php tests/state_integrity.php` with PHP 8.1+ and ctype. Optionally set `ALARM_LIVE_CONFIG` to the live Module 1 JSON export; the test validates the 393-rule installation without committing the private export. Tests execute the actual module methods against a Symcon stub which deliberately separates pending from applied properties. They cover graph validation, activation entrypoints, rollback, all protected states, missing inputs, bedroom polarity, mode switching, COUNT/pulse sync, delivery failure, recovery and bounded cache behavior.

The stub does not emulate Symcon scheduling, console form callbacks, device drivers, or Module 3 output execution. Before production use, verify on a Symcon test instance: normal console Apply and COMMIT; class add/edit/delete; custom sensor editing; restore; dashboard toggles; restart in states 0/2/3/6/9; both armed mode switches with Module 3; configured throttles; hardware value/type semantics; and behavior under bursts. Confirm native MonitoringHealthy, InputHealth, ConfigurationHealth and PendingMode indicators. No live system has been modified by these repository tests.

## Delivery

Install both module updates and their helper files together. Existing configuration exports remain compatible. Keep the pull request in draft until the Symcon integration checks and independent review are accepted. Roll back the repository version if necessary; do not import new runtime attributes into an old version.
