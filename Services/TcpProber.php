<?php

namespace Plugin\SubscribeLb\Services;

/**
 * 并发 TCP 建连探测（非阻塞 connect + stream_select），所有目标共用一个总超时。
 *
 * 只能证明「面板服务器到成员 host:port 的 TCP 能建连」：能发现机器宕机、端口没在监听、入口 DNAT 失效；
 * 发现不了大陆方向的封锁（GFW 不拦海外互连），封锁仍靠人工/外部探测把成员置为强制不可用。
 */
final class TcpProber
{
    /**
     * @param array<int|string, array{host: string, port: int}> $targets
     * @return array<int|string, array{ok: bool, ms: ?int, error: ?string}>
     */
    public static function probe(array $targets, float $timeout = 4.0): array
    {
        $results = [];
        $sockets = [];
        $started = [];
        $t0 = microtime(true);

        foreach ($targets as $key => $target) {
            $host = trim((string) $target['host']);
            $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);
            if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                $results[$key] = ['ok' => false, 'ms' => null, 'error' => 'dns'];
                continue;
            }
            $addr = (str_contains($ip, ':') ? "[{$ip}]" : $ip) . ':' . (int) $target['port'];
            $errno = 0;
            $errstr = '';
            $socket = @stream_socket_client(
                'tcp://' . $addr,
                $errno,
                $errstr,
                $timeout,
                STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT
            );
            if ($socket === false) {
                $results[$key] = ['ok' => false, 'ms' => null, 'error' => 'connect: ' . ($errstr ?: $errno)];
                continue;
            }
            stream_set_blocking($socket, false);
            $sockets[$key] = $socket;
            $started[$key] = microtime(true);
        }

        while (!empty($sockets)) {
            $remaining = $timeout - (microtime(true) - $t0);
            if ($remaining <= 0) {
                break;
            }
            $read = null;
            $write = array_values($sockets);
            $except = null;
            $sec = (int) floor($remaining);
            $usec = (int) (($remaining - $sec) * 1000000);
            $n = @stream_select($read, $write, $except, $sec, $usec);
            if ($n === false) {
                break;
            }
            if ($n === 0) {
                continue;
            }
            foreach ($write as $socket) {
                $key = array_search($socket, $sockets, true);
                if ($key === false) {
                    continue;
                }
                $error = self::socketError($socket);
                $results[$key] = $error === null
                    ? ['ok' => true, 'ms' => (int) round((microtime(true) - $started[$key]) * 1000), 'error' => null]
                    : ['ok' => false, 'ms' => null, 'error' => $error];
                @fclose($socket);
                unset($sockets[$key]);
            }
        }

        foreach ($sockets as $key => $socket) {
            @fclose($socket);
            $results[$key] = ['ok' => false, 'ms' => null, 'error' => 'timeout'];
        }
        return $results;
    }

    /** 非阻塞 connect 完成后取 SO_ERROR；没有 sockets 扩展时退回 getpeername 判断 */
    private static function socketError($stream): ?string
    {
        if (function_exists('socket_import_stream')) {
            $sock = @socket_import_stream($stream);
            if ($sock !== false && $sock !== null) {
                $err = @socket_get_option($sock, SOL_SOCKET, SO_ERROR);
                if ($err === 0) {
                    return null;
                }
                if (is_int($err)) {
                    return 'connect: ' . socket_strerror($err);
                }
            }
        }
        return @stream_socket_get_name($stream, true) === false ? 'connect: refused' : null;
    }
}
