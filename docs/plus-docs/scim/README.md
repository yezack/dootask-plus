# SCIM Client 设计

Dootask Plus 作为 SCIM Client，主动从外部 IdP（Azure AD、Okta 等）同步用户和组织架构。

---

## 架构

```
外部 IdP (SCIM v2 Server)
    │
    ├── 定时轮询 GET /Users, GET /Groups
    │
    └── ⚠ 可选 Webhook → POST /api/scim/webhook → 触发即时同步
          │
          ▼
    SCIM Sync Service
          │
          ├── 用户映射 → User 模型
          ├── 部门映射 → department 结构
          └── 状态同步 → 启用/禁用/删除
```

---

## 配置

存储位置：`settings` 表 `name='scim_client'`

```json
{
  "enabled": false,
  "base_url": "https://idp.example.com/scim/v2",
  "auth_type": "bearer",
  "auth_token": "xxx",
  "poll_interval_minutes": 60,
  "user_mapping": {
    "externalId": "externalId",
    "userName": "email",
    "displayName": "nickname",
    "active": "disable_at",
    "emails[0].value": "email",
    "phoneNumbers[0].value": "phone"
  },
  "group_mapping": {
    "displayName": "department_name",
    "externalId": "department_external_id"
  },
  "sync_options": {
    "create_users": true,
    "update_users": true,
    "deactivate_missing": false,
    "create_departments": true,
    "default_password": ""
  }
}
```

---

## 定时轮询

在 `LoopTask` 中加入 `scim:sync` 任务：

```
每分钟检查 → 距上次同步 > poll_interval_minutes → 触发全量同步
```

同步流程：

```
1. GET /scim/v2/Users?startIndex=1&count=100
2. GET /scim/v2/Groups?startIndex=1&count=100
3. 遍历用户 → 映射字段 → createOrUpdate
4. 遍历组 → 映射字段 → 同步部门结构
5. 如 deactivate_missing=true → 禁用本地有但远端无的用户
6. 记录 last_sync_at
```

---

## 用户映射

| SCIM 属性 | Dootask 字段 | 说明 |
|-----------|-------------|------|
| `externalId` | 存储于 `users` 扩展字段 | 下次同步匹配 |
| `userName` | `email` | 登录账号 |
| `displayName` | `nickname` | 显示名称 |
| `name.givenName` | 扩展字段 | 名 |
| `name.familyName` | 扩展字段 | 姓 |
| `active` | `disable_at` | true=null, false=当前时间 |
| `emails[0].value` | `email`（备用） | 当 userName 不是邮箱时 |
| `groups[].value` | 部门归属 | 根据 group 建立部门关系 |

### 新建用户
```php
$user = User::firstOrNew(['email' => $scimUser['userName']]);
$user->nickname = $scimUser['displayName'];
$user->email = $scimUser['userName'];
$user->setExtend('scim_external_id', $scimUser['externalId']);
$user->save();
```

### 更新用户
- 匹配 `email` 或 `externalId`
- 更新变化字段
- 不覆盖手动修改的字段（可配置）

### 禁用用户
- `active=false` → 设置 `disable_at = now()`
- `active=true` → 设置 `disable_at = null`

---

## 部门映射

SCIM Group → Dootask 部门：

```
Group "总公司/技术部/后端组"
  → 创建/匹配部门结构
  → 将用户加入对应部门
```

处理逻辑：
1. 按 `/` 分割 group displayName → 层级
2. 从根开始逐层 findOrCreate 部门
3. 将匹配到的用户加入部门

---

## SCIM 字段采集

额外采集并存入 `users` 扩展字段：
- `externalId` — SCIM 全局唯一 ID
- `name.givenName` / `name.familyName` — 姓名拆分
- `phoneNumbers[0].value` — 电话
- `title` — 职位
- `department` — SCIM 原生部门字段（不与 group 混淆）
- `locale` — 语言偏好

见 [scim-fields.md](./scim-fields.md)

---

## 文件结构

```
app/
├── Scim/
│   ├── ScimClient.php          ← HTTP 客户端，调用 IdP
│   ├── ScimUserMapper.php      ← 用户字段映射
│   ├── ScimGroupMapper.php     ← 部门/组映射
│   └── ScimSyncService.php     ← 同步编排
├── Console/Commands/
│   └── ScimSync.php            ← artisan scim:sync（手动+定时）
├── Http/Controllers/Api/
│   └── ScimController.php      ← Webhook 接收端（可选）
└── Models/
    └── User.php                ← 新增 scim 相关方法
```

---

## API

### 手动触发
```bash
php artisan scim:sync
```

### Webhook（可选）
```
POST /api/scim/webhook
Authorization: Bearer <token>
{"event": "user.created", "externalId": "xxx"}
```

---

## 后续扩展

- [ ] 字段映射 UI 配置页面
- [ ] 同步日志和失败重试
- [ ] SCIM Server 模式（接受外部推送）
- [ ] 增量同步（`lastModified` 过滤）
