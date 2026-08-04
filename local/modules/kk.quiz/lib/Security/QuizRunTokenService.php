<?php

declare(strict_types=1);

namespace Kk\Quiz\Security;

use Bitrix\Main\Data\Cache;
use Bitrix\Main\Context;

final class QuizRunTokenService
{
    private const TTL = 7200;
    private const CACHE_DIR = '/kk.quiz/run_tokens';
    private const COOKIE = 'kk_quiz_visitor_id';

    public function issue(string $quizCode, string $runId = ''): array
    {
        $quizCode = $this->cleanCode($quizCode);
        $runId = $this->cleanRunId($runId) ?: $this->newToken(16);
        $token = $this->newToken(32);
        $data = ['quiz_code' => $quizCode, 'run_id' => $runId, 'used' => false, 'visitor' => $this->getVisitorId()];
        $this->save($token, $data);
        if (isset($_SESSION) && is_array($_SESSION)) {
            $_SESSION['kk_quiz_run_tokens'][$token] = $data + ['expires_at' => time() + self::TTL];
        }
        return ['run_id' => $runId, 'run_token' => $token, 'expires_in' => self::TTL];
    }

    public function validate(string $token, string $quizCode, string $runId = ''): bool
    {
        $token = $this->cleanToken($token);
        if ($token === '') return false;
        $data = $this->load($token);
        if ($data === []) return false;
        if (!empty($data['used'])) return false;
        if ((string)($data['quiz_code'] ?? '') !== $this->cleanCode($quizCode)) return false;
        if ($runId !== '' && (string)($data['run_id'] ?? '') !== $this->cleanRunId($runId)) return false;
        return true;
    }

    public function markUsed(string $token): void
    {
        $token = $this->cleanToken($token);
        if ($token === '') return;
        $data = $this->load($token);
        if ($data === []) return;
        $data['used'] = true;
        $this->save($token, $data);
        if (isset($_SESSION) && is_array($_SESSION) && isset($_SESSION['kk_quiz_run_tokens'][$token])) {
            $_SESSION['kk_quiz_run_tokens'][$token]['used'] = true;
        }
    }

    private function load(string $token): array
    {
        $session = isset($_SESSION) && is_array($_SESSION) ? ($_SESSION['kk_quiz_run_tokens'][$token] ?? null) : null;
        if (is_array($session) && (int)($session['expires_at'] ?? 0) >= time()) return $session;
        $cache = Cache::createInstance();
        if ($cache->initCache(self::TTL, $this->cacheKey($token), self::CACHE_DIR)) {
            $data = $cache->getVars();
            return is_array($data) ? $data : [];
        }
        return [];
    }

    private function save(string $token, array $data): void
    {
        $cache = Cache::createInstance();
        $cache->clean($this->cacheKey($token), self::CACHE_DIR);
        if ($cache->startDataCache(self::TTL, $this->cacheKey($token), self::CACHE_DIR)) $cache->endDataCache($data);
    }

    private function getVisitorId(): string
    {
        $request = Context::getCurrent()->getRequest();
        return substr(hash('sha256', (string)$request->getCookie(self::COOKIE) . '|' . (string)$request->getRemoteAddress()), 0, 32);
    }

    private function newToken(int $bytes): string { return bin2hex(random_bytes($bytes)); }
    private function cacheKey(string $token): string { return 'kk_quiz_run_' . md5($token); }
    private function cleanCode(string $value): string { return preg_replace('/[^a-zA-Z0-9_-]/', '', $value) ?? ''; }
    private function cleanRunId(string $value): string { return preg_match('/^[a-zA-Z0-9_-]{8,64}$/', $value) === 1 ? $value : ''; }
    private function cleanToken(string $value): string { return preg_match('/^[a-f0-9]{64}$/i', $value) === 1 ? strtolower($value) : ''; }
}
