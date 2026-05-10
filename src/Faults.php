<?php
declare(strict_types=1);

namespace MIS;

final class Faults
{
    /** Severity counts for the dashboard KPI cards. */
    public static function severityCounts(?int $airframeId = null): array
    {
        $pdo = Db::pdo();
        $where = 'WHERE fc.is_active = 1';
        $params = [];
        if ($airframeId) {
            $where .= ' AND fc.airframe_id = :aid';
            $params[':aid'] = $airframeId;
        }
        $sql = "SELECT severity, COUNT(*) AS n FROM fault_catalog fc $where GROUP BY severity";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $out = ['CRITICAL' => 0, 'HIGH' => 0, 'MEDIUM' => 0, 'LOW' => 0];
        foreach ($stmt->fetchAll() as $r) {
            $out[$r['severity']] = (int) $r['n'];
        }
        $sql = "SELECT COUNT(*) AS total FROM fault_catalog fc $where";
        $stmt = $pdo->prepare($sql); $stmt->execute($params);
        $out['TOTAL'] = (int) $stmt->fetchColumn();

        $sql = "SELECT COUNT(*) FROM fault_occurrences fo
                JOIN fault_catalog fc ON fc.id = fo.fault_id
                $where AND fo.occurred_at >= (NOW() - INTERVAL 30 DAY)";
        $stmt = $pdo->prepare($sql); $stmt->execute($params);
        $out['OCCURRENCES_30D'] = (int) $stmt->fetchColumn();
        return $out;
    }

    /** Active issues list (mockup left column). */
    public static function activeList(?int $airframeId = null): array
    {
        $pdo = Db::pdo();
        $where = 'WHERE fc.is_active = 1';
        $params = [];
        if ($airframeId) {
            $where .= ' AND fc.airframe_id = :aid';
            $params[':aid'] = $airframeId;
        }
        $sql = "
            SELECT fc.id, fc.fault_code, fc.title, fc.severity, fc.ata_chapter,
                   s.name AS system_name,
                   (SELECT COUNT(*) FROM fault_occurrences fo WHERE fo.fault_id = fc.id) AS occurrences,
                   (SELECT MAX(fo.occurred_at) FROM fault_occurrences fo WHERE fo.fault_id = fc.id) AS last_seen,
                   (SELECT GROUP_CONCAT(DISTINCT t.tail_number ORDER BY t.tail_number SEPARATOR ', ')
                      FROM fault_occurrences fo
                      JOIN aircraft_tails t ON t.id = fo.tail_id
                     WHERE fo.fault_id = fc.id) AS affected_tails
            FROM fault_catalog fc
            JOIN systems s ON s.id = fc.system_id
            $where
            ORDER BY FIELD(fc.severity,'CRITICAL','HIGH','MEDIUM','LOW'), last_seen DESC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function detail(int $faultId): ?array
    {
        $pdo = Db::pdo();
        $stmt = $pdo->prepare(
            'SELECT fc.*, s.name AS system_name, a.code AS airframe_code, a.model AS airframe_model
               FROM fault_catalog fc
               JOIN systems s ON s.id = fc.system_id
               JOIN airframes a ON a.id = fc.airframe_id
              WHERE fc.id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $faultId]);
        $fault = $stmt->fetch();
        if (!$fault) {
            return null;
        }
        $aff = $pdo->prepare('SELECT label FROM fault_affected_systems WHERE fault_id = :id ORDER BY id');
        $aff->execute([':id' => $faultId]);
        $fault['affected_labels'] = array_column($aff->fetchAll(), 'label');

        $q = $pdo->prepare('SELECT id, question_order, question FROM fault_diagnostic_questions WHERE fault_id = :id ORDER BY question_order, id');
        $q->execute([':id' => $faultId]);
        $fault['diagnostic_questions'] = $q->fetchAll();

        $occ = $pdo->prepare(
            'SELECT fo.id, fo.occurred_at, fo.flight_number, fo.phase, fo.report_type, fo.notes, t.tail_number
               FROM fault_occurrences fo
               JOIN aircraft_tails t ON t.id = fo.tail_id
              WHERE fo.fault_id = :id
              ORDER BY fo.occurred_at DESC LIMIT 50'
        );
        $occ->execute([':id' => $faultId]);
        $fault['recent_occurrences'] = $occ->fetchAll();

        // Trend (occurrences per day for last 14 days)
        $trend = $pdo->prepare(
            "SELECT DATE(occurred_at) AS d, COUNT(*) AS n
               FROM fault_occurrences
              WHERE fault_id = :id AND occurred_at >= (NOW() - INTERVAL 14 DAY)
              GROUP BY DATE(occurred_at)
              ORDER BY d ASC"
        );
        $trend->execute([':id' => $faultId]);
        $fault['trend'] = $trend->fetchAll();

        $docs = $pdo->prepare(
            'SELECT d.id, d.title, d.subtitle, d.doc_type, d.ata_chapter, d.storage_path
               FROM fault_reference_documents frd
               JOIN documents d ON d.id = frd.document_id
              WHERE frd.fault_id = :id
              ORDER BY frd.display_order, d.title'
        );
        $docs->execute([':id' => $faultId]);
        $fault['reference_documents'] = $docs->fetchAll();

        $fault['affected_tail_numbers'] = array_unique(array_column($fault['recent_occurrences'], 'tail_number'));
        return $fault;
    }
}
