<?php

namespace App\Utils\Repositories;

use App\Utils\Services\Base64UtilityService;

use function in_array;
use function is_string;
use function strlen;

class Base64UtilityRepository implements Base64UtilityService
{
    private const MAX_IMAGE_BYTES = 2097152;

    private const MAX_IMAGE_PIXELS = 10000000;

    private const JPEG_QUALITY = 92;

    private const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
    ];

    public function sanitize(string $data): ?string
    {
        $base64 = $this->extractBase64Payload($data);

        if ($base64 === null) {
            return null;
        }

        $decoded = base64_decode($base64, true);

        if (
            $decoded === false
            || $decoded === ''
            || strlen($decoded) > self::MAX_IMAGE_BYTES
        ) {
            return null;
        }

        $imageInfo = @getimagesizefromstring($decoded);

        if ($imageInfo === false || empty($imageInfo['mime'])) {
            return null;
        }

        $mimeType = strtolower($imageInfo['mime']);
        $width = (int) ($imageInfo[0] ?? 0);
        $height = (int) ($imageInfo[1] ?? 0);

        if (
            ! in_array($mimeType, self::ALLOWED_MIME_TYPES, true)
            || $width <= 0
            || $height <= 0
            || ($width * $height) > self::MAX_IMAGE_PIXELS
            || ! function_exists('imagecreatefromstring')
        ) {
            return null;
        }

        $image = @imagecreatefromstring($decoded);

        if ($image === false) {
            return null;
        }

        try {
            $sanitized = $this->encodeCleanImage($image, $mimeType);
        } finally {
            unset($image);
        }

        if ($sanitized === null || strlen($sanitized) > self::MAX_IMAGE_BYTES) {
            return null;
        }

        return base64_encode($sanitized);
    }

    public function validate(string $data): ?string
    {
        $base64 = $this->extractBase64Payload($data);

        if ($base64 === null) {
            return null;
        }

        $decoded = base64_decode($base64, true);

        if (
            $decoded === false
            || $decoded === ''
            || strlen($decoded) > self::MAX_IMAGE_BYTES
            || ! $this->hasValidImageStructure($decoded)
        ) {
            return null;
        }

        $imageInfo = @getimagesizefromstring($decoded);

        if ($imageInfo === false || empty($imageInfo['mime'])) {
            return null;
        }

        $width = (int) ($imageInfo[0] ?? 0);
        $height = (int) ($imageInfo[1] ?? 0);

        if (
            ! in_array(strtolower($imageInfo['mime']), self::ALLOWED_MIME_TYPES, true)
            || $width <= 0
            || $height <= 0
            || ($width * $height) > self::MAX_IMAGE_PIXELS
        ) {
            return null;
        }

        return base64_encode($decoded);
    }

    private function extractBase64Payload(string $data): ?string
    {
        $data = trim($data);

        if ($data === '') {
            return null;
        }

        if (str_starts_with(strtolower($data), 'data:')) {
            if (! preg_match('/^data:(image\/[a-z0-9.+-]+);base64,(.*)$/is', $data, $matches)) {
                return null;
            }

            if (! in_array(strtolower($matches[1]), self::ALLOWED_MIME_TYPES, true)) {
                return null;
            }

            $data = $matches[2];
        }

        $data = preg_replace('/\s+/', '', $data);

        if (! is_string($data) || $data === '' || ! preg_match('/^[A-Za-z0-9+\/]*={0,2}$/', $data)) {
            return null;
        }

        if (strlen($data) % 4 !== 0) {
            return null;
        }

        return $data;
    }

    private function encodeCleanImage(\GdImage $image, string $mimeType): ?string
    {
        ob_start();

        $encoded = match ($mimeType) {
            'image/jpeg' => $this->encodeCleanJpeg($image),
            'image/png' => $this->encodeCleanPng($image),
            default => false,
        };

        $contents = ob_get_clean();

        if ($encoded === false || $contents === false || $contents === '') {
            return null;
        }

        return $contents;
    }

    private function encodeCleanPng(\GdImage $image): bool
    {
        imagealphablending($image, false);
        imagesavealpha($image, true);

        return imagepng($image, null, 9, PNG_ALL_FILTERS);
    }

    private function encodeCleanJpeg(\GdImage $image): bool
    {
        imageinterlace($image, true);

        return imagejpeg($image, null, self::JPEG_QUALITY);
    }

    private function hasValidImageStructure(string $binary): bool
    {
        if (str_starts_with($binary, "\x89PNG\r\n\x1A\n")) {
            return str_ends_with($binary, "\x00\x00\x00\x00IEND\xAE\x42\x60\x82");
        }

        return str_starts_with($binary, "\xFF\xD8")
            && str_ends_with($binary, "\xFF\xD9");
    }
}
