<?php

declare(strict_types=1);

namespace Kalakotra\SiteSpider\Jobs;

use GuzzleHttp\Client;
use Kalakotra\AIGateway\Services\AIGatewayService;
use Kalakotra\AIGateway\Services\AIProviderRegistry;
use Kalakotra\SiteSpider\Models\AuditSession;
use Kalakotra\SiteSpider\Services\AuditService;
use Kalakotra\SiteSpider\Services\PageSpeedService;
use Kalakotra\SiteSpider\Services\SpiderService;
use Psr\Log\LoggerInterface;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injector;
use Symbiote\QueuedJobs\DataObjects\QueuedJobDescriptor;
use Symbiote\QueuedJobs\Services\AbstractQueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJobService;

class SiteAuditCrawlJob extends AbstractQueuedJob
{
    use Configurable;

    private static int $page_delay_seconds = 60;
    private static int $retry_max_attempts = 3;
    private static int $retry_initial_delay = 60;
    private static float $retry_falloff_multiplier = 2;

    public function __construct($params = [], bool $noAI = false)
    {
        parent::__construct($params);
        $sessionId = is_array($params) ? (int) ($params['SessionID'] ?? 0) : (int) $params;
        $this->SessionID = $sessionId;
        $this->NoAI = $noAI;
        $this->totalSteps = 1;
    }

    public static function queueSession(int $sessionId, bool $noAI = false, ?string $startAfter = null): int
    {
        $signature = self::signatureFor($sessionId);
        $existing = QueuedJobDescriptor::get()
            ->filter([
                'Signature' => $signature,
                'JobStatus' => [
                    QueuedJob::STATUS_NEW,
                    QueuedJob::STATUS_INIT,
                    QueuedJob::STATUS_RUN,
                    QueuedJob::STATUS_WAIT,
                ],
            ])
            ->first();

        if ($existing) {
            return (int) $existing->ID;
        }

        return QueuedJobService::singleton()->queueJob(
            new self($sessionId, $noAI),
            $startAfter
        );
    }

    private static function signatureFor(int $sessionId): string
    {
        return md5(static::class . ':' . $sessionId);
    }

    public function getTitle(): string
    {
        return "SiteSpider crawl session #{$this->SessionID}";
    }

    public function getSignature(): string
    {
        return self::signatureFor((int) $this->SessionID);
    }

    public function getJobType(): string
    {
        return QueuedJob::QUEUED;
    }

    public function process(): void
    {
        if ($this->NoAI) {
            Config::modify()->set(AuditService::class, 'ai_cannibalization_enabled', false);
            Config::modify()->set(AuditService::class, 'ai_onpage_enabled', false);
        }

        $injector = Injector::inst();
        $logger = $injector->get(LoggerInterface::class);
        $httpClient = $injector->get(Client::class);
        $spider = $injector->createWithArgs(SpiderService::class, [
            'httpClient' => $httpClient,
            'logger' => $logger,
        ]);
        $aiGateway = $injector->createWithArgs(AIGatewayService::class, [
            'registry' => $injector->get(AIProviderRegistry::class),
            'logger' => $logger,
        ]);
        $pageSpeed = $injector->createWithArgs(PageSpeedService::class, [
            'httpClient' => $httpClient,
            'logger' => $logger,
        ]);
        $service = $injector->createWithArgs(AuditService::class, [
            'spider' => $spider,
            'aiGateway' => $aiGateway,
            'pageSpeed' => $pageSpeed,
            'httpClient' => $httpClient,
            'logger' => $logger,
        ]);

        $this->addMessage($service->processTick((int) $this->SessionID));
        $this->currentStep = 1;
        $this->isComplete = true;
    }

    public function afterComplete(): void
    {
        $session = AuditSession::get()->byID((int) $this->SessionID);
        if (!$session || !in_array($session->Status, ['pending', 'running'], true)) {
            return;
        }

        $delay = max(0, (int) self::config()->get('page_delay_seconds'));
        $startAfter = date('Y-m-d H:i:s', time() + $delay);
        QueuedJobService::singleton()->queueJob(
            new self((int) $session->ID, (bool) $this->NoAI),
            $startAfter
        );
    }
}