<?php

declare(strict_types=1);

namespace SignerPHP\PdfCore\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SignerPHP\PdfCore\Exception\PdfCoreStructureException;
use SignerPHP\PdfCore\PdfDocument;
use SignerPHP\PdfCore\PDFObject;
use SignerPHP\PdfCore\PdfValue\PDFValueList;
use SignerPHP\PdfCore\PdfValue\PDFValueObject;
use SignerPHP\PdfCore\PdfValue\PDFValueReference;
use SignerPHP\PdfCore\PdfValue\PDFValueSimple;
use SignerPHP\PdfCore\Service\DocumentTimestampObjectAssembler;

final class DocumentTimestampObjectAssemblerTest extends TestCase
{
    public function test_timestamp_dictionary_has_doc_timestamp_type_and_rfc3161_subfilter(): void
    {
        $document = $this->buildSinglePageDocument();

        $timestamp = (new DocumentTimestampObjectAssembler)->assemble($document);

        self::assertSame('/DocTimeStamp', (string) $timestamp['Type']);
        self::assertSame('/ETSI.RFC3161', (string) $timestamp['SubFilter']);
    }

    public function test_timestamp_is_the_value_of_an_invisible_signature_field(): void
    {
        $document = $this->buildSinglePageDocument();

        $timestamp = (new DocumentTimestampObjectAssembler)->assemble($document);

        $root = $document->getObject(1);
        self::assertNotNull($root);
        self::assertFalse(isset($root['DocTimeStamp']), 'the catalog must not reference the timestamp directly');
        self::assertFalse(isset($root['Perms']), '/Perms only holds DocMDP and UR3');

        $acroForm = $document->getObject($root['AcroForm']->asObjectReferenceOrNull());
        self::assertNotNull($acroForm);
        self::assertSame(3, $acroForm['SigFlags']->asIntOrNull());

        $fieldRefs = $acroForm['Fields']->asObjectReferenceOrNull();
        self::assertIsArray($fieldRefs);
        self::assertCount(1, $fieldRefs);

        $field = $document->getObject($fieldRefs[0]);
        self::assertNotNull($field);
        self::assertSame('/Sig', (string) $field['FT']);
        self::assertSame('/Widget', (string) $field['Subtype']);
        self::assertSame(132, $field['F']->asIntOrNull());
        self::assertSame($timestamp->getOid(), $field['V']->asObjectReferenceOrNull());
        self::assertSame(3, $field['P']->asObjectReferenceOrNull());

        $page = $document->getObject(3);
        self::assertNotNull($page);
        self::assertSame($fieldRefs, $page['Annots']->asObjectReferenceOrNull());
    }

    public function test_timestamp_field_is_appended_to_existing_fields(): void
    {
        $document = $this->buildSinglePageDocument();
        $acroForm = $document->createObject(['Fields' => new PDFValueList([new PDFValueReference(40)])]);
        $root = $document->getObject(1);
        self::assertNotNull($root);
        $root['AcroForm'] = new PDFValueReference($acroForm->getOid());
        $document->addObject($root);

        (new DocumentTimestampObjectAssembler)->assemble($document);
        (new DocumentTimestampObjectAssembler)->assemble($document);

        $updated = $document->getObject($acroForm->getOid());
        self::assertNotNull($updated);
        $fieldRefs = $updated['Fields']->asObjectReferenceOrNull();
        self::assertIsArray($fieldRefs);
        self::assertCount(3, $fieldRefs, 'every timestamp keeps its own field');
        self::assertSame(40, $fieldRefs[0]);
    }

    public function test_timestamp_field_is_registered_without_pages(): void
    {
        $document = new PdfDocument;
        $document->setTrailerObject(new PDFValueObject(['Root' => new PDFValueReference(1)]));
        $document->addObject(new PDFObject(1, ['Type' => '/Catalog', 'Pages' => new PDFValueReference(2)]));
        $document->addObject(new PDFObject(2, ['Type' => '/Pages', 'Kids' => new PDFValueList([]), 'Count' => 0]));
        $document->acquirePagesInfo();

        $timestamp = (new DocumentTimestampObjectAssembler)->assemble($document);

        $root = $document->getObject(1);
        self::assertNotNull($root);
        $acroForm = $document->getObject($root['AcroForm']->asObjectReferenceOrNull());
        self::assertNotNull($acroForm);
        $field = $document->getObject($acroForm['Fields']->asObjectReferenceOrNull()[0]);
        self::assertNotNull($field);
        self::assertSame($timestamp->getOid(), $field['V']->asObjectReferenceOrNull());
        self::assertFalse(isset($field['P']));
    }

    public function test_assemble_throws_when_root_reference_is_missing(): void
    {
        $document = new PdfDocument;
        $document->setTrailerObject(new PDFValueObject([]));

        $this->expectException(PdfCoreStructureException::class);
        $this->expectExceptionMessage('Could not resolve root object to attach document timestamp.');

        (new DocumentTimestampObjectAssembler)->assemble($document);
    }

    public function test_assemble_throws_when_root_object_cannot_be_resolved(): void
    {
        $document = new PdfDocument;
        $document->setTrailerObject(new PDFValueObject([
            'Root' => new PDFValueReference(9),
        ]));

        $this->expectException(PdfCoreStructureException::class);
        $this->expectExceptionMessage('Invalid root object while attaching document timestamp.');

        (new DocumentTimestampObjectAssembler)->assemble($document);
    }

    public function test_assemble_throws_when_acroform_reference_cannot_be_resolved(): void
    {
        $document = $this->buildSinglePageDocument();
        $root = $document->getObject(1);
        self::assertNotNull($root);
        $root['AcroForm'] = new PDFValueReference(77);
        $document->addObject($root);

        $this->expectException(PdfCoreStructureException::class);
        $this->expectExceptionMessage('Could not resolve AcroForm object while attaching document timestamp.');

        (new DocumentTimestampObjectAssembler)->assemble($document);
    }

    private function buildSinglePageDocument(): PdfDocument
    {
        $mediaBox = new PDFValueList([
            new PDFValueSimple(0),
            new PDFValueSimple(0),
            new PDFValueSimple(595),
            new PDFValueSimple(842),
        ]);

        $document = new PdfDocument;
        $document->setTrailerObject(new PDFValueObject(['Root' => new PDFValueReference(1)]));
        $document->addObject(new PDFObject(1, ['Type' => '/Catalog', 'Pages' => new PDFValueReference(2)]));
        $document->addObject(new PDFObject(2, [
            'Type' => '/Pages',
            'Kids' => new PDFValueList([new PDFValueReference(3)]),
            'Count' => 1,
            'MediaBox' => $mediaBox,
        ]));
        $document->addObject(new PDFObject(3, [
            'Type' => '/Page',
            'Parent' => new PDFValueReference(2),
            'MediaBox' => $mediaBox,
        ]));
        $document->acquirePagesInfo();

        return $document;
    }
}
