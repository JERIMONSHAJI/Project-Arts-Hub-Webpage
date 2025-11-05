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
$username = $_SESSION['username'];
$error = '';
$success = '';
$post_id = isset($_GET['post_id']) ? intval($_GET['post_id']) : 0;

// Fetch user ID
try {
    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    if (!$user) {
        $error = "User not found.";
        exit();
    }
    $user_id = $user['id'];
} catch (PDOException $e) {
    $error = "Failed to fetch user data: " . $e->getMessage();
    exit();
}

// Validate post_id
if ($post_id <= 0) {
    $error = "Invalid post ID.";
} else {
    // Check if post exists and is sold
    try {
        $stmt = $pdo->prepare("SELECT user_id FROM posts WHERE id = ? AND sold = 1");
        $stmt->execute([$post_id]);
        $post = $stmt->fetch();
        if (!$post) {
            $error = "Post not found or not sold.";
        } else {
            $post_owner_id = $post['user_id'];
        }
    } catch (PDOException $e) {
        $error = "Failed to validate post: " . $e->getMessage();
    }
}

// Handle address submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['submit_address']) && !$error) {
    $address = trim($_POST['address']);
    if (empty($address)) {
        $error = "Address cannot be empty.";
    } else {
        try {
            // Check if address already submitted for this post by this user
            $stmt = $pdo->prepare("SELECT id FROM bid_addresses WHERE post_id = ? AND user_id = ?");
            $stmt->execute([$post_id, $user_id]);
            if ($stmt->fetch()) {
                $error = "You have already submitted an address for this post.";
            } else {
                // Insert address
                $stmt = $pdo->prepare("INSERT INTO bid_addresses (post_id, user_id, address) VALUES (?, ?, ?)");
                $stmt->execute([$post_id, $user_id, $address]);
                
                // Notify the post owner with the bidder's username and submitted address
                $message = htmlspecialchars($username) . " has submitted their address for your post: " . htmlspecialchars($address);
                $stmt = $pdo->prepare("
                    INSERT INTO notifications (user_id, type, post_id, actor_id, message, is_read)
                    VALUES (?, 'address_submitted', ?, ?, ?, 0)
                ");
                $stmt->execute([$post_owner_id, $post_id, $user_id, $message]);
                
                $success = "Address submitted successfully!";
            }
        } catch (PDOException $e) {
            $error = "Failed to submit address: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ConnectSphere - Submit Address</title>
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
            max-width: 600px;
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

        .form-container {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .form-group {
            margin-bottom: 20px;
            text-align: left;
        }

        .form-group label {
            display: block;
            font-size: 0.9em;
            margin-bottom: 8px;
            text-shadow: 0 1px 3px rgba(0, 0, 0, 0.2);
        }

        .form-group textarea {
            width: 100%;
            padding: 12px;
            border: none;
            border-radius: 8px;
            background: rgba(255, 255, 255, 0.2);
            color: #fff;
            font-size: 1em;
            transition: all 0.3s ease;
            box-shadow: inset 0 2px 5px rgba(0, 0, 0, 0.1);
            resize: vertical;
            min-height: 100px;
        }

        .form-group textarea:focus {
            outline: none;
            background: rgba(255, 255, 255, 0.3);
            transform: scale(1.02);
            box-shadow: 0 0 10px rgba(255, 255, 255, 0.5);
        }

        .form-group textarea::placeholder {
            color: rgba(255, 255, 255, 0.7);
        }

        button {
            width: 100%;
            padding: 12px;
            border: none;
            border-radius: 8px;
            background: linear-gradient(45deg, #ff6b6b, #4ecdc4);
            color: #fff;
            font-size: 1.1em;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
        }

        button:hover {
            background: linear-gradient(45deg, #4ecdc4, #ff6b6b);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.3);
        }

        button:active {
            transform: translateY(0);
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
        }

        .alert-error {
            color: #ff4d4d;
            font-size: 1em;
            text-align: center;
            margin-bottom: 20px;
        }

        .alert-success {
            color: #4ecdc4;
            font-size: 1em;
            text-align: center;
            margin-bottom: 20px;
        }

        .alert-modal {
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

        .alert-modal-content {
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

        .alert-close-button {
            padding: 10px;
            border: none;
            border-radius: 8px;
            background: linear-gradient(45deg, #ff6b6b, #4ecdc4);
            color: #fff;
            font-size: 1em;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
            width: 100%;
        }

        .alert-close-button:hover {
            background: linear-gradient(45deg, #4ecdc4, #ff6b6b);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.3);
        }

        .alert-close-button:active {
            transform: translateY(0);
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
        }

        @media (max-width: 600px) {
            .container {
                padding: 10px;
            }

            .form-container {
                padding: 20px;
            }

            .navbar {
                flex-direction: column;
                gap: 10px;
            }

            .navbar .nav-links a {
                margin-left: 10px;
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
        <div class="form-container">
            <h2>Submit Your Address</h2>
            <?php if ($success): ?>
                <div class="alert-success"><?php echo htmlspecialchars($success); ?></div>
            <?php elseif ($error): ?>
                <div class="alert-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <?php if (!$error): ?>
                <form method="POST" action="">
                    <input type="hidden" name="post_id" value="<?php echo $post_id; ?>">
                    <input type="hidden" name="submit_address" value="1">
                    <div class="form-group">
                        <label for="address">Address</label>
                        <textarea id="address" name="address" placeholder="Enter your shipping address" required></textarea>
                    </div>
                    <button type="submit">Submit Address</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <div class="alert-modal" id="alert-modal">
        <div class="alert-modal-content">
            <?php if ($success): ?>
                <div class="alert-success"><?php echo htmlspecialchars($success); ?></div>
            <?php elseif ($error): ?>
                <div class="alert-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <button class="alert-close-button" onclick="closeAlertModal()">Close</button>
        </div>
    </div>

    <script>
        function closeAlertModal() {
            document.getElementById('alert-modal').style.display = 'none';
        }

        window.addEventListener('load', () => {
            <?php if ($success || $error): ?>
                document.getElementById('alert-modal').style.display = 'flex';
            <?php endif; ?>
        });

        window.addEventListener('click', (event) => {
            if (event.target === document.getElementById('alert-modal')) {
                closeAlertModal();
            }
        });
    </script>
</body>
</html>