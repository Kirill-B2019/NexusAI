<?php

namespace App\Http\Controllers;

use App\Exceptions\NexusApiException;
use App\Services\NexusApiClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TrainingResearchController extends Controller
{
    private const SESSION_KEY = 'research_drafts';

    public function draft(Request $request, NexusApiClient $nexus): RedirectResponse
    {
        $validated = $request->validate([
            'expert' => ['required', 'string', 'max:100'],
            'query' => ['required', 'string', 'min:3', 'max:500'],
            'count' => ['required', 'integer', 'min:1', 'max:10'],
            'topic' => ['nullable', 'string', 'max:100'],
        ]);

        try {
            $result = $nexus->draftTrainingExamples(
                $validated['expert'],
                $validated['query'],
                (int) $validated['count'],
            );
        } catch (NexusApiException $exception) {
            Log::warning('NEXUS AI could not draft training examples.', [
                'status' => $exception->statusCode,
                'configuration_error' => $exception->configurationError,
            ]);

            return back()->withInput()->withErrors([
                'research' => match ($exception->statusCode) {
                    404 => 'Выбранный эксперт больше недоступен.',
                    422 => 'Поиск не нашёл источников по этому запросу. Уточните формулировку.',
                    502 => 'Эксперт не смог составить пары — повторите позже.',
                    503 => 'Веб-поиск не настроен или недоступен. Обратитесь к администратору сервера.',
                    default => 'Не удалось собрать черновики через NEXUS AI.',
                },
            ]);
        }

        $topic = trim((string) ($validated['topic'] ?? ''));
        $result['topic'] = $topic !== '' ? $topic : null;

        $request->session()->put(self::SESSION_KEY, $result);

        return back()->with('status', 'training_research_drafted');
    }

    public function store(Request $request, NexusApiClient $nexus): RedirectResponse
    {
        $validated = $request->validate([
            'expert' => ['required', 'string', 'max:100'],
            'drafts' => ['required', 'array', 'min:1', 'max:10'],
            'drafts.*.question' => ['required', 'string', 'max:10000'],
            'drafts.*.answer' => ['required', 'string', 'max:100000'],
            'drafts.*.source_url' => ['nullable', 'string', 'max:2000'],
            'accepted' => ['required', 'array', 'min:1', 'max:10'],
            'accepted.*' => ['integer', 'min:0', 'max:9'],
        ]);

        $acceptedDrafts = array_values(array_intersect_key(
            $validated['drafts'],
            array_flip(array_map('intval', $validated['accepted'])),
        ));
        if ($acceptedDrafts === []) {
            return back()->withErrors([
                'research' => 'Отметьте хотя бы одну пару, которую принимаете.',
            ]);
        }

        $drafts = $request->session()->get(self::SESSION_KEY);
        $query = is_array($drafts) ? ($drafts['query'] ?? null) : null;
        $topic = is_array($drafts) ? ($drafts['topic'] ?? null) : null;
        $jsonl = $this->toJsonl(
            $validated['expert'],
            $acceptedDrafts,
            is_string($query) ? $query : null,
            is_string($topic) ? $topic : null,
        );

        $path = tempnam(sys_get_temp_dir(), 'nexus-research-');
        if ($path === false) {
            return back()->withErrors([
                'research' => 'Не удалось подготовить файл черновиков.',
            ]);
        }
        $filePath = $path.'.jsonl';
        rename($path, $filePath);
        file_put_contents($filePath, $jsonl);

        try {
            $batch = $nexus->createTrainingDatasetBatch(
                $validated['expert'],
                $filePath,
                'research-'.$validated['expert'].'.jsonl',
            );
        } catch (NexusApiException $exception) {
            Log::warning('NEXUS AI could not import researched drafts.', [
                'status' => $exception->statusCode,
                'configuration_error' => $exception->configurationError,
            ]);

            return back()->withErrors([
                'research' => match ($exception->statusCode) {
                    404 => 'Выбранный эксперт больше недоступен.',
                    413 => 'Черновики превышают ограничение файла.',
                    422 => 'Проверьте черновики: вопрос и ответ не должны быть пустыми.',
                    default => 'Не удалось сохранить черновики как партию в NEXUS AI.',
                },
            ]);
        } finally {
            if (is_file($filePath)) {
                @unlink($filePath);
            }
        }

        $request->session()->forget(self::SESSION_KEY);

        $batchId = (string) $batch['id'];
        $status = $batch['status'] ?? null;

        if ($status === 'rejected') {
            return redirect()
                ->route('admin.training-datasets', ['batch' => $batchId])
                ->withErrors([
                    'research' => 'Эта партия уже отклонена. Составьте новые черновики.',
                ]);
        }

        $alreadyApproved = $status === 'approved';

        if (! $alreadyApproved) {
            $needsReview = (int) ($batch['invalid'] ?? 0) > 0
                || (int) ($batch['duplicates'] ?? 0) > 0;

            if ($needsReview) {
                return redirect()
                    ->route('admin.training-datasets', ['batch' => $batchId])
                    ->with('status', 'training_research_saved_for_review');
            }

            try {
                $nexus->approveTrainingDatasetBatch($batchId, true);
            } catch (NexusApiException $exception) {
                Log::warning('NEXUS AI could not approve researched drafts.', [
                    'status' => $exception->statusCode,
                    'configuration_error' => $exception->configurationError,
                ]);

                return redirect()
                    ->route('admin.training-datasets', ['batch' => $batchId])
                    ->withErrors([
                        'research' => $exception->statusCode === 409
                            ? 'Пары сохранены, но партию нельзя утвердить автоматически. Проверьте строки и утвердите её вручную.'
                            : 'Пары сохранены, но утвердить партию не удалось. Утвердите её вручную.',
                    ]);
            }
        }

        // Принятые пары утверждены — собираем новый снимок, из которого
        // обучается эксперт (экспорт берёт самый свежий release).
        try {
            $release = $nexus->createTrainingDatasetRelease($validated['expert']);
        } catch (NexusApiException $exception) {
            Log::warning('NEXUS AI could not release researched drafts.', [
                'status' => $exception->statusCode,
                'configuration_error' => $exception->configurationError,
            ]);

            return redirect()
                ->route('admin.training-datasets', ['batch' => $batchId])
                ->with('status', 'training_research_approved_no_release');
        }

        return redirect()
            ->route('admin.training-datasets', [
                'batch' => $batchId,
                'expert' => $validated['expert'],
            ])
            ->with('status', $alreadyApproved
                ? 'training_research_already_approved'
                : 'training_research_accepted')
            ->with('release_example_count', $release['example_count'] ?? null);
    }

    public function cancel(Request $request): RedirectResponse
    {
        $request->session()->forget(self::SESSION_KEY);

        return back()->with('status', 'training_research_cancelled');
    }

    /**
     * @param  array<int, array<string, mixed>>  $drafts
     */
    private function toJsonl(string $expert, array $drafts, ?string $query, ?string $topic = null): string
    {
        $lines = '';
        foreach ($drafts as $draft) {
            $record = [
                'question' => trim((string) $draft['question']),
                'answer' => trim((string) $draft['answer']),
                'expert' => $expert,
                'source' => 'web_research',
            ];

            $sourceUrl = trim((string) ($draft['source_url'] ?? ''));
            if ($sourceUrl !== '') {
                $record['source_url'] = $sourceUrl;
            }
            if ($query !== null && $query !== '') {
                $record['search_query'] = $query;
            }
            if ($topic !== null && $topic !== '') {
                $record['topic'] = $topic;
            }

            $lines .= json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
        }

        return $lines;
    }
}
