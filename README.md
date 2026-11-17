# mcp-etracker

This package is part of [Ibexa DXP](https://ibexa.co).

To use this package, [install Ibexa DXP](https://doc.ibexa.co/en/latest/install/).

This package simulates an **etracker** analytics service as MCP tools for `ibexa/mcp`. It exposes
click-stream statistics of a tracked **website** and **Instagram** channel per tracking account.

> All statistics shipped with the bundle are **simulated**. Page URLs and Instagram post shortcodes
> of nrwbank.de / @nrw.bank were taken from the live site on 2026-11-17; posts with
> `source: simulated` (shortcode `SIM…`) are invented and have no URL.

## Architecture

```
ClickStreamTools (MCP tools, server "etracker")         src/lib/Tool/Tracking
  └─ StreamServiceInterface → StreamService             src/contracts/Stream, src/lib/Stream
       └─ StreamDataSourceInterface → JsonFileStreamDataSource (one JSON file per account)
```

`StreamDataSourceInterface` is the integration point: alias it to a real etracker API client to
replace the simulated JSON files.

## Installation

```php
// config/bundles.php
Ibexa\Bundle\McpEtracker\IbexaMcpEtrackerBundle::class => ['all' => true],
```

Add an `etracker` MCP server to the repository (all tools declare `servers: ['etracker']`):

```yaml
# config/packages/ibexa.yaml
ibexa:
    repositories:
        default:
            mcp:
                etracker:
                    path: /mcp/etracker
                    version: '1.0.0'
                    description: 'etracker Analytics MCP Server (simulated)'
                    instructions: 'Use this server to retrieve website and Instagram click statistics. Call list_tracking_accounts first.'
                    enabled: true
                    discovery_cache: 'cache.app'
                    session:
                        type: psr16
                        service: 'cache.app'
                        prefix: 'mcp_uuid_'
                        ttl: 7200
```

To expose the tools on another server instead, list `Ibexa\McpEtracker\Tool\Tracking\ClickStreamTools`
under that server's `tools:` key.

## Configuration

Without configuration, two bundled accounts are available:

| Account | Data | Period |
|---|---|---|
| `nrwbank` (default) | `src/bundle/Resources/data/nrwbank_clickstream_3months.json` | 2026-08-17 – 2026-11-16 (92 days) |
| `nrwbank_sample` | `src/bundle/Resources/data/nrwbank_clickstream_1day.json` | 2026-11-16 (1 day) |

Configure your own accounts (this replaces the bundled ones):

```yaml
ibexa_mcp_etracker:
    default_account: acme
    accounts:
        acme:
            name: 'ACME Corp.'
            data_file: '%kernel.project_dir%/var/etracker/acme.json'
```

New data sets can be generated with `src/bundle/Resources/data/generate.py`
(`python3 generate.py --end 2026-11-16 --days 92 --seed 42 --out acme.json`).

## MCP tools

| Tool | Purpose | Parameters |
|---|---|---|
| `list_tracking_accounts` | Accounts, channels (domain / Instagram handle), tracked period | – |
| `get_click_stream` | Daily totals per channel (pageviews, sessions, visitors, clicks / likes, comments, clicks, reach, followers) | `account`, `channel`, `timeframe`, `from`, `to` |
| `get_channel_summary` | Totals over the timeframe: bounce rate, traffic sources, devices, peak day / engagement rate, follower growth | `account`, `channel`, `timeframe`, `from`, `to` |
| `get_hourly_click_distribution` | Website clicks / Instagram likes per hour of day | `account`, `channel`, `timeframe`, `from`, `to` |
| `get_website_page_statistics` | Per-page pageviews, clicks, CTR, clicks by target, time on page | `account`, `timeframe`, `from`, `to`, `section`, `sortBy`, `limit` |
| `get_instagram_post_statistics` | Per-post likes, comments, saves, shares, reach, plays, clicks, lifetime totals | `account`, `timeframe`, `from`, `to`, `mediaType`, `sortBy`, `limit` |

Common parameters:

- `account` – account identifier; omitted = default account.
- `channel` – `website` | `instagram` | `all` (default).
- `timeframe` – `last_day` | `last_7_days` | `last_14_days` | `last_30_days` (default) | `last_90_days` | `all`.
  Presets count backwards from the **last tracked day** of the account.
- `from` / `to` – custom inclusive range (`YYYY-MM-DD`); overrides `timeframe`. A missing bound defaults to
  the start/end of the tracked period. Ranges are clipped to the tracked period.

Every tool returns the `ibexa/mcp` `ToolResult` envelope (`{"success": true, "data": …}` or
`{"success": false, "error": "…"}`).

## COPYRIGHT

Copyright (C) 1999-2025 Ibexa AS (formerly eZ Systems AS). All rights reserved.

## LICENSE

This source code is available separately under the following licenses:

A - Ibexa Business Use License Agreement (Ibexa BUL),
version 2.4 or later versions (as license terms may be updated from time to time)
Ibexa BUL is granted by having a valid Ibexa DXP (formerly eZ Platform Enterprise) subscription,
as described at: https://www.ibexa.co/product
For the full Ibexa BUL license text, please see:
- LICENSE-bul file placed in the root of this source code, or
- https://www.ibexa.co/software-information/licenses-and-agreements (latest version applies)

AND

B - Ibexa Trial and Test License Agreement (Ibexa TTL),
version 2.2 or later versions (as license terms may be updated from time to time)
Trial can be granted by Ibexa, reach out to Ibexa AS for evaluation access: https://www.ibexa.co/about-ibexa/contact-us
For the full Ibexa TTL license text, please see:
- LICENSE file placed in the root of this source code, or
- https://www.ibexa.co/software-information/licenses-and-agreements (latest version applies)
