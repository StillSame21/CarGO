<?php

namespace Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class CarImageTest extends TestCase
{
    private const CAR_DIR = __DIR__ . '/../car';

    /** @var string[] Fixture files created by a test, removed in tearDown(). */
    private array $createdFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->createdFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        $this->createdFiles = [];
    }

    private function touchFixture(string $relativePath): void
    {
        $diskPath = self::CAR_DIR . '/' . basename($relativePath);
        file_put_contents($diskPath, 'fixture');
        $this->createdFiles[] = $diskPath;
    }

    // --- carImageDerivativePath() ---------------------------------------

    public function testDerivativePathWithWidth(): void
    {
        $this->assertSame(
            'car/abc123-civic-400.webp',
            carImageDerivativePath('car/abc123-civic.jpg', 400, 'webp')
        );
    }

    public function testDerivativePathFullSizeHasNoSuffix(): void
    {
        $this->assertSame(
            'car/abc123-civic.webp',
            carImageDerivativePath('car/abc123-civic.jpg', null, 'webp')
        );
    }

    public function testDerivativePathRejectsTraversal(): void
    {
        $this->expectException(InvalidArgumentException::class);
        carImageDerivativePath('car/../../etc/passwd', 400, 'jpg');
    }

    public function testDerivativePathRejectsPathOutsideCarDir(): void
    {
        $this->expectException(InvalidArgumentException::class);
        carImageDerivativePath('uploads/x.jpg', 400, 'jpg');
    }

    // --- deleteCarImageFile() --------------------------------------------

    public function testDeleteCarImageFileRemovesDerivativeSiblings(): void
    {
        $base = 'delcar-' . uniqid();
        $imagePath = "car/{$base}.jpg";

        foreach (['jpg', 'webp'] as $ext) {
            $this->touchFixture("car/{$base}.{$ext}");
        }
        foreach (CAR_IMAGE_DERIVATIVE_WIDTHS as $width) {
            $this->touchFixture("car/{$base}-{$width}.jpg");
            $this->touchFixture("car/{$base}-{$width}.webp");
        }

        deleteCarImageFile($imagePath);

        foreach ($this->createdFiles as $path) {
            $this->assertFileDoesNotExist($path);
        }
        $this->createdFiles = []; // already gone, nothing left for tearDown to remove
    }

    public function testDeleteCarImageFileIgnoresTraversalPaths(): void
    {
        $base = 'safecar-' . uniqid();
        $this->touchFixture("car/{$base}.jpg");
        $decoyPath = self::CAR_DIR . '/' . $base . '.jpg';

        deleteCarImageFile('../car/' . $base . '.jpg');
        deleteCarImageFile('car/../car/' . $base . '.jpg');

        $this->assertFileExists($decoyPath);
    }

    public function testDeleteCarImageFileIgnoresPathOutsideCarDir(): void
    {
        // Must not throw even though carImageDerivativePath() would reject
        // this internally - deleteCarImageFile() should just no-op.
        deleteCarImageFile('uploads/not-a-car-image.jpg');
        $this->addToAssertionCount(1);
    }

    // --- carImageTag() -----------------------------------------------------

    public function testTagFallsBackToPlainImgWhenNoDerivativesExist(): void
    {
        $base = 'noderiv-' . uniqid();
        $this->touchFixture("car/{$base}.jpg");

        $html = carImageTag("car/{$base}.jpg", 'Test Car', '400px');

        $this->assertStringNotContainsString('<picture>', $html);
        $this->assertStringContainsString('<img', $html);
        $this->assertStringContainsString("car/{$base}.jpg", $html);
        $this->assertStringContainsString('loading="lazy"', $html);
        $this->assertStringContainsString('decoding="async"', $html);
    }

    public function testTagRendersPictureWithSrcsetWhenDerivativesExist(): void
    {
        $base = 'hasderiv-' . uniqid();
        $this->touchFixture("car/{$base}.jpg");
        $this->touchFixture("car/{$base}-400.jpg");
        $this->touchFixture("car/{$base}-400.webp");

        $html = carImageTag("car/{$base}.jpg", 'Test Car', '(max-width: 700px) 100vw, 400px');

        $this->assertStringContainsString('<picture>', $html);
        $this->assertStringContainsString('type="image/webp"', $html);
        $this->assertStringContainsString("{$base}-400.webp 400w", $html);
        $this->assertStringContainsString('type="image/jpeg"', $html);
        $this->assertStringContainsString("{$base}-400.jpg 400w", $html);
        // Full-size original is always the <img> fallback, srcset or not.
        $this->assertStringContainsString("{$base}.jpg", $html);
    }

    public function testTagEscapesAltAndPath(): void
    {
        $html = carImageTag('', 'Car "Brand" <script>alert(1)</script>', '400px');

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('&quot;Brand&quot;', $html);
    }

    public function testTagWithEmptyPathUsesPlaceholder(): void
    {
        $html = carImageTag('', 'No image', '400px');

        $this->assertStringContainsString(CAR_IMAGE_PLACEHOLDER, $html);
        $this->assertStringNotContainsString('<picture>', $html);
    }

    public function testTagPassesThroughExternalUrlUnchanged(): void
    {
        $html = carImageTag('https://example.com/car.jpg', 'External', '400px');

        $this->assertStringContainsString('src="https://example.com/car.jpg"', $html);
        $this->assertStringNotContainsString('<picture>', $html);
    }

    public function testTagAppliesEagerLoadingAndFetchPriority(): void
    {
        $base = 'eager-' . uniqid();
        $this->touchFixture("car/{$base}.jpg");

        $html = carImageTag("car/{$base}.jpg", 'Hero', '800px', [
            'loading' => 'eager',
            'fetchpriority' => 'high',
        ]);

        $this->assertStringContainsString('loading="eager"', $html);
        $this->assertStringContainsString('fetchpriority="high"', $html);
    }
}
