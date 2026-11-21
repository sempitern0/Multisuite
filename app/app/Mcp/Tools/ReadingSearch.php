<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\SearchesServiceCatalog;
use App\Models\User;
use App\Services\KomgaAccounts;
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

#[Name('reading_search')]
#[Title('Buscar en Lectura')]
#[Description('Busca series y libros (novelas, cómics, manga, documentación) por nombre en lo que la persona puede leer en Komga. Para leer, que abra Lectura desde la portada de Multisuite.')]
#[IsReadOnly]
#[IsIdempotent]
class ReadingSearch extends AssistantTool
{
    use SearchesServiceCatalog;

    public function __construct(private KomgaAccounts $komga) {}

    public function schema(JsonSchema $schema): array
    {
        return ['query' => $schema->string()->min(2)->max(100)->required()->description('Parte del título de la serie o el libro.')];
    }

    protected function run(User $user, Request $request): Response|ResponseFactory
    {
        $input = $request->validate(['query' => ['required', 'string', 'min:2', 'max:100']]);
        $name = (string) config('portal.services.komga.name', 'Lectura');
        try {
            // Komga's own library sharing decides what this person can see.
            $results = $this->komga->searchFor($this->readyAccount($user, 'komga', $name), (string) $input['query']);
        } catch (RuntimeException $exception) {
            return Response::error(self::serviceError($exception, $name));
        }

        return Response::structured(['query' => $input['query'], ...$results]);
    }
}
