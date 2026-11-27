<?php

/**
 * PDF downloads for every export (admin/export.php, agent/export.php, the internship / employee hours reports).
 *
 * mentora_pdf() lays the rows out as a table on landscape A4 pages: the title and when it was generated, then the
 * table, with the header row repeated on every page, long cells wrapped onto more lines, and page numbers.
 * Plain PHP, no library or extension beyond zlib: it uses the PDF viewer's built-in Helvetica font, so text is
 * limited to Windows-1252 characters (English, Filipino and Western European letters, ’ “ ” — · …); any other
 * character prints as "?".
 */

declare(strict_types=1);

const MENTORA_PDF_PAGE = [842.0, 595.0]; // A4 landscape, in points
const MENTORA_PDF_MARGIN = 28.0;
const MENTORA_PDF_PAD = 3.0;             // space inside each table cell
const MENTORA_PDF_MAX_COLUMN = 260.0;    // a column never asks for more than this before sharing space
const MENTORA_PDF_MIN_COLUMN = 34.0;     // narrowest a column gets when the table has to shrink
const MENTORA_PDF_MAX_FLOOR = 72.0;      // ...unless its longest word needs more, up to this

/** Send $rows (each a list of cells, in $header's order) as a PDF table download and stop. */
function mentora_pdf(string $title, string $fileName, array $header, iterable $rows): never
{
    $cells = [];
    foreach ($rows as $row) {
        $cells[] = array_map(static fn($cell): string => (string) $cell, array_values((array) $row));
    }
    $subtitle = 'Generated ' . date('d M Y, g:i A') . ' · ' . count($cells) . ' record' . (count($cells) === 1 ? '' : 's');
    $pdf = mentora_pdf_table($title, $subtitle, array_map('strval', $header), $cells);

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $fileName) . '"');
    header('Content-Length: ' . strlen($pdf));
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo $pdf;
    exit;
}

/** Build the PDF file (as a string) for a titled table. */
function mentora_pdf_table(string $title, string $subtitle, array $header, array $rows): string
{
    [$pageWidth, $pageHeight] = MENTORA_PDF_PAGE;
    $margin = MENTORA_PDF_MARGIN;
    $pad = MENTORA_PDF_PAD;
    $columns = max(1, count($header));
    $size = $columns > 14 ? 6.5 : ($columns > 9 ? 7.0 : 8.0);
    $lineHeight = $size * 1.25;
    $tableWidth = $pageWidth - 2 * $margin;
    $bottom = $margin + 10; // leaves room for the footer

    $header = array_map('mentora_pdf_text', array_pad($header, $columns, ''));
    $rows = array_map(static fn(array $row): array => array_map('mentora_pdf_text', array_slice(array_pad($row, $columns, ''), 0, $columns)), $rows);
    $widths = mentora_pdf_column_widths($header, $rows, $size, $tableWidth);

    // Every cell's lines; a row taller than a page is cut short with "..." (very long text in a narrow column).
    $maxLines = max(1, (int) floor(($pageHeight - 2 * $margin - 60) / $lineHeight) - 6);
    $layout = static function (array $row, bool $bold) use ($widths, $size, $pad, $maxLines): array {
        $lines = [];
        foreach ($row as $i => $text) {
            $cell = mentora_pdf_wrap($text, $widths[$i] - 2 * $pad, $size, $bold);
            if (count($cell) > $maxLines) {
                $cell = array_slice($cell, 0, $maxLines);
                $cell[$maxLines - 1] .= '...';
            }
            $lines[] = $cell;
        }
        return $lines;
    };
    $rowHeight = static fn(array $lines): float => max(array_map('count', $lines)) * $lineHeight + 2 * $pad;

    $headerLines = $layout($header, true);
    $headerHeight = $rowHeight($headerLines);
    $pages = [];
    $page = '';
    $y = 0.0;

    $drawRow = static function (array $lines, float $height, bool $isHeader, bool $shaded) use (&$page, &$y, $widths, $margin, $pad, $size, $lineHeight, $tableWidth): void {
        if ($isHeader) {
            $page .= mentora_pdf_rect($margin, $y - $height, $tableWidth, $height, [0.043, 0.384, 0.365]);
        } elseif ($shaded) {
            $page .= mentora_pdf_rect($margin, $y - $height, $tableWidth, $height, [0.945, 0.965, 0.961]);
        }
        $x = $margin;
        foreach ($lines as $i => $cell) {
            $baseline = $y - $pad - $size * 0.85;
            foreach ($cell as $line) {
                if ($line !== '') {
                    $page .= mentora_pdf_text_op($x + $pad, $baseline, $line, $size, $isHeader, $isHeader ? [1, 1, 1] : [0.13, 0.17, 0.17]);
                }
                $baseline -= $lineHeight;
            }
            $x += $widths[$i];
        }
        $y -= $height;
        $page .= sprintf("0.85 0.88 0.88 RG 0.5 w %.2F %.2F m %.2F %.2F l S\n", $margin, $y, $margin + $tableWidth, $y);
    };
    $newPage = static function (bool $first) use (&$page, &$pages, &$y, $pageHeight, $margin, $title, $subtitle, $headerLines, $headerHeight, $drawRow): void {
        if ($page !== '') {
            $pages[] = $page;
        }
        $page = '';
        $y = $pageHeight - $margin;
        if ($first) {
            $page .= mentora_pdf_text_op($margin, $y - 14, mentora_pdf_text($title), 14, true, [0.043, 0.384, 0.365]);
            $page .= mentora_pdf_text_op($margin, $y - 28, mentora_pdf_text($subtitle), 8, false, [0.4, 0.45, 0.45]);
            $y -= 40;
        }
        $drawRow($headerLines, $headerHeight, true, false);
    };

    $newPage(true);
    if (!$rows) {
        $drawRow([[mentora_pdf_text('No records.')]], $lineHeight + 2 * $pad, false, false);
    }
    foreach ($rows as $index => $row) {
        $lines = $layout($row, false);
        $height = $rowHeight($lines);
        if ($y - $height < $bottom) {
            $newPage(false);
        }
        $drawRow($lines, $height, false, $index % 2 === 1);
    }
    $pages[] = $page;

    // Footer on every page, now that the page count is known.
    $footerLeft = mentora_pdf_text('Mentora · ' . $title);
    foreach ($pages as $number => &$content) {
        $right = 'Page ' . ($number + 1) . ' of ' . count($pages);
        $content .= mentora_pdf_text_op($margin, $margin - 12, $footerLeft, 7, false, [0.5, 0.55, 0.55]);
        $content .= mentora_pdf_text_op($pageWidth - $margin - mentora_pdf_width($right, 7, false), $margin - 12, $right, 7, false, [0.5, 0.55, 0.55]);
    }
    unset($content);

    return mentora_pdf_document($pages, mentora_pdf_text($title));
}

/**
 * Column widths that fill the table, in proportion to each column's content (so long text such as an AI summary
 * gets the most room). When the table is too wide, columns shrink in proportion but never below
 * MENTORA_PDF_MIN_COLUMN (or their own content, if that is narrower).
 */
function mentora_pdf_column_widths(array $header, array $rows, float $size, float $tableWidth): array
{
    $pad = 2 * MENTORA_PDF_PAD;
    $natural = [];
    $longestWord = [];
    foreach ($header as $i => $label) {
        $width = mentora_pdf_longest_line($label, $size, true);
        $word = mentora_pdf_longest_word($label, $size, true);
        foreach ($rows as $row) {
            $width = max($width, mentora_pdf_longest_line($row[$i], $size, false));
            $word = max($word, mentora_pdf_longest_word($row[$i], $size, false));
        }
        $natural[$i] = min($width, MENTORA_PDF_MAX_COLUMN) + $pad;
        $longestWord[$i] = $word + $pad;
    }
    $total = array_sum($natural);
    if ($total <= $tableWidth) {
        return array_map(static fn(float $w): float => $w * $tableWidth / $total, $natural);
    }
    // Shrink in proportion; a column that would drop below its floor is fixed there and the rest share what's left.
    // A column's floor fits its longest word (e.g. "Department", a date), within MIN..MAX_FLOOR.
    $floors = [];
    foreach ($natural as $i => $width) {
        $floors[$i] = min($width, max(MENTORA_PDF_MIN_COLUMN, min($longestWord[$i], MENTORA_PDF_MAX_FLOOR)));
    }
    if (array_sum($floors) > $tableWidth) {
        $floors = array_map(static fn(float $w): float => $w * $tableWidth / array_sum($floors), $floors);
    }
    $widths = [];
    $free = $natural;
    do {
        $scale = ($tableWidth - array_sum($widths)) / array_sum($free);
        $fixedAny = false;
        foreach ($free as $i => $width) {
            if ($width * $scale < $floors[$i]) {
                $widths[$i] = $floors[$i];
                unset($free[$i]);
                $fixedAny = true;
            }
        }
    } while ($fixedAny && $free);
    foreach ($free as $i => $width) {
        $widths[$i] = $width * $scale;
    }
    ksort($widths);
    return $widths;
}

function mentora_pdf_longest_line(string $text, float $size, bool $bold): float
{
    $longest = 0.0;
    foreach (explode("\n", $text) as $line) {
        $longest = max($longest, mentora_pdf_width($line, $size, $bold));
    }
    return $longest;
}

function mentora_pdf_longest_word(string $text, float $size, bool $bold): float
{
    $longest = 0.0;
    foreach (preg_split('/\s+/', $text) ?: [] as $word) {
        $longest = max($longest, mentora_pdf_width($word, $size, $bold));
    }
    return $longest;
}

/** Split text into lines no wider than $max points; words longer than a line are broken. */
function mentora_pdf_wrap(string $text, float $max, float $size, bool $bold): array
{
    $lines = [];
    foreach (explode("\n", $text) as $paragraph) {
        $line = '';
        foreach (explode(' ', $paragraph) as $word) {
            if ($word === '') {
                continue;
            }
            $candidate = $line === '' ? $word : $line . ' ' . $word;
            if (mentora_pdf_width($candidate, $size, $bold) <= $max) {
                $line = $candidate;
                continue;
            }
            if ($line !== '') {
                $lines[] = $line;
            }
            while (strlen($word) > 1 && mentora_pdf_width($word, $size, $bold) > $max) {
                $cut = 1;
                while ($cut < strlen($word) - 1 && mentora_pdf_width(substr($word, 0, $cut + 1), $size, $bold) <= $max) {
                    $cut++;
                }
                $lines[] = substr($word, 0, $cut);
                $word = substr($word, $cut);
            }
            $line = $word;
        }
        $lines[] = $line;
    }
    return $lines;
}

/** UTF-8 to the Windows-1252 bytes the built-in fonts use, with spacing tidied. */
function mentora_pdf_text(string $text): string
{
    $text = str_replace(["\r\n", "\r", "\t"], ["\n", "\n", ' '], $text);
    $text = trim(preg_replace(['/ {2,}/', '/\n{2,}/'], [' ', "\n"], $text) ?? $text);
    $converted = function_exists('iconv') ? @iconv('UTF-8', 'Windows-1252//TRANSLIT', $text) : false;
    return $converted !== false ? $converted : mb_convert_encoding($text, 'Windows-1252', 'UTF-8');
}

/** Width of Windows-1252 text in points (Helvetica / Helvetica-Bold metrics). */
function mentora_pdf_width(string $text, float $size, bool $bold): float
{
    static $metrics = null;
    if ($metrics === null) {
        $ascii = [
            // characters 32 to 126
            '278 278 355 556 556 889 667 191 333 333 389 584 278 333 278 278 556 556 556 556 556 556 556 556 556 556 278 278 584 584 584 556 1015 667 667 722 722 667 611 778 722 278 500 667 556 833 722 778 667 778 722 667 611 722 667 944 667 667 611 278 278 278 469 556 333 556 556 500 556 556 278 556 556 222 222 500 222 833 556 556 556 556 333 500 278 556 500 722 500 500 500 334 260 334 584',
            '278 333 474 556 556 889 722 238 333 333 389 584 278 333 278 278 556 556 556 556 556 556 556 556 556 556 333 333 584 584 584 611 975 722 722 722 722 667 611 778 722 278 556 722 611 833 722 778 667 778 722 667 611 722 667 944 667 667 611 333 278 333 584 556 333 556 611 556 611 556 333 611 611 278 278 556 278 889 611 611 611 611 389 556 333 611 556 778 556 556 500 389 280 389 584',
        ];
        // Punctuation above 127 that is narrower or wider than a letter; other letters count as 667 (safe for wrapping).
        $extra = [0x85 => 1000, 0x91 => 278, 0x92 => 278, 0x93 => 500, 0x94 => 500, 0x95 => 350, 0x96 => 556, 0x97 => 1000, 0xA0 => 278, 0xB7 => 278];
        foreach ($ascii as $font => $list) {
            $table = array_fill(0, 256, 667);
            for ($code = 0; $code < 32; $code++) {
                $table[$code] = 0;
            }
            foreach (explode(' ', $list) as $offset => $width) {
                $table[32 + $offset] = (int) $width;
            }
            $metrics[$font] = array_replace($table, $extra);
        }
    }
    $font = $metrics[$bold ? 1 : 0];
    $units = 0;
    foreach (count_chars($text, 1) as $code => $times) {
        $units += $font[$code] * $times;
    }
    return $units * $size / 1000;
}

function mentora_pdf_rect(float $x, float $y, float $width, float $height, array $rgb): string
{
    return sprintf("%.3F %.3F %.3F rg %.2F %.2F %.2F %.2F re f\n", $rgb[0], $rgb[1], $rgb[2], $x, $y, $width, $height);
}

function mentora_pdf_text_op(float $x, float $y, string $text, float $size, bool $bold, array $rgb): string
{
    return sprintf(
        "BT /%s %.1F Tf %.3F %.3F %.3F rg %.2F %.2F Td (%s) Tj ET\n",
        $bold ? 'F2' : 'F1', $size, $rgb[0], $rgb[1], $rgb[2], $x, $y, mentora_pdf_escape($text)
    );
}

function mentora_pdf_escape(string $text): string
{
    return str_replace(['\\', '(', ')', "\n"], ['\\\\', '\\(', '\\)', ' '], $text);
}

/** Wrap page content streams into a complete PDF 1.4 file. */
function mentora_pdf_document(array $pages, string $title): string
{
    [$pageWidth, $pageHeight] = MENTORA_PDF_PAGE;
    // 1 catalog, 2 page tree, 3-4 fonts, 5 document info, then a page and its content for every page.
    $objects = [
        1 => '<< /Type /Catalog /Pages 2 0 R >>',
        2 => '',
        3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
        4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
        5 => '<< /Title (' . mentora_pdf_escape($title) . ') /Producer (Mentora) /CreationDate (D:' . date('YmdHis') . ') >>',
    ];
    $kids = [];
    foreach ($pages as $content) {
        $stream = function_exists('gzcompress') ? gzcompress($content) : $content;
        $filter = function_exists('gzcompress') ? ' /Filter /FlateDecode' : '';
        $contentId = count($objects) + 1;
        $objects[$contentId] = '<< /Length ' . strlen($stream) . $filter . " >>\nstream\n" . $stream . "\nendstream";
        $pageId = $contentId + 1;
        $objects[$pageId] = sprintf(
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.0F %.0F] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>',
            $pageWidth, $pageHeight, $contentId
        );
        $kids[] = $pageId . ' 0 R';
    }
    $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';

    $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
    $offsets = [];
    foreach ($objects as $id => $body) {
        $offsets[$id] = strlen($pdf);
        $pdf .= $id . " 0 obj\n" . $body . "\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= 'xref' . "\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
    foreach ($offsets as $offset) {
        $pdf .= sprintf("%010d 00000 n \n", $offset);
    }
    return $pdf . 'trailer' . "\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R /Info 5 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
}
