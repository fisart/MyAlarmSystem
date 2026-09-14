# Float temperature continuity diagnostic

Branch: `diagnostic/fifo-temperature`, based on main `aa1d91344712f2d6dfb4dde942a064f175865162`.
Module 1 marker: `2.14.1-lab.1`. This is an optional diagnostic build; main is unchanged.

## Production evidence and question

Production input 34157 is the Float `Speichertemperatur`, not merely an unnamed ebusd device value.
Archive 14817 records 54.0 at 2026-09-14 10:08:25+02:00 and 53.0 at 10:20:28+02:00,
the precise time of the reported prior-value continuity fault. A new baseline was published at
10:20:29 after six subsequent observations were omitted. Earlier faults also involved input
27005. Archive changes establish historical value/timestamp evidence, but do not contain FIFO's
stored value or the previous value/type delivered by the native callback.

The cause remains unknown. Existing FIFO encoding already uses `JSON_PRESERVE_ZERO_FRACTION`.
Neither missing updates, reordering nor a type mismatch has been established in production.
Do not weaken the continuity guard or infer a production cause from a synthetic test.

## Install and run in Symcon

1. Select `diagnostic/fifo-temperature` for the MyAlarmSystem module library and update it.
   Keep the production instance's existing FIFO and activation compatibility settings; do not
   enable production failure capture or shadow. No service restart or production guard reset.
2. Paste `libs/tools/symcon_fifo_temperature_lab_install.php` into a temporary PHP script and run it
   once manually. The usual path is `/modules/MyAlarmSystem/libs/tools` beneath `IPS_GetKernelDir()`.
3. The installer creates the separate root category **MyAlarmFifoTemperatureLab**, with
   **Module1Test**, **Temperature** (Float), **Scenario**, **RunScenario**, **StopScenario** and **Result**.
   Existing small and production-size labs are preserved. No production sensor IDs are bound.
4. Set the String variable **Scenario** to a scenario below, then manually run **RunScenario**.
   Start with `temperature_simple`, then `temperature_refresh`, then `temperature_parallel`.
   Each takes seconds and disables only its own lab FIFO afterwards. Do not enable dispatch targets.
5. Copy the printed JSON or the **Result** String contents back to the reviewing developer.
   If a scenario faults, stop and retain its result rather than rerunning repeatedly.

| Scenario | Native writes | Purpose |
| --- | --- | --- |
| temperature_simple | Baseline 54.0, then 53.0 | Float JSON round trip and the observed value transition |
| temperature_refresh | Eight 54.0 refreshes, 53.0, four 53.0 refreshes | Unchanged callbacks and typed admission baseline |
| temperature_parallel | The same temperature plan plus 32 toggles each on NoiseA/NoiseB | Concurrent callbacks from distinct sources |

These are compressed diagnostic timing plans, not a replay of the full 12-minute archive interval
or production MQTT transport. No real watchdog cycle is requested. Inputs are ordinary queue inputs;
there is no heartbeat priority, reservation or bypass.

## Read the report

`temperature_check.passed` and `comparison_incomplete: false` identify complete expected evidence.
The report contains `fifo_before/fifo_after.continuity_diagnostic`:

- One selected source, at most 64 samples / 16 KiB / 30 seconds, volatile across library reload.
- `stored`, `previous`, `current` retain each numeric value and its exact PHP type.
- `native_changed` is the native callback's changed flag.
- `capture_index` orders selected observations under the admission mutex. `candidate_seq` is
  the queue sequence the observation would receive; unchanged refreshes do not consume a sequence.
- `entry_ns` is monotonic callback-entry time. `native_counter` is recorded as opaque metadata,
  not interpreted as an archive timestamp. `captured_at` is the diagnostic recording wall time.
- `prior_matches` reflects the unchanged strict comparison used by admission.
- After disable, `is_current_baseline: false` correctly marks retained historical evidence.

All selected values are Float in native scenarios. A failing comparison with stored Float and
integer previous value would support a type mismatch only if actually observed natively.
The automated test deliberately injects such a payload to check fault reporting; it does not prove
that Symcon delivers it. Exact written/processed counts alone cannot prove downstream delivery.

## Scope, performance and rollback

No changes to Module 2/3 or watchdog. No dispatch targets, external scripts or alarm outputs.
The existing continuity/fault/recovery policy is unchanged. Capture requires the isolated temperature
root, a local Float dependency and explicit active diagnostic mode. Ordinary processing does not
parse or write capture state; its added hook checks the existing diagnostic-mode flag. Manual report
generation may read the optional bounded buffer. Selected lab callbacks perform a bounded buffer
read/write under the existing queue lock; this intentionally adds diagnostic overhead to that lab.
No per-event logs, archive writes, new background polling or permanent timers. Synthetic concurrent
writers still use the shared Symcon runtime, so run these bounded scenarios manually.

Return the library to main after diagnostic testing. Keep production FIFO/activation compatibility
enabled and production failure capture/shadow disabled. The installer never changes production
instance settings. No automatic main merge is authorized for a prospective fix by this lab request.

Automated tests: 31 temperature checks include native-shaped Float/refresh/parallel plans, isolation,
normal-mode capture inactivity, type-mismatch evidence and capacity/fault policy. Full regression CI
results and exact published head are recorded in the diagnostic PR comments for handoff.
