# Wallos fork 改动清单与上游合并指南

本仓库是 [ellite/Wallos](https://github.com/ellite/Wallos) 的个人 fork（`iamvicliu/Wallos`）。
目标：**持续跟上游最新代码，同时保留下面这些自用功能。**

本文档是合并时的唯一凭据 —— 改 fork 功能后请同步更新这里。

| 远端 | 地址 | 说明 |
|---|---|---|
| `origin` | https://github.com/ellite/Wallos.git | 上游，只读参考 |
| `myfork` | https://github.com/iamvicliu/Wallos.git | 本 fork，推送目标 |

**当前基线**

| 项 | 值 |
|---|---|
| 已合并的上游版本 | **v5.8.3**（`cc9677a`） |
| 分支 | `merge/upstream-v5.8.3` |
| fork 上次独立开发时的上游基线 | v4.9.0-2（`7c0b76f`） |
| 合并后相对上游的差异 | 46 个文件，约 1129 行（全部为本 fork 功能） |

---

## 一、合并上游新版本的流程

```bash
cd <本仓库根目录>
git fetch origin
git log --oneline -1 origin/main          # 确认上游新版本号

git switch -c merge/upstream-<新版本>      # 新分支，不动 main
git merge origin/main
```

冲突处理完后**必须做这四步核对**：

```bash
# 1. 无冲突残留
git grep -n -E '^(<<<<<<<|>>>>>>>|=======$)' -- . ':!vendor'

# 2. 差异范围只应落在下面第五节列出的文件里
git diff --name-status HEAD origin/main

# 3. 上游独有的行必须逐条解释清楚（正常只有 10 行左右，见第四节）
git diff HEAD origin/main | awk '/^\+\+\+ b\//{f=substr($2,3)} /^\+[^+]/{print f"\t"$0}'

# 4. JS 语法 + i18n 结构
for f in $(git diff --name-only HEAD -- '*.js'); do node --check "$f" || echo "FAIL $f"; done
```

本机没有 `php`，PHP 只能做结构校验（大括号平衡、i18n 键值合法性）；
**真正的语法检查要在服务器上做**（部署前跑一次容器）。

---

## 二、已知冲突模式（每次合并都会重复出现）

### 1. i18n 文件的「整文件冲突」——行尾差异，不是内容冲突

`includes/i18n/*.php` 里，本 fork 改过的那些是 **LF**，上游和旧分支是 **CRLF**，
Git 会把整个文件判成冲突。**不要手工逐块解决。**

解法：以上游版本为底，只把本 fork 新增的键补回去。

```python
# 以 :2(我方) 为底，补入 :3(对方) 相对 :1(基线) 新增且我方没有的键
# 完整脚本见本仓库曾经用过的 /tmp/resolve_i18n2.py 思路：
#   1. 用 KEY_RE 正则提取三方的 "key" => "value", 行
#   2. 只保留对方有、我方没有的键
#   3. 插到 "total_cost_trend" 这类锚点后面（保持分区整洁）
```

补完必须校验：每个文件的键值全部合法、**0 重复键**。

### 2. `calendar.php` / `stats.php` 整文件冲突

上游重构过这两个文件（`stats.php` 从 Chart.js 迁到了 ApexCharts 并把图表拆成分区区块）。
解法同理：**取上游版本，再把本 fork 的片段补回去**，不要在旧结构上改。

### 3. `scripts/settings.js`

这个文件合并时是「两边内容拼在一起」解决的，拼接处曾经少一个 `}`，
是手工补上的 —— **本身不是上游的 bug**，但正因为如此，每次合并后
一定要对这个文件跑 `node --check`，别只看 git 有没有冲突。

---

## 三、本 fork 的全部功能改动

### 1. 日历格子显示订阅名 + 价格 + 续费图标

| 文件 | 改动 |
|---|---|
| `calendar.php` | 在 `$code` 之后加载 `$calCurrencies`（货币 id → 符号）；日历格子用 `calendar-subscription-title` + `cal-price` 渲染，点击走 `showSubscriptionDetails(event, id)` |
| `styles/styles.css` | `.calendar .calendar-subscription-title`、`.cal-renewal-icon`、`.cal-name`、`.cal-price` 及移动端媒体查询 |

上游同位置是 `.calendar-event` 小标签（只显示名字）。**保留我们的，替换上游的。**

### 2. 订阅批量操作

| 文件 | 改动 |
|---|---|
| `endpoints/subscriptions/bulk_update.php` | **fork 独有文件**，批量改分类/支付方式/通知提前天数/启停通知/删除 |
| `endpoints/subscriptions/toggle_notify.php` | **fork 独有文件**，单条切换通知 |
| `endpoints/subscriptions/get.php` | 返回值多一个 `notify` 字段 |
| `subscriptions.php` | 顶栏的批量模式按钮 + `.bulk-toolbar` 工具条 |
| `scripts/subscriptions.js` | `toggleBulkMode` / `enterBulkMode` / `exitBulkMode` / `updateBulkSelectedCount` / `bulkToggleSelectAll` / `onBulkActionChange` / `applyBulkAction` / `toggleSubscriptionNotify` |
| `includes/list_subscriptions.php` | 每条左侧的多选框 + 右侧通知铃铛 |
| `styles/styles.css` | `.bulk-toolbar*`、`.subscription-bulk-checkbox`、`#subscriptions.bulk-mode` 等 |

### 3. 订阅列表「列表 / 网格」视图切换

- `subscriptions.php` 顶栏 `.view-toggle`
- `scripts/subscriptions.js` 里 `setSubscriptionsView()`
- `styles/styles.css` 里 `.view-toggle` / `.grid-view`
- 容器 class 由**上游**的 `subscriptions<?= $subscriptionsView === 'grid' ? ' grid-view' : '' ?>` 提供（两边都要保留）

### 4. 新订阅默认值（默认自动续费 / 默认通知）

| 文件 | 改动 |
|---|---|
| `endpoints/settings/default_auto_renew.php` | **fork 独有文件** |
| `endpoints/settings/default_notifications.php` | **fork 独有文件** |
| `settings.php` | 「新订阅默认值」两个勾选框 |
| `scripts/settings.js` | `setDefaultAutoRenew()` / `setDefaultNotifications()` |
| `includes/getsettings.php` | 注入 `$settings['defaultAutoRenew']` / `defaultNotifications` |
| `includes/header.php` | 注入 `window.defaultAutoRenew` / `window.defaultNotifications` |
| `migrations/000060.php` | 加 `settings.default_auto_renew` / `default_notifications` 两列 |

> **本 fork 有意删掉了上游 `scripts/subscriptions.js` 里硬编码默认值的 4 行**
> （`const autoRenew = document.querySelector("#auto_renew"); autoRenew.checked = true;` 等），
> 改为读上面的 settings。合并时看到「上游多了这 4 行」是正常的，**不要加回来**。

### 5. 统计页「未来 12 个月支付预测」图

| 文件 | 改动 |
|---|---|
| `includes/stats_calculations.php` | 新增 `$monthlyForecastDataPoints` / `$showMonthlyForecastGraph`（文件末尾的 12 个月分桶计算） |
| `stats.php` | 预测图卡片并入上游的「趋势与预测」区块；`$showMonthlyForecastGraph` 要同时加进 `$showAnyGraph`、`$showTrendsSection` 和 graphs 容器的守卫；`loadLineGraph("monthlyForecastChart", ...)` |

> 上游 v5.8.3 已把统计页迁到 ApexCharts：容器用 `<div id="...">` 而不是 `<canvas>`。
> 合并时不要迁回 canvas 写法。

### 6. 新增的 i18n 键

`includes/i18n/*.php`（26 个语言）+ `scripts/i18n/en.js` + `scripts/i18n/zh_cn.js`：

```
monthly_payment_forecast, next_12_months          # 预测图
new_subscription_defaults, default_auto_renew, default_notifications   # 默认值
bulk_actions, select_all, selected, select_action,
disable_notifications, set_notification_timing, set_category,
set_payment_method, apply, cancel, enable_notifications,
no_subscriptions_selected, confirm_bulk_delete,
bulk_action_success, error_bulk_action             # 批量操作
renewal_type, automatically_renews, manual_renewal # 日历续费图标
```

### 7. ⚠️ 迁移文件改名：`000047.php` → `000060.php`

本 fork 原来的 `000047.php`（加 `default_auto_renew` / `default_notifications` 两列）
撞上了上游新版的 `000047.php`（加 `oauth_settings.require_email_verified`）。

因为**迁移是按「文件名字符串」去重的**（`includes/run_migrations.php` 拿
`migrations/000047.php` 这个字符串和 `migrations` 表里的记录比对），
两份 000047 只能留一份，另一份会被**永久跳过**。

处理：留上游的 000047，把本 fork 的内容改名成 `000060.php`（幂等，可重复执行）。

**部署时还有个必须做的一步**：线上库的 `migrations` 表里如果已经存了
`migrations/000047.php` 这条记录，上游 000047 的 `require_email_verified` 列就永远加不上。
上线前先删掉那条记录（或手工补列）：

```sql
DELETE FROM migrations WHERE migration = 'migrations/000047.php';
```

### 8. 修复：订阅详情弹窗价格显示 `&yen;1.00`（上游 bug，2026-10-17）

`scripts/subscription-details.js` 的 `detailsFormatPrice`：Wallos 语言代码是 `zh_cn` 这种下划线写法，
`Intl.NumberFormat` 不认，抛错后回退到库里存的 HTML 实体符号（`&yen;`），又用 `textContent` 输出，
所以页面上原样显示 `&yen;`。改为把 `_` 换成 `-` 再交给 `Intl`，回退分支先解码实体。
适合单独提上游 PR。

### 9. 首页「到期时间线」（2026-10-17）

首页「即将到期的付款」下面新增一块，把**未来 30 天**内每一次续费按日期排成竖向时间线：

- 每天标「今天 / 明天 / N 天后」+ 日期 + 当天合计（折算成主货币）；标题右侧是 30 天总笔数与总金额。
- 周期订阅会展开成窗口内的**每一次**续费（周付的会出现四五次）；日期按 `next_payment + n×周期` 计算，
  不链式累加，月底日期不会越滚越偏；早于今天的跳过，过了取消日期的停止。
- 一次性买断不算（没有续费）；手动续费且已逾期的不往后推（「逾期续订」区块已经显示了）。
- 续费图标与日历一致（自动=循环箭头，手动=手形），手动续费的条目左边加橙色竖条；点条目打开详情弹窗。

涉及文件：`includes/expiry_timeline.php`（新文件，`get_expiry_timeline()`）、`index.php`
（require + 区块）、`styles/styles.css`（末尾 `.expiry-timeline*`）、i18n（见下）。
新增 i18n 键只加了 `en` / `zh_cn` / `zh_tw`，其他语言由 `translate()` 回退到英文：

```
expiry_timeline, timeline_next_30_days, timeline_renewals,
timeline_today, timeline_tomorrow, timeline_in_n_days   # %d 天后
```

**版本号**：`includes/version.php` 改成了 `v5.8.3.2`（只为让 Service Worker 换缓存；
`v5.8.3.1` = 第 8 项价格修复 / 镜像 `5.8.3-2`，`v5.8.3.2` = 第 9 项时间线 / 镜像 `5.8.3-3`）。
合并上游新版本时这一行必然冲突，直接取上游的版本号即可。

---

## 四、上游独有行的正常范围

合并后 `git diff HEAD origin/main` 里「上游有、我们没有」的行应只有下面这些，
多出来的就说明**合并吞掉了上游代码**，要查：

| 文件 | 行数 | 原因 |
|---|---|---|
| `calendar.php` | 2 | 上游的 `.calendar-event` 标签，被我们的日历格子替换 |
| `stats.php` | 3 | 上游的图表守卫，被我们加上 `$showMonthlyForecastGraph` |
| `scripts/subscriptions.js` | 4 | 上游硬编码的新订阅默认值，我们改成读 settings |
| `scripts/i18n/en.js` | 1 | `// Calendar ` 行尾空格（本 fork 原本就没有） |
| `includes/i18n/ko.php` | 1 | 文件末尾换行差异 |

---

## 五、部署

**线上位置**：一台 arm64（aarch64）服务器，`ssh -i <SSH 密钥> ubuntu@<部署服务器>`，命令前加 `sudo docker`。服务器地址与密钥只记在本地，不进仓库。
数据在 `/home/ubuntu/wallos/`（`db/wallos.db` + `logos/`），**只 bind mount 这两个目录**，
**部署只换代码，别动数据目录**。入口 `https://<Wallos 域名>` → Cloudflare → nginx `wallet.conf` → `127.0.0.1:8282`。

> 2026-10-08 从旧的 x86_64 服务器整体迁到 arm：旧服务器上的 `wallos`(fork 镜像)/
> `wallos-old`(官方镜像) 容器都已停止但**保留**，数据也在，是整体退回的现成退路。
> 注意线上是 aarch64，**镜像必须在部署服务器本机构建**（旧服务器那份 x86_64 用不了）。

### 镜像怎么构建

用仓库根目录的 **`Dockerfile.fork`**（在官方镜像上叠应用层），**不是**官方那个 `Dockerfile`。
原因：服务器只有 954MB 内存 / 2 核，官方 Dockerfile 要编译 `intl`(ICU) 等扩展，容易 OOM；
而本 fork 只改应用层文件，叠一层结果等价。

```bash
# 1) 本机把代码传上去（排除 .git）
cd <本仓库根目录>
tar czf - --exclude='./.git' --exclude='.DS_Store' . \
  | ssh -i <SSH 密钥> ubuntu@<部署服务器> \
    'mkdir -p /home/ubuntu/wallos-build && tar xzf - -C /home/ubuntu/wallos-build'

# 2) 服务器上构建（线上是 arm64/aarch64，一律在服务器构建，保证与线上同架构）
ssh … 'cd /home/ubuntu/wallos-build && sudo docker build -f Dockerfile.fork -t wallos-fork:<版本>-<序号> .'
```

### 发布

```bash
# 备份（SQLite 在线备份，别直接 cp 运行中的库）
TS=$(date +%Y%m%d-%H%M%S); BK=/home/ubuntu/wallos-backup/$TS; mkdir -p "$BK"
python3 -c "import sqlite3;s=sqlite3.connect('file:/home/ubuntu/wallos/db/wallos.db?mode=ro',uri=True);d=sqlite3.connect('$BK/wallos.db');s.backup(d)"
sudo docker inspect wallos > "$BK/wallos-container.json"

# 切换（旧容器改名保留，方便回滚）
sudo docker stop wallos && sudo docker rename wallos wallos-old
sudo docker run -d --name wallos --restart unless-stopped \
  -p 127.0.0.1:8282:80 \
  -v /home/ubuntu/wallos/db:/var/www/html/db \
  -v /home/ubuntu/wallos/logos:/var/www/html/images/uploads/logos \
  --label com.centurylinklabs.watchtower.enable=false \
  wallos-fork:<版本>-<序号>
```

**回滚**：数据在 bind mount 上，重建容器即可恢复，所以回滚就是「用旧镜像名重建」——
把上面的 `wallos-fork:<新版本>` 换成上一版镜像（如 `sudo docker rm -f wallos` 后重新 `docker run`）。
数据坏了就用 `wallos-backup/<时间戳>/wallos.db` 恢复。旧服务器上的容器与数据已于 2026-10-17 删除，
不再有「整体退回旧服务器」这条路。

**上线后必须确认**：容器 `healthy`、`https://<Wallos 域名>/login.php` 返回 200、
`/var/log/cron/*.log` 继续在写、订阅与货币数量不变；改过 JS/CSS 的话还要做第 5 个坑里的清缓存。

### 五个坑

1. **Watchtower 必须排除这个容器**，否则会被拉回官方镜像、自用功能全部冲掉（2026-10 已发生过一次）。
   `--label com.centurylinklabs.watchtower.enable=false` 对**没有** `--label-enable` 的 Watchtower
   同样生效（已查官方文档确认）。**每次 `docker run` 重建容器都要带上这个标签** ——
   2026-10-08 迁到 arm 时就漏过一次，已补。
   两个宿主机的 Watchtower 都是每天 **02:00 UTC**（北京 10:00）跑一次。
2. **不要用 `bellamy/wallos:latest`**，那是官方镜像。
3. 容器内的定时任务是**正常执行**的，改部署方式时别弄丢：Dockerfile 把仓库根目录的 `cronjobs`
   复制到 `/etc/cron.d/cronjobs`，`startup.sh` 里的 `crontab -d -u root` 删的只是
   `/etc/crontabs/` 那份**副本**（防重复执行），dcron 读的是 `/etc/cron.d/cronjobs`。
   证据：容器内 `/var/log/cron/*.log` 每天都有记录（含 02:00 的汇率刷新）。
   注意容器时区是 **UTC**：汇率刷新 = 北京 10:00、通知推送（sendnotifications 09:00）= 北京 17:00。
4. **arm 的 `/home/ubuntu/wallos/` 里只放 `db/` 和 `logos/`**，别放代码、别放 compose 文件。
   5 月旧部署留下的 `docker-compose.yml`（指向官方镜像）和 16 个 php/js/css 残留已于 2026-10-08 清理；
   以后也不要在这个目录跑 `docker compose up -d`，否则会起第二个容器抢 8282 端口并降级应用。
5. **改了 JS/CSS 必须改版本号，并清 Cloudflare 缓存**。
   `service-worker.php` 的静态缓存名跟 `includes/version.php` 走，不改版本号浏览器永远用旧 JS。
   但光改版本号还不够：Service Worker 预缓存的是**不带查询参数**的 URL（如 `scripts/subscription-details.js`），
   Cloudflare 会把这个 URL 缓存一段时间（2026-10-17 实测 `cf-cache-status: HIT`），
   新 Service Worker 安装时就会拿到 Cloudflare 里的旧文件，并以新版本号存进缓存。
   所以上线后要按 URL 清掉改过的静态文件，**带版本号和不带版本号的两个 URL 都要清**
   （2026-10-17 第二次上线时只清了不带参数的，浏览器拿 `styles.css?v5.8.3.2` 仍命中旧文件）：
   `POST /zones/<zone>/purge_cache`，body `{"files":["https://<Wallos 域名>/scripts/xxx.js","https://<Wallos 域名>/scripts/xxx.js?<版本号>"]}`。
   清完用浏览器 `fetch(url,{cache:'reload'})` 检查内容里有没有新代码，别只看 purge 返回 success。
   已经装了坏缓存的浏览器，要等下一个版本号才会自愈，或者手动清站点数据。

### 迁移文件那个坑（已核实不成立）

曾经担心：线上 `migrations` 表里已有 `migrations/000047.php` 记录，
而本 fork 把自用的 000047 改成了 `000060.php`，会导致上游的 `require_email_verified` 被跳过。

**2026-10-08 实测**：线上 `oauth_settings.require_email_verified` 和
`settings.default_auto_renew`/`default_notifications` **三个列都已存在**（官方 v5.8.3 镜像早就跑过），
合并后保留的又是上游版 000047，`000060.php` 自带列存在性检查且幂等 —— **不需要删任何迁移记录**。

---

## 六、可清理项（降低以后合并的摩擦）

`scripts/calendar.js` 里还留着本 fork 早期的订阅详情弹窗实现，
上游 v5.8.3 已用统一的 `scripts/subscription-details.js` 取代：

- `closeSubscriptionModal()` / `renderSubscriptionModal()` / `openSubscriptionModal()`
  —— 已成死代码（只被彼此引用，`calendar.php` 现在调 `showSubscriptionDetails()`）
- `exportCalendar()` / `decodeHtmlEntities()` —— 与上游 `subscription-details.js` 里的重复
  （两处都在时，后加载的 `calendar.js` 覆盖前者）

删掉这些能少 ~70 行 fork 差异，但属于改动线上代码，**要单独确认后再做**。
