<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CheckIns\CheckInRequest;
use App\Support\CheckIns\CheckInException;
use App\Support\CheckIns\CheckInQuestions;
use App\Support\CheckIns\CheckInWorkflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Check-ins on the phone (ADR 0102), `check-ins.respond`: this month's questions,
 * one's own months, and saving this month's answers. Self-scoped: there is no way
 * to ask for anybody else's. Through {@see CheckInWorkflow}, as the web is.
 */
class CheckInController extends Controller
{
    public function __construct(private readonly CheckInWorkflow $workflow) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->workflow->overview($request->user()->employee()->first())]);
    }

    public function store(CheckInRequest $request): JsonResponse
    {
        $employee = $request->user()->employee()->first();

        if ($employee === null) {
            return response()->json(['message' => 'Your account isn’t linked to an employee record. Ask HR to link it.'], 422);
        }

        try {
            $checkIn = $this->workflow->submit($employee, $request->validated());
        } catch (CheckInException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => CheckInQuestions::present($checkIn->refresh()),
            'message' => 'Check-in saved. Thank you.',
        ], $checkIn->wasRecentlyCreated ? 201 : 200);
    }
}
