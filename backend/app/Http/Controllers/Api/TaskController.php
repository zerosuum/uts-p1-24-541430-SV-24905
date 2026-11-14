<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\SafeLogContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;

class TaskController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tasks = $request->user()
            ->tasks()
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'data' => $tasks,
        ], 200);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validateAndLog($request, [
            'title' => ['required', 'string', 'max:120'],
            'first_step' => ['nullable', 'string', 'max:500'],
            'status' => [
                'sometimes',
                Rule::in(['inbox', 'in_progress', 'done']),
            ],
        ], 'task_create');

        try {
            $task = $request->user()->tasks()->create([
            'title' => $validated['title'],
                'first_step' => $validated['first_step'] ?? null,
                'status' => $validated['status'] ?? 'inbox',
            ]);
        } catch (QueryException $exception) {
            Log::error('task.database_write_failed', [
                'event' => 'task.database_write_failed',
                ...SafeLogContext::forUser($request, 'task_create', 'database_write_failed'),
                'component' => 'database',
            ]);

            return response()->json([
                'message' => 'Penyimpanan sementara tidak tersedia.',
            ], 503);
        }

        Log::info('task.created', [
            'event' => 'task.created',
            ...SafeLogContext::forUser($request, 'task_create', 'user_requested_create'),
            'result' => 'created',
        ]);

        return response()->json([
            'message' => 'Tugas berhasil ditambahkan.',
            'data' => $task,
        ], 201);
    }

    public function show(Request $request, int $task): JsonResponse
    {
        $item = $request->user()
            ->tasks()
            ->findOrFail($task);

        return response()->json([
            'data' => $item,
        ], 200);
    }

    public function update(Request $request, int $task): JsonResponse
    {
        $item = $request->user()
            ->tasks()
            ->findOrFail($task);

        $validated = $this->validateAndLog($request, [
            'title' => [
                'sometimes', 'required', 'string', 'max:120',
            ],
            'first_step' => [
                'sometimes', 'nullable', 'string', 'max:500',
            ],
            'status' => [
                'sometimes',
                Rule::in(['inbox', 'in_progress', 'done']),
            ],
        ], 'task_update');

        $item->update($validated);

        return response()->json([
            'message' => 'Tugas berhasil diperbarui.',
            'data' => $item->fresh(),
        ], 200);
    }

    public function destroy(Request $request, int $task): JsonResponse
    {
        $item = $request->user()
            ->tasks()
            ->findOrFail($task);

        $item->delete();

        return response()->json([
            'message' => 'Tugas berhasil dihapus.',
        ], 200);
    }

    /** Preserve Laravel's normal 422 validation response; never log input values. */
    private function validateAndLog(Request $request, array $rules, string $action): array
    {
        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            Log::warning('task.validation_failed', [
                'event' => 'task.validation_failed',
                ...SafeLogContext::forUser($request, $action, 'validation_failed'),
                'invalid_fields' => array_keys($validator->failed()),
            ]);

            throw new ValidationException($validator);
        }

        return $validator->validated();
    }
}
