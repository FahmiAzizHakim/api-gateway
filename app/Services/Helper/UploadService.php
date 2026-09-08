<?php

namespace App\Services\Helper;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class UploadService
{
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

            // The web server's document root is public_html, but Laravel's
            // public/ lives at public_html/webapps/public. Files must land in
            // the served docroot so their URLs (asset('uploads/...')) resolve.
            // Set UPLOAD_PUBLIC_ROOT in .env to override (e.g. for CLI/queue,
            // where DOCUMENT_ROOT is empty).
            $publicRoot = rtrim(
                env('UPLOAD_PUBLIC_ROOT') ?: ($_SERVER['DOCUMENT_ROOT'] ?? public_path()),
                '/'
            );
            $targetDir = $publicRoot . '/uploads/' . $path;

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
