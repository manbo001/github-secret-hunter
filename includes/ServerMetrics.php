<?php
declare(strict_types=1);

/**
 * 服务器资源监测（Linux，纯 PHP 读取 /proc，无 shell 执行）
 *
 * - CPU：/proc/stat 两次采样差值计算使用率；/proc/cpuinfo 读取型号与核心数
 * - 内存：/proc/meminfo（以 MemAvailable 计算真实占用）
 * - 磁盘：/proc/mounts 中的物理分区（/dev/*），大小与用量取自 disk_total_space/disk_free_space
 * - 网络：/proc/net/dev 累计字节 + 采样差值折算上下行速率（不含 lo 回环）
 *
 * 采样差值依赖上一次采样点，缓存在系统临时目录（跨请求共享）；
 * 上次采样过旧时本方法内补一次短间隔采样，保证速率数值可信。
 */
class ServerMetrics
{
    private const SAMPLE_FILE = 'gsh_server_sample.json';

    /**
     * 采集快照。非 Linux 环境返回 ['available' => false]，调用方按不支持展示
     */
    public static function snapshot(): array {
        if (!is_readable('/proc/stat') || !is_readable('/proc/meminfo')) {
            return ['available' => false];
        }

        $prev = self::readPrevSample();
        $s1 = self::takeSample();

        // 上次采样距今超过 1 秒：直接用历史点差值；否则补一次短采样
        if ($prev !== null && (microtime(true) - $prev['ts']) >= 1.0) {
            $base = $prev;
            $cur = $s1;
        } else {
            usleep(400000); // 0.4 秒，采样窗口足够算出平滑的速率
            $base = $s1;
            $cur = self::takeSample();
        }
        self::writeSample($cur);

        $dt = max(0.001, $cur['ts'] - $base['ts']);

        // ---- CPU 使用率 ----
        $cpuPercent = null;
        if (isset($base['cpu_total'], $base['cpu_idle'])) {
            $dTotal = $cur['cpu_total'] - $base['cpu_total'];
            $dIdle = $cur['cpu_idle'] - $base['cpu_idle'];
            if ($dTotal > 0) {
                $cpuPercent = round(max(0.0, min(100.0, (1 - $dIdle / $dTotal) * 100)), 1);
            }
        }

        // ---- 网络速率 ----
        $rxRate = null;
        $txRate = null;
        if (isset($base['rx'], $base['tx'])) {
            $rxRate = max(0, (int)round(($cur['rx'] - $base['rx']) / $dt));
            $txRate = max(0, (int)round(($cur['tx'] - $base['tx']) / $dt));
        }

        return [
            'available' => true,
            'time'      => date('Y-m-d H:i:s'),
            'cpu' => [
                'percent' => $cpuPercent,
                'model'   => self::cpuModel(),
                'cores'   => self::cpuCores(),
                'load'    => self::loadAvg(),
                'uptime'  => self::uptimeText(),
            ],
            'mem'  => self::memInfo(),
            'disks' => self::diskInfo(),
            'net' => [
                'rx_rate'  => $rxRate,
                'tx_rate'  => $txRate,
                'rx_total' => $cur['rx'] ?? 0,
                'tx_total' => $cur['tx'] ?? 0,
            ],
        ];
    }

    /** @return array{ts:float, cpu_total:int, cpu_idle:int, rx:int, tx:int}|null */
    private static function readPrevSample(): ?array {
        $file = sys_get_temp_dir() . '/' . self::SAMPLE_FILE;
        $raw = @file_get_contents($file);
        if ($raw === false) {
            return null;
        }
        $d = json_decode($raw, true);
        if (!is_array($d) || !isset($d['ts'], $d['cpu_total'], $d['rx'])) {
            return null;
        }
        // 采样点超过 10 分钟视为失效（期间无访问，速率差值无意义）
        if ((microtime(true) - (float)$d['ts']) > 600) {
            return null;
        }
        return $d;
    }

    /** @param array $d */
    private static function writeSample(array $d): void {
        @file_put_contents(
            sys_get_temp_dir() . '/' . self::SAMPLE_FILE,
            json_encode($d),
            LOCK_EX
        );
    }

    /** @return array{ts:float, cpu_total:int, cpu_idle:int, rx:int, tx:int} */
    private static function takeSample(): array {
        $out = ['ts' => microtime(true), 'cpu_total' => 0, 'cpu_idle' => 0, 'rx' => 0, 'tx' => 0];

        $stat = @file_get_contents('/proc/stat');
        if ($stat !== false && preg_match('/^cpu\s+(.*)$/m', $stat, $m)) {
            $cols = array_map('intval', preg_split('/\s+/', trim($m[1])));
            // user nice system idle iowait irq softirq steal（guest/guest_nice 已计入 user/nice，
            // 不得重复累加，否则 CPU 使用率被系统性低估）
            $idle = $cols[3] + ($cols[4] ?? 0);
            $total = array_sum(array_slice($cols, 0, 8));
            $out['cpu_idle'] = $idle;
            $out['cpu_total'] = $total;
        }

        $net = @file_get_contents('/proc/net/dev');
        if ($net !== false) {
            foreach (explode("\n", $net) as $line) {
                $pos = strpos($line, ':');
                if ($pos === false) {
                    continue;
                }
                $iface = trim(substr($line, 0, $pos));
                if ($iface === '' || $iface === 'lo') {
                    continue;
                }
                $cols = preg_split('/\s+/', trim(substr($line, $pos + 1)));
                // 第 1 列收字节、第 9 列发字节
                $out['rx'] += (int)($cols[0] ?? 0);
                $out['tx'] += (int)($cols[8] ?? 0);
            }
        }
        return $out;
    }

    private static function cpuModel(): string {
        $info = @file_get_contents('/proc/cpuinfo');
        if ($info !== false && preg_match('/^model name\s*:\s*(.+)$/m', $info, $m)) {
            return trim($m[1]);
        }
        return '未知';
    }

    private static function cpuCores(): int {
        $info = @file_get_contents('/proc/cpuinfo');
        if ($info !== false) {
            return (int)preg_match_all('/^processor\s*:/m', $info);
        }
        return 0;
    }

    /** @return array{total:int, used:int, available:int, percent:float} 单位：字节 */
    private static function memInfo(): array {
        $info = @file_get_contents('/proc/meminfo');
        $get = static function (string $key) use ($info): int {
            if ($info !== false && preg_match('/^' . $key . '\s*:\s*(\d+)\s*kB/m', $info, $m)) {
                return (int)$m[1] * 1024;
            }
            return 0;
        };
        $total = $get('MemTotal');
        $available = $get('MemAvailable');
        $used = max(0, $total - $available);
        return [
            'total'     => $total,
            'used'      => $used,
            'available' => $available,
            'percent'   => $total > 0 ? round($used / $total * 100, 1) : 0.0,
        ];
    }

    /**
     * 物理磁盘分区用量（/proc/mounts 中 /dev/* 挂载点，按挂载点去重）
     * @return array<int, array{mount:string, device:string, total:int, free:int, percent:float}> 单位：字节
     */
    private static function diskInfo(): array {
        $mounts = @file_get_contents('/proc/mounts');
        $out = [];
        if ($mounts === false) {
            return $out;
        }
        $realFs = ['ext2', 'ext3', 'ext4', 'xfs', 'btrfs', 'f2fs', 'vfat', 'exfat', 'ntfs'];
        $seen = [];
        $seenDev = []; // 同一设备的绑定挂载（如 /tmp 绑自 rl-root）只保留首个挂载点
        foreach (explode("\n", $mounts) as $line) {
            $cols = preg_split('/\s+/', trim($line));
            if (count($cols) < 3) {
                continue;
            }
            [$dev, $mount, $fs] = $cols;
            // 内核把 mount 路径中的空格/制表/反斜杠转义为 \040/\011/\134，需解码，
            // 否则含空格的挂载点传给 disk_total_space 会失败、分区悄悄消失
            $dev = stripcslashes($dev);
            $mount = stripcslashes($mount);
            // 只保留物理设备挂载的真实分区（排除 tmpfs / overlay / 绑定挂载）
            if (!str_starts_with($dev, '/dev/') || !in_array($fs, $realFs, true) || isset($seen[$mount])) {
                continue;
            }
            $seen[$mount] = true;
            if (isset($seenDev[$dev])) {
                continue;
            }
            $seenDev[$dev] = true;
            $total = @disk_total_space($mount);
            $free = @disk_free_space($mount);
            if ($total === false || $total <= 0) {
                continue;
            }
            $free = $free === false ? 0 : $free;
            $out[] = [
                'mount'   => $mount,
                'device'  => $dev,
                'total'   => (int)$total,
                'free'    => (int)$free,
                'percent' => round(($total - $free) / $total * 100, 1),
            ];
        }
        // 根分区排最前
        usort($out, static fn(array $a, array $b): int => strcmp($a['mount'], $b['mount']));
        return $out;
    }

    /** @return array{0:float,1:float,2:float} */
    private static function loadAvg(): array {
        $load = @file_get_contents('/proc/loadavg');
        if ($load !== false) {
            $parts = array_slice(explode(' ', trim($load)), 0, 3);
            if (count($parts) === 3) {
                return array_map('floatval', $parts);
            }
        }
        return [0.0, 0.0, 0.0];
    }

    private static function uptimeText(): string {
        $up = (float)@file_get_contents('/proc/uptime');
        $days = (int)floor($up / 86400);
        $hours = (int)floor(fmod($up, 86400) / 3600);
        return $days > 0 ? "{$days} 天 {$hours} 小时" : "{$hours} 小时";
    }
}
