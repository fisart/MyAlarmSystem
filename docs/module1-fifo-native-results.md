# Native probe results: 2026-09-13

Artur supplied the optional observer report and watchdog history after running the probe in production. This records aggregated findings; raw uploaded reports are not committed. No FIFO was enabled during this measurement.

## Assessment

The ordinary-update observation supports starting Module 1 shadow implementation. It does not approve a production FIFO rollout or establish lossless capture, burst capacity, crash recovery or every alarm rule. Heartbeat remains an ordinary input with no priority/bypass.

Installation observed: Symcon 9.0, PHP 8.5.5. Capture: 12:46:56–12:48:57 local time, two-minute requested duration, three boolean variables and the integer heartbeat input. The observer stopped on duration and reported no contention, lifecycle interruption or unfinished session.

## Message evidence

- 83 samples: 18 native changed flags and 65 unchanged refreshes. All belong to the same session; metadata counts match the records.
- All samples carry six native Data fields with untruncated typed descriptions. Field 0 agrees with the later live-value comparison in all 83 observations. Field 1 is boolean and consistently agrees with field 0 differing from field 2. This supports field 0 as the captured value, field 1 as changed and field 2 as prior value on this installation; do not assume undocumented timestamp-field meanings.
- Two boolean variables produced seven transitions each. The third produced 24 unchanged false refreshes, so it did not supply an open/close test.
- No native counter regression or callback-entry order regression was observed. Gaps in the global counter cannot establish event loss, and absence of contention cannot establish serialization in every case.
- Two heartbeat tokens and both resets were recorded through the same observer code as the boolean inputs. Their observed pulse durations were 999.115 ms and 999.626 ms. Token spacing was approximately 61.003 seconds despite the configured 60-second cycle; the existing watchdog's synchronous pulse/reset behavior is outside the FIFO scope.
- Callback entry to admission: median 0.1452 ms, maximum 3.759744 ms. The separately reported maximum callback work metric was 3.874347 ms and excludes final metadata/stop writes. These are observer measurements, not Module 1 processing or future FIFO latency.
- Serialized sample data: 50,748 bytes (49.6 KiB). This is not PHP resident memory. Maximum reported diagnostic timer lateness was 6.13324 ms; the final tick was about 123 ms after the requested deadline.

## Heartbeat baseline

Watchdog settings had no unapplied changes: 60-second cycle, 1,000 ms pulse, 45-second delivery timeout and 120-second Module 1 maximum age.

All ten retained cycles and all four monitored target records per cycle were OK.

| Monitored target | Runtime range across ten cycles | Runtimes of the two captured tokens |
|---|---|---|
| Module 1 callback | 112–714 ms | 123, 125 ms |
| Module 3 Intrusion | 52–65 ms | 62, 57 ms |
| Module 3 Hazard | 61–85 ms | 71, 70 ms |
| Module 3 Technical | 105–142 ms | 120, 123 ms |

The 714 ms Module 1 callback occurred at 12:49:11, after the observer capture stopped. It is an outlier within the existing timeout, not evidence of FIFO overhead; FIFO was not enabled. Heartbeat callback confirmation does not prove every output executed or every intrusion rule is correct.

## Remaining implementation checks

This was ordinary traffic (83 observations over two minutes), not a saturation test. Before enabling FIFO, verify captured-value handling for any additional configured scalar types, bootstrap/reference consistency, configuration cutovers, COUNT/pulse semantics, shutdown/enqueue races, native semaphore/timer contention and bounded overflow/recovery. Use shadow comparisons with no extra alarm outputs and measure CPU, PHP memory and event-to-dispatch latency under production-shaped bursts. Compare the future shared-path heartbeat timing with this baseline.
