<?php

declare(strict_types=1);

namespace SignerPHP\PdfCore\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SignerPHP\PdfCore\PdfDocument;
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
