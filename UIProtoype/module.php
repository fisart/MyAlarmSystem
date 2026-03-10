<?php

declare(strict_types=1);

class SimpleListPersistenceTest extends IPSModule
{
    public function Create()
    {
        parent::Create();

        // One plain property-backed list
        $this->RegisterPropertyString('TestList', '[]');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // No rewriting of properties here.
        // No normalization.
        // No GetConfigurationForm override.
        $this->SetStatus(102);
    }
}
