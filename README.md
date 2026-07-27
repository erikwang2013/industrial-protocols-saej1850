# SAE J1850 协议包 — OBD-II 早期标准，需 J1850 接口桥接

> [English](README.en.md)

SAE J1850 OBD-II 早期汽车诊断标准（PWM 41.6 kbps / VPW 10.4 kbps）。需要 J1850 接口硬件，通过内核 Bridge 层桥接。

## 安装

```bash
composer require erikwang2013/industrial-protocols-saej1850
```

## 功能

SAE J1850 PWM/VPW 桥接、BridgeConnector 连接管理

## 所需硬件

J1850 接口适配器（ELM327 / STN1110）

## 兼容框架

Laravel / Webman / Hyperf / ThinkPHP / Yii2 / Plain PHP

## 系统要求

- PHP >= 8.1
- J1850 接口硬件
- erikwang2013/industrial-protocols-kernel

## License

MIT — Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
