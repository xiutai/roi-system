<!DOCTYPE html>
<html lang="zh">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ROI统计后台 - @yield('title', '首页')</title>
    <!-- 引入Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- 引入Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <!-- 引入DateRangePicker -->
    <link rel="stylesheet" type="text/css" href="https://cdn.jsdelivr.net/npm/daterangepicker/daterangepicker.css" />
    <!-- 引入Font Awesome图标 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            padding-top: 60px;
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        body.auth-page {
            padding-top: 0;
        }
        
        /* 导航栏样式 */
        .navbar-custom {
            background: linear-gradient(135deg, #2c3e50 0%, #1565C0 100%);
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
        }
        
        .navbar-brand {
            font-weight: 600;
            font-size: 1.5rem;
            color: white;
            padding: 10px 0;
        }
        
        .navbar-custom .nav-link {
            color: rgba(255, 255, 255, 0.85);
            font-weight: 500;
            padding: 12px 16px;
            transition: all 0.3s;
            position: relative;
            border-radius: 4px;
        }
        
        .navbar-custom .nav-link:hover {
            color: white;
            background-color: rgba(255, 255, 255, 0.1);
        }
        
        .navbar-custom .nav-link.active {
            color: white;
            background-color: rgba(255, 255, 255, 0.15);
        }
        
        .navbar-custom .nav-link.active::after {
            content: "";
            position: absolute;
            bottom: 0;
            left: 50%;
            transform: translateX(-50%);
            width: 30px;
            height: 3px;
            background-color: #4fc3f7;
            border-radius: 2px;
        }
        
        /* 版块样式 */
        .card {
            border-radius: 8px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.08);
            border: none;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        
        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }
        
        .card-title {
            font-weight: 600;
            color: #2c3e50;
            border-bottom: 2px solid #f0f0f0;
            padding-bottom: 10px;
            margin-bottom: 15px;
        }
        
        /* 表格样式 */
        .table {
            background-color: white;
            border-radius: 8px;
            overflow: hidden;
        }
        
        .table thead th {
            background-color: #f8f9fa;
            color: #2c3e50;
            font-weight: 600;
            border-top: none;
            padding: 12px;
        }
        
        .table-striped tbody tr:nth-of-type(odd) {
            background-color: rgba(0, 123, 255, 0.03);
        }
        
        .table-hover tbody tr:hover {
            background-color: rgba(0, 123, 255, 0.05);
        }
        
        /* 按钮样式 */
        .btn {
            border-radius: 6px;
            font-weight: 500;
            padding: 8px 16px;
            transition: all 0.3s;
        }
        
        .btn-primary {
            background: linear-gradient(45deg, #2962FF, #3D5AFE);
            border: none;
            box-shadow: 0 2px 5px rgba(63, 81, 181, 0.3);
        }
        
        .btn-primary:hover {
            background: linear-gradient(45deg, #1E40FF, #3D5AFE);
            box-shadow: 0 4px 8px rgba(63, 81, 181, 0.5);
        }
        
        /* 其他辅助样式 */
        .sidebar {
            position: fixed;
            top: 60px;
            bottom: 0;
            left: 0;
            z-index: 100;
            padding: 48px 0 0;
            box-shadow: inset -1px 0 0 rgba(0, 0, 0, .1);
        }
        .sidebar-sticky {
            position: relative;
            top: 0;
            height: calc(100vh - 48px);
            padding-top: .5rem;
            overflow-x: hidden;
            overflow-y: auto;
        }
    </style>
    @yield('styles')
</head>
<body class="{{ request()->routeIs('login') || request()->routeIs('password.request') || request()->routeIs('password.reset') ? 'auth-page' : '' }}">
    <!-- 顶部导航 -->
    @if(!(request()->routeIs('login') || request()->routeIs('password.request') || request()->routeIs('password.reset')))
    <nav class="navbar navbar-expand-md navbar-dark navbar-custom fixed-top">
        <div class="container-fluid">
            <a class="navbar-brand" href="{{ route('dashboard') }}">
                <i class="fas fa-chart-line me-2"></i>ROI统计平台
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarCollapse">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarCollapse">
                <ul class="navbar-nav me-auto mb-2 mb-md-0">
                    <li class="nav-item">
                        <a class="nav-link {{ request()->routeIs('dashboard') ? 'active' : '' }}" href="{{ route('dashboard') }}">
                            <i class="fas fa-tachometer-alt me-1"></i>仪表盘
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link {{ request()->routeIs('import.*') ? 'active' : '' }}" href="{{ route('import.index') }}">
                            <i class="fas fa-file-import me-1"></i>数据导入
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link {{ request()->routeIs('exchange_rates.*') ? 'active' : '' }}" href="{{ route('exchange_rates.index') }}">
                            <i class="fas fa-exchange-alt me-1"></i>汇率管理
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link {{ request()->routeIs('expenses.*') ? 'active' : '' }}" href="{{ route('expenses.index') }}">
                            <i class="fas fa-dollar-sign me-1"></i>消耗管理
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link {{ request()->routeIs('channels.*') ? 'active' : '' }}" href="{{ route('channels.index') }}">
                            <i class="fas fa-sitemap me-1"></i>渠道管理
                        </a>
                    </li>
                    @if(Auth::check() && Auth::user()->isAdmin())
                    <li class="nav-item">
                        <a class="nav-link {{ request()->routeIs('users.*') ? 'active' : '' }}" href="{{ route('users.index') }}">
                            <i class="fas fa-users me-1"></i>用户管理
                        </a>
                    </li>
                    @endif
                </ul>
                
                <!-- 右侧用户菜单 -->
                <ul class="navbar-nav ms-auto">
                    @guest
                        <li class="nav-item">
                            <a class="nav-link" href="{{ route('login') }}">
                                <i class="fas fa-sign-in-alt me-1"></i>登录
                            </a>
                        </li>
                    @else
                        <li class="nav-item dropdown">
                            <a id="navbarDropdown" class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false" v-pre>
                                <i class="fas fa-user-circle me-1"></i>{{ Auth::user()->name }}
                            </a>

                            <div class="dropdown-menu dropdown-menu-end" aria-labelledby="navbarDropdown">
                                <a class="dropdown-item" href="{{ route('logout') }}"
                                   onclick="event.preventDefault();
                                                 document.getElementById('logout-form').submit();">
                                    <i class="fas fa-sign-out-alt me-1"></i>退出登录
                                </a>

                                <form id="logout-form" action="{{ route('logout') }}" method="POST" class="d-none">
                                    @csrf
                                </form>
                            </div>
                        </li>
                    @endguest
                </ul>
            </div>
        </div>
    </nav>
    @endif

    <div class="container-fluid {{ request()->routeIs('login') || request()->routeIs('password.request') || request()->routeIs('password.reset') ? 'pt-0' : '' }}">
        <div class="row">
            <!-- 主要内容区域 -->
            <main class="col-md-12 ms-sm-auto col-lg-12 px-md-4 {{ request()->routeIs('login') || request()->routeIs('password.request') || request()->routeIs('password.reset') ? 'mt-0' : 'mt-4' }}">
                <!-- 提示消息 -->
                @if(session('success'))
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle me-2"></i>{{ session('success') }}
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                @endif

                @if(session('error'))
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-circle me-2"></i>{{ session('error') }}
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                @endif

                <!-- 页面标题 -->
                @if(!request()->routeIs('login') && !request()->routeIs('password.request') && !request()->routeIs('password.reset'))
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-4">
                    <h1 class="h2 text-primary">
                        <i class="fas fa-{{ request()->routeIs('dashboard') ? 'tachometer-alt' : (request()->routeIs('roi.*') ? 'chart-pie' : 'file-alt') }} me-2"></i>
                        @yield('page-title', '仪表盘')
                    </h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        @yield('page-actions')
                    </div>
                </div>
                @endif

                <!-- 主要内容 -->
                @yield('content')
            </main>
        </div>
    </div>

    <!-- 引入Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <!-- 引入jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <!-- 引入Moment.js和DateRangePicker -->
    <script src="https://cdn.jsdelivr.net/momentjs/latest/moment.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/daterangepicker/daterangepicker.min.js"></script>
    @yield('scripts')
    @stack('scripts')
</body>
</html> 