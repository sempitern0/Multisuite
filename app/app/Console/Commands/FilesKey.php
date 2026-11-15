<?php

namespace App\Console\Commands;

use App\Services\Storage\FileCipher;
use Illuminate\Console\Command;

/**
 * Creates the master key that encrypts private files at rest (NAS_FILES_KEY) and writes it
 * to app/.env. It never replaces an existing key: files encrypted with it would become
 * unreadable. The operator must keep a copy outside the server (docs/STORAGE.md).
 */
class FilesKey extends Command
{
    protected $signature = 'portal:files-key {--show : Muestra una clave nueva sin guardarla}';

    protected $description = 'Crea la clave de cifrado de los archivos privados (NAS_FILES_KEY) si no existe';

    public function handle(): int
    {
        $key = FileCipher::generateKey();
        if ($this->option('show')) {
            $this->line($key);

            return self::SUCCESS;
        }
        if ((string) config('nas.files_key') !== '') {
            $this->info('NAS_FILES_KEY ya existe; no se cambia (los archivos cifrados dejarían de abrirse).');

            return self::SUCCESS;
        }
        $env = base_path('.env');
        $contents = is_file($env) ? (string) file_get_contents($env) : '';
        if (preg_match('/^NAS_FILES_KEY=\S+/m', $contents) === 1) {
            $this->error('app/.env ya tiene NAS_FILES_KEY pero la configuración no la ve (¿config:cache antigua?). Revisa antes de continuar.');

            return self::FAILURE;
        }
        $contents = preg_match('/^NAS_FILES_KEY=$/m', $contents) === 1
            ? (string) preg_replace('/^NAS_FILES_KEY=$/m', 'NAS_FILES_KEY='.$key, $contents)
            : rtrim($contents, "\n")."\n\n# Encrypts private files at rest. Keep a copy OUTSIDE the server: without it the files cannot be read.\nNAS_FILES_KEY=".$key."\n";
        if (file_put_contents($env, $contents) === false) {
            $this->error('No se pudo escribir app/.env.');

            return self::FAILURE;
        }
        config(['nas.files_key' => $key]);
        $this->warn('Clave de cifrado de archivos creada en app/.env. Guarda una copia fuera del servidor: sin ella los archivos no se pueden abrir.');

        return self::SUCCESS;
    }
}
