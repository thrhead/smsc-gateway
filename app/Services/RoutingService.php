<?php

namespace App\Services;

use App\Models\Operator;
use App\Models\Route;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class RoutingService
{
    private const CACHE_TTL = 300; // 5 minutes

    public function findBestRoute(string $recipient): ?Operator
    {
        try {
            // Clean and normalize the recipient number
            $recipient = $this->normalizeNumber($recipient);

            // Try to get from cache
            $cacheKey = "route_" . $recipient;
            $cachedRoute = Cache::get($cacheKey);
            
            if ($cachedRoute) {
                $operator = Operator::find($cachedRoute['operator_id']);
                if ($operator && $operator->status === 'active') {
                    return $operator;
                }
            }

            // Find the longest matching prefix
            $prefix = $this->findLongestMatchingPrefix($recipient);
            if (!$prefix) {
                throw new \Exception('No route found for recipient');
            }

            // Get all routes for this prefix
            $routes = Route::where('prefix', $prefix)
                ->orderBy('priority', 'desc')
                ->orderBy('cost', 'asc')
                ->get();

            foreach ($routes as $route) {
                $operator = $this->getAvailableOperator($route->operator_id);
                if ($operator) {
                    // Cache the result
                    Cache::put($cacheKey, [
                        'operator_id' => $operator->id,
                        'prefix' => $prefix
                    ], self::CACHE_TTL);

                    return $operator;
                }
            }

            throw new \Exception('No active operator found for route');
        } catch (\Exception $e) {
            Log::error('Route selection failed: ' . $e->getMessage(), [
                'recipient' => $recipient
            ]);
            return null;
        }
    }

    private function normalizeNumber(string $number): string
    {
        // Remove any non-digit characters
        $number = preg_replace('/[^0-9]/', '', $number);

        // Ensure number starts with country code
        if (substr($number, 0, 1) !== '+') {
            $number = '+' . $number;
        }

        return $number;
    }

    private function findLongestMatchingPrefix(string $number): ?string
    {
        // Get all prefixes from cache or database
        $prefixes = $this->getPrefixes();

        // Sort by length (longest first)
        usort($prefixes, function($a, $b) {
            return strlen($b) - strlen($a);
        });

        // Find the first matching prefix
        foreach ($prefixes as $prefix) {
            if (str_starts_with($number, $prefix)) {
                return $prefix;
            }
        }

        return null;
    }

    private function getPrefixes(): array
    {
        return Cache::remember('route_prefixes', self::CACHE_TTL, function() {
            return Route::distinct()
                ->orderBy('prefix')
                ->pluck('prefix')
                ->toArray();
        });
    }

    private function getAvailableOperator(int $operatorId): ?Operator
    {
        $operator = Operator::find($operatorId);

        if (!$operator || $operator->status !== 'active') {
            return null;
        }

        // Check if operator hasn't exceeded its TPS limit
        $currentTps = $this->getCurrentTps($operatorId);
        if ($currentTps >= $operator->max_tps) {
            return null;
        }

        return $operator;
    }

    private function getCurrentTps(int $operatorId): int
    {
        $cacheKey = "operator_tps_{$operatorId}";
        
        return Cache::remember($cacheKey, 1, function() use ($operatorId) {
            // Count messages sent in the last second
            return \App\Models\Message::where('operator_id', $operatorId)
                ->where('created_at', '>=', now()->subSecond())
                ->count();
        });
    }

    public function updateRoutes(array $routes): void
    {
        try {
            foreach ($routes as $route) {
                Route::updateOrCreate(
                    ['prefix' => $route['prefix'], 'operator_id' => $route['operator_id']],
                    [
                        'priority' => $route['priority'],
                        'cost' => $route['cost']
                    ]
                );
            }

            // Clear route-related caches
            Cache::tags(['routes'])->flush();
        } catch (\Exception $e) {
            Log::error('Failed to update routes: ' . $e->getMessage(), [
                'routes' => $routes
            ]);
            throw $e;
        }
    }

    public function getOperatorStats(int $operatorId): array
    {
        $operator = Operator::findOrFail($operatorId);
        
        $stats = Cache::remember("operator_stats_{$operatorId}", 60, function() use ($operator) {
            $now = now();
            $hourAgo = $now->copy()->subHour();

            return [
                'total_messages' => Message::where('operator_id', $operator->id)
                    ->where('created_at', '>=', $hourAgo)
                    ->count(),
                'successful_messages' => Message::where('operator_id', $operator->id)
                    ->where('created_at', '>=', $hourAgo)
                    ->where('status', 'delivered')
                    ->count(),
                'failed_messages' => Message::where('operator_id', $operator->id)
                    ->where('created_at', '>=', $hourAgo)
                    ->where('status', 'failed')
                    ->count(),
                'current_tps' => $this->getCurrentTps($operator->id),
                'max_tps' => $operator->max_tps,
                'status' => $operator->status
            ];
        });

        return array_merge($stats, [
            'name' => $operator->name,
            'country_code' => $operator->country_code
        ]);
    }
} 