<?php
/**
 * Studio Index API
 * -----------------
 * Two modes, one query:
 *
 * Unpaged (signlab_background-fix relies on this shape; do not change it):
 *   GET api.php?date=20260331        -> JSON array of videos for that date
 *   GET api.php?date=2026-03-31      -> same (dashed format also accepted)
 *   GET api.php                      -> 400 { "error": ..., "available_dates": [...] }
 *
 * Paged (index.html):
 *   GET api.php?page=N[&date=...]    -> { "videos": [...], "pagination": {currentPage, totalPages}, "dates": [...] }
 *                                        100 per page; no date = all dates.
 *
 * Each video object:
 *   {
 *     "id": 123,
 *     "m_transcription": "...",
 *     "glos": "...",
 *     "date": "20260331",              (unpaged mode only)
 *     "files": [
 *       { "post": "https://.../post/...mp4",
 *         "raw":  "https://.../raw/...mp4",
 *         "filename": "M20260331....mp4" },
 *       ...
 *     ]
 *   }
 */

include('../mysql_config.php');
header('Content-Type: application/json');

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

$conn = new mysqli($servername, $username, $password, $database);
if ($conn->connect_error) {
    http_response_code(500);
    die(json_encode(["error" => "Connection failed: " . $conn->connect_error]));
}

function fail($conn, $msg, $stmt = null) {
    http_response_code(500);
    echo json_encode(["error" => $msg]);
    if ($stmt) $stmt->close();
    $conn->close();
    exit;
}

// Distinct 8-digit dates found inside m_file (M20260331_1234.wav -> 20260331).
function availableDates($conn, $skipEmpty = true) {
    $dateResult = $conn->query("SELECT DISTINCT SUBSTRING(m.m_file, 2, 8) as date
                                FROM matched_transcriptions m
                                WHERE m.added = 1
                                ORDER BY date DESC");
    $dates = [];
    if ($dateResult) {
        while ($dateRow = $dateResult->fetch_assoc()) {
            if (!$skipEmpty || !empty($dateRow['date'])) {
                $dates[] = $dateRow['date'];
            }
        }
    }
    return $dates;
}

// Turn matched_transcriptions rows into video objects (gloss lookup + media URLs).
function buildVideos($conn, $result, $date = null) {
    $videos = [];
    if (!$result || $result->num_rows === 0) {
        return $videos;
    }

    $glosStmt   = $conn->prepare("SELECT glos FROM form_data WHERE id = ?");
    $nmmStmt    = $conn->prepare("SELECT glos FROM nmm_data WHERE id = ?");
    $zinStmt    = $conn->prepare("SELECT zinString FROM sentences WHERE id = ?");
    $externStmt = $conn->prepare("SELECT glos FROM form_data WHERE id = ? AND extern='1'");
    if (!$glosStmt || !$nmmStmt || !$zinStmt || !$externStmt) {
        fail($conn, "Failed to prepare glos queries");
    }

    // Which table holds the label for each zOg type ('labels' and 'all sorts' use form_data).
    $stmtFor = [
        'Glos' => $glosStmt, 'glos' => $glosStmt, 'labels' => $glosStmt, 'all sorts' => $glosStmt,
        'nmm' => $nmmStmt, 'Zin' => $zinStmt, 'extern' => $externStmt,
    ];

    while ($row = $result->fetch_assoc()) {
        $videoData = [
            'id'              => (int)$row['id'],
            'm_transcription' => htmlspecialchars($row['m_transcription']),
            'glos'            => '',
        ];
        if ($date !== null) {
            $videoData['date'] = $date;
        }
        $videoData['files'] = [];

        $type = $row['zOg'];
        $currentStmt = $stmtFor[$type] ?? null;
        if ($currentStmt) {
            $m_transcription_id = $row['m_transcription'];
            $currentStmt->bind_param('s', $m_transcription_id);
            if ($currentStmt->execute()) {
                $glosResult = $currentStmt->get_result();
                if ($glosResult && $glosResult->num_rows > 0) {
                    $glosRow = $glosResult->fetch_assoc();
                    $col = ($type == 'Zin') ? 'zinString' : 'glos';
                    $glosValue = isset($glosRow[$col]) ? htmlspecialchars($glosRow[$col]) : '';
                    $videoData['glos'] = $glosValue . "   " . htmlspecialchars($type);
                }
            }
        }

        foreach (['l_file', 'm_file', 'r_file', 'a_file', 'b_file'] as $file) {
            if (!empty($row[$file])) {
                $filePath = str_replace('.wav', '.mp4', $row[$file]);
                $videoData['files'][] = [
                    'post'     => "https://signcollect.nl/gebarenoverleg_media/studioFilesMini/post/" . $filePath,
                    'raw'      => "https://signcollect.nl/gebarenoverleg_media/studioFilesMini/raw/" . $filePath,
                    'filename' => $filePath
                ];
            }
        }

        $videos[] = $videoData;
    }

    $glosStmt->close();
    $nmmStmt->close();
    $zinStmt->close();
    $externStmt->close();
    return $videos;
}

$select = "SELECT m.id, m.m_transcription, m.zOg, m.l_file, m.m_file, m.r_file, m.a_file, m.b_file
           FROM matched_transcriptions m";

// ---- Paged mode (index.html) ------------------------------------------------
if (isset($_GET['page'])) {
    $perPage = 100;
    $page = (int)$_GET['page'];
    $offset = ($page - 1) * $perPage;
    $dateFilter = isset($_GET['date']) ? $_GET['date'] : '';

    $whereClause = "WHERE m.added = 1";
    $params = [];
    $types = "";
    if (!empty($dateFilter)) {
        $whereClause .= " AND m.m_file LIKE ?";
        $params[] = "%$dateFilter%";
        $types .= "s";
    }

    $stmt = $conn->prepare("$select $whereClause ORDER BY m.id ASC LIMIT ? OFFSET ?");
    if (!$stmt) fail($conn, "Failed to prepare main query");
    $mainParams = array_merge($params, [$perPage, $offset]);
    $stmt->bind_param($types . "ii", ...$mainParams);
    if (!$stmt->execute()) fail($conn, "Failed to execute main query", $stmt);
    $result = $stmt->get_result();
    $stmt->close();

    $totalStmt = $conn->prepare("SELECT COUNT(*) as total FROM matched_transcriptions m $whereClause");
    if (!$totalStmt) fail($conn, "Failed to prepare count query");
    if (!empty($params)) {
        $totalStmt->bind_param($types, ...$params);
    }
    if (!$totalStmt->execute()) fail($conn, "Failed to execute count query", $totalStmt);
    $totalRows = $totalStmt->get_result()->fetch_assoc()['total'];
    $totalStmt->close();

    echo json_encode([
        'videos' => buildVideos($conn, $result),
        'pagination' => [
            'currentPage' => $page,
            'totalPages' => ceil($totalRows / $perPage)
        ],
        'dates' => availableDates($conn, false)
    ]);
    $conn->close();
    exit;
}

// ---- Unpaged mode (signlab_background-fix) -----------------------------
// Normalise the date filter to the 8-digit YYYYMMDD form used inside m_file.
$rawDate = isset($_GET['date']) ? trim($_GET['date']) : '';
$dateFilter = preg_replace('/\D/', '', $rawDate);

if ($dateFilter === '') {
    http_response_code(400);
    echo json_encode([
        "error" => "No date provided. Pass ?date=YYYYMMDD",
        "available_dates" => availableDates($conn)
    ]);
    $conn->close();
    exit;
}

$stmt = $conn->prepare("$select WHERE m.added = 1 AND m.m_file LIKE ? ORDER BY m.id ASC");
if (!$stmt) fail($conn, "Failed to prepare query");
$like = "%$dateFilter%";
$stmt->bind_param("s", $like);
if (!$stmt->execute()) fail($conn, "Failed to execute query", $stmt);
$result = $stmt->get_result();
$stmt->close();

echo json_encode(buildVideos($conn, $result, $dateFilter));
$conn->close();
