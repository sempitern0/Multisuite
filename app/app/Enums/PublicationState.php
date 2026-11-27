<?php

namespace App\Enums;

/** publications.state (docs/STORAGE.md § Publicación para todos). Withdrawn or rejected rows are deleted. */
enum PublicationState: string
{
    /** The contributor asked to publish it; nothing changed yet. */
    case Requested = 'requested';
    /** An administrator published it: linked in the common library of the service, bytes in the common fund. */
    case Published = 'published';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
