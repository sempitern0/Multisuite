<?php

namespace Tests\Unit;

use App\Enums\FileKind;
use Tests\TestCase;

class FileKindTest extends TestCase
{
    public function test_classifies_verified_mime_types_in_one_place(): void
    {
        $this->assertSame(FileKind::Image, FileKind::fromMime('image/png'));
        $this->assertSame(FileKind::Video, FileKind::fromMime('video/quicktime'));
        $this->assertSame(FileKind::Audio, FileKind::fromMime('application/ogg'));
        $this->assertSame('music', FileKind::fromMime('application/ogg')->icon());
        $this->assertSame(FileKind::Document, FileKind::fromMime('text/plain'));
        $this->assertSame(FileKind::Document, FileKind::fromMime('application/pdf'));
        $this->assertSame(FileKind::Other, FileKind::fromMime('application/zip'));
        $this->assertSame(FileKind::Other, FileKind::fromMime(null));
        $this->assertTrue(FileKind::Video->hasThumbnail());
        $this->assertFalse(FileKind::Audio->hasThumbnail());
        $this->assertSame(['image', 'video', 'audio', 'document', 'other'], array_keys(FileKind::options()));
    }
}
