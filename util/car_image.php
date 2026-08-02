<?php

const CAR_IMAGE_MAX_BYTES = 5242880;
const CAR_IMAGE_MAX_DIMENSION = 1600;
const CAR_IMAGE_JPEG_QUALITY = 82;
const CAR_IMAGE_WEBP_QUALITY = 82;

// Small-width siblings (e.g. car/400/<id>-civic.jpg) so grid/thumb views skip the 1600px original.
const CAR_IMAGE_DERIVATIVE_WIDTHS = [400, 800];

// Conservative ceiling for shared hosting; above this, skip GD and reject the upload outright.
const CAR_IMAGE_DECODE_CEILING_BYTES = 256 * 1024 * 1024;

/** Decode ceiling, overridable via CARGO_DECODE_CEILING_MB for the CLI backfill script. */
function carImageDecodeCeilingBytes(): int
{
    $overrideMb = getenv('CARGO_DECODE_CEILING_MB');
    if ($overrideMb !== false && $overrideMb !== '' && ctype_digit((string) $overrideMb)) {
        return (int) $overrideMb * 1024 * 1024;
    }

    return CAR_IMAGE_DECODE_CEILING_BYTES;
}

// Validates an uploaded car photo, resizes/saves it, and returns its stored 'car/...' path.
function processCarImageUpload(?array $file): string
{
    if ($file === null || !isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return '';
    }

    if (!is_int($file['error'])) {
        throw new InvalidArgumentException('Could not process the uploaded image.');
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException(getCarImageUploadErrorMessage($file['error']));
    }

    if (!isset($file['tmp_name'], $file['name'], $file['size']) || !is_uploaded_file($file['tmp_name'])) {
        throw new InvalidArgumentException('Please upload a valid car image.');
    }

    if ((int) $file['size'] > CAR_IMAGE_MAX_BYTES) {
        throw new InvalidArgumentException('Car image must be 5 MB or smaller.');
    }

    $imageInfo = getimagesize($file['tmp_name']);
    if ($imageInfo === false) {
        throw new InvalidArgumentException('Please upload a valid image file.');
    }

    $mimeType = mime_content_type($file['tmp_name']);
    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];

    if (!isset($extensions[$mimeType])) {
        throw new InvalidArgumentException('Car image must be a JPG, PNG, GIF, or WebP file.');
    }

    // A small file can still be too high-resolution to safely decode (uncatchable OOM fatal) - reject up front.
    $channels = $imageInfo['channels'] ?? 4;
    if (estimateImageDecodeBytes($imageInfo[0], $imageInfo[1], $channels) > carImageDecodeCeilingBytes()) {
        throw new InvalidArgumentException(
            'This image resolution is too high to process. Please upload an image no larger than '
            . 'about 35 megapixels (for example 7000x5000 pixels).'
        );
    }

    $uploadDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'car';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true)) {
        throw new InvalidArgumentException('Could not prepare the car image folder.');
    }

    if (!is_writable($uploadDir)) {
        throw new InvalidArgumentException('Car image folder is not writable.');
    }

    $filename = buildCarImageFilename((string) $file['name'], $extensions[$mimeType]);
    $destination = $uploadDir . DIRECTORY_SEPARATOR . $filename;

    if (!saveResizedCarImage($file['tmp_name'], $mimeType, $destination)) {
        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            throw new InvalidArgumentException('Could not save the uploaded car image.');
        }
    }

    return 'car/' . $filename;
}

/**
 * Downscale and re-encode to keep stored car photos small. Returns false if GD can't handle this
 * image, so the caller falls back to a plain copy of the original upload.
 */
function saveResizedCarImage(string $sourcePath, string $mimeType, string $destination): bool
{
    if (!extension_loaded('gd')) {
        return false;
    }

    // Check dimensions cheaply via getimagesize() before a full GD decode, which can OOM-fatal.
    $info = @getimagesize($sourcePath);
    if ($info === false) {
        return false;
    }

    if (!ensureEnoughMemoryToDecodeImage($info[0], $info[1], $info['channels'] ?? 4)) {
        return false;
    }

    $image = loadCarImageResource($sourcePath, $mimeType);
    if ($image === false) {
        return false;
    }

    $width = imagesx($image);
    $height = imagesy($image);
    $longestSide = max($width, $height);

    if ($longestSide > CAR_IMAGE_MAX_DIMENSION) {
        $scale = CAR_IMAGE_MAX_DIMENSION / $longestSide;
        $resized = imagescale($image, (int) round($width * $scale), (int) round($height * $scale));
        if ($resized !== false) {
            imagedestroy($image);
            $image = $resized;
        }
    }

    $saved = writeCarImageResource($image, $mimeType, $destination);

    if ($saved) {
        // Best-effort: a derivative failure must never take down an otherwise-successful upload.
        try {
            generateCarImageDerivatives('car/' . basename($destination), $image, $mimeType);
        } catch (\Throwable $e) {
            // Swallow: fall back to serving the full-size original for this car.
        }
    }

    imagedestroy($image);

    return $saved;
}

/**
 * Raise memory_limit if needed to decode an image this size. Returns false if the estimated
 * need exceeds the decode ceiling, so the caller skips GD and keeps the original file.
 */
function ensureEnoughMemoryToDecodeImage(int $width, int $height, int $channels): bool
{
    $bytesNeeded = estimateImageDecodeBytes($width, $height, $channels);

    if ($bytesNeeded > carImageDecodeCeilingBytes()) {
        return false;
    }

    $currentLimitBytes = parsePhpIniBytes((string) ini_get('memory_limit'));
    if ($currentLimitBytes !== -1 && $currentLimitBytes < $bytesNeeded) {
        @ini_set('memory_limit', (string) $bytesNeeded);
    }

    return true;
}

/** Estimate GD's decode+process memory need: ~4 bytes/pixel plus a safety margin. */
function estimateImageDecodeBytes(int $width, int $height, int $channels): int
{
    $bytesPerPixel = max($channels, 3) + 1;

    return (int) round($width * $height * $bytesPerPixel * 1.8) + (4 * 1024 * 1024);
}

// Convert a php.ini shorthand size (e.g. "128M", "-1") to bytes.
function parsePhpIniBytes(string $value): int
{
    $value = trim($value);
    if ($value === '' || $value === '-1') {
        return -1;
    }

    $unit = strtolower(substr($value, -1));
    $number = (int) $value;

    return match ($unit) {
        'g' => $number * 1024 * 1024 * 1024,
        'm' => $number * 1024 * 1024,
        'k' => $number * 1024,
        default => $number,
    };
}

/**
 * Decodes an image file into a GD resource, picking the right imagecreatefrom* by mime type.
 * @return \GdImage|false
 */
function loadCarImageResource(string $sourcePath, string $mimeType)
{
    switch ($mimeType) {
        case 'image/jpeg':
            return @imagecreatefromjpeg($sourcePath);
        case 'image/png':
            return @imagecreatefrompng($sourcePath);
        case 'image/gif':
            return @imagecreatefromgif($sourcePath);
        case 'image/webp':
            return function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($sourcePath) : false;
        default:
            return false;
    }
}

/**
 * Encodes a GD resource to disk in its own format, picking the right imagejpeg/png/gif/webp call.
 * @param \GdImage $image
 */
function writeCarImageResource($image, string $mimeType, string $destination): bool
{
    switch ($mimeType) {
        case 'image/jpeg':
            return imagejpeg($image, $destination, CAR_IMAGE_JPEG_QUALITY);
        case 'image/png':
            // Preserve transparency; PNG compression level 0-9 (9 = smallest, slower).
            imagesavealpha($image, true);
            return imagepng($image, $destination, 6);
        case 'image/gif':
            return imagegif($image, $destination);
        case 'image/webp':
            return function_exists('imagewebp') ? imagewebp($image, $destination, CAR_IMAGE_WEBP_QUALITY) : false;
        default:
            return false;
    }
}

/**
 * Write the small-width JPEG/WebP siblings plus a full-size WebP, reusing the already-decoded
 * $image resource instead of re-reading the file from disk.
 * @param \GdImage $image The final (already downscaled) resource.
 */
function generateCarImageDerivatives(string $imagePath, $image, string $mimeType): void
{
    $originalExtension = strtolower(pathinfo($imagePath, PATHINFO_EXTENSION));

    // Skip full-size WebP if the upload was already WebP - it'd just duplicate the original.
    if ($originalExtension !== 'webp') {
        writeCarImageDerivativeFile($imagePath, $image, null, 'webp');
    }

    $width = imagesx($image);
    foreach (CAR_IMAGE_DERIVATIVE_WIDTHS as $targetWidth) {
        if ($targetWidth >= $width) {
            continue; // never upscale a derivative past the source
        }

        writeCarImageDerivativeFile($imagePath, $image, $targetWidth, 'jpg');
        writeCarImageDerivativeFile($imagePath, $image, $targetWidth, 'webp');
    }
}

/**
 * Scales (if needed), flattens (if JPEG), and writes one derivative sibling file.
 * @param \GdImage $image
 */
function writeCarImageDerivativeFile(string $imagePath, $image, ?int $targetWidth, string $extension): void
{
    if ($extension === 'webp' && !function_exists('imagewebp')) {
        return;
    }

    $derivative = $image;
    $ownsDerivative = false;

    if ($targetWidth !== null) {
        $scaled = imagescale($image, $targetWidth, -1, IMG_BICUBIC);
        if ($scaled === false) {
            return;
        }
        $derivative = $scaled;
        $ownsDerivative = true;
    }

    if ($extension === 'jpg') {
        // JPEG has no alpha channel; flatten onto white first so a
        // transparent PNG/WebP/GIF source doesn't turn black.
        $flattened = flattenCarImageForJpeg($derivative);
        if ($ownsDerivative) {
            imagedestroy($derivative);
        }
        $derivative = $flattened;
        $ownsDerivative = true;
    }

    $relativePath = carImageDerivativePath($imagePath, $targetWidth, $extension);
    $diskPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

    $diskDir = dirname($diskPath);
    if (!is_dir($diskDir) && !mkdir($diskDir, 0775, true) && !is_dir($diskDir)) {
        if ($ownsDerivative) {
            imagedestroy($derivative);
        }

        return;
    }

    if ($extension === 'webp') {
        @imagewebp($derivative, $diskPath, CAR_IMAGE_WEBP_QUALITY);
    } else {
        @imagejpeg($derivative, $diskPath, CAR_IMAGE_JPEG_QUALITY);
    }

    if ($ownsDerivative) {
        imagedestroy($derivative);
    }
}

// Flatten onto white so a transparent source doesn't render black in JPEG (no alpha channel).
function flattenCarImageForJpeg($image)
{
    $width = imagesx($image);
    $height = imagesy($image);

    $flattened = imagecreatetruecolor($width, $height);
    $white = imagecolorallocate($flattened, 255, 255, 255);
    imagefill($flattened, 0, 0, $white);
    imagealphablending($flattened, true);
    imagesavealpha($flattened, false);
    imagecopy($flattened, $image, 0, 0, 0, 0, $width, $height);

    return $flattened;
}

/**
 * Naming scheme for derivative siblings, e.g. 'car/abcd-civic.jpg' + 400 + 'webp' -> 'car/400/abcd-civic.webp'.
 * Single source of truth, shared by the writer here and the reader in car_display.php.
 */
function carImageDerivativePath(string $imagePath, ?int $width, string $extension): string
{
    if ($imagePath === '' || str_contains($imagePath, '..') || !str_starts_with($imagePath, 'car/')) {
        throw new InvalidArgumentException('Invalid car image path.');
    }

    $directory = dirname($imagePath);
    $baseName = pathinfo($imagePath, PATHINFO_FILENAME);

    if ($width !== null) {
        return $directory . '/' . $width . '/' . $baseName . '.' . $extension;
    }

    return $directory . '/' . $baseName . '.' . $extension;
}

/**
 * Every derivative path generateCarImageDerivatives() could have written for $imagePath,
 * whether or not it actually exists - deleteCarImageFile() attempts all of them safely.
 * @return string[]
 */
function carImageAllDerivativeCandidates(string $imagePath): array
{
    $candidates = [carImageDerivativePath($imagePath, null, 'webp')];

    foreach (CAR_IMAGE_DERIVATIVE_WIDTHS as $width) {
        $candidates[] = carImageDerivativePath($imagePath, $width, 'jpg');
        $candidates[] = carImageDerivativePath($imagePath, $width, 'webp');
    }

    return $candidates;
}

// Removes a car's original image and every derivative sibling from disk.
function deleteCarImageFile(string $imagePath): void
{
    if ($imagePath === '' || str_contains($imagePath, '..') || !str_starts_with($imagePath, 'car/')) {
        return;
    }

    $filePath = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $imagePath);
    if (is_file($filePath)) {
        unlink($filePath);
    }

    foreach (carImageAllDerivativeCandidates($imagePath) as $derivativePath) {
        if ($derivativePath === $imagePath) {
            continue;
        }

        $derivativeDiskPath = dirname(__DIR__) . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $derivativePath);
        if (is_file($derivativeDiskPath)) {
            unlink($derivativeDiskPath);
        }
    }
}

// Unique on-disk filename: random 8-byte prefix plus a slugified version of the original name.
function buildCarImageFilename(string $originalName, string $extension): string
{
    $baseName = pathinfo($originalName, PATHINFO_FILENAME);
    $baseName = strtolower($baseName);
    $baseName = preg_replace('/[^a-z0-9]+/', '-', $baseName);
    $baseName = trim((string) $baseName, '-');

    if ($baseName === '') {
        $baseName = 'car';
    }

    $baseName = substr($baseName, 0, 60);
    $uniqueId = bin2hex(random_bytes(8));

    return $uniqueId . '-' . $baseName . '.' . $extension;
}

// User-facing message for a PHP upload error code.
function getCarImageUploadErrorMessage(int $errorCode): string
{
    if ($errorCode === UPLOAD_ERR_INI_SIZE || $errorCode === UPLOAD_ERR_FORM_SIZE) {
        return 'Car image must be 5 MB or smaller.';
    }

    return 'Could not upload the car image. Please try again.';
}
