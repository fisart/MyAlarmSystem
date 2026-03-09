<?php

declare(strict_types=1);

class ARMResponseManagerMock extends IPSModule
{
    private const HOUSE_STATES = [
        0 => 'Disarmed',
        2 => 'Exit Delay',
        3 => 'Armed External',
        6 => 'Armed Internal',
        9 => 'Alarm'
    ];

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyInteger('Module1InstanceID', 0);
        $this->RegisterPropertyInteger('Module2InstanceID', 0);
        $this->RegisterPropertyInteger('VaultInstanceID', 0);
        $this->RegisterPropertyString('ImportedModule1ConfigJson', '');
        $this->RegisterPropertyString('GroupStateMappings', '[]');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $rows = json_decode($this->ReadPropertyString('GroupStateMappings'), true);
        if (!is_array($rows) || count($rows) === 0) {
            IPS_SetProperty($this->InstanceID, 'GroupStateMappings', json_encode($this->BuildDefaultRows()));
            IPS_ApplyChanges($this->InstanceID);
            return;
        }

        $this->SetStatus(102);
    }

    public function GetConfigurationForm()
    {
        $formPath = __DIR__ . '/form.json';
        $form = json_decode(file_get_contents($formPath), true);

        if (!is_array($form)) {
            return json_encode([
                'elements' => [
                    [
                        'type'    => 'Label',
                        'caption' => 'Error loading form.json'
                    ]
                ]
            ]);
        }

        $rows = json_decode($this->ReadPropertyString('GroupStateMappings'), true);
        if (!is_array($rows)) {
            $rows = [];
        }

        $stateOptions = [];
        foreach (self::HOUSE_STATES as $id => $label) {
            $stateOptions[] = [
                'caption' => $label,
                'value'   => (string) $id
            ];
        }

        foreach ($form['elements'] as &$element) {
            if (($element['type'] ?? '') === 'List' && ($element['name'] ?? '') === 'GroupStateMappings') {
                $element['values'] = $rows;

                foreach ($element['columns'] as &$column) {
                    if (($column['name'] ?? '') === 'HouseState') {
                        $column['edit']['options'] = $stateOptions;
                    }
                }
                unset($column);
            }
        }
        unset($element);

        return json_encode($form);
    }

    private function BuildDefaultRows(): array
    {
        $groups = [
            'Burglar Alarm',
            'Fire Alarm',
            'Water Alarm',
            'Technical Fault'
        ];

        $rows = [];
        foreach ($groups as $groupName) {
            foreach (self::HOUSE_STATES as $stateId => $stateLabel) {
                $rows[] = [
                    'Enabled'         => false,
                    'GroupName'       => $groupName,
                    'HouseState'      => (string) $stateId,
                    'AssignedOutputs' => '',
                    'MinSeconds'      => 60
                ];
            }
        }

        return $rows;
    }
}
