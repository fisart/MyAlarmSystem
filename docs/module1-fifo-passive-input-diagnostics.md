# Passive FIFO input diagnostics — no production restart

Build: `Version2.14.0-fifo.5`. Scope: Module 1 only. Keep live FIFO disabled. Existing alarm evaluation continues; the check creates no alarm outputs and gives heartbeat no separate route.

## Decision and unresolved problem

Production reached 149 FIFO recoveries after missing sensors were removed. Later reports show configured FIFO enabled but actual ownership disabled by the retained legacy concurrency guard. They contain no new FIFO fault/session evidence.

Artur requires unrelated Symcon services to remain running. Independent review rejected clearing the guard based on newly added tracking: calls started by the older implementation have no completion registration, and may still evaluate or dispatch synchronously. An idle worker, timeout, library update or module reload cannot prove those calls ended. No guard reset, manual attribute editing or service restart is requested. Future tracked cutover needs registration of every evaluation before entry and a barrier proving no prior caller remains; migrating historical untracked calls remains unresolved.

The approved next step is a bounded passive check of actual native VM_UPDATE contracts, alongside the existing evaluator. It can identify unsupported payload fields that might cause admission failures. It cannot establish the cause of the recovery loop by itself.

## Production steps

1. Disable **Use FIFO for live Module 1 alarm processing** and Apply. Leave shadow disabled. Confirm ordinary heartbeat.
2. Update Module Control from `design/module1-fifo` to this build. Do not restart IP-Symcon.
3. Click **Start passive FIFO input check (15 seconds)**. Alternatively execute:

   ```php
   echo MYALARM_StartInputFifoDiagnostic(23172, 15);
   ```

4. Do not change settings or Apply during the capture. After about 15 seconds, click **Print input FIFO report** or separately execute:

   ```php
   echo MYALARM_GetInputFifoReport(23172);
   ```

5. Send the report, particularly `input_diagnostic`. Leave live FIFO disabled. No Stop is necessary before printing. **Stop passive FIFO input check** / `MYALARM_StopInputFifoDiagnostic(23172)` ends it early and retains the report.

The authenticated Mermaid webpage shows capture status, counts, incomplete results and bounded source reasons through its existing five-second polling and safe text rendering. No new timer or polling is introduced. Updating the module still follows the native module lifecycle; this is not a claim that native library updates cannot interrupt alarm callbacks.

## Meaning and limits

The observer checks every subscribed native update reaching the legacy branch before rule filtering and refresh suppression. The exact same predicate used by live FIFO checks integer counter, array fields 0/1/2, boolean changed flag, scalar current/previous values, finite floats and strings of at most 1,024 valid UTF-8 bytes. It never stores sensor contents or rereads values.

`observed` counts inspected callbacks, `rejected` counts contract failures, and `accepted_contract` is their difference. Examples retain only variable ID, timestamp, type/size reason and whether the source was required by the active FIFO dependency graph at Start. A subscribed disabled/non-evaluated source can fail the contract without being required by that graph. This distinction informs later design; the diagnostic changes no admission policy.

Default duration is 15 seconds; maximum is 30. Capture ends at 10,000 observations and retains at most eight distinct source examples. Duration is checked on observation/report, without another wake timer. Results are volatile and disappear on interface-buffer recreation; a missing diagnostic is not a clean result. An empty observed capture provides no native contract evidence.

Any Apply within the capture's declared time window tags its session as interrupted, including Apply during Start publication or changes reverted before reporting. Such results remain incomplete. An Apply after manual Stop within that time window may conservatively invalidate the retained result too. Contended observations or diagnostic exceptions mark incomplete and do not stop normal evaluation. A busy report asks for retry without fabricating counts.

Zero rejection does **not** validate prior-value continuity, baseline synchronization, rule/evaluator correctness, dispatch delivery, absence of event loss or completion of historical legacy calls. It does not authorize live FIFO activation.

## Cost and verification

Inactive legacy input processing adds one buffer read. Active capture uses a separate semaphore with a wait of at most 1 ms, tiny counter writes and bounded scalar checks. Example serialization is bounded to the first eight distinct rejected sources. Start compiles at most 1,024 dependency IDs once. Apply adds a small session-marker check without waiting for diagnostic ownership. No routine logs, archives, configuration writes, new timers, full queue rewrites or alarm dispatch are added. The actual live FIFO branch does not call this observer. CPU and resident RAM remain unmeasured.

The passive suite passes 39 checks, including unchanged alarm/bedroom decision projections, equal heartbeat token/reset treatment, malformed types/size/UTF-8 boundaries, limits, contention, retained historical guard, same-revision Apply interruption and Apply during Start publication. Existing five suites pass 350 checks; total 389. Tests model selected interleavings and do not replace native production evidence.
