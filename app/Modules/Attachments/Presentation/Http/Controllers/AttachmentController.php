<?php
declare(strict_types=1);
namespace Nyx\Modules\Attachments\Presentation\Http\Controllers;
use Nyx\Core\Http\Request; use Nyx\Core\Http\JsonResponse;
use Nyx\Shared\Infrastructure\Http\ApiEnvelope;
use Nyx\Shared\Infrastructure\IdGenerator\UlidLikeId;
use Nyx\Shared\Infrastructure\Storage\FileStore;
use Nyx\Shared\Infrastructure\Http\AuthContext;

final class AttachmentController {
    private function store(): FileStore {
        return new FileStore(defined('DATA_DIR') ? DATA_DIR : '');
    }
    private function blobDir(): string {
        $dir = (defined('DATA_DIR') ? DATA_DIR : '') . '/attachments/blobs';
        if (!is_dir($dir)) @mkdir($dir, 0750, true);
        return $dir;
    }
    private function host(): string {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
        return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }
    private function urlFor(string $id): string {
        return $this->host() . '/nyx/api/v3/attachments/download?id=' . rawurlencode($id);
    }
    private function saveBlob(string $id, string $raw, array $metaIn = []): array {
        $safe = preg_replace('/[^a-zA-Z0-9_\-]/', '', $id);
        $path = $this->blobDir() . '/' . $safe . '.bin';
        file_put_contents($path, $raw);
        $s = $this->store();
        $meta = $s->get("attachments/{$id}.json") ?? ['id' => $id];
        $meta = array_merge($meta, $metaIn);
        $meta['id'] = $id;
        $meta['size'] = strlen($raw);
        $meta['hash'] = hash('sha256', $raw);
        $meta['blob_path'] = "attachments/blobs/{$safe}.bin";
        $meta['uploaded_ms'] = ApiEnvelope::nowMs();
        $s->put("attachments/{$id}.json", $meta);
        return $meta;
    }

    public function meta(Request $r): JsonResponse {
        $b = $r->json() ?? [];
        $uid = AuthContext::userId($r, $b);
        if ($uid === '') {
            return ApiEnvelope::error('unauthorized', 401);
        }
        $id = (string)($b['id'] ?? UlidLikeId::generate('att'));
        $rec = [
            'id' => $id,
            'name' => (string)($b['name'] ?? 'file'),
            'mime' => (string)($b['mime'] ?? 'application/octet-stream'),
            'size' => (int)($b['size'] ?? 0),
            'hash' => (string)($b['hash'] ?? ''),
            'user_id' => $uid,
            'encrypted' => !empty($b['encrypted']),
            'created_ms' => ApiEnvelope::nowMs(),
            'url' => $this->urlFor($id),
        ];
        $this->store()->put("attachments/{$id}.json", $rec);
        return ApiEnvelope::ok($rec);
    }

    public function init(Request $r): JsonResponse {
        // alias for clients that call /attachments/init
        return $this->meta($r);
    }
    public function upload(Request $r): JsonResponse {
        $b = $r->json() ?? [];
        $id = (string)($b['id'] ?? UlidLikeId::generate('att'));
        $raw = null;
        $name = (string)($b['name'] ?? 'file');
        $mime = (string)($b['mime'] ?? 'application/octet-stream');

        // Multipart FormData (browser XHR) — preferred path for small files
        if (!empty($_FILES) && is_array($_FILES)) {
            $file = $_FILES['file'] ?? $_FILES['attachment'] ?? null;
            if ($file === null) {
                $first = reset($_FILES);
                if (is_array($first)) $file = $first;
            }
            if (is_array($file) && (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                $tmp = (string)($file['tmp_name'] ?? '');
                if ($tmp !== '' && is_uploaded_file($tmp)) {
                    $raw = (string)file_get_contents($tmp);
                    if ($name === 'file' && !empty($file['name'])) $name = (string)$file['name'];
                    if ($mime === 'application/octet-stream' && !empty($file['type'])) $mime = (string)$file['type'];
                }
            }
        }

        // JSON base64 fallback
        if ($raw === null) {
            $b64 = (string)($b['data'] ?? $b['data_base64'] ?? $b['base64'] ?? $b['content'] ?? '');
            if ($b64 === '') return ApiEnvelope::error('data (base64) or multipart file required', 422);
            if (str_contains($b64, ',')) $b64 = substr($b64, strpos($b64, ',') + 1);
            $decoded = base64_decode($b64, true);
            if ($decoded === false) return ApiEnvelope::error('invalid base64', 422);
            $raw = $decoded;
        }

        if (strlen($raw) > 12 * 1024 * 1024) return ApiEnvelope::error('blob too large (max 12MB)', 413);
        $meta = $this->saveBlob($id, $raw, [
            'name' => $name,
            'mime' => $mime,
            'user_id' => (AuthContext::userId($r, $b) ?: (string)($b['user_id'] ?? '')),
            'encrypted' => !empty($b['encrypted']),
            'is_voice' => !empty($b['is_voice']) || !empty($b['isVoice']),
        ]);
        return ApiEnvelope::ok([
            'id' => $id, 'size' => $meta['size'], 'hash' => $meta['hash'],
            'stored' => true, 'url' => $this->urlFor($id), 'host' => $this->host() . '/nyx',
            'mime' => $meta['mime'] ?? $mime, 'name' => $meta['name'] ?? $name,
        ]);
    }

    /** Alias for legacy client POST /api/v3/uploads */
    public function uploadLegacy(Request $r): JsonResponse {
        return $this->upload($r);
    }

    /** Raw binary body POST /api/v3/uploads/raw */
    public function uploadRaw(Request $r): JsonResponse {
        $id = UlidLikeId::generate('att');
        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') return ApiEnvelope::error('empty body', 422);
        if (strlen($raw) > 12 * 1024 * 1024) return ApiEnvelope::error('blob too large (max 12MB)', 413);
        $name = (string)($_SERVER['HTTP_X_NYX_FILENAME'] ?? 'file');
        $mime = (string)($_SERVER['HTTP_X_NYX_MIME'] ?? ($_SERVER['CONTENT_TYPE'] ?? 'application/octet-stream'));
        $mime = explode(';', $mime)[0];
        $isVoice = !empty($_SERVER['HTTP_X_NYX_IS_VOICE']);
        $meta = $this->saveBlob($id, $raw, [
            'name' => $name, 'mime' => $mime, 'is_voice' => $isVoice, 'encrypted' => false,
        ]);
        return ApiEnvelope::ok([
            'id' => $id, 'size' => $meta['size'], 'hash' => $meta['hash'],
            'stored' => true, 'url' => $this->urlFor($id), 'host' => $this->host() . '/nyx',
            'mime' => $mime, 'name' => $name, 'isVoice' => $isVoice,
        ]);
    }

    public function get(Request $r): JsonResponse {
        $id = (string)($r->query('id') ?? ($r->json()['id'] ?? ''));
        if ($id === '') return ApiEnvelope::error('id required', 422);
        $meta = $this->store()->get("attachments/{$id}.json");
        if (!$meta) return ApiEnvelope::error('not found', 404);
        $include = ($r->query('include_data') ?? '') === '1';
        if ($include) {
            $safe = preg_replace('/[^a-zA-Z0-9_\-]/', '', $id);
            $blob = $this->blobDir() . '/' . $safe . '.bin';
            if (is_file($blob)) $meta['data'] = base64_encode((string)file_get_contents($blob));
        }
        $meta['url'] = $this->urlFor($id);
        return ApiEnvelope::ok($meta);
    }

    public function download(Request $r): JsonResponse {
        $id = (string)($r->query('id') ?? '');
        if ($id === '') {
            http_response_code(422);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => ['message' => 'id required']]);
            exit;
        }
        $safe = preg_replace('/[^a-zA-Z0-9_\-]/', '', $id);
        $meta = $this->store()->get("attachments/{$id}.json") ?: $this->store()->get("attachments/{$safe}.json");
        $blob = $this->blobDir() . '/' . $safe . '.bin';
        if (!is_file($blob)) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => ['message' => 'blob not found']]);
            exit;
        }
        $mime = is_array($meta) ? (string)($meta['mime'] ?? 'application/octet-stream') : 'application/octet-stream';
        $name = is_array($meta) ? (string)($meta['name'] ?? ($safe . '.bin')) : ($safe . '.bin');
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($blob));
        header('Content-Disposition: inline; filename="' . str_replace(['"', "\r", "\n"], '', $name) . '"');
        header('Cache-Control: private, max-age=3600');
        header('X-Content-Type-Options: nosniff');
        readfile($blob);
        exit;
    }
}