<?php

declare(strict_types=1);

namespace SignerPHP\PdfCore\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SignerPHP\PdfCore\Metadata;
use SignerPHP\PdfCore\PdfValue\PDFValueList;
use SignerPHP\PdfCore\PdfValue\PDFValueObject;
use SignerPHP\PdfCore\PdfValue\PDFValueReference;
use SignerPHP\PdfCore\Service\IncrementalUpdateWriter;
use SignerPHP\PdfCore\Service\PdfDocumentPreparer;
use SignerPHP\PdfCore\Service\SignatureObjectAssembler;
use SignerPHP\PdfCore\SignatureAppearance;
use SignerPHP\PdfCore\Tests\Support\PdfFixtureFactory;

final class SignatureObjectAssemblerSerializationTest extends TestCase
{
    #[DataProvider('permissionsLayouts')]
    public function test_certification_reference_survives_incremental_write_and_parse(string $layout): void
    {
        $original = PdfFixtureFactory::minimalPdf();
        $document = (new PdfDocumentPreparer)->prepare($original);
        $existingEntryOid = null;
        $indirectPermsOid = null;

        if ($layout !== 'missing') {
            $existingEntry = $document->createObject(['Type' => '/Sig']);
            $existingEntryOid = $existingEntry->getOid();
            $root = $document->getObject(1);
            self::assertNotNull($root);

            if ($layout === 'inline') {
                $root['Perms'] = new PDFValueObject(['UR3' => new PDFValueReference($existingEntryOid)]);
            } else {
                $indirectPerms = $document->createObject(['UR3' => new PDFValueReference($existingEntryOid)]);
                $indirectPermsOid = $indirectPerms->getOid();
                $root['Perms'] = new PDFValueReference($indirectPermsOid);
            }

            $document->addObject($root);
        }

        $signature = (new SignatureObjectAssembler)->assemble(
            $document,
            SignatureAppearance::new()->withRect([10, 10, 100, 60]),
            Metadata::new(),
            certificationLevel: 2,
        );
        $document->addObject($signature);

        $serialized = (new IncrementalUpdateWriter)->write($document)->raw();
        self::assertStringStartsWith($original, $serialized);

        $reparsed = (new PdfDocumentPreparer)->prepare($serialized);
        $catalog = $reparsed->getObject(1);
        self::assertNotNull($catalog);
        self::assertNull($catalog['DocMDP'], 'DocMDP must not be on the catalog');

        $permsValue = $catalog['Perms'];
        if ($layout === 'indirect') {
            self::assertNotNull($permsValue);
            self::assertSame($indirectPermsOid, $permsValue->asObjectReferenceOrNull());
            $permsObject = $reparsed->getObject($indirectPermsOid);
            self::assertNotNull($permsObject);
            $permissions = $permsObject->getValue();
        } else {
            self::assertInstanceOf(PDFValueObject::class, $permsValue);
            $permissions = $permsValue;
        }

        self::assertSame($signature->getOid(), $permissions['DocMDP']->asObjectReferenceOrNull());

        if ($existingEntryOid !== null) {
            self::assertSame($existingEntryOid, $permissions['UR3']->asObjectReferenceOrNull());
        }

        $reparsedSignature = $reparsed->getObject($signature->getOid());
        self::assertNotNull($reparsedSignature);
        $references = $reparsedSignature['Reference'];
        self::assertInstanceOf(PDFValueList::class, $references);
        self::assertCount(1, $references->val());
        self::assertSame('DocMDP', $references[0]['TransformMethod']->val());
        self::assertSame(2, $references[0]['TransformParams']['P']->asIntOrNull());
    }

    public static function permissionsLayouts(): array
    {
        return [
            'no /Perms entry' => ['missing'],
            'inline /Perms' => ['inline'],
            'indirect /Perms' => ['indirect'],
        ];
    }

    public function test_approval_signature_does_not_create_docmdp_permissions_after_serialization(): void
    {
        $original = PdfFixtureFactory::minimalPdf();
        $document = (new PdfDocumentPreparer)->prepare($original);
        $signature = (new SignatureObjectAssembler)->assemble(
            $document,
            SignatureAppearance::new()->withRect([10, 10, 100, 60]),
            Metadata::new(),
        );
        $document->addObject($signature);

        $serialized = (new IncrementalUpdateWriter)->write($document)->raw();
        $reparsed = (new PdfDocumentPreparer)->prepare($serialized);
        $catalog = $reparsed->getObject(1);
        self::assertNotNull($catalog);
        self::assertNull($catalog['Perms']);
        self::assertNull($catalog['DocMDP']);

        $reparsedSignature = $reparsed->getObject($signature->getOid());
        self::assertNotNull($reparsedSignature);
        self::assertNull($reparsedSignature['Reference']);
    }
}
