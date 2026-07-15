# 部门层级 3→9

## 文件

`app/Http/Controllers/Api/UsersController.php`

## 改动

```diff
- return Base::retError('部门层级最多只能创建3级');
+ return Base::retError('部门层级最多只能创建9级');
```

## 说明

官方限制部门最多 3 层嵌套，Plus 放宽到 9 层。仅此一行，无需数据库迁移或前端修改。
