<?php

namespace App\Http\Controllers;

use App\Exceptions\NexusApiException;
use App\Http\Requests\StoreTrainingDatasetBatchRequest;
use App\Services\NexusApiClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class TrainingDatasetController extends Controller
{
    public function index(Request $request, NexusApiClient $nexus): View
    {
        $experts = [];
        $batches = ['batches' => [], 'total' => 0, 'limit' => 50, 'offset' => 0];
        $releases = ['releases' => [], 'total' => 0, 'limit' => 50, 'offset' => 0];
        $adapters = ['adapters' => [], 'total' => 0, 'limit' => 50, 'offset' => 0];
        $selectedBatch = null;
        $stats = null;
        $error = null;
        $selectedExpert = $request->query('expert');
        $selectedStatus = $request->query('status');
        $batchOffset = max(0, (int) $request->query('offset', 0));
        $exampleOffset = max(0, (int) $request->query('example_offset', 0));

        try {
            $experts = $nexus->listExperts();

            $filters = [];
            foreach (['expert', 'status'] as $filter) {
                $value = $request->query($filter);
                if (is_string($value) && $value !== '') {
                    $filters[$filter] = $value;
                }
            }
            $filters['limit'] = 50;
            $filters['offset'] = $batchOffset;
            $batches = $nexus->listTrainingDatasetBatches($filters);

            $releaseFilters = [];
            if (is_string($selectedExpert) && $selectedExpert !== '') {
                $releaseFilters['expert'] = $selectedExpert;
            }
            $releases = $nexus->listTrainingDatasetReleases($releaseFilters);

            $adapterFilters = [];
            if (is_string($selectedExpert) && $selectedExpert !== '') {
                $adapterFilters['expert'] = $selectedExpert;
            }
            $adapters = $nexus->listLoraAdapters($adapterFilters);

            if (is_string($selectedExpert) && $selectedExpert !== '') {
                try {
                    $stats = $nexus->trainingDatasetStats($selectedExpert);
                } catch (NexusApiException $exception) {
                    Log::warning('NEXUS AI could not load training dataset stats.', [
                        'status' => $exception->statusCode,
                        'configuration_error' => $exception->configurationError,
                    ]);
                }
            }

            $batchId = $request->query('batch');
            if (is_string($batchId) && $batchId !== '') {
                $selectedBatch = $nexus->getTrainingDatasetBatch($batchId);
                $exampleCount = count($selectedBatch['validation']['examples']);
                $maxExampleOffset = intdiv(max(0, $exampleCount - 1), 50) * 50;
                $exampleOffset = min($exampleOffset, $maxExampleOffset);
            }
        } catch (NexusApiException $exception) {
            Log::warning('Failed to load training dataset administration page.', [
                'status' => $exception->statusCode,
                'configuration_error' => $exception->configurationError,
            ]);
            $error = $exception->configurationError
                ? 'Подключение NEXUS AI не настроено.'
                : 'Не удалось загрузить партии обучения из NEXUS AI.';
        }

        return view('pages.admin.training-datasets', [
            'experts' => $experts,
            'batches' => $batches,
            'releases' => $releases,
            'adapters' => $adapters,
            'stats' => $stats,
            'selectedBatch' => $selectedBatch,
            'error' => $error,
            'selectedExpert' => is_string($selectedExpert) ? $selectedExpert : null,
            'selectedStatus' => is_string($selectedStatus) ? $selectedStatus : null,
            'exampleOffset' => $exampleOffset,
            'researchDrafts' => $request->session()->get('research_drafts'),
        ]);
    }

    public function storeRelease(Request $request, NexusApiClient $nexus): RedirectResponse
    {
        $request->validate([
            'expert' => ['required', 'string', 'max:100'],
        ]);

        $expert = $request->string('expert')->toString();

        try {
            $release = $nexus->createTrainingDatasetRelease($expert);

            return redirect()
                ->route('admin.training-datasets', ['expert' => $expert])
                ->with('status', 'training_dataset_release_created')
                ->with('release_example_count', $release['example_count'] ?? null);
        } catch (NexusApiException $exception) {
            Log::warning('NEXUS AI could not create a training dataset release.', [
                'status' => $exception->statusCode,
                'configuration_error' => $exception->configurationError,
            ]);

            return back()->withInput()->withErrors([
                'release' => match ($exception->statusCode) {
                    404 => 'Выбранный эксперт больше недоступен.',
                    409 => 'Для эксперта нет утверждённых партий с примерами.',
                    default => 'Не удалось собрать снимок датасета в NEXUS AI.',
                },
            ]);
        }
    }

    public function store(
        StoreTrainingDatasetBatchRequest $request,
        NexusApiClient $nexus,
    ): RedirectResponse {
        $file = $request->file('file');

        try {
            $batch = $nexus->createTrainingDatasetBatch(
                $request->string('expert')->toString(),
                $file->getPathname(),
                $file->getClientOriginalName(),
            );

            return redirect()->route('admin.training-datasets', ['batch' => $batch['id']])
                ->with('status', ($batch['reused'] ?? false)
                    ? 'training_dataset_batch_reused'
                    : 'training_dataset_batch_created');
        } catch (NexusApiException $exception) {
            Log::warning('NEXUS AI could not create a training dataset batch.', [
                'status' => $exception->statusCode,
                'configuration_error' => $exception->configurationError,
            ]);

            return back()->withInput()->withErrors([
                'dataset' => match ($exception->statusCode) {
                    404 => 'Выбранный эксперт больше недоступен.',
                    413 => 'Файл превышает ограничение 50 МБ.',
                    422 => 'Проверьте формат файла, кодировку UTF-8 и обязательные поля Q/A.',
                    default => 'Не удалось проверить или сохранить файл в NEXUS AI.',
                },
            ]);
        }
    }

    public function approve(Request $request, string $batchId, NexusApiClient $nexus): RedirectResponse
    {
        try {
            $nexus->approveTrainingDatasetBatch(
                $batchId,
                $request->boolean('duplicates_reviewed'),
            );

            return redirect()->route('admin.training-datasets', ['batch' => $batchId])
                ->with('status', 'training_dataset_batch_approved');
        } catch (NexusApiException $exception) {
            Log::warning('NEXUS AI could not approve a training dataset batch.', [
                'status' => $exception->statusCode,
            ]);

            return redirect()->route('admin.training-datasets', ['batch' => $batchId])
                ->withErrors([
                    'dataset' => $exception->statusCode === 409
                        ? 'Партию нельзя утвердить: проверьте её статус и исправьте некорректные строки.'
                        : 'Не удалось утвердить партию в NEXUS AI.',
                ]);
        }
    }

    public function reject(string $batchId, NexusApiClient $nexus): RedirectResponse
    {
        try {
            $nexus->rejectTrainingDatasetBatch($batchId);

            return redirect()->route('admin.training-datasets', ['batch' => $batchId])
                ->with('status', 'training_dataset_batch_rejected');
        } catch (NexusApiException $exception) {
            Log::warning('NEXUS AI could not reject a training dataset batch.', [
                'status' => $exception->statusCode,
            ]);

            return redirect()->route('admin.training-datasets', ['batch' => $batchId])
                ->withErrors([
                    'dataset' => $exception->statusCode === 409
                        ? 'Партия уже была рассмотрена.'
                        : 'Не удалось отклонить партию в NEXUS AI.',
                ]);
        }
    }

    public function updateExample(
        Request $request,
        string $batchId,
        int $row,
        NexusApiClient $nexus,
    ): RedirectResponse {
        $request->validate([
            'question' => ['nullable', 'string', 'max:10000'],
            'answer' => ['nullable', 'string', 'max:100000'],
            'metadata' => ['nullable', 'string'],
            'expert' => ['nullable', 'string', 'max:100'],
        ]);

        $payload = [];
        foreach (['question', 'answer'] as $field) {
            $value = trim((string) $request->input($field, ''));
            if ($value !== '') {
                $payload[$field] = $value;
            }
        }

        $metadataJson = $request->input('metadata');
        if (is_string($metadataJson) && $metadataJson !== '' && $request->filled('expert')) {
            $metadata = json_decode($metadataJson, true);
            if (is_array($metadata)) {
                $metadata['expert'] = $request->string('expert')->toString();
                $payload['metadata'] = $metadata;
            }
        }

        if ($payload === []) {
            return back()->withErrors([
                'example' => 'Укажите вопрос и ответ или новое значение эксперта.',
            ]);
        }

        try {
            $nexus->updateTrainingDatasetBatchExample($batchId, $row, $payload);

            return redirect()->route('admin.training-datasets', ['batch' => $batchId])
                ->with('status', 'training_dataset_example_updated');
        } catch (NexusApiException $exception) {
            Log::warning('NEXUS AI could not update a training dataset example.', [
                'status' => $exception->statusCode,
            ]);

            return redirect()->route('admin.training-datasets', ['batch' => $batchId])
                ->withErrors(['example' => $this->exampleError($exception)]);
        }
    }

    public function deleteExample(
        string $batchId,
        int $row,
        NexusApiClient $nexus,
    ): RedirectResponse {
        try {
            $nexus->deleteTrainingDatasetBatchExample($batchId, $row);

            return redirect()->route('admin.training-datasets', ['batch' => $batchId])
                ->with('status', 'training_dataset_example_deleted');
        } catch (NexusApiException $exception) {
            Log::warning('NEXUS AI could not delete a training dataset example.', [
                'status' => $exception->statusCode,
            ]);

            return redirect()->route('admin.training-datasets', ['batch' => $batchId])
                ->withErrors(['example' => $this->exampleError($exception)]);
        }
    }

    private function exampleError(NexusApiException $exception): string
    {
        return match ($exception->statusCode) {
            404 => 'Строка не найдена — партия могла измениться. Обновите страницу.',
            409 => 'Изменять можно только партию со статусом «ожидает проверки».',
            422 => 'Проверьте введённые данные: вопрос и ответ не должны быть пустыми.',
            default => 'Не удалось изменить строку партии в NEXUS AI.',
        };
    }
}
