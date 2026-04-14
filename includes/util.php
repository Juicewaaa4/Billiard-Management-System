<?php
declare(strict_types=1);

function flash_set(string $type, string $message): void
{
  $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function flash_get(): ?array
{
  if (empty($_SESSION['flash'])) return null;
  $f = $_SESSION['flash'];
  unset($_SESSION['flash']);
  return $f;
}

function money(float $v): string
{
  return '₱' . number_format($v, 2);
}

function parse_date(string $value): ?string
{
  $value = trim($value);
  if ($value === '') return null;
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return null;
  return $value;
}

