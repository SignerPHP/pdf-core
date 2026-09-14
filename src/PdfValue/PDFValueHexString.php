<?php

declare(strict_types=1);

namespace SignerPHP\PdfCore\PdfValue;

class PDFValueHexString extends PDFValueString
{
    public function __toString(): string
    {
        return '<'.trim((string) $this->value).'>';
    }
}
