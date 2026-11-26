<?php
/**
 * Server-side client for the FiscalPoint ML service (ml/fiscalpoint_ml/api.py).
 *
 * The browser never talks to the ML service directly: pages call ml_request() and the
 * service URL / API key stay on the server. Configure in .env:
 *   ML_API_URL=http://127.0.0.1:5001
 *   ML_API_TOKEN=<same value as the ML service>
 */

/**
 * POST (or GET when $payload is null) to the ML service.
 *
 * @return array{ok: bool, status: int, data: ?array, error: ?string}
 */
function ml_request(string $path, ?array $payload = null, int $timeout = 8): array
{
    $base = rtrim(env('ML_API_URL', 'http://127.0.0.1:5001'), '/');
    $headers = ['Accept: application/json'];
    if ($token = env('ML_API_TOKEN')) {
        $headers[] = 'X-API-Key: ' . $token;
    }

    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => $timeout,
    ]);
    if ($payload !== null) {
        $body = json_encode($payload);
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $response = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false || $status === 0) {
        error_log('FiscalPoint ML service unreachable: ' . $curlError);
        return ['ok' => false, 'status' => 0, 'data' => null,
                'error' => 'The prediction service is offline. Start it with: python -m fiscalpoint_ml.api (from the ml/ folder).'];
    }

    $data = json_decode($response, true);
    if (!is_array($data)) {
        return ['ok' => false, 'status' => $status, 'data' => null, 'error' => 'The prediction service returned an invalid response.'];
    }
    if ($status >= 400) {
        return ['ok' => false, 'status' => $status, 'data' => $data, 'error' => (string) ($data['error'] ?? "Prediction service error (HTTP $status).")];
    }
    return ['ok' => true, 'status' => $status, 'data' => $data, 'error' => null];
}

/** Human label for a risk level returned by /v1/budget-risk. */
function risk_badge(string $level): array
{
    return [
        'low' => ['Low risk', '#2e9e6b'],
        'medium' => ['Medium risk', '#e8a33a'],
        'high' => ['High risk', '#d9534f'],
        'exceeded' => ['Budget exceeded', '#b52b27'],
    ][$level] ?? ['Unknown', '#888888'];
}
