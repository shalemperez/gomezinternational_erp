<?php

namespace App\Services\Ecf;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

class QrCodeGenerator
{
    /** @return string `data:image/png;base64,...` */
    public function pngDataUri(string $url): string
    {
        $options = new QROptions([
            'version' => QRCode::VERSION_AUTO,
            'eccLevel' => EccLevel::L,
            'outputType' => QROutputInterface::GDIMAGE_PNG,
            'outputBase64' => true,
            'scale' => 6,
            'quietzoneSize' => 2,
            'imageTransparent' => false,
        ]);

        return (new QRCode($options))->render($url);
    }

    /** Test helper: decodes a PNG back into its text. */
    public function decode(string $pngBinary): string
    {
        return (string) (new QRCode)->readFromBlob($pngBinary);
    }
}
