<?php

declare(strict_types=1);

namespace SignerPHP\PdfCore\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SignerPHP\PdfCore\PdfDocument;
use SignerPHP\PdfCore\Service\IncrementalUpdateWriter;
use SignerPHP\PdfCore\Service\PdfDocumentPreparer;
use SignerPHP\PdfCore\Xref\Xref;

final class HybridReferenceXrefTest extends TestCase
{
    public function test_preparer_resolves_compressed_object_only_listed_in_xrefstm(): void
    {
        $pdf = $this->hybridPdf();

        $document = (new PdfDocumentPreparer)->prepare($pdf);
        $compressed = $document->getObject(5);

        self::assertNotNull($compressed);
        self::assertSame('Metadata', $compressed['Type']->val());
        self::assertSame('XML', $compressed['Subtype']->val());
        self::assertSame('Catalog', $document->getObject(1)['Type']->val());
    }

    public function test_xref_facade_routes_hybrid_file_through_classic_table_and_merges_stream(): void
    {
        $pdf = $this->hybridPdf();
        $xrefPosition = (int) strrpos($pdf, "\nxref\n") + 1;

        $document = new PdfDocument;
        $document->setBufferFromString($pdf);

        $result = Xref::new()
            ->withPdfDocument($document)
            ->withXrefPosition($xrefPosition)
            ->parse();

        self::assertIsInt($result->table[1]);
        self::assertSame(['stmoid' => 4, 'pos' => 0], $result->table[5]);
    }

    #[DataProvider('xrefVersions')]
    public function test_incremental_revisions_preserve_hybrid_input_and_resolve_updated_objects(string $xrefVersion): void
    {
        [$original, $updated, $previousXref] = $this->updatedHybridPdf($xrefVersion);

        self::assertStringStartsWith($original, $updated);
        $reparsed = (new PdfDocumentPreparer)->prepare($updated);
        self::assertFalse(isset($reparsed->getTrailerObject()['XRefStm']));
        self::assertTrue(isset($reparsed->getTrailerObject()['Prev']));
        self::assertSame($previousXref, (int) $reparsed->getTrailerObject()['Prev']->val());
        self::assertSame('Incremental', $reparsed->getObject(1)['RevisionMarker']->val());
        self::assertSame('Metadata', $reparsed->getObject(5)['Type']->val());
    }

    #[DataProvider('xrefVersions')]
    public function test_mupdf_parses_the_latest_trailer_and_updated_objects(string $xrefVersion): void
    {
        $binary = [];
        $status = 1;
        exec('command -v mutool 2>/dev/null', $binary, $status);
        if ($status !== 0) {
            self::markTestSkipped('MuPDF mutool is required for independent PDF parsing (installed in CI).');
        }

        [, $updated] = $this->updatedHybridPdf($xrefVersion);
        $path = tempnam(sys_get_temp_dir(), 'pdf-xref-');
        self::assertNotFalse($path);

        try {
            self::assertSame(strlen($updated), file_put_contents($path, $updated));

            $trailer = $this->mutoolShow($path, 'trailer');
            self::assertStringNotContainsString('/XRefStm', $trailer);
            self::assertStringContainsString('/Prev', $trailer);

            $catalog = $this->mutoolShow($path, '1');
            self::assertStringContainsString('/RevisionMarker /Incremental', $catalog);

            $compressed = $this->mutoolShow($path, '5');
            self::assertStringContainsString('/Type /Metadata', $compressed);
        } finally {
            unlink($path);
        }
    }

    public static function xrefVersions(): iterable
    {
        yield 'classic cross-reference table' => ['1.4'];
        yield 'cross-reference stream' => ['1.5'];
    }

    private function mutoolShow(string $pdfPath, string $selector): string
    {
        $output = [];
        $status = 1;
        exec('mutool show '.escapeshellarg($pdfPath).' '.escapeshellarg($selector).' 2>&1', $output, $status);
        self::assertSame(0, $status, implode("\n", $output));

        return implode("\n", $output);
    }

    /**
     * @return array{string, string, int}
     */
    private function updatedHybridPdf(string $xrefVersion): array
    {
        $original = $this->hybridPdf();
        $document = (new PdfDocumentPreparer)->prepare($original);
        $document->setXrefTableVersion($xrefVersion);
        $catalog = $document->getObject(1);
        self::assertNotNull($catalog);
        $catalog['RevisionMarker'] = '/Incremental';
        $document->addObject($catalog);

        return [$original, (new IncrementalUpdateWriter)->write($document)->raw(), $document->getXrefPosition()];
    }

    private function hybridPdf(): string
    {
        $objects = [];
        $objects[1] = "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";
        $objects[2] = "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n";
        $objects[3] = "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 10 10] >>\nendobj\n";
        $objStmPayload = '5 0 << /Type /Metadata /Subtype /XML >>';
        $objects[4] = "4 0 obj\n<< /Type /ObjStm /N 1 /First 4 /Length ".strlen($objStmPayload)." >>\nstream\n".$objStmPayload."\nendstream\nendobj\n";

        $streamEntries = chr(2).pack('n', 4).chr(0);
        $objects[6] = "6 0 obj\n<< /Type /XRef /W [1 2 1] /Size 7 /Index [5 1] /Length ".strlen($streamEntries)." >>\nstream\n".$streamEntries."\nendstream\nendobj\n";

        $pdf = "%PDF-1.5\n";
        $offsets = [];
        foreach ($objects as $oid => $body) {
            $offsets[$oid] = strlen($pdf);
            $pdf .= $body;
        }

        $xrefOffset = strlen($pdf);
        $pdf .= "xref\n0 1\n0000000000 65535 f \n";
        $pdf .= "1 4\n";
        for ($oid = 1; $oid <= 4; $oid++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$oid]);
        }
        $pdf .= "6 1\n";
        $pdf .= sprintf("%010d 00000 n \n", $offsets[6]);
        $pdf .= 'trailer'."\n";
        $pdf .= '<< /Size 7 /Root 1 0 R /XRefStm '.$offsets[6]." >>\n";
        $pdf .= "startxref\n{$xrefOffset}\n%%EOF\n";

        return $pdf;
    }
}
