# SCIM 集成设计（对接 UniAuthSync）

Dootask Plus ← SCIM ← UniAuthSync（OAuth 服务器 `https://oauth.xz.sjq.sh`）

---

## 实际数据样例

```
SCIM User:
  id:          "abb1bb05c7194003b7c16a44683cf9df"
  userName:    "066182"              ← 警号
  name.familyName: "唐佳辉"          ← 中文姓名
  displayName: "唐佳辉"
  emails[0].value: "066182@sjq.sh"   ← 警号@sjq.sh
  active:      true
  groups:      []                    ← 暂无分组
```

---

## 字段映射

| SCIM | Dootask | 说明 |
|------|---------|------|
| `externalId` (=`id`) | `users` 扩展 `scim_external_id` | 主匹配键 |
| `userName` | — | 警号，存扩展 |
| `emails[0].value` | `users.email` | `警号@sjq.sh` 作为登录邮箱 |
| `displayName` | `users.nickname` | 中文姓名 |
| `name.familyName` | 扩展 `scim_family_name` | |
| `active` | `users.disable_at` | true=null, false=now() |
| `groups[].value` | 部门归属 | 按 group 名匹配/创建部门 |

### 密码策略
```php
// 新建用户默认密码
'password' => Hash::make('sjq@' . $userName)   // sjq@066182
```

---

## 同步方式

### 1. 定时轮询（LoopTask）

```
每 60 分钟:
  GET /oauth/token (client_credentials)
  GET /scim/v2/Users?startIndex=1&count=100
  → 遍历 → 匹配 email → createOrUpdate
  → 记录 last_sync_at
```

### 2. RFC 9967 SET Webhook（推送）

在 UniAuthSync 客户端配置中填写：

| 字段 | 值 |
|------|-----|
| `scim_event_uri` | `https://dootask.xz.sjq.sh/api/scim/webhook` |
| `scim_event_secret` | 16 位随机字符串 |

dootask 接收 HMAC-SHA256 签名验证后处理三类事件：
- `prov:create:notice` → 创建用户
- `prov:patch:notice` → 更新用户
- `prov:delete` → 禁用用户

---

## Auth 配置

```env
# dootask .env
SCIM_SERVER_URL=https://oauth.xz.sjq.sh
SCIM_CLIENT_ID=710f75a2dc9c465fbaaf9cb9f31cb145
SCIM_CLIENT_SECRET=189a7ebc77ea8a7be3ff8267f2c4c8f791aeabf536a8762961ad255bad7ee6e2
SCIM_POLL_INTERVAL=60
SCIM_WEBHOOK_SECRET=<同 scim_event_secret>
SCIM_DEFAULT_PASSWORD_PREFIX=sjq@
```

---

## 实现清单

- [ ] `app/Scim/ScimClient.php` — HTTP 客户端，含 token 获取
- [ ] `app/Scim/ScimUserMapper.php` — 字段映射 + createOrUpdate
- [ ] `app/Console/Commands/ScimSync.php` — `artisan scim:sync`
- [ ] `app/Http/Controllers/Api/ScimWebhookController.php` — SET 推送接收
- [ ] `app/Tasks/LoopTask.php` — 注册定时任务
- [ ] `routes/api.php` — 注册 webhook 路由
