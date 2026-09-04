<?php
$title = $title ?? app_config('name');
$pageSlug = preg_replace('/[^a-z0-9_-]+/i', '-', (string)($_GET['page'] ?? 'home')) ?: 'home';
$bodyClass = ($layout === 'app' ? 'app-body' : 'public-body') . ' page-' . strtolower($pageSlug);
?>
<!doctype html>
<html lang="en" data-theme="light">
<head>
 <meta charset="utf-8">
 <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
 <meta name="color-scheme" content="light dark">
 <meta name="theme-color" content="#d96a77">
 <script>(function(){try{var t=localStorage.getItem('aesthetic-intel-theme');if(t==='dark'||t==='light'){document.documentElement.dataset.theme=t;document.documentElement.style.colorScheme=t;}}catch(e){}})();</script>
 <title><?=e($title)?> · <?=e(app_config('name'))?></title>
 <link rel="icon" href="<?=asset('img/favicon.svg')?>" type="image/svg+xml">
 <link rel="stylesheet" href="<?=asset('css/app.css')?>?v=<?=e(app_config('version'))?>">
 <link rel="stylesheet" href="<?=asset('css/ui-v2.css')?>?v=<?=e(app_config('version'))?>">
 <link rel="stylesheet" href="<?=asset('css/print.css')?>?v=<?=e(app_config('version'))?>" media="print">
</head>
<body class="<?=e($bodyClass)?>">
<?php if($layout==='app'):?><div class="app-shell"><?php endif;?>
