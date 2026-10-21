# Dujiao-Next 的 PolyPay 支付插件

[English](README.md) · **简体中文**

为 Dujiao-Next 增加原生 PolyPay 支付渠道，提供完整宿主源码补丁及修改记录。

**版本：** 0.2.1 预览版 · **宿主基线：** `41b86260`

## 使用 AI 快速接入

复制下面的提示词，发送给 Claude Code、Cursor、Codex 等 AI 编程工具：

```text
请安装并使用 Dujiao-Next 的 PolyPay 接入 Skill：
https://github.com/PolyPayAi/dujiao-next-plugin/tree/master/skills/polypay-dujiao-next

然后为当前 Dujiao-Next 项目接入原生 PolyPay 支付渠道，完成配置及建单、取消验证。
```

[查看 Skill 说明](skills/polypay-dujiao-next/SKILL.md)

## 原生渠道安装

按照[原生安装说明](native/README.zh.md)核对宿主版本、应用补丁并重新构建，在后台直接选择 PolyPay。

[源码修改清单与校验值](native/manifest.json) · [宿主补丁](native/patches)
