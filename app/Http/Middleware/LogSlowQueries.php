<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LogSlowQueries
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        // Only log slow queries in non-production or when explicitly enabled
        if (app()->environment('production') && !config('app.log_slow_queries', false)) {
            return $next($request);
        }

        $startTime = microtime(true);
        $startMemory = memory_get_usage();
        $queryCount = 0;
        $slowQueries = [];

        // Enable query logging
        DB::enableQueryLog();

        $response = $next($request);

        // Get query log
        $queries = DB::getQueryLog();
        $queryCount = count($queries);
        
        $endTime = microtime(true);
        $endMemory = memory_get_usage();
        $executionTime = ($endTime - $startTime) * 1000; // Convert to milliseconds
        $memoryUsed = ($endMemory - $startMemory) / 1024 / 1024; // Convert to MB

        // Log slow queries (queries taking more than 100ms)
        $slowQueryThreshold = config('app.slow_query_threshold', 100); // milliseconds
        
        foreach ($queries as $query) {
            $queryTime = $query['time']; // Already in milliseconds
            if ($queryTime > $slowQueryThreshold) {
                $slowQueries[] = [
                    'query' => $query['query'],
                    'bindings' => $query['bindings'],
                    'time' => $queryTime . 'ms',
                ];
            }
        }

        // Log performance metrics if request is slow or has slow queries
        $slowRequestThreshold = config('app.slow_request_threshold', 1000); // milliseconds
        
        if ($executionTime > $slowRequestThreshold || !empty($slowQueries)) {
            $logData = [
                'url' => $request->fullUrl(),
                'method' => $request->method(),
                'execution_time' => round($executionTime, 2) . 'ms',
                'memory_used' => round($memoryUsed, 2) . 'MB',
                'query_count' => $queryCount,
                'slow_queries' => $slowQueries,
            ];

            if (!empty($slowQueries)) {
                Log::warning('Slow queries detected', $logData);
            } else {
                Log::info('Slow request detected', $logData);
            }
        }

        return $response;
    }
}
