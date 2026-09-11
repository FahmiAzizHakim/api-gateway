<?php

namespace App\Services\Helper;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class UploadService
{
    /**
     * The served document root, which is not always Laravel's public/.
     *
     * The web server's docroot is public_html while Laravel's public/ sits at
     * public_html/webapps/public, so a file has to be written to the former
     * for asset('uploads/...') to resolve. UPLOAD_PUBLIC_ROOT overrides it,
     * which is what CLI and queue runs need -- DOCUMENT_ROOT is empty there.
     *
     * Public because store() is no longer the only caller: reading a stored
     * file back (to forward it) and deleting one both have to resolve the same
     * root, and a second copy of this rule would be a second thing to get
     * wrong.
     */
    public function publicRoot(): string
    {
        return rtrim(
            env('UPLOAD_PUBLIC_ROOT') ?: ($_SERVER['DOCUMENT_ROOT'] ?? public_path()),
            '/'
        );
    }

    /**
     * Where a path this service returned actually lives on disk.
     *
     * Takes the relative path as stored in a row ('uploads/qris/foo.png') and
     * answers an absolute one.
     */
    public function absolutePath(string $relative): string
    {
        return $this->publicRoot() . '/' . ltrim($relative, '/');
    }

    /**
     * Remove a stored file, by the relative path a row holds.
     *
     * Answers true when the file is gone, including when it was already
     * missing: the caller's intent is "there should be no file here", and a
     * file someone removed by hand has not failed that.
     *
     * Only uploads/ is reachable. A row should never hold anything else, and
     * if one somehow did, this must not be the thing that deletes it.
     */
    public function delete(?string $relative): bool
    {
        $relative = ltrim((string) $relative, '/');

        if ($relative === '' || !str_starts_with($relative, 'uploads/')) {
            return false;
        }

        $full = realpath($this->absolutePath($relative));
        $root = realpath($this->publicRoot() . '/uploads');

        // Resolved before it is compared, so a path with ../ in it cannot
        // reach outside the uploads tree.
        if (!$full || !$root || !str_starts_with($full, $root . DIRECTORY_SEPARATOR)) {
            return !$full;
        }

        if (!is_file($full)) {
            return true;
        }

        return @unlink($full);
    }

    public function store($file, $path)
    {
        try {
            $originalname = $file->getClientOriginalName();
            $extension    = $file->getClientOriginalExtension();

            // Build a filesystem-safe, unique filename.
            // (base64 output can contain "/", "+" or "=" which break the path.)
            $base = Str::slug(pathinfo($originalname, PATHINFO_FILENAME));
            if ($base === '') {
                $base = 'file';
            }

            $filename = $base . '-' . date('YmdHis') . '-' . Str::random(6);
            if ($extension) {
                $filename .= '.' . $extension;
            }

            // Files must land in the served docroot so their URLs
            // (asset('uploads/...')) resolve -- see publicRoot().
            $targetDir = $this->publicRoot() . '/uploads/' . $path;

            // --- DEBUG: state before move ---
            Log::info('UploadService.store: before move', [
                'valid'        => $file->isValid(),
                'error'        => $file->getError(),          // 0 = UPLOAD_ERR_OK
                'tmp_path'     => $file->getRealPath(),
                'tmp_exists'   => $file->getRealPath() ? file_exists($file->getRealPath()) : false,
                'public_path'  => public_path(),
                'target_dir'   => $targetDir,
                'dir_exists'   => is_dir($targetDir),
                'dir_writable' => is_dir($targetDir) ? is_writable($targetDir) : is_writable(dirname($targetDir)),
                'filename'     => $filename,
            ]);

            $moved = $file->move($targetDir, $filename);

            $finalPath = $targetDir . DIRECTORY_SEPARATOR . $filename;

            // --- DEBUG: state after move ---
            Log::info('UploadService.store: after move', [
                'moved_realpath' => $moved->getRealPath(),
                'final_path'     => $finalPath,
                'final_exists'   => file_exists($finalPath),
                'final_size'     => file_exists($finalPath) ? filesize($finalPath) : null,
            ]);

            $ret = 'uploads/' . $path . '/' . $filename;
        } catch (\Throwable $e) {
            // --- DEBUG: log the real reason instead of hiding it ---
            Log::error('UploadService.store: failed', [
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
            ]);
            return false;
        }

        return [
            "original" => $originalname,
            "uploaded" => $ret,
        ];
    }
}
