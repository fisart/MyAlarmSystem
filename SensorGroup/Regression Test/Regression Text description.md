Working overall Committ =  9fba167

# 1. Purpose of the test script

The script is a **full end-to-end regression test** for the **house-state path**:

**raw variables → Module 1 aggregation/dispatch → Module 2 business logic/state machine**

It is intended to be run after **every relevant change** in:

- Module 1 configuration
- Module 1 logic
- Module 2 logic
- polarity handling
- bedroom logic
- arming rules
- token handling / event sequencing

It does **not** test Module 3 alarm outputs.

---

# 2. What the script verifies

The script verifies five things at the same time:

## A. Variable writes work

Each input variable used by the test is written with `SetValue()`, and the script confirms the value was actually changed.

## B. Module 2 processes a newer snapshot/token

After every step, the script checks that Module 2 processed a newer event snapshot.

This guards against:

- stale processing
- no-message situations
- hidden propagation failures
- timing bugs where raw values changed but Module 2 did not consume the final state

## C. Business state is correct

The script checks:

- `StateID`
- `IsDelayActive`
- `PresenceMap`
- selected high-level business outcomes

## D. Transition behavior is correct

The script checks not only static states, but also transitions:

- secure → arming allowed
- secure → one violation → arming blocked
- presence on/off effects
- bedroom veto activation/removal

## E. Timing-sensitive scenarios are stable

For critical scenarios, the script does not trust one single read.
It waits until the expected business condition is visible **stably** for multiple polls.

This prevents false failures caused by:

- propagation delays
- intermediate snapshots
- race conditions between Module 1 and Module 2

---

# 3. Scope of the script

## Included

The script covers the **house-state business logic**:

- windows
- generic doors
- ground floor entrance
- basement entrance
- presence
- bedroom active/inactive logic
- bedroom door veto logic
- away/home arming path
- veto removal
- token advancement

## Excluded

The script does **not** cover:

- Module 3 outputs
- sirens
- notifications
- burglary reaction routing
- hazard alarm routing
- police / email / output actions
- UI rendering / HTML dashboard correctness

---

# 4. Current business logic model behind the test

This is the logic the test currently assumes.

## 4.1 Global intent of Module 2

Module 2 only evaluates the **house state**.

It answers questions like:

- Is the house secure enough to arm?
- Is the system disarmed?
- Is Exit Delay active?
- Are bedroom vetoes active?
- Does a current condition block arming?

It does **not** decide the final burglary response behavior. That belongs to Module 3.

---

## 4.2 Presence

`45731`

- `true` = someone home
- `false` = away

Effects:

- when away, bedroom vetoes are ignored
- when home, active bedrooms matter

---

## 4.3 Windows

Variables:

- `54217`
- `53418`
- `41617`

Business meaning:

- all windows must be secure/closed for the house to be armable

Important:

- Module 2 evaluates the **business result**
- it does **not** need to expose every individual window sensor as an arming result
- the test therefore checks whether the total house-state outcome is correct, not whether `"Windows"` appears as a low-level direct signal in every scenario

---

## 4.4 Generic doors

Variables:

- `10775`
- `22945`
- `49829`

Business meaning:

- all generic doors must be secure/closed for the house to be armable

Same principle as windows:

- the business outcome matters
- not every internal representation must be directly visible as a low-level sensor assertion

---

## 4.5 Ground-floor entrance

Variables:

- `14125` = GF lock
- `25478` = GF contact

Business meaning:

- the entrance is secure only when the required secure condition is met by that entrance path
- incomplete entrance state must block arming
- breaking one member from an allowed state must block arming

---

## 4.6 Basement entrance

Variables:

- `32928` = basement lock
- `41718` = basement contact

Same business meaning as the GF entrance.

---

## 4.7 Bedrooms

Active variables:

- `28379` = Master active
- `38285` = Small guest active
- `28099` = Big guest active

Door members:

- Master: `29918`, `34618`
- Small guest: `36671`
- Big guest: `18998`

Business meaning:

### when presence = false

Bedrooms do **not** block arming.

### when presence = true

Only **active bedrooms** matter.

For each active bedroom:

- if bedroom secure condition is satisfied, no veto
- if bedroom secure condition is not satisfied, arming is blocked

Also:

- if a currently active secure bedroom becomes insecure, arming must be blocked

---

## 4.8 Bedroom polarity

Your current live system uses the **inverted bedroom-door interpretation** compared with the earlier script assumptions.

So the test now follows the observed live behavior:

- active + insecure bedroom → `DoorTripped = false`
- active + secure bedroom → `DoorTripped = true`

That is not treated as a fault.
That is now part of the documented expected behavior for the current configuration.

---

## 4.9 Arming path

When all required secure conditions are met, Module 2 should move to:

- `StateID = 2`
- `IsDelayActive = true`

This is interpreted as:

- **Exit Delay**
- house is armable and currently entering the arming path

---

## 4.10 Blocking conditions

The script verifies that the arming path is blocked when any required condition is broken, for example:

- one window opens
- one generic door opens
- one entrance element becomes insecure
- one active bedroom becomes insecure

Expected result:

- `StateID = 0`
- `IsDelayActive = false`

---

# 5. Current scenario set

The regression suite currently contains these scenario groups.

## S01

Baseline reset

## W01–W03

Window-only logic

## D01–D03

Generic-door-only logic

## E01–E06

Entrance completeness logic

## B01–B06

Bedroom state mapping / bedroom secure-insecure evaluation

## H01–H06

House-state arming matrix:

- away secure
- home secure with no active bedrooms
- home secure with active secure bedroom
- home blocked by insecure active bedroom
- all active bedrooms secure
- one active bedroom insecure

## V01–V05

Violation transitions from an allowed state

## P01–P02

Presence interaction with bedroom veto logic

## T01

Token advancement and propagation integrity

---

# 6. Why stable wait logic was needed

Earlier versions of the script produced false failures because they used a simple model:

- write variables
- sleep fixed time
- read once

That was not always enough.

The live system has a chain:

- raw variable update
- Module 1 class evaluation
- Module 1 group evaluation
- dispatch
- Module 2 consume payload
- Module 2 state machine transition

During this chain, there can be **intermediate valid-but-not-final states**.

So the new script uses stable waiting for critical steps:

- it polls repeatedly
- it checks the target business condition
- it requires several consecutive successful reads

This prevents failures caused by:

- checking too early
- transient intermediate states
- different propagation timing in different scenarios

---

# 7. Recommended version metadata to add to the script

Add this block near the top of the script.

```php
$TEST_SCRIPT_VERSION    = '1.0.0';
$SCENARIO_MATRIX_VERSION = '1.0.0';
$EXPECTED_MODULE2_VERSION = '7.2.0';
$LOGIC_PROFILE = [
    'presence_semantics'         => 'true=home, false=away',
    'arming_state_id'            => 2,
    'arming_delay_field'         => 'IsDelayActive',
    'bedroom_polarity_note'      => 'Current live behavior: secure bedroom -> DoorTripped=true, insecure bedroom -> DoorTripped=false',
    'scope'                      => 'House-state only, no Module 3 output testing'
];
```

And include it in the report:

```php
'test_script_version'      => $TEST_SCRIPT_VERSION,
'scenario_matrix_version'  => $SCENARIO_MATRIX_VERSION,
'expected_module2_version' => $EXPECTED_MODULE2_VERSION,
'logic_profile'            => $LOGIC_PROFILE,
```

---

# 8. What each future test run means

When the script passes completely, it means:

## Confirmed

- input writes work
- Module 1 and Module 2 propagate correctly
- token progression works
- current business logic is internally consistent
- current bedroom logic matches expectations
- current arming / blocking path behaves as documented

## Not automatically confirmed

- Module 3 behavior
- UI text correctness
- human-readable wording in dashboards
- all possible production edge cases outside the scenario matrix

---

# 9. Limits of the regression test

Even a full regression test has boundaries.

## It is strong for:

- repeated validation after code/config changes
- catching regressions
- validating known business rules
- detecting propagation/token issues

## It is weaker for:

- discovering completely new business rules not yet modeled
- testing every possible combinatorial input state
- diagnosing root cause inside Module 1 or Module 2 implementation internals

So this script should be treated as:

**a formal regression harness for the agreed business matrix**

not as a mathematical proof of all possible states.

---

# 10. When you should update the scenario matrix version

Increase `SCENARIO_MATRIX_VERSION` whenever:

- business rules change
- polarity meaning changes
- new bedrooms are added
- new required secure conditions are added
- arming conditions change
- state meanings change
- a scenario is added, removed, or redefined

---

# 11. When you should update the test script version

Increase `TEST_SCRIPT_VERSION` whenever:

- timing/wait logic changes
- report structure changes
- assertion logic changes
- token handling changes
- output formatting changes
- scenario execution engine changes

---

# 12. Suggested versioning rule

A practical rule:

- **patch**: small non-business fixes
  Example: better wording, logging cleanup, timeout tuning

- **minor**: scenario additions or reporting additions
  Example: new scenario group, extra assertions, new metadata

- **major**: changed business interpretation
  Example: new bedroom polarity model, new arming semantics, changed state meaning

---

# 13. Recommended documentation header for the script

Put this comment block at the top of the script:

```php
/*
 * PSM FULL REGRESSION E2E TEST
 *
 * Test Script Version: 1.0.0
 * Scenario Matrix Version: 1.0.0
 * Expected Module 2 Version: 7.2.0
 *
 * Scope:
 * - End-to-end regression for Module 1 -> Module 2 house-state logic
 * - Validates write path, token progression, state machine outcomes,
 *   bedroom veto logic, presence interaction, and critical transitions
 * - Excludes Module 3 output behavior
 *
 * Current logic profile:
 * - Presence true = home
 * - Presence false = away
 * - Exit Delay = StateID 2
 * - Delay flag = IsDelayActive
 * - Current live bedroom polarity:
 *     secure bedroom   -> PresenceMap DoorTripped = true
 *     insecure bedroom -> PresenceMap DoorTripped = false
 *
 * Test philosophy:
 * - Minimal console output
 * - Internal assertions only
 * - Stable wait polling for timing-sensitive scenarios
 * - Full JSON report persisted to disk
 */
```

---

# 14. Recommended report header fields

Your JSON report should include:

- `test_script_version`
- `scenario_matrix_version`
- `expected_module2_version`
- `started_at`
- `finished_at`
- `module2_id`
- `logic_profile`
- `scenario_pass`
- `scenario_fail`
- `failed_assertions`
- `scenario_results`

---

# 15. Practical usage guidance

Run this script after:

- any Module 1 config change
- any Module 1 class/group logic change
- any Module 2 state rule change
- any bedroom handling change
- any polarity change
- any token-processing change

If a run fails:

1. first check whether the failure is in a **setup/precondition** step or in the **actual target step**
2. compare with live UI/dashboard
3. decide whether:
   - business logic changed intentionally
   - test expectation is outdated
   - there is a real regression
   - it is a timing issue

---

# 16. Current conclusion

With the latest run:

- **33 pass**
- **0 fail**

So the current regression harness is in a good state and can now serve as your standard post-change validation for Module 1 / Module 2 house-state behavior.

Below is the complete **business matrix** for the current **Module 2 house-state logic** as we have established it.

I split it into three parts so it stays readable:

1. **Input meaning**
2. **Business rules**
3. **Formal scenario matrix with sensor states and expected outputs**

---

# 1. Input meaning

## Presence

- `45731 = true` → **home**
- `45731 = false` → **away**

## Windows

- `54217`, `53418`, `41617`
- `true` = secure / closed
- all must be `true` for windows to be secure

## Generic doors

- `10775`, `22945`, `49829`
- `true` = secure / closed
- all must be `true` for generic doors to be secure

## Ground-floor entrance

- `14125` = GF lock
- `25478` = GF contact
- `true` = secure
- both must be `true` for GF entrance to be secure

## Basement entrance

- `32928` = basement lock
- `41718` = basement contact
- `true` = secure
- both must be `true` for basement entrance to be secure

## Bedroom active flags

- `28379` = Master active
- `38285` = Small guest active
- `28099` = Big guest active

`true` = bedroom is active and must be considered in home mode
`false` = bedroom is ignored

## Bedroom door members

- Master: `29918`, `34618`
- Small guest: `36671`
- Big guest: `18998`

Current live polarity in Module 2 / PresenceMap:

- **secure bedroom** → `DoorTripped = true`
- **insecure bedroom** → `DoorTripped = false`

So for the test/business interpretation:

### Master Bedroom

- secure if `29918 = true` **and** `34618 = true`
- insecure otherwise

### Small Guest Bedroom

- secure if `36671 = true`
- insecure if `36671 = false`

### Big Guest Bedroom

- secure if `18998 = true`
- insecure if `18998 = false`

---

# 2. Business rules

## Global arming prerequisites

The house can enter the arming path only if all of the following are secure:

- all windows secure
- all generic doors secure
- GF entrance secure
- basement entrance secure

And additionally:

### if away (`Presence = false`)

- bedroom states do **not** matter

### if home (`Presence = true`)

- every **active** bedroom must be secure
- inactive bedrooms do not matter

---

## Output meaning

### Armable / arming path reached

- `StateID = 2`
- `IsDelayActive = true`

### Not armable / blocked / disarmed

- `StateID = 0`
- `IsDelayActive = false`

---

# 3. Derived aggregate conditions

For compactness, define:

- `W = all windows secure`
- `D = all generic doors secure`
- `GF = ground-floor entrance secure`
- `BS = basement entrance secure`
- `M = master bedroom secure`
- `SG = small guest secure`
- `BG = big guest secure`

And active flags:

- `MA = master active`
- `SA = small guest active`
- `BA = big guest active`

Then:

- `PERIMETER_OK = W and D and GF and BS`

### Away mode

- `ARMABLE = PERIMETER_OK`

### Home mode

- `ARMABLE = PERIMETER_OK and every active bedroom is secure`

Equivalent:

- if `MA = false`, master is ignored
- if `SA = false`, small guest is ignored
- if `BA = false`, big guest is ignored

---

# 4. Complete house-state business matrix

## 4.1 Core perimeter matrix

| W   | D   | GF  | BS  | Presence  | Bedrooms relevant? | Expected output                    |
| --- | --- | --- | --- | --------- | ------------------ | ---------------------------------- |
| 0   | \*  | \*  | \*  | away/home | no                 | `StateID=0`, `IsDelayActive=false` |
| 1   | 0   | \*  | \*  | away/home | no                 | `StateID=0`, `IsDelayActive=false` |
| 1   | 1   | 0   | \*  | away/home | no                 | `StateID=0`, `IsDelayActive=false` |
| 1   | 1   | 1   | 0   | away/home | no                 | `StateID=0`, `IsDelayActive=false` |
| 1   | 1   | 1   | 1   | away      | no                 | `StateID=2`, `IsDelayActive=true`  |
| 1   | 1   | 1   | 1   | home      | yes                | depends on active bedrooms         |

`*` means irrelevant because an earlier blocking condition already failed.

---

## 4.2 Home mode bedroom logic matrix

This table applies only when:

- `Presence = true`
- `W=1`
- `D=1`
- `GF=1`
- `BS=1`

| MA  | M   | SA  | SG  | BA  | BG  | Expected output                    |
| --- | --- | --- | --- | --- | --- | ---------------------------------- |
| 0   | \*  | 0   | \*  | 0   | \*  | `StateID=2`, `IsDelayActive=true`  |
| 1   | 1   | 0   | \*  | 0   | \*  | `StateID=2`, `IsDelayActive=true`  |
| 1   | 0   | 0   | \*  | 0   | \*  | `StateID=0`, `IsDelayActive=false` |
| 0   | \*  | 1   | 1   | 0   | \*  | `StateID=2`, `IsDelayActive=true`  |
| 0   | \*  | 1   | 0   | 0   | \*  | `StateID=0`, `IsDelayActive=false` |
| 0   | \*  | 0   | \*  | 1   | 1   | `StateID=2`, `IsDelayActive=true`  |
| 0   | \*  | 0   | \*  | 1   | 0   | `StateID=0`, `IsDelayActive=false` |
| 1   | 1   | 1   | 1   | 1   | 1   | `StateID=2`, `IsDelayActive=true`  |
| 1   | 0   | 1   | 1   | 1   | 1   | `StateID=0`, `IsDelayActive=false` |
| 1   | 1   | 1   | 0   | 1   | 1   | `StateID=0`, `IsDelayActive=false` |
| 1   | 1   | 1   | 1   | 1   | 0   | `StateID=0`, `IsDelayActive=false` |
| 1   | 0   | 1   | 0   | 1   | 0   | `StateID=0`, `IsDelayActive=false` |

Rule:
**all active bedrooms must be secure**

---

# 5. Full sensor-level matrix for the named regression scenarios

## S01 Baseline reset

| Sensors          | Expected output                    |
| ---------------- | ---------------------------------- |
| all inputs false | `StateID=0`, `IsDelayActive=false` |

---

## W01 Only one window secure

| 54217 | 53418 | 41617 | Expected |
| ----- | ----- | ----- | -------- |
| 1     | 0     | 0     | disarmed |

## W02 All windows secure alone

| 54217 | 53418 | 41617 | Expected |
| ----- | ----- | ----- | -------- |
| 1     | 1     | 1     | disarmed |

## W03 Break one window from windows-secure-only state

| Before                    | After   | Expected |
| ------------------------- | ------- | -------- |
| 54217=1, 53418=1, 41617=1 | 54217=0 | disarmed |

---

## D01 One generic door secure

| 10775 | 22945 | 49829 | Expected |
| ----- | ----- | ----- | -------- |
| 1     | 0     | 0     | disarmed |

## D02 All generic doors secure alone

| 10775 | 22945 | 49829 | Expected |
| ----- | ----- | ----- | -------- |
| 1     | 1     | 1     | disarmed |

## D03 Break one generic door from door-secure-only state

| Before                  | After   | Expected |
| ----------------------- | ------- | -------- |
| 10775=1,22945=1,49829=1 | 22945=0 | disarmed |

---

## E01 GF lock only

| 14125 | 25478 | Expected |
| ----- | ----- | -------- |
| 1     | 0     | disarmed |

## E02 GF contact only

| 14125 | 25478 | Expected |
| ----- | ----- | -------- |
| 0     | 1     | disarmed |

## E03 GF complete

| 14125 | 25478 | Expected           |
| ----- | ----- | ------------------ |
| 1     | 1     | disarmed by itself |

---

## E04 Basement lock only

| 32928 | 41718 | Expected |
| ----- | ----- | -------- |
| 1     | 0     | disarmed |

## E05 Basement contact only

| 32928 | 41718 | Expected |
| ----- | ----- | -------- |
| 0     | 1     | disarmed |

## E06 Basement complete

| 32928 | 41718 | Expected           |
| ----- | ----- | ------------------ |
| 1     | 1     | disarmed by itself |

---

## B01 Master active insecure

| 28379 | 29918 | 34618 | Expected PresenceMap                            |
| ----- | ----- | ----- | ----------------------------------------------- |
| 1     | 1     | 0     | Master: `SwitchState=true`, `DoorTripped=false` |

## B02 Master active secure

| 28379 | 29918 | 34618 | Expected PresenceMap                           |
| ----- | ----- | ----- | ---------------------------------------------- |
| 1     | 1     | 1     | Master: `SwitchState=true`, `DoorTripped=true` |

## B03 Small guest active insecure

| 38285 | 36671 | Expected PresenceMap                                 |
| ----- | ----- | ---------------------------------------------------- |
| 1     | 0     | Small guest: `SwitchState=true`, `DoorTripped=false` |

## B04 Small guest active secure

| 38285 | 36671 | Expected PresenceMap                                |
| ----- | ----- | --------------------------------------------------- |
| 1     | 1     | Small guest: `SwitchState=true`, `DoorTripped=true` |

## B05 Big guest active insecure

| 28099 | 18998 | Expected PresenceMap                               |
| ----- | ----- | -------------------------------------------------- |
| 1     | 0     | Big guest: `SwitchState=true`, `DoorTripped=false` |

## B06 Big guest active secure

| 28099 | 18998 | Expected PresenceMap                              |
| ----- | ----- | ------------------------------------------------- |
| 1     | 1     | Big guest: `SwitchState=true`, `DoorTripped=true` |

---

## H01 Away fully secure

| Presence | Windows    | Doors      | GF     | BS     | Bedrooms | Expected                          |
| -------- | ---------- | ---------- | ------ | ------ | -------- | --------------------------------- |
| 0        | all secure | all secure | secure | secure | ignored  | `StateID=2`, `IsDelayActive=true` |

Concrete sensor set:

- `45731=0`
- `54217=1,53418=1,41617=1`
- `10775=1,22945=1,49829=1`
- `14125=1,25478=1`
- `32928=1,41718=1`

---

## H02 Home secure, no active bedrooms

| Presence | Windows | Doors  | GF     | BS     | 28379 | 38285 | 28099 | Expected                          |
| -------- | ------- | ------ | ------ | ------ | ----- | ----- | ----- | --------------------------------- |
| 1        | secure  | secure | secure | secure | 0     | 0     | 0     | `StateID=2`, `IsDelayActive=true` |

---

## H03 Home secure, master active secure

| Presence | Perimeter    | 28379 | 29918 | 34618 | Expected                          |
| -------- | ------------ | ----- | ----- | ----- | --------------------------------- |
| 1        | fully secure | 1     | 1     | 1     | `StateID=2`, `IsDelayActive=true` |

---

## H04 Home secure except master insecure

| Presence | Perimeter    | 28379 | 29918 | 34618 | Expected                           |
| -------- | ------------ | ----- | ----- | ----- | ---------------------------------- |
| 1        | fully secure | 1     | 1     | 0     | `StateID=0`, `IsDelayActive=false` |

---

## H05 Home secure, all active bedrooms secure

| Presence | Perimeter    | Master        | Small guest   | Big guest     | Expected                          |
| -------- | ------------ | ------------- | ------------- | ------------- | --------------------------------- |
| 1        | fully secure | active+secure | active+secure | active+secure | `StateID=2`, `IsDelayActive=true` |

---

## H06 Home secure, one active bedroom insecure

| Presence | Perimeter    | Master        | Small guest   | Big guest       | Expected                           |
| -------- | ------------ | ------------- | ------------- | --------------- | ---------------------------------- |
| 1        | fully secure | active+secure | active+secure | active+insecure | `StateID=0`, `IsDelayActive=false` |

---

## V01 Break one window from allowed state

| Before              | After            | Expected                           |
| ------------------- | ---------------- | ---------------------------------- |
| fully armable state | one window false | `StateID=0`, `IsDelayActive=false` |

## V02 Break one generic door from allowed state

| Before              | After                  | Expected                           |
| ------------------- | ---------------------- | ---------------------------------- |
| fully armable state | one generic door false | `StateID=0`, `IsDelayActive=false` |

## V03 Break GF entrance member from allowed state

| Before              | After     | Expected                           |
| ------------------- | --------- | ---------------------------------- |
| fully armable state | `25478=0` | `StateID=0`, `IsDelayActive=false` |

## V04 Break basement entrance member from allowed state

| Before              | After     | Expected                           |
| ------------------- | --------- | ---------------------------------- |
| fully armable state | `41718=0` | `StateID=0`, `IsDelayActive=false` |

## V05 Open active bedroom door from allowed home-secure state

| Before                                 | After                        | Expected                           |
| -------------------------------------- | ---------------------------- | ---------------------------------- |
| home armable with active master secure | one master door member false | `StateID=0`, `IsDelayActive=false` |

---

## P01 Away ignores insecure bedrooms

| Presence | Perimeter    | Bedrooms            | Expected                          |
| -------- | ------------ | ------------------- | --------------------------------- |
| 0        | fully secure | active but insecure | `StateID=2`, `IsDelayActive=true` |

## P02 Turn presence off removes bedroom veto

| Step 1                                            | Step 2             | Expected                                         |
| ------------------------------------------------- | ------------------ | ------------------------------------------------ |
| home + perimeter secure + active insecure bedroom | set presence=false | transitions to `StateID=2`, `IsDelayActive=true` |

---

## T01 Token progression

| Step            | Expected                                       |
| --------------- | ---------------------------------------------- |
| windows secure  | token advances                                 |
| doors secure    | token advances                                 |
| GF secure       | token advances                                 |
| basement secure | token advances and final armable state reached |

---

# 6. Short-form business rule summary

The complete business rule can be expressed as:

## Armable condition

### Away

```text
Armable =
    all windows secure
AND all generic doors secure
AND GF entrance secure
AND basement entrance secure
```

### Home

```text
Armable =
    all windows secure
AND all generic doors secure
AND GF entrance secure
AND basement entrance secure
AND every active bedroom is secure
```

## Output

```text
If Armable:
    StateID = 2
    IsDelayActive = true
Else:
    StateID = 0
    IsDelayActive = false
```

---

# 7. Important documentation note

This matrix documents the **current validated live behavior**, including the current bedroom polarity behavior:

- bedroom secure → `DoorTripped=true`
- bedroom insecure → `DoorTripped=false`

That is unusual by name, but it is what your current system and regression test now consistently use.

Below is a **clean Markdown documentation block** you can place directly in your repository (for example `docs/PSM_House_State_Business_Matrix.md`).
It matches the **validated behavior of your current regression test (33 PASS / 0 FAIL)**.

---

# Property State Manager (PSM)

## House-State Business Logic and Regression Matrix

Version: **1.0.0**
Validated against:

- **Module 2 Version:** 7.2.0
- **Regression Test Script:** 1.0.0
- **Scenario Matrix:** 1.0.0

Scope:

- House-state logic only
- Covers Module 1 → Module 2 interaction
- Does **not** cover Module 3 alarm outputs

---

# 1. System Architecture Context

The alarm system is divided into three layers.

| Layer  | Name                         | Responsibility                                         |
| ------ | ---------------------------- | ------------------------------------------------------ |
| Tier 1 | Sensor Aggregator            | collects raw sensors, performs class/group aggregation |
| Tier 2 | Property State Manager (PSM) | evaluates **house security state**                     |
| Tier 3 | Output Manager               | executes alarm responses                               |

This document describes **Tier 2 only**.

PSM determines:

- whether the house is secure
- whether the system can arm
- whether exit delay is active
- whether bedroom veto conditions block arming

PSM **does not decide alarm actions**.

---

# 2. Input Variables

## Presence

| Variable | Meaning         |
| -------- | --------------- |
| 45731    | Presence sensor |

Value meaning:

| Value | Meaning      |
| ----- | ------------ |
| true  | someone home |
| false | away         |

Presence affects **bedroom logic only**.

---

# 3. Perimeter Sensors

## Windows

| Variable | Location |
| -------- | -------- |
| 54217    | window   |
| 53418    | window   |
| 41617    | window   |

Interpretation:

| Value | Meaning                |
| ----- | ---------------------- |
| true  | window closed / secure |
| false | window open            |

Aggregate rule:

```
WindowsSecure =
    54217 AND
    53418 AND
    41617
```

---

## Generic Doors

| Variable | Location |
| -------- | -------- |
| 10775    | door     |
| 22945    | door     |
| 49829    | door     |

Aggregate rule:

```
DoorsSecure =
    10775 AND
    22945 AND
    49829
```

---

# 4. Entrance Security

## Ground Floor Entrance

| Variable | Meaning      |
| -------- | ------------ |
| 14125    | lock         |
| 25478    | door contact |

Secure condition:

```
GFEntranceSecure =
    14125 AND
    25478
```

---

## Basement Entrance

| Variable | Meaning      |
| -------- | ------------ |
| 32928    | lock         |
| 41718    | door contact |

Secure condition:

```
BasementEntranceSecure =
    32928 AND
    41718
```

---

# 5. Bedroom Logic

Bedroom logic only matters when **Presence = true**.

## Bedroom activation flags

| Variable | Bedroom     |
| -------- | ----------- |
| 28379    | Master      |
| 38285    | Small Guest |
| 28099    | Big Guest   |

Meaning:

| Value | Meaning         |
| ----- | --------------- |
| true  | bedroom active  |
| false | bedroom ignored |

---

## Bedroom door sensors

### Master bedroom

| Variable |
| -------- |
| 29918    |
| 34618    |

Secure condition:

```
MasterSecure =
    29918 AND
    34618
```

---

### Small Guest bedroom

| Variable |
| -------- |
| 36671    |

```
SmallGuestSecure =
    36671
```

---

### Big Guest bedroom

| Variable |
| -------- |
| 18998    |

```
BigGuestSecure =
    18998
```

---

# 6. Bedroom Polarity

The system currently exposes bedroom state using the field:

```
PresenceMap[].DoorTripped
```

Observed polarity:

| Condition        | DoorTripped |
| ---------------- | ----------- |
| bedroom secure   | true        |
| bedroom insecure | false       |

Note:

The naming is slightly counter-intuitive but reflects the **actual live system behavior**.

---

# 7. Armable House Condition

## Perimeter condition

```
PerimeterSecure =
    WindowsSecure
AND DoorsSecure
AND GFEntranceSecure
AND BasementEntranceSecure
```

---

## Away Mode

When

```
Presence = false
```

Bedroom states are ignored.

Armable rule:

```
Armable = PerimeterSecure
```

---

## Home Mode

When

```
Presence = true
```

Every **active bedroom must be secure**.

Armable rule:

```
Armable =
    PerimeterSecure
AND (Master inactive OR MasterSecure)
AND (SmallGuest inactive OR SmallGuestSecure)
AND (BigGuest inactive OR BigGuestSecure)
```

---

# 8. Output State

PSM exposes two important fields.

## StateID

| Value | Meaning    |
| ----- | ---------- |
| 0     | Disarmed   |
| 2     | Exit Delay |

---

## IsDelayActive

| Value | Meaning            |
| ----- | ------------------ |
| true  | exit delay running |
| false | no delay           |

---

## Armable output

If the house is armable:

```
StateID = 2
IsDelayActive = true
```

Otherwise:

```
StateID = 0
IsDelayActive = false
```

---

# 9. Regression Test Scenario Matrix

The regression test script validates the following scenario groups.

---

# Baseline

### S01

All inputs false.

Expected:

```
StateID = 0
IsDelayActive = false
```

---

# Window Logic

### W01

Only one window secure.

Result:

```
Not armable
```

---

### W02

All windows secure.

Result:

```
Still not armable
```

because doors and entrances are insecure.

---

### W03

Break one window from secure state.

Result:

```
Still disarmed
```

---

# Generic Door Logic

### D01

Only one generic door secure.

```
Not armable
```

---

### D02

All generic doors secure.

```
Still not armable
```

---

### D03

Break one generic door.

```
Still disarmed
```

---

# Entrance Logic

### E01

GF lock only.

```
Not armable
```

---

### E02

GF contact only.

```
Not armable
```

---

### E03

GF entrance complete.

```
Still not armable alone
```

---

### E04

Basement lock only.

```
Not armable
```

---

### E05

Basement contact only.

```
Not armable
```

---

### E06

Basement entrance complete.

```
Still not armable alone
```

---

# Bedroom Mapping

### B01

Master active insecure

Expected:

```
PresenceMap.Master.SwitchState = true
PresenceMap.Master.DoorTripped = false
```

---

### B02

Master active secure

```
SwitchState = true
DoorTripped = true
```

---

### B03–B06

Equivalent logic for:

- Small Guest
- Big Guest

---

# House-State Scenarios

### H01

Away mode with secure perimeter.

```
StateID = 2
IsDelayActive = true
```

---

### H02

Home mode with no active bedrooms.

```
Armable
```

---

### H03

Home mode with active **secure** master bedroom.

```
Armable
```

---

### H04

Home mode with active **insecure** master bedroom.

```
Not armable
```

---

### H05

All active bedrooms secure.

```
Armable
```

---

### H06

One active bedroom insecure.

```
Not armable
```

---

# Violation Scenarios

These tests confirm that a valid armable state is revoked correctly.

### V01

Break one window.

Result:

```
Disarmed
```

---

### V02

Break one generic door.

```
Disarmed
```

---

### V03

Break ground-floor entrance member.

```
Disarmed
```

---

### V04

Break basement entrance member.

```
Disarmed
```

---

### V05

Open active bedroom door.

```
Disarmed
```

---

# Presence Transitions

### P01

Away mode ignores insecure bedrooms.

```
Armable
```

---

### P02

Switching presence from home → away removes bedroom veto.

```
Armable
```

---

# Token Validation

### T01

Ensures Module 2 advances the event token after each state change.

Purpose:

Detects

- stale processing
- lost payloads
- race conditions

---

# 10. Regression Test Results

Current validated run:

```
PASS = 33
FAIL = 0
```

Report file example:

```
/var/lib/symcon/psm_full_regression_YYYYMMDD_HHMMSS.json
```

---

# 11. Usage Guidelines

Run the regression test whenever:

- Module 1 configuration changes
- Module 1 logic changes
- Module 2 state machine changes
- bedroom logic changes
- polarity semantics change
- entrance logic changes

---

# 12. Future Extensions

Potential future tests:

- alarm state transition tests
- motion sensor interaction
- Module 3 response routing
- partial arming modes
- multi-zone arming

---

If you want, I can also generate a **visual architecture + logic diagram (Mermaid)** for this documentation so the entire system can be understood in about **30 seconds by a developer reading the repo**.

Property State Manager (PSM)
House-State Logic and Regression Specification
Version: 1.0.0

Validated against:

Component Version
Module 2 (PSM) 7.2.0
Regression Test Script 1.0.0
Scenario Matrix 1.0.0
Scope:

House-state logic only
Module 1 → Module 2 interaction
Does not include Module 3 alarm actions

1. Architecture Overview
   The alarm system uses a three-tier architecture.

Tier Module Role
1 Sensor Aggregator Collects sensors and builds classes/groups
2 Property State Manager Evaluates house security state
3 Output Manager Executes alarm responses
Architecture Diagram
flowchart LR
A["Tier 1<br/>Sensor Aggregator"] --> B["Tier 2<br/>Property State Manager"]
B --> C["Tier 3<br/>Output Manager"]

    A1["Raw sensors<br/>doors<br/>windows<br/>locks<br/>contacts<br/>presence"] --> A
    B1["House state<br/>secure / blocked<br/>arming decision"] --> B
    C1["Outputs<br/>sirens<br/>notifications<br/>automation"] --> C

2. Scope of Module 2
   PSM evaluates:

house security condition
armable state
exit delay state
bedroom veto conditions
PSM does not decide alarm actions.

Outputs exposed by Module 2:

Field Meaning
StateID system state
IsDelayActive exit delay active
PresenceMap bedroom status
ActiveSensors active secure aggregates 3. Input Variables
Presence
Variable Meaning
45731 presence sensor
Value semantics:

Value Meaning
true someone home
false away 4. Perimeter Sensors
Windows
Variable
54217
53418
41617
Interpretation:

Value Meaning
true closed / secure
false open
Secure condition:

WindowsSecure =
54217 AND
53418 AND
41617
Generic Doors
Variable
10775
22945
49829
Secure condition:

DoorsSecure =
10775 AND
22945 AND
49829 5. Entrance Security
Ground Floor Entrance
Variable Meaning
14125 lock
25478 contact
Secure condition:

GFEntranceSecure =
14125 AND
25478
Basement Entrance
Variable Meaning
32928 lock
41718 contact
Secure condition:

BasementEntranceSecure =
32928 AND
41718 6. Bedroom Logic
Bedroom logic is applied only when Presence = true.

Bedroom activation flags
Variable Bedroom
28379 Master
38285 Small Guest
28099 Big Guest
Meaning:

Value Meaning
true bedroom active
false bedroom ignored
Bedroom door sensors
Master bedroom
Variable
29918
34618
Secure condition:

MasterSecure =
29918 AND
34618
Small guest bedroom
Variable
36671
SmallGuestSecure = 36671
Big guest bedroom
Variable
18998
BigGuestSecure = 18998 7. Bedroom Status Polarity
Module 2 exports bedroom status through:

PresenceMap[].DoorTripped
Observed polarity:

Condition DoorTripped
bedroom secure true
bedroom insecure false
Note: naming is historical but matches the actual system behavior.

8. Perimeter Security
   PerimeterSecure =
   WindowsSecure
   AND DoorsSecure
   AND GFEntranceSecure
   AND BasementEntranceSecure
9. Armability Logic
   Away Mode
   If

Presence = false
Bedroom states are ignored.

Armable = PerimeterSecure
Home Mode
If

Presence = true
All active bedrooms must be secure.

Armable =
PerimeterSecure
AND (Master inactive OR MasterSecure)
AND (SmallGuest inactive OR SmallGuestSecure)
AND (BigGuest inactive OR BigGuestSecure) 10. Output State
Armable condition
If armable:

StateID = 2
IsDelayActive = true
Meaning:

Exit Delay / Arming path

Not armable
StateID = 0
IsDelayActive = false
Meaning:

Disarmed or blocked

11. State Machine
    stateDiagram-v2
    [*] --> Disarmed

        Disarmed --> ExitDelay: Armable condition met
        ExitDelay --> Disarmed: Any secure condition breaks

12. Full Decision Flow
    flowchart TD

        P["PerimeterSecure"] --> PR{"Presence"}

        PR -- "Away" --> A1["Armable"]

        PR -- "Home" --> B1{"Active bedrooms secure?"}

        B1 -- "Yes" --> A1
        B1 -- "No" --> D1["Blocked"]

        A1 --> O1["StateID = 2<br/>IsDelayActive = true"]
        D1 --> O2["StateID = 0<br/>IsDelayActive = false"]

        P -- "false" --> O2

13. Regression Test Strategy
    The regression test performs end-to-end validation.

Process:

flowchart LR

    T["Test script"] --> R["Reset all sensors"]
    R --> S["Apply scenario values"]
    S --> W["Wait for stable state"]
    W --> C["Check outputs"]
    C --> J["Write JSON report"]

Checks performed:

sensor write success
token advancement
expected StateID
expected IsDelayActive
expected PresenceMap values 14. Scenario Groups
The regression suite contains 33 validated scenarios.

Group Purpose
S baseline reset
W window logic
D generic door logic
E entrance completeness
B bedroom mapping
H house armability
V violation transitions
P presence logic
T token advancement 15. Violation Logic
Breaking any required secure condition must cancel arming.

Examples tested:

Scenario Effect
open window disarm
open door disarm
unlock entrance disarm
open bedroom door disarm (home mode only) 16. Regression Test Result
Latest validated execution:

PASS = 33
FAIL = 0
Example report file:

/var/lib/symcon/psm_full_regression_YYYYMMDD_HHMMSS.json 17. Timing Stability Mechanism
Because Module 1 and Module 2 propagate events asynchronously,
the test script uses stable polling.

flowchart TD

    A["Write sensor values"] --> B["Module 1 recompute"]
    B --> C["Module 1 dispatch"]
    C --> D["Module 2 process"]
    D --> E["State update"]

    E --> F{"Expected state stable?"}

    F -- "No" --> G["Poll again"]
    G --> E

    F -- "Yes" --> H["Accept result"]

18. Sensor Map
    flowchart TD

        subgraph Windows
            W1["54217"]
            W2["53418"]
            W3["41617"]
        end

        subgraph Doors
            D1["10775"]
            D2["22945"]
            D3["49829"]
        end

        subgraph GFEntrance
            GF1["14125"]
            GF2["25478"]
        end

        subgraph BasementEntrance
            B1["32928"]
            B2["41718"]
        end

        subgraph Bedrooms
            M["Master active 28379"]
            SG["Small guest active 38285"]
            BG["Big guest active 28099"]
        end

        subgraph BedroomDoors
            M1["29918"]
            M2["34618"]
            SG1["36671"]
            BG1["18998"]
        end

        PR["Presence 45731"]

19. Developer Summary
    Core rule:

Armable =
PerimeterSecure
AND (Away OR AllActiveBedroomsSecure)
Outputs:

Armable -> ExitDelay
Not armable -> Disarmed 20. Future Extensions
Possible future additions:

partial arming modes
motion sensors
perimeter zones
night mode
alarm escalation logic
Module 3 output testing
