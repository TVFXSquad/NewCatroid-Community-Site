<?php
session_start();

define('DATA_DIR', 'data');
define('USERS_FILE', DATA_DIR . '/users.txt');
define('GAMES_FILE', DATA_DIR . '/games.txt');
define('DOWNLOAD_LOG_FILE', DATA_DIR . '/download_log.txt');
define('PROJECTS_DIR', DATA_DIR . '/projects');
define('IMAGES_DIR', DATA_DIR . '/images');
define('TITLE_MAX_LENGTH', 30);
define('DESCRIPTION_MAX_LENGTH', 500);
define('COMMENT_MAX_LENGTH', 300);
define('ALLOWED_EXTENSION', 'newtrobat');
define('ADMIN_USERNAMES', ['jojo_kent', 'tvfxsquad']);
define('UPLOAD_COOLDOWN_SECONDS', 100);

define('ALLOWED_IMAGE_TYPES', [IMAGETYPE_JPEG, IMAGETYPE_PNG]);
define('ALLOWED_IMAGE_MIMES', ['image/jpeg', 'image/png']);
define('IMAGE_MAX_SIZE_MB', 3);
define('PROJECT_FILE_MAX_SIZE_MB', 35);

function create_directories() {
  if (!is_dir(DATA_DIR)) {
      if (!@mkdir(DATA_DIR, 0775, true)) {
          error_log("FATAL: Failed to create base directory: " . DATA_DIR);
          return false;
      }
  }

  if (!is_dir(PROJECTS_DIR)) {
      @mkdir(PROJECTS_DIR, 0775, true);
  }
  if (!is_dir(IMAGES_DIR)) {
      @mkdir(IMAGES_DIR, 0775, true);
  }

  $core_files = [DOWNLOAD_LOG_FILE, USERS_FILE, GAMES_FILE];
  foreach ($core_files as $core_file) {
      if (!file_exists($core_file)) {
          @file_put_contents($core_file, serialize([]), LOCK_EX);
      }
  }

  return true;
}




function get_users() {
  $users = [];
  if (!file_exists(USERS_FILE)) {
      error_log("Users file not found: " . USERS_FILE);
      return [];
  }
  $content = @file_get_contents(USERS_FILE);
  if ($content === false) {
      error_log("Failed to read users file: " . USERS_FILE);
      return [];
  }
  if (!empty($content)) {

      $decoded_users = @unserialize($content);
      if ($decoded_users !== false && is_array($decoded_users)) {

          $processed_users = [];
          foreach($decoded_users as $key => $userData) {
              $processed_users[strtolower($key)] = $userData;

              if (!isset($processed_users[strtolower($key)]['original_login'])) {
                  $processed_users[strtolower($key)]['original_login'] = $key;
              }
          }
          $users = $processed_users;
      } else {
          error_log("Failed to unserialize users file or content is not an array: " . USERS_FILE);

      }
  }
  return $users;
}

function save_users($users) {
    if (!is_array($users)) {
        error_log("Attempted to save non-array data to users file.");
        return false;
    }

    $users_to_save = [];
     foreach($users as $key => $userData) {
         $lower_key = strtolower($key);
         $userData['original_login'] = $key;
         $users_to_save[$lower_key] = $userData;
     }


    $serialized_data = serialize($users_to_save);

    if (@file_put_contents(USERS_FILE, $serialized_data, LOCK_EX) === false) {
        error_log("Failed to save users file: " . USERS_FILE . " - Check permissions and disk space.");
        return false;
    }
    return true;
}

function register_user($login, $email, $password) {

    $login_clean = trim($login);
    $email_clean = trim($email);
    $login_lower = strtolower($login_clean);

    if (empty($login_clean)) return ['success' => false, 'message' => "Логин не может быть пустым."];

    if (!preg_match('/^[a-zA-Z0-9_-]{3,20}$/', $login_clean)) return ['success' => false, 'message' => "Логин содержит недопустимые символы или имеет неверную длину (3-20 символов: a-z, A-Z, 0-9, _, -)."];
    if (empty($email_clean)) return ['success' => false, 'message' => "Email не может быть пустым."];
    if (strlen($password) < 6) return ['success' => false, 'message' => "Пароль должен быть не менее 6 символов."];
    if (!filter_var($email_clean, FILTER_VALIDATE_EMAIL)) return ['success' => false, 'message' => "Некорректный формат Email."];

    $users = get_users();
    $email_lower = strtolower($email_clean);


    if (isset($users[$login_lower])) return ['success' => false, 'message' => "Этот логин уже занят."];

    foreach ($users as $user_data) {
        if (isset($user_data['email']) && strtolower($user_data['email']) === $email_lower) {
            return ['success' => false, 'message' => "Этот Email уже зарегистрирован."];
        }
    }


    $users[$login_lower] = [
        'original_login' => $login_clean,
        'email' => $email_lower,
        'password' => password_hash($password, PASSWORD_DEFAULT),

    ];


    if (save_users($users)) {
        return ['success' => true, 'message' => "Регистрация успешна! Теперь вы можете войти."];
    } else {
        error_log("Failed to save users file during registration for: " . $login_lower);
        return ['success' => false, 'message' => "Ошибка сервера при сохранении данных. Попробуйте позже."];
    }
}

function authenticate_user($identifier, $password) {
    $users = get_users();
    $identifier_clean = trim($identifier);
    $identifier_lower = strtolower($identifier_clean);

    if (empty($identifier_clean) || empty($password)) return false;


    if (strpos($identifier_lower, '@') !== false && filter_var($identifier_lower, FILTER_VALIDATE_EMAIL)) {

        foreach ($users as $login_lower_key => $user_data) {
            if (isset($user_data['email']) && $user_data['email'] === $identifier_lower) {

                if (isset($user_data['password']) && password_verify($password, $user_data['password'])) {

                     return $user_data['original_login'] ?? $login_lower_key;
                } else {
                    return false;
                }
            }
        }
        return false;
    } else {

        if (isset($users[$identifier_lower])) {
             $user_data = $users[$identifier_lower];
             if (isset($user_data['password']) && password_verify($password, $user_data['password'])) {

                 return $user_data['original_login'] ?? $identifier_lower;
             }
        }
        return false;
    }
}




function get_download_log() {
    $log = [];
     if (!file_exists(DOWNLOAD_LOG_FILE)) { error_log("DL Log file missing: ".DOWNLOAD_LOG_FILE); return []; }
    $content = @file_get_contents(DOWNLOAD_LOG_FILE);
    if ($content === false) { error_log("Failed read DL Log: ".DOWNLOAD_LOG_FILE); return []; }
    if (!empty($content)) {
        $decoded_log = @unserialize($content);
        if ($decoded_log !== false && is_array($decoded_log)) {

             $processed_log = [];
             foreach ($decoded_log as $game_id => $user_list) {
                 if (is_array($user_list)) {
                     $processed_log[$game_id] = array_map('strtolower', $user_list);
                 } else {
                      $processed_log[$game_id] = [];
                 }
             }
            $log = $processed_log;
        } else {
            error_log("Failed unserialize DL Log: ".DOWNLOAD_LOG_FILE);
        }
    }
    return $log;
}
function save_download_log($log) {
    if (!is_array($log)) { error_log("Attempt save non-array DL Log."); return false; }

    $log_to_save = [];
    foreach ($log as $game_id => $user_list) {
        if (is_array($user_list)) {
            $log_to_save[$game_id] = array_map('strtolower', $user_list);
        } else {
             $log_to_save[$game_id] = [];
        }
    }
    if (@file_put_contents(DOWNLOAD_LOG_FILE, serialize($log_to_save), LOCK_EX) === false) {
        error_log("Failed save DL Log: ".DOWNLOAD_LOG_FILE); return false;
    }
    return true;
}
function has_user_downloaded($game_id, $user_login) {
    $log = get_download_log();
    $user_login_lower = strtolower($user_login);

    return isset($log[$game_id]) && is_array($log[$game_id]) && in_array($user_login_lower, $log[$game_id]);
}
function record_user_download($game_id, $user_login) {

    $log = get_download_log();
    $user_login_lower = strtolower($user_login);
    if (!isset($log[$game_id]) || !is_array($log[$game_id])) $log[$game_id] = [];


    if (!in_array($user_login_lower, $log[$game_id])) {

        $log[$game_id][] = $user_login_lower;
        return save_download_log($log);
    }
    return true;
}




function get_games() {
    $games = [];
    if (!file_exists(GAMES_FILE)) { error_log("Games file missing: ".GAMES_FILE); return []; }
    $content = @file_get_contents(GAMES_FILE);
    if ($content === false) { error_log("Failed read Games file: ".GAMES_FILE); return []; }
    if (!empty($content)) {
       $decoded_games = @unserialize($content);
        if ($decoded_games !== false && is_array($decoded_games)) {

           foreach ($decoded_games as $id => &$game) {
               $game['likes'] = isset($game['likes']) && is_array($game['likes']) ? array_map('strtolower', $game['likes']) : [];
               $game['dislikes'] = isset($game['dislikes']) && is_array($game['dislikes']) ? array_map('strtolower', $game['dislikes']) : [];
               $game['downloads'] = isset($game['downloads']) && is_numeric($game['downloads']) ? (int)$game['downloads'] : 0;

               if (isset($game['author'])) {
                   $game['author_lower'] = strtolower($game['author']);
               } else {
                   $game['author'] = 'Неизв.';
                   $game['author_lower'] = strtolower($game['author']);
               }

               $game['image'] = $game['image'] ?? '';
               $game['file'] = $game['file'] ?? '';  
               $game['description'] = $game['description'] ?? '';
               $game['title'] = $game['title'] ?? 'Без названия';
               $game['timestamp'] = $game['timestamp'] ?? 0;
           }
           unset($game);
           $games = $decoded_games;
       } else {
           error_log("Failed unserialize Games file: ".GAMES_FILE);

       }
    }
    return $games;
}

function save_games($games) {
   if (!is_array($games)) { error_log("Attempt save non-array Games."); return false; }

   $games_to_save = [];
   foreach ($games as $id => $game) {

        $games_to_save[$id] = [
           'title' => $game['title'] ?? 'Без названия',
           'image' => $game['image'] ?? '',
           'file' => $game['file'] ?? '',
           'author' => $game['author'] ?? 'Неизв.',
           'author_lower' => strtolower($game['author'] ?? 'Неизв.'),
           'description' => $game['description'] ?? '',
           'downloads' => (int)($game['downloads'] ?? 0),
           'timestamp' => (int)($game['timestamp'] ?? 0),
           'likes' => isset($game['likes']) && is_array($game['likes']) ? array_map('strtolower', $game['likes']) : [],
           'dislikes' => isset($game['dislikes']) && is_array($game['dislikes']) ? array_map('strtolower', $game['dislikes']) : [],
        ];
   }

   if (@file_put_contents(GAMES_FILE, serialize($games_to_save), LOCK_EX) === false) {
        error_log("CRITICAL: Failed save Games file: ".GAMES_FILE); return false;
   }
   return true;
}

function add_game($title, $image_url, $file_url, $author, $description) {
  $games = get_games();
  $game_id = uniqid('game_');
  $games[$game_id] = [
    'title' => $title,
    'image' => $image_url,
    'file' => $file_url,
    'author' => $author,
    'author_lower' => strtolower($author),
    'description' => $description,
    'downloads' => 0,
    'timestamp' => time(),
    'likes' => [],
    'dislikes' => []
  ];
  if (!save_games($games)) {
      return false;
  }
  return $game_id;
}

function get_game($game_id) {
  $games = get_games();
  return isset($games[$game_id]) ? $games[$game_id] : null;
}

function increment_total_download_count($game_id) {
    $games = get_games();
    if (!isset($games[$game_id])) { error_log("Attempt increment DL count non-exist game: ".$game_id); return false; }

    $games[$game_id]['downloads']++;
    if (!save_games($games)) { error_log("Failed save games after increment DL count: ".$game_id); return false; }
    return true;
}



function record_vote($game_id, $user_login) {
    $games = get_games();
    if (!isset($games[$game_id])) { error_log("Vote non-exist game: ".$game_id); return ['success' => false, 'message' => 'Проект не найден.']; }

    $user_login_lower = strtolower($user_login);
    $game = $games[$game_id];

    $likes = $game['likes'];
    $dislikes = $game['dislikes'];

    $is_liked = in_array($user_login_lower, $likes);
    $is_disliked = in_array($user_login_lower, $dislikes);

    $user_vote_status = null;
    $action_taken = $_GET['action'] ?? null;

    if ($action_taken === 'like') {
        if ($is_disliked) {
            $dislikes = array_values(array_filter($dislikes, fn($u) => $u !== $user_login_lower));
        }
        if ($is_liked) {
            $likes = array_values(array_filter($likes, fn($u) => $u !== $user_login_lower));
            $user_vote_status = null;
        } else {
            $likes[] = $user_login_lower;
            $user_vote_status = 'like';
        }
    } elseif ($action_taken === 'dislike') {
        if ($is_liked) {
            $likes = array_values(array_filter($likes, fn($u) => $u !== $user_login_lower));
        }
        if ($is_disliked) {
            $dislikes = array_values(array_filter($dislikes, fn($u) => $u !== $user_login_lower));
            $user_vote_status = null;
        } else {
            $dislikes[] = $user_login_lower;
            $user_vote_status = 'dislike';
        }
    } else {

        return ['success' => false, 'message' => 'Недопустимое действие голоса.'];
    }


    $games[$game_id]['likes'] = array_values($likes);
    $games[$game_id]['dislikes'] = array_values($dislikes);

    if (save_games($games)) {
        return [
            'success' => true,
            'likes' => count($games[$game_id]['likes']),
            'dislikes' => count($games[$game_id]['dislikes']),
            'user_vote' => $user_vote_status
        ];
    } else {
        error_log("Failed save games after vote: ".$game_id);
        return ['success' => false, 'message' => 'Ошибка сервера при сохранении голоса.'];
    }
}


function get_like_count($game_id) { $game = get_game($game_id); return $game ? count($game['likes'] ?? []) : 0; }
function get_dislike_count($game_id) { $game = get_game($game_id); return $game ? count($game['dislikes'] ?? []) : 0; }
function get_user_vote($game_id, $user_login) {
    $game = get_game($game_id);
    if (!$game || !$user_login) return null;
    $user_login_lower = strtolower($user_login);

    if (in_array($user_login_lower, $game['likes'] ?? [])) return 'like';
    if (in_array($user_login_lower, $game['dislikes'] ?? [])) return 'dislike';
    return null;
}



function add_comment($game_id, $author, $comment_raw) {

  $author_clean = htmlspecialchars(trim($author), ENT_QUOTES, 'UTF-8');
  $comment_processed = htmlspecialchars(trim($comment_raw), ENT_QUOTES, 'UTF-8');


  if (mb_strlen(trim($comment_raw), 'UTF-8') > COMMENT_MAX_LENGTH) return false;
  if (empty($author_clean) || empty(trim($comment_raw)) || empty($game_id)) return false;


  $safe_game_id = preg_replace('/[^a-zA-Z0-9_]/', '', $game_id);
  if ($safe_game_id !== $game_id || empty($safe_game_id)) { error_log("Invalid game_id comment: ".$game_id); return false; }
  $comment_file = DATA_DIR . '/comments_' . $safe_game_id . '.txt';

  $comment_id = uniqid('cmt_');
  $comment_data = json_encode(['id' => $comment_id, 'author' => $author_clean, 'comment' => $comment_processed, 'timestamp' => time()]);
  if ($comment_data === false) { error_log("JSON encode comment fail for game: ".$safe_game_id); return false; }


  if (@file_put_contents($comment_file, $comment_data . PHP_EOL, FILE_APPEND | LOCK_EX) !== false) {
      return true;
  } else {
      error_log("Write comment fail: ".$comment_file . " - Check permissions.");
      return false;
  }
}

function get_comments($game_id) {

  $safe_game_id = preg_replace('/[^a-zA-Z0-9_]/', '', $game_id);
   if ($safe_game_id !== $game_id || empty($safe_game_id)) { return []; }
  $comment_file = DATA_DIR . '/comments_' . $safe_game_id . '.txt';
  $comments_array = [];
  if (file_exists($comment_file)) {

     $lines = @file($comment_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
     if ($lines !== false) {
         foreach ($lines as $line) {
             $decoded_comment = json_decode($line, true);

             if (is_array($decoded_comment) && isset($decoded_comment['id'], $decoded_comment['author'], $decoded_comment['comment'], $decoded_comment['timestamp']) && is_int($decoded_comment['timestamp'])) {
                $comments_array[] = $decoded_comment;
             } else {

                 error_log("Invalid comment line found in file: ".$comment_file." Line: ".$line);
             }
         }
     } else {
         error_log("Failed to read comment file: ".$comment_file);
     }
  }
  return $comments_array;
}

function delete_comment($game_id, $comment_id) {

    $safe_game_id = preg_replace('/[^a-zA-Z0-9_]/', '', $game_id);
    $safe_comment_id = preg_replace('/[^a-zA-Z0-9_]/', '', $comment_id);

    if ($safe_game_id !== $game_id || empty($safe_game_id) || $safe_comment_id !== $comment_id || empty($safe_comment_id)) {
        error_log("Attempted to delete comment with invalid game_id or comment_id. Game ID: " . $game_id . ", Comment ID: " . $comment_id);
        return false;
    }

    $comment_file = DATA_DIR . '/comments_' . $safe_game_id . '.txt';
    if (!file_exists($comment_file)) {

        return false;
    }
    $lines = @file($comment_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        error_log("Failed to read comment file for deletion: ".$comment_file);
        return false;
    }

    $new_lines = []; $deleted = false;
    foreach ($lines as $line) {
        $decoded_comment = json_decode($line, true);

        if (is_array($decoded_comment) && isset($decoded_comment['id']) && $decoded_comment['id'] === $comment_id) {
            $deleted = true;

        } else {

            $new_lines[] = $line;
        }
    }

    if ($deleted) {

        $new_content = implode(PHP_EOL, $new_lines);

        if (!empty($new_lines)) {
             $new_content .= PHP_EOL;
        }


        if (@file_put_contents($comment_file, $new_content, LOCK_EX) !== false) {

            if (empty($new_lines) && file_exists($comment_file)) {
                 @unlink($comment_file);
            }
            return true;
        } else {
            error_log("Failed to rewrite comment file after deletion: ".$comment_file);
            return false;
        }
    }
    return false;
}




function save_to_local($tmp_file_path, $target_dir, $filename) {
    if (!file_exists($tmp_file_path) || !is_readable($tmp_file_path)) {
        return false;
    }
    if (!is_dir($target_dir)) {
        mkdir($target_dir, 0775, true);
    }
    $target_path = $target_dir . '/' . $filename;
    if (move_uploaded_file($tmp_file_path, $target_path)) {
        return $target_path;
    }
    return false;
}

function delete_game($game_id) {
    $safe_game_id = preg_replace('/[^a-zA-Z0-9_]/', '', $game_id);
    if ($safe_game_id !== $game_id || empty($safe_game_id)) {
         return false;
    }

    $games = get_games();
    if (!isset($games[$game_id])) {
         return false;
    }
    $game = $games[$game_id];
    $success = true;

    if (!empty($game['image']) && file_exists($game['image'])) {
        @unlink($game['image']);
    }
    if (!empty($game['file']) && file_exists($game['file'])) {
        @unlink($game['file']);
    }

    $comment_file = DATA_DIR . '/comments_' . $safe_game_id . '.txt';
    if (file_exists($comment_file)) {
        @unlink($comment_file);
    }

    unset($games[$game_id]);
    if (!save_games($games)) {
        return false;
    }

    $download_log = get_download_log();
    if (isset($download_log[$game_id])) {
        unset($download_log[$game_id]);
        save_download_log($download_log);
    }

    return $success;
}

function delete_user($login_to_delete) {
    $login_to_delete_clean = trim($login_to_delete);
    $login_to_delete_lower = strtolower($login_to_delete_clean);
    if(empty($login_to_delete_lower)) return false;

    $admin_logins_lower = array_map('strtolower', ADMIN_USERNAMES);
    if (in_array($login_to_delete_lower, $admin_logins_lower)) {

         return false;
    }

    $users = get_users();
    if (!isset($users[$login_to_delete_lower])) {

         return false;
    }

    $overall_success = true;



    $all_games_before_delete = get_games();
    $game_ids_to_delete = [];
    foreach ($all_games_before_delete as $game_id => $game_data) {

        if (isset($game_data['author_lower']) && $game_data['author_lower'] === $login_to_delete_lower) {
            $game_ids_to_delete[] = $game_id;
        }
    }


    foreach($game_ids_to_delete as $game_id) {
        if (!delete_game($game_id)) {
            $overall_success = false;
            error_log("Failed to delete game $game_id during user $login_to_delete del.");
        }
    }





    $remaining_games_after_delete = get_games();
    foreach (array_keys($remaining_games_after_delete) as $game_id) {
        $comments = get_comments($game_id);
        if (!empty($comments)) {
            $comments_to_delete_ids = [];
            foreach ($comments as $comment) {

                if (isset($comment['author']) && strtolower($comment['author']) === $login_to_delete_lower) {
                    $comments_to_delete_ids[] = $comment['id'];
                }
            }

            foreach($comments_to_delete_ids as $comment_id) {
                if (!delete_comment($game_id, $comment_id)) {
                    $overall_success = false;
                    error_log("Failed to delete comment $comment_id on $game_id during user $login_to_delete del.");
                }
            }
        }
    }


    $download_log_changed = false;
    $current_download_log = get_download_log();
    foreach ($current_download_log as $game_id => &$user_list) {
        if (is_array($user_list)) {
            $initial_count = count($user_list);

            $user_list = array_values(array_filter($user_list, fn($user) => $user !== $login_to_delete_lower));
             if (count($user_list) !== $initial_count) {
                 $download_log_changed = true;

                 if (empty($user_list)) {
                      unset($current_download_log[$game_id]);
                 }
             }
        }
    }
    unset($user_list);
    if ($download_log_changed) {

        if (!save_download_log($current_download_log)) {
             $overall_success = false;
             error_log("Failed to save DL log after user $login_to_delete del.");
        }
    }



    $current_games_for_votes = get_games();
    $games_data_changed_for_votes = false;

    foreach ($current_games_for_votes as $game_id => &$game_data) {
        $votes_modified = false;

        if (isset($game_data['likes']) && is_array($game_data['likes'])) {
            $initial_count = count($game_data['likes']);

            $game_data['likes'] = array_values(array_filter($game_data['likes'], fn($user) => $user !== $login_to_delete_lower));
            if(count($game_data['likes']) !== $initial_count) $votes_modified = true;
        }

         if (isset($game_data['dislikes']) && is_array($game_data['dislikes'])) {
            $initial_count = count($game_data['dislikes']);

            $game_data['dislikes'] = array_values(array_filter($game_data['dislikes'], fn($user) => $user !== $login_to_delete_lower));
            if(count($game_data['dislikes']) !== $initial_count) $votes_modified = true;
        }

        if ($votes_modified) {
             $games_data_changed_for_votes = true;
        }
    }
    unset($game_data);
    if($games_data_changed_for_votes) {

        if (!save_games($current_games_for_votes)) {
             $overall_success = false;
             error_log("Failed to save games after user $login_to_delete vote removal.");
        }
    }


    $current_users = get_users();
    if (isset($current_users[$login_to_delete_lower])) {
       unset($current_users[$login_to_delete_lower]);

       if (!save_users($current_users)) {
           $overall_success = false;
           error_log("CRITICAL: Failed to save users file after user $login_to_delete del.");
       }
    } else {



        error_log("User $login_to_delete ($login_to_delete_lower) not found in users list during final delete step?");

    }


    return $overall_success;
}



function remove_line_breaks_for_title($string) {
    return str_replace(["\r\n", "\r", "\n"], ' ', $string);
}
function safe_redirect($url) {

    $safe_url = filter_var($url, FILTER_SANITIZE_URL);


    $host = $_SERVER['HTTP_HOST'];
    if (strpos($safe_url, '://') === false || strpos($safe_url, $host) !== false || strpos($safe_url, '/') === 0) {
        if (!headers_sent()) {
            header("Location: " . $safe_url);
            exit();
        } else {

            echo "<script>window.location.href='" . addslashes($safe_url) . "';</script>";
            exit();
        }
    } else {

        error_log("Potential unsafe redirect attempted to: " . $url);

        if (!headers_sent()) {
             header("Location: " . $_SERVER['PHP_SELF'] . "?page=home");
             exit();
        } else {
             echo "<script>window.location.href='" . addslashes($_SERVER['PHP_SELF'] . "?page=home") . "';</script>";
             exit();
        }
    }
}



if (!create_directories()) {

    die("Server setup error: Unable to access required data directories. Please check permissions.");
}
$registration_message = null; $login_message = null; $publish_message = null; $comment_message = null; $admin_message = null; $general_error_message = null;
$page_render = isset($_GET['page']) ? basename($_GET['page']) : 'home';


if (isset($_SESSION['user'])) {
    $current_session_user_original_case = $_SESSION['user'];
    $current_session_user_lower = strtolower($current_session_user_original_case);
    $all_users_check = get_users();


    if (!isset($all_users_check[$current_session_user_lower])) {
        error_log("User '{$current_session_user_original_case}' in session but not found in users data (key: {$current_session_user_lower}). Logging out.");
        session_unset();
        session_destroy();

        if (ini_get("session.use_cookies")) { $params = session_get_cookie_params(); setcookie(session_name(), '', time() - 42000, $params["path"], $params["domain"], $params["secure"], $params["httptest"]); }
        safe_redirect($_SERVER['PHP_SELF'] . "?page=home&status=account_deleted");
    } else {

        $stored_user_data = $all_users_check[$current_session_user_lower];
        if (isset($stored_user_data['original_login'])) {
            $_SESSION['user'] = $stored_user_data['original_login'];
        } else {


            error_log("User '{$current_session_user_original_case}' found by lowercase key '{$current_session_user_lower}', but 'original_login' missing in users data.");





              $_SESSION['user'] = $current_session_user_lower;
        }
    }
}


$is_admin = false;
if (isset($_SESSION['user'])) {

    $current_user_lower = strtolower($_SESSION['user']);
    $admin_logins_lower = array_map('strtolower', ADMIN_USERNAMES);
    $is_admin = in_array($current_user_lower, $admin_logins_lower);
}


if (isset($_GET['logout'])) {
    session_unset();
    session_destroy();

    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httptest"]
        );
    }
    safe_redirect($_SERVER['PHP_SELF'] . "?page=home");
}



if ($_SERVER['REQUEST_METHOD'] === 'POST') {


    if ($is_admin) {
        if (isset($_POST['delete_game']) && isset($_POST['game_id'])) {
            $game_id_to_delete = $_POST['game_id'];
            if (delete_game($game_id_to_delete)) { $admin_message = "Проект '" . htmlspecialchars($game_id_to_delete) . "', его файлы, комментарии и записи о скачиваниях успешно удалены."; }
            else { $admin_message = "Ошибка при удалении проекта '" . htmlspecialchars($game_id_to_delete) . "'."; }
            $_SESSION['admin_message'] = $admin_message;
            safe_redirect($_SERVER['PHP_SELF'] . "?page=admin&status=action_completed");
        } elseif (isset($_POST['delete_user']) && isset($_POST['user_login'])) {
            $user_to_delete_raw = $_POST['user_login'];
            $user_to_delete_lower = strtolower(trim($user_to_delete_raw));
            $admin_logins_lower_check = array_map('strtolower', ADMIN_USERNAMES);

            if (in_array($user_to_delete_lower, $admin_logins_lower_check)) { $admin_message = "Нельзя удалить администратора."; }
            elseif (delete_user($user_to_delete_raw)) {
                 $admin_message = "Пользователь '" . htmlspecialchars($user_to_delete_raw) . "', его проекты, файлы, комментарии и записи удалены.";

                 if (isset($_SESSION['user']) && strtolower($_SESSION['user']) === $user_to_delete_lower) {
                     session_unset(); session_destroy();
                     if (ini_get("session.use_cookies")) { $params = session_get_cookie_params(); setcookie(session_name(), '', time() - 42000, $params["path"], $params["domain"], $params["secure"], $params["httptest"]); }
                     safe_redirect($_SERVER['PHP_SELF'] . "?page=home&status=account_deleted_by_admin");
                 }
            } else { $admin_message = "Ошибка при удалении пользователя '" . htmlspecialchars($user_to_delete_raw) . "'."; }
             $_SESSION['admin_message'] = $admin_message;
             safe_redirect($_SERVER['PHP_SELF'] . "?page=admin&status=action_completed");
        } elseif (isset($_POST['delete_comment']) && isset($_POST['game_id']) && isset($_POST['comment_id'])) {
             $game_id_for_comment = $_POST['game_id'];
             $comment_id_to_delete = $_POST['comment_id'];

             if (delete_comment($game_id_for_comment, $comment_id_to_delete)) {

                 safe_redirect($_SERVER['PHP_SELF'] . "?page=game&id=" . urlencode($game_id_for_comment) . "&status=comment_deleted#comments-section");
             } else {

                 $_SESSION['temp_error_msg'] = "Ошибка при удалении комментария.";
                 safe_redirect($_SERVER['PHP_SELF'] . "?page=game&id=" . urlencode($game_id_for_comment) . "#comments-section");
             }
        }


         if (isset($_POST['delete_game']) || isset($_POST['delete_user']) || isset($_POST['delete_comment'])) {
             exit();
         }
    }


    if (isset($_POST['register'])) {
        $login = $_POST['login'] ?? ''; $email = $_POST['email'] ?? ''; $password = $_POST['password'] ?? '';
        $result = register_user($login, $email, $password);

        $_SESSION['registration_message'] = "<span class='" . ($result['success'] ? 'message' : 'error-message') . "'>" . htmlspecialchars($result['message']) . "</span>";
        $redirect_url = $_SERVER['PHP_SELF'] . "?page=home";
        if (!$result['success']) {

             $redirect_url .= "&show_reg_form=1";
        } else {

             $redirect_url .= "&reg_success=1";
        }
        safe_redirect($redirect_url);

    }

    elseif (isset($_POST['login_action'])) {
        $identifier = $_POST['identifier'] ?? ''; $password = $_POST['password'] ?? '';
        $login_result = authenticate_user($identifier, $password);
        if ($login_result !== false) {
            $_SESSION['user'] = $login_result;
            $_SESSION['login_time'] = time();
            safe_redirect($_SERVER['PHP_SELF'] . '?page=home');
        } else {

            $_SESSION['login_message'] = "Неверный Логин/Email или пароль.";
            safe_redirect($_SERVER['PHP_SELF'] . '?page=home&show_login_form=1');
        }
    }

    elseif (isset($_POST['publish_game_ajax']) && $page_render === 'publish') {
        header('Content-Type: application/json');
        $response = ['success' => false, 'message' => 'Неизвестная ошибка публикации.'];


        if (!isset($_SESSION['user'])) { $response['message'] = 'Требуется вход в систему.'; http_response_code(401); echo json_encode($response); exit(); }
        $author_original_case = $_SESSION['user'];
        $author_lower = strtolower($author_original_case);
        $all_users = get_users();



        if (!isset($all_users[$author_lower])) {
             error_log("Publish attempt by session user '{$author_original_case}' but user data not found for key '{$author_lower}'.");
             $response['message'] = 'Ошибка проверки данных пользователя. Попробуйте войти заново.'; http_response_code(500); echo json_encode($response); exit();
        }
        if (isset($all_users[$author_lower]['last_upload_timestamp']) && (time() - (int)$all_users[$author_lower]['last_upload_timestamp']) < UPLOAD_COOLDOWN_SECONDS) {
            $time_remaining = UPLOAD_COOLDOWN_SECONDS - (time() - (int)$all_users[$author_lower]['last_upload_timestamp']);
            $hours_remaining = floor($time_remaining / 3600); $minutes_remaining = floor(($time_remaining % 3600) / 60);
            $response['message'] = "Следующую публикацию можно сделать через " . $hours_remaining . " ч " . $minutes_remaining . " мин.";
            http_response_code(429); echo json_encode($response); exit();
        }


        $title_processed = remove_line_breaks_for_title(trim($_POST['title'] ?? ''));
        $description_processed = trim($_POST['description'] ?? '');
        $error_message = '';


        if (empty($title_processed)) $error_message = "Название не может быть пустым.";
        elseif (mb_strlen($title_processed, 'UTF-8') > TITLE_MAX_LENGTH) $error_message = "Название слишком длинное (" . mb_strlen($title_processed, 'UTF-8') . " / " . TITLE_MAX_LENGTH . ").";
        if (empty($description_processed)) $error_message = $error_message ?: "Описание не может быть пустым.";
        elseif (mb_strlen($description_processed, 'UTF-8') > DESCRIPTION_MAX_LENGTH) $error_message = $error_message ?: "Описание слишком длинное (" . mb_strlen($description_processed, 'UTF-8') . " / " . DESCRIPTION_MAX_LENGTH . ").";


        $image_tmp_name = $_FILES['image']['tmp_name'] ?? null;
        $file_tmp_name = $_FILES['file']['tmp_name'] ?? null;
        $image_error_code = $_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE;
        $file_error_code = $_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE;
        $image_size = $_FILES['image']['size'] ?? 0;
        $file_size = $_FILES['file']['size'] ?? 0;
        $image_original_name = $_FILES['image']['name'] ?? '';
        $file_original_name = $_FILES['file']['name'] ?? '';



        $upload_errors = [
            UPLOAD_ERR_OK         => "Файл загружен без ошибок.",
            UPLOAD_ERR_INI_SIZE   => "Файл слишком большой (ошибка INI).",
            UPLOAD_ERR_FORM_SIZE  => "Файл слишком большой (ошибка FORM).",
            UPLOAD_ERR_PARTIAL    => "Загружен только часть файла.",
            UPLOAD_ERR_NO_FILE    => "Файл не выбран.",
            UPLOAD_ERR_NO_TMP_DIR => "Отсутствует временная директория для загрузки.",
            UPLOAD_ERR_CANT_WRITE => "Ошибка записи файла на диск на сервере.",
            UPLOAD_ERR_EXTENSION  => "Расширение PHP остановило загрузку файла."
        ];


        if (empty($error_message)) {
             if ($file_error_code !== UPLOAD_ERR_OK) {
                 $error_message = $upload_errors[$file_error_code] ?? "Ошибка загрузки файла проекта.";
             } else {
                 $file_extension = strtolower(pathinfo($file_original_name, PATHINFO_EXTENSION));
                 if ($file_extension !== ALLOWED_EXTENSION) $error_message = "Неверный тип файла проекта (требуется ." . ALLOWED_EXTENSION . ").";
                 elseif ($file_size > PROJECT_FILE_MAX_SIZE_MB * 1024 * 1024) $error_message = "Файл проекта слишком большой (> " . PROJECT_FILE_MAX_SIZE_MB . "MB).";

                 elseif ($file_tmp_name && filesize($file_tmp_name) === 0) $error_message = "Файл проекта пустой.";
             }
        }


         if (empty($error_message)) {
            if ($image_error_code !== UPLOAD_ERR_OK) {

                 $error_message = str_replace("файла проекта", "файла обложки", ($upload_errors[$image_error_code] ?? "Ошибка загрузки файла обложки."));
            } else {
                $image_tmp_name = $_FILES['image']['tmp_name'];

                $image_info = @getimagesize($image_tmp_name);

                if ($image_info === false) {
                    $error_message = "Не удалось прочитать файл обложки (возможно, поврежден или не является изображением).";
                } elseif (!in_array($image_info[2], ALLOWED_IMAGE_TYPES)) {
                    $error_message = "Неверный тип файла обложки (только JPG, PNG).";
                } elseif ($image_info[0] !== $image_info[1]) {
                     $error_message = "Файл обложки должен быть квадратным (одинаковая ширина и высота).";
                } elseif ($image_size > IMAGE_MAX_SIZE_MB * 1024 * 1024) {
                    $error_message = "Файл обложки слишком большой (> " . IMAGE_MAX_SIZE_MB . "MB).";
                }

                elseif ($image_tmp_name && filesize($image_tmp_name) === 0) $error_message = "Файл обложки пустой.";
            }
        }

        $local_image_path = null;
        $local_file_path = null;
        $new_game_id = null;

        if (empty($error_message)) {
            $new_game_id = uniqid('game_');

            $image_extension = strtolower(pathinfo($image_original_name, PATHINFO_EXTENSION));
            $image_filename = $new_game_id . '_cover.' . $image_extension;
            
            $local_image_path = save_to_local($image_tmp_name, IMAGES_DIR, $image_filename);

            if ($local_image_path === false) {
                $error_message = "Не удалось сохранить файл обложки.";
            }
        }

        if (empty($error_message)) {
            $file_extension = strtolower(pathinfo($file_original_name, PATHINFO_EXTENSION));
            $project_filename = $new_game_id . '.' . $file_extension;

            $local_file_path = save_to_local($file_tmp_name, PROJECTS_DIR, $project_filename);

            if ($local_file_path === false) {
                $error_message = "Не удалось сохранить файл проекта.";
            }
        }

        if (empty($error_message) && $local_image_path && $local_file_path && $new_game_id) {
            $description_safe = htmlspecialchars($description_processed, ENT_QUOTES, 'UTF-8');
            $game_id_saved = add_game($title_processed, $local_image_path, $local_file_path, $author_original_case, $description_safe);

            if ($game_id_saved) {
                $all_users[$author_lower]['last_upload_timestamp'] = time();
                if (save_users($all_users)) {
                     $response = ['success' => true, 'message' => "Проект успешно опубликован!", 'redirectUrl' => $_SERVER['PHP_SELF'] . "?page=game&id=" . urlencode($game_id_saved) . "&status=published"];
                } else {
                    $response = ['success' => true, 'message' => "Проект опубликован, но не удалось сохранить ваш статус (ошибка сервера).", 'redirectUrl' => $_SERVER['PHP_SELF'] . "?page=game&id=" . urlencode($game_id_saved) . "&status=published_user_save_error"];
                }
            } else {
                $response['message'] = "Ошибка сервера при сохранении данных игры."; http_response_code(500);
            }
        } else {
             if (empty($error_message)) $error_message = 'Неизвестная ошибка обработки.';
             $response['message'] = "Ошибка публикации: " . $error_message;
             http_response_code(400);
        }
        echo json_encode($response); exit();
    }

    elseif (isset($_POST['add_comment'])) {
         /* ... add comment logic ... */

         if (!isset($_SESSION['user'])) {
             $_SESSION['temp_error_msg'] = "Войдите, чтобы комментировать.";
             $gid = $_POST['game_id'] ?? null;
             safe_redirect($_SERVER['PHP_SELF'].($gid ? "?page=game&id=".urlencode($gid)."#comments-section" : "?page=home"));
         } else {
             $game_id = $_POST['game_id'] ?? null;
             $comment_raw = $_POST['comment'] ?? '';
             $author = $_SESSION['user'];
             $error = null;

            if (!$game_id) $error = "ID игры не найден.";
            elseif (empty(trim($comment_raw))) $error = "Комментарий не может быть пустым.";
            elseif (mb_strlen(trim($comment_raw), 'UTF-8') > COMMENT_MAX_LENGTH) $error = "Комментарий слишком длинный (".mb_strlen(trim($comment_raw), 'UTF-8')." / ".COMMENT_MAX_LENGTH.").";
            else {

                 if (add_comment($game_id, $author, $comment_raw)) {

                     safe_redirect($_SERVER['PHP_SELF']."?page=game&id=".urlencode($game_id)."&status=comment_added#comments-section");
                 } else {
                     $error = "Ошибка сохранения комментария.";
                 }
             }

            if ($error) {
                 $_SESSION['temp_error_msg'] = $error;
                 $_SESSION['temp_comment_text'] = $comment_raw;
                 safe_redirect($_SERVER['PHP_SELF']."?page=game&id=".urlencode($game_id)."#comments-section");
            }
         }
         exit();
    }



    error_log("Unhandled POST request. Referer: " . ($_SERVER['HTTP_REFERER'] ?? 'N/A'));
    $_SESSION['temp_error_msg'] = "Неверный запрос.";
    safe_redirect($_SERVER['PHP_SELF'] . "?page=home");
    exit();


}



elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {

    if ($page_render === 'increment_download') {
        header('Content-Type: application/json'); $game_id = $_GET['id'] ?? null; $response = ['success' => false, 'incremented' => false];
        if (!isset($_SESSION['user'])) { $response['message'] = 'Требуется вход.'; http_response_code(401); echo json_encode($response); exit(); }
        $user_login = $_SESSION['user'];
        if (!$game_id || !get_game($game_id)) { $response['message'] = 'Неверный ID игры.'; http_response_code(404); echo json_encode($response); exit(); }

        if (has_user_downloaded($game_id, $user_login)) { $response = ['success' => true, 'incremented' => false, 'message' => 'Скачивание уже учтено.']; }
        else {

            $rec_ok = record_user_download($game_id, $user_login);
            $inc_ok = false;

            if ($rec_ok) {
               $inc_ok = increment_total_download_count($game_id);
            }

            if ($inc_ok && $rec_ok) {

                $game_updated = get_game($game_id);
                $updated_dl_count = $game_updated ? ($game_updated['downloads'] ?? 0) : ($game['downloads'] + 1);
                $response = ['success' => true, 'incremented' => true, 'message' => 'Скачивание учтено.', 'new_count' => $updated_dl_count];
            }
            else {

                if (!$rec_ok) error_log("DL count fail: Failed to record user download G:$game_id U:$user_login");
                if ($rec_ok && !$inc_ok) error_log("DL count fail: Failed to increment total count G:$game_id U:$user_login");

                $response['message'] = 'Ошибка обновления записи счетчика.'; http_response_code(500);
            }
        }
        echo json_encode($response); exit();
    }

    elseif ($page_render === 'vote') {
        header('Content-Type: application/json'); $response = ['success' => false];
        if (!isset($_SESSION['user'])) { $response['message'] = 'Требуется вход.'; http_response_code(401); echo json_encode($response); exit(); }
        $user_login = $_SESSION['user'];
        $game_id = $_GET['id'] ?? null; $action = $_GET['action'] ?? null;
        if (!$game_id || !get_game($game_id)) { $response['message'] = 'Неверный ID игры.'; http_response_code(404); echo json_encode($response); exit(); }
        if ($action !== 'like' && $action !== 'dislike') { $response['message'] = 'Неверное действие голоса.'; http_response_code(400); echo json_encode($response); exit(); }


        $result = record_vote($game_id, $user_login);
        if ($result['success']) { $response = $result; http_response_code(200); }
        else { $response['message'] = $result['message'] ?? 'Ошибка записи голоса.'; http_response_code(500); error_log("Vote record fail: Game: ".$game_id." User: ".$user_login." Action: ".$action." Result: ".json_encode($result)); }
        echo json_encode($response); exit();
    }


}



$preserved_comment_text = '';

$registration_message = $_SESSION['registration_message'] ?? null; unset($_SESSION['registration_message']);
$login_message_temp = $_SESSION['login_message'] ?? null; unset($_SESSION['login_message']);
$admin_message = $_SESSION['admin_message'] ?? null; unset($_SESSION['admin_message']);


if (isset($_GET['status'])) {
    switch ($_GET['status']) {
        case 'comment_added': $comment_message = "<span class='message'>Комментарий успешно добавлен!</span>"; break;
        case 'comment_deleted': $comment_message = "<span class='message'>Комментарий удален.</span>"; break;
        case 'published': $publish_message = "Проект успешно опубликован!"; break;
        case 'published_user_save_error': $publish_message = "Проект опубликован, но не удалось сохранить ваш статус (ошибка сервера)."; break;
        case 'account_deleted': $login_message = "Ваш аккаунт был удален. Вы были выведены из системы."; break;
        case 'account_deleted_by_admin': $login_message = "Ваш аккаунт был удален администратором."; break;
        case 'action_completed':


             if ($page_render === 'admin' && !$admin_message) {
                  $admin_message = "<p class='message'>Действие выполнено.</p>";
             } elseif ($page_render !== 'admin' && !$general_error_message && !$registration_message && !$login_message) {

                  $general_error_message = "<span class='message'>Действие выполнено.</span>";
             }
             break;
        default:


            break;
    }
}


if ($login_message_temp) {

     $login_message = $login_message_temp;
}



if (isset($_SESSION['temp_error_msg'])) {
    $error_msg_text = htmlspecialchars($_SESSION['temp_error_msg'], ENT_QUOTES, 'UTF-8');

    if ($page_render === 'game' && isset($_GET['id']) && (strpos($_SESSION['temp_error_msg'], 'комментар') !== false || strpos($_SESSION['temp_error_msg'], 'empty') !== false || strpos($_SESSION['temp_error_msg'], 'длинн') !== false || strpos($_SESSION['temp_error_msg'], 'Войдите') !== false)) {
         $comment_message = "<span class='error-message'>" . $error_msg_text . "</span>";
    } else {

         $general_error_message = "<span class='error-message'>" . $error_msg_text . "</span>";
    }
    unset($_SESSION['temp_error_msg']);

    if (isset($_SESSION['temp_comment_text'])) {
        $preserved_comment_text = htmlspecialchars($_SESSION['temp_comment_text'], ENT_QUOTES, 'UTF-8');
        unset($_SESSION['temp_comment_text']);
    }
}


$show_reg_form = isset($_GET['show_reg_form']);
$show_login_form = isset($_GET['show_login_form']);
$reg_success = isset($_GET['reg_success']);


?>
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>NewCatroid Community</title>
  <link rel="icon" href="http://a1108021.xsph.ru/TVFXPocketCode/1.png" type="image/png">
  <style>
    /* --- CSS Styles (Mostly unchanged, minor adjustments) --- */
    :root{--primary-color:#380365;--primary-dark:#007a87;--light-gray:#f0f0f0;--medium-gray:#ccc;--dark-gray:#555;--card-bg:#ffffff;--card-border:#e0e0e0;--text-color:#333;--text-light:#666;--danger-bg:#f8d7da;--danger-border:#f5c6cb;--danger-text:#721c24;--success-bg:#d4edda;--success-border:#c3e6cb;--success-text:#155724;--navbar-hover-bg:#f0f5f6; --mobile-nav-width: 250px; --like-color: #28a745; --dislike-color: #dc3545;}
    body{font-family:-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;background-color:#f7f9fa;margin:0;padding:0;color:var(--text-color);line-height:1.6}
    .container{width:calc(100% - 230px - 40px);margin:65px 20px 20px 250px;min-width:400px;box-sizing:border-box; transition: margin-left 0.3s ease-in-out;}
    .navbar{background-color:#fff;overflow-y:auto;width:230px;height:100vh;position:fixed;top:0;left:0;box-shadow:2px 0 5px rgba(0,0,0,0.08);padding:20px;z-index:102;display:flex;flex-direction:column;box-sizing:border-box; transition: transform 0.3s ease-in-out;}
    .navbar a{display:block;width:100%;color:var(--text-color);text-align:left;padding:12px 18px;text-decoration:none;margin-bottom:8px;border-radius:6px;transition:background-color 0.2s ease-in-out, color 0.2s ease-in-out;box-sizing:border-box;font-weight:500}
    .navbar a:hover, .navbar a.active{background-color:var(--navbar-hover-bg);color:var(--primary-dark)}
    .bar2{background-color:var(--primary-color);width:calc(100% - 230px);height:50px;position:fixed;top:0;left:230px;z-index:100;display:flex;align-items:center;padding-left:20px;color:white;font-size:1.2em;font-weight:500;box-sizing:border-box;box-shadow:0 2px 4px rgba(0,0,0,0.1); transition: left 0.3s ease-in-out, width 0.3s ease-in-out;}
    .bar2 p{margin:0}
    #mobile-menu-toggle { display: none; position: fixed; top: 5px; left: 15px; z-index: 103; background: var(--primary-color); border: none; color: white; padding: 8px 12px; border-radius: 4px; cursor: pointer; font-size: 1.2em; line-height: 1; }
    #mobile-menu-toggle span { display: block; width: 20px; height: 2px; background-color: white; margin: 4px 0; transition: transform 0.3s, opacity 0.3s; }
    #mobile-menu-toggle.is-active span:nth-child(1) { transform: translateY(6px) rotate(45deg); } #mobile-menu-toggle.is-active span:nth-child(2) { opacity: 0; } #mobile-menu-toggle.is-active span:nth-child(3) { transform: translateY(-6px) rotate(-45deg); }
    #menu-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); z-index: 101; }
    form{margin-bottom:30px;padding:20px;border:1px solid var(--card-border);border-radius:8px;background-color:var(--card-bg);box-shadow:0 1px 4px rgba(0,0,0,0.04)}
    form h2{margin-top:0;margin-bottom:20px;font-size:1.5em;color:var(--text-color);font-weight:600;border-bottom:1px solid var(--card-border);padding-bottom:10px}
    label{display:block;margin-bottom:6px;font-weight:500;color:var(--dark-gray);font-size:0.95em}
    input[type="text"],input[type="password"],input[type="email"],textarea,input[type="file"]{width:100%;padding:10px 12px;margin-bottom:15px;border:1px solid var(--medium-gray);border-radius:6px;box-sizing:border-box;font-size:1em;transition:border-color 0.2s ease-in-out}
    input[type="text"]:focus,input[type="password"]:focus,input[type="email"]:focus,textarea:focus{border-color:var(--primary-color);outline:none}
    input[type="file"]{padding:8px 10px;background-color:#f9f9f9}
    textarea{min-height:120px;resize:vertical}
    button,input[type="submit"]{background-color:var(--primary-color);color:white;padding:10px 20px;border:none;border-radius:6px;cursor:pointer;font-size:1em;font-weight:500;transition:background-color 0.2s ease-in-out, opacity 0.2s ease-in-out; margin-right:10px}
    button[type="button"]{background-color:var(--light-gray);color:var(--text-color);border:1px solid var(--medium-gray)}
    button.delete-button, input.delete-button { background-color: var(--danger-color, #dc3545); padding: 5px 10px; font-size: 0.9em; color: white;} /* Ensure color */
    button.delete-button:hover, input.delete-button:hover { background-color: var(--danger-dark, #c82333); }
    button:hover,input[type="submit"]:hover{background-color:var(--primary-dark)}
    button[type="button"]:hover{background-color:#e0e0e0}
    input[type="submit"]:disabled, button:disabled { background-color: var(--medium-gray); cursor: not-allowed; opacity: 0.7; } /* Apply disabled styles to buttons too */
    .message, .error-message, span.message, span.error-message { display: block; padding:12px 18px;margin-bottom:20px;border-radius:6px;border:1px solid transparent;font-size:0.95em; }
    .message, span.message {background-color:var(--success-bg);border-color:var(--success-border);color:var(--success-text)}
    .error-message, span.error-message {background-color:var(--danger-bg);border-color:var(--danger-border);color:var(--danger-text)}
    #publish-message-area { margin-bottom: 15px; min-height: 1.5em;}
    .profile, .game-details, .admin-panel{border:1px solid var(--card-border);padding:25px;border-radius:8px;margin-bottom:30px;background-color:var(--card-bg);box-shadow:0 2px 5px rgba(0,0,0,0.05)}
    .profile h2, .game-details h2, .admin-panel h2{margin-top:0;color:var(--text-color);font-weight:600;border-bottom:1px solid var(--card-border);padding-bottom:12px;margin-bottom:20px;font-size:1.6em}
    .profile h3, .admin-panel h3 { margin-top: 25px; margin-bottom: 15px; font-size: 1.3em; color: var(--primary-dark); border-bottom: 1px solid #eee; padding-bottom: 8px;}
    .profile ul, .admin-list ul { list-style: none; padding-left: 0; }
    .profile li, .admin-list li { margin-bottom: 10px; background-color: #f9f9f9; padding: 10px 15px; border-radius: 4px; border: 1px solid #eee; display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 10px; }
    .admin-list li > div:first-child { flex-grow: 1; margin-right: 10px; word-break: break-word; } /* Allow wrapping */
    .profile li a, .admin-list li a { text-decoration: none; color: var(--primary-color); font-weight: 500;}
    .profile li a:hover, .admin-list li a:hover { text-decoration: underline; }
    .profile li span, .admin-list li span { font-size: 0.9em; color: var(--text-light); margin-left: 10px;}
    .admin-list form { margin: 0; padding: 0; border: none; box-shadow: none; display: inline; }
    .profile p, .game-details p{margin-bottom:10px;color:var(--text-light)}
    .profile p strong, .game-details p strong{color:var(--text-color);margin-right:5px;min-width:80px;display:inline-block; font-weight: 600;}
    .game-details strong{min-width:100px}
    .game-details img{max-width:200px;height:auto;border-radius:6px;margin-bottom:20px;border:1px solid var(--card-border);display:block}
    /* Game Actions (Download, Like, Dislike) */
    .game-actions { margin-top: 15px; margin-bottom: 25px; display: flex; align-items: center; gap: 15px; flex-wrap: wrap; }
    .game-actions button { padding: 8px 15px; font-size: 0.95em; }
    .vote-buttons { display: flex; align-items: center; gap: 8px; }
    .vote-button { background-color: var(--light-gray); color: var(--text-color); border: 1px solid var(--medium-gray); padding: 6px 12px; font-size: 0.9em; display: inline-flex; align-items: center; gap: 5px; border-radius: 4px; cursor: pointer; transition: background-color 0.2s, border-color 0.2s, color 0.2s; }
    .vote-button:hover:not(:disabled) { background-color: #e0e0e0; } /* Hover only if not disabled */
    .vote-button.liked { background-color: var(--like-color); color: white; border-color: var(--like-color); }
    .vote-button.disliked { background-color: var(--dislike-color); color: white; border-color: var(--dislike-color); }
    .vote-count { font-size: 0.9em; color: var(--text-light); min-width: 10px; display: inline-block; text-align: right;}
    .vote-button .icon { font-style: normal; font-weight: normal; font-size: 1.1em; line-height: 1;} /* Simple text icons */
    .comments{margin-top:30px;border:1px solid var(--card-border);padding:25px;border-radius:8px;background-color:var(--card-bg);box-shadow:0 2px 5px rgba(0,0,0,0.05)}
    .comments h3, .comments h4{margin-top:0;color:var(--text-color);font-weight:600;border-bottom:1px solid var(--card-border);padding-bottom:12px;margin-bottom:20px;font-size:1.3em}
    .comments h4{font-size:1.2em;border-bottom:none;margin-bottom:15px}
    .comment{margin-bottom:15px;padding:15px;border:1px solid #eee;border-radius:6px;background-color:#f9fafb;position:relative; display: flex; flex-wrap: wrap; justify-content: space-between; align-items: flex-start; gap: 10px;}
    .comment-content { flex-grow: 1; margin-right: 10px; word-break: break-word; } /* Allow comment text to wrap */
    .comment-actions { flex-shrink: 0; }
    .comment-author a { font-weight:600; color:var(--primary-dark); margin-right:8px; text-decoration: none; }
    .comment-author a:hover { text-decoration: underline; }
    .comment-meta{font-size:0.8em;color:var(--text-light);margin-left:10px}
    .comment p { margin: 8px 0 0 0; padding-left: 0; border-left: none; white-space: pre-wrap; } /* Keep pre-wrap */
    .game-details p.description-text { border-left: none; padding-left: 0; white-space: pre-wrap; word-wrap: break-word; }
    .comment .comment-actions form { display: inline; margin: 0; padding: 0; border: none; box-shadow: none;}
    #loading{display:none;position:fixed;top:0;left:0;width:100%;height:100%;background-color:rgba(0,0,0,0.75);z-index:1000;display:flex;flex-direction:column;justify-content:center;align-items:center;color:white;font-size:1.5em;text-align:center}
    #loading span#progress{display:block;margin-top:15px;font-weight:bold;font-size:1.2em}
    #loading span#loading-message{font-size: 1em; margin-top: 10px;}
    .carousel-section{margin-bottom:40px}
    .carousel-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:15px}
    .carousel-header h2{margin:0;color:var(--text-color);font-size:1.4em;font-weight:600}
    .show-more a{text-decoration:none;color:var(--primary-color);font-weight:500;font-size:0.95em;display:flex;align-items:center;transition:color 0.2s}
    .show-more a:hover{color:var(--primary-dark);text-decoration:underline}
    .show-more a::after{content:' →';margin-left:5px;font-weight:normal}
    .carousel{display:flex;overflow-x:auto;overflow-y:hidden;gap:16px;padding:5px 0 20px 5px;-webkit-overflow-scrolling:touch;scroll-snap-type:x mandatory; scrollbar-width: thin; scrollbar-color: var(--medium-gray) #f1f1f1;} /* Added Firefox scrollbar styling */
    .carousel::-webkit-scrollbar{height:8px}
    .carousel::-webkit-scrollbar-track{background:#f1f1f1;border-radius:4px}
    .carousel::-webkit-scrollbar-thumb{background-color:var(--medium-gray);border-radius:4px}
    .carousel::-webkit-scrollbar-thumb:hover{background-color:var(--dark-gray)}
    .carousel-item{background-color:var(--card-bg);border:1px solid var(--card-border);border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,0.05);flex:0 0 auto;width:160px;overflow:hidden;text-align:center;padding-bottom:10px;transition:transform 0.2s ease-in-out, box-shadow 0.2s ease-in-out;scroll-snap-align:start}
    .carousel-item a.card-link{text-decoration:none;color:inherit;display:block}
    .carousel-item:hover{transform:translateY(-4px);box-shadow:0 4px 10px rgba(0,0,0,0.08)}
    .carousel-item img{width:100%;height:120px;object-fit:cover;border-bottom:1px solid var(--card-border);display:block;border-top-left-radius:8px;border-top-right-radius:8px}
    .carousel-item img[alt="Нет обложки"], .carousel-item-placeholder {height:120px; background-color:var(--light-gray); display:flex; align-items:center; justify-content:center; color:var(--dark-gray); font-size:0.9em; border-bottom:1px solid var(--card-border); border-top-left-radius:8px; border-top-right-radius:8px;} /* Consistent Placeholder */
    .carousel-item .item-content{padding:10px}
    .carousel-item h3{font-size:0.95em;font-weight:600;margin:0 0 5px 0;line-height:1.3;color:var(--text-color);white-space:normal;overflow:hidden;text-overflow:ellipsis;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;min-height:2.6em; word-wrap: break-word;}
    .carousel-item p.downloads{font-size:0.85em;color:var(--text-light);margin:0;display:flex;align-items:center;justify-content:center}
    .carousel-item p.downloads::before{content:'↓';margin-right:5px;font-weight:bold;display:inline-block}
    .hidden{display:none}
    .char-counter{font-size:0.8em;color:var(--text-light);text-align:right;margin-top:-10px; margin-bottom:10px;display:block; height: 1em;}
    .progress-container{width:100%;background-color:var(--light-gray);border-radius:4px;margin-top:5px; margin-bottom:15px;overflow:hidden;display:none;}
    .progress-bar{width:0%;height:15px;background-color:var(--primary-color);text-align:center;line-height:15px;color:white;font-size:0.8em;border-radius:4px;transition:width 0.2s ease-out;}
    #upload-status{font-size:0.9em;color:var(--text-light);margin-top:5px;display:none;}
    
    /* Watermark Style */
    .watermark {
        position: fixed;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%) rotate(-30deg);
        font-size: 8vw;
        color: rgba(0, 0, 0, 0.05);
        pointer-events: none;
        white-space: nowrap;
        z-index: 9999;
        user-select: none;
    }
    
    /* Creator Page Style */
    .creator-page {
        border: 1px solid var(--card-border);
        padding: 25px;
        border-radius: 8px;
        margin-bottom: 30px;
        background-color: var(--card-bg);
        box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        text-align: center;
    }
    .creator-page h2 {
        margin-top: 0;
        color: var(--text-color);
        font-weight: 600;
        border-bottom: 1px solid var(--card-border);
        padding-bottom: 12px;
        margin-bottom: 20px;
        font-size: 1.6em;
    }
    .creator-page p {
        margin-bottom: 15px;
        font-size: 1.1em;
        color: var(--text-color);
    }
    .creator-page .telegram-link {
        display: inline-block;
        margin-top: 10px;
        padding: 12px 24px;
        background-color: #0088cc; /* Telegram blue */
        color: white;
        text-decoration: none;
        border-radius: 6px;
        font-weight: 500;
        transition: background-color 0.2s;
    }
    .creator-page .telegram-link:hover {
        background-color: #0077b5;
    }

    @media (max-width: 768px){
        #mobile-menu-toggle { display: block; }
        .navbar { transform: translateX(-100%); width: var(--mobile-nav-width); z-index: 102; padding-top: 60px; background-color: #fff; /* Ensure bg */ }
        .navbar.is-open { transform: translateX(0); box-shadow: 3px 0 6px rgba(0,0,0,0.1);} /* Add shadow when open */
        .bar2 { width: 100%; left: 0; z-index: 101; padding-left: 60px} /* Adjust padding for toggle */
        .container { width: 100%; margin: 65px 0 20px 0; padding: 0 15px; min-width: unset; transition: none; }
        .carousel-item { width: 140px; }
        .carousel-item img, .carousel-item-placeholder { height: 100px; }
        #menu-overlay.is-visible { display: block; z-index: 101; }
        .admin-list li { flex-direction: column; align-items: flex-start;}
        .admin-list li > div:last-child { margin-top: 10px; width: 100%; text-align: right;} /* Adjust button alignment */
        .comment { flex-direction: column; align-items: flex-start; }
        .comment-actions { margin-top: 10px; width: 100%; text-align: right;} /* Adjust button alignment */
        .game-actions { flex-direction: column; align-items: flex-start; gap: 10px;} /* Adjust gap */
        .vote-buttons { width: 100%; justify-content: flex-start; } /* Align votes left on mobile */
    }
  </style>
</head>
<body>

<div class="watermark">Исходный код сайта</div>

<!-- Mobile Menu Toggle -->
<button id="mobile-menu-toggle" aria-label="Открыть меню" aria-expanded="false">
    <span></span><span></span><span></span>
</button>
<div id="menu-overlay"></div>

<!-- Sidebar Navigation -->
<div class="navbar" id="main-navbar">
    <!-- Navigation Links -->
    <a href="<?php echo $_SERVER['PHP_SELF']; ?>?page=home" class="<?php echo ($page_render === 'home') ? 'active' : ''; ?>">Главная</a>
    <a href="<?php echo $_SERVER['PHP_SELF']; ?>?page=creator" class="<?php echo ($page_render === 'creator') ? 'active' : ''; ?>">Создатель сайта</a>
    <?php if (isset($_SESSION['user'])): ?>
      <?php

        $current_session_user_original = $_SESSION['user'];
        $all_users_display = get_users();
        $current_user_lower_display = strtolower($current_session_user_original);
        $currentUserDisplay = htmlspecialchars($all_users_display[$current_user_lower_display]['original_login'] ?? $current_session_user_original, ENT_QUOTES, 'UTF-8');


        $currentUserUrl = urlencode($all_users_display[$current_user_lower_display]['original_login'] ?? $current_session_user_original);


        $profileLinkActive = ($page_render === 'profile' && isset($_GET['user']) && strtolower(urldecode($_GET['user'])) === $current_user_lower_display);

      ?>
      <a href="<?php echo $_SERVER['PHP_SELF']; ?>?page=profile&user=<?php echo $currentUserUrl; ?>" class="<?php echo $profileLinkActive ? 'active' : ''; ?>">Профиль (<?php echo $currentUserDisplay; ?>)</a>
      <a href="<?php echo $_SERVER['PHP_SELF']; ?>?page=publish" class="<?php echo ($page_render === 'publish') ? 'active' : ''; ?>">Добавить Проект</a>
      <?php if ($is_admin): ?><a href="<?php echo $_SERVER['PHP_SELF']; ?>?page=admin" class="<?php echo ($page_render === 'admin') ? 'active' : ''; ?>">Админ панель</a><?php endif; ?>
      <a href="<?php echo $_SERVER['PHP_SELF']; ?>?logout=true">Выход</a>
    <?php else: ?>
      <a href="#" onclick="showRegistrationForm(); return false;">Регистрация</a>
      <a href="#" onclick="showLoginForm(); return false;">Войти</a>
    <?php endif; ?>
</div>

<!-- Top Bar -->
<div class="bar2"><p>NewCatroid Community</p></div>

<!-- Main Content Container -->
<div class="container">

  <!-- Display Global Messages -->
  <?php

    if ($general_error_message) echo $general_error_message;


    if ($registration_message && !$show_reg_form && !$reg_success) echo $registration_message;

    if ($login_message && !$show_login_form) echo "<p class='" . (strpos($login_message, 'удален') !== false ? 'message' : 'error-message') . "'>" . htmlspecialchars($login_message, ENT_QUOTES, 'UTF-8') . "</p>";

    if ($publish_message) echo "<p class='message'>" . htmlspecialchars($publish_message, ENT_QUOTES, 'UTF-8') . "</p>";


  ?>

  <!-- Hidden Authentication Forms -->
  <?php if (!isset($_SESSION['user'])): ?>
    <div id="registrationForm" style="display:none;">
        <form method="post" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>">
            <h2>Регистрация</h2>
            <?php if ($registration_message && ($show_reg_form || $reg_success)) echo $registration_message; ?>
            <label for="reg_login">Логин:</label>
            <input type="text" id="reg_login" name="login" required pattern="^[a-zA-Z0-9_-]{3,20}$" title="3-20 символов: буквы, цифры, _ -">
            <label for="reg_email">Email:</label>
            <input type="email" id="reg_email" name="email" required>
            <label for="reg_password">Пароль (мин. 6 симв.):</label>
            <input type="password" id="reg_password" name="password" required minlength="6"><br>
            <input type="submit" name="register" value="Зарегистрироваться">
            <button type="button" onclick="hideAuthForms();">Отмена</button>
        </form>
    </div>
    <div id="loginForm" style="display:none;">
        <form method="post" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>">
            <h2>Вход в аккаунт</h2>
             <?php if ($login_message && $show_login_form) echo "<p class='" . (strpos($login_message, 'удален') !== false ? 'message' : 'error-message') . "'>" . htmlspecialchars($login_message, ENT_QUOTES, 'UTF-8') . "</p>"; ?>
            <label for="login_identifier">Логин или Email:</label>
            <input type="text" id="login_identifier" name="identifier" required>
            <label for="login_password">Пароль:</label>
            <input type="password" id="login_password" name="password" required><br>
            <input type="submit" name="login_action" value="Войти">
            <button type="button" onclick="hideAuthForms();">Отмена</button>
        </form>
    </div>
  <?php endif; ?>

  <!-- Page-Specific Content Area -->
  <div id="mainContent">
    <?php

      $loggedIn = isset($_SESSION['user']);
      $loggedInUser = $loggedIn ? $_SESSION['user'] : null;


      switch($page_render) {
        case 'home': break;

        case 'creator':
            echo "<div class='creator-page'>
                    <h2>Создатель сайта</h2>
                    <p>Создатель Алексей и Майнд (TV FX Squad And MindTeam)</p>
                    <p>Telegram канал по вопросам</p>
                    <a href='https://t.me/TVFXSquad1' class='telegram-link' target='_blank'>Перейти в Telegram</a>
                  </div>";
            break;

        case 'profile':
            $profile_user_raw = isset($_GET['user']) ? urldecode($_GET['user']) : ($loggedInUser ?? 'Гость');
            $profile_user_lookup = strtolower($profile_user_raw);

            $all_users = get_users();

            if ($profile_user_lookup === strtolower('Гость')) {
                 echo "<div class='profile'><p>Профиль гостя. <a href='#' onclick='showLoginForm(); return false;'>Войдите</a> или <a href='#' onclick='showRegistrationForm(); return false;'>зарегистрируйтесь</a>.</p></div>";
            } elseif (!isset($all_users[$profile_user_lookup])) {

                 echo "<div class='profile'><p class='error-message'>Пользователь '" . htmlspecialchars($profile_user_raw, ENT_QUOTES, 'UTF-8') . "' не найден.</p></div>";
            }
            else {
                 $user_data = $all_users[$profile_user_lookup];

                 $display_login_case = $user_data['original_login'] ?? $profile_user_raw;
                 $display_login_safe = htmlspecialchars($display_login_case, ENT_QUOTES, 'UTF-8');

                 echo "<div class='profile'><h2>Профиль: " . $display_login_safe . "</h2>";
                 echo "<p><strong>Логин:</strong> " . $display_login_safe . "</p>";
                 $admin_logins_lower_check = array_map('strtolower', ADMIN_USERNAMES);
                 echo "<p><strong>Роль:</strong> " . (in_array($profile_user_lookup, $admin_logins_lower_check) ? 'Администратор' : 'Пользователь') . "</p>";

                 echo "<h3>Проекты пользователя:</h3>";
                 $allGames = get_games();

                 $userGames = array_filter($allGames, fn($g) => isset($g['author_lower']) && $g['author_lower'] === $profile_user_lookup);
                 krsort($userGames);
                 if (!empty($userGames)) {
                     echo "<ul>";
                     foreach ($userGames as $id => $g) {
                         $gt = htmlspecialchars($g['title']??'Без назв.',ENT_QUOTES,'UTF-8');
                         $gl = htmlspecialchars($_SERVER['PHP_SELF'].'?page=game&id='.urlencode($id),ENT_QUOTES,'UTF-8');
                         $gd = (int)($g['downloads']??0);
                         $gdate = isset($g['timestamp']) ? date('d.m.y', $g['timestamp']) : '?';
                         echo "<li><div><a href='{$gl}'>{$gt}</a> <span>({$gd} скач., {$gdate})</span></div></li>";
                     }
                     echo "</ul>";
                 } else {
                     echo "<p>Нет проектов.</p>";
                 }
                 echo "</div>";
            }
            break;

        case 'publish':

            if (!$loggedIn) {
                echo "<div class='error-message'>Вы должны <a href='#' onclick='showLoginForm(); return false;'>войти</a>, чтобы опубликовать проект.</div>";
            } else {


                 $author_lower_check = strtolower($loggedInUser);
                 $users_for_cooldown = get_users();
                 $can_upload = true;
                 $cooldown_message_html = '';

                 if (isset($users_for_cooldown[$author_lower_check]['last_upload_timestamp'])) {
                     $last_upload_time = (int)$users_for_cooldown[$author_lower_check]['last_upload_timestamp'];
                     $time_elapsed = time() - $last_upload_time;
                     if ($time_elapsed < UPLOAD_COOLDOWN_SECONDS) {
                         $can_upload = false;
                         $time_remaining = UPLOAD_COOLDOWN_SECONDS - $time_elapsed;
                         $hours_remaining = floor($time_remaining / 3600);
                         $minutes_remaining = floor(($time_remaining % 3600) / 60);
                         $cooldown_message_html = "<p class='error-message'>Следующую публикацию можно сделать через " . $hours_remaining . " ч " . $minutes_remaining . " мин.</p>";
                     }
                 }
                 
        


                 echo "<form id='publish-form' method='post' enctype='multipart/form-data'>
                        <h2>Опубликовать проект</h2>";
                 echo $cooldown_message_html;

                 echo "<div id='publish-message-area'></div>";

                 echo "<label for='title'>Название (макс. " . TITLE_MAX_LENGTH . "):</label>
                        <input type='text' id='title' name='title' required maxlength='" . TITLE_MAX_LENGTH . "' oninput='updateCharCounter(\"title\", " . TITLE_MAX_LENGTH . "); removeLineBreaksInput(this);' " . ($can_upload ? '' : 'disabled') . ">
                        <span id='title-counter' class='char-counter'>0 / " . TITLE_MAX_LENGTH . "</span>

                        <label for='image'>Обложка (JPG, PNG, < " . IMAGE_MAX_SIZE_MB . "MB, **квадратная**):</label>
                        <input type='file' id='image' name='image' accept='" . implode(',', ALLOWED_IMAGE_MIMES) . "' required " . ($can_upload ? '' : 'disabled') . ">

                        <label for='file'>Файл проекта (." . htmlspecialchars(ALLOWED_EXTENSION, ENT_QUOTES) . ", < " . PROJECT_FILE_MAX_SIZE_MB . "MB):</label>
                        <input type='file' id='file' name='file' accept='." . htmlspecialchars(ALLOWED_EXTENSION, ENT_QUOTES) . "' required " . ($can_upload ? '' : 'disabled') . ">

                        <div id='progress-container' class='progress-container'><div id='progress-bar' class='progress-bar'>0%</div></div>
                        <div id='upload-status'></div>

                        <label for='description'>Описание (макс. " . DESCRIPTION_MAX_LENGTH . "):</label>
                        <textarea id='description' name='description' rows='6' required maxlength='" . DESCRIPTION_MAX_LENGTH . "' oninput='updateCharCounter(\"description\", " . DESCRIPTION_MAX_LENGTH . ");' " . ($can_upload ? '' : 'disabled') . "></textarea>
                        <span id='description-counter' class='char-counter'>0 / " . DESCRIPTION_MAX_LENGTH . "</span>

                        <input type='submit' id='publish-submit-button' value='Опубликовать' " . ($can_upload ? '' : 'disabled') . ">
                      </form>";
            }
            break;
            
        

        case 'game':
             $game_id = isset($_GET['id']) ? $_GET['id'] : null;
             if ($game_id) {
                 $game = get_game($game_id);
                 if ($game):
                     $game_title_safe = htmlspecialchars($game['title']??'Без назв.',ENT_QUOTES,'UTF-8');
                     $game_author_raw = $game['author']??'Неизв.';
                     $game_author_display_safe=htmlspecialchars($game_author_raw,ENT_QUOTES,'UTF-8');


                     $author_link_raw = $game_author_raw;
                     $users_check_author = get_users();
                     $game_author_lower_link = strtolower($game_author_raw);
                     if (isset($users_check_author[$game_author_lower_link]['original_login'])) {
                          $author_link_raw = $users_check_author[$game_author_lower_link]['original_login'];
                     }

                     $author_link_safe=htmlspecialchars($_SERVER['PHP_SELF']."?page=profile&user=".urlencode($author_link_raw),ENT_QUOTES,'UTF-8');

                     $game_desc_safe=nl2br(htmlspecialchars($game['description']??'',ENT_QUOTES,'UTF-8'));
                     $img_url_raw=$game['image']??'';
                     $img_url_safe=htmlspecialchars($img_url_raw,ENT_QUOTES,'UTF-8');
                     $file_url_raw=$game['file']??'';
                     $file_url_safe=htmlspecialchars($file_url_raw,ENT_QUOTES,'UTF-8');

                     $game_dl=(int)($game['downloads']??0);
                     $game_ts=isset($game['timestamp'])?date('d.m.Y H:i',$game['timestamp']):'Неизв.';
                     $game_id_safe = htmlspecialchars($game_id, ENT_QUOTES, 'UTF-8');

                     $like_count = count($game['likes'] ?? []);
                     $dislike_count = count($game['dislikes'] ?? []);
                     $user_vote = $loggedIn ? get_user_vote($game_id, $loggedInUser) : null;
                     $like_button_class = ($user_vote === 'like') ? 'liked' : '';
                     $dislike_button_class = ($user_vote === 'dislike') ? 'disliked' : '';

                     echo "<div class='game-details'><h2>".$game_title_safe."</h2>";
                     if(!empty($img_url_safe)){
                          echo "<img src='".$img_url_safe."' alt='Обложка ".$game_title_safe."'><br>";
                     } else {
                          echo "<div class='carousel-item-placeholder'>Нет обложки</div>";
                     }
                     echo "<p><strong>Автор:</strong> <a href='".$author_link_safe."'>".$game_author_display_safe."</a></p>";
                     echo "<p><strong>Описание:</strong></p><p class='description-text'>".$game_desc_safe."</p>";
                     echo "<p><strong>Скачиваний:</strong> <span id='download-count-".$game_id_safe."'>".$game_dl."</span></p>";
                     echo "<p><strong>Опубликовано:</strong> ".$game_ts."</p>";

                     echo "<div class='game-actions'>";
                     if (!empty($file_url_safe)){
                         echo "<button onclick='downloadFile(this)' data-file-url='".$file_url_safe."' data-game-id='".$game_id_safe."' data-logged-in='".($loggedIn?'true':'false')."'>Скачать игру</button>";
                         if(!$loggedIn){ echo "<small style='color:var(--text-light); margin-left: 5px;'>(<a href=\"#\" onclick=\"showLoginForm(); return false;\">Войдите</a>, чтобы скачать и оценить)</small>"; }
                     } else {
                         echo "<button disabled>Файл недоступен</button>";
                     }

                     echo "<div class='vote-buttons' data-game-id='".$game_id_safe."'>";

                     if ($loggedIn) {
                         echo "<button class='vote-button ".$like_button_class."' data-action='like' onclick='handleVote(this)' title='Нравится'><span class='icon'>👍</span> <span class='vote-count' id='like-count-{$game_id_safe}'>{$like_count}</span></button>";
                         echo "<button class='vote-button ".$dislike_button_class."' data-action='dislike' onclick='handleVote(this)' title='Не нравится'><span class='icon'>👎</span> <span class='vote-count' id='dislike-count-{$game_id_safe}'>{$dislike_count}</span></button>";
                     } else {

                         echo "<span class='vote-count' title='Нравится'><span class='icon'>👍</span> {$like_count}</span>";
                         echo "<span class='vote-count' title='Не нравится' style='margin-left: 10px;'><span class='icon'>👎</span> {$dislike_count}</span>";
                     }
                     echo "</div>";
                     echo "</div>";
                     echo "</div>";

                     echo "<div class='comments' id='comments-section'>";
                     if ($comment_message) echo $comment_message;
                     echo "<h3>Комментарии</h3>";
                     $comments=get_comments($game_id);

                     usort($comments, fn($a,$b)=>($b['timestamp']??0)<=>($a['timestamp']??0));

                     if(!empty($comments)):
                         foreach($comments as $cd):
                             $ca_raw=$cd['author']??'Неизв.';

                             $comment_author_link_raw = $ca_raw;
                             $users_check_comment = get_users();
                             $comment_author_lower = strtolower($ca_raw);
                             if (isset($users_check_comment[$comment_author_lower]['original_login'])) {
                                 $comment_author_link_raw = $users_check_comment[$comment_author_lower]['original_login'];
                             }

                             $ca_link=htmlspecialchars($_SERVER['PHP_SELF']."?page=profile&user=".urlencode($comment_author_link_raw),ENT_QUOTES,'UTF-8');

                             $ct_safe=nl2br(htmlspecialchars($cd['comment']??'',ENT_QUOTES,'UTF-8'));
                             $cts_safe=isset($cd['timestamp']) ? date('d.m.Y H:i',$cd['timestamp']) : 'Неизв.';
                             $cid_safe=htmlspecialchars($cd['id']??'',ENT_QUOTES);

                             echo "<div class='comment'><div class='comment-content'><span class='comment-author'><a href='".$ca_link."'>".htmlspecialchars($ca_raw, ENT_QUOTES, 'UTF-8')."</a></span><span class='comment-meta'>".$cts_safe."</span><p>".$ct_safe."</p></div>";


                             $can_delete_comment = $is_admin || ($loggedIn && strtolower($loggedInUser) === strtolower($ca_raw));


                             if($can_delete_comment && isset($cd['id']) && !empty($cd['id'])){
                                echo "<div class='comment-actions'><form method='post' action='".htmlspecialchars($_SERVER['PHP_SELF'])."?page=game&id=".urlencode($game_id)."' onsubmit='return confirm(\"Удалить комментарий?\\nЭто действие необратимо.\");'><input type='hidden' name='delete_comment' value='1'><input type='hidden' name='game_id' value='".$game_id_safe."'><input type='hidden' name='comment_id' value='".$cid_safe."'><button type='submit' name='delete_comment_btn' class='delete-button'>Удалить</button></form></div>";
                             }
                             echo "</div>";
                         endforeach;
                     else:
                         echo "<p>Нет комментариев.</p>";
                     endif;


                     if($loggedIn){
                         echo "<form method='post' action='".htmlspecialchars($_SERVER['PHP_SELF']."?page=game&id=".urlencode($game_id))."#comments-section'><h4>Оставить комментарий (макс. ".COMMENT_MAX_LENGTH.")</h4><input type='hidden' name='add_comment' value='1'><input type='hidden' name='game_id' value='".$game_id_safe."'><label for='comment'>Комментарий:</label><textarea id='comment' name='comment' rows='4' required maxlength='".COMMENT_MAX_LENGTH."' oninput='updateCharCounter(\"comment\", " . COMMENT_MAX_LENGTH . ");'>".$preserved_comment_text."</textarea><span id='comment-counter' class='char-counter'>".mb_strlen($preserved_comment_text)." / ".COMMENT_MAX_LENGTH."</span><input type='submit' name='add_comment_submit' value='Отправить'></form>";
                     } else {

                         echo "<p style='margin-top: 20px;'><a href='#' onclick='showLoginForm(); return false;'>Войдите</a> или <a href='#' onclick='showRegistrationForm(); return false;'>зарегистрируйтесь</a>, чтобы комментировать.</p>";
                     }
                     echo "</div>";

                 else:

                     echo "<p class='error-message'>Игра с ID '".htmlspecialchars($game_id, ENT_QUOTES)."' не найдена.</p>";
                 endif;
             } else {

                 echo "<p class='error-message'>Ошибка: ID игры не указан.</p>";
             }
             break;

        case 'admin':

             if (!$is_admin) {
                 echo "<p class='error-message'>Доступ запрещен.</p>";
             } else {
                echo "<div class='admin-panel'><h2>Админ панель</h2>";

                if ($admin_message) {
                     echo "<p class='" . (strpos($admin_message, 'Ошибка') !== false || strpos($admin_message, 'Нельзя') !== false ? 'error-message' : 'message') . "'>" . htmlspecialchars($admin_message, ENT_QUOTES, 'UTF-8') . "</p>";
                } else if (isset($_GET['status']) && $_GET['status'] === 'action_completed') {

                      echo "<p class='message'>Действие выполнено.</p>";
                }

                echo "<h3>Все Проекты (".count(get_games()).")</h3>";
                $allGamesAdmin=get_games();
                if(!empty($allGamesAdmin)){
                     krsort($allGamesAdmin);
                     echo "<ul class='admin-list'>";
                     foreach($allGamesAdmin as $id=>$g){
                         $gt=htmlspecialchars($g['title']??'Без назв.',ENT_QUOTES,'UTF-8');
                         $ga_raw=$g['author']??'Неизв.';
                         $ga=htmlspecialchars($ga_raw,ENT_QUOTES,'UTF-8');
                         $gl=htmlspecialchars($_SERVER['PHP_SELF'].'?page=game&id='.urlencode($id),ENT_QUOTES,'UTF-8');


                         $admin_author_link_raw = $ga_raw;
                         $users_check_admin = get_users();
                         $admin_author_lower = strtolower($ga_raw);
                          if (isset($users_check_admin[$admin_author_lower]['original_login'])) {
                              $admin_author_link_raw = $users_check_admin[$admin_author_lower]['original_login'];
                          }
                         $al=htmlspecialchars($_SERVER['PHP_SELF'].'?page=profile&user='.urlencode($admin_author_link_raw),ENT_QUOTES,'UTF-8');

                         $gd_timestamp=isset($g['timestamp'])?(int)$g['timestamp']:0;
                         $gd_formatted=($gd_timestamp>0)?date('d.m.y H:i',$gd_timestamp):'?';
                         $id_safe=htmlspecialchars($id,ENT_QUOTES);

                         echo "<li><div><a href='{$gl}'>{$gt}</a> <span>(Автор: <a href='{$al}'>{$ga}</a>, {$gd_formatted})</span></div><div><form method='post' action='".htmlspecialchars($_SERVER['PHP_SELF'])."?page=admin' onsubmit='return confirm(\"Удалить проект {$gt} (ID: {$id_safe})?\\n - Файлы, комментарии и записи о скачиваниях будут удалены.\\nЭто действие необратимо.\\nУверены?\");' style='display:inline;'><input type='hidden' name='delete_game' value='1'><input type='hidden' name='game_id' value='".$id_safe."'><button type='submit' name='delete_game_btn' class='delete-button'>Удалить Проект</button></form></div></li>";
                     }
                     echo "</ul>";
                } else { echo "<p>Проектов нет.</p>"; }

                echo "<h3>Все Пользователи (".count(get_users()).")</h3>";
                $allUsersAdmin=get_users();
                if(!empty($allUsersAdmin)){
                     ksort($allUsersAdmin);
                     echo "<ul class='admin-list'>";
                     $admin_check_lower=array_map('strtolower',ADMIN_USERNAMES);

                     foreach($allUsersAdmin as $login_lower => $user_data){

                         $display_login_case_admin = $user_data['original_login'] ?? $login_lower;
                         $ld_display=htmlspecialchars($display_login_case_admin,ENT_QUOTES,'UTF-8');
                         $pl=htmlspecialchars($_SERVER['PHP_SELF'].'?page=profile&user='.urlencode($display_login_case_admin),ENT_QUOTES,'UTF-8');


                         $is_adm=in_array($login_lower,$admin_check_lower);

                         echo "<li><div><a href='{$pl}'>{$ld_display}</a></div><div>";
                         if(!$is_adm){

                            echo "<form method='post' action='".htmlspecialchars($_SERVER['PHP_SELF'])."?page=admin' onsubmit='return confirm(\"ВНИМАНИЕ! Удаление {$ld_display} удалит:\\n- Его аккаунт с сервера.\\n- Все его проекты с сервера.\\n- Все его комментарии с сервера.\\n- Его Лайки/дизлайки и записи о скачиваниях.\\nЭто действие необратимо.\\nУверены?\");' style='display:inline;'><input type='hidden' name='delete_user' value='1'><input type='hidden' name='user_login' value='".$ld_display."'><button type='submit' name='delete_user_btn' class='delete-button'>Удалить Пользователя</button></form>";
                         }else{
                            echo "<span>(Администратор)</span>";
                         }
                         echo "</div></li>";
                     }
                     echo "</ul>";
                } else { echo "<p>Пользователей нет.</p>"; }
                echo "</div>";
             }
             break;

        default:
             error_log("Unknown page requested: " . ($_GET['page'] ?? 'N/A') . " - Falling back to home.");
             $page_render = 'home';
             echo "<p class='error-message'>Запрошенная страница не найдена.</p>";


      }


      if ($page_render === 'home') {
          echo "<h1>Добро пожаловать!</h1>";
          $allGames = get_games();


          $mostDl = $allGames;

          uasort($mostDl, fn($a,$b)=>($b['downloads']??0)<=>($a['downloads']??0) ?: ($b['timestamp']??0)<=>($a['timestamp']??0));

          $trending = $allGames;

          uasort($trending, fn($a,$b)=>($b['timestamp']??0)<=>($a['timestamp']??0));

          $randomG = $allGames;

          if(!empty($randomG)){$keys=array_keys($randomG);shuffle($keys);$rShuf=[];foreach($keys as $key){$rShuf[$key]=$randomG[$key];}$randomG=$rShuf;}

          $carouselData = ['Самые популярные'=>$mostDl, 'Новые'=>$trending, 'Рекомендации'=>$randomG];
          $maxItemsPerCarousel = 100;

          foreach ($carouselData as $title => $games) {
              if (!empty($games)) {
                  echo '<div class="carousel-section"><div class="carousel-header"><h2>'.htmlspecialchars($title).'</h2></div><div class="carousel">';
                  $count=0;
                  foreach($games as $id => $g){
                      if($count>=$maxItemsPerCarousel)break;
                      $gt=htmlspecialchars($g['title']??'Без назв.',ENT_QUOTES,'UTF-8');
                      $gi_url_raw=$g['image']??'';
                      $gi_url_safe=htmlspecialchars($gi_url_raw,ENT_QUOTES,'UTF-8');
                      $gd=(int)($g['downloads']??0);
                      $gl=htmlspecialchars($_SERVER['PHP_SELF'].'?page=game&id='.urlencode($id),ENT_QUOTES,'UTF-8');
                  ?>
                      <div class="carousel-item">
                          <a href="<?php echo $gl; ?>" class="card-link" title="<?php echo $gt; ?>">
                              <?php
                              if(!empty($gi_url_safe)):?>
                                  <img src="<?php echo $gi_url_safe;?>" alt="<?php echo $gt;?>" loading="lazy">
                              <?php else:?>
                                  <div class="carousel-item-placeholder">Нет обложки</div>
                              <?php endif;?>
                              <div class="item-content">
                                  <h3><?php echo $gt;?></h3>
                                  <p class="downloads"><?php echo $gd;?></p>
                              </div>
                          </a>
                      </div>
                  <?php
                      $count++;
                  }
                  echo '</div></div>';
              }
          }


          if(empty($allGames)){
              echo "<div style='text-align:center; margin-top:20px;'><p>Пока нет проектов.</p>";
              if($loggedIn){
                  echo "<p><a href='".htmlspecialchars($_SERVER['PHP_SELF'])."?page=publish'>Опубликовать проект</a>.</p>";
              }else{
                  echo "<p><a href='#' onclick='showLoginForm(); return false;'>Войдите</a> или <a href='#' onclick='showRegistrationForm(); return false;'>зарегистрируйтесь</a>, чтобы добавить проект.</p>";
              }
              echo "</div>";
          }
      }
    ?>
  </div> <!-- End mainContent -->

</div> <!-- End container -->

<!-- Loading Overlay -->
<div id="loading" style="display: none;"><span>Загрузка...</span><span id="progress">0%</span><span id="loading-message"></span></div>

<!-- JavaScript -->
<script>


  function showAuthForm(formElement) {
      const reg = document.getElementById("registrationForm"), log = document.getElementById("loginForm");
      if(reg) reg.style.display='none';
      if(log) log.style.display='none';
      if(formElement){
          formElement.style.display='block';
          formElement.scrollIntoView({behavior:'smooth',block:'center'});
      }
  }
  function showRegistrationForm() { showAuthForm(document.getElementById("registrationForm")); }
  function showLoginForm() { showAuthForm(document.getElementById("loginForm")); }
  function hideAuthForms() {
      const reg = document.getElementById("registrationForm"), log = document.getElementById("loginForm");
      if(reg) reg.style.display='none';
      if(log) log.style.display='none';
  }


  const menuToggle = document.getElementById("mobile-menu-toggle"), navbar = document.getElementById("main-navbar"), menuOverlay = document.getElementById("menu-overlay");
  function openMobileMenu() {
      if(navbar) navbar.classList.add("is-open");
      if(menuOverlay) menuOverlay.classList.add("is-visible");
      if(menuToggle){ menuToggle.classList.add("is-active"); menuToggle.setAttribute("aria-expanded", "true"); }
      document.body.style.overflow = 'hidden';
  }
  function closeMobileMenu() {
      if(navbar) navbar.classList.remove("is-open");
      if(menuOverlay) menuOverlay.classList.remove("is-visible");
      if(menuToggle){ menuToggle.classList.remove("is-active"); menuToggle.setAttribute("aria-expanded", "false"); }
      document.body.style.overflow = '';
  }

  if(menuToggle && navbar){
      menuToggle.addEventListener("click", (e)=>{
          e.stopPropagation();
          if(navbar.classList.contains("is-open")) closeMobileMenu();
          else openMobileMenu();
      });
  }
  if(menuOverlay){
      menuOverlay.addEventListener("touchstart", closeMobileMenu, {passive:true});
      menuOverlay.addEventListener("click", closeMobileMenu);
  }

  if(navbar){
      navbar.querySelectorAll("a").forEach(link => {
          link.addEventListener("click", (event) => {

              const isRealLink = link.href && link.getAttribute('href') !== '#' && !link.href.startsWith('javascript:');
              const isOnClickHandler = !!link.onclick;

              if(window.innerWidth <= 768 && navbar.classList.contains("is-open")){
                  if(isRealLink || isOnClickHandler) {

                      setTimeout(closeMobileMenu, 50);
                  }
              }
          });
      });
  }


  function updateCharCounter(id, max) {
      const i = document.getElementById(id);
      const c = document.getElementById(id + "-counter");
      if (i && c) {



          const l = i.value.length;
          c.textContent = `${l} / ${max}`;

          c.style.color = l > max ? 'var(--danger-text)' : 'var(--text-light)';
      }
  }



  function removeLineBreaksInput(el) {
      if (el && el.type === 'text') {
          const originalValue = el.value;
          const newValue = originalValue.replace(/[\r\n]+/g, ' ');
          if (originalValue !== newValue) {
              el.value = newValue;

              const maxAttr = el.getAttribute('maxlength');
              if (maxAttr) {
                  const max = parseInt(maxAttr);
                  if (!isNaN(max)) updateCharCounter(el.id, max);
              }
          }
      }
  }



  function downloadFile(btn) {
      const url = btn.getAttribute('data-file-url');
      const id = btn.getAttribute('data-game-id');
      const loggedIn = btn.getAttribute('data-logged-in') === 'true';
      const load = document.getElementById("loading");
      const prog = document.getElementById("progress");
      const msg = document.getElementById("loading-message");
      const countSpan = document.getElementById("download-count-" + id);

      if (msg) msg.innerText = '';
      if (prog) {
          prog.style.display = 'block';
          prog.innerText = "0%";
          prog.style.width = "0%";
      }


      if (!loggedIn) {

          if (load && msg) {
              load.style.display = "flex";
              if (prog) prog.style.display = 'none';
              msg.innerText = 'Необходимо войти для скачивания и учета.';

              setTimeout(() => {
                  load.style.display = "none";
                  if (msg) msg.innerText = '';
                  showLoginForm();
              }, 3000);
          } else {

              alert("Необходимо войти для скачивания.");
              showLoginForm();
          }
          return;
      }


      if (!url) {
           alert("Ошибка: URL файла недоступен.");
           if(load) load.style.display = "none";
           return;
      }


      if (!load || !prog || !msg) {
          console.warn("Loading overlay elements missing.");
          triggerDownload(url);

          fetch(`<?php echo htmlspecialchars($_SERVER['PHP_SELF'], ENT_QUOTES, 'UTF-8'); ?>?page=increment_download&id=${encodeURIComponent(id)}`).catch(e => console.error("Silent DL count fail:", e));
          return;
      }

      load.style.display = "flex";
      if (prog) {
           prog.style.display = 'block';
           prog.innerText = "0%";
           prog.style.width = "0%";
      }
      if (msg) msg.innerText = 'Проверка счетчика скачиваний...';

      const incUrl = `<?php echo htmlspecialchars($_SERVER['PHP_SELF'], ENT_QUOTES, 'UTF-8'); ?>?page=increment_download&id=${encodeURIComponent(id)}`;



      let fakeProgress = 0;
      const fakeInterval = setInterval(() => {

          fakeProgress += Math.floor(Math.random() * 10) + 2;
          if (fakeProgress > 80) fakeProgress = 80;
          if (prog) {
               prog.innerText = fakeProgress + "%";
               prog.style.width = fakeProgress + "%";
          }
      }, 100);



      fetch(incUrl)
          .then(r => {
              clearInterval(fakeInterval);
              if (!r.ok) {

                  return r.json().then(e => {
                      console.error('Server error JSON:', e);
                      throw new Error(e.message || `Server error (${r.status})`);
                  }).catch(() => {

                      console.error('Server error (non-JSON):', r.statusText);
                      throw new Error(`Server error (${r.status})`);
                  });
              }

              const ct = r.headers.get("content-type");
              if (ct && ct.includes("application/json")) {
                  return r.json();
              }

              throw new Error("Unexpected server response type.");
          })
          .then(d => {
              console.log("Download count update response:", d);
              if (d && d.success) {

                  if (d.incremented && countSpan) {

                      if (d.new_count !== undefined) {
                           countSpan.innerText = d.new_count;
                      } else {

                           let currentCount = parseInt(countSpan.innerText, 10);
                           countSpan.innerText = isNaN(currentCount) ? 1 : currentCount + 1;
                      }
                  }

                  if (msg) msg.innerText = 'Начинаем скачивание...';
                   if (prog) {
                       prog.style.width = "100%";
                       prog.textContent = "100%";
                   }

                  triggerDownload(url);

                  setTimeout(() => {
                      if (load) load.style.display = "none";
                  }, 1500);

              } else {

                  const msgText = d.message || "Сбой обновления счетчика.";
                  console.error("Download count update failed:", msgText);
                  if (msg) msg.innerText = `Ошибка: ${msgText}`;

                   if (prog) {
                       prog.style.width = "0%";
                       prog.textContent = "0%";
                   }

                  setTimeout(() => {
                      if (load) load.style.display = "none";
                  }, 3500);
              }
          })
          .catch(e => {

              clearInterval(fakeInterval);
              console.error("Download count update fetch/process error:", e);
              if (msg) msg.innerText = `Критическая ошибка: ${e.message}`;

              if (prog) {
                  prog.style.width = "0%";
                  prog.textContent = "0%";
              }

              setTimeout(() => {
                  if (load) load.style.display = "none";
              }, 3500);
          });
  }


   function triggerDownload(url) {
       if(!url){
           alert("Ошибка: URL скачивания недоступен.");
           return;
       }
       console.log("Triggering download:", url);
       try{
           const a=document.createElement("a");
           a.href=url;


           let filename = 'download';
           try {
                const urlObj = new URL(url);
                const pathname = urlObj.pathname;
                const parts = pathname.split('/');
                if (parts.length > 0) {

                    filename = parts[parts.length - 1] || filename;

                    filename = filename.split('?')[0].split('#')[0];

                    filename = decodeURIComponent(filename);
                }
           } catch (e) {
               console.warn("Failed to parse URL for filename using URL API:", e);

               const parts = url.split('/');
               if(parts.length > 0) filename = parts[parts.length - 1] || filename;
               filename = filename.split('?')[0].split('#')[0];
           }


            const allowedExt = '<?php echo strtolower(ALLOWED_EXTENSION); ?>';
            if (!filename.toLowerCase().endsWith('.' + allowedExt)) {

                if (url.toLowerCase().includes('.' + allowedExt + '?') || url.toLowerCase().endsWith('.' + allowedExt)) {
                    filename += '.' + allowedExt;
                }
            }

           a.download = filename;
           a.target = '_blank';
           document.body.appendChild(a);
           a.click();
           document.body.removeChild(a);
       }catch(e){
           console.error("Error triggering download:", e);
           alert("Произошла ошибка при начале скачивания.");
       }
   }




  function handleVote(btn) {
      const loggedIn = <?php echo $loggedIn?'true':'false';?>;
      if (!loggedIn) {
          alert("Войдите, чтобы оценить.");
          showLoginForm();
          return;
      }

      const action = btn.getAttribute('data-action');
      const voteDiv = btn.closest('.vote-buttons');
      if (!voteDiv) {
          console.error("Vote container not found."); return;
      }
      const id = voteDiv.getAttribute('data-game-id');
      if (!id) {
          console.error("Game ID not found for voting."); return;
      }


      const likeBtn = voteDiv.querySelector('.vote-button[data-action="like"]');
      const dislikeBtn = voteDiv.querySelector('.vote-button[data-action="dislike"]');
      const likeSpan = voteDiv.querySelector('#like-count-' + id);
      const dislikeSpan = voteDiv.querySelector('#dislike-count-' + id);

      if (!likeBtn || !dislikeBtn || !likeSpan || !dislikeSpan) {
          console.error("Vote elements not found."); return;
      }


      likeBtn.disabled = true;
      dislikeBtn.disabled = true;

      console.log(`Sending vote: User=${'<?php echo $loggedInUser;?>'}, Game ID=${id}, Action=${action}`);


      const voteUrl = `<?php echo htmlspecialchars($_SERVER['PHP_SELF'], ENT_QUOTES, 'UTF-8'); ?>?page=vote&id=${encodeURIComponent(id)}&action=${encodeURIComponent(action)}`;


      fetch(voteUrl)
          .then(r => {
              console.log(`Vote response status: ${r.status}`);
              if (!r.ok) {

                  return r.json().then(e => {
                      console.error('Server error JSON:', e);
                      throw new Error(e.message || `Server error (${r.status})`);
                  }).catch(() => {

                      console.error('Server error (non-JSON):', r.statusText);
                      throw new Error(`Server error (${r.status})`);
                  });
              }

              const ct = r.headers.get("content-type");
              if (ct && ct.includes("application/json")) {
                  return r.json();
              }

              throw new Error("Unexpected server response type.");
          })
          .then(d => {
              console.log('Vote response data:', d);
              if (d && d.success) {

                  likeSpan.textContent = d.likes ?? '0';
                  dislikeSpan.textContent = d.dislikes ?? '0';


                  likeBtn.classList.remove('liked');
                  dislikeBtn.classList.remove('disliked');
                  if (d.user_vote === 'like') {
                      likeBtn.classList.add('liked');
                  } else if (d.user_vote === 'dislike') {
                      dislikeBtn.classList.add('disliked');
                  }
                  console.log("Vote UI updated.");

              } else {

                  const msgText = d.message || "Сбой обработки голоса.";
                  alert("Ошибка голоса: " + msgText);
                  console.error("Vote record failed:", msgText, "Response Data:", d);
              }
          })
          .catch(e => {

              alert("Ошибка отправки голоса: " + e.message);
              console.error("Vote fetch/process error:", e);
          })
          .finally(() => {

              likeBtn.disabled = false;
              dislikeBtn.disabled = false;
              console.log("Vote buttons enabled.");
          });
  }



  document.addEventListener("DOMContentLoaded", () => {
      console.log("DOM loaded.");


      ['title', 'description', 'comment'].forEach(id => {
          const el = document.getElementById(id);
          if (el) {
              const maxAttr = el.getAttribute('maxlength');
              if (maxAttr) {
                  const max = parseInt(maxAttr);
                  if (!isNaN(max)) updateCharCounter(id, max);
              }
          }
      });



      const showReg = <?php echo $show_reg_form ? 'true' : 'false'; ?>;
      const showLogin = <?php echo $show_login_form ? 'true' : 'false'; ?>;
      const regSuccess = <?php echo $reg_success ? 'true' : 'false'; ?>;


      if (showReg) {
          showRegistrationForm();
      } else if (showLogin) {
          showLoginForm();
      } else if (regSuccess) {


           hideAuthForms();
           console.log("Registration successful, auth forms hidden.");
      }




      const publishForm = document.getElementById("publish-form");
      const messageArea = document.getElementById("publish-message-area");
      const submitButton = document.getElementById("publish-submit-button");
      const progressContainer = document.getElementById("progress-container");
      const progressBar = document.getElementById("progress-bar");
      const uploadStatus = document.getElementById("upload-status");


      if (publishForm && submitButton && messageArea && progressContainer && progressBar && uploadStatus) {
          publishForm.addEventListener("submit", function(event) {
              event.preventDefault();
              console.log("Publish form submit intercepted for AJAX.");



              const titleInput = document.getElementById('title');
              const descriptionInput = document.getElementById('description');
              const fileInput = document.getElementById('file');
              const imageInput = document.getElementById('image');

              let clientError = null;


              if (!titleInput.value.trim()) clientError = "Название не может быть пустым.";
              else if (titleInput.value.trim().length > <?php echo TITLE_MAX_LENGTH; ?>) clientError = `Название слишком длинное (${titleInput.value.trim().length} / <?php echo TITLE_MAX_LENGTH; ?>).`;


              if (!clientError && !descriptionInput.value.trim()) clientError = "Описание не может быть пустым.";
              else if (!clientError && descriptionInput.value.trim().length > <?php echo DESCRIPTION_MAX_LENGTH; ?>) clientError = `Описание слишком длинное (${descriptionInput.value.trim().length} / <?php echo DESCRIPTION_MAX_LENGTH; ?>).`;



              if (!clientError) {
                  if (!fileInput || fileInput.files.length === 0 || !fileInput.files[0]) clientError = "Файл проекта не выбран.";
                  else {
                      const file = fileInput.files[0];
                      const fileName = file.name || '';
                      const fileExtension = fileName.split('.').pop()?.toLowerCase();
                      const allowedExt = '<?php echo strtolower(ALLOWED_EXTENSION); ?>';

                      if (fileExtension !== allowedExt) clientError = `Неверный тип файла проекта (требуется .${allowedExt}).`;
                      else if (file.size > <?php echo PROJECT_FILE_MAX_SIZE_MB * 1024 * 1024; ?>) clientError = `Файл проекта слишком большой (> <?php echo PROJECT_FILE_MAX_SIZE_MB; ?>MB). Размер: ${(file.size / (1024 * 1024)).toFixed(2)}MB`;
                      else if (file.size === 0) clientError = "Файл проекта пустой.";
                  }
              }


              if (!clientError) {
                  if (!imageInput || imageInput.files.length === 0 || !imageInput.files[0]) clientError = "Файл обложки не выбран.";
                  else {
                      const imgFile = imageInput.files[0];
                      const allowedMimes = <?php echo json_encode(ALLOWED_IMAGE_MIMES); ?>;
                      const imgType = imgFile.type;

                      if (!allowedMimes.includes(imgType)) clientError = "Неверный тип файла обложки (только JPG, PNG).";
                      else if (imgFile.size > <?php echo IMAGE_MAX_SIZE_MB * 1024 * 1024; ?>) clientError = `Файл обложки слишком большой (> <?php echo IMAGE_MAX_SIZE_MB; ?>MB). Размер: ${(imgFile.size / (1024 * 1024)).toFixed(2)}MB`;
                       else if (imgFile.size === 0) clientError = "Файл обложки пустой.";
                  }
              }


              if (clientError) {
                  messageArea.innerHTML = `<p class="error-message">${clientError}</p>`;
                  messageArea.scrollIntoView({ behavior: 'smooth', block: 'center' });
                  return;
              }



              const formData = new FormData(publishForm);
              formData.append('publish_game_ajax', '1');

              const xhr = new XMLHttpRequest();


              messageArea.innerHTML = "";
              submitButton.disabled = true;
              submitButton.value = "Публикация...";
              progressContainer.style.display = "block";
              progressBar.style.width = "0%";
              progressBar.textContent = "0%";
              uploadStatus.style.display = "block";
              uploadStatus.textContent = "Загрузка файлов на сервер...";

              console.log("Starting XHR upload...");


              xhr.upload.addEventListener("progress", function(e) {
                  if (e.lengthComputable) {
                      const percentComplete = Math.round((e.loaded / e.total) * 100);
                      progressBar.style.width = percentComplete + "%";
                      progressBar.textContent = percentComplete + "%";
                      uploadStatus.textContent = `Загрузка файлов: ${percentComplete}%`;
                  } else {
                      uploadStatus.textContent = "Загрузка...";
                  }
              }, false);



              xhr.addEventListener("load", function() {
                  console.log(`XHR load complete. Status: ${xhr.status}`);

                  progressContainer.style.display = "none";
                  uploadStatus.style.display = "none";
                  submitButton.disabled = false;
                  submitButton.value = "Опубликовать";

                  let responseData = null;
                  try {

                      responseData = JSON.parse(xhr.responseText);
                      console.log("Parsed Server Response:", responseData);
                  } catch (e) {

                      messageArea.innerHTML = `<p class="error-message">Ошибка обработки ответа сервера.</p>`;
                      console.error("JSON Parse Error:", e, "Response Text:", xhr.responseText);
                      messageArea.scrollIntoView({ behavior: 'smooth', block: 'center' });
                      return;
                  }


                  if (xhr.status >= 200 && xhr.status < 300 && responseData && responseData.success) {
                      messageArea.innerHTML = `<p class="message">${responseData.message || "Успех!"}</p>`;
                      messageArea.scrollIntoView({ behavior: 'smooth', block: 'center' });


                      publishForm.reset();

                      updateCharCounter('title', <?php echo TITLE_MAX_LENGTH; ?>);
                      updateCharCounter('description', <?php echo DESCRIPTION_MAX_LENGTH; ?>);


                      if (responseData.redirectUrl) {
                          console.log("Redirecting to:", responseData.redirectUrl);

                          setTimeout(() => {
                              window.location.href = responseData.redirectUrl;
                          }, 1500);
                      }

                  } else {

                      const errorMessage = responseData.message || `Ошибка публикации (${xhr.status}).`;
                      messageArea.innerHTML = `<p class="error-message">${errorMessage}</p>`;
                      console.error("Publish failed:", errorMessage, `Status: ${xhr.status}`, "Response Data:", responseData);
                      messageArea.scrollIntoView({ behavior: 'smooth', block: 'center' });
                  }
              });


              xhr.addEventListener("error", function() {
                  console.error("XHR Network Error.");

                  progressContainer.style.display = "none";
                  uploadStatus.style.display = "none";
                  submitButton.disabled = false;
                  submitButton.value = "Опубликовать";
                  messageArea.innerHTML = `<p class="error-message">Ошибка сети при загрузке. Проверьте соединение.</p>`;
                  messageArea.scrollIntoView({ behavior: 'smooth', block: 'center' });
              });


              xhr.addEventListener("abort", function() {
                  console.log("XHR Aborted.");

                  progressContainer.style.display = "none";
                  uploadStatus.style.display = "none";
                  submitButton.disabled = false;
                  submitButton.value = "Опубликовать";
                  messageArea.innerHTML = `<p class="error-message">Загрузка отменена.</p>`;
                  messageArea.scrollIntoView({ behavior: 'smooth', block: 'center' });
              });


              xhr.open("POST", window.location.href, true);
              xhr.send(formData);
          });
      }
  });




   const showReg = <?php echo $show_reg_form ? 'true' : 'false'; ?>;
   const showLogin = <?php echo $show_login_form ? 'true' : 'false'; ?>;
   const regSuccess = <?php echo $reg_success ? 'true' : 'false'; ?>;

   if (showReg) {
       showRegistrationForm();
   } else if (showLogin) {
       showLoginForm();
   } else if (regSuccess) {


        hideAuthForms();
   }

</script>

</body>
</html>
<?php
