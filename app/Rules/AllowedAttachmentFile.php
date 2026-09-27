<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * Validates uploaded attachment files against an allowlist of extensions and MIME types.
 * Rejects dangerous file types like executables, scripts, and archives that could be harmful.
 */
class AllowedAttachmentFile implements ValidationRule
{
    /**
     * Safe extensions for email attachments (lowercase).
     *
     * @var list<string>
     */
    public const ALLOWED_EXTENSIONS = [
        'pdf',
        'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp',
        'rtf', 'txt', 'csv',
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'avif', 'heic', 'heif', 'tiff', 'tif',
        'mp4', 'mov', 'avi', 'webm', 'mkv',
        'mp3', 'wav', 'ogg', 'aac', 'm4a',
        'zip', 'rar', '7z', 'tar', 'gz',
        'eml', 'msg', 'ics',
    ];

    /**
     * MIME types that are safe for email attachments.
     * This is checked against the detected MIME type (not client-provided).
     *
     * @var list<string>
     */
    public const ALLOWED_MIME_TYPES = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'application/vnd.oasis.opendocument.text',
        'application/vnd.oasis.opendocument.spreadsheet',
        'application/vnd.oasis.opendocument.presentation',
        'application/rtf',
        'text/plain',
        'text/csv',
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/bmp',
        'image/avif',
        'image/heic',
        'image/heif',
        'image/tiff',
        'video/mp4',
        'video/quicktime',
        'video/x-msvideo',
        'video/webm',
        'video/x-matroska',
        'audio/mpeg',
        'audio/wav',
        'audio/x-wav',
        'audio/ogg',
        'audio/aac',
        'audio/mp4',
        'audio/x-m4a',
        'application/zip',
        'application/x-rar-compressed',
        'application/vnd.rar',
        'application/x-7z-compressed',
        'application/x-tar',
        'application/gzip',
        'application/x-gzip',
        'message/rfc822',
        'application/vnd.ms-outlook',
        'text/calendar',
        'application/octet-stream',
    ];

    /**
     * Dangerous extensions that should always be blocked (executables, scripts, etc.).
     *
     * @var list<string>
     */
    public const BLOCKED_EXTENSIONS = [
        'exe', 'dll', 'bat', 'cmd', 'com', 'msi', 'msp', 'scr', 'pif', 'hta',
        'js', 'jse', 'vbs', 'vbe', 'wsf', 'wsh', 'ps1', 'psm1', 'psd1',
        'sh', 'bash', 'zsh', 'csh', 'ksh',
        'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'phps',
        'pl', 'pm', 'py', 'pyc', 'pyo', 'rb', 'asp', 'aspx', 'jsp', 'cgi',
        'app', 'dmg', 'pkg', 'deb', 'rpm', 'apk',
        'jar', 'class', 'war',
        'lnk', 'inf', 'reg', 'scf',
        'svg', 'svgz',
        'swf', 'xap',
        'html', 'htm', 'xhtml', 'shtml', 'mht', 'mhtml',
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile) {
            $fail('A valid file must be uploaded.');

            return;
        }

        $extension = strtolower($value->getClientOriginalExtension());
        $detectedMime = strtolower($value->getMimeType() ?? '');

        if (in_array($extension, self::BLOCKED_EXTENSIONS, true)) {
            $fail('This file type is not allowed for security reasons.');

            return;
        }

        if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            $fail('This file type is not supported. Allowed: PDF, images, documents, audio, and video files.');

            return;
        }

        if ($detectedMime !== '' && ! in_array($detectedMime, self::ALLOWED_MIME_TYPES, true)) {
            $fail('The file content does not match an allowed type.');

            return;
        }
    }
}
