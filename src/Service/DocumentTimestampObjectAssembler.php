<?php

declare(strict_types=1);

namespace SignerPHP\PdfCore\Service;

use SignerPHP\PdfCore\DocumentTimestampObject;
use SignerPHP\PdfCore\Exception\PdfCoreStructureException;
use SignerPHP\PdfCore\PdfDocument;
use SignerPHP\PdfCore\PDFObject;
use SignerPHP\PdfCore\PdfValue\PDFValue;
use SignerPHP\PdfCore\PdfValue\PDFValueList;
use SignerPHP\PdfCore\PdfValue\PDFValueObject;
use SignerPHP\PdfCore\PdfValue\PDFValueReference;
use SignerPHP\PdfCore\PdfValue\PDFValueString;
use SignerPHP\PdfCore\Utils\Str;

/**
 * Attaches a document timestamp (ISO 32000-2, 12.8.5) the way signatures are attached:
 * the /DocTimeStamp dictionary is the /V of an invisible signature field listed in
 * /AcroForm /Fields, and the field's widget is added to the first page when there is one.
 */
final class DocumentTimestampObjectAssembler
{
    public function assemble(PdfDocument $pdfDocument): DocumentTimestampObject
    {
        $rootObject = $this->resolveRootObject($pdfDocument);
        $timestampObject = $pdfDocument->createObject([], DocumentTimestampObject::class, false);
        assert($timestampObject instanceof DocumentTimestampObject);

        $pageObject = $pdfDocument->getPageInfo()->getPage(0);

        $field = [
            'Type' => '/Annot',
            'Subtype' => '/Widget',
            'FT' => '/Sig',
            'T' => new PDFValueString('DocTimeStamp'.Str::random()),
            'V' => new PDFValueReference($timestampObject->getOid()),
            'Rect' => [0, 0, 0, 0],
            'F' => 132,
        ];
        if ($pageObject !== null) {
            $field['P'] = new PDFValueReference($pageObject->getOid());
        }
        $fieldObject = $pdfDocument->createObject($field);

        if ($pageObject !== null) {
            $annotations = $this->resolveListValue($pdfDocument, $pageObject['Annots'] ?? new PDFValueList, 'annotation list', true);
            $annotations->push(new PDFValueReference($fieldObject->getOid()));
            $pageObject['Annots'] = $annotations;
            $pdfDocument->addObject($pageObject);
        }

        $acroFormObject = $this->resolveOrCreateAcroForm($pdfDocument, $rootObject);
        $acroFormObject['SigFlags'] = 3;
        $fields = $this->resolveListValue($pdfDocument, $acroFormObject['Fields'] ?? new PDFValueList, 'AcroForm fields');
        $fields->push(new PDFValueReference($fieldObject->getOid()));
        $acroFormObject['Fields'] = $fields;
        $pdfDocument->addObject($acroFormObject);

        return $timestampObject;
    }

    private function resolveRootObject(PdfDocument $pdfDocument): PDFObject
    {
        $root = $pdfDocument->getTrailerObject()['Root'] ?? null;
        if (($root === null) || (($root = $root->asObjectReferenceOrNull()) === null) || is_array($root)) {
            throw new PdfCoreStructureException('Could not resolve root object to attach document timestamp.');
        }

        $rootObject = $pdfDocument->getObject($root);
        if ($rootObject === null) {
            throw new PdfCoreStructureException('Invalid root object while attaching document timestamp.');
        }

        return $rootObject;
    }

    private function resolveOrCreateAcroForm(PdfDocument $pdfDocument, PDFObject $rootObject): PDFObject
    {
        if (! isset($rootObject['AcroForm'])) {
            $acroFormObject = $pdfDocument->createObject(['Fields' => new PDFValueList]);
            $rootObject['AcroForm'] = new PDFValueReference($acroFormObject->getOid());
            $pdfDocument->addObject($rootObject);

            return $acroFormObject;
        }

        $reference = $rootObject['AcroForm']->asObjectReferenceOrNull();
        if (($reference !== null) && (! is_array($reference))) {
            $acroFormObject = $pdfDocument->getObject($reference);
            if ($acroFormObject === null) {
                throw new PdfCoreStructureException('Could not resolve AcroForm object while attaching document timestamp.');
            }

            return $acroFormObject;
        }

        // Inline AcroForm dictionary: entries are written on the catalog object.
        return $rootObject;
    }

    private function resolveListValue(PdfDocument $pdfDocument, PDFValue $value, string $context, bool $fallbackWhenMissingReference = false): PDFValueList
    {
        if ($value instanceof PDFValueList) {
            return new PDFValueList($value->val());
        }

        $reference = $value->asObjectReferenceOrNull();
        if (($reference !== null) && (! is_array($reference))) {
            $listObject = $pdfDocument->getObject($reference);
            if ($listObject === null) {
                if ($fallbackWhenMissingReference) {
                    return new PDFValueList;
                }

                throw new PdfCoreStructureException('Could not resolve '.$context.' object while attaching document timestamp.');
            }

            return new PDFValueList(array_values($listObject->getValue()->val()));
        }

        if ($value instanceof PDFValueObject) {
            return new PDFValueList(array_values($value->val()));
        }

        return new PDFValueList;
    }
}
