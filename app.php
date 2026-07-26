<?php

// Production error handling: never leak stack traces to visitors.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

define('BLOG_ROOT', __DIR__);
define('BLOG_DATA', BLOG_ROOT . '/data');
define('BLOG_POSTS', BLOG_DATA . '/posts');
define('BLOG_MEDIA', BLOG_ROOT . '/media');

require_once BLOG_ROOT . '/vendor/Parsedown.php';

function blog_start_session()
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    ini_set('session.use_strict_mode', '1');
    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params(array(
            'lifetime' => 0,
            'path' => '/',
            'httponly' => true,
            'secure' => $secure,
            'samesite' => 'Lax',
        ));
    } else {
        session_set_cookie_params(0, '/; samesite=Lax', '', $secure, true);
    }
    session_start();
}

function blog_send_security_headers($html = true)
{
    if (headers_sent()) {
        return;
    }
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    if ($html) {
        // Posts may reference remote images over https; admin confirm() handlers
        // need inline script.
        header(
            "Content-Security-Policy: default-src 'self'; "
            . "img-src 'self' https: data:; media-src 'self' https:; "
            . "style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; "
            . "object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'"
        );
    }
}

function blog_throttle_store()
{
    return BLOG_DATA . '/.login_throttle.json';
}

function blog_throttle_key()
{
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown';
    return preg_replace('/[^a-f0-9:._-]/i', '_', $ip);
}

function blog_throttle_read()
{
    $file = blog_throttle_store();
    if (!is_file($file)) {
        return array();
    }
    $data = json_decode((string) file_get_contents($file), true);
    return is_array($data) ? $data : array();
}

function blog_login_blocked_seconds()
{
    $data = blog_throttle_read();
    $key = blog_throttle_key();
    if (!empty($data[$key]['until']) && $data[$key]['until'] > time()) {
        return $data[$key]['until'] - time();
    }
    return 0;
}

function blog_login_register_failure()
{
    blog_ensure_storage();
    $window = 900;
    $max = 8;
    $lock = 900;
    $now = time();
    $data = blog_throttle_read();
    $key = blog_throttle_key();
    $rec = isset($data[$key]) ? $data[$key] : array('count' => 0, 'first' => $now, 'until' => 0);
    if ($now - $rec['first'] > $window) {
        $rec = array('count' => 0, 'first' => $now, 'until' => 0);
    }
    $rec['count']++;
    if ($rec['count'] >= $max) {
        $rec['until'] = $now + $lock;
    }
    $data[$key] = $rec;
    foreach ($data as $k => $v) {
        if (isset($v['first']) && $now - $v['first'] > 86400) {
            unset($data[$k]);
        }
    }
    file_put_contents(blog_throttle_store(), json_encode($data), LOCK_EX);
}

function blog_login_clear_failures()
{
    $data = blog_throttle_read();
    $key = blog_throttle_key();
    if (isset($data[$key])) {
        unset($data[$key]);
        file_put_contents(blog_throttle_store(), json_encode($data), LOCK_EX);
    }
}

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
    $text = blog_lower(trim($text));
    $text = preg_replace('/[^\p{L}\p{N}]+/u', '-', $text);
    if ($text === null) {
        return 'post-' . date('Ymd-His');
    }
    $text = trim($text, '-');
    return $text ? $text : 'post-' . date('Ymd-His');
}

function blog_lower($text)
{
    $text = (string) $text;
    if (function_exists('mb_strtolower')) {
        return mb_strtolower($text, 'UTF-8');
    }

    $cyrillic = array(
        'А' => 'а', 'Б' => 'б', 'В' => 'в', 'Г' => 'г', 'Д' => 'д',
        'Е' => 'е', 'Ж' => 'ж', 'З' => 'з', 'И' => 'и', 'Й' => 'й',
        'К' => 'к', 'Л' => 'л', 'М' => 'м', 'Н' => 'н', 'О' => 'о',
        'П' => 'п', 'Р' => 'р', 'С' => 'с', 'Т' => 'т', 'У' => 'у',
        'Ф' => 'ф', 'Х' => 'х', 'Ц' => 'ц', 'Ч' => 'ч', 'Ш' => 'ш',
        'Щ' => 'щ', 'Ъ' => 'ъ', 'Ь' => 'ь', 'Ю' => 'ю', 'Я' => 'я',
    );

    return strtolower(strtr($text, $cyrillic));
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
    $post = array('title' => '', 'slug' => '', 'date' => date('Y-m-d'), 'status' => 'draft', 'tags' => '', 'body' => '');
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
    $tags = implode(', ', blog_post_tags($post));
    $body = isset($post['body']) ? rtrim($post['body']) : '';

    return "---\n"
        . "title: " . $title . "\n"
        . "date: " . $date . "\n"
        . "status: " . $status . "\n"
        . "slug: " . $slug . "\n"
        . "tags: " . $tags . "\n"
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

function blog_tag_slug($tag)
{
    // Like blog_slugify(), but returns '' for empty input instead of inventing
    // a timestamped fallback name.
    $tag = blog_lower(trim((string) $tag));
    $tag = preg_replace('/[^\p{L}\p{N}]+/u', '-', $tag);
    if ($tag === null) {
        return '';
    }
    return trim($tag, '-');
}

function blog_post_tags($post)
{
    $raw = isset($post['tags']) ? $post['tags'] : '';
    // Legacy .json posts may already hold an array.
    $parts = is_array($raw) ? $raw : explode(',', (string) $raw);
    $tags = array();
    foreach ($parts as $part) {
        $slug = blog_tag_slug($part);
        if ($slug !== '' && !in_array($slug, $tags, true)) {
            $tags[] = $slug;
        }
    }
    return $tags;
}

function blog_posts_by_tag($tag, $include_drafts)
{
    $tag = blog_tag_slug($tag);
    if ($tag === '') {
        return array();
    }
    $posts = array();
    foreach (blog_all_posts($include_drafts) as $post) {
        if (in_array($tag, blog_post_tags($post), true)) {
            $posts[] = $post;
        }
    }
    return $posts;
}

function blog_all_tags($include_drafts)
{
    $counts = array();
    foreach (blog_all_posts($include_drafts) as $post) {
        foreach (blog_post_tags($post) as $tag) {
            $counts[$tag] = isset($counts[$tag]) ? $counts[$tag] + 1 : 1;
        }
    }
    ksort($counts);
    return $counts;
}

function blog_tag_chips($post)
{
    $tags = blog_post_tags($post);
    if (!$tags) {
        return '';
    }
    $html = '<p class="tags">';
    foreach ($tags as $tag) {
        $html .= '<a class="tag" href="/?tag=' . rawurlencode($tag) . '">' . h($tag) . '</a>';
    }
    return $html . "</p>\n";
}

function blog_excerpt($body)
{
    $text = trim(strip_tags(blog_markdown($body)));
    if (strlen($text) <= 180) {
        return $text;
    }
    return rtrim(substr($text, 0, 180)) . '...';
}

function blog_markdown_parser()
{
    static $parser = null;
    if ($parser === null) {
        $parser = new Parsedown();
        $parser->setSafeMode(true);
        $parser->setStrictMode(true);
    }
    return $parser;
}

function blog_markdown_color_value($color)
{
    $color = blog_lower(trim((string) $color));
    $named = array(
        'red' => '#b42318',
        'blue' => '#0969da',
        'green' => '#1a7f37',
        'orange' => '#bc4c00',
        'purple' => '#8250df',
        'gray' => '#57606a',
        'grey' => '#57606a',
    );
    if (isset($named[$color])) {
        return $named[$color];
    }
    return preg_match('/^#[0-9a-f]{6}$/i', $color) ? $color : '';
}

function blog_markdown_media_url($url)
{
    $url = trim((string) $url);
    if (substr($url, 0, 1) === '/' && substr($url, 0, 2) !== '//') {
        return $url;
    }
    return preg_match('#^https://[^\s<>"\']+$#i', $url) ? $url : '';
}

function blog_markdown_demote_headings($html)
{
    return preg_replace_callback('/<(\/?)h([1-6])(\b[^>]*)>/', function ($match) {
        $level = min(6, ((int) $match[2]) + 1);
        return '<' . $match[1] . 'h' . $level . $match[3] . '>';
    }, $html);
}

function blog_markdown_extensions($text, &$replacements, $parser)
{
    $text = str_replace(array("\r\n", "\r"), "\n", (string) $text);
    $lines = explode("\n", $text);
    $output = array();
    $fence_character = '';
    $fence_length = 0;

    foreach ($lines as $line) {
        if (preg_match('/^\s*(`{3,}|~{3,})/', $line, $fence)) {
            $character = substr($fence[1], 0, 1);
            $length = strlen($fence[1]);
            if ($fence_character === '') {
                $fence_character = $character;
                $fence_length = $length;
            } elseif ($character === $fence_character && $length >= $fence_length) {
                $fence_character = '';
                $fence_length = 0;
            }
            $output[] = $line;
            continue;
        }

        if ($fence_character !== '') {
            $output[] = $line;
            continue;
        }

        if (preg_match('/^!\[([^\]\r\n]*)\]\(([^\s)\r\n]+)\)\s*$/', $line, $image)) {
            $url = blog_markdown_media_url($image[2]);
            if ($url !== '') {
                $token = 'BLOGIMAGETOKEN' . count($replacements) . sha1($line) . 'END';
                $figure = '<figure><img src="' . h($url) . '" alt="' . h($image[1]) . '">';
                if (trim($image[1]) !== '') {
                    $figure .= '<figcaption>' . h($image[1]) . '</figcaption>';
                }
                $replacements['<p>' . $token . '</p>'] = $figure . '</figure>';
                $output[] = '';
                $output[] = $token;
                $output[] = '';
                continue;
            }
        }

        if (preg_match('/^\[video:([^\]\r\n]+)\]\s*$/', $line, $video)) {
            $url = blog_markdown_media_url($video[1]);
            if ($url !== '') {
                $token = 'BLOGVIDEOTOKEN' . count($replacements) . sha1($line) . 'END';
                $replacements['<p>' . $token . '</p>'] =
                    '<video controls preload="metadata" src="' . h($url) . '"></video>';
                $output[] = '';
                $output[] = $token;
                $output[] = '';
                continue;
            }
        }

        if (strpos($line, '`') === false) {
            $line = preg_replace_callback(
                '/(?<!\\\\)\[color:([^\]\r\n]+)\]([^\r\n]+?)\[\/color\]/i',
                function ($match) use (&$replacements, $parser) {
                    $color = blog_markdown_color_value($match[1]);
                    if ($color === '') {
                        return $match[0];
                    }
                    $token = 'BLOGCOLORTOKEN' . count($replacements) . sha1($match[0]) . 'END';
                    $replacements[$token] = '<span style="color:' . h($color) . '">'
                        . $parser->line($match[2]) . '</span>';
                    return $token;
                },
                $line
            );
        }
        $output[] = $line;
    }

    return implode("\n", $output);
}

function blog_markdown($text)
{
    $parser = blog_markdown_parser();
    $replacements = array();
    $text = blog_markdown_extensions($text, $replacements, $parser);

    $html = blog_markdown_demote_headings($parser->text($text));
    if ($replacements) {
        $html = str_replace(array_keys($replacements), array_values($replacements), $html);
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

function blog_post_is_visible($post, $preview_requested, $admin_logged_in)
{
    return is_array($post)
        && ($post['status'] === 'published' || ($preview_requested && $admin_logged_in));
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
    // 'svg' is intentionally excluded: SVGs can carry inline scripts and would
    // execute as stored XSS when opened directly from /media.
    return array('jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'webm', 'mov', 'pdf', 'zip', 'txt', 'md', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx');
}

function blog_upload_mime_type($path)
{
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mime = finfo_file($finfo, $path);
            finfo_close($finfo);
            if (is_string($mime) && $mime !== '') {
                return strtolower($mime);
            }
        }
    }
    if (function_exists('mime_content_type')) {
        $mime = mime_content_type($path);
        return is_string($mime) ? strtolower($mime) : '';
    }
    return '';
}

function blog_allowed_upload_mimes($ext)
{
    $mimes = array(
        'jpg' => array('image/jpeg', 'image/pjpeg'),
        'jpeg' => array('image/jpeg', 'image/pjpeg'),
        'png' => array('image/png', 'image/x-png'),
        'gif' => array('image/gif'),
        'webp' => array('image/webp'),
        'mp4' => array('video/mp4', 'application/mp4'),
        'webm' => array('video/webm'),
        'mov' => array('video/quicktime'),
        'pdf' => array('application/pdf'),
        'zip' => array('application/zip', 'application/x-zip-compressed'),
        'txt' => array('text/plain'),
        'md' => array('text/plain', 'text/markdown'),
        'doc' => array('application/msword', 'application/x-ole-storage', 'application/vnd.ms-office'),
        'xls' => array('application/vnd.ms-excel', 'application/x-ole-storage', 'application/vnd.ms-office'),
        'ppt' => array('application/vnd.ms-powerpoint', 'application/x-ole-storage', 'application/vnd.ms-office'),
        'docx' => array('application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'),
        'xlsx' => array('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'),
        'pptx' => array('application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip'),
    );
    return isset($mimes[$ext]) ? $mimes[$ext] : array();
}

function blog_upload_is_valid_image($path, $ext)
{
    $types = array(
        'jpg' => IMAGETYPE_JPEG,
        'jpeg' => IMAGETYPE_JPEG,
        'png' => IMAGETYPE_PNG,
        'gif' => IMAGETYPE_GIF,
        'webp' => IMAGETYPE_WEBP,
    );
    if (!isset($types[$ext])) {
        return true;
    }
    $info = @getimagesize($path);
    return $info !== false && isset($info[2]) && $info[2] === $types[$ext];
}

function blog_save_upload($file)
{
    if (empty($file) || !isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        return array(false, 'Upload failed.');
    }

    $size = isset($file['size']) ? (int) $file['size'] : 0;
    if ($size <= 0 || $size > 50 * 1024 * 1024) {
        return array(false, 'Files must be between 1 byte and 50 MB.');
    }

    $filename = blog_clean_filename($file['name']);
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if (!in_array($ext, blog_allowed_upload_extensions())) {
        return array(false, 'This file type is not allowed.');
    }

    $mime = blog_upload_mime_type($file['tmp_name']);
    if ($mime === '' || !in_array($mime, blog_allowed_upload_mimes($ext), true)) {
        return array(false, 'The file contents do not match the selected file type.');
    }
    if (!blog_upload_is_valid_image($file['tmp_name'], $ext)) {
        return array(false, 'That file is not a valid image.');
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
