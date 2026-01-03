<?php
/*
|--------------------------------------------------------------------------
| CONFIGURATION
|--------------------------------------------------------------------------
*/
$config = [
    'title' => 'My Blog',
    'admin_password' => 'changeme123',  // CHANGE THIS!
    'posts_file' => 'posts.json',
    'require_auth_to_read' => false,    // Set true to make blog private
];

/*
|--------------------------------------------------------------------------
| BACKEND LOGIC
|--------------------------------------------------------------------------
*/
session_start();

// Initialize posts file
if (!file_exists($config['posts_file'])) {
    file_put_contents($config['posts_file'], json_encode([]));
}

function getPosts() {
    global $config;
    $content = file_get_contents($config['posts_file']);
    return json_decode($content, true) ?: [];
}

function savePosts($posts) {
    global $config;
    file_put_contents($config['posts_file'], json_encode($posts, JSON_PRETTY_PRINT));
}

function isLoggedIn() {
    return isset($_SESSION['authenticated']) && $_SESSION['authenticated'] === true;
}

function generateId() {
    return bin2hex(random_bytes(8));
}

// Handle actions
$message = '';
$error = '';

// Login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    if ($_POST['action'] === 'login') {
        if ($_POST['password'] === $config['admin_password']) {
            $_SESSION['authenticated'] = true;
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        } else {
            $error = 'Invalid password';
        }
    }
    
    if ($_POST['action'] === 'logout') {
        session_destroy();
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
    
    // Protected actions
    if (isLoggedIn()) {
        
        if ($_POST['action'] === 'create' || $_POST['action'] === 'update') {
            $title = trim($_POST['title'] ?? '');
            $content = trim($_POST['content'] ?? '');
            $tags = array_filter(array_map('trim', explode(',', $_POST['tags'] ?? '')));
            $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($title));
            
            if ($title && $content) {
                $posts = getPosts();
                
                if ($_POST['action'] === 'create') {
                    $post = [
                        'id' => generateId(),
                        'title' => $title,
                        'slug' => $slug,
                        'content' => $content,
                        'tags' => $tags,
                        'date' => date('c'),
                        'updated' => null,
                    ];
                    array_unshift($posts, $post);
                    $message = 'Post published!';
                } else {
                    $id = $_POST['id'];
                    foreach ($posts as &$post) {
                        if ($post['id'] === $id) {
                            $post['title'] = $title;
                            $post['slug'] = $slug;
                            $post['content'] = $content;
                            $post['tags'] = $tags;
                            $post['updated'] = date('c');
                            break;
                        }
                    }
                    $message = 'Post updated!';
                }
                
                savePosts($posts);
            } else {
                $error = 'Title and content are required';
            }
        }
        
        if ($_POST['action'] === 'delete') {
            $id = $_POST['id'];
            $posts = getPosts();
            $posts = array_filter($posts, fn($p) => $p['id'] !== $id);
            savePosts(array_values($posts));
            $message = 'Post deleted!';
        }
    }
    
    if (!$error && $_POST['action'] !== 'login') {
        header('Location: ' . $_SERVER['PHP_SELF'] . (isset($_GET['post']) ? '?post=' . $_GET['post'] : ''));
        exit;
    }
}

// Get current view data
$posts = getPosts();
$currentPost = null;
$editPost = null;
$view = 'list';

if (isset($_GET['post'])) {
    foreach ($posts as $post) {
        if ($post['id'] === $_GET['post'] || $post['slug'] === $_GET['post']) {
            $currentPost = $post;
            $view = 'single';
            break;
        }
    }
}

if (isset($_GET['edit']) && isLoggedIn()) {
    foreach ($posts as $post) {
        if ($post['id'] === $_GET['edit']) {
            $editPost = $post;
            $view = 'edit';
            break;
        }
    }
}

if (isset($_GET['new']) && isLoggedIn()) {
    $view = 'new';
}

// Check read auth
$canRead = !$config['require_auth_to_read'] || isLoggedIn();

// Simple Markdown parser
function parseMarkdown($text) {
    $text = htmlspecialchars($text);
    $text = preg_replace('/```(.*?)```/s', '<pre><code>$1</code></pre>', $text);
    $text = preg_replace('/`([^`]+)`/', '<code>$1</code>', $text);
    $text = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text);
    $text = preg_replace('/\*([^*]+)\*/', '<em>$1</em>', $text);
    $text = preg_replace('/^### (.+)$/m', '<h3>$1</h3>', $text);
    $text = preg_replace('/^## (.+)$/m', '<h2>$1</h2>', $text);
    $text = preg_replace('/^# (.+)$/m', '<h1>$1</h1>', $text);
    $text = preg_replace('/^&gt; (.+)$/m', '<blockquote>$1</blockquote>', $text);
    $text = preg_replace('/^- (.+)$/m', '<li>$1</li>', $text);
    $text = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '<a href="$2" target="_blank" rel="noopener">$1</a>', $text);
    $text = preg_replace('/\n\n/', '</p><p>', $text);
    $text = preg_replace('/\n/', '<br>', $text);
    return '<p>' . $text . '</p>';
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($currentPost['title'] ?? $config['title']) ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        :root {
            --bg: #fafafa;
            --card: #ffffff;
            --text: #1a1a1a;
            --text-muted: #666666;
            --accent: #2563eb;
            --accent-hover: #1d4ed8;
            --border: #e5e7eb;
            --danger: #dc2626;
            --success: #16a34a;
        }
        
        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #0f172a;
                --card: #1e293b;
                --text: #f1f5f9;
                --text-muted: #94a3b8;
                --border: #334155;
            }
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--bg);
            color: var(--text);
            line-height: 1.7;
        }
        
        a { color: var(--accent); text-decoration: none; }
        a:hover { text-decoration: underline; }
        
        header {
            background: var(--card);
            border-bottom: 1px solid var(--border);
            padding: 1rem;
            position: sticky;
            top: 0;
            z-index: 100;
        }
        
        .header-inner {
            max-width: 800px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }
        
        .logo {
            font-size: 1.4rem;
            font-weight: 700;
            color: var(--text);
        }
        
        nav { display: flex; gap: 0.75rem; align-items: center; }
        
        .btn {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 0.5rem;
            cursor: pointer;
            font-size: 0.9rem;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn-primary { background: var(--accent); color: white; }
        .btn-primary:hover { background: var(--accent-hover); text-decoration: none; }
        .btn-secondary { background: var(--border); color: var(--text); }
        .btn-danger { background: var(--danger); color: white; }
        .btn-small { padding: 0.3rem 0.6rem; font-size: 0.8rem; }
        
        main {
            max-width: 800px;
            margin: 0 auto;
            padding: 2rem 1rem;
        }
        
        .message {
            padding: 1rem;
            border-radius: 0.5rem;
            margin-bottom: 1.5rem;
        }
        .message.success { background: #dcfce7; color: #166534; }
        .message.error { background: #fee2e2; color: #991b1b; }
        
        .post-card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 1rem;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        
        .post-card:hover {
            border-color: var(--accent);
        }
        
        .post-title {
            font-size: 1.5rem;
            margin-bottom: 0.5rem;
            color: var(--text);
        }
        
        .post-title a { color: inherit; }
        
        .post-meta {
            font-size: 0.85rem;
            color: var(--text-muted);
            margin-bottom: 1rem;
        }
        
        .post-tag {
            background: var(--accent);
            color: white;
            padding: 0.15rem 0.5rem;
            border-radius: 1rem;
            font-size: 0.75rem;
            margin-left: 0.25rem;
        }
        
        .post-excerpt {
            color: var(--text-muted);
        }
        
        .post-actions {
            margin-top: 1rem;
            display: flex;
            gap: 0.5rem;
        }
        
        .full-post .post-title { font-size: 2.2rem; }
        
        .post-content {
            margin-top: 1.5rem;
            font-size: 1.1rem;
        }
        
        .post-content h1, .post-content h2, .post-content h3 {
            margin: 1.5rem 0 0.75rem 0;
        }
        
        .post-content p { margin-bottom: 1rem; }
        
        .post-content code {
            background: var(--border);
            padding: 0.2rem 0.4rem;
            border-radius: 0.25rem;
            font-size: 0.9em;
        }
        
        .post-content pre {
            background: var(--border);
            padding: 1rem;
            border-radius: 0.5rem;
            overflow-x: auto;
            margin: 1rem 0;
        }
        
        .post-content pre code { background: none; padding: 0; }
        
        .post-content blockquote {
            border-left: 4px solid var(--accent);
            padding-left: 1rem;
            color: var(--text-muted);
            font-style: italic;
            margin: 1rem 0;
        }
        
        .post-content li { margin-left: 1.5rem; }
        
        /* Form Styles */
        .form-group { margin-bottom: 1.25rem; }
        
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
        }
        
        .form-group input,
        .form-group textarea {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--border);
            border-radius: 0.5rem;
            font-size: 1rem;
            font-family: inherit;
            background: var(--bg);
            color: var(--text);
        }
        
        .form-group textarea {
            min-height: 400px;
            resize: vertical;
            font-family: ui-monospace, monospace;
        }
        
        .form-group input:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--accent);
        }
        
        .form-help {
            font-size: 0.8rem;
            color: var(--text-muted);
            margin-top: 0.5rem;
        }
        
        .login-box {
            max-width: 400px;
            margin: 4rem auto;
            background: var(--card);
            padding: 2rem;
            border-radius: 1rem;
            border: 1px solid var(--border);
        }
        
        .login-box h2 { margin-bottom: 1.5rem; }
        
        .back-link { display: block; margin-bottom: 1.5rem; }
        
        .empty {
            text-align: center;
            padding: 3rem;
            color: var(--text-muted);
        }
        
        footer {
            text-align: center;
            padding: 2rem;
            color: var(--text-muted);
            font-size: 0.85rem;
        }
        
        @media (max-width: 600px) {
            .header-inner { flex-direction: column; text-align: center; }
            .post-title { font-size: 1.3rem; }
            .full-post .post-title { font-size: 1.6rem; }
        }
    </style>
</head>
<body>
    <header>
        <div class="header-inner">
            <a href="<?= $_SERVER['PHP_SELF'] ?>" class="logo"><?= htmlspecialchars($config['title']) ?></a>
            <nav>
                <?php if (isLoggedIn()): ?>
                    <a href="?new=1" class="btn btn-primary">+ New Post</a>
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="action" value="logout">
                        <button type="submit" class="btn btn-secondary">Logout</button>
                    </form>
                <?php else: ?>
                    <a href="?login=1" class="btn btn-secondary">Admin</a>
                <?php endif; ?>
            </nav>
        </div>
    </header>
    
    <main>
        <?php if ($message): ?>
            <div class="message success"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="message error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <?php if (isset($_GET['login']) && !isLoggedIn()): ?>
            <!-- Login Form -->
            <div class="login-box">
                <h2>Admin Login</h2>
                <form method="POST">
                    <input type="hidden" name="action" value="login">
                    <div class="form-group">
                        <label for="password">Password</label>
                        <input type="password" name="password" id="password" required autofocus>
                    </div>
                    <button type="submit" class="btn btn-primary">Login</button>
                </form>
            </div>
            
        <?php elseif (!$canRead): ?>
            <div class="login-box">
                <h2>Private Blog</h2>
                <p>This blog requires authentication to view.</p>
                <br>
                <a href="?login=1" class="btn btn-primary">Login</a>
            </div>
            
        <?php elseif ($view === 'new' || $view === 'edit'): ?>
            <!-- Post Editor -->
            <a href="<?= $_SERVER['PHP_SELF'] ?>" class="back-link">← Back to posts</a>
            
            <div class="post-card">
                <h2><?= $view === 'edit' ? 'Edit Post' : 'New Post' ?></h2>
                <form method="POST" style="margin-top: 1.5rem;">
                    <input type="hidden" name="action" value="<?= $view === 'edit' ? 'update' : 'create' ?>">
                    <?php if ($editPost): ?>
                        <input type="hidden" name="id" value="<?= htmlspecialchars($editPost['id']) ?>">
                    <?php endif; ?>
                    
                    <div class="form-group">
                        <label for="title">Title</label>
                        <input type="text" name="title" id="title" required
                               value="<?= htmlspecialchars($editPost['title'] ?? '') ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="tags">Tags (comma-separated)</label>
                        <input type="text" name="tags" id="tags" placeholder="tech, life, updates"
                               value="<?= htmlspecialchars(implode(', ', $editPost['tags'] ?? [])) ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="content">Content</label>
                        <textarea name="content" id="content" required><?= htmlspecialchars($editPost['content'] ?? '') ?></textarea>
                        <div class="form-help">
                            Supports Markdown: **bold**, *italic*, `code`, # headings, > quotes, - lists, [text](url)
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">
                        <?= $view === 'edit' ? 'Update Post' : 'Publish Post' ?>
                    </button>
                </form>
            </div>
            
        <?php elseif ($view === 'single' && $currentPost): ?>
            <!-- Single Post View -->
            <a href="<?= $_SERVER['PHP_SELF'] ?>" class="back-link">← Back to posts</a>
            
            <article class="post-card full-post">
                <h1 class="post-title"><?= htmlspecialchars($currentPost['title']) ?></h1>
                <div class="post-meta">
                    📅 <?= date('F j, Y', strtotime($currentPost['date'])) ?>
                    <?php if ($currentPost['updated']): ?>
                        (updated <?= date('F j, Y', strtotime($currentPost['updated'])) ?>)
                    <?php endif; ?>
                    <?php foreach ($currentPost['tags'] as $tag): ?>
                        <span class="post-tag"><?= htmlspecialchars($tag) ?></span>
                    <?php endforeach; ?>
                </div>
                
                <div class="post-content">
                    <?= parseMarkdown($currentPost['content']) ?>
                </div>
                
                <?php if (isLoggedIn()): ?>
                    <div class="post-actions">
                        <a href="?edit=<?= $currentPost['id'] ?>" class="btn btn-secondary btn-small">Edit</a>
                        <form method="POST" style="display:inline;" 
                              onsubmit="return confirm('Delete this post?')">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $currentPost['id'] ?>">
                            <button type="submit" class="btn btn-danger btn-small">Delete</button>
                        </form>
                    </div>
                <?php endif; ?>
            </article>
            
        <?php else: ?>
            <!-- Post List -->
            <?php if (empty($posts)): ?>
                <div class="empty">
                    <h2>No posts yet</h2>
                    <p>Check back soon for new content!</p>
                    <?php if (isLoggedIn()): ?>
                        <br><a href="?new=1" class="btn btn-primary">Create your first post</a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <?php foreach ($posts as $post): ?>
                    <article class="post-card">
                        <h2 class="post-title">
                            <a href="?post=<?= htmlspecialchars($post['slug']) ?>">
                                <?= htmlspecialchars($post['title']) ?>
                            </a>
                        </h2>
                        <div class="post-meta">
                            📅 <?= date('F j, Y', strtotime($post['date'])) ?>
                            <?php foreach ($post['tags'] as $tag): ?>
                                <span class="post-tag"><?= htmlspecialchars($tag) ?></span>
                            <?php endforeach; ?>
                        </div>
                        <div class="post-excerpt">
                            <?= htmlspecialchars(substr($post['content'], 0, 250)) ?>...
                        </div>
                        
                        <?php if (isLoggedIn()): ?>
                            <div class="post-actions">
                                <a href="?edit=<?= $post['id'] ?>" class="btn btn-secondary btn-small">Edit</a>
                                <form method="POST" style="display:inline;" 
                                      onsubmit="return confirm('Delete this post?')">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= $post['id'] ?>">
                                    <button type="submit" class="btn btn-danger btn-small">Delete</button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        <?php endif; ?>
    </main>
    
    <footer>
        Powered by a single PHP file ✨
    </footer>
</body>
</html>
