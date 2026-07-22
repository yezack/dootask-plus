# 部门层级 3→9

## 文件

- `app/Http/Controllers/Api/UsersController.php`
- `resources/assets/js/pages/manage/components/TeamManagement.vue`

## 改动

```diff
- return Base::retError('部门层级最多只能创建3级');
+ return Base::retError('部门层级最多只能创建9级');

- item.level <= 3 / item.level > 3
+ item.level <= 9 / item.level > 9
```

## 说明

官方限制部门最多 3 层嵌套，Plus 将后端校验和前端父部门选择同步放宽到 9 层，无需数据库迁移。
