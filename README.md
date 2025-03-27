# 基本队列处理命令
php artisan queue:work

# 优化的导入队列处理命令（推荐）
php artisan queue:work --queue=imports --timeout=7200 --sleep=3 --tries=3

# 重启队列工作进程（代码修改后必须执行）
php artisan queue:restart

# 清除所有失败的任务
php artisan queue:flush

# 列出失败的任务
php artisan queue:failed

# 重试所有失败的任务
php artisan queue:retry all

# 重试特定ID的失败任务
php artisan queue:retry {id}

# 删除特定ID的导入任务
php artisan import:delete {id}

# 清空所有数据（慎用！）
php artisan db:wipe

# 清除数据（会清空交易数据、ROI计算结果等）
php artisan import:clear

# 清除应用缓存
php artisan cache:clear

# 清除视图缓存
php artisan view:clear

# 清除路由缓存
php artisan route:clear

# 重置配置缓存
php artisan config:clear

# 修复交易表中的渠道ID映射
php artisan fix:channel-ids

# 仅预览要修复的内容，不实际执行
php artisan fix:channel-ids --dry-run