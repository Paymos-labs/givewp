# Paymos GiveWP 稳定币捐赠插件

[English](README.md) | **简体中文** | [Русский](README.ru.md) | [Español](README.es.md) | [Deutsch](README.de.md) | [Türkçe](README.tr.md)

在 GiveWP 中通过 Paymos 托管收银台接收单次稳定币捐赠（USDT/USDC 收款）。Paymos 为 GiveWP 的 Visual 表单与传统捐赠表单新增稳定币支付方式。捐赠者可从您站点的状态页打开 Paymos 收银台；插件向 Paymos 核验付款完成后，GiveWP 才会记录已确认的捐赠并出具原生收据。

[![观看视频：12 款官方 CMS 稳定币支付插件 — 一键连接（Paymos）](https://i.ytimg.com/vi/VMLUGVumYkg/maxresdefault.jpg)](https://www.youtube.com/watch?v=VMLUGVumYkg)
> **视频演示（1:12，提供 6 种语言字幕）：** [在 YouTube 观看](https://www.youtube.com/watch?v=VMLUGVumYkg)

## 概述

Paymos Stablecoin Donations 将 Paymos 托管收银台直接接入 GiveWP，支持在 Visual 表单和传统表单中接收单次稳定币捐赠。

- 捐赠者从您 WordPress 站点的专属状态页进入 Paymos 托管收银台。
- 付款确认绝不单纯依赖浏览器跳转——只有在签名 Webhook 通知和 Merchant API 反向校验均确认付款后，GiveWP 才会记为已确认捐赠并生成原生收据。
- 支持在 WordPress 后台直接发起全额退款和超额退款。
- 采用 GPLv2 或更高版本协议开源（[LICENSE](LICENSE)）。当前稳定版本：`1.0.1`。

## 环境要求

- WordPress `6.9` 及以上版本（已测试兼容至 `7.1`）
- GiveWP `4.19.1.1` 及以上版本
- PHP `7.4` 及以上版本
- 启用 HTTPS 的 WordPress 站点
- Paymos 账号及项目
- 需在每个站点单独启用插件（支持单次捐赠）

## 安装与一键连接

1. 先安装并启用 GiveWP，再安装并启用 **Paymos Stablecoin Donations**（可通过 WordPress.org 插件目录或 [Releases](https://github.com/Paymos-labs/givewp/releases) 获取）。
2. 打开 **GiveWP → Settings → Payment Gateways → Paymos**，选择 **Sandbox** 或 **Live** 以匹配 GiveWP 的支付模式。
3. 点击 **Connect Paymos**，在 Paymos 授权页确认站点和项目。
4. 在需要接受稳定币捐赠的 Visual 或传统表单中启用 Paymos。

## 外部服务与安全机制

本网关依赖 Paymos 提供的托管收银台与商户 API。请查阅 [Paymos 服务条款](https://paymos.io/zh-hans/terms)与[隐私政策](https://paymos.io/zh-hans/privacy)。

**连接授权。** 管理员启动一键连接时，WordPress 会向 `https://app.paymos.io/connect/cms/device` 发送 `client_id=paymos-cms` 和 `scope=cms:bootstrap`。浏览器授权链接会携带 `givewp` 插件标识、站点 URL、用户验证码，以及同源时的后台返回 URL。在等待授权期间，WordPress 使用设备码授权方式、`client_id` 和保密的 `device_code` 轮询 `https://app.paymos.io/connect/token`。授权完成后，WordPress 将所选环境的凭据加密保存在 option 中，并立即丢弃短时效 OAuth 令牌。

**捐赠账单。** 捐赠者打开 Paymos 收银台时，WordPress 向 `https://api.paymos.io` 发送 `POST /v1/invoices`，仅包含 `project_id`、`amount`、`currency` 及随机生成的 `external_order_id`。捐赠者个人信息和 GiveWP 收据凭据均保留在 WordPress 本地。插件通过 `GET /v1/invoices/{invoice_id}` 精确查询账单，也可通过 `GET /v1/invoices` 结合项目和原始参考号查找中断的请求。所有请求均附带密钥 ID、时间戳与 HMAC 签名，签名密钥始终保留在您的站点本地。

**支付与确认。** 捐赠者在 `https://checkout.paymos.io` 打开托管账单并完成支付。Paymos 会向已关联项目的 WordPress Webhook 发送签名账单通知。插件校验签名后，通过 API 拉取该笔账单详情，核对作用域和支付状态无误后再更新 GiveWP。WordPress 的定时对账任务也会通过 API 持续检查待处理记录。

## 退款

具备权限的工作人员可在 **GiveWP → Paymos refunds** 中发起全额退款或超额退款。WordPress 会发送带签名的 `POST /v1/refunds` 请求，包含 `invoice_id`、`mode` 和随机 `external_order_id`。收款地址由 Paymos 根据原始付款记录自动确定。

插件通过 `GET /v1/refunds/{withdrawal_id}` 追踪退款进度，并在需要时按账单范围查询 `GET /v1/refunds` 列表。核验 Paymos 退款完成后，GiveWP 会记录全额退款；若为超额部分退款，则会保留原始捐赠的已确认状态。

## 常见问题

**捐赠者在刷新页面后如何继续未完成的捐赠？**

如果浏览器能够保存恢复链接，捐赠者可以在同一表单上选择 **Resume your previous Paymos donation**。该链接会打开站点状态页，有效期为 12 小时。站点会校验原始浏览器与 GiveWP 绑定关系，通过后才展示状态或收据。重新正常提交表单则会发起一笔新的独立捐赠。

**断开连接或删除插件后会发生什么？**

停用支付网关会停止接收新的 Paymos 捐赠，但会保留查询未结操作所需的凭据。停用插件会停止其定时对账检查。删除插件会清除本地凭据、浏览器对状态页的访问权限及定时任务。GiveWP 会保留完整的捐赠历史，插件也会为尚未完结的付款和退款保留最少必要的引用信息，便于管理员人工核查。远端 API 密钥和 Webhook 请在 Paymos 项目中管理。

## 支持与链接

- 文档：[paymos.io/zh-hans/docs/cms-givewp](https://paymos.io/zh-hans/docs/cms-givewp)
- 源码与发布：[Paymos-labs/givewp](https://github.com/Paymos-labs/givewp)
- 技术支持：有关 Paymos 结账、连接或退款的问题，请联系 [support@paymos.io](mailto:support@paymos.io)。
- 开源协议：GPLv2 或更高版本（[LICENSE](LICENSE)）
