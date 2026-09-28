# 账户双重验证恢复

0.24.1 增加后台恢复入口。恢复只移除验证器及恢复码，不重置密码、不授予角色或会员权益；同时吊销目标账户所有登录会话及未使用的密码找回链接。密码重置仍保留双重验证保护。

## 还能登录管理员后台

打开“用户”→目标用户→“双重验证恢复”。先通过已有联系方式核实持有人，再重新输入目标用户名、记录身份核实与恢复原因、输入当前管理员密码，勾选确认。如果管理员自己已开启双重验证，还必须输入自己的验证码或恢复码。恢复其他管理员时，操作管理员必须已绑定验证器。

提交时服务器重新验证管理员角色、当前密码、会话版本、目标用户名与版本。页面打开后目标账户发生变化会拒绝旧表单；刷新后重新核实。禁止从该入口重置自己的验证器；自己的恢复码可在登录页或安全中心使用。

成功后目标用户收到站内安全通知，操作记录在后台日志 `account.factor_admin_recovery`。请让用户重新登录，并在安全中心绑定新验证器、保存新的恢复码。旧验证码、恢复码和旧会话均不能继续使用。

## 所有管理员都失去验证器与恢复码，虚拟主机没有 SSH

网站访客不能使用此路径。只有拥有本网站数据库管理权限的站点持有人才能执行以下操作；在主机面板打开本网站的 PHPMyAdmin，先导出数据库备份。

先查询并核对具体管理员，不要猜 ID：

```sql
SELECT id, username, email, role, status, session_version
FROM cy_users WHERE role = 'admin';
```

以下为 **MySQL/InnoDB** 恢复事务。将 `123` 和 `confirmed_admin_name` 同时替换为上一步核实的账户。用户名只允许程序注册规则中的小写字母、数字、下划线，不要填邮箱。整个脚本在同一个 SQL 窗口一次执行。

```sql
SET @cy_recovery_id = 123;
SET @cy_recovery_username = 'confirmed_admin_name';
SET @cy_recovery_target = NULL;
START TRANSACTION;
SELECT id INTO @cy_recovery_target
FROM cy_users
WHERE id = @cy_recovery_id AND username = @cy_recovery_username
  AND role = 'admin' AND status = 'active'
FOR UPDATE;
DELETE FROM cy_second_factors WHERE user_id = @cy_recovery_target;
SET @cy_recovery_removed = ROW_COUNT();
UPDATE cy_users SET session_version = session_version + 1
WHERE id = @cy_recovery_target AND @cy_recovery_removed = 1;
UPDATE cy_resets SET used_at = UNIX_TIMESTAMP()
WHERE user_id = @cy_recovery_target AND used_at = 0 AND @cy_recovery_removed = 1;
INSERT INTO cy_audit (actor_id, action, target, ip_hash, created_at)
SELECT 0, 'account.factor_database_recovery',
       CAST(@cy_recovery_target AS CHAR), '', UNIX_TIMESTAMP()
WHERE @cy_recovery_removed = 1;
COMMIT;
SELECT @cy_recovery_removed AS authenticator_removed;
```

结果为 `1` 才表示本次确实移除了验证器；为 `0` 表示没有匹配的有效管理员验证器，不要扩大 SQL 条件或删除其他账户记录。若执行报错，先 `ROLLBACK`，核实数据库、表前缀和引擎后再处理。不要将 SQL、数据库密码或导出的数据上传到网站公开目录。

用原管理员密码重新登录，立即绑定新验证器。若密码也遗失，需先通过已有密码恢复流程或主机商协助核实；此事务不会修改密码。SQLite 网站请采用下面的私有 CLI 方式，不能把这份 MySQL SQL 直接套用到 SQLite。

## 可以使用私有 CLI

完整包的工具仍可使用，不要上传到公开网站根目录：

```text
php tools/recover-authenticator.php /absolute/site/root USER_ID CONFIRM-REVOKE-ALL-SESSIONS
```

操作记录为 `account.factor_offline_recovery`。该工具没有 HTTP 入口，不依赖 SMTP、Fileinfo 或 WordPress。恢复后同样需要重新登录和绑定验证器。
