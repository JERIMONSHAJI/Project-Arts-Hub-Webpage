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

// Initialize variables
$error = '';
$target_username = isset($_GET['username']) ? trim($_GET['username']) : '';
$user = null;
$art_posts = [];
$user_videos = [];

if (empty($target_username)) {
    $error = "No user specified.";
} else {
    // Redirect to profile.php if viewing own profile
    if ($target_username === $_SESSION['username']) {
        header("Location: profile.php");
        exit();
    }
    // Fetch user data
    try {
        $stmt = $pdo->prepare("SELECT id, username, bio, profile_photo FROM users WHERE username = ?");
        $stmt->execute([$target_username]);
        $user = $stmt->fetch();
        if (!$user) {
            $error = "User not found.";
        } else {
            $user_id = $user['id'];
            // Fetch user's posts
            try {
                $stmt = $pdo->prepare("
                    SELECT id, image, description, status, price, min_trade_value, timestamp
                    FROM posts
                    WHERE user_id = ?
                    ORDER BY timestamp DESC
                ");
                $stmt->execute([$user_id]);
                $art_posts = $stmt->fetchAll();
            } catch (PDOException $e) {
                $error = "Failed to fetch posts: " . $e->getMessage();
            }

            // Fetch user's videos
            try {
                $stmt = $pdo->prepare("
                    SELECT id, video, title, description, timestamp
                    FROM videos
                    WHERE user_id = ?
                    ORDER BY timestamp DESC
                ");
                $stmt->execute([$user_id]);
                $user_videos = $stmt->fetchAll();
            } catch (PDOException $e) {
                $error = "Failed to fetch videos: " . $e->getMessage();
            }
        }
    } catch (PDOException $e) {
        $error = "Failed to fetch user data: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ConnectSphere - View Profile</title>
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

        .profile-container {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.2);
            text-align: center;
            margin-bottom: 30px;
        }

        .profile-container h2 {
            font-size: 2em;
            margin-bottom: 20px;
            text-shadow: 0 2px 5px rgba(0, 0, 0, 0.3);
        }

        .profile-photo {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid rgba(255, 255, 255, 0.5);
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.2);
            margin-bottom: 20px;
        }

        .bio {
            font-size: 1em;
            margin-bottom: 20px;
            color: rgba(255, 255, 255, 0.9);
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

        .art-posts-container,
        .video-posts-container {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 20px;
        }

        .art-post,
        .video-post {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 10px;
            padding: 15px;
            text-align: center;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.2);
            transition: transform 0.3s ease;
        }

        .art-post:hover,
        .video-post:hover {
            transform: scale(1.02);
        }

        .art-post a,
        .video-post a {
            text-decoration: none;
            color: inherit;
            display: block;
        }

        .art-post img,
        .video-post video {
            max-width: 100%;
            border-radius: 8px;
            margin-bottom: 10px;
        }

        .art-post p,
        .video-post p {
            font-size: 0.9em;
            margin-bottom: 5px;
        }

        .art-post .timestamp,
        .video-post .timestamp {
            font-size: 0.8em;
            color: rgba(255, 255, 255, 0.7);
        }

        @media (max-width: 600px) {
            .container {
                padding: 10px;
            }

            .profile-container {
                padding: 20px;
            }

            .navbar {
                flex-direction: column;
                gap: 10px;
            }

            .navbar .nav-links a {
                margin-left: 10px;
            }

            .art-posts-container,
            .video-posts-container {
                grid-template-columns: 1fr;
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
                <a href="profile.php">Profile</a>
                <a href="learn.php">Learn</a>
                <a href="index.php">Logout</a>
            </div>
        </div>
        <?php if ($error): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php elseif ($user): ?>
            <div class="profile-container">
                <h2><?php echo htmlspecialchars($user['username']); ?>'s Profile</h2>
                <?php if ($user['profile_photo']): ?>
                    <img src="<?php echo htmlspecialchars($user['profile_photo']); ?>" alt="Profile Photo" class="profile-photo">
                <?php else: ?>
                    <img src="https://via.placeholder.com/150" alt="Profile Photo" class="profile-photo">
                <?php endif; ?>
                <?php if ($user['bio']): ?>
                    <div class="bio"><?php echo htmlspecialchars($user['bio']); ?></div>
                <?php else: ?>
                    <div class="bio">No bio available.</div>
                <?php endif; ?>
            </div>
            <div class="art-posts-container">
                <h2><?php echo htmlspecialchars($user['username']); ?>'s Art Posts</h2>
                <?php if (!empty($art_posts)): ?>
                    <?php foreach ($art_posts as $post): ?>
                        <div class="art-post">
                            <a href="view_post.php?type=art&id=<?php echo $post['id']; ?>">
                                <img src="<?php echo htmlspecialchars($post['image']); ?>" alt="Art Post">
                                <?php if ($post['description']): ?>
                                    <p><?php echo htmlspecialchars($post['description']); ?></p>
                                <?php endif; ?>
                                <?php if ($post['status'] == 'sell'): ?>
                                    <p>For Sale: $<?php echo number_format($post['price'], 2); ?></p>
                                <?php elseif ($post['status'] == 'trade'): ?>
                                    <p>For Trade: Minimum Value $<?php echo number_format($post['min_trade_value'], 2); ?></p>
                                <?php else: ?>
                                    <p>Shared</p>
                                <?php endif; ?>
                                <p class="timestamp"><?php echo htmlspecialchars($post['timestamp']); ?></p>
                            </a>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p>No art posts yet.</p>
                <?php endif; ?>
            </div>
            <div class="video-posts-container">
                <h2><?php echo htmlspecialchars($user['username']); ?>'s Videos</h2>
                <?php if (!empty($user_videos)): ?>
                    <?php foreach ($user_videos as $video): ?>
                        <div class="video-post">
                            <a href="view_post.php?type=video&id=<?php echo $video['id']; ?>">
                                <video controls>
                                    <source src="<?php echo htmlspecialchars($video['video']); ?>" type="video/mp4">
                                    Your browser does not support the video tag.
                                </video>
                                <p><strong><?php echo htmlspecialchars($video['title']); ?></strong></p>
                                <?php if ($video['description']): ?>
                                    <p><?php echo htmlspecialchars($video['description']); ?></p>
                                <?php endif; ?>
                                <p class="timestamp"><?php echo htmlspecialchars($video['timestamp']); ?></p>
                            </a>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p>No videos yet.</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>