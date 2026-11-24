<?php

declare(strict_types=1);

namespace SignerPHP\PdfCore\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SignerPHP\PdfCore\Exception\PdfCoreStructureException;
use SignerPHP\PdfCore\ObjectParser;
use SignerPHP\PdfCore\PdfDocument;
use SignerPHP\PdfCore\PDFObject;
use SignerPHP\PdfCore\PdfValue\PDFValueList;
use SignerPHP\PdfCore\PdfValue\PDFValueObject;
use SignerPHP\PdfCore\PdfValue\PDFValueReference;
use SignerPHP\PdfCore\PdfValue\PDFValueSimple;
use SignerPHP\PdfCore\Service\DocumentTimestampObjectAssembler;
use SignerPHP\PdfCore\Service\IncrementalUpdateWriter;
use SignerPHP\PdfCore\Service\Inspect\SignatureDictionaryInspector;
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
        self::assertFalse(isset($timestamp['M']), 'The time is supplied by the TSA token, not the local clock.');
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

    #[DataProvider('invalidFormStructures')]
    public function test_rejects_invalid_form_structures_without_mutating_the_document(string $key, string $body, bool $indirect): void
    {
        $document = $this->buildSinglePageDocument();
        $root = $document->getObject(1);
        $page = $document->getObject(3);
        self::assertNotNull($root);
        self::assertNotNull($page);

        $value = (new ObjectParser)->parseString($body);
        self::assertNotNull($value);
        if ($indirect) {
            $value = new PDFValueReference($document->createObject($value)->getOid());
        }
        if ($key === 'Annots') {
            $page['Annots'] = $value;
            $document->addObject($page);
        } elseif ($key === 'Fields') {
            $root['AcroForm'] = new PDFValueObject(['Fields' => $value]);
        } else {
            $root['AcroForm'] = $value;
        }
        $document->addObject($root);

        $before = array_map(static fn (PDFObject $obj): string => $obj->toPdfEntry(), $document->getPdfObjects());
        $maxOid = $document->getMaxOid();
        try {
            (new DocumentTimestampObjectAssembler)->assemble($document);
            self::fail("Invalid /$key must be rejected before creating or modifying objects.");
        } catch (PdfCoreStructureException) {
            self::assertSame($before, array_map(static fn (PDFObject $obj): string => $obj->toPdfEntry(), $document->getPdfObjects()));
            self::assertSame($maxOid, $document->getMaxOid());
        }
    }

    public static function invalidFormStructures(): iterable
    {
        foreach (['Fields', 'Annots'] as $key) {
            foreach ([false, true] as $indirect) {
                $kind = $indirect ? 'indirect' : 'direct';
                yield "$key $kind scalar" => [$key, '7', $indirect];
                yield "$key $kind dictionary" => [$key, '<< /0 3 0 R >>', $indirect];
                yield "$key $kind mixed array" => [$key, '[3 0 R /Invalid]', $indirect];
            }
        }

        yield 'AcroForm indirect array' => ['AcroForm', '[]', true];
        yield 'AcroForm indirect scalar' => ['AcroForm', '7', true];
    }

    #[DataProvider('nullFormStructures')]
    public function test_explicit_null_is_treated_as_an_absent_optional_value(string $key): void
    {
        $document = $this->buildSinglePageDocument();
        $root = $document->getObject(1);
        $page = $document->getObject(3);
        self::assertNotNull($root);
        self::assertNotNull($page);
        if ($key === 'AcroForm') {
            $root['AcroForm'] = new PDFValueSimple('null');
        } else {
            $root['AcroForm'] = new PDFValueObject(['Fields' => new PDFValueSimple('null')]);
        }
        $page['Annots'] = new PDFValueSimple('null');
        $document->addObject($root);
        $document->addObject($page);

        $timestamp = (new DocumentTimestampObjectAssembler)->assemble($document);
        $form = $root['AcroForm'];
        if (is_int($reference = $form->asObjectReferenceOrNull())) {
            $form = $document->getObject($reference);
        }
        self::assertNotNull($form);
        $fields = $form['Fields']->asObjectReferenceOrNull();
        self::assertIsArray($fields);
        self::assertCount(1, $fields);
        self::assertSame($fields, $page['Annots']->asObjectReferenceOrNull());
        self::assertSame($timestamp->getOid(), $document->getObject($fields[0])['V']->asObjectReferenceOrNull());
    }

    public static function nullFormStructures(): iterable
    {
        yield 'null AcroForm' => ['AcroForm'];
        yield 'null Fields' => ['Fields'];
    }

    public function test_indirect_field_and_annotation_arrays_survive_multiple_timestamp_revisions(): void
    {
        $source = $this->pdfWithIndirectFormArrays();
        $writer = new IncrementalUpdateWriter;
        $preparer = new PdfDocumentPreparer;
        $timestampOids = [];
        $fieldOids = [];
        $fieldNames = [];
        $previous = $source;
        foreach ([1, 2] as $iteration) {
            $document = $preparer->prepare($previous);
            $timestamp = (new DocumentTimestampObjectAssembler)->assemble($document);
            $timestampOids[] = $timestamp->getOid();
            $document->addObject($timestamp);
            $serialized = $writer->write($document)->raw();
            self::assertStringStartsWith($previous, $serialized);
            $reparsed = $preparer->prepare($serialized);
            $root = $reparsed->getObject(1);
            $form = $reparsed->getObject(8);
            $page = $reparsed->getObject(3);
            self::assertNotNull($root);
            self::assertNotNull($form);
            self::assertNotNull($page);
            self::assertSame(8, $root['AcroForm']->asObjectReferenceOrNull());
            self::assertSame(2, $form['Q']->asIntOrNull());
            self::assertSame('<</UR3 9 0 R>>', (string) $root['Perms']);
            $fields = $form['Fields']->asObjectReferenceOrNull();
            $annots = $page['Annots']->asObjectReferenceOrNull();
            self::assertIsArray($fields);
            self::assertIsArray($annots);
            self::assertCount($iteration + 1, $fields);
            $fieldOid = $fields[array_key_last($fields)];
            $fieldOids[] = $fieldOid;
            self::assertSame([4, ...$fieldOids], $fields);
            self::assertSame([5, ...$fieldOids], $annots);
            $field = $reparsed->getObject($fieldOid);
            self::assertNotNull($field);
            $fieldNames[] = (string) $field['T'];
            self::assertSame($timestamp->getOid(), $field['V']->asObjectReferenceOrNull());
            self::assertSame('/DocTimeStamp', (string) $reparsed->getObject($timestamp->getOid())['Type']);
            $previous = $serialized;
        }

        self::assertCount(2, array_unique($fieldNames));
        foreach ($fieldOids as $index => $fieldOid) {
            self::assertSame($timestampOids[$index], $reparsed->getObject($fieldOid)['V']->asObjectReferenceOrNull());
        }
        $details = (new SignatureDictionaryInspector)->extractDetails($previous);
        self::assertSame(['ETSI.RFC3161', 'ETSI.RFC3161'], array_column($details, 'subfilter'));
    }

    public function test_mupdf_resolves_each_timestamp_through_its_signature_field(): void
    {
        exec('command -v mutool 2>/dev/null', $output, $status);
        if ($status !== 0) {
            self::markTestSkipped('MuPDF mutool is required for independent PDF parsing (installed in CI).');
        }

        $pdf = $this->pdfWithIndirectFormArrays();
        $preparer = new PdfDocumentPreparer;
        $writer = new IncrementalUpdateWriter;
        for ($revision = 0; $revision < 2; $revision++) {
            $document = $preparer->prepare($pdf);
            $timestamp = (new DocumentTimestampObjectAssembler)->assemble($document);
            $document->addObject($timestamp);
            $pdf = $writer->write($document)->raw();
        }

        $path = tempnam(sys_get_temp_dir(), 'timestamp-fields-');
        self::assertNotFalse($path);
        try {
            self::assertSame(strlen($pdf), file_put_contents($path, $pdf));
            $fields = $this->mutoolShow($path, 'trailer/Root/AcroForm/Fields');
            preg_match_all('/(\d+)\s+\d+\s+R/', $fields, $matches);
            self::assertCount(3, $matches[1]);
            self::assertSame('4', $matches[1][0]);

            $annotations = $this->mutoolShow($path, 'pages/1/Annots');
            preg_match_all('/(\d+)\s+\d+\s+R/', $annotations, $annotationMatches);
            self::assertSame(['5', $matches[1][1], $matches[1][2]], $annotationMatches[1]);

            foreach (array_slice($matches[1], 1) as $fieldOid) {
                self::assertSame('/Sig', $this->mutoolShow($path, $fieldOid.'/FT'));
                self::assertSame('/Widget', $this->mutoolShow($path, $fieldOid.'/Subtype'));
                self::assertSame('/DocTimeStamp', $this->mutoolShow($path, $fieldOid.'/V/Type'));
                self::assertSame('/ETSI.RFC3161', $this->mutoolShow($path, $fieldOid.'/V/SubFilter'));
            }
        } finally {
            unlink($path);
        }
    }

    private function mutoolShow(string $path, string $selector): string
    {
        exec('mutool show '.escapeshellarg($path).' '.escapeshellarg($selector).' 2>&1', $output, $status);
        self::assertSame(0, $status, implode("\n", $output));

        return trim(implode("\n", $output));
    }

    private function pdfWithIndirectFormArrays(): string
    {
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R /AcroForm 8 0 R /Perms << /UR3 9 0 R >> >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 300 144] /Annots 7 0 R >>',
            4 => '<< /FT /Tx /T (Existing) >>',
            5 => '<< /Type /Annot /Subtype /Text /Rect [0 0 10 10] /Contents (Existing) >>',
            6 => '[4 0 R]',
            7 => '[5 0 R]',
            8 => '<< /Fields 6 0 R /Q 2 >>',
            9 => '<< /Type /Sig >>',
        ];
        $pdf = "%PDF-1.7\n";
        $offsets = [];
        foreach ($objects as $oid => $body) {
            $offsets[$oid] = strlen($pdf);
            $pdf .= "$oid 0 obj\n$body\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 10\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf."trailer\n<< /Size 10 /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
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
