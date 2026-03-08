# SensorGroup Module Documentation

**Version:** 2.9.3 (Stable Production Release)

## 1. Overview

The **SensorGroup Module** (Module 1) acts as the **Edge / Aggregator** in a distributed alarm system architecture. Its primary purpose is to monitor raw physical sensors (Variables), aggregate them into logical classes and groups, and dispatch standardized JSON payloads to downstream targets such as Module 2 and Module 3.

It is designed to be **stateless regarding alarm handling**.
It detects **conditions**, but it does **not** own the global alarm state machine such as:

- Disarmed
- Exit Delay
- Armed Internal
- Armed External
- Alarm

Those semantics belong to **Module 2**.

Module 1 remains the authority for:

- raw sensor truth
- filtering / debouncing
- class aggregation
- group aggregation
- active sensor determination
- event token generation

---

## 2. User Interface (Configuration)

The configuration form is divided into logical steps to build the hierarchy bottom-up.

### Step 1: Class Definitions

Classes represent a sensor type or logical zone, for example:

- Smoke Detectors
- Ground Floor Windows
- Motion Sensors
- Entrance Ground Floor

Each class defines how sensor activity is interpreted.

Fields:

- **Class Name**: Human-readable name
- **Label Mode**: Determines how the sensor is described in payloads
  - `0` = Sensor Name
  - `1` = Parent Name
  - `2` = Status Text

- **Logic Mode**
  - `0` **OR (Instant)**: active if any member sensor matches
  - `1` **AND (All)**: active only if all member sensors match simultaneously
  - `2` **COUNT**: active if the number of trigger events within the time window reaches the threshold

- **Threshold / TimeWindow**: parameters for COUNT logic

### Step 2: Sensor Inputs

This section assigns physical IP-Symcon variables to the classes defined in Step 1.

Fields:

- **Variable**: Source Variable ID
- **Operator / ComparisonValue**: condition that defines a trigger
  - example: `= 1`
  - example: `= true`

- **Note for NC contacts**: invert by using `=` with value `0`
- Sensors are shown in expansion panels grouped by class

### Step 3: Group Management

Groups represent higher-level alarm sectors, for example:

- Fire Alarm
- Burglar Alarm
- Entrance Ground Floor
- Entrance Basement
- House State

Subsections:

#### 3a. Group Definitions

Defines:

- **Group Name**
- **Group Logic**
  - `0` = OR
  - `1` = AND

#### 3b. Bedroom Behavioral Settings

Defines special bedroom-related behavior for presence synchronization.

Fields:

- **Active Variable (IPSView)**: indicates whether the bedroom is occupied
- **Door Class**: class used to determine whether the bedroom door is tripped

#### 3c. Group Membership Matrix

Checkbox matrix linking:

- Classes → Groups

### Step 4: Dispatch Targets (Routing)

Defines where group events are sent.

Fields:

- **DispatchTargets**: list of target instances
- **GroupDispatch**: maps groups to target instances
- **BedroomTarget**: single target instance receiving `BEDROOM_SYNC`
- **VaultInstanceID**: Secrets/Vault instance for dashboard authentication

### Actions

- **COMMIT ALL CHANGES TO DISK**
  Saves buffers to properties and registers sensor event subscriptions.
  Required to make changes live.

- **FORCE RE-REGISTER SENSORS (Repair)**
  Rebuilds event subscriptions without changing configuration.

- **Bulk Import Wizard**
  Scans the Symcon object tree and adds variables to a selected class.

---

## 3. Visualization Dashboard (HTML / Mermaid)

The module provides a live interactive architecture diagram via Webhook.

### URL

```text
http://<IP>:3777/hook/MyAlarmFlow_<InstanceID>
```

### Security

Protected by the selected Secrets/Vault instance.

### Technology

- Mermaid.js
- svg-pan-zoom
- AJAX refresh

### Visual Logic

- **Green** = inactive / safe
- **Red** = active / triggered
- **Grey** = static path
- **Blue** = target instance node

### Features

- real-time refresh without page reload
- target filter checkboxes
- pan / zoom interaction
- visual tracing from sensors → classes → groups → targets

---

## 4. Input / Output Process Flow

### Input Processing

1. A Symcon variable changes state
2. `MessageSink()` receives the update
3. `CheckLogic()`:
   - matches the variable to `SensorList`
   - evaluates the sensor rule
   - updates class state according to class logic
   - updates group state according to group memberships
   - updates runtime active class / sensor buffers
   - emits runtime payloads as needed

### Output Processing

Module 1 emits two runtime payload types:

1. **ALARM**
2. **BEDROOM_SYNC**

There is **no separate RESET event type**.
A reset / all-clear is represented by an `ALARM` payload with empty active lists.

#### ALARM Dispatch

If at least one group is active, Module 1 sends an `ALARM` payload to routed targets.

#### RESET / All-Clear Dispatch

If all groups become inactive, Module 1 sends an `ALARM` payload with:

- `active_groups = []`
- `active_classes = []`
- `trigger_details = null`
- `active_sensor_details = []`

and also empty `target_*` fields.

#### BEDROOM_SYNC

Module 1 sends bedroom presence/door synchronization payloads to the configured `BedroomTarget`.

---

## 5. Data Structures

## A. Configuration Structure (`GetConfiguration()`, Backup / Restore)

```json
{
  "ClassList": [
    {
      "ClassID": "cls_x",
      "ClassName": "Smoke",
      "LogicMode": 0,
      "LabelMode": 0,
      "Threshold": 1,
      "TimeWindow": 10
    }
  ],
  "GroupList": [
    {
      "GroupID": "grp_y",
      "GroupName": "Fire Alarm",
      "GroupLogic": 0
    }
  ],
  "SensorList": [
    {
      "VariableID": 12345,
      "ClassID": "cls_x",
      "Operator": 0,
      "ComparisonValue": "1",
      "DisplayID": "12345",
      "ParentName": "Kitchen",
      "GrandParentName": "Ground Floor"
    }
  ],
  "GroupMembers": [
    {
      "GroupName": "Fire Alarm",
      "ClassID": "cls_x"
    }
  ],
  "BedroomList": [
    {
      "GroupName": "Guest Room",
      "ActiveVariableID": 55555,
      "BedroomDoorClassID": "cls_z"
    }
  ],
  "TamperList": [
    {
      "VariableID": 66666,
      "Operator": 0,
      "ComparisonValue": "1"
    }
  ],
  "DispatchTargets": [
    {
      "Name": "Central Logic",
      "InstanceID": 99999
    }
  ],
  "GroupDispatch": [
    {
      "GroupName": "Fire Alarm",
      "InstanceID": 99999
    }
  ],
  "BedroomTarget": 99999,
  "MaintenanceMode": false
}
```

### Notes

This structure describes the **configuration**, not the runtime state.

It tells consumers:

- which classes exist
- which groups exist
- how sensors map to classes
- how classes map to groups
- how groups map to targets

It does **not** tell which sensors or groups are active right now.

---

## B. Runtime Communication Payloads

Payloads are sent via:

```php
IPS_RequestAction($TargetID, 'ReceivePayload', $JSON)
```

Module 1 now provides **two views** inside the same `ALARM` payload:

### 1. Legacy / Global View

Used for backward compatibility and consumers such as Module 2:

- `primary_class`
- `primary_group`
- `active_classes`
- `active_groups`
- `trigger_details`
- `active_sensor_details`

### 2. Target-Local Projection

Used primarily by Module 3:

- `target_instance_id`
- `target_active_groups`
- `target_active_classes`
- `target_trigger_details`
- `target_active_sensor_details`

### Important interpretation rule

**Module 3 should treat the `target_*` fields as authoritative for runtime event interpretation.**
The legacy/global fields remain present for backward compatibility and for consumers such as Module 2.

---

### 1. `ALARM` payload (active event)

```json
{
  "event_type": "ALARM",
  "event_epoch": 1700000000123,
  "event_seq": 17,
  "event_id": "65f0c9f4d8a12",
  "timestamp": 1700000000,
  "source_id": 30015,
  "source_name": "Sensor Aggregator",
  "primary_class": "Smoke",
  "primary_group": "Fire Alarm",
  "active_classes": ["Smoke", "Entrance Ground Floor"],
  "active_groups": ["Fire Alarm", "Entrance Ground Floor"],
  "is_maintenance": false,
  "trigger_details": {
    "variable_id": 12345,
    "value_raw": true,
    "tag": "Smoke",
    "class_id": "cls_smoke",
    "var_name": "Kitchen Smoke Detector",
    "parent_name": "Kitchen",
    "grandparent_name": "Ground Floor",
    "value_human": "Alarm",
    "smart_label": "Kitchen Smoke Detector"
  },
  "active_sensor_details": [
    {
      "variable_id": 12345,
      "value_raw": true,
      "tag": "Smoke",
      "class_id": "cls_smoke",
      "class_name": "Smoke",
      "var_name": "Kitchen Smoke Detector",
      "parent_name": "Kitchen",
      "grandparent_name": "Ground Floor",
      "value_human": "Alarm",
      "smart_label": "Kitchen Smoke Detector",
      "group_names": ["Fire Alarm"]
    }
  ],
  "target_instance_id": 99999,
  "target_active_groups": ["Fire Alarm"],
  "target_active_classes": ["Smoke"],
  "target_trigger_details": {
    "variable_id": 12345,
    "value_raw": true,
    "tag": "Smoke",
    "class_id": "cls_smoke",
    "var_name": "Kitchen Smoke Detector",
    "parent_name": "Kitchen",
    "grandparent_name": "Ground Floor",
    "value_human": "Alarm",
    "smart_label": "Kitchen Smoke Detector"
  },
  "target_active_sensor_details": [
    {
      "variable_id": 12345,
      "value_raw": true,
      "tag": "Smoke",
      "class_id": "cls_smoke",
      "class_name": "Smoke",
      "var_name": "Kitchen Smoke Detector",
      "parent_name": "Kitchen",
      "grandparent_name": "Ground Floor",
      "value_human": "Alarm",
      "smart_label": "Kitchen Smoke Detector",
      "group_names": ["Fire Alarm"]
    }
  ]
}
```

---

### Field descriptions for `ALARM`

#### Token / identity

- **`event_type`**: always `"ALARM"`
- **`event_epoch`**: event ordering token, wall-clock milliseconds
- **`event_seq`**: monotonic per-runtime sequence
- **`event_id`**: unique payload ID from `uniqid()`
- **`timestamp`**: Unix timestamp in seconds

#### Source

- **`source_id`**: Module 1 instance ID
- **`source_name`**: Module 1 instance name

#### Legacy / global view

- **`primary_class`**: human-readable primary class
- **`primary_group`**: first active group
- **`active_classes`**: globally active class names
- **`active_groups`**: globally active group names
- **`is_maintenance`**: mirrors MaintenanceMode
- **`trigger_details`**: legacy/global anchor sensor
- **`active_sensor_details`**: globally active concrete sensors with metadata

#### Target-local view

- **`target_instance_id`**: target instance ID for this payload projection
- **`target_active_groups`**: active groups routed to this target
- **`target_active_classes`**: active classes contributing to `target_active_groups`
- **`target_trigger_details`**: payload-local anchor sensor for this target
- **`target_active_sensor_details`**: concrete active sensors contributing to this target’s groups

---

### Structure of `trigger_details`

```json
{
  "variable_id": 12345,
  "value_raw": true,
  "tag": "Smoke",
  "class_id": "cls_smoke",
  "var_name": "Kitchen Smoke Detector",
  "parent_name": "Kitchen",
  "grandparent_name": "Ground Floor",
  "value_human": "Alarm",
  "smart_label": "Kitchen Smoke Detector"
}
```

---

### Structure of `active_sensor_details[]` and `target_active_sensor_details[]`

```json
{
  "variable_id": 12345,
  "value_raw": true,
  "tag": "Smoke",
  "class_id": "cls_smoke",
  "class_name": "Smoke",
  "var_name": "Kitchen Smoke Detector",
  "parent_name": "Kitchen",
  "grandparent_name": "Ground Floor",
  "value_human": "Alarm",
  "smart_label": "Kitchen Smoke Detector",
  "group_names": ["Fire Alarm"]
}
```

#### Notes

- `group_names` in the global list refers to globally active groups the sensor contributes to
- `group_names` in the target-local list refers only to groups relevant to that target

---

### 2. RESET / all-clear payload

There is no separate reset event type.
Reset is encoded as an `ALARM` payload with empty active lists.

```json
{
  "event_type": "ALARM",
  "event_epoch": 1700000000456,
  "event_seq": 18,
  "event_id": "65f0c9f4d8a34",
  "timestamp": 1700000001,
  "source_id": 30015,
  "source_name": "Sensor Aggregator",
  "primary_class": "",
  "primary_group": "",
  "active_classes": [],
  "active_groups": [],
  "is_maintenance": false,
  "trigger_details": null,
  "active_sensor_details": [],
  "target_instance_id": 99999,
  "target_active_groups": [],
  "target_active_classes": [],
  "target_trigger_details": null,
  "target_active_sensor_details": []
}
```

---

### 3. `BEDROOM_SYNC` payload

```json
{
  "event_type": "BEDROOM_SYNC",
  "event_epoch": 1700000000789,
  "event_seq": 19,
  "timestamp": 1700000002,
  "source_id": 30015,
  "bedrooms": [
    {
      "GroupName": "Guest Room",
      "SwitchState": true,
      "DoorTripped": false
    }
  ]
}
```

#### Notes

`BEDROOM_SYNC` is separate from `ALARM` and is sent to the configured `BedroomTarget`.

---

## 6. Public API

These functions are available for scripts or downstream modules.

### `MYALARM_GetConfiguration(int $InstanceID)`

Purpose:

Discovery of Module 1 configuration.

Returns:

JSON string of the configuration structure described above.

Usage:

```php
$configJSON = MYALARM_GetConfiguration(12345);
$config = json_decode($configJSON, true);
```

---

### `MYALARM_RequestStateSync(int $InstanceID)`

Purpose:

Forces Module 1 to immediately evaluate all sensors and emit current runtime state.

Behavior:

- iterates over active sensors
- emits one or more `ALARM` payloads as required
- emits `BEDROOM_SYNC` if applicable

Usage:

```php
MYALARM_RequestStateSync(12345);
```

---

### `MYALARM_SaveConfiguration(int $InstanceID)`

Purpose:

Commits UI buffer state to properties and re-registers sensor subscriptions.

Typical use:

Internal UI commit action or scripted deployment.

---

### `MYALARM_UI_Import(int $InstanceID, string $ClassID)`

Purpose:

Imports variables from the wizard cache into the selected class.

Typical use:

Internal UI helper.

---

## 7. Architectural Notes

### Module boundaries

#### Module 1 owns

- sensor truth
- filtering / debounce
- class aggregation
- group aggregation
- active sensor determination
- event tokens
- routing

#### Module 2 owns

- authoritative global house/system state
- state machine semantics
- arming / delay / alarm logic

#### Module 3 owns

- domain-specific response logic
- incident interpretation
- output selection and execution
- local output throttling

### Runtime interpretation guidance

- **Module 2** should continue using the legacy/global fields
- **Module 3** should use the `target_*` fields for runtime interpretation and should not rely on the legacy/global fields for domain decisions

---
