<?php

declare(strict_types=1);

function ghastly_contact_validation(array $payload): ?string
{
    foreach (['name', 'email', 'message'] as $key) {
        if (!is_string($payload[$key] ?? null)) return 'Enter your name, email, and message.';
    }
    $name = trim($payload['name']);
    $email = trim($payload['email']);
    $message = trim($payload['message']);
    if ($name === '' || mb_strlen($name) > 100 || preg_match('/[\r\n]/', $name)) return 'Enter a valid name.';
    if (mb_strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $email)) return 'Enter a valid email address.';
    if (mb_strlen($message) < 10 || mb_strlen($message) > 5000) return 'Enter a message between 10 and 5,000 characters.';
    return null;
}
