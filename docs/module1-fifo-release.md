# Module 1 FIFO v2.14.0

Release cleanup follows Artur's successful native production FIFO, ordinary heartbeat and functional testing. Runtime changes are confined to Module 1. Module 2, Module 3 and watchdog retain their main code. The release preserves all tested processing settings and the existing main subscription/bedroom fixes.

## Updating the production instance

Switch the library to **main** and update after PR#3 is merged. On SensorGroup **23172**, retain **Use FIFO for Module 1 alarm processing** enabled. In **Ordered input FIFO → Advanced activation and diagnostics**, retain **Allow FIFO activation after legacy processing (compatibility option)** enabled for the tested configuration. This is the existing AllowFifoTrialCutover property; it is not renamed internally or reset by the update. Keep **failure capture and shadow OFF**.

There is no automatic FIFO activation for other installations and no automatic expiry of the accepted production configuration. No Symcon service restart or lab installation is required. If there are known pending settings, COMMIT validates before applying. Switching/updating can temporarily recreate subscriptions and baselines; do not assume seamless historical-event coverage at that boundary.

Print the FIFO report while running. Expected: enabled/ready=true, trial.active=true for compatibility, trial.automatic_recovery=true, test.active=false, unknown_inputs=[], fault=""; idle worker interval0 is normal. Existing historical-loss/legacy overlap warnings can remain. Clear a recovered incident only after inspecting it; clearing incident does not erase the legacy guard or fault record.

## Cleanup and compatibility

- Version marker is2.14.0; normal status no longer labels the accepted deployment as a trial requiring manual shutdown.
- Diagnostic pause/passive controls are collapsed in advanced settings; lab scripts, isolated probe and diagnostics remain opt-in and are not installed or run by updating.
- Existing properties, attributes, report fields and APIs remain compatible. `trial` report fields describe the existing activation compatibility mode.
- Hidden ClassID now explicitly saves through the form, preventing the confirmed missing-ID draft problem. Existing unique-name ID healing and strict validation remain.
- Main's rejected-Apply recovery, dynamic tamper references and bedroom save/isolated-draft recovery fixes are retained.

## Processing and evidence

Heartbeat is an ordinary input, with no priority or reserved route. Existing LEVEL/CHANGE/ONCE/COUNT evaluation is preserved. Compatibility uses the tested10ms pending continuation and50ms initial wake; non-compatibility ordinary FIFO retains50ms continuation. Queue capacity128records/256KiB,state512KiB; one bounded10msadmission/commit and1msdequeue/owner wait; soft20ms/32recordsbatches, stopping timer when empty. The queue does not silently overwrite unread records on wrapping.

Observed production:158admitted/158processed,emptyqueue,peak2,no new baseline/fault between successive reports,maxlag246ms. Nine ordinary post-start heartbeat cycles confirmed all4targets,Module1median190ms/max996ms. Artur reports functional testing OK. Local release workflow701checks/14suites and28syntaxchecks; final independent review/CI/merge SHA in PR#3.

These results support release under the observed workload; they do not establish sustained-overload behavior, CPU/residentRAM impact, hard receiver latency, every physical alarm output or durable exactly-once delivery. Fault recovery can restore current values but cannot reconstruct missed historical events. Module2/3receiver-level changes remain outside this release.

## Disabling or rolling back

Uncheck FIFO and activation compatibility on23172,leave capture/shadowOFF,COMMIT. Confirm actual enabled=false/trial.active=false/workerinterval0; Apply can wait for admitted work to drain. The existing evaluator resumes. Previous maina4c63a813cf55852c5f36966bac5e6357f88a82c / Module1v2.13.4 remains the code rollback point. Do not restart the service or clear the legacy guard as a routine workaround.
