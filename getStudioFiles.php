<?php
include('../mysql_config.php');
header('Content-Type: application/json');

// Enable error reporting for debugging (consider disabling in production)
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

$conn = new mysqli($servername, $username, $password, $database);

// Check for connection errors
if ($conn->connect_error) {
    http_response_code(500);
    die(json_encode(["error" => "Connection failed: " . $conn->connect_error]));
}

$perPage = 100;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $perPage;
$dateFilter = isset($_GET['date']) ? $_GET['date'] : '';

// Build WHERE clause with prepared statement parameters
$whereClause = "WHERE m.added = 1";
$params = [];
$types = "";

if (!empty($dateFilter)) {
    $whereClause .= " AND m.m_file LIKE ?";
    $params[] = "%$dateFilter%";
    $types .= "s";
}

// Main query with prepared statement
$sql = "SELECT m.id, m.m_transcription, m.zOg, m.l_file, m.m_file, m.r_file, m.a_file, m.b_file
        FROM matched_transcriptions m
        $whereClause
        ORDER BY m.id ASC
        LIMIT ? OFFSET ?";

$params[] = $perPage;
$params[] = $offset;
$types .= "ii";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    echo json_encode(["error" => "Failed to prepare main query"]);
    $conn->close();
    exit;
}

// Bind parameters dynamically
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

if (!$stmt->execute()) {
    http_response_code(500);
    echo json_encode(["error" => "Failed to execute main query"]);
    $stmt->close();
    $conn->close();
    exit;
}

$result = $stmt->get_result();
$stmt->close();

// Get total rows for pagination with prepared statement
$totalSql = "SELECT COUNT(*) as total FROM matched_transcriptions m $whereClause";
$totalStmt = $conn->prepare($totalSql);
if (!$totalStmt) {
    http_response_code(500);
    echo json_encode(["error" => "Failed to prepare count query"]);
    $conn->close();
    exit;
}

// Bind parameters for count query
if (!empty($dateFilter)) {
    $dateFilterParam = "%$dateFilter%";
    $totalStmt->bind_param("s", $dateFilterParam);
}

if (!$totalStmt->execute()) {
    http_response_code(500);
    echo json_encode(["error" => "Failed to execute count query"]);
    $totalStmt->close();
    $conn->close();
    exit;
}

$totalResult = $totalStmt->get_result();
$totalRow = $totalResult->fetch_assoc();
$totalRows = $totalRow['total'];
$totalPages = ceil($totalRows / $perPage);
$totalStmt->close();

// Get unique dates for the select menu
$dateSql = "SELECT DISTINCT SUBSTRING(m.m_file, 2, 8) as date FROM matched_transcriptions m WHERE m.added = 1 ORDER BY date DESC";
$dateResult = $conn->query($dateSql);

$dates = [];
if ($dateResult && $dateResult->num_rows > 0) {
    while ($dateRow = $dateResult->fetch_assoc()) {
        $dates[] = $dateRow['date'];
    }
}

$response = [
    'videos' => [],
    'pagination' => [
        'currentPage' => $page,
        'totalPages' => $totalPages
    ],
    'dates' => $dates
];

if ($result->num_rows > 0) {
    // Prepare statements for glos queries (reuse for performance)
    $glosStmt = $conn->prepare("SELECT glos FROM form_data WHERE id = ?");
    $nmmStmt = $conn->prepare("SELECT glos FROM nmm_data WHERE id = ?");
    $zinStmt = $conn->prepare("SELECT zinString FROM sentences WHERE id = ?");
    $externStmt = $conn->prepare("SELECT glos FROM form_data WHERE id = ? AND extern='1'");

    if (!$glosStmt || !$nmmStmt || !$zinStmt || !$externStmt) {
        http_response_code(500);
        echo json_encode(["error" => "Failed to prepare glos queries"]);
        $conn->close();
        exit;
    }

    while ($row = $result->fetch_assoc()) {
        $videoData = [
            'id' => (int)$row['id'],
            'm_transcription' => htmlspecialchars($row['m_transcription']),
            'glos' => '',
            'files' => []
        ];

        // Determine the type and fetch 'glos' accordingly
        $type = $row['zOg'];
        $m_transcription_id = $row['m_transcription'];

        $glosValue = '';
        $currentStmt = null;

        if ($type == 'Glos' || $type == 'glos') {
            $currentStmt = $glosStmt;
        } elseif ($type == 'labels') {
            $currentStmt = $glosStmt;  // Use form_data for 'labels'
        } elseif ($type == 'nmm') {
            $currentStmt = $nmmStmt;
        } elseif ($type == 'Zin') {
            $currentStmt = $zinStmt;
        } elseif ($type == 'extern') {
            $currentStmt = $externStmt;
        } elseif ($type == 'all sorts') {
            $currentStmt = $glosStmt;  // Use form_data for 'all sorts'
        }

        if ($currentStmt) {
            $currentStmt->bind_param('s', $m_transcription_id);

            if ($currentStmt->execute()) {
                $glosResult = $currentStmt->get_result();

                if ($glosResult && $glosResult->num_rows > 0) {
                    $glosRow = $glosResult->fetch_assoc();

                    if ($type == 'Zin') {
                        // Use zinString instead of zinArray
                        $glosValue = isset($glosRow['zinString']) ? htmlspecialchars($glosRow['zinString']) : '';
                    } else {
                        $glosValue = isset($glosRow['glos']) ? htmlspecialchars($glosRow['glos']) : '';
                    }

                    $videoData['glos'] = $glosValue . "   " . htmlspecialchars($type);
                }
            }
        }

        // Process files - provide both post and raw URLs for fallback
        $files = ['l_file', 'm_file', 'r_file', 'a_file', 'b_file'];
        foreach ($files as $file) {
            if (!empty($row[$file])) {
                $filePath = str_replace('.wav', '.mp4', $row[$file]);
                $videoData['files'][] = [
                    'post' => "https://signcollect.nl/gebarenoverleg_media/studioFilesMini/post/" . $filePath,
                    'raw' => "https://signcollect.nl/gebarenoverleg_media/studioFilesMini/raw/" . $filePath,
                    'filename' => $filePath
                ];
            }
        }

        $response['videos'][] = $videoData;
    }

    // Close prepared statements
    $glosStmt->close();
    $nmmStmt->close();
    $zinStmt->close();
    $externStmt->close();
}

echo json_encode($response);

$conn->close();
?>
