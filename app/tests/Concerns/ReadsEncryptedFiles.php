<?php

namespace Tests\Concerns;

use App\Services\Storage\FileCipher;

/** Files are encrypted at rest in the suite (phpunit.xml sets NAS_FILES_KEY): read them back as plaintext. */
trait ReadsEncryptedFiles
{
    protected function decrypted(string $path, string $context): string
    {
        $cipher = FileCipher::fromConfig();
        $this->assertNotNull($cipher);

        return $cipher->readPrefix($path, $context, PHP_INT_MAX);
    }
}
