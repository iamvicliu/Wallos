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
| 上游版本 | **v5.10.0**（`c98f303`，2026-10-17） |
| 分支 | `merge/upstream-v5.10.0` |
| 上一个基线 | v5.8.3（分支 `merge/upstream-v5.8.3`，镜像 `5.8.3-6`） |
| 相对上游的差异 | 52 个文件，约 +600 / −12 行 |

### 这个分支是怎么来的

不是把旧 fork 合并到新上游，而是**从上游 tag 重新起分支，再叠提交**：

```
v5.10.0
 ├─ feat: show upcoming payments by date range           ← 上游 PR #1269
 ├─ feat: toggle notifications from the subscription list ← 上游 PR #1272
 ├─ feat: let users choose the defaults for new subscriptions ← 上游 PR #1273
 ├─ fix: restore CRLF line endings                        ← 修复我解决冲突时把两个文件转成 LF 的问题
 ├─ chore: fork-only changes                              ← 只属于本 fork 的东西（见第三节）
 ├─ docs: 上线记录
 └─ fix(pwa): don't pair a newer page with the old worker's cached assets ← 上游 PR #1279（同时把版本升到 v5.10.0.2）
```

旧 fork 的其他功能都已经被上游收了，直接用上游的：

| 旧 fork 功能 | 现在 |
|---|---|
| 日历格子显示订阅名 + 价格 + 续费图标 | 上游 #1270 已合并（v5.10.0） |
| 订阅批量操作（`bulk_update.php`） | 上游 #1271 的 `bulkedit.php` 已合并；单条切换通知仍是 #1272 |
| 列表 / 网格视图 | 上游早已自带 |
| 订阅详情价格显示 `&yen;` 的修复 | 上游 #1265 已合并 |
| 统计页「未来 12 个月预测」图 | 2026-10-17 已删，上游自带「预计支出」 |
| `scripts/calendar.js` 里的旧弹窗死代码 | 随旧日历实现一起消失，不用再清理 |

**以后上游合并了 #1269 / #1272 / #1273 中的哪个，就把对应提交从这里去掉**（下次重新起分支时不再 cherry-pick）。

---

## 一、跟进上游新版本的流程

```bash
cd <本仓库根目录>
git fetch origin --tags
git switch -c merge/upstream-<新版本> <新版本 tag>

# 依次 cherry-pick 还没被上游合并的 PR 提交，以及 fork-only 提交
git cherry-pick <PR 提交> ...
```

解决冲突后**必须做这几步核对**：

```bash
# 1. 无冲突残留
git grep -n -E '^(<<<<<<<|>>>>>>>|=======$)' -- . ':!vendor'

# 2. 差异规模合理（几十个文件、几百行）。出现几千行的文件，基本是行尾被改了
git diff <新版本 tag> --stat
git diff <新版本 tag> --stat --ignore-cr-at-eol     # 两者差很多 = 行尾问题

# 3. fork 删掉的上游行必须逐条能解释（见第四节）
git diff <新版本 tag> | awk '/^\+\+\+ b\//{f=substr($2,3)} /^-[^-]/{print f"\t"$0}'

# 4. JS 语法 + i18n 无重复键
for f in $(git diff --name-only <新版本 tag> -- '*.js'); do node --check "$f" || echo "FAIL $f"; done
```

本机没有 `php`，**PHP 语法检查和测试在服务器上用容器跑**（见第五节）。

---

## 二、已知坑

### 1. 行尾：很多文件是 CRLF

`scripts/subscriptions.js`、`endpoints/subscriptions/get.php`、`settings.php`、`includes/version.php`、
`includes/i18n/*.php` 等在上游是 **CRLF**。手工解决冲突或用脚本（Python 文本模式）改写时很容易
整文件变成 LF，git 会显示上千行差异。改完对比一下 `--stat` 和 `--stat --ignore-cr-at-eol`。

### 2. ⚠️ 迁移文件编号撞车

迁移按**文件名字符串**去重（`includes/run_migrations.php` 拿 `migrations/000060.php` 这种字符串
和 `migrations` 表比对）。fork 自己的迁移一旦和上游同号，其中一份会被**永久跳过**。

历史：

| 内容 | 曾用名 | 现在 |
|---|---|---|
| `settings.default_auto_renew` / `default_notifications` | 000047 → 000060 | **900002** |
| `settings.upcoming_payments_days` | 000061 | **900001** |

v5.10.0 的上游自己用了 000060（`subscription_payers` 表）和 000061（`subscription_categories` 表）。
而**线上库的 `migrations` 表里已经记了 fork 当年的 000060 / 000061**，所以上游这两个迁移在线上会被跳过。

处理办法（不改线上库的迁移记录）：

- fork 迁移改用 **9000xx** 号段，远离上游编号；两个都自带列存在性检查，线上重跑无副作用。
- 加 **`migrations/900000.php`**：`require_once` 上游的 000060 / 000061。两者都是幂等的
  （`CREATE TABLE IF NOT EXISTS`、`INSERT OR IGNORE`）。
  - 线上库：000060/000061 被跳过 → 900000 把两张表建出来并回填。
  - 新库：000060/000061 正常执行 → 900000 里的 `require_once` 指向同一文件，直接跳过。

上游以后**再改 000060/000061 的内容**，或新加的迁移依赖这两张表，900000 的顺序（排在所有 0000xx 之后）
可能不够用，到时要重新看。**上游 PR 合并后**，对应的 9000xx 文件不能删（线上已记录），保留空跑即可，
除非确认上游版本的迁移会以同样的列加到同一张表。

### 3. 新订阅默认值和上游的 resetForm

`scripts/subscriptions.js` 的 `resetForm()` 里，上游先写死 `autoRenew.checked = true;`，
后面 `form.reset()` 之后 PR #1273 的代码再按 `window.defaultAutoRenew` / `defaultNotifications` 覆盖。
上游那行保留不动，顺序别弄反。

---

## 三、本 fork 的改动

### 1. 上游 PR（等上游合并）

| PR | 内容 | 迁移 |
|---|---|---|
| [#1269](https://github.com/ellite/Wallos/pull/1269) | 首页「即将到期的付款」可按日期范围显示（7/14/30/60/90 天），设置 + API + 测试 | 900001 |
| [#1272](https://github.com/ellite/Wallos/pull/1272) | 订阅列表每条右侧一个铃铛，直接开关通知（`endpoints/subscription/togglenotify.php`，`get.php` 返回 `notify`） | — |
| [#1273](https://github.com/ellite/Wallos/pull/1273) | 设置里选新订阅的默认自动续费 / 默认通知 | 900002 |
| [#1279](https://github.com/ellite/Wallos/pull/1279) | Service Worker：页面请求的 `?版本号` 和 worker 自己的版本不一致时走网络，避免升级后第一次加载「新页面 + 旧 CSS/JS」 | — |

PR 分支里的迁移号已于 2026-10-18 改成上游下一个空号：#1269 = **000062**、#1273 = **000063**（fork 里对应 900001 / 900002，测试文件 require 的也是 9000xx）。
上游合并后，fork 下次起分支时**不要**再带 000062/000063：线上已经记了 900001/900002，两边都是幂等加列，重复跑无害，但没必要。

### 2. fork-only

| 文件 | 改动 |
|---|---|
| `migrations/900000.php` | 上面第二节第 2 点的垫片 |
| `includes/upcoming_payments.php` | 加 `UPCOMING_PAYMENTS_DEFAULT_DAYS = 30` |
| `index.php` | 首页**只按日期**：`upcoming_payments_days` 为 0（没设过）时用 30 天 |
| `settings.php` | 删掉上游的条数下拉框（3/5/10/20）；日期范围下拉框去掉「关闭」项 |
| `scripts/settings.js` | 删掉日期范围切换时禁用条数下拉框的两行 |
| `includes/version.php` | `v5.10.0.2`（只为让 Service Worker 换缓存） |
| `.github/workflows/build-release.yaml` | `release` job 加 `if: github.repository == 'ellite/Wallos'`，fork 没有发布凭据 |
| `Dockerfile.fork` | 在官方镜像上叠应用层的部署镜像（见第五节） |
| `FORK-CHANGES.md` | 本文件 |

上游的 `upcoming_payments_limit` 字段、端点、API 和 `setUpcomingPaymentsLimit()` 都保留，只是设置页不显示。

**版本号**：`v5.10.0.2` = 镜像 `5.10.0-2`。下次跟上游时直接取上游版本号再加 `.1`。

---

## 四、fork 删掉的上游行（正常范围）

`git diff v5.10.0` 里以 `-` 开头的上游行只应有这些：

| 文件 | 行数 | 原因 |
|---|---|---|
| `settings.php` | 6 | 条数下拉框（fork-only，只按日期） |
| `includes/upcoming_payments.php` | 2 | `get_upcoming_payments()` 加第 4 个参数（#1269） |
| `index.php` | 2 | 传入日期范围（#1269 + fork-only） |
| `styles/styles.css` | 1 | 网格布局给铃铛腾位置（#1272） |
| `includes/version.php` | 1 | 版本号 |

多出来的说明 cherry-pick 时吞掉了上游代码，要查。

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
   另一个现象（2026-10-18 实测）：升级后**第一次**打开页面，新 worker 还在预缓存（约 150 个文件，要一阵子），
   这期间是**旧 worker** 在服务，它用 `ignoreSearch` 缓存优先，把旧版 CSS 配给新页面
   （表现：两个批量按钮、卡片没有勾选框）。刷新即恢复。fork 从 v5.10.0.2 起带修复（上游 PR #1279），
   请求版本号与 worker 版本不一致就走网络；因为修复在 worker 里，**下一次升级起**才生效。


---

## 六、上线记录

- 2026-10-18（北京时间 14:33）：生产换成 `wallos-fork:5.10.0-1`，迁移 900000–900002 执行成功，两张关联表各回填 83 行；
  订阅 83、货币 10 不变；备份 `wallos-backup/20261018-143328`；回滚容器 `wallos-old` = `5.8.3-6`。
- 2026-10-18（北京时间 16:35）：生产换成 `wallos-fork:5.10.0-2`（只改 `service-worker.php` + 版本号，无迁移）；
  订阅 83、货币 10 不变，容器 healthy，登录页 200；浏览器实测版本不一致走网络、一致走缓存，旧静态缓存被清掉。
  只改 PHP，没清 Cloudflare。备份 `wallos-backup/20261018-083524`（目录名为服务器 UTC 时间）；
  上一版容器改名保留为 `wallos-5.10.0-1`（停止），`wallos-old` = `5.8.3-6` 也仍保留。
