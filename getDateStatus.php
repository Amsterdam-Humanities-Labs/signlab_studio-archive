<?php
include('../mysql_config.php');
header('Content-Type: application/json');

$date = isset($_GET['date']) ? $_GET['date'] : '';
if ($date === '') {
    echo json_encode(['error' => 'No date provided']);
    exit;
}

// Convert 20260331 to 2026-03-31
if (strlen($date) === 8 && ctype_digit($date)) {
    $dateDashed = substr($date, 0, 4) . '-' . substr($date, 4, 2) . '-' . substr($date, 6, 2);
} else {
    $dateDashed = $date;
}

$conn = new mysqli($servername, $username, $password, $database);
if ($conn->connect_error) {
    echo json_encode(['error' => 'DB connection failed']);
    exit;
}

// 1. Count CameraRecords for this date
$stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM CameraRecords WHERE stopTime LIKE CONCAT(?, '%')");
$stmt->bind_param('s', $dateDashed);
$stmt->execute();
$cameraCount = $stmt->get_result()->fetch_assoc()['cnt'];
$stmt->close();

// 2. Count matched_transcriptions + file presence
$stmt = $conn->prepare("
    SELECT
        COUNT(*) as total,
        SUM(CASE WHEN l_file IS NOT NULL AND l_file != '' THEN 1 ELSE 0 END) as l_present,
        SUM(CASE WHEN m_file IS NOT NULL AND m_file != '' THEN 1 ELSE 0 END) as m_present,
        SUM(CASE WHEN r_file IS NOT NULL AND r_file != '' THEN 1 ELSE 0 END) as r_present,
        SUM(CASE WHEN a_file IS NOT NULL AND a_file != '' THEN 1 ELSE 0 END) as a_present,
        SUM(CASE WHEN b_file IS NOT NULL AND b_file != '' THEN 1 ELSE 0 END) as b_present,
        SUM(CASE WHEN post_processed IS NOT NULL AND post_processed != '' THEN 1 ELSE 0 END) as post_processed
    FROM matched_transcriptions
    WHERE date = ?
");
$stmt->bind_param('s', $dateDashed);
$stmt->execute();
$mt = $stmt->get_result()->fetch_assoc();
$stmt->close();

$matchedCount = (int)$mt['total'];

// 3. Cross-reference: per (glosId, zOg) group, compare counts
$stmt = $conn->prepare("
    SELECT cr.glosId, cr.zOg, cr.cr_count, IFNULL(mt.mt_count, 0) as mt_count
    FROM (
        SELECT glosId, zOg, COUNT(*) as cr_count
        FROM CameraRecords
        WHERE stopTime LIKE CONCAT(?, '%')
        GROUP BY glosId, zOg
    ) cr
    LEFT JOIN (
        SELECT m_transcription, zOg, COUNT(*) as mt_count
        FROM matched_transcriptions
        WHERE date = ?
        GROUP BY m_transcription, zOg
    ) mt ON cr.glosId = mt.m_transcription AND LOWER(cr.zOg) = LOWER(mt.zOg)
");
$stmt->bind_param('ss', $dateDashed, $dateDashed);
$stmt->execute();
$result = $stmt->get_result();

$validated = 0;
$missing = 0;
$missingDetails = [];

while ($row = $result->fetch_assoc()) {
    $crCount = (int)$row['cr_count'];
    $mtCount = (int)$row['mt_count'];
    $matched = min($crCount, $mtCount);
    $validated += $matched;
    $gap = $crCount - $matched;
    if ($gap > 0) {
        $missing += $gap;
        $missingDetails[] = $row['glosId'] . ' (' . $row['zOg'] . ') — ' . $gap . ' missing';
    }
}
$stmt->close();
$conn->close();

echo json_encode([
    'camera_records' => $cameraCount,
    'matched' => $matchedCount,
    'validated' => $validated,
    'missing' => $missing,
    'missing_details' => $missingDetails,
    'post_processed' => (int)$mt['post_processed'],
    'files' => [
        'l_file' => ['present' => (int)$mt['l_present'], 'null' => $matchedCount - (int)$mt['l_present']],
        'm_file' => ['present' => (int)$mt['m_present'], 'null' => $matchedCount - (int)$mt['m_present']],
        'r_file' => ['present' => (int)$mt['r_present'], 'null' => $matchedCount - (int)$mt['r_present']],
        'a_file' => ['present' => (int)$mt['a_present'], 'null' => $matchedCount - (int)$mt['a_present']],
        'b_file' => ['present' => (int)$mt['b_present'], 'null' => $matchedCount - (int)$mt['b_present']],
    ]
]);
