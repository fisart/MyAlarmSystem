# Questions for Symcon: safe Module 1 FIFO activation without a service restart

**Historical investigation, now shelved.** Artur accepts missed messages/overlapping previous evaluations during a supervised test. The current next step is the [first-failure diagnostic test](module1-fifo-first-failure-test.md), not vendor contact, further thread inventory or a Symcon restart. Native-guarantee statements below describe the earlier conservative investigation; they are not prerequisites for this explicitly authorized test.

Prepared technical inquiry; not sent. Target installation: Symcon 9.0, Ubuntu (Docker), amd64, build dated 15 June 2026, revision `f2880badc0d6`, PHP 8.5.5.

We need to switch one PHP module from overlapping legacy evaluations to a single FIFO evaluator without restarting IP-Symcon or interrupting unrelated services. Some older evaluations were not registered in an active-call counter; an idle module semaphore does not account for those calls. We currently retain a conservative activation guard and leave legacy monitoring running.

A one-shot native inventory listed 100 entries. Several returned StartTime=0 and ScriptID=0. One returned a nonzero StartTime/ScriptID matching the diagnostic capture's second. The native row contains ThreadID, ExecuteCount, ExecutionMin/Avg/Max, StartTime, Sender, SenderID, FilePath, ScriptID, PeakMemoryUsage and MemoryCleanups. We need the API guarantees, rather than a guess based on observed fields:

1. **Coverage:** Does IPS_GetScriptThreadList/GetScriptThread include every direct PHP-module MessageSink invocation, public module/control call, timer call and nested synchronous module dispatch? Does a worker remain marked executing until the outer caller and all synchronous receiver calls have actually returned? Are there executing PHP-module contexts outside this enumeration?
2. **Idle and identity:** Does StartTime=0 guarantee the previous invocation fully finished, rather than paused/waiting for I/O? Are records read coherently? When is ExecuteCount incremented, can it wrap/reset, and is there a supported unique execution identity or monotonic completion count distinguishing reuse of the same worker in the same second? Does IPS_ScriptThreadExists identify a worker slot or an individual execution?
3. **Update boundary:** After a PHP library update/reload returns, can an already queued call still enter the previous module implementation? Does Destroy or another supported lifecycle operation wait for all older evaluations/dispatch to finish? What supported mechanism establishes a code-generation boundary without a full service restart?
4. **Supported transition:** Is there a supported way to establish that all old calls to one module have finished while preserving incoming messages and unrelated execution? What exact API/ordering should be used, and what failures or race conditions must cause activation to defer?

We will not terminate threads, manually clear the guard, infer completion from a fixed delay or require a production-wide quiet period. The intended future mechanism registers every new legacy evaluation before entry, removes it after synchronous dispatch in finally, and switches FIFO ownership under a shared short admission barrier only when prior calls are proven complete. That mechanism still needs a justified migration boundary for historical untracked calls.

Until the native guarantees are known, neither a missing/idle thread nor a clean heartbeat/payload sample authorizes clearing the guard. No runtime activation change is proposed in this inquiry.
