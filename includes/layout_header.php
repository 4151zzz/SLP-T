<?php
declare(strict_types=1);

$user = function_exists('current_user') ? current_user() : null;
$appName = defined('APP_NAME') ? APP_NAME : 'ระบบงานบริหารทั่วไป';
$appOrgName = defined('APP_ORG_NAME') ? APP_ORG_NAME : 'โรงเรียนสระหลวงพิทยาคม';
$pageTitleText = (isset($pageTitle) && $pageTitle !== '') ? ($pageTitle . ' — ' . $appName) : $appName;
$currentScript = basename((string)($_SERVER['PHP_SELF'] ?? ''), '.php');
$activeKey = $activeNav ?? $currentScript;
$showSidebar = ($user !== null) && empty($hideSidebar);
$menuItems = ($showSidebar && function_exists('nav_items')) ? nav_items($user) : [];

$roleLabels = defined('ROLE_LABELS') ? ROLE_LABELS : [
    'user'          => 'บุคลากรภายใน',
    'external_user' => 'บุคคลภายนอก',
    'head'          => 'หัวหน้างาน',
    'vice_director' => 'รองผู้อำนวยการ',
    'director'      => 'ผู้อำนวยการ',
    'staff'         => 'เจ้าหน้าที่บริหาร/ช่าง',
    'admin'         => 'ผู้ดูแลระบบ',
];
$roleCode = (string)($user['role'] ?? '');
$roleDisplay = $roleLabels[$roleCode] ?? $roleCode;
$isSidebarScrollable = ($roleCode === 'admin') || (count($menuItems) > 9);
$userDisplayName = (string)($user['full_name'] ?? $user['username'] ?? '');
$userInitial = $userDisplayName !== '' ? mb_substr($userDisplayName, 0, 1, 'UTF-8') : 'U';

$flashes = function_exists('get_flashes') ? get_flashes() : [];
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitleText) ?></title>
    <link rel="icon" type="image/png" href="<?= e(app_url('/assets/img/school-logo.png')) ?>">
    <link rel="stylesheet" href="<?= e(app_url('/assets/vendor/bootstrap/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(app_url('/assets/vendor/bootstrap/bootstrap-icons.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(app_url('/assets/css/app.css')) ?>">
    <script>
        (function () {
            try {
                var saved = localStorage.getItem('slp_theme');
                var theme = (saved === 'dark' || saved === 'light') ? saved : 'light';
                document.documentElement.setAttribute('data-theme', theme);
                document.documentElement.setAttribute('data-bs-theme', theme);
            } catch (e) {}
        })();
    </script>
    <?php if (!empty($extraHead)): ?>
        <?= $extraHead ?>
    <?php endif; ?>
</head>
<body class="d-flex flex-column min-vh-100<?= $showSidebar ? ' has-sidebar' : '' ?><?= !empty($bodyClass) ? ' ' . e($bodyClass) : '' ?>">

<nav class="navbar navbar-expand-lg navbar-dark bg-primary sticky-top app-navbar no-print" data-testid="top-navbar">
    <div class="container-fluid px-2 px-sm-3 flex-nowrap gap-2 overflow-hidden">
        <div class="d-flex align-items-center gap-2 min-w-0 flex-grow-1 overflow-hidden">
            <?php if ($showSidebar): ?>
                <button class="btn btn-outline-light border-0 bg-white bg-opacity-10 d-lg-none touch-target flex-shrink-0"
                        type="button"
                        data-bs-toggle="offcanvas"
                        data-bs-target="#appSidebar"
                        aria-controls="appSidebar"
                        aria-label="เปิดเมนูนำทาง"
                        data-testid="sidebar-toggle">
                    <i class="bi bi-list fs-5"></i>
                </button>
            <?php endif; ?>
            <a class="navbar-brand fw-semibold d-flex align-items-center gap-2 mb-0 min-w-0 flex-shrink-1"
               href="<?= e(app_url('/index.php')) ?>"
               data-testid="brand-link">
                <span class="navbar-brand-icon navbar-brand-logo">
                    <img src="<?= e(app_url('/assets/img/school-logo.png')) ?>" alt="ตรา<?= e($appOrgName) ?>">
                </span>
                <span class="d-flex flex-column min-w-0 overflow-hidden">
                    <span class="text-truncate lh-sm"><?= e($appName) ?></span>
                    <span class="navbar-org-subtitle text-truncate d-none d-sm-block"><?= e($appOrgName) ?></span>
                </span>
            </a>
        </div>

        <div class="d-flex align-items-center gap-2 ms-auto flex-shrink-0">
            <button type="button"
                    id="themeToggleBtn"
                    class="btn btn-light bg-white bg-opacity-10 text-white border-white border-opacity-25 btn-sm touch-target d-inline-flex align-items-center justify-content-center rounded-pill px-2 px-md-3"
                    title="สลับโหมดสว่าง / โหมดมืด"
                    aria-label="สลับโหมดสว่างหรือโหมดมืด"
                    data-testid="theme-toggle">
                <i class="bi bi-moon-stars-fill" id="themeToggleIcon"></i>
                <span class="d-none d-xl-inline ms-1 small" id="themeToggleLabel">โหมดมืด</span>
            </button>
            <?php if ($user !== null): ?>
                <div class="d-none d-sm-flex align-items-center gap-2 text-white small navbar-user-pill" data-testid="user-badge">
                    <span class="navbar-user-avatar">
                        <i class="bi bi-person-fill"></i>
                    </span>
                    <div class="lh-sm text-start pe-1">
                        <div class="fw-semibold text-white"><?= e($userDisplayName) ?></div>
                        <div class="text-white-50" style="font-size: 0.72rem;"><?= e($roleDisplay) ?></div>
                    </div>
                </div>
                <button type="submit"
                        form="appLogoutForm"
                        class="btn btn-light bg-white bg-opacity-10 text-white border-white border-opacity-25 btn-sm touch-target d-inline-flex align-items-center gap-1 px-2 px-md-3 rounded-pill"
                        data-testid="logout-btn">
                    <i class="bi bi-box-arrow-right"></i>
                    <span class="d-none d-md-inline">ออกจากระบบ</span>
                </button>
            <?php else: ?>
                <a href="<?= e(app_url('/login.php')) ?>" class="btn btn-outline-light btn-sm touch-target d-inline-flex align-items-center rounded-pill px-3" data-testid="nav-login">เข้าสู่ระบบ</a>
                <a href="<?= e(app_url('/register.php')) ?>" class="btn btn-light btn-sm text-primary fw-semibold touch-target d-inline-flex align-items-center rounded-pill px-3" data-testid="nav-register">ลงทะเบียนภายนอก</a>
            <?php endif; ?>
        </div>
    </div>
</nav>

<div class="app-shell flex-grow-1<?= $showSidebar ? ' d-lg-flex' : '' ?>">
    <?php if ($showSidebar): ?>
        <aside class="offcanvas-lg offcanvas-start app-sidebar no-print <?= $isSidebarScrollable ? 'is-scrollable' : 'is-locked' ?>"
               tabindex="-1"
               id="appSidebar"
               aria-labelledby="appSidebarLabel"
               data-testid="sidebar-nav">
            <div class="offcanvas-header border-bottom d-lg-none">
                <div id="appSidebarLabel" class="fw-semibold text-primary d-flex align-items-center gap-2">
                    <span class="sidebar-user-avatar"><?= e($userInitial) ?></span>
                    <div class="lh-sm">
                        <div class="text-dark fw-bold"><?= e($userDisplayName) ?></div>
                        <small class="text-muted fw-normal"><?= e($roleDisplay) ?></small>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="offcanvas" data-bs-target="#appSidebar" aria-label="ปิดเมนู"></button>
            </div>
            <div class="offcanvas-body flex-column p-3">
                <div class="sidebar-user-card d-none d-lg-flex align-items-center gap-2 mb-3">
                    <span class="sidebar-user-avatar"><?= e($userInitial) ?></span>
                    <div class="min-w-0">
                        <div class="fw-bold text-dark text-truncate small"><?= e($userDisplayName) ?></div>
                        <div class="text-primary fw-semibold" style="font-size: 0.74rem;"><?= e($roleDisplay) ?></div>
                    </div>
                </div>

                <div class="text-uppercase text-muted fw-semibold px-2 mb-2 d-none d-lg-block" style="font-size: 0.72rem; letter-spacing: 0.04em;">เมนูหลัก</div>
                <nav class="nav nav-pills flex-column gap-1 w-100" aria-label="เมนูหลัก">
                    <?php foreach ($menuItems as $item):
                        $href = (string)($item['href'] ?? '#');
                        $itemKey = (string)($item['key'] ?? basename($href, '.php'));
                        $isActive = ($activeKey === $itemKey) || ($currentScript === basename($href, '.php'));
                        $testId = (string)($item['testid'] ?? ('nav-' . str_replace('_', '-', $itemKey)));
                    ?>
                        <a href="<?= e($href) ?>"
                           class="nav-link app-nav-link<?= $isActive ? ' active' : '' ?>"
                           <?= $isActive ? 'aria-current="page"' : '' ?>
                           data-testid="<?= e($testId) ?>">
                            <?php if (!empty($item['icon'])): ?>
                                <i class="bi <?= e((string)$item['icon']) ?>"></i>
                            <?php endif; ?>
                            <span class="flex-grow-1"><?= e((string)($item['label'] ?? $itemKey)) ?></span>
                            <?php if (!empty($item['badge'])): ?>
                                <span class="badge rounded-pill <?= $isActive ? 'text-bg-light text-primary' : 'text-bg-danger' ?>">
                                    <?= e((string)$item['badge']) ?>
                                </span>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </nav>
            </div>
        </aside>
    <?php endif; ?>

    <main class="flex-grow-1 min-w-0 p-3 p-md-4 app-main" data-testid="main-content">
        <?php if (!empty($flashes)): ?>
            <div class="app-flash-region mb-3 no-print" data-testid="flash-container">
                <?php foreach ($flashes as $fl):
                    $type = preg_replace('/[^a-z]/', '', (string)($fl['type'] ?? 'info')) ?: 'info';
                    $msg  = (string)($fl['message'] ?? '');
                    if ($msg === '') {
                        continue;
                    }
                ?>
                    <div class="alert alert-<?= e($type) ?> alert-dismissible fade show shadow-sm border-0 rounded-3"
                         role="alert"
                         data-testid="flash-message"
                         data-flash-type="<?= e($type) ?>">
                        <?= e($msg) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="ปิด"></button>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
