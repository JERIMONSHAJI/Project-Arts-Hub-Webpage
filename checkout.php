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
    if ($current_user_id <= 0) {
        header("Location: home.php");
        exit();
    }
} catch (PDOException $e) {
    $error = "Failed to fetch user data: " . $e->getMessage();
}

// Check for post_id in query string
$post_id = isset($_GET['post_id']) ? intval($_GET['post_id']) : 0;
if ($post_id <= 0) {
    header("Location: home.php");
    exit();
}

// Fetch post details
try {
    $stmt = $pdo->prepare("
        SELECT p.id, p.description, p.price, p.sold, u.username
        FROM posts p
        LEFT JOIN users u ON p.user_id = u.id
        WHERE p.id = ? AND p.status = 'sell'
    ");
    $stmt->execute([$post_id]);
    $post = $stmt->fetch();
    if (!$post) {
        header("Location: home.php");
        exit();
    }
    if ($post['sold']) {
        $error = "This item has already been sold.";
    }
} catch (PDOException $e) {
    $error = "Failed to fetch post details: " . $e->getMessage();
}

// Handle address form submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['submit_address']) && !$post['sold']) {
    $street = trim($_POST['street'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $state = trim($_POST['state'] ?? '');
    $zip_code = trim($_POST['zip_code'] ?? '');
    $country = trim($_POST['country'] ?? '');

    // Basic validation
    $errors = [];
    if (empty($street)) $errors[] = "Street address is required.";
    if (empty($city)) $errors[] = "City is required.";
    if (empty($state)) $errors[] = "State is required.";
    if (empty($zip_code)) $errors[] = "Zip code is required.";
    if (empty($country)) $errors[] = "Country is required.";

    if (empty($errors)) {
        try {
            // Start transaction
            $pdo->beginTransaction();

            // Insert address into orders table
            $stmt = $pdo->prepare("
                INSERT INTO orders (post_id, user_id, street, city, state, zip_code, country)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$post_id, $current_user_id, $street, $city, $state, $zip_code, $country]);

            // Mark post as sold
            $stmt = $pdo->prepare("UPDATE posts SET sold = 1 WHERE id = ?");
            $stmt->execute([$post_id]);

            // Insert notification for post owner with address
            $address = htmlspecialchars("$street, $city, $state $zip_code, $country");
            $message = $_SESSION['username'] . " purchased your post: " . ($post['description'] ?: 'Post ID ' . $post['id']) . " for $" . number_format($post['price'], 2) . ". Shipping address: $address";
            $stmt = $pdo->prepare("
                INSERT INTO notifications (user_id, type, post_id, actor_id, message)
                SELECT p.user_id, 'purchase', p.id, ?, ?
                FROM posts p WHERE p.id = ?
            ");
            $stmt->execute([$current_user_id, $message, $post_id]);

            // Commit transaction
            $pdo->commit();

            $success = "Address submitted successfully. The item has been marked as sold.";
            // Redirect to home to prevent resubmission
            header("Location: home.php");
            exit();
        } catch (PDOException $e) {
            // Rollback transaction on error
            $pdo->rollBack();
            $errors[] = "Failed to save address or mark item as sold: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ConnectSphere - Checkout</title>
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

        .checkout-form {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
        }

        .checkout-form h2 {
            margin-bottom: 15px;
            font-size: 1.5em;
            text-align: center;
        }

        .item-details {
            margin-bottom: 20px;
            font-size: 0.9em;
        }

        .item-details p {
            margin: 5px 0;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-size: 0.9em;
        }

        .form-group input,
        .form-group textarea {
            width: 100%;
            padding: 8px;
            border: none;
            border-radius: 5px;
            background: rgba(255, 255, 255, 0.2);
            color: #fff;
            font-size: 0.9em;
        }

        .form-group textarea {
            resize: vertical;
            min-height: 80px;
        }

        .submit-button {
            background: rgba(255, 255, 255, 0.2);
            border: none;
            color: #fff;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 0.9em;
            transition: background 0.3s ease;
            width: 100%;
        }

        .submit-button:hover {
            background: rgba(255, 255, 255, 0.3);
        }

        .submit-button:disabled {
            background: rgba(255, 255, 255, 0.1);
            color: rgba(255, 255, 255, 0.5);
            cursor: not-allowed;
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

        .success {
            color: #4ecdc4;
            background: rgba(0, 0, 0, 0.3);
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 20px;
            font-size: 0.9em;
            text-align: center;
        }

        /* Responsive Design */
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

            .checkout-form {
                padding: 15px;
            }

            .checkout-form h2 {
                font-size: 1.3em;
            }

            .form-group label,
            .form-group input,
            .form-group textarea,
            .submit-button {
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
        <?php if (isset($error)): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if (isset($success)): ?>
            <div class="success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        <?php if (isset($errors) && !empty($errors)): ?>
            <div class="error">
                <?php foreach ($errors as $err): ?>
                    <p><?php echo htmlspecialchars($err); ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php if (!$post['sold']): ?>
            <div class="checkout-form">
                <h2>Checkout</h2>
                <div class="item-details">
                    <p><strong>Item:</strong> <?php echo htmlspecialchars($post['description'] ?: 'Post ID ' . $post['id']); ?></p>
                    <p><strong>Price:</strong> $<?php echo number_format($post['price'], 2); ?></p>
                    <p><strong>Seller:</strong> <?php echo htmlspecialchars($post['username']); ?></p>
                </div>
                <form method="POST" action="" onsubmit="return validateForm()">
                    <div class="form-group">
                        <label for="street">Street Address</label>
                        <textarea id="street" name="street" required></textarea>
                    </div>
                    <div class="form-group">
                        <label for="city">City</label>
                        <input type="text" id="city" name="city" required>
                    </div>
                    <div class="form-group">
                        <label for="state">State/Province</label>
                        <input type="text" id="state" name="state" required>
                    </div>
                    <div class="form-group">
                        <label for="zip_code">Zip/Postal Code</label>
                        <input type="text" id="zip_code" name="zip_code" required>
                    </div>
                    <div class="form-group">
                        <label for="country">Country</label>
                        <input type="text" id="country" name="country" required>
                    </div>
                    <button type="submit" name="submit_address" class="submit-button">Submit Address</button>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <script>
        function validateForm() {
            const street = document.getElementById('street').value.trim();
            const city = document.getElementById('city').value.trim();
            const state = document.getElementById('state').value.trim();
            const zipCode = document.getElementById('zip_code').value.trim();
            const country = document.getElementById('country').value.trim();

            if (!street || !city || !state || !zipCode || !country) {
                alert('Please fill in all required fields.');
                return false;
            }
            return true;
        }
    </script>
</body>
</html>