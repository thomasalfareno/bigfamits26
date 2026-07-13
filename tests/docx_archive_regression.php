<?php

require_once __DIR__ . '/../config/security.php';

$tmpBase = tempnam(sys_get_temp_dir(), 'bigfam_docx_');
if ($tmpBase === false) {
    fwrite(STDERR, "Tidak dapat membuat file sementara.\n");
    exit(1);
}
@unlink($tmpBase);

$zipPath = $tmpBase . '.zip';
$docxPath = $tmpBase . '.docx';

try {
    $documentXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
        . '<w:body><w:p><w:r><w:t>fallback-ok</w:t></w:r></w:p></w:body></w:document>';
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
            throw new RuntimeException('Tidak dapat membuat arsip ZIP pengujian.');
        }
        $zip->addFromString('word/document.xml', $documentXml);
        $zip->close();
    } else {
        $zip = new PharData($zipPath);
        $zip->addFromString('word/document.xml', $documentXml);
        unset($zip);
    }

    if (!rename($zipPath, $docxPath)) {
        throw new RuntimeException('Tidak dapat menyiapkan DOCX pengujian.');
    }

    $reader = new DocxArchiveReader();
    if (!$reader->open($docxPath)) {
        throw new RuntimeException('DOCX tidak dapat dibuka oleh reader.');
    }
    $content = $reader->getFromName('word/document.xml');
    $reader->close();
    if ($content !== $documentXml) {
        throw new RuntimeException('Isi DOCX tidak terbaca dengan benar.');
    }

    $converted = convertDocxToHtml($docxPath);
    if ($converted === null || !str_contains($converted, 'fallback-ok')) {
        throw new RuntimeException('DOCX tidak berhasil dikonversi menjadi HTML.');
    }

    echo "DOCX archive regression test: OK\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
} finally {
    @unlink($zipPath);
    @unlink($docxPath);
}
