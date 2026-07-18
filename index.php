<?php
require __DIR__ . '/app.php';
blog_send_security_headers();
blog_ensure_storage();
$config = blog_config();
$path = blog_current_path();
$slug = '';

if (preg_match('#^/post/([a-z0-9-]+)$#', $path, $match)) {
    $slug = $match[1];
} elseif (isset($_GET['post'])) {
    $slug = blog_slugify($_GET['post']);
}

$tag = isset($_GET['tag']) ? blog_tag_slug($_GET['tag']) : '';
$show_tags = isset($_GET['tags']);
$post = null;

if ($slug) {
    $post = blog_load_post($slug);
    if (!$post || $post['status'] !== 'published') {
        http_response_code(404);
        $page_title = 'Not found';
    } else {
        $page_title = $post['title'];
    }
} elseif ($show_tags) {
    $page_title = 'Tags';
} elseif ($tag !== '') {
    $page_title = 'Posts tagged "' . $tag . '"';
} else {
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
        <a href="/?tags=1">Tags</a>
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
      <?php echo blog_tag_chips($post); ?>
      <?php echo blog_markdown($post['body']); ?>
    </article>
<?php elseif ($show_tags): ?>
<?php $tags = blog_all_tags(false); ?>
    <section class="intro">
      <h1>Tags</h1>
    </section>
    <section class="tag-index">
<?php foreach ($tags as $name => $count): ?>
      <a class="tag" href="/?tag=<?php echo rawurlencode($name); ?>"><?php echo h($name); ?> <span><?php echo (int) $count; ?></span></a>
<?php endforeach; ?>
<?php if (!$tags): ?>
      <p>No tags yet.</p>
<?php endif; ?>
    </section>
<?php else: ?>
<?php $posts = $tag !== '' ? blog_posts_by_tag($tag, false) : blog_all_posts(false); ?>
    <section class="intro">
<?php if ($tag !== ''): ?>
      <h1>Posts tagged &ldquo;<?php echo h($tag); ?>&rdquo;</h1>
      <p class="tag-clear"><a href="/">Show all posts</a></p>
<?php else: ?>
      <h1><?php echo h(isset($config['tagline']) ? $config['tagline'] : 'Notes'); ?></h1>
<?php endif; ?>
    </section>
    <section class="archive">
<?php foreach ($posts as $item): ?>
      <article class="archive-item">
        <time><?php echo h(date('M j, Y', strtotime($item['date']))); ?></time>
        <h2><a href="/?post=<?php echo h($item['slug']); ?>"><?php echo h($item['title']); ?></a></h2>
        <p><?php echo h(blog_excerpt($item['body'])); ?></p>
        <?php echo blog_tag_chips($item); ?>
      </article>
<?php endforeach; ?>
<?php if (!$posts): ?>
<?php if ($tag !== ''): ?>
      <p>No posts tagged &ldquo;<?php echo h($tag); ?>&rdquo;.</p>
<?php else: ?>
      <p>No posts yet.</p>
<?php endif; ?>
<?php endif; ?>
    </section>
<?php endif; ?>
  </main>
</body>
</html>
