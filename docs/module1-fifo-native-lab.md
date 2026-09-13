# Native FIFO timing lab

Branch `design/module1-fifo`, actual Module 1 fifo.6 diagnostics. Main remains stable Module 1 v2.13.4. This lab does not change alarm-module runtime or implement a different FIFO.

## Purpose and scope

Artur proposed a standalone FIFO emulator plus synthetic inputs/timing scripts. A copied queue can reproduce its own defects while missing the actual message sink/worker behavior. The installer and scenario scripts therefore exercise the real SensorGroup instance through native `SetValue` callbacks and native asynchronous script execution. They create a separate test instance and eight input variables under the dedicated root category `MyAlarmFifoLab`.

The test instance has no dispatch targets/routes, no bedroom target and no vault. It sends no input/payload to production Module 2/3 or the watchdog. The token input is an ordinary CHANGE sensor with token/reset pairs, with the same FIFO treatment as other sensors; it does not mark the actual watchdog healthy. End-to-end heartbeat and receiver delivery are outside this lab's scope.

Lab and production share the installed module library and server. Switching the library branch changes the code loaded for production instances too. Keep **production live FIFO, diagnostic mode and shadow disabled** while using this branch; the lab alone enables FIFO. A separate instance isolates configuration and routing, not PHP-library code or CPU/memory/thread resources. No Symcon restart is requested. Native library updates use normal lifecycle and can briefly interrupt callbacks.

## Install and run

1. Update the library from `design/module1-fifo` while production testing is disabled. Confirm fresh actual heartbeat/monitoring health after the update.
2. Paste [tools/symcon_fifo_lab_install.php](../tools/symcon_fifo_lab_install.php) into a temporary Symcon PHP script and run it. Its default folder is `IPS_GetKernelDir()/modules/MyAlarmSystem/tools`; adjust that folder only if your actual library path differs. The helper [tools/FifoLab.php](../tools/FifoLab.php) is loaded from the installed branch.
3. The installer prints IDs and creates `Module1Test`, eight inputs, `Scenario`, `RunScenario`, `StopScenario`, `Result`, `Manifest`, `ActiveSession` and three producer-status variables. It leaves lab FIFO disabled. Re-running installation reuses a validated existing lab without resetting it. An incomplete or modified existing lab is refused; inspect/remove only that dedicated category if you need a fresh installation.
4. Set the string variable `Scenario` to `sequential`, then run `RunScenario`. Read/copy `Result` after the run; the script also prints the report. No need to stop before reading it. Each run requests that lab live FIFO and diagnostic capture be disabled afterward. Verify `after_disable.configured_enabled=false` and `after_disable.enabled=false`; if `enabled` remains true, wait for its retained prefix/Apply to finish and inspect the lab health. Never infer successful disabling from a requested property change alone.
5. Repeat `interleaved`, `concurrent_flicker`, then `refresh_noise`, saving each Result. Use `StopScenario` to cancel a run. Do not edit the lab manifest/configuration or manually change its inputs during a scenario.
6. After ordinary tests, run `baseline_race` and `admission_contention`. A deliberate fault/pause is acceptable here because the instance has no alarm outputs. Send the reports before changing the FIFO design. Finish with fresh real watchdog history to check shared-server effects.

The module configuration is validated against the exact expected lab graph, pending settings and local object ownership. Adding routes, redirecting a manifest ID outside the lab, aliasing statuses with inputs, changing types or staging unrelated configuration changes makes the scripts refuse operation. Cancellation is established before startup; it is checked before activation/producer launch. Old producer sessions do not publish completion into a different active session. Run ownership is released even when cleanup/report persistence fails.

## Scenarios

| Scenario | Input pattern | What it can reveal |
|---|---|---|
| sequential | Door open/close, COUNT activations, ONCE transition and token/reset at requested 50 ms gaps | Basic native admission/worker consistency and existing rule execution |
| interleaved | Two alternating sources mixed with token/reset pairs | Competing source processing in one writer |
| concurrent_flicker | Two simultaneous noise writers and a third token/door writer | Native ordering/continuity or admission contention under parallel activity |
| refresh_noise | 48 unchanged updates alongside token/reset pairs | Refresh suppression and progress of another source |
| baseline_race | Concurrent inputs while current-state recovery is requested three times | Baseline sample/generation failure and first-fault stage |
| admission_contention | Hold only the lab instance's admission semaphore for requested 40 ms while producers run | Intentional omitted-observation fault, diagnostic pause and retained-prefix behavior |

Each plan has at most 128 writes in total, usually 70 or fewer. Requested gaps are 1–50 ms. Native scheduling can delay them; producer reports retain elapsed time and maximum schedule lateness. Asynchronous producers may start at different times, so this is not a precise physical-timing simulator. Forced admission contention demonstrates fault handling, not that contention caused the historical production failure. The initial lab's seven classes are smaller than the production graph.

## Reports and comparison limits

Result contains the scenario/session, three producer reports, actual changed-write count, worker processed delta, timing, `comparison_incomplete`, `changed_write_count_matches_processed`, FIFO snapshots and first-failure evidence. Paused state or another baseline publication makes comparison incomplete. Incomplete/missing producer reports or count mismatch also remain explicitly uncertain.

After producers finish, the coordinator waits up to two seconds for the expected aggregate processed count **and** an empty queue, or a fault. Queue emptiness alone does not prove that delayed callbacks have arrived. A mismatch at the deadline is evidence to investigate, not definitive proof of event loss.

Count agreement supports aggregate processing for this synthetic workload; it does not prove exact per-input delivery, downstream receiver acceptance, physical event order, COUNT/ONCE output policies or complete sensor history. Pulse expiry is set to 30 seconds, beyond the short scenarios, to avoid routine expiry controls contaminating the comparison. Other externally requested control frames could still change counts; leave the test instance untouched while running it. First-fault observation time is publication time, not physical sensor time.

Result is one bounded report (maximum 64 KB), overwritten on the next run. Copy reports you want to keep. First-fault evidence remains in the test instance even after disabling. It is not a per-event archive. Standalone baseline/MessageSink observations and simulated thread behavior cannot establish safe production concurrency guarantees.

## Performance and persistence

A manual run uses one coordinator and up to three short-lived producer scripts, plus the actual Module 1 worker. There is no permanent scenario timer, external I/O, per-input logging or archive registration. Producer waits are bounded to 50 ms per operation; setup polls every 25 ms for at most two seconds, producer completion for at most four seconds, and aggregate drain for at most two seconds. These bounds cannot preempt a blocked native API call or a server execution timeout.

Configuration validation occurs at script entry and before configuration activation, not for every input write. Producer completion writes one small status variable each; Result writes once at run completion. Inputs themselves use native variable writes and actual FIFO code. The intentional admission mutex affects only the lab instance and is held for requested 40 ms, never a production instance's lock. Scripts and variables consume shared server resources; CPU/resident RAM remain unmeasured. Do not run repeated stress loops or leave a scenario unattended.

Native API signatures were checked against the official [Symcon GlobalStubs](https://github.com/symcon/SymconStubs/blob/master/GlobalStubs.php): instance/variable/script creation, script content installation, `IPS_RunScriptEx`, `SetValue` and `IPS_Sleep`. Stubs establish API shape, not native ordering, timing or completion guarantees. Mock verification supplements the existing suites; live results remain required.

## Review and checks

All eight local suites pass **482 checks**; the harness contributes **34** covering installation, scope/plan bounds, route/manifest/pending-property refusal, four successful scenario lifecycles, startup cancellation, partial-control stop, stale completion refusal, cleanup-write failure and delayed callbacks. Changed PHP lint and whitespace checks pass. Independent read-only review reran the 34 checks and approved this harness for supervised testing with no remaining blockers. Mock script execution is deterministic and does not validate native parallel scheduling.
