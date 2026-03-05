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

| State ID | State Name         | Description |
|---------:|--------------------|-------------|
| 0        | Disarmed           | The system is idle. Monitoring sensors but taking no action. |
| 2        | Exit Delay         | The system is counting down. Arming will occur when the timer expires (if conditions remain valid). |
| 3        | Armed External     | "Away" Mode. Perimeter is secure. |
| 6        | Armed Internal     | "Night" Mode. Perimeter is secure. Bedroom door rules apply. |
| 9        | Alarm Triggered!   | Latched alarm state triggered by group-level opening (windows / generic doors) while armed. |

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
