# Dujiao-Next 的 PolyPay 支付插件

[English](README.md) · **简体中文**

为 Dujiao-Next 增加原生 PolyPay 支付渠道，提供完整宿主源码补丁及修改记录。

**版本：** 0.2.1 预览版 · **宿主基线：** `41b86260`

## 使用 AI 快速接入

复制下面的提示词，发送给 Claude Code、Cursor、Codex 等 AI 编程工具：

```text
请安装并使用 Dujiao-Next 的 PolyPay 接入 Skill：
https://github.com/PolyPayAi/dujiao-next-plugin/tree/feat/standalone-payments/skills/polypay-dujiao-next

然后为当前 Dujiao-Next 项目接入原生 PolyPay 支付渠道，完成配置及建单、取消验证。
```

[查看 Skill 说明](skills/polypay-dujiao-next/SKILL.md)

## 原生渠道安装

按照[原生安装说明](native/README.zh.md)核对宿主版本、应用补丁并重新构建，在后台直接选择 PolyPay。

[源码修改清单与校验值](native/manifest.json) · [宿主补丁](native/patches)

<details>
<summary>旧版易支付桥接说明（可选）</summary>

不修改 Dujiao-Next 源码、数据库结构或二进制程序。在商城后台使用已有的「易支付 v1 → 跳转支付」渠道接入。桥接本身需要独立部署，不能作为 ZIP 上传给 Dujiao-Next 动态加载。

版本：**0.1.0 预览版；协议测试已通过，尚待真实宿主验收。** 源码核对基准为 Dujiao-Next `41b86260`。

### 安装

1. 将完整安装包部署到独立目录，例如 `/opt/polypay-dujiao-next`；网站根目录必须仅指向其中的 `public/`。
2. 将 `config.example.php` 复制到网站根目录以外，例如 `/etc/polypay-dujiao-next.php`。在 PHP-FPM 配置环境变量 `POLYPAY_BRIDGE_CONFIG`，指向这个私有文件。
3. PHP 需要 curl、json、bcmath、sodium。使用单实例和持久化私有存储；`storage_dir` 必须在网站根目录以外，不能放进临时容器文件系统。
4. 生成独立的 `epay_key`：`php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'`。这是桥接与商城的认证密钥，不是 PolyPay API Key。
5. 填写 正式 API Key、商户 ID、`environment=production`、桥接 HTTPS 地址及商城回调/返回地址。
6. `usd_rate` 表示商城提交金额每一单位折合多少 USD。例如 `0.14` 表示 10 元换算为 1.40 USD；商城提交 USD 时填 `1`。下单前保存汇率快照，配置变更不会影响旧订单。

### Docker 部署

仓库附带 `Dockerfile` 和 `deploy/compose.yml`。设置 `POLYPAY_BRIDGE_LISTEN_IP`（例如 `127.0.0.1`）及私有配置路径 `POLYPAY_BRIDGE_CONFIG_FILE` 后，执行 `docker compose -f deploy/compose.yml up -d --build`。

私有配置中的 `storage_dir` 填 `/var/lib/polypay-dujiao-next`；映射存储由持久化数据卷保存。配置文件需允许容器 PHP 用户（UID 33）读取，同时限制父目录权限。18083 端口提供 HTTP，由 HTTPS 反向代理转发，配置中的公网地址默认要求 HTTPS。内网测试可设置 `force_https=0`，使用 IP 和 HTTP 地址；正式部署默认 `force_https=1`。PolyPay 收银台和商户 API 继续使用 HTTPS，公网回调无法直接访问内网 IP，因而内网测试不能验证自动发货。

### 商城后台

新增支付渠道：提供方选择「易支付」，版本 `v1`，交互方式 `redirect`，网关地址填桥接域名；商户号与商户密钥分别填 `pid` 和 `epay_key`。签名为 `MD5`，通道类型选择 `alipay`，显示名称设为 `PolyPay`。

`alipay` 只是宿主现有的协议标识，不代表开通支付宝；买家实际在 PolyPay 收银台选择支付方式。不要使用二维码模式；不要未经核算就在商城和桥接同时配置汇率换算。

`notify_url` 必须与配置中完整地址一致；`return_url` 填商城返回页，不带查询参数（商城自行追加的订单追踪参数会保留）。自定义了商城回调路径时，两端必须同步设置。

### 回调与运维

使用官方 SDK 验证 Webhook v2 的签名、商户、环境、时间和 nonce，只接收状态 2、7 作为已付款。浏览器返回不标记支付成功。核对锁定的 USD 总额后，按原始商城金额通知宿主，由宿主完成发货或充值。

先持久化付款事实，再通知商城。商城返回 `success` 才记录投递成功；失败可由 PolyPay 重试，或运行：

```bash
php bin/payment.php retry 商城网关支付订单号
php bin/payment.php reconcile 商城网关支付订单号
php bin/payment.php cancel 商城网关支付订单号
```

CLI 同样需要 `POLYPAY_BRIDGE_CONFIG`。取消前后都会查单；若付款在取消期间完成，应查单补发付款通知。确认中的订单不强制取消。

**易支付 v1 不会通知桥接商城里的取消、关单和支付链接替换操作，所以无法自动同步这些动作。** 需要主动取消对应支付订单或等待 PolyPay 过期；期间旧链接可能仍可付款。迟到支付交由宿主异常处理，必要时人工核对。必须接受这个边界后再投入生产。

### 验证与打包

执行 `php tests/run.php`、`php tests/http-config.php` 和 `python3 scripts/package.py`。正式使用前，先验证下单和取消，不进行付款；经明确授权的小额真实付款后，再验证注册用户、游客购买、余额充值、发货、重复/非法回调、金额校验、投递失败重试、过期及取消；当前测试不代表真实联调通过。

</details>
