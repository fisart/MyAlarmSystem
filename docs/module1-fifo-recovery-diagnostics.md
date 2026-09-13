# Retained FIFO recovery diagnostics

Build: `Version2.14.0-fifo.3`. Scope: Module 1 diagnostics only. The underlying production recovery loop remains unresolved; keep live FIFO disabled outside a brief supervised capture.

## Production observation

After the bedroom COMMIT repair and removal of missing Fob inputs, Artur's report showed `unknown_inputs=[]`, `ready=true` and no current fault, but 149 recoveries. The two displayed sessions started roughly two seconds apart and admitted/processed zero events; the previous session omitted seven observations after a fault. These sessions do not establish sustained healthy alarm processing. The report retained the original startup incident from 15:28:19, while automatic recovery erased the later current fault, hiding the loop's cause.

The valid configuration export confirms the bedroom fields survived COMMIT. The sensor cleanup is separate from the unresolved recovery problem. A missing current fault immediately after recovery does not prove the fault stopped recurring. No CPU/resident RAM measurements are available by agreement.

## Repair

- `last_fault` retains the last successfully published fault's timestamp, configuration revision, fence and bounded reason in a persistent module attribute. It survives automatic recovery, incident acknowledgement, disabling FIFO and interface recreation. It is historical evidence, not a current-health indicator. Later observations can coalesce under lock contention, so the record is not guaranteed to identify the latest observed fault.
- `previous_session.recovery_fault` and `recovered_at` retain the reason captured before successful baseline replacement cleared the fault. Only one previous session is retained, as before.
- Invalid native input reasons identify the variable and describe counter/payload/field types and string byte lengths. They never copy input strings into diagnostic records.
- If fault-publication lock contention prevented precise evidence, successful recovery records `details_unavailable=true` with `observed_at=null` and a conservative loss reason. It does not invent a precise observation time or source.
- The authenticated Module 1 Mermaid status panel shows the last retained fault alongside current health and the original incident. Existing five-second polling is unchanged.

Healthy input processing, heartbeat handling, queue capacities, admission checks, automatic recovery, output delivery and Module 2/3/watchdog runtime are unchanged. One bounded anomaly record is added; identical retained record writes are suppressed. No event-value logs, per-input healthy-path diagnostics, additional timers or archive variables are added. This repair exposes failure evidence; it does not claim to fix the failure that caused the recovery loop.

## Short supervised capture

1. Disable **Use FIFO for live Module 1 alarm processing**, Apply and confirm normal heartbeat with the existing evaluator.
2. Update from `design/module1-fifo` to this diagnostic build with FIFO disabled.
3. While present, enable FIFO briefly to observe ordinary incoming traffic. Do not deliberately trigger sirens/ASK or overload sensors. Stop as soon as a new fault/recovery appears, or after approximately 15 seconds if none appears; this is a diagnostic capture, not a five-cycle acceptance run.
4. Disable FIFO and Apply. Print the report via the form or:

   ```php
   echo MYALARM_GetInputFifoReport(23172);
   ```

5. Send the complete report, especially `last_fault` and `previous_session.recovery_fault`, and confirm heartbeat after disabling. The retained last fault is available even when disabled. If disabling cannot complete, use the previously documented working-build return point.

Do not clear the original incident during this capture. The new diagnostic cannot reconstruct fault evidence already erased by the older build.

## Verification

The executable FIFO suite passes 82 checks; all five suites total 343 checks. Targeted regressions reproduce an old incident masking later malformed-input failures, omitted observations and repeated zero-processing recovery; they verify source/type/byte evidence, no payload retention, persistence through disable/recreation, explicit unavailable-detail fallback and coalescing under fault-lock contention. Native failure evidence remains required to determine the production cause.
