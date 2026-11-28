# Changelog

## [Unreleased]

## [1.2.0] - 2026-11-28

- fix(givewp): no refunds for tolerance-paid donations, green preflight
- docs(plugins): add 6-language READMEs (EN/zh-CN/ru/es/de/tr) and YouTube walkthrough previews across all 25 Paymos-labs repos
- chore: bundle Paymos PHP SDK v1.13.0
- chore: rebuild canonical CMS package

- Stopped offering and sending refunds for a donation Paymos completed inside its underpayment tolerance (for example 9.95 of 10). Paymos refuses both refund modes for such a payment, so the request ended as Failed; the plugin now keeps it waiting with `refund_amount_unavailable` without a request and hides the refund button for that mode.

## [1.1.1] - 2026-11-22

- chore: rebuild canonical CMS package

## [1.1.0] - 2026-11-21

- feat(release): automate Odoo 20.0 and GiveWP releases across all 21 ecosystem mirrors
- chore: bundle Paymos PHP SDK v1.11.1
- chore: rebuild canonical CMS package

## 1.0.1

- Trusted server-confirmed final `paid` settlement status, preserved 24-decimal NEAR amount formatting, and expanded tolerance refund reconciliation coverage.

## 1.0.0

- Added Paymos hosted checkout for one-time donations in GiveWP Visual and legacy forms.
- Added signed invoice verification, recovery of interrupted checkout, and native GiveWP receipts.
- Added full and overpayment refund requests and reconciliation for authorized GiveWP staff.
