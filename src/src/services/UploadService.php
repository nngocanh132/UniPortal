<?php

class UploadService
{
    private const FILE_LIMIT = 20 * 1024 * 1024;
    private const TOTAL_LIMIT = 30 * 1024 * 1024;
    private const EXTENSIONS = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'zip', 'rar', 'jpg', 'jpeg', 'png', 'txt', 'py', 'java', 'cpp', 'c'];
    private const INVALID = 'Nội dung tệp không hợp lệ hoặc không khớp phần mở rộng.';
    private static array $created = [];

    // Exposed so student-facing pages show the same limits the validator enforces.
    public static function fileLimitBytes(): int { return self::FILE_LIMIT; }

    public static function totalLimitBytes(): int { return self::TOTAL_LIMIT; }

    public static function maxFiles(): int { return 10; }

    public static function allowedExtensions(): array { return self::EXTENSIONS; }

    public static function store(array $files, string $folder, bool $required = false): array
    {
        if (!preg_match('~\A(?:assignments|submissions)/[1-9][0-9]*\z~D', $folder)) {
            throw new RuntimeException('Thư mục tải lên không hợp lệ.');
        }
        $postLimit = trim((string) ini_get('post_max_size'));
        if (preg_match('/\A([0-9]+)\s*([KMG]?)\z/i', $postLimit, $limit)) {
            $postBytes = (int) $limit[1] * (1024 ** (int) strpos(' KMG', strtoupper($limit[2] ?: ' ')));
            if ($postBytes > 0 && (float) ($_SERVER['CONTENT_LENGTH'] ?? 0) > $postBytes) {
                throw new RuntimeException('Tổng dữ liệu vượt quá giới hạn tải lên của máy chủ.');
            }
        }
        $rows = self::normalize($files);
        if (count($rows) > self::maxFiles()) {
            throw new RuntimeException('Chỉ được tải lên tối đa 10 tệp.');
        }
        $empty = true;
        foreach ($rows as $row) {
            if ($row['error'] !== UPLOAD_ERR_NO_FILE || $row['name'] !== '' || $row['tmp_name'] !== '' || $row['size'] !== 0) {
                $empty = false;
            }
        }
        if ($empty) {
            if ($required) {
                throw new RuntimeException('Vui lòng chọn tệp tải lên.');
            }
            return [];
        }

        $prepared = [];
        $total = 0;
        $temporaryPaths = [];
        foreach ($rows as $row) {
            if ($row['error'] !== UPLOAD_ERR_OK) {
                throw new RuntimeException(self::uploadError($row['error']));
            }
            if (!is_uploaded_file($row['tmp_name']) || isset($temporaryPaths[$row['tmp_name']])) {
                throw new RuntimeException('Tệp tải lên không hợp lệ.');
            }
            $temporaryPaths[$row['tmp_name']] = true;
            $name = self::clientName($row['name']);
            $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            clearstatcache(true, $row['tmp_name']);
            $size = filesize($row['tmp_name']);
            if ($size === false || $size > self::FILE_LIMIT) {
                throw new RuntimeException('Mỗi tệp không được vượt quá 20 MB.');
            }
            $total += $size;
            if ($total > self::TOTAL_LIMIT) {
                throw new RuntimeException('Tổng dung lượng không được vượt quá 30 MB.');
            }
            $prepared[] = ['tmp' => $row['tmp_name'], 'name' => $name, 'extension' => $extension, 'size' => $size];
        }
        // Validate the whole batch before creating any destination files.
        foreach ($prepared as &$file) {
            $file['mime'] = self::mime($file['tmp'], $file['extension']);
            $file['hash'] = hash_file('sha256', $file['tmp']);
            if ($file['hash'] === false) {
                throw new RuntimeException('Không thể đọc tệp tải lên.');
            }
        }
        unset($file);

        $saved = [];
        try {
            $directory = self::directory($folder);
            foreach ($prepared as $file) {
                $filename = bin2hex(random_bytes(16)) . '.' . $file['extension'];
                $destination = $directory . '/' . $filename;
                $reservation = @fopen($destination, 'x+b');
                if ($reservation === false) {
                    throw new RuntimeException('Không thể lưu tệp tải lên.');
                }
                fclose($reservation);
                if (!@move_uploaded_file($file['tmp'], $destination)) {
                    @unlink($destination);
                    throw new RuntimeException('Không thể lưu tệp tải lên.');
                }
                $entry = ['path' => $folder . '/' . $filename, 'name' => $file['name'], 'size' => $file['size'], 'mime' => $file['mime']];
                clearstatcache(true, $destination);
                self::$created[$entry['path']] = ['entry' => $entry, 'absolute' => $destination, 'hash' => $file['hash'], 'identity' => lstat($destination)];
                $saved[] = $entry;
            }
            return $saved;
        } catch (Throwable $exception) {
            self::cleanup($saved);
            throw new RuntimeException('Không thể lưu tệp tải lên.', 0, $exception);
        }
    }

    public static function cleanup(array $saved): void
    {
        foreach ($saved as $entry) {
            if (!is_array($entry) || !isset($entry['path']) || !is_string($entry['path'])) {
                continue;
            }
            $owned = self::$created[$entry['path']] ?? null;
            if ($owned === null || $entry !== $owned['entry']) {
                continue;
            }
            $path = $owned['absolute'];
            clearstatcache(true, $path);
            $identity = @lstat($path);
            // Caller-provided paths alone never authorize deletion of existing attachments.
            if (is_array($identity) && is_array($owned['identity']) && $identity['dev'] === $owned['identity']['dev']
                && $identity['ino'] === $owned['identity']['ino'] && !is_link($path) && self::safeParents(dirname($path)) && is_file($path)
                && @hash_file('sha256', $path) === $owned['hash'] && @unlink($path)) {
                unset(self::$created[$entry['path']]);
            }
        }
    }

    public static function mime(string $path, string $extension): string
    {
        $extension = strtolower(ltrim($extension, '.'));
        if (!in_array($extension, self::EXTENSIONS, true)) {
            throw new RuntimeException('Định dạng tệp không được hỗ trợ.');
        }
        if (str_contains($path, "\0") || str_contains($path, '://') || is_link($path) || !is_file($path) || !is_readable($path)) {
            throw new RuntimeException('Không thể đọc tệp.');
        }
        clearstatcache(true, $path);
        $size = filesize($path);
        if ($size === false || $size > self::FILE_LIMIT) {
            throw new RuntimeException('Mỗi tệp không được vượt quá 20 MB.');
        }
        try {
            $detected = (new finfo(FILEINFO_MIME_TYPE))->file($path);
            $prefix = file_get_contents($path, false, null, 0, 512);
            if (!is_string($detected) || $prefix === false) {
                throw new RuntimeException(self::INVALID);
            }
            if ($extension === 'pdf') {
                $tail = file_get_contents($path, false, null, max(0, $size - 2048));
                if ($detected === 'application/pdf' && preg_match('/\A%PDF-[12]\.[0-9](?:\r|\n| )/', $prefix)
                    && is_string($tail) && preg_match('/%%EOF[\x20\t\r\n]*\z/', $tail)) {
                    return 'application/pdf';
                }
            } elseif (in_array($extension, ['jpg', 'jpeg', 'png'], true)) {
                $expected = $extension === 'png' ? 'image/png' : 'image/jpeg';
                $info = @getimagesize($path);
                $tailLength = $extension === 'png' ? 12 : 2;
                $tail = file_get_contents($path, false, null, max(0, $size - $tailLength));
                $signature = $extension === 'png'
                    ? str_starts_with($prefix, "\x89PNG\r\n\x1a\n") && $tail === "\0\0\0\0IEND\xaeB`\x82"
                    : str_starts_with($prefix, "\xff\xd8\xff") && $tail === "\xff\xd9";
                if ($detected === $expected && $signature && $info !== false && ($info['mime'] ?? '') === $expected
                    && $info[0] > 0 && $info[1] > 0 && $info[0] <= 20000 && $info[1] <= 20000 && $info[0] * $info[1] <= 100000000) {
                    return $expected;
                }
            } elseif (in_array($extension, ['docx', 'xlsx', 'pptx', 'zip'], true)) {
                $types = [
                    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                    'zip' => 'application/zip',
                ];
                if (in_array($detected, ['application/zip', 'application/x-zip', 'application/x-zip-compressed', $types[$extension]], true)
                    && (str_starts_with($prefix, "PK\x03\x04") || str_starts_with($prefix, "PK\x05\x06"))) {
                    self::zip($path, $size, $extension);
                    return $types[$extension];
                }
            } elseif ($extension === 'rar') {
                if (in_array($detected, ['application/vnd.rar', 'application/x-rar', 'application/x-rar-compressed'], true)) {
                    if (str_starts_with($prefix, "Rar!\x1a\x07\x00") && $size >= 20 && ord($prefix[9]) === 0x73
                        && self::u16($prefix, 12) >= 13 && self::u16($prefix, 12) <= $size - 7) {
                        return 'application/vnd.rar';
                    }
                    if (str_starts_with($prefix, "Rar!\x1a\x07\x01\x00") && $size >= 16) {
                        return 'application/vnd.rar';
                    }
                }
            } elseif (in_array($extension, ['doc', 'xls', 'ppt'], true)) {
                if ($extension === 'doc' && in_array($detected, ['application/rtf', 'text/rtf'], true)
                    && preg_match('/\A\{\\\\rtf[1-9][0-9]*\b/', $prefix)) {
                    $tail = file_get_contents($path, false, null, max(0, $size - 512));
                    if (is_string($tail) && preg_match('/\}[\x20\t\r\n]*\z/', $tail)) {
                        return 'application/rtf';
                    }
                }
                $types = ['doc' => 'application/msword', 'xls' => 'application/vnd.ms-excel', 'ppt' => 'application/vnd.ms-powerpoint'];
                $aliases = ['doc' => ['application/vnd.ms-word'], 'xls' => ['application/msexcel', 'application/x-msexcel'], 'ppt' => ['application/mspowerpoint']];
                if (str_starts_with($prefix, "\xd0\xcf\x11\xe0\xa1\xb1\x1a\xe1")
                    && in_array($detected, array_merge([$types[$extension], 'application/CDFV2', 'application/x-ole-storage', 'application/octet-stream'], $aliases[$extension]), true)
                    && self::compound($path, $prefix, $size, $extension)) {
                    return $types[$extension];
                }
            } else {
                $textTypes = ['text/plain', 'application/x-empty', 'inode/x-empty'];
                if ($extension !== 'txt') {
                    $textTypes = array_merge($textTypes, ['text/x-c', 'text/x-c++', 'text/x-java', 'text/x-java-source', 'text/x-python', 'text/x-script.python']);
                }
                if (in_array($detected, $textTypes, true)) {
                    $text = file_get_contents($path);
                    if (is_string($text) && preg_match('//u', $text) === 1 && !preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $text)
                        && !preg_match('/\A(?:\xef\xbb\xbf)?[\x20\t\r\n]*(?:MZ|<\?(?:php\b|=)|<(?:!doctype\s+html\b|html\b|head\b|body\b|script\b|iframe\b|svg\b))/i', $text)
                        && !preg_match('/<\?(?:php\b|=)|<\/?(?:html|head|body|script|iframe|svg|object|embed)\b/i', $text)) {
                        return match ($detected) {
                            'application/x-empty', 'inode/x-empty' => 'text/plain',
                            'text/x-script.python' => 'text/x-python',
                            'text/x-java-source' => 'text/x-java',
                            default => $detected,
                        };
                    }
                }
            }
        } catch (Throwable $exception) {
            throw new RuntimeException(self::INVALID, 0, $exception);
        }
        throw new RuntimeException(self::INVALID);
    }

    private static function normalize(array $files): array
    {
        if ($files === []) {
            return [];
        }
        $fields = ['name', 'tmp_name', 'error', 'size'];
        if (array_key_exists('name', $files)) {
            foreach ($fields as $field) {
                if (!array_key_exists($field, $files)) {
                    throw new RuntimeException('Dữ liệu tải lên không hợp lệ.');
                }
            }
            if (is_array($files['name'])) {
                $keys = array_keys($files['name']);
                foreach ($fields as $field) {
                    if (!is_array($files[$field]) || array_keys($files[$field]) !== $keys) {
                        throw new RuntimeException('Dữ liệu tải lên không hợp lệ.');
                    }
                }
                $rows = [];
                foreach ($keys as $key) {
                    $rows[] = array_combine($fields, array_map(static fn ($field) => $files[$field][$key], $fields));
                }
            } else {
                $rows = [$files];
            }
        } else {
            if (!array_is_list($files)) {
                throw new RuntimeException('Dữ liệu tải lên không hợp lệ.');
            }
            $rows = $files;
        }
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['name'], $row['tmp_name'], $row['error'], $row['size'])
                || !is_string($row['name']) || !is_string($row['tmp_name']) || !is_int($row['error']) || !is_int($row['size']) || $row['size'] < 0) {
                throw new RuntimeException('Dữ liệu tải lên không hợp lệ.');
            }
        }
        return $rows;
    }

    private static function clientName(string $name): string
    {
        if (strlen($name) > 4096 || preg_match('//u', $name) !== 1 || preg_match('/[\x00-\x1f\x7f]/', $name)) {
            throw new RuntimeException('Tên tệp không hợp lệ.');
        }
        $parts = explode('/', str_replace('\\', '/', $name));
        $name = end($parts);
        $extension = pathinfo($name, PATHINFO_EXTENSION);
        if (!in_array(strtolower($extension), self::EXTENSIONS, true)) {
            throw new RuntimeException('Định dạng tệp không được hỗ trợ.');
        }
        $stem = substr($name, 0, -strlen($extension) - 1);
        if ($stem === '' || trim($stem, '. ') === '') {
            throw new RuntimeException('Tên tệp không hợp lệ.');
        }
        $stem = substr($stem, 0, 254 - strlen($extension));
        while (preg_match('//u', $stem) !== 1) {
            $stem = substr($stem, 0, -1);
        }
        return $stem . '.' . $extension;
    }

    private static function uploadError(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Tệp vượt quá giới hạn tải lên.',
            UPLOAD_ERR_PARTIAL => 'Tệp chưa được tải lên đầy đủ.',
            UPLOAD_ERR_NO_FILE => 'Vui lòng chọn đầy đủ các tệp tải lên.',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'Không thể lưu tệp tải lên.',
            UPLOAD_ERR_EXTENSION => 'Máy chủ đã chặn tệp tải lên.',
            default => 'Tệp tải lên bị lỗi.',
        };
    }

    private static function directory(string $folder): string
    {
        $root = realpath(dirname(__DIR__, 3));
        if ($root === false) {
            throw new RuntimeException('Không thể mở thư mục tải lên.');
        }
        $path = str_replace('\\', '/', $root);
        foreach (explode('/', 'storage/uploads/' . $folder) as $part) {
            $path .= '/' . $part;
            if (is_link($path) || (!is_dir($path) && !@mkdir($path, 0755) && !is_dir($path))) {
                throw new RuntimeException('Không thể mở thư mục tải lên.');
            }
            if (!self::safeParents($path)) {
                throw new RuntimeException('Thư mục tải lên không hợp lệ.');
            }
        }
        return $path;
    }

    private static function safeParents(string $path): bool
    {
        $root = realpath(dirname(__DIR__, 3));
        if ($root === false) {
            return false;
        }
        $root = str_replace('\\', '/', $root);
        if (!str_starts_with($path, $root . '/storage')) {
            return false;
        }
        while ($path !== $root) {
            if (is_link($path) || !is_dir($path)) {
                return false;
            }
            $resolved = realpath($path);
            if ($resolved === false || str_replace('\\', '/', $resolved) !== $path) {
                return false;
            }
            $path = str_replace('\\', '/', dirname($path));
        }
        return true;
    }

    private static function zip(string $path, int $size, string $extension): void
    {
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new RuntimeException(self::INVALID);
        }
        try {
            $tailLength = min($size, 65557);
            fseek($stream, $size - $tailLength);
            $tail = self::readBytes($stream, $tailLength);
            $end = strrpos($tail, "PK\x05\x06");
            if ($end === false || strlen($tail) - $end < 22 || $end + 22 + self::u16($tail, $end + 20) !== strlen($tail)) {
                throw new RuntimeException(self::INVALID);
            }
            $count = self::u16($tail, $end + 10);
            $centralSize = self::u32($tail, $end + 12);
            $centralOffset = self::u32($tail, $end + 16);
            if (self::u16($tail, $end + 4) !== 0 || self::u16($tail, $end + 6) !== 0 || self::u16($tail, $end + 8) !== $count
                || $count > 2048 || ($count === 0 && ($centralSize !== 0 || $centralOffset !== 0))
                || $centralSize > 2 * 1024 * 1024 || $centralOffset + $centralSize !== $size - $tailLength + $end) {
                throw new RuntimeException(self::INVALID);
            }
            // Bound the central directory before PharData can allocate its archive manifest.
            fseek($stream, $centralOffset);
            $central = self::readBytes($stream, $centralSize);
            $offset = 0;
            $total = 0;
            $entries = [];
            $ranges = [];
            for ($index = 0; $index < $count; $index++) {
                if (strlen($central) - $offset < 46 || substr($central, $offset, 4) !== "PK\x01\x02") {
                    throw new RuntimeException(self::INVALID);
                }
                $header = substr($central, $offset, 46);
                $flags = self::u16($header, 8);
                $method = self::u16($header, 10);
                $compressed = self::u32($header, 20);
                $expanded = self::u32($header, 24);
                $nameLength = self::u16($header, 28);
                $recordLength = 46 + $nameLength + self::u16($header, 30) + self::u16($header, 32);
                $localOffset = self::u32($header, 42);
                $mode = (self::u32($header, 38) >> 16) & 0170000;
                $name = substr($central, $offset + 46, $nameLength);
                if ($offset + $recordLength > strlen($central) || $nameLength === 0 || $nameLength > 1024
                    || preg_match('~[\x00-\x1f\x7f\\\\:]|\A/|(?:\A|/)\.{1,2}(?:/|\z)~', $name)
                    || isset($entries[$name]) || self::u16($header, 34) !== 0 || ($flags & 0x2041) !== 0
                    || !in_array($method, [0, 8], true) || !in_array($mode, [0, 0100000, 0040000], true)
                    || $expanded > 32 * 1024 * 1024 || $expanded > max(1024 * 1024, $compressed * 200)
                    || ($expanded > 0 && $compressed === 0) || ($method === 0 && $compressed !== $expanded)
                    || $localOffset + 30 > $centralOffset) {
                    throw new RuntimeException(self::INVALID);
                }
                $total += $expanded;
                if ($total > 100 * 1024 * 1024) {
                    throw new RuntimeException(self::INVALID);
                }
                fseek($stream, $localOffset);
                $local = self::readBytes($stream, 30);
                $dataOffset = $localOffset + 30 + self::u16($local, 26) + self::u16($local, 28);
                if (substr($local, 0, 4) !== "PK\x03\x04" || self::u16($local, 6) !== $flags || self::u16($local, 8) !== $method
                    || self::u16($local, 26) !== $nameLength || self::readBytes($stream, $nameLength) !== $name
                    || $dataOffset + $compressed > $centralOffset
                    || (($flags & 8) === 0 && (self::u32($local, 18) !== $compressed || self::u32($local, 22) !== $expanded || substr($local, 14, 4) !== substr($header, 16, 4)))) {
                    throw new RuntimeException(self::INVALID);
                }
                $entries[$name] = ['size' => $expanded, 'compressed' => $compressed, 'offset' => $dataOffset, 'method' => $method, 'crc' => sprintf('%08x', self::u32($header, 16))];
                $ranges[] = [$localOffset, $dataOffset + $compressed];
                $offset += $recordLength;
            }
            if ($offset !== $centralSize) {
                throw new RuntimeException(self::INVALID);
            }
            usort($ranges, static fn ($a, $b) => $a[0] <=> $b[0]);
            $previousEnd = 0;
            foreach ($ranges as $index => $range) {
                if (($index === 0 && $range[0] !== 0) || $range[0] < $previousEnd) {
                    throw new RuntimeException(self::INVALID);
                }
                $previousEnd = $range[1];
            }
            foreach ($entries as $entry) {
                fseek($stream, $entry['offset']);
                $remaining = $entry['compressed'];
                $expanded = 0;
                $crc = hash_init('crc32b');
                $inflater = $entry['method'] === 8 ? inflate_init(ZLIB_ENCODING_RAW) : null;
                if ($inflater === false) {
                    throw new RuntimeException(self::INVALID);
                }
                // Small compressed chunks bound allocation even when declared sizes are forged.
                while ($remaining > 0) {
                    $chunk = self::readBytes($stream, min(8192, $remaining));
                    $remaining -= strlen($chunk);
                    $decoded = $inflater === null ? $chunk : @inflate_add($inflater, $chunk, $remaining === 0 ? ZLIB_FINISH : ZLIB_SYNC_FLUSH);
                    if ($decoded === false) {
                        throw new RuntimeException(self::INVALID);
                    }
                    $expanded += strlen($decoded);
                    if ($expanded > $entry['size']) {
                        throw new RuntimeException(self::INVALID);
                    }
                    hash_update($crc, $decoded);
                }
                if ($expanded !== $entry['size'] || hash_final($crc) !== $entry['crc']
                    || ($inflater !== null && (inflate_get_status($inflater) !== ZLIB_STREAM_END || inflate_get_read_len($inflater) !== $entry['compressed']))) {
                    throw new RuntimeException(self::INVALID);
                }
            }
        } finally {
            fclose($stream);
        }

        $temporary = tempnam(sys_get_temp_dir(), 'uni_upload_');
        if ($temporary === false) {
            throw new RuntimeException(self::INVALID);
        }
        $zipPath = $temporary . '.zip';
        $archive = null;
        $ownsZip = false;
        try {
            // PHP upload names lack a suffix; PharData needs an unambiguous archive format.
            $output = @fopen($zipPath, 'x+b');
            if ($output === false) {
                throw new RuntimeException(self::INVALID);
            }
            $ownsZip = true;
            try {
                $input = fopen($path, 'rb');
                if ($input === false) {
                    throw new RuntimeException(self::INVALID);
                }
                try {
                    if (stream_copy_to_stream($input, $output, $size + 1) !== $size) {
                        throw new RuntimeException(self::INVALID);
                    }
                } finally {
                    fclose($input);
                }
            } finally {
                fclose($output);
            }
            $archive = new PharData($zipPath, 0, null, Phar::ZIP);
            foreach ($entries as $name => $entry) {
                if (str_ends_with($name, '/')) {
                    continue;
                }
                if (!isset($archive[$name]) || !$archive[$name]->isFile() || $archive[$name]->getSize() !== $entry['size']
                    || $archive[$name]->getCompressedSize() !== $entry['compressed']) {
                    throw new RuntimeException(self::INVALID);
                }
            }
            if ($extension !== 'zip') {
                self::officeXml($archive, $entries, $extension);
            }
        } finally {
            unset($archive);
            if ($ownsZip && is_file($zipPath)) {
                @unlink($zipPath);
            }
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    private static function officeXml(PharData $archive, array $entries, string $extension): void
    {
        [$part, $element, $namespace, $contentType] = match ($extension) {
            'docx' => ['word/document.xml', 'document', 'wordprocessingml', 'wordprocessingml.document'],
            'xlsx' => ['xl/workbook.xml', 'workbook', 'spreadsheetml', 'spreadsheetml.sheet'],
            'pptx' => ['ppt/presentation.xml', 'presentation', 'presentationml', 'presentationml.presentation'],
        };
        foreach (['[Content_Types].xml' => 1024 * 1024, $part => self::FILE_LIMIT] as $name => $limit) {
            if (!isset($entries[$name]) || $entries[$name]['size'] === 0 || $entries[$name]['size'] > $limit) {
                throw new RuntimeException(self::INVALID);
            }
        }
        $types = self::xml($archive['[Content_Types].xml']->getContent());
        $found = false;
        if ($types->documentElement->localName !== 'Types' || $types->documentElement->namespaceURI !== 'http://schemas.openxmlformats.org/package/2006/content-types') {
            throw new RuntimeException(self::INVALID);
        }
        foreach ($types->getElementsByTagNameNS('http://schemas.openxmlformats.org/package/2006/content-types', 'Override') as $override) {
            if ($override->getAttribute('PartName') === '/' . $part
                && $override->getAttribute('ContentType') === 'application/vnd.openxmlformats-officedocument.' . $contentType . '.main+xml') {
                $found = true;
            }
        }
        $document = self::xml($archive[$part]->getContent());
        if (!$found || $document->documentElement->localName !== $element
            || !in_array($document->documentElement->namespaceURI, ['http://schemas.openxmlformats.org/' . $namespace . '/2006/main', 'http://purl.oclc.org/ooxml/' . $namespace . '/main'], true)) {
            throw new RuntimeException(self::INVALID);
        }
    }

    private static function xml(string $content): DOMDocument
    {
        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $content)) {
            throw new RuntimeException(self::INVALID);
        }
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            if (!$document->loadXML($content, LIBXML_NONET | LIBXML_COMPACT) || $document->doctype !== null || $document->documentElement === null) {
                throw new RuntimeException(self::INVALID);
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        return $document;
    }

    private static function compound(string $path, string $header, int $size, string $extension): bool
    {
        if (strlen($header) !== 512 || substr($header, 28, 2) !== "\xfe\xff"
            || !in_array([self::u16($header, 26), self::u16($header, 30)], [[3, 9], [4, 12]], true)
            || self::u16($header, 32) !== 6) {
            return false;
        }
        $sectorSize = 1 << self::u16($header, 30);
        if ($size < $sectorSize * 2 || $size % $sectorSize !== 0) {
            return false;
        }
        $sectors = intdiv($size, $sectorSize) - 1;
        $fatCount = self::u32($header, 44);
        if ($fatCount < 1 || $fatCount > 109 || $fatCount > $sectors) {
            return false;
        }
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            return false;
        }
        try {
            $fat = [];
            for ($index = 0; $index < $fatCount; $index++) {
                $sector = self::u32($header, 76 + 4 * $index);
                if ($sector >= $sectors) {
                    return false;
                }
                fseek($stream, ($sector + 1) * $sectorSize);
                $fat = array_merge($fat, array_values(unpack('V*', self::readBytes($stream, $sectorSize))));
            }
            $wanted = match ($extension) {
                'doc' => ['WordDocument'],
                'xls' => ['Workbook', 'Book'],
                'ppt' => ['PowerPoint Document'],
            };
            $sector = self::u32($header, 48);
            $visited = [];
            $found = false;
            while ($sector !== 0xfffffffe) {
                if ($sector >= $sectors || isset($visited[$sector]) || count($visited) >= 2048 || !isset($fat[$sector])) {
                    return false;
                }
                $visited[$sector] = true;
                fseek($stream, ($sector + 1) * $sectorSize);
                $directory = self::readBytes($stream, $sectorSize);
                for ($offset = 0; $offset < $sectorSize; $offset += 128) {
                    if (ord($directory[$offset + 66]) !== 2 || self::u32($directory, $offset + 120) === 0) {
                        continue;
                    }
                    foreach ($wanted as $name) {
                        $encoded = implode("\0", str_split($name)) . "\0\0\0";
                        if (self::u16($directory, $offset + 64) === strlen($encoded) && substr($directory, $offset, strlen($encoded)) === $encoded) {
                            $found = true;
                        }
                    }
                }
                $sector = $fat[$sector];
            }
            return $found;
        } finally {
            fclose($stream);
        }
    }

    private static function readBytes($stream, int $length): string
    {
        if ($length < 0 || $length > self::FILE_LIMIT) {
            throw new RuntimeException(self::INVALID);
        }
        $data = '';
        while (strlen($data) < $length) {
            $chunk = fread($stream, $length - strlen($data));
            if ($chunk === false || $chunk === '') {
                throw new RuntimeException(self::INVALID);
            }
            $data .= $chunk;
        }
        return $data;
    }

    private static function u16(string $data, int $offset): int
    {
        return unpack('v', substr($data, $offset, 2))[1];
    }

    private static function u32(string $data, int $offset): int
    {
        return unpack('V', substr($data, $offset, 4))[1];
    }
}
