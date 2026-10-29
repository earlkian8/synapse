<?php

namespace App\Support\Files;

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Symfony\Component\Process\ExecutableFinder;
use Throwable;
use ZipArchive;

/**
 * Reads the words out of an uploaded document (ADR 0097), so the ATS can match
 * a résumé's skills instead of only the candidate's headline.
 *
 *  - **PDF** with `pdftotext` (poppler). A scanned PDF has no text layer, so
 *    when it gives back almost nothing its first pages are rendered
 *    (`pdftoppm`) and read by OCR (`tesseract`).
 *  - **Word** (.docx) straight from its XML; the older .doc with `antiword` or
 *    `catdoc` when installed, otherwise from the text runs in the file.
 *  - **Images** (a photographed résumé) by OCR.
 *
 * Each tool is optional: one that isn't installed only means that kind of
 * file can't be read, never an error. Commands run without a shell, with a
 * time limit, on a private copy of the file that is deleted afterwards.
 */
class DocumentText
{
    /** The most text kept from one document — far more than any résumé. */
    public const MAX_CHARS = 60000;

    /** Files larger than this are not read (uploads are capped at 10 MB). */
    private const MAX_BYTES = 20 * 1024 * 1024;

    /** Less text than this from a PDF means it is a scan. */
    private const SCANNED_BELOW = 80;

    /** How many pages of a scan are read by OCR. */
    private const OCR_PAGES = 3;

    /** Seconds one command may take. */
    private const TIMEOUT = 45;

    /** @var array<string, string|false> */
    private static array $binaries = [];

    /**
     * The text of a file on the uploads disk: a string (perhaps empty, when the
     * file holds no readable words), or null when it can't be read at all.
     */
    public function fromUpload(?string $path): ?string
    {
        if (blank($path) || Str::startsWith($path, ['http://', 'https://'])) {
            return null;
        }

        $disk = Uploads::disk();

        try {
            if (! $disk->exists($path) || $disk->size($path) > self::MAX_BYTES) {
                return null;
            }

            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $copy = $this->temporaryFile($extension);
            $stream = $disk->readStream($path);

            if ($stream === null) {
                return null;
            }

            try {
                file_put_contents($copy, $stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            try {
                return $this->fromFile($copy, $extension);
            } finally {
                @unlink($copy);
            }
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    /**
     * The text of a local file, read by its extension.
     */
    public function fromFile(string $file, string $extension): ?string
    {
        if (! is_file($file) || filesize($file) > self::MAX_BYTES) {
            return null;
        }

        $text = match (strtolower($extension)) {
            'pdf' => $this->pdf($file),
            'docx' => $this->docx($file),
            'doc' => $this->doc($file),
            'png', 'jpg', 'jpeg', 'webp' => $this->ocr($file),
            'txt' => (string) file_get_contents($file),
            default => null,
        };

        return $text === null ? null : $this->clean($text);
    }

    private function pdf(string $file): ?string
    {
        $text = $this->run('pdftotext', ['-enc', 'UTF-8', '-nopgbrk', '-l', '20', $file, '-']);

        if ($text !== null && strlen(trim($text)) >= self::SCANNED_BELOW) {
            return $text;
        }

        return $this->ocrPdf($file) ?? $text;
    }

    /**
     * Render the first pages of a scanned PDF and read them by OCR.
     */
    private function ocrPdf(string $file): ?string
    {
        if (! $this->binary('pdftoppm') || ! $this->binary('tesseract')) {
            return null;
        }

        $directory = sys_get_temp_dir().'/synapse-ocr-'.Str::random(16);
        @mkdir($directory, 0700);

        try {
            if ($this->run('pdftoppm', ['-r', '200', '-l', (string) self::OCR_PAGES, '-png', $file, $directory.'/page']) === null) {
                return null;
            }

            $pages = glob($directory.'/page*.png') ?: [];
            sort($pages);

            $text = implode("\n", array_filter(array_map(fn (string $page): ?string => $this->ocr($page), $pages)));

            return $text === '' ? null : $text;
        } finally {
            foreach (glob($directory.'/*') ?: [] as $leftover) {
                @unlink($leftover);
            }

            @rmdir($directory);
        }
    }

    private function ocr(string $image): ?string
    {
        return $this->run('tesseract', [$image, 'stdout', '-l', 'eng', '--psm', '3']);
    }

    /**
     * A .docx is a zip of XML: the body, plus the headers and footers where a
     * résumé often keeps its contact line.
     */
    private function docx(string $file): ?string
    {
        $zip = new ZipArchive;

        if ($zip->open($file, ZipArchive::RDONLY) !== true) {
            return null;
        }

        try {
            $parts = [];

            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                $name = (string) ($stat['name'] ?? '');

                if (preg_match('#^word/(document|header\d*|footer\d*)\.xml$#', $name) !== 1) {
                    continue;
                }

                // A bomb that unpacks to gigabytes is not a résumé.
                if (($stat['size'] ?? 0) > self::MAX_BYTES) {
                    continue;
                }

                $xml = $zip->getFromIndex($index);

                if (is_string($xml)) {
                    $parts[$name] = $this->wordXmlText($xml);
                }
            }

            if ($parts === []) {
                return null;
            }

            // The body first, then whatever surrounds it.
            uksort($parts, fn (string $a, string $b): int => ($a === 'word/document.xml' ? 0 : 1) <=> ($b === 'word/document.xml' ? 0 : 1));

            return implode("\n", $parts);
        } finally {
            $zip->close();
        }
    }

    private function wordXmlText(string $xml): string
    {
        $xml = (string) preg_replace(['#</w:p>#', '#<w:tab\s*/>#', '#<w:(br|cr)\s*/>#'], ["\n", "\t", "\n"], $xml);

        return html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /**
     * The older Word format, by a converter when one is installed; otherwise
     * from the runs of text the file stores (8-bit or UTF-16), which is enough
     * to find words in.
     */
    private function doc(string $file): ?string
    {
        foreach ([['antiword', [$file]], ['catdoc', ['-w', $file]]] as [$tool, $arguments]) {
            if ($this->binary($tool) && ($text = $this->run($tool, $arguments)) !== null && trim($text) !== '') {
                return $text;
            }
        }

        $bytes = (string) file_get_contents($file);

        preg_match_all('/(?:[\x20-\x7E\r\n\t]\x00){4,}/', $bytes, $wide);
        $wideText = implode("\n", array_map(fn (string $run): string => (string) mb_convert_encoding($run, 'UTF-8', 'UTF-16LE'), $wide[0]));

        preg_match_all('/[\x20-\x7E\r\n\t]{6,}/', $bytes, $narrow);
        $narrowText = implode("\n", array_filter($narrow[0], fn (string $run): bool => preg_match_all('/[A-Za-z]/', $run) >= strlen($run) / 2));

        $text = strlen($wideText) >= strlen($narrowText) ? $wideText : $narrowText;

        return trim($text) === '' ? null : $text;
    }

    /**
     * Run a tool and return what it printed, or null when it isn't installed,
     * fails or runs out of time.
     *
     * @param  list<string>  $arguments
     */
    private function run(string $tool, array $arguments): ?string
    {
        $binary = $this->binary($tool);

        if ($binary === null) {
            return null;
        }

        try {
            $result = Process::timeout(self::TIMEOUT)->run([$binary, ...$arguments]);
        } catch (Throwable) {
            return null;
        }

        return $result->successful() ? $result->output() : null;
    }

    private function binary(string $tool): ?string
    {
        self::$binaries[$tool] ??= (new ExecutableFinder)->find($tool) ?? false;

        return self::$binaries[$tool] ?: null;
    }

    private function temporaryFile(string $extension): string
    {
        $base = tempnam(sys_get_temp_dir(), 'synapse-read-');
        $file = $base.($extension !== '' ? '.'.$extension : '');
        rename($base, $file);

        return $file;
    }

    /**
     * Valid UTF-8, no control characters, one space between words, at most two
     * line breaks in a row, and no longer than {@see MAX_CHARS}.
     */
    private function clean(string $text): string
    {
        $text = (string) mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        $text = (string) preg_replace('/[^\P{C}\n\t]+/u', ' ', $text);
        $text = (string) preg_replace('/[ \t\x{00A0}]+/u', ' ', $text);
        $text = (string) preg_replace('/ *\n */', "\n", $text);
        $text = (string) preg_replace('/\n{3,}/', "\n\n", $text);

        return mb_substr(trim($text), 0, self::MAX_CHARS);
    }
}
