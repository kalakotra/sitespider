# Kalakotra SiteSpider

SEO crawl engine for SilverStripe 6.1 — sitemap-first link graph analysis with AI keyword cannibalization detection.

## Features

- **Sitemap seeding** — XML sitemap (`<urlset>` + `<sitemapindex>`) pre-populates the crawl queue in order
- **BFS fallback** — if no sitemap, discovery starts from `BaseURL` via `<a href>` parsing
- **robots.txt audit and enforcement** — reports status, broad blocks, sitemap directives, and skips URLs disallowed for the SiteSpider user-agent
- **llms.txt audit** — reports availability and basic Markdown structure with optional recommendations; it is not presented as a Google ranking factor
- **Queued crawl jobs** — one page per job, delayed continuation, retry support, and serialized queue processing
- **Recurring monitoring projects** — daily, weekly, or monthly sitemap crawls with cross-run issue history and optional regression email alerts
- **Link graph** — `PageLink` pivot table tracks every directed internal edge
- **Inbound/Outbound counts** — live-updated on every `persistLinks()` call
- **Orphan detection** — `IsOrphan=true` when `InboundLinksCount === 0`
- **AI Cannibalization** — compares each page's keywords against all other crawled pages in the same `AuditSession` via `kalakotra/silverstripe-aigateway`
- **CMS dashboard** — `SiteAuditAdmin` with smart filters, CSV export, D3 map endpoint

## Installation

```bash
composer require kalakotra/sitespider
vendor/bin/sake dev/build flush=all
```

## Queued job worker

```cron
* * * * * flock -n /tmp/sitespider-dispatch.lock sh -c 'cd /path/to/project && php vendor/bin/sake dev/tasks/Kalakotra-SiteSpider-Tasks-SiteAuditSpiderTask >> var/log/sitespider.log 2>&1'
* * * * * flock -n /tmp/queuedjobs-worker.lock sh -c 'cd /path/to/project && php vendor/bin/sake dev/tasks/ProcessJobQueueTask --queue=queued >> var/log/queuedjobs.log 2>&1'
* * * * * sleep 20; flock -n /tmp/queuedjobs-worker.lock sh -c 'cd /path/to/project && php vendor/bin/sake dev/tasks/ProcessJobQueueTask --queue=queued >> var/log/queuedjobs.log 2>&1'
* * * * * sleep 40; flock -n /tmp/queuedjobs-worker.lock sh -c 'cd /path/to/project && php vendor/bin/sake dev/tasks/ProcessJobQueueTask --queue=queued >> var/log/queuedjobs.log 2>&1'
```

The dispatcher checks due monitoring projects and recovers active sessions. QueuedJobs uses a serial queue runner by default. Shared `flock` locks prevent overlapping processes; each SiteSpider job processes one page, then schedules the next page after `page_delay_seconds` (60 seconds by default).

### External HTTP scheduler (optional)

If the host does not offer system cron, keep the CLI options above and configure an external scheduler such as cron-job.org to call:

```text
POST https://your-site.example/api/sitespider/cron
Authorization: Bearer YOUR_LONG_RANDOM_SECRET
```

Set `SITESPIDER_CRON_TOKEN` in the hosting environment to the same randomly generated secret. Do not put the token in the URL. The endpoint returns `401` if the token is missing or invalid, takes a database advisory lock to avoid overlapping triggers, enqueues due projects/active sessions, and executes at most one ready queued job per request. Set the external scheduler interval to 1 minute or slower and its HTTP timeout long enough for one page crawl (allow at least 60 seconds when AI/PageSpeed calls are enabled). A `200` response with `status: idle` means there was no ready work; `status: processed` means one job ran; `status: busy` means another trigger is still active.

This is an additional trigger only. The existing `SiteAuditSpiderTask` BuildTask and `ProcessJobQueueTask` worker remain available for manual use or a hosting cron; do not run both schedulers concurrently unless they share the same dispatch/worker lock strategy.

Create a project in **CMS → Site Spider → Monitoring Projects**, set its sitemap/frequency, and leave monitoring enabled. Its first crawl is due immediately. Configure a notification email and enable alerts to receive email for new or recurring findings. The project retains a durable finding history; a finding is resolved only after its URL was successfully crawled and that issue was no longer detected.

### Tenant access

Each project is owned by one SilverStripe `Member`. SaaS endpoints should require an authenticated member and use the model owner checks: a member can view/edit only records under their projects, while new projects are automatically assigned to the current member. `ADMIN` can administer all tenants. Crawl sessions, pages, links, and beacon logs are read-only to tenant users; only an administrator can delete history. Existing sessions without a project owner remain admin-only. The current CMS section separately uses `SITESPIDER_VIEW`/`SITESPIDER_MANAGE` for access to that CMS interface. Shareable client reports intentionally remain accessible by their unguessable share token.

### Plan limits

`MemberSeoLimitsExtension` adds `seoLimits()`, `hasSeoLimit()`, `getSeoLimit()`, `seoUsage()`, and `canUseSeoLimit()` to SilverStripe `Member`. Stable feature names are `SeoLimit::MAX_DOMAINS` and `SeoLimit::CRAWL_TASK`; the latter counts unique page scans per project/session during the current calendar month. Limits default to `null` (unlimited), so existing installations are not blocked until a billing/plan extension supplies values through SilverStripe's `updateSeoLimits` hook. Example application extension:

```php
public function updateSeoLimits(array &$limits): void
{
  $limits[\Kalakotra\SiteSpider\Services\SeoLimit::MAX_DOMAINS] = 3;
  $limits[\Kalakotra\SiteSpider\Services\SeoLimit::CRAWL_TASK] = 5000;
}
```

`MAX_DOMAINS` is checked before project creation. `CRAWL_TASK` is recorded in an idempotent monthly usage ledger; when exhausted, the crawl session pauses and the dispatcher resumes it at the next month boundary.

Attach the billing application's own extension to Member to set the current plan values:

```yaml
SilverStripe\Security\Member:
  extensions:
  - App\Extensions\CustomerPlanLimitsExtension
```

```php
use Kalakotra\SiteSpider\Services\SeoLimit;
use SilverStripe\Core\Extension;

class CustomerPlanLimitsExtension extends Extension
{
  public function updateSeoLimits(array &$limits): void
  {
    $limits[SeoLimit::MAX_DOMAINS] = 3;
    $limits[SeoLimit::CRAWL_TASK] = 5000;
  }
}
```

Code can call `$member->seoLimits()`, `$member->getSeoLimit(SeoLimit::CRAWL_TASK)`, `$member->seoUsage(SeoLimit::CRAWL_TASK)`, and `$member->canUseSeoLimit(SeoLimit::MAX_DOMAINS)`. SilverStripe exposes extension methods dynamically, so `method_exists($member, 'seoLimits')` is not a reliable check; the module registers the extension on `Member` automatically.

At session seeding, SiteSpider checks `/robots.txt` and `/llms.txt` at the site's origin and stores the findings in the AuditSession `SiteFiles` CMS tab. A robots.txt 5xx or fetch failure is treated conservatively: pages are skipped rather than crawled without a confirmed policy. A missing robots.txt is optional and allows crawling. A missing llms.txt is a recommendation, not an error.

## Creating a session

```bash
# With sitemap (recommended)
vendor/bin/sake dev/tasks/SiteAuditSpiderTask \
  new=1 \
  url=https://example.com \
  sitemap=https://example.com/sitemap.xml

# Without sitemap (BFS from BaseURL)
vendor/bin/sake dev/tasks/SiteAuditSpiderTask new=1 url=https://example.com

# Disable AI for a run
vendor/bin/sake dev/tasks/SiteAuditSpiderTask noai=1

# Enqueue the active session; the worker processes one page per queued job
vendor/bin/sake dev/tasks/SiteAuditSpiderTask
```

Or via PHP:

```php
use Kalakotra\SiteSpider\Services\AuditService;
use SilverStripe\Core\Injector\Injector;

$service = Injector::inst()->get(AuditService::class);

// Creates and queues a session automatically
$session = $service->createSession(
    baseUrl: 'https://example.com',
    sitemapUrl: 'https://example.com/sitemap.xml'
);
```

## Queued crawl logic

```
SiteAuditCrawlJob(sessionId)
  ├─ processTick(sessionId) once (seed, crawl one page, or finalize)
  ├─ retry temporary job failures with exponential delay
  └─ if session remains active, queue the next job after page_delay_seconds
```

## Database tables

| Table              | Purpose                                   |
|--------------------|-------------------------------------------|
| `KSS_AuditSession` | One row per crawl run; holds SitemapUrl   |
| `KSS_AuditPage`    | One row per discovered/crawled URL        |
| `KSS_PageLink`     | Directed edge: SourcePageID → TargetPageID|

## AuditSession fields

| Field          | Type     | Description                                       |
|----------------|----------|---------------------------------------------------|
| `BaseURL`      | Varchar  | Root URL of the crawl target                      |
| `SitemapUrl`   | Varchar  | XML sitemap endpoint (optional, recommended)      |
| `Status`       | Enum     | `pending` → `running` → `completed` \| `failed`  |
| `CrawledPages` | Int      | Pages processed so far                            |
| `TotalPages`   | Int      | Total URLs discovered (sitemap + BFS)             |
| `TotalBroken`  | Int      | Pages returning HTTP 404                          |
| `TotalOrphans` | Int      | Pages with zero inbound links                     |

## AuditPage spider metrics

| Field                          | Type    | Description                              |
|--------------------------------|---------|------------------------------------------|
| `IsFromSitemap`                | Boolean | URL originated from sitemap.xml          |
| `InboundLinksCount`            | Int     | Internal pages linking to this page      |
| `OutboundLinksCount`           | Int     | Internal links leaving this page         |
| `IsOrphan`                     | Boolean | `InboundLinksCount === 0`                |
| `KeywordCannibalizationWarning`| Text    | AI-generated keyword overlap warning     |

## Requirements

- PHP 8.2+
- SilverStripe 6.1+
- `guzzlehttp/guzzle ^7.8`
- `kalakotra/silverstripe-aigateway ^1.0`

## License

Proprietary — Kalakotra
