<?php

declare(strict_types=1);

class DynamicListPatternTest extends IPSModule
{
    public function Create()
    {
        parent::Create();

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

        $typeOptions = $this->buildTypeOptions($outputTypes);
        $typeLabels  = $this->buildTypeLabels($outputTypes);

        $this->setListColumnOptions($form, 'OutputResources', 'TypeID', $typeOptions);

        $resourceValues = [];
        foreach ($outputResources as $row) {
            $typeID = (string)($row['TypeID'] ?? '');

            $resourceValues[] = [
                'Active'     => (bool)($row['Active'] ?? true),
                'OutputID'   => (string)($row['OutputID'] ?? ''),
                'Name'       => (string)($row['Name'] ?? ''),
                'TypeID'     => $typeID,
                'Recipient'  => (string)($row['Recipient'] ?? ''),
                'Sender'     => (string)($row['Sender'] ?? ''),
                'TypeLabel'  => $typeLabels[$typeID] ?? '',
                'RowSummary' => $this->buildSummary($row, $typeLabels)
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

    private function buildTypeOptions(array $outputTypes): array
    {
        $options = [
            [
                'caption' => '',
                'value'   => ''
            ]
        ];

        foreach ($outputTypes as $row) {
            $typeID   = trim((string)($row['TypeID'] ?? ''));
            $typeName = trim((string)($row['TypeName'] ?? ''));

            if ($typeID === '') {
                continue;
            }

            $options[] = [
                'caption' => ($typeName !== '') ? $typeName : $typeID,
                'value'   => $typeID
            ];
        }

        return $options;
    }

    private function buildTypeLabels(array $outputTypes): array
    {
        $labels = [];

        foreach ($outputTypes as $row) {
            $typeID = trim((string)($row['TypeID'] ?? ''));
            $typeName = trim((string)($row['TypeName'] ?? ''));

            if ($typeID === '') {
                continue;
            }

            $labels[$typeID] = ($typeName !== '') ? $typeName : $typeID;
        }

        return $labels;
    }

    private function buildSummary(array $row, array $typeLabels): string
    {
        $typeID    = (string)($row['TypeID'] ?? '');
        $typeLabel = $typeLabels[$typeID] ?? $typeID;
        $name      = trim((string)($row['Name'] ?? ''));
        $recipient = trim((string)($row['Recipient'] ?? ''));
        $sender    = trim((string)($row['Sender'] ?? ''));

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
        $this->walkAndModifyList($form, $listName, function (&$element) use ($values) {
            $element['values'] = $values;
        });
    }

    private function setListColumnOptions(array &$form, string $listName, string $columnName, array $options): void
    {
        $this->walkAndModifyList($form, $listName, function (&$element) use ($columnName, $options) {
            if (!isset($element['columns']) || !is_array($element['columns'])) {
                return;
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
        });
    }

    private function walkAndModifyList(array &$node, string $listName, callable $callback): bool
    {
        if (($node['type'] ?? '') === 'List' && ($node['name'] ?? '') === $listName) {
            $callback($node);
            return true;
        }

        if (isset($node['elements']) && is_array($node['elements'])) {
            foreach ($node['elements'] as &$child) {
                if ($this->walkAndModifyList($child, $listName, $callback)) {
                    return true;
                }
            }
        }

        if (isset($node['items']) && is_array($node['items'])) {
            foreach ($node['items'] as &$child) {
                if ($this->walkAndModifyList($child, $listName, $callback)) {
                    return true;
                }
            }
        }

        return false;
    }
}
