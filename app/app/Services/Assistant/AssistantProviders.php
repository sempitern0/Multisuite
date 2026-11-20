<?php

namespace App\Services\Assistant;

use App\Models\AssistantProvider;
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

    /** Sends a minimal request with this provider only; returns a key of TEST_RESULTS. */
    public function test(AssistantProvider $provider): string
    {
        try {
            (new AnonymousAgent('Responde únicamente con la palabra OK.', [], []))
                ->prompt('OK', provider: [$this->build($provider) => $provider->model], timeout: 20);

            return 'ok';
        } catch (InvalidApiKeyException) {
            return 'key';
        } catch (RateLimitedException|InsufficientCreditsException) {
            return 'limit';
        } catch (ProviderOverloadedException) {
            return 'busy';
        } catch (ProviderConnectionException) {
            return 'connection';
        } catch (Throwable) {
            // The remote answer is never shown or logged: it may echo the request.
            return 'error';
        }
    }

    private function build(AssistantProvider $provider): string
    {
        $name = $provider->runtimeName();
        $this->ai->build(['name' => $name, 'driver' => $provider->driver, 'key' => $provider->api_key]);

        return $name;
    }
}
