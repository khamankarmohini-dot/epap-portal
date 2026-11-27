<?php
declare(strict_types=1);

namespace App\Core;

class Judge0Client
{
    private const LANGUAGE_IDS = [
        'python' => 71, // Python 3.8+
        'java'   => 62, // Java (OpenJDK 13+)
        'cpp'    => 54, // C++ (GCC 9.2+)
        'c'      => 50, // C (GCC 9.2+)
    ];

    public static function isConfigured(): bool
    {
        return trim((string) Env::get('JUDGE0_URL', '')) !== '';
    }

    public static function execute(string $language, string $sourceCode, string $stdin = ''): array
    {
        $baseUrl = rtrim((string) Env::get('JUDGE0_URL', ''), '/');
        if ($baseUrl === '') {
            return [
                'mode' => 'demo',
                'configured' => false,
                'status' => 'Demo Mode (Judge0 Not Configured)',
                'stdout' => null,
                'stderr' => null,
                'compile_output' => null,
                'time_ms' => null,
                'memory_kb' => null,
                'message' => 'Judge0 execution endpoint (JUDGE0_URL) is not configured in .env. Code was validated and recorded without remote sandbox execution.',
            ];
        }

        $langId = self::LANGUAGE_IDS[$language] ?? 71;
        $apiKey = (string) Env::get('JUDGE0_API_KEY', '');

        $payload = json_encode([
            'language_id' => $langId,
            'source_code' => base64_encode($sourceCode),
            'stdin'       => base64_encode($stdin),
        ]);

        $headers = [
            'Content-Type: application/json',
        ];
        if ($apiKey !== '') {
            $headers[] = 'X-RapidAPI-Key: ' . $apiKey;
            $headers[] = 'X-Auth-Token: ' . $apiKey;
        }

        $ch = curl_init($baseUrl . '/submissions?base64_encoded=true&wait=true');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
        ]);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $httpCode >= 400) {
            Logger::error('Judge0 request failed', ['http' => $httpCode, 'err' => $err]);
            return [
                'mode' => 'judge0',
                'configured' => true,
                'status' => 'Execution Error',
                'message' => 'Judge0 sandbox returned an error (HTTP ' . $httpCode . ').',
            ];
        }

        $res = json_decode($raw, true) ?: [];
        return [
            'mode' => 'judge0',
            'configured' => true,
            'status' => $res['status']['description'] ?? 'Unknown',
            'status_id' => $res['status']['id'] ?? 0,
            'stdout' => isset($res['stdout']) ? base64_decode((string) $res['stdout']) : '',
            'stderr' => isset($res['stderr']) ? base64_decode((string) $res['stderr']) : '',
            'compile_output' => isset($res['compile_output']) ? base64_decode((string) $res['compile_output']) : '',
            'time_ms' => isset($res['time']) ? (int) round(((float) $res['time']) * 1000) : null,
            'memory_kb' => $res['memory'] ?? null,
        ];
    }
}
