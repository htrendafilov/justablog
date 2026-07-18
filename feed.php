<?php
require __DIR__ . '/app.php';
blog_send_security_headers(false);
$config = blog_config();
$posts = blog_all_posts(false);
header('Content-Type: application/rss+xml; charset=utf-8');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<rss version="2.0">
<channel>
  <title><?php echo h($config['site_title']); ?></title>
  <link><?php echo h(blog_base_url()); ?>/</link>
  <description><?php echo h(isset($config['tagline']) ? $config['tagline'] : ''); ?></description>
<?php foreach ($posts as $post): ?>
  <item>
    <title><?php echo h($post['title']); ?></title>
    <link><?php echo h(blog_url('/?post=' . $post['slug'])); ?></link>
    <guid><?php echo h(blog_url('/?post=' . $post['slug'])); ?></guid>
    <pubDate><?php echo date(DATE_RSS, strtotime($post['date'])); ?></pubDate>
<?php foreach (blog_post_tags($post) as $tag): ?>
    <category><?php echo h($tag); ?></category>
<?php endforeach; ?>
    <description><![CDATA[<?php echo blog_markdown($post['body']); ?>]]></description>
  </item>
<?php endforeach; ?>
</channel>
</rss>
