<?php
/*
Plugin Name: Personnel Management
Description: Gestion des guides et de leurs disponibilités avec un calendrier.
Version: 1.20
Author: Maxime CERQUEIRA
*/

// Empêcher l'accès direct au fichier
if (!defined('ABSPATH')) {
    exit;
}

// Définir des constantes
define('PM_PLUGIN_DIR', plugin_dir_path(__FILE__));

// Inclure les fichiers nécessaires
require_once PM_PLUGIN_DIR . 'database.php';
require_once PM_PLUGIN_DIR . 'roles.php';
require_once PM_PLUGIN_DIR . 'enqueue.php';
require_once PM_PLUGIN_DIR . 'shortcodes.php';
require_once PM_PLUGIN_DIR . 'ajax.php';
require_once PM_PLUGIN_DIR . 'endpoints.php';
?>