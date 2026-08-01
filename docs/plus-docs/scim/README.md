# SCIM 集成（对接 UniAuthSync）

DooTask Plus 从 [UniAuthSync](https://github.com/yezack/UniAuthSync) 同步用户，不新增 SCIM 专用表或字段，尽量复用 DooTask 原有用户、部门和离职机制。

> SCIM 负责账号预配与资料同步，OIDC 负责统一登录，两者可独立启用。启用 OIDC 不代表已经启用 SCIM 权威同步。

## 字段映射

| UniAuthSync SCIM | DooTask 现有字段 | 规则 |
|---|---|---|
| `emails[0].value` | `users.email` | 唯一匹配键 |
| `displayName` | `users.nickname` | 同步姓名并刷新拼音字段 |
| enterprise `division` + `title` | `users.profession` | 例如 `警察 - 民警` |
| custom `phone` | `users.tel` | 电话未被占用时同步 |
| Organizations / Groups | `user_departments` | 按稳定外部 ID 映射并创建或更新部门树 |
| User direct Groups | `users.department` | 映射到对应 DooTask 部门；可配置为权威替换 |
| `active=false` | `disable_at` + `identity=disable` | 按原有离职机制禁用 |

SCIM `id`、`org_id`、身份证等字段不落库。用户邮箱变更会失去关联，因此 UniAuthSync 中已同步用户的邮箱应保持稳定。

设置 `SCIM_REPLACE_DEPARTMENTS=true` 后，UniAuthSync 的直接 Group 成员关系会权威替换用户部门，同时保留该用户作为负责人或协管必须加入的部门。

`active=true` 默认不恢复 DooTask 中人工离职的用户；设置 `SCIM_REACTIVATE_USERS=true` 后才由 UniAuthSync 状态自动恢复。

## 同步方式

### 定时全量同步

每分钟检查一次同步间隔，达到 `SCIM_POLL_INTERVAL` 后：

1. `POST /oauth/token` 获取 client_credentials token；
2. 分页调用 `GET /scim/v2/Users`；
3. 按邮箱创建或更新用户；
4. 记录本次尝试时间；全部成功后另行记录最后成功时间。

UniAuthSync 的用户列表只返回启用用户，因此离职/删除通知依赖下面的 SET Webhook，不能用“列表中不存在”判断离职。

### RFC 9967 SET Webhook

在 UniAuthSync OAuth 客户端中配置：

| 字段 | 值 |
|---|---|
| `scim_event_uri` | `https://dootask.example.com/api/scim/webhook` |
| `scim_event_secret` | 随机共享密钥 |

UniAuthSync 使用以下格式推送：

- Content-Type：`application/secevent+jwt`
- 请求体：RS256 compact JWT SET
- 签名头：`X-SCIM-Event-Signature: <HMAC-SHA256 hex>`

DooTask 对完整原始请求体验证 HMAC，并校验 `iss`、`aud`、`iat`、`exp`、`jti` 和 `sub_id`，再用 `jti` 防重放。收到用户通知后根据 `sub_id.uri` 回调 UniAuthSync SCIM 用户详情，再复用同一字段映射同步。

## 配置

```env
SCIM_SERVER_URL=https://oauth.example.com
# 可选；仅当 SET 的 iss 与 SCIM_SERVER_URL 不同时填写
SCIM_ISSUER=
SCIM_CLIENT_ID=<UniAuthSync client id>
SCIM_CLIENT_SECRET=<UniAuthSync client secret>
SCIM_VERIFY_TLS=true
SCIM_POLL_INTERVAL=60
SCIM_LOCK_SECONDS=3600
SCIM_WEBHOOK_SECRET=<同 scim_event_secret>

# 留空时为新用户生成随机初始密码；仅兼容旧策略时才配置前缀
SCIM_DEFAULT_PASSWORD_PREFIX=

# 默认不创建部门、不覆盖人工部门、不自动恢复人工离职
SCIM_SYNC_DEPARTMENT=true
SCIM_REPLACE_DEPARTMENTS=false
SCIM_REACTIVATE_USERS=false
```

`SCIM_VERIFY_TLS=true` 默认校验 UniAuthSync 的 HTTPS 证书。隔离内网使用自签名证书时，可在确认网络边界可信后设置为 `false`；公网或跨网络部署不要关闭证书校验。

UniAuthSync 的 OIDC 单点登录链路使用独立开关 `UNIAUTH_VERIFY_TLS=true`，同样仅在可信隔离内网自签名场景下设置为 `false`。

## OIDC 统一登录模式

```env
# disabled：禁用统一登录，仅显示 DooTask 本地登录
# available：统一登录和本地登录同时可用
# required：普通用户必须统一登录，管理员保留应急本地登录入口
UNIAUTH_MODE=available
```

`UNIAUTH_MODE` 是当前推荐配置。留空时继续兼容旧配置：

| 旧配置 | 等效模式 |
|---|---|
| `UNIAUTH_ENABLED=false` | `disabled` |
| `UNIAUTH_ENABLED=true` 且 `UNIAUTH_ALLOW_LOCAL_LOGIN=true` | `available` |
| `UNIAUTH_ENABLED=true` 且 `UNIAUTH_ALLOW_LOCAL_LOGIN=false` | `required` |

`required` 模式会在后端拒绝普通用户的本地密码登录和注册，不能通过直接调用 API 绕过；登录页只保留统一登录主入口和管理员应急登录入口。

手动同步：

```bash
./cmd artisan scim:sync
```

配置或路由变更后需按项目约定重启 LaravelS/Swoole。
