# Supervised production Module 1 FIFO trial

Current diagnostic release: **Version2.14.0-fifo.12**, branch **design/module1-fifo**, draft PR #3. This is a supervised trial, not permanent production approval or a main merge. Modules 2/3 and the watchdog are unchanged.

## Confirmed starting position

Production SensorGroup instance **23172**: configuration committed, no unapplied changes; live FIFO, shadow and failure capture off; actual FIFO ownership off, worker timer 0. Its historical legacy-overlap guard remains set. The pending 61-class list differed only by missing internal ClassIDs; supported COMMIT restored IDs from the validated active configuration.

The isolated lab already runs actual Module 1 MessageSink/FIFO/worker code. Native tests established aggregate 70/70 processing under concurrent synthetic activity, selected nine-event mirror/COUNT/ONCE/token/reset semantics, and deliberately provoked fault detection. They did not exercise real downstream receiver costs, broad production rule activation, sustained overload, or long-term reliability. The separate real-heartbeat-overlap lab is deferred by the user; heartbeat remains an ordinary source in the production trial.

## New explicit trial policy

`AllowFifoTrialCutover` defaults false. It requires live FIFO to be enabled and applied. The existing worker owner semaphore is still required, and previously admitted FIFO work must finish before Apply changes ownership. Only the retained historical legacy-overlap blocker is overridden for the expressly accepted supervised switching boundary; the guard is not cleared, and no claim of completed historical evaluations is made. Lost/overlapping switching edges remain possible.

Applied `FifoTrialActive` is separate from `FifoTestActive`. Normal FIFO faults schedule existing automatic baseline recovery, retaining the incident/last-fault warning. The trial does **not** pause forever at the first fault. Trial and failure capture cannot activate FIFO together; conflict leaves the existing evaluator active and reports the reason. Normal recovery can resample current values but cannot reconstruct omitted historical events.

The trial uses the native-tested pending continuation of 10 ms, unchanged initial/idle wake of 50 ms, one worker owner, 128 slots, 256 KiB queue bytes, existing 10/10/1 ms queue waits and soft 20 ms/32-record batch limit. All sensor kinds, including heartbeat token/reset, use the same queue/evaluation path. No priority, reserved slots or direct-delivery bypass is added.

There is **no automatic expiry or automatic switch to legacy**. End the trial manually while present. Recovery attempts can remain degraded if current inputs never yield a valid baseline. A rollback request can be deferred while trustworthy queued work drains; the checkbox alone does not establish actual disabled ownership.

## Activation on the user's server

1. Update the installed **design/module1-fifo** library and verify **Version2.14.0-fifo.12**. No Symcon restart or lab reinstall is required. Updating the shared library changes the loaded production code as well as lab code; defaults do not activate FIFO.
2. Open **production Module 1 / SensorGroup 23172**, not Module1Test 54312 or 18919. Finish known configuration edits first. Keep shadow and failure capture off.
3. Under **Ordered input FIFO (supervised testing)**, check **Use FIFO for live Module 1 alarm processing** and **Supervised FIFO trial: allow switching overlap with normal recovery**. Press **COMMIT ALL CHANGES TO DISK**.
4. Use **Print input FIFO report** while FIFO is running. Do not stop FIFO to obtain it. Check `enabled=true`, `ready=true`, `trial.active=true`, `trial.automatic_recovery=true`, `test.active=false`, `unknown_inputs=[]`, and `fault=""`. A retained incident/legacy-overlap warning is expected and must not be mistaken for current failure. Start-up may briefly show baseline recovery pending; sustained recovery is not a successful activation.
5. Share the first report before expanding the trial. Observe normal sensor activity and existing heartbeat history, then exercise the already-understood house-state checks while present. Confirm Module 2 state and Module 3 behavior as well as queue metrics; processed counts alone do not prove downstream acceptance. Compare later reports' admissions/processed/backlog, lag and recoveries; an idle queue legitimately has worker interval 0, and continuing recoveries or increasing backlog need investigation.

The Mermaid page's existing FIFO status panel displays the active-trial/manual-stop instruction and retained overlap warning using its existing refresh. The form and `Input FIFO Health` variable show the same status. No additional polling is installed. CPU and resident RAM are unmeasured; synchronous receivers can exceed the soft batch target. This trial can delay or omit real alarm inputs under overload/fault, so leave stable main available.

## End or rollback

Uncheck live FIFO and the supervised-trial option on **23172**, keep failure capture/shadow off, then COMMIT. Inspect the report until `enabled=false`, `trial.active=false`, `test.active=false`, and `worker_interval_ms=0`; queued work may postpone Apply. The retained guard/incident can remain. The existing evaluator then handles inputs. Do not clear the legacy guard manually. Stable main remains unchanged and contains the tested configuration/safety fixes; its original competing-input loss issue remains unresolved.

## Local verification

The added trial suite covers default/applied/draft permissions, retained guard/warning, real receiver-path token/reset values through the stub, normal fault recovery after a trustworthy prefix, timer failure/no replay, fast pending scheduling, deferred rollback, conflict refusal and unchanged failure-capture pause policy. It does not establish native semaphore timing, production receiver delivery or protection during uncertain switching.
