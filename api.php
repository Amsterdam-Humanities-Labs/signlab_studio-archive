<?php
/**
 * Studio Index API
 * -----------------
 * Returns the list of videos for a given date as a JSON array.
 *
 *   GET api.php?date=20260331        -> JSON array of videos for that date
 *   GET api.php?date=2026-03-31      -> same (dashed format also accepted)
 *   GET api.php                      -> { "error": ..., "available_dates": [...] }
 *
 * Each video object:
 *   {
 *     "id": 123,
 *     "m_transcription": "...",
 *     "glos": "...",
 *     "date": "20260331",
 *     "files": [
 *       { "filename": "M20260331....mp4",
 *         "post": "https://.../post/...mp4",
 *         "raw":  "https://.../raw/...mp4" },
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

// Normalise the date filter to the 8-digit YYYYMMDD form used inside m_file.
$rawDate = isset($_GET['date']) ? trim($_GET['date']) : '';
$dateFilter = preg_replace('/\D/', '', $rawDate); // strip dashes/slashes -> digits only

// No date supplied: return the list of available dates so callers can discover them.
if ($dateFilter === '') {
    $dateSql = "SELECT DISTINCT SUBSTRING(m.m_file, 2, 8) as date
                FROM matched_transcriptions m
                WHERE m.added = 1
                ORDER BY date DESC";
    $dateResult = $conn->query($dateSql);
    $dates = [];
    if ($dateResult) {
        while ($dateRow = $dateResult->fetch_assoc()) {
            if (!empty($dateRow['date'])) {
                $dates[] = $dateRow['date'];
            }
        }
    }
    http_response_code(400);
    echo json_encode([
        "error" => "No date provided. Pass ?date=YYYYMMDD",
        "available_dates" => $dates
    ]);
    $conn->close();
    exit;
}

// Fetch all matched rows for the date (no pagination — full list).
$sql = "SELECT m.id, m.m_transcription, m.zOg, m.l_file, m.m_file, m.r_file, m.a_file, m.b_file
        FROM matched_transcriptions m
        WHERE m.added = 1 AND m.m_file LIKE ?
        ORDER BY m.id ASC";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    echo json_encode(["error" => "Failed to prepare query"]);
    $conn->close();
    exit;
}

$like = "%$dateFilter%";
$stmt->bind_param("s", $like);

if (!$stmt->execute()) {
    http_response_code(500);
    echo json_encode(["error" => "Failed to execute query"]);
    $stmt->close();
    $conn->close();
    exit;
}

$result = $stmt->get_result();
$stmt->close();

$videos = [];

if ($result && $result->num_rows > 0) {
    // Prepared statements for glos lookups (reused per row for performance).
    $glosStmt   = $conn->prepare("SELECT glos FROM form_data WHERE id = ?");
    $nmmStmt    = $conn->prepare("SELECT glos FROM nmm_data WHERE id = ?");
    $zinStmt    = $conn->prepare("SELECT zinString FROM sentences WHERE id = ?");
    $externStmt = $conn->prepare("SELECT glos FROM form_data WHERE id = ? AND extern='1'");

    if (!$glosStmt || !$nmmStmt || !$zinStmt || !$externStmt) {
        http_response_code(500);
        echo json_encode(["error" => "Failed to prepare glos queries"]);
        $conn->close();
        exit;
    }

    while ($row = $result->fetch_assoc()) {
        $videoData = [
            'id'              => (int)$row['id'],
            'm_transcription' => htmlspecialchars($row['m_transcription']),
            'glos'            => '',
            'date'            => $dateFilter,
            'files'           => []
        ];

        // Pick the right glos source based on the record type.
        $type = $row['zOg'];
        $m_transcription_id = $row['m_transcription'];
        $currentStmt = null;

        if ($type == 'Glos' || $type == 'glos') {
            $currentStmt = $glosStmt;
        } elseif ($type == 'labels') {
            $currentStmt = $glosStmt;
        } elseif ($type == 'nmm') {
            $currentStmt = $nmmStmt;
        } elseif ($type == 'Zin') {
            $currentStmt = $zinStmt;
        } elseif ($type == 'extern') {
            $currentStmt = $externStmt;
        } elseif ($type == 'all sorts') {
            $currentStmt = $glosStmt;
        }

        if ($currentStmt) {
            $currentStmt->bind_param('s', $m_transcription_id);
            if ($currentStmt->execute()) {
                $glosResult = $currentStmt->get_result();
                if ($glosResult && $glosResult->num_rows > 0) {
                    $glosRow = $glosResult->fetch_assoc();
                    if ($type == 'Zin') {
                        $glosValue = isset($glosRow['zinString']) ? htmlspecialchars($glosRow['zinString']) : '';
                    } else {
                        $glosValue = isset($glosRow['glos']) ? htmlspecialchars($glosRow['glos']) : '';
                    }
                    $videoData['glos'] = $glosValue . "   " . htmlspecialchars($type);
                }
            }
        }

        // Build post/raw URLs for each present camera file.
        $files = ['l_file', 'm_file', 'r_file', 'a_file', 'b_file'];
        foreach ($files as $file) {
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
}

// Success: bare JSON array of videos for the requested date.
echo json_encode($videos);

$conn->close();
?>
