<?php
header('Content-Type: application/json');

$cacheFile = '/home/gomer/mailChecker/status_cache.json';

clearstatcache(true, $cacheFile);

if (!file_exists($cacheFile)) {
    echo json_encode(['error' => 'Status cache not found']);
    exit;
}

$contents = file_get_contents($cacheFile);
$data = json_decode($contents, true);
if ($data === null) {
    echo json_encode(['error' => 'Invalid JSON in status cache']);
    exit;
}

$date = isset($_GET['date']) ? $_GET['date'] : '';

// Convert 20260320 to 2026-03-20 format to match JSON keys
if (strlen($date) === 8 && ctype_digit($date)) {
    $date = substr($date, 0, 4) . '-' . substr($date, 4, 2) . '-' . substr($date, 6, 2);
}

if ($date === '' || !isset($data['dates'][$date])) {
    echo json_encode(['error' => 'Date not found']);
    exit;
}

$entry = $data['dates'][$date];
echo json_encode([
    'cameras' => $entry['cameras'],
    'matched' => $entry['matched'],
    'post_processed' => $entry['post_processed']
]);
