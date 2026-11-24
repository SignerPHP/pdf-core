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
use SignerPHP\PdfCore\Service\IncrementalUpdateWriter;
use SignerPHP\PdfCore\Service\PdfDocumentPreparer;
use SignerPHP\PdfCore\Tests\Support\PdfFixtureFactory;

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

    public function test_timestamp_field_is_registered_inside_existing_inline_acroform(): void
    {
        $document = $this->buildSinglePageDocument();
        $existingField = $document->createObject(['FT' => '/Tx']);
        $root = $document->getObject(1);
        self::assertNotNull($root);
        $root['AcroForm'] = new PDFValueObject([
            'Fields' => new PDFValueList([new PDFValueReference($existingField->getOid())]),
            'Q' => new PDFValueSimple(2),
        ]);
        $document->addObject($root);

        $timestamp = (new DocumentTimestampObjectAssembler)->assemble($document);

        $root = $document->getObject(1);
        self::assertNotNull($root);
        self::assertFalse(isset($root['Fields']), '/Fields belongs inside /AcroForm');
        self::assertFalse(isset($root['SigFlags']), '/SigFlags belongs inside /AcroForm');
        $acroForm = $root['AcroForm'];
        self::assertInstanceOf(PDFValueObject::class, $acroForm);
        self::assertSame(2, $acroForm['Q']->asIntOrNull());
        self::assertSame(3, $acroForm['SigFlags']->asIntOrNull());

        $fields = $acroForm['Fields']->asObjectReferenceOrNull();
        self::assertIsArray($fields);
        self::assertCount(2, $fields);
        self::assertSame($existingField->getOid(), $fields[0]);

        $timestampField = $document->getObject($fields[1]);
        self::assertNotNull($timestampField);
        self::assertSame($timestamp->getOid(), $timestampField['V']->asObjectReferenceOrNull());
    }

    public function test_inline_acroform_remains_valid_after_incremental_pdf_serialization(): void
    {
        $source = PdfFixtureFactory::minimalPdf();
        $document = (new PdfDocumentPreparer)->prepare($source);
        $root = $document->getObject(1);
        self::assertNotNull($root);
        $root['AcroForm'] = new PDFValueObject([
            'Fields' => new PDFValueList,
            'Q' => new PDFValueSimple(2),
        ]);
        $document->addObject($root);

        $timestamp = (new DocumentTimestampObjectAssembler)->assemble($document);
        $document->addObject($timestamp);
        $serialized = (new IncrementalUpdateWriter)->write($document)->raw();
        self::assertStringStartsWith($source, $serialized);

        $reparsed = (new PdfDocumentPreparer)->prepare($serialized);
        $catalog = $reparsed->getObject(1);
        self::assertNotNull($catalog);
        self::assertFalse(isset($catalog['Fields']));
        self::assertFalse(isset($catalog['SigFlags']));

        $acroForm = $catalog['AcroForm'];
        self::assertInstanceOf(PDFValueObject::class, $acroForm);
        self::assertSame(2, $acroForm['Q']->asIntOrNull());
        self::assertSame(3, $acroForm['SigFlags']->asIntOrNull());

        $fieldRefs = $acroForm['Fields']->asObjectReferenceOrNull();
        self::assertIsArray($fieldRefs);
        self::assertCount(1, $fieldRefs);
        $field = $reparsed->getObject($fieldRefs[0]);
        self::assertNotNull($field);
        self::assertSame($timestamp->getOid(), $field['V']->asObjectReferenceOrNull());

        $reparsedTimestamp = $reparsed->getObject($timestamp->getOid());
        self::assertNotNull($reparsedTimestamp);
        self::assertSame('/DocTimeStamp', (string) $reparsedTimestamp['Type']);
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
        self::assertSame('/Sig', (string) $field['FT']);
        foreach (['Type', 'Subtype', 'P', 'Rect', 'F'] as $widgetKey) {
            self::assertFalse(isset($field[$widgetKey]), "a field without a page must not declare widget key /$widgetKey");
        }
    }

    public function test_assemble_throws_and_keeps_annotations_when_annots_reference_cannot_be_resolved(): void
    {
        $document = $this->buildSinglePageDocument();
        $page = $document->getObject(3);
        self::assertNotNull($page);
        $page['Annots'] = new PDFValueReference(88);
        $document->addObject($page);
        $objectsBefore = count($document->getPdfObjects());
        $maxOidBefore = $document->getMaxOid();

        try {
            (new DocumentTimestampObjectAssembler)->assemble($document);
            self::fail('An unresolvable /Annots reference must not be replaced.');
        } catch (PdfCoreStructureException $exception) {
            self::assertSame('Could not resolve annotation list object while attaching document timestamp.', $exception->getMessage());
        }

        $page = $document->getObject(3);
        self::assertNotNull($page);
        self::assertSame(88, $page['Annots']->asObjectReferenceOrNull());
        self::assertCount($objectsBefore, $document->getPdfObjects(), 'no field may be created');
        self::assertSame($maxOidBefore, $document->getMaxOid(), 'no object IDs should be consumed');
    }

    public function test_invalid_acroform_fields_reference_does_not_mutate_document(): void
    {
        $document = $this->buildSinglePageDocument();
        $acroForm = $document->createObject(['Fields' => new PDFValueReference(9999)]);
        $root = $document->getObject(1);
        self::assertNotNull($root);
        $root['AcroForm'] = new PDFValueReference($acroForm->getOid());
        $document->addObject($root);

        $objectsBefore = array_keys($document->getPdfObjects());
        $maxOidBefore = $document->getMaxOid();
        $pageBefore = (string) $document->getObject(3);
        $acroFormBefore = (string) $document->getObject($acroForm->getOid());

        try {
            (new DocumentTimestampObjectAssembler)->assemble($document);
            self::fail('An invalid /AcroForm /Fields reference must be rejected.');
        } catch (PdfCoreStructureException $exception) {
            self::assertSame('Could not resolve AcroForm fields object while attaching document timestamp.', $exception->getMessage());
        }

        self::assertSame($objectsBefore, array_keys($document->getPdfObjects()), 'no orphan field or page update');
        self::assertSame($maxOidBefore, $document->getMaxOid(), 'no object IDs should be consumed');
        self::assertSame($pageBefore, (string) $document->getObject(3), 'page annotations must remain unchanged');
        self::assertSame($acroFormBefore, (string) $document->getObject($acroForm->getOid()));
    }

    public function test_existing_page_annotations_are_preserved(): void
    {
        $document = $this->buildSinglePageDocument();
        $annots = new PDFObject(10, new PDFValueList([new PDFValueReference(50), new PDFValueReference(51)]));
        $document->addObject($annots);
        $page = $document->getObject(3);
        self::assertNotNull($page);
        $page['Annots'] = new PDFValueReference($annots->getOid());
        $document->addObject($page);

        (new DocumentTimestampObjectAssembler)->assemble($document);

        $page = $document->getObject(3);
        self::assertNotNull($page);
        $refs = $page['Annots']->asObjectReferenceOrNull();
        self::assertIsArray($refs);
        self::assertSame([50, 51], array_slice($refs, 0, 2));
        self::assertCount(3, $refs);
    }

    public function test_malformed_acroform_is_rejected_without_catalog_modifications(): void
    {
        $document = $this->buildSinglePageDocument();
        $root = $document->getObject(1);
        self::assertNotNull($root);
        $root['AcroForm'] = new PDFValueSimple(7);
        $document->addObject($root);
        $catalogBefore = (string) $root;
        $objectsBefore = array_keys($document->getPdfObjects());
        $maxOidBefore = $document->getMaxOid();

        try {
            (new DocumentTimestampObjectAssembler)->assemble($document);
            self::fail('A non-dictionary /AcroForm must not be accepted.');
        } catch (PdfCoreStructureException $exception) {
            self::assertSame('Invalid AcroForm dictionary while attaching document timestamp.', $exception->getMessage());
        }

        self::assertSame($catalogBefore, (string) $document->getObject(1));
        self::assertSame($objectsBefore, array_keys($document->getPdfObjects()));
        self::assertSame($maxOidBefore, $document->getMaxOid());
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
