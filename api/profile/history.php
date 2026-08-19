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

try {
    $pdo = get_pdo();

    $stmt = $pdo->prepare(
        'SELECT id, bass_gain, treble_gain, presence_gain, confidence_score, created_at
         FROM auditory_profiles
         WHERE user_id = ?
         ORDER BY created_at DESC'
    );
    $stmt->execute([$user_id]);
    $profiles = $stmt->fetchAll();

    echo json_encode([
        'user_id' => (int)$user_id,
        'profiles' => array_map(function ($p) {
            return [
                'id' => (int)$p['id'],
                'bass_gain' => (float)$p['bass_gain'],
                'presence_gain' => (float)$p['presence_gain'],
                'treble_gain' => (float)$p['treble_gain'],
                'confidence_score' => (float)$p['confidence_score'],
                'created_at' => $p['created_at'],
            ];
        }, $profiles),
    ]);
} catch (PDOException $e) {
    error_log('profile/history.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Could not fetch profile history']);
}
