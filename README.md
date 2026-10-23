# Tonyapps_AdvancedReports

Extra admin reports on top of Magento's Reports menu, a scheduled sales-performance email to
admins, and a REST endpoint for external BI.

**Status: phase 1 built 2026-09-30. It still needs one click-through in the browser (see *Phase 1, as built*).** This file is the agreed scope. Update it; don't re-litigate it.

## Environment

| | |
|---|---|
| Platform | Magento **Open Source** 2.4.8-p4 |
| PHP | module declares `>=8.3` (matches the other Tonyapps modules) |
| Module | `Tonyapps_AdvancedReports` / `tonyapps/module-advanced-reports` / v0.1.0 / proprietary |
| Charts | Chart.js already ships in `lib/web/chartjs` (used by the admin dashboard), so no new dependency |

## What Magento already has (not rebuilding)

| Menu | Reports | Limitation we fix |
|---|---|---|
| Sales | Orders, Tax, Invoiced, Shipping, Refunds, Coupons, PayPal Settlement | Read `*_aggregated` tables that are empty until **Refresh Statistics** runs; filter only by status and period |
| Products | Bestsellers, Ordered, Views, Low Stock, Downloads | No revenue, margin, or category view. Views needs `report_event`, which is empty here |
| Customers | New, Order Total, Order Count | Top-N lists only. No new vs returning, guest share, or lapsed customers |
| Marketing / Reviews / Search Terms | Carts, abandoned carts, reviews, search terms | Out of v1 scope |

## Decisions (answered 2026-09-30)

1. **v1 = Tier 1 reports + search reports + scheduled sales email + REST API.**
2. **Core Magento data only.** B2B, StockNotification (Tonyapps) and Riskified are custom
   modules, so this module has no dependency on them and no reports for them. If they need
   reports, those reports live in their own modules and register into the `ReportPool` via `di.xml`.
3. **Margin appears only when there is cost data.** `sales_order_item.base_cost` is used when it
   is > 0. Otherwise the margin columns are hidden. There's no extra config.
4. **Audience: admin grid + chart + CSV export, scheduled email, and a REST API.**

## Design

- **Live SQL against `sales_order` / `sales_order_item` (+ `sales_creditmemo_item`,
  `catalog_category_product`).** No aggregation tables and no Refresh Statistics step.
  Every report takes the same filters: date from/to (converted from store timezone to UTC),
  store view(s), and order state (default excludes `canceled`). Revenue is in base currency.
  `ponytail:` live queries. If a report gets slow at real volume, add a nightly aggregate for that one report.
- **One report = one class** implementing `ReportInterface` (`getCode()`, `getTitle()`,
  `getColumns()`, `getRows(Filter)`, `getChart()`), registered in a `ReportPool` through `di.xml`.
  The admin page, CSV export, email, and REST all read from the pool, so each report is written once.
- **Admin:** menu `Reports > Advanced Reports > <report>`. One controller renders a shared block
  (filter form, Chart.js chart, HTML table, and an "Export CSV" button). Not UI-component listings.
  Those need a collection per report, which is overkill for read-only aggregates.
- **ACL:** `Tonyapps_AdvancedReports::reports`, one child per report, plus `::email_config`.

## Reports (v1)

| # | Code | Rows / metrics | Chart |
|---|---|---|---|
| 1 | `sales_overview` | per day/week/month: orders, revenue, AOV, items/order, refunds, discount, [margin]; totals row with **% vs previous period** | line, current vs previous |
| 2a | `sales_by_payment` | per payment method: orders, revenue, AOV, share % | bar |
| 2b | `sales_by_country` | per billing country (+ region drill-down): orders, revenue, AOV | bar |
| 2c | `sales_by_group` | per customer group (guest = group 0): orders, revenue, AOV | bar |
| 2d | `sales_by_store` | per store view: orders, revenue, AOV | bar |
| 3 | `sales_by_time` | 7×24 matrix, day of week × hour (store timezone): orders, revenue | heatmap (table with cell shading) |
| 4 | `product_performance` | per SKU: qty sold, revenue, qty refunded, refund rate, [cost, margin, margin %] | top 10 bar |
| 5a | `sales_by_category` | per category: qty, revenue (an item counts in every category it belongs to, as noted in the UI) | bar |
| 5b | `sales_by_attribute` | per option of a selected dropdown attribute (e.g. manufacturer): qty, revenue | bar |
| 6a | `customers_new_returning` | per period: new vs returning customers' orders and revenue (new = first order in range) | stacked bar |
| 6b | `customers_guest_registered` | per period: guest vs registered orders and revenue | stacked bar |
| 6c | `customers_lapsed` | customers whose last order is older than N days (filter): email, orders, lifetime revenue, last order date | none |
| 7 | `order_status` | per period × status: order count | stacked bar |

## Search reports

`search_query` only stores lifetime counters (`popularity`, last `updated_at`), so it can't answer
"in this date range". Core's Search Terms grid already covers the lifetime view, including the
zero-results filter. **Exception to "no new tables":**

- **`tonyapps_advancedreports_search_daily`**: `date`, `store_id`, `query_text`, `searches`,
  `num_results` (latest), PK (`date`, `store_id`, `query_text`). Written by an after-plugin on
  `Magento\Search\Model\Query::saveIncrementalPopularity()`, the call core makes once per
  storefront search, with one `INSERT … ON DUPLICATE KEY UPDATE searches = searches + 1`.
  `ponytail:` no purge. Add a cron with a retention setting if the table grows too big.
- History starts when the module is installed. Nothing can be backfilled.

| # | Code | Rows / metrics | Chart |
|---|---|---|---|
| 8a | `search_top` | per term in range: searches, results (latest) | top 20 bar |
| 8b | `search_zero_results` | terms with 0 results in range: searches, last seen | none |
| 8c | `search_trend` | per day/week/month: searches, unique terms, % zero-result | line |

## Scheduled email

- Config: `Stores > Configuration > Tonyapps > Advanced Reports > Email`. Settings: enabled,
  frequency (daily / weekly on Monday / monthly on the 1st), recipient **admin roles**
  (multiselect, sent to active users in those roles), and store scope.
- Cron runs daily at 06:00 store time and sends when the frequency matches. The last completed period
  is compared with the one before it.
- Body is an HTML email template with the `sales_overview` totals and deltas, top 5 products, top
  payment methods, and a link to the admin report. `ponytail:` no CSV attachment, since the link
  covers it. Add an attachment via Laminas MIME if recipients have no admin access.

## REST API

- `GET /V1/tonyapps/reports` lists the codes and titles the caller is allowed to see.
- `GET /V1/tonyapps/reports/:code?from=&to=&store_ids[]=&state[]=` returns `{columns, rows, totals}`.
- Auth: admin token or integration. ACL is the per-report resource, so an integration only
  gets the reports it's granted.

## Phases

1. Framework: `ReportInterface`, pool, filter, admin controller/block/chart, CSV, ACL, menu.
   Reports 1 and 2a to prove it. Plus a unit test for the timezone range conversion and previous-period math.
2. Reports 2b–7, plus the search log table, plugin, and reports 8a–8c.
3. Scheduled email (config, cron, template).
4. REST API (`webapi.xml`, service interface, ACL).

## Phase 1, as built

- `Model/ReportInterface` (`getTitle`, `isPeriodic`, `run(Filter): Result`), `ReportPool` (di.xml; ACL =
  `Tonyapps_AdvancedReports::<code>` unless overridden via the pool's `aclResources` argument).
- `Model/Filter` is a value object: local-day range, UTC conversion, previous period, and period buckets.
  `FilterFactory` validates untrusted params: `Y-m-d` dates, max 1100 days, whitelisted period/states,
  int store ids. Default is the last 30 days with every state except `canceled`.
- `Model/OrderSelect` is the shared filtered `sales_order` select. Money is `base_* × base_to_global_rate`,
  so it's reported in the global base currency. Periodic reports group by UTC hour in SQL and bucket
  into local day/week/month in PHP, so there's no `CONVERT_TZ` and no MySQL tz tables needed.
- Margin: cost from leaf items (as core's invoice cost total does), revenue from the priced item
  (configurable parent or itself). Hidden when no item in range has `base_cost > 0`.
- Admin: `tonyapps_reports/report/view/code/<code>` and `/export/code/<code>` (CSV with formula-injection guard).
  One block + `report.phtml`. The chart is `view/adminhtml/web/js/chart.js` on core's `chartJs`.
- Checked: unit tests (`Test/Unit/Model/FilterTest.php`, DST/bucket/previous-period), both reports
  against the dev DB (21 non-canceled orders, $1,022.03, which matches raw SQL), margin with a
  temporary cost on a configurable child (22 − 10 = 12, 54.5%, reverted), block render, menu, ACL,
  and route.
- **Not checked:** the page in a real browser. Admin 2FA blocked a scripted login. Open
  Reports > Advanced Reports > Sales Overview and check the chart draws.
- `setup:di:compile` passes (fixed 2026-09-30: `NotifyOnNewBrowserLogin` renamed from the `Tony\` to the `Tonyapps\`
  namespace, and its declared `cbschuld/browser.php` dependency is now in the root composer.json).

## Later (deferred, not in v1)

- Search → order conversion, which needs session tracking.
- Reports for the custom modules (B2B, StockNotification, Riskified), which belong in those modules (see Decision 2).
- Cohort retention / RFM, inventory valuation, and view→order conversion (needs `report_event` enabled).

## Open questions

- Is the recipient list by admin role OK, or do you want free-text emails too?
- Default order-state filter: exclude only `canceled`, or also `closed` (fully refunded)?
- Dev data has 23 orders. Is there a prod-sized dump to check query speed against before phase 2?
