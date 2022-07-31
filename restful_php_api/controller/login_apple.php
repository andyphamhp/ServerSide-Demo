<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/x-www-form-urlencoded");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

require_once(__DIR__ . '/../vendor/autoload.php');
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Request;

if (!empty($_POST['identity_token']) && !empty($_POST['auth_code'])) {
    $identity_token = $_POST['identity_token'];
    $auth_code = $_POST['auth_code'];
    $SIWA_CLIENT_ID = "UM.UltraMusician.Andy";
    $SIWA_CLIENT_SECRET = "eyJraWQiOiJBNVJRWDlTUEw5IiwiYWxnIjoiRVMyNTYifQ.eyJpc3MiOiJIS1haVEg0WUEzIiwiaWF0IjoxNjU4OTM3MDE3LCJleHAiOjE2NzQ0ODkwMTcsImF1ZCI6Imh0dHBzOi8vYXBwbGVpZC5hcHBsZS5jb20iLCJzdWIiOiJVTS5VbHRyYU11c2ljaWFuLkFuZHkifQ.rntdOM7m4HV0E40ztcWEvz8cDDXmxuxKg5PlmK6XGWKdVyWfvtVg_ylW7ko6q7dnrVPkV6hFgLcZk4rN5MGR5A";
    $body = 'client_id=' . $SIWA_CLIENT_ID . '&client_secret=' . $SIWA_CLIENT_SECRET . '&code=' . $auth_code . '&grant_type=authorization_code';
    $client = new Client();
    $request = new Request(
        "POST",
        "https://appleid.apple.com/auth/token",
        ["Content-Type" => "application/x-www-form-urlencoded"],
        $body);
    try {
        $response = $client->send($request);
        $data = json_decode($response->getBody(), true);
        $payload = json_decode(base64_decode(str_replace('_', '/', str_replace('-', '+', explode('.', $data['id_token'])[1]))), true);
        echo json_encode($payload);
    } catch (GuzzleException $e) {
        echo $e->getMessage();
    }
}