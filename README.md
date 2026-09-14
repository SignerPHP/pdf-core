# signerphp/pdf-core

Standalone PHP library for PDF parsing, object manipulation and incremental serialization. Requires PHP 8.2+, zlib, fileinfo and ctype. It has no dependency on Signer PHP, OpenSSL, certificates, signing providers or timestamp services.

```bash
composer require signerphp/pdf-core
```

Until the package is on Packagist, require it from GitHub:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/SignerPHP/pdf-core"
        }
    ],
    "require": {
        "signerphp/pdf-core": "^0.1"
    }
}
```

```php
use SignerPHP\PdfCore\Service\PdfDocumentPreparer;
use SignerPHP\PdfCore\Service\IncrementalUpdateWriter;
use SignerPHP\PdfCore\PdfValue\PDFValueString;

$document = (new PdfDocumentPreparer)->prepare(file_get_contents('input.pdf'));
$info = $document->getObject($document->getTrailerObject()['Info']->asObjectReferenceOrNull());
$info['Title'] = new PDFValueString('Updated title');
$document->addObject($info);
file_put_contents('output.pdf', (new IncrementalUpdateWriter)->write($document)->raw());
```

The example assumes an existing indirect Info dictionary. Use `createObject()` and a `PDFValueReference` to add one when absent. Explicitly register modified objects with `addObject()`. For another update, parse the serialized output again; a document retains its original input buffer and pending objects.

## Scope

PDF objects/values, streams and object streams, trailers, classic and stream xrefs, incremental revisions, page information, image/XObject rendering, AcroForm signature fields, signature dictionaries, reserved Contents/ByteRange layout, and DocMDP dictionary assembly. `SignatureObject` only represents PDF bytes: it does not produce or validate a cryptographic signature. SubFilter names are PDF dictionary values, not implementations of those protocols.

`SignatureObjectAssembler::assemble()` accepts an optional integer DocMDP permission (1, 2 or 3). `AppearanceXObject` holds PDF stream operators and resources. `DocumentTimestampObject` represents a PDF dictionary only; no network or token processing exists here.

## Development with Docker Compose

```sh
docker compose run --rm app composer install
docker compose run --rm app composer tests
docker compose run --rm app composer pint:check
```

This package builds and tests with only its own directory mounted. Its test fixtures do not require Signer PHP. The sibling Signer PHP repository uses a Composer path repository for local development. Publish and tag this package before releasing the consuming Signer PHP version; replace the local path repository with the published package for distribution.

## Known inherited limitations

- Classic xrefs, xref streams, object streams, `/Prev` and hybrid `/XRefStm` section merging are supported. A hybrid trailer’s `/XRefStm` is treated as a supplement to that classic table (table entries win on conflict); revision history still comes from `/Prev`.
- Regex-based structural inspection is diagnostic, not authoritative parsing.
- Inline AcroForm/Perms handling, generation serialization and fixed xref entry widths need separate review.
- FormMDP can be represented using PDF dictionaries but has no dedicated builder yet.
- Legacy Producer text and compatibility value methods are retained to preserve output.

MIT licensed; extracted from Signer PHP by Jeidison Farias.
