# SCIM 集成（对接 UniAuthSync）

DooTask Plus 从 [UniAuthSync](https://github.com/yezack/UniAuthSync) 同步用户，不新增 SCIM 专用表或字段，尽量复用 DooTask 原有用户、部门和离职机制。

> SCIM 负责账号预配与资料同步，不等同于 OIDC 单点登录。当前登录仍使用 DooTask 原有登录流程。

## 字段映射

| UniAuthSync SCIM | DooTask 现有字段 | 规则 |
|---|---|---|
| `emails[0].value` | `users.email` | 唯一匹配键 |
| `displayName` | `users.nickname` | 同步姓名并刷新拼音字段 |
| enterprise `division` + `title` | `users.profession` | 例如 `警察 - 民警` |
| custom `phone` | `users.tel` | 电话未被占用时同步 |
| enterprise `department` | `users.department` | 只匹配唯一同名的现有部门，不创建部门 |
| `active=false` | `disable_at` + `identity=disable` | 按原有离职机制禁用 |

SCIM `id`、`org_id`、身份证等字段不落库。用户邮箱变更会失去关联，因此 UniAuthSync 中已同步用户的邮箱应保持稳定。

部门采用保守默认策略：只给尚无部门的用户绑定唯一同名部门，不覆盖人工维护的部门。设置 `SCIM_REPLACE_DEPARTMENTS=true` 后，UniAuthSync 组织会替换用户现有部门并同步部门群成员。

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

手动同步：

```bash
./cmd artisan scim:sync
```

配置或路由变更后需按项目约定重启 LaravelS/Swoole。
