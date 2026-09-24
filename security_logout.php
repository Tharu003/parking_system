<?php
session_start();
require_once 'config/db.php';
if(!empty($_SESSION['security_logged_in'])){
 $name=$_SESSION['security_name']??'Security Officer'; $action='Security staff logged out';
 $stmt=$conn->prepare("INSERT INTO audit_logs (user_name, action) VALUES (?,?)");$stmt->bind_param("ss",$name,$action);$stmt->execute();$stmt->close();
}
$_SESSION=[]; if(ini_get("session.use_cookies")){ $p=session_get_cookie_params(); setcookie(session_name(),'',
time()-42000,$p["path"],$p["domain"],$p["secure"],$p["httponly"]); } session_destroy();
header('Location: security_login.php'); exit;
