<?php

/**
 * Plain text from an uploaded resume, for the AI review: PDF, DOCX, DOC and TXT.
 * Pure PHP (zlib only), so it works on a default XAMPP install without the zip extension.
 * Best effort: scanned (image-only) PDFs have no text; the review then asks HR to open the file.
 */

declare(strict_types=1);

const MENTORA_RESUME_EXTENSIONS = ['pdf', 'docx', 'doc', 'txt'];
const MENTORA_AI_TEXT_LIMIT = 20000;

function mentora_ai_extract_text(string $path, string $extension): string
{
    if (!is_file($path) || !is_readable($path)) {
        return '';
    }
    $raw = (string) file_get_contents($path);
    $text = match (strtolower($extension)) {
        'txt' => $raw,
        'docx' => mentora_ai_docx_text($raw),
        'pdf' => mentora_ai_pdf_text($raw),
        'doc' => implode(' ', preg_match_all('/[\x20-\x7E]{4,}/', $raw, $runs) ? $runs[0] : []),
        default => '',
    };
    if (!mb_check_encoding($text, 'UTF-8')) {
        $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
    }
    $text = preg_replace("/[ \t\x{00A0}]+/u", ' ', $text) ?? '';
    $text = preg_replace("/\s*\n\s*/u", "\n", $text) ?? '';

    return mb_substr(trim($text), 0, MENTORA_AI_TEXT_LIMIT);
}

/** word/document.xml from the .docx (a zip file), as text with one line per paragraph. */
function mentora_ai_docx_text(string $zip): string
{
    $xml = mentora_ai_zip_entry($zip, 'word/document.xml');
    if ($xml === null) {
        return '';
    }
    $xml = str_replace(['</w:p>', '<w:tab/>', '<w:br/>'], ["\n", ' ', "\n"], $xml);

    return html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/** One file from a zip archive, read through its central directory (stored or deflated entries). */
function mentora_ai_zip_entry(string $zip, string $name): ?string
{
    if (class_exists('ZipArchive')) {
        $tmp = tempnam(sys_get_temp_dir(), 'mzip');
        if ($tmp !== false && file_put_contents($tmp, $zip) !== false) {
            $archive = new ZipArchive();
            $content = $archive->open($tmp) === true ? $archive->getFromName($name) : false;
            $archive->close();
            @unlink($tmp);
            if (is_string($content)) {
                return $content;
            }
        }
    }
    $end = strrpos($zip, "PK\x05\x06");
    if ($end === false || strlen($zip) < $end + 22) {
        return null;
    }
    // End of central directory: ... total entries (2 bytes) at +10, directory size (4) at +12, its offset (4) at +16.
    $directory = unpack('ventries/Vsize/Voffset', substr($zip, $end + 10, 10));
    $position = (int) $directory['offset'];
    for ($i = 0; $i < (int) $directory['entries'] && substr($zip, $position, 4) === "PK\x01\x02"; $i++) {
        $entry = unpack('vmethod/x8/Vcompressed/Vsize/vnameLength/vextraLength/vcommentLength/x8/Voffset', substr($zip, $position + 10, 36));
        $entryName = substr($zip, $position + 46, $entry['nameLength']);
        if ($entryName === $name) {
            $local = unpack('vnameLength/vextraLength', substr($zip, $entry['offset'] + 26, 4));
            $data = substr($zip, $entry['offset'] + 30 + $local['nameLength'] + $local['extraLength'], $entry['compressed']);
            return match ((int) $entry['method']) {
                0 => $data,
                8 => @gzinflate($data) ?: null,
                default => null,
            };
        }
        $position += 46 + $entry['nameLength'] + $entry['extraLength'] + $entry['commentLength'];
    }

    return null;
}

/** Text drawn by a PDF's content streams (Tj / TJ / ' / " operators). */
function mentora_ai_pdf_text(string $pdf): string
{
    if (!preg_match_all('/<<(.*?)>>\s*stream\r?\n(.*?)\r?\n?endstream/s', $pdf, $streams, PREG_SET_ORDER)) {
        return '';
    }
    $text = '';
    foreach ($streams as [, $dictionary, $data]) {
        if (str_contains($dictionary, '/Image') || str_contains($dictionary, '/FontFile')) {
            continue;
        }
        if (str_contains($dictionary, '/FlateDecode')) {
            $data = @gzuncompress($data) ?: (@gzinflate($data) ?: '');
        }
        if ($data === '' || !str_contains($data, 'BT')) {
            continue;
        }
        $text .= mentora_ai_pdf_operators($data) . "\n";
        if (strlen($text) > MENTORA_AI_TEXT_LIMIT * 2) {
            break;
        }
    }

    return $text;
}

/** Strings shown between BT and ET in one content stream, with line breaks on moves to a new line. */
function mentora_ai_pdf_operators(string $content): string
{
    $out = '';
    preg_match_all('/BT(.*?)ET/s', $content, $blocks);
    foreach ($blocks[1] as $block) {
        preg_match_all('/(\[(?:\\\\.|[^\]])*\]\s*TJ|\((?:\\\\.|[^\\\\)])*\)\s*(?:Tj|\'|")|<[0-9A-Fa-f\s]+>\s*Tj|T\*|-?[\d.]+\s+-?[\d.]+\s+T[dD])/s', $block, $operators);
        foreach ($operators[1] as $operator) {
            if ($operator === 'T*' || preg_match('/T[dD]$/', $operator)) {
                $out .= "\n";
                continue;
            }
            if ($operator[0] === '[') {
                preg_match_all('/\((?:\\\\.|[^\\\\)])*\)|<[0-9A-Fa-f\s]+>|-?\d+(?:\.\d+)?/s', $operator, $parts);
                foreach ($parts[0] as $part) {
                    if ($part[0] === '(' || $part[0] === '<') {
                        $out .= mentora_ai_pdf_string($part);
                    } elseif ((float) $part < -200) {
                        $out .= ' '; // a wide gap between letters is a space
                    }
                }
            } else {
                $out .= mentora_ai_pdf_string(preg_replace('/\s*(Tj|\'|")$/', '', $operator) ?? '');
            }
        }
        $out .= "\n";
    }

    return $out;
}

/** Decode one PDF string literal "(...)" or hex string "<...>" (Latin text or UTF-16). */
function mentora_ai_pdf_string(string $value): string
{
    if ($value === '') {
        return '';
    }
    if ($value[0] === '<') {
        $bytes = (string) @hex2bin(preg_replace('/[^0-9A-Fa-f]/', '', $value) ?? '');
        if (strlen($bytes) % 2 === 0 && preg_match('/^(\x00[\x20-\x7E])+$/', $bytes)) {
            return mb_convert_encoding($bytes, 'UTF-8', 'UTF-16BE');
        }
        return preg_match('/^[\x20-\x7E]+$/', $bytes) ? $bytes : ''; // glyph ids without a font map: unreadable
    }
    $inner = substr($value, 1, -1);
    $inner = preg_replace_callback('/\\\\([0-7]{1,3}|.)/s', static function (array $m): string {
        return match (true) {
            ctype_digit($m[1]) => chr(octdec($m[1]) & 0xFF),
            $m[1] === 'n' => "\n",
            $m[1] === 'r' => '',
            $m[1] === 't' => ' ',
            $m[1] === 'b', $m[1] === 'f', $m[1] === "\n", $m[1] === "\r" => '',
            default => $m[1],
        };
    }, $inner) ?? '';
    if (str_starts_with($inner, "\xFE\xFF")) {
        return mb_convert_encoding(substr($inner, 2), 'UTF-8', 'UTF-16BE');
    }

    return $inner;
}
