# Module 1-only FIFO: revised design

Status: scope accepted by Artur on 2026-09-13. Ordinary native message observations have been received and analyzed; see [native results](module1-fifo-native-results.md). No FIFO runtime is enabled. The earlier independent review covered the coordinated architecture, not approval of an implementation of this narrower design.
Baseline: main commit `59e413ead4d6a85a5336583af7788ee9175d0a49`, Module 1 v2.13.3 / Module 2 v7.3.2.

## Decision and scope

Implement a bounded inbound FIFO and one evaluator in Module 1. Capture native sensor observations and process admitted changes in order, retaining ordinary unchanged-value suppression. Preserve existing Module 2/3 interfaces, configuration and alarm policies. Artur accepts the downstream limitations below in exchange for a smaller implementation and rollout.

Do not introduce receiver acceptance protocols, independent delivery outboxes, automatic delivery retries, matching PSM decision contexts, receiver deduplication, intrusion cancellation or changes to the PSM webpage. Existing synchronous dispatch, target throttles and receiver behavior remain. A slow receiver can therefore delay the Module 1 worker; FIFO capacity only absorbs a bounded burst.

Heartbeat policy (Artur, 2026-09-13): heartbeat observations use the same admission, FIFO, evaluator and dispatch path as all other sensor inputs. No priority, reserved queue capacity, bypass or special recovery path. Heartbeat delay/loss must remain capable of revealing shared-path problems. Observe token/reset timing and watchdog timeout margin in tests without giving heartbeat privileged handling. A successful heartbeat exercises its configured route; it does not prove every alarm rule or output policy.

Guarantee sought: within a running session and the validated native message contract, admitted sensor changes are evaluated in admission order by one worker. No claim of physical sensor-time ordering, crash-proof buffering, downstream alarm detection or exactly-once external actions.

## Accepted limitations

| Limitation | Consequence |
|---|---|
| Module 2 rereads live inputs | A captured open/close can still fail to trigger an intrusion if the input is closed when Module 2 evaluates it. |
| Module 3 uses its existing house-state context selection | Delayed events can use a newer house state; the narrower FIFO does not repair event-time output policy. |
| Dispatch has no explicit receiver acceptance acknowledgement | A normal return is not proof of retained or executed work. Delivery failures, throttle suppression and receiver queue loss remain possible. |
| Module 3 queue/deduplication behavior is unchanged | Downstream loss, duplicate actions and a stranded queue tail remain unresolved. No new blind retry is added. |
| Inbound FIFO is volatile and bounded | Unexpected restart loses pending records; saturation cannot retain unlimited observations. |
| No cross-module integrity or cancellation controls | Module 1 faults do not introduce new arming gates or pending siren/ASK cancellation in Modules 2/3. |

These are accepted code-path risks, not assertions that each failure has occurred in production. Loss of historical evidence cannot be repaired by sampling current values.

## Admission and evaluator

First verify native VM_UPDATE payload values, types, counters, callback concurrency and startup behavior with a bounded read-only probe. Do not assume the event Data layout or silently replace a missing captured value with a later live value. Resolve unsupported native behavior before enabling the new path.

Keep MessageSink short: filter subscribed dependencies, validate the typed native observation, admit it with a sequence number and request a worker wake. Store only compact observation data and configuration revision. No evaluation or external action under the admission lock.

Use a bounded ring with individually serialized slots and small metadata rather than rewriting a whole JSON queue per event. Update sequence, slot, counters and the last-admitted suppression baseline together under a short lock. Do not advance that baseline when admission fails. Suppress ordinary typed unchanged refreshes; never collapse A→B→A into A. A lock failure or full queue is an explicit fault, not permission to run inline out of order.

One worker owns evaluation state. Maintain a Module 1 value mirror so its rules use captured observations rather than rereading newer values. Include dynamic comparison dependencies and bedroom inputs where Module 1 interprets them. Preserve rule identity when multiple rules use the same variable. Preserve existing COUNT, CHANGE and pulse semantics; reference updates and state-only sync must not manufacture COUNT events. Handle pulse deadlines and Module 1 state controls consistently with admitted observations.

Apply/Create retain PR #2's deferred lifecycle behavior. Stage accepted configuration changes and establish an explicit revision boundary before processing under a new configuration. Requested sync uses the same evaluator ownership, preserving the existing external API; it does not acquire a downstream delivery guarantee. Do not serialize Module 2's own arming controls through a new protocol.

Wake scheduling must prevent a producer enqueue during worker shutdown from leaving a tail stranded. Stop timers when empty. Bound each batch by record count and elapsed time between records; a synchronous receiver call cannot be interrupted by that time budget. No blocking sleep and no evaluation or remote calls under the queue metadata lock.

Do not add a new token/session wire format unless existing consumers have been checked for compatibility. The inbound admission sequence can remain internal. Broader receiver/session protocol repair is deferred.

## Faults, restart and performance

Report admission failure, overflow, invalid observations and ambiguous worker state through Module 1's existing diagnostics. Retain bounded incident summaries with time, reason and affected input range/count; avoid per-event logging and archive writes. Specify overflow disposition before implementation: do not silently discard a retained prefix, repeatedly evaluate COUNT side effects or report healthy processing after known loss. Rebuild a current baseline through an explicit recovery boundary; do not invent missed edges or automatically replay uncertain delivery.

On restart, discard volatile old-session state and establish a validated current Module 1 baseline. Do not add a manual acknowledgement requirement or alter Module 2 arming policy. Module 1 diagnostics cannot prove that all downstream destinations are healthy. The expanded house-state/Mermaid panel remains deferred.

Measure capacities and worker intervals against the live configuration (393 rules, 371 distinct variables). Bound queue entries, serialized bytes, mirror/control state and incident retention; account separately for PHP object/array overhead. The earlier coordinated architecture's 2 MiB budget is not a validated budget for this narrower implementation. Prefer dependency indexes and compact data; avoid extra polling, repeated full configuration parsing, routine verbose logging and long semaphore waits.

## Implementation and acceptance

1. Run the [bounded native read-only probe](module1-fifo-probe.md) and measure production-shaped bursts, rule cost, synchronous dispatch latency and memory. The optional observer captures native evidence and supplies timing observations; additional shadow/load measurements are still required for implementation acceptance.
2. Implement a shadow path that compares Module 1 evaluations without sending additional downstream actions.
3. Verify rapid open/close, duplicate refreshes, duplicate rule IDs/variables, dynamic references, COUNT/pulse timing, worker shutdown/enqueue races, sync, configuration cutover, overflow and restart. Check existing receivers accept unchanged payloads and tokens.
4. Supervise the Module 1 production rollout with its rollback prepared. Modules 2/3 remain at their existing compatible versions. Rollback cannot recover lost observations or undo actions already sent.

Runtime acceptance must measure CPU, memory, lock waits and event-to-dispatch latency. A successful FIFO test does not establish downstream intrusion detection or output execution.

## Deferred work

The [coordinated architecture](module1-fifo-coordinated-backlog.md) retains ordered Module 2 evidence/controls, matching event context, per-target acceptance/outboxes, Module 3 deduplication and reliable drain scheduling, intrusion cancellation, restart/delivery integrity and the expanded webpage status panel. Artur's previously accepted cancellation and unattended recovery policies belong to that future scope.

Also retain stale-device telemetry detection, atomic physical sampling limitations, multiple PSM consumers and wider transport/logging cleanup as separate backlog items. None is required to claim the limited Module 1 admission/evaluation improvement.
