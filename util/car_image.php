<?php

const CAR_IMAGE_MAX_BYTES = 5242880;

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

    if (getimagesize($file['tmp_name']) === false) {
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

    $uploadDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'car';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true)) {
        throw new InvalidArgumentException('Could not prepare the car image folder.');
    }

    if (!is_writable($uploadDir)) {
        throw new InvalidArgumentException('Car image folder is not writable.');
    }

    $filename = buildCarImageFilename((string) $file['name'], $extensions[$mimeType]);
    $destination = $uploadDir . DIRECTORY_SEPARATOR . $filename;

    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        throw new InvalidArgumentException('Could not save the uploaded car image.');
    }

    return 'car/' . $filename;
}

function deleteCarImageFile(string $imagePath): void
{
    if ($imagePath === '' || str_contains($imagePath, '..') || !str_starts_with($imagePath, 'car/')) {
        return;
    }

    $filePath = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $imagePath);
    if (is_file($filePath)) {
        unlink($filePath);
    }
}

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

function getCarImageUploadErrorMessage(int $errorCode): string
{
    if ($errorCode === UPLOAD_ERR_INI_SIZE || $errorCode === UPLOAD_ERR_FORM_SIZE) {
        return 'Car image must be 5 MB or smaller.';
    }

    return 'Could not upload the car image. Please try again.';
}
