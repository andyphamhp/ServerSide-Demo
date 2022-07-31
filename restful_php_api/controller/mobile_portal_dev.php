<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

include_once("config_db.php");

$mysqli = db_connect_ultra_user_data();
http_response_code(400);
$ultra_mobile_private_key = "DaveWillBustAnAirIn2015";
$private_key = $ultra_mobile_private_key;
$headers = getallheaders();
$user_hash = $headers['Hash'];
$hashable_data = "";
$command = "";
$commands = "";

if ($_SERVER['REQUEST_METHOD'] == 'GET') {
    $query_string = $_SERVER['QUERY_STRING'];
    $hashable_data = urldecode($query_string);
    $command = $_GET['command'];
} else if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $body = file_get_contents('php://input');
    $hashable_data = $body;
    $json = json_decode($body, $assoc = true);
    $commands = $json;
}

$server_hash = hash_hmac("sha256", $hashable_data, $private_key);
if ($user_hash != $server_hash) {
    http_response_code(401);
    echo "Access Denied.";
    exit(0);
}

for ($i = 0; $i < count($commands); $i++) {
    $action = $commands[$i];
    $command = $action['command'];
    $session_token = $action['session_token'];
    $arguments = $action['arguments'];
    $uid = $action['user_id'];
    $device_id = $action['device_id'];
    if (strcmp($command, "login") == 0) {
        login($arguments);
    } elseif (strcmp($command, "create_user") == 0) {
        create_user($arguments, $device_id);
    } elseif (strcmp($command, "delete_user") == 0) {
        delete_user($arguments);
    } elseif (strcmp($command, "log_lesson_entry") == 0) {
        log_lesson_entry($session_token, $arguments, $device_id);
    } elseif (strcmp($command, "get_user_id") == 0) {
        get_user_id();
    } elseif (strcmp($command, "get_lesson_entries") == 0) {
        get_lesson_entries_public($uid);
    } elseif (strcmp($command, "get_top_users") == 0) {
        get_top_users();
    } elseif (strcmp($command, "get_recent_activity") == 0) {
        get_recent_activity();
    } elseif (strcmp($command, "logout") == 0) {
        logout($session_token);
    } elseif (strcmp($command, "get_num_credits") == 0) {
        get_num_credits($session_token);
    } elseif (strcmp($command, "log_multiple_lessons") == 0) {
        log_multiple_lessons($session_token, $arguments, $device_id);
    } elseif (strcmp($command, "email") == 0) {
        send_email($arguments);
    } elseif (strcmp($command, "register_device") == 0) {
        register_device($arguments);
    } elseif (strcmp($command, "increment_credits") == 0) {
        increment_credits($uid, $arguments);
    } elseif (strcmp($command, "set_instrument") == 0) {
        change_instrument($uid, $arguments);
    } elseif (strcmp($command, "add_multiple_users") == 0) {
        add_multiple_users($device_id, $arguments);
    } elseif (strcmp($command, "add_pending_lesson_entries") == 0) {
        add_pending_lesson_entries($device_id, $arguments);
    } elseif (strcmp($command, "username_already_exists") == 0) {
        check_if_username_exists($arguments);
    } else {
        http_response_code(401);
        echo "";
    }
}

//////////////////////////////////////////////////////////////////////////////////////////
//////////////////////////////////////////////////////////////////////////////////////////
// Users
//////////////////////////////////////////////////////////////////////////////////////////
//////////////////////////////////////////////////////////////////////////////////////////
function login($arguments)
{
    global $mysqli;
    $username = $arguments['username'];
    $password = $arguments['password'];
    $passwordMD5 = md5($password);
    $query = "SELECT `uid` FROM  `users` WHERE  `username` =  '$username' AND  `pass` =  '$passwordMD5'";
    $data_sql = mysqli_query($mysqli, $query);
    $data = mysqli_fetch_assoc($data_sql);
    $uid = $data["uid"];
    if ($uid) {
        $query = "SELECT * FROM  `users` WHERE  `username` =  '$username'";
        $data_sql = mysqli_query($mysqli, $query);
        $row = mysqli_fetch_assoc($data_sql);
        $instrument = $row['instrument'];
        //Now we need to create a session token
        $token = create_session_token_for_uid($uid);
        $lesson_entries = get_lesson_entries($uid);
        $credits = get_credits_for_uid($uid);
        $array = array('session_token' => $token, 'user_id' => $uid, 'instrument' => $instrument, 'lesson_entries' => $lesson_entries, 'credits' => $credits, 'username' => $username);
        $json_data = json_encode($array, JSON_FORCE_OBJECT);
        echo $json_data;
        return $json_data;
    } else {
        http_response_code(401);
        $message = array('error' => 'INCORRECT USERNAME OR PASSWORD');
        $json_data = json_encode($message, JSON_FORCE_OBJECT);
        echo $json_data;
        return $json_data;
    }
}

function logout($session_token)
{
    global $mysqli;
    $query = "DELETE FROM `ultra_user_data`.`um_session_tokens` WHERE `um_session_tokens`.`session_token` = '$session_token'";
    $result = mysqli_query($mysqli, $query);
    $message;
    if ($result == 0) {
        $message = array('message' => 'LOGOUT SUCCESSFUL');
    } else {
        $message = array('error' => 'LOGOUT UNSUCCESSFUL');
        http_response_code(400);
    }
    $json_data = json_encode($message, JSON_FORCE_OBJECT);
    echo $json_data;
}

function get_user_id()
{
    $username = $_GET['username'];
    global $mysqli;
    $query = "SELECT  `uid` FROM  `users` WHERE  `username` =  '$username'";
    $data_sql = mysqli_query($mysqli, $query);
    $data = mysqli_fetch_assoc($data_sql);
    $uid = $data['uid'];
    return $uid;
}

function create_user($arguments, $device_id)
{
    global $mysqli;
    $username = $arguments['username'];
    $password = $arguments['password'];
    $instrument = $arguments['instrument'];
    $email = $arguments['email'];
    $passwordMD5 = md5($password);
    //See if user already exists
    if (userAlreadyExists($username)) {
        http_response_code(409);
        $message = array('error' => 'USERNAME TAKEN');
        $json_data = json_encode($message, JSON_FORCE_OBJECT);
        echo $json_data;
        return;
    }
    $query = "INSERT INTO `ultra_user_data`.`users` (`username`, `pass`, `instrument`, `email`) VALUES ('$username', '$passwordMD5', '$instrument', '$email')";
    $result = mysqli_query($mysqli, $query);
    if (result == 0) {
        //If the user is successfully created, then the next step is to determine their user id
        //Once user id is created, we use that to create a session token.
        $uid = get_user_id_for_username($username);
        set_default_credits($uid);
        $token = create_session_token_for_uid($uid);
        $lesson_entries = $arguments['lesson_entries'];
        $lesson_entries_result = log_lesson_entries($lesson_entries, $uid, $device_id);
        $credits = get_credits_for_uid($uid);
        $user_info = array('user_id' => $uid, 'session_token' => $token, 'lesson_entries' => $lesson_entries_result, 'credits' => $credits, 'username' => $username, 'instrument' => $instrument);
        $json_data = json_encode($user_info, JSON_FORCE_OBJECT);
        http_response_code(201);
        echo $json_data;
        return true;
    } elseif (result == 1) {
        http_response_code(500);
        echo "ERROR_CREATING_ACCOUNT";
        return false;
    }
}

function add_multiple_users($device_id, $arguments)
{
    global $mysqli;
    $users = $arguments['users'];
    $message_string;
    $numUsers = 0;
    $add_users_result = array();
    $create_session_token = true;
    $group_name = $arguments['group_name'];

    if ($group_name != "") {
        if (groupAlreadyExists($group_name)) {
            $message = array('error' => "Group name already exists.");
            $json_data = json_encode($message, JSON_FORCE_OBJECT);
            echo $json_data;
            exit(0);
        }
    }

    for ($i = 0; $i < count($users); $i++) {
        if ($i > 0) $create_session_token = false;
        $user = $users[$i];
        $user_result = add_single_user($user, $device_id, $create_session_token);
        array_push($add_users_result, $user_result);
    }
    //Create the group
    if ($group_name != "") {
        createGroups($add_users_result, $group_name);
    }
    $message = array("add_multiple_users" => $add_users_result);
    $json_data = json_encode($message, JSON_FORCE_OBJECT);
    echo $json_data;
}

function createGroups($user_list, $group_name)
{
    $firstUserResult = $add_users_result[0];
    $leader_id = $firstUserResult['user_id'];
    $query = "INSERT INTO `ultra_user_data`.`um_groups` (`group_name`, `leader_id`) VALUES ('$group_name', '$leader_id')";
    $result = mysqli_query($mysqli, $query);
    //Add members to the group
    for ($i = 0; $i < count($add_users_result); $i++) {
        $user = $add_users_result[$i];
        $uid = $user['user_id'];
        $query = "INSERT INTO `ultra_user_data`.`um_group_members` (`group_name`, `uid`) VALUES ('$group_name', '$uid')";
        $result = mysqli_query($mysqli, $query);
    }
}

function groupAlreadyExists($group_name)
{
    //See if user already exists
    global $mysqli;
    $query = "SELECT * FROM `ultra_user_data`.`um_groups` WHERE `group_name` = '$group_name'";
    $result = mysqli_query($mysqli, $query);
    if (mysqli_num_rows($result) > 0) {
        return true;
    } else {
        return false;
    }
}

function add_single_user($arguments, $device_id, $create_session_token)
{
    //This function is specifically called by add_multiple_users. The purpose was to not break the create_user command by changing its output.
    global $mysqli;
    $username = $arguments['username'];
    $password = $arguments['password'];
    $instrument = $arguments['instrument'];
    $email = $arguments['email'];
    $passwordMD5 = md5($password);
    //See if user already exists
    $user_return_info = array('username' => $username);

    if (userAlreadyExists($username)) {
        http_response_code(409);
        $user_return_info['result'] = 'DUPLICATE';
        return $user_return_info;
    }
    $query = "INSERT INTO `ultra_user_data`.`users` (`username`, `pass`, `instrument`, `email`) VALUES ('$username', '$passwordMD5', '$instrument', '$email')";
    $result = mysqli_query($mysqli, $query);
    if (result == 0) {
        //If the user is successfully created, then the next step is to determine their user id
        //Once user id is created, we use that to create a session token.
        $uid = get_user_id_for_username($username);
        set_default_credits($uid);
        $token = "";
        if ($create_session_token) $token = create_session_token_for_uid($uid);
        $lesson_entries = $arguments['lesson_entries'];
        $lesson_entries_result = log_lesson_entries($lesson_entries, $uid, $device_id);
        $credits = get_credits_for_uid($uid);
        $user_return_info['user_id'] = $uid;
        $user_return_info['session_token'] = $token;
        $user_return_info['credits'] = $credits;
        $user_return_info['result'] = 'SUCCESS';
        $user_return_info['instrument'] = $instrument;

    } elseif (result == 1) {
        http_response_code(409);
        $user_return_info['result'] = 'ERROR';

    }
    return $user_return_info;
}

function delete_user($arguments)
{
    global $mysqli;
    $username = $arguments['username'];
    $uid = get_user_id_for_username($username);
    delete_session_tokens_for_uid($uid);
    $query = "DELETE FROM `ultra_user_data`.`users` WHERE `users`.`uid` = '$uid'";
    $result = mysqli_query($mysqli, $query);
    $json_data = json_encode(array('message' => 'USER SUCCESSFULLY DELETED'), JSON_FORCE_OBJECT);
    echo $json_data;
    http_response_code(201);
    return;
}

function get_user_id_for_username($username)
{
    global $mysqli;
    $query = "SELECT * FROM  `users` WHERE  `username` =  '$username'";
    $data_sql = mysqli_query($mysqli, $query);
    /* while($data = mysqli_fetch_assoc($data_sql)) {
    	$uid = $data['uid'];
    	echo "\nThis uid = $uid\n";
    }*/
    $data = mysqli_fetch_assoc($data_sql);
    $uid = $data['uid'];
    return $uid;
}

function check_if_username_exists($arguments)
{
//This function basically just publicly exposes the userAlreadyExists() function
    $username = $arguments['username'];
    $result = userAlreadyExists($username);
    $message = array('username_already_exists' => $result);
    $json_data = json_encode($message, JSON_FORCE_OBJECT);
    echo $json_data;
}


function userAlreadyExists($username)
{
    //See if user already exists
    global $mysqli;
    $query = "SELECT * FROM `ultra_user_data`.`users` WHERE `username` = '$username'";
    $result = mysqli_query($mysqli, $query);
    if (mysqli_num_rows($result) > 0) {
        return true;
    } else {
        return false;
    }
}

function get_username_from_user_id($uid)
{
    global $mysqli;
    $query = "SELECT * FROM  `users` WHERE  `uid` = '$uid'";
    $data_sql = mysqli_query($mysqli, $query);
    $data = mysqli_fetch_assoc($data_sql);
    $username = $data['username'];
    return $username;
}

//////////////////////////////////////////////////////////////////////////////////////////
//////////////////////////////////////////////////////////////////////////////////////////
// Session Tokens
//////////////////////////////////////////////////////////////////////////////////////////
//////////////////////////////////////////////////////////////////////////////////////////
function create_session_token()
{
    //First generates a random number between 1 and a trillion.
    $random_int = mt_rand(1, 1000000000000);
    $token = hash_hmac("sha256", $random_int, $private_key);
    return $token;
}

function create_session_token_for_uid($uid)
{
    global $mysqli;
    $token = create_session_token();
    $query = "INSERT INTO  `ultra_user_data`.`um_session_tokens` (`token` ,`uid`) VALUES ('$token',  '$uid')";
    $result = mysqli_query($mysqli, $query);
    if (!$result) {
        return 0;
    } else {
        return $token;
    }
}

function delete_session_tokens_for_uid($uid)
{
    global $mysqli;
    $query = "DELETE FROM `ultra_user_data`.`um_session_tokens` WHERE `uid` = '$uid'";
    $result = mysqli_query($mysqli, $query);
}

function get_user_id_from_session_token($session_token)
{
    global $mysqli;
    $query = "SELECT * FROM  `ultra_user_data`.`um_session_tokens` WHERE  `token` =  '$session_token'";
    $data_sql = mysqli_query($mysqli, $query);
    if (!$data_sql) {
        echo "ERROR_GETTING_UID_FROM_SESSION_TOKEN_BAD_QUERY";
    }
    $data = mysqli_fetch_assoc($data_sql);
    $uid = $data['uid'];
    if (!$uid) return 0;
    return $uid;
}

//////////////////////////////////////////////////////////////////////////////////////////
//////////////////////////////////////////////////////////////////////////////////////////
// Credits
//////////////////////////////////////////////////////////////////////////////////////////
//////////////////////////////////////////////////////////////////////////////////////////
function get_num_credits($session_token)
{
    //Gets number of credits and echoes to the screen
    $uid = get_user_id_from_session_token($session_token);
    $credits = get_credits_for_uid($uid);
    $array = array("command" => "get_num_credits", "credits" => $credits, "session_token" => $session_token);
    $json_data = json_encode($array, JSON_FORCE_OBJECT);
    echo $json_data;
}

function get_credits_for_uid($uid)
{
    //An internal method for getting credits which returns a value.
    global $mysqli;
    $query = "SELECT `credits` FROM  `ultra_user_data`.`um_credits` WHERE  `uid` =  '$uid'";
    $data_sql = mysqli_query($mysqli, $query);
    if (!$data_sql) {
        echo "ERROR_GETTING_CREDITS";
    }
    $data = mysqli_fetch_assoc($data_sql);
    $credits = $data['credits'];
    return $credits;
}

function set_default_credits($uid)
{
    global $mysqli;
    $query = "INSERT INTO  `ultra_user_data`.`um_credits` (`index` ,`uid` ,`credits`) VALUES (NULL ,  '$uid',  '50')";
    $result = mysqli_query($mysqli, $query);
    if (!$result) echo "ERROR_SETTING_UP_CREDITS";
}

function decrement_credits_by($uid, $n)
{
    echo("decrement_credits_by uid = $uid\n");
    global $mysqli;
    $credits = get_credits_for_uid($uid);
    echo("old credits = $credits\n");
    $credits = $credits - $n;
    echo("new credits = $credits\n");
    $query = "UPDATE  `ultra_user_data`.`um_credits` SET  `credits` =  '$credits' WHERE  `um_credits`.`uid` = '$uid'";
    echo("query = $query\n");
    $result = mysqli_query($mysqli, $query);
    var_dump($result);
    if (!$result) {
        echo("DECREMENT CREDITS FAILED");
    }
}

function increment_credits($uid, $arguments)
{
    global $mysqli;
    if ($uid == 0) {
        $message = array('error' => 'USER ID = 0. CANT INCREMENT CREDITS FOR UNKNOWN USERS');
        $json_data = json_encode($message, JSON_FORCE_OBJECT);
        echo $json_data;
        return;
    }
    $credits = get_credits_for_uid($uid);
    $n = $arguments['credits'];
    $credits = $credits + $n;
    $query = "UPDATE  `ultra_user_data`.`um_credits` SET  `credits` =  '$credits' WHERE  `um_credits`.`uid` = '$uid'";

    $result = mysqli_query($mysqli, $query);
    if (!$result) {
        $message = array('error' => "FAILED TO INCREMENT CREDITS. QUERY = $query");
        $json_data = json_encode($message, JSON_FORCE_OBJECT);
        echo $json_data;
        return;
    } else {
        $message = array('message' => 'CREDITS INCREMENTED SUCCESSFULLY');
        $json_data = json_encode($message, JSON_FORCE_OBJECT);
        echo $json_data;
    }
}

//////////////////////////////////////////////////////////////////////////////////////////
//////////////////////////////////////////////////////////////////////////////////////////
// Lesson Entries
//////////////////////////////////////////////////////////////////////////////////////////
//////////////////////////////////////////////////////////////////////////////////////////


function log_multiple_lessons($session_token, $arguments, $device_id)
{
    //This method is designed to provide a public face for uploading blocks of lessons
    $uid = get_user_id_from_session_token($session_token);
    if (!$uid) {
        http_response_code(400);
        $array = array('error' => "INVALID SESSION TOKEN");
        return 1;
    }
    $result = log_lesson_entries($arguments, $uid, $device_id);
    $json_data = json_encode($result, JSON_FORCE_OBJECT);
    echo $json_data;
}

function log_lesson_entries($lesson_entries, $uid, $device_id)
{
    if (!$lesson_entries) {
        $array = array('message' => 'WARNING: lesson_entries object argument is empty (this may be normal if none were passed in)', 'errors' => "0");
        return $array;
    }
    $numErrors = 0;
    $numSuccesses = 0;
    foreach ($lesson_entries as $key => $entry) {
        if (!$entry) {
            $numErrors++;
            continue;
        }
        $query = sprintf("insert into um_lesson_entry (uid, num_right, num_wrong, instrument, level, mode, direction, chord_table, lesson_name, score, device_id) values ('%s', '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s')",
            $uid,
            $entry['num_right'],
            $entry['num_wrong'],
            $entry['instrument'],
            $entry['level'],
            $entry['mode'],
            $entry['direction'],
            $entry['chord_table'],
            $entry['lesson_name'],
            $entry['score'],
            $device_id
        );
        $result = mysqli_query($mysqli, $query);
        if (!$result) {
            $numErrors++;
        } else {
            $numSuccesses++;
        }
    }
    decrement_credits_by($uid, $numSuccesses);
    //Got this far, must be OK.
    $array = array('message' => "$numSuccesses LESSONS LOGGED SUCCESSFULLY", 'errors' => "$numErrors");
    return $array;
}

function add_pending_lesson_entries($device_id, $arguments)
{
    global $mysqli;
    $lesson_entries = $arguments['lesson_entries'];
    $lesson_results = array();
    foreach ($lesson_entries as $key => $lesson_entry) {
        if (!$lesson_entry) {
            $numErrors++;
            continue;
        }
        $query = sprintf("insert into um_lesson_entry (uid, num_right, num_wrong, instrument, level, mode, direction, chord_table, lesson_name, score, device_id) values ('%s', '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s')",
            $lesson_entry['uid'],
            $lesson_entry['num_right'],
            $lesson_entry['num_wrong'],
            $lesson_entry['instrument'],
            $lesson_entry['level'],
            $lesson_entry['mode'],
            $lesson_entry['direction'],
            $lesson_entry['chord_table'],
            $lesson_entry['lesson_name'],
            $lesson_entry['score'],
            //$lesson_entry['device_id']
            $device_id
        );
        //Create an array to hold results
        $lesson_result = array();
        foreach ($lesson_entry as $key => $value) {
            $lesson_result[$key] = $value;
        }
        //Check if duplicate
        if (lesson_exists($lesson_entry, $device_id)) {
            $lesson_result['result'] = "DUPLICATE";
            array_push($lesson_results, $lesson_result);
            continue;
        }
        //Log the lesson entry and store the result
        $result = mysqli_query($mysqli, $query);
        if ($result) {
            $lesson_result['result'] = "SUCCESS";
            $uid = $lesson_entry['uid'];
            update_score_for_user_id($uid);
            decrement_credits_by($uid, 1);
        } else {
            $lesson_result['result'] = "ERROR";
        }
        array_push($lesson_results, $lesson_result);

    }
    //Got this far, must be OK.
    $array = array('add_pending_lesson_entries' => $lesson_results);
    $json_data = json_encode($array, JSON_FORCE_OBJECT);
    echo $json_data;
    return;

}

function get_lesson_entries_public($uid)
{
    $rows = get_lesson_entries($uid);
    $array = array('lesson_entries' => $rows);
    $json_data = json_encode($array, JSON_FORCE_OBJECT);
    echo $json_data;
}

function get_lesson_entries($uid)
{
    //This function gets all the lesson entries from the SQL table and formats them as a JSON object
    global $mysqli;
    $query = "SELECT * FROM  `um_lesson_entry` WHERE  `uid` = '$uid'";
    $data_sql = mysqli_query($mysqli, $query);
    $rows = array();
    while ($data = mysqli_fetch_assoc($data_sql)) {
        $row = array();
        foreach ($data as $key => $value) {
            $row[$key] = $value;
        }
        array_push($rows, $row);
    }
    return $rows;
}

//////////////////////////////////////////////////////////////////////////////////////////
//////////////////////////////////////////////////////////////////////////////////////////
// Misc
//////////////////////////////////////////////////////////////////////////////////////////
//////////////////////////////////////////////////////////////////////////////////////////

function send_email($arguments)
{
    $email_address = $arguments['email_address'];
    $body = $arguments['body'];

    require_once 'Exception.php';
    require_once 'PHPMailer.php';
    require_once 'SMTP.php';

    $host = "ssl://smtp.gmail.com";
    $port = '465';
    $username = "david@ultramusician.com";
    $magic_word = "zljnwfwtrguyecql";

    $from = $email_address;
    $to = "david@ultramusician.com";
    $subject = "UltraMusician app help request";

    $headers = array('From' => $from,
        'To' => $to,
        'Reply-To' => $from,
        'Subject' => $subject);
    try {
        $mail = new PHPMailer();
        $mail->IsSMTP();
        $mail->CharSet = 'UTF-8';
        $mail->Host = "smtp.gmail.com";    // SMTP server example
        $mail->SMTPDebug = 0;                    // enables SMTP debug information (for testing)
        $mail->SMTPAuth = true;                // enable SMTP authentication
        $mail->Port = 587;                    // set the SMTP port for the GMAIL server
        $mail->Username = $username;            // SMTP account username example
        $mail->Password = $magic_word;        // SMTP account password example
        $mail->SMTPSecure = 'TLS';                // Enable encryption, 'ssl' also accepted
        $mail->From = $from;
        $mail->FromName = "Ultra User";
        $mail->addAddress($to, "David at UltraMusician");        // Add a recipient
        $mail->addReplyTo($from, "Ultra User");
        $mail->Subject = $subject;
        $mail->Body = $body;
        $result = array('message' => 'Message sent successfully');
        if (!$mail->send()) {
            $result = array('error' => 'Message failed to send');
        }
        $json = json_encode($result, JSON_FORCE_OBJECT);
    } catch (Exception $e) {
        $result = array('error' => $mail->ErrorInfo);
    }
    $json = json_encode($result, JSON_FORCE_OBJECT);
    echo $json;
}

function register_device($arguments)
{
    global $mysqli;
    $device = $arguments['device'];
    $device_id = $arguments['device_id'];
    $query = "INSERT INTO  `ultra_user_data`.`um_device_id` (`index` ,`device`, `device_id`) VALUES (NULL , '$device', '$device_id')";
    $result = mysqli_query($mysqli, $query);
    $value;
    if ($result) {
        $value = array('message' => "DEVICE SUCCESSFULLY REGISTERED");
    } else {
        $value = array('error' => "ERROR REGISTERING DEVICE");
    }
    $json = json_encode($value, JSON_FORCE_OBJECT);
    echo $json;
}

function filterString($string)
{
    $result = filter_var($string, FILTER_SANITIZE_STRING, FILTER_NULL_ON_FAILURE);
    if (is_null($result)) exit(0);
    $regex = '/[;&]/';
    $matches = preg_match($regex, $string);
    if ($matches > 0) {
        fail($string);
    } else {
        return $result;
    }
}

//////////////////////////////////////////////////////////////////////////////////////////
//////////////////////////////////////////////////////////////////////////////////////////
// High Scores
//////////////////////////////////////////////////////////////////////////////////////////
//////////////////////////////////////////////////////////////////////////////////////////
function get_top_users()
{
    global $mysqli;
    //update_all_scores(); <--keep this commented out unless you need to manually update scores.
    $query = "SELECT * FROM `um_scores` ORDER BY score DESC LIMIT 0 , 100";
    $rank = 1;
    $data_sql = mysqli_query($mysqli, $query);
    $array = array();
    $i = 0;
    while ($data = mysqli_fetch_assoc($data_sql)) {
        $username = $data['username'];
        $uid = $data['uid'];
        if ($uid == 0) continue;
        if ($uid == 1) continue;
        $username = get_username_from_user_id($uid);
        if (substr($username, 0, 4) == 'test') continue;
        $parts = explode('@', $username);
        $username = $parts[0];
        $score = $data['score'];
        $level = $data['level'];
        $row = array('rank' => $rank, 'score' => $score, 'level' => $level, 'username' => $username);
        $array[$i++] = $row;
        $rank++;
    }
    $top_users = array('top_users' => $array);
    $group_scores = getGroupScores();
    $top_users['group_scores'] = $group_scores;
    $json_data = json_encode($top_users, JSON_FORCE_OBJECT);
    echo $json_data;
}

function getGroupScores()
{
    global $mysqli;
    $groups = array();
    $group_scores = array();
    $query = "SELECT * FROM `ultra_user_data`.`um_groups`";
    $result = mysqli_query($mysqli, $query);
    while ($data = mysqli_fetch_assoc($result)) {
        $group_name = $data['group_name'];
        $score = getGroupScore($group_name);
        $groups[$group_name] = $score;
    }

    //1) Get group name
    //2) Get all users for that group
    //3) Get score for each user (EZ)
    //4) Add the scores up
    return $groups;
}

function getGroupScore($group_name)
{
    global $mysqli;
    $score = 0;
    $query = "SELECT * FROM `ultra_user_data`.`um_group_members` WHERE `group_name` LIKE '$group_name'";
    $result = mysqli_query($mysqli, $query);
    while ($data = mysqli_fetch_assoc($result)) {
        $uid = $data['uid'];
        $user_score = getScoreForUser($uid);
        $score += $user_score;
    }
    return $score;
}

function getScoreForUser($uid)
{
    $query = "SELECT * FROM `um_scores` WHERE `uid` = $uid";
    $data_sql = mysqli_query($mysqli, $query);
    $score = 0;
    while ($data = mysqli_fetch_assoc($data_sql)) {
        $score = $data['score'];
    }
    return $score;
    //return 0;
}

function update_score_for_user_id($uid)
{
    global $mysqli;
    $query = "SELECT * FROM  `um_lesson_entry` WHERE  `uid` = '$uid'";
    $data_sql = mysqli_query($mysqli, $query);
    $rows = array();
    $total_score = 0;
    $level = "0";
    while ($data = mysqli_fetch_assoc($data_sql)) {
        $score = $data['score'];
        $level = $data['level'];
        $total_score += $score;
    }
    $query = "DELETE FROM um_scores where uid='$uid'";
    $result = mysqli_query($mysqli, $query);
    //Now, create new entry.
    $username = $username = get_username_from_user_id($uid);
    $query = "INSERT INTO um_scores (uid, username, score, level) VALUES ('$uid', '$username', '$total_score', '$level')";
    $result = mysqli_query($mysqli, $query);
}

function update_all_scores()
{
    for ($i = 0; $i < 5000; $i++) {
        update_score_for_user_id($i);
    }
}

function get_recent_activity()
{
    global $mysqli;
    $query = "SELECT  *  FROM  `um_lesson_entry` WHERE `uid` != 0 ORDER BY  `time_stamp` DESC LIMIT 0 , 100";
    $data_sql = mysqli_query($mysqli, $query);
    $recent_activity = "";
    while ($data = mysqli_fetch_assoc($data_sql)) {
        $uid = $data['uid'];
        $username = get_username_from_user_id($uid);
        if (strpos($username, 'test') !== false) continue;
        if (strpos($username, 'Test') !== false) continue;
        if (!$username) continue;
        $parts = explode('@', $username);
        $username = $parts[0];
        $username = substr($username, 0, strlen($username) - 1);
        $lessonType = $data['mode'];
        $level = $data['level'];
        $lesson_name = $data['lesson_name'];
        $instrument = $data['instrument'];
        $new_string = "$username just finished level $level $lesson_name on $instrument...   ";
        $recent_activity = $recent_activity . $new_string;
    }
    $recent = array('recent_activity' => $recent_activity);
    $json_data = json_encode($recent, JSON_FORCE_OBJECT);
    echo $json_data;
}

function change_instrument($uid, $arguments)
{
    global $mysqli;
    $instrument = $arguments['instrument'];
    $query = "UPDATE  `ultra_user_data`.`users` SET  `instrument` =  '$instrument' WHERE  `users`.`uid` = '$uid'";
    $result = mysqli_query($mysqli, $query);
    if ($result) {
        $value = array('message' => "INSTRUMENT SUCCESSFULLY UPDATED");
    } else {
        $value = array('error' => "ERROR UPDATING INSTRUMENT. Query = $query");
    }
    $json = json_encode($value, JSON_FORCE_OBJECT);
    echo $json;
}


function create_group($device_id, $arguments)
{
    global $mysqli;
    $group_name = $arguments['group_name'];
    $query = "INSERT INTO `um_groups` (`ID`, `timestamp`, `group_name`, `leader_id`) VALUES (NULL, CURRENT_TIMESTAMP, 'group_name', '0');";
    $result = mysqli_query($mysqli, $query);
    $message_string = "";
    if ($result) {
        $message_string = "$group_name group created successfully.";
    } else {
        $message_string = "failed to create $group_name group.";
    }
    $message = array('message' => $message_string);
    $json_data = json_encode($message, JSON_FORCE_OBJECT);
    echo $json_data;
}

function lesson_exists($lesson_entry, $device_id)
{
    global $mysqli;
    $uid = $lesson_entry['uid'];
    $instrument = $lesson_entry['instrument'];
    $num_right = $lesson_entry['num_right'];
    $num_wrong = $lesson_entry['num_wrong'];
    $level = $lesson_entry['level'];
    $mode = $lesson_entry['mode'];
    $direction = $lesson_entry['direction'];
    $chord_table = $lesson_entry['chord_table'];
    $lesson_name = $lesson_entry['lesson_name'];
    //$device_id = $lesson_entry['device_id'];
    $score = $lesson_entry['score'];
    $query = "SELECT * FROM `um_lesson_entry` WHERE `uid` = $uid AND `num_right` = $num_right AND `num_wrong` = $num_wrong AND `instrument` LIKE '$instrument' AND `level` = $level AND `mode` LIKE '$mode' AND `direction` LIKE '$direction' AND `chord_table` LIKE '$chord_table' AND `lesson_name` LIKE '$lesson_name' AND `score` = $score AND `device_id` LIKE '$device_id'";
    $result = mysqli_query($mysqli, $query);
    if (mysqli_num_rows($result) > 0) {
        return true;
    } else {
        return false;
    }
}

function log_lesson_entry($session_token, $arguments, $device_id)
{
    //Currently, $uid is not being used.
    global $mysqli;
    $lesson_entry = array();
    $user_id = get_user_id_from_session_token($session_token);
    $instrument = $arguments['instrument'];
    $num_right = $arguments['num_right'];
    $num_wrong = $arguments['num_wrong'];
    $level = $arguments['level'];
    $mode = $arguments['mode'];
    $direction = $arguments['direction'];
    $chord_table = urldecode($arguments['chord_table']);
    $lesson_name = urldecode($arguments['lesson_name']);
    $bonus = $arguments['bonus'];
    $score = $arguments['score'];
    $lesson_entry = array('instrument' => $instrument, 'num_right' => $num_right, 'num_wrong' => $num_wrong, 'level' => $level, 'mode' => $mode, 'direction' => $direction, 'chord_table' => $chord_table, 'lesson_name' => $lesson_name, 'bonus' => $bonus, 'score' => $score, 'uid' => $user_id);
    if (lesson_exists($lesson_entry, $device_id)) {
        $message = array('error' => 'LESSON ENTRY ALREADY EXISTS');
        $json_data = json_encode($message, JSON_FORCE_OBJECT);
        echo $json_data;
        log_error_message($user_id, $device_id, "LESSON ENTRY ALREADY EXISTS");
        return;
    }
    $query = sprintf("insert into um_lesson_entry (uid, num_right, num_wrong, instrument, level, mode, direction, chord_table, lesson_name, score, device_id) values ('%s', '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s')",
        $user_id, $num_right, $num_wrong, $instrument, $level, $mode, $direction, $chord_table, $lesson_name, $score, $device_id);
    $r = mysqli_query($mysqli, $query);
    if (!$r) {
        $message = array('error' => 'FAILED TO LOG LESSON');
        $json_data = json_encode($message, JSON_FORCE_OBJECT);
        echo $json_data;
        return;
    } else {
        $message = array('message' => 'LESSON LOGGED SUCCESSFULLY');
        $json_data = json_encode($message, JSON_FORCE_OBJECT);
        echo $json_data;
    }
    //Now decrement credits
    decrement_credits_by($user_id, 1);
    update_score_for_user_id($user_id);
}

function log_error_message($uid, $device_id, $message)
{
    $query = "INSERT INTO `um_error_log` (`ID`, `time_stamp`, `uid`, `device_id`, `message`) VALUES (NULL, CURRENT_TIMESTAMP, '$uid', '$device_id', '$message')";
    $result = mysqli_query($mysqli, $query);

}

