---
id: user-account.scim-principal-missing.faq
title: SCIM 负责人或协管用户不存在
type: faq
feature: user-account
scope: super-admin
locale: zh
aliases:
  - SCIM 用户不存在是谁
  - 部门负责人同步失败
  - SCIM 分组管理员不存在
  - 负责人分配后无法同步
related_tools: []
related_pages: []
prerequisites:
  - 已启用 SCIM 用户和部门同步
negative:
  - DooTask 不会用同名或相似账号替代不存在的 SCIM 用户
last_verified: v1.8.89
---

# SCIM 负责人或协管用户不存在

## 问题
同步部门时报“SCIM 负责人或协管用户不存在”，错误中会同时显示 SCIM User ID、引用该用户的分组名称以及 owner 或 admin 角色。

## 原因
UniAuthSync 的 Group 仍将该 User ID 设置为负责人或管理员，但 DooTask 使用当前 SCIM Client 请求 `/scim/v2/Users/{id}` 时得到 404。常见原因是用户已删除、分组关系残留，或该用户不在 SCIM Client 授权的组织和分组范围内。

## 解决
1. 根据错误中的分组名称和 owner/admin 角色，在 UniAuthSync 检查对应负责人或管理员。
2. 确认错误中的用户仍存在且已启用，并位于该 SCIM Client 可访问范围内。
3. 删除失效引用或扩大客户端授权范围后，重新执行同步。

## 不支持
- DooTask 不会用同名、相似账号或兜底管理员替代一个已被 Group 明确引用但无法查询的 SCIM 用户。
