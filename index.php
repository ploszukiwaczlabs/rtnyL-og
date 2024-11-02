<?php
$client_id = 'your cliend id';
$client_secret = 'your client secret';
$redirect_uri = 'redirect uri/rtnyl instance uri';
function generateToken($length = 32) {
    return bin2hex(random_bytes($length / 2));
}

function checkRateLimit($user, $action, $limit, $time_frame) {
    $rate_limit_dir = __DIR__ . '/rate_limits';
    if (!is_dir($rate_limit_dir)) {
        mkdir($rate_limit_dir, 0777, true);
    }

    $rate_limit_file = "$rate_limit_dir/rate_limit_{$user}_{$action}.json";
    if (!file_exists($rate_limit_file)) {
        file_put_contents($rate_limit_file, json_encode([]));
    }

    $rate_limit_data = json_decode(file_get_contents($rate_limit_file), true);
    $current_time = time();
    $rate_limit_data = array_filter($rate_limit_data, function($timestamp) use ($current_time, $time_frame) {
        return ($current_time - $timestamp) < $time_frame;
    });

    if (count($rate_limit_data) >= $limit) {
        return false;
    }

    $rate_limit_data[] = $current_time;
    file_put_contents($rate_limit_file, json_encode($rate_limit_data));
    return true;
}

$accounts_file = 'directory to accounts.json (make it secure)';
$posts_file = 'directory to posts.json (make it secure)';

$accounts = json_decode(file_get_contents($accounts_file), true);
$posts = json_decode(file_get_contents($posts_file), true);

$current_user = null;
if (isset($_GET['code'])) {
    $token_response = file_get_contents("https://discord.com/api/oauth2/token", false, stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => 'Content-Type: application/x-www-form-urlencoded',
            'content' => http_build_query([
                'client_id' => $client_id,
                'client_secret' => $client_secret,
                'grant_type' => 'authorization_code',
                'code' => $_GET['code'],
                'redirect_uri' => $redirect_uri
            ])
        ]
    ]));
    $token = json_decode($token_response, true)['access_token'];

    $user_response = file_get_contents('https://discord.com/api/users/@me', false, stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => 'Authorization: Bearer ' . $token
        ]
    ]));
    $user = json_decode($user_response, true);

    $username = $user['username'];
    $discriminator = $user['discriminator'];
    $display_name = ucfirst(strtolower($username));

    if (!isset($accounts[$username])) {
        $accounts[$username] = [
            'display_name' => $display_name,
            'bio' => '',
            'profile_picture' => 'https://cdn.discordapp.com/avatars/' . $user['id'] . '/' . $user['avatar'] . '.png',
            'verified' => false,
            'donator' => false,
            'developer' => false,
            'employee' => false,
            'tokens' => [],
            'following' => [],
            'followers' => []
        ];
    }

    $token = generateToken();
    $accounts[$username]['tokens'][] = $token;
    setcookie('id', $token, time() + 3600 * 24 * 30, "/");

    file_put_contents($accounts_file, json_encode($accounts));

    header('Location: /');
    exit;
}

if (isset($_GET['logout'])) {
    setcookie('id', '', time() - 3600, "/");
    header('Location: /');
    exit;
}

if (isset($_COOKIE['id'])) {
    foreach ($accounts as $username => $account) {
        if (in_array($_COOKIE['id'], $account['tokens'])) {
            if (isset($account['banned']) && $account['banned']) {
                $current_user = null;
                break;
            }
            $current_user = $username;
            break;
        }
    }
}

// Handle new post submission and replies
if ($current_user && isset($_POST['content'])) {
    // Determine if it's a reply or a new post
    $is_reply = isset($_POST['replying_to']) && !empty($_POST['replying_to']);
    
    if ($is_reply) {
        $action = 'reply_post';
        $replying_to = $_POST['replying_to'];
    } else {
        $action = 'new_post';
        $replying_to = null;
    }
    
    if (!checkRateLimit($current_user, $action, 5, 60)) {
        echo '<script>alert("An error occurred");</script>';
        die('Please wait before you do that action again.');
    }

    function containsOnlyValidCharacters($string) {
        // Prüfen, ob der String nur reguläre und lesbare Zeichen enthält.
        // Dies schließt Buchstaben, Zahlen, Satzzeichen und typische Unicode-Zeichen ein.
        return preg_match('/^[\p{L}\p{N}\p{P}\p{S}\p{Zs}\p{M}]*$/u', $string);
    }

    $content = substr($_POST['content'], 0, 280);

    if (containsOnlyValidCharacters($content)) {
        $new_post = [
            'id' => uniqid(),
            'username' => $current_user,
            'display_name' => $accounts[$current_user]['display_name'],
            'profile_picture' => $accounts[$current_user]['profile_picture'],
            'content' => $content,
            'timestamp' => time(),
            'likes' => 0,
            'replies' => [],
            'replying_to' => $replying_to,
            'image_url' => isset($_POST['image_url']) && preg_match('/\.(jpg|jpeg|png|gif|bmp)$/i', $_POST['image_url']) ? $_POST['image_url'] : null
        ];
        $posts[$new_post['id']] = $new_post;
    } else {
        // Fehlerbehandlung, wenn ungültige Zeichen gefunden wurden
        echo "Error: Your tnyL contains invalid characters. Please re-create your tnyL with valid characters!";
    }



    // If it's a reply, add the reply ID to the original post
    if ($is_reply) {
        $posts[$replying_to]['replies'][] = $new_post['id'];
    }

    file_put_contents($posts_file, json_encode($posts));

    header('Location: /');
    exit;
}


// Handle post deletion
if ($current_user && isset($_GET['delete'])) {
    if (!checkRateLimit($current_user, 'delete_post', 5, 60)) {
        echo '<script>alert("An error occurred");</script>';
        die('Please wait before you do that action again.');
    }

    $post_id = $_GET['delete'];

    // Recursive function to delete a post and its replies
    function deletePostAndReplies($post_id, &$posts) {
        // If the post has replies, delete them first
        if (isset($posts[$post_id]['replies']) && !empty($posts[$post_id]['replies'])) {
            foreach ($posts[$post_id]['replies'] as $reply_id) {
                deletePostAndReplies($reply_id, $posts);  // Recursive call
            }
        }

        // If the post is a reply, remove it from the parent's replies array
        if ($posts[$post_id]['replying_to']) {
            $parent_id = $posts[$post_id]['replying_to'];
            $posts[$parent_id]['replies'] = array_diff($posts[$parent_id]['replies'], [$post_id]);
        }

        // Finally, delete the post itself
        unset($posts[$post_id]);
    }

    if (isset($posts[$post_id]) && ($posts[$post_id]['username'] == $current_user || $current_user == "faelixyz")) {
        deletePostAndReplies($post_id, $posts);
        file_put_contents($posts_file, json_encode($posts));
    }

    header('Location: /');
    exit;
}


// Handle following a user
if ($current_user && isset($_GET['follow'])) {
    if (!checkRateLimit($current_user, 'follow_unfollow', 3, 60)) {
        echo '<script>alert("An error occurred");</script>';
        die('Please wait before you do that action again.');
    }

    $user_to_follow = $_GET['follow'];
    if (isset($accounts[$user_to_follow]) && $current_user != $user_to_follow) {
        if (!in_array($user_to_follow, $accounts[$current_user]['following'])) {
            $accounts[$current_user]['following'][] = $user_to_follow;
            $accounts[$user_to_follow]['followers'][] = $current_user;
            file_put_contents($accounts_file, json_encode($accounts));
        }
    }
    header('Location: /?profile=' . $user_to_follow);
    exit;
}

// Handle unfollowing a user
if ($current_user && isset($_GET['unfollow'])) {
    if (!checkRateLimit($current_user, 'follow_unfollow', 3, 60)) {
        echo '<script>alert("An error occurred");</script>';
        die('Please wait before you do that action again.');
    }

    $user_to_unfollow = $_GET['unfollow'];
    if (isset($accounts[$user_to_unfollow]) && $current_user != $user_to_unfollow) {
        if (in_array($user_to_unfollow, $accounts[$current_user]['following'])) {
            $accounts[$current_user]['following'] = array_diff($accounts[$current_user]['following'], [$user_to_unfollow]);
            $accounts[$user_to_unfollow]['followers'] = array_diff($accounts[$user_to_unfollow]['followers'], [$current_user]);
            file_put_contents($accounts_file, json_encode($accounts));
        }
    }
    header('Location: /?profile=' . $user_to_unfollow);
    exit;
}

// Handle post liking/unliking
if ($current_user && isset($_GET['like'])) {
    if (!checkRateLimit($current_user, 'like_unlike', 5, 60)) {
        echo '<script>alert("An error occurred");</script>';
        die('Please wait before you do that action again.');
    }

    $post_id = $_GET['like'];
    if (isset($posts[$post_id])) {
        $liked_by = isset($posts[$post_id]['liked_by']) ? $posts[$post_id]['liked_by'] : [];
        if (in_array($current_user, $liked_by)) {
            $posts[$post_id]['likes']--;
            $liked_by = array_diff($liked_by, [$current_user]);
        } else {
            $posts[$post_id]['likes']++;
            $liked_by[] = $current_user;
        }
        $posts[$post_id]['liked_by'] = $liked_by;
        file_put_contents($posts_file, json_encode($posts));
    }

    header('Location: /');
    exit;
}
// Handle profile editing
if ($current_user && isset($_POST['edit_profile'])) {
    if (!checkRateLimit($current_user, 'edit_profile', 3, 60)) {
        echo '<script>alert("An error occurred");</script>';
        die('Please wait before you do that action again.');
    }

    if (isset($_POST['display_name'])) {
        $accounts[$current_user]['display_name'] = substr($_POST['display_name'], 0, 60);
    }
    if (isset($_POST['bio'])) {
        $accounts[$current_user]['bio'] = substr($_POST['bio'], 0, 60);
    }

    file_put_contents($accounts_file, json_encode($accounts));

    header('Location: /?profile=' . $current_user);
    exit;
}

// Handle banning a user (admin action)
if ($current_user && isset($_POST['ban_user']) && isset($_POST['user_to_ban'])) {
    if (!checkRateLimit($current_user, 'ban_user', 1, 300)) {
        echo '<script>alert("Ein Fehler ist aufgetreten");</script>';
        die('Rate limit exceeded for banning users.');
    }

    $user_to_ban = $_POST['user_to_ban'];
    if (isset($accounts[$user_to_ban])) {
        $accounts[$user_to_ban]['banned'] = true;
        file_put_contents($accounts_file, json_encode($accounts));
    }
    header('Location: /');
    exit;
}

// Handle account deletion
if ($current_user && isset($_POST['delete_account']) && isset($_POST['confirm_delete']) && $_POST['confirm_delete'] == 'yes') {
    if (!checkRateLimit($current_user, 'delete_account', 1, 86400)) {
        echo '<script>alert("An error occurred");</script>';
        die('Please wait before you do that action again.');
    }

    unset($accounts[$current_user]);
    file_put_contents($accounts_file, json_encode($accounts));

    setcookie('id', '', time() - 3600, "/");
    header('Location: /');
    exit;
}

// Select suggested posts (excluding replies)
$suggested_posts = [];
if (!empty($posts)) {
    $post_ids = array_filter(array_keys($posts), function($post_id) use ($posts) {
        $replying_to = $posts[$post_id]['replying_to'];
        return $replying_to === null || $replying_to === '';
    });

    if (count($post_ids) > 0) {
        $suggested_posts = array_rand($post_ids, min(5, count($post_ids)));
        if (!is_array($suggested_posts)) {
            $suggested_posts = [$suggested_posts];
        }
    }
}

function formatTime($timestamp) {
    $dt = new DateTime("@$timestamp");
    $dt->setTimezone(new DateTimeZone('Europe/Berlin')); // UTC+2
    return $dt->format('d.m.Y H:i:s');
}
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>rtnyL</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .modal {
            display: none;
            position: fixed;
            z-index: 50;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.4);
        }
        .modal-content {
            background-color: #1f2937;
            margin: 15% auto;
            padding: 20px;
            border: 1px solid #888;
            width: 80%;
            max-height: 80%;
            overflow-y: auto;
            color: white;
        }
        .close {
            color: #ccc;
            float: right;
            font-size: 28px;
            font-weight: bold;
        }
        .close:hover,
        .close:focus {
            color: white;
            text-decoration: none;
            cursor: pointer;
        }
    </style>
</head>
<body class="bg-gray-900 text-white">
    <div class="container mx-auto p-4">
        <?php if ($current_user === null && isset($_COOKIE['id']) && isset($accounts[$_COOKIE['id']]) && $accounts[$_COOKIE['id']]['banned']): ?>
            <div class="fixed inset-0 bg-black bg-opacity-75 flex items-center justify-center">
            <div class="bg-gray-800 p-8 rounded-lg shadow-lg text-center">
            <h2 class="text-2xl font-bold mb-4">Your Account is gone</h2>
            <p class="mb-4">Contact Support.</p>
            <a href="https://discord.com/" class="text-blue-500">Support</a> <!-- this wont be shown so i put random shit here lmao !-->
        </div>
    </div>
<?php endif; ?>

        <div class="flex justify-between items-center">
            <h1 class="text-3xl font-bold">rtnyL</h1>
            <div>
                <?php if ($current_user): ?>
                    <span class="mr-4">Logged in as: <?php echo htmlspecialchars($accounts[$current_user]['display_name']); ?> (@<?php echo htmlspecialchars($current_user); ?>)</span>
                    <a href="/?logout" class="text-blue-500">Logout</a>
                <?php else: ?>
                    <a href="https://discord.com/api/oauth2/authorize?client_id=<?php echo $client_id; ?>&redirect_uri=<?php echo urlencode($redirect_uri); ?>&response_type=code&scope=identify%20email" class="text-blue-500">Login with Discord</a><br>
                    <a href="login2.php" class="text-blue-500">Login with Username and Password</a>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($current_user): ?>
            <div class="mt-4 flex">
                <div class="w-1/4 bg-gray-800 p-4 rounded-lg shadow">
                    <a href="/" class="block text-blue-400 mb-2">Home</a>
                    <a href="/?profile=<?php echo $current_user; ?>" class="block text-blue-400 mb-2">Profile</a>
                    <a href="/?settings" class="block text-blue-400 mb-2">Settings</a>
            		<a href="/donate.php" class="block text-blue-400 mb-2">Donate</a>
            		<a href="/other-pages.html" class="block text-blue-400 mb-2">Important links</a>
                </div>
                <div class="w-3/4 ml-4">
                    <?php if (isset($_GET['profile'])): ?>
                    <?php
                    $profile_user = $_GET['profile'];
                    if (isset($accounts[$profile_user])):
                    ?>
                        <div class="mt-4 bg-gray-800 p-4 rounded-lg shadow">
                            <h2 class="text-xl font-bold mb-4"><?php echo htmlspecialchars($accounts[$profile_user]['display_name']); ?>s Profile</h2>
                            <img src="<?php echo htmlspecialchars($accounts[$profile_user]['profile_picture']); ?>" alt="image" class="w-16 h-16 rounded-full mb-4">
                            <p><strong>@<?php echo htmlspecialchars($profile_user); ?></strong>
                            <?php if ($accounts[$profile_user]['employee']): ?>
                                <img src="manager.png" alt="DevBadge" class="inline w-4 h-4 ml-1">
                            <?php endif; ?>
                            <?php if ($accounts[$profile_user]['verified']): ?>
                                <img src="check.png" alt="Verified" class="inline w-4 h-4 ml-1">
                            <?php endif; ?>
                            <?php if ($accounts[$profile_user]['donator']): ?>
                                <img src="donator.png" alt="DonatorBadge" class="inline w-4 h-4 ml-1">
                            <?php endif; ?>
                            <?php if ($accounts[$profile_user]['developer']): ?>
                                <img src="dev.png" alt="DevBadge" class="inline w-4 h-4 ml-1">
                            <?php endif; ?>
                            </p>
                            <p class="mb-4"><?php echo htmlspecialchars($accounts[$profile_user]['bio']); ?></p>
                            <p>Followers: <?php echo count($accounts[$profile_user]['followers']); ?></p>
                            <div class="flex space-x-4 mb-4">
                                <a href="/?profile=<?php echo urlencode($profile_user); ?>&section=tnyls" class="px-4 py-2 bg-blue-500 text-white rounded">tnyLs</a>
                                <a href="/?profile=<?php echo urlencode($profile_user); ?>&section=replies" class="px-4 py-2 bg-gray-500 text-white rounded">Replies</a>
                            </div>
                            <?php if ($profile_user == $current_user): ?>
                                <button onclick="document.getElementById('editProfileModal').style.display='block'" class="px-4 py-2 bg-blue-500 text-white rounded">Edit profile</button>
                            <?php else: ?>
                                <?php if (in_array($profile_user, $accounts[$current_user]['following'])): ?>
                                    <a href="/?unfollow=<?php echo urlencode($profile_user); ?>" class="px-4 py-2 bg-red-500 text-white rounded">Unfollow</a>
                                <?php else: ?>
                                    <a href="/?follow=<?php echo urlencode($profile_user); ?>" class="px-4 py-2 bg-blue-500 text-white rounded">Follow</a>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>

                        <div class="mt-8">
                            <h2 class="text-xl font-bold mb-4">
                                <?php echo isset($_GET['section']) && $_GET['section'] == 'replies' ? 'Replies' : 'tnyLs'; ?>
                                by <?php echo htmlspecialchars($accounts[$profile_user]['display_name']); ?>
                            </h2>
                            <div>
                            <?php
                            $user_posts = array_filter($posts, function ($post) use ($profile_user) {
                                if (isset($_GET['section']) && $_GET['section'] == 'replies') {
                                    return $post['username'] === $profile_user && $post['replying_to'] !== null && $post['replying_to'] !== '';
                                } else {
                                    return $post['username'] === $profile_user && ($post['replying_to'] === null || $post['replying_to'] === '');
                                }
                            });

                            usort($user_posts, function ($a, $b) {
                                return $b['timestamp'] - $a['timestamp'];
                            });
                            ?>

                                <?php if (!empty($user_posts)): ?>
                                    <?php foreach ($user_posts as $post): ?>
                                        <?php if (isset($post['replying_to']) && $post['replying_to'] !== ''): ?>
                                            <?php $original_post = $posts[$post['replying_to']]; ?>
                                            <div class="mb-4 p-4 bg-gray-800 rounded-lg shadow">
                                                <div class="flex items-center mb-2">
                                                    <img src="<?php echo htmlspecialchars($original_post['profile_picture']); ?>" alt="image" class="w-8 h-8 rounded-full mr-2">
                                                    <div>
                                                        <span class="font-bold"><a href="/?profile=<?php echo urlencode($original_post['username']); ?>" class="text-blue-400"><?php echo htmlspecialchars($original_post['display_name']); ?></a></span>
                                                        <span class="text-gray-400">@<?php echo htmlspecialchars($original_post['username']); ?> · <?php echo formatTime($original_post['timestamp']); ?></span>
                                                    </div>
                                                </div>
                                                <p class="mb-2"><?php echo htmlspecialchars($original_post['content']); ?></p>
                                                <?php if ($original_post['image_url']): ?>
                                                    <a href="<?php echo htmlspecialchars($original_post['image_url']); ?>" target="_blank">
                                                        <img src="<?php echo htmlspecialchars($original_post['image_url']); ?>" alt="Post image" width="256" height="256" class="mt-2">
                                                    </a>
                                                <?php endif; ?>
                                                <div class="mt-4 p-4 bg-gray-700 rounded-lg shadow">
                                                    <div class="flex items-center mb-2">
                                                        <img src="<?php echo htmlspecialchars($post['profile_picture']); ?>" alt="image" class="w-8 h-8 rounded-full mr-2">
                                                        <div>
                                                            <span class="font-bold"><?php echo htmlspecialchars($post['display_name']); ?></span>
                                                            <span class="text-gray-400">@<?php echo htmlspecialchars($post['username']); ?> · <?php echo formatTime($post['timestamp']); ?></span>
                                                        </div>
                                                    </div>
                                                    <p class="mb-2"><?php echo htmlspecialchars($post['content']); ?></p>
                                                    <?php if ($post['image_url']): ?>
                                                        <a href="<?php echo htmlspecialchars($post['image_url']); ?>" target="_blank">
                                                            <img src="<?php echo htmlspecialchars($post['image_url']); ?>" alt="Reply image" width="256" height="256" class="mt-2">
                                                        </a>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <div class="mb-4 p-4 bg-gray-800 rounded-lg shadow">
                                                <div class="flex items-center mb-2">
                                                    <img src="<?php echo htmlspecialchars($post['profile_picture']); ?>" alt="image" class="w-8 h-8 rounded-full mr-2">
                                                    <div>
                                                        <span class="font-bold"><?php echo htmlspecialchars($post['display_name']); ?></span>
                                                        <span class="text-gray-400">@<?php echo htmlspecialchars($post['username']); ?> · <?php echo formatTime($post['timestamp']); ?></span>
                                                    </div>
                                                </div>
                                                <p class="mb-2"><?php echo htmlspecialchars($post['content']); ?></p>
                                                <?php if ($post['image_url']): ?>
                                                    <a href="<?php echo htmlspecialchars($post['image_url']); ?>" target="_blank">
                                                        <img src="<?php echo htmlspecialchars($post['image_url']); ?>" alt="Post image" width="256" height="256" class="mt-2">
                                                    </a>
                                                <?php endif; ?>
                                                <div class="flex space-x-4">
                                                    <a href="/?like=<?php echo $post['id']; ?>" class="text-blue-400">Like (<?php echo $post['likes']; ?>)</a>
                                                    <button onclick="openReplies('<?php echo $post['id']; ?>')" class="text-blue-400">Replies (<?php echo count($post['replies']); ?>)</button>
                                                    <?php if ($post['username'] == $current_user || $current_user == "faelixyz"): ?>
                                                        <a href="/?delete=<?php echo $post['id']; ?>" class="text-red-400">Delete</a>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <p>No <?php echo isset($_GET['section']) && $_GET['section'] == 'replies' ? 'replies' : 'tnyLs'; ?> found.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <p>Profile not found.</p>
                    <?php endif; ?>
                    <?php elseif (isset($_GET['settings'])): ?>
                        <div class="mt-4 bg-gray-800 p-4 rounded-lg shadow">
                            <h2 class="text-xl font-bold mb-4">Settings</h2>

                            <h1 class="text-xl font-bold mb-4">Verification</h1>
                            <p>You can request a verification mark. Contact @faelixyz on discord. You just need a social media that has 50 or more followers</p>
                            <br>
                            <h1 class="text-xl font-bold mb-4">Discord</h1>
                            <p>Join the Discord!</p><br>
                            <a href="https://discord.gg/j6UYEssGm8" target="_blank" class="px-4 py-2 bg-blue-500 text-white rounded">Join Now</a><br>
                            <br><h1 class="text-xl font-bold mb-4">Custom Name</h1>
                            <p>Please DM @faelixyz on Discord to get a custom name on your rtnyL Account.</p><br>
                            <h1 class="text-xl font-bold mb-4">Account Deletion</h1>
                            <form action="/" method="post">
                                <input type="hidden" name="delete_account" value="1">
                                <div class="mb-4">
                                    <label class="block text-gray-400">Confirm the deletion of your account:</label>
                                    <input type="text" name="confirm_delete" placeholder="Enter 'yes' to confirm the deletion (Your posts are being saved for approximately 1 day if you change your mind. Your account settings are not being saved.)" class="mt-1 block w-full rounded-md bg-gray-900 border-gray-600 text-white shadow-sm focus:border-indigo-500 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                                </div>
                                <div class="flex items-center justify-between">
                                    <button type="submit" class="px-4 py-2 bg-red-500 text-white rounded">Delete account</button>
                                    <a href="/" class="text-gray-400">Cancel</a>
                                </div>
                            </form>

                            <br>

                            
                        </div>
                    <?php else: ?>

                        <div class="mt-4">
                            <h2 class="text-xl font-bold mb-4">Create new tnyL</h2>
                            <form action="/" method="post">
                                <textarea name="content" id="content" rows="4" maxlength="280" class="mt-1 block w-full rounded-md bg-gray-900 border-gray-600 text-white shadow-sm focus:border-indigo-500 focus:ring focus:ring-indigo-200 focus:ring-opacity-50" placeholder="What do you wanna tnyL?" oninput="updateCharacterCount()"></textarea>
                                <small id="charCount" class="text-gray-400">0/280 characters</small>
                                <input type="url" name="image_url" placeholder="Image URL (optional)" class="mt-2 block w-full rounded-md bg-gray-900 border-gray-600 text-white shadow-sm focus:border-indigo-500 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                                <button type="submit" class="mt-2 px-4 py-2 bg-blue-500 text-white rounded">Create tnyL</button>
                                <input type="hidden" name="replying_to" id="replying_to" value="">
                            </form>
                        </div>

                        <div class="mt-8">
                            <h2 class="text-xl font-bold mb-4">Your Feed</h2>
                            <div>
                                <?php if (!empty($suggested_posts)): ?>
                                    <?php foreach ($suggested_posts as $post_id): ?>
                                        <?php $post = $posts[$post_ids[$post_id]]; ?>
                                        <div class="mb-4 p-4 bg-gray-800 rounded-lg shadow">
                                            <div class="flex items-center mb-2">
                                                <img src="<?php echo htmlspecialchars($post['profile_picture']); ?>" alt="image" class="w-8 h-8 rounded-full mr-2">
                                                <div>
                                                    <span class="font-bold"><a href="/?profile=<?php echo urlencode($post['username']); ?>" class="text-blue-400"><?php echo htmlspecialchars($post['display_name']); ?></a></span>
                                                    <?php if ($accounts[$post['username']]['employee']): ?>
                                                        <img src="manager.png" alt="DevBadge" class="inline w-4 h-4 ml-1">
                                                    <?php endif; ?>
                                                    <?php if ($accounts[$post['username']]['verified']): ?>
                                                        <img src="check.png" alt="Verified" class="inline w-4 h-4 ml-1">
                                                    <?php endif; ?>
                                                    <?php if ($accounts[$post['username']]['donator']): ?>
                                                        <img src="donator.png" alt="DonateBadge" class="inline w-4 h-4 ml-1">
                                                    <?php endif; ?>
                                                    <?php if ($accounts[$post['username']]['developer']): ?>
                                                        <img src="dev.png" alt="DevBadge" class="inline w-4 h-4 ml-1">
                                                    <?php endif; ?>
                                                    <span class="text-gray-400">@<?php echo htmlspecialchars($post['username']); ?> · <?php echo formatTime($post['timestamp']); ?></span>
                                                </div>
                                            </div>
                                            <p class="mb-2"><?php echo htmlspecialchars($post['content']); ?></p>
                                            <?php if ($post['image_url']): ?>
                                                <a href="<?php echo htmlspecialchars($post['image_url']); ?>" target="_blank">
                                                    <img src="<?php echo htmlspecialchars($post['image_url']); ?>" alt="Post image" width="256" height="256" class="mt-2">
                                                </a>
                                            <?php endif; ?>
                                            <div class="flex space-x-4">
                                                <a href="/?like=<?php echo $post['id']; ?>" class="text-blue-400">Like (<?php echo $post['likes']; ?>)</a>
                                                <button onclick="openReplies('<?php echo $post['id']; ?>')" class="text-blue-400">Replies (<?php echo count($post['replies']); ?>)</button>
                                                <?php if ($post['username'] == $current_user || $current_user == "faelixyz"): ?>
                                                    <a href="/?delete=<?php echo $post['id']; ?>" class="text-red-400">Delete</a>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <p>No tnyls found.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php else: ?>
            <p>You need to login if you wanna see the tnyls or wanna see a profile. </p>
            <p>Please note that logging on to this Service "rtnyL", you agree to the <a href="https://vybo.dev/legal/rtnyl/terms-of-use.html" target="_blank">Terms of Use and that we use cookies to remember that you are logged in.</p>
        <?php endif; ?>
    </div>

    <!-- Modals -->
    <div id="editProfileModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="document.getElementById('editProfileModal').style.display='none'">&times;</span>
            <h2 class="text-xl font-bold mb-4">Edit profile</h2>
            <form action="/" method="post" enctype="multipart/form-data">
                <input type="hidden" name="edit_profile" value="1">
                <div class="mb-4">
                    <label class="block text-gray-400">Displayname:</label>
                    <input type="text" name="display_name" value="<?php echo htmlspecialchars($accounts[$current_user]['display_name']); ?>" class="mt-1 block w-full rounded-md bg-gray-900 border-gray-600 text-white shadow-sm focus:border-indigo-500 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                </div>
                <div class="mb-4">
                    <label class="block text-gray-400">Bio:</label>
                    <textarea name="bio" rows="4" maxlength="60" class="mt-1 block w-full rounded-md bg-gray-900 border-gray-600 text-white shadow-sm focus:border-indigo-500 focus:ring focus:ring-indigo-200 focus:ring-opacity-50"><?php echo htmlspecialchars($accounts[$current_user]['bio']); ?></textarea>
                </div>
                <div class="flex items-center justify-between">
                    <button type="submit" class="px-4 py-2 bg-blue-500 text-white rounded">Save</button>
                    <a href="/" class="text-gray-400">Cancel</a>
                </div>
            </form>
        </div>
    </div>

    <div id="repliesModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="document.getElementById('repliesModal').style.display='none'">&times;</span>
            <div id="repliesContent"></div>
            <form action="/" method="post">
                <textarea name="content" id="replyContent" rows="4" maxlength="280" class="mt-1 block w-full rounded-md bg-gray-900 border-gray-600 text-white shadow-sm focus:border-indigo-500 focus:ring focus:ring-indigo-200 focus:ring-opacity-50" placeholder="Write your reply..."></textarea>
                <button type="submit" class="mt-2 px-4 py-2 bg-blue-500 text-white rounded">Reply</button>
                <input type="hidden" name="replying_to" id="replyingTo" value="">
            </form>

        </div>
    </div>

<script>
    function openReplies(postId) {
        var modal = document.getElementById('repliesModal');
        var content = document.getElementById('repliesContent');
        document.getElementById('replyingTo').value = postId;
        content.innerHTML = '';

        <?php foreach ($posts as $post): ?>
        if (postId === '<?php echo $post['id']; ?>') {
            content.innerHTML += `
                <div class="p-4 bg-gray-800 rounded-lg shadow mb-4">
                    <div class="flex items-center mb-2">
                        <img src="<?php echo htmlspecialchars($post['profile_picture']); ?>" alt="image" class="w-8 h-8 rounded-full mr-2">
                        <div>
                            <span class="font-bold"><a href="/?profile=<?php echo htmlspecialchars($post['username']); ?>"><?php echo htmlspecialchars($post['display_name']); ?></a></span>
                            <span class="text-gray-400">@<?php echo htmlspecialchars($post['username']); ?> · <?php echo formatTime($post['timestamp']); ?></span>
                        </div>
                    </div>
                    <p class="mb-2"><?php echo htmlspecialchars($post['content']); ?></p>
                    <?php if ($post['image_url']): ?>
                    <a href="<?php echo htmlspecialchars($post['image_url']); ?>" target="_blank">
                        <img src="<?php echo htmlspecialchars($post['image_url']); ?>" alt="Post image" width="256" height="256" class="mt-2">
                    </a>
                    <?php endif; ?>
                </div>
            `;

            <?php if (!empty($post['replies'])): ?>
            content.innerHTML += '<h3 class="text-lg font-bold mb-2">Replies</h3>';
            <?php foreach ($post['replies'] as $reply_id): ?>
            var reply = <?php echo json_encode($posts[$reply_id]); ?>;
            content.innerHTML += `
                <div class="p-4 bg-gray-700 rounded-lg shadow mb-4 ml-4">
                    <div class="flex items-center mb-2">
                        <img src="` + reply.profile_picture + `" alt="image" class="w-8 h-8 rounded-full mr-2">
                        <div>
                            <span class="font-bold"><a href="/?profile=` + reply.username + `"> ` + reply.display_name + `</a></span>
                            <span class="text-gray-400">@` + reply.username + ` · ` + new Date(reply.timestamp * 1000).toLocaleString() + `</span>
                        </div>
                    </div>
                    <p class="mb-2">` + reply.content + `</p>
                    <div class="flex space-x-4">
                        <a href="/?like=` + reply.id + `" class="text-blue-400">Like (` + (reply.likes || 0) + `)</a>
                        <?php if ($current_user == $post['username'] || $current_user == 'faelixyz'): ?>
                        <a href="/?delete=` + reply.id + `" class="text-red-400">Delete</a>
                        <?php endif; ?>
                    </div>
                </div>
            `;
            <?php endforeach; ?>
            <?php endif; ?>

        }
        <?php endforeach; ?>

        modal.style.display = 'block';
    }

    function updateCharacterCount() {
        var content = document.getElementById('content');
        document.getElementById('charCount').innerText = content.value.length + '/280 characters';
    }

    window.onclick = function(event) {
        if (event.target.classList.contains('modal')) {
            event.target.style.display = 'none';
        }
    }

</script>

</body>
</html>
