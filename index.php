<?php
require __DIR__ . '/app.php';
blog_ensure_storage();
$config = blog_config();
$path = blog_current_path();
$slug = '';

if (preg_match('#^/post/([a-z0-9-]+)$#', $path, $match)) {
    $slug = $match[1];
} elseif (isset($_GET['post'])) {
    $slug = blog_slugify($_GET['post']);
}

if ($slug) {
    $post = blog_load_post($slug);
    if (!$post || $post['status'] !== 'published') {
        http_response_code(404);
        $page_title = 'Not found';
    } else {
        $page_title = $post['title'];
    }
} else {
    $post = null;
    $page_title = $config['site_title'];
}
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?php echo h($page_title); ?></title>
  <meta name="description" content="<?php echo h(isset($config['tagline']) ? $config['tagline'] : ''); ?>">
  <link rel="stylesheet" href="/assets/style.css">
  <link rel="alternate" type="application/rss+xml" title="<?php echo h($config['site_title']); ?>" href="/feed.php">
</head>
<body>
  <main class="wrap">
    <header class="site-header">
      <a class="brand" href="/"><?php echo h($config['site_title']); ?></a>
      <nav>
        <a href="/">Archive</a>
        <a href="/feed.php">RSS</a>
      </nav>
    </header>

<?php if ($slug && !$post): ?>
    <article class="post">
      <h1>Not found</h1>
      <p>This post is not available.</p>
    </article>
<?php elseif ($post): ?>
    <article class="post">
      <p class="date"><?php echo h(date('F j, Y', strtotime($post['date']))); ?></p>
      <h1><?php echo h($post['title']); ?></h1>
      <?php echo blog_markdown($post['body']); ?>
    </article>
<?php else: ?>
    <section class="intro">
      <h1><?php echo h(isset($config['tagline']) ? $config['tagline'] : 'Notes'); ?></h1>
    </section>
    <section class="archive">
<?php foreach (blog_all_posts(false) as $item): ?>
      <article class="archive-item">
        <time><?php echo h(date('M j, Y', strtotime($item['date']))); ?></time>
        <h2><a href="/?post=<?php echo h($item['slug']); ?>"><?php echo h($item['title']); ?></a></h2>
        <p><?php echo h(blog_excerpt($item['body'])); ?></p>
      </article>
<?php endforeach; ?>
<?php if (!blog_all_posts(false)): ?>
      <p>No posts yet.</p>
<?php endif; ?>
    </section>
<?php endif; ?>
  </main>
</body>
</html>
