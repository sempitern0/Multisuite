<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\SearchesServiceCatalog;
use App\Models\User;
use App\Services\NavidromeAccounts;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use RuntimeException;

#[Name('music_search')]
#[Title('Buscar en Música')]
#[Description('Busca álbumes y canciones por nombre en la música que la persona puede escuchar en Navidrome (bibliotecas comunes y la suya). Para escuchar, que abra Música desde la portada de Multisuite.')]
#[IsReadOnly]
#[IsIdempotent]
class MusicSearch extends AssistantTool
{
    use SearchesServiceCatalog;

    public function __construct(private NavidromeAccounts $navidrome) {}

    public function schema(JsonSchema $schema): array
    {
        return ['query' => $schema->string()->min(2)->max(100)->required()->description('Parte del nombre del álbum o la canción.')];
    }

    protected function run(User $user, Request $request): Response|ResponseFactory
    {
        $input = $request->validate(['query' => ['required', 'string', 'min:2', 'max:100']]);
        $name = (string) config('portal.services.music.name', 'Música');
        try {
            // Navidrome's own library assignments decide what this person can see.
            $results = $this->navidrome->searchFor($this->readyAccount($user, 'music', $name), (string) $input['query']);
        } catch (RuntimeException $exception) {
            return Response::error(self::serviceError($exception, $name));
        }

        return Response::structured(['query' => $input['query'], ...$results]);
    }
}
