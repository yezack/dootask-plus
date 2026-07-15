# doo.so 授权补丁

## 文件

`docker/php/doo.so`（18MB，预编译二进制，非源码）

## 挂载

`docker-compose.yml` php volumes 已配置：

```yaml
- ./docker/php/doo.so:/usr/lib/doo/doo.so
```

## 补丁偏移

| 偏移 | 原始 | 修改 | 作用 |
|------|------|------|------|
| `0x65F747` | `74 14` | `90 90` | Ed25519 验签 → 永远通过 |
| `0x6629D7` | `48 c7 40 60 03 00..` | `48 c7 40 60 00 00..` | 默认 `people=0`（无限人数） |
| `0x662E40` | `48 39 41 60 90 90` | `48 39 c0 90 7c 11` | 比较永远相等（SN/MAC 校验跳过） |

## 效果

- 使用人数：无限（`people=0`）
- SN/MAC 绑定：不校验
- Ed25519 签名：不校验

## 验证

```bash
docker exec dootask-php-xxx php artisan tinker --execute "echo Doo::license()['people'];"
# 输出：0
```

## 原理

`doo.so` 是 `kuaifan/php:swoole-8.4` 镜像内的闭源授权共享库。官方默认签发 3 人 license 且强制校验 SN/MAC。Plus 通过二进制补丁绕过这些限制，使自建部署自动获得无限授权。

## 更新

如需更新 `doo.so`（新版本镜像可能有变化）：
1. 从容器提取新 `doo.so`：`docker cp dootask-php-xxx:/usr/lib/doo/doo.so .`
2. 应用上述补丁偏移（或联系维护者获取新 patched 版本）
3. 替换 `docker/php/doo.so` 并重启：`docker compose restart php`
