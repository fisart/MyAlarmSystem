# SensorGroup Module Documentation
**Version:** 2.9.2 (Stable Production Release)

## 1. Overview
The **SensorGroup Module** (Module 1) acts as the **"Edge" / Aggregator** in a distributed alarm system architecture. Its primary purpose is to monitor raw physical sensors (Variables), aggregate them into logical zones (Classes) and sectors (Groups), and dispatch standardized JSON payloads to a logic controller (Module 2).

It is designed to be **stateless regarding alarm handling** (it detects *conditions*, it does not manage *state machines* like "Armed/Disarmed").

---

## 2. User Interface (Configuration)
The configuration form is divided into logical steps to build the hierarchy bottom-up.

### Step 1: Class Definitions (Zones & Logic)
Classes represent the type of sensor or a specific zone (e.g., "Smoke Detectors", "Ground Floor Windows").
*   **Class Name:** Human-readable name.
*   **Label Mode:** Determines how the sensor is described in payloads (`0`=Sensor Name, `1`=Parent Name, `2`=Status Text).
*   **Logic Mode:**
    *   `0` **OR (Instant):** Active if *any* sensor in the class is triggered.
    *   `1` **AND (All):** Active only if *all* sensors in the class are triggered simultaneously.
    *   `2` **COUNT:** Active if the number of triggers within the **Window** exceeds the **Threshold**.
*   **Threshold / Window:** Parameters for COUNT logic.

### Step 2: Sensor Inputs
This section assigns physical IP-Symcon Variables to the Classes defined in Step 1.
*   **Variable:** The Source Variable ID.
*   **Operator/Value:** The condition that defines a "Trigger". (e.g., `Value = 1` or `Value = true`).
    *   *Note: To invert a sensor (NC contact), set Operator to `=` and Value to `0`.*
*   **Expansion Panels:** Sensors are grouped visually by their assigned Class.

### Step 3: Group Management
Groups represent high-level alarm sectors (e.g., "Fire Alarm", "Burglar Alarm", "House State").
*   **3a. Create Groups:** Define the Group Name and its internal Logic (`OR` vs `AND`).
*   **3b. Bedroom Behavioral Settings:** Configures specific rules for "Presence Gating".
    *   *Active Variable (IPSView):* The switch that determines if the room is Occupied.
    *   *Door Class:* The Class containing the door/window sensors for this room.
*   **Step B (Matrix):** A checkbox matrix linking **Classes** to **Groups**.

### Step 4: Dispatch Targets (Routing)
*   **Module 2 Instances:** A list of target instances (InstanceIDs) that will receive data.
*   **Route Groups:** Maps specific **Groups** to specific **Target Instances**.
*   **Global Bedroom Target:** A dropdown to select the single Target Instance that receives `BEDROOM_SYNC` presence data.
*   **VaultInstanceID:** Selects the "Secrets" module instance used to authenticate Webhook access.

### Actions
*   **COMMIT ALL CHANGES TO DISK:** Saves the RAM Buffer to Disk Properties and registers event listeners (subscriptions) for all sensors. **Must be clicked to go "Live".**
*   **FORCE RE-REGISTER SENSORS (Repair):** Manually wipes and rebuilds all message subscriptions without changing configuration.
*   **Bulk Import Wizard:** Scans the IP-Symcon object tree to quickly add variables to a Class.

---

## 3. Visualization Dashboard (HTML/Mermaid)
The module provides a live, interactive architecture diagram accessible via Webhook.

*   **URL:** `http://<IP>:3777/hook/MyAlarmFlow_<InstanceID>`
*   **Security:** Protected by the module selected in `VaultInstanceID`. Redirects to a login portal if not authenticated.
*   **Technology:** Uses **Mermaid.js** for rendering and **svg-pan-zoom** for navigation.
*   **Visual Logic:**
    *   **Green:** Safe / Inactive.
    *   **Red:** Triggered / Active.
    *   **Grey:** Static logic path.
*   **Features:**
    *   **AJAX Updates:** The chart updates status in real-time without reloading the page.
    *   **Target Filter:** Checkboxes at the top allow filtering the graph to show only logic paths relevant to specific Targets.
    *   **Pan/Zoom:** Infinite canvas with mouse-wheel zoom and drag-to-pan.

---

## 4. Input / Output Process Flow

### Input Processing
1.  **Event Source:** An IP-Symcon Variable changes state.
2.  **MessageSink:** The module receives the update via `MessageSink`.
3.  **CheckLogic Engine:**
    *   Matches the Variable ID to the `SensorList`.
    *   Evaluates the Rule (`Value == Target`).
    *   Updates the `Class` state based on LogicMode (OR/AND/COUNT).
    *   Updates the `Group` state based on Class Memberships.

### Output Processing (Dispatch)
The module generates three types of output actions:

1.  **ALARM Dispatch:**
    *   If a Group is **Active**, sends a JSON payload to the mapped Target.
    *   *Payload:* Includes the specific sensor that triggered the event (`trigger_details`).
2.  **RESET Dispatch:**
    *   If **ALL** Groups become **Inactive**, sends a "Clear" payload to **ALL** known targets.
3.  **BEDROOM SYNC:**
    *   On **EVERY** sensor update, sends a presence map (Switch State + Door State) to the defined `BedroomTarget`.

---

## 5. Data Structures

### A. Configuration Backup (JSON)
The structure used by `GetConfiguration()` and Backup/Restore.

```json
{
  "ClassList": [
    { "ClassID": "cls_x", "ClassName": "Smoke", "LogicMode": 0, "Threshold": 1, "TimeWindow": 10 }
  ],
  "GroupList": [
    { "GroupID": "grp_y", "GroupName": "Fire Alarm", "GroupLogic": 0 }
  ],
  "SensorList": [
    { "VariableID": 12345, "ClassID": "cls_x", "Operator": 0, "ComparisonValue": "1", "ParentName": "Kitchen" }
  ],
  "GroupMembers": [
    { "GroupName": "Fire Alarm", "ClassID": "cls_x" }
  ],
  "BedroomList": [
    { "GroupName": "Guest Room", "ActiveVariableID": 55555, "BedroomDoorClassID": "cls_z" }
  ],
  "DispatchTargets": [
    { "Name": "Central Logic", "InstanceID": 99999 }
  ],
  "GroupDispatch": [
    { "GroupName": "Fire Alarm", "InstanceID": 99999 }
  ],
  "BedroomTarget": 99999,
  "MaintenanceMode": false
}
```

### B. Communication Payloads (JSON)
Sent to Module 2 via `IPS_RequestAction($TargetID, 'ReceivePayload', $JSON)`.

#### 1. ALARM (Active Event)
```json
{
  "event_type": "ALARM",
  "event_id": "unique_id",
  "timestamp": 1700000000,
  "source_id": 30015,
  "primary_class": "Smoke",
  "primary_group": "Fire Alarm",
  "active_groups": ["Fire Alarm"],
  "trigger_details": {
      "variable_id": 12345,
      "value_raw": true,
      "tag": "Smoke",
      "var_name": "Kitchen Smoke Detector"
  }
}
```

#### 2. RESET (All Clear)
```json
{
  "event_type": "ALARM",
  "event_id": "unique_id",
  "active_groups": [],
  "trigger_details": null
}
```

#### 3. BEDROOM_SYNC (Presence Map)
```json
{
  "event_type": "BEDROOM_SYNC",
  "source_id": 30015,
  "bedrooms": [
    { "GroupName": "Guest Room", "SwitchState": true, "DoorTripped": false }
  ]
}
```

---

## 6. Public API (Function Calls)

These functions are available for external scripts or Module 2 to call.

### `MYALARM_GetConfiguration(int $InstanceID)`
*   **Purpose:** Discovery. Allows Module 2 to learn the configuration of Module 1.
*   **Returns:** (String) A JSON-encoded string containing the full Configuration structure (see Section 5A).
*   **Usage:**
    ```php
    $configJSON = MYALARM_GetConfiguration(12345);
    $config = json_decode($configJSON, true);
    ```

### `MYALARM_RequestStateSync(int $InstanceID)`
*   **Purpose:** Forced Synchronization. Instructs Module 1 to immediately evaluate all sensors and send payloads for every active sensor.
*   **Behavior:** Performs a "Full Active Iteration". If 5 windows are open, it fires 5 separate `ALARM` payloads (one per sensor) followed by a `BEDROOM_SYNC`.
*   **Usage:**
    ```php
    MYALARM_RequestStateSync(12345);
    ```

### `MYALARM_SaveConfiguration(int $InstanceID)`
*   **Purpose:** Commits RAM buffers to disk and registers event listeners.
*   **Usage:** Typically called by the UI "Commit" button, but can be called by scripts to automate deployment.

### `MYALARM_UI_Import(int $InstanceID, string $ClassID)`
*   **Purpose:** Adds sensors from the Import Wizard cache to the specified Class.
*   **Usage:** Internal UI use.
