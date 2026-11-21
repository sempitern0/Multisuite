<?php

namespace App\Services\Assistant;

use App\Models\AssistantProvider;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\AiManager;
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use Laravel\Ai\Exceptions\InvalidApiKeyException;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Throwable;

/**
 * External AI providers for the assistant (decisions D2 and D3). Only drivers whose endpoint
 * is fixed by laravel/ai: no address is ever taken from the page, so there is no SSRF door.
 */
class AssistantProviders
{
    public function __construct(private AiManager $ai) {}

    /** @var array<string, array{label: string, model: string}> driver => label and suggested model */
    public const DRIVERS = [
        'anthropic' => ['label' => 'Anthropic (Claude)', 'model' => 'claude-sonnet-5-5'],
        'openai' => ['label' => 'OpenAI', 'model' => 'gpt-6.1-sol'],
        'gemini' => ['label' => 'Google Gemini', 'model' => 'gemini-3.8-flash'],
        'mistral' => ['label' => 'Mistral', 'model' => 'mistral-large-4-0'],
        'deepseek' => ['label' => 'DeepSeek', 'model' => 'deepseek-flash'],
        'groq' => ['label' => 'Groq', 'model' => 'openai/gpt-oss-120b'],
        'xai' => ['label' => 'xAI (Grok)', 'model' => 'grok-4.7'],
        'openrouter' => ['label' => 'OpenRouter', 'model' => 'anthropic/claude-sonnet-5.5'],
    ];

    /** @var array<string, string> */
    public const TEST_RESULTS = [
        'ok' => 'Conexión correcta: el proveedor ha respondido.',
        'key' => 'El proveedor rechaza la clave. Comprueba que esté bien copiada y activa.',
        'limit' => 'El proveedor ha rechazado la petición por límite de uso o saldo. Revisa tu cuenta en el proveedor.',
        'busy' => 'El proveedor está saturado ahora mismo. Prueba de nuevo en unos minutos.',
        'connection' => 'No se ha podido conectar con el proveedor. Comprueba la conexión a Internet del servidor.',
        'error' => 'El proveedor ha respondido con un error. Comprueba el nombre del modelo.',
    ];

    /**
     * Enabled providers in failover order, built for laravel/ai: runtime name => model.
     *
     * @return array<string, string>
     */
    public function chain(): array
    {
        $chain = [];
        foreach (AssistantProvider::where('enabled', true)->orderBy('position')->orderBy('id')->get() as $provider) {
            $chain[$this->build($provider)] = $provider->model;
        }

        return $chain;
    }

    /**
     * Sends a minimal request with this provider only. Returns a key of TEST_RESULTS and the
     * HTTP status the provider answered with, when there is one (shown to the owner to diagnose).
     *
     * @return array{result: string, status: int|null}
     */
    public function test(AssistantProvider $provider): array
    {
        try {
            (new AnonymousAgent('Responde únicamente con la palabra OK.', [], []))
                ->prompt('OK', provider: [$this->build($provider) => $provider->model], timeout: 20);

            return ['result' => 'ok', 'status' => null];
        } catch (Throwable $exception) {
            $result = match (true) {
                $exception instanceof InvalidApiKeyException => 'key',
                $exception instanceof RateLimitedException, $exception instanceof InsufficientCreditsException => 'limit',
                $exception instanceof ProviderOverloadedException => 'busy',
                $exception instanceof ProviderConnectionException => 'connection',
                default => 'error',
            };
            $status = self::statusOf($exception);
            // Type and status only: the remote message may echo the request.
            Log::info('Assistant provider test failed.', ['driver' => $provider->driver, 'result' => $result, 'status' => $status, 'exception' => $exception::class]);

            return ['result' => $result, 'status' => $status];
        }
    }

    /** HTTP status behind a provider exception, looking through wrapped exceptions. */
    public static function statusOf(Throwable $exception): ?int
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof RequestException && $current->response !== null) {
                return $current->response->status();
            }
        }

        return null;
    }

    private function build(AssistantProvider $provider): string
    {
        $name = $provider->runtimeName();
        $this->ai->build(['name' => $name, 'driver' => $provider->driver, 'key' => $provider->api_key]);

        return $name;
    }
}
