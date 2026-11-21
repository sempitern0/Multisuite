<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Assistant\SemanticIndex;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AssistantIndex extends Command
{
    protected $signature = 'portal:assistant-index {--user= : Solo esta persona (id)}';

    protected $description = 'Actualiza la búsqueda por significado del asistente (solo personas que lo aceptaron)';

    public function handle(SemanticIndex $index): int
    {
        // People with something indexed are visited too, so withdrawals and switches are cleaned up.
        $ids = $this->option('user') !== null ? [(int) $this->option('user')]
            : DB::table('assistant_consents')->pluck('user_id')->merge(DB::table('assistant_indexed_files')->distinct()->pluck('user_id'))->unique()->all();
        $files = 0;
        foreach (User::whereIn('id', $ids)->get() as $user) {
            try {
                $files += $index->sync($user);
            } catch (RuntimeException $exception) {
                $this->warn('Persona '.$user->id.': '.$exception->getMessage());
            }
        }
        $this->info('Archivos preparados: '.$files.'.');

        return self::SUCCESS;
    }
}
