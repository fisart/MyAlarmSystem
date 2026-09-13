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

### Production follow-up: Module 1 v2.13.3 / Module 2 v7.3.2

The v2.13.2 attribute substitution did not resolve interface creation: the same warning moved to ReadAttributeString. Cross-module work during Apply is therefore deferred. Module 1 schedules its post-Apply evaluation/dispatch (or rejected-Apply notification) on PostApplyTimer. Module 2 schedules validation, configuration discovery and state evaluation on SafetyApplyTimer. Both wait for KR_READY, execute outside Apply, and disable themselves. There is no sleep or ongoing healthy-state polling; timers retry once per second only while waiting for kernel startup. Module 2 marks monitoring as Initializing until a fresh evaluation, retains protected states, and normal runtime evaluation completes pending activation first so valid intrusion is still actionable. Applied settings are read in the deferred callback; uncommitted form drafts are not activated. The existing degraded retry remains responsible for unavailable-source recovery. Tests now reject all cross-module PHP API and dispatch calls during Apply, rather than testing only inaccessible buffers. This catches the previous implementation. Symcon must still confirm successful interface recreation after update; repository tests do not emulate the native InstanceManager.

### Production follow-up: Module 1 v2.13.2

Module 2 interface creation reported `InstanceInterface is not available` at both snapshot API `GetBuffer` calls. The API now uses registered attributes for ActiveRevision and the bounded SafetyPlan, removing its dependency on the runtime-buffer interface during cross-module initialization. Activation and consumer notification use the same attributes. The plan contains configuration only: every snapshot still reads current sensor values. Plan writes occur only on a cache miss with Remember=true; revision writes occur during Apply. Persistence allows reuse of the validated active configuration and compiled plan after restart, while empty/updating revisions still return invalid snapshots. No event polling, waits, archive writes or per-read plan writes were introduced. Tests deny both buffer reads and writes for Module 1 while executing Module 2 Apply, including a cache rebuild. Real Symcon instance-creation recovery still requires verification after updating; no status code is forced to hide an initialization failure.

### Production follow-up: Module 1 v2.13.1

Live diagnostics identified integer value 2 on the four guest-room/office usage selectors, with valid Boolean door values. The safety evaluator previously accepted only Boolean and integer 0/1. It now accepts integer selectors using the same zero/nonzero conversion as existing BEDROOM_SYNC. With Module 2's configured `unused` polarity, 0 means used and 2 means unused. Missing variables and unsupported types remain unknown; door evaluation is unchanged. Regression checks cover the reported live values, internal arming with an unused bedroom open, disarming when the selector changes to used, and rejection of missing/malformed usage. This adds no reads, timers or logging.

### Production follow-up: Module 2 v7.3.1

The first production report showed an unavailable/invalid safety snapshot followed by all seven missing roles. The normal read omitted the fourth API argument while configuration validation supplied it. Module 2 now supplies `Remember=true` explicitly so it also works with generated wrappers that require every argument. The stub now requires all four arguments: the previous implementation fails the healthy-baseline test; the corrected version passes 114 checks including the live export. This reproduces a likely cause; confirmation on the actual Symcon installation is still needed. Snapshot and configuration-validation exceptions now expose their class and a bounded message through existing health-transition diagnostics. No timers, sensor evaluation policy or normal logging frequency were added.

Install both module updates and their helper files together. Existing configuration exports remain compatible. Keep the pull request in draft until the Symcon integration checks and independent review are accepted. Roll back the repository version if necessary; do not import new runtime attributes into an old version.
