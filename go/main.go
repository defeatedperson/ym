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

// TrafficMonitor 流量监控器结构体
type TrafficMonitor struct {
	lastIOCounters []net.IOCountersStat
	lastTime       time.Time
	totalUpload    float64 // MB
	totalDownload  float64 // MB
	currentMonth   string
}

// NewTrafficMonitor 创建新的流量监控器
func NewTrafficMonitor() *TrafficMonitor {
	return &TrafficMonitor{
		currentMonth: time.Now().Format("2006-01"),
		lastTime:     time.Now(),
	}
}

// UpdateTraffic 更新流量统计
func (tm *TrafficMonitor) UpdateTraffic() error {
	// 获取当前网络统计
	ioCounters, err := net.IOCounters(true)
	if err != nil {
		return fmt.Errorf("获取网络统计失败: %v", err)
	}

	currentTime := time.Now()
	currentMonth := currentTime.Format("2006-01")

	// 检查是否为新月份，如果是则重置统计
	if tm.currentMonth != currentMonth {
		tm.totalUpload = 0
		tm.totalDownload = 0
		tm.currentMonth = currentMonth
	}

	// 如果有上次的数据，计算增量
	if tm.lastIOCounters != nil && len(tm.lastIOCounters) > 0 {
		var uploadBytes, downloadBytes uint64
		
		// 创建上次数据的映射，按接口名称索引
		lastCountersMap := make(map[string]net.IOCountersStat)
		for _, counter := range tm.lastIOCounters {
			lastCountersMap[counter.Name] = counter
		}
		
		// 遍历当前接口数据
		for _, currentCounter := range ioCounters {
			// 过滤常见虚拟网卡
			if isVirtualInterface(currentCounter.Name) {
				continue
			}
			
			// 查找对应的上次数据
			if lastCounter, exists := lastCountersMap[currentCounter.Name]; exists {
				// 计算增量（处理计数器重置的情况）
				var interfaceUpload, interfaceDownload uint64
				
				if currentCounter.BytesSent >= lastCounter.BytesSent {
					interfaceUpload = currentCounter.BytesSent - lastCounter.BytesSent
					uploadBytes += interfaceUpload
				} else {
					// 计数器可能重置了，使用当前值
					interfaceUpload = currentCounter.BytesSent
					uploadBytes += interfaceUpload
				}
				
				if currentCounter.BytesRecv >= lastCounter.BytesRecv {
					interfaceDownload = currentCounter.BytesRecv - lastCounter.BytesRecv
					downloadBytes += interfaceDownload
				} else {
					// 计数器可能重置了，使用当前值
					interfaceDownload = currentCounter.BytesRecv
					downloadBytes += interfaceDownload
				}
				

			}
		}

		// 累加到总流量（转换为MB）
		addedUpload := float64(uploadBytes) / (1024 * 1024)
		addedDownload := float64(downloadBytes) / (1024 * 1024)
		
		tm.totalUpload += addedUpload
		tm.totalDownload += addedDownload
	}

	// 更新上次的数据
	tm.lastIOCounters = ioCounters
	tm.lastTime = currentTime

	return nil
}

// GetMonthlyTraffic 获取月流量统计
func (tm *TrafficMonitor) GetMonthlyTraffic() MonthlyTraffic {
	return MonthlyTraffic{
		Month:    tm.currentMonth,
		Upload:   tm.totalUpload,
		Download: tm.totalDownload,
	}
}

// isVirtualInterface 检查是否为虚拟接口
func isVirtualInterface(name string) bool {
	// 常见的虚拟网卡前缀/名称（精简版）
	virtualPrefixes := []string{
		"docker", "veth", "br-", "lo", "Loopback",
		"vmnet", "vboxnet", "tun", "tap", "vEthernet",
		"Hyper-V", "VMware", "Bluetooth",
	}

	// 转换为小写进行比较
	nameLower := strings.ToLower(name)
	
	for _, prefix := range virtualPrefixes {
		prefixLower := strings.ToLower(prefix)
		if strings.HasPrefix(nameLower, prefixLower) || strings.Contains(nameLower, prefixLower) {
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
	NodeID        int            `json:"node_id"`
	NodeSecret    string         `json:"node_secret"`
	NodeConfig    NodeConfig     `json:"node_config"`
	NodeLoad      NodeLoad       `json:"node_load"`
	MonthlyTraffic MonthlyTraffic `json:"monthly_traffic"`
	Timestamp     int64          `json:"timestamp"`
}

// MonthlyTraffic 月流量统计结构体
type MonthlyTraffic struct {
	Month    string  `json:"month"`    // 格式: 2024-01
	Upload   float64 `json:"upload"`   // MB
	Download float64 `json:"download"` // MB
}

// CacheData 缓存数据结构体
type CacheData struct {
	CPUUsage         float64 `json:"cpu_usage"`
	MemoryUsage      float64 `json:"memory_usage"`
	UploadBandwidth  float64 `json:"upload_bandwidth"`
	DownloadBandwidth float64 `json:"download_bandwidth"`
	DiskUsage        float64 `json:"disk_usage"`
	Timestamp        int64   `json:"timestamp"`
}

// writeFileAtomic 原子写入文件
func writeFileAtomic(filename string, data []byte, perm os.FileMode) error {
	tempFile := filename + ".tmp"
	
	// 写入临时文件
	err := os.WriteFile(tempFile, data, perm)
	if err != nil {
		return fmt.Errorf("写入临时文件失败: %v", err)
	}
	
	// 原子性重命名
	err = os.Rename(tempFile, filename)
	if err != nil {
		// 清理临时文件
		os.Remove(tempFile)
		return fmt.Errorf("重命名文件失败: %v", err)
	}
	
	return nil
}

// saveMonthlyTraffic 保存月流量统计到文件
func saveMonthlyTraffic(traffic MonthlyTraffic) error {
	filename := "monthly_traffic.json"
	
	// 序列化数据
	data, err := json.MarshalIndent(traffic, "", "  ")
	if err != nil {
		return fmt.Errorf("序列化月流量数据失败: %v", err)
	}
	
	// 原子写入文件
	return writeFileAtomic(filename, data, 0644)
}

// loadMonthlyTraffic 从文件加载月流量统计
func loadMonthlyTraffic() (MonthlyTraffic, error) {
	filename := "monthly_traffic.json"
	currentMonth := time.Now().Format("2006-01")
	
	// 默认返回当前月份的空统计
	defaultTraffic := MonthlyTraffic{
		Month:    currentMonth,
		Upload:   0,
		Download: 0,
	}
	
	// 读取文件
	data, err := os.ReadFile(filename)
	if err != nil {
		if os.IsNotExist(err) {
			// 文件不存在，返回默认值
			return defaultTraffic, nil
		}
		return defaultTraffic, fmt.Errorf("读取月流量文件失败: %v", err)
	}
	
	// 解析JSON
	var traffic MonthlyTraffic
	err = json.Unmarshal(data, &traffic)
	if err != nil {
		return defaultTraffic, fmt.Errorf("解析月流量JSON失败: %v", err)
	}
	
	// 检查月份是否匹配
	if traffic.Month != currentMonth {
		// 月份不匹配，返回当前月份的空统计
		return defaultTraffic, nil
	}
	
	return traffic, nil
}

// saveToCache 保存数据到缓存
func saveToCache(usage SystemUsage) {
	cacheData := CacheData{
		CPUUsage:          usage.CPUUsage,
		MemoryUsage:       usage.MemoryUsage,
		UploadBandwidth:   usage.UploadMB,
		DownloadBandwidth: usage.DownloadMB,
		DiskUsage:         usage.DiskUsage,
		Timestamp:         time.Now().Unix(),
	}
	
	// 读取现有缓存
	var cacheList []CacheData
	if data, err := os.ReadFile("cache.json"); err == nil {
		if err := json.Unmarshal(data, &cacheList); err != nil {
			cacheList = []CacheData{} // 重置为空列表
		}
	}
	
	// 添加新数据
	cacheList = append(cacheList, cacheData)
	
	// 保存缓存（使用原子写入）
	if data, err := json.Marshal(cacheList); err == nil {
		writeFileAtomic("cache.json", data, 0644)
	}
}

// getMaxCPUData 获取缓存中CPU占用率最大的数据
func getMaxCPUData() (CacheData, bool) {
	var cacheList []CacheData
	data, err := os.ReadFile("cache.json")
	if err != nil {
		return CacheData{}, false
	}
	
	if err := json.Unmarshal(data, &cacheList); err != nil || len(cacheList) == 0 {
		return CacheData{}, false
	}
	
	// 找到CPU占用率最大的数据（相同时取最后一条）
	maxData := cacheList[0]
	for _, cache := range cacheList {
		if cache.CPUUsage >= maxData.CPUUsage {
			maxData = cache
		}
	}
	
	return maxData, true
}

// clearCache 清除缓存文件
func clearCache() {
	os.Remove("cache.json")
}

// startMonitoring 启动监控定时器
func startMonitoring(config Config, trafficMonitor *TrafficMonitor) {
	// 每10秒采集一次数据
	collectTicker := time.NewTicker(10 * time.Second)
	go func() {
		for range collectTicker.C {
			if usage, err := getSystemUsage(); err == nil {
				// 保存到缓存
				saveToCache(usage)
			}
		}
	}()
	
	// 每30秒更新一次流量统计（持续监控）
	trafficTicker := time.NewTicker(30 * time.Second)
	go func() {
		for range trafficTicker.C {
			trafficMonitor.UpdateTraffic()
		}
	}()
	
	// 每5分钟保存一次月流量数据到文件
	saveTrafficTicker := time.NewTicker(5 * time.Minute)
	go func() {
		for range saveTrafficTicker.C {
			traffic := trafficMonitor.GetMonthlyTraffic()
			saveMonthlyTraffic(traffic)
		}
	}()
	
	// 立即执行一次提交
	submitData(config, trafficMonitor)
	
	// 每分钟提交一次数据
	submitTicker := time.NewTicker(1 * time.Minute)
	go func() {
		for range submitTicker.C {
			submitData(config, trafficMonitor)
		}
	}()
}

// submitData 提交数据到API
func submitData(config Config, trafficMonitor *TrafficMonitor) {
	// 获取缓存中CPU占用率最大的数据
	maxData, hasData := getMaxCPUData()
	if !hasData {
		// 如果没有缓存数据，获取当前数据
		usage, err := getSystemUsage()
		if err != nil {
			clearCache()
			return
		}
		maxData = CacheData{
			CPUUsage:          usage.CPUUsage,
			MemoryUsage:       usage.MemoryUsage,
			UploadBandwidth:   usage.UploadMB,
			DownloadBandwidth: usage.DownloadMB,
			DiskUsage:         usage.DiskUsage,
			Timestamp:         time.Now().Unix(),
		}
	}
	
	// 获取系统信息
	cpuInfo, err := getCpuInfo()
	if err != nil {
		clearCache()
		return
	}
	
	memInfo, err := getMemoryInfo()
	if err != nil {
		clearCache()
		return
	}
	
	diskInfo, err := getDiskInfo()
	if err != nil {
		clearCache()
		return
	}
	
	// 构造节点配置信息
	nodeConfig := NodeConfig{
		CPUModel:   cpuInfo.ModelName,
		MemorySize: memInfo.TotalGB * 1024, // 转换为MB
		DiskSize:   diskInfo.TotalGB * 1024, // 转换为MB
	}
	
	// 构造节点负载信息（使用缓存中的最大值数据）
	nodeLoad := NodeLoad{
		CPUUsage:          maxData.CPUUsage,
		MemoryUsage:       maxData.MemoryUsage,
		UploadBandwidth:   maxData.UploadBandwidth,
		DownloadBandwidth: maxData.DownloadBandwidth,
		DiskUsage:         maxData.DiskUsage,
	}
	
	// 获取月流量数据（优先使用内存中的实时数据）
	monthlyTraffic := trafficMonitor.GetMonthlyTraffic()
	
	// 如果内存中的数据为空，尝试从文件加载
	if monthlyTraffic.Upload == 0 && monthlyTraffic.Download == 0 {
		if fileTraffic, err := loadMonthlyTraffic(); err == nil {
			// 更新流量监控器的数据
			trafficMonitor.totalUpload = fileTraffic.Upload
			trafficMonitor.totalDownload = fileTraffic.Download
			trafficMonitor.currentMonth = fileTraffic.Month
			monthlyTraffic = fileTraffic
		} else {
			}
	}
	
	// 构造完整数据
	nodeData := NodeData{
		NodeID:        config.NodeID,
		NodeSecret:    config.NodeSecret,
		NodeConfig:    nodeConfig,
		NodeLoad:      nodeLoad,
		MonthlyTraffic: monthlyTraffic,
		Timestamp:     maxData.Timestamp,
	}
	
	// 尝试发送数据，最多重试2次
	for i := 0; i < 3; i++ { // 初始尝试 + 2次重试
		err := sendRequest(config.MasterAddress, nodeData)
		if err == nil {
			break
		}
		

		if i < 2 { // 不是最后一次尝试，等待5秒后重试
			time.Sleep(5 * time.Second)
		}
	}
	
	// 无论是否提交成功都清除缓存
	clearCache()
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
		fmt.Printf("加载配置文件失败: %v\n", err)
		return
	}
	
	// 创建流量监控器
	trafficMonitor := NewTrafficMonitor()
	
	// 尝试从文件加载已有的月流量数据
	if savedTraffic, err := loadMonthlyTraffic(); err == nil {
		trafficMonitor.totalUpload = savedTraffic.Upload
		trafficMonitor.totalDownload = savedTraffic.Download
		trafficMonitor.currentMonth = savedTraffic.Month
	}
	
	// 立即保存一次初始的月流量数据
	traffic := trafficMonitor.GetMonthlyTraffic()
	saveMonthlyTraffic(traffic)
	
	// 启动监控
	startMonitoring(config, trafficMonitor)
	

	
	// 保持程序运行
	select {}
}
