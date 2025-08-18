package main

import (
	"bytes"
	"encoding/json"
	"fmt"
	"net/http"
	"os"
	"strings"
	"time"

	"github.com/shirou/gopsutil/v3/cpu"
	"github.com/shirou/gopsutil/v3/disk"
	"github.com/shirou/gopsutil/v3/mem"
	"github.com/shirou/gopsutil/v3/net"
)

// getSystemInfo 获取系统信息
func getSystemInfo() {
	// 获取CPU信息
	cpuInfo, err := getCpuInfo()
	if err != nil {
		// 静默处理错误
	} else {
		fmt.Printf("CPU型号: %s\n", cpuInfo.ModelName)
		fmt.Printf("CPU核心数: %d\n", cpuInfo.Cores)
	}

	// 获取内存信息
	memInfo, err := getMemoryInfo()
	if err != nil {
		// 静默处理错误
	} else {
		fmt.Printf("总内存大小: %.2f GB\n", memInfo.TotalGB)
	}

	// 获取磁盘信息
	diskInfo, err := getDiskInfo()
	if err != nil {
		// 静默处理错误
	} else {
		fmt.Printf("总磁盘大小: %.2f GB\n", diskInfo.TotalGB)
	}
}

// CpuInfo CPU信息结构体
type CpuInfo struct {
	ModelName string
	Cores     int
}

// getCpuInfo 获取CPU信息
func getCpuInfo() (CpuInfo, error) {
	var cpuInfo CpuInfo

	// 获取CPU详细信息
	cpus, err := cpu.Info()
	if err != nil {
		return cpuInfo, err
	}

	if len(cpus) > 0 {
		cpuInfo.ModelName = cpus[0].ModelName
		cpuInfo.Cores = int(cpus[0].Cores)
	}

	return cpuInfo, nil
}

// MemoryInfo 内存信息结构体
type MemoryInfo struct {
	TotalGB float64
}

// getMemoryInfo 获取内存信息
func getMemoryInfo() (MemoryInfo, error) {
	var memInfo MemoryInfo

	// 获取虚拟内存信息
	vmStat, err := mem.VirtualMemory()
	if err != nil {
		return memInfo, err
	}

	// 转换为GB
	memInfo.TotalGB = float64(vmStat.Total) / (1024 * 1024 * 1024)

	return memInfo, nil
}

// DiskInfo 磁盘信息结构体
type DiskInfo struct {
	TotalGB float64
}

// getDiskInfo 获取磁盘信息
func getDiskInfo() (DiskInfo, error) {
	var diskInfo DiskInfo

	// 获取磁盘使用情况
	diskStat, err := disk.Usage("/") // 在Windows上可能需要使用"C:\\"或其他路径
	if err != nil {
		// 如果"/"路径失败，尝试其他常见路径
		diskStat, err = disk.Usage("C:\\")
		if err != nil {
			return diskInfo, err
		}
	}

	// 转换为GB
	diskInfo.TotalGB = float64(diskStat.Total) / (1024 * 1024 * 1024)

	return diskInfo, nil
}

// SystemUsage 系统使用情况结构体
type SystemUsage struct {
	CPUUsage    float64
	MemoryUsage float64
	DiskUsage   float64
	UploadMB    float64
	DownloadMB  float64
}

// getSystemUsage 获取系统使用情况
func getSystemUsage() (SystemUsage, error) {
	var usage SystemUsage

	// 获取CPU使用率
	cpuPercent, err := cpu.Percent(time.Second, false)
	if err != nil {
		return usage, fmt.Errorf("获取CPU使用率失败: %v", err)
	}
	if len(cpuPercent) > 0 {
		usage.CPUUsage = cpuPercent[0]
	}

	// 获取内存使用率
	memStat, err := mem.VirtualMemory()
	if err != nil {
		return usage, fmt.Errorf("获取内存使用率失败: %v", err)
	}
	usage.MemoryUsage = memStat.UsedPercent

	// 获取磁盘使用率
	diskStat, err := disk.Usage("/")
	if err != nil {
		diskStat, err = disk.Usage("C:\\")
		if err != nil {
			return usage, fmt.Errorf("获取磁盘使用率失败: %v", err)
		}
	}
	usage.DiskUsage = diskStat.UsedPercent

	// 获取网络带宽使用情况
	uploadMB, downloadMB, err := getBandwidthUsage()
	if err != nil {
		return usage, fmt.Errorf("获取带宽使用情况失败: %v", err)
	}
	usage.UploadMB = uploadMB
	usage.DownloadMB = downloadMB

	return usage, nil
}

// getBandwidthUsage 获取带宽使用情况（单位：兆字节/秒）
func getBandwidthUsage() (uploadMB, downloadMB float64, err error) {
	// 获取初始网络统计
	ioCountersStart, err := net.IOCounters(true)
	if err != nil {
		return 0, 0, fmt.Errorf("获取初始网络统计失败: %v", err)
	}

	// 等待1秒钟
	time.Sleep(time.Second)

	// 获取结束时的网络统计
	ioCountersEnd, err := net.IOCounters(true)
	if err != nil {
		return 0, 0, fmt.Errorf("获取结束时网络统计失败: %v", err)
	}

	// 计算带宽使用情况
	var totalUploadBytes, totalDownloadBytes uint64
	for i := range ioCountersStart {
		// 确保是同一个网络接口
		if i < len(ioCountersEnd) && ioCountersStart[i].Name == ioCountersEnd[i].Name {
			// 过滤常见虚拟网卡
			if isVirtualInterface(ioCountersStart[i].Name) {
				continue
			}

			// 计算上传和下载的字节数
			uploadBytes := ioCountersEnd[i].BytesSent - ioCountersStart[i].BytesSent
			downloadBytes := ioCountersEnd[i].BytesRecv - ioCountersStart[i].BytesRecv

			totalUploadBytes += uploadBytes
			totalDownloadBytes += downloadBytes
		}
	}

	// 转换为兆字节/秒 (MB/s)
	uploadMB = float64(totalUploadBytes) / (1024 * 1024)
	downloadMB = float64(totalDownloadBytes) / (1024 * 1024)

	return uploadMB, downloadMB, nil
}

// isVirtualInterface 检查是否为虚拟接口
func isVirtualInterface(name string) bool {
	// 常见的虚拟网卡前缀/名称
	virtualPrefixes := []string{
		"docker", "veth", "br-", "vmnet", "vboxnet", "lo", "Loopback",
		"isatap", "teredo", "6to4", "sit", "stf", "gif", "dummy",
		"tun", "tap", "ppp", "bridge", "ovs",
	}

	for _, prefix := range virtualPrefixes {
		if strings.HasPrefix(name, prefix) || strings.Contains(name, prefix) {
			return true
		}
	}

	return false
}

// Config 配置文件结构体
type Config struct {
	MasterAddress string `json:"master_address"`
	NodeID        int    `json:"node_id"`
	NodeSecret    string `json:"node_secret"`
}

// loadConfig 加载配置文件
func loadConfig() (Config, error) {
	var config Config

	// 打开配置文件
	file, err := os.Open("conf.json")
	if err != nil {
		return config, fmt.Errorf("无法打开配置文件: %v", err)
	}
	defer file.Close()

	// 解析JSON配置
	decoder := json.NewDecoder(file)
	err = decoder.Decode(&config)
	if err != nil {
		return config, fmt.Errorf("解析配置文件失败: %v", err)
	}

	return config, nil
}

// NodeConfig 节点配置信息结构体
type NodeConfig struct {
	CPUModel   string  `json:"cpu_model"`
	MemorySize float64 `json:"memory_size"` // MB
	DiskSize   float64 `json:"disk_size"`   // MB
}

// NodeLoad 节点负载信息结构体
type NodeLoad struct {
	CPUUsage         float64 `json:"cpu_usage"`
	MemoryUsage      float64 `json:"memory_usage"`
	UploadBandwidth  float64 `json:"upload_bandwidth"`  // MB/s
	DownloadBandwidth float64 `json:"download_bandwidth"` // MB/s
	DiskUsage        float64 `json:"disk_usage"`
}

// NodeData 节点数据结构体
type NodeData struct {
	NodeID     int       `json:"node_id"`
	NodeSecret string    `json:"node_secret"`
	NodeConfig NodeConfig `json:"node_config"`
	NodeLoad   NodeLoad   `json:"node_load"`
	Timestamp  int64     `json:"timestamp"`
}

// startMonitoring 启动监控定时器
func startMonitoring(config Config) {
	// 立即执行一次
	sendData(config)
	
	// 启动定时器，每分钟执行一次
	ticker := time.NewTicker(1 * time.Minute)
	go func() {
		for range ticker.C {
			sendData(config)
		}
	}()
}

// sendData 发送数据到API
func sendData(config Config) {
	// 获取系统信息
	cpuInfo, err := getCpuInfo()
	if err != nil {
		return
	}
	
	memInfo, err := getMemoryInfo()
	if err != nil {
		return
	}
	
	diskInfo, err := getDiskInfo()
	if err != nil {
		return
	}
	
	// 获取系统使用情况
	usage, err := getSystemUsage()
	if err != nil {
		return
	}
	
	// 构造节点配置信息
	nodeConfig := NodeConfig{
		CPUModel:   cpuInfo.ModelName,
		MemorySize: memInfo.TotalGB * 1024, // 转换为MB
		DiskSize:   diskInfo.TotalGB * 1024, // 转换为MB
	}
	
	// 构造节点负载信息
	nodeLoad := NodeLoad{
		CPUUsage:          usage.CPUUsage,
		MemoryUsage:       usage.MemoryUsage,
		UploadBandwidth:   usage.UploadMB,
		DownloadBandwidth: usage.DownloadMB,
		DiskUsage:         usage.DiskUsage,
	}
	
	// 构造完整数据
	nodeData := NodeData{
		NodeID:     config.NodeID,
		NodeSecret: config.NodeSecret,
		NodeConfig: nodeConfig,
		NodeLoad:   nodeLoad,
		Timestamp:  time.Now().Unix(),
	}
	
	// 尝试发送数据，最多重试2次
	for i := 0; i < 3; i++ { // 初始尝试 + 2次重试
		err := sendRequest(config.MasterAddress, nodeData)
		if err == nil {
			return
		}
		
		if i < 2 { // 不是最后一次尝试，等待5秒后重试
			time.Sleep(5 * time.Second)
		}
	}
}

// sendRequest 发送HTTP请求
func sendRequest(masterAddress string, data NodeData) error {
	// 序列化数据
	jsonData, err := json.Marshal(data)
	if err != nil {
		return fmt.Errorf("序列化数据失败: %v", err)
	}
	
	// 构造请求URL
	url := fmt.Sprintf("%s/api/api.php", strings.TrimRight(masterAddress, "/"))
	
	// 创建HTTP请求
	req, err := http.NewRequest("POST", url, bytes.NewBuffer(jsonData))
	if err != nil {
		return fmt.Errorf("创建请求失败: %v", err)
	}
	req.Header.Set("Content-Type", "application/json")
	
	// 发送请求
	client := &http.Client{Timeout: 30 * time.Second}
	resp, err := client.Do(req)
	if err != nil {
		return fmt.Errorf("发送请求失败: %v", err)
	}
	defer resp.Body.Close()
	
	// 检查响应状态
	if resp.StatusCode != http.StatusOK {
		return fmt.Errorf("服务器返回错误状态码: %d", resp.StatusCode)
	}
	
	return nil
}

func main() {
	// 加载配置文件
	config, err := loadConfig()
	if err != nil {
		return
	}
	
	// 启动监控
	startMonitoring(config)
	
	// 保持程序运行
	select {}
}
