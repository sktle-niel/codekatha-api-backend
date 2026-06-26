<?php
// Small input helpers shared by every endpoint.

function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '';
}

// Strip control characters that have no business in form input.
function clean_text(string $s): string
{
    return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s);
}

// Read, trim, clean, and length-cap a single field from a decoded JSON body.
function field(array $body, string $key, int $max = 255): string
{
    return mb_substr(clean_text(trim((string) ($body[$key] ?? ''))), 0, $max);
}
