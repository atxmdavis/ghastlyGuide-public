<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
require dirname(__DIR__, 2) . '/private/src/contact.php';

function ghastly_contact_response(int $status, string $message): never
{
    http_response_code($status);
    echo json_encode(['message' => $message], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}

function ghastly_contact_rate_limit(string $clientAddress): bool
{
    $path = dirname(__DIR__, 2) . '/private/data/contact-rate-limits.json';
    $handle = fopen($path, 'c+');
    if ($handle === false || !flock($handle, LOCK_EX)) {
        if (is_resource($handle)) fclose($handle);
        return false;
    }
    $contents = stream_get_contents($handle);
    $records = json_decode(is_string($contents) ? $contents : '', true);
    $records = is_array($records) ? $records : [];
    $now = time();
    $cutoff = $now - 3600;
    $key = hash('sha256', 'ghastly-guide-contact-v1|' . $clientAddress);
    foreach ($records as $recordKey => $timestamps) {
        if (!is_array($timestamps)) {
            unset($records[$recordKey]);
            continue;
        }
        $records[$recordKey] = array_values(array_filter(
            $timestamps,
            static fn (mixed $timestamp): bool => is_int($timestamp) && $timestamp >= $cutoff,
        ));
        if ($records[$recordKey] === []) unset($records[$recordKey]);
    }
    $allowed = count($records[$key] ?? []) < 5;
    if ($allowed) $records[$key][] = $now;
    rewind($handle);
    ftruncate($handle, 0);
    fwrite($handle, json_encode($records, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
    return $allowed;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    ghastly_contact_response(405, 'Use the contact form to send a message.');
}

$contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentLength <= 0 || $contentLength > 12000) {
    ghastly_contact_response(413, 'The message is too large.');
}

$host = strtolower(preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')) ?? '');
$allowedOrigins = ['https://ghastlyguide.com', 'https://www.ghastlyguide.com'];
if (in_array($host, ['localhost', '127.0.0.1'], true)) {
    $allowedOrigins[] = 'http://localhost:5173';
    $allowedOrigins[] = 'http://127.0.0.1:5173';
}
$origin = rtrim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''), '/');
if (!in_array($origin, $allowedOrigins, true)) {
    ghastly_contact_response(403, 'This submission could not be verified.');
}

$payload = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($payload)) {
    ghastly_contact_response(400, 'Enter your name, email, and message.');
}

$honeypot = trim((string) ($payload['website'] ?? ''));
if ($honeypot !== '') {
    ghastly_contact_response(200, 'Your message has been sent.');
}
$validation = ghastly_contact_validation($payload);
if ($validation !== null) ghastly_contact_response(422, $validation);

$name = trim((string) ($payload['name'] ?? ''));
$email = trim((string) ($payload['email'] ?? ''));
$message = trim((string) ($payload['message'] ?? ''));
if (!ghastly_contact_rate_limit((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'))) {
    ghastly_contact_response(429, 'Too many messages have been sent. Please try again later.');
}

$recipient = 'info@ghastlyguide.com';
$sender = 'info@ghastlyguide.com';
$subject = 'Ghastly Guide contact form';
$body = "Name: {$name}\r\nEmail: {$email}\r\n\r\nMessage:\r\n{$message}\r\n";
$headers = [
    'From: Ghastly Guide <' . $sender . '>',
    'Reply-To: ' . $email,
    'MIME-Version: 1.0',
    'Content-Type: text/plain; charset=UTF-8',
    'X-Mailer: Ghastly Guide Contact Form',
];

if (!mail($recipient, $subject, $body, implode("\r\n", $headers), '-f' . $sender)) {
    error_log('Ghastly Guide contact form: mail delivery was rejected.');
    ghastly_contact_response(500, 'Your message could not be sent. Please email info@ghastlyguide.com directly.');
}

ghastly_contact_response(200, 'Your message has been sent.');
