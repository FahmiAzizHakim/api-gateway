<?php

namespace App\Http\Controllers\Api;

use App\Services\Helper\UploadService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The gateway is the public origin for files: site assets and everything the
 * admins upload.
 *
 * Why it lives here rather than in each service: a logo, a product photo and a
 * payment receipt are all served to the same browser from the same host, so
 * one document root holds them and one place decides what may be read. The
 * services write into that root by pointing UPLOAD_PUBLIC_ROOT at it, and
 * store only the relative path in their own database.
 *
 * Only two prefixes are reachable -- uploads/ and webassets/ -- and the path
 * is resolved before it is checked, so no amount of ../ escapes them.
 */
class FileController extends ApiController
{
    /**
     * Roots a request may read from, relative to the public directory.
     */
    protected const ROOTS = ['uploads', 'webassets'];

    /**
     * Folders an upload may be filed under. A payload cannot invent one.
     */
    protected const FOLDERS = [
        'website', 'banner', 'content', 'about', 'client',
        'service', 'product', 'bank', 'transaction', 'qris',
    ];

    /**
     * Serve one file. Answers 404 for anything outside the allowed roots, so a
     * probe cannot tell a blocked path from a missing one.
     */
    public function show(string $path)
    {
        $full = realpath(public_path($path));

        if (!$full || !is_file($full) || !$this->isAllowed($full)) {
            return $this->notFound('File');
        }

        return (new BinaryFileResponse($full))
            // These are content-addressed by their generated filename, so a
            // long cache is safe: a replacement gets a new name.
            ->setMaxAge(31536000)
            ->setPublic();
    }

    /**
     * Store an upload and answer with the path to record.
     *
     * A service that would rather not hold files itself posts here and keeps
     * the returned path; one that already writes into the shared root (the
     * usual case) does not need this at all.
     */
    public function store(Request $request, UploadService $upload)
    {
        $data = $request->validate([
            'file'   => 'required|file|max:5120',
            'folder' => ['required', 'string', 'in:' . implode(',', self::FOLDERS)],
        ]);

        $stored = $upload->store($request->file('file'), $data['folder']);

        if (!$stored) {
            return response()->json(['message' => 'Upload failed'], 422);
        }

        return response()->json([
            'message' => 'Uploaded',
            'data'    => [
                // What the calling service saves in its own row.
                'path'     => $stored['uploaded'],
                'original' => $stored['original'],
                'url'      => asset($stored['uploaded']),
            ],
        ], 201);
    }

    /**
     * Whether a resolved absolute path sits inside one of the allowed roots.
     */
    protected function isAllowed(string $full): bool
    {
        foreach (self::ROOTS as $root) {
            $base = realpath(public_path($root));

            if ($base && str_starts_with($full, $base . DIRECTORY_SEPARATOR)) {
                return true;
            }
        }

        return false;
    }
}
