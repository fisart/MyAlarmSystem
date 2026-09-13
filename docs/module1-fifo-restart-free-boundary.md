# Restart-free FIFO activation: native execution boundary review

**Historical investigation, now shelved.** Artur accepts missed messages/overlapping previous evaluations during a supervised test. The current next step is the [first-failure diagnostic test](module1-fifo-first-failure-test.md), not vendor contact, further thread inventory or a Symcon restart. Native-guarantee statements below describe the earlier conservative investigation; they are not prerequisites for this explicitly authorized test.

Status: investigation; activation remains blocked. Module runtime stays at `Version2.14.0-fifo.5`. Do not restart Symcon, reload the library as a guard reset, stop unrelated scripts/services or edit internal guard attributes. Keep live FIFO disabled and existing evaluation running.

## Native execution inventory received

Installed build supplied by Artur: Symcon 9.0, Ubuntu (Docker), amd64, 15 June 2026, revision `f2880badc0d6`; PHP 8.5.5. At 16:59:11+02:00 on 13 September 2026, all four queried function names were PHP-callable. The actual list contained 100 entries. The capped inventory inspected IDs 100 through 93; each returned 12 fields, including ThreadID, ExecuteCount, ExecutionMin/Avg/Max, StartTime, Sender, SenderID, FilePath, ScriptID, PeakMemoryUsage and MemoryCleanups.

Seven inspected entries had StartTime=0 and ScriptID=0. ID 96 had StartTime=1789311551 and ScriptID=44773. That start time is exactly the capture's wall-clock second; this could be the inventory execution itself, but the script's identity was not supplied. Sender/SenderID values were not retained by this first schema check. The other 92 entries were not inspected.

Inference: the list appears to enumerate reusable worker slots, including apparently idle slots, rather than only currently executing invocations. Therefore waiting for a listed ID to disappear is not a justified completion test. This is an inference from the observed schema/state, not an established API lifetime guarantee. The zero fields' idle semantics, ExecuteCount increment semantics and inclusion of direct module MessageSink/nested dispatch still need confirmation. The snapshot does not identify a lingering old Module 1 call or prove that none exists.

No further routine inventory is requested at this point. The next required evidence is the [native execution contract](module1-fifo-native-contract-questions.md). The previous inventory instructions below are retained as the procedure that produced this result, not as a request to repeat it.

## New production evidence

Artur's passive session `ae50885deb13e3a9` started 13 September 2026 at 16:46:20+02:00 and completed its 15-second duration. It inspected 84 native updates, rejected none, and reported no interruption or configuration change. Revision: `d9dabb098a9c68d56e53838368c358a7637aa1821f623acbd0c5e47d1059ba18`. Live FIFO was disabled; the historical activation guard remained retained. No unknown inputs or current FIFO fault were reported, and no actual FIFO metrics were collected.

This rules out malformed contracts among those inspected samples. It does not rule out occasional malformed events, prior-value gaps, baseline races, missing events, synchronous delivery failures or lingering old evaluators. The earlier 149-recovery loop remains unexplained.

## Primary-source findings

- Official [GlobalStubs.php](https://github.com/symcon/SymconStubs/blob/master/GlobalStubs.php) exposes `IPS_GetScriptThreadList()`, `IPS_GetScriptThread(int)` and `IPS_ScriptThreadExists(int)`. `IPS_GetScriptThreads(array)` is explicitly JSON-RPC-only; the script must not call it directly.
- Official [KernelStubs.php](https://github.com/symcon/SymconStubs/blob/master/KernelStubs.php), class ScriptEngine, leaves all three thread-inspection implementations as Not implemented. The stubs establish names/signatures, not native row schema, coverage, lifetime, generation or completion semantics.
- [Create](https://www.symcon.de/en/service/documentation/developer-area/sdk-tools/sdk-php/module/create/) documents instance creation and Symcon startup. [Destroy](https://www.symcon.de/en/service/documentation/developer-area/sdk-tools/sdk-php/module/destroy/) documents instance deletion/module update. Neither page establishes that Destroy waits for every existing evaluation and nested synchronous receiver call to finish.
- [The constructor](https://www.symcon.de/en/service/documentation/developer-area/sdk-tools/sdk-php/module/construct/) runs with every function call. That alone does not establish which code generation a queued/in-flight invocation can use after an update.

Consequently, neither library update nor the disappearance of one observed thread ID is currently an approved completion barrier. An observed empty list would also need proven coverage before it could authorize cutover.

## Design challenged by independent review

The tempting migration is to record the running threads, install tracking for subsequent calls, wait until recorded threads disappear, then clear the historical guard. It is rejected until native guarantees establish all of the following:

1. The enumeration includes direct Module 1 MessageSink, control, baseline and nested synchronous dispatch invocations, through actual completion.
2. Every old-code call is represented at the boundary, or a documented generation barrier prevents an unseen queued old-code call from entering later.
3. Thread IDs plus start/generation identity distinguish reuse, and disappearance/completion is reliable. Snapshot races and failed reads must defer activation.
4. Every subsequent legacy evaluator registers under the same short admission mutex before entry, deregisters in finally after synchronous dispatch, and shares that registration barrier with Apply.
5. Cutover switches ownership only at a proven zero-active boundary, retains admitted inputs in a bounded transition mechanism and handles configuration changes/reentrancy without manufacturing historical events.

Adding future tracking does not retroactively satisfy the first three requirements. A fixed delay, idle worker acquisition, clean heartbeat or 84 clean payload samples is not equivalent. Registration overflow/contention, interrupted tracking or unsupported native semantics must keep the guard retained. No transition queue/counter/polling implementation is introduced here; its overhead and event-loss policy require a separate review once the historical boundary can be established.

## Inventory procedure already completed

Run [the execution inventory script](../tools/symcon_fifo_execution_inventory.php) once in the existing Symcon script editor, with live FIFO disabled. Paste the complete file including its PHP opening tag. Send its JSON output. No module update or restart is needed to run it.

The script checks PHP-callable capabilities, reads the thread list once and inspects at most eight entries. Each detail retains at most 32 field names/type labels and allowlisted numeric ID/timing/status fields. Script text, source, parameters, strings and exception messages are not copied. A thread finishing between list/detail reads is marked unavailable, not healthy. It does not invoke the JSON-RPC-only API, alarm modules, sensor writes, semaphore operations, logging, archives, sleep, polling, reload or thread termination.

This is API/schema evidence only. The snapshot includes the diagnostic execution if Symcon lists it. It cannot identify all Module 1 execution from redacted fields or prove absence of old calls. Even a clean inventory will not clear the guard. The installed capabilities/fields determine whether a more focused investigation is possible; otherwise vendor clarification of the guarantees above is needed before implementation.

Output and detail reads are bounded, but the kernel's allocation/cost to return a whole native list or one detail record is not controlled by the script. This is one on-demand census with no continuing workload; CPU/resident RAM remain unmeasured.

## Current acceptance

Independent review agrees that native thread inspection is evidence gathering, not proof of quiescence. Restart-free activation is not approved. The existing Module 1 evaluator and Module 2/3/watchdog runtime remain unchanged. Native FIFO activation/recovery testing stays deferred until the execution boundary and earlier recovery cause can be addressed.
