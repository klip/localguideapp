<?php

/**
 * Profile photos travel as `data:image/(png|jpeg|webp);base64,...` strings
 * (ImageCropper.vue's output, around 480x480 JPEG) and land in `users.image`
 * as they are — so this is the one guard against something huge or
 * not-actually-an-image getting stored. Used by the auth service (register)
 * and the API (updateAccount).
 */
final class ImageData
{
    /** A few hundred KB is typical; this is generous headroom. */
    private const MAX_BASE64_LENGTH = 2_000_000;

    public static function validate(string $image): string
    {
        if ($image === '') {
            return '';
        }

        if (strlen($image) > self::MAX_BASE64_LENGTH) {
            throw new RuntimeException('Image is too large.');
        }

        if (!preg_match('/^data:image\/(png|jpe?g|webp);base64,[A-Za-z0-9+\/]+=*$/', $image)) {
            throw new RuntimeException('Image must be a PNG, JPEG or WebP data URL.');
        }

        return $image;
    }
}
