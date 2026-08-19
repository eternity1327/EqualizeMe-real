<?php

require_once __DIR__ . '/../session.php';
require_once __DIR__ . '/../db.php';
start_secure_session();
header('Content-Type: application/json');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not logged in']);
    exit;
}

$user_id = $_SESSION['user_id'];
$profile1_id = $_GET['p1'] ?? null;
$profile2_id = $_GET['p2'] ?? null;

if (!$profile1_id || !$profile2_id) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing profile IDs (p1 and p2 required)']);
    exit;
}

try {
    $pdo = get_pdo();

    $stmt = $pdo->prepare(
        'SELECT id, bass_gain, treble_gain, presence_gain, confidence_score, created_at
         FROM auditory_profiles
         WHERE user_id = ? AND id IN (?, ?)'
    );
    $stmt->execute([$user_id, $profile1_id, $profile2_id]);
    $profiles = $stmt->fetchAll();

    if (count($profiles) !== 2) {
        http_response_code(404);
        echo json_encode(['error' => 'One or both profiles not found']);
        exit;
    }

    usort($profiles, function ($a, $b) {
        return strtotime($a['created_at']) <=> strtotime($b['created_at']);
    });

    $older = $profiles[0];
    $newer = $profiles[1];

    echo json_encode([
        'older' => [
            'id' => (int)$older['id'],
            'bass_gain' => (float)$older['bass_gain'],
            'presence_gain' => (float)$older['presence_gain'],
            'treble_gain' => (float)$older['treble_gain'],
            'confidence_score' => (float)$older['confidence_score'],
            'created_at' => $older['created_at'],
        ],
        'newer' => [
            'id' => (int)$newer['id'],
            'bass_gain' => (float)$newer['bass_gain'],
            'presence_gain' => (float)$newer['presence_gain'],
            'treble_gain' => (float)$newer['treble_gain'],
            'confidence_score' => (float)$newer['confidence_score'],
            'created_at' => $newer['created_at'],
        ],
        'changes' => [
            'bass_gain' => round((float)$newer['bass_gain'] - (float)$older['bass_gain'], 2),
            'presence_gain' => round((float)$newer['presence_gain'] - (float)$older['presence_gain'], 2),
            'treble_gain' => round((float)$newer['treble_gain'] - (float)$older['treble_gain'], 2),
            'confidence_change' => round((float)$newer['confidence_score'] - (float)$older['confidence_score'], 1),
        ],
    ]);
} catch (PDOException $e) {
    error_log('profile/compare.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Could not compare profiles']);
}
