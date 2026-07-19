# SCIM 字段映射参考

本集成不修改数据库结构，只使用 DooTask 已有字段。

| SCIM 路径 | DooTask 字段 | 当前行为 |
|---|---|---|
| `id` | — | 仅用于 SET 回调，不持久化 |
| `userName` | — | 邮箱缺失时拼接 `@sjq.sh` 作为兜底 |
| `emails[0].value` | `users.email` | 用户匹配键 |
| `displayName` | `users.nickname` | 同步 |
| `active` | `users.disable_at`、`identity` | false 禁用；true 默认不自动恢复 |
| enterprise `division` | `users.profession` | 与 title 拼接 |
| enterprise `title` | `users.profession` | 与 division 拼接 |
| enterprise `department` | `users.department` | 唯一同名现有部门 |
| custom `phone` | `users.tel` | 无冲突时同步 |

以下数据不落库：SCIM `id`、organization ID、姓名拆分、身份证、自定义 role、timezone 和 group ID。
