<?php

namespace App\Support\Attendance;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;

/**
 * A QR station's code as the screen draws it (ADR 0103): the grid of dark and
 * light modules, worked out on the server with the library the two-factor
 * setup already uses, and sent as a bitmap of a couple of hundred bytes — the
 * screen draws it itself, so a few minutes of codes cost less to send than one
 * picture of a code would.
 *
 * Error correction M, which a phone reads off a screen across a room.
 */
final class QrImage
{
    /**
     * The modules, row by row from the top left, eight to a byte (most
     * significant bit first), base64-encoded; `size` modules a side.
     *
     * @return array{size: int, bits: string}
     */
    public static function matrix(string $payload): array
    {
        $matrix = Encoder::encode($payload, ErrorCorrectionLevel::M(), Encoder::DEFAULT_BYTE_MODE_ENCODING, null, false)->getMatrix();
        $size = $matrix->getWidth();

        $bytes = '';
        $byte = 0;
        $count = 0;

        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                $byte = ($byte << 1) | ($matrix->get($x, $y) === 1 ? 1 : 0);

                if (++$count === 8) {
                    $bytes .= chr($byte);
                    $byte = 0;
                    $count = 0;
                }
            }
        }

        if ($count > 0) {
            $bytes .= chr($byte << (8 - $count));
        }

        return ['size' => $size, 'bits' => base64_encode($bytes)];
    }
}
