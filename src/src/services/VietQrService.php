<?php
/**
 * UniPortal – VietQR payload builder + local QR renderer.
 *
 * Builds an EMVCo merchant-presented QR payload for a bank account transfer
 * (NAPAS VietQR layout: GUID A000000727, service code QRIBFTTA) and renders it
 * as an SVG with a 4-module quiet zone. No external QR service is required.
 */
require_once __DIR__ . '/../lib/QRCode.php';

class VietQrService {

    /** Build the EMVCo/VietQR payload string (including CRC). */
    public static function buildPayload(string $bankBin, string $accountNumber, int $amountVnd,
        string $reference, string $accountHolder = 'VNUIS ACADEMIC'): string {
        $bankBin       = preg_replace('/\D/', '', $bankBin);
        $accountNumber = preg_replace('/[^0-9A-Za-z]/', '', $accountNumber);
        $amountVnd     = max(0, $amountVnd);

        $beneficiary = self::field('00', $bankBin) . self::field('01', $accountNumber);
        $merchant    = self::field('00', 'A000000727')
                     . self::field('01', $beneficiary)
                     . self::field('02', 'QRIBFTTA');

        $payload  = self::field('00', '01');            // payload format indicator
        $payload .= self::field('01', '12');            // dynamic QR
        $payload .= self::field('38', $merchant);       // beneficiary + service
        $payload .= self::field('53', '704');           // currency VND
        $payload .= self::field('54', (string)$amountVnd);
        $payload .= self::field('58', 'VN');
        $payload .= self::field('59', self::merchantName($accountHolder));
        $payload .= self::field('60', 'HA NOI');
        $payload .= self::field('62', self::field('08', $reference)); // transfer purpose
        $payload .= '6304';
        return $payload . self::crc16($payload);
    }

    /** Render a payload as a standalone SVG image (white background, quiet zone). */
    public static function renderSvg(string $payload, int $scale = 6): string {
        $raw = \splitbrain\phpQRCode\QRCode::svg($payload, ['s' => 'qrm']);
        if (!preg_match('/viewBox="0 0 (\d+) (\d+)"/', $raw, $m)) {
            throw new RuntimeException('Không thể tạo mã QR.');
        }
        $size = (int)$m[1];
        $margin = 4;
        $total = $size + $margin * 2;
        $inner = preg_replace('/\A<svg[^>]*>|<\/svg>\z/', '', $raw);

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $total . ' ' . $total . '"'
            . ' width="' . ($total * $scale) . '" height="' . ($total * $scale) . '"'
            . ' shape-rendering="crispEdges" role="img" aria-label="VietQR">'
            . '<rect width="' . $total . '" height="' . $total . '" fill="#ffffff"/>'
            . '<g transform="translate(' . $margin . ' ' . $margin . ')" fill="#000000">'
            . $inner . '</g></svg>';
    }

    /** Normalise free-form bank content for reference matching. */
    public static function normalize(string $value): string {
        $value = strtoupper($value);
        return preg_replace('/[^A-Z0-9]/', '', $value) ?? '';
    }

    /** Extract a UniPortal payment reference from normalized bank content. */
    public static function extractReference(string $value): ?string {
        $norm = self::normalize($value);
        if (preg_match('/UNI\d{8}HK\d{1,3}[A-F0-9]{6}/', $norm, $m)) {
            return $m[0];
        }
        return null;
    }

    /** ASCII-safe merchant name, max 25 characters (EMVCo tag 59). */
    public static function merchantName(string $name): string {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
        if ($ascii === false || $ascii === '') $ascii = $name;
        $ascii = strtoupper(preg_replace('/[^A-Za-z0-9 ]/', ' ', $ascii));
        $ascii = trim(preg_replace('/\s+/', ' ', $ascii));
        if ($ascii === '') $ascii = 'UNIPORTAL';
        return substr($ascii, 0, 25);
    }

    /** EMVCo TLV field with two-digit length. */
    private static function field(string $id, string $value): string {
        $len = strlen($value);
        if ($len > 99) throw new InvalidArgumentException('EMV field too long: tag ' . $id);
        return $id . str_pad((string)$len, 2, '0', STR_PAD_LEFT) . $value;
    }

    /** CRC-16/CCITT-FALSE (poly 0x1021, init 0xFFFF) used by EMVCo tag 63. */
    public static function crc16(string $data): string {
        $crc = 0xFFFF;
        $len = strlen($data);
        for ($i = 0; $i < $len; $i++) {
            $crc ^= ord($data[$i]) << 8;
            for ($bit = 0; $bit < 8; $bit++) {
                $crc = ($crc & 0x8000) ? (($crc << 1) ^ 0x1021) : ($crc << 1);
                $crc &= 0xFFFF;
            }
        }
        return strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
    }
}
