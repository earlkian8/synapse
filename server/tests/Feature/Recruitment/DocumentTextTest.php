<?php

use App\Support\Files\DocumentText;
use Dompdf\Dompdf;
use Symfony\Component\Process\ExecutableFinder;

/*
| Reading the words out of uploaded documents (ADR 0097). The PDF and OCR
| cases need poppler and tesseract, which the image installs; where this
| machine lacks one, that case is skipped rather than faked.
*/

function atsTempFile(string $extension, string $contents): string
{
    $path = tempnam(sys_get_temp_dir(), 'ats-text-').'.'.$extension;
    file_put_contents($path, $contents);

    return $path;
}

test('a Word document is read from its XML, headers included', function () {
    $path = tempnam(sys_get_temp_dir(), 'ats-docx-');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('word/document.xml', '<w:document><w:body><w:p><w:r><w:t>Skills:</w:t></w:r><w:r><w:tab/><w:t>TypeScript &amp; Go</w:t></w:r></w:p><w:p><w:r><w:t>Second line</w:t></w:r></w:p></w:body></w:document>');
    $zip->addFromString('word/header1.xml', '<w:hdr><w:p><w:r><w:t>Ana Reyes · ana@example.com</w:t></w:r></w:p></w:hdr>');
    $zip->close();

    $text = (new DocumentText)->fromFile($path, 'docx');

    expect($text)->toStartWith("Skills: TypeScript & Go\nSecond line")
        ->and($text)->toContain('Ana Reyes');
});

test('a PDF with a text layer is read', function () {
    if ((new ExecutableFinder)->find('pdftotext') === null) {
        $this->markTestSkipped('pdftotext is not installed here.');
    }

    $dompdf = new Dompdf;
    $dompdf->loadHtml('<h1>Maria Santos</h1><p>Senior accountant: bookkeeping, payroll and BIR compliance since 2015.</p>');
    $dompdf->render();

    $text = (new DocumentText)->fromFile(atsTempFile('pdf', $dompdf->output()), 'pdf');

    expect($text)->toContain('bookkeeping, payroll and BIR compliance');
});

test('a scanned résumé is read by OCR', function () {
    if ((new ExecutableFinder)->find('tesseract') === null) {
        $this->markTestSkipped('tesseract is not installed here.');
    }

    $image = imagecreatetruecolor(900, 160);
    imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
    $small = imagecreatetruecolor(300, 40);
    imagefill($small, 0, 0, imagecolorallocate($small, 255, 255, 255));
    imagestring($small, 5, 10, 12, 'PYTHON DEVELOPER', imagecolorallocate($small, 0, 0, 0));
    imagecopyresampled($image, $small, 0, 0, 0, 0, 900, 120, 300, 40);
    ob_start();
    imagepng($image);
    $png = (string) ob_get_clean();

    $text = (new DocumentText)->fromFile(atsTempFile('png', $png), 'png');

    expect(strtoupper((string) $text))->toContain('PYTHON');
});

test('the older Word format is read from the text it stores', function () {
    $bytes = "\xD0\xCF\x11\xE0\x00\x00".mb_convert_encoding('Skills: Laravel and Docker', 'UTF-16LE', 'UTF-8')."\x00\x00\x01\x02";

    expect((new DocumentText)->fromFile(atsTempFile('doc', $bytes), 'doc'))->toContain('Laravel and Docker');
});

test('a file that is not what it claims, or of an unknown type, gives nothing', function () {
    expect((new DocumentText)->fromFile(atsTempFile('docx', 'not a zip'), 'docx'))->toBeNull()
        ->and((new DocumentText)->fromFile(atsTempFile('exe', 'MZ'), 'exe'))->toBeNull()
        ->and((new DocumentText)->fromUpload(null))->toBeNull()
        ->and((new DocumentText)->fromUpload('https://example.com/cv.pdf'))->toBeNull();
});

test('text is cleaned: no control characters, tidy spacing, a bounded length', function () {
    $path = atsTempFile('txt', "Line\x07 one   \n\n\n\nLine two ".str_repeat('x', DocumentText::MAX_CHARS));

    $text = (string) (new DocumentText)->fromFile($path, 'txt');

    expect($text)->toStartWith("Line one\n\nLine two")
        ->and(mb_strlen($text))->toBe(DocumentText::MAX_CHARS);
});
