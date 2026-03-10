<?php

declare(strict_types=1);

class SimpleTwoListPersistenceTest extends IPSModule
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
}
