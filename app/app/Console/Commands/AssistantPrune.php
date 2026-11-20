<?php

namespace App\Console\Commands;

use App\Services\Assistant\AssistantChat;
use Illuminate\Console\Command;

class AssistantPrune extends Command
{
    protected $signature = 'portal:assistant-prune';

    protected $description = 'Borra las conversaciones del asistente sin actividad en los últimos 30 días';

    public function handle(AssistantChat $chat): int
    {
        $this->info('Conversaciones borradas: '.$chat->prune().'.');

        return self::SUCCESS;
    }
}
