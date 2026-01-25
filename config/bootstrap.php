<?php
// includes/bootstrap.php

session_start();

$env = require __DIR__ . '/../config/env.php';
$currentEnv = $env['env'];

error_reporting($env[$currentEnv]['error_reporting']);
ini_set('display_errors', $env[$currentEnv]['display_errors'] ? '1' : '0');

$app       = require __DIR__ . '/../config/app.php';
$dbConfig  = require __DIR__ . '/../config/db.php';
$mailCfg   = require __DIR__ . '/../config/mail.php';
$smsCfg    = require __DIR__ . '/../config/sms.php';
$constants = require __DIR__ . '/../config/constants.php';

date_default_timezone_set($app['timezone']);

// DB connection
$mysqli = new mysqli(
    $dbConfig['host'],
    $dbConfig['username'],
    $dbConfig['password'],
    $dbConfig['database']
);

if ($mysqli->connect_error) {
    die('Database connection failed.');
}

$mysqli->set_charset($dbConfig['charset']);
