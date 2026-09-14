<?php

declare(strict_types=1);

namespace SignerPHP\PdfCore\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PdfCoreNoLegacyWrapperUsageTest extends TestCase
{
    public function test_core_uses_optional_apis_instead_of_legacy_wrappers(): void
    {
        $corePath = __DIR__.'/../../src';
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($corePath));

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $path = $file->getPathname();
            if (str_ends_with($path, 'Compat/LegacyPdfValueCompat.php') || str_ends_with($path, 'PdfValue/PDFValue.php')) {
                continue;
            }

            $content = (string) file_get_contents($path);
            self::assertStringNotContainsString('->getInt(', $content, $path);
            self::assertStringNotContainsString('->getObjectReferenced(', $content, $path);
        }
    }

    public function test_core_does_not_depend_on_signer_php_or_cryptography(): void
    {
        $corePath = __DIR__.'/../../src';
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($corePath));

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $path = $file->getPathname();
            $content = (string) file_get_contents($path);

            self::assertStringNotContainsString('SignerPHP\\Infrastructure', $content, $path);
            self::assertStringNotContainsString('SignerPHP\\Application', $content, $path);
            self::assertStringNotContainsString('SignerPHP\\Domain', $content, $path);
            self::assertStringNotContainsString('SignerPHP\\Presentation', $content, $path);
            self::assertDoesNotMatchRegularExpression('/\bopenssl_[a-z0-9_]+\\s*\\(/i', $content, $path);
            self::assertDoesNotMatchRegularExpression('/\bPKCS7_(BINARY|DETACHED|NOCERTS|NOSIGS)\b/', $content, $path);
        }
    }
}
