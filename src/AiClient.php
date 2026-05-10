<?php
declare(strict_types=1);

namespace MIS;

/**
 * Thin wrapper around the Anthropic Messages API.
 * Falls back to a deterministic stub response when no API key is configured.
 *
 * Uses prompt caching on the system prompt + airframe context block so that
 * repeated questions about the same fault don't re-bill those tokens.
 */
final class AiClient
{
    private string $apiKey;
    private string $model;
    private int $maxTokens;

    public function __construct(array $cfg)
    {
        $this->apiKey   = (string) ($cfg['api_key'] ?? '');
        $this->model    = (string) ($cfg['model'] ?? 'claude-opus-4-7');
        $this->maxTokens = (int) ($cfg['max_tokens'] ?? 1024);
    }

    public function isLive(): bool
    {
        return $this->apiKey !== '';
    }

    /**
     * @param array{role:string,content:string}[] $history User/assistant messages
     */
    public function ask(string $systemPrompt, string $airframeContext, array $history): array
    {
        if (!$this->isLive()) {
            return $this->stubResponse($history);
        }
        $payload = [
            'model'      => $this->model,
            'max_tokens' => $this->maxTokens,
            'system'     => [
                ['type' => 'text', 'text' => $systemPrompt,    'cache_control' => ['type' => 'ephemeral']],
                ['type' => 'text', 'text' => $airframeContext, 'cache_control' => ['type' => 'ephemeral']],
            ],
            'messages' => array_map(fn($m) => [
                'role' => $m['role'],
                'content' => $m['content'],
            ], $history),
        ];
        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: 2023-06-01',
            ],
            CURLOPT_TIMEOUT => 60,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            return ['error' => 'transport_error', 'detail' => $err];
        }
        $decoded = json_decode((string) $body, true);
        if ($code !== 200 || !is_array($decoded)) {
            return ['error' => 'api_error', 'status' => $code, 'detail' => $decoded ?? $body];
        }
        $text = '';
        foreach (($decoded['content'] ?? []) as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= $block['text'];
            }
        }
        return [
            'text'   => $text,
            'usage'  => $decoded['usage'] ?? null,
            'model'  => $decoded['model'] ?? $this->model,
            'stop'   => $decoded['stop_reason'] ?? null,
        ];
    }

    /** Deterministic stand-in so the UI works before an API key is set. */
    private function stubResponse(array $history): array
    {
        $last = end($history) ?: ['content' => ''];
        $q = strtolower((string) ($last['content'] ?? ''));
        $bullets = [
            "Check the Maintenance Diagnostic Computer (MDC) for FCC unit history (MDC > Avionics > FCC > Unit Status).",
            "Review aircraft maintenance logbook for any recent FCC LRU replacement entries (ATA 22-31-28).",
            "Verify the FCC software part number in the MDC and compare against the current SB A-22-31-47 compliance matrix.",
            "Check if both FCCs (L & R) are running the same software version.",
        ];
        $intro = strpos($q, 'fcc') !== false || strpos($q, 'autopilot') !== false
            ? "To determine if the FCC has been swapped or updated:"
            : "Here is a starting diagnostic flow based on the active fault context:";
        $text = $intro . "\n\n- " . implode("\n- ", $bullets) .
                "\n\n_(Stub response — set anthropic.api_key in config/config.php to enable live answers.)_";
        return ['text' => $text, 'usage' => null, 'model' => 'stub', 'stop' => 'end_turn'];
    }
}
