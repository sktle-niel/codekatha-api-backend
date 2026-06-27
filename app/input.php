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

// Build a created_at filter from month (YYYY-MM) and day (1-31) query params.
// $col is a trusted, code-supplied column name (qualified for joins). Returns
// [sqlCondition, params] — condition is "1" (no filter) when month is missing.
function ckx_date_filter(string $month, string $day, string $col = 'created_at'): array
{
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
        return ['1', []];
    }
    if (preg_match('/^([1-9]|[12]\d|3[01])$/', $day)) {
        $date = $month . '-' . str_pad($day, 2, '0', STR_PAD_LEFT);
        return ["DATE($col) = ?", [$date]];
    }
    return ["DATE_FORMAT($col, '%Y-%m') = ?", [$month]];
}
