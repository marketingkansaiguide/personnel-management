<?php
// Création des tables lors de l'activation du plugin
function pm_create_tables() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'pm_employees';
    $availability_table = $wpdb->prefix . 'pm_availabilities';
    $notification_table = $wpdb->prefix . 'pm_notifications';
    $charset_collate = $wpdb->get_charset_collate();

    $sql_employees = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        user_id bigint(20) PRIMARY KEY,
        name varchar(255) NOT NULL,
        PRIMARY KEY (id)
    ) $charset_collate";

    $sql_availabilities = "CREATE TABLE $availability_table (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        employee_id mediumint NOT NULL,
        date date NOT NULL,
        start_time time DEFAULT NULL,
        end_time time DEFAULT NULL,
        status varchar(20) NOT NULL,
        comment TEXT,
        PRIMARY KEY (id)
    ) $charset_collate";

    $sql_notifications = "CREATE TABLE $notification_table (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        employee_id mediumint NOT NULL,
        date date NOT NULL,
        start_time time DEFAULT NULL,
        end_time time DEFAULT NULL,
        status varchar(20) NOT NULL,
        comment TEXT,
        token varchar(64) NOT NULL,
        created_at datetime NOT NULL,
        PRIMARY KEY (id),
        INDEX token_idx (token)
    ) $charset_collate";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql_employees);
    dbDelta($sql_availabilities);
    dbDelta($sql_notifications);

    // Vérifier et ajouter la colonne comment si elle n'existe pas
    $column_exists = $wpdb->get_results("SHOW COLUMNS FROM $availability_table LIKE 'comment'");
    if (empty($column_exists)) {
        $wpdb->query("ALTER TABLE $availability_table ADD comment TEXT");
    }

    // Vérifier et ajouter les colonnes start_time et end_time si elles n'existent pas
    $start_time_exists = $wpdb->get_results("SHOW COLUMNS FROM $availability_table LIKE 'start_time'");
    if (empty($start_time_exists)) {
        $wpdb->query("ALTER TABLE $availability_table ADD start_time TIME DEFAULT NULL AFTER date");
    }
    $end_time_exists = $wpdb->get_results("SHOW COLUMNS FROM $availability_table LIKE 'end_time'");
    if (empty($end_time_exists)) {
        $wpdb->query("ALTER TABLE $availability_table ADD end_time TIME DEFAULT NULL AFTER start_time");
    }

    $start_time_notif_exists = $wpdb->get_results("SHOW COLUMNS FROM $notification_table LIKE 'start_time'");
    if (empty($start_time_notif_exists)) {
        $wpdb->query("ALTER TABLE $notification_table ADD start_time TIME DEFAULT NULL AFTER date");
    }
    $end_time_notif_exists = $wpdb->get_results("SHOW COLUMNS FROM $notification_table LIKE 'end_time'");
    if (empty($end_time_notif_exists)) {
        $wpdb->query("ALTER TABLE $notification_table ADD end_time TIME DEFAULT NULL AFTER start_time");
    }
}
register_activation_hook(PM_PLUGIN_DIR . 'personnel-management.php', 'pm_create_tables');
?>