<?php

namespace App\Support;

/**
 * Private images that the app IS allowed to re-encode.
 *
 * Same disk and same access rules as PrivateFile — nothing here is reachable
 * without a controller authorising the caller first. The only difference is
 * that images are downscaled and converted to WebP on the way in.
 *
 * This exists as a separate class rather than a flag on PrivateFile because
 * of what PrivateFile also holds: student submissions. Those must come back
 * byte-for-byte. A student photographing handwritten homework at 8 MP would
 * otherwise be downscaled to IMAGE_MAX_WIDTH and re-encoded lossily — on work
 * that gets graded, and that a teacher may need to read closely.
 *
 * Today that distinction happens to hold anyway, because submissions call
 * storeAs() which never compresses. That is an accident of which method each
 * caller reaches for, not a guarantee. Splitting the classes makes it one.
 *
 * Use for: content the school produced and stores privately — announcement
 * images, and anything similar added later. Not for anything a user submitted
 * as evidence of their own work.
 */
class PrivateImage extends PrivateFile
{
    protected static bool $compressImages = true;
}
