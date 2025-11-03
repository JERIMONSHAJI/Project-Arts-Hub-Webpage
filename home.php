<?php
// Start session
session_start();

// Redirect to login if not authenticated
if (!isset($_SESSION['username'])) {
    header("Location: index.php");
    exit();
}

// Include database connection
require 'db_connect.php';

// Get current user ID
try {
    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->execute([$_SESSION['username']]);
    $current_user = $stmt->fetch();
    $current_user_id = $current_user['id'] ?? 0;
} catch (PDOException $e) {
    $error = "Failed to fetch user data: " . $e->getMessage();
}

// Handle like/unlike action
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['toggle_like']) && isset($_POST['post_id'])) {
    $post_id = intval($_POST['post_id']);
    
    // Check if user already liked the post
    $stmt = $pdo->prepare("SELECT id FROM likes WHERE post_id = ? AND user_id = ?");
    $stmt->execute([$post_id, $current_user_id]);
    $existing_like = $stmt->fetch();

    try {
        if ($existing_like) {
            // Unlike: Remove the like
            $stmt = $pdo->prepare("DELETE FROM likes WHERE post_id = ? AND user_id = ?");
            $stmt->execute([$post_id, $current_user_id]);
        } else {
            // Like: Add the like
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("INSERT INTO likes (post_id, user_id) VALUES (?, ?)");
            $stmt->execute([$post_id, $current_user_id]);

            // Insert notification for post owner
            $message = $_SESSION['username'] . " liked your post: " . ($post['description'] ?: 'Post ID ' . $post_id);
            $stmt = $pdo->prepare("
                INSERT INTO notifications (user_id, type, post_id, actor_id, message)
                SELECT p.user_id, 'like', p.id, ?, ?
                FROM posts p WHERE p.id = ?
            ");
            $stmt->execute([$current_user_id, $message, $post_id]);

            $pdo->commit();
        }
        // Redirect to prevent form resubmission, preserving filter
        $art_type = isset($_GET['art_type']) ? urlencode($_GET['art_type']) : '';
        header("Location: home.php" . ($art_type ? "?art_type=$art_type" : ""));
        exit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        $error = "Failed to update like: " . $e->getMessage();
    }
}

// Handle comment submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_comment']) && isset($_POST['post_id']) && isset($_POST['comment'])) {
    $post_id = intval($_POST['post_id']);
    $comment = trim($_POST['comment']);
    
    if (!empty($comment)) {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("INSERT INTO comments (post_id, user_id, comment) VALUES (?, ?, ?)");
            $stmt->execute([$post_id, $current_user_id, $comment]);

            // Insert notification for post owner
            $message = $_SESSION['username'] . " commented on your post: " . substr($comment, 0, 50) . (strlen($comment) > 50 ? '...' : '');
            $stmt = $pdo->prepare("
                INSERT INTO notifications (user_id, type, post_id, actor_id, message)
                SELECT p.user_id, 'comment', p.id, ?, ?
                FROM posts p WHERE p.id = ?
            ");
            $stmt->execute([$current_user_id, $message, $post_id]);

            $pdo->commit();
            // Redirect to prevent form resubmission, preserving filter
            $art_type = isset($_GET['art_type']) ? urlencode($_GET['art_type']) : '';
            header("Location: home.php" . ($art_type ? "?art_type=$art_type" : ""));
            exit();
        } catch (PDOException $e) {
            $pdo->rollBack();
            $error = "Failed to add comment: " . $e->getMessage();
        }
    } else {
        $error = "Comment cannot be empty.";
    }
}

// Handle buy action
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && isset($_POST['post_id'])) {
    $post_id = intval($_POST['post_id']);
    $action = $_POST['action'];
    try {
        if ($action === 'buy') {
            header("Location: checkout.php?post_id=$post_id");
            exit();
        } elseif ($action === 'trade') {
            header("Location: bid.php?post_id=$post_id");
            exit();
        }
    } catch (PDOException $e) {
        $error = "Failed to process $action: " . $e->getMessage();
    }
}

// Handle filter
$art_type_filter = isset($_GET['art_type']) && $_GET['art_type'] !== 'All' ? $_GET['art_type'] : null;
$valid_art_types = ['Paintings', 'Drawings', 'Prints & Reproductions', 'Sculpture & 3D Art', 'Photography'];
if ($art_type_filter && !in_array($art_type_filter, $valid_art_types)) {
    $art_type_filter = null;
    $error = "Invalid art type selected.";
}

// Fetch all posts with user data, like counts, and comment counts
try {
    $query = "
        SELECT p.id, p.image, p.description, p.status, p.price, p.min_trade_value, p.timestamp, p.sold, p.art_type,
               u.username, u.profile_photo,
               (SELECT COUNT(*) FROM likes l WHERE l.post_id = p.id) as like_count,
               (SELECT COUNT(*) FROM likes l WHERE l.post_id = p.id AND l.user_id = $current_user_id) as user_liked,
               (SELECT COUNT(*) FROM comments c WHERE c.post_id = p.id) as comment_count
        FROM posts p
        LEFT JOIN users u ON p.user_id = u.id
    ";
    if ($art_type_filter) {
        $query .= " WHERE p.art_type = ?";
    }
    $query .= " ORDER BY p.timestamp DESC";
    
    $stmt = $pdo->prepare($query);
    if ($art_type_filter) {
        $stmt->execute([$art_type_filter]);
    } else {
        $stmt->execute();
    }
    $all_posts = $stmt->fetchAll();
} catch (PDOException $e) {
    $error = "Failed to load posts: " . $e->getMessage();
}

// Fetch comments for a specific post if requested
$comments = [];
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['view_comments']) && isset($_POST['post_id'])) {
    $post_id = intval($_POST['post_id']);
    try {
        $stmt = $pdo->prepare("
            SELECT c.comment, c.created_at, u.username, u.profile_photo
            FROM comments c
            JOIN users u ON c.user_id = u.id
            WHERE c.post_id = ?
            ORDER BY c.created_at ASC
        ");
        $stmt->execute([$post_id]);
        $comments = $stmt->fetchAll();
    } catch (PDOException $e) {
        $error = "Failed to fetch comments: " . $e->getMessage();
    }
}

// Fetch likers for a specific post if requested
$likers = [];
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['view_likes']) && isset($_POST['post_id'])) {
    $post_id = intval($_POST['post_id']);
    try {
        $stmt = $pdo->prepare("
            SELECT u.username
            FROM likes l
            JOIN users u ON l.user_id = u.id
            WHERE l.post_id = ?
            ORDER BY l.created_at ASC
        ");
        $stmt->execute([$post_id]);
        $likers = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        $error = "Failed to fetch likers: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ConnectSphere - Home</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        body {
            min-height: 100vh;
            background: linear-gradient(45deg, #ff6b6b, #4ecdc4, #45b7d1, #96c93d);
            background-size: 400% 400%;
            animation: gradientShift 15s ease infinite;
            color: #fff;
        }

        @keyframes gradientShift {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }

        .container {
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
        }

        .navbar {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border-radius: 10px;
            padding: 10px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .navbar .logo {
            font-size: 1.5em;
            font-weight: bold;
        }

        .navbar .nav-links a {
            color: #fff;
            text-decoration: none;
            margin-left: 20px;
            font-size: 0.9em;
            transition: color 0.3s ease;
        }

        .navbar .nav-links a:hover {
            color: #4ecdc4;
        }

        .filter-container {
            display: flex;
            align-items: center;
            margin-bottom: 20px;
        }

        .filter-button {
            background: rgba(255, 255, 255, 0.2);
            border: none;
            color: #fff;
            padding: 10px 20px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 1em;
            transition: background 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .filter-button:hover {
            background: rgba(255, 255, 255, 0.3);
        }

        .filter-dropdown {
            position: relative;
            display: none;
        }

        .filter-dropdown.active {
            display: block;
        }

        .filter-options {
            position: absolute;
            top: 100%;
            left: 0;
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border-radius: 8px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.2);
            width: 200px;
            z-index: 100;
            margin-top: 5px;
        }

        .filter-option {
            padding: 10px 15px;
            font-size: 0.9em;
            color: #fff;
            text-decoration: none;
            display: block;
            transition: background 0.3s ease;
        }

        .filter-option:hover {
            background: rgba(255, 255, 255, 0.2);
        }

        .filter-option.selected {
            background: rgba(255, 255, 255, 0.3);
            font-weight: bold;
        }

        .feed {
            display: grid;
            grid-template-columns: 1fr;
            gap: 20px;
        }

        .post {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
            position: relative;
        }

        .post-image-container {
            position: relative;
            width: 100%;
        }

        .post-header {
            position: absolute;
            top: 3px;
            left: 3px;
            display: flex;
            align-items: center;
            background: rgba(0, 0, 0, 0.4);
            border-radius: 14px;
            padding: 3px 6px;
            z-index: 1;
        }

        .post-header a {
            text-decoration: none;
            color: inherit;
        }

        .post-header img {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            margin-right: 5px;
            border: 1px solid rgba(255, 255, 255, 0.5);
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.2);
        }

        .post-header span {
            font-weight: bold;
            font-size: 0.75em;
            color: #fff;
            text-shadow: 0 1px 2px rgba(0, 0, 0, 0.2);
        }

        .post-image {
            width: 100%;
            height: auto;
            display: block;
        }

        .post-art-type {
            padding: 0 10px;
            font-size: 0.85em;
            color: rgba(255, 255, 255, 0.9);
            margin-bottom: 5px;
        }

        .post-caption {
            padding: 10px;
            font-size: 0.9em;
        }

        .post-status {
            padding: 0 10px 10px;
            font-size: 0.9em;
            color: rgba(255, 255, 255, 0.8);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .action-button {
            background: rgba(255, 255, 255, 0.2);
            border: none;
            color: #fff;
            padding: 5px 10px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 0.85em;
            transition: background 0.3s ease;
        }

        .action-button:hover:not(:disabled) {
            background: rgba(255, 255, 255, 0.3);
        }

        .action-button:disabled {
            background: rgba(255, 255, 255, 0.1);
            color: rgba(255, 255, 255, 0.5);
            cursor: not-allowed;
        }

        .post-timestamp {
            padding: 0 10px 10px;
            font-size: 0.8em;
            color: rgba(255, 255, 255, 0.7);
        }

        .interaction-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px;
            border-top: 1px solid rgba(255, 255, 255, 0.2);
        }

        .like-container {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .like-button {
            background: none;
            border: none;
            cursor: pointer;
            padding: 5px;
            font-size: 1.2em;
            color: #fff;
            transition: color 0.3s ease;
        }

        .like-button.liked {
            color: #ff4d4d;
        }

        .like-count, .comment-count {
            font-size: 0.9em;
            color: rgba(255, 255, 255, 0.9);
        }

        .like-count.clickable, .comment-count.clickable {
            cursor: pointer;
            text-decoration: underline;
            color: #4ecdc4;
            transition: color 0.3s ease;
        }

        .like-count.clickable:hover, .comment-count.clickable:hover {
            color: #fff;
        }

        .comment-section {
            padding: 10px;
            border-top: 1px solid rgba(255, 255, 255, 0.2);
        }

        .comment-form {
            display: flex;
            gap: 10px;
            margin-bottom: 10px;
            align-items: center;
        }

        .comment-form textarea {
            flex: 1;
            padding: 8px;
            border-radius: 5px;
            border: 1px solid rgba(255, 255, 255, 0.3);
            background: rgba(255, 255, 255, 0.1);
            color: #fff;
            font-size: 0.9em;
            resize: none;
            height: 40px;
        }

        .comment-form button {
            padding: 8px 15px;
            background: rgba(255, 255, 255, 0.2);
            border: none;
            border-radius: 5px;
            color: #fff;
            cursor: pointer;
            font-size: 0.9em;
        }

        .comment-form button:hover {
            background: rgba(255, 255, 255, 0.3);
        }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            padding: 20px;
            max-width: 400px;
            width: 90%;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.2);
            text-align: center;
            position: relative;
        }

        .close-button {
            position: absolute;
            top: 10px;
            right: 10px;
            background: none;
            border: none;
            color: #fff;
            font-size: 1.2em;
            cursor: pointer;
        }

        .likers-list, .comments-list {
            max-height: 300px;
            overflow-y: auto;
            margin-top: 10px;
            text-align: left;
        }

        .likers-list p, .comments-list .comment {
            font-size: 0.9em;
            color: #fff;
            padding: 5px 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.2);
        }

        .likers-list a, .comments-list a {
            color: #4ecdc4;
            text-decoration: none;
            transition: color 0.3s ease;
        }

        .likers-list a:hover, .comments-list a:hover {
            color: #fff;
            text-decoration: underline;
        }

        .comment {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 8px 0;
        }

        .comment img {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            border: 1px solid rgba(255, 255, 255, 0.5);
        }

        .comment-content {
            flex: 1;
        }

        .comment-content a {
            font-weight: bold;
            font-size: 0.85em;
        }

        .comment-content p {
            font-size: 0.85em;
            color: rgba(255, 255, 255, 0.9);
            margin: 2px 0;
        }

        .comment-timestamp {
            font-size: 0.75em;
            color: rgba(255, 255, 255, 0.7);
        }

        .error {
            color: #ff4d4d;
            background: rgba(0, 0, 0, 0.3);
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 20px;
            font-size: 0.9em;
            text-align: center;
        }

        @media (max-width: 600px) {
            .container {
                padding: 10px;
            }

            .navbar {
                flex-direction: column;
                gap: 10px;
            }

            .navbar .nav-links a {
                margin-left: 10px;
            }

            .filter-button {
                padding: 8px 15px;
                font-size: 0.9em;
            }

            .filter-options {
                width: 180px;
            }

            .filter-option {
                font-size: 0.85em;
                padding: 8px 12px;
            }

            .post-header {
                top: 2px;
                left: 2px;
                padding: 2px 5px;
            }

            .post-header img {
                width: 20px;
                height: 20px;
                margin-right: 4px;
            }

            .post-header span {
                font-size: 0.65em;
            }

            .post-status {
                flex-direction: column;
                align-items: flex-start;
                gap: 5px;
            }

            .action-button {
                font-size: 0.8em;
                padding: 4px 8px;
            }

            .interaction-bar {
                flex-wrap: wrap;
                gap: 5px;
                padding: 8px;
            }

            .like-container {
                gap: 5px;
            }

            .like-button {
                font-size: 1em;
            }

            .like-count, .comment-count {
                font-size: 0.8em;
            }

            .comment-form textarea {
                font-size: 0.8em;
                padding: 6px;
                height: 35px;
            }

            .comment-form button {
                font-size: 0.8em;
                padding: 6px 10px;
            }

            .comment img {
                width: 20px;
                height: 20px;
            }

            .comment-content a {
                font-size: 0.75em;
            }

            .comment-content p {
                font-size: 0.75em;
            }

            .comment-timestamp {
                font-size: 0.65em;
            }

            .modal-content {
                padding: 15px;
                max-width: 300px;
            }

            .likers-list p, .comments-list .comment {
                font-size: 0.8em;
            }

            .likers-list a, .comments-list a {
                font-size: 0.8em;
            }

            .post-art-type {
                font-size: 0.8em;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="navbar">
            <div class="logo">ConnectSphere</div>
            <div class="nav-links">
                <a href="home.php">Home</a>
                <a href="notifications.php">Notifications</a>
                <a href="profile.php">Profile</a>
                <a href="learn.php">Learn</a>
                <a href="index.php">Logout</a>
            </div>
        </div>
        <div class="filter-container">
            <button class="filter-button" onclick="toggleFilterDropdown()">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M4 6h16M7 12h10M10 18h4"/>
                </svg>
                Filter by Art Type<?php echo $art_type_filter ? ': ' . htmlspecialchars($art_type_filter) : ''; ?>
            </button>
            <div class="filter-dropdown" id="filter-dropdown">
                <div class="filter-options">
                    <a href="home.php" class="filter-option <?php echo !$art_type_filter ? 'selected' : ''; ?>">All</a>
                    <a href="home.php?art_type=Paintings" class="filter-option <?php echo $art_type_filter === 'Paintings' ? 'selected' : ''; ?>">Paintings</a>
                    <a href="home.php?art_type=Drawings" class="filter-option <?php echo $art_type_filter === 'Drawings' ? 'selected' : ''; ?>">Drawings</a>
                    <a href="home.php?art_type=Prints+%26+Reproductions" class="filter-option <?php echo $art_type_filter === 'Prints & Reproductions' ? 'selected' : ''; ?>">Prints & Reproductions</a>
                    <a href="home.php?art_type=Sculpture+%26+3D+Art" class="filter-option <?php echo $art_type_filter === 'Sculpture & 3D Art' ? 'selected' : ''; ?>">Sculpture & 3D Art</a>
                    <a href="home.php?art_type=Photography" class="filter-option <?php echo $art_type_filter === 'Photography' ? 'selected' : ''; ?>">Photography</a>
                </div>
            </div>
        </div>
        <?php if (isset($error)): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <div class="feed">
            <?php if (!empty($all_posts)): ?>
                <?php foreach ($all_posts as $post): ?>
                    <div class="post">
                        <div class="post-image-container">
                            <div class="post-header">
                                <a href="<?php echo ($post['username'] === $_SESSION['username']) ? 'profile.php' : 'view_profile.php?username=' . urlencode($post['username']); ?>">
                                    <img src="<?php echo htmlspecialchars($post['profile_photo'] ?? 'https://via.placeholder.com/28'); ?>" alt="User">
                                </a>
                                <a href="<?php echo ($post['username'] === $_SESSION['username']) ? 'profile.php' : 'view_profile.php?username=' . urlencode($post['username']); ?>">
                                    <span><?php echo htmlspecialchars($post['username'] ?? 'Unknown User'); ?></span>
                                </a>
                            </div>
                            <img src="<?php echo htmlspecialchars($post['image']); ?>" alt="Art Post" class="post-image">
                        </div>
                        <?php if ($post['art_type']): ?>
                            <div class="post-art-type">Type: <?php echo htmlspecialchars($post['art_type']); ?></div>
                        <?php endif; ?>
                        <?php if ($post['description']): ?>
                            <div class="post-caption"><?php echo htmlspecialchars($post['description']); ?></div>
                        <?php endif; ?>
                        <?php if ($post['status'] !== 'share'): ?>
                            <div class="post-status">
                                <?php
                                if ($post['status'] == 'sell') {
                                    if ($post['sold']) {
                                        echo 'Sold';
                                    } else {
                                        echo 'For Sale: $' . number_format($post['price'], 2);
                                    }
                                } elseif ($post['status'] == 'trade') {
                                    if ($post['sold']) {
                                        echo 'Sold';
                                    } else {
                                        echo 'For Trade: Minimum Value $' . number_format($post['min_trade_value'], 2);
                                    }
                                }
                                ?>
                                <?php if ($post['username'] !== $_SESSION['username'] && !$post['sold']): ?>
                                    <form method="POST" action="">
                                        <input type="hidden" name="post_id" value="<?php echo $post['id']; ?>">
                                        <button type="submit" name="action" value="<?php echo $post['status'] == 'sell' ? 'buy' : 'trade'; ?>" class="action-button" <?php echo $post['status'] == 'sell' && $post['sold'] ? 'disabled' : ''; ?>">
                                            <?php echo $post['status'] == 'sell' ? 'Buy Now' : 'Bid'; ?>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        <div class="post-timestamp"><?php echo htmlspecialchars($post['timestamp']); ?></div>
                        <div class="interaction-bar">
                            <div class="like-container">
                                <form method="POST" action="">
                                    <input type="hidden" name="post_id" value="<?php echo $post['id']; ?>">
                                    <button type="submit" name="toggle_like" class="like-button <?php echo $post['user_liked'] ? 'liked' : ''; ?>">
                                        <?php echo $post['user_liked'] ? '❤️' : '🤍'; ?>
                                    </button>
                                </form>
                                <?php if ($post['username'] === $_SESSION['username'] && $post['like_count'] > 0): ?>
                                    <form method="POST" action="">
                                        <input type="hidden" name="post_id" value="<?php echo $post['id']; ?>">
                                        <button type="submit" name="view_likes" class="like-count clickable">
                                            <?php echo $post['like_count']; ?> <?php echo $post['like_count'] == 1 ? 'Like' : 'Likes'; ?>
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span class="like-count">
                                        <?php echo $post['like_count']; ?> <?php echo $post['like_count'] == 1 ? 'Like' : 'Likes'; ?>
                                    </span>
                                <?php endif; ?>
                                <?php if ($post['comment_count'] > 0): ?>
                                    <form method="POST" action="">
                                        <input type="hidden" name="post_id" value="<?php echo $post['id']; ?>">
                                        <button type="submit" name="view_comments" class="comment-count clickable">
                                            <?php echo $post['comment_count']; ?> <?php echo $post['comment_count'] == 1 ? 'Comment' : 'Comments'; ?>
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span class="comment-count">
                                        <?php echo $post['comment_count']; ?> <?php echo $post['comment_count'] == 1 ? 'Comment' : 'Comments'; ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="comment-section">
                            <form method="POST" action="" class="comment-form">
                                <input type="hidden" name="post_id" value="<?php echo $post['id']; ?>">
                                <textarea name="comment" rows="2" placeholder="Add a comment..." maxlength="500"></textarea>
                                <button type="submit" name="add_comment">Post</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p>No posts available.</p>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!empty($likers)): ?>
        <div class="modal" id="likers-modal" style="display: flex;">
            <div class="modal-content">
                <button class="close-button" onclick="closeModal('likers-modal')">×</button>
                <h2>Users Who Liked This Post</h2>
                <div class="likers-list">
                    <?php if ($likers): ?>
                        <?php foreach ($likers as $liker): ?>
                            <p>
                                <a href="<?php echo ($liker === $_SESSION['username']) ? 'profile.php' : 'view_profile.php?username=' . urlencode($liker); ?>">
                                    <?php echo htmlspecialchars($liker); ?>
                                </a>
                            </p>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p>No likes yet.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($comments)): ?>
        <div class="modal" id="comments-modal" style="display: flex;">
            <div class="modal-content">
                <button class="close-button" onclick="closeModal('comments-modal')">×</button>
                <h2>Comments on This Post</h2>
                <div class="comments-list">
                    <?php if ($comments): ?>
                        <?php foreach ($comments as $comment): ?>
                            <div class="comment">
                                <a href="<?php echo ($comment['username'] === $_SESSION['username']) ? 'profile.php' : 'view_profile.php?username=' . urlencode($comment['username']); ?>">
                                    <img src="<?php echo htmlspecialchars($comment['profile_photo'] ?? 'https://via.placeholder.com/24'); ?>" alt="User">
                                </a>
                                <div class="comment-content">
                                    <a href="<?php echo ($comment['username'] === $_SESSION['username']) ? 'profile.php' : 'view_profile.php?username=' . urlencode($comment['username']); ?>">
                                        <?php echo htmlspecialchars($comment['username']); ?>
                                    </a>
                                    <p><?php echo htmlspecialchars($comment['comment']); ?></p>
                                    <div class="comment-timestamp"><?php echo htmlspecialchars($comment['created_at']); ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p>No comments yet.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <script>
        function toggleFilterDropdown() {
            const dropdown = document.getElementById('filter-dropdown');
            dropdown.classList.toggle('active');
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        window.addEventListener('click', (event) => {
            const dropdown = document.getElementById('filter-dropdown');
            const filterButton = document.querySelector('.filter-button');
            if (!dropdown.contains(event.target) && !filterButton.contains(event.target)) {
                dropdown.classList.remove('active');
            }
            if (event.target === document.getElementById('likers-modal')) {
                closeModal('likers-modal');
            }
            if (event.target === document.getElementById('comments-modal')) {
                closeModal('comments-modal');
            }
        });
    </script>
</body>
</html>