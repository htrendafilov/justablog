<?php

require dirname(__DIR__) . '/app.php';

$failures = 0;

function test_expect($condition, $message)
{
    global $failures;
    if (!$condition) {
        $failures++;
        fwrite(STDERR, "FAIL: " . $message . "\n");
    }
}

$markdown = <<<'MD'
# Body heading

This is **bold**, *italic*, ~~deleted~~, and [color:red]red[/color].

> A quoted paragraph.

1. First
2. Second

| Name | Value |
| --- | --- |
| A | B |

![Caption](/media/2026/photo.jpg)

[video:/media/2026/movie.mp4]

```text
[color:red]literal color syntax[/color]
[video:/media/2026/literal.mp4]
```

***

<script>alert(1)</script>
MD;

$html = blog_markdown($markdown);
test_expect(strpos($html, '<h2>Body heading</h2>') !== false, 'body H1 is demoted below the post title');
test_expect(strpos($html, '<strong>bold</strong>') !== false, 'bold text renders');
test_expect(strpos($html, '<em>italic</em>') !== false, 'italic text renders');
test_expect(strpos($html, '<del>deleted</del>') !== false, 'strikethrough renders');
test_expect(strpos($html, '<blockquote>') !== false, 'blockquote renders');
test_expect(strpos($html, '<ol>') !== false, 'ordered list renders');
test_expect(strpos($html, '<table>') !== false, 'table renders');
test_expect(strpos($html, '<hr />') !== false, 'horizontal rule renders');
test_expect(strpos($html, '<figure><img') !== false, 'standalone image retains figure markup');
test_expect(strpos($html, '<figcaption>Caption</figcaption>') !== false, 'image caption renders');
test_expect(strpos($html, '<video controls') !== false, 'custom video syntax renders');
test_expect(strpos($html, 'style="color:#b42318"') !== false, 'allow-listed color syntax renders');
test_expect(substr_count($html, '<video controls') === 1, 'custom directives stay literal inside code fences');
test_expect(strpos($html, '[color:red]literal color syntax[/color]') !== false, 'color syntax stays literal inside code fences');
test_expect(strpos($html, '<script>') === false, 'raw HTML is escaped');

test_expect(blog_slugify('Тестова статия') === 'тестова-статия', 'Cyrillic post slugs are preserved');
test_expect(blog_tag_slug('Библия и софтуер') === 'библия-и-софтуер', 'Cyrillic tags are preserved');

$published = array('status' => 'published');
$draft = array('status' => 'draft');
test_expect(blog_post_is_visible($published, false, false), 'published posts are public');
test_expect(!blog_post_is_visible($draft, false, true), 'drafts require preview mode');
test_expect(!blog_post_is_visible($draft, true, false), 'draft previews require an admin session');
test_expect(blog_post_is_visible($draft, true, true), 'admins can preview drafts');

$fake = tempnam(sys_get_temp_dir(), 'blog-upload-');
file_put_contents($fake, '<html>not an image</html>');
list($upload_ok) = blog_save_upload(array(
    'error' => UPLOAD_ERR_OK,
    'name' => 'fake.jpg',
    'tmp_name' => $fake,
    'size' => filesize($fake),
));
test_expect($upload_ok === false, 'an HTML file renamed to JPG is rejected');
unlink($fake);

list($oversized_ok) = blog_save_upload(array(
    'error' => UPLOAD_ERR_OK,
    'name' => 'large.pdf',
    'tmp_name' => __FILE__,
    'size' => 50 * 1024 * 1024 + 1,
));
test_expect($oversized_ok === false, 'uploads larger than 50 MB are rejected');

if ($failures > 0) {
    fwrite(STDERR, $failures . " test(s) failed.\n");
    exit(1);
}

echo "All blog tests passed.\n";
