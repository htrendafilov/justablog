<?php
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
require dirname(__DIR__) . '/app.php';
blog_send_security_headers();
blog_ensure_storage();
$error = '';
$message = '';
$action = isset($_GET['action']) ? $_GET['action'] : 'dashboard';
$config = blog_config();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['setup']) && !blog_has_config()) {
        $password = isset($_POST['password']) ? $_POST['password'] : '';
        if (!blog_check_csrf()) {
            $error = 'Session expired. Please try again.';
        } elseif (strlen($password) < 10) {
            $error = 'Use a password with at least 10 characters.';
        } else {
            $config = array(
                'site_title' => trim($_POST['site_title']),
                'tagline' => trim($_POST['tagline']),
                'author' => trim($_POST['author']),
                'username' => trim($_POST['username']),
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'base_url' => rtrim(trim($_POST['base_url']), '/'),
            );
            blog_save_config($config);
            session_regenerate_id(true);
            $_SESSION['blog_admin'] = true;
            header('Location: /admin/');
            exit;
        }
    } elseif (isset($_POST['login'])) {
        $wait = blog_login_blocked_seconds();
        if ($wait > 0) {
            $error = 'Too many attempts. Try again in ' . ceil($wait / 60) . ' minute(s).';
        } elseif (!blog_check_csrf()) {
            $error = 'Session expired. Please try again.';
        } elseif (!blog_has_config()) {
            $error = 'Run setup first.';
        } elseif (isset($_POST['username'], $_POST['password'])
            && $_POST['username'] === $config['username']
            && password_verify($_POST['password'], $config['password_hash'])
        ) {
            blog_login_clear_failures();
            session_regenerate_id(true);
            $_SESSION['blog_admin'] = true;
            header('Location: /admin/');
            exit;
        } else {
            blog_login_register_failure();
            $error = 'Invalid username or password.';
        }
    } elseif (isset($_POST['logout'])) {
        if (blog_check_csrf()) {
            session_destroy();
        }
        header('Location: /admin/');
        exit;
    } elseif (blog_admin_logged_in() && blog_check_csrf()) {
        if (isset($_POST['save_settings'])) {
            $config['site_title'] = trim($_POST['site_title']);
            $config['tagline'] = trim($_POST['tagline']);
            $config['author'] = trim($_POST['author']);
            $config['base_url'] = rtrim(trim($_POST['base_url']), '/');
            if (!empty($_POST['password'])) {
                if (strlen($_POST['password']) < 10) {
                    $error = 'Use a password with at least 10 characters.';
                } else {
                    $config['password_hash'] = password_hash($_POST['password'], PASSWORD_DEFAULT);
                }
            }
            if (!$error) {
                blog_save_config($config);
                $message = 'Settings saved.';
            }
        } elseif (isset($_POST['upload_media'])) {
            list($ok, $result) = blog_save_upload(isset($_FILES['media_file']) ? $_FILES['media_file'] : null);
            if ($ok) {
                $message = 'Uploaded: ' . $result;
            } else {
                $error = $result;
            }
        } elseif (isset($_POST['save_post'])) {
            $old_slug = isset($_POST['old_slug']) ? $_POST['old_slug'] : '';
            $title = trim($_POST['title']);
            $slug = trim($_POST['slug']) ? blog_slugify($_POST['slug']) : blog_slugify($title);
            if ($title === '') {
                $error = 'Title is required.';
            } else {
                $post = array(
                    'title' => $title,
                    'slug' => $slug,
                    'date' => trim($_POST['date']) ? trim($_POST['date']) : date('Y-m-d'),
                    'status' => $_POST['status'] === 'published' ? 'published' : 'draft',
                    'tags' => isset($_POST['tags']) ? $_POST['tags'] : '',
                    'body' => isset($_POST['body']) ? $_POST['body'] : '',
                );
                if ($old_slug && $old_slug !== $slug) {
                    blog_delete_post($old_slug);
                }
                blog_save_post($post);
                header('Location: /admin/?saved=1');
                exit;
            }
        } elseif (isset($_POST['delete_post'])) {
            blog_delete_post($_POST['slug']);
            header('Location: /admin/?deleted=1');
            exit;
        }
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $error = 'Session expired. Please try again.';
    }
}

if (isset($_GET['saved'])) {
    $message = 'Post saved.';
}
if (isset($_GET['deleted'])) {
    $message = 'Post deleted.';
}

function admin_header($title)
{
    ?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?php echo h($title); ?></title>
  <link rel="stylesheet" href="/admin/style.css">
</head>
<body>
  <main class="admin-wrap">
<?php
}

function admin_footer()
{
    ?>
  </main>
</body>
</html>
<?php
}

admin_header('Blog admin');
?>
    <header class="admin-header">
      <a class="admin-brand" href="/admin/">Blog admin</a>
<?php if (blog_admin_logged_in()): ?>
      <nav>
        <a href="/admin/">Posts</a>
        <a href="/admin/?action=new">New post</a>
        <a href="/admin/?action=media">Media</a>
        <a href="/admin/?action=settings">Settings</a>
        <a href="/" target="_blank">View site</a>
      </nav>
      <form method="post" class="logout">
        <input type="hidden" name="csrf" value="<?php echo h(blog_csrf_token()); ?>">
        <button type="submit" name="logout">Log out</button>
      </form>
<?php endif; ?>
    </header>

<?php if ($error): ?>
    <p class="notice error"><?php echo h($error); ?></p>
<?php endif; ?>
<?php if ($message): ?>
    <p class="notice"><?php echo h($message); ?></p>
<?php endif; ?>

<?php if (!blog_has_config()): ?>
    <section class="panel">
      <h1>Set up the blog</h1>
      <form method="post">
        <input type="hidden" name="csrf" value="<?php echo h(blog_csrf_token()); ?>">
        <label>Site title <input name="site_title" value="Hristo Trendafilov" required></label>
        <label>Tagline <input name="tagline" value="Notes and links"></label>
        <label>Author <input name="author" value="Hristo Trendafilov" required></label>
        <label>Base URL <input name="base_url" value="https://blog.trendafilovi.net" required></label>
        <label>Username <input name="username" autocomplete="username" required></label>
        <label>Password <input name="password" type="password" autocomplete="new-password" required></label>
        <button type="submit" name="setup">Create admin</button>
      </form>
    </section>
<?php elseif (!blog_admin_logged_in()): ?>
    <section class="panel">
      <h1>Log in</h1>
      <form method="post">
        <input type="hidden" name="csrf" value="<?php echo h(blog_csrf_token()); ?>">
        <label>Username <input name="username" autocomplete="username" required></label>
        <label>Password <input name="password" type="password" autocomplete="current-password" required></label>
        <button type="submit" name="login">Log in</button>
      </form>
    </section>
<?php elseif ($action === 'settings'): ?>
    <section class="panel">
      <h1>Settings</h1>
      <form method="post">
        <input type="hidden" name="csrf" value="<?php echo h(blog_csrf_token()); ?>">
        <label>Site title <input name="site_title" value="<?php echo h($config['site_title']); ?>" required></label>
        <label>Tagline <input name="tagline" value="<?php echo h($config['tagline']); ?>"></label>
        <label>Author <input name="author" value="<?php echo h($config['author']); ?>" required></label>
        <label>Base URL <input name="base_url" value="<?php echo h($config['base_url']); ?>" required></label>
        <label>New password <input name="password" type="password" autocomplete="new-password"></label>
        <button type="submit" name="save_settings">Save settings</button>
      </form>
    </section>
<?php elseif ($action === 'media'): ?>
    <section class="panel">
      <h1>Media</h1>
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?php echo h(blog_csrf_token()); ?>">
        <label>Upload file <input name="media_file" type="file" required></label>
        <button type="submit" name="upload_media">Upload</button>
      </form>
    </section>
    <section class="panel panel-gap">
      <h1>Files</h1>
      <table>
        <thead><tr><th>File</th><th>Markdown</th><th></th></tr></thead>
        <tbody>
<?php foreach (blog_media_files() as $file):
    $path = $file['path'];
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if (in_array($ext, array('jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'))) {
        $snippet = '![' . pathinfo($file['name'], PATHINFO_FILENAME) . '](' . $path . ')';
    } elseif (in_array($ext, array('mp4', 'webm', 'mov'))) {
        $snippet = '[video:' . $path . ']';
    } else {
        $snippet = '[Download ' . $file['name'] . '](' . $path . ')';
    }
?>
          <tr>
            <td><?php echo h($file['name']); ?></td>
            <td><code><?php echo h($snippet); ?></code></td>
            <td><a href="<?php echo h($path); ?>" target="_blank">Open</a></td>
          </tr>
<?php endforeach; ?>
        </tbody>
      </table>
    </section>
<?php elseif ($action === 'new' || $action === 'edit'):
    $editing = $action === 'edit';
    $post = $editing && isset($_GET['slug']) ? blog_load_post($_GET['slug']) : null;
    if (!$post) {
        $post = array('title' => '', 'slug' => '', 'date' => date('Y-m-d'), 'status' => 'draft', 'tags' => '', 'body' => '');
    }
?>
    <section class="panel">
      <h1><?php echo $editing ? 'Edit post' : 'New post'; ?></h1>
      <form method="post">
        <input type="hidden" name="csrf" value="<?php echo h(blog_csrf_token()); ?>">
        <input type="hidden" name="old_slug" value="<?php echo h($post['slug']); ?>">
        <label>Title <input name="title" value="<?php echo h($post['title']); ?>" required></label>
        <label>Slug <input name="slug" value="<?php echo h($post['slug']); ?>"></label>
        <label>Date <input name="date" type="date" value="<?php echo h(substr($post['date'], 0, 10)); ?>"></label>
        <label>Status
          <select name="status">
            <option value="draft"<?php echo $post['status'] === 'draft' ? ' selected' : ''; ?>>Draft</option>
            <option value="published"<?php echo $post['status'] === 'published' ? ' selected' : ''; ?>>Published</option>
          </select>
        </label>
        <label>Tags <input name="tags" value="<?php echo h(implode(', ', blog_post_tags($post))); ?>" placeholder="comma, separated"></label>
        <label>Body
          <textarea name="body" rows="18"><?php echo h($post['body']); ?></textarea>
        </label>
        <button type="submit" name="save_post">Save post</button>
      </form>
      <div class="help">
        <p>Images: <code>![Alt text](/media/2026/photo.jpg)</code></p>
        <p>Videos: <code>[video:/media/2026/movie.mp4]</code></p>
        <p>Downloads: <code>[Download file](/media/2026/file.pdf)</code></p>
      </div>
<?php if ($editing): ?>
      <form method="post" class="danger" onsubmit="return confirm('Delete this post?');">
        <input type="hidden" name="csrf" value="<?php echo h(blog_csrf_token()); ?>">
        <input type="hidden" name="slug" value="<?php echo h($post['slug']); ?>">
        <button type="submit" name="delete_post">Delete post</button>
      </form>
<?php endif; ?>
    </section>
<?php else: ?>
    <section class="panel">
      <div class="toolbar">
        <h1>Posts</h1>
        <div class="actions">
          <a class="button" href="/admin/?action=new">New post</a>
          <a class="button secondary" href="/admin/?action=media">Media</a>
        </div>
      </div>
      <table>
        <thead><tr><th>Title</th><th>Date</th><th>Status</th><th>Tags</th><th></th></tr></thead>
        <tbody>
<?php foreach (blog_all_posts(true) as $post): ?>
          <tr>
            <td><?php echo h($post['title']); ?></td>
            <td><?php echo h(substr($post['date'], 0, 10)); ?></td>
            <td><?php echo h($post['status']); ?></td>
            <td><?php echo h(implode(', ', blog_post_tags($post))); ?></td>
            <td><a href="/admin/?action=edit&amp;slug=<?php echo h($post['slug']); ?>">Edit</a></td>
          </tr>
<?php endforeach; ?>
        </tbody>
      </table>
    </section>
<?php endif; ?>
<?php admin_footer(); ?>
