# Retained FIFO recovery diagnostics

Build: `Version2.14.0-fifo.3`. Scope: Module 1 diagnostics only. The underlying production recovery loop remains unresolved; keep live FIFO disabled. The live-capture procedure below is superseded by the passive check.

Current procedure: use the `Version2.14.0-fifo.5` [passive input check](module1-fifo-passive-input-diagnostics.md). The retained activation guard stays intact. No service restart or live FIFO activation is requested; earlier restart advice is withdrawn.

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

## Current capture procedure

Keep live FIFO disabled and Apply. Update the branch to `Version2.14.0-fifo.5`, start the passive input check, and print the input FIFO report after about 15 seconds. See the [passive instructions and limitations](module1-fifo-passive-input-diagnostics.md). The check cannot reconstruct earlier erased fault evidence and does not prove baseline continuity or delivery correctness.

## Verification

The executable FIFO suite passes 82 checks; all five suites total 343 checks. Targeted regressions reproduce an old incident masking later malformed-input failures, omitted observations and repeated zero-processing recovery; they verify source/type/byte evidence, no payload retention, persistence through disable/recreation, explicit unavailable-detail fallback and coalescing under fault-lock contention. Native failure evidence remains required to determine the production cause.
