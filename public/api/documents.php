<?php
declare(strict_types=1);
require __DIR__ . '/_helpers.php';

$user = \MIS\Auth::require();
$action = $_GET['action'] ?? 'list';

if ($action === 'list') {
    $pdo = \MIS\Db::pdo();
    $rows = $pdo->query(
        'SELECT d.id, d.doc_type, d.ata_chapter, d.title, d.subtitle, d.revision, d.effective_date,
                a.code AS airframe_code, s.name AS system_name
           FROM documents d
           LEFT JOIN airframes a ON a.id = d.airframe_id
           LEFT JOIN systems s ON s.id = d.system_id
          ORDER BY d.airframe_id, d.ata_chapter, d.title'
    )->fetchAll();
    ok(['documents' => $rows]);
}

if ($action === 'open') {
    $id = (int) ($_GET['id'] ?? 0);
    if ($id < 1) \MIS\Auth::respond(400, ['error' => 'missing_id']);
    $pdo = \MIS\Db::pdo();
    $stmt = $pdo->prepare('SELECT id, title, storage_path FROM documents WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $doc = $stmt->fetch();
    if (!$doc) \MIS\Auth::respond(404, ['error' => 'not_found']);
    \MIS\Audit::log((int) $user['id'], 'doc.open', 'document', (string) $id);
    ok(['document' => $doc]);
}

if ($action === 'download') {
    $id = (int) ($_GET['id'] ?? 0);
    if ($id < 1) \MIS\Auth::respond(400, ['error' => 'missing_id']);
    $pdo = \MIS\Db::pdo();
    $stmt = $pdo->prepare('SELECT id, title, storage_path FROM documents WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $doc = $stmt->fetch();
    if (!$doc) \MIS\Auth::respond(404, ['error' => 'not_found']);

    $libRoot = realpath(dirname(__DIR__, 2) . '/docs/library');
    $rel     = ltrim((string) $doc['storage_path'], '/');
    $abs     = realpath($libRoot . '/' . $rel);

    \MIS\Audit::log((int) $user['id'], 'doc.download', 'document', (string) $id, [
        'storage_path' => $doc['storage_path'],
        'served'       => $abs && str_starts_with($abs, $libRoot ?: ''),
    ]);

    // Confirm path is inside the library (defence-in-depth against traversal)
    if (!$abs || !$libRoot || strpos($abs, $libRoot) !== 0 || !is_file($abs)) {
        // No file present yet — return a tiny placeholder PDF so the UI flow is testable.
        $placeholder = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 612 792]/Contents 4 0 R/Resources<</Font<</F1<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>>>>>>>endobj\n4 0 obj<</Length 90>>stream\nBT /F1 16 Tf 60 730 Td (" . addslashes($doc['title']) . ") Tj 0 -22 Td (Placeholder \\261 file not yet uploaded.) Tj ET\nendstream\nendobj\nxref\n0 5\n0000000000 65535 f\n0000000009 00000 n\n0000000058 00000 n\n0000000111 00000 n\n0000000223 00000 n\ntrailer<</Size 5/Root 1 0 R>>\nstartxref\n330\n%%EOF\n";
        header('Content-Type: application/pdf');
        $safe = preg_replace('/[^A-Za-z0-9 \-_.]/', '_', (string) $doc['title']);
        header('Content-Disposition: inline; filename="' . $safe . '.pdf"');
        header('Cache-Control: no-store');
        echo $placeholder;
        exit;
    }
    header('Content-Type: application/pdf');
    $safe = preg_replace('/[^A-Za-z0-9 \-_.]/', '_', (string) $doc['title']);
    header('Content-Disposition: inline; filename="' . $safe . '.pdf"');
    header('Content-Length: ' . filesize($abs));
    readfile($abs);
    exit;
}

\MIS\Auth::respond(404, ['error' => 'unknown_action']);
