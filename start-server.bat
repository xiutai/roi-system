@echo off
echo 正在启动Laravel开发服务器...
echo 服务器将在所有网络接口上监听8000端口
echo 您可以通过局域网IP:8000来访问应用
echo.
echo 按下Ctrl+C组合键停止服务器
echo.

php artisan serve --host=0.0.0.0 --port=8000 