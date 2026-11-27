<?php
declare(strict_types=1);

namespace App\Core;

use PDO;

class FileStorage
{
    public static function driver(): string
    {
        $d = strtolower(trim((string) Env::get('STORAGE_DRIVER', 'local')));
        return $d === 'db' ? 'db' : 'local';
    }

    public static function save(
        PDO $pdo,
        int $submissionId,
        string $tmpPath,
        int $studentId,
        string $origName,
        string $ext,
        string $mimeType,
        int $fileSize
    ): string {
        if (self::driver() === 'db') {
            $bytes = file_get_contents($tmpPath);
            if ($bytes === false) {
                throw new \RuntimeException('Failed to read uploaded file.');
            }
            $stmt = $pdo->prepare(
                'INSERT INTO submission_files (submission_id, file_name, mime_type, file_size, content)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                   file_name = VALUES(file_name),
                   mime_type = VALUES(mime_type),
                   file_size = VALUES(file_size),
                   content   = VALUES(content),
                   created_at = CURRENT_TIMESTAMP'
            );
            $stmt->execute([$submissionId, $origName, $mimeType ?: 'application/octet-stream', $fileSize, $bytes]);
            return 'db:' . $submissionId;
        }

        $subDir = EPAP_ROOT . '/storage/uploads/' . $studentId;
        if (!is_dir($subDir) && !@mkdir($subDir, 0775, true) && !is_dir($subDir)) {
            throw new \RuntimeException('Failed to create upload directory.');
        }
        $storedName = bin2hex(random_bytes(16)) . '.' . $ext;
        $dest = $subDir . '/' . $storedName;
        if (!@move_uploaded_file($tmpPath, $dest) && !@rename($tmpPath, $dest)) {
            throw new \RuntimeException('Failed to store uploaded file.');
        }
        return $studentId . '/' . $storedName;
    }

    public static function stream(PDO $pdo, array $sub): void
    {
        $filePath = (string) ($sub['file_path'] ?? '');
        if ($filePath === '') {
            Response::error('Submission file not found.', 404);
        }
        $downloadName = basename((string) ($sub['original_name'] ?: $sub['file_name'] ?: 'submission'));

        if (str_starts_with($filePath, 'db:')) {
            $subId = (int) substr($filePath, 3);
            $stmt = $pdo->prepare('SELECT file_name, mime_type, file_size, content FROM submission_files WHERE submission_id = ? LIMIT 1');
            $stmt->execute([$subId]);
            $row = $stmt->fetch();
            if (!$row) {
                Response::error('File no longer exists in database.', 404);
            }
            header('Content-Type: ' . ($row['mime_type'] ?: 'application/octet-stream'));
            header('Content-Disposition: attachment; filename="' . basename((string) ($row['file_name'] ?: $downloadName)) . '"');
            header('Content-Length: ' . (int) $row['file_size']);
            echo $row['content'];
            exit;
        }

        $fullPath = EPAP_ROOT . '/storage/uploads/' . $filePath;
        if (!is_file($fullPath)) {
            Response::error('File no longer exists on server.', 404);
        }
        header('Content-Type: ' . ($sub['mime_type'] ?: 'application/octet-stream'));
        header('Content-Disposition: attachment; filename="' . $downloadName . '"');
        header('Content-Length: ' . filesize($fullPath));
        readfile($fullPath);
        exit;
    }
}
