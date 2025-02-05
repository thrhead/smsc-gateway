<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Services\MessageService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class MessageController extends Controller
{
    private MessageService $messageService;

    public function __construct(MessageService $messageService)
    {
        $this->messageService = $messageService;
    }

    public function send(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'sender' => 'required|string|max:20',
            'recipient' => 'required|string|max:20',
            'content' => 'required|string|max:1600',
            'priority' => 'integer|between:1,5',
            'callback_url' => 'nullable|url'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $message = $this->messageService->sendMessage(
                $request->input('sender'),
                $request->input('recipient'),
                $request->input('content'),
                $request->input('priority', 3),
                $request->input('callback_url')
            );

            return response()->json([
                'status' => 'success',
                'message_id' => $message->message_id,
                'status' => $message->status
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to send message',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function status(string $messageId): JsonResponse
    {
        try {
            $message = Message::where('message_id', $messageId)->firstOrFail();
            
            return response()->json([
                'status' => 'success',
                'message_id' => $message->message_id,
                'status' => $message->status,
                'sent_at' => $message->updated_at,
                'operator' => $message->operator?->name
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Message not found'
            ], 404);
        }
    }

    public function bulk(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'messages' => 'required|array|min:1|max:1000',
            'messages.*.sender' => 'required|string|max:20',
            'messages.*.recipient' => 'required|string|max:20',
            'messages.*.content' => 'required|string|max:1600',
            'callback_url' => 'nullable|url'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $results = $this->messageService->sendBulk(
                $request->input('messages'),
                $request->input('callback_url')
            );

            return response()->json([
                'status' => 'success',
                'total' => count($results),
                'messages' => $results
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to process bulk messages',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function cancel(string $messageId): JsonResponse
    {
        try {
            $success = $this->messageService->cancelMessage($messageId);
            
            return response()->json([
                'status' => $success ? 'success' : 'error',
                'message' => $success ? 'Message cancelled' : 'Unable to cancel message'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to cancel message',
                'error' => $e->getMessage()
            ], 500);
        }
    }
} 