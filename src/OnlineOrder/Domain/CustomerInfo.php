<?php

declare(strict_types=1);

namespace App\OnlineOrder\Domain;

/**
 * Delivery customer identity — the minimum Poster needs to attach a
 * client to an incoming order: a name and a reachable phone. Email is
 * optional.
 */
final class CustomerInfo
{
    public function __construct(
        public readonly string  $name,
        public readonly string  $phone,
        public readonly ?string $email = null,
    ) {}

    public static function fromInput(array $r): self
    {
        $email = trim((string)($r['email'] ?? ''));
        return new self(
            name:  trim((string)($r['name'] ?? '')),
            phone: self::normalizePhone((string)($r['phone'] ?? '')),
            email: $email !== '' ? $email : null,
        );
    }

    public function isValid(): bool
    {
        return $this->name !== '' && self::phoneIsPlausible($this->phone);
    }

    public function firstName(): string
    {
        $parts = preg_split('/\s+/', trim($this->name)) ?: [];
        return (string)($parts[0] ?? $this->name);
    }

    public function lastName(): string
    {
        $parts = preg_split('/\s+/', trim($this->name)) ?: [];
        if (count($parts) < 2) return '';
        return implode(' ', array_slice($parts, 1));
    }

    /**
     * Keep a leading "+" and digits only. Vietnamese local numbers
     * written as 0XXXXXXXXX are promoted to +84XXXXXXXXX so Poster /
     * couriers get an international-dialable number.
     */
    public static function normalizePhone(string $raw): string
    {
        $raw = trim($raw);
        $plus = str_starts_with($raw, '+');
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if ($digits === '') return '';
        if (!$plus && str_starts_with($digits, '0') && strlen($digits) >= 9 && strlen($digits) <= 11) {
            $digits = '84' . substr($digits, 1);
            return '+' . $digits;
        }
        return ($plus ? '+' : '') . $digits;
    }

    public static function phoneIsPlausible(string $phone): bool
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        return strlen($digits) >= 8 && strlen($digits) <= 15;
    }
}
