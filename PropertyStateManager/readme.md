Here is the text formatted correctly in Markdown for a GitHub `README.md` file.

# Module Documentation: Property State Manager (PSM)

**Version:** 7.2.0  
**Prefix:** PSM  
**Type:** Logic / State Machine (Tier 2)

---

## 1. Overview & Purpose

The Property State Manager (Module 2) acts as the central "Brain" of the alarm system. It sits between the Sensor Aggregator (Module 1) and the Output Manager (Module 3).

### Key Responsibilities

- **Input Normalization:** Receives raw sensor events from Module 1 and maps them to logical roles (e.g., "Sensor 12345 is the Front Door Lock").
- **State Machine:** Maintains the security state of the property (Disarmed, Exit Delay, Armed Internal, Armed External, Alarm).
- **Safety Logic:** Enforces safety constraints (e.g., "Cannot complete arming if a relevant bedroom door is open").
- **Runtime Buffers (Attributes):** Maintains live buffers (ActiveSensors / ActiveGroups / PresenceMap). These Attributes persist across restarts; Module 2 requests state sync from Module 1 on ApplyChanges and via the dashboard Sync button.
- **Visualization:** Provides a live, secured HTML dashboard for diagnostics and status monitoring.

---

## 2. Core Functionality & Logic

### A. The State Machine

The module implements an Explicit State Machine (ESM) rather than a simple combinatorial matrix. Transitions are governed by strict conditions.

| State ID | State Name       | Description                                                                                         |
| -------: | :--------------- | :-------------------------------------------------------------------------------------------------- |
|        0 | Disarmed         | The system is idle. Monitoring sensors but taking no action.                                        |
|        2 | Exit Delay       | The system is counting down. Arming will occur when the timer expires (if conditions remain valid). |
|        3 | Armed External   | "Away" Mode. Perimeter is secure.                                                                   |
|        6 | Armed Internal   | "Night" Mode. Perimeter is secure. Bedroom door rules apply.                                        |
|        9 | Alarm Triggered! | Latched alarm state triggered by group-level opening (windows / generic doors) while armed.         |

---

### B. Arming Paths

The system decides which Arming Mode to enter based on the **Presence** intent at the moment the timer expires.

#### Path A: External Arming (Leaving)

- **Condition to enter Exit Delay:** Perimeter Secure + Presence = FALSE (ready to leave).
- **Process:** Exit Delay timer starts immediately once arming conditions are met.
- **Result on timer expiry:** Transitions to **State 3 (Armed External)**.

#### Path B: Internal Arming (Sleeping)

- **Condition to enter Exit Delay:** Perimeter Secure + Presence = TRUE (ready to sleep).
- **Constraint:** During Exit Delay, if Presence is TRUE and a relevant Bedroom Door is open, the delay is **aborted** and the system returns to Disarmed.
- **Process:** Exit Delay timer starts immediately once arming conditions are met; it can be aborted if bedroom door is open while Presence is TRUE.
- **Result on timer expiry:** Transitions to **State 6 (Armed Internal)**.

---

### C. Disarm / Abort / Alarm Triggers

#### During Exit Delay (State 2)

The Exit Delay is **aborted** and the system reverts to **State 0 (Disarmed)** if:

- Any perimeter condition becomes unsecure, OR
- Presence is TRUE and a relevant Bedroom Door is open.

#### While Armed (State 3 / 6)

- **Unlocking any entrance lock** disarms immediately → **State 0 (Disarmed)**.
- **Opening any group-level perimeter (windows / generic doors)** triggers alarm → **State 9 (Alarm Triggered!)**.
- **Internal mode only:** Opening a relevant Bedroom Door disarms → **State 0 (Disarmed)**.

#### Alarm Triggered (State 9)

- **Authorized reset:** Unlocking any entrance lock always disarms → **State 0**.
- **Internal context reset:** If Presence is TRUE and a relevant Bedroom Door is open, system disarms → **State 0**.
- Otherwise remains latched in alarm state.

---

## 3. User Interfaces

### A. IP-Symcon Configuration Form (Backend)

This is where the integrator links Module 2 to the rest of the system.

- **Sensor Group Instance (Module 1):** Select the ID of the aggregation module.
- **Step 1: Select Dispatch Target:** Select the routing target defined in Module 1 to filter the sensor list.
- **Arming Delay (Minutes):** Integer. Defines how long the Exit Delay (State 2) lasts.
- **Security Vault (SecretsManager):** Optional. Links to a Vault instance to enforce biometric/password protection on the WebHook.
- **Group Mapping List:** The core configuration table.

#### Group Mapping Columns (Conceptual)

- **Source Key:** A dropdown list of Sensors (numeric Variable IDs as strings) and Groups (string Group Names) discovered from Module 1.
- **Logical Role:** Assigns a function to the source.
- **Polarity (optional):** Controls interpretation for perimeter roles.
  - `"breach"` = active means open/breach (default for Window Contact / Generic Door if Polarity missing)
  - `"secure"` = active means closed/secure (inverts raw meaning)

#### Roles

- **Front Door Lock / Basement Door Lock:** Secure role (active means locked).
- **Front Door Contact / Basement Door Contact:** Secure role (active means closed).
- **Perimeter: Window Contact:** Perimeter role (open/breach if active unless Polarity="secure").
- **Perimeter: Generic Door:** Perimeter role (open/breach if active unless Polarity="secure").
- **Presence:** Maps to presence intent logic.

---

### B. Logic Analysis Dashboard (HTML WebHook)

A real-time diagnostic page accessible via browser:

- **URL:** `http://[IP]:3777/hook/psm_logic_<InstanceID>`
- **Technology:** PHP-generated HTML with client-side JavaScript (AJAX polling).

#### Features

- **Auto-Refresh:** Polls the API every **2 seconds**.
- **Sensor Status:** Visualizes key internal state fields; bits are displayed for quick overview.
- **System State:** Large text display of the current State Name.
- **Countdown Timer:** If State is "Exit Delay", a pink countdown bar appears.
- **Unmapped Warnings:** Displays a yellow alert box if Module 2 receives signals from sensors that are not mapped in the configuration.
- **Sync Button:** A manual button to force `MYALARM_RequestStateSync` to realign Module 2 with Module 1.
- **Bedrooms Detail List (UI):** Shows bedroom status lines including whether a bedroom is blocking (used + door open) or bypassed.
- **Perimeter Blocking List (UI):** Shows which **group** and which **member sensors** (caption format: grandparent > parent > name) are currently responsible for perimeter breach/open.

---

## 4. Input / Output Data Structures

### A. Configuration Backup (JSON)

The internal configuration is stored in the `GroupMapping` property.

**Format:**

```json
[
  {
    "SourceKey": "14125",
    "LogicalRole": "Front Door Lock"
  },
  {
    "SourceKey": "Windows",
    "LogicalRole": "Window Contact",
    "Polarity": "breach"
  }
]
```

Module 2 accepts two distinct JSON payloads via ReceivePayload.

#### Type 1: Alarm / Sensor Event

Triggered when a sensor changes state.

```json
{
  "event_type": "ALARM",
  "source_name": "Sensor Aggregator",
  "active_groups": ["Burglar Alarm"],
  "trigger_details": {
    "variable_id": 14125,
    "value_raw": true,
    "var_name": "Front Door Lock"
  }
}
```

**Global Reset behavior:**
If active_groups is present and is an empty array (`[]`), Module 2 interprets this as a global reset and clears its ActiveSensors list.

#### Type 2: Bedroom Sync

Triggered periodically or on change to update presence logic.

```json
{
  "event_type": "BEDROOM_SYNC",
  "bedrooms": [
    {
      "GroupName": "Master Bedroom",
      "SwitchState": true,
      "DoorTripped": false
    }
  ]
}
```

- `SwitchState` = bedroom “in use” (presence intent)
- `DoorTripped` = bedroom door open/tripped state

### C. Output Payloads

#### 1) Output Payload (To Module 3 / API on request)

Returned by GetSystemState() for Module 3 consumption.

```json
{
  "StateID": 3,
  "PresenceMap": [...],
  "ActiveSensors": [14125, 55667],
  "IsDelayActive": false
}
```

#### 2) Dashboard API Payload (WebHook ?api=1)

Returned by the dashboard API for UI/diagnostics. Includes additional UI fields (bitmask, labels, bedrooms, perimeterDetails, etc.).

---

## 5. Public API Functions

These functions are available to other scripts or modules in IP-Symcon.

#### `PSM_GetSystemState(int $InstanceID): string`

- **Description:** Returns the complete snapshot of the house status for Module 3 consumption.
- **Returns:** JSON String (see Output Payload #1).
- **Usage:** Called by Module 3 to decide on alerting actions.

#### `PSM_ReceivePayload(int $InstanceID, string $Payload): void`

- **Description:** The main input door. Processes JSON events, updates internal buffers, and triggers state machine evaluation.
- **Usage:** Typically invoked via IPS_RequestAction($InstanceID, 'ReceivePayload', $Payload) from Module 1.

#### `PSM_RequestAction(int $InstanceID, string $Ident, mixed $Value): void`

- **Description:** Standard IP-Symcon Action Handler.
- **Usage:** Allows Module 1 to call IPS_RequestAction($id, 'ReceivePayload', $json) safely.

#### `PSM_GetPayloadHistory(int $InstanceID): string`

- **Description:** Returns the last 100 received payloads.
- **Returns:** JSON String (Array of objects with Timestamp and Data).
- **Usage:** Debugging and diagnostics.

#### `PSM_ResetPayloadHistory(int $InstanceID): void`

- **Description:** Clears debug logs, clears active sensor/group/presence buffers, and resets System State to 0.
- **Usage:** Hard reset during testing.

#### `PSM_UI_Refresh(int $InstanceID): void`

- **Description:** Builds UI caches (caption map + group membership map) and updates SyncTimestamp to enable Apply Changes.
- **Usage:** Internal UI helper; improves dashboard perimeter sensor captions.

## Interface Contract: Module 2 → Module 3 “Mapping Hints” Export

## Purpose

`PSM_GetMappingHints()` provides a **read-only semantic export** that allows Module 3 (Output Manager) to stay consistent with Module 2’s role mapping **without re-implementing Module 2 interpretation logic**.

Module 3 uses this export to:

- build its configuration UI (what sources exist, what they mean)
- apply stable rule categories (entry locks vs perimeter windows vs doors vs presence/bedrooms)

## Strict Separation of Concerns

- **Module 2 remains the only component that interprets sensor meaning in context** (state machine, arming logic, presence gating, alarm validity).
- **Module 3 must not derive live breach/secure state from mapping hints.**
- Live decision context for Module 3 must come from Module 2 state outputs (e.g., `PSM_GetSystemState()` / house-mode), not from `PSM_GetMappingHints()`.

`PSM_GetMappingHints()` is semantic metadata + discovery information, not a live-state API.

---

## Output Contract

The export returns a JSON object including:

- schema and version metadata
- `buckets`: compact semantic categorization lists (SourceKeys only)
- `mappings[]`: lossless mapping rows with metadata and captions
- bedroom catalog + bedroom runtime snapshot (clearly separated)
- `warnings[]` for partial/incomplete data

### Required Meta Fields

- `schema_version` (int): contract version
- `module2_version` (string): Module 2 version
- `generated_at` (unix timestamp): when the export was generated

---

## SourceKey Rules

A `source_key` is a string identifying a mapped source and can refer to:

- a **sensor** (VariableID string, e.g., `"14125"`)
- a **group** (Module 1 GroupName, e.g., `"Windows"`)

Each mapping row must include `source_kind` = `"sensor"` or `"group"`.

---

## Polarity Semantics

Polarity is explicit metadata describing what **Module 2 considers “active”** to mean for that mapped source.

> **Definition of “active” in this contract:**
> “Active” refers to Module 2’s notion of activity for that `source_key` (as produced by Module 1 payloads and Module 2 runtime buffers such as `ActiveSensors` / `ActiveGroups`). It is **not** guaranteed to reflect raw device electrical polarity.
> **Polarity describes how Module 2 interprets the source’s truth value in its mapping, not the raw electrical polarity and not necessarily `trigger_details.value_raw` from Module 1.**

### Allowed Values

- `polarity: "secure"`
  **Active means secure/closed/locked/OK.**
- `polarity: "breach"`
  **Active means breach/open/tripped.**
- `polarity: null` / missing
  Unknown / not applicable / legacy.

### Contract Rule

`polarity` must reflect the configured polarity **as-is** from Module 2 mapping.

### Design Rule for Module 3

Module 3 may use `polarity` for UI display and default rule templates, but **must not** use it to infer live “breach” state from raw values. Live context must come from Module 2 state outputs.

---

## Buckets

`buckets` provides a normalized semantic view for Module 3. Buckets contain **SourceKeys only** (strings). Details and captions are resolved via `mappings[]`.

> **Rule:** Module 3 must treat `mappings[]` as the authoritative detail source. Buckets are only references to `source_key` values.

### Minimum Required Buckets (always present)

- `entry_locks`: sources that act as disarm/reset triggers (e.g., door locks)
- `perimeter_windows`: sources representing window perimeter
- `perimeter_doors`: sources representing door perimeter (generic doors)
- `interior_motion`: motion-related sources (may be empty)
- `presence_sources`: presence intent sources only if needed by downstream consumers
- `authorized_reset_sources`: reserved for future non-lock resets (may be empty)

Bedroom-specific semantic buckets are not required for the Module 2 → Module 3 contract.

---

## Mappings

`mappings[]` is the authoritative list of mapping rows. Each row must include at least:

- `source_key` (string)
- `source_kind` (`"sensor"` | `"group"`)
- `logical_role` (string) – the Module 2 role name
- `polarity` (string or null)
- `caption` (string) – best-available human readable label
- `origin` (string) – e.g., `module1_variable`, `module1_group`

If `source_kind="group"`, include:

- `members[]`: list of objects with `variable_id` and `caption` (best effort)

> **Best-effort note (group members):**
> `members[]` is populated from Module 2 discovery caches (caption map + group membership map, typically built/refreshed via `PSM_UI_Refresh()`), and may be empty until refreshed.

---

## Bedroom Data

Bedroom-related logic is an internal concern of **Module 2** and is **not part of the required Module 2 → Module 3 contract**.

Module 2 may maintain bedroom-specific runtime data internally (for example from `BEDROOM_SYNC`) in order to implement internal arming rules, bedroom-door gating, and related state-machine behavior. However, Module 3 must not depend on bedroom-specific metadata, bedroom catalogs, or bedroom runtime snapshots.

### Contract rule for Module 3

Module 3 should consume only the generic semantic export and Module 2 state outputs, for example:

- generic mapped sources such as entry locks, perimeter windows, perimeter doors, and other non-bedroom-specific roles
- interpreted state outputs such as `PSM_GetSystemState()` / house-mode snapshots

### Design intent

- **Module 2** remains responsible for interpreting bedroom presence and bedroom door logic.
- **Module 3** should treat bedroom doors like ordinary generic/perimeter door information if such signals are exposed through the generic mapping/state outputs, without any special bedroom semantics.
- Bedroom-specific structures may still exist internally or for diagnostics/UI, but they are not required for Module 3 integration and are not a stable dependency contract.

---

## Warnings & Partial Data

The export must include `warnings: []` always.
If captions/members cannot be resolved (e.g., cache not built yet), Module 2 should:

- still return valid JSON with `mappings[]` and `buckets`
- include warnings describing what could not be resolved

No fatal errors for incomplete discovery.

---

## Backward Compatibility

- Schema evolution should be **additive**: add fields, do not remove or rename without a deprecation period.
- If introducing new names (e.g., `bedroom_catalog`, `bedroom_status`), keep legacy fields alongside them during transition.

---

## Summary

`PSM_GetMappingHints()` is a stable, minimal semantic export that:

- makes Module 3 configuration consistent with Module 2 roles,
- avoids logic duplication by keeping interpretation in Module 2,
- provides enough metadata for Module 3 UI and rule mapping using generic semantic categories,
- and does **not** require Module 3 to understand or consume bedroom-specific semantics, which remain internal to Module 2.
