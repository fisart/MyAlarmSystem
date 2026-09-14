# Module 1 FIFO — AI handoff

Updated: 2026-09-14 UTC. Repository: fisart/MyAlarmSystem. Release: **Module 1 v2.14.0**. PR [#3](https://github.com/fisart/MyAlarmSystem/pull/3) contains implementation, reviews, native evidence and the authorized main merge checkpoint.

## Current decision and next action

Artur reports the production FIFO tests look OK and authorizes release cleanup and merge into main. Preserve the tested runtime behavior; no debounce/coalescing redesign, no heartbeat exception, no changes to Module2/3/watchdog. Final release CI and independent review precede merge. Exact merged head is recorded in PR Conversation. The earlier main baseline was a4c63a813cf55852c5f36966bac5e6357f88a82c, Module1v2.13.4/Module2v7.3.2.

After merge, switch the module library to main/update and inspect production SensorGroup **23172**. Existing live FIFO and activation compatibility settings carry over. Keep shadow and failure capture OFF for normal operation. Confirm no pending changes, actual FIFO enabled/ready, no currentfault/unknowninputs, ordinary heartbeat and expected house-state behavior. No Symcon service restart required. See [release guide](module1-fifo-release.md).

## Released behavior and compatibility

FIFO remains default-off for existing/new instances; no automatic activation by updating main. The already tested production configuration enables EnableInputFifo and AllowFifoTrialCutover. Its second option is now labelled **Allow FIFO activation after legacy processing (compatibility option)** in collapsed advanced controls. Retain old property/attribute/report names for installed configurations and diagnostics; `trial.active` is the effective compatibility mode, not an expiring lab session.

The compatibility setting still permits expressly accepted uncertain switching overlap, retains the historical legacy guard and uses normal automatic recovery. Existing worker ownership and admitted-prefix Apply deferral remain. Failure capture is diagnostic-only, pauses first fault and has no expiry; both modes together refuse activation. No production instruction to disable FIFO after testing remains in ordinary runtime health.

Tested effective continuation remains10ms for compatibility/capture modes,50ms initial wake,50ms ordinary non-compatibility continuation,32records/soft20msbatchtarget. Queue128slots/256KiB and evaluationstate512KiB, one bounded10msadmission/essentialcommit and1msdequeue/owner waits. Ring wrapping is normal; unread entries are not silently overwritten. Fault recovery resamples current values and cannot recreate lost history. No durable event delivery/receiver acknowledgement guarantee.

All inputs, including heartbeat/token/reset, share admission and evaluation. Class LEVEL/CHANGE/ONCE/COUNT semantics preserved. No priority/reserved heartbeat path, per-source debounce or speculative latest-value coalescing.

## Main fixes retained during reconciliation

Restore retained sensor/tamper primary and dynamic comparison subscriptions after rejected Apply/interface recreation; skip ignored CHANGE references and restore stopped pulse wake without fabricating events. Keep ordinary tamper comparison dependency subscription. Preserve strict configuration validation and last validated graph; bedroom backing-list typed fields and isolated draft restore remain.

Release also sets save=true for the hidden ClassID column, preventing the actual61classIDs being stripped from pending form serialization. Existing missing IDs still heal only by unique names from active identity baseline. No rule/settings migration or ID regeneration introduced.

## Native production evidence

Production preparation: supported COMMIT healed61missing pendingClassIDs without changing other compared class settings; user confirmed IPS_HasChanges23172=false. Live fifo.12 started00:01:07/00:01:25+02:00 on14Sep2026. Second report kept samefencee510db0a5b1b407b/revisiond9dabb.../recoveries2;158admitted=158processed,count0,peak2/128,maxlag246.292ms,maxbatch192.867ms; no currentfault/lastfault/unknowninputs. Historical15:28:19incident/legacy guard retained. User subsequently confirmed overall functional testing looks OK; no final metrics report supplied with that acceptance.

Nine ordinary watchdog sends after reportedFIFOstart,00:01:55.821–00:10:03.846, all Module1callback plus three Module3targetsOK/exacttokens. Module1median190ms,max996ms. First supplied00:00:54cycle predatesFIFO and is excluded. The separate isolated heartbeat-overlap scenario is deferred; it is not a release prerequisite after observed ordinary production confirmations. Heavy intentional overlap not established by those history entries.

Earlier isolated native evidence: fifo.9largerconcurrent70/70,maxlag807.833ms; forced40msadmissionhold detected/pause/cleanup with zero admissions (not nonempty-prefix failure proof). fifo.10nine-eventsemantic session94c9625cbbb37b92 passed10assertions for sequence/mirror/COUNT1/1/2/ONCE/token-reset,currentauditcomplete,queueempty and actualdisable. Legacy production loss causality remains unproven; experimental fifo.6 admission mutex omission was independently reproduced and bounded waits/scheduling revised.

## Validation and performance

Release workflow: **701 checks /14suites**,28PHPsyntax/all42commands; FIFO runtime109,scheduling31,compatibility42,timing24,failurecapture59,lab59,verification33,heartbeatlab35,passivediagnostic39,configurationform26,shadow64,safety129,eventprobe44,subscription7. Independent final release review and GitHub CI outcome are recorded on PR#3.

Normal operation installs no lab variables/producers, permanent scenario timer or event log/archive. Lab helpers live under libs/tools; all test tools remain opt-in. Form passive/capture controls are collapsed advanced diagnostics. Mermaid's existing FIFO status refresh remains unchanged, retaining health/incident/lastfault visibility. Diagnostic timing/semantic capture stays off outside applied failure-capture mode. Do not interpret serialized bytes/elapsedtime as residentRAM/CPU. Synchronous receiver calls can exceed the soft batch target; measured queue was shallow.

## Accepted limits and outstanding work

No Module2/3 changes. End-to-end exactly-once acknowledgement, receiver-side duplicate/stale protection, cancellation of pending siren/ASK actions after trustworthy disarm and stronger failure-aware arming remain deferred where they require receiver changes. Module1 warning does not independently prove or enforce all downstream policies.

CPU/residentRAM unmeasured; sustained overload/queue exhaustion, broad production simultaneous activation, unclean-restart/durable-history loss and long-term reliability not established. Native semantic tests do not prove physical cross-producer order. No hard batch latency bound when synchronous receivers block. Continue anomaly-based monitoring; do not claim every alarm rule/output is covered by heartbeat or admission counts.

User constraints: no Symcon restart; runtime changesModule1only; normal heartbeat; bounded diagnostics/waits/state/no noisy logs; test switching overlap accepted; debounce/thread inventory/quiescence reset approaches shelved. No automated guard reset.

## Rollback

Uncheck FIFO and compatibility, keep capture/shadowOFF,COMMIT; wait for actual enabled=false/trial.active=false/workerinterval0 because admitted work can defer Apply. Incident/guard may remain. Previous maina4c63a8/v2.13.4 is available if rollback of library code is needed; original legacy competing-input loss remains unresolved there. GitHub source/handoff and PR checkpoints are durable.
