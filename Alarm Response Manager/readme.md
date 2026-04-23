# Module 3 — Response / Output Orchestration Module

## 1. Purpose

Module 3 is the **domain-specific response engine** of the alarm architecture.

Its job is to:

- receive relevant **group-level alarm events directly from Module 1**
- obtain the **current authoritative house/system state from Module 2**
- evaluate the event with its own **state-dependent rule engine**
- determine the **human-readable alarm meaning**
- select and execute the appropriate **outputs**
- apply **local throttling per output type**

Module 3 may exist **multiple times**.
Each instance can be specialized for a dedicated alarm domain or use case, for example:

- intrusion
- hazard / fire
- technical alarms
- warning / advisory scenarios

Module 3 is **not** the global house-state owner and **not** the raw sensor aggregation owner.

---

## 2. Position in the architecture

### Module 1

Owns:

- raw sensor truth
- filtering / aggregation
- classes / groups
- generation of standardized group-level alarm events
- knowledge of which sensors are currently active within a triggered group

### Module 2

Owns:

- the authoritative house/system state machine
- interpretation of the global house state
- synchronization consistency with Module 1 via message/event tokens

### Module 3

Owns:

- domain-specific interpretation of Module 1 events in the context of Module 2 state
- human-readable incident identification
- state-based response rules
- output selection and execution
- local throttling of its own outputs

---

## 3. High-level philosophy

Module 3 answers the question:

**“Given this Module 1 group event, and given the current state of the house from Module 2, what should this alarm domain do now, and how should it be presented to the user?”**

Module 3 is therefore:

- **state-aware**
- **domain-specific**
- **output-oriented**
- **human-facing**

It is not just a dumb output relay.

---

## 4. Inputs of Module 3

Module 3 combines two core inputs:

### A. Events from Module 1

Module 1 sends **group-level alert messages** directly to Module 3.

These messages represent that a certain Module 1 group is active/alarming.
Examples:

- motion sensors group active
- fire alarm group active
- water alarm group active
- technical fault group active

Module 3 must be able to determine, for a relevant event:

- which group triggered
- which underlying sensors are active
- enough identity/context information to derive a human-readable alarm source/location

### B. State from Module 2

Module 3 queries Module 2 for the **authoritative current house/system state**.

Examples of such states:

- Disarmed
- Exit Delay
- Armed External
- Armed Internal
- Alarm

Module 3 uses this state to decide how the same Module 1 event should be interpreted and which outputs should result.

---

## 5. Consistency model / message tokens

Module 3 depends on **token-based consistency** between Module 1 events and Module 2 state.

Reason:
Module 3 must not interpret a fresh Module 1 event against an outdated Module 2 state snapshot.

Therefore, the message/event tokens are used so that Module 3 can ensure that:

- the Module 1 event being processed is clearly identified
- the Module 2 state used for evaluation is synchronized consistently with the same processing timeline

The exact token mechanics are defined by Module 1 / Module 2, but Module 3 depends on them for correctness.

The purpose of tokens is:

- consistency
- stale/duplicate protection
- deterministic interpretation across modules

---

## 6. Core responsibilities of Module 3

## 6.1 Domain-specific event interpretation

Module 3 interprets incoming Module 1 group events for its own domain.

Examples:

- an intrusion Module 3 may care about motion, doors, windows
- a hazard Module 3 may care about fire, water, gas
- a technical Module 3 may care about fault or service groups

Each Module 3 instance only reacts to the groups relevant to its configured purpose.

---

## 6.2 State-dependent rule evaluation

Module 3 contains a **rule engine**, organized by **house/system state**.

There is effectively a distinct rule set for each relevant state, for example:

- rules when Disarmed
- rules when Exit Delay
- rules when Armed Internal
- rules when Armed External
- rules when Alarm

This means the same Module 1 event may produce different responses depending on the current Module 2 state.

Examples:

- motion while disarmed may produce no alarm or only an advisory output
- motion while armed may produce a full intrusion response
- fire may trigger a strong response in every state, but with state-specific differences

---

## 6.3 Human-readable incident identification

Module 3 must determine **which concrete sensors are active** under the triggering Module 1 group and derive a **human-readable alarm description**.

Module 3 uses:

- the active sensor names
- the names of their parents
- the names of their grandparents

to build a clear description of:

- location
- source
- alarm type

Examples:

- “Motion detected in Guest Bedroom”
- “Smoke alarm in Kitchen”
- “Window contact alarm in Master Bedroom”
- “Water leak detected in Utility Room”

Important:
Module 3 does not invent this semantic quality by itself.
It depends on:

- the user’s naming discipline
- proper group assignment
- proper object tree / location hierarchy

So the wording quality is partly a configuration responsibility of the user.

---

## 6.4 Output selection

Based on:

- the triggering Module 1 event
- the synchronized Module 2 state
- the active sensors / location wording
- the Module 3 rule set

Module 3 determines which outputs should be generated.

Possible outputs include, for example:

- Alarm Bell
- E-Mail
- SMS
- Symcon Notification
- Alarm Light Outside
- Siren Outside
- Alarm Security Service
- All Blinds Down
- VOIP messages
- Voice announcements
- Message Screen
- Miscellaneous alarms

Each Module 3 instance selects outputs according to its own domain logic.

---

## 6.5 Output execution

Module 3 directly executes its configured outputs.

There is currently **no Module 4**.
Output responsibility stays inside each Module 3 instance.

So Module 3 is not only deciding the response; it is also responsible for carrying it out.

---

## 6.6 Local per-output throttling

Each Module 3 instance applies **its own throttling per output type**.

Purpose:

- prevent overload
- prevent repeated flooding
- respect output channel limitations

Examples:

- cooldowns for email/SMS/notifications
- avoiding repeated retriggering of the same bell/siren
- limiting repeated screen or VOIP messages
- throttling voice output locally

The current design assumes that this local throttling is sufficient for almost all outputs.

---

## 7. Voice output special case

Voice output is the one output type that may become a shared scarce resource.

Reason:

- spoken output is serial
- overlapping spoken messages create confusion
- a loudspeaker system cannot safely deliver multiple messages at the same time

Current design decision:

- there is **no central Module 4**
- each Module 3 throttles its own voice output locally
- because cross-domain simultaneous alarms are assumed to be very rare

Possible future extension:

- introduce a dedicated **voice server / shared voice service**
- all Module 3 instances would then submit voice messages there
- the voice service would centrally handle serialization / overall throttling

This is currently only a possible later extension, not part of the current architecture.

---

## 8. Multiplicity of Module 3

Module 3 can exist **more than once**.

Each instance may be configured for a dedicated purpose or situation.

Examples:

- one Module 3 for intrusion
- one Module 3 for hazard/fire
- one Module 3 for technical alarms

This means:

- Module 3 is **not a singleton**
- each instance has its own subscriptions / relevant groups
- each instance has its own rule engine
- each instance has its own outputs
- each instance throttles its own outputs locally

---

## 9. What Module 3 must know

Module 3 must know or obtain:

- which Module 1 groups it is responsible for
- which underlying sensors are active in a triggered group
- the names of the active sensors and their parent/grandparent hierarchy
- the current authoritative Module 2 state
- enough token information to ensure synchronized interpretation
- its own per-state rule configuration
- its own output definitions and per-output throttle settings

---

## 10. What Module 3 must not own

Module 3 must **not** own or redefine the following:

### Not owned by Module 3

- raw sensor truth
- debouncing / aggregation logic of Module 1
- global house/system state machine
- global synchronization authority
- decision of what the house state fundamentally is

### In practice

Module 3 may **consume** house state, but must not independently recreate or reinterpret the global house state logic that belongs to Module 2.

Module 3 may **consume** group events and active sensors, but must not become the raw aggregation engine of Module 1.

---

## 11. Boundary to Module 1

Module 1 must provide Module 3 with:

- direct group-level alert delivery
- consistent event identity/token
- access to or inclusion of the concrete active sensors behind a triggered group
- enough source metadata so that Module 3 can generate human-readable incident descriptions

Module 3 depends on Module 1 for event truth.

Module 1 should not assume Module 3 is merely a dumb output sink; Module 3 needs enough detail to identify:

- which sensor(s) triggered
- what kind of source they are
- where they are located in human terms

---

## 12. Boundary to Module 2

Module 2 must provide Module 3 with:

- the authoritative current house/system state
- a synchronized view that can be matched consistently to the Module 1 event token/timeline

Module 3 depends on Module 2 for state truth.

Module 2 should not assume that Module 3 only needs a simple label detached from event timing; Module 3 needs a state view that is consistent with the triggering Module 1 event.

---

## 13. Internal conceptual layers inside Module 3

Even if implemented inside one module, Module 3 conceptually contains these layers:

### A. Event intake

Receives and identifies the Module 1 group event.

### B. State synchronization

Obtains the authoritative Module 2 state consistently for the event.

### C. Incident interpretation

Determines:

- which concrete sensors are active
- where the incident is
- what kind of incident wording should be used

### D. Rule evaluation

Applies the domain-specific rules for the current house state.

### E. Output selection

Determines which outputs should ideally be used.

### F. Output delivery / throttling

Executes outputs and applies local per-output throttling.

This is a useful mental model for all involved AIs.

---

## 14. Design assumptions behind Module 3

The current architecture assumes:

- simultaneous alarms across different Module 3 domains are very rare
- most outputs do not require global cross-instance arbitration
- duplicate/overlapping activation of many outputs is acceptable or harmless
- voice is the only output type that may later require central coordination
- therefore local per-Module-3 throttling is sufficient for now

This assumption is intentional and complexity-reducing.

---

## 15. Non-goals

Module 3 is not intended to:

- replace Module 2 as the central brain
- replace Module 1 as the grouping/aggregation engine
- centrally coordinate all output resources across all Module 3 instances
- solve shared voice arbitration globally in the first version

---

## 16. Short version

**Module 3 is a multi-instance, domain-specific, state-aware response engine. It receives group-level alerts directly from Module 1, uses synchronized authoritative house state from Module 2, resolves the concrete active sensors into human-readable alarm descriptions using object names and hierarchy, applies per-state response rules, selects and executes outputs, and throttles its own outputs locally.**

---

## 17. Ultra-short version for alignment checks

**Module 3 consumes Module 1 group alarms plus Module 2 house state, interprets them for its own alarm domain, derives readable alarm location/type text from active sensors and hierarchy, then executes and locally throttles the resulting outputs.**

---

Now the implemented output types can be described much more precisely.

## Implemented output resource types and their parameters

| Type / TypeID                                 | TargetObjectID means                                                                                     | Main content source               | Type-specific input parameters                                                 | What is actually sent                                                               |
| --------------------------------------------- | -------------------------------------------------------------------------------------------------------- | --------------------------------- | ------------------------------------------------------------------------------ | ----------------------------------------------------------------------------------- |
| `request_action`                              | Symcon object that receives `RequestAction(...)`                                                         | `BuildRequestActionValue(...)`    | `ActionValueMode`, `ActionFixedValue`                                          | Depending on `ActionValueMode`: composed message text, fixed value, or JSON payload |
| `notification`                                | Notification-capable target object / instance                                                            | composed message text             | no extra confirmed parameters beyond common text fields                        | text message                                                                        |
| `voip`                                        | VoIP/call target object / instance                                                                       | composed message text             | no extra confirmed parameters beyond common text fields                        | text passed to the VoIP handler                                                     |
| `screen`                                      | not a transport target in practice; output is written to Module 3’s internal `OutputScreenHtml` variable | `BuildOutputScreenEntryHtml(...)` | `ScreenLogMaxEntries` is a module property, not a resource-row field           | rendered HTML entry appended to the screen log                                      |
| `email_1` / `email_2` / `email_3` / `email_4` | SMTP instance ID                                                                                         | subject + body from Module 3      | no extra row-specific parameters confirmed here; type is selected via `TypeID` | `SMTP_SendMail($TargetObjectID, $subject, $body)`                                   |

## Common output-resource fields used for content composition

These are the fields that feed the common text builder:

| Field                | Meaning                                  |
| -------------------- | ---------------------------------------- |
| `PrefixText`         | fixed text before the message            |
| `SuffixText`         | fixed text after the message             |
| `UseSensorName`      | include sensor name                      |
| `UseParentName`      | include parent name                      |
| `UseGrandparentName` | include grandparent name                 |
| `UseContent`         | include the core generated incident text |
| `PrefixOrder`        | order position of prefix                 |
| `SensorOrder`        | order position of sensor name            |
| `ParentOrder`        | order position of parent name            |
| `GrandparentOrder`   | order position of grandparent name       |
| `ContentOrder`       | order position of generated content      |
| `SuffixOrder`        | order position of suffix                 |

So the final human-readable text is not just prefix/body/suffix in a fixed order. It is assembled by the configured order fields.

---

## `request_action` in detail

This is now clear.

| Field / parameter  | Meaning                                   |
| ------------------ | ----------------------------------------- |
| `TargetObjectID`   | object that receives `RequestAction(...)` |
| `ActionValueMode`  | defines payload format                    |
| `ActionFixedValue` | used only when mode is `fixed_value`      |

### Supported `ActionValueMode` values

| ActionValueMode | Content source                  | Result                 |
| --------------- | ------------------------------- | ---------------------- |
| `message_text`  | `BuildOutputMessageText(...)`   | plain composed text    |
| `fixed_value`   | `ActionFixedValue`              | fixed configured value |
| `json_payload`  | Module 3 builds structured JSON | JSON string payload    |

### `json_payload` contains

| JSON field               | Meaning                      |
| ------------------------ | ---------------------------- |
| `group`                  | group label                  |
| `house_state_id`         | current house state ID       |
| `house_state_name`       | current house state name     |
| `message`                | composed output message text |
| `event_epoch`            | event epoch from payload     |
| `event_seq`              | event sequence from payload  |
| `target_trigger_details` | trigger details from payload |

So your earlier question was exactly right:

**For `request_action`, the content does not come from `TargetObjectID`.**
`TargetObjectID` only defines the recipient.
The actual content comes from `ActionValueMode` plus the corresponding builder/value source.

---

## `email` in detail

The email branch is also now precise.

| Field / parameter | Meaning                                                   |
| ----------------- | --------------------------------------------------------- |
| `TypeID`          | must be one of `email_1`, `email_2`, `email_3`, `email_4` |
| `TargetObjectID`  | SMTP instance ID                                          |
| `subject`         | supplied by Module 3 execution logic                      |
| `body`            | supplied by Module 3 execution logic                      |

### What happens

Module 3 validates:

- email type is allowed
- target instance exists
- `SMTP_SendMail` is available

Then it sends:

`SMTP_SendMail($TargetObjectID, $subject, $body)`

So for email, the important type-specific meaning is:

- `TargetObjectID` = SMTP sender instance
- content = **subject + body**, not a single message value

---

## `screen` in detail

| Field / parameter     | Meaning                                   |
| --------------------- | ----------------------------------------- |
| `TargetObjectID`      | effectively not used in the handler shown |
| `OutputScreenHtml`    | target variable inside Module 3 instance  |
| `ScreenLogMaxEntries` | module property limiting retained entries |

### What happens

- Module 3 builds one HTML entry with `BuildOutputScreenEntryHtml(...)`
- prepends it to the current screen entries
- trims to `ScreenLogMaxEntries`
- writes the wrapped HTML back into `OutputScreenHtml`

So `screen` is really an **internal presentation output**, not an external transport.

---

## Practical conclusion

The implemented output types are not all shaped the same:

| Type             | Payload shape              |
| ---------------- | -------------------------- |
| `request_action` | text, fixed value, or JSON |
| `email_*`        | subject + body             |
| `notification`   | text                       |
| `voip`           | text                       |
| `screen`         | HTML entry                 |

So when cloning Module 3 configurations, the fields that are most likely to be **type-sensitive** are:

- `TypeID`
- `TargetObjectID`
- `ActionValueMode`
- `ActionFixedValue`
- the text composition flags/order fields

If you want, the next useful step is a **template-safe vs instance-specific table for OutputResources**.
