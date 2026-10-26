<?php

namespace Plugin\SubscribeLb\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\User;
use Plugin\SubscribeLb\Services\AssignmentStore;
use Plugin\SubscribeLb\Services\DnsResolver;
use Plugin\SubscribeLb\Services\EventLog;
use Plugin\SubscribeLb\Services\GroupInspector;
use Plugin\SubscribeLb\Services\HealthEvaluator;
use Plugin\SubscribeLb\Services\LoadBalancer;
use Plugin\SubscribeLb\Services\MemberPool;
use Plugin\SubscribeLb\Services\ServerSnapshot;
use Plugin\SubscribeLb\Services\StrategyRegistry;
use Plugin\SubscribeLb\Services\Tables;
use App\Services\ServerService;
use App\Services\UserService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 负载均衡组后台接口：/api/v2/{secure_path}/sub-lb/*（admin 中间件）
 * 控制台页面：/{secure_path}/sub-lb（只是静态壳，数据全走带后台登录 token 的接口）
 */
class AdminController extends Controller
{
    public function console()
    {
        $html = @file_get_contents(dirname(__DIR__) . '/resources/console.html');
        if ($html === false) {
            return response('console.html missing', 500);
        }
        $securePath = admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key'))));
        $html = str_replace('__SECURE_PATH__', htmlspecialchars((string) $securePath, ENT_QUOTES), $html);
        return response($html, 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'no-store',
            'X-Robots-Tag' => 'noindex',
        ]);
    }

    public function fetch()
    {
        if (!Tables::installed()) {
            return $this->success(['installed' => false]);
        }
        $now = time();
        $all = GroupInspector::loadAll();
        $store = new AssignmentStore();
        $groups = [];
        foreach ($all['groups'] as $group) {
            $insp = GroupInspector::inspect($group, $all['snapshot'], $now);
            /** @var MemberPool $pool */
            $pool = $insp['pool'];
            $interval = LoadBalancer::clampInterval((int) $group['interval_seconds']);
            $counts = $store->activeCounts($group['id'], $now - $interval);
            $members = [];
            foreach ($group['members'] as $m) {
                $sid = (int) $m['server_id'];
                $s = $all['snapshot'][$sid] ?? null;
                $e = $pool->entries[$sid] ?? null;
                $members[] = [
                    'id' => (int) $m['id'],
                    'server_id' => $sid,
                    'status' => $m['status'],
                    'standalone' => (int) $m['standalone'] === 1,
                    'managed' => $m['managed'] ?? null,
                    'health_override' => $m['health_override'],
                    'health_note' => $m['health_note'],
                    'health_override_at' => $m['health_override_at'] !== null ? (int) $m['health_override_at'] : null,
                    'health_override_by' => $m['health_override_by'],
                    'probe_state' => $m['probe_state'],
                    'probe_checked_at' => $m['probe_checked_at'] !== null ? (int) $m['probe_checked_at'] : null,
                    'probe_changed_at' => $m['probe_changed_at'] !== null ? (int) $m['probe_changed_at'] : null,
                    'probe_detail' => $m['probe_detail'],
                    'probe_ok_streak' => (int) $m['probe_ok_streak'],
                    'probe_fail_streak' => (int) $m['probe_fail_streak'],
                    'server' => $s === null ? null : $this->publicServer($s),
                    'eval' => $e === null ? null : [
                        'blocked' => $e['blocked'],
                        'healthy' => $e['healthy'],
                        'auto_ok' => $e['auto_ok'],
                        'health_reason' => $e['health_reason'],
                        'signals' => $e['signals'],
                        'candidate' => $pool->isCandidate($sid),
                    ],
                    'assigned' => $counts[$sid] ?? 0,
                ];
            }
            $groups[] = [
                'id' => $group['id'],
                'entry_id' => LoadBalancer::entryId($group['id']),
                'name' => $group['name'],
                'enabled' => (int) $group['enabled'] === 1,
                'sort' => (float) $group['sort'],
                'interval_seconds' => (int) $group['interval_seconds'],
                'strategy' => $group['strategy'],
                'health_mode' => HealthEvaluator::normalizeMode($group['health_mode']),
                'remark' => $group['remark'],
                'resolve_server_id' => isset($group['resolve_server_id']) ? ($group['resolve_server_id'] !== null ? (int) $group['resolve_server_id'] : null) : null,
                'resolve_interval' => (int) ($group['resolve_interval'] ?? DnsResolver::DEFAULT_INTERVAL),
                'resolved_at' => isset($group['resolved_at']) && $group['resolved_at'] !== null ? (int) $group['resolved_at'] : null,
                'resolve_detail' => isset($group['resolve_detail']) ? json_decode((string) $group['resolve_detail'], true) : null,
                'state' => $insp['state'],
                'tier' => $pool->tier,
                'summary' => $pool->summary(),
                'stored_state' => $group['state'],
                'state_changed_at' => $group['state_changed_at'] !== null ? (int) $group['state_changed_at'] : null,
                'checked_at' => $group['checked_at'] !== null ? (int) $group['checked_at'] : null,
                'warnings' => $insp['warnings'],
                'assigned_total' => array_sum($counts),
                'reasons_1h' => $store->recentReasons($group['id'], $now - 3600),
                'created_at' => (int) $group['created_at'],
                'updated_at' => (int) $group['updated_at'],
                'members' => $members,
            ];
        }
        $lastCheck = null;
        try {
            $lastCheck = Cache::get('sub_lb:last_check_at');
        } catch (\Throwable $e) {
        }
        return $this->success([
            'installed' => true,
            'now' => $now,
            'last_check_at' => $lastCheck !== null ? (int) $lastCheck : null,
            'entry_id_base' => LoadBalancer::ENTRY_ID_BASE,
            'strategies' => StrategyRegistry::options(),
            'health_modes' => HealthEvaluator::MODES,
            'statuses' => MemberPool::STATUSES,
            'states' => GroupInspector::STATES,
            'probe_rules' => [
                'down_after' => HealthEvaluator::PROBE_DOWN_AFTER,
                'up_after' => HealthEvaluator::PROBE_UP_AFTER,
                'stale_after' => HealthEvaluator::PROBE_STALE_AFTER,
                'heartbeat_window' => HealthEvaluator::HEARTBEAT_WINDOW,
            ],
            'groups' => $groups,
        ]);
    }

    /** 候选服务器列表（添加成员用） */
    public function servers()
    {
        $memberOf = [];
        if (Tables::installed()) {
            foreach (DB::table(Tables::MEMBERS)->get(['server_id', 'group_id']) as $row) {
                $memberOf[(int) $row->server_id] = (int) $row->group_id;
            }
        }
        $out = [];
        foreach (Server::orderBy('sort')->orderBy('id')->get() as $s) {
            $out[] = [
                'id' => (int) $s->id,
                'name' => (string) $s->name,
                'type' => (string) $s->type,
                'host' => (string) $s->host,
                'port' => (string) $s->port,
                'rate' => (float) $s->rate,
                'rate_time_enable' => (bool) $s->rate_time_enable,
                'show' => (bool) $s->show,
                'enabled' => $s->enabled,
                'sort' => $s->sort,
                'machine_id' => $s->machine_id,
                'group_ids' => array_values(array_map('intval', (array) ($s->group_ids ?? []))),
                'lb_group_id' => $memberOf[(int) $s->id] ?? null,
            ];
        }
        return $this->success($out);
    }

    public function events(Request $request)
    {
        if (!Tables::installed()) {
            return $this->success([]);
        }
        $limit = max(1, min(500, (int) $request->input('limit', 100)));
        $q = DB::table(Tables::EVENTS)->orderByDesc('id')->limit($limit);
        if ($request->filled('group_id')) {
            $q->where('group_id', (int) $request->input('group_id'));
        }
        return $this->success($q->get());
    }

    /** 按用户查：该用户在每个组的分配记录 + 现在会拿到哪个成员（只读预览，不改分配） */
    public function userLookup(Request $request)
    {
        $q = trim((string) $request->input('q', ''));
        if ($q === '') {
            return $this->fail([422, '请输入用户 ID 或邮箱']);
        }
        $user = ctype_digit($q) ? User::find((int) $q) : User::where('email', $q)->first();
        if (!$user) {
            return $this->fail([404, '用户不存在']);
        }
        if (!Tables::installed()) {
            return $this->fail([400, '负载均衡表未安装']);
        }
        $rows = (new AssignmentStore())->forUser((int) $user->id);
        $available = (new UserService())->isAvailable($user) ? ServerService::getAvailableServers($user) : [];
        $preview = [];
        foreach (LoadBalancer::make()->present($available, $user) as $entry) {
            $meta = $entry[LoadBalancer::META_KEY] ?? null;
            if (!is_array($meta)) {
                continue;
            }
            $preview[(int) $meta['group_id']] = [
                'name' => $entry['name'],
                'server_id' => (int) $meta['server_id'],
                'reason' => $meta['reason'],
                'assigned_at' => $meta['assigned_at'],
                'type' => $entry['type'],
                'host' => $entry['host'],
                'port' => $entry['port'],
                'rate' => $entry['rate'],
            ];
        }
        return $this->success([
            'user' => [
                'id' => (int) $user->id,
                'email' => $user->email,
                'group_id' => $user->group_id,
                'plan_id' => $user->plan_id,
                'available' => !empty($available),
            ],
            'assignments' => array_values($rows),
            'preview' => $preview,
        ]);
    }

    // ------------------------------------------------------------------ 写操作

    public function groupSave(Request $request)
    {
        if (!Tables::installed()) {
            return $this->fail([400, '负载均衡表未安装，请先执行迁移']);
        }
        $request->validate([
            'id' => 'nullable|integer',
            'name' => 'required|string|max:64',
            'enabled' => 'nullable|boolean',
            'sort' => 'nullable|numeric',
            'interval_seconds' => 'nullable|integer|min:' . LoadBalancer::MIN_INTERVAL . '|max:' . LoadBalancer::MAX_INTERVAL,
            'strategy' => 'nullable|string|max:32',
            'health_mode' => 'nullable|string|max:32',
            'remark' => 'nullable|string|max:255',
            'server_ids' => 'nullable|array|max:100',   // 仅新建时：同时加入的成员
            'server_ids.*' => 'integer',
            'resolve_server_id' => 'nullable|integer',   // 域名入口解析来源节点；null=不解析
            'resolve_interval' => 'nullable|integer|min:' . DnsResolver::MIN_INTERVAL . '|max:' . DnsResolver::MAX_INTERVAL,
        ], [
            'resolve_interval.min' => '解析间隔最少 5 分钟',
            'resolve_interval.max' => '解析间隔最多 24 小时',
            'name.required' => '展示名称不能为空',
            'name.max' => '展示名称最多 64 个字符',
            'interval_seconds.min' => '分配间隔最少 ' . LoadBalancer::MIN_INTERVAL . ' 秒',
            'interval_seconds.max' => '分配间隔最多 30 天',
            'sort.numeric' => '排序必须是数字',
        ]);
        $name = trim((string) $request->input('name'));
        if ($name === '') {
            return $this->fail([422, '展示名称不能为空']);
        }
        $strategy = $request->input('strategy', StrategyRegistry::DEFAULT);
        if (!StrategyRegistry::has($strategy)) {
            return $this->fail([422, '未知分配策略：' . $strategy]);
        }
        $mode = $request->input('health_mode', 'heartbeat');
        if (!isset(HealthEvaluator::MODES[$mode])) {
            return $this->fail([422, '未知健康模式：' . $mode]);
        }
        $id = $request->input('id') ? (int) $request->input('id') : null;
        $existing = $id ? DB::table(Tables::GROUPS)->where('id', $id)->first() : null;
        if ($id && !$existing) {
            return $this->fail([404, '组不存在']);
        }
        $dup = DB::table(Tables::GROUPS)->where('name', $name)->when($id, fn($q) => $q->where('id', '!=', $id))->exists();
        if ($dup) {
            return $this->fail([422, '已有同名负载均衡组']);
        }
        $newIds = (!$existing && is_array($request->input('server_ids')))
            ? array_values(array_unique(array_map('intval', $request->input('server_ids'))))
            : [];
        if ($newIds) {
            $err = $this->validateNewMembers(null, $newIds, MemberPool::STATUS_ACTIVE);
            if ($err) {
                return $this->fail([422, $err]);
            }
        }
        // 域名入口解析来源：必须存在、地址是域名、不在别的组里、倍率与组内一致
        $resolveId = $request->filled('resolve_server_id') ? (int) $request->input('resolve_server_id') : null;
        if ($resolveId !== null) {
            $src = Server::find($resolveId);
            if (!$src) {
                return $this->fail([422, "解析来源节点 #{$resolveId} 不存在"]);
            }
            if (filter_var(trim((string) $src->host), FILTER_VALIDATE_IP)) {
                return $this->fail([422, "节点 #{$resolveId} 的地址已经是 IP，不需要解析"]);
            }
            $other = DB::table(Tables::MEMBERS)->where('server_id', $resolveId)->when($id, fn($q) => $q->where('group_id', '!=', $id))->value('group_id');
            if ($other) {
                return $this->fail([422, "节点 #{$resolveId} 已在别的负载均衡组里"]);
            }
            if ($err = $this->rateMismatch($id, array_merge([$src], Server::whereIn('id', $newIds)->get()->all()))) {
                return $this->fail([422, $err]);
            }
        }
        // 组名不能和会被独立下发的节点重名；本组的隐藏成员（含本次一起加入的）不算
        $hiddenIds = $id
            ? DB::table(Tables::MEMBERS)->where('group_id', $id)->where('standalone', 0)->pluck('server_id')->map(fn($v) => (int) $v)->all()
            : $newIds;
        if ($resolveId !== null) {
            $hiddenIds[] = $resolveId; // 来源节点会作为隐藏成员加入
        }
        $clash = DB::table('v2_server')->where('name', $name)->where('show', 1)
            ->when(!empty($hiddenIds), fn($q) => $q->whereNotIn('id', $hiddenIds))
            ->value('id');
        if ($clash) {
            return $this->fail([422, "展示名称与节点 #{$clash} 重名（客户端遇到重名代理会报错），请换一个名称"]);
        }

        $now = time();
        $data = [
            'name' => $name,
            'enabled' => $request->has('enabled') ? ($request->boolean('enabled') ? 1 : 0) : ($existing->enabled ?? 1),
            'sort' => $request->filled('sort') ? (float) $request->input('sort') : ($existing->sort ?? 0),
            'interval_seconds' => $request->filled('interval_seconds') ? (int) $request->input('interval_seconds') : ($existing->interval_seconds ?? 3600),
            'strategy' => $strategy,
            'health_mode' => $mode,
            'remark' => $request->input('remark'),
            'updated_at' => $now,
        ];
        if (Schema::hasColumn(Tables::GROUPS, 'resolve_server_id')) {
            $data['resolve_server_id'] = $resolveId;
            $data['resolve_interval'] = $request->filled('resolve_interval') ? (int) $request->input('resolve_interval')
                : (int) ($existing->resolve_interval ?? DnsResolver::DEFAULT_INTERVAL);
            if ($existing && (int) ($existing->resolve_server_id ?? 0) !== (int) ($resolveId ?? 0)) {
                $data['resolved_at'] = null; // 换了来源：下一轮立即重解析
            }
        } elseif ($resolveId !== null) {
            return $this->fail([400, '域名解析功能需要先执行 002 迁移']);
        }
        if ($existing) {
            DB::table(Tables::GROUPS)->where('id', $id)->update($data);
        } else {
            $data['created_at'] = $now;
            $id = DB::transaction(function () use ($data, $newIds, $now) {
                $gid = (int) DB::table(Tables::GROUPS)->insertGetId($data);
                $this->insertMembers($gid, $newIds, MemberPool::STATUS_ACTIVE, $now);
                return $gid;
            });
        }
        EventLog::record($existing ? 'group_updated' : 'group_created', EventLog::INFO, $id, null,
            ['by' => $this->actor($request), 'server_ids' => $newIds] + $data);
        $resolve = null;
        if ($resolveId !== null) {
            // 保存后立即在面板服务器解析一次，后台马上能看到自动成员
            $row = (array) DB::table(Tables::GROUPS)->where('id', $id)->first();
            try {
                $resolve = DnsResolver::resolveGroup($row, time());
            } catch (\Throwable $e) {
                $resolve = ['error' => $e->getMessage()];
            }
        }
        return $this->success(['id' => $id, 'resolve' => $resolve]);
    }

    public function groupToggle(Request $request)
    {
        $request->validate(['id' => 'required|integer', 'enabled' => 'required|boolean']);
        $id = (int) $request->input('id');
        $n = DB::table(Tables::GROUPS)->where('id', $id)->update([
            'enabled' => $request->boolean('enabled') ? 1 : 0,
            'updated_at' => time(),
        ]);
        if (!$n) {
            return $this->fail([404, '组不存在']);
        }
        EventLog::record($request->boolean('enabled') ? 'group_enabled' : 'group_disabled', EventLog::INFO, $id, null,
            ['by' => $this->actor($request)]);
        return $this->success(true);
    }

    /** 删除组：成员释放，回到按各自 show 独立下发；分配记录一并删除 */
    public function groupDrop(Request $request)
    {
        $request->validate(['id' => 'required|integer']);
        $id = (int) $request->input('id');
        $group = DB::table(Tables::GROUPS)->where('id', $id)->first();
        if (!$group) {
            return $this->fail([404, '组不存在']);
        }
        $hidden = [];
        DB::transaction(function () use ($id, &$hidden) {
            $hidden = $this->hideEntryChildren($id, DB::table(Tables::MEMBERS)->where('group_id', $id)->pluck('server_id')->map(fn($v) => (int) $v)->all());
            DB::table(Tables::ASSIGNMENTS)->where('group_id', $id)->delete();
            DB::table(Tables::MEMBERS)->where('group_id', $id)->delete();
            DB::table(Tables::GROUPS)->where('id', $id)->delete();
        });
        EventLog::record('group_deleted', EventLog::WARNING, $id, null, ['name' => $group->name, 'hidden_entry_children' => $hidden, 'by' => $this->actor($request)]);
        return $this->success(true);
    }

    public function memberAdd(Request $request)
    {
        $request->validate([
            'group_id' => 'required|integer',
            'server_ids' => 'required|array|min:1|max:100',
            'server_ids.*' => 'integer',
            'status' => 'nullable|in:active,draining,disabled',
        ]);
        $gid = (int) $request->input('group_id');
        if (!DB::table(Tables::GROUPS)->where('id', $gid)->exists()) {
            return $this->fail([404, '组不存在']);
        }
        $ids = array_values(array_unique(array_map('intval', $request->input('server_ids'))));
        $status = $request->input('status', MemberPool::STATUS_ACTIVE);
        $err = $this->validateNewMembers($gid, $ids, $status);
        if ($err) {
            return $this->fail([422, $err]);
        }
        $now = time();
        DB::transaction(function () use ($ids, $gid, $status, $now) {
            $this->insertMembers($gid, $ids, $status, $now);
            DB::table(Tables::GROUPS)->where('id', $gid)->update(['updated_at' => $now]);
        });
        EventLog::record('members_added', EventLog::INFO, $gid, null,
            ['server_ids' => $ids, 'status' => $status, 'by' => $this->actor($request)]);
        return $this->success(true);
    }

    /** 新成员校验：服务器存在、不在任何组里、倍率与组内一致；返回错误文案或 null */
    private function validateNewMembers(?int $gid, array $ids, string $status): ?string
    {
        $servers = Server::whereIn('id', $ids)->get()->keyBy('id');
        $missing = array_diff($ids, $servers->keys()->map(fn($k) => (int) $k)->all());
        if ($missing) {
            return '服务器不存在：#' . implode(', #', $missing);
        }
        $taken = DB::table(Tables::MEMBERS)->whereIn('server_id', $ids)->get(['server_id', 'group_id']);
        if ($taken->isNotEmpty()) {
            $names = DB::table(Tables::GROUPS)->whereIn('id', $taken->pluck('group_id'))->pluck('name', 'id');
            $msg = $taken->map(fn($r) => "#{$r->server_id} 已在组「" . ($names[$r->group_id] ?? $r->group_id) . '」')->implode('；');
            return '一台节点只能属于一个负载均衡组：' . $msg;
        }
        if ($status !== MemberPool::STATUS_DISABLED) {
            return $this->rateMismatch($gid, $servers->values()->all());
        }
        return null;
    }

    private function insertMembers(int $gid, array $ids, string $status, int $now): void
    {
        foreach ($ids as $sid) {
            DB::table(Tables::MEMBERS)->insert([
                'group_id' => $gid,
                'server_id' => $sid,
                'status' => $status,
                'standalone' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function memberUpdate(Request $request)
    {
        $request->validate([
            'id' => 'required|integer',
            'status' => 'nullable|in:active,draining,disabled',
            'standalone' => 'nullable|boolean',
        ]);
        $member = DB::table(Tables::MEMBERS)->where('id', (int) $request->input('id'))->first();
        if (!$member) {
            return $this->fail([404, '成员不存在']);
        }
        $data = ['updated_at' => time()];
        if ($request->filled('status')) {
            $status = $request->input('status');
            if ($status !== MemberPool::STATUS_DISABLED && $member->status === MemberPool::STATUS_DISABLED) {
                $server = Server::find((int) $member->server_id);
                if ($server && ($err = $this->rateMismatch((int) $member->group_id, [$server], (int) $member->id))) {
                    return $this->fail([422, $err]);
                }
            }
            $data['status'] = $status;
        }
        if ($request->has('standalone')) {
            $data['standalone'] = $request->boolean('standalone') ? 1 : 0;
        }
        DB::table(Tables::MEMBERS)->where('id', $member->id)->update($data);
        DB::table(Tables::GROUPS)->where('id', $member->group_id)->update(['updated_at' => $data['updated_at']]);
        EventLog::record('member_updated', EventLog::INFO, (int) $member->group_id, (int) $member->server_id,
            ['by' => $this->actor($request)] + $data);
        return $this->success(true);
    }

    /** 人工/外部健康覆盖：auto=跟随自动信号 / down=强制不可用（立即让其上的分配失效）/ up=强制可用 */
    public function memberHealth(Request $request)
    {
        $request->validate([
            'id' => 'required|integer',
            'override' => 'required|in:auto,down,up',
            'note' => 'nullable|string|max:255',
        ]);
        $member = DB::table(Tables::MEMBERS)->where('id', (int) $request->input('id'))->first();
        if (!$member) {
            return $this->fail([404, '成员不存在']);
        }
        $override = $request->input('override') === 'auto' ? null : $request->input('override');
        $now = time();
        DB::table(Tables::MEMBERS)->where('id', $member->id)->update([
            'health_override' => $override,
            'health_note' => $request->input('note'),
            'health_override_at' => $now,
            'health_override_by' => $this->actor($request),
            'updated_at' => $now,
        ]);
        EventLog::record('health_override', $override === 'down' ? EventLog::WARNING : EventLog::INFO,
            (int) $member->group_id, (int) $member->server_id,
            ['override' => $override ?? 'auto', 'note' => $request->input('note'), 'by' => $this->actor($request)]);
        return $this->success(true);
    }

    public function memberDrop(Request $request)
    {
        $request->validate(['id' => 'required|integer']);
        $member = DB::table(Tables::MEMBERS)->where('id', (int) $request->input('id'))->first();
        if (!$member) {
            return $this->fail([404, '成员不存在']);
        }
        DB::transaction(function () use ($member) {
            $this->hideEntryChildren((int) $member->group_id, [(int) $member->server_id]);
            DB::table(Tables::ASSIGNMENTS)->where('group_id', $member->group_id)->where('server_id', $member->server_id)->delete();
            DB::table(Tables::MEMBERS)->where('id', $member->id)->delete();
            DB::table(Tables::GROUPS)->where('id', $member->group_id)->update(['updated_at' => time()]);
        });
        EventLog::record('member_removed', EventLog::WARNING, (int) $member->group_id, (int) $member->server_id,
            ['by' => $this->actor($request)]);
        return $this->success(true);
    }

    /** 手动重新分配：删掉组内（或某成员上）的分配，相关用户下次更新订阅时重新随机 */
    public function reset(Request $request)
    {
        $request->validate(['group_id' => 'required|integer', 'server_id' => 'nullable|integer']);
        $gid = (int) $request->input('group_id');
        $sid = $request->filled('server_id') ? (int) $request->input('server_id') : null;
        $n = (new AssignmentStore())->reset($gid, $sid);
        EventLog::record('manual_reset', EventLog::WARNING, $gid, $sid, ['deleted' => $n, 'by' => $this->actor($request)]);
        return $this->success(['deleted' => $n]);
    }

    // ------------------------------------------------------------------ helpers

    /**
     * 组内成员倍率配置必须一致。
     * @param Server[] $incoming
     */
    private function rateMismatch(?int $gid, array $incoming, ?int $exceptMemberId = null): ?string
    {
        $existingIds = $gid === null ? [] : DB::table(Tables::MEMBERS)->where('group_id', $gid)
            ->where('status', '!=', MemberPool::STATUS_DISABLED)
            ->when($exceptMemberId, fn($q) => $q->where('id', '!=', $exceptMemberId))
            ->pluck('server_id')->map(fn($v) => (int) $v)->all();
        $signatures = [];
        foreach (Server::whereIn('id', $existingIds)->get() as $s) {
            $signatures[ServerSnapshot::rateSignature($s)][] = "#{$s->id}({$s->rate}x)";
        }
        foreach ($incoming as $s) {
            $signatures[ServerSnapshot::rateSignature($s)][] = "#{$s->id}({$s->rate}x)";
        }
        if (count($signatures) <= 1) {
            return null;
        }
        $parts = [];
        foreach ($signatures as $list) {
            $parts[] = implode('/', $list);
        }
        return '组内成员倍率（含分时倍率）必须一致：' . implode(' ≠ ', $parts);
    }

    /**
     * 为负载均衡建的「入口子节点」（parent 是同组成员、machine_id=NULL）离开组后不应以「名称 · IP」单独下发：
     * 置 show=0（不删除，可在节点管理里恢复）。返回被隐藏的 server id。
     */
    private function hideEntryChildren(int $gid, array $serverIds): array
    {
        if (empty($serverIds)) {
            return [];
        }
        $groupServerIds = DB::table(Tables::MEMBERS)->where('group_id', $gid)->pluck('server_id')->map(fn($v) => (int) $v)->all();
        $ids = DB::table('v2_server')->whereIn('id', $serverIds)->whereNull('machine_id')
            ->whereIn('parent_id', $groupServerIds)->pluck('id')->map(fn($v) => (int) $v)->all();
        if ($ids) {
            DB::table('v2_server')->whereIn('id', $ids)->update(['show' => 0, 'updated_at' => date('Y-m-d H:i:s')]);
        }
        return $ids;
    }

    private function publicServer(array $s): array
    {
        return [
            'id' => $s['id'],
            'name' => $s['name'],
            'type' => $s['type'],
            'host' => $s['host'],
            'port' => $s['port'],
            'rate' => $s['rate'],
            'show' => $s['show'],
            'enabled' => $s['enabled'],
            'machine_id' => $s['machine_id'],
            'group_ids' => $s['group_ids'],
            'is_online' => $s['is_online'],
            'last_check_at' => $s['last_check_at'] !== null ? (int) $s['last_check_at'] : null,
            'online' => $s['online'],
            'over_cap' => $s['over_cap'],
            'probeable' => ServerSnapshot::probeable($s),
        ];
    }

    private function actor(Request $request): string
    {
        $u = $request->user();
        return 'admin:' . ($u ? $u->email : '?');
    }
}
