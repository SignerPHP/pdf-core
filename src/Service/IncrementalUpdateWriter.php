<?php

declare(strict_types=1);

namespace SignerPHP\PdfCore\Service;

use SignerPHP\PdfCore\Buffer;
use SignerPHP\PdfCore\PdfDocument;
use SignerPHP\PdfCore\Xref\Xref;

final class IncrementalUpdateWriter
{
    public function write(PdfDocument $document): Buffer
    {
        [$content, $offsets] = Xref::new()
            ->withPdfDocument($document)
            ->generateContentToXref();

        $tail = (new XrefContentResolver)->resolve($document, $offsets, $content->size());

        return new Buffer($content->raw().$tail->raw());
    }
}
