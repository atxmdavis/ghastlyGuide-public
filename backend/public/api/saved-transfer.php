<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

function ghastly_saved_transfer_response(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}


try {
    require dirname(__DIR__, 2) . '/private/src/bootstrap.php';
    require dirname(__DIR__, 2) . '/private/src/saved-transfer.php';
    require dirname(__DIR__, 2) . '/private/src/rate-limit.php';
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? ''));

    if ($method === 'POST') {
        $raw = (string) file_get_contents('php://input', false, null, 0, 524289);
        if (strlen($raw) > 524288) {
            ghastly_saved_transfer_response(413, ['error' => 'That list of saves is too large to copy.']);
        }
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            ghastly_saved_transfer_response(400, ['error' => 'Send the saved titles from this device.']);
        }
        $address = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        if (!ghastly_rate_limit('saved-transfer', $address, 30, 600)) {
            header('Retry-After: 600');
            ghastly_saved_transfer_response(429, ['error' => 'Too many copy requests. Please try again in 10 minutes.']);
        }
        $database = ghastly_db();
        $database->exec("DELETE FROM saved_transfers WHERE expires_at <= UTC_TIMESTAMP()");
        $token = trim((string) ($payload['token'] ?? ''));
        if ($token !== '') {
            $hash = hash('sha256', $token);
            $normalized = ghastly_claim_saved_transfer($database, $hash);
            if ($normalized === null) ghastly_saved_transfer_response(410, ['error' => 'That copy link expired or was already used.']);
            ghastly_saved_transfer_response(200, ['saves' => $normalized]);
        }
        if (!ghastly_rate_limit('saved-transfer-create', $address, 10, 3600)) {
            header('Retry-After: 3600');
            ghastly_saved_transfer_response(429, ['error' => 'Too many copy links created. Please try again in an hour.']);
        }
        $normalized = ghastly_saved_transfer_normalize($payload['saves'] ?? null);
        if ($normalized === null) {
            ghastly_saved_transfer_response(400, ['error' => 'Save at least one title on this device first.']);
        }
        $token = bin2hex(random_bytes(24));
        $insert = $database->prepare(
            'INSERT INTO saved_transfers (token_hash, payload_json, expires_at) VALUES (:hash, :payload, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 10 MINUTE))'
        );
        $insert->execute([
            'hash' => hash('sha256', $token),
            'payload' => json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);
        ghastly_saved_transfer_response(201, ['token' => $token, 'expiresIn' => 600]);
    }

    header('Allow: POST');
    ghastly_saved_transfer_response(405, ['error' => 'Use POST to copy or claim saved titles.']);
} catch (Throwable $error) {
    error_log('Ghastly Guide saved transfer: ' . $error->getMessage());
    ghastly_saved_transfer_response(500, ['error' => 'Copying saves is temporarily unavailable.']);
}
