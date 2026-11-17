<?php
/**
 * UniPortal – Demo assignment & submission seeder (PART 2)
 *
 * CLI only. Repeatable and non-destructive:
 *   - creates demo assignments/submissions marked with is_demo = 1
 *     and a "[DEMO]" title prefix
 *   - never overwrites real assignments, submissions or files
 *   - skips rows that already exist (matched by section + title,
 *     and by the unique assignment_id + enrollment_id pair)
 *   - only creates submissions for students actually enrolled in
 *     the target class section
 *
 * Run:
 *   php -d extension=gd -d extension=zip database/seed_demo_assignments.php
 * (Extensions are loaded only for this CLI process.)
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once __DIR__ . '/../src/src/config/database.php';
require_once __DIR__ . '/../src/src/services/UploadService.php';

if (!extension_loaded('gd') || !class_exists(ZipArchive::class)) {
    fwrite(STDERR, "Run: php -d extension=gd -d extension=zip database/seed_demo_assignments.php\n");
    exit(1);
}

$root     = dirname(__DIR__);
$asgBase  = $root . '/storage/uploads/assignments';
$subBase  = $root . '/storage/uploads/submissions';
foreach ([$asgBase, $subBase] as $d) {
    if (!is_dir($d) && !mkdir($d, 0755, true)) {
        fwrite(STDERR, "Cannot create $d\n");
        exit(1);
    }
}

$font = 'C:/Windows/Fonts/arial.ttf';
if (!is_file($font)) $font = 'C:/Windows/Fonts/tahoma.ttf';
if (!is_file($font)) {
    fwrite(STDERR, "No TTF font found for rendering.\n");
    exit(1);
}

// ─────────────────────────────────────────────────────────────
// File generators (all produce real, valid files)
// ─────────────────────────────────────────────────────────────

function xml_esc(string $s): string {
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function wrap_text(string $text, int $maxChars): array {
    $out = [];
    foreach (explode("\n", $text) as $line) {
        if ($line === '') { $out[] = ''; continue; }
        $words = preg_split('/\s+/u', trim($line)) ?: [];
        $cur = '';
        foreach ($words as $w) {
            if ($cur === '') { $cur = $w; continue; }
            if (mb_strlen($cur . ' ' . $w) <= $maxChars) $cur .= ' ' . $w;
            else { $out[] = $cur; $cur = $w; }
        }
        if ($cur !== '') $out[] = $cur;
    }
    return $out;
}

/** Renders a document page into a JPEG via GD (handles Vietnamese). */
function render_page_jpeg(string $title, string $subtitle, array $lines, string $font, int $w = 1240, int $h = 1754): string {
    $img = imagecreatetruecolor($w, $h);
    $white = imagecolorallocate($img, 255, 255, 255);
    imagefilledrectangle($img, 0, 0, $w, $h, $white);
    $navy = imagecolorallocate($img, 0, 22, 52);
    $accent = imagecolorallocate($img, 253, 204, 53);
    $gray = imagecolorallocate($img, 71, 85, 105);
    imagefilledrectangle($img, 0, 0, $w, 150, $navy);
    imagefilledrectangle($img, 0, 150, $w, 158, $accent);
    imagettftext($img, 15, 0, 60, 50, $accent, $font, 'UNIPORTAL • DỮ LIỆU MINH HỌA');
    imagettftext($img, 26, 0, 60, 100, $white, $font, $title);
    if ($subtitle !== '') imagettftext($img, 15, 0, 60, 135, $accent, $font, $subtitle);

    $y = 220;
    foreach ($lines as $line) {
        foreach (wrap_text($line, 88) as $wrapped) {
            if ($y > $h - 80) break 2;
            if ($wrapped === '') { $y += 16; continue; }
            imagettftext($img, 14, 0, 60, $y, $gray, $font, $wrapped);
            $y += 30;
        }
    }
    imagettftext($img, 11, 0, 60, $h - 50, $gray, $font, 'Tệp minh họa được tạo tự động – không phải tài liệu chính thức.');

    ob_start();
    imagejpeg($img, null, 88);
    $data = (string)ob_get_clean();
    imagedestroy($img);
    return $data;
}

function gen_jpg(string $path, string $title, string $subtitle, array $lines, string $font): void {
    file_put_contents($path, render_page_jpeg($title, $subtitle, $lines, $font, 1200, 800));
}

function gen_png(string $path, string $title, string $subtitle, array $lines, string $font): void {
    $w = 1000; $h = 700;
    $img = imagecreatetruecolor($w, $h);
    $bg = imagecolorallocate($img, 248, 250, 252);
    imagefilledrectangle($img, 0, 0, $w, $h, $bg);
    $navy = imagecolorallocate($img, 0, 22, 52);
    $blue = imagecolorallocate($img, 37, 99, 235);
    $yellow = imagecolorallocate($img, 253, 204, 53);
    $gray = imagecolorallocate($img, 71, 85, 105);

    imagefilledrectangle($img, 0, 0, $w, 92, $navy);
    imagettftext($img, 21, 0, 36, 56, $yellow, $font, $title);
    if ($subtitle !== '') imagettftext($img, 12, 0, 36, 80, imagecolorallocate($img, 203, 213, 225), $font, $subtitle);

    // Simple process diagram
    $labels = array_slice(array_filter($lines), 0, 4);
    $boxW = 200; $gap = 26; $x = 36; $y = 170;
    foreach ($labels as $i => $lab) {
        $bx = $x + $i * ($boxW + $gap);
        imagefilledrectangle($img, $bx, $y, $bx + $boxW, $y + 92, imagecolorallocate($img, 226, 232, 240));
        imagerectangle($img, $bx, $y, $bx + $boxW, $y + 92, $blue);
        $short = mb_substr($lab, 0, 30);
        imagettftext($img, 11, 0, $bx + 14, $y + 40, $navy, $font, $short);
        imagettftext($img, 10, 0, $bx + 14, $y + 64, $gray, $font, 'Bước ' . ($i + 1));
        if ($i < count($labels) - 1) {
            imageline($img, $bx + $boxW + 4, $y + 46, $bx + $boxW + $gap - 6, $y + 46, $blue);
            imagettftext($img, 14, 0, $bx + $boxW + 8, $y + 52, $blue, $font, '→');
        }
    }

    $y2 = 320;
    foreach ($lines as $line) {
        if ($y2 > $h - 40) break;
        imagettftext($img, 12, 0, 36, $y2, $gray, $font, mb_substr($line, 0, 110));
        $y2 += 28;
    }
    imagettftext($img, 10, 0, 36, $h - 22, $gray, $font, 'Sơ đồ minh họa – UniPortal demo data');

    imagepng($img, $path, 6);
    imagedestroy($img);
}

/** Wraps a JPEG into a valid single-page PDF (A4). */
function gen_pdf(string $path, string $title, string $subtitle, array $lines, string $font): void {
    $jpeg = render_page_jpeg($title, $subtitle, $lines, $font);
    $iw = 1240; $ih = 1754;

    $objects = [];
    $objects[1] = "<< /Type /Catalog /Pages 2 0 R >>";
    $objects[2] = "<< /Type /Pages /Kids [3 0 R] /Count 1 >>";
    $objects[3] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /XObject << /Im1 4 0 R >> >> /Contents 5 0 R >>";
    $objects[4] = "<< /Type /XObject /Subtype /Image /Width $iw /Height $ih /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length " . strlen($jpeg) . " >>\nstream\n" . $jpeg . "\nendstream";
    $content = "q 595 0 0 842 0 0 cm /Im1 Do Q";
    $objects[5] = "<< /Length " . strlen($content) . " >>\nstream\n" . $content . "\nendstream";

    $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
    $offsets = [];
    foreach ($objects as $n => $body) {
        $offsets[$n] = strlen($pdf);
        $pdf .= "$n 0 obj\n$body\nendobj\n";
    }
    $xrefPos = strlen($pdf);
    $count = count($objects) + 1;
    $pdf .= "xref\n0 $count\n0000000000 65535 f \n";
    foreach ($offsets as $n => $off) {
        $pdf .= sprintf("%010d 00000 n \n", $off);
    }
    $pdf .= "trailer\n<< /Size $count /Root 1 0 R >>\nstartxref\n$xrefPos\n%%EOF\n";
    file_put_contents($path, $pdf);
}

function gen_docx(string $path, string $title, array $paragraphs): void {
    $body = '<w:p><w:pPr><w:spacing w:after="160"/></w:pPr><w:r><w:rPr><w:b/><w:sz w:val="36"/><w:color w:val="001634"/></w:rPr><w:t xml:space="preserve">' . xml_esc($title) . '</w:t></w:r></w:p>';
    foreach ($paragraphs as $p) {
        $body .= '<w:p><w:r><w:rPr><w:sz w:val="24"/></w:rPr><w:t xml:space="preserve">' . xml_esc($p) . '</w:t></w:r></w:p>';
    }
    $body .= '<w:p><w:r><w:rPr><w:i/><w:sz w:val="20"/><w:color w:val="64748B"/></w:rPr><w:t xml:space="preserve">Tệp minh họa được tạo tự động (UniPortal demo data).</w:t></w:r></w:p>';

    $document = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
        . $body . '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1440" w:right="1440" w:bottom="1440" w:left="1440"/></w:sectPr>'
        . '</w:body></w:document>';

    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException("zip open $path");
    $zip->addFromString('[Content_Types].xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
        . '</Types>');
    $zip->addFromString('_rels/.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
        . '</Relationships>');
    $zip->addFromString('word/document.xml', $document);
    $zip->close();
}

/** Legacy .doc: RTF (opens in Word, supports Vietnamese via \u escapes). */
function rtf_escape(string $s): string {
    $out = '';
    foreach (preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
        $cp = mb_ord($ch, 'UTF-8');
        if ($cp === 10) { $out .= "\\par\n"; continue; }
        if ($cp < 128) {
            if ($ch === '\\' || $ch === '{' || $ch === '}') $out .= '\\' . $ch;
            else $out .= $ch;
        } else {
            $out .= '\\u' . $cp . '?';
        }
    }
    return $out;
}

function gen_doc(string $path, string $title, array $paragraphs): void {
    $rtf = "{\\rtf1\\ansi\\ansicpg1252\\deff0{\\fonttbl{\\f0\\fswiss Arial;}}\\f0\\fs24\n";
    $rtf .= "\\pard\\qc\\b\\fs36 " . rtf_escape($title) . "\\b0\\fs24\\par\n\\pard\\ql\\par\n";
    foreach ($paragraphs as $p) {
        $rtf .= rtf_escape($p) . "\\par\n";
    }
    $rtf .= "\\par\\i Tep minh hoa duoc tao tu dong (UniPortal demo data).\\i0\\par}";
    file_put_contents($path, $rtf);
}

function gen_xlsx(string $path, array $rows): void {
    $sheetRows = '';
    $rowNo = 0;
    foreach ($rows as $row) {
        $rowNo++;
        $cells = '';
        $col = 0;
        foreach ($row as $value) {
            $ref = chr(65 + $col) . $rowNo;
            $cells .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . xml_esc((string)$value) . '</t></is></c>';
            $col++;
        }
        $sheetRows .= '<row r="' . $rowNo . '">' . $cells . '</row>';
    }
    $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetData>' . $sheetRows . '</sheetData></worksheet>';

    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException("zip open $path");
    $zip->addFromString('[Content_Types].xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '</Types>');
    $zip->addFromString('_rels/.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>');
    $zip->addFromString('xl/workbook.xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="DuLieu" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '</Relationships>');
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
    $zip->close();
}

function gen_pptx(string $path, string $title, array $bullets): void {
    $paras = '<a:p><a:r><a:rPr lang="vi-VN" sz="2800" b="1"><a:solidFill><a:srgbClr val="FDFC35"/></a:solidFill></a:rPr><a:t>' . xml_esc($title) . '</a:t></a:r></a:p>';
    foreach ($bullets as $b) {
        $paras .= '<a:p><a:r><a:rPr lang="vi-VN" sz="1800"><a:solidFill><a:srgbClr val="FFFFFF"/></a:solidFill></a:rPr><a:t>• ' . xml_esc($b) . '</a:t></a:r></a:p>';
    }
    $spTree = '<p:spTree><p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr>'
        . '<p:grpSpPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/><a:chOff x="0" y="0"/><a:chExt cx="0" cy="0"/></a:xfrm></p:grpSpPr>'
        . '<p:sp><p:nvSpPr><p:cNvPr id="2" name="Background"/><p:cNvSpPr/><p:nvPr/></p:nvSpPr>'
        . '<p:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="12192000" cy="6858000"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom>'
        . '<a:solidFill><a:srgbClr val="001634"/></a:solidFill></p:spPr><p:txBody><a:bodyPr/><a:lstStyle/><a:p/></p:txBody></p:sp>'
        . '<p:sp><p:nvSpPr><p:cNvPr id="3" name="Content"/><p:cNvSpPr/><p:nvPr/></p:nvSpPr>'
        . '<p:spPr><a:xfrm><a:off x="609600" y="609600"/><a:ext cx="10972800" cy="5486400"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom><a:noFill/></p:spPr>'
        . '<p:txBody><a:bodyPr wrap="square"/><a:lstStyle/>' . $paras . '</p:txBody></p:sp>'
        . '</p:spTree>';

    $slide = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<p:sld xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main">'
        . '<p:cSld>' . $spTree . '</p:cSld><p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr></p:sld>';

    $master = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<p:sldMaster xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main">'
        . '<p:cSld><p:spTree><p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr>'
        . '<p:grpSpPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/><a:chOff x="0" y="0"/><a:chExt cx="0" cy="0"/></a:xfrm></p:grpSpPr></p:spTree></p:cSld>'
        . '<p:clrMap bg1="lt1" tx1="dk1" bg2="lt2" tx2="dk2" accent1="accent1" accent2="accent2" accent3="accent3" accent4="accent4" accent5="accent5" accent6="accent6" hlink="hlink" folHlink="folHlink"/>'
        . '<p:sldLayoutIdLst><p:sldLayoutId id="2147483649" r:id="rId1"/></p:sldLayoutIdLst>'
        . '<p:txStyles><p:titleStyle/><p:bodyStyle/><p:otherStyle/></p:txStyles></p:sldMaster>';

    $layout = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<p:sldLayout xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" type="blank" preserve="1">'
        . '<p:cSld name="Blank"><p:spTree><p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr>'
        . '<p:grpSpPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/><a:chOff x="0" y="0"/><a:chExt cx="0" cy="0"/></a:xfrm></p:grpSpPr></p:spTree></p:cSld>'
        . '<p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr></p:sldLayout>';

    $pres = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<p:presentation xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main">'
        . '<p:sldMasterIdLst><p:sldMasterId id="2147483648" r:id="rId1"/></p:sldMasterIdLst>'
        . '<p:sldIdLst><p:sldId id="256" r:id="rId2"/></p:sldIdLst>'
        . '<p:sldSz cx="12192000" cy="6858000"/><p:notesSz cx="6858000" cy="9144000"/></p:presentation>';

    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException("zip open $path");
    $zip->addFromString('[Content_Types].xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/ppt/presentation.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml"/>'
        . '<Override PartName="/ppt/slides/slide1.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slide+xml"/>'
        . '<Override PartName="/ppt/slideMasters/slideMaster1.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slideMaster+xml"/>'
        . '<Override PartName="/ppt/slideLayouts/slideLayout1.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slideLayout+xml"/>'
        . '</Types>');
    $zip->addFromString('_rels/.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="ppt/presentation.xml"/>'
        . '</Relationships>');
    $zip->addFromString('ppt/presentation.xml', $pres);
    $zip->addFromString('ppt/_rels/presentation.xml.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideMaster" Target="slideMasters/slideMaster1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide" Target="slides/slide1.xml"/>'
        . '</Relationships>');
    $zip->addFromString('ppt/slides/slide1.xml', $slide);
    $zip->addFromString('ppt/slides/_rels/slide1.xml.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideLayout" Target="../slideLayouts/slideLayout1.xml"/>'
        . '</Relationships>');
    $zip->addFromString('ppt/slideMasters/slideMaster1.xml', $master);
    $zip->addFromString('ppt/slideMasters/_rels/slideMaster1.xml.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideLayout" Target="../slideLayouts/slideLayout1.xml"/>'
        . '</Relationships>');
    $zip->addFromString('ppt/slideLayouts/slideLayout1.xml', $layout);
    $zip->addFromString('ppt/slideLayouts/_rels/slideLayout1.xml.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideMaster" Target="../slideMasters/slideMaster1.xml"/>'
        . '</Relationships>');
    $zip->close();
}

function make_file(string $path, string $ext, string $title, string $subtitle, array $lines, string $font): void {
    switch ($ext) {
        case 'pdf':  gen_pdf($path, $title, $subtitle, $lines, $font); break;
        case 'docx': gen_docx($path, $title, $lines); break;
        case 'doc':  gen_doc($path, $title, $lines); break;
        case 'xlsx':
            $rows = [['Hạng mục', 'Mô tả', 'Điểm tối đa', 'Ghi chú']];
            foreach ($lines as $i => $l) $rows[] = ['Mục ' . ($i + 1), $l, (string)(10 - $i), 'Dữ liệu minh họa'];
            gen_xlsx($path, $rows);
            break;
        case 'pptx': gen_pptx($path, $title, $lines); break;
        case 'png':  gen_png($path, $title, $subtitle, $lines, $font); break;
        case 'jpg':
        case 'jpeg': gen_jpg($path, $title, $subtitle, $lines, $font); break;
        default: throw new RuntimeException("Unsupported demo extension: $ext");
    }
}

function file_mime(string $path, string $ext): string {
    return UploadService::mime($path, $ext);
}

// ─────────────────────────────────────────────────────────────
// Demo plan
// ─────────────────────────────────────────────────────────────
$DEMO_NOTE = 'DỮ LIỆU MINH HỌA – được tạo tự động bởi database/seed_demo_assignments.php. Không phải bài tập chính thức.';

$plan = [
    'INS3056-01' => [
        [
            'key' => 'ins-a1', 'type' => 'Báo cáo cá nhân',
            'title' => '[DEMO] Báo cáo cá nhân – Phân tích quy trình nghiệp vụ',
            'desc' => 'Phân tích một quy trình nghiệp vụ thực tế của doanh nghiệp (mua hàng, bán hàng hoặc kho vận), chỉ rõ tác nhân, dữ liệu vào/ra và điểm nghẽn của quy trình hiện tại.',
            'instructions' => "1. Chọn một quy trình nghiệp vụ và mô tả bối cảnh doanh nghiệp.\n2. Vẽ sơ đồ BPMN hoặc flowchart cho quy trình hiện tại (AS-IS).\n3. Phân tích ít nhất 03 điểm nghẽn và đề xuất quy trình TO-BE.\n4. Trình bày tối đa 10 trang A4, định dạng PDF.\n5. Ghi rõ nguồn tham khảo nếu có.",
            'pub' => '2026-09-14 08:00:00', 'due' => '2026-09-28 23:59:00', 'max' => 10.00,
            'attachments' => [['pdf', 'Huong-dan-bai-tap-ca-nhan.pdf'], ['png', 'So-do-quy-trinh-mau.png']],
            'sub_ext' => 'pdf',
            'counts' => ['published' => 3, 'draft' => 1, 'submitted' => 2, 'late' => 2, 'draft_saved' => 1],
        ],
        [
            'key' => 'ins-a2', 'type' => 'Bài tập nhóm',
            'title' => '[DEMO] Bài tập nhóm – Thiết kế mô hình dữ liệu ERD',
            'desc' => 'Thiết kế mô hình dữ liệu quan hệ cho hệ thống thông tin quản lý đặt hàng, đảm bảo chuẩn hóa 3NF và mô tả ràng buộc toàn vẹn.',
            'instructions' => "1. Lập danh sách thực thể và thuộc tính cho hệ thống.\n2. Vẽ ERD đầy đủ quan hệ, khóa chính, khóa ngoại.\n3. Trình bày bảng mô tả chi tiết và giải thích chuẩn hóa.\n4. Nộp kèm file DOCX báo cáo và XLSX danh mục thực thể.",
            'pub' => '2026-10-05 08:00:00', 'due' => '2026-10-19 23:59:00', 'max' => 10.00,
            'attachments' => [['docx', 'De-bai-ERD-nhom.docx'], ['xlsx', 'Mau-danh-muc-thuc-the.xlsx']],
            'sub_ext' => 'docx',
            'counts' => ['published' => 3, 'draft' => 1, 'submitted' => 2, 'late' => 2, 'draft_saved' => 1],
        ],
        [
            'key' => 'ins-a3', 'type' => 'Case study',
            'title' => '[DEMO] Case study – Đánh giá kiến trúc hệ thống thông tin',
            'desc' => 'Đánh giá kiến trúc của một hệ thống thông tin doanh nghiệp được cung cấp trong case study, nhận diện rủi ro và đề xuất phương án cải tiến.',
            'instructions' => "1. Đọc kỹ case study đính kèm.\n2. Phân tích kiến trúc logic, vật lý và các thành phần tích hợp.\n3. Đánh giá rủi ro bảo mật, hiệu năng và khả năng mở rộng.\n4. Chuẩn bị slide thuyết trình 8-10 slide (PPTX) kèm báo cáo PDF.",
            'pub' => '2026-10-19 08:00:00', 'due' => '2026-11-02 23:59:00', 'max' => 10.00,
            'attachments' => [['pdf', 'Case-study-kien-truc.pdf'], ['pptx', 'Khung-thuyet-trinh.pptx']],
            'sub_ext' => 'pdf',
            'counts' => ['published' => 3, 'draft' => 1, 'submitted' => 2, 'late' => 2, 'draft_saved' => 1],
        ],
        [
            'key' => 'ins-a4', 'type' => 'Tiểu luận',
            'title' => '[DEMO] Tiểu luận – Tác động của ERP đến hiệu quả doanh nghiệp',
            'desc' => 'Viết tiểu luận về tác động của hệ thống ERP đến hiệu quả vận hành và quản trị của doanh nghiệp, có dẫn chứng từ tài liệu và số liệu thực tế.',
            'instructions' => "1. Đặt vấn đề và mục tiêu nghiên cứu.\n2. Tổng quan tài liệu về ERP và hiệu quả doanh nghiệp.\n3. Phân tích 2-3 trường hợp triển khai điển hình.\n4. Kết luận và khuyến nghị, dài 8-12 trang DOCX.\n5. Trích dẫn theo APA, tối thiểu 05 nguồn.",
            'pub' => '2026-11-02 08:00:00', 'due' => '2026-11-23 23:59:00', 'max' => 10.00,
            'attachments' => [['pdf', 'Huong-dan-viet-tieu-luan.pdf'], ['docx', 'Mau-trinh-bay-tieu-luan.docx']],
            'sub_ext' => 'docx',
            'counts' => ['published' => 0, 'draft' => 0, 'submitted' => 4, 'late' => 0, 'draft_saved' => 2],
        ],
        [
            'key' => 'ins-a5', 'type' => 'Bài kiểm tra giữa kỳ',
            'title' => '[DEMO] Bài kiểm tra giữa kỳ – Quy trình nghiệp vụ và hệ thống thông tin',
            'desc' => 'Bài kiểm tra giữa kỳ dạng bài luận ngắn, đánh giá hiểu biết về quy trình nghiệp vụ, mô hình dữ liệu và kiến trúc hệ thống thông tin doanh nghiệp.',
            'instructions' => "1. Làm bài độc lập, thời gian 90 phút.\n2. Trả lời 04 câu hỏi phân tích tình huống.\n3. Trình bày rõ ràng, có sơ đồ minh họa khi cần.\n4. Nộp bản PDF đúng hạn, không quá 6 trang.",
            'pub' => '2026-11-09 08:00:00', 'due' => '2026-12-07 23:59:00', 'max' => 20.00,
            'attachments' => [['pdf', 'De-bai-kiem-tra-giua-ky.pdf']],
            'sub_ext' => 'pdf',
            'counts' => ['published' => 0, 'draft' => 0, 'submitted' => 4, 'late' => 0, 'draft_saved' => 2],
        ],
        [
            'key' => 'ins-a6', 'type' => 'Bài tập nghiên cứu',
            'title' => '[DEMO] Bài tập nghiên cứu – Xu hướng hệ thống thông tin doanh nghiệp 2026',
            'desc' => 'Nghiên cứu và tổng hợp các xu hướng công nghệ nổi bật trong hệ thống thông tin doanh nghiệp năm 2026, đánh giá tác động đến doanh nghiệp Việt Nam.',
            'instructions' => "1. Chọn 03 xu hướng tiêu biểu (AI, điện toán đám mây, dữ liệu lớn…).\n2. Thu thập tài liệu và số liệu từ nguồn tin cậy.\n3. Phân tích cơ hội, thách thức cho doanh nghiệp Việt Nam.\n4. Trình bày báo cáo PDF 10-12 trang kèm infographic.",
            'pub' => '2026-11-16 08:00:00', 'due' => '2026-12-21 23:59:00', 'max' => 10.00,
            'attachments' => [['pdf', 'Huong-dan-nghien-cuu.pdf'], ['png', 'Mau-infographic.png']],
            'sub_ext' => 'pdf',
            'counts' => ['published' => 0, 'draft' => 0, 'submitted' => 4, 'late' => 0, 'draft_saved' => 2],
        ],
    ],
    'MIS2101-01' => [
        [
            'key' => 'mis-a1', 'type' => 'Báo cáo cá nhân',
            'title' => '[DEMO] Báo cáo cá nhân – Phân tích hệ thống ERP',
            'desc' => 'Tìm hiểu một giải pháp ERP phổ biến (SAP, Oracle hoặc Odoo) và phân tích mức độ phù hợp với doanh nghiệp vừa và nhỏ tại Việt Nam.',
            'instructions' => "1. Giới thiệu giải pháp và kiến trúc tổng quan.\n2. Phân tích các phân hệ chính: tài chính, nhân sự, chuỗi cung ứng.\n3. So sánh ưu/nhược điểm và chi phí triển khai.\n4. Nêu khuyến nghị cho doanh nghiệp SME.",
            'pub' => '2026-09-14 08:00:00', 'due' => '2026-09-28 23:59:00', 'max' => 10.00,
            'attachments' => [['docx', 'Yeu-cau-bao-cao-ERP.docx']],
            'sub_ext' => 'docx',
            'counts' => ['published' => 3, 'draft' => 1, 'submitted' => 2, 'late' => 2, 'draft_saved' => 1],
        ],
        [
            'key' => 'mis-a2', 'type' => 'Dự án nhóm',
            'title' => '[DEMO] Dự án nhóm – Dashboard quản trị vận hành',
            'desc' => 'Xây dựng bộ chỉ số KPI và dashboard quản trị vận hành cho một phòng ban, kèm phân tích dữ liệu mẫu.',
            'instructions' => "1. Xác định 5-7 KPI cốt lõi và công thức tính.\n2. Chuẩn bị dữ liệu mẫu trong file XLSX.\n3. Thiết kế dashboard (mockup PNG) và mô tả insight.\n4. Chuẩn bị thuyết trình 10 phút.",
            'pub' => '2026-10-05 08:00:00', 'due' => '2026-10-19 23:59:00', 'max' => 10.00,
            'attachments' => [['xlsx', 'Du-lieu-KPI-mau.xlsx'], ['png', 'Mockup-dashboard.png'], ['pdf', 'Tieu-chi-danh-gia.pdf']],
            'sub_ext' => 'xlsx',
            'counts' => ['published' => 3, 'draft' => 1, 'submitted' => 2, 'late' => 2, 'draft_saved' => 1],
        ],
        [
            'key' => 'mis-a3', 'type' => 'Thuyết trình',
            'title' => '[DEMO] Thuyết trình – Chiến lược chuyển đổi số',
            'desc' => 'Đề xuất chiến lược chuyển đổi số 3 năm cho một doanh nghiệp bán lẻ, bao gồm lộ trình và chỉ số đo lường.',
            'instructions' => "1. Phân tích hiện trạng và mục tiêu chuyển đổi số.\n2. Đề xuất lộ trình 3 giai đoạn với hạng mục ưu tiên.\n3. Trình bày slide PPTX tối đa 12 slide.\n4. Nộp kèm báo cáo PDF mô tả chi tiết.",
            'pub' => '2026-10-19 08:00:00', 'due' => '2026-11-02 23:59:00', 'max' => 10.00,
            'attachments' => [['pptx', 'Khung-thuyet-trinh-chuyen-doi-so.pptx'], ['pdf', 'Rubric-cham-thuyet-trinh.pdf']],
            'sub_ext' => 'pptx',
            'counts' => ['published' => 3, 'draft' => 1, 'submitted' => 2, 'late' => 2, 'draft_saved' => 1],
        ],
        [
            'key' => 'mis-a4', 'type' => 'Tiểu luận',
            'title' => '[DEMO] Tiểu luận – Vai trò dữ liệu trong ra quyết định quản trị',
            'desc' => 'Phân tích vai trò của dữ liệu và hệ thống thông tin trong quá trình ra quyết định quản trị, liên hệ thực tiễn tại một doanh nghiệp cụ thể.',
            'instructions' => "1. Trình bày khung lý thuyết về dữ liệu và ra quyết định.\n2. Phân tích ví dụ doanh nghiệp sử dụng BI/dashboard.\n3. Thảo luận thách thức về chất lượng dữ liệu.\n4. Viết 8-12 trang DOCX, trích dẫn tối thiểu 05 nguồn.",
            'pub' => '2026-11-02 08:00:00', 'due' => '2026-11-23 23:59:00', 'max' => 10.00,
            'attachments' => [['pdf', 'De-cuong-tieu-luan.pdf']],
            'sub_ext' => 'docx',
            'counts' => ['published' => 0, 'draft' => 0, 'submitted' => 4, 'late' => 0, 'draft_saved' => 2],
        ],
        [
            'key' => 'mis-a5', 'type' => 'Bài kiểm tra giữa kỳ',
            'title' => '[DEMO] Bài kiểm tra giữa kỳ – Hệ thống thông tin quản lý',
            'desc' => 'Kiểm tra giữa kỳ tổng hợp kiến thức về hệ thống thông tin quản lý, quy trình nghiệp vụ và ứng dụng công nghệ trong doanh nghiệp.',
            'instructions' => "1. Làm bài độc lập trong 90 phút.\n2. Trả lời 04 câu hỏi tình huống.\n3. Có thể vẽ sơ đồ minh họa.\n4. Nộp PDF tối đa 6 trang.",
            'pub' => '2026-11-09 08:00:00', 'due' => '2026-12-07 23:59:00', 'max' => 20.00,
            'attachments' => [['pdf', 'De-bai-giua-ky-MIS.pdf']],
            'sub_ext' => 'pdf',
            'counts' => ['published' => 0, 'draft' => 0, 'submitted' => 4, 'late' => 0, 'draft_saved' => 2],
        ],
        [
            'key' => 'mis-a6', 'type' => 'Bài tập nghiên cứu',
            'title' => '[DEMO] Bài tập nghiên cứu – Ứng dụng AI trong quản trị doanh nghiệp',
            'desc' => 'Nghiên cứu ứng dụng trí tuệ nhân tạo trong quản trị doanh nghiệp: các use case phổ biến, lợi ích, rủi ro và điều kiện triển khai.',
            'instructions' => "1. Tổng quan các nhóm ứng dụng AI trong quản trị.\n2. Phân tích 02 case study doanh nghiệp.\n3. Đánh giá rủi ro đạo đức và dữ liệu.\n4. Trình bày báo cáo PDF 10-12 trang.",
            'pub' => '2026-11-16 08:00:00', 'due' => '2026-12-21 23:59:00', 'max' => 10.00,
            'attachments' => [['pdf', 'Huong-dan-nghien-cuu-AI.pdf'], ['docx', 'Mau-bao-cao-nghien-cuu.docx']],
            'sub_ext' => 'pdf',
            'counts' => ['published' => 0, 'draft' => 0, 'submitted' => 4, 'late' => 0, 'draft_saved' => 2],
        ],
    ],
    'MIS2101-02' => [
        [
            'key' => 'mis2-a1', 'type' => 'Báo cáo cá nhân',
            'title' => '[DEMO] Báo cáo cá nhân – Khảo sát hệ thống thông tin doanh nghiệp',
            'desc' => 'Khảo sát một doanh nghiệp cụ thể và mô tả hệ thống thông tin đang sử dụng: các phân hệ, luồng dữ liệu và điểm hạn chế.',
            'instructions' => "1. Giới thiệu doanh nghiệp và phạm vi khảo sát.\n2. Mô tả các hệ thống/phân hệ hiện có.\n3. Chỉ ra 03 hạn chế và đề xuất cải tiến.\n4. Trình bày PDF tối đa 8 trang.",
            'pub' => '2026-09-14 08:00:00', 'due' => '2026-09-28 23:59:00', 'max' => 10.00,
            'attachments' => [['pdf', 'Huong-dan-khao-sat.pdf'], ['docx', 'Mau-bao-cao-khao-sat.docx']],
            'sub_ext' => 'pdf',
            'counts' => ['published' => 3, 'draft' => 1, 'submitted' => 2, 'late' => 2, 'draft_saved' => 1],
        ],
        [
            'key' => 'mis2-a2', 'type' => 'Bài tập nhóm',
            'title' => '[DEMO] Bài tập nhóm – Mô hình hóa quy trình nghiệp vụ BPMN',
            'desc' => 'Mô hình hóa quy trình xử lý đơn hàng của một sàn thương mại điện tử bằng BPMN và phân tích hiệu quả quy trình.',
            'instructions' => "1. Mô tả quy trình từ đặt hàng đến giao hàng.\n2. Vẽ sơ đồ BPMN cho quy trình hiện tại.\n3. Đề xuất cải tiến và ước lượng thời gian tiết kiệm.\n4. Nộp ảnh sơ đồ PNG và báo cáo PDF.",
            'pub' => '2026-10-05 08:00:00', 'due' => '2026-10-19 23:59:00', 'max' => 10.00,
            'attachments' => [['pdf', 'Huong-dan-BPMN.pdf'], ['png', 'Mau-so-do-BPMN.png']],
            'sub_ext' => 'png',
            'counts' => ['published' => 3, 'draft' => 1, 'submitted' => 2, 'late' => 2, 'draft_saved' => 1],
        ],
        [
            'key' => 'mis2-a3', 'type' => 'Case study',
            'title' => '[DEMO] Case study – Chuyển đổi số ngành bán lẻ',
            'desc' => 'Phân tích case study chuyển đổi số của một chuỗi bán lẻ: chiến lược, công nghệ, thay đổi quy trình và kết quả kinh doanh.',
            'instructions' => "1. Đọc case study đính kèm.\n2. Phân tích động lực và lộ trình chuyển đổi.\n3. Đánh giá kết quả và bài học kinh nghiệm.\n4. Trình bày DOCX 8-10 trang.",
            'pub' => '2026-10-19 08:00:00', 'due' => '2026-11-02 23:59:00', 'max' => 10.00,
            'attachments' => [['docx', 'Case-study-ban-le.docx'], ['jpg', 'So-do-kien-truc.jpg']],
            'sub_ext' => 'docx',
            'counts' => ['published' => 3, 'draft' => 1, 'submitted' => 2, 'late' => 2, 'draft_saved' => 1],
        ],
        [
            'key' => 'mis2-a4', 'type' => 'Tiểu luận',
            'title' => '[DEMO] Tiểu luận – Quản trị dữ liệu và chất lượng dữ liệu',
            'desc' => 'Phân tích các nguyên tắc quản trị dữ liệu và ảnh hưởng của chất lượng dữ liệu đến hiệu quả hệ thống thông tin doanh nghiệp.',
            'instructions' => "1. Trình bày khái niệm quản trị dữ liệu.\n2. Phân tích các chiều chất lượng dữ liệu.\n3. Liên hệ một tình huống thực tế.\n4. Viết 8-12 trang, định dạng DOC, trích dẫn nguồn.",
            'pub' => '2026-11-02 08:00:00', 'due' => '2026-11-23 23:59:00', 'max' => 10.00,
            'attachments' => [['pdf', 'De-cuong-tieu-luan-du-lieu.pdf']],
            'sub_ext' => 'doc',
            'counts' => ['published' => 0, 'draft' => 0, 'submitted' => 4, 'late' => 0, 'draft_saved' => 2],
        ],
        [
            'key' => 'mis2-a5', 'type' => 'Bài kiểm tra giữa kỳ',
            'title' => '[DEMO] Bài kiểm tra giữa kỳ – Phân tích thiết kế hệ thống',
            'desc' => 'Bài kiểm tra giữa kỳ về phân tích yêu cầu, mô hình hóa chức năng và thiết kế cơ sở dữ liệu cho một hệ thống thông tin.',
            'instructions' => "1. Làm bài độc lập 90 phút.\n2. Phân tích yêu cầu và vẽ use case.\n3. Thiết kế ERD và mô tả bảng dữ liệu.\n4. Nộp PDF tối đa 8 trang.",
            'pub' => '2026-11-09 08:00:00', 'due' => '2026-12-07 23:59:00', 'max' => 20.00,
            'attachments' => [['pdf', 'De-bai-giua-ky-phan-tich.pdf']],
            'sub_ext' => 'pdf',
            'counts' => ['published' => 0, 'draft' => 0, 'submitted' => 4, 'late' => 0, 'draft_saved' => 2],
        ],
        [
            'key' => 'mis2-a6', 'type' => 'Thực hành',
            'title' => '[DEMO] Thực hành – Truy vấn dữ liệu quản trị',
            'desc' => 'Thực hành viết truy vấn SQL để trích xuất, tổng hợp và phân tích dữ liệu phục vụ báo cáo quản trị.',
            'instructions' => "1. Cài đặt và làm quen cơ sở dữ liệu mẫu.\n2. Viết 10 truy vấn theo yêu cầu.\n3. Tổng hợp kết quả vào file XLSX.\n4. Nộp kèm mô tả cách thực hiện.",
            'pub' => '2026-11-16 08:00:00', 'due' => '2026-12-21 23:59:00', 'max' => 20.00,
            'attachments' => [['xlsx', 'Du-lieu-thuc-hanh.xlsx'], ['pdf', 'De-bai-truy-van.pdf']],
            'sub_ext' => 'xlsx',
            'counts' => ['published' => 0, 'draft' => 0, 'submitted' => 4, 'late' => 0, 'draft_saved' => 2],
        ],
    ],
    'AIT2101-01' => [
        [
            'key' => 'ait-a1', 'type' => 'Thực hành',
            'title' => '[DEMO] Thực hành – Xây dựng trang đăng nhập an toàn',
            'desc' => 'Xây dựng trang đăng nhập có kiểm tra dữ liệu phía client và phía server, hiển thị thông báo lỗi thân thiện.',
            'instructions' => "1. Tạo form đăng nhập với HTML/CSS responsive.\n2. Kiểm tra dữ liệu bằng JavaScript và PHP.\n3. Xử lý CSRF và chống tấn công brute-force cơ bản.\n4. Chụp màn hình kết quả (PNG) và nộp kèm mô tả trong PDF.",
            'pub' => '2026-09-14 08:00:00', 'due' => '2026-09-28 23:59:00', 'max' => 10.00,
            'attachments' => [['pdf', 'De-bai-thuc-hanh.pdf'], ['png', 'Mau-giao-dien.png']],
            'sub_ext' => 'pdf',
            'counts' => ['published' => 3, 'draft' => 1, 'submitted' => 2, 'late' => 2, 'draft_saved' => 1],
        ],
        [
            'key' => 'ait-a2', 'type' => 'Báo cáo cá nhân',
            'title' => '[DEMO] Báo cáo cá nhân – Thiết kế REST API',
            'desc' => 'Thiết kế đặc tả REST API cho hệ thống quản lý sinh viên, bao gồm xác thực, phân quyền và xử lý lỗi.',
            'instructions' => "1. Liệt kê tài nguyên và endpoint (CRUD).\n2. Mô tả request/response mẫu dạng JSON.\n3. Quy định mã lỗi và quy ước đặt tên.\n4. Trình bày trong DOCX, kèm sơ đồ luồng xác thực.",
            'pub' => '2026-10-05 08:00:00', 'due' => '2026-10-19 23:59:00', 'max' => 10.00,
            'attachments' => [['docx', 'Dac-ta-API-mau.docx'], ['doc', 'Mau-bao-cao-API.doc']],
            'sub_ext' => 'docx',
            'counts' => ['published' => 3, 'draft' => 1, 'submitted' => 2, 'late' => 2, 'draft_saved' => 1],
        ],
        [
            'key' => 'ait-a3', 'type' => 'Bài tập nhóm',
            'title' => '[DEMO] Bài tập nhóm – Ứng dụng CRUD hoàn chỉnh',
            'desc' => 'Xây dựng ứng dụng CRUD quản lý công việc nhóm với giao diện hiện đại và cơ sở dữ liệu quan hệ.',
            'instructions' => "1. Thiết kế CSDL và API cho ứng dụng.\n2. Xây dựng giao diện danh sách, thêm, sửa, xóa.\n3. Xử lý xác thực và phân quyền người dùng.\n4. Nộp báo cáo PDF kèm ảnh chụp màn hình.",
            'pub' => '2026-10-19 08:00:00', 'due' => '2026-11-02 23:59:00', 'max' => 10.00,
            'attachments' => [['pdf', 'Yeu-cau-ung-dung-CRUD.pdf'], ['jpg', 'Wireframe-tham-khao.jpg']],
            'sub_ext' => 'pdf',
            'counts' => ['published' => 3, 'draft' => 1, 'submitted' => 2, 'late' => 2, 'draft_saved' => 1],
        ],
        [
            'key' => 'ait-a4', 'type' => 'Tiểu luận',
            'title' => '[DEMO] Tiểu luận – Bảo mật ứng dụng web hiện đại',
            'desc' => 'Phân tích các rủi ro bảo mật phổ biến của ứng dụng web và biện pháp phòng chống theo OWASP Top 10.',
            'instructions' => "1. Trình bày 05 rủi ro OWASP tiêu biểu.\n2. Phân tích ví dụ tấn công và hậu quả.\n3. Đề xuất biện pháp phòng chống cho từng rủi ro.\n4. Viết 8-12 trang DOCX, trích dẫn nguồn.",
            'pub' => '2026-11-02 08:00:00', 'due' => '2026-11-23 23:59:00', 'max' => 10.00,
            'attachments' => [['pdf', 'De-cuong-tieu-luan-bao-mat.pdf']],
            'sub_ext' => 'docx',
            'counts' => ['published' => 0, 'draft' => 0, 'submitted' => 4, 'late' => 0, 'draft_saved' => 2],
        ],
        [
            'key' => 'ait-a5', 'type' => 'Bài kiểm tra giữa kỳ',
            'title' => '[DEMO] Bài kiểm tra giữa kỳ – Phát triển ứng dụng web',
            'desc' => 'Kiểm tra giữa kỳ về HTML/CSS/JavaScript, xử lý form, phiên đăng nhập và tương tác cơ sở dữ liệu.',
            'instructions' => "1. Làm bài độc lập 90 phút.\n2. Trả lời 04 câu hỏi lý thuyết và bài tập.\n3. Viết mã minh họa khi được yêu cầu.\n4. Nộp PDF tối đa 6 trang.",
            'pub' => '2026-11-09 08:00:00', 'due' => '2026-12-07 23:59:00', 'max' => 20.00,
            'attachments' => [['pdf', 'De-bai-giua-ky-web.pdf']],
            'sub_ext' => 'pdf',
            'counts' => ['published' => 0, 'draft' => 0, 'submitted' => 4, 'late' => 0, 'draft_saved' => 2],
        ],
        [
            'key' => 'ait-a6', 'type' => 'Bài tập nghiên cứu',
            'title' => '[DEMO] Bài tập nghiên cứu – Hiệu năng và khả năng mở rộng ứng dụng web',
            'desc' => 'Nghiên cứu các kỹ thuật tối ưu hiệu năng và mở rộng ứng dụng web: caching, CDN, tối ưu truy vấn và kiến trúc triển khai.',
            'instructions' => "1. Trình bày các yếu tố ảnh hưởng hiệu năng.\n2. Phân tích 03 kỹ thuật tối ưu phổ biến.\n3. Đề xuất kiến trúc triển khai cho ứng dụng nhiều người dùng.\n4. Nộp báo cáo PDF kèm sơ đồ (JPG).",
            'pub' => '2026-11-16 08:00:00', 'due' => '2026-12-21 23:59:00', 'max' => 10.00,
            'attachments' => [['pdf', 'Huong-dan-nghien-cuu-hieu-nang.pdf'], ['jpg', 'Mau-so-do-trien-khai.jpg']],
            'sub_ext' => 'jpg',
            'counts' => ['published' => 0, 'draft' => 0, 'submitted' => 4, 'late' => 0, 'draft_saved' => 2],
        ],
    ],
];
$feedbackPool = [
    'Bài làm trình bày rõ ràng, phân tích đúng trọng tâm. Cần bổ sung ví dụ minh họa cụ thể hơn.',
    'Nội dung tốt, lập luận mạch lạc. Chú ý định dạng tài liệu và trích dẫn nguồn.',
    'Phân tích khá đầy đủ, sơ đồ dễ hiểu. Một số nhận định chưa có số liệu chứng minh.',
    'Bài làm đáp ứng yêu cầu, cấu trúc hợp lý. Cải thiện phần kết luận và khuyến nghị.',
    'Thể hiện sự hiểu biết tốt về quy trình. Cần rút gọn phần mô tả và tăng phân tích.',
    'Sản phẩm hoàn chỉnh, có tính ứng dụng. Trình bày đẹp, đúng hạn.',
];

$pdo = get_db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
if ((int)$pdo->query("SELECT GET_LOCK('uniportal_seed_demo_assignments', 0)")->fetchColumn() !== 1) {
    fwrite(STDERR, "Another demo seed is already running.\n");
    exit(1);
}

$stats = [
    'assignments_created' => 0, 'assignments_skipped' => 0,
    'submissions_created' => 0, 'submissions_skipped' => 0, 'drafts_created' => 0,
    'files_created' => 0, 'submission_files_created' => 0, 'attachments_added' => 0,
    'errors' => [],
];

function demo_rel_name(string $kind, string $key, string $ext, string $orig): string {
    return 'demo_' . substr(sha1($kind . '|' . $key . '|' . $orig), 0, 16) . '.' . $ext;
}

foreach ($plan as $sectionCode => $assignments) {
    $sec = $pdo->prepare(
        "SELECT cs.section_id, cs.section_code, cs.lecturer_id, c.course_code, c.course_name,
                acc.account_id AS lecturer_account
         FROM ClassSection cs
         JOIN Course c ON c.course_id = cs.course_id
         JOIN Semester sem ON sem.semester_id = cs.semester_id AND sem.status = 'Active'
         JOIN Account acc ON acc.lecturer_id = cs.lecturer_id AND acc.role = 'Lecturer'
         WHERE cs.section_code = :code
           AND EXISTS (SELECT 1 FROM Enrollment e WHERE e.section_id = cs.section_id AND e.status = 'Enrolled')
         ORDER BY cs.section_id LIMIT 1"
    );
    $sec->execute([':code' => $sectionCode]);
    $section = $sec->fetch();
    if (!$section) {
        $stats['errors'][] = "No enrolled active section for $sectionCode";
        continue;
    }
    $sectionId = (int)$section['section_id'];
    $students = $pdo->prepare(
        'SELECT e.enrollment_id, e.student_id, s.full_name
         FROM Enrollment e JOIN Student s ON s.student_id = e.student_id
         WHERE e.section_id = :sid AND e.status = \'Enrolled\'
         ORDER BY e.student_id'
    );
    $students->execute([':sid' => $sectionId]);
    $roster = $students->fetchAll();
    if (!$roster) {
        $stats['errors'][] = "Section $sectionId has no enrolled students";
        continue;
    }

    $ai = 0;
    foreach ($assignments as $a) {
        $ai++;
        $existing = $pdo->prepare('SELECT assignment_id, is_demo, is_deleted, description, publication_date, due_date FROM Assignment WHERE section_id = :sid AND title = :t LIMIT 1');
        $existing->execute([':sid' => $sectionId, ':t' => $a['title']]);
        $existingRow = $existing->fetch();
        if ($existingRow && (!(bool)$existingRow['is_demo'] || (bool)$existingRow['is_deleted']
            || !str_contains((string)$existingRow['description'], $DEMO_NOTE))) {
            $stats['assignments_skipped']++;
            continue;
        }
        $existingId = (int)($existingRow['assignment_id'] ?? 0);
        if ($existingRow) {
            $a['pub'] = $existingRow['publication_date'];
            $a['due'] = $existingRow['due_date'];
        }

        $attachmentMeta = [];
        $firstMeta = null;

        // Always materialise attachments; when the assignment already exists
        // they are reused and any newly planned file is added without
        // touching existing rows.
        $dir = $asgBase . '/' . $sectionId;
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) throw new RuntimeException("mkdir $dir");

        foreach ($a['attachments'] as [$ext, $orig]) {
            $file = demo_rel_name('asg', $a['key'], $ext, $orig);
            $path = $dir . '/' . $file;
            if (!is_file($path)) {
                make_file($path, $ext, $a['title'], $section['course_code'] . ' • ' . $section['section_code'],
                    wrap_text($a['instructions'], 70), $font);
                $stats['files_created']++;
            }
            $meta = [
                'path' => 'assignments/' . $sectionId . '/' . $file,
                'name' => $orig,
                'size' => filesize($path),
                'mime' => file_mime($path, $ext),
            ];
            $attachmentMeta[] = $meta;
            if ($firstMeta === null) $firstMeta = $meta;
        }

        if ($existingId === 0) {
            $pdo->beginTransaction();
            try {
                $pdo->prepare(
                    'INSERT INTO Assignment
                        (section_id, title, description, instructions, publication_date,
                         attachment_path, attachment_name, attachment_size, attachment_mime,
                         due_date, max_score, status, is_deleted, is_demo, created_by, created_at)
                     VALUES
                        (:sid, :title, :desc, :inst, :pub,
                         :ap, :an, :asz, :am, :due, :max, \'Published\', 0, 1, :by, :created)'
                )->execute([
                    ':sid' => $sectionId,
                    ':title' => $a['title'],
                    ':desc' => $a['desc'] . "\n\n" . $DEMO_NOTE,
                    ':inst' => $a['instructions'],
                    ':pub' => $a['pub'],
                    ':ap' => $firstMeta['path'] ?? null,
                    ':an' => $firstMeta['name'] ?? null,
                    ':asz' => $firstMeta['size'] ?? null,
                    ':am' => $firstMeta['mime'] ?? null,
                    ':due' => $a['due'],
                    ':max' => $a['max'],
                    ':by' => $section['lecturer_account'] ?: null,
                    ':created' => date('Y-m-d H:i:s', strtotime($a['pub'] . ' -3 days')),
                ]);
                $assignmentId = (int)$pdo->lastInsertId();

                $insAtt = $pdo->prepare(
                    'INSERT INTO AssignmentAttachment (assignment_id, file_path, file_name, file_size, file_mime)
                     VALUES (:aid, :fp, :fn, :fs, :fm)'
                );
                foreach ($attachmentMeta as $m) {
                    $insAtt->execute([':aid' => $assignmentId, ':fp' => $m['path'], ':fn' => $m['name'],
                                      ':fs' => $m['size'], ':fm' => $m['mime']]);
                }
                $pdo->commit();
                $stats['assignments_created']++;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $stats['errors'][] = "Assignment {$a['title']}: " . $e->getMessage();
                continue;
            }
        } else {
            $assignmentId = $existingId;
            $stats['assignments_skipped']++;
            $names = $pdo->prepare('SELECT file_name FROM AssignmentAttachment WHERE assignment_id = :aid');
            $names->execute([':aid' => $assignmentId]);
            $existingNames = array_flip($names->fetchAll(PDO::FETCH_COLUMN) ?: []);
            $insAtt = $pdo->prepare(
                'INSERT INTO AssignmentAttachment (assignment_id, file_path, file_name, file_size, file_mime)
                 VALUES (:aid, :fp, :fn, :fs, :fm)'
            );
            foreach ($attachmentMeta as $m) {
                if (isset($existingNames[$m['name']])) continue;
                $insAtt->execute([':aid' => $assignmentId, ':fp' => $m['path'], ':fn' => $m['name'],
                                  ':fs' => $m['size'], ':fm' => $m['mime']]);
                $stats['attachments_added'] = ($stats['attachments_added'] ?? 0) + 1;
            }
        }

        // ── Submissions ─────────────────────────────────────
        $totalStudents = $roster ? count($roster) : 0;
        if ($totalStudents === 0) continue;

        $counts = $a['counts'] + ['draft_saved' => 0];
        $need = $counts['published'] + $counts['draft'] + $counts['submitted'] + $counts['late'] + $counts['draft_saved'];
        $need = min($need, $totalStudents);

        $offset = ($ai - 1) * 9;
        $picked = [];
        for ($i = 0; $i < $need; $i++) {
            $picked[] = $roster[($offset + $i) % $totalStudents];
        }

        $subDirPath = $subBase . '/' . $assignmentId;
        if (!is_dir($subDirPath) && !mkdir($subDirPath, 0755, true)) {
            $stats['errors'][] = "mkdir $subDirPath";
            continue;
        }

        $seen = [];
        $roleQueue = array_merge(
            array_fill(0, $counts['published'], 'published'),
            array_fill(0, $counts['draft'], 'draft'),
            array_fill(0, $counts['submitted'], 'submitted'),
            array_fill(0, $counts['late'], 'late'),
            array_fill(0, $counts['draft_saved'], 'draft_saved')
        );

        foreach ($picked as $idx => $stu) {
            if ($a['pub'] !== null && strtotime($a['pub']) > time()) continue;
            $role = $roleQueue[$idx] ?? 'submitted';
            $dupKey = $assignmentId . ':' . $stu['enrollment_id'];
            if (isset($seen[$dupKey])) continue;
            $seen[$dupKey] = true;

            $chk = $pdo->prepare('SELECT submission_id FROM Submission WHERE assignment_id = :aid AND enrollment_id = :eid LIMIT 1');
            $chk->execute([':aid' => $assignmentId, ':eid' => $stu['enrollment_id']]);
            if ($chk->fetchColumn()) { $stats['submissions_skipped']++; continue; }

            // In-progress work: an unsent draft has no file and no submitted_at.
            if ($role === 'draft_saved') {
                $draftContent = "Bản nháp bài làm của " . $stu['full_name'] . " (" . $stu['student_id'] . ").\n"
                    . "Đã ghi chú dàn ý và tài liệu tham khảo, đang hoàn thiện nội dung trước khi nộp chính thức.";
                $pdo->beginTransaction();
                try {
                    $pdo->prepare(
                        "INSERT INTO Submission
                            (assignment_id, enrollment_id, file_path, file_name, file_size, file_mime,
                             content, submitted_at, score, feedback, grade_status, is_demo, version)
                         VALUES (:aid, :eid, NULL, NULL, NULL, NULL, :content, NULL, NULL, NULL, 'Draft', 1, 0)"
                    )->execute([':aid' => $assignmentId, ':eid' => $stu['enrollment_id'], ':content' => $draftContent]);
                    $pdo->commit();
                    $stats['drafts_created']++;
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $stats['errors'][] = "Draft {$a['title']} / {$stu['student_id']}: " . $e->getMessage();
                }
                continue;
            }

            $ext = $a['sub_ext'];
            $fileName = 'Bai-nop-' . $stu['student_id'] . '.' . $ext;
            $diskName = demo_rel_name('sub', $a['key'] . ':' . $stu['enrollment_id'], $ext, $fileName);
            $diskPath = $subDirPath . '/' . $diskName;
            if (!is_file($diskPath)) {
                make_file(
                    $diskPath, $ext,
                    'BÀI NỘP – ' . mb_strtoupper($stu['full_name']),
                    $section['course_code'] . ' • ' . $section['section_code'] . ' • MSSV ' . $stu['student_id'],
                    [
                        'Bài tập: ' . preg_replace('/^\[DEMO\]\s*/u', '', $a['title']),
                        'Loại bài: ' . $a['type'],
                        'Sinh viên: ' . $stu['full_name'] . ' (' . $stu['student_id'] . ')',
                        'Lớp học phần: ' . $section['section_code'],
                        'Học phần: ' . $section['course_code'] . ' – ' . $section['course_name'],
                        '',
                        'Nội dung tóm tắt:',
                        'Bài làm được trình bày theo cấu trúc yêu cầu của giảng viên, có phân tích và khuyến nghị.',
                        'Số liệu và ví dụ minh họa được tổng hợp từ tài liệu học phần.',
                        '',
                        'Lưu ý: dữ liệu minh họa – không phải bài nộp thật của sinh viên.',
                    ],
                    $font
                );
                $stats['submission_files_created']++;
            }

            if ($role === 'late') {
                $submittedAt = date('Y-m-d H:i:s', strtotime($a['due'] . ' +1 day +2 hours'));
                $score = null; $feedback = null; $gradeStatus = 'Draft';
            } elseif ($role === 'published' || $role === 'draft') {
                $submittedAt = date('Y-m-d H:i:s', strtotime($a['due'] . ' -2 days -3 hours'));
                $ratio = 0.62 + ((crc32($a['key'] . $stu['student_id']) % 34) / 100);
                $score = round($ratio * (float)$a['max'], 1);
                $feedback = $feedbackPool[$idx % count($feedbackPool)];
                $gradeStatus = $role === 'published' ? 'Published' : 'Draft';
            } else {
                $submittedAt = date('Y-m-d H:i:s', strtotime($a['due'] . ' -1 day -5 hours'));
                $score = null; $feedback = null; $gradeStatus = 'Draft';
            }

            $submittedAt = date('Y-m-d H:i:s', min(time(), max(strtotime($a['pub'] ?? '1970-01-01'), strtotime($submittedAt))));
            $mime = file_mime($diskPath, $ext);
            $pdo->beginTransaction();
            try {
                $pdo->prepare(
                    'INSERT INTO Submission
                        (assignment_id, enrollment_id, file_path, file_name, file_size, file_mime,
                         submitted_at, score, feedback, grade_status, is_demo, version)
                     VALUES (:aid, :eid, :fp, :fn, :fs, :fm, :sat, :sc, :fb, :gs, 1, 1)'
                )->execute([
                    ':aid' => $assignmentId, ':eid' => $stu['enrollment_id'],
                    ':fp' => 'submissions/' . $assignmentId . '/' . $diskName,
                    ':fn' => $fileName, ':fs' => filesize($diskPath), ':fm' => $mime,
                    ':sat' => $submittedAt, ':sc' => $score, ':fb' => $feedback, ':gs' => $gradeStatus,
                ]);
                $submissionId = (int)$pdo->lastInsertId();
                $pdo->prepare(
                    'INSERT INTO SubmissionAttachment (submission_id, file_path, file_name, file_size, file_mime)
                     VALUES (:sid, :fp, :fn, :fs, :fm)'
                )->execute([
                    ':sid' => $submissionId,
                    ':fp' => 'submissions/' . $assignmentId . '/' . $diskName,
                    ':fn' => $fileName, ':fs' => filesize($diskPath), ':fm' => $mime,
                ]);
                if ($score !== null) {
                    $pdo->prepare(
                        'INSERT INTO SubmissionGradeHistory
                            (submission_id, new_score, new_feedback, new_grade_status, changed_by, changed_at, submission_version)
                         VALUES (?, ?, ?, ?, ?, ?, 1)'
                    )->execute([$submissionId, $score, $feedback, $gradeStatus, $section['lecturer_account'], $submittedAt]);
                }
                $pdo->commit();
                $stats['submissions_created']++;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $stats['errors'][] = "Submission {$a['title']} / {$stu['student_id']}: " . $e->getMessage();
            }
        }
    }
}

echo "Demo seed finished\n";
foreach ($stats as $k => $v) {
    if ($k === 'errors') continue;
    echo str_pad($k, 28) . ": $v\n";
}
if ($stats['errors']) {
    echo "Errors:\n";
    foreach ($stats['errors'] as $e) echo " - $e\n";
    exit(1);
}
echo "OK\n";
