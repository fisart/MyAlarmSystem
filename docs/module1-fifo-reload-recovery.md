# Module 1 recovery after rejected Apply and library reload

Build `2.14.0-shadow.3`, draft PR #3, branch `design/module1-fifo`.

Production shadow comparisons remain paused. Load this build for guarded recovery with shadow stopped, Apply Module 1, and confirm fresh completed watchdog cycles are OK at all four targets before any further shadow test. The hotfix is reviewed and tested locally; native recovery still needs verification.

## Evidence and diagnosis

The supplied Symcon log confirms:

- 13:45:37: Module 1 rejects a bedroom draft because GroupName/BedroomDoorClassID are not strings and ActiveVariableID is not an integer ID.
- 13:56:39: the module library unloads; the optional SensorEventProbe instance crashes in Destroy. It accesses withdrawn InstanceInterface attributes and passes false to json_decode.
- 13:56:40: modules are recreated. Subsequent snapshots have enabled/applied shadow settings but zero observations, while nine retained completed heartbeat cycles are MISSING at Module 1 and the three Module 3 targets. The publish timer's LastRun advances.

Module 1's rejected-Apply path originally retained its persisted active graph but returned before registering inputs. That preserves existing subscriptions during an ordinary failed Apply; it does not rebuild subscriptions withdrawn during interface recreation. A regression reproduces the loss by clearing interface subscriptions and applying a malformed draft. Repeated errors can be omitted from the log because error messages are deduplicated in persisted attributes.

This is a reproduced, code-supported explanation matching the timeline. Native subscription state was not captured, so the log alone does not prove it is the only cause of the production failure. The earlier captured-prior baseline gap also remains unexplained.

## Fix

On rejected Apply, Module 1 validates the retained active graph and registers only its missing primary sensor, existing comparison-reference, tamper and bedroom-input subscriptions. It neither promotes nor overwrites rejected properties. Existing subscriptions remain. A stopped pulse-expiry timer is restored using the unchanged pulse map; no COUNT event or sensor transition is generated. Existing post-Apply consumer notification remains deferred.

The optional probe's Destroy calls only the parent implementation. It does not touch withdrawn attributes, buffers, variables, messages or timers. The host owns interface removal. Symcon calls Destroy during Module Control updates: [SDK documentation](https://www.symcon.de/en/service/documentation/developer-area/sdk-tools/sdk-php/module/destroy/).

Modules 2, 3 and the watchdog code are unchanged. Added graph validation/subscription work occurs once during rejected Apply; there is no new cost per sensor update, logging stream, polling loop or queue. Normal heartbeat has no privileged path.

## Guarded recovery steps

1. Stop shadow capture. Update `design/module1-fifo` to build `2.14.0-shadow.3`.
2. Leave FIFO shadow testing disabled, then Apply Module 1 instance 23172. Wait for normal post-Apply processing.
3. Check fresh completed watchdog cycles, allowing the normal one-minute cadence and timeout window. Require all four monitored targets to return to OK; old retained MISSING records do not disappear immediately.
4. Send the fresh heartbeat history and any new errors. If callbacks remain missing, keep testing paused and use the working backup/production procedures; do not assume the instance's status 102 proves monitoring health.
5. Only after recovery is confirmed, assess the malformed bedroom draft and resume the bounded shadow investigation. The last validated graph remains active while the draft error is visible.

Read-only history export:

```php
<?php
echo AHW_GetHeartbeatHistory(35750);
```

## Verification

The subscription-loss regression fails on the previous code and passes with recovery. Tests also cover the exact malformed bedroom field types, active/draft/COUNT separation, unchanged pulse state and unavailable-interface probe Destroy reaching its parent. Final suites pass 129 state-integrity, 44 probe and 64 shadow checks. A separate read-only reviewer approved the implementation for guarded recovery testing with no remaining blockers. Production recovery and the prior-value gap require new native evidence.
