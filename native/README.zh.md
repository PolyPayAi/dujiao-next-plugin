# Dujiao-Next 原生 PolyPay 渠道（0.2.1 预览）

此目录完整记录宿主源码修改。基线是上游 `41b86260dc9a60269526057eed8d915844ceb3a3`，修改后的本地宿主提交依次为 `b353044b3f2633a759405b9392e6d7dfcc3f2d68`、`f3cb1a81`（商户归属校验）、`2231f111`（待选支付方式订单金额修复）。补丁适用范围、SHA-256 和所有补丁的校验值与逐文件修改清单见 [manifest.json](manifest.json)。宿主补丁遵循原项目 GPL-3.0，见 [LICENSE](LICENSE)；仓库的 skill 和打包工具按根目录许可证提供。

安装后，“支付渠道 → 新增 → 渠道类型”可以直接选择 **PolyPay**。不涉及数据库结构变更。必须重新构建宿主后端与内嵌前端；复制插件文件夹无法让已有二进制动态识别渠道。

## 应用源码补丁

先备份数据库、上传文件、私有配置和当前运行镜像。宿主源码工作区必须干净；其他版本需要单独审查兼容性，不能盲目套用。

```bash
cd /path/to/dujiao-next
test -z "$(git status --porcelain)"
test "$(git rev-parse HEAD)" = 41b86260dc9a60269526057eed8d915844ceb3a3
git switch -c feat/polypay-native
git am /path/to/dujiao-next-plugin/native/patches/*.patch
```

`git am` 保留作者与完整改动；提交 SHA 会随提交元数据变化，以补丁和清单为审计依据。补丁冲突时先 `git am --abort`，分析版本差异后再移植。

## 构建与验证

要求 Go >= 1.26、Node >= 24、pnpm 10.34.3。完整单元测试中的 go-sqlite3 需要启用 CGO 和系统 C 编译器；发布二进制使用 CGO_ENABLED=0。

```bash
CGO_ENABLED=1 go vet ./...
CGO_ENABLED=1 go test ./...
(cd frontend/admin && pnpm install --frozen-lockfile && pnpm run test && pnpm run build:fullstack)
(cd frontend/user && pnpm install --frozen-lockfile && pnpm run test && pnpm run build)
mkdir -p internal/web/dist/admin internal/web/dist/user
cp -a frontend/admin/dist/. internal/web/dist/admin/
cp -a frontend/user/dist/. internal/web/dist/user/
CGO_ENABLED=0 go build -trimpath -tags release,fullstack -o dujiao-next-native ./cmd/server
```

也可使用宿主原有 Dockerfile 构建完整镜像：`docker build -t dujiao-next:polypay-native-0.2.1 .`。替换服务时复用原数据库、上传文件、日志、配置和 Redis；不要创建空数据目录替代现有卷。应用新版本后，清除浏览器旧资源缓存并验证前台和后台。

## 后台配置

首次启用支付管理功能时，宿主可能要求超级管理员亲自确认合法使用声明。该声明由站点负责人阅读并确认；本集成不会跳过或代为签署。

| 配置项 | 内容 |
| --- | --- |
| 渠道类型 | PolyPay |
| 支付方式 | PolyPay |
| 交互方式 | 跳转 |
| 商户 ID | 正式 PolyPay 商户 ID |
| API Key | 同一商户的有效正式密钥，具有创建、查询和取消订单权限 |
| USD 汇率 | 每 1 单位店铺计价币种兑换的 USD；例如店铺使用 CNY 时填 `0.14` 表示 CNY 10 → USD 1.40；USD 店铺填 `1` |
| 异步通知地址 | `https://store.example.com/api/v1/payments/webhook/polypay` |
| 支付返回地址 | `https://store.example.com/pay` |
| 强制 HTTPS | 默认开启；内网 HTTP 联调可关闭 |

回调地址的 `channel_id` 由创建支付时自动附加，无需先知道渠道 ID。面板字段跟随简体中文、繁体中文、英文切换。API Key 仅由后台保存并掩码返回，不进入顾客前端。

PolyPay 没有 Sandbox 模式。商户 API 与收银台始终使用 HTTPS 正式服务。HTTPS 开关只控制商城回调和返回地址；私有 IP 无法接收公网通知。内网订单可通过付款页面的服务器查单确认状态，充值详情也可使用“检查支付”。临时测试密钥到期后须更换；不能把过期密钥当成长期配置。

## 支付状态与取消

在请求 PolyPay 前，宿主持久化独立支付单号、USD 金额、原币种金额与汇率快照。状态仅由严格验签的 Ed25519 v2 Webhook 或带 API Key 的服务端查询确认；浏览器返回不代表付款成功。只接受已付款状态 2/7，状态 5（人工充值）不会授权商城商品订单。

Webhook 校验固定官方 HTTPS JWKS、原始请求体、商户、production 环境、五分钟时效与 Redis 原子 nonce。**Redis 必须启用且可用**；无法防重放时拒绝处理。落库或验签失败返回真实非 2xx，以便上游重试；本地支付事务保证重复通知不重复发货。

取消订单、超时关闭、余额充值超时和更换支付渠道时，先查询并取消未付的 PolyPay 支付尝试，再执行本地关闭。正在确认的支付不强制关闭；如果付款先成功，按已付款入账并阻止取消。创建请求仍在进行时暂不允许关闭，等待请求结束后重试。创建响应丢失时使用已持久化商户单号找回真实交易，而不是伪造网关单号。更换渠道时，原链接需要先关闭；重用同一渠道的现有有效链接不会重新创建交易。

## 修改范围与升级

- Go：注册 PolyPay provider、原生 REST 客户端、严格 v2 Webhook、主动查单、取消端口、创建前金额快照及回调并发保护、密钥掩码、Webhook 路由。
- 管理前端：增加渠道选项和专用配置表单，覆盖三种面板语言。
- 顾客前端：识别 PolyPay 返回标记并通过既有已鉴权 capture 接口查单。
- 测试：协议/TLS fixture、验签/重放/换钥、金额快照、真实 SQLite 记账幂等和取消并发、Webhook HTTP 状态。

更新上游版本时保留功能分支，先合并上游并检查这份补丁涉及的所有文件，然后重跑验证并导出新的补丁、基线和清单。使用上游自动更新会替换定制二进制，应在完成移植前避免直接覆盖。

## 回滚

优先恢复上一运行镜像或二进制，保留现有数据库与上传卷；不要直接恢复旧数据库覆盖已经产生的新订单。先停用 PolyPay 渠道、查询并处理在途付款，再回退旧程序。源码可在确认干净后按逆序 `git revert <应用补丁产生的提交>`。旧版本不会识别原生 PolyPay 渠道，必须先停用该渠道；未完成付款需要保留可查单/处理能力。

## 验收记录

2026-10-20：全量 Go vet/test、原生协议 race、管理端 28 项测试和 fullstack 构建、顾客端 62 项测试和构建通过。使用正式测试账号创建 USD 0.14 订单并查询、取消成功，最终为已取消，没有实际支付。真实付款、公网异步通知及真实商品发货尚未验收；不得据此声明已完成付款闭环。96 环境已由站点负责人确认声明，原生渠道 ID 1 已配置并启用。

96 测试实例已部署 `dujiao-next:polypay-native-0.2.1`，宿主服务器源码提交依次为 `caf0e02`、`0d5ff86`、`8183f41`（通过同一补丁应用产生，与本地提交补丁等价）。前后台 HTTP 正常，实际加载的管理端资源包含原生 PolyPay 配置，回调缺少必需渠道 ID 时返回 HTTP 400。部署前已保留旧镜像并备份现有 SQLite 数据库和配置；无数据库表结构/初始化 SQL 变更。服务器端覆盖文件为私有目录中的 `polypay-native.override.yml`，后续重启要继续带上此覆盖文件，避免被基础 compose 的旧镜像设置覆盖。


0.2.1 修复记录：PolyPay 未选择支付方式的收银台订单（状态 0）在 `amount` 保存 USD 金额，`fiat_amount` 为 0；选择支付方式后，`amount` 才是代币数量，USD 金额改由 `fiat_amount` 表示。插件现在按明确的订单状态及支付方式字段区分两种金额语义，未选择支付方式就取消/过期的订单保留原始 USD `amount`。已支付或确认中的订单仍严格校验正数 `fiat_amount`，不能使用代币数量替代。新增真实 HTTP 协议回归测试，覆盖建单、查询、取消、错误金额拒绝及零法币金额拒绝。

96 上已通过商城真实游客建单接口验证：CNY 1.00 → USD 0.14，返回 HTTPS 收银台链接；随后通过正常后台接口取消该验证订单，PolyPay 支付关闭且测试卡密库存恢复。没有付款或人工标记已付，也没有取消用户正在测试的订单。真实付款、公网异步通知及实际发货仍未验收。
