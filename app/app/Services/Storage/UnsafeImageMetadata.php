<?php

namespace App\Services\Storage;

use RuntimeException;

/** A picture whose private metadata could not be removed: it is not stored. */
class UnsafeImageMetadata extends RuntimeException {}
