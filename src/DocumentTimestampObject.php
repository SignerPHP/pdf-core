<?php

declare(strict_types=1);

namespace SignerPHP\PdfCore;

class DocumentTimestampObject extends SignatureObject
{
    public function __construct(int $oid)
    {
        parent::__construct($oid);
        // ISO 32000-2, 12.8.5: a document timestamp dictionary has /Type /DocTimeStamp.
        $this->value['Type'] = '/DocTimeStamp';
        $this->value['SubFilter'] = '/ETSI.RFC3161';
    }
}
