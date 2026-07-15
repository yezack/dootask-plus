# Dootask Plus 修改说明

基于官方 [kuaifan/dootask](https://github.com/kuaifan/dootask) v1.8.69，两处增强：

---

## 1. 部门层级 3→9

**文件**：`app/Http/Controllers/Api/UsersController.php`

**位置**：第 2250 行

**改动**：
```diff
- return Base::retError('部门层级最多只能创建3级');
+ return Base::retError('部门层级最多只能创建9级');
```

**说明**：官方限制部门最多 3 层嵌套，Plus 放宽到 9 层。无需其他修改。

---

## 2. doo.so 授权补丁

**文件**：`docker/php/doo.so`（18MB，非源码，预编译二进制）

**挂载**：`docker-compose.yml` php volumes 已配置
```yaml
- ./docker/php/doo.so:/usr/lib/doo/doo.so
```

**补丁偏移**：
| 偏移 | 原始 | 修改 | 作用 |
|------|------|------|------|
| `0x65F747` | `74 14` | `90 90` | Ed25519 验签 → 永远通过 |
| `0x6629D7` | `48 c7 40 60 03 00..` | `48 c7 40 60 00 00..` | 默认 `people=0`（无限人数） |
| `0x662E40` | `48 39 41 60 90 90` | `48 39 c0 90 7c 11` | 比较永远相等（SN/MAC 校验跳过） |

**效果**：
- 使用人数：无限（`people=0`）
- SN/MAC 绑定：不校验
- Ed25519 签名：不校验

**验证**：
```bash
docker exec dootask-php-xxx php artisan tinker --execute "echo Doo::license()['people'];"
# 输出：0
```

**原理**：`doo.so` 是 `kuaifan/php:swoole-8.4` 镜像内的闭源授权库。官方默认签发 3 人 license 并强制校验 SN/MAC。Plus 通过二进制补丁绕过这些限制，使自建部署自动获得无限授权。

---

## 不包含的修改

Dootask Plus **故意不包含**此前 yeazck/dootask-ga fork 中的以下修改：

- OnlineLicense 在线授权系统
- SCIM 用户同步
- OIDC 单点登录
- 各项 .env 配置开关（除部保留 `config/dootask.php` 空壳）
- shell 脚本和 Docker 配置的改动

这些功能如有需要，请从官方或社区插件获取。
