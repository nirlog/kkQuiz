<?php

declare(strict_types=1);

namespace Kk\Quiz\Service;

final class LeadPayloadBuilder
{
    public function build(array $leadData): array
    {
        return [
            'event' => 'kk_quiz_lead_created',
            'module' => 'kk.quiz',
            'version' => 1,
            'created_at' => date('c'),
            'lead' => [
                'id' => $this->toInt($leadData['id'] ?? 0),
                'name' => $this->toString($leadData['name'] ?? ''),
                'created_at' => $this->toString($leadData['created_at'] ?? date('c')),
                'status' => $this->toString($leadData['status'] ?? ''),
                'quiz' => [
                    'section_id' => $this->toInt($leadData['quiz_section_id'] ?? 0),
                    'code' => $this->toString($leadData['quiz_code'] ?? ''),
                    'name' => $this->toString($leadData['quiz_name'] ?? ''),
                ],
                'result' => [
                    'id' => $this->toInt($leadData['result_id'] ?? 0),
                    'code' => $this->toString($leadData['result_code'] ?? ''),
                    'title' => $this->toString($leadData['result_title'] ?? ''),
                ],
                'client' => [
                    'name' => $this->toString($leadData['client_name'] ?? ''),
                    'phone' => $this->toString($leadData['client_phone'] ?? ''),
                    'email' => $this->toString($leadData['client_email'] ?? ''),
                    'messenger' => $this->toString($leadData['client_messenger'] ?? ''),
                    'comment' => $this->toString($leadData['client_comment'] ?? ''),
                ],
                'answers' => $this->buildAnswers((string)($leadData['detail_text'] ?? '')),
                'answers_text' => $this->toString($leadData['detail_text'] ?? ''),
                'page' => [
                    'url' => $this->toString($leadData['page_url'] ?? ''),
                    'referer' => $this->toString($leadData['referer'] ?? ''),
                ],
                'utm' => [
                    'source' => $this->toString($leadData['utm_source'] ?? ''),
                    'medium' => $this->toString($leadData['utm_medium'] ?? ''),
                    'campaign' => $this->toString($leadData['utm_campaign'] ?? ''),
                    'content' => $this->toString($leadData['utm_content'] ?? ''),
                    'term' => $this->toString($leadData['utm_term'] ?? ''),
                ],
                'technical' => [
                    'user_agent' => $this->toString($leadData['user_agent'] ?? ''),
                    'ip' => $this->toString($leadData['ip'] ?? ''),
                    'session_id' => $this->toString($leadData['session_id'] ?? ''),
                ],
            ],
        ];
    }

    private function buildAnswers(string $answersText): array
    {
        $answersText = trim($answersText);
        if ($answersText === '') {
            return [];
        }

        $items = [];
        foreach (preg_split('/\n{2,}/u', $answersText) ?: [] as $block) {
            $lines = array_values(array_filter(array_map('trim', preg_split('/\n/u', (string)$block) ?: []), static fn(string $line): bool => $line !== ''));
            if ($lines === []) {
                continue;
            }

            $question = array_shift($lines);
            $answer = trim(preg_replace('/^Ответы?:\s*/u', '', implode("\n", $lines)) ?? '');
            $items[] = [
                'question' => $this->toString($question),
                'answer' => $this->toString($answer),
            ];
        }

        return $items;
    }

    private function toString(mixed $value): string
    {
        if (!is_scalar($value)) {
            return '';
        }

        $value = trim((string)$value);

        return function_exists('mb_substr') ? (string)mb_substr($value, 0, 10000) : substr($value, 0, 10000);
    }

    private function toInt(mixed $value): int
    {
        return is_numeric($value) ? max(0, (int)$value) : 0;
    }
}
