

# 云幕监控 📡

一款轻量、安全、跨平台的节点监控工具，支持对多节点的负载状态进行实时监控，专注于信息采集与可视化展示，兼顾安全性与易用性。

## 最新情况说明

新版本已改名【星梦监控】，详情请查看  https://xmm.xmpanel.cn

## 注意事项

新安装的主控，可能会因为浏览器缓存的原因，导致一直无法显示节点状态（例如提示被控未升级，前台没有服务器之类的），只需要用浏览器无痕模式打开即可测试，或者清空/更换一个浏览器即可

## 项目简介 🌟

为用户提供简单高效的节点监控方案，核心功能是对多个节点（服务器 / 设备）的运行状态进行实时监控，包括但不限于 CPU、内存、带宽、磁盘、流量使用率及节点配置信息等关键指标。

### 核心特性 🚀



*   **跨平台支持**：兼容 Windows 和 Linux 系统，无需为不同系统单独部署监控方案。

*   **极致安全**：被控端采用主动模式上报信息，无需公网 IP，无需开放特殊端口；无任何节点操作权限，仅采集必要运行信息，避免安全风险。

*   **部署便捷**：主控推荐使用 Docker 容器化部署，一键启动，无需复杂环境配置。

*   **轻量高效**：被控端资源占用低，主控端界面简洁，操作门槛低。

## 页面展示
[![](https://raw.githubusercontent.com/defeatedperson/ym/refs/heads/main/photo/11.webp)](https://raw.githubusercontent.com/defeatedperson/ym/refs/heads/main/photo/11.webp)
[![](https://raw.githubusercontent.com/defeatedperson/ym/refs/heads/main/photo/22.webp)](https://raw.githubusercontent.com/defeatedperson/ym/refs/heads/main/photo/22.webp)
[![](https://raw.githubusercontent.com/defeatedperson/ym/refs/heads/main/photo/3.webp)](https://raw.githubusercontent.com/defeatedperson/ym/refs/heads/main/photo/3.webp)
[![](https://raw.githubusercontent.com/defeatedperson/ym/refs/heads/main/photo/4.webp)](https://raw.githubusercontent.com/defeatedperson/ym/refs/heads/main/photo/4.webp)

## 功能说明 🔧

### 已实现功能 📊



*   实时监控多节点的核心指标：


    *   系统信息：节点名称、系统类型（Windows/Linux）、硬件配置等

    *   负载状态：CPU 使用率、内存使用率、磁盘使用率、网络带宽（上行 / 下行）

*   跨系统支持：同时兼容 Windows 和 Linux 节点

*   数据持久化：监控数据本地存储，支持历史趋势查看

*   可视化界面：通过 Web 界面直观展示节点状态，支持多节点切换查看

### 开发中功能 🛠️



*   **xdm 扩展**：计划支持自动故障转移、被动模式（被控端被动接收监控指令），进一步扩展监控场景。

## 技术栈 💻



*   **被控端**：Go 语言开发，**完整开源**（含详细注释，无加密 / 混淆），轻量且跨平台，负责采集节点信息并主动上报

*   **主控端**：


    *   后端：原生 PHP（完整开源，含详细注释，无加密 / 混淆）

    *   前端：Vue3 + 自研 starUI V3 框架（暂不开源，根据社区使用情况决定是否开源）

## 开源协议 📜

项目整体采用 **Apache License 2.0** 协议开源：



*   被控端代码（Go）与后端代码（PHP）均完整开源，包含注释，无加密 / 混淆，允许二次开发（需遵循协议）

*   前端代码（Vue3）暂不开源，仅提供运行时文件

*   禁止行为：


    *   直接将本程序用于商业售卖并宣称 "自主开发"

    *   用于任何违反法律法规或行业规范的业务场景

## 安装部署（Docker 容器化） 🐳

文档地址https://re.xcdream.com/9405.html

[![通过雨云一键部署](https://rainyun-apps.cn-nb1.rains3.com/materials/deploy-on-rainyun-cn.svg)](https://app.rainyun.com/apps/rca/store/6871/dp712_)

### 首次部署 🚀



1.  拉取最新镜像：



```
docker pull defeatedperson/ym-app:latest
```



1.  启动容器（注意挂载数据卷以保持数据持久化）：



```
docker run -d \
     --name ym-cloud-transfer \
     -p 8080:80 \
     -v $(pwd)/web/api/auth/data:/var/www/html/api/auth/data \
     -v $(pwd)/web/api/data:/var/www/html/api/data \
     -v $(pwd)/web/api/monitor/data:/var/www/html/api/monitor/data \
     -v $(pwd)/web/api/node/data:/var/www/html/api/node/data \
     defeatedperson/ym-app:latest
```

**参数说明**：



*   `-d`：后台运行容器

*   `--name`：指定容器名称（可自定义）

*   `-p`：端口映射，格式为`主机端口:容器端口`（容器内固定为 80）

*   `-v`：挂载数据卷，确保监控数据、节点信息等在容器重启后不丢失

### 访问应用 🌐

容器启动后，通过以下地址访问主控端界面：



```
http://\[主机IP]:8080  # 若修改了主机端口，需对应调整（如http://IP:8090）
```

### 更新到最新版本 ⬆️



1.  停止并删除现有容器：



```
docker stop ym-cloud-transfer

docker rm ym-cloud-transfer
```



1.  拉取最新镜像（先删除，再拉取）：


```
docker rmi defeatedperson/ym-app:latest
```



```
docker pull defeatedperson/ym-app:latest
```



1.  重新启动容器（使用与首次部署相同的命令）：



```
docker run -d \
     --name ym-cloud-transfer \
     -p 8080:80 \
     -v $(pwd)/web/api/auth/data:/var/www/html/api/auth/data \
     -v $(pwd)/web/api/data:/var/www/html/api/data \
     -v $(pwd)/web/api/monitor/data:/var/www/html/api/monitor/data \
     -v $(pwd)/web/api/node/data:/var/www/html/api/node/data \
     defeatedperson/ym-app:latest
```

### 常用 Docker 命令 📝



*   查看容器日志（排查问题）：`docker logs -f ym-cloud-transfer`

*   进入容器终端：`docker exec -it ym-cloud-transfer /bin/sh`

*   查看运行中的容器：`docker ps`

*   查看所有容器（包括已停止）：`docker ps -a`

*   查看本地镜像：`docker images`

## 注意事项 ⚠️



1.  数据卷挂载是必须的，否则容器重启后所有监控数据会丢失。

2.  被控端需与主控端网络互通（主动模式下，被控端需能访问主控端的 8080 端口）。

3.  若需修改主控端端口，仅需调整`-p`参数的主机端口部分（如`-p 80:80`映射到主机 80 端口）。

## 反馈与贡献 🤝

若使用中遇到问题或有功能建议，欢迎提交 Issue。后端代码与被控端代码开源部分接受合理 PR，共同完善项目。

## 官网/支持我们

官网https://xcdream.com/ym

购买云服务（CDN/服务器/游戏云/物理机）https://re.xcdream.com/links/qiafan
