<?php

use FormTools\Accounts;
use FormTools\Administrator;
use FormTools\General;
use FormTools\Settings;
use FormTools\Themes;

//require("global/session_start.php");

Core::init();

$settings = Settings::get();
$g_title = $settings['program_name'];
$theme = $settings['default_theme'];

$admin_info = Administrator::getAdminInfo();
$admin_email = $admin_info["email"];

// if a user id is included in the query string, use it to determine the appearance of the
// interface (including logo)
$id = General::loadField("id", "id", "");

if (!empty($id)) {
    $info = Accounts::getAccountInfo($id);

    if (!empty($info)) {
        $theme  = $info['theme'];
        $language = $info["ui_language"];
        include_once("global/lang/{$language}.php");
    }
}

// if trying to send password
if (isset($_POST) && !empty($_POST)) {
    list($g_success, $g_message) = ft_send_password($_POST);
}

$username = (isset($_POST["username"]) && !empty($_POST["username"])) ? $_POST["username"] : "";
$username = General::stripChars($username);

// --------------------------------------------------------------------------------------------

$replacements = array("site_admin_email" => "<a href=\"mailto:$admin_email\">$admin_email</a>");

$page_vars = array();
$page_vars["text_forgot_password"] = General::evalSmartyString($LANG["text_forgot_password"], $replacements);
$page_vars["head_title"] = $settings['program_name'];
$page_vars["page"] = "forgot_password";
$page_vars["page_url"] = Pages::getPageUrl("forgot_password");
$page_vars["settings"] = $settings;
$page_vars["username"] = $username;
$page_vars["head_js"] =<<<END
var rules = [];
rules.push("required,username,{$LANG['validation_no_username']}");
$(function() { document.forget_password.username.focus(); });
END;

Themes::displayPage("forget_password.tpl", $page_vars, $theme);
