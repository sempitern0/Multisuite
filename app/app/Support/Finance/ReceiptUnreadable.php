<?php

namespace App\Support\Finance;

use RuntimeException;

/** An expected reading outcome; the message is written for the person and safe to show. */
final class ReceiptUnreadable extends RuntimeException {}
