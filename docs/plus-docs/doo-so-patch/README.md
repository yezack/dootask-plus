# DooTask doo.so 许可证校验二进制补丁

## 概述

`docker/php/doo.so` 是 DooTask 的 PHP 扩展（Go 编译的共享库），负责许可证校验。本文档记录 `(*LicenseModel).verify()` 函数的 5 个二进制补丁。

**目标文件**: `docker/php/doo.so`  
**校验函数**: `(*LicenseModel).verify()` @ `0x662ea0`

## verify() 检查顺序

```
verify() @ 0x662ea0
  │
  ├─ ① 过期日期检查         ← Patch #4 拦截（最先执行，必须先过）
  ├─ ② 人数检查             ← Patch #2 拦截
  ├─ ③ SN/MAC 绑定          ← Patch #3 拦截
  ├─ ④ verifySign()         ← Patch #5 拦截（调用 OpenPGP license.Verify，产生 "签名无效" 错误）
  └─ ⑤ 底层 Ed25519 校验    ← Patch #1 拦截（另一个调用点，仅 bool 判断）
```

⚠️ **关键**: 
- 检查顺序固定（过期 → 人数 → 序列号 → verifySign → Ed25519）
- **#1 和 #5 是两条不同的签名验证路径**：#1 在 `0x65F747` 校验 Go `crypto/ed25519` 底层 bool；#5 在 `0x662faf` 校验 `dooso/license.Verify()` 的 OpenPGP 授权链路（ProtonMail go-crypto）。如果只打 #1 不打 #5，"LICENSE 签名无效" 依然会报。

## 补丁清单

### Patch #5 — verifySign() 调用永远通过 ⚠️ 最关键

| 项 | 值 |
|---|---|
| **地址** | `0x662FAF` |
| **原始字节** | `48 85 c0 74 6e` |
| **补丁字节** | `eb 71 90 90 90` |
| **指令变化** | `test rax, rax; je +0x6e` → `jmp +0x71; NOP; NOP; NOP` |

**这是真正产生 "LICENSE 签名无效" 错误的代码路径。**

```
[0x662faa] call verifySign()         ← 调用 dooso/license.Verify()（OpenPGP 链路）
[0x662faf] test rax, rax             ← 检查返回的 error 接口
[0x662fb2] je +0x6e → 0x663022      ← 无错则跳到成功路径
[0x662fb4] LEA "LICENSE 签名无效"    ← 有错则加载此错误字符串
```

改为 `jmp +0x71` 后，无条件跳转到成功路径（`0x663022`），跳过错误字符串加载。

---

### Patch #1 — 底层 Ed25519 签名校验永远通过

| 项 | 值 |
|---|---|
| **地址** | `0x65F747` |
| **原始字节** | `74 14` |
| **补丁字节** | `90 90` |
| **指令变化** | `je +0x14` → `NOP; NOP` |

**注意**：这个补丁针对的是另一个 Ed25519 调用点——位于 `0x65F740` 的 `call crypto/ed25519.Verify`，仅返回 bool 判断。即使此补丁生效，`verifySign()` 仍会走 OpenPGP 链路报错。必须配合 #5 使用。

---

### Patch #2 — 默认 license.people=0（无限人数）

| 项 | 值 |
|---|---|
| **地址** | `0x6629D7` |
| **原始字节** | `48 c7 40 60 03 00 00 00` |
| **补丁字节** | `48 c7 40 60 00 00 00 00` |
| **指令变化** | `mov QWORD PTR [rax+0x60], 0x3` → `mov ..., 0x0` |

`people=0` 在后续逻辑中被解释为"不限制"。

---

### Patch #3 — SN/MAC 绑定比较永远相等

| 项 | 值 |
|---|---|
| **地址** | `0x662E40` |
| **原始字节** | `48 39 41 60 90 90` |
| **补丁字节** | `48 39 c0 90 7c 11` |
| **指令变化** | `cmp [rcx+0x60], rax; NOP; NOP` → `cmp rax, rax; NOP; jl +0x11` |

`cmp rax, rax` 自比较永远相等。

---

### Patch #4 — 过期日期检查永远跳过

| 项 | 值 |
|---|---|
| **地址** | `0x662EE3` |
| **原始字节** | `0f 84 a8 00 00 00` |
| **补丁字节** | `e9 a9 00 00 00 90` |
| **指令变化** | `je +0xa8` (6 bytes) → `jmp +0xa9` (5 bytes) + `NOP` (1 byte) |

**偏移修正**：`jmp rel32`（5B）比 `je rel32`（6B）短 1 字节，rel32 从指令末尾计算，故偏移需 +1（`a8`→`a9`）。原始清单的 `a8` 会使目标偏 1 字节。

---

## 补丁验证（5 补丁全部打入后）

| 补丁 | 地址 | 期望字节 |
|---|---|---|
| #1 Ed25519 | `0x65F747` | `9090` |
| #2 people=0 | `0x6629D7` | `48c7406000000000` |
| #3 SN/MAC | `0x662E40` | `4839c0907c11` |
| #4 expiry | `0x662EE3` | `e9a900000090` |
| #5 verifySign | `0x662FAF` | `eb71909090` |

**补丁后 SHA256**: `ac50b1f984b74515a43c2faaab72d453c5577393710e7da00e109a18d5c2cac8`

## 使用方法

### 手动打补丁

```bash
# 备份
cp docker/php/doo.so docker/php/doo.so.bak

# Python 一行搞定全部 5 个补丁
python3 -c "
from pathlib import Path
b = bytearray(Path('docker/php/doo.so').read_bytes())
b[0x65F747:0x65F749] = bytes.fromhex('9090')
b[0x6629D7:0x6629DF] = bytes.fromhex('48c7406000000000')
b[0x662E40:0x662E46] = bytes.fromhex('4839c0907c11')
b[0x662EE3:0x662EE9] = bytes.fromhex('e9a900000090')
b[0x662FAF:0x662FB4] = bytes.fromhex('eb71909090')
Path('docker/php/doo.so').write_bytes(bytes(b))
print('Done')
"
```

### 恢复

```bash
cp docker/php/doo.so.bak docker/php/doo.so
```

## 风险与注意事项

1. **#4 偏移修正**：原始清单 `a8` → 正确 `a9`。
2. **#5 是后来补充的**：原始 4 补丁不包含 verifySign() 调用点，漏掉后 "LICENSE 签名无效" 仍然报错。
3. **版本依赖**：补丁地址依赖于具体编译产物，升级 doo.so 后需重新定位。
4. **备份重要**：操作前务必保留原文件备份。
