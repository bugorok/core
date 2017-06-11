<?php

/**
 * Form Tools - generic form processing, storage and access script
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without
 * even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
 * General Public License included in this zipfile for more details.
 *
 * The Core class added in 3.0.0. This replaces the old /global/library.php file. It's a singleton that's
 * instantiated for all page loads and contains all the core functionality / objects / data etc. used
 * throughout the script. Basically it's a convenience static object that contains most of the stuff you need, e.g.:
 *          - Core::$db (database connection)
 *          - Core::$user (current user)
 *          - Core::$L (language strings for current user)
 *
 * @copyright Benjamin Keen 2017
 * @author Benjamin Keen <ben.keen@gmail.com>
 * @version 3.0.x
 * @package 3-0-x
 */


// -------------------------------------------------------------------------------------------------

namespace FormTools;

use Smarty;


class Core {


    // SECTION 1: settings you can override in your global/config.php file

    /**
     * This is the base URL of the Form Tools installation on your server. e.g.
     * http://www.yoursite.com/formtools. You can either supply an absolute or relative URL. Note: if
     * you include the full URL, make sure that the "www." part is either included or removed
     * consistently; if you try to log in at http://www.yoursite.com/admin but your $rootURL is set to
     * http://yoursite.com/admin it will not work! (and vice versa).
     */
    private static $rootURL = "";

    /**
     * The server directory path to your Form Tools folder.
     */
    private static $rootDir = "";

    /**
     * The database hostname (most often 'localhost').
     */
    private static $dbHostname = "";

    /**
     * The name of the database. Most often, hosting providers provide you with some sort of user
     * interface for creating databases and assigning user accounts to them.
     */
    private static $dbName = "";

    /**
     * The DB port.
     */
    private static $dbPort = "";

    /**
     * The MySQL username. Note: this user account must have privileges for adding and deleting tables, and
     * adding and deleting records.
     */
    private static $dbUsername = "";

    /**
     * The MySQL password.
     */
    private static $dbPassword = "";

    /**
     * This option allows you make a secure connection to the database server using the MYSQL_CLIENT_SSL
     * flag.
     */
    private static $dbSSLEnabled = false;

    /**
     * This value lets you define a custom database prefix for your Form Tools tables. This is handy if
     * Form Tools will be added to an existing database and you want to avoid table naming conflicts.
     */
    private static $dbTablePrefix = "ft_";

    /**
     * This controls the maximum number of pagination links that appear in the Form Tools UI (e.g. for
     * viewing the submission listings page).
     */
    private static $maxNavPages;

    /**
     * This offers support for unicode. All form submissions will be sent as UTF-8. This is enabled for all
     * new installations.
     */
    private static $unicode = true;

    /**
     * This setting should be enabled PRIOR to including this file in any external script (e.g. the API)
     * that doesn't require the person to be logged into Form Tools. This lets you leverage the Form Tools
     * functionality in the outside world without already being logged into Form Tools.
     */
    private static $checkFTSessions = true;

    /**
     * This is set to 1 by default (genuine errors only). Crank it up to 2047 to list every
     * last error/warning/notice that occurs.
     */
    private static $errorReporting;

    /**
     * Various debug settings. As of 2.3.0 these are of varying degrees of being supported.
     */
    private static $debugEnabled;
    private static $jsDebugEnabled;
    private static $smartyDebug = false;
    private static $apiDebug = true;

    /**
     * This tells Smarty to create the compiled templates in subdirectories, which is slightly more efficient.
     * Not compatible on some systems, so it's set to false by default.
     */
    private static $smartyUseSubDirs = false;

    /**
     * This determines the value used to separate the content of array form submissions (e.g. checkboxes
     * in your form that have the same name, or multi-select dropdowns) when submitted via a query
     * string for "direct" form submissions (added in version 1.4.2).
     */
    private static $queryStrMultiValSeparator;

    /**
     * For module developers. This prevents the code from automatically deleting your module folder when you're
     * testing your uninstallation function. Defaults to TRUE, but doesn't work on all systems: sometimes the PHP
     * doesn't have the permission to remove the folder.
     */
    private static $deleteModuleFolderOnUninstallation = true;

    /**
     * This setting lets you control the type of sessions the application uses. The default value is "database",
     * but you can change it to "php" if you'd prefer to use PHP sessions. This applies to all users of the program.
     */
    private static $sessionType = "php"; // "php" or "database"

    /**
     * This lets you specify the session save path, used by PHP sessions. By default this isn't set, relying
     * on the default value. But on some systems this value needs to be set.
     */
    private static $sessionSavePath = "";

    /**
     * These two settings are for the ft_api_display_captcha() function. See the API documentation for more
     * information on how that works.
     */
    private static $apiRecaptchaPublicKey  = "";
    private static $apiRecaptchaPrivateKey = "";

    /**
     * This is used by the ft_api_init_form_page() function when setting up the environment for the webpage;
     * headers are sent with this charset.
     */
    private static $apiHeaderCharset = "utf-8";

    /**
     * Used for the database charset. For rare cases, the utf8 character set isn't available, so this allows
     * them to change it and install the script.
     */
    private static $dbTableCharset = "utf8";

    /**
     * The default sessions timeout for the API. Default is 1 hour (3600 seconds)
     */
    private static $apiSessionsTimeout = 3600;

    /**
     * Permissible characters in a filename. All other characters are stripped out. *** including a hyphen here
     * leads to problems. ***
     */
    private static $filenameCharWhitelist = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ_0123456789";

    /**
     * Special chars, required in password (optional setting through interface).
     */
    private static $requiredPasswordSpecialChars = "~!@#$%^&";

    /**
     * The size of the password_history setting in the settings table. Form Tools keeps track of the last 10
     * passwords, to (optionally) prevent users from re-using a password that they used before.
     */
    private static $passwordHistorySize = 10;

    /**
     * Determines the format of the date range string when searching a date field. Note: this only accepts two
     * values: d/m/y or m/d/y. This is because this value is used by both the daterangepicker element and
     * on the server. I don't want to fuss around with too many formats; it's confusing enough!
     */
    private static $searchFormDateFieldFormat;

    /**
     * Added in 2.1.0 and enabled by default. This overrides the default SQL mode for any query, to prevent
     * problems that may arise due to MySQL strict mode being on.
     */
    private static $setSqlMode = true;

    /**
     * This hides the upgrade link in the administrator's UI.
     */
    private static $hideUpgradeLink;

    /**
     * Limits the number of forms that can be stored in the database. If left blank there are no limits.
     */
    private static $maxForms = "";

    /**
     * Limits the number of fields that can be stored for a form.
     */
    private static $maxFormFields = "";


    // -------------------------------------------------------------------------------------------------


    // SECTION 2: internal settings. These can't be overridden.

    /**
     * The database instance automatically instantiated by Core::init(). This allows any code to just
     * reference Core::$db for any database interaction.
     * @var Database
     */
    public static $db;

    /**
     * @var Smarty
     */
    public static $smarty;

    /**
     * The translations object. Used to get the current UI language and translation strings (Core::$translations->getList())
     * @var Translations
     */
    public static $translations;

    public static $L;
    private static $currLang;

    /**
     * User-related settings.
     * @var User
     */
    public static $user;

    /**
     * Tracks whether the user's configuration file exists.
     */
    private static $configFileExists = false;

    /**
     * The current version of the Form Tools Core.
     */
    private static $version = "3.0.0";

    /**
     * The release type: alpha, beta or main
     */
    private static $releaseType = "beta";

    /**
     * The release date: YYYYMMDD
     */
    private static $releaseDate = "20170403";

    /**
     * The minimum required PHP version needed to run Form Tools.
     */
    protected static $requiredPhpVersion = "5.3";

    /**
     * The minimum required MySQL version needed to run Form Tools.
     */
    private static $requiredMysqlVersion = "4.1.2";

    /**
     * Default values. These are use during installation when we have no idea what the user wants. For non-authenticated
     * people visiting the login/forget password pages, they'll get whatever theme & lang has been configured in the
     * database (I figure that's a bit more flexible putting it there than hardcoded in config file).
     */
    private static $defaultTheme = "default";
    private static $defaultLang = "en_us";

    /**
     * This determines the value used in the database to separate multiple field values (checkboxes and
     * multi-select boxes) and image filenames (main image, main thumb, search results thumb). It's strongly
     * recommended to leave this value alone.
     */
    private static $multiFieldValDelimiter;

    /**
     * Used throughout the script to store any and all temporary error / notification messages. Don't change
     * or remove - defining them here prevents unwanted PHP notices.
     */
//    private static $g_success = "";
//    private static $g_message = "";

    /**
     * Simple benchmarking code. When enabled, this outputs a page load time in the footer.
     */
    private static $enableBenchmarking;
    private static $benchmarkStart     = "";

    /**
     * Used for caching data sets during large, repeat operations.
     */
    private static $cache = array();

    /**
     * Added in 2.3.0 to prevent hooks being executed during in the installation process, prior to the database being
     * ready. This was always an issue but the errors were swallowed up with earlier versions of PHP.
     */
    private static $hooksEnabled = true;

    /**
     * Added in 2.1.0 to provide better error checking on the login page. This is used to confirm that all the Core
     * tables do in fact exist before letting the user log in.
     */
    private static $coreTables = array(
        "account_settings",
        "accounts",
        "client_forms",
        "client_views",
        "email_template_edit_submission_views",
        "email_template_recipients",
        "email_template_when_sent_views",
        "email_templates",
        "field_options",
        "field_settings",
        "field_type_setting_options",
        "field_type_settings",
        "field_types",
        "field_type_validation_rules",
        "field_validation",
        "form_email_fields",
        "form_fields",
        "forms",
        "hook_calls",
        "hooks",
        "list_groups",
        "menu_items",
        "menus",
        "modules",
        "module_menu_items",
        "multi_page_form_urls",
        "new_view_submission_defaults",
        "option_lists",
        "public_form_omit_list",
        "public_view_omit_list",
        "sessions",
        "settings",
        "themes",
        "views",
        "view_columns",
        "view_fields",
        "view_filters",
        "view_tabs"
    );

    /**
     * Initializes the Core singleton for use throughout Form Tools.
     *
     * *** This contents of this method is still being determined ***
     *
     *   - sets up PDO database connection available through Core::$db
     *   - starts sessions
     *   - if a user is logged in, instantiates the User object and makes it available via Core::$user
     *   - The language is user-specific, but lang strings available as a convenience here: Core::$L
     */
    public static function init($options = array()) {

        self::loadConfigFile();

        // explicitly set the error reporting value
        error_reporting(self::$errorReporting);

        if (self::$configFileExists) {
            self::initDatabase();
        }

        // ensure the application has been installed. This redirects the
        Installation::checkInstalled();

        self::$smarty = new Smarty();

//        if ($options["check_sessions"] == false) {
        self::startSessions();
  //      }

        self::$user = new User();
        self::$currLang = self::$user->getLang();

//        if (self::checkFTSessions() && self::$user->isLoggedIn()) {
//            ft_check_sessions_timeout();
//        }

        // OVERRIDES - TODO interface
        if (isset($options["currLang"])) {
            self::$currLang = $options["currLang"];
        }

        self::setCurrLang(self::$currLang);
        self::enableDebugging();

        // optionally enable benchmarking. Dev-only feature to confirm pages aren't taking too long to load
        if (self::$enableBenchmarking) {
            self::$benchmarkStart = General::getMicrotimeFloat();
        }

        // not thrilled with this, but it needs to be handled on all pages, and this is a convenient spot
        if (Core::checkConfigFileExists()) {
            if (isset($_GET["logout"])) {
                Core::$user->logout();
            }
        }
    }

    public static function startSessions() {
        if (self::$sessionType == "database") {
            new SessionManager();
        }

        if (!empty(self::$sessionSavePath)) {
            session_save_path(self::$sessionSavePath);
        }

        session_start();
        header("Cache-control: private");
        header("Content-Type: text/html; charset=utf-8");
    }

    /**
     * @access public
     */
    public static function checkConfigFileExists() {
        return self::$configFileExists;
    }


    /**
     * Loads the user's config file. If successful, it updates the various private member vars
     * with whatever's been defined.
     * @access private
     */
    private static function loadConfigFile() {
        $configFilePath = realpath(__DIR__ . "/../config.php");
        if (!file_exists($configFilePath)) {
            return;
        }
        require_once($configFilePath);

        self::$configFileExists = true;

        self::$rootURL     = (isset($g_root_url)) ? $g_root_url : null;
        self::$rootDir     = (isset($g_root_dir)) ? $g_root_dir : null;
        self::$dbHostname  = (isset($g_db_hostname)) ? $g_db_hostname : null;
        self::$dbName      = (isset($g_db_name)) ? $g_db_name : null;
        self::$dbPort      = (isset($g_db_port)) ? $g_db_port : null;
        self::$dbUsername  = (isset($g_db_username)) ? $g_db_username : null;
        self::$dbPassword  = (isset($g_db_password)) ? $g_db_password : null;
        self::$dbTablePrefix = (isset($g_table_prefix)) ? $g_table_prefix : null;
        self::$unicode    = (isset($g_unicode)) ? $g_unicode : null;
        self::$setSqlMode = (isset($g_set_sql_mode)) ? $g_set_sql_mode : null;
        self::$hideUpgradeLink = (isset($g_hide_upgrade_link)) ? $g_hide_upgrade_link : false;
        self::$enableBenchmarking = (isset($g_enable_benchmarking)) ? $g_enable_benchmarking : false;
        self::$jsDebugEnabled = isset($g_js_debug) ? $g_js_debug : false;
        self::$maxForms = isset($g_max_forms) ? $g_max_forms : "";
        self::$maxNavPages = isset($g_max_nav_pages) ? $g_max_nav_pages : 16;
        self::$searchFormDateFieldFormat = isset($g_search_form_date_field_format) ? $g_search_form_date_field_format : "d/m/y";
        self::$multiFieldValDelimiter = isset($g_multi_val_delimiter) ? $g_multi_val_delimiter : ", ";
        self::$queryStrMultiValSeparator = isset($g_query_str_multi_val_separator) ? $g_query_str_multi_val_separator : ",";
        self::$errorReporting = isset($g_default_error_reporting) ? $g_default_error_reporting : 1;
        self::$debugEnabled = isset($g_debug) ? $g_debug : false;
    }

    /**
     * Called automatically in Core::init(). This initializes a default database connection, accessible via Core::$db
     */
    private static function initDatabase() {
        self::$db = new Database(self::$dbHostname, self::$dbName, self::$dbPort, self::$dbUsername, self::$dbPassword,
            self::$dbTablePrefix);
    }

    public static function getRootUrl() {
        return self::$rootURL;
    }

    public static function getRootDir() {
        return self::$rootDir;
    }

    public static function isValidPHPVersion() {
        return version_compare(phpversion(), self::$requiredPhpVersion, ">=");
    }

    public static function getCoreTables() {
        return self::$coreTables;
    }

    public static function getDbTablePrefix() {
        return self::$dbTablePrefix;
    }

    public static function getCoreVersion() {
        return self::$version;
    }

    public static function getReleaseDate() {
        return self::$releaseDate;
    }

    public static function getReleaseType() {
        return self::$releaseType;
    }

    public static function getDbTableCharset() {
        return self::$dbTableCharset;
    }

    public static function enableDebugging() {
        ini_set('display_errors', 1);
        ini_set('display_startup_errors', 1);
        error_reporting(E_ALL);;
    }

    public static function isUnicode() {
        return self::$unicode;
    }

    public static function shouldSetSqlMode() {
        return self::$setSqlMode;
    }

    public static function shouldDeleteFolderOnUninstallation() {
        return self::$deleteModuleFolderOnUninstallation;
    }

    public static function getDefaultLang() {
        return self::$defaultLang;
    }

    public static function getDefaultTheme() {
        return self::$defaultTheme;
    }

    public static function setHooksEnabled($status) {
        self::$hooksEnabled = $status;
    }

    public static function areHooksEnabled() {
        return self::$hooksEnabled;
    }

    public static function setCurrLang($lang) {
        self::$currLang = $lang;
        self::$translations = new Translations(self::$currLang);
        self::$L = self::$translations->getStrings();
    }

    public static function getCurrentLang() {
        return self::$currLang;
    }

    public static function checkFTSessions() {
        return self::$checkFTSessions;
    }

    public static function getPasswordHistorySize() {
        return self::$passwordHistorySize;
    }

    public static function getDbName() {
        return self::$dbName;
    }

    public static function getRequiredPasswordSpecialChars() {
        return self::$requiredPasswordSpecialChars;
    }

    public static function isSmartyDebugEnabled() {
        return self::$smartyDebug;
    }

    public static function isJsDebugEnabled() {
        return self::$jsDebugEnabled;
    }

    public static function shouldUseSmartySubDirs() {
        return self::$smartyUseSubDirs;
    }

    public static function shouldHideUpgradeLink() {
        return self::$hideUpgradeLink;
    }

    public static function isBenchmarkingEnabled() {
        return self::$enableBenchmarking;
    }

    public static function getBenchmarkStart() {
        return self::$benchmarkStart;
    }

    public static function getMaxForms() {
        return self::$maxForms;
    }

    public static function getMaxNavPages() {
        return self::$maxNavPages;
    }

    public static function getSearchFormDateFieldFormat() {
        return self::$searchFormDateFieldFormat;
    }

    public static function getMultiFieldValDelimiter() {
        return self::$multiFieldValDelimiter;
    }

    public static function isDebugEnabled() {
        return self::$debugEnabled;
    }

    public static function getDefaultErrorReporting() {
        return self::$errorReporting;
    }

    public static function getQueryStrMultiValSeparator() {
        return self::$queryStrMultiValSeparator;
    }
}
