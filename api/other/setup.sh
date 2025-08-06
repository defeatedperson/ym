#!/bin/bash

# YM云幕监控（被控）安装/卸载脚本

# 检查是否以root权限运行
if [ "$EUID" -ne 0 ]; then
  echo "请以root权限运行此脚本"
  exit 1
fi

# 显示菜单
show_menu() {
    echo "============================================="
    echo "      YM云幕监控（被控）管理程序"
    echo "============================================="
    echo ""
    echo "请选择操作："
    echo "1. 安装 YM云幕监控（被控）"
    echo "2. 卸载 YM云幕监控（被控）"
    echo "3. 退出"
    echo ""
}

# 安装功能
install_ym() {
    echo "============================================="
    echo "      YM云幕监控（被控）安装程序"
    echo "============================================="
    echo ""
    echo "即将安装YM云幕监控（被控）被动模式程序。"
    echo "请确保本机和主控能正常通信。"
    echo ""

    # 用户确认
    read -p "是否继续安装？(y/N): " confirm

    if [[ "$confirm" != "y" && "$confirm" != "Y" ]]; then
        echo "安装已取消。"
        return
    fi

    # 创建ym用户
    if id "ym" &>/dev/null; then
        echo "用户ym已存在"
    else
        echo "创建ym用户..."
        useradd -r -s /bin/false ym
        if [ $? -eq 0 ]; then
            echo "ym用户创建成功"
        else
            echo "ym用户创建失败"
            return
        fi
    fi

    # 创建/ym目录并设置权限
    if [ ! -d "/ym" ]; then
        echo "创建/ym目录..."
        mkdir -p /ym
        if [ $? -eq 0 ]; then
            echo "/ym目录创建成功"
        else
            echo "/ym目录创建失败"
            return
        fi
    else
        echo "/ym目录已存在"
    fi

    # 设置/ym目录所有者为ym用户
    echo "设置/ym目录权限..."
    chown -R ym:ym /ym
    chmod -R 755 /ym

    echo "目录权限设置完成"

        # 创建/ym/conf.json配置文件
    echo "创建/ym/conf.json配置文件..."
    
    # 获取用户输入
    read -p "请输入主控地址 (例如: http://your-domain.com): " master_address
    read -p "请输入节点ID (数字): " node_id
    read -p "请输入节点密钥 (原始密钥，无需转换): " node_secret
    
    # 对密钥进行base64编码
    encoded_secret=$(echo -n "$node_secret" | base64)
    
    # 创建配置文件
    cat > /ym/conf.json << EOF
{
  "master_address": "$master_address",
  "node_id": $node_id,
  "node_secret": "$encoded_secret"
}
EOF
    
    # 设置配置文件所有者为ym用户并设置读写权限
    chown ym:ym /ym/conf.json
    chmod 644 /ym/conf.json
    
    echo "/ym/conf.json配置文件创建完成"

        # 下载程序到/ym文件夹
    echo "正在从主控地址下载程序..."
    
    # 使用curl下载程序
    if command -v curl >/dev/null 2>&1; then
        echo "使用curl下载程序..."
        curl -L -o /ym/ym "$master_address/api/data/ym" --progress-bar
        download_status=$?
    # 如果curl不可用，尝试使用wget
    elif command -v wget >/dev/null 2>&1; then
        echo "使用wget下载程序..."
        wget --progress=bar -O /ym/ym "$master_address/api/data/ym"
        download_status=$?
    else
        echo "错误：系统中未找到curl或wget命令，无法下载程序。"
        return 1
    fi
    
    # 检查下载是否成功
    if [ $download_status -eq 0 ] && [ -f "/ym/ym" ]; then
        # 检查文件大小，确保下载完整
        file_size=$(stat -c%s "/ym/ym" 2>/dev/null || wc -c < "/ym/ym")
        echo "程序下载成功，文件大小：${file_size} 字节"
        
        # 检查文件是否太小（可能下载不完整）
        if [ "$file_size" -lt 1048576 ]; then  # 小于1MB
            echo "警告：下载的文件可能不完整，文件大小只有 ${file_size} 字节"
            echo "请检查网络连接和下载地址是否正确"
            read -p "是否继续安装？(y/N): " continue_install
            if [[ "$continue_install" != "y" && "$continue_install" != "Y" ]]; then
                echo "安装已取消"
                return 1
            fi
        fi
        
        # 赋予运行权限
        chmod +x /ym/ym
        echo "已赋予程序运行权限"
        
        # 设置程序所有者为ym用户
        chown ym:ym /ym/ym
    else
        echo "程序下载失败"
        return 1
    fi

        # 创建启动脚本
    echo "创建启动脚本..."
    
    # 确保程序可执行
    chmod +x /ym/ym
    
    # 先尝试在/ym目录下启动程序测试，确保能找到配置文件
    echo "正在启动 YM 监控程序..."
    cd /ym && nohup ./ym > ym.log 2>&1 &
    echo "YM 监控程序已启动，日志输出到 /ym/ym.log"
    
    # 创建systemd服务文件实现自启动
    echo "创建systemd服务文件实现自启动..."
    
    # 检查systemctl是否存在
    if command -v systemctl >/dev/null 2>&1; then
        # 创建服务文件
        SERVICE_FILE="/etc/systemd/system/ym.service"
        cat > "$SERVICE_FILE" << EOF
[Unit]
Description=YM Cloud Monitor Agent
After=network.target

[Service]
Type=simple
ExecStart=/ym/ym
WorkingDirectory=/ym
User=ym
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
EOF
        
        # 重新加载systemd配置
        systemctl daemon-reload
        
        # 启用并启动服务
        systemctl enable ym.service
        systemctl start ym.service
        
        # 检查服务状态
        if systemctl is-active --quiet ym.service; then
            echo "YM云幕监控服务已成功启动并设置为开机自启动"
        else
            echo "警告：YM云幕监控服务启动失败，请检查日志：journalctl -u ym.service"
        fi
    else
        echo "警告：系统中未找到systemctl命令，无法设置开机自启动"
        echo "程序已手动启动，日志文件：/ym/ym.log"
    fi

    echo "安装完成！"
}

# 卸载功能
uninstall_ym() {
    echo "============================================="
    echo "      YM云幕监控（被控）卸载程序"
    echo "============================================="
    echo ""
    echo "即将卸载YM云幕监控（被控）程序。"
    echo ""

    # 用户确认
    read -p "是否继续卸载？(y/N): " confirm

    if [[ "$confirm" != "y" && "$confirm" != "Y" ]]; then
        echo "卸载已取消。"
        return
    fi

    echo "开始卸载..."
    
    # 停止并禁用systemd服务
    if command -v systemctl >/dev/null 2>&1; then
        echo "停止YM云幕监控服务..."
        systemctl stop ym.service 2>/dev/null
        echo "禁用YM云幕监控服务..."
        systemctl disable ym.service 2>/dev/null
        echo "删除YM云幕监控服务文件..."
        rm -f /etc/systemd/system/ym.service
        # 重新加载systemd配置
        systemctl daemon-reload 2>/dev/null
    fi
    
    # 删除/ym目录
    if [ -d "/ym" ]; then
        echo "删除/ym目录..."
        rm -rf /ym
        if [ $? -eq 0 ]; then
            echo "/ym目录删除成功"
        else
            echo "/ym目录删除失败"
        fi
    else
        echo "/ym目录不存在"
    fi

    # 注意：不删除ym用户
    echo "卸载完成！注意：ym用户未被删除。"
}

# 主程序循环
while true; do
    show_menu
    read -p "请输入选项 [1-3]: " choice
    case $choice in
        1)
            install_ym
            read -p "按回车键继续..." dummy
            ;;
        2)
            uninstall_ym
            read -p "按回车键继续..." dummy
            ;;
        3)
            echo "退出程序。"
            exit 0
            ;;
        *)
            echo "无效选项，请重新输入。"
            read -p "按回车键继续..." dummy
            ;;
    esac
done