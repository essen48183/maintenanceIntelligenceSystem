<?php
declare(strict_types=1);
require __DIR__ . '/_helpers.php';
\MIS\Auth::require();

$pdo = \MIS\Db::pdo();
$rows = $pdo->query(
    "SELECT a.id, a.code, a.manufacturer, a.model,
            (SELECT COUNT(*) FROM aircraft_tails t WHERE t.airframe_id = a.id AND t.in_service = 1) AS tails,
            (SELECT COUNT(*) FROM fault_catalog fc WHERE fc.airframe_id = a.id AND fc.is_active = 1) AS active_faults
       FROM airframes a WHERE a.is_active = 1 ORDER BY a.code"
)->fetchAll();
$systems = $pdo->query("SELECT id, code, name, ata_chapter, icon_key FROM systems ORDER BY id")->fetchAll();
ok(['airframes' => $rows, 'systems' => $systems]);
