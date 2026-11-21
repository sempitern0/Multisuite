<?php

namespace App\Services\Assistant;

use App\Models\AssistantProvider;
use App\Models\AssistantSetting;
use App\Models\User;
use App\Models\UserFile;
use App\Services\Files\FileText;
use App\Services\Files\PersonalFiles;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\AiManager;
use Laravel\Ai\Embeddings;
use RuntimeException;
use Throwable;

/**
 * Search by meaning over a person's own files (MCP-07, docs/MCP.md). Only for people who
 * accepted that the assistant reads their files (D3), when the owner turns it on and an
 * embeddings-capable provider exists. Text is stored encrypted with APP_KEY; vectors are
 * compared in PHP (no database extension), which is enough for a household and capped.
 */
class SemanticIndex
{
    /** Drivers whose laravel/ai provider can create embeddings. */
    public const EMBEDDING_DRIVERS = ['openai', 'gemini', 'mistral', 'openrouter'];

    public const DIMENSIONS = 768;

    public const MAX_FILES_PER_PERSON = 2000;

    public const MAX_CHUNKS_PER_PERSON = 6000;

    private const MAX_TEXT_BYTES = 262144;

    private const MAX_CHUNKS_PER_FILE = 120;

    private const CHUNK_CHARS = 1500;

    private const OVERLAP_CHARS = 200;

    private const BATCH = 32;

    public function __construct(
        private AssistantAccess $access,
        private PersonalFiles $files,
        private FileText $text,
        private AiManager $ai,
    ) {}

    /** Reason from AssistantAccess::DENIALS, 'semantic_off' or 'semantic_provider', or null when available. */
    public function denial(User $user): ?string
    {
        if (! AssistantSetting::current()->semantic_enabled) {
            return 'semantic_off';
        }

        return $this->access->contentDenial($user) ?? ($this->provider() === null ? 'semantic_provider' : null);
    }

    /**
     * The first active provider that can create embeddings, built for laravel/ai.
     *
     * @return array{name: string, model: string, key: string}|null
     */
    public function provider(): ?array
    {
        $provider = AssistantProvider::where('enabled', true)->whereIn('driver', self::EMBEDDING_DRIVERS)
            ->orderBy('position')->orderBy('id')->first();
        if ($provider === null) {
            return null;
        }
        $name = $provider->runtimeName().'-embeddings';
        $this->ai->build(['name' => $name, 'driver' => $provider->driver, 'key' => $provider->api_key]);
        $model = $this->ai->embeddingProvider($name)->defaultEmbeddingsModel();

        // Vectors from another provider or model are not comparable: the key marks them.
        return ['name' => $name, 'model' => $model, 'key' => $provider->driver.':'.$model.':'.self::DIMENSIONS];
    }

    /**
     * Brings one person's index up to date: new or changed files are added, removed or
     * trashed ones dropped. Returns how many files were (re)indexed in this pass.
     */
    public function sync(User $user, int $maxFiles = 40): int
    {
        if ($this->denial($user) !== null) {
            $this->forget($user);

            return 0;
        }
        $provider = $this->provider() ?? throw new RuntimeException('semantic_provider');
        $current = $this->files->current($user, self::MAX_FILES_PER_PERSON)
            ->filter(fn (UserFile $file): bool => FileText::supports($file->mime));
        $currentIds = $current->pluck('id')->all();

        // Drop what is no longer a current file, or was indexed with another model.
        $stale = DB::table('assistant_indexed_files')->where('user_id', $user->id)
            ->where(fn ($query) => $query->whereNotIn('user_file_id', $currentIds === [] ? [0] : $currentIds)->orWhere('model', '!=', $provider['key']))
            ->pluck('user_file_id')->map(fn ($id): int => (int) $id)->values()->all();
        $this->dropFiles($stale);

        $indexed = DB::table('assistant_indexed_files')->where('user_id', $user->id)->pluck('upload_id', 'user_file_id')->all();
        $pending = $current->filter(fn (UserFile $file): bool => ($indexed[$file->id] ?? null) !== $file->upload_id)->take($maxFiles);
        $done = 0;
        foreach ($pending as $file) {
            if ($this->chunkCount($user, $provider['key']) >= self::MAX_CHUNKS_PER_PERSON) {
                break;
            }
            try {
                $this->indexFile($user, $file, $provider);
                $done++;
            } catch (RuntimeException $exception) {
                // A file that cannot be read (protected PDF, images only) is recorded with no chunks.
                $this->dropFiles([$file->id]);
                $this->markIndexed($user, $file, $provider['key'], 0);
                Log::info('Assistant index skipped a file.', ['reason' => $exception::class]);
            }
        }

        return $done;
    }

    /**
     * Passages of the person's current files closest in meaning to $query.
     *
     * @return list<array{file_id: int, name: string, folder: string, excerpt: string, score: float}>
     */
    public function search(User $user, string $query, int $limit = 8): array
    {
        $denial = $this->denial($user);
        if ($denial !== null) {
            throw new RuntimeException($denial);
        }
        $provider = $this->provider() ?? throw new RuntimeException('semantic_provider');
        $vector = $this->embed([$query], $provider, $user)[0];

        $scored = [];
        // Join on current files: a trashed file disappears from results at once, before the next pass.
        $rows = DB::table('assistant_chunks')->join('user_files', 'user_files.id', '=', 'assistant_chunks.user_file_id')
            ->where('assistant_chunks.user_id', $user->id)->where('user_files.user_id', $user->id)
            ->where('assistant_chunks.model', $provider['key'])
            ->whereNull('user_files.trashed_at')->whereNull('user_files.version_of_id')
            ->select(['assistant_chunks.id', 'assistant_chunks.user_file_id', 'assistant_chunks.embedding'])->cursor();
        foreach ($rows as $row) {
            $scored[] = [(int) $row->id, (int) $row->user_file_id, self::cosine($vector, self::unpack($row->embedding))];
        }
        usort($scored, fn (array $a, array $b): int => $b[2] <=> $a[2]);

        $results = [];
        $paths = $this->files->folderPaths($user);
        $perFile = [];
        foreach ($scored as [$chunkId, $fileId, $score]) {
            if (count($results) >= $limit) {
                break;
            }
            if (($perFile[$fileId] ?? 0) >= 2) {
                continue;
            }
            $perFile[$fileId] = ($perFile[$fileId] ?? 0) + 1;
            $file = UserFile::where('user_id', $user->id)->find($fileId);
            $content = DB::table('assistant_chunks')->where('id', $chunkId)->value('content');
            if ($file === null || ! is_string($content)) {
                continue;
            }
            $results[] = [
                'file_id' => $file->id,
                'name' => $file->name,
                'folder' => $file->folder_id === null ? 'Mis archivos' : ($paths[(int) $file->folder_id] ?? 'Mis archivos'),
                'excerpt' => mb_strimwidth(Crypt::decryptString($content), 0, 600, '…'),
                'score' => round($score, 3),
            ];
        }

        return $results;
    }

    /** Removes everything indexed for a person (consent withdrawn, feature off, account gone). */
    public function forget(User $user): void
    {
        DB::transaction(function () use ($user): void {
            DB::table('assistant_chunks')->where('user_id', $user->id)->delete();
            DB::table('assistant_indexed_files')->where('user_id', $user->id)->delete();
        });
    }

    /** @return array{files: int, chunks: int} */
    public function stats(User $user): array
    {
        return [
            'files' => DB::table('assistant_indexed_files')->where('user_id', $user->id)->where('chunks', '>', 0)->count(),
            'chunks' => DB::table('assistant_chunks')->where('user_id', $user->id)->count(),
        ];
    }

    /** @param array{name: string, model: string, key: string} $provider */
    private function indexFile(User $user, UserFile $file, array $provider): void
    {
        $chunks = self::chunks($this->text->upTo($user, $file, self::MAX_TEXT_BYTES));
        $vectors = [];
        foreach (array_chunk($chunks, self::BATCH) as $batch) {
            array_push($vectors, ...$this->embed($batch, $provider, $user));
        }
        DB::transaction(function () use ($user, $file, $provider, $chunks, $vectors): void {
            $this->dropFiles([$file->id]);
            foreach ($chunks as $position => $chunk) {
                DB::table('assistant_chunks')->insert([
                    'user_id' => $user->id,
                    'user_file_id' => $file->id,
                    'position' => $position,
                    'content' => Crypt::encryptString($chunk),
                    'embedding' => self::pack($vectors[$position]),
                    'model' => $provider['key'],
                ]);
            }
            $this->markIndexed($user, $file, $provider['key'], count($chunks));
        });
    }

    /**
     * @param  list<string>  $inputs
     * @param  array{name: string, model: string, key: string}  $provider
     * @return list<list<float>>
     */
    private function embed(array $inputs, array $provider, User $user): array
    {
        try {
            $response = Embeddings::for($inputs)->dimensions(self::DIMENSIONS)->timeout(60)->generate([$provider['name'] => $provider['model']]);
        } catch (Throwable $exception) {
            Log::warning('Assistant embeddings failed.', ['exception' => $exception::class]);
            throw new RuntimeException('semantic_unavailable');
        }
        app(AssistantChat::class)->recordTokens($user, $response->usage->inputTokens, 0);

        return array_values(array_map(fn (array $vector): array => array_values(array_map('floatval', $vector)), $response->embeddings));
    }

    /** @return list<string> */
    private static function chunks(string $text): array
    {
        $text = trim((string) preg_replace('/[ \t]+/u', ' ', $text));
        if ($text === '') {
            return [];
        }
        $chunks = [];
        $length = mb_strlen($text);
        for ($start = 0; $start < $length && count($chunks) < self::MAX_CHUNKS_PER_FILE; $start += self::CHUNK_CHARS - self::OVERLAP_CHARS) {
            $chunks[] = mb_substr($text, $start, self::CHUNK_CHARS);
        }

        return $chunks;
    }

    /** @param list<int> $fileIds */
    private function dropFiles(array $fileIds): void
    {
        if ($fileIds === []) {
            return;
        }
        DB::table('assistant_chunks')->whereIn('user_file_id', $fileIds)->delete();
        DB::table('assistant_indexed_files')->whereIn('user_file_id', $fileIds)->delete();
    }

    private function markIndexed(User $user, UserFile $file, string $model, int $chunks): void
    {
        DB::table('assistant_indexed_files')->insert([
            'user_file_id' => $file->id, 'user_id' => $user->id, 'upload_id' => $file->upload_id,
            'model' => $model, 'chunks' => $chunks, 'indexed_at' => now(),
        ]);
    }

    private function chunkCount(User $user, string $model): int
    {
        return DB::table('assistant_chunks')->where('user_id', $user->id)->where('model', $model)->count();
    }

    /** @param list<float> $vector */
    private static function pack(array $vector): string
    {
        return base64_encode(pack('g*', ...$vector));
    }

    /** @return list<float> */
    private static function unpack(mixed $binary): array
    {
        $binary = is_string($binary) ? base64_decode($binary, true) : false;
        $values = is_string($binary) && $binary !== '' ? unpack('g*', $binary) : false;

        return $values === false ? [] : array_values($values);
    }

    /**
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    private static function cosine(array $a, array $b): float
    {
        $count = min(count($a), count($b));
        $dot = $normA = $normB = 0.0;
        for ($i = 0; $i < $count; $i++) {
            $dot += $a[$i] * $b[$i];
            $normA += $a[$i] * $a[$i];
            $normB += $b[$i] * $b[$i];
        }

        return $normA > 0 && $normB > 0 ? $dot / (sqrt($normA) * sqrt($normB)) : 0.0;
    }
}
