<?php


function db_connect_ultra_user_data() {
    $servername = "localhost";
    $username = "root";
    $password = "techcombank";
    $port = 8889;

    return mysqli_connect(
        $servername,
        $username,
        $password,
        null,
        $port
    );
}

