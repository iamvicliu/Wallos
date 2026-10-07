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
cd /Users/vicliu/Github/Wallos
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

## 五、部署注意

1. **镜像要从本 fork 构建**，不要用 `bellamy/wallos:latest` —— 否则 Watchtower
   会把容器拉回官方镜像，所有自用功能被冲掉（2026-10 已发生过一次）。
2. **把 wallos 容器排除出 Watchtower**，改为手动 `git fetch origin && git merge` 升级。
3. 线上数据在 `/home/ubuntu/wallos/`（`db/wallos.db` + `logos/`），部署时**只换代码，别动数据目录**。
4. 汇率刷新：容器内 `startup.sh` 会 `crontab -d -u root` 删掉自己的定时任务，
   **只在容器启动时**跑一次 `endpoints/cronjobs/updateexchange.php`。
   要每日自动更新汇率，得在**宿主机**加 cron。

---

## 六、可清理项（降低以后合并的摩擦）

`scripts/calendar.js` 里还留着本 fork 早期的订阅详情弹窗实现，
上游 v5.8.3 已用统一的 `scripts/subscription-details.js` 取代：

- `closeSubscriptionModal()` / `renderSubscriptionModal()` / `openSubscriptionModal()`
  —— 已成死代码（只被彼此引用，`calendar.php` 现在调 `showSubscriptionDetails()`）
- `exportCalendar()` / `decodeHtmlEntities()` —— 与上游 `subscription-details.js` 里的重复
  （两处都在时，后加载的 `calendar.js` 覆盖前者）

删掉这些能少 ~70 行 fork 差异，但属于改动线上代码，**要单独确认后再做**。
