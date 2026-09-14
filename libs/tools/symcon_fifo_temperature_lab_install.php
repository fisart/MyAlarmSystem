<?php
declare(strict_types=1);
// Einmal manuell ausfuehren; eigene Lab-Kategorie, keine Produktions-IDs.
$labToolsDirectory = rtrim(IPS_GetKernelDir(), '/\\') . '/modules/MyAlarmSystem/libs/tools';
require_once $labToolsDirectory . '/FifoLab.php';
echo json_encode(FifoLab::install('temperature'), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
