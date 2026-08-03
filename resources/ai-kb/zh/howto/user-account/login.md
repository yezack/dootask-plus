---
id: user-account.login.howto
title: 登录账号
type: howto
feature: user-account
scope: end-user
locale: zh
aliases:
  - 怎么登录
  - 登录 DooTask
  - 登录方式
  - 怎么进系统
  - 登不上
  - 密码登录
  - 统一身份登录
  - UniAuthSync
  - 管理员应急登录
related_tools: []
related_pages: []
prerequisites: []
negative:
  - 邮箱、密码各自最长 32 字符，超过提示「帐号或密码错误」
  - 账号被停用（disable_at 非空）会提示「帐号已停用」，需联系管理员
  - 开启「注册需邮箱验证」时，未验证邮箱的账号无法登录，必须先完成验证（[[user-account.email-verify.howto]]）
  - 多次失败后系统会强制要求填验证码（[[user-account.login-codeimg.howto]]）
  - 统一身份登录只匹配 UniAuthSync SCIM 中明确的邮箱与已有 DooTask 账号，不会自动注册或创建本地账号
  - 统一身份登录失败、凭据过期或账号未匹配时，返回登录页并显示通用错误提示，请联系管理员核对账号邮箱及统一认证配置
  - SCIM 创建的用户不会因本地随机初始密码而被强制跳转到修改密码页面
last_verified: v1.8.89
---

# 登录账号

## 入口
- 登录页：`/login`
- 客户端启动时自动跳转

## 支持的登录方式
DooTask 同时支持以下登录方式（在登录页可切换）：

1. **邮箱 + 密码**：默认方式
2. **扫码登录**：客户端/App 已登录后扫码登录另一端，详见 [[user-account.login-qrcode.howto]]
3. **LDAP**：管理员启用 LDAP 时，邮箱+LDAP 密码也可登录，系统自动同步用户
4. **统一身份登录（UniAuthSync）**：管理员启用后，Web 登录页显示「统一身份登录」按钮，通过 OIDC Authorization Code + PKCE 完成身份确认；DooTask 仍使用自己的登录 token
5. **插件 SSO**：管理员配置 OAuth/SAML 插件后，登录页可能出现对应按钮（具体取决于插件）

## 统一身份登录步骤
1. 在 Web 登录页点击「统一身份登录」
2. 跳转到 UniAuthSync 完成认证和授权
3. 系统按 OIDC `sub` 查询 SCIM 用户，并以 SCIM 中明确、合法的邮箱唯一匹配已有 DooTask 账号
4. 匹配成功后签发 DooTask 自有 token 并进入系统；不会把 UniAuthSync token 直接用作 DooTask token

正常统一登录会复用 UniAuthSync 当前会话。用户从 DooTask 主动退出后，下一次统一登录会进入账号选择页，可继续当前账号或切换账号；这是 OIDC `prompt=select_account` 的标准语义。需要强制重新输入凭据时使用 `prompt=login`，无交互探测使用 `prompt=none`。

SCIM 预配的新用户使用随机本地密码占位，并将 `changepass` 设为 `0`。因此统一身份登录成功后会直接进入 DooTask，不要求用户修改一个自己并不知道的本地随机密码。

当管理员关闭普通本地登录入口时，登录页仍提供「管理员应急登录」入口。该入口只允许已有 DooTask 管理员使用本地账号密码登录，普通用户仍必须通过统一身份登录；同时关闭本地注册。

## 邮箱密码登录步骤
1. 填邮箱、密码
2. 若系统判定需要验证码（API `login/needcode` 返回 need），填图形验证码
3. 提交，成功返回 token 和用户信息

## 登录后行为
- 写入 `last_ip`、`last_at`、`line_ip`、`line_at`
- 生成 token（默认 30 天有效，可在系统设置 token_valid_days 调整）
- 首次登录会自动创建「📝 个人项目」

## 常见错误
- **帐号或密码错误**：邮箱不存在或密码不对；连续错误后会触发验证码
- **请输入验证码 / 请输入正确的验证码**：触发风控，需填图形验证码
- **您还没有验证邮箱**：需先按 [[user-account.email-verify.howto]] 完成验证
- **帐号已停用**：账号被管理员禁用，联系管理员恢复
