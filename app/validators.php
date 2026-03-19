<?php
declare(strict_types=1);

function sanitize_string(?string $value): string {
  return trim((string)$value);
}

function require_string(?string $value, string $field, int $min = 1, int $max = 255): string {
  $clean = sanitize_string($value);
  if ($clean === '' || strlen($clean) < $min || strlen($clean) > $max) {
    throw new InvalidArgumentException("{$field} must be between {$min} and {$max} characters.");
  }
  return $clean;
}

function require_enum(?string $value, array $allowed, string $field): string {
  $clean = strtoupper(require_string($value, $field));
  if (!in_array($clean, $allowed, true)) {
    throw new InvalidArgumentException("{$field} must be one of: " . implode(', ', $allowed));
  }
  return $clean;
}

function require_positive_float($value, string $field): float {
  if (!is_numeric($value)) {
    throw new InvalidArgumentException("{$field} must be numeric.");
  }
  $float = (float)$value;
  if ($float <= 0) {
    throw new InvalidArgumentException("{$field} must be greater than zero.");
  }
  return $float;
}

function optional_positive_float($value): ?float {
  if ($value === null || $value === '') {
    return null;
  }
  return require_positive_float($value, 'value');
}

function require_int_range($value, string $field, int $min, int $max): int {
  if (!is_numeric($value)) {
    throw new InvalidArgumentException("{$field} must be a valid number.");
  }
  $int = (int)$value;
  if ($int < $min || $int > $max) {
    throw new InvalidArgumentException("{$field} must be between {$min} and {$max}.");
  }
  return $int;
}

function require_float_range($value, string $field, float $min, float $max): float {
  if (!is_numeric($value)) {
    throw new InvalidArgumentException("{$field} must be numeric.");
  }
  $float = (float)$value;
  if ($float < $min || $float > $max) {
    throw new InvalidArgumentException("{$field} must be between {$min} and {$max}.");
  }
  return $float;
}

function sanitize_symbol(?string $value): string {
  $clean = strtoupper(preg_replace('/[^a-z0-9]/i', '', sanitize_string($value)));
  return $clean;
}
