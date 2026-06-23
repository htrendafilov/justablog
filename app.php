<?php

define('BLOG_ROOT', __DIR__);
define('BLOG_DATA', BLOG_ROOT . '/data');
define('BLOG_POSTS', BLOG_DATA . '/posts');
define('BLOG_MEDIA', BLOG_ROOT . '/media');

if (!function_exists('hash_equals')) {
    function hash_equals($known, $user)
    {
        if (!is_string($known) || !is_string($user) || strlen($known) !== strlen($user)) {
            return false;
        }
        $result = 0;
        for ($i = 0; $i < strlen($known); $i++) {
            $result |= ord($known[$i]) ^ ord($user[$i]);
        }
        return $result === 0;
    }
}

function blog_ensure_storage()
{
    if (!is_dir(BLOG_DATA)) {
        mkdir(BLOG_DATA, 0755, true);
    }
    if (!is_dir(BLOG_POSTS)) {
        mkdir(BLOG_POSTS, 0755, true);
    }
    if (!is_dir(BLOG_MEDIA)) {
        mkdir(BLOG_MEDIA, 0755, true);
    }
}

function h($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function blog_config_path()
{
    return BLOG_DATA . '/config.php';
}

function blog_has_config()
{
    return is_file(blog_config_path());
}

function blog_config()
{
    if (!blog_has_config()) {
        return array(
            'site_title' => 'Hristo Trendafilov',
            'tagline' => 'Notes and links',
            'author' => 'Hristo Trendafilov',
            'username' => '',
            'password_hash' => '',
            'base_url' => 'https://blog.trendafilovi.net',
        );
    }

    $config = include blog_config_path();
    return is_array($config) ? $config : array();
}

function blog_save_config($config)
{
    blog_ensure_storage();
    $php = "<?php\nreturn " . var_export($config, true) . ";\n";
    return file_put_contents(blog_config_path(), $php, LOCK_EX) !== false;
}

function blog_base_url()
{
    $config = blog_config();
    return rtrim(isset($config['base_url']) ? $config['base_url'] : '', '/');
}

function blog_url($path)
{
    return blog_base_url() . '/' . ltrim($path, '/');
}

function blog_slugify($text)
{
    $text = strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    $text = trim($text, '-');
    return $text ? $text : 'post-' . date('Ymd-His');
}

function blog_post_path($slug)
{
    return BLOG_POSTS . '/' . basename($slug) . '.md';
}

function blog_legacy_post_path($slug)
{
    return BLOG_POSTS . '/' . basename($slug) . '.json';
}

function blog_parse_front_matter($content)
{
    $post = array('title' => '', 'slug' => '', 'date' => date('Y-m-d'), 'status' => 'draft', 'body' => '');
    $content = (string) $content;
    $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);

    if (substr($content, 0, 4) !== "---\n" && substr($content, 0, 5) !== "---\r\n") {
        $post['body'] = trim($content);
        return $post;
    }

    $parts = preg_split('/\r?\n---\r?\n/', $content, 2);
    if (count($parts) !== 2) {
        $post['body'] = trim($content);
        return $post;
    }

    $front = preg_replace('/^---\r?\n/', '', $parts[0]);
    foreach (preg_split('/\r\n|\r|\n/', $front) as $line) {
        if (strpos($line, ':') === false) {
            continue;
        }
        list($key, $value) = explode(':', $line, 2);
        $key = strtolower(trim($key));
        $value = trim($value);
        $value = trim($value, "\"'");
        if (array_key_exists($key, $post)) {
            $post[$key] = $value;
        }
    }
    $post['body'] = trim($parts[1]);
    return $post;
}

function blog_format_front_matter_value($value)
{
    return str_replace(array("\r", "\n"), ' ', trim((string) $value));
}

function blog_format_post_markdown($post)
{
    $title = blog_format_front_matter_value(isset($post['title']) ? $post['title'] : '');
    $slug = blog_format_front_matter_value(isset($post['slug']) ? $post['slug'] : blog_slugify($title));
    $date = blog_format_front_matter_value(isset($post['date']) ? $post['date'] : date('Y-m-d'));
    $status = isset($post['status']) && $post['status'] === 'published' ? 'published' : 'draft';
    $body = isset($post['body']) ? rtrim($post['body']) : '';

    return "---\n"
        . "title: " . $title . "\n"
        . "date: " . $date . "\n"
        . "status: " . $status . "\n"
        . "slug: " . $slug . "\n"
        . "---\n\n"
        . $body . "\n";
}

function blog_load_post($slug)
{
    $path = blog_post_path($slug);
    if (is_file($path)) {
        $post = blog_parse_front_matter(file_get_contents($path));
        if (empty($post['slug'])) {
            $post['slug'] = basename($slug);
        }
        if (empty($post['title'])) {
            $post['title'] = $post['slug'];
        }
        return $post;
    }

    $legacy = blog_legacy_post_path($slug);
    if (!is_file($legacy)) {
        return null;
    }
    $post = json_decode(file_get_contents($legacy), true);
    return is_array($post) ? $post : null;
}

function blog_save_post($post)
{
    blog_ensure_storage();
    if (empty($post['slug'])) {
        $post['slug'] = blog_slugify($post['title']);
    }
    $post['updated_at'] = date('c');
    $saved = file_put_contents(blog_post_path($post['slug']), blog_format_post_markdown($post), LOCK_EX) !== false;
    if ($saved && is_file(blog_legacy_post_path($post['slug']))) {
        unlink(blog_legacy_post_path($post['slug']));
    }
    return $saved;
}

function blog_delete_post($slug)
{
    $path = blog_post_path($slug);
    $legacy = blog_legacy_post_path($slug);
    $ok = is_file($path) ? unlink($path) : true;
    if (is_file($legacy)) {
        $ok = unlink($legacy) && $ok;
    }
    return $ok;
}

function blog_all_posts($include_drafts)
{
    blog_ensure_storage();
    $posts = array();
    foreach (glob(BLOG_POSTS . '/*.md') as $path) {
        $post = blog_parse_front_matter(file_get_contents($path));
        if (!is_array($post)) {
            continue;
        }
        if (empty($post['slug'])) {
            $post['slug'] = basename($path, '.md');
        }
        if (!$include_drafts && (!isset($post['status']) || $post['status'] !== 'published')) {
            continue;
        }
        $posts[] = $post;
    }

    foreach (glob(BLOG_POSTS . '/*.json') as $path) {
        $post = json_decode(file_get_contents($path), true);
        if (!is_array($post) || empty($post['slug']) || is_file(blog_post_path($post['slug']))) {
            continue;
        }
        if (!$include_drafts && (!isset($post['status']) || $post['status'] !== 'published')) {
            continue;
        }
        $posts[] = $post;
    }

    usort($posts, function ($a, $b) {
        return strcmp(isset($b['date']) ? $b['date'] : '', isset($a['date']) ? $a['date'] : '');
    });

    return $posts;
}

function blog_excerpt($body)
{
    $text = trim(strip_tags(blog_markdown($body)));
    if (strlen($text) <= 180) {
        return $text;
    }
    return rtrim(substr($text, 0, 180)) . '...';
}

function blog_inline_markup($text)
{
    $text = h($text);
    $text = preg_replace('/!\[([^\]]*)\]\(([^)\s]+)\)/', '<img src="$2" alt="$1">', $text);
    $text = preg_replace('/`([^`]+)`/', '<code>$1</code>', $text);
    $text = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text);
    $text = preg_replace('/\[([^\]]+)\]\(((?:https?:\/\/|\/)[^)\s]+)\)/', '<a href="$2">$1</a>', $text);
    return $text;
}

function blog_markdown($text)
{
    $lines = preg_split('/\r\n|\r|\n/', trim((string) $text));
    $html = '';
    $paragraph = array();
    $in_list = false;
    $in_code = false;
    $code = array();

    $flush_paragraph = function () use (&$paragraph, &$html) {
        if ($paragraph) {
            $html .= '<p>' . blog_inline_markup(implode(' ', $paragraph)) . "</p>\n";
            $paragraph = array();
        }
    };

    foreach ($lines as $line) {
        $trimmed = trim($line);

        if ($trimmed === '```') {
            if ($in_code) {
                $html .= '<pre><code>' . h(implode("\n", $code)) . "</code></pre>\n";
                $code = array();
                $in_code = false;
            } else {
                $flush_paragraph();
                if ($in_list) {
                    $html .= "</ul>\n";
                    $in_list = false;
                }
                $in_code = true;
            }
            continue;
        }

        if ($in_code) {
            $code[] = $line;
            continue;
        }

        if ($trimmed === '') {
            $flush_paragraph();
            if ($in_list) {
                $html .= "</ul>\n";
                $in_list = false;
            }
            continue;
        }

        if (preg_match('/^\[video:((?:https?:\/\/|\/)[^\]\s]+)\]$/', $trimmed, $match)) {
            $flush_paragraph();
            $src = h($match[1]);
            $html .= '<video controls preload="metadata" src="' . $src . '"></video>' . "\n";
            continue;
        }

        if (preg_match('/^!\[([^\]]*)\]\(((?:https?:\/\/|\/)[^)\s]+)\)$/', $trimmed, $match)) {
            $flush_paragraph();
            $html .= '<figure><img src="' . h($match[2]) . '" alt="' . h($match[1]) . '">';
            if (trim($match[1]) !== '') {
                $html .= '<figcaption>' . h($match[1]) . '</figcaption>';
            }
            $html .= "</figure>\n";
            continue;
        }

        if (preg_match('/^##\s+(.+)/', $trimmed, $match)) {
            $flush_paragraph();
            $html .= '<h2>' . blog_inline_markup($match[1]) . "</h2>\n";
            continue;
        }

        if (preg_match('/^#\s+(.+)/', $trimmed, $match)) {
            $flush_paragraph();
            $html .= '<h1>' . blog_inline_markup($match[1]) . "</h1>\n";
            continue;
        }

        if (preg_match('/^[-*]\s+(.+)/', $trimmed, $match)) {
            $flush_paragraph();
            if (!$in_list) {
                $html .= "<ul>\n";
                $in_list = true;
            }
            $html .= '<li>' . blog_inline_markup($match[1]) . "</li>\n";
            continue;
        }

        $paragraph[] = $trimmed;
    }

    $flush_paragraph();
    if ($in_list) {
        $html .= "</ul>\n";
    }
    if ($in_code) {
        $html .= '<pre><code>' . h(implode("\n", $code)) . "</code></pre>\n";
    }

    return $html;
}

function blog_csrf_token()
{
    if (empty($_SESSION['csrf'])) {
        if (function_exists('openssl_random_pseudo_bytes')) {
            $_SESSION['csrf'] = bin2hex(openssl_random_pseudo_bytes(16));
        } else {
            $_SESSION['csrf'] = sha1(uniqid('', true));
        }
    }
    return $_SESSION['csrf'];
}

function blog_check_csrf()
{
    return isset($_POST['csrf']) && isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $_POST['csrf']);
}

function blog_admin_logged_in()
{
    return !empty($_SESSION['blog_admin']);
}

function blog_require_admin()
{
    if (!blog_admin_logged_in()) {
        header('Location: /admin/');
        exit;
    }
}

function blog_current_path()
{
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    return '/' . trim($path, '/');
}

function blog_media_year_dir()
{
    return BLOG_MEDIA . '/' . date('Y');
}

function blog_media_public_path($filename)
{
    return '/media/' . date('Y') . '/' . $filename;
}

function blog_clean_filename($filename)
{
    $info = pathinfo($filename);
    $name = isset($info['filename']) ? $info['filename'] : 'file';
    $ext = isset($info['extension']) ? strtolower($info['extension']) : '';
    $name = blog_slugify($name);
    return $ext ? $name . '.' . $ext : $name;
}

function blog_allowed_upload_extensions()
{
    return array('jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'mp4', 'webm', 'mov', 'pdf', 'zip', 'txt', 'md', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx');
}

function blog_save_upload($file)
{
    if (empty($file) || !isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        return array(false, 'Upload failed.');
    }

    $filename = blog_clean_filename($file['name']);
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if (!in_array($ext, blog_allowed_upload_extensions())) {
        return array(false, 'This file type is not allowed.');
    }

    $dir = blog_media_year_dir();
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $target = $dir . '/' . $filename;
    if (is_file($target)) {
        $filename = pathinfo($filename, PATHINFO_FILENAME) . '-' . date('His') . '.' . $ext;
        $target = $dir . '/' . $filename;
    }

    if (!move_uploaded_file($file['tmp_name'], $target)) {
        return array(false, 'Could not save the uploaded file.');
    }

    return array(true, blog_media_public_path($filename));
}

function blog_media_files()
{
    blog_ensure_storage();
    $files = array();
    foreach (glob(BLOG_MEDIA . '/*/*') as $path) {
        if (!is_file($path)) {
            continue;
        }
        $files[] = array(
            'path' => str_replace(BLOG_ROOT, '', $path),
            'name' => basename($path),
            'size' => filesize($path),
            'mtime' => filemtime($path),
        );
    }
    usort($files, function ($a, $b) {
        return $b['mtime'] - $a['mtime'];
    });
    return $files;
}

?>
