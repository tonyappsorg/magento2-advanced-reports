# Tonyapps_AdvancedReports — notes for AI agents and developers

Read this before changing the module. Workspace-wide rules are in ../AGENTS.md.

Extra admin reports on top of Magento's Reports menu, a scheduled sales-performance email to admins (planned), a REST
endpoint for external BI (planned), and order export (built). **This file is the agreed scope. Update it; don't
re-litigate it.**

## Status

- **Phase 1 built 2026-09-30.** Still needs one click-through in a real browser (see *Phase 1, as built*).
- **Order export built 2026-10-25**, ahead of phase 2.
- Review fixes 2026-10-30 applied.
- Phases 2 (reports 2b–7, search log + 8a–8c), 3 (scheduled sales email) and 4 (REST API) **not built**.

Phases:

1. Framework: `ReportInterface`, pool, filter, admin controller/block/chart, CSV, ACL, menu. Reports 1 and 2a to prove
   it. Plus a unit test for the timezone range conversion and previous-period math. — **done**
2. Reports 2b–7, plus the search log table, plugin, and reports 8a–8c.
3. Scheduled email (config, cron, template).
4. REST API (`webapi.xml`, service interface, ACL).

## Environment

| | |
|---|---|
| Platform | Magento **Open Source** 2.4.8-p4 |
| PHP | `composer.json`: `~8.3.0\|\|~8.4.0` (old README said `>=8.3`) |
| Module | `Tonyapps_AdvancedReports` / `tonyapps/module-advanced-reports` / 1.0.0 / GPL-3.0-or-later |
| Charts | Chart.js already ships in `lib/web/chartjs` (used by the admin dashboard), so no new dependency |

## What Magento already has (not rebuilding)

| Menu | Reports | Limitation we fix |
|---|---|---|
| Sales | Orders, Tax, Invoiced, Shipping, Refunds, Coupons, PayPal Settlement | Read `*_aggregated` tables that are empty until **Refresh Statistics** runs; filter only by status and period |
| Products | Bestsellers, Ordered, Views, Low Stock, Downloads | No revenue, margin, or category view. Views needs `report_event`, which is empty here |
| Customers | New, Order Total, Order Count | Top-N lists only. No new vs returning, guest share, or lapsed customers |
| Marketing / Reviews / Search Terms | Carts, abandoned carts, reviews, search terms | Out of v1 scope |

## Architecture

### Design

- **Live SQL against `sales_order` / `sales_order_item` (+ `sales_creditmemo_item`, `catalog_category_product`).** No
  aggregation tables, no Refresh Statistics step. Every report takes the same filters: date from/to (converted from
  store timezone to UTC), store view(s), order state (default excludes `canceled`, `pending_payment`,
  `payment_review`). Revenue in base currency. `ponytail:` live queries; if a report gets slow at real volume, add a
  nightly aggregate for that one report.
- **One report = one class** implementing `ReportInterface`, registered in `ReportPool` through `di.xml`. Admin page,
  CSV export, email and REST all read from the pool, so each report is written once. (Original design listed
  `getCode()`, `getTitle()`, `getColumns()`, `getRows(Filter)`, `getChart()`; as built the interface is
  `getTitle()`, `isPeriodic()`, `run(Filter): Result`.)
- **Admin:** menu `Reports > Advanced Reports > <report>`. One controller renders a shared block (filter form,
  Chart.js chart, HTML table, "Export CSV" button). Not UI-component listings: those need a collection per report,
  overkill for read-only aggregates.
- **ACL:** `Tonyapps_AdvancedReports::reports`, one child per report (`Tonyapps_AdvancedReports::<code>`). Planned
  `::email_config` for the sales email; built: `::order_export_config`.

### Phase 1, as built

- `Model/ReportInterface` (`getTitle`, `isPeriodic`, `run(Filter): Result`), `Model/ReportPool` (di.xml; ACL =
  `Tonyapps_AdvancedReports::<code>`), `Model/Result`.
- `Model/Filter` is a value object: local-day range, UTC conversion, previous period, period buckets.
  `Model/FilterFactory` validates untrusted params: `Y-m-d` dates, max 1100 days (`MAX_DAYS`), whitelisted
  period/states, int store ids. Default: last 30 days (`DEFAULT_DAYS`), every state except `canceled`,
  `pending_payment`, `payment_review`.
- `Model/OrderSelect` is the shared filtered `sales_order` select. Money is `base_* × base_to_global_rate`
  (`OrderSelect::RATE`, `IFNULL(..., 1)`), so it's reported in the global base currency. Periodic reports group by UTC
  quarter hour in SQL (`OrderSelect::UTC_SLOT`) and bucket into local day/week/month in PHP: no `CONVERT_TZ`, no MySQL
  tz tables.
- Margin: cost from leaf items (as core's invoice cost total does), revenue from the priced item (configurable parent
  or itself). Hidden when no item in range has `base_cost > 0`.
- Admin routes: `tonyapps_reports/report/view/code/<code>` and `/export/code/<code>`
  (`Controller/Adminhtml/Report/{AbstractReport,View,Export}`). CSV (`Model/CsvWriter`) has a formula-injection guard:
  text starting with `=`, `@`, `+`, `-`, tab or CR gets a leading `'`; numbers and phone-like values such as
  `+1 555 0100` or `-5` are left alone for ERP imports.
- One block (`Block/Adminhtml/Report`) + `report.phtml` (layout `tonyapps_reports_report_view.xml`). Chart is
  `view/adminhtml/web/js/chart.js` on core's `chartJs`.
- Built reports in the pool: `sales_overview` (`Report/SalesOverview`), `sales_by_payment` (`Report/SalesByPayment`),
  `order_export`, `order_items_export`.
- Checked: unit tests (`Test/Unit/Model/FilterTest.php`, DST/bucket/previous-period), both reports against the dev DB
  (21 non-canceled orders, $1,022.03, matches raw SQL), margin with a temporary cost on a configurable child
  (22 − 10 = 12, 54.5%, reverted), block render, menu, ACL, route.
- **Not checked:** the page in a real browser. Admin 2FA blocked a scripted login. Open
  Reports > Advanced Reports > Sales Overview and check the chart draws.
- `setup:di:compile` passes (fixed 2026-09-30: `NotifyOnNewBrowserLogin` renamed from the `Tony\` to the `Tonyapps\`
  namespace, and its declared `cbschuld/browser.php` dependency is now in the root composer.json).

### Order export (built 2026-10-25)

- `Report/OrderExport` and `Report/OrderItemsExport` (base `Report/AbstractOrderExport`) both have
  `fromSelect(Select)`, so the reports (`OrderSelect::create`) and the scheduled run (`OrderSelect::createIdRange`)
  share the row code.
- Ordinary `ReportInterface` reports: same filters, Export CSV button, ACL, and (in phase 4) REST as every other
  report. No totals row, because ERP imports choke on one.
- Money is in **base** currency per order (`base_*`, with the currency code column), not converted to global like the
  aggregate reports. Row data for accounting, not sums. Admin page shows row-level amounts in the order's own base
  currency.
- Items: only rows with `parent_item_id IS NULL`. A configurable's parent row already carries the chosen simple's SKU
  and price. `ponytail:` dynamic-price bundle children aren't exported separately; add them if an ERP needs bundle
  components.
- Admin page fetches only the first 500 rows (`Report::MAX_HTML_ROWS`; fetches 501 to know whether to say so), so a
  long range can't run PHP out of memory. The CSV always has every row: written row by row to a temp file
  `var/export/tonyapps_advancedreports_<random>.csv` and streamed back; the file is removed after sending. Aggregate
  reports are small and still run whole.

| # | Code | One row per | Columns |
|---|---|---|---|
| 9a | `order_export` | order | order #, created (store time), store, state, status, customer email/name/group, guest flag, billing name/company/street/city/region/postcode/country/phone/VAT, shipping name/street/city/region/postcode/country, shipping method, payment method code, coupon, items qty, base currency, base subtotal/discount/shipping/tax/grand total/refunded, order currency, grand total charged |
| 9b | `order_items_export` | priced order item | order #, created, status, customer email, item ID, SKU, name, type, qty ordered/invoiced/shipped/refunded/canceled, base price, base row total, discount, tax, row total incl. tax |

### Scheduled order export

- `Model/ScheduledOrderExport::execute()` holds the mark logic; `Model/AttachmentMailer` the Symfony attachment step.
  Cron job `tonyapps_advancedreports_order_export` (group `default`) takes its schedule from config via
  `<config_path>tonyapps_advancedreports/order_export/schedule</config_path>`.
- **New since last run:** the last exported `sales_order.entity_id` is kept in core's `flag` table (flag code
  `tonyapps_advancedreports_order_export_last_id`). Each run exports `last < entity_id <= max` and advances the mark
  only after the email is sent, so an order lands in exactly one email even if a run fails or is skipped.
- **Settle window:** "max" is the highest ID among orders created more than 10 minutes ago (`SETTLE_MINUTES`). Order
  IDs are assigned at insert but visible only at commit, not in ID order; without the window a run could see order 102
  while 101's checkout was still committing, move the mark past 101 and never send it. Max order ID walks the primary
  key down instead of `MAX()` over a `created_at` range.
- **Cap:** at most 1000 orders per run (`MAX_ORDERS_PER_RUN`); the mark moves to the last one sent, so a backlog drains
  over the following runs.
- **Email disabled** (`system/smtp/disable`, *Advanced > System > Mail Sending Settings > Disable Email
  Communications*): the run logs a warning and stops without moving the mark, instead of core silently dropping the
  email.
- **First run:** orders from the last 24 hours (`FIRST_RUN_HOURS`), so switching it on doesn't mail the whole history.
- **Every state is included.** A state filter would lose orders for good: a `pending_payment` order skipped today has a
  lower ID than the mark by the time it's paid. The state column tells the ERP. `ponytail:` no re-send on later status
  changes; that's a "changed since" feed, if ever needed.
- No new orders → no email. No recipients or disabled → no run.
- Attachments: Magento's mail wrapper keeps only the first text part, so the CSVs are added to the underlying Symfony
  message (`MixedPart` + `DataPart`, from core's `symfony/mime`). A transport without core's Symfony message fails
  with a clear error (mark stays).
- The email renders in the **frontend** area of the default store view. In adminhtml the shared
  `design/email/header_template` fails ("Template file 'header.html' is not found").
- Email Designer real-data preview: `Model\EmailPreview` (registered on `Tonyapps\EmailDesigner\Model\PreviewPool` in `etc/di.xml`, duck-typed). Records: "Orders since last export" (the range the next run would send, `ScheduledOrderExport::nextRange()`) and "Orders of the last 24 hours" (`nextRange(true)`, the first-run window), each offered only when it has orders. Variables (`count`, `first`, `last`) from `ScheduledOrderExport::emailVariables()`. Read-only: the mark is not moved, no CSV is built, nothing is sent; no placeholders. `getStoreId()` returns null: the email goes out from the default store view, as `AttachmentMailer` sends it.
- Email Designer (`etc/di.xml`, ignored without `Tonyapps_EmailDesigner`): starter
  `view/adminhtml/email_designer/order_export.mjml` for `tonyapps_advancedreports_order_export_email_template`; no
  blocks (the email is one sentence).

### Planned, not built

**Reports (v1), phase 2** (1 and 2a built):

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

**Search reports, phase 2.** `search_query` only stores lifetime counters (`popularity`, last `updated_at`), so it
can't answer "in this date range". Core's Search Terms grid covers the lifetime view, including zero results.
**Exception to "no new tables"** (see Data model). History starts when the module is installed; nothing can be
backfilled.

| # | Code | Rows / metrics | Chart |
|---|---|---|---|
| 8a | `search_top` | per term in range: searches, results (latest) | top 20 bar |
| 8b | `search_zero_results` | terms with 0 results in range: searches, last seen | none |
| 8c | `search_trend` | per day/week/month: searches, unique terms, % zero-result | line |

**Scheduled sales email, phase 3.**

- Config `Stores > Configuration > Tonyapps > Advanced Reports > Email`: enabled, frequency (daily / weekly on Monday
  / monthly on the 1st), recipient **admin roles** (multiselect, sent to active users in those roles), store scope.
- Cron daily at 06:00 store time; sends when the frequency matches. Last completed period compared with the one
  before it.
- Body: HTML email template with `sales_overview` totals and deltas, top 5 products, top payment methods, link to the
  admin report. `ponytail:` no CSV attachment, the link covers it; add one via Laminas MIME if recipients have no
  admin access.

**REST API, phase 4.**

- `GET /V1/tonyapps/reports` lists codes and titles the caller may see.
- `GET /V1/tonyapps/reports/:code?from=&to=&store_ids[]=&state[]=` returns `{columns, rows, totals}`.
- Auth: admin token or integration. ACL is the per-report resource, so an integration only gets the reports it's
  granted.

## Data model

- No module tables yet. The scheduled export mark is a row in core's `flag` table
  (`tonyapps_advancedreports_order_export_last_id`).
- Planned (phase 2): **`tonyapps_advancedreports_search_daily`**: `date`, `store_id`, `query_text`, `searches`,
  `num_results` (latest), PK (`date`, `store_id`, `query_text`). Written by an after-plugin on
  `Magento\Search\Model\Query::saveIncrementalPopularity()` (core calls it once per storefront search) with one
  `INSERT … ON DUPLICATE KEY UPDATE searches = searches + 1`. `ponytail:` no purge; add a cron with a retention setting
  if the table grows too big.

## Configuration and ACL

| Path | Default | Notes |
|---|---|---|
| `tonyapps_advancedreports/order_export/enabled` | `0` | default scope only |
| `tonyapps_advancedreports/order_export/schedule` | `0 6 * * *` | cron expression, server time |
| `tonyapps_advancedreports/order_export/recipients` | — | comma-separated emails |
| `tonyapps_advancedreports/order_export/files` | `orders,items` | `Model/Config/Source/ExportFiles` |
| `tonyapps_advancedreports/order_export/email_template` | `tonyapps_advancedreports_order_export_email_template` | `view/frontend/email/order_export.html` |

- Section **Stores > Configuration > Tonyapps > Advanced Reports > Order Export**, ACL
  `Tonyapps_AdvancedReports::order_export_config`.
- Report ACL under `Magento_Reports::report`: `Tonyapps_AdvancedReports::reports` > `::sales_overview`,
  `::sales_by_payment`, `::order_export`, `::order_items_export`. Menu items mirror these.
- Email template can be replaced by a **Marketing › Email Templates** template or a `Tonyapps_EmailDesigner` design
  (**Marketing › Communications › Email Designer**).
- Template ID follows core's config-path convention so the setting can offer the default:
  `tonyapps_advancedreports_order_export` was renamed to `tonyapps_advancedreports_order_export_email_template`. Theme
  overrides keep working (by file name). A custom template created from the old ID keeps its content, but its
  *Template Information* no longer names the default it was loaded from.

## Decisions and constraints

Answered 2026-09-30:

1. **v1 = Tier 1 reports + search reports + scheduled sales email + REST API.**
2. **Core Magento data only.** B2B, StockNotification (Tonyapps) and Riskified are custom modules, so no dependency
   on them and no reports for them. If they need reports, those live in their own modules and register into the
   `ReportPool` via `di.xml`.
3. **Margin only when there is cost data.** `sales_order_item.base_cost` used when > 0; otherwise margin columns are
   hidden. No extra config.
4. **Audience: admin grid + chart + CSV export, scheduled email, and a REST API.**
5. **Order export lives here (added 2026-10-25), not in its own module.** Two row-level reports (orders, order items)
   plus a scheduled email that sends new orders since the last run as CSV attachments. CSV only. Answered 2026-10-25:
   both granularities, email delivery, "new since last run", CSV only.

## Known limitations and open points

Deferred (not in v1):

- Search → order conversion (needs session tracking).
- Reports for the custom modules (B2B, StockNotification, Riskified) — belong in those modules (Decision 2).
- Cohort retention / RFM, inventory valuation, view→order conversion (needs `report_event` enabled).

Open questions for the owner:

- Is the recipient list by admin role OK, or do you want free-text emails too? (Sales email, phase 3. The built order
  export already uses free-text emails.)
- Default order-state filter: exclude only `canceled`, or also `closed` (fully refunded)?
- Dev data has 23 orders. Is there a prod-sized dump to check query speed against before phase 2?

Not checked in a real browser: the phase 1 report page, the order export config page and the two order export report
pages (admin 2FA blocks scripted login).

## Tests

```bash
vendor/bin/phpunit -c dev/tests/unit/phpunit.xml.dist app/code/Tonyapps/AdvancedReports/Test/Unit
```

- Unit tests: `Block/Adminhtml/ReportTest`, `Controller/Adminhtml/Report/ExportTest`, `Model/{AttachmentMailer,EmailPreview,
  CsvWriter,FilterFactory,Filter,OrderSelect,ScheduledOrderExport}Test`, `Model/Report/OrderExportTest`.
- Order export check (2026-10-25): 7 unit tests for the mark (disabled, no recipients, nothing new, sends then
  advances, failed send keeps the mark, first run = last 24h, orders-only file). Both reports against the dev DB:
  27 orders / $1,208.03 and 29 item rows / 30 units, matching raw SQL. Store-time conversion (18:27 UTC → 11:27 PDT).
  Real send to Mailpit: subject, body, two `text/csv` attachments, mark 36 → 39, second run sent nothing.

## Change history

- **2026-11-16** — Released as free software under GPL-3.0-or-later (owner decision): `LICENSE` added,
  `composer.json` license changed from `proprietary`, License section in the README.
### 2026-11-15 Email Designer real-data preview

- `Model\EmailPreview` (see Scheduled order export). The range logic (mark, first-run fallback, settle window, cap) moved from `execute()` into `ScheduledOrderExport::nextRange()` and the email variables into `emailVariables()`, both used by the export and the preview. Tests in `EmailPreviewTest`.

### 2026-11-13 Email Designer blocks and starter designs

- `etc/di.xml` registers a starter design for the order export email (heading from the subject + the sentence).

### 2026-10-30 review fixes

- **:30/:45 timezones** (India, Nepal) no longer break Sales Overview: periodic reports group by UTC quarter hour
  (`OrderSelect::UTC_SLOT`), so every slot falls on one side of local midnight.
- **Scheduled export sends at most 1000 orders per run** (`ScheduledOrderExport::MAX_ORDERS_PER_RUN`) and moves the
  mark to the last one sent; a backlog after a long pause drains over the following runs instead of one email SMTP
  rejects forever.
- **Admin CSV export streams**: order and order-item exports fetch row by row into a temp file that is streamed back,
  instead of `fetchAll` plus one string. Aggregate reports are small and still run whole.
- **Default state filter** also leaves out `pending_payment` and `payment_review` (not yet paid).
- **Row-level amounts** on the admin page shown in the order's own base currency.
- **Max order ID** walks the primary key down instead of `MAX()` over a `created_at` range.
- **Attachments**: a mail transport without core's Symfony message fails with a clear error (mark stays) instead of a
  fatal error.

### 2026-10-25

- Order export built (reports 9a/9b + scheduled order export).

### 2026-09-30

- Phase 1 built; decisions 1–4 answered; `setup:di:compile` fix in NotifyOnNewBrowserLogin.
