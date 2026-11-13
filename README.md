# Advanced Reports for Magento 2

Live sales reports with charts, period comparison and CSV export, plus a scheduled order export for your ERP or
accountant.

Magento Open Source's built-in sales reports read summary tables that stay empty until someone runs **Refresh
Statistics**, and they filter only by status and period. Advanced Reports queries your orders directly, so the
numbers are current the moment you open a report, and adds order-level CSV exports that can be emailed on a schedule.

## Features

- **Always up to date** — reports read live order data. No Refresh Statistics step, no waiting for a nightly job.
- **Sales Overview** — orders, revenue, refunds, discounts, average order value and items per order, by day, week or
  month, with a chart that compares the range with the previous period of the same length and a totals row showing
  the change in %.
- **Gross margin when you track cost** — if your products have a cost, Sales Overview adds gross margin and margin %.
  If not, the columns stay hidden.
- **Sales by Payment Method** — orders, revenue, average order value and revenue share for each payment method, with
  a bar chart.
- **Order Export and Order Items Export** — one row per order (customer, billing and shipping address, shipping and
  payment method, coupon, totals) or one row per order line (SKU, quantities ordered, invoiced, shipped, refunded and
  canceled, prices, discount, tax). Ready for ERP and accounting imports.
- **Same filters everywhere** — date range in your store's timezone, store views and order states. Unpaid and
  canceled orders are left out by default.
- **CSV export for every report** — one click, any length. Values are protected against spreadsheet formula
  injection while phone numbers and negative numbers stay intact.
- **Scheduled order export by email** — new orders since the last run are emailed as CSV attachments (orders, order
  lines, or both) on the schedule you set. Each order is sent exactly once, even if a run fails or is skipped.
- **Per-report permissions** — each report has its own admin role permission.

## How it works

Open **Reports › Advanced Reports** and pick a report. Choose the date range, grouping (day / week / month), store
views and order states, then **Show Report**. The page shows a chart and a table; **Export CSV** downloads the same
data.

For the scheduled export, enable it under **Stores › Configuration › Tonyapps › Advanced Reports › Order Export**,
enter a schedule and the recipients. Each run emails the orders placed since the previous run. No new orders, no
email.

## Good to know

- Four reports are available today: Sales Overview, Sales by Payment Method, Order Export and Order Items Export.
- Amounts in the summary reports are shown in your global base currency. Order exports keep each order's own base
  currency, with the currency code in a column.
- The report page shows at most 500 rows of an order export; the CSV always contains every row.
- A scheduled run sends at most 1,000 orders; a larger backlog is sent over the following runs. The first run covers
  the last 24 hours, and orders placed in the last 10 minutes wait for the next run.
- The scheduled export sends every order state and does not re-send an order when its status changes later.
- Dynamic-price bundle components are not exported as separate lines.
- If **Disable Email Communications** is on, the scheduled export waits and does not skip any orders.

## Requirements

- Magento Open Source 2.4 (developed on 2.4.8-p4)
- PHP 8.3 or 8.4
- Magento cron running (for the scheduled export)

## Installation

```bash
composer require tonyapps/module-advanced-reports
bin/magento setup:upgrade
```

Make sure Magento cron is running if you use the scheduled order export.

## Configuration

- **Reports › Advanced Reports** — Sales Overview, Sales by Payment Method, Order Export, Order Items Export.
- **Stores › Configuration › Tonyapps › Advanced Reports › Order Export** — enable, schedule (cron expression,
  default daily at 06:00 server time), recipients, which files to attach, and the email template.
- **System › Permissions › User Roles** — *Reports › Advanced Reports* (one permission per report) and *Stores ›
  Configuration › Advanced Reports: Order Export*.

### Redesigning the email

Pick a template made in **Marketing › Email Templates**, or a design made with the
[Email Designer](https://github.com/tonyappsorg/magento2-email-designer) (**Marketing › Communications › Email
Designer**), in **Order Export › Email Template**. The built-in template is used until you pick another one. A new
design starts from a ready-made copy of the export email.

## For developers

Architecture, decisions, data model and tests are in [AGENTS.md](AGENTS.md).
