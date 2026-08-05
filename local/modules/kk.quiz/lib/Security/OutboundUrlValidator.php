<?php

declare(strict_types=1);

namespace Kk\Quiz\Security;

final class OutboundUrlValidator
{
    public function normalizePublicHttpUrl(mixed $value, bool $httpsOnly = false): string
    {
        $url = is_scalar($value) ? trim((string)$value) : '';
        if ($url === '' || preg_match('/[\x00-\x1F\x7F]/', $url) === 1) return '';
        $parts = parse_url($url);
        if (!is_array($parts)) return '';
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true) || ($httpsOnly && $scheme !== 'https')) return '';
        if (isset($parts['user']) || isset($parts['pass'])) return '';
        $host = (string)($parts['host'] ?? '');
        if ($host === '' || !$this->isPublicHost($host)) return '';
        return $url;
    }

    public function isPublicHost(string $host): bool
    {
        $host = trim($host, "[] \t\n\r\0\x0B");
        if ($host === '' || preg_match('/[\x00-\x1F\x7F]/', $host) === 1) return false;
        $lower = strtolower(rtrim($host, '.'));
        if ($lower === 'localhost' || str_ends_with($lower, '.localhost')) return false;
        $ips = [];
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips[] = $host;
        } else {
            $records = @dns_get_record($lower, DNS_A + DNS_AAAA);
            if (!is_array($records) || $records === []) return false;
            foreach ($records as $record) {
                $ip = (string)($record['ip'] ?? $record['ipv6'] ?? '');
                if ($ip !== '') $ips[] = $ip;
            }
        }
        if ($ips === []) return false;
        foreach (array_unique($ips) as $ip) {
            if (!$this->isPublicIp($ip)) return false;
        }
        return true;
    }

    public function maskUrl(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts)) return '';
        $scheme = (string)($parts['scheme'] ?? '');
        $host = (string)($parts['host'] ?? '');
        if ($scheme === '' || $host === '') return '';
        $masked = $scheme . '://' . $host . (isset($parts['port']) ? ':' . (int)$parts['port'] : '') . (string)($parts['path'] ?? '');
        if (!empty($parts['query'])) {
            parse_str((string)$parts['query'], $query);
            foreach ($query as $key => $value) {
                if (preg_match('/(?:token|key|auth|signature|code)/i', (string)$key) === 1) $query[$key] = '***';
            }
            $masked .= '?' . http_build_query($query);
        }
        return $masked;
    }

    private function isPublicIp(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) return false;
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }
}
