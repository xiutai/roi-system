@echo off
echo 启动队列工作进程监控脚本
echo 此脚本将在队列工作进程退出时自动重启它
echo.

:loop
echo 正在启动队列工作进程...
php artisan queue:work --queue=imports --timeout=7200 --sleep=3 --tries=3 --daemon
echo 队列工作进程已退出，正在重启...
timeout /t 3 /nobreak
goto loop 