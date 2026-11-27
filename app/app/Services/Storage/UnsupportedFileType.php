<?php

namespace App\Services\Storage;

use App\Services\Files\ConnectedFolders;
use RuntimeException;

/**
 * The detected type is not accepted where the file is going. Carries a message for the person:
 * a video format Jellyfin plays but the browser cannot is accepted only in the «Vídeos» folder.
 */
final class UnsupportedFileType extends RuntimeException
{
    public function __construct(public readonly string $mime)
    {
        parent::__construct(in_array($mime, ConnectedFolders::definition('videos')['mimes'], true)
            ? 'Este formato de vídeo solo se admite en la carpeta Vídeos, donde lo reproduce Vídeo. Súbelo allí o conviértelo a MP4.'
            : 'Este tipo de archivo no se admite. Se aceptan fotos, vídeo MP4, WebM o MOV, música, PDF, texto, libros y documentos de oficina.');
    }
}
