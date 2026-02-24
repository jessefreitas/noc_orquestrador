<?php
declare(strict_types=1);

require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/backup_storage.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';

function ensure_server_log_archives_table(): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }

    db()->exec(
        "CREATE TABLE IF NOT EXISTS server_log_archives (
            id BIGSERIAL PRIMARY KEY,
            company_id BIGINT NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
            project_id BIGINT NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
            server_id BIGINT NOT NULL REFERENCES hetzner_servers(id) ON DELETE CASCADE,
            actor_user_id BIGINT REFERENCES users(id) ON DELETE SET NULL,
            format VARCHAR(10) NOT NULL,
            range_window VARCHAR(20),
            query_text TEXT,
            bucket_name VARCHAR(190) NOT NULL,
            object_key TEXT NOT NULL,
            object_size BIGINT NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'stored',
            created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
        )"
    );
    db()->exec('CREATE INDEX IF NOT EXISTS idx_server_log_archives_scope ON server_log_archives (company_id, project_id, server_id, created_at DESC)');
    db()->exec('CREATE INDEX IF NOT EXISTS idx_server_log_archives_status ON server_log_archives (status, created_at DESC)');

    $ensured = true;
}

/**
 * @return array{ok:bool,error:?string,provider:string,endpoint_url:string,region:string,bucket:string,force_path_style:bool,access_key_id:string,secret_access_key:string}
 */
function resolve_company_r2_storage(int $companyId): array
{
    if (!backup_storage_ready()) {
        return [
            'ok' => false,
            'error' => 'Modulo de backup storage indisponivel.',
            'provider' => '',
            'endpoint_url' => '',
            'region' => '',
            'bucket' => '',
            'force_path_style' => true,
            'access_key_id' => '',
            'secret_access_key' => '',
        ];
    }

    $stmt = db()->prepare(
        'SELECT
            cbp.postgres_bucket,
            gbs.provider,
            gbs.endpoint_url,
            gbs.region,
            gbs.default_bucket,
            gbs.force_path_style,
            gbs.access_key_id_ciphertext,
            gbs.secret_access_key_ciphertext
         FROM company_backup_policies cbp
         INNER JOIN global_backup_storages gbs ON gbs.id = cbp.global_backup_storage_id
         WHERE cbp.company_id = :company_id
           AND cbp.enabled = TRUE
           AND gbs.status = :status
         LIMIT 1'
    );
    $stmt->execute([
        'company_id' => $companyId,
        'status' => 'active',
    ]);
    $row = $stmt->fetch();
    if (!is_array($row)) {
        return [
            'ok' => false,
            'error' => 'Sem policy de backup ativa para a empresa.',
            'provider' => '',
            'endpoint_url' => '',
            'region' => '',
            'bucket' => '',
            'force_path_style' => true,
            'access_key_id' => '',
            'secret_access_key' => '',
        ];
    }

    $provider = strtolower(trim((string) ($row['provider'] ?? '')));
    if ($provider !== 'cloudflare_r2' && $provider !== 'aws_s3') {
        return [
            'ok' => false,
            'error' => 'Storage da empresa nao e S3/R2 compativel.',
            'provider' => '',
            'endpoint_url' => '',
            'region' => '',
            'bucket' => '',
            'force_path_style' => true,
            'access_key_id' => '',
            'secret_access_key' => '',
        ];
    }

    $bucket = trim((string) ($row['postgres_bucket'] ?? ''));
    if ($bucket === '') {
        $bucket = trim((string) ($row['default_bucket'] ?? ''));
    }
    $endpointUrl = rtrim(trim((string) ($row['endpoint_url'] ?? '')), '/');
    $region = trim((string) ($row['region'] ?? ''));
    if ($region === '') {
        $region = $provider === 'cloudflare_r2' ? 'auto' : 'us-east-1';
    }

    if ($bucket === '' || $endpointUrl === '') {
        return [
            'ok' => false,
            'error' => 'Storage sem bucket/endpoint configurado.',
            'provider' => '',
            'endpoint_url' => '',
            'region' => '',
            'bucket' => '',
            'force_path_style' => true,
            'access_key_id' => '',
            'secret_access_key' => '',
        ];
    }

    try {
        $accessKeyId = decrypt_secret((string) ($row['access_key_id_ciphertext'] ?? ''));
        $secretAccessKey = decrypt_secret((string) ($row['secret_access_key_ciphertext'] ?? ''));
    } catch (Throwable $exception) {
        return [
            'ok' => false,
            'error' => 'Falha ao descriptografar credenciais do storage.',
            'provider' => '',
            'endpoint_url' => '',
            'region' => '',
            'bucket' => '',
            'force_path_style' => true,
            'access_key_id' => '',
            'secret_access_key' => '',
        ];
    }

    if ($accessKeyId === '' || $secretAccessKey === '') {
        return [
            'ok' => false,
            'error' => 'Credenciais do storage vazias.',
            'provider' => '',
            'endpoint_url' => '',
            'region' => '',
            'bucket' => '',
            'force_path_style' => true,
            'access_key_id' => '',
            'secret_access_key' => '',
        ];
    }

    return [
        'ok' => true,
        'error' => null,
        'provider' => $provider,
        'endpoint_url' => $endpointUrl,
        'region' => $region,
        'bucket' => $bucket,
        'force_path_style' => (bool) ($row['force_path_style'] ?? true),
        'access_key_id' => $accessKeyId,
        'secret_access_key' => $secretAccessKey,
    ];
}

function s3_signature_key(string $secretKey, string $dateStamp, string $region, string $service = 's3'): string
{
    $kDate = hash_hmac('sha256', $dateStamp, 'AWS4' . $secretKey, true);
    $kRegion = hash_hmac('sha256', $region, $kDate, true);
    $kService = hash_hmac('sha256', $service, $kRegion, true);
    return hash_hmac('sha256', 'aws4_request', $kService, true);
}

function s3_canonical_uri(string $bucket, string $objectKey, bool $forcePathStyle): string
{
    $segments = explode('/', ltrim($objectKey, '/'));
    $encodedPath = implode('/', array_map(static fn (string $segment): string => rawurlencode($segment), $segments));
    $encodedPath = str_replace('%2F', '/', $encodedPath);
    if ($forcePathStyle) {
        return '/' . rawurlencode($bucket) . '/' . $encodedPath;
    }
    return '/' . $encodedPath;
}

/**
 * @return array{ok:bool,status:int,body:string,error:?string}
 */
function s3_signed_request(
    string $method,
    string $endpointUrl,
    string $region,
    string $accessKeyId,
    string $secretAccessKey,
    string $bucket,
    string $objectKey,
    bool $forcePathStyle,
    string $payload = ''
): array {
    $method = strtoupper(trim($method));
    $parsed = parse_url($endpointUrl);
    if (!is_array($parsed)) {
        return ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'Endpoint invalido.'];
    }
    $scheme = strtolower((string) ($parsed['scheme'] ?? 'https'));
    $host = (string) ($parsed['host'] ?? '');
    $port = (int) ($parsed['port'] ?? 0);
    $basePath = rtrim((string) ($parsed['path'] ?? ''), '/');
    if ($host === '') {
        return ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'Endpoint sem host.'];
    }
    if ($scheme !== 'http' && $scheme !== 'https') {
        return ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'Endpoint com esquema invalido.'];
    }

    $requestHost = $forcePathStyle ? $host : ($bucket . '.' . $host);
    $canonicalUri = $basePath . s3_canonical_uri($bucket, $objectKey, $forcePathStyle);
    if ($canonicalUri === '') {
        $canonicalUri = '/';
    }

    $amzDate = gmdate('Ymd\THis\Z');
    $dateStamp = gmdate('Ymd');
    $payloadHash = hash('sha256', $payload);
    $canonicalHeaders = 'host:' . strtolower($requestHost) . "\n"
        . 'x-amz-content-sha256:' . $payloadHash . "\n"
        . 'x-amz-date:' . $amzDate . "\n";
    $signedHeaders = 'host;x-amz-content-sha256;x-amz-date';
    $canonicalRequest = $method . "\n"
        . $canonicalUri . "\n"
        . "\n"
        . $canonicalHeaders . "\n"
        . $signedHeaders . "\n"
        . $payloadHash;
    $credentialScope = $dateStamp . '/' . $region . '/s3/aws4_request';
    $stringToSign = 'AWS4-HMAC-SHA256' . "\n"
        . $amzDate . "\n"
        . $credentialScope . "\n"
        . hash('sha256', $canonicalRequest);
    $signingKey = s3_signature_key($secretAccessKey, $dateStamp, $region, 's3');
    $signature = hash_hmac('sha256', $stringToSign, $signingKey);

    $authHeader = 'AWS4-HMAC-SHA256 Credential=' . $accessKeyId . '/' . $credentialScope
        . ', SignedHeaders=' . $signedHeaders
        . ', Signature=' . $signature;

    $url = $scheme . '://' . $requestHost;
    if ($port > 0) {
        $url .= ':' . $port;
    }
    $url .= $canonicalUri;

    $headers = [
        'Authorization: ' . $authHeader,
        'x-amz-content-sha256: ' . $payloadHash,
        'x-amz-date: ' . $amzDate,
        'Accept: application/json, text/plain, */*',
    ];
    if ($method === 'PUT') {
        $headers[] = 'Content-Type: ' . (str_ends_with($objectKey, '.json') ? 'application/json' : 'text/plain');
    }

    $ch = curl_init($url);
    if ($ch === false) {
        return ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'Falha ao iniciar cURL S3.'];
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 45,
    ]);
    if ($method === 'PUT') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    }

    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if (!is_string($body)) {
        $body = '';
    }
    if ($error !== '') {
        return ['ok' => false, 'status' => $status, 'body' => $body, 'error' => $error];
    }

    return [
        'ok' => $status >= 200 && $status < 300,
        'status' => $status,
        'body' => $body,
        'error' => null,
    ];
}

/**
 * @param array<int,array{ts:string,labels:string,line:string}> $rows
 * @return array{ok:bool,error:?string,count:int}
 */
function archive_server_logs_to_r2(
    int $companyId,
    int $projectId,
    int $serverId,
    int $actorUserId,
    string $serverName,
    string $rangeWindow,
    string $queryText,
    array $rows
): array {
    ensure_server_log_archives_table();
    $storage = resolve_company_r2_storage($companyId);
    if (($storage['ok'] ?? false) !== true) {
        return ['ok' => false, 'error' => (string) ($storage['error'] ?? 'Storage R2 indisponivel.'), 'count' => 0];
    }

    $safeServer = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $serverName) ?? 'server';
    $safeServer = trim($safeServer, '-');
    if ($safeServer === '') {
        $safeServer = 'server';
    }

    $dateDir = gmdate('Y/m/d');
    $stamp = gmdate('Ymd-His');
    $basePrefix = 'company-' . $companyId
        . '/project-' . $projectId
        . '/server-' . $serverId
        . '/logs/' . $dateDir;
    $filePrefix = $safeServer . '-' . $rangeWindow . '-' . $stamp;

    $jsonPayload = json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if (!is_string($jsonPayload)) {
        $jsonPayload = '[]';
    }
    $logPayload = '';
    foreach ($rows as $row) {
        $ts = (string) ($row['ts'] ?? '');
        $labels = trim((string) ($row['labels'] ?? ''));
        $line = (string) ($row['line'] ?? '');
        $prefix = '[' . $ts . ']';
        if ($labels !== '') {
            $prefix .= ' {' . $labels . '}';
        }
        $logPayload .= $prefix . ' ' . $line . "\n";
    }

    $objects = [
        ['format' => 'json', 'key' => $basePrefix . '/' . $filePrefix . '.json', 'payload' => $jsonPayload],
        ['format' => 'log', 'key' => $basePrefix . '/' . $filePrefix . '.log', 'payload' => $logPayload],
    ];

    $saved = 0;
    foreach ($objects as $object) {
        $put = s3_signed_request(
            'PUT',
            (string) $storage['endpoint_url'],
            (string) $storage['region'],
            (string) $storage['access_key_id'],
            (string) $storage['secret_access_key'],
            (string) $storage['bucket'],
            (string) $object['key'],
            (bool) $storage['force_path_style'],
            (string) $object['payload']
        );
        if (!($put['ok'] ?? false)) {
            return [
                'ok' => false,
                'error' => 'Falha ao enviar para R2 (' . (string) ($object['format'] ?? '-') . '): HTTP ' . (int) ($put['status'] ?? 0),
                'count' => $saved,
            ];
        }

        $stmt = db()->prepare(
            'INSERT INTO server_log_archives (
                company_id, project_id, server_id, actor_user_id, format, range_window, query_text,
                bucket_name, object_key, object_size, status
            ) VALUES (
                :company_id, :project_id, :server_id, :actor_user_id, :format, :range_window, :query_text,
                :bucket_name, :object_key, :object_size, :status
            )'
        );
        $stmt->execute([
            'company_id' => $companyId,
            'project_id' => $projectId,
            'server_id' => $serverId,
            'actor_user_id' => $actorUserId > 0 ? $actorUserId : null,
            'format' => (string) $object['format'],
            'range_window' => $rangeWindow !== '' ? $rangeWindow : null,
            'query_text' => $queryText !== '' ? $queryText : null,
            'bucket_name' => (string) $storage['bucket'],
            'object_key' => (string) $object['key'],
            'object_size' => strlen((string) $object['payload']),
            'status' => 'stored',
        ]);
        $saved++;
    }

    audit_log(
        $companyId,
        $projectId,
        $actorUserId,
        'server.logs.archived_r2',
        'hetzner_server',
        (string) $serverId,
        null,
        [
            'range' => $rangeWindow,
            'query' => $queryText,
            'saved_files' => $saved,
            'bucket' => (string) $storage['bucket'],
            'prefix' => $basePrefix,
        ]
    );

    return ['ok' => true, 'error' => null, 'count' => $saved];
}

/**
 * @return array<int,array<string,mixed>>
 */
function list_server_log_archives(int $companyId, int $projectId, int $serverId, int $limit = 40): array
{
    ensure_server_log_archives_table();
    $stmt = db()->prepare(
        'SELECT *
         FROM server_log_archives
         WHERE company_id = :company_id
           AND project_id = :project_id
           AND server_id = :server_id
         ORDER BY created_at DESC
         LIMIT :limit'
    );
    $stmt->bindValue(':company_id', $companyId, PDO::PARAM_INT);
    $stmt->bindValue(':project_id', $projectId, PDO::PARAM_INT);
    $stmt->bindValue(':server_id', $serverId, PDO::PARAM_INT);
    $stmt->bindValue(':limit', max(1, min(200, $limit)), PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
    return is_array($rows) ? $rows : [];
}

function find_server_log_archive(int $companyId, int $projectId, int $serverId, int $archiveId): ?array
{
    ensure_server_log_archives_table();
    $stmt = db()->prepare(
        'SELECT *
         FROM server_log_archives
         WHERE id = :id
           AND company_id = :company_id
           AND project_id = :project_id
           AND server_id = :server_id
         LIMIT 1'
    );
    $stmt->execute([
        'id' => $archiveId,
        'company_id' => $companyId,
        'project_id' => $projectId,
        'server_id' => $serverId,
    ]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

/**
 * @return array{ok:bool,error:?string,filename:string,content:string,mime:string}
 */
function download_server_log_archive_content(int $companyId, int $projectId, int $serverId, int $archiveId): array
{
    $archive = find_server_log_archive($companyId, $projectId, $serverId, $archiveId);
    if (!is_array($archive)) {
        return ['ok' => false, 'error' => 'Arquivo de log nao encontrado.', 'filename' => '', 'content' => '', 'mime' => 'text/plain'];
    }

    $storage = resolve_company_r2_storage($companyId);
    if (($storage['ok'] ?? false) !== true) {
        return ['ok' => false, 'error' => (string) ($storage['error'] ?? 'Storage indisponivel.'), 'filename' => '', 'content' => '', 'mime' => 'text/plain'];
    }

    $get = s3_signed_request(
        'GET',
        (string) $storage['endpoint_url'],
        (string) $storage['region'],
        (string) $storage['access_key_id'],
        (string) $storage['secret_access_key'],
        (string) ($archive['bucket_name'] ?? ''),
        (string) ($archive['object_key'] ?? ''),
        (bool) $storage['force_path_style'],
        ''
    );
    if (!($get['ok'] ?? false)) {
        return [
            'ok' => false,
            'error' => 'Falha ao baixar arquivo do R2 (HTTP ' . (int) ($get['status'] ?? 0) . ').',
            'filename' => '',
            'content' => '',
            'mime' => 'text/plain',
        ];
    }

    $key = (string) ($archive['object_key'] ?? 'logs.txt');
    $filename = basename($key);
    if ($filename === '' || $filename === '.' || $filename === '..') {
        $filename = 'logs-' . (int) $archiveId . '.txt';
    }
    $isJson = strtolower((string) ($archive['format'] ?? '')) === 'json' || str_ends_with(strtolower($filename), '.json');

    return [
        'ok' => true,
        'error' => null,
        'filename' => $filename,
        'content' => (string) ($get['body'] ?? ''),
        'mime' => $isJson ? 'application/json; charset=utf-8' : 'text/plain; charset=utf-8',
    ];
}
