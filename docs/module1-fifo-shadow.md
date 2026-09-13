# Module 1 FIFO shadow test

Build: `2.14.0-shadow.1`, branch `design/module1-fifo`, draft PR #3.

This build adds an optional comparison inside Module 1. The existing live evaluator and dispatch continue to operate. A second, isolated evaluator processes captured observations in FIFO order and compares its decisions with the live evaluator. It never dispatches additional alarm actions. Modules 2, 3 and the watchdog are unchanged.

Heartbeat inputs use exactly the same admission, suppression, queue and shadow evaluator as other inputs. There is no heartbeat priority, reserved capacity or bypass. Its existing live route continues normally. The actual production alarm path has not yet been moved onto FIFO.

## Production steps

1. Update the module library on branch `design/module1-fifo`. Confirm the Module 1 file has marker `Version2.14.0-shadow.1`. Keep your working rollback available.
2. Open Module 1 instance `23172`, expand **FIFO shadow testing**, check **Allow read-only FIFO shadow testing**, set duration to **300 seconds**, then Apply. Wait for normal post-Apply processing to finish.
3. Click **Start shadow**. Check **FIFO Shadow Health** below the instance. It should say **Running shadow only**. Enabling the setting alone does not start capture.
4. For five minutes, use ordinary sensor inputs and allow the normal heartbeat cycles. Include door open/close, presence and bedroom usage changes where practical. The live alarm rules remain active, so your normal production procedures still apply. Do not generate synthetic alarm events solely for this test.
5. Capture ends automatically within approximately five seconds of the duration limit. Click **Print shadow report** and send the result, together with watchdog history and CPU/memory observations. A changing input during startup may produce **Baseline changing**; retry Start during quieter traffic.

The previous optional `SensorEventProbe` instance is not required for this test. Leave it stopped.

If you prefer a Symcon script, export the reports without changing sensor values:

```php
$module1InstanceID = 23172;
$watchdogInstanceID = 35750;
echo json_encode([
    'shadow' => json_decode(MYALARM_GetFifoShadowReport($module1InstanceID), true),
    'heartbeat_history' => json_decode(AHW_GetHeartbeatHistory($watchdogInstanceID), true)
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
```

**Stop shadow** stops only the comparison. Apply, configuration changes or restart also stop the comparison; it never restarts automatically. To disable it completely, stop it, uncheck the setting and Apply. Returning to the working branch removes the experimental build. Neither stopping nor a shadow fault introduces a new arming/disarming policy.

## Reading the result

| Field | Meaning |
|---|---|
| `fault` | Invalid observation, baseline gap, contention, capacity or processing failure detected by the diagnostic path. |
| `comparison_incomplete` | Capture stopped with pending or uncommitted comparisons. Do not treat the final interval as verified. |
| `lifecycle_interruption` | Apply/restart invalidated the captured configuration session. |
| `observed` / `ingress_suppressed` | Observed native inputs and exact typed unchanged refreshes filtered before queuing. |
| `admitted` / `processed` / `count` | FIFO records accepted, compared and still pending. Control evaluations are also records. |
| `mismatches` / `examples` | Differences in evaluation/suppression, active classes, groups, sensors or sabotage; up to eight compact examples. |
| `queue_peak` / `queue_bytes_peak` | Largest retained queue during this capture. |
| `queue_lag_max_ms` | Admission through completion of shadow processing, including live decision and worker scheduling. |
| `live_max_ms` | Input capture through completion of the existing live evaluation. |
| `batch_max_ms` / `worker_total_ms` | Observed worker elapsed time. These are not CPU utilization. |
| `config_bytes` / `state_bytes` / `ingress_bytes` | Serialized storage; not resident PHP/Symcon memory. |
| `worker_php_delta_max_bytes` | Largest end-of-worker PHP allocation increase relative to that worker's start; not transient peak or process RSS. |

A mismatch may expose a current-value race or an existing timing difference; it does not automatically mean the shadow evaluator is wrong. Comparisons cover decision projections, not payload text, bedroom output formatting, receiver acceptance, siren execution or notifications. An empty fault field is not proof that every physical sensor event was observed.

Manual/duration stop does not drain remaining comparisons. A nonzero pending count makes the final comparison window incomplete. This affects diagnostics only. Faults stop shadow processing and become visible on the next report refresh; the live path retains its existing behavior.

## Measuring production load

Record Symcon CPU and resident memory before Start, during the five-minute comparison and after Stop. Use the same CPU/memory display or operating-system measurement throughout. Send readings or screenshots with timestamps. Check watchdog cycles during capture for all four targets, callback durations, token/reset separation and timeout failures.

The additional evaluator necessarily adds work. The production observations will establish how much on this host. Do not infer CPU percentage or resident memory from serialized byte counts or elapsed worker time. Do not enable per-event archival for the diagnostic report/health variables.

## Implementation bounds

- 128 fixed queue slots, 256 KiB combined retained queue data and 16 KiB per record.
- Compact configuration, evaluator state and ingress state each capped at 512 KiB. Startup also caps rule/dependency/class/group counts and ingress bucket occupancy.
- Fixed 128 ingress buckets, each holding at most 16 source entries. No growing buffer-name list when configuration IDs change.
- COUNT history capped at 2,048 entries per class and 8,192 total; stop diagnostics instead of unbounded growth.
- Worker wakes after the oldest record's live comparison becomes ready, on a 50 ms timer. It processes at most 32 records and checks a 20 ms elapsed budget between records, attempting at least one ready record per batch. One evaluation or runtime API call can exceed that budget.
- Queue locks use 1 ms acquisition attempts. Evaluation occurs outside the queue lock. Short fault publication has separate ownership to prevent stale sessions hiding newer faults; duplicate faults may be coalesced while a fault publisher owns that boundary.
- Empty/pending-head workers stop; completing the head guarantees a new wake. Reports refresh every five seconds while capture runs and once on stop. No per-event logging/archive writes or diagnostic remote dispatch.

The shadow baseline is sampled twice and legacy evaluation state checked for observed motion. This is not atomic physical sampling. A subsequent prior-value mismatch stops capture rather than substituting a later live value. Each Apply establishes a fresh lifecycle fence, and old completions/faults cannot alter a new capture.

For compatibility comparisons, the pure evaluator preserves existing shared variable pulse/condition caches, integer wall-second pulse deadlines, tamper ordering, COUNT and unchanged-value suppression. Captured admission wall seconds are used for queued evaluations. Future production FIFO timing/recovery must be finalized separately; shadow approval is not production FIFO approval. Buffers remain volatile, and interrupted worker batches are reported as incomplete rather than replayed.

## Verification before publishing

An independent, read-only reviewer approved this build for bounded opt-in shadow testing after fixes to Apply cleanup, stale-session fault publication and incomplete comparison reporting. The focused suite passes 55 checks; existing state-integrity and native-probe suites pass 124 and 43 checks. CI includes all three suites and PHP syntax checks.

A local PHP 8.3 pure-engine exercise used the uploaded configuration shape: 393 rules, 61 classes, 55 groups and 377 dependencies including comparison/bedroom references. With synthetic integer values, 1,000 frames took 176.803 ms (median 0.166 ms, p99 0.356 ms, maximum 0.450 ms). Compact configuration was 84,771 bytes, resulting serialized state 7,105 bytes and retained PHP allocation increase 129,848 bytes. This excludes Symcon, queue integration, real values, dispatch and native concurrency; it is not a production load measurement.

Before actual FIFO activation, review real shadow differences, native scalar types, rapid/concurrent changes, lifecycle boundaries, burst capacity, scheduling, CPU/resident memory and heartbeat results. The accepted Module 2/3 live-state, context and delivery limitations remain in the [Module 1-only design](module1-fifo-design.md).
