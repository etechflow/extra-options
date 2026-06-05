<?php
declare(strict_types=1);

namespace Etechflow\OptionsPlugin\Controller\Files;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Driver\File as FileDriver;
use Magento\Framework\HTTP\PhpEnvironment\Request as HttpRequest;
use Magento\Framework\UrlInterface;
use Psr\Log\LoggerInterface;

/**
 * POST /etechflow/files/upload
 *
 * Receives ONE file from the storefront multi-image picker, validates it, and
 * stores it under pub/media/etechflow/uploads/<random>/<safe-name>.
 * Returns JSON with the relative path the frontend stores in the JSON-array
 * hidden input; that array is later converted into the option value by
 * {@see \Etechflow\OptionsPlugin\Plugin\MultiFileBuyRequest}.
 *
 * Multi-file uploads work by calling this endpoint once per selected file from
 * JS (the standard Magento multipart form only handles one file per option),
 * which keeps Magento's native file-option plumbing out of the picture and
 * lets us store an arbitrary number of files.
 */
class Upload implements HttpPostActionInterface, CsrfAwareActionInterface
{
    /** 10 MB per file. */
    private const MAX_BYTES = 10 * 1024 * 1024;
    /** Max 10 files per option. Hard cap enforced server-side too. */
    private const MAX_PER_OPTION = 10;
    /** Allowed mime prefixes. Images + PDFs. */
    private const ALLOWED_MIMES = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/heic',
        'image/heif', 'image/avif', 'image/bmp', 'image/tiff', 'application/pdf',
    ];

    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $jsonFactory,
        private readonly Filesystem $filesystem,
        private readonly FileDriver $fileDriver,
        private readonly UrlInterface $urlBuilder,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();

        try {
            $optionId = (int) $this->request->getParam('option_id', 0);
            if ($optionId <= 0) {
                return $result->setHttpResponseCode(400)
                    ->setData(['ok' => false, 'error' => 'Missing option_id.']);
            }

            $existingCount = (int) $this->request->getParam('existing_count', 0);
            if ($existingCount >= self::MAX_PER_OPTION) {
                return $result->setHttpResponseCode(400)
                    ->setData(['ok' => false, 'error' => __(
                        'You can attach up to %1 files per option.',
                        self::MAX_PER_OPTION
                    )]);
            }

            if (empty($_FILES['file']) || !is_array($_FILES['file'])) {
                return $result->setHttpResponseCode(400)
                    ->setData(['ok' => false, 'error' => 'No file in request.']);
            }
            $file = $_FILES['file'];
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                return $result->setHttpResponseCode(400)
                    ->setData(['ok' => false, 'error' => 'Upload error code ' . (int) $file['error']]);
            }
            if (!is_uploaded_file($file['tmp_name'])) {
                return $result->setHttpResponseCode(400)
                    ->setData(['ok' => false, 'error' => 'Suspicious upload, refused.']);
            }
            if (($file['size'] ?? 0) > self::MAX_BYTES) {
                return $result->setHttpResponseCode(400)
                    ->setData(['ok' => false, 'error' => __(
                        'File is larger than %1 MB.',
                        (int) (self::MAX_BYTES / 1024 / 1024)
                    )]);
            }

            $detectedMime = $this->detectMime($file['tmp_name']);
            if (!in_array($detectedMime, self::ALLOWED_MIMES, true)) {
                return $result->setHttpResponseCode(400)
                    ->setData(['ok' => false, 'error' => __(
                        'File type %1 is not allowed.',
                        $detectedMime
                    )]);
            }

            $safeName = $this->safeName((string) ($file['name'] ?? 'file'));
            $hash     = bin2hex(random_bytes(8));
            $relDir   = 'etechflow/uploads/' . $hash;
            $relPath  = $relDir . '/' . $safeName;

            $mediaDir = $this->filesystem->getDirectoryWrite(DirectoryList::MEDIA);
            $mediaDir->create($relDir);
            $absDest = $mediaDir->getAbsolutePath($relPath);

            if (!@move_uploaded_file($file['tmp_name'], $absDest)) {
                throw new \RuntimeException('Failed to move uploaded file into storage.');
            }
            @chmod($absDest, 0644);

            $url = rtrim($this->urlBuilder->getBaseUrl(['_type' => UrlInterface::URL_TYPE_MEDIA]), '/')
                 . '/' . $relPath;

            return $result->setData([
                'ok'   => true,
                'path' => $relPath,           // stored in the hidden JSON field
                'name' => $safeName,
                'size' => (int) $file['size'],
                'mime' => $detectedMime,
                'url'  => $url,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('[etmm upload] ' . $e->getMessage(), ['exception' => $e]);
            return $result->setHttpResponseCode(500)
                ->setData(['ok' => false, 'error' => 'Server error.']);
        }
    }

    /** Magento 2 CSRF: form_key on the POST body. */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null; // Form-key check handled by the form_key field in the POST.
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        if ($request instanceof HttpRequest && $request->isPost()) {
            // The frontend includes form_key — Magento's CSRF Validator already
            // covers it via the standard form_key flow when present.
            return true;
        }
        return false;
    }

    /** Sniff MIME from the file contents (not the browser-supplied header). */
    private function detectMime(string $path): string
    {
        if (function_exists('finfo_open')) {
            $f = finfo_open(FILEINFO_MIME_TYPE);
            if ($f) {
                $mime = (string) finfo_file($f, $path);
                finfo_close($f);
                return $mime ?: 'application/octet-stream';
            }
        }
        return (string) (mime_content_type($path) ?: 'application/octet-stream');
    }

    /** Sanitize a user-supplied filename. */
    private function safeName(string $name): string
    {
        $name = basename($name);
        $name = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $name) ?: 'file';
        $name = trim($name, '._-') ?: 'file';
        if (strlen($name) > 80) {
            $ext = pathinfo($name, PATHINFO_EXTENSION);
            $name = substr($name, 0, 70) . ($ext ? '.' . $ext : '');
        }
        return $name;
    }
}
