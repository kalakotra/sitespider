# Kalakotra SiteSpider

SEO crawl engine for SilverStripe 6.1 — sitemap-first link graph analysis with AI keyword cannibalization detection.

## Features

- **Sitemap seeding** — XML sitemap (`<urlset>` + `<sitemapindex>`) pre-populates the crawl queue in order
- **BFS fallback** — if no sitemap, discovery starts from `BaseURL` via `<a href>` parsing
- **One page per cron tick** — flat memory, no timeouts, multiple concurrent sessions
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

## Crontab (every minute)

```cron
* * * * * /path/to/project/vendor/bin/sake dev/tasks/SiteAuditSpiderTask >> /var/log/sitespider.log 2>&1
```

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

# Run 50 ticks manually (dev)
vendor/bin/sake dev/tasks/SiteAuditSpiderTask ticks=50
```

Or via PHP:

```php
use Kalakotra\SiteSpider\Services\AuditService;
use SilverStripe\Core\Injector\Injector;

$service = Injector::inst()->get(AuditService::class);

// Creates a session; cron picks it up automatically
$session = $service->createSession(
    baseUrl: 'https://example.com',
    sitemapUrl: 'https://example.com/sitemap.xml'
);
```

## Cron tick logic

```
processTick()
  ├─ Resolve active session (oldest running, then oldest pending)
  ├─ [Seed]   Status=pending OR no pages yet + SitemapUrl set
  │     └─ parseSitemap() → INSERT AuditPage rows (IsFromSitemap=true)
  │        OR ensureSeedPage() from BaseURL
  ├─ [Crawl]  Pop oldest IsCrawled=false AuditPage (FIFO by ID)
  │     ├─ checkResponse() → HttpStatus, RedirectTarget
  │     ├─ GET body → extractMetadata() → PageTitle, H1, keywords
  │     ├─ discoverLinks() → new internal hrefs not yet in queue
  │     ├─ persistLinks() → PageLink rows + Inbound/Outbound counters
  │     └─ analyzeKeywordCannibalization() → AI warning via AIGateway
  └─ [Finish] isExhausted() → Status=completed, final stats written
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

Proprietary — Kalakotra d.o.o.
