<?php
declare(strict_types=1);
// Paste in a temporary Symcon script. Adjust only this folder if your library path differs.
$labToolsDirectory = rtrim(IPS_GetKernelDir(), '/\\') . '/modules/MyAlarmSystem/libs/tools';
require_once $labToolsDirectory . '/FifoLab.php';
echo json_encode(FifoLab::install(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
