<?php /** Public pages (home, login, register). @var string $title */ ?>
<!doctype html>
<html lang="en-GH">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= e($title ?? 'PrepDeck') ?></title>
   <?php $metaDesc = $metaDesc ?? 'Past questions, model answers, flashcards, study guides and practice exams for every department and semester.'; $ogBase = rtrim((string)env('APP_URL', ''), '/') ?: ((!empty($_SERVER['HTTPS']) ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost')); $canon = $ogBase . strtok($_SERVER['REQUEST_URI'] ?? '/', '?'); ?>
   <meta name="description" content="<?= e($metaDesc) ?>">
   <meta name="theme-color" content="#0D2B2E">
   <link rel="canonical" href="<?= e($canon) ?>">
   <meta property="og:type" content="website"><meta property="og:site_name" content="PrepDeck"><meta property="og:title" content="<?= e($title ?? 'PrepDeck') ?>"><meta property="og:description" content="<?= e($metaDesc) ?>"><meta property="og:url" content="<?= e($canon) ?>"><meta property="og:image" content="<?= e($ogBase . url('og-image.png')) ?>">   <meta property="og:image:alt" content="PrepDeck - past questions, flashcards and study guides"><meta property="og:locale" content="en_GH"><meta property="og:image:width" content="1200"><meta property="og:image:height" content="630">
   <meta name="twitter:card" content="summary_large_image"><meta name="twitter:title" content="<?= e($title ?? 'PrepDeck') ?>"><meta name="twitter:description" content="<?= e($metaDesc) ?>"><meta name="twitter:image" content="<?= e($ogBase . url('og-image.png')) ?>"><meta name="twitter:image:alt" content="PrepDeck - past questions, flashcards and study guides">
  <script type="application/ld+json"><?= json_encode(['@context' => 'https://schema.org', '@type' => 'EducationalOrganization', 'name' => 'PrepDeck', 'url' => $canon, 'description' => $metaDesc], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?></script>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,500;12..96,700;12..96,800&family=Figtree:wght@400;500;600&family=IBM+Plex+Mono:wght@500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
  <link rel="icon" type="image/svg+xml" href="<?= e(url('favicon.svg')) ?>">
  <link rel="stylesheet" href="<?= e(asset('css/main.css')) ?>">
  <link rel="stylesheet" href="<?= e(asset('css/pw.css')) ?>">
  <meta name="csrf" content="<?= Csrf::token() ?>">
  <meta name="base" content="<?= e(url()) ?>">
</head>
<body>
<div id="pageLoader" class="spinner-overlay" role="status" aria-label="Loading page"><span class="spinner"></span></div>
