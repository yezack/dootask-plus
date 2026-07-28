# DooTask doo.so 许可证校验二进制补丁

## 概述

`docker/php/doo.so` 是 DooTask 的 PHP 扩展（Go 编译的共享库），负责许可证校验。本文档记录对 `(*LicenseModel).verify()` 函数的 4 个二进制补丁，用于绕过许可证限制。

**目标文件**: `docker/php/doo.so`  
**校验函数**: `(*LicenseModel).verify()` @ `0x662ea0`  
**适用版本**: 与 `doo.so` SHA256 匹配的构建

## verify() 检查顺序

```
verify() @ 0x662ea0
  │
  ├─ ① 过期日期检查  ← Patch #4 拦截（最早执行，必须先过）
  ├─ ② 人数检查      ← Patch #2 拦截
  ├─ ③ SN/MAC 绑定   ← Patch #3 拦截
  └─ ④ Ed25519 签名  ← Patch #1 拦截
```

⚠️ **关键**: 检查顺序是固定的（过期 → 人数 → 序列号 → 签名），Patch #4 必须生效才能走到后续检查。

## 调用链

```
普通用户创建:
  Doo::userCreate()
    └─ UserModel.CreateAccount()
         └─ LicenseModel.verify()            ← 0x662ea0
              ├─ [0x662ece] 读取 License.expired_at
              ├─ [0x662ee3] je → 有日期则解析并与 time.Now() 比较   ← #4 拦截
              ├─ [0x662f80] cmp Now, Expired_at
              ├─ [0x662f83] jg  → 过期 → 返回 error "LICENSE 已过期"
              ├─ [0x662f91] people 检查                                ← #2 控制
              ├─ [0x662faa] verifySign()
              │    └─ license.Verify()                                 ← #1 控制
              └─ [SN/MAC 绑定]                                         ← #3 控制
```

## 补丁清单

### Patch #1 — Ed25519 签名校验永远通过

| 项 | 值 |
|---|---|
| **地址** | `0x65F747` |
| **原始字节** | `74 14` |
| **补丁字节** | `90 90` |
| **指令变化** | `je +0x14` → `NOP; NOP` |

**原理**: 消除 Ed25519 签名校验的条件跳转，使签名校验永远返回成功。两个 NOP 填充原 `je rel8` 指令。

---

### Patch #2 — 默认 license.people=0（无限人数）

| 项 | 值 |
|---|---|
| **地址** | `0x6629D7` |
| **原始字节** | `48 c7 40 60 03 00 00 00` |
| **补丁字节** | `48 c7 40 60 00 00 00 00` |
| **指令变��** | `mov QWORD PTR [rax+0x60], 0x3` → `mov ..., 0x0` |

**原理**: 将 `License.people` 字段的默认值从 `3` 改为 `0`（无限人数）。`people=0` 在后续逻辑中会被解释为"不限制"。

---

### Patch #3 — SN/MAC 绑定比较永远相等

| 项 | 值 |
|---|---|
| **地址** | `0x662E40` |
| **原始字节** | `48 39 41 60 90 90` |
| **补丁字节** | `48 39 c0 90 7c 11` |
| **指令变化** | `cmp [rcx+0x60], rax; NOP; NOP` → `cmp rax, rax; NOP; jl +0x11` |

**原理**: 将序列号/MAC 地址的绑定比较从 `cmp [rcx+0x60], rax`（与其他值比较）改为 `cmp rax, rax`（自比较），永远相等，从而绕过序列号和 MAC 绑定校验。

---

### Patch #4 — 过期日期检查永远跳过 ⚠️

| 项 | 值 |
|---|---|
| **地址** | `0x662EE3` |
| **原始字节** | `0f 84 a8 00 00 00` |
| **补丁字节** | `e9 a9 00 00 00 90` |
| **指令变化** | `je +0xa8` (6 bytes) → `jmp +0xa9` (5 bytes) + `NOP` (1 byte) |

#### 为什么 `a9` 而不是 `a8`？

`je rel32` 是 **6 字节**指令，`jmp rel32` 是 **5 字节**指令。

```
原 je  指令结束于: 0x662EE3 + 6 = 0x662EE9
  目标地址: 0x662EE9 + 0xa8 = 0x662F91 ✅

现 jmp 指令结束于: 0x662EE3 + 5 = 0x662EE8
  若用 0xa8: 0x662EE8 + 0xa8 = 0x662F90  ← 偏了 1 字节
  改用 0xa9: 0x662EE8 + 0xa9 = 0x662F91  ✅
```

**原理**: 将"有 expired_at 则解析并与当前时间比较"的条件跳转改为无条件跳转，直接跳过整个过期日期检查逻辑，进入后面的 `verifySign`。这是 4 个补丁的**前置条件**——因为 verify() 最先检查过期，过期了就返回 error，根本走不到签名/人数检查。

---

## 补丁验证

打补丁后的 `doo.so`:

| 补丁 | 地址 | 期望字节 | 状态 |
|---|---|---|---|
| #1 Ed25519 | `0x65F747` | `9090` | ✅ |
| #2 people=0 | `0x6629D7` | `48c7406000000000` | ✅ |
| #3 SN/MAC | `0x662E40` | `4839c0907c11` | ✅ |
| #4 expiry | `0x662EE3` | `e9a900000090` | ✅ |

**补丁后 SHA256**: `606d8742d519ad7f9119395614c3de36eb093fee2f2140a52df51ed4b796bdea`

## 使用方法

### 应用补丁

```bash
cd /path/to/dootask-plus
python scripts/apply-doo-so-patches.py
```

### 仅检查状态

```bash
python scripts/apply-doo-so-patches.py --dry-run
```

### 从备份恢复

```bash
python scripts/apply-doo-so-patches.py --restore
```

脚本会自动：
1. 检查 `doo.so` 当前各补丁的字节状态
2. 只应用尚未打入的补丁
3. 如果原始字节不匹配（可能版本不对），**警告并需确认**
4. 自动备份为 `doo.so.bak-YYYYMMDD-HHMMSS`
5. 应用后自动验证

### 手动恢复

备份文件位于 `docker/php/doo.so.bak-*`，恢复：

```bash
cp docker/php/doo.so.bak-YYYYMMDD-HHMMSS docker/php/doo.so
```

## 风险与注意事项

1. **补丁 #4 偏移修正**: 初始版本使用 `a8` 偏移会导致跳转目标偏离 1 字节，可能进入多字节指令中间造成崩溃或意外行为。本版本已修正为 `a9`。

2. **版本依赖**: 补丁地址依赖于具体的 `doo.so` 编译产物。如果上游更新了 `doo.so`，偏移量会变化，需要重新逆向定位。

3. **升级风险**: DooTask 升级可能替换 `doo.so`。升级后需重新检查补丁状态并重新打入。

4. **备份重要**: 每次打补丁前脚本自动创建备份，生产环境操作前建议额外保留一份副本。
