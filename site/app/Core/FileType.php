<?php
declare(strict_types=1);
namespace Chengyu\Core;

/** Content sniffing for the upload allowlist, without Fileinfo, GD or ZipArchive.
 * This is type identification, not a malware scanner or a full media decoder.
 * Callers must retain authorization, image limits and protected storage/serving.
 */
final class FileType
{
    public const PROBE_BYTES = 262144;
    public const IMAGES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    public const EXTENSIONS = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp','gif'=>'image/gif','pdf'=>'application/pdf','zip'=>'application/zip','txt'=>'text/plain','mp4'=>'video/mp4','mp3'=>'audio/mpeg'];
    private const UNKNOWN = 'application/octet-stream';

    /** $read reads an exact range from the same immutable file, excluding any storage envelope. */
    public static function detect(string $head, int $bytes, callable $read): string
    {
        return self::identify($head, $bytes, self::reader($head, $bytes, $read));
    }

    /** All upload paths use the same type/dimension inspection and a single read budget. */
    public static function inspect(string $head, int $bytes, callable $read): array
    {
        $range = self::reader($head, $bytes, $read);
        $mime = self::identify($head, $bytes, $range); $image = null;
        if (in_array($mime, self::IMAGES, true)) {
            $image = $mime === 'image/jpeg' ? self::jpegInfo($bytes, $range) : @getimagesizefromstring($head);
        }
        return ['mime' => $mime, 'image' => $image];
    }

    private static function reader(string $head, int $bytes, callable $read): callable
    {
        if ($bytes < 1 || strlen($head) !== min($bytes, self::PROBE_BYTES)) { throw new Problem('File unavailable.'); }
        $budget = 262144; $requests = 32;
        return static function (int $start, int $length) use ($head, $bytes, $read, &$budget, &$requests): string {
            if ($start < 0 || $length < 1 || $length > 65558 || $start > $bytes - $length) { return ''; }
            if ($start + $length <= strlen($head)) { return substr($head, $start, $length); }
            if ($length > $budget || --$requests < 0) { throw new Problem('File verification exceeded its read limit.'); }
            $budget -= $length; $data = $read($start, $length);
            if (!is_string($data) || strlen($data) !== $length) { throw new Problem('File unavailable.'); }
            return $data;
        };
    }

    private static function identify(string $head, int $bytes, callable $range): string
    {
        if (substr($head, 0, 2) === "\xff\xfe" || substr($head, 0, 2) === "\xfe\xff") {
            return self::text($head, strlen($head) === $bytes) ? 'text/plain' : self::UNKNOWN;
        }
        // Recognized but malformed binary headers never fall through to plain text.
        if (substr($head, 0, 8) === "\x89PNG\r\n\x1a\n") {
            if ($bytes < 57 || substr($head, 8, 8) !== "\x00\x00\x00\x0dIHDR") { return self::UNKNOWN; }
            $depths = [0=>[1,2,4,8,16], 2=>[8,16], 3=>[1,2,4,8], 4=>[8,16], 6=>[8,16]];
            if (!in_array(ord($head[24]), $depths[ord($head[25])] ?? [], true)
                || ord($head[26]) !== 0 || ord($head[27]) !== 0 || ord($head[28]) > 1
                || hash('crc32b', substr($head, 12, 17), true) !== substr($head, 29, 4)) { return self::UNKNOWN; }
            $end = $range($bytes - 12, 12);
            if ($end !== "\x00\x00\x00\x00IEND\xae\x42\x60\x82") { return self::UNKNOWN; }
            $palette = false;
            for ($at = 33, $chunks = 0; $chunks < 128 && $at + 12 < $bytes; ++$chunks) {
                $chunk = $range($at, 8); if (strlen($chunk) !== 8) { return self::UNKNOWN; }
                $length = self::be32($chunk, 0); $type = substr($chunk, 4);
                if ($length > $bytes - $at - 12 || !preg_match('/^[A-Za-z]{4}$/D', $type)) { return self::UNKNOWN; }
                if ($type === 'IDAT' && $length > 0) { return ord($head[25]) !== 3 || $palette ? 'image/png' : self::UNKNOWN; }
                if ($type === 'IEND' || $type === 'IHDR') { return self::UNKNOWN; }
                if ($type === 'PLTE') {
                    if ($palette || in_array(ord($head[25]), [0,4], true) || $length < 3 || $length > 768 || $length % 3 !== 0
                        || (ord($head[25]) === 3 && $length / 3 > (1 << ord($head[24])))) { return self::UNKNOWN; }
                    $colors = $range($at + 8, $length + 4);
                    if (hash('crc32b', 'PLTE' . substr($colors, 0, $length), true) !== substr($colors, $length)) { return self::UNKNOWN; }
                    $palette = true;
                } elseif ($type !== 'IDAT' && ord($type[0]) < 97) { return self::UNKNOWN; }
                $at += 12 + $length;
            }
            return self::UNKNOWN;
        }
        if (in_array(substr($head, 0, 6), ['GIF87a', 'GIF89a'], true)) {
            return self::gif($head, $bytes, $range) ? 'image/gif' : self::UNKNOWN;
        }
        if (substr($head, 0, 3) === "\xff\xd8\xff") {
            // Trailing padding is allowed by common JPEG encoders; SOF/type/dimensions are checked by callers.
            $tail = $range(max(0, $bytes - 4096), min($bytes, 4096));
            return $bytes >= 12 && strpos($tail, "\xff\xd9") !== false ? 'image/jpeg' : self::UNKNOWN;
        }
        if (substr($head, 0, 4) === 'RIFF' && substr($head, 8, 4) === 'WEBP') {
            if ($bytes < 30 || substr($head, 8, 4) !== 'WEBP' || self::le32($head, 4) + 8 !== $bytes) { return self::UNKNOWN; }
            return self::webp($bytes, $range) ? 'image/webp' : self::UNKNOWN;
        }
        if (substr($head, 0, 5) === '%PDF-') {
            if (!preg_match('/^%PDF-(?:1\.[0-7]|2\.0)(?:\r|\n)/', $head)) { return self::UNKNOWN; }
            $tail = $range(max(0, $bytes - 4096), min($bytes, 4096));
            return preg_match('/%%EOF[\x00\x09\x0a\x0c\x0d\x20]*$/D', $tail) ? 'application/pdf' : self::UNKNOWN;
        }
        if (in_array(substr($head, 0, 4), ["PK\x03\x04", "PK\x05\x06", "PK\x06\x06"], true)) { return self::zip($head, $bytes, $range) ? 'application/zip' : self::UNKNOWN; }
        if ((strlen($head) >= 4 && substr($head, 0, 3) === 'ID3' && ord($head[3]) < 32) || (strlen($head) >= 2 && ord($head[0]) === 255 && (ord($head[1]) & 224) === 224)) {
            return self::mp3($head, $bytes, $range) ? 'audio/mpeg' : self::UNKNOWN;
        }
        if (strlen($head) >= 8 && in_array(substr($head, 4, 4), ['ftyp','free','skip','wide'], true)) {
            return self::mp4($bytes, $range) ? 'video/mp4' : self::UNKNOWN;
        }
        return self::text($head, strlen($head) === $bytes) ? 'text/plain' : self::UNKNOWN;
    }

    private static function le16(string $s, int $at): int { return unpack('v', substr($s, $at, 2))[1]; }
    private static function le32(string $s, int $at): int { return unpack('V', substr($s, $at, 4))[1]; }
    private static function be32(string $s, int $at): int { return unpack('N', substr($s, $at, 4))[1]; }

    private static function jpegInfo(int $bytes, callable $range): ?array
    {
        // EXIF/ICC/comment segments may precede SOF by more than the prefix size.
        // Skip segment bodies by length; never load their potentially large contents.
        for ($at = 2, $markers = 0; $markers < 256 && $at + 4 <= $bytes; ++$markers) {
            $marker = $range($at, 2);
            if (strlen($marker) !== 2 || $marker[0] !== "\xff") { return null; }
            $type = ord($marker[1]);
            if ($type === 255) { ++$at; continue; } // Marker fill bytes.
            if ($type === 0 || $type === 216 || $type === 217 || $type === 218 || ($type >= 208 && $type <= 215)) { return null; }
            if ($type === 1) { $at += 2; continue; }
            $length = unpack('n', $range($at + 2, 2))[1];
            if ($length < 2 || $length > $bytes - $at - 2) { return null; }
            if (in_array($type, [192,193,194,195,197,198,199,201,202,203,205,206,207], true)) {
                if ($length < 11) { return null; }
                $sof = $range($at + 4, 6);
                $components = ord($sof[5]);
                if ($components < 1 || $length !== 8 + 3 * $components || !in_array(ord($sof[0]), [8,12,16], true)) { return null; }
                return [unpack('n', substr($sof, 3, 2))[1], unpack('n', substr($sof, 1, 2))[1], 'mime' => 'image/jpeg'];
            }
            $at += 2 + $length;
        }
        return null;
    }

    private static function webp(int $bytes, callable $range): bool
    {
        $extended = false; $animation = false;
        for ($at = 12, $chunks = 0; $chunks < 128 && $at + 8 <= $bytes; ++$chunks) {
            $header = $range($at, 8); if (strlen($header) !== 8) { return false; }
            $type = substr($header, 0, 4); $length = self::le32($header, 4);
            if ($length + ($length % 2) > $bytes - $at - 8) { return false; }
            if ($type === 'VP8 ' || $type === 'VP8L') {
                $data = $range($at + 8, min($length, 10));
                return $type === 'VP8 ' ? strlen($data) === 10 && (ord($data[0]) & 1) === 0 && substr($data, 3, 3) === "\x9d\x01\x2a" : strlen($data) >= 5 && $data[0] === "\x2f";
            }
            if ($at === 12) {
                if ($type !== 'VP8X' || $length !== 10) { return false; }
                $data = $range($at + 8, 10); $extended = true; $animation = (ord($data[0]) & 2) !== 0;
            } elseif (!$extended) { return false; }
            if ($type === 'ANMF' && $animation && $length >= 30) {
                // Frame header is 16 bytes; optional alpha precedes the encoded image chunk.
                $end = $at + 8 + $length; $frame = $at + 24;
                for ($n = 0; $n < 2 && $frame + 8 <= $end; ++$n) {
                    $h = $range($frame, 8); $size = self::le32($h, 4); $kind = substr($h, 0, 4);
                    if ($size + ($size % 2) > $end - $frame - 8) { return false; }
                    $data = $range($frame + 8, min($size, 10));
                    if ($kind === 'VP8 ') { return strlen($data) === 10 && (ord($data[0]) & 1) === 0 && substr($data, 3, 3) === "\x9d\x01\x2a"; }
                    if ($kind === 'VP8L') { return strlen($data) >= 5 && $data[0] === "\x2f"; }
                    if ($kind !== 'ALPH') { return false; }
                    $frame += 8 + $size + ($size % 2);
                }
            }
            $at += 8 + $length + ($length % 2);
        }
        return false;
    }

    private static function gif(string $head, int $bytes, callable $range): bool
    {
        if ($bytes < 26 || $range($bytes - 1, 1) !== ';') { return false; }
        $packed = ord($head[10]); $at = 13 + (($packed & 128) ? 3 * (1 << (($packed & 7) + 1)) : 0);
        for ($blocks = 0; $blocks < 1024 && $at < $bytes - 1; ++$blocks) {
            $marker = $range($at++, 1);
            if ($marker === ',') {
                $descriptor = $range($at, 9);
                if (strlen($descriptor) !== 9 || self::le16($descriptor, 4) === 0 || self::le16($descriptor, 6) === 0) { return false; }
                $packed = ord($descriptor[8]); $at += 9 + (($packed & 128) ? 3 * (1 << (($packed & 7) + 1)) : 0);
                $data = $range($at, 2);
                return strlen($data) === 2 && ord($data[0]) >= 2 && ord($data[0]) <= 8 && ord($data[1]) > 0 && $at + 2 + ord($data[1]) < $bytes - 1;
            }
            if ($marker !== '!') { return false; }
            ++$at; // Extension label, followed by data sub-blocks.
            do {
                $length = $range($at, 1); if ($length === '') { return false; }
                $length = ord($length); $at += 1 + $length;
                if (++$blocks > 1024 || $at >= $bytes) { return false; }
            } while ($length > 0);
        }
        return false;
    }

    private static function zip(string $head, int $bytes, callable $range): bool
    {
        if ($bytes < 22 || !in_array(substr($head, 0, 4), ["PK\x03\x04", "PK\x05\x06", "PK\x06\x06"], true)) { return false; }
        $tailStart = max(0, $bytes - 65557); $tail = $range($tailStart, $bytes - $tailStart);
        // An EOCD-looking sequence in the archive comment is not necessarily the real EOCD.
        for ($at = strlen($tail) - 22; $at >= 0; --$at) {
            if (substr($tail, $at, 4) !== "PK\x05\x06" || $at + 22 + self::le16($tail, $at + 20) !== strlen($tail)) { continue; }
            if (self::le16($tail, $at + 4) !== 0 || self::le16($tail, $at + 6) !== 0) { continue; }
            $entries = self::le16($tail, $at + 10); $length = self::le32($tail, $at + 12); $offset = self::le32($tail, $at + 16);
            $directoryEnd = $tailStart + $at;
            if (self::le16($tail, $at + 8) !== $entries) { continue; }
            if ($entries === 65535 || $length === 4294967295 || $offset === 4294967295) {
                $locator = $range($tailStart + $at - 20, 20);
                if (strlen($locator) !== 20 || substr($locator, 0, 4) !== "PK\x06\x07" || self::le32($locator, 4) !== 0 || self::le32($locator, 16) !== 1 || self::le32($locator, 12) !== 0) { continue; }
                $recordOffset = self::le32($locator, 8); $record = $range($recordOffset, 56);
                if (strlen($record) !== 56 || substr($record, 0, 4) !== "PK\x06\x06" || self::le32($record, 4) < 44 || self::le32($record, 8) !== 0 || self::le32($record, 16) !== 0 || self::le32($record, 20) !== 0 || self::le32($record, 36) !== 0 || self::le32($record, 44) !== 0 || self::le32($record, 52) !== 0) { continue; }
                $entries = self::le32($record, 32); $length = self::le32($record, 40); $offset = self::le32($record, 48);
                if (substr($record, 24, 8) !== substr($record, 32, 8) || $offset + $length > $recordOffset || $recordOffset + 12 + self::le32($record, 4) !== $tailStart + $at - 20) { continue; }
                $directoryEnd = $recordOffset;
            }
            if ($entries === 0) { if ($length === 0 && $offset === 0 && $directoryEnd === 0) { return true; } continue; }
            if ($offset < 30 || $length < 46 || $offset + $length > $tailStart + $at) { continue; }
            $first = $range($offset, 46);
            if (substr($head, 0, 4) === "PK\x03\x04" && substr($first, 0, 4) === "PK\x01\x02") { return true; }
        }
        return false;
    }

    private static function mp4(int $bytes, callable $range): bool
    {
        $at = 0; $recognized = false; $media = false;
        // Read headers, not media/padding payloads. The shared range budget still applies.
        for ($boxes = 0; $boxes < 128 && $at + 8 <= $bytes; ++$boxes) {
            $header = $range($at, 8); $size = self::be32($header, 0); $type = substr($header, 4); $headerBytes = 8;
            if ($size === 1) {
                if ($bytes - $at < 16) { return false; }
                $large = $range($at + 8, 8); $high = self::be32($large, 0); $low = self::be32($large, 4);
                // Compare before multiplication: hostile uint64 values must not become floats.
                $remaining = $bytes - $at;
                if ($high > intdiv($remaining, 4294967296) || ($high === intdiv($remaining, 4294967296) && $low > $remaining % 4294967296)) { return false; }
                $size = $high * 4294967296 + $low; $headerBytes = 16;
            } elseif ($size === 0) { $size = $bytes - $at; }
            if ($size < $headerBytes || $size > $bytes - $at) { return false; }
            $payload = $size - $headerBytes;
            if ($type === 'ftyp') {
                if ($recognized || $payload < 8 || $payload > 4096 || $payload % 4 !== 0) { return false; }
                $brands = $range($at + $headerBytes, $payload);
                for ($brand = 0; $brand < $payload; $brand += 4) {
                    if ($brand === 4) { continue; } // Minor version is not a brand.
                    if (in_array(substr($brands, $brand, 4), ['isom','iso2','iso3','iso4','iso5','iso6','iso7','iso8','iso9','mp41','mp42','avc1','hvc1','hev1','M4V ','MSNV','dash','cmfc','cmfs'], true)) { $recognized = true; break; }
                }
                if (!$recognized) { return false; }
            } elseif (!$recognized && !in_array($type, ['free','skip','wide'], true)) { return false; }
            elseif (in_array($type, ['moov','mdat'], true) && $payload > 0) { $media = true; }
            $at += $size;
            if ($at === $bytes) { return $recognized && $media; }
        }
        return false;
    }

    /** Require two consecutive Layer III frames; ID3 tags alone are not audio. */
    private static function mp3(string $head, int $bytes, callable $range): bool
    {
        $at = 0;
        if (substr($head, 0, 3) === 'ID3') {
            if (strlen($head) < 10 || !in_array(ord($head[3]), [2,3,4], true) || ord($head[4]) === 255) { return false; }
            $allowed = [2=>192, 3=>224, 4=>240];
            if ((ord($head[5]) & ~$allowed[ord($head[3])]) !== 0) { return false; }
            $length = 0;
            for ($i = 6; $i < 10; ++$i) { if (ord($head[$i]) > 127) { return false; } $length = ($length << 7) | ord($head[$i]); }
            $at = 10 + $length + ((ord($head[3]) === 4 && (ord($head[5]) & 16)) ? 10 : 0);
            if (ord($head[3]) === 4 && (ord($head[5]) & 16) && $range($at - 10, 10) !== '3DI' . substr($head, 3, 7)) { return false; }
        }
        $first = self::frame($range($at, 4));
        if ($first === null) { return false; }
        $next = $at + $first[0]; $second = self::frame($range($next, 4));
        return $second !== null && $second[1] === $first[1] && $next + $second[0] <= $bytes;
    }

    private static function frame(string $h): ?array
    {
        if (strlen($h) !== 4 || ord($h[0]) !== 255 || (ord($h[1]) & 224) !== 224) { return null; }
        $b = ord($h[1]); $c = ord($h[2]); $version = ($b >> 3) & 3; $layer = ($b >> 1) & 3; $bitrate = ($c >> 4) & 15; $sample = ($c >> 2) & 3;
        if ($version === 1 || $layer !== 1 || $bitrate === 0 || $bitrate === 15 || $sample === 3 || (ord($h[3]) & 3) === 2) { return null; }
        $rates = $version === 3 ? [0,32,40,48,56,64,80,96,112,128,160,192,224,256,320] : [0,8,16,24,32,40,48,56,64,80,96,112,128,144,160];
        $hz = [44100,48000,32000][$sample] / ($version === 3 ? 1 : ($version === 2 ? 2 : 4));
        $length = (int)floor(($version === 3 ? 144000 : 72000) * $rates[$bitrate] / $hz) + (($c >> 1) & 1);
        return [$length, $version . ':' . $sample];
    }

    private static function text(string $s, bool $complete): bool
    {
        if (substr($s, 0, 2) === "\xff\xfe" || substr($s, 0, 2) === "\xfe\xff") {
            $little = substr($s, 0, 2) === "\xff\xfe"; $decoded = '';
            if (strlen($s) % 2 !== 0) { return false; }
            $high = false;
            for ($at = 2; $at < strlen($s); $at += 2) {
                $unit = $little ? (ord($s[$at]) | (ord($s[$at + 1]) << 8)) : ((ord($s[$at]) << 8) | ord($s[$at + 1]));
                if ($high) { if ($unit < 56320 || $unit > 57343) { return false; } $decoded .= '?'; $high = false; continue; }
                if ($unit >= 55296 && $unit <= 56319) { $high = true; continue; }
                if ($unit >= 56320 && $unit <= 57343) { return false; }
                $decoded .= $unit < 128 ? chr($unit) : '?';
            }
            if ($high && $complete) { return false; }
            $s = $decoded;
        } else {
            $utf8Bom = substr($s, 0, 3) === "\xef\xbb\xbf";
            if ($utf8Bom) { $s = substr($s, 3); }
            // A bounded probe can end inside the final UTF-8 code point.
            if (!preg_match('//u', $s)) {
                $valid = false;
                if (!$complete) {
                    // Only an unfinished, otherwise legal code point may be removed.
                    for ($n = 1; $n <= 3; ++$n) {
                        $tail = substr($s, -$n); $first = ord($tail[0]);
                        $need = $first >= 194 && $first <= 223 ? 2 : ($first >= 224 && $first <= 239 ? 3 : ($first >= 240 && $first <= 244 ? 4 : 0));
                        if ($need <= $n) { continue; }
                        $padding = str_repeat("\x80", $need - $n);
                        if ($n === 1 && ($first === 224 || $first === 240)) { $padding[0] = $first === 224 ? "\xa0" : "\x90"; }
                        if (preg_match('//u', $tail . $padding) && preg_match('//u', substr($s, 0, -$n))) { $s = substr($s, 0, -$n); $valid = true; break; }
                    }
                }
                if (!$valid) {
                    // Legacy Chinese text has no reliable encoding signature. Validate byte
                    // structure only; do not transcode or override an explicit UTF-8 BOM.
                    $legacy = $utf8Bom ? null : self::gbText($s, $complete);
                    if ($legacy === null) { return false; }
                    $s = $legacy;
                }
            }
        }
        if ($s === '' || preg_match('/[\x00-\x08\x0b\x0e-\x1f\x7f]/', $s)) { return false; }
        $start = ltrim($s, " \t\r\n\x0c");
        if (substr($start, 0, 2) === '#!' || substr($start, 0, 2) === 'MZ' || strpos($s, '<?') !== false || strpos($s, '<%') !== false) { return false; }
        // Never promote active markup to text/plain on a host without libmagic.
        return !preg_match('/<\s*(?:!doctype|!--|\/?(?:html|head|body|script|svg|iframe|object|embed|style|link|meta|title|div|span|p|br|h[1-6]|table|a)\b)/i', $s);
    }

    /** Preserve ASCII for active-markup checks; this is not a character-set converter. */
    private static function gbText(string $s, bool $complete): ?string
    {
        $out = ''; $size = strlen($s);
        for ($at = 0; $at < $size; ++$at) {
            $lead = ord($s[$at]);
            if ($lead < 128) { $out .= $s[$at]; continue; }
            if ($lead === 128) { $out .= '?'; continue; } // Legacy GBK euro sign.
            if ($lead < 129 || $lead > 254) { return null; }
            if ($at + 1 >= $size) { return $complete ? null : $out; }
            $next = ord($s[++$at]);
            if ($next >= 64 && $next <= 254 && $next !== 127) { $out .= '?'; continue; }
            if ($next < 48 || $next > 57) { return null; }
            if ($at + 1 >= $size) { return $complete ? null : $out; }
            $third = ord($s[++$at]); if ($third < 129 || $third > 254) { return null; }
            if ($at + 1 >= $size) { return $complete ? null : $out; }
            $fourth = ord($s[++$at]); if ($fourth < 48 || $fourth > 57) { return null; }
            $pointer = (($lead - 129) * 10 + $next - 48) * 1260 + ($third - 129) * 10 + $fourth - 48;
            if (($pointer > 39419 && $pointer < 189000) || $pointer > 1237575) { return null; }
            $out .= '?';
        }
        return $out;
    }
}
