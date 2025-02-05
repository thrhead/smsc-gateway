<?php

namespace App\Services;

use App\Models\Message;
use App\Models\Operator;
use App\Models\MessageQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\RoutingService;
use App\Services\SigtranService;

class MessageService
{
    private RoutingService $routingService;
    private SigtranService $sigtranService;

    public function __construct(
        RoutingService $routingService,
        SigtranService $sigtranService
    ) {
        $this->routingService = $routingService;
        $this->sigtranService = $sigtranService;
    }

    public function sendMessage(
        string $sender,
        string $recipient,
        string $content,
        int $priority = 3,
        ?string $callbackUrl = null
    ): Message {
        try {
            DB::beginTransaction();

            // Generate unique message ID
            $messageId = Message::generateMessageId();

            // Find best operator route
            $operator = $this->routingService->findBestRoute($recipient);
            if (!$operator) {
                throw new \Exception('No valid route found for recipient');
            }

            // Create message record
            $message = Message::create([
                'message_id' => $messageId,
                'sender' => $sender,
                'recipient' => $recipient,
                'content' => $content,
                'status' => Message::STATUS_PENDING,
                'operator_id' => $operator->id
            ]);

            // Create queue entry
            MessageQueue::create([
                'message_id' => $messageId,
                'operator_id' => $operator->id,
                'priority' => $priority,
                'scheduled_at' => now(),
                'status' => 'pending'
            ]);

            DB::commit();

            // Trigger async processing
            $this->processMessageAsync($message);

            return $message;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to send message: ' . $e->getMessage(), [
                'sender' => $sender,
                'recipient' => $recipient,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    public function sendBulk(array $messages, ?string $callbackUrl = null): array
    {
        $results = [];

        foreach ($messages as $message) {
            try {
                $result = $this->sendMessage(
                    $message['sender'],
                    $message['recipient'],
                    $message['content'],
                    $message['priority'] ?? 3,
                    $callbackUrl
                );

                $results[] = [
                    'message_id' => $result->message_id,
                    'status' => 'queued',
                    'recipient' => $message['recipient']
                ];
            } catch (\Exception $e) {
                $results[] = [
                    'recipient' => $message['recipient'],
                    'status' => 'failed',
                    'error' => $e->getMessage()
                ];
            }
        }

        return $results;
    }

    public function cancelMessage(string $messageId): bool
    {
        try {
            DB::beginTransaction();

            $message = Message::where('message_id', $messageId)
                ->whereIn('status', [Message::STATUS_PENDING, Message::STATUS_QUEUED])
                ->first();

            if (!$message) {
                return false;
            }

            // Remove from queue
            MessageQueue::where('message_id', $messageId)->delete();

            // Update message status
            $message->status = 'cancelled';
            $message->save();

            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to cancel message: ' . $e->getMessage(), [
                'message_id' => $messageId
            ]);
            return false;
        }
    }

    private function processMessageAsync(Message $message): void
    {
        try {
            // Update status to queued
            $message->status = Message::STATUS_QUEUED;
            $message->save();

            // Dispatch to queue for processing
            // This would typically be handled by a queue worker
            dispatch(function () use ($message) {
                $this->processMessage($message);
            })->onQueue('sms');
        } catch (\Exception $e) {
            Log::error('Failed to queue message for processing: ' . $e->getMessage(), [
                'message_id' => $message->message_id
            ]);
            $message->markAsFailed($e->getMessage());
        }
    }

    private function processMessage(Message $message): void
    {
        try {
            // Get operator connection
            $operator = $message->operator;
            if (!$operator || $operator->status !== 'active') {
                throw new \Exception('Operator not available');
            }

            // Update status
            $message->status = Message::STATUS_SENDING;
            $message->save();

            // Send via Sigtran
            $result = $this->sigtranService->sendMessage(
                $message->sender,
                $message->recipient,
                $message->content,
                $operator->connection_params
            );

            if ($result['success']) {
                $message->markAsSent();
            } else {
                throw new \Exception($result['error'] ?? 'Failed to send message');
            }
        } catch (\Exception $e) {
            Log::error('Failed to process message: ' . $e->getMessage(), [
                'message_id' => $message->message_id
            ]);
            $message->markAsFailed($e->getMessage());
        }
    }
} 