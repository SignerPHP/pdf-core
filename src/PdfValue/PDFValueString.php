<?php

declare(strict_types=1);

namespace SignerPHP\PdfCore\PdfValue;

class PDFValueString extends PDFValue
{
    public function __toString(): string
    {
        return '('.trim($this->value).')';
    }
}
