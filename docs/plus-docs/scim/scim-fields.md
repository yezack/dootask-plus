# SCIM 字段映射参考

SCIM v2 标准用户属性与 Dootask 字段对照。

---

## Core Schema (RFC 7643 §4.1)

| SCIM 路径 | 类型 | Dootask 目标 | 说明 |
|-----------|------|-------------|------|
| `id` | string | 不存 | SCIM 服务端内部 ID，仅日志用 |
| `externalId` | string | `users` 扩展字段 | **主匹配键** |
| `userName` | string | `users.email` | 登录标识 |
| `displayName` | string | `users.nickname` | |
| `name.formatted` | string | 扩展字段 | |
| `name.familyName` | string | 扩展字段 | 姓 |
| `name.givenName` | string | 扩展字段 | 名 |
| `name.middleName` | string | 扩展字段 | |
| `active` | boolean | `users.disable_at` | true=null, false=now() |
| `emails[0].value` | string | `users.email`（备用） | userName 不是邮箱时用 |
| `phoneNumbers[0].value` | string | 扩展字段 | |
| `title` | string | `users.profession` | |
| `locale` | string | `users.lang` | 需转换 `zh-CN` → `zh` |
| `timezone` | string | 扩展字段 | |
| `groups[].value` | string | 部门关系 | 建立 user→department 关联 |

## Enterprise Extension (RFC 7643 §4.3)

| SCIM 路径 | Dootask 目标 |
|-----------|-------------|
| `urn:ietf:params:scim:schemas:extension:enterprise:2.0:User.department` | 扩展字段 |
| `urn:ietf:params:scim:schemas:extension:enterprise:2.0:User.employeeNumber` | 扩展字段 |
| `urn:ietf:params:scim:schemas:extension:enterprise:2.0:User.organization` | 扩展字段 |

## 自定义扩展

| SCIM 路径 | Dootask 目标 |
|-----------|-------------|
| `urn:dootask:params:scim:schemas:extension:1.0:User.position` | `users.profession`（覆盖 title） |
| `urn:dootask:params:scim:schemas:extension:1.0:User.bot` | 是否创建为机器人 |

---

## 存储方式

扩展字段统一存入 `users` 表的 JSON 扩展列，key 前缀 `scim_`：

```json
{
  "scim_external_id": "a1b2c3d4",
  "scim_given_name": "三",
  "scim_family_name": "张",
  "scim_phone": "+8613800138000",
  "scim_employee_number": "E00123",
  "scim_department": "技术部",
  "scim_organization": "某公司",
  "scim_timezone": "Asia/Shanghai"
}
```
