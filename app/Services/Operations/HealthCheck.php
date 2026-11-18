<?php

namespace App\Services\Operations;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Operational health indicators for the dashboard and the /api/v1/health probe:
 * database, cache, queue backlog, failed jobs, scheduler heartbeat and storage.
 */
class HealthCheck
{
    public const QUEUES = ['otp', 'webhooks', 'notifications', 'default'];

    /** @return array<string, array{ok: bool, label: string, detail: string}> */
    public function run(): array
    {
        return [
            'database' => $this->check('Database (PostgreSQL)', function () {
                $started = hrtime(true);
                DB::select('select 1');

                return 'Responded in '.round((hrtime(true) - $started) / 1e6, 1).' ms';
            }),
            'cache' => $this->check('Cache ('.config('cache.default').')', function () {
                $key = 'health:'.Str::random(8);
                Cache::put($key, 'ok', 10);
                $ok = Cache::pull($key) === 'ok';
                if (! $ok) {
                    throw new \RuntimeException('Read-back failed');
                }

                return 'Read/write OK';
            }),
            'queue' => $this->check('Queue workers ('.config('queue.default').')', function () {
                $backlog = array_sum($this->queueSizes());
                if ($backlog > 1000) {
                    throw new \RuntimeException("{$backlog} jobs waiting — are workers running?");
                }

                return "{$backlog} jobs waiting";
            }),
            'failed_jobs' => $this->check('Failed jobs', function () {
                $failed = DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count();
                if ($failed > 0) {
                    throw new \RuntimeException("{$failed} failed in the last 24 hours");
                }

                return 'None in the last 24 hours';
            }),
            'scheduler' => $this->check('Scheduler', function () {
                $beat = Cache::get('scheduler:heartbeat');
                if (! $beat || now()->diffInMinutes($beat, true) > 3) {
                    throw new \RuntimeException($beat ? 'Last run '.Carbon::parse($beat)->diffForHumans() : 'No heartbeat recorded — is cron running schedule:run?');
                }

                return 'Last run '.Carbon::parse($beat)->diffForHumans();
            }),
            'storage' => $this->check('Private storage ('.config('uec.media.private_disk').')', function () {
                $disk = Storage::disk(config('uec.media.private_disk'));
                $path = 'healthchecks/'.Str::ulid().'.txt';
                $disk->put($path, 'ok');
                $ok = $disk->get($path) === 'ok';
                $disk->delete($path);
                if (! $ok) {
                    throw new \RuntimeException('Read-back failed');
                }

                return 'Read/write OK';
            }),
        ];
    }

    /** @return array<string, int> */
    public function queueSizes(): array
    {
        $sizes = [];
        foreach (self::QUEUES as $queue) {
            try {
                $sizes[$queue] = Queue::size($queue);
            } catch (Throwable) {
                $sizes[$queue] = 0;
            }
        }

        return $sizes;
    }

    private function check(string $label, callable $probe): array
    {
        try {
            return ['ok' => true, 'label' => $label, 'detail' => (string) $probe()];
        } catch (Throwable $e) {
            return ['ok' => false, 'label' => $label, 'detail' => Str::limit($e->getMessage(), 200)];
        }
    }
}
