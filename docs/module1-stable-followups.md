# Stable Module 1 follow-ups for main

Module 1 v2.13.4 backports the tested ordinary-processing fixes from draft PR #3 onto the merged PR #2 baseline. Module 2 remains v7.3.2; Module 3 and watchdog code are unchanged. No FIFO, shadow comparison or probe runtime is introduced.

The backport restores retained active sensor, bedroom and dynamic comparison subscriptions when an invalid draft rejects Apply after interface recreation. A stopped pulse timer is restored without generating a trigger or changing temporal state. Dynamic tamper comparison references are subscribed during ordinary and rejected Apply; CHANGE comparisons remain ignored according to existing rules.

Bedroom backing-list columns explicitly save the three required typed fields. Working rows remain authoritative on reload. The explicit **Restore bedroom draft from running configuration** action repairs only a corrupted pending bedroom section and preserves other edits, including sensor deletions. Configuration validation remains strict and intentional empty bedroom lists remain empty.

## Installation before the 12-hour pause

1. In the installed experimental build, leave live FIFO, FIFO diagnostic mode and shadow disabled; disable and Apply if any are enabled.
2. Switch the module library to `main`, update it, and Apply Module 1 instance 23172. Reopen the form and check configuration health. No Symcon service restart is required by this change; the library update uses the normal module lifecycle and is not guaranteed to have uninterrupted callbacks.
3. Verify fresh completed heartbeat cycles at Module 1 and all three Module 3 targets, and check Module 2 monitoring health. Existing successful house-state checks provide supporting evidence but heartbeat alone does not test every alarm rule/output.
4. Keep main installed during the pause. The experimental FIFO remains on `design/module1-fifo` in draft PR #3 for later supervised investigation.

Previously configured experimental settings/attributes do not activate an experimental evaluator in this stable build. The new stable code does not read them or register FIFO APIs/timers. Disable testing before switching so an old installed session is not intentionally left running through the update.

## Verification and limits

Stable safety129, configuration-form24 and subscription7 checks pass: **160 checks**. With Artur's supplied 392-rule export, 61 classes, 69 memberships, 57 routes and six bedroom mappings, the safety suite adds seven checks: **167 total**. The private export is not committed. Tests validate/compile/evaluate its schema and reported bedroom values; they model Symcon, not native sensor access or physical alarm output.

The subscription suite covers ordinary/rejected Apply, dynamic tamper-reference evaluation, temporal state retention, CHANGE reference exclusion and ignoring retained experimental settings/attributes on ordinary sensor input. The form suite retains strict rejection, isolated draft restoration, sensor deletion and intentional empty-list behavior.

Independent read-only review approved stable v2.13.4 with no blocking findings, separately rerunning all three suites and the uploaded-configuration safety checks. GitHub Actions must pass before merge. This backport improves the earlier stable baseline but does not solve the original competing-input/heartbeat event-dropping problem. Heavy native concurrent processing and every alarm route remain unproven. CPU/resident RAM are unmeasured. A branch update cannot recover lost historical messages or undo actions already delivered.
