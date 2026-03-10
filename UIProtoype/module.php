<?php

declare(strict_types=1);

class DynamicListPatternTest extends IPSModule
{
    public function Create()
    {
        parent::Create();

        // Authoritative persisted data
        $this->RegisterPropertyString('OutputTypes', '[]');
        $this->RegisterPropertyString('OutputResources', '[]');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->SetStatus(102);
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);

        $outputTypes = $this->readListProperty('OutputTypes');
        $outputResources = $this->readListProperty('OutputResources');

        // Build dynamic select options from authoritative OutputTypes property
        $typeOptions = [];
        $typeLabels = [];

        foreach ($outputTypes as $row) {
            $typeID = trim((string)($row['TypeID'] ?? ''));
            $typeName = trim((string)($row['TypeName'] ?? ''));

            if ($typeID === '') {
                continue;
            }

            $label = $typeName !== '' ? $typeName : $typeID;

            $typeOptions[] = [
                'label' => $label,
                'value' => $typeID
            ];
            $typeLabels[$typeID] = $label;
        }

        // Inject dynamic options into OutputResources.TypeID
        $this->setListColumnOptions($form, 'OutputResources', 'TypeID', $typeOptions);

        // Because loadValuesFromConfiguration=false on OutputResources,
        // we must rebuild values explicitly from the saved property.
        $resourceValues = [];
        foreach ($outputResources as $row) {
            $typeID = (string)($row['TypeID'] ?? '');

            $resourceValues[] = [
                'Active'      => (bool)($row['Active'] ?? true),
                'OutputID'    => (string)($row['OutputID'] ?? ''),
                'Name'        => (string)($row['Name'] ?? ''),
                'TypeID'      => $typeID,
                'Recipient'   => (string)($row['Recipient'] ?? ''),
                'Sender'      => (string)($row['Sender'] ?? ''),
                'TypeLabel'   => $typeLabels[$typeID] ?? '',
                'RowSummary'  => $this->buildSummary($row, $typeLabels)
            ];
        }

        $this->setListValues($form, 'OutputResources', $resourceValues);

        return json_encode($form);
    }

    private function readListProperty(string $propertyName): array
    {
        $raw = $this->ReadPropertyString($propertyName);
        $data = json_decode($raw, true);
        return is_array($data) ? array_values($data) : [];
    }

    private function buildSummary(array $row, array $typeLabels): string
    {
        $typeID = (string)($row['TypeID'] ?? '');
        $typeLabel = $typeLabels[$typeID] ?? $typeID;
        $name = trim((string)($row['Name'] ?? ''));
        $recipient = trim((string)($row['Recipient'] ?? ''));
        $sender = trim((string)($row['Sender'] ?? ''));

        $parts = [];
        if ($typeLabel !== '') {
            $parts[] = $typeLabel;
        }
        if ($name !== '') {
            $parts[] = $name;
        }
        if ($recipient !== '') {
            $parts[] = 'to: ' . $recipient;
        }
        if ($sender !== '') {
            $parts[] = 'from: ' . $sender;
        }

        return implode(' / ', $parts);
    }

    private function setListValues(array &$form, string $listName, array $values): void
    {
        if (!isset($form['elements']) || !is_array($form['elements'])) {
            return;
        }

        foreach ($form['elements'] as &$element) {
            if (($element['type'] ?? '') === 'List' && ($element['name'] ?? '') === $listName) {
                $element['values'] = $values;
                return;
            }
        }
    }

    private function setListColumnOptions(array &$form, string $listName, string $columnName, array $options): void
    {
        if (!isset($form['elements']) || !is_array($form['elements'])) {
            return;
        }

        foreach ($form['elements'] as &$element) {
            if (($element['type'] ?? '') !== 'List' || ($element['name'] ?? '') !== $listName) {
                continue;
            }

            if (!isset($element['columns']) || !is_array($element['columns'])) {
                continue;
            }

            foreach ($element['columns'] as &$column) {
                if (($column['name'] ?? '') !== $columnName) {
                    continue;
                }

                if (!isset($column['edit']) || !is_array($column['edit'])) {
                    $column['edit'] = ['type' => 'Select'];
                }

                $column['edit']['options'] = $options;
                return;
            }
        }
    }
}
