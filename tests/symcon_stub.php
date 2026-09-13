<?php
// Small executable Symcon model. Pending properties and applied properties are deliberately distinct.
const VM_UPDATE = 10603;
const KL_MESSAGE = 0;
const KL_WARNING = 1;
const KR_READY = 10103;
$GLOBALS['objects'] = []; $GLOBALS['variables'] = []; $GLOBALS['calls'] = []; $GLOBALS['nextVar'] = 900000;
class IPSModule
{
    public int $InstanceID;
    public array $pending = [], $properties = [], $attributes = [], $buffers = [], $idents = [], $timers = [], $messages = [], $logs = [];
    public function __construct(int $id) { $this->InstanceID = $id; $GLOBALS['objects'][$id] = $this; }
    public function Create() {}
    public function Destroy() { $GLOBALS['destroyed_instances'][] = $this->InstanceID; }
    public function ApplyChanges() {}
    public function __call(string $name, array $args) {
        if (($GLOBALS['unavailable_attribute_interface'] ?? 0) === $this->InstanceID && str_contains($name, 'Attribute')) throw new RuntimeException('InstanceInterface is not available during Destroy');
        if (str_starts_with($name, 'RegisterProperty')) { $this->properties[$args[0]] ??= $args[1]; $this->pending[$args[0]] ??= $args[1]; return; }
        if (str_starts_with($name, 'ReadProperty')) return $this->properties[$args[0]];
        if (str_starts_with($name, 'RegisterAttribute')) { $this->attributes[$args[0]] ??= $args[1]; return; }
        if (str_starts_with($name, 'ReadAttribute')) return $this->attributes[$args[0]];
        if (str_starts_with($name, 'WriteAttribute')) { $this->attributes[$args[0]] = $args[1]; return; }
        if (str_starts_with($name, 'RegisterVariable')) {
            if (!isset($this->idents[$args[0]])) {
                $id = ++$GLOBALS['nextVar']; $this->idents[$args[0]] = $id;
                $GLOBALS['variables'][$id] = str_ends_with($name, 'String') ? '' : (str_ends_with($name, 'Boolean') ? false : 0);
            }
            return $this->idents[$args[0]];
        }
        if ($name === 'UpdateFormField') { $this->buffers['test_form_' . $args[0]] = $args[2]; return; }
        if ($name === 'UnregisterVariable' && !empty($GLOBALS['model_variable_cleanup'])) {
            $id = $this->idents[$args[0]] ?? 0; unset($this->idents[$args[0]], $GLOBALS['variables'][$id]); return;
        }
        if (in_array($name, ['ReloadForm', 'UpdateFormField', 'SendDebug', 'UnregisterVariable'], true)) return;
        throw new RuntimeException('Unstubbed method ' . $name);
    }
    public function GetValue($name) { return GetValue($this->GetIDForIdent($name)); }
    public function SetValue($name, $value) { $GLOBALS['variables'][$this->GetIDForIdent($name)] = $value; }
    public function GetIDForIdent($name) { return $this->idents[$name] ?? 0; }
    public function GetBuffer($name) {
        if (($GLOBALS['unavailable_buffer_interface'] ?? 0) === $this->InstanceID) throw new RuntimeException('InstanceInterface is not available');
        return $this->buffers[$name] ?? '';
    }
    public function SetBuffer($name, $value) {
        if (($GLOBALS['unavailable_buffer_interface'] ?? 0) === $this->InstanceID) throw new RuntimeException('InstanceInterface is not available');
        $this->buffers[$name] = $value;
        if (isset($GLOBALS['on_set_buffer'])) ($GLOBALS['on_set_buffer'])($this, $name, $value);
    }
    public function GetTimerInterval($name) { return $this->timers[$name] ?? 0; }
    public function SetTimerInterval($name, $value) {
        $this->timers[$name] = $value;
        if (isset($GLOBALS['on_set_timer_interval'])) ($GLOBALS['on_set_timer_interval'])($this, $name, $value);
    }
    public function RegisterTimer($name, $value, $script) { $this->timers[$name] = $value; }
    public function RegisterMessage($id, $message) { $this->messages[$id] = [$message]; }
    public function UnregisterMessage($id, $message) { unset($this->messages[$id]); }
    public function GetMessageList() { return $this->messages; }
    public function LogMessage($text, $level) { $this->logs[] = $text; }
}
function IPS_SetProperty($id, $name, $value) { $GLOBALS['objects'][$id]->pending[$name] = $value; }
function IPS_GetProperty($id, $name) { return $GLOBALS['objects'][$id]->pending[$name]; }
function IPS_HasChanges($id) { $o = $GLOBALS['objects'][$id]; return $o->pending !== $o->properties; }
function IPS_ApplyChanges($id) {
    $o = $GLOBALS['objects'][$id]; $o->properties = $o->pending;
    $GLOBALS['apply_depth'] = ($GLOBALS['apply_depth'] ?? 0) + 1;
    try { $o->ApplyChanges(); } finally { --$GLOBALS['apply_depth']; }
    if (!$GLOBALS['apply_depth'] && empty($GLOBALS['hold_post_apply'])) drainPostApplyTimers();
}
function IPS_GetKernelRunlevel() { return $GLOBALS['kernel_runlevel'] ?? KR_READY; }
function drainPostApplyTimers() {
    foreach ($GLOBALS['objects'] as $o) {
        if ($o->GetTimerInterval('PostApplyTimer') > 0) $o->RunPostApply();
        if ($o->GetTimerInterval('SafetyApplyTimer') > 0) $o->CompleteSafetyApply();
    }
}
function assertOutsideApply() {
    if (!empty($GLOBALS['apply_depth'])) {
        $GLOBALS['lifecycle_cross_calls'] = ($GLOBALS['lifecycle_cross_calls'] ?? 0) + 1;
        throw new RuntimeException('InstanceInterface is not available during cross-module Apply');
    }
}
function IPS_VariableExists($id) { return array_key_exists($id, $GLOBALS['variables']); }
function IPS_ObjectExists($id) { return IPS_VariableExists($id) || IPS_InstanceExists($id); }
function IPS_InstanceExists($id) { return isset($GLOBALS['objects'][$id]); }
function GetValue($id) {
    if (isset($GLOBALS['on_get_value'])) ($GLOBALS['on_get_value'])($id);
    if (!IPS_VariableExists($id)) throw new RuntimeException("Missing variable $id");
    return $GLOBALS['variables'][$id];
}
function GetValueFormattedEx($id, $value) { return (string)$value; }
function GetValueFormatted($id) { return (string)GetValue($id); }
function IPS_GetParent($id) { return $GLOBALS['model_parents'][$id] ?? 0; }
function IPS_GetName($id) { if (isset($GLOBALS["on_get_name"])) ($GLOBALS["on_get_name"])($id); return "Object $id"; }
function IPS_GetObject($id) { return $GLOBALS['model_objects'][$id] ?? ['ObjectType' => IPS_VariableExists($id) ? 2 : 1, 'ObjectIdent' => $GLOBALS['model_idents'][$id] ?? '', 'ObjectName' => IPS_GetName($id)]; }
function IPS_GetInstance($id) { return ['ModuleInfo' => ['ModuleID' => $GLOBALS['model_module_ids'][$id] ?? ($GLOBALS['objects'][$id] instanceof PropertyStateManager ? '{D90786C5-5A3E-4B0F-935A-3A3A9D1C9E9A}' : '{OTHER}')]]; }
function IPS_GetVariable($id) { return ['VariableUpdated' => time(), 'VariableType' => $GLOBALS['model_variable_types'][$id] ?? (is_bool(GetValue($id)) ? 0 : 1)]; }
function IPS_GetChildrenIDs($id) { return $GLOBALS['model_children'][$id] ?? []; }
function IPS_GetInstanceListByModuleID($id) { return []; }
function IPS_SetHidden($id, $hidden) {}
function IPS_VariableProfileExists($name) { return true; }
function IPS_SetVariableProfileAssociation(...$args) {}
function IPS_RequestAction($id, $ident, $value) {
    assertOutsideApply();
    $GLOBALS['calls'][] = [$id, $ident, $value];
    if (isset($GLOBALS['on_request_action'])) ($GLOBALS['on_request_action'])($id, $ident, $value);
    if (!empty($GLOBALS['fail_dispatch'][$id])) throw new RuntimeException('Simulated delivery failure');
    if (method_exists($GLOBALS['objects'][$id], 'RequestAction')) $GLOBALS['objects'][$id]->RequestAction($ident, $value);
}
function MYALARM_GetConfiguration($id) { assertOutsideApply(); return $GLOBALS['objects'][$id]->GetConfiguration(); }
// Exercise wrappers that require every argument even when the module method has a default.
function MYALARM_GetSafetySnapshot($id, $mapping, $target, $remember) {
    assertOutsideApply();
    if (!empty($GLOBALS['snapshot_failure'])) throw new RuntimeException('Simulated API failure');
    return $GLOBALS['objects'][$id]->GetSafetySnapshot($mapping, $target, $remember);
}
function IPS_LogMessage(...$args) {}
function IPS_GetKernelDir() { return '/tmp/'; }
function invokePrivate($object, $method, ...$args) { return (new ReflectionMethod($object, $method))->invoke($object, ...$args); }
function IPS_SemaphoreEnter($name, $timeout) {
    if (isset($GLOBALS['on_semaphore_enter'])) ($GLOBALS['on_semaphore_enter'])($name, $timeout);
    return empty($GLOBALS['semaphore_busy'][$name]);
}
function IPS_SemaphoreLeave($name) { if(isset($GLOBALS['on_semaphore_leave']))($GLOBALS['on_semaphore_leave'])($name); }

function IPS_GetVariableProfile($name) { return ['Associations'=>array_map(static fn($id)=>['Value'=>$id,'Name'=>'State '.$id],[0,2,3,6,9])]; }
