<?php

declare(strict_types=1);

namespace SignerPHP\PdfCore\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SignerPHP\PdfCore\PdfValue\PDFValueString;
use SignerPHP\PdfCore\Service\IncrementalUpdateWriter;
use SignerPHP\PdfCore\Service\PdfDocumentPreparer;
use SignerPHP\PdfCore\Tests\Support\PdfFixtureFactory;

final class IncrementalUpdateWriterTest extends TestCase
{
    public function test_write_appends_an_incremental_revision_to_the_original_pdf(): void
    {
        $original = PdfFixtureFactory::minimalPdf();
        $document = (new PdfDocumentPreparer)->prepare($original);

        $infoOid = $document->getTrailerObject()['Info']->asObjectReferenceOrNull();
        self::assertIsInt($infoOid);

        $info = $document->getObject($infoOid);
        self::assertNotNull($info);
        $info['Title'] = new PDFValueString('Updated title');
        $document->addObject($info);

        $output = (new IncrementalUpdateWriter)->write($document)->raw();

        self::assertStringStartsWith($original, $output);
        self::assertGreaterThan(strlen($original), strlen($output));
        self::assertStringContainsString('Updated title', $output);
        self::assertStringContainsString("trailer\n", $output);
        self::assertMatchesRegularExpression('/startxref\s+\d+\s+%%EOF/s', $output);
    }
}
