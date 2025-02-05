<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Models\Operator;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MonitoringController extends Controller
{
    public function dashboard(): JsonResponse
    {
        try {
            $stats = Cache::remember('dashboard_stats', 60, function() {
                $now = now();
                $hourAgo = $now->copy()->subHour();
                $dayAgo = $now->copy()->subDay();

                return [
                    'messages' => [
                        'last_hour' => [
                            'total' => Message::where('created_at', '>=', $hourAgo)->count(),
                            'successful' => Message::where('created_at', '>=', $hourAgo)
                                ->where('status', 'delivered')
                                ->count(),
                            'failed' => Message::where('created_at', '>=', $hourAgo)
                                ->where('status', 'failed')
                                ->count()
                        ],
                        'last_day' => [
                            'total' => Message::where('created_at', '>=', $dayAgo)->count(),
                            'successful' => Message::where('created_at', '>=', $dayAgo)
                                ->where('status', 'delivered')
                                ->count(),
                            'failed' => Message::where('created_at', '>=', $dayAgo)
                                ->where('status', 'failed')
                                ->count()
                        ]
                    ],
                    'operators' => [
                        'total' => Operator::count(),
                        'active' => Operator::where('status', 'active')->count(),
                        'inactive' => Operator::where('status', '!=', 'active')->count()
                    ],
                    'current_tps' => $this->calculateCurrentTps(),
                    'queue_size' => DB::table('message_queue')->count(),
                    'system_health' => $this->checkSystemHealth()
                ];
            });

            return response()->json([
                'status' => 'success',
                'data' => $stats
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to fetch dashboard stats: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch dashboard statistics'
            ], 500);
        }
    }

    public function stats(): JsonResponse
    {
        try {
            $hourlyStats = $this->getHourlyStats();
            $operatorStats = $this->getOperatorStats();
            $routeStats = $this->getRouteStats();

            return response()->json([
                'status' => 'success',
                'data' => [
                    'hourly_stats' => $hourlyStats,
                    'operator_stats' => $operatorStats,
                    'route_stats' => $routeStats
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to fetch detailed stats: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch detailed statistics'
            ], 500);
        }
    }

    public function alerts(): JsonResponse
    {
        try {
            $alerts = $this->checkSystemAlerts();
            
            return response()->json([
                'status' => 'success',
                'data' => $alerts
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to fetch alerts: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch system alerts'
            ], 500);
        }
    }

    public function logs(): JsonResponse
    {
        try {
            $logs = $this->getSystemLogs();
            
            return response()->json([
                'status' => 'success',
                'data' => $logs
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to fetch logs: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch system logs'
            ], 500);
        }
    }

    public function performance(): JsonResponse
    {
        try {
            $metrics = $this->getPerformanceMetrics();
            
            return response()->json([
                'status' => 'success',
                'data' => $metrics
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to fetch performance metrics: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch performance metrics'
            ], 500);
        }
    }

    private function calculateCurrentTps(): int
    {
        return Message::where('created_at', '>=', now()->subSecond())->count();
    }

    private function checkSystemHealth(): array
    {
        $health = [
            'status' => 'healthy',
            'components' => []
        ];

        // Check database
        try {
            DB::connection()->getPdo();
            $health['components']['database'] = ['status' => 'healthy'];
        } catch (\Exception $e) {
            $health['status'] = 'degraded';
            $health['components']['database'] = [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }

        // Check Redis
        try {
            Cache::get('health_check');
            $health['components']['cache'] = ['status' => 'healthy'];
        } catch (\Exception $e) {
            $health['status'] = 'degraded';
            $health['components']['cache'] = [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }

        // Check queue
        try {
            $queueSize = DB::table('message_queue')->count();
            $health['components']['queue'] = [
                'status' => $queueSize > 10000 ? 'warning' : 'healthy',
                'size' => $queueSize
            ];
        } catch (\Exception $e) {
            $health['status'] = 'degraded';
            $health['components']['queue'] = [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }

        return $health;
    }

    private function getHourlyStats(): array
    {
        $stats = [];
        $now = now();

        for ($i = 23; $i >= 0; $i--) {
            $hour = $now->copy()->subHours($i);
            $nextHour = $hour->copy()->addHour();

            $hourStats = Message::whereBetween('created_at', [$hour, $nextHour])
                ->selectRaw('status, COUNT(*) as count')
                ->groupBy('status')
                ->get()
                ->pluck('count', 'status')
                ->toArray();

            $stats[] = [
                'hour' => $hour->format('Y-m-d H:00'),
                'total' => array_sum($hourStats),
                'delivered' => $hourStats['delivered'] ?? 0,
                'failed' => $hourStats['failed'] ?? 0,
                'pending' => $hourStats['pending'] ?? 0
            ];
        }

        return $stats;
    }

    private function getOperatorStats(): array
    {
        return Operator::with(['messages' => function($query) {
            $query->where('created_at', '>=', now()->subDay());
        }])
        ->get()
        ->map(function($operator) {
            return [
                'id' => $operator->id,
                'name' => $operator->name,
                'status' => $operator->status,
                'messages' => [
                    'total' => $operator->messages->count(),
                    'delivered' => $operator->messages->where('status', 'delivered')->count(),
                    'failed' => $operator->messages->where('status', 'failed')->count()
                ],
                'current_tps' => Message::where('operator_id', $operator->id)
                    ->where('created_at', '>=', now()->subSecond())
                    ->count()
            ];
        })
        ->toArray();
    }

    private function getRouteStats(): array
    {
        return DB::table('routes')
            ->join('operators', 'routes.operator_id', '=', 'operators.id')
            ->join('messages', 'operators.id', '=', 'messages.operator_id')
            ->where('messages.created_at', '>=', now()->subDay())
            ->select(
                'routes.prefix',
                'operators.name as operator',
                DB::raw('COUNT(*) as total_messages'),
                DB::raw('AVG(CASE WHEN messages.status = "delivered" THEN 1 ELSE 0 END) as success_rate')
            )
            ->groupBy('routes.prefix', 'operators.name')
            ->get()
            ->toArray();
    }

    private function checkSystemAlerts(): array
    {
        $alerts = [];
        $now = now();

        // Check high failure rate
        $failureRate = Message::where('created_at', '>=', $now->subMinutes(5))
            ->selectRaw('COUNT(CASE WHEN status = "failed" THEN 1 END) * 100.0 / COUNT(*) as failure_rate')
            ->first()
            ->failure_rate;

        if ($failureRate > 10) {
            $alerts[] = [
                'level' => 'critical',
                'type' => 'high_failure_rate',
                'message' => "High message failure rate: {$failureRate}%",
                'timestamp' => $now->toIso8601String()
            ];
        }

        // Check queue backup
        $queueSize = DB::table('message_queue')->count();
        if ($queueSize > 10000) {
            $alerts[] = [
                'level' => 'warning',
                'type' => 'queue_backup',
                'message' => "Large message queue size: {$queueSize}",
                'timestamp' => $now->toIso8601String()
            ];
        }

        // Check operator status
        $inactiveOperators = Operator::where('status', '!=', 'active')->get();
        foreach ($inactiveOperators as $operator) {
            $alerts[] = [
                'level' => 'warning',
                'type' => 'operator_inactive',
                'message' => "Operator {$operator->name} is {$operator->status}",
                'timestamp' => $now->toIso8601String()
            ];
        }

        return $alerts;
    }

    private function getSystemLogs(): array
    {
        // In production, this would integrate with a proper logging system
        return [
            'system' => $this->getTailOfLogFile(storage_path('logs/laravel.log')),
            'error' => $this->getTailOfLogFile(storage_path('logs/error.log')),
            'debug' => $this->getTailOfLogFile(storage_path('logs/debug.log'))
        ];
    }

    private function getTailOfLogFile(string $path, int $lines = 100): array
    {
        if (!file_exists($path)) {
            return [];
        }

        $file = new \SplFileObject($path, 'r');
        $file->seek(PHP_INT_MAX);
        $last_line = $file->key();

        $logs = [];
        $start = max(0, $last_line - $lines);

        $file->seek($start);
        while (!$file->eof()) {
            $logs[] = $file->current();
            $file->next();
        }

        return $logs;
    }

    private function getPerformanceMetrics(): array
    {
        return [
            'message_processing' => [
                'average_processing_time' => $this->calculateAverageProcessingTime(),
                'throughput' => $this->calculateCurrentTps(),
                'queue_latency' => $this->calculateQueueLatency()
            ],
            'system_resources' => [
                'cpu_usage' => $this->getCpuUsage(),
                'memory_usage' => $this->getMemoryUsage(),
                'disk_usage' => $this->getDiskUsage()
            ],
            'operator_performance' => $this->getOperatorPerformance()
        ];
    }

    private function calculateAverageProcessingTime(): float
    {
        return Message::where('created_at', '>=', now()->subMinutes(5))
            ->whereNotNull('updated_at')
            ->selectRaw('AVG(TIMESTAMPDIFF(MICROSECOND, created_at, updated_at)) as avg_time')
            ->first()
            ->avg_time ?? 0;
    }

    private function calculateQueueLatency(): float
    {
        return DB::table('message_queue')
            ->where('status', 'pending')
            ->selectRaw('AVG(TIMESTAMPDIFF(SECOND, created_at, NOW())) as avg_latency')
            ->first()
            ->avg_latency ?? 0;
    }

    private function getCpuUsage(): float
    {
        // This would need to be implemented based on the hosting environment
        return 0.0;
    }

    private function getMemoryUsage(): array
    {
        return [
            'used' => memory_get_usage(true),
            'peak' => memory_get_peak_usage(true)
        ];
    }

    private function getDiskUsage(): array
    {
        $path = storage_path();
        return [
            'free' => disk_free_space($path),
            'total' => disk_total_space($path)
        ];
    }

    private function getOperatorPerformance(): array
    {
        return Operator::where('status', 'active')
            ->get()
            ->map(function($operator) {
                $messages = Message::where('operator_id', $operator->id)
                    ->where('created_at', '>=', now()->subMinutes(5))
                    ->get();

                return [
                    'operator_id' => $operator->id,
                    'name' => $operator->name,
                    'current_tps' => $messages->where('created_at', '>=', now()->subSecond())->count(),
                    'success_rate' => $messages->count() > 0 
                        ? ($messages->where('status', 'delivered')->count() * 100 / $messages->count())
                        : 0,
                    'average_latency' => $messages->whereNotNull('updated_at')
                        ->avg(function($message) {
                            return $message->updated_at->diffInMilliseconds($message->created_at);
                        }) ?? 0
                ];
            })
            ->toArray();
    }
} 