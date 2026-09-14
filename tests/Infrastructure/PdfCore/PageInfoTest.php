<?php

declare(strict_types=1);

namespace SignerPHP\PdfCore\Tests\Infrastructure\PdfCore;

use PHPUnit\Framework\TestCase;
use SignerPHP\PdfCore\PageInfo;
use SignerPHP\PdfCore\PdfDocument;
use SignerPHP\PdfCore\PDFObject;
use SignerPHP\PdfCore\PdfValue\PDFValueList;
use SignerPHP\PdfCore\PdfValue\PDFValueObject;
use SignerPHP\PdfCore\PdfValue\PDFValueReference;
use SignerPHP\PdfCore\PdfValue\PDFValueSimple;

final class PageInfoTest extends TestCase
{
    public function test_acquire_pages_info_skips_cycles_in_page_tree(): void
    {
        $document = new PdfDocument;
        $document->setTrailerObject(new PDFValueObject([
            'Root' => new PDFValueReference(1),
        ]));
        $document->addObject(new PDFObject(1, [
            'Type' => '/Catalog',
            'Pages' => new PDFValueReference(2),
        ]));
        $document->addObject(new PDFObject(2, [
            'Type' => '/Pages',
            'Kids' => new PDFValueList([
                new PDFValueReference(3),
            ]),
        ]));
        $document->addObject(new PDFObject(3, [
            'Type' => '/Pages',
            'Kids' => new PDFValueList([
                new PDFValueReference(2),
                new PDFValueReference(4),
            ]),
        ]));
        $document->addObject(new PDFObject(4, [
            'Type' => '/Page',
            'MediaBox' => new PDFValueList([
                new PDFValueSimple(0),
                new PDFValueSimple(0),
                new PDFValueSimple(595),
                new PDFValueSimple(842),
            ]),
        ]));

        $pageInfo = PageInfo::new()
            ->withPdfDocument($document)
            ->acquirePagesInfo();

        self::assertNotNull($pageInfo->getPage(0));
        self::assertNull($pageInfo->getPage(1));
        self::assertSame(
            [0, 0, 595, 842],
            array_map(
                static fn (PDFValueSimple $value): int => $value->asIntOrNull() ?? -1,
                $pageInfo->getPageSize(0) ?? []
            )
        );
    }
}
