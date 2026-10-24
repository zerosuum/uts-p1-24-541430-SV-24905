<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'first_step' => ['nullable', 'string', 'max:500'],
            'status' => [
                'sometimes',
                Rule::in(['inbox', 'in_progress', 'done']),
            ],
        ]);

        $task = $request->user()->tasks()->create([
            'title' => $validated['title'],
            'first_step' => $validated['first_step'] ?? null,
            'status' => $validated['status'] ?? 'inbox',
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

        $validated = $request->validate([
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
        ]);

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
}
