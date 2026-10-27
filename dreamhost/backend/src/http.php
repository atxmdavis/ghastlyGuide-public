<?php

declare(strict_types=1);

/** null means stop; never retry earlier than a valid server-requested delay. */
function ghastly_http_retry_delay(int $status, int $attempt, ?string $retryAfter, int $now): ?int
{
    if ($attempt >= 3 || !($status === 0 || $status === 429 || $status >= 500)) {
        return null;
    }
    $retryAfter = trim($retryAfter ?? '');
    $delay = null;
    if (preg_match('/^\d+$/D', $retryAfter)) {
        $delay = (float) $retryAfter;
    } elseif (preg_match('/^[A-Z][a-z]{2}, \d{2} [A-Z][a-z]{2} \d{4} \d{2}:\d{2}:\d{2} GMT$/D', $retryAfter)) {
        $timestamp = strtotime($retryAfter);
        if ($timestamp !== false) $delay = max(0, $timestamp - $now);
    }
    if ($delay !== null) {
        // Leave long cooldowns for the next run rather than retrying prematurely.
        return $delay > 60 ? null : max(1, (int) $delay);
    }
    return ($status === 429 ? 15 : 2) * (2 ** ($attempt - 1));
}

function ghastly_http_request(
    string $url,
    array $headers = [],
    string $method = 'GET',
    ?string $body = null,
    int $timeout = 30,
): string
{
    $config = ghastly_config();
    $method = strtoupper($method);
    $timeout = max(5, $timeout);
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $retryAfter = null;
        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('Unable to initialize HTTP client.');
        }
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => $config['user_agent'],
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$retryAfter): int {
                // Redirects and interim responses must not leak old retry headers.
                if (str_starts_with($line, 'HTTP/')) {
                    $retryAfter = null;
                } elseif (stripos($line, 'Retry-After:') === 0) {
                    $retryAfter = trim(substr($line, strlen('Retry-After:')));
                }
                return strlen($line);
            },
        ];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($handle, $options);
        $responseBody = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);
        if (is_string($responseBody) && $status >= 200 && $status < 300) {
            return $responseBody;
        }
        $delay = ghastly_http_retry_delay($status, $attempt, $retryAfter, time());
        if ($delay !== null) {
            sleep($delay);
            continue;
        }
        $detail = $error;
        if ($detail === '' && is_string($responseBody)) {
            $detail = trim(preg_replace('/\s+/', ' ', strip_tags($responseBody)) ?? '');
            if (mb_strlen($detail) > 160) {
                $detail = mb_substr($detail, 0, 157) . '...';
            }
        }
        throw new RuntimeException(sprintf('Source request failed with HTTP %d after %d attempt(s)%s.', $status, $attempt, $detail !== '' ? ": {$detail}" : ''));
    }
    throw new RuntimeException('Source request failed after retries.');
}

function ghastly_http_json(
    string $url,
    array $headers = [],
    string $method = 'GET',
    ?string $body = null,
    int $timeout = 30,
): array
{
    $responseBody = ghastly_http_request(
        $url,
        array_merge(['Accept: application/json'], $headers),
        $method,
        $body,
        $timeout,
    );
    $decoded = json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        throw new RuntimeException('Source returned an unexpected JSON response.');
    }
    return $decoded;
}

function ghastly_http_html(string $url): string
{
    return ghastly_http_request($url, [
        'Accept: text/html,application/xhtml+xml',
    ]);
}

/** Decode one object at a time instead of expanding a large JSON array in memory. */
function ghastly_json_object_items(string $json): Generator
{
    $length = strlen($json);
    $offset = strspn($json, " \t\r\n");
    if (($json[$offset++] ?? '') !== '[') {
        throw new RuntimeException('Source must return a JSON array of objects.');
    }
    $offset += strspn($json, " \t\r\n", $offset);
    if (($json[$offset] ?? '') !== ']') {
        while (true) {
            if (($json[$offset] ?? '') !== '{') {
                throw new RuntimeException('Source array contains an invalid object.');
            }
            $start = $offset;
            $depth = 0;
            $quoted = false;
            $escaped = false;
            for (; $offset < $length; $offset++) {
                $character = $json[$offset];
                if ($quoted) {
                    if ($escaped) {
                        $escaped = false;
                    } elseif ($character === '\\') {
                        $escaped = true;
                    } elseif ($character === '"') {
                        $quoted = false;
                    }
                } elseif ($character === '"') {
                    $quoted = true;
                } elseif ($character === '{') {
                    $depth++;
                } elseif ($character === '}' && --$depth === 0) {
                    $offset++;
                    break;
                }
            }
            if ($depth !== 0 || $quoted) {
                throw new RuntimeException('Source array contains a truncated object.');
            }
            yield json_decode(substr($json, $start, $offset - $start), true, 512, JSON_THROW_ON_ERROR);
            $offset += strspn($json, " \t\r\n", $offset);
            if (($json[$offset] ?? '') === ']') {
                break;
            }
            if (($json[$offset++] ?? '') !== ',') {
                throw new RuntimeException('Source array is missing an object separator.');
            }
            $offset += strspn($json, " \t\r\n", $offset);
        }
    }
    $offset++;
    $offset += strspn($json, " \t\r\n", $offset);
    if ($offset !== $length) {
        throw new RuntimeException('Source array has trailing or missing data.');
    }
}
