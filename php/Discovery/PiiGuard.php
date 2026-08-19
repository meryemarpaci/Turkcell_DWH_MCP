<?php

declare(strict_types=1);

namespace App\Discovery;

/**
 * Mask identity / PII fields before anything reaches LLM context.
 * Join keys may still be used in SQL; only tool payloads for the model are masked.
 */
final class PiiGuard
{
    private const PII_NAME = '/(msisdn|gsm|phone|telefon|tc_?kimlik|tckn|national_?id|email|e_?mail|address|adres|iban|credit_?card|kart_?no|password|sifre|ssn)/i';

    /** @param list<string> $allowedDomains Session-scoped domain allowlist; empty = all. */
    public function __construct(private readonly array $allowedDomains = [])
    {
    }

    public function isPiiColumn(string $column): bool
    {
        return (bool) preg_match(self::PII_NAME, $column);
    }

    /** @param list<mixed> $values */
    public function maskSamples(string $column, array $values): array
    {
        if (!$this->isPiiColumn($column)) {
            return array_slice($values, 0, 8);
        }
        $out = [];
        foreach (array_slice($values, 0, 8) as $v) {
            $out[] = $this->maskValue((string) $v);
        }
        return $out;
    }

    public function maskValue(string $v): string
    {
        $len = mb_strlen($v);
        if ($len <= 4) {
            return '****';
        }
        return mb_substr($v, 0, 2) . str_repeat('*', min(8, $len - 4)) . mb_substr($v, -2);
    }

    /** Domain ACL: empty allowlist = unrestricted. */
    public function domainAllowed(?string $domain): bool
    {
        if ($this->allowedDomains === []) {
            return true;
        }
        if ($domain === null || $domain === '') {
            return true;
        }
        return in_array(strtolower($domain), array_map('strtolower', $this->allowedDomains), true);
    }

    /**
     * Strip/mask PII keys from a row destined for LLM.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public function maskRow(array $row): array
    {
        $out = [];
        foreach ($row as $k => $v) {
            if ($this->isPiiColumn((string) $k) && is_scalar($v)) {
                $out[$k] = $this->maskValue((string) $v);
            } else {
                $out[$k] = $v;
            }
        }
        return $out;
    }
}
