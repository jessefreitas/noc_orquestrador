<?php
declare(strict_types=1);

require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/tenancy.php';

function llm_provider_catalog(): array
{
    return [
        'openai' => [
            'label' => 'OpenAI',
            'aliases' => ['openai'],
            'models' => ['gpt-4.1-mini', 'gpt-4o-mini', 'o4-mini', 'text-embedding-3-large'],
        ],
        'openrouter' => [
            'label' => 'OpenRouter',
            'aliases' => ['openrouter', 'openouter'],
            'models' => ['openai/gpt-4.1-mini', 'anthropic/claude-3.7-sonnet', 'google/gemini-2.0-flash'],
        ],
        'zai' => [
            'label' => 'Z.ai',
            'aliases' => ['zai', 'z.ai', 'z-ai'],
            'models' => ['glm-4.5', 'glm-4.5-air', 'glm-4.5v'],
        ],
        'anthropic' => [
            'label' => 'Anthropic',
            'aliases' => ['anthropic', 'claude'],
            'models' => ['claude-3.7-sonnet', 'claude-3.5-haiku'],
        ],
        'google' => [
            'label' => 'Google',
            'aliases' => ['google', 'gemini'],
            'models' => ['gemini-2.0-flash', 'gemini-1.5-pro'],
        ],
        'groq' => [
            'label' => 'Groq',
            'aliases' => ['groq'],
            'models' => ['llama-3.3-70b-versatile', 'mixtral-8x7b-32768'],
        ],
        'deepseek' => [
            'label' => 'DeepSeek',
            'aliases' => ['deepseek'],
            'models' => ['deepseek-chat', 'deepseek-reasoner'],
        ],
        'other' => [
            'label' => 'Outro',
            'aliases' => ['other', 'outro', 'custom'],
            'models' => ['custom-model'],
        ],
    ];
}

function llm_supported_providers(): array
{
    return array_keys(llm_provider_catalog());
}

function llm_migration_hint_command(): string
{
    return 'docker compose -f orch-php/docker-compose.yml exec -T db psql -U noc_user -d noc_orquestrador -f /docker-entrypoint-initdb.d/003-llm-keys.sql';
}

function llm_storage_ready(): bool
{
    static $ready = null;
    if (is_bool($ready)) {
        return $ready;
    }

    try {
        $stmt = db()->query("SELECT to_regclass('public.company_llm_keys') IS NOT NULL");
        $ready = (bool) $stmt->fetchColumn();
    } catch (Throwable $exception) {
        $ready = false;
    }

    return $ready;
}

function llm_normalize_token(string $value): string
{
    return str_replace([' ', '.', '-', '_'], '', strtolower(trim($value)));
}

function llm_normalize_provider(string $provider): string
{
    $inputToken = llm_normalize_token($provider);
    if ($inputToken === '') {
        return '';
    }

    foreach (llm_provider_catalog() as $providerKey => $meta) {
        $aliases = $meta['aliases'] ?? [];
        if (!is_array($aliases)) {
            continue;
        }

        foreach ($aliases as $alias) {
            if (llm_normalize_token((string) $alias) === $inputToken) {
                return $providerKey;
            }
        }
    }

    return '';
}

function llm_provider_label(string $provider): string
{
    $normalized = llm_normalize_provider($provider);
    if ($normalized === '') {
        return strtoupper(trim($provider));
    }

    $catalog = llm_provider_catalog();
    $meta = $catalog[$normalized] ?? null;
    if (!is_array($meta)) {
        return strtoupper($normalized);
    }

    return (string) ($meta['label'] ?? strtoupper($normalized));
}

function llm_provider_models(string $provider): array
{
    $normalized = llm_normalize_provider($provider);
    if ($normalized === '') {
        $normalized = 'other';
    }

    $catalog = llm_provider_catalog();
    $meta = $catalog[$normalized] ?? [];
    $models = $meta['models'] ?? [];
    return is_array($models) ? $models : [];
}

function llm_mask_hint(string $key): string
{
    $trimmed = trim($key);
    if ($trimmed === '') {
        return '';
    }

    $length = strlen($trimmed);
    if ($length <= 8) {
        return str_repeat('*', $length);
    }

    return substr($trimmed, 0, 4) . '...' . substr($trimmed, -4);
}

function create_company_llm_key(
    int $userId,
    int $companyId,
    string $provider,
    string $model,
    string $apiKey,
    ?string $apiBaseUrl = null,
    ?string $keyLabel = null
): int {
    if (!llm_storage_ready()) {
        throw new RuntimeException(
            'Tabela company_llm_keys nao encontrada. Rode a migration: ' . llm_migration_hint_command()
        );
    }

    if (!user_has_company_access($userId, $companyId)) {
        throw new RuntimeException('Sem acesso a empresa selecionada.');
    }

    $normalizedProvider = llm_normalize_provider($provider);
    if ($normalizedProvider === '' || !in_array($normalizedProvider, llm_supported_providers(), true)) {
        throw new InvalidArgumentException('Provider LLM invalido.');
    }

    $normalizedModel = trim($model);
    if ($normalizedModel === '') {
        throw new InvalidArgumentException('Modelo LLM e obrigatorio.');
    }

    $normalizedApiKey = trim($apiKey);
    if ($normalizedApiKey === '') {
        throw new InvalidArgumentException('Chave API e obrigatoria.');
    }

    $stmt = db()->prepare(
        'INSERT INTO company_llm_keys (
            company_id,
            provider,
            model,
            key_label,
            api_base_url,
            api_key_ciphertext,
            key_hint,
            created_by
        ) VALUES (
            :company_id,
            :provider,
            :model,
            :key_label,
            :api_base_url,
            :api_key_ciphertext,
            :key_hint,
            :created_by
        )
        RETURNING id'
    );

    $stmt->execute([
        'company_id' => $companyId,
        'provider' => $normalizedProvider,
        'model' => $normalizedModel,
        'key_label' => trim((string) $keyLabel) !== '' ? trim((string) $keyLabel) : null,
        'api_base_url' => trim((string) $apiBaseUrl) !== '' ? trim((string) $apiBaseUrl) : null,
        'api_key_ciphertext' => encrypt_secret($normalizedApiKey),
        'key_hint' => llm_mask_hint($normalizedApiKey),
        'created_by' => $userId,
    ]);

    $keyId = (int) $stmt->fetchColumn();

    audit_log(
        $companyId,
        null,
        $userId,
        'company.llm_key.created',
        'company_llm_key',
        (string) $keyId,
        null,
        [
            'provider' => $normalizedProvider,
            'model' => $normalizedModel,
            'key_label' => trim((string) $keyLabel) !== '' ? trim((string) $keyLabel) : null,
        ]
    );

    return $keyId;
}

function list_company_llm_keys(int $userId, int $companyId): array
{
    if (!llm_storage_ready()) {
        return [];
    }

    if (!user_has_company_access($userId, $companyId)) {
        return [];
    }

    $stmt = db()->prepare(
        'SELECT ck.id,
                ck.company_id,
                ck.provider,
                ck.model,
                ck.key_label,
                ck.api_base_url,
                ck.key_hint,
                ck.status,
                ck.created_at,
                c.name AS company_name
         FROM company_llm_keys ck
         INNER JOIN companies c ON c.id = ck.company_id
         INNER JOIN company_users cu ON cu.company_id = ck.company_id
         WHERE cu.user_id = :user_id
           AND ck.company_id = :company_id
         ORDER BY ck.created_at DESC'
    );
    $stmt->execute([
        'user_id' => $userId,
        'company_id' => $companyId,
    ]);

    return $stmt->fetchAll();
}

function llm_provider_default_base_url(string $provider): ?string
{
    $normalized = llm_normalize_provider($provider);
    return match ($normalized) {
        'openai' => 'https://api.openai.com/v1',
        'openrouter' => 'https://openrouter.ai/api/v1',
        'zai' => 'https://api.z.ai/api/coding/paas/v4',
        'groq' => 'https://api.groq.com/openai/v1',
        'deepseek' => 'https://api.deepseek.com',
        default => null,
    };
}

/**
 * @return array{enabled:bool,secret_key:string,public_key:string,base_url:string}
 */
function llm_langfuse_config(): array
{
    $secretKey = trim((string) env_value('LANGFUSE_SECRET_KEY', ''));
    $publicKey = trim((string) env_value('LANGFUSE_PUBLIC_KEY', ''));
    $baseUrl = rtrim(trim((string) env_value('LANGFUSE_BASE_URL', '')), '/');

    return [
        'enabled' => $secretKey !== '' && $publicKey !== '' && $baseUrl !== '',
        'secret_key' => $secretKey,
        'public_key' => $publicKey,
        'base_url' => $baseUrl,
    ];
}

function llm_trace_id(): string
{
    try {
        return bin2hex(random_bytes(16));
    } catch (Throwable $exception) {
        return sha1((string) microtime(true) . '-' . (string) mt_rand());
    }
}

function llm_force_global_runtime(): bool
{
    $raw = strtolower(trim((string) env_value('LLM_FORCE_GLOBAL', '0')));
    return in_array($raw, ['1', 'true', 'yes', 'on'], true);
}

/**
 * Envio de eventos para Langfuse sem interromper a resposta do usuario.
 *
 * @param array{enabled:bool,secret_key:string,public_key:string,base_url:string} $langfuse
 * @param array<string,mixed> $runtime
 * @param array<string,mixed> $requestInfo
 * @param array<string,mixed> $responseInfo
 */
function llm_langfuse_emit_chat_event(
    array $langfuse,
    array $runtime,
    string $traceId,
    array $requestInfo,
    array $responseInfo
): void {
    if (($langfuse['enabled'] ?? false) !== true) {
        return;
    }

    $ingestUrl = rtrim((string) ($langfuse['base_url'] ?? ''), '/') . '/api/public/ingestion';
    $publicKey = (string) ($langfuse['public_key'] ?? '');
    $secretKey = (string) ($langfuse['secret_key'] ?? '');
    if ($ingestUrl === '/api/public/ingestion' || $publicKey === '' || $secretKey === '') {
        return;
    }

    $generationId = llm_trace_id();
    $nowIso = gmdate('c');

    $inputSummary = [
        'system_chars' => (int) ($requestInfo['system_chars'] ?? 0),
        'user_chars' => (int) ($requestInfo['user_chars'] ?? 0),
        'user_preview' => (string) ($requestInfo['user_preview'] ?? ''),
    ];
    $outputSummary = [
        'ok' => (bool) ($responseInfo['ok'] ?? false),
        'http_code' => (int) ($responseInfo['http_code'] ?? 0),
        'error' => (string) ($responseInfo['error'] ?? ''),
        'content_preview' => (string) ($responseInfo['content_preview'] ?? ''),
        'content_chars' => (int) ($responseInfo['content_chars'] ?? 0),
    ];

    $payload = [
        'batch' => [
            [
                'id' => llm_trace_id(),
                'type' => 'trace-create',
                'timestamp' => $nowIso,
                'body' => [
                    'id' => $traceId,
                    'name' => 'omninoc.llm.chat',
                    'metadata' => [
                        'provider' => (string) ($runtime['provider'] ?? ''),
                        'model' => (string) ($runtime['model'] ?? ''),
                        'source' => (string) ($runtime['source'] ?? ''),
                    ],
                    'input' => $inputSummary,
                    'output' => $outputSummary,
                ],
            ],
            [
                'id' => llm_trace_id(),
                'type' => 'generation-create',
                'timestamp' => $nowIso,
                'body' => [
                    'id' => $generationId,
                    'traceId' => $traceId,
                    'name' => 'chat.completions',
                    'model' => (string) ($runtime['model'] ?? ''),
                    'input' => $inputSummary,
                    'output' => $outputSummary,
                    'metadata' => [
                        'provider' => (string) ($runtime['provider'] ?? ''),
                        'source' => (string) ($runtime['source'] ?? ''),
                    ],
                ],
            ],
        ],
        'metadata' => [
            'batch_id' => llm_trace_id(),
            'sdk' => [
                'name' => 'omninoc-php',
                'version' => '1.0.0',
            ],
        ],
    ];

    $auth = base64_encode($publicKey . ':' . $secretKey);
    $headers = [
        'Authorization: Basic ' . $auth,
        'Content-Type: application/json',
        'Accept: application/json',
    ];

    $ch = curl_init($ingestUrl);
    if ($ch === false) {
        return;
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => 4,
    ]);
    curl_exec($ch);
    curl_close($ch);
}

/**
 * @return array{provider:string,model:string,api_base_url:string,api_key:string,source:string}|null
 */
function llm_runtime_for_devops_analysis(int $userId, int $companyId): ?array
{
    $forceGlobal = llm_force_global_runtime();

    if (!$forceGlobal && llm_storage_ready() && user_has_company_access($userId, $companyId)) {
        $stmt = db()->prepare(
            "SELECT provider, model, api_base_url, api_key_ciphertext
             FROM company_llm_keys
             WHERE company_id = :company_id
               AND status = :status
             ORDER BY
               CASE provider
                 WHEN 'zai' THEN 1
                 WHEN 'openrouter' THEN 2
                 WHEN 'openai' THEN 3
                 WHEN 'groq' THEN 4
                 WHEN 'deepseek' THEN 5
                 WHEN 'other' THEN 6
                 ELSE 99
               END,
               id DESC
             LIMIT 1"
        );
        $stmt->execute([
            'company_id' => $companyId,
            'status' => 'active',
        ]);
        $row = $stmt->fetch();
        if (is_array($row)) {
            $provider = llm_normalize_provider((string) ($row['provider'] ?? ''));
            $apiKeyCipher = (string) ($row['api_key_ciphertext'] ?? '');
            if ($provider !== '' && $apiKeyCipher !== '') {
                try {
                    $apiKey = decrypt_secret($apiKeyCipher);
                } catch (Throwable $exception) {
                    $apiKey = '';
                }
                $baseUrl = trim((string) ($row['api_base_url'] ?? ''));
                if ($baseUrl === '') {
                    $baseUrl = (string) (llm_provider_default_base_url($provider) ?? '');
                }
                if ($apiKey !== '' && $baseUrl !== '') {
                    return [
                        'provider' => $provider,
                        'model' => trim((string) ($row['model'] ?? '')) !== '' ? trim((string) $row['model']) : 'gpt-4o-mini',
                        'api_base_url' => rtrim($baseUrl, '/'),
                        'api_key' => $apiKey,
                        'source' => 'company_llm_keys',
                    ];
                }
            }
        }
    }

    $globalApiKey = trim((string) env_value('LLM_GLOBAL_API_KEY', ''));
    if ($globalApiKey !== '') {
        $globalProvider = llm_normalize_provider((string) env_value('LLM_GLOBAL_PROVIDER', 'openai'));
        if ($globalProvider === '') {
            $globalProvider = 'openai';
        }

        $globalModel = trim((string) env_value('LLM_GLOBAL_MODEL', ''));
        if ($globalModel === '') {
            $globalModel = match ($globalProvider) {
                'openrouter' => 'openai/gpt-4.1-mini',
                'zai' => 'glm-4.5',
                'groq' => 'llama-3.3-70b-versatile',
                'deepseek' => 'deepseek-chat',
                default => 'gpt-4o-mini',
            };
        }

        $globalBaseUrl = rtrim(trim((string) env_value('LLM_GLOBAL_BASE_URL', '')), '/');
        if ($globalBaseUrl === '') {
            $globalBaseUrl = (string) (llm_provider_default_base_url($globalProvider) ?? '');
        }

        if ($globalBaseUrl !== '') {
            return [
                'provider' => $globalProvider,
                'model' => $globalModel,
                'api_base_url' => $globalBaseUrl,
                'api_key' => $globalApiKey,
                'source' => $forceGlobal ? 'env.llm_global_forced' : 'env.llm_global',
            ];
        }
    }

    $openaiKey = trim((string) env_value('OPENAI_API_KEY', ''));
    if ($openaiKey !== '') {
        return [
            'provider' => 'openai',
            'model' => trim((string) env_value('OPENAI_MODEL', 'gpt-4o-mini')) ?: 'gpt-4o-mini',
            'api_base_url' => rtrim((string) env_value('OPENAI_BASE_URL', 'https://api.openai.com/v1'), '/'),
            'api_key' => $openaiKey,
            'source' => $forceGlobal ? 'env.openai_forced' : 'env.openai',
        ];
    }

    $zaiKey = trim((string) env_value('ZAI_API_KEY', ''));
    if ($zaiKey !== '') {
        return [
            'provider' => 'zai',
            'model' => trim((string) env_value('ZAI_MODEL', 'glm-4.5')) ?: 'glm-4.5',
            'api_base_url' => rtrim((string) env_value('ZAI_BASE_URL', 'https://api.z.ai/api/coding/paas/v4'), '/'),
            'api_key' => $zaiKey,
            'source' => 'env.zai',
        ];
    }

    return null;
}

/**
 * @return array{ok:bool,content:string,error:?string,meta:array<string,mixed>}
 */
function llm_openai_compatible_chat(
    array $runtime,
    string $systemPrompt,
    string $userPrompt,
    int $timeoutSec = 45
): array {
    $traceId = llm_trace_id();
    $langfuse = llm_langfuse_config();
    $baseUrl = rtrim((string) ($runtime['api_base_url'] ?? ''), '/');
    $apiKey = (string) ($runtime['api_key'] ?? '');
    $model = trim((string) ($runtime['model'] ?? ''));
    if ($baseUrl === '' || $apiKey === '' || $model === '') {
        return ['ok' => false, 'content' => '', 'error' => 'Runtime LLM incompleto.', 'meta' => []];
    }

    $endpoint = $baseUrl . '/chat/completions';
    $payload = [
        'model' => $model,
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt],
        ],
        'temperature' => 0.2,
        'max_tokens' => 900,
    ];

    $headers = [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json',
        'Accept: application/json',
    ];
    if (($runtime['provider'] ?? '') === 'openrouter') {
        $headers[] = 'HTTP-Referer: https://omniforge.com.br';
        $headers[] = 'X-Title: OmniNOC DevOps Analyst';
    }

    $ch = curl_init($endpoint);
    if ($ch === false) {
        return ['ok' => false, 'content' => '', 'error' => 'Falha ao iniciar chamada LLM.', 'meta' => []];
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => max(10, $timeoutSec),
    ]);
    $body = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    $requestInfo = [
        'system_chars' => strlen($systemPrompt),
        'user_chars' => strlen($userPrompt),
        'user_preview' => substr(preg_replace('/\s+/', ' ', $userPrompt) ?? $userPrompt, 0, 700),
    ];

    if (!is_string($body) || $body === '') {
        llm_langfuse_emit_chat_event(
            $langfuse,
            $runtime,
            $traceId,
            $requestInfo,
            [
                'ok' => false,
                'http_code' => $httpCode,
                'error' => $curlError !== '' ? $curlError : 'sem resposta',
                'content_preview' => '',
                'content_chars' => 0,
            ]
        );
        return ['ok' => false, 'content' => '', 'error' => 'Sem resposta do LLM: ' . ($curlError !== '' ? $curlError : 'erro desconhecido'), 'meta' => []];
    }
    if ($httpCode < 200 || $httpCode >= 300) {
        llm_langfuse_emit_chat_event(
            $langfuse,
            $runtime,
            $traceId,
            $requestInfo,
            [
                'ok' => false,
                'http_code' => $httpCode,
                'error' => 'http_' . $httpCode,
                'content_preview' => '',
                'content_chars' => 0,
            ]
        );
        return ['ok' => false, 'content' => '', 'error' => 'LLM retornou HTTP ' . $httpCode . '.', 'meta' => ['http_code' => $httpCode]];
    }

    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'content' => '', 'error' => 'Resposta LLM invalida.', 'meta' => []];
    }

    $content = (string) ($decoded['choices'][0]['message']['content'] ?? '');
    if (trim($content) === '') {
        llm_langfuse_emit_chat_event(
            $langfuse,
            $runtime,
            $traceId,
            $requestInfo,
            [
                'ok' => false,
                'http_code' => $httpCode,
                'error' => 'empty_content',
                'content_preview' => '',
                'content_chars' => 0,
            ]
        );
        return ['ok' => false, 'content' => '', 'error' => 'LLM respondeu sem conteudo.', 'meta' => ['raw' => $decoded]];
    }

    llm_langfuse_emit_chat_event(
        $langfuse,
        $runtime,
        $traceId,
        $requestInfo,
        [
            'ok' => true,
            'http_code' => $httpCode,
            'error' => '',
            'content_preview' => substr(preg_replace('/\s+/', ' ', $content) ?? $content, 0, 900),
            'content_chars' => strlen($content),
        ]
    );

    return [
        'ok' => true,
        'content' => $content,
        'error' => null,
        'meta' => [
            'model' => $model,
            'provider' => (string) ($runtime['provider'] ?? ''),
            'source' => (string) ($runtime['source'] ?? ''),
            'trace_id' => $traceId,
            'langfuse_enabled' => (bool) ($langfuse['enabled'] ?? false),
            'langfuse_base_url' => (string) ($langfuse['base_url'] ?? ''),
        ],
    ];
}

/**
 * @param callable(string):void $onToken
 * @return array{ok:bool,content:string,error:?string,meta:array<string,mixed>}
 */
function llm_openai_compatible_chat_stream(
    array $runtime,
    string $systemPrompt,
    string $userPrompt,
    callable $onToken,
    int $timeoutSec = 45
): array {
    $traceId = llm_trace_id();
    $langfuse = llm_langfuse_config();
    $baseUrl = rtrim((string) ($runtime['api_base_url'] ?? ''), '/');
    $apiKey = (string) ($runtime['api_key'] ?? '');
    $model = trim((string) ($runtime['model'] ?? ''));
    if ($baseUrl === '' || $apiKey === '' || $model === '') {
        return ['ok' => false, 'content' => '', 'error' => 'Runtime LLM incompleto.', 'meta' => []];
    }

    $endpoint = $baseUrl . '/chat/completions';
    $payload = [
        'model' => $model,
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt],
        ],
        'temperature' => 0.2,
        'max_tokens' => 900,
        'stream' => true,
    ];

    $headers = [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json',
        'Accept: text/event-stream',
    ];
    if (($runtime['provider'] ?? '') === 'openrouter') {
        $headers[] = 'HTTP-Referer: https://omniforge.com.br';
        $headers[] = 'X-Title: OmniNOC DevOps Analyst';
    }

    $requestInfo = [
        'system_chars' => strlen($systemPrompt),
        'user_chars' => strlen($userPrompt),
        'user_preview' => substr(preg_replace('/\s+/', ' ', $userPrompt) ?? $userPrompt, 0, 700),
        'stream' => true,
    ];

    $responseBuffer = '';
    $lineBuffer = '';
    $content = '';
    $streamDone = false;
    $streamError = '';
    $hadChunk = false;

    $ch = curl_init($endpoint);
    if ($ch === false) {
        return ['ok' => false, 'content' => '', 'error' => 'Falha ao iniciar chamada LLM.', 'meta' => []];
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => max(10, $timeoutSec),
        CURLOPT_WRITEFUNCTION => static function ($chHandle, string $chunk) use (&$responseBuffer, &$lineBuffer, &$content, &$streamDone, &$streamError, &$hadChunk, $onToken): int {
            $hadChunk = true;
            $responseBuffer .= $chunk;
            $lineBuffer .= $chunk;

            while (($linePos = strpos($lineBuffer, "\n")) !== false) {
                $line = substr($lineBuffer, 0, $linePos);
                $lineBuffer = substr($lineBuffer, $linePos + 1);
                $line = rtrim($line, "\r");
                if ($line === '' || !str_starts_with($line, 'data:')) {
                    continue;
                }
                $payloadLine = trim(substr($line, 5));
                if ($payloadLine === '' || $payloadLine === '[DONE]') {
                    if ($payloadLine === '[DONE]') {
                        $streamDone = true;
                    }
                    continue;
                }
                $decoded = json_decode($payloadLine, true);
                if (!is_array($decoded)) {
                    continue;
                }
                $delta = (string) ($decoded['choices'][0]['delta']['content'] ?? '');
                if ($delta !== '') {
                    $content .= $delta;
                    $onToken($delta);
                }
                $finishReason = $decoded['choices'][0]['finish_reason'] ?? null;
                if (is_string($finishReason) && trim($finishReason) !== '') {
                    $streamDone = true;
                }
                $maybeErr = $decoded['error']['message'] ?? null;
                if (is_string($maybeErr) && trim($maybeErr) !== '') {
                    $streamError = trim($maybeErr);
                }
            }

            return strlen($chunk);
        },
    ]);

    curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError !== '') {
        llm_langfuse_emit_chat_event(
            $langfuse,
            $runtime,
            $traceId,
            $requestInfo,
            [
                'ok' => false,
                'http_code' => $httpCode,
                'error' => $curlError,
                'content_preview' => '',
                'content_chars' => 0,
            ]
        );
        return ['ok' => false, 'content' => '', 'error' => 'Sem resposta do LLM: ' . $curlError, 'meta' => []];
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        llm_langfuse_emit_chat_event(
            $langfuse,
            $runtime,
            $traceId,
            $requestInfo,
            [
                'ok' => false,
                'http_code' => $httpCode,
                'error' => 'http_' . $httpCode,
                'content_preview' => '',
                'content_chars' => 0,
            ]
        );
        return ['ok' => false, 'content' => '', 'error' => 'LLM retornou HTTP ' . $httpCode . '.', 'meta' => ['http_code' => $httpCode]];
    }

    if ($streamError !== '') {
        llm_langfuse_emit_chat_event(
            $langfuse,
            $runtime,
            $traceId,
            $requestInfo,
            [
                'ok' => false,
                'http_code' => $httpCode,
                'error' => $streamError,
                'content_preview' => '',
                'content_chars' => 0,
            ]
        );
        return ['ok' => false, 'content' => '', 'error' => 'LLM streaming falhou: ' . $streamError, 'meta' => []];
    }

    if (!$hadChunk || trim($content) === '') {
        $fallback = json_decode($responseBuffer, true);
        if (is_array($fallback)) {
            $fallbackContent = (string) ($fallback['choices'][0]['message']['content'] ?? '');
            if (trim($fallbackContent) !== '') {
                $content = $fallbackContent;
                $onToken($fallbackContent);
            }
        }
    }

    if (trim($content) === '') {
        llm_langfuse_emit_chat_event(
            $langfuse,
            $runtime,
            $traceId,
            $requestInfo,
            [
                'ok' => false,
                'http_code' => $httpCode,
                'error' => 'empty_content_stream',
                'content_preview' => '',
                'content_chars' => 0,
            ]
        );
        return ['ok' => false, 'content' => '', 'error' => 'LLM respondeu sem conteudo.', 'meta' => ['stream_done' => $streamDone]];
    }

    llm_langfuse_emit_chat_event(
        $langfuse,
        $runtime,
        $traceId,
        $requestInfo,
        [
            'ok' => true,
            'http_code' => $httpCode,
            'error' => '',
            'content_preview' => substr(preg_replace('/\s+/', ' ', $content) ?? $content, 0, 900),
            'content_chars' => strlen($content),
        ]
    );

    return [
        'ok' => true,
        'content' => $content,
        'error' => null,
        'meta' => [
            'model' => $model,
            'provider' => (string) ($runtime['provider'] ?? ''),
            'source' => (string) ($runtime['source'] ?? ''),
            'trace_id' => $traceId,
            'langfuse_enabled' => (bool) ($langfuse['enabled'] ?? false),
            'langfuse_base_url' => (string) ($langfuse['base_url'] ?? ''),
            'stream_done' => $streamDone,
        ],
    ];
}
