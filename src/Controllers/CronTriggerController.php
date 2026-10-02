<?php

declare(strict_types=1);

namespace Kalakotra\SiteSpider\Controllers;

use Kalakotra\SiteSpider\Services\CrawlDispatcher;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DB;
use Symbiote\QueuedJobs\Services\QueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJobService;

class CronTriggerController extends Controller
{
    private static array $allowed_actions = ['index'];
    private const LOCK_NAME = 'sitespider_http_cron_trigger';

    public function index(HTTPRequest $request): HTTPResponse
    {
        if (!$request->isPOST()) {
            return $this->jsonResponse(405, ['error' => 'POST required']);
        }

        $configuredToken = (string) Environment::getEnv('SITESPIDER_CRON_TOKEN');
        $authorization = trim((string) $request->getHeader('Authorization'));
        $providedToken = preg_match('/^Bearer\s+(.+)$/i', $authorization, $match)
            ? trim($match[1])
            : '';

        if ($configuredToken === '' || $providedToken === '' || !hash_equals($configuredToken, $providedToken)) {
            return $this->jsonResponse(401, ['error' => 'Unauthorized']);
        }

        $lock = (int) DB::query("SELECT GET_LOCK('" . self::LOCK_NAME . "', 0)")->value();
        if ($lock !== 1) {
            return $this->jsonResponse(200, ['status' => 'busy', 'processed' => false]);
        }

        try {
            /** @var CrawlDispatcher $dispatcher */
            $dispatcher = Injector::inst()->get(CrawlDispatcher::class);
            $queued = $dispatcher->enqueueReadyCrawls();

            $queue = QueuedJobService::singleton();
            $job = $queue->getNextPendingJob(QueuedJob::QUEUED);
            if (!$job) {
                return $this->jsonResponse(200, [
                    'status' => 'idle',
                    'processed' => false,
                    'projectsQueued' => $queued['projects'],
                    'sessionsChecked' => $queued['sessions'],
                ]);
            }

            $success = $queue->runJob((int) $job->ID);
            return $this->jsonResponse($success ? 200 : 503, [
                'status' => $success ? 'processed' : 'job_failed',
                'processed' => $success,
                'jobId' => (int) $job->ID,
                'projectsQueued' => $queued['projects'],
                'sessionsChecked' => $queued['sessions'],
            ]);
        } catch (\Throwable $e) {
            return $this->jsonResponse(500, ['error' => 'Cron trigger failed']);
        } finally {
            DB::query("SELECT RELEASE_LOCK('" . self::LOCK_NAME . "')");
        }
    }

    private function jsonResponse(int $status, array $payload): HTTPResponse
    {
        $response = HTTPResponse::create(json_encode($payload, JSON_UNESCAPED_SLASHES) ?: '{}', $status);
        $response->addHeader('Content-Type', 'application/json; charset=utf-8');
        $response->addHeader('Cache-Control', 'no-store');
        return $response;
    }
}