# Module 1 v2.14.5: Mermaid class and group multi-filters

## Change

The Module 1 Mermaid page provides separate dropdown menus for Groups and Classes. Each menu contains checkboxes plus All and None actions, so any number of groups and classes can be selected at the same time. The selections are intersected with each other and with the existing target filter. Selecting no classes or no groups returns a valid Mermaid placeholder instead of an invalid or unrelated graph.

Selections are stored per Module 1 instance in browser local storage. An all-selected state is not stored, allowing newly configured groups or classes to appear automatically. The existing depth, active/passive state, direction and bedroom controls remain unchanged.

## Scope and performance

Filtering is presentation-only. It neither changes the active configuration nor toggles a sensor, class, group or target. Server-side filtering runs only for the existing Mermaid API refresh; no evaluator, FIFO, heartbeat, output or archive path is changed. The page keeps its existing two-second graph refresh and five-second FIFO-status refresh, so no new polling loop is added.

The feature is stacked on the lifecycle-safe ONCE/CHANGE fix in PR #9 for production testing. It should be merged only after PR #9 and after native verification of both pulse expiry and the two dropdown selections.

## Validation

The regression verifies a multiple group selection combined with one selected class, multiple selected classes, an independent group subset, an empty class selection, both dropdown controls in the HTML page, and transmission of the class selection to the graph API.
