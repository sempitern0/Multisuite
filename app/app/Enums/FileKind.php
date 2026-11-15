<?php

namespace App\Enums;

use Illuminate\Database\Eloquent\Builder;

/**
 * What kind of file an entry is, from its verified MIME type. The single owner of the
 * classification: the filter (SQL), the type column, labels and icons all come from here,
 * so a new kind is added in one place.
 */
enum FileKind: string
{
    case Image = 'image';
    case Video = 'video';
    case Audio = 'audio';
    case Document = 'document';
    case Other = 'other';

    public static function fromMime(?string $mime): self
    {
        $mime = (string) $mime;
        foreach ([self::Image, self::Video, self::Audio, self::Document] as $kind) {
            if (in_array($mime, $kind->exactMimes(), true)) {
                return $kind;
            }
            foreach ($kind->prefixes() as $prefix) {
                if (str_starts_with($mime, $prefix)) {
                    return $kind;
                }
            }
        }

        return self::Other;
    }

    /** Filter options: value => plural label. @return array<string, string> */
    public static function options(): array
    {
        return array_column(array_map(fn (self $kind): array => [$kind->value, $kind->plural()], self::cases()), 1, 0);
    }

    public function plural(): string
    {
        return match ($this) {
            self::Image => 'Imágenes',
            self::Video => 'Vídeos',
            self::Audio => 'Música y audio',
            self::Document => 'Documentos',
            self::Other => 'Otros',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Image => 'Imagen',
            self::Video => 'Vídeo',
            self::Audio => 'Audio',
            self::Document => 'Documento',
            self::Other => 'Archivo',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Image => 'image',
            self::Video => 'video',
            self::Audio => 'music',
            self::Document, self::Other => 'document',
        };
    }

    /** Previewable as a thumbnail in the gallery. */
    public function hasThumbnail(): bool
    {
        return $this === self::Image || $this === self::Video;
    }

    /**
     * Restricts a query joined with private_uploads to this kind, matching fromMime().
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     */
    public function scope(Builder $query, string $column = 'private_uploads.mime'): void
    {
        if ($this === self::Other) {
            foreach ([self::Image, self::Video, self::Audio, self::Document] as $kind) {
                $query->whereNot(fn ($q) => $kind->matching($q, $column));
            }

            return;
        }
        $query->where(fn ($q) => $this->matching($q, $column));
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     */
    private function matching(Builder $query, string $column): void
    {
        foreach ($this->prefixes() as $prefix) {
            $query->orWhere($column, 'like', $prefix.'%');
        }
        if ($this->exactMimes() !== []) {
            $query->orWhereIn($column, $this->exactMimes());
        }
    }

    /** @return list<string> */
    private function prefixes(): array
    {
        return match ($this) {
            self::Image => ['image/'],
            self::Video => ['video/'],
            self::Audio => ['audio/'],
            self::Document => ['text/'],
            self::Other => [],
        };
    }

    /** @return list<string> */
    private function exactMimes(): array
    {
        return match ($this) {
            self::Audio => ['application/ogg'],
            self::Document => array_values(array_filter((array) config('nas.allowed_mimes', []), fn ($mime): bool => is_string($mime) && (
                str_starts_with($mime, 'application/pdf') || str_starts_with($mime, 'application/json') || str_starts_with($mime, 'application/epub')
                || str_contains($mime, 'officedocument') || str_contains($mime, 'opendocument')
                || str_starts_with($mime, 'application/vnd.ms-') || $mime === 'application/msword'))),
            default => [],
        };
    }
}
