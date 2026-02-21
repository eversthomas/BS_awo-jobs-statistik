<?php
/**
 * Plugin Name:       BS AWO Jobs Statistik
 * Plugin URI:        https://github.com/bezugssysteme/BS_awo-jobs-statistik
 * Description:       Statistische Auswertung offener und historischer Stellenausschreibungen (AWO). VZÄ, Fluktuation, Vakanzzeit.
 * Version:           0.1.0
 * Author:            Bezugssysteme (BS)
 * Author URI:        https://bezugssysteme.de
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       bs-awo-jobs-statistik
 * Domain Path:       /languages
 */

declare(strict_types=1);

namespace BS_Awo_Jobs_Statistik;

if (!defined('ABSPATH')) {
    exit;
}

define('BS_AWO_JOBS_STATISTIK_FILE', __FILE__);
define('BS_AWO_JOBS_STATISTIK_DIR', __DIR__);

$autoload = __DIR__ . '/vendor/autoload.php';
if (is_readable($autoload)) {
    require_once $autoload;
}
require_once __DIR__ . '/src/Core/Database.php';
require_once __DIR__ . '/src/Core/Installer.php';

\register_activation_hook(__FILE__, [\BS_Awo_Jobs_Statistik\Core\Installer::class, 'activate']);
