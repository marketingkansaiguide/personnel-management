<?php
/*
Plugin Name: Planning Guides
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

// Création des tables lors de l'activation du plugin
function pm_create_tables() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'pm_employees';
    $availability_table = $wpdb->prefix . 'pm_availabilities';
    $notification_table = $wpdb->prefix . 'pm_notifications';
    $characteristics_table = $wpdb->prefix . 'pm_characteristics';
    $employee_characteristics_table = $wpdb->prefix . 'pm_employee_characteristics';
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
        start_date date NOT NULL,
        end_date date DEFAULT NULL,
        start_time time DEFAULT NULL,
        end_time time DEFAULT NULL,
        status varchar(20) NOT NULL,
        comment TEXT,
        remuneration VARCHAR(255) DEFAULT NULL,
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
        remuneration VARCHAR(255) DEFAULT NULL,
        token varchar(64) NOT NULL,
        created_at datetime NOT NULL,
        PRIMARY KEY (id),
        INDEX token_idx (token)
    ) $charset_collate";

    $sql_characteristics = "CREATE TABLE $characteristics_table (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        name varchar(255) NOT NULL,
        PRIMARY KEY (id)
    ) $charset_collate";

    $sql_employee_characteristics = "CREATE TABLE $employee_characteristics_table (
        employee_id mediumint NOT NULL,
        characteristic_id mediumint NOT NULL,
        PRIMARY KEY (employee_id, characteristic_id)
    ) $charset_collate";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql_employees);
    dbDelta($sql_availabilities);
    dbDelta($sql_notifications);
    dbDelta($sql_characteristics);
    dbDelta($sql_employee_characteristics);

    if ($wpdb->get_var("SHOW COLUMNS FROM `$availability_table` LIKE 'date'")) {
        $wpdb->query("ALTER TABLE `$availability_table` CHANGE `date` `start_date` DATE NOT NULL");
    }
    if (!$wpdb->get_var("SHOW COLUMNS FROM `$availability_table` LIKE 'end_date'")) {
        $wpdb->query("ALTER TABLE `$availability_table` ADD `end_date` DATE DEFAULT NULL AFTER `start_date`");
    }
    if (!$wpdb->get_var("SHOW COLUMNS FROM `$availability_table` LIKE 'remuneration'")) {
        $wpdb->query("ALTER TABLE `$availability_table` ADD `remuneration` VARCHAR(255) DEFAULT NULL AFTER `comment`");
    }
    if (!$wpdb->get_var("SHOW COLUMNS FROM `$notification_table` LIKE 'remuneration'")) {
        $wpdb->query("ALTER TABLE `$notification_table` ADD `remuneration` VARCHAR(255) DEFAULT NULL AFTER `comment`");
    }

    $column_exists = $wpdb->get_results("SHOW COLUMNS FROM $availability_table LIKE 'comment'");
    if (empty($column_exists)) {
        $wpdb->query("ALTER TABLE $availability_table ADD comment TEXT");
    }
    $start_time_exists = $wpdb->get_results("SHOW COLUMNS FROM $availability_table LIKE 'start_time'");
    if (empty($start_time_exists)) {
        $wpdb->query("ALTER TABLE $availability_table ADD start_time TIME DEFAULT NULL AFTER end_date");
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
register_activation_hook(__FILE__, 'pm_create_tables');

// Créer un rôle personnalisé pour les employés
function pm_add_employee_role() {
    add_role('pm_employee', 'Employé', [
        'read' => true,
        'pm_manage_availability' => true
    ]);
}
register_activation_hook(__FILE__, 'pm_add_employee_role');

// Supprimer le rôle personnalisé lors de la désactivation
function pm_remove_employee_role() {
    remove_role('pm_employee');
}
register_deactivation_hook(__FILE__, 'pm_remove_employee_role');

// Enqueue scripts et styles
function pm_enqueue_scripts() {
    global $post;

    if (is_a($post, 'WP_Post') && (
        has_shortcode($post->post_content, 'pm_manage_employees') ||
        has_shortcode($post->post_content, 'pm_admin_calendar') ||
        has_shortcode($post->post_content, 'pm_employee_login')
    )) {
        wp_enqueue_script('jquery');
        wp_enqueue_script('fullcalendar', 'https://cdn.jsdelivr.net/npm/fullcalendar@5.11.0/main.min.js', ['jquery'], '5.11.0', true);
        wp_enqueue_style('fullcalendar', 'https://cdn.jsdelivr.net/npm/fullcalendar@5.11.0/main.min.css', [], '5.11.0');
        wp_enqueue_style('pm-styles', plugins_url('/css/pm-styles.css', __FILE__), [], '1.9', 'all');
        // Ajouter le nonce via wp_localize_script
        wp_localize_script('fullcalendar', 'pmAjax', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('pm_status')
        ]);
    }
}
add_action('wp_enqueue_scripts', 'pm_enqueue_scripts');

// Shortcode pour la gestion des employés en front-office
function pm_manage_employees_shortcode() {
    // Intercepte la demande d'export avant l'affichage de la page
    if (
        isset($_POST['pm_export_action']) &&
        $_POST['pm_export_action'] === 'export_remuneration' &&
        isset($_POST['pm_export_nonce']) &&
        wp_verify_nonce(sanitize_key($_POST['pm_export_nonce']), 'pm_export_nonce_action')
    ) {
        if (current_user_can('manage_options')) {
            $month = sanitize_text_field(wp_unslash($_POST['export_month']));
            pm_handle_remuneration_export($month);
        }
    }

    static $shortcode_executed = false;
    if ($shortcode_executed) {
        return '<p class="pm-message pm-message-error">Le shortcode [pm_manage_employees] ne peut être utilisé qu’une fois par page.</p>';
    }
    $shortcode_executed = true;

    if (!is_user_logged_in()) {
        ob_start();
        ?>
        <div class="pm-login-form">
            <h2>Connexion</h2>
            <form method="post" action="<?php echo esc_url(wp_login_url(get_permalink())); ?>" class="pm-login-form-inner">
                <label for="log">Identifiant ou adresse email</label>
                <input type="text" name="log" id="log" required>
                <label for="pwd">Mot de passe</label>
                <input type="password" name="pwd" id="pwd" required>
                <input type="submit" value="Se connecter">
                <p><a href="<?php echo esc_url(wp_lostpassword_url()); ?>">Mot de passe oublié ?</a></p>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    if (!current_user_can('manage_options')) {
        return '<p class="pm-message pm-message-error">Accès réservé aux administrateurs.</p>';
    }

    global $wpdb;
    $employees = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}pm_employees");
    $characteristics = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}pm_characteristics");

    $message = '';
    if (isset($_POST['pm_action']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['pm_nonce'])), 'pm_employee_action')) {
        $name = sanitize_text_field(wp_unslash($_POST['employee_name']));
        $email = sanitize_email(wp_unslash($_POST['employee_email']));
        $password = wp_unslash($_POST['employee_password']);
        $selected_characteristics = isset($_POST['employee_characteristics']) ? array_map('absint', $_POST['employee_characteristics']) : [];
        $action = sanitize_text_field(wp_unslash($_POST['pm_action']));
        $employee_id = isset($_POST['employee_id']) ? absint($_POST['employee_id']) : 0;

        if (empty($name) || empty($email)) {
            $message = '<p class="pm-message pm-message-error">Nom et email sont requis.</p>';
        } elseif (!is_email($email)) {
            $message = '<p class="pm-message pm-message-error">Email invalide.</p>';
        } elseif ($action === 'add') {
            if (email_exists($email)) {
                $message = '<p class="pm-message pm-message-error">Cet email est déjà utilisé.</p>';
            } elseif (empty($password)) {
                $message = '<p class="pm-message pm-message-error">Mot de passe requis.</p>';
            } else {
                $user_id = wp_create_user($email, $password, $email);
                if (!is_wp_error($user_id)) {
                    $wpdb->insert(
                        $wpdb->prefix . 'pm_employees',
                        ['user_id' => absint($user_id), 'name' => $name],
                        ['%d', '%s']
                    );
                    $new_employee_id = $wpdb->insert_id;
                    foreach ($selected_characteristics as $char_id) {
                        $wpdb->insert(
                            $wpdb->prefix . 'pm_employee_characteristics',
                            ['employee_id' => $new_employee_id, 'characteristic_id' => $char_id],
                            ['%d', '%d']
                        );
                    }
                    wp_update_user(['ID' => absint($user_id), 'role' => 'pm_employee']);
                    $message = '<p class="pm-message pm-message-success">Employé ajouté avec succès.</p>';
                } else {
                    $message = '<p class="pm-message pm-message-error">Erreur lors de la création de l’utilisateur : ' . esc_html($user_id->get_error_message()) . '</p>';
                }
            }
        } elseif ($action === 'edit' && $employee_id) {
            $employee = $wpdb->get_row($wpdb->prepare("SELECT user_id FROM {$wpdb->prefix}pm_employees WHERE id = %d", absint($employee_id)));
            if ($employee) {
                $user_data = ['ID' => absint($employee->user_id)];
                if ($email !== get_userdata($employee->user_id)->user_email) {
                    if (email_exists($email)) {
                        $message = '<p class="pm-message pm-message-error">Cet email est déjà utilisé.</p>';
                    } else {
                        $user_data['user_email'] = $email;
                    }
                }
                if (!empty($password)) {
                    wp_set_password($password, absint($employee->user_id));
                }
                wp_update_user($user_data);
                $wpdb->update(
                    $wpdb->prefix . 'pm_employees',
                    ['name' => $name],
                    ['id' => absint($employee_id)],
                    ['%s'],
                    ['%d']
                );
                $wpdb->delete($wpdb->prefix . 'pm_employee_characteristics', ['employee_id' => $employee_id], ['%d']);
                foreach ($selected_characteristics as $char_id) {
                    $wpdb->insert(
                        $wpdb->prefix . 'pm_employee_characteristics',
                        ['employee_id' => $employee_id, 'characteristic_id' => $char_id],
                        ['%d', '%d']
                    );
                }
                $message = '<p class="pm-message pm-message-success">Employé mis à jour avec succès.</p>';
            } else {
                $message = '<p class="pm-message pm-message-error">Employé non trouvé.</p>';
            }
        }
        $employees = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}pm_employees");
    }

    if (isset($_POST['add_characteristic']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['pm_char_nonce'])), 'pm_characteristic_action')) {
        $char_name = sanitize_text_field(wp_unslash($_POST['characteristic_name']));
        if (!empty($char_name)) {
            $wpdb->insert(
                $wpdb->prefix . 'pm_characteristics',
                ['name' => $char_name],
                ['%s']
            );
            $characteristics = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}pm_characteristics");
            $message = '<p class="pm-message pm-message-success">Caractéristique ajoutée avec succès.</p>';
        } else {
            $message = '<p class="pm-message pm-message-error">Nom de caractéristique requis.</p>';
        }
    }

    if (isset($_POST['delete_employee']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['pm_delete_nonce'])), 'pm_delete_employee')) {
        $employee_id = absint($_POST['employee_id']);
        $employee = $wpdb->get_row($wpdb->prepare("SELECT user_id FROM {$wpdb->prefix}pm_employees WHERE id = %d", $employee_id));
        if ($employee) {
            $user_data = get_userdata($employee->user_id);
            if ($user_data && in_array('administrator', (array)$user_data->roles)) {
                $message = '<p class="pm-message pm-message-error">Impossible de supprimer un compte administrateur.</p>';
            } else {
                $wpdb->delete($wpdb->prefix . 'pm_employees', ['id' => $employee_id], ['%d']);
                $wpdb->delete($wpdb->prefix . 'pm_availabilities', ['employee_id' => $employee_id], ['%d']);
                $wpdb->delete($wpdb->prefix . 'pm_employee_characteristics', ['employee_id' => $employee_id], ['%d']);
                wp_delete_user($employee->user_id);
                $employees = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}pm_employees");
                $message = '<p class="pm-message pm-message-success">Employé supprimé avec succès.</p>';
            }
        } else {
            $message = '<p class="pm-message pm-message-error">Employé non trouvé.</p>';
        }
    }

    ob_start();
    ?>
    <div class="pm-manage-employees">
        <div id="pm_message_area" class="pm-message" style="display: <?php echo $message ? 'block' : 'none'; ?>;">
            <?php echo wp_kses_post($message); ?>
        </div>
        <h2>Gérer les employés</h2>

        <h3>Export des Rémunérations</h3>
        <form method="post" action="">
            <label for="export_month">Choisir le mois :</label>
            <input type="month" id="export_month" name="export_month" value="<?php echo esc_attr(date('Y-m')); ?>" required>
            <input type="hidden" name="pm_export_action" value="export_remuneration">
            <?php wp_nonce_field('pm_export_nonce_action', 'pm_export_nonce'); ?>
            <input type="submit" value="Exporter en CSV">
        </form>

        <h3>Ajouter un employé</h3>
        <form id="pm_add_employee_form" method="post" action="">
            <input type="text" name="employee_name" placeholder="Nom de l'employé" required maxlength="255">
            <input type="email" name="employee_email" placeholder="Email" required>
            <input type="password" name="employee_password" placeholder="Mot de passe" required>
            <label>Caractéristiques :</label>
            <select name="employee_characteristics[]" multiple class="pm-characteristics-unique">
                <?php foreach ($characteristics as $char) : ?>
                    <option value="<?php echo esc_attr($char->id); ?>"><?php echo esc_html($char->name); ?></option>
                <?php endforeach; ?>
            </select>
            <input type="hidden" name="pm_action" value="add">
            <?php wp_nonce_field('pm_employee_action', 'pm_nonce'); ?>
            <input type="submit" value="Ajouter">
        </form>

        <h3>Ajouter une caractéristique</h3>
        <form method="post" action="">
            <input type="text" name="characteristic_name" placeholder="Nom de la caractéristique" required maxlength="255">
            <?php wp_nonce_field('pm_characteristic_action', 'pm_char_nonce'); ?>
            <input type="submit" name="add_characteristic" value="Ajouter caractéristique">
        </form>

        <h3>Liste des employés</h3>
        <table class="pm-employee-table">
            <thead>
                <tr>
                    <th>Nom</th>
                    <th>Email</th>
                    <th>Caractéristiques</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="pm_employee_list">
                <?php foreach ($employees as $employee) :
                    $user_data = get_userdata($employee->user_id);
                    if (!$user_data) continue;
                    $employee_chars = $wpdb->get_results($wpdb->prepare(
                        "SELECT c.id, c.name FROM {$wpdb->prefix}pm_characteristics c
                        JOIN {$wpdb->prefix}pm_employee_characteristics ec ON c.id = ec.characteristic_id
                        WHERE ec.employee_id = %d",
                        $employee->id
                    ));
                    $char_names = implode(', ', array_column($employee_chars, 'name'));
                    $char_ids = array_column($employee_chars, 'id');
                    $is_admin = in_array('administrator', (array)$user_data->roles);
                    ?>
                    <tr data-employee-id="<?php echo esc_attr(absint($employee->id)); ?>">
                        <td><?php echo esc_html($employee->name); ?></td>
                        <td><?php echo esc_html($user_data->user_email); ?></td>
                        <td><?php echo esc_html($char_names ?: '-'); ?></td>
                        <td>
                            <button type="button" onclick="pmEditEmployee(<?php echo esc_attr(absint($employee->id)); ?>, '<?php echo esc_js($employee->name); ?>', '<?php echo esc_js($user_data->user_email); ?>', [<?php echo implode(',', $char_ids); ?>])">Modifier</button>
                            <?php if (!$is_admin) : ?>
                                <form method="post" style="display:inline;" class="pm_delete_employee_form">
                                    <input type="hidden" name="employee_id" value="<?php echo esc_attr(absint($employee->id)); ?>">
                                    <?php wp_nonce_field('pm_delete_employee', 'pm_delete_nonce'); ?>
                                    <input type="submit" name="delete_employee" value="Supprimer" onclick="return confirm('Confirmer la suppression ?');">
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <form method="post" action="" id="pm_edit_employee_form" style="display:none;">
            <input type="text" name="employee_name" id="pm_edit_employee_name" required maxlength="255">
            <input type="email" name="employee_email" id="pm_edit_employee_email" required>
            <input type="password" name="employee_password" id="pm_edit_employee_password" placeholder="Nouveau mot de passe (optionnel)">
            <label>Caractéristiques :</label>
            <select name="employee_characteristics[]" id="pm_edit_employee_characteristics" multiple class="pm-characteristics-unique">
                <?php foreach ($characteristics as $char) : ?>
                    <option value="<?php echo esc_attr($char->id); ?>"><?php echo esc_html($char->name); ?></option>
                <?php endforeach; ?>
            </select>
            <input type="hidden" name="employee_id" id="pm_edit_employee_id">
            <input type="hidden" name="pm_action" value="edit">
            <?php wp_nonce_field('pm_employee_action', 'pm_nonce'); ?>
            <input type="submit" value="Mettre à jour">
        </form>
    </div>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const editForm = document.getElementById('pm_edit_employee_form');
            if (editForm) {
                editForm.style.display = 'none';
            }

            const addForm = document.getElementById('pm_add_employee_form');
            const messageArea = document.getElementById('pm_message_area');
            const employeeList = document.getElementById('pm_employee_list');

            if (addForm) {
                addForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    const formData = new FormData(addForm);
                    formData.append('action', 'pm_add_employee');

                    messageArea.style.display = 'none';
                    messageArea.classList.remove('pm-message-success', 'pm-message-error');

                    jQuery.ajax({
                        url: '<?php echo esc_url(admin_url('admin-ajax.php', 'https')); ?>',
                        type: 'POST',
                        data: formData,
                        processData: false,
                        contentType: false,
                        success: function(response) {
                            if (response.success) {
                                messageArea.textContent = 'Succès : Employé ajouté avec succès.';
                                messageArea.classList.add('pm-message-success');
                                messageArea.style.display = 'block';
                                setTimeout(() => {
                                    messageArea.style.display = 'none';
                                }, 3000);

                                addForm.reset();

                                jQuery.get('<?php echo esc_url(admin_url('admin-ajax.php', 'https')); ?>', {
                                    action: 'pm_get_employees'
                                }, function(resp) {
                                    if (resp.success) {
                                        employeeList.innerHTML = resp.data.html;
                                        const editForm = document.getElementById('pm_edit_employee_form');
                                        if (editForm) {
                                            editForm.style.display = 'none';
                                            editForm.reset();
                                        }
                                    } else {
                                        messageArea.textContent = 'Erreur lors du rafraîchissement de la liste.';
                                        messageArea.classList.add('pm-message-error');
                                        messageArea.style.display = 'block';
                                    }
                                });
                            } else {
                                messageArea.textContent = 'Erreur : ' + (response.data || 'Erreur inconnue.');
                                messageArea.classList.add('pm-message-error');
                                messageArea.style.display = 'block';
                            }
                        },
                        error: function() {
                            messageArea.textContent = 'Erreur de connexion au serveur.';
                            messageArea.classList.add('pm-message-error');
                            messageArea.style.display = 'block';
                        }
                    });
                });
            }

            window.pmEditEmployee = function(id, name, email, characteristics) {
                id = parseInt(id, 10);
                if (isNaN(id) || id <= 0) {
                    alert('Identifiant d’employé invalide.');
                    return;
                }
                name = name || '';
                email = email || '';
                characteristics = characteristics || [];
                document.getElementById('pm_edit_employee_id').value = id;
                document.getElementById('pm_edit_employee_name').value = name;
                document.getElementById('pm_edit_employee_email').value = email;
                const select = document.getElementById('pm_edit_employee_characteristics');
                Array.from(select.options).forEach(option => {
                    option.selected = characteristics.includes(parseInt(option.value));
                });
                document.getElementById('pm_edit_employee_form').style.display = 'block';
            };
        });
    </script>
    <?php
    return ob_get_clean();
}
add_shortcode('pm_manage_employees', 'pm_manage_employees_shortcode');

// AJAX pour ajouter un employé
function pm_add_employee() {
    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['pm_nonce'])), 'pm_employee_action')) {
        wp_send_json_error('Nonce invalide.');
    }

    if (!current_user_can('manage_options')) {
        wp_send_json_error('Accès non autorisé.');
    }

    $name = sanitize_text_field(wp_unslash($_POST['employee_name']));
    $email = sanitize_email(wp_unslash($_POST['employee_email']));
    $password = wp_unslash($_POST['employee_password']);
    $characteristics = isset($_POST['employee_characteristics']) ? array_map('absint', $_POST['employee_characteristics']) : [];

    if (empty($name)) {
        wp_send_json_error('Nom requis.');
    }
    if (!is_email($email)) {
        wp_send_json_error('Email invalide.');
    }
    if (email_exists($email)) {
        wp_send_json_error('Cet email est déjà utilisé.');
    }
    if (empty($password)) {
        wp_send_json_error('Mot de passe requis.');
    }

    global $wpdb;
    $user_id = wp_create_user($email, $password, $email);
    if (is_wp_error($user_id)) {
        wp_send_json_error('Erreur lors de la création de l’utilisateur : ' . esc_html($user_id->get_error_message()));
    }

    $wpdb->insert(
        $wpdb->prefix . 'pm_employees',
        ['user_id' => absint($user_id), 'name' => $name],
        ['%d', '%s']
    );
    $new_employee_id = $wpdb->insert_id;
    foreach ($characteristics as $char_id) {
        $wpdb->insert(
            $wpdb->prefix . 'pm_employee_characteristics',
            ['employee_id' => $new_employee_id, 'characteristic_id' => $char_id],
            ['%d', '%d']
        );
    }
    wp_update_user(['ID' => absint($user_id), 'role' => 'pm_employee']);

    wp_send_json_success();
}
add_action('wp_ajax_pm_add_employee', 'pm_add_employee');

// AJAX pour récupérer la liste des employés
function pm_get_employees() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Accès non autorisé.');
    }

    global $wpdb;
    $employees = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}pm_employees");

    ob_start();
    foreach ($employees as $employee) :
        $user = get_userdata($employee->user_id);
        if (!$user) continue;
        $employee_chars = $wpdb->get_results($wpdb->prepare(
            "SELECT c.id, c.name FROM {$wpdb->prefix}pm_characteristics c
            JOIN {$wpdb->prefix}pm_employee_characteristics ec ON c.id = ec.characteristic_id
            WHERE ec.employee_id = %d",
            $employee->id
        ));
        $char_names = implode(', ', array_column($employee_chars, 'name'));
        $char_ids = array_column($employee_chars, 'id');
        ?>
        <tr data-employee-id="<?php echo esc_attr(absint($employee->id)); ?>">
            <td><?php echo esc_html($employee->name); ?></td>
            <td><?php echo esc_html($user->user_email); ?></td>
            <td><?php echo esc_html($char_names ?: '-'); ?></td>
            <td>
                <button type="button" onclick="pmEditEmployee(<?php echo esc_attr(absint($employee->id)); ?>, '<?php echo esc_js($employee->name); ?>', '<?php echo esc_js($user->user_email); ?>', [<?php echo implode(',', $char_ids); ?>])">Modifier</button>
                <form method="post" style="display:inline;" class="pm_delete_employee_form">
                    <input type="hidden" name="employee_id" value="<?php echo esc_attr(absint($employee->id)); ?>">
                    <?php wp_nonce_field('pm_delete_employee', 'pm_delete_nonce'); ?>
                    <input type="submit" name="delete_employee" value="Supprimer" onclick="return confirm('Confirmer la suppression ?');">
                </form>
            </td>
        </tr>
    <?php endforeach;
    $html = ob_get_clean();

    wp_send_json_success(['html' => $html]);
}
add_action('wp_ajax_pm_get_employees', 'pm_get_employees');

// Shortcode pour la page de connexion des employés et calendrier
// Shortcode pour la page de connexion des employés et calendrier
function pm_employee_login_shortcode() {
    if (!is_user_logged_in()) {
        ob_start();
        ?>
        <div class="pm-login-form">
            <h2>Connexion</h2>
            <form method="post" action="<?php echo esc_url(wp_login_url(get_permalink())); ?>" class="pm-login-form-inner">
                <label for="log">Identifiant ou adresse email</label>
                <input type="text" name="log" id="log" required>
                <label for="pwd">Mot de passe</label>
                <input type="password" name="pwd" id="pwd" required>
                <input type="submit" value="Se connecter">
                <p><a href="<?php echo esc_url(wp_lostpassword_url(get_permalink())); ?>">Mot de passe oublié ?</a></p>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    global $wpdb;
    $user = wp_get_current_user();
    $employee = $wpdb->get_row($wpdb->prepare(
        "SELECT id, name FROM {$wpdb->prefix}pm_employees WHERE user_id = %d",
        absint($user->ID)
    ));

    if (!$employee) {
        $wpdb->insert(
            $wpdb->prefix . 'pm_employees',
            [
                'user_id' => absint($user->ID),
                'name' => sanitize_text_field($user->display_name ?: $user->user_login)
            ],
            ['%d', '%s']
        );
        $employee = $wpdb->get_row($wpdb->prepare(
            "SELECT id, name FROM {$wpdb->prefix}pm_employees WHERE user_id = %d",
            absint($user->ID)
        ));
    }

    $user_roles = (array) $user->roles;
    if (!in_array('pm_employee', $user_roles)) {
        $user->add_role('pm_employee');
    }

    ob_start();
    ?>
    <div class="pm-employee-calendar">
        <h1>Bonjour, <?php echo esc_html($employee->name); ?></h1>
        <h2>Votre calendrier de disponibilités</h2>
        <div id="pm_message_area" class="pm-message" style="display: none;"></div>
        <div id="pm_calendar_wrapper">
            <div id="pm_spinner" class="pm-spinner" style="display: none;"></div>
            <div id="pm_employee_calendar"></div>
        </div>
    </div>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            console.log('Personnel Management: Initializing employee calendar for user_id: <?php echo esc_js(absint($user->ID)); ?>, employee_id: <?php echo esc_js(absint($employee->id)); ?>');
            var calendarEl = document.getElementById('pm_employee_calendar');
            var messageArea = document.getElementById('pm_message_area');
            var spinner = document.getElementById('pm_spinner');
            var selectedRange = null;

            // Gestion de la fenêtre de confirmation
            const urlParams = new URLSearchParams(window.location.search);
            const status = urlParams.get('status');
            const message = urlParams.get('message');
            if (status && message) {
                const modal = document.createElement('div');
                modal.id = 'pm_confirmation_modal';
                modal.className = 'pm-modal';
                modal.innerHTML = `
                    <div class="pm-modal-content">
                        <h3>Confirmation</h3>
                        <p class="${status === 'success' ? 'pm-message-success' : 'pm-message-error'}">${decodeURIComponent(message)}</p>
                        <button id="pm_modal_close">Fermer</button>
                    </div>
                `;
                document.body.appendChild(modal);
                document.getElementById('pm_modal_close').addEventListener('click', function() {
                    modal.remove();
                    window.history.replaceState({}, document.title, window.location.pathname);
                });
            }

            if (!calendarEl) {
                console.error('Personnel Management: Calendar element not found');
                showMessage('Erreur : Impossible de charger le calendrier.', true);
                return;
            }

            var commentDialog = document.createElement('div');
            commentDialog.id = 'pm_comment_dialog';
            commentDialog.innerHTML = `
                <h3>Informations sur la date</h3>
                <p><strong>Statut :</strong> <span id="pm_dialog_status"></span></p>
                <p><strong>Date :</strong> <span id="pm_dialog_date"></span></p>
                <p><strong>Heure de début :</strong> <span id="pm_dialog_start_time"></span></p>
                <p><strong>Heure de fin :</strong> <span id="pm_dialog_end_time"></span></p>
                <p id="pm_dialog_remuneration_wrapper" style="display: none;"><strong>Rémunération :</strong> <span id="pm_dialog_remuneration"></span></p>
                <p><strong>Commentaire :</strong></p>
                <textarea id="pm_dialog_comment" readonly></textarea>
                <button id="pm_dialog_close">Fermer</button>
            `;
            document.body.appendChild(commentDialog);

            var availabilityDialog = document.createElement('div');
            availabilityDialog.id = 'pm_availability_dialog';
            availabilityDialog.innerHTML = `
                <h3>Modifier la disponibilité</h3>
                <p><strong>Date :</strong> <span id="pm_dialog_date"></span></p>
                <div class="pm-button-group">
                    <button id="pm_available_btn" class="pm-availability-btn">Disponible</button>
                    <button id="pm_unavailable_btn" class="pm-availability-btn">Non disponible</button>
                    <button id="pm_delete_btn" class="pm-availability-btn pm-delete-btn">Supprimer</button>
                </div>
                <button id="pm_availability_close">Annuler</button>
            `;
            document.body.appendChild(availabilityDialog);

            var calendar = new FullCalendar.Calendar(calendarEl, {
                initialView: 'dayGridMonth',
                locale: 'fr',
                selectable: true,
                selectMirror: true,
                selectOverlap: true,
                unselectAuto: false,
                selectLongPressDelay: 100,
                eventLongPressDelay: 100,
                events: function(fetchInfo, successCallback, failureCallback) {
                    console.log('Personnel Management: Fetching events for employee_id: <?php echo esc_js(absint($employee->id)); ?>', fetchInfo);
                    jQuery.ajax({
                        url: '<?php echo esc_url(admin_url('admin-ajax.php')); ?>',
                        type: 'POST',
                        data: {
                            action: 'pm_get_employee_availability',
                            employee_id: <?php echo esc_js(absint($employee->id)); ?>,
                            nonce: '<?php echo esc_js(wp_create_nonce('pm_get_employee_nonce')); ?>'
                        },
                        success: function(response) {
                            console.log('Personnel Management: Raw event data received:', response);
                            if (response.success && response.data) {
                                console.log('Personnel Management: Processing events:', response.data);
                                successCallback(response.data);
                                if (response.data.length === 0) {
                                    showMessage('Aucune disponibilité enregistrée pour le moment.', false);
                                }
                            } else {
                                console.error('Personnel Management: No valid event data:', response.data || 'Erreur inconnue');
                                showMessage('Erreur : Impossible de charger les disponibilités.', true);
                                failureCallback([]);
                            }
                        },
                        error: function(jqXHR, textStatus, errorThrown) {
                            console.error('Personnel Management AJAX Error:', textStatus, errorThrown);
                            showMessage('Erreur de connexion au serveur.', true);
                            failureCallback([]);
                        }
                    });
                },
                eventDidMount: function(info) {
                    console.log('Personnel Management: Rendering event:', info.event.toJSON());
                    var title = 'Statut : ' + info.event.title;
                    if (info.event.extendedProps.start_time) {
                        title += ' (' + info.event.extendedProps.start_time + ' - ' + info.event.extendedProps.end_time + ')';
                    }
                    if (info.event.extendedProps.comment) {
                        title += ' - Commentaire : ' + info.event.extendedProps.comment;
                    } else if (info.event.extendedProps.status === 'réservé') {
                        title += ' - Réservé par l’admin';
                    }
                    info.el.setAttribute('title', title);
                },
                eventClick: function(info) {
                    console.log('Personnel Management: Event clicked:', info.event.toJSON());
                    if (info.event.extendedProps.status === 'réservé' || (info.event.extendedProps.comment && info.event.extendedProps.comment.trim() !== '' && info.event.extendedProps.status !== 'disponible' && info.event.extendedProps.status !== 'non-disponible')) {
                        document.getElementById('pm_dialog_status').textContent = info.event.title;
                        document.getElementById('pm_dialog_date').textContent = info.event.startStr.split('T')[0];
                        document.getElementById('pm_dialog_start_time').textContent = info.event.extendedProps.start_time || 'Non défini';
                        document.getElementById('pm_dialog_end_time').textContent = info.event.extendedProps.end_time || 'Non défini';
                        document.getElementById('pm_dialog_comment').value = info.event.extendedProps.comment || 'Aucun commentaire';
                        
                        var remunerationWrapper = document.getElementById('pm_dialog_remuneration_wrapper');
                        var remunerationSpan = document.getElementById('pm_dialog_remuneration');
                        if (info.event.extendedProps.remuneration) {
                            remunerationSpan.textContent = info.event.extendedProps.remuneration;
                            remunerationWrapper.style.display = 'block';
                        } else {
                            remunerationWrapper.style.display = 'none';
                        }
                        
                        commentDialog.style.display = 'block';
                        document.getElementById('pm_dialog_close').onclick = function() {
                            commentDialog.style.display = 'none';
                        };
                        calendar.unselect();
                        return;
                    }
                    var dateStr = info.event.startStr.split('T')[0];
                    selectedRange = {
                        start: dateStr,
                        end: dateStr
                    };
                    document.getElementById('pm_dialog_date').textContent = dateStr;
                    // Masquer les boutons selon le statut actuel
                    document.getElementById('pm_available_btn').style.display = info.event.extendedProps.status === 'disponible' ? 'none' : 'inline-block';
                    document.getElementById('pm_unavailable_btn').style.display = info.event.extendedProps.status === 'non-disponible' ? 'none' : 'inline-block';
                    document.getElementById('pm_delete_btn').style.display = 'inline-block';
                    availabilityDialog.style.display = 'block';
                    messageArea.style.display = 'none';
                    calendar.select(dateStr);
                },
                select: function(info) {
                    console.log('Personnel Management: Range selected:', info);
                    var start = new Date(info.startStr.split('T')[0]);
                    var endDate = new Date(info.endStr.split('T')[0]);
                    if (start.toDateString() === endDate.toDateString()) {
                        endDate = start;
                    } else {
                        endDate.setDate(endDate.getDate() - 1);
                    }
                    var startStr = info.startStr.split('T')[0];
                    var endStr = endDate.toISOString().split('T')[0];
                    var events = calendar.getEvents();
                    var hasLockedEvent = false;
                    for (var d = new Date(start); d <= endDate; d.setDate(d.getDate() + 1)) {
                        var dateStr = d.toISOString().split('T')[0];
                        var event = events.find(e => e.startStr.split('T')[0] === dateStr && 
                            (e.extendedProps.status === 'réservé' || (e.extendedProps.comment && e.extendedProps.comment.trim() !== '' && e.extendedProps.status !== 'disponible' && e.extendedProps.status !== 'non-disponible')));
                        if (event) {
                            hasLockedEvent = true;
                            break;
                        }
                    }
                    if (hasLockedEvent) {
                        showMessage('Ces dates sont verrouillées par l’admin.', true);
                        calendar.unselect();
                        return;
                    }
                    selectedRange = {
                        start: startStr,
                        end: endStr
                    };
                    document.getElementById('pm_dialog_date').textContent = selectedRange.start + (selectedRange.start !== selectedRange.end ? ' à ' + selectedRange.end : '');
                    // Afficher tous les boutons pour une nouvelle sélection
                    document.getElementById('pm_available_btn').style.display = 'inline-block';
                    document.getElementById('pm_unavailable_btn').style.display = 'inline-block';
                    document.getElementById('pm_delete_btn').style.display = 'none'; // Pas de suppression pour une nouvelle date
                    availabilityDialog.style.display = 'block';
                    messageArea.style.display = 'none';
                },
                unselect: function() {
                    console.log('Personnel Management: Selection cleared');
                    selectedRange = null;
                    availabilityDialog.style.display = 'none';
                    messageArea.style.display = 'none';
                }
            });

            availabilityDialog.addEventListener('click', function(e) {
                e.stopPropagation();
            });

            function showMessage(message, isError = false) {
                console.log('Personnel Management: Showing message:', message, 'isError:', isError);
                messageArea.style.display = 'block';
                messageArea.className = 'pm-message ' + (isError ? 'pm-message-error' : 'pm-message-info');
                messageArea.textContent = message;
                setTimeout(() => {
                    messageArea.style.display = 'none';
                }, 3000);
            }

            function toggleSpinnerAndButtons(show) {
                spinner.style.display = show ? 'flex' : 'none';
                var buttons = availabilityDialog.querySelectorAll('.pm-availability-btn');
                buttons.forEach(btn => btn.disabled = show);
            }

            function sendAvailabilityRequest(action, status = null) {
                if (!selectedRange) {
                    showMessage('Veuillez sélectionner une plage de dates.', true);
                    return;
                }
                toggleSpinnerAndButtons(true);
                var data = {
                    action: action,
                    employee_id: <?php echo esc_js(absint($employee->id)); ?>,
                    start_date: selectedRange.start,
                    end_date: selectedRange.end,
                    start_time: null,
                    end_time: null,
                    nonce: '<?php echo esc_js(wp_create_nonce('pm_availability')); ?>'
                };
                if (status) {
                    data.status = status;
                }
                console.log('Personnel Management: Sending availability request:', data);
                jQuery.post('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', data, function(response) {
                    console.log('Personnel Management: Availability request response:', response);
                    toggleSpinnerAndButtons(false);
                    if (response.success) {
                        calendar.refetchEvents();
                        availabilityDialog.style.display = 'none';
                        selectedRange = null;
                        calendar.unselect();
                        showMessage('Action effectuée avec succès.');
                    } else {
                        showMessage('Erreur : ' + (response.data || 'Erreur inconnue.'), true);
                    }
                }).fail(function(jqXHR, textStatus, errorThrown) {
                    console.error('Personnel Management AJAX Error:', textStatus, errorThrown);
                    toggleSpinnerAndButtons(false);
                    showMessage('Erreur de connexion au serveur.', true);
                });
            }

            availabilityDialog.querySelector('#pm_available_btn').addEventListener('click', function() {
                sendAvailabilityRequest('pm_update_availability', 'disponible');
            });

            availabilityDialog.querySelector('#pm_unavailable_btn').addEventListener('click', function() {
                sendAvailabilityRequest('pm_update_availability', 'non-disponible');
            });

            availabilityDialog.querySelector('#pm_delete_btn').addEventListener('click', function() {
                if (confirm('Confirmer la suppression des disponibilités pour la plage sélectionnée ?')) {
                    sendAvailabilityRequest('pm_delete_availability');
                }
            });

            availabilityDialog.querySelector('#pm_availability_close').addEventListener('click', function() {
                availabilityDialog.style.display = 'none';
                calendar.unselect();
            });

            try {
                console.log('Personnel Management: Rendering calendar');
                calendar.render();
            } catch (error) {
                console.error('Personnel Management: Calendar Render Error:', error);
                showMessage('Erreur lors de l\'initialisation du calendrier.', true);
            }
        });
    </script>
    <?php
    return ob_get_clean();
}
add_shortcode('pm_employee_login', 'pm_employee_login_shortcode');

// Shortcode pour le calendrier admin en front-office
function pm_admin_calendar_shortcode() {
    if (!is_user_logged_in()) {
        ob_start();
        ?>
        <div class="pm-login-form">
            <h2>Connexion</h2>
            <form method="post" action="<?php echo esc_url(wp_login_url(get_permalink())); ?>" class="pm-login-form-inner">
                <label for="log">Identifiant ou adresse email</label>
                <input type="text" name="log" id="log" required>
                <label for="pwd">Mot de passe</label>
                <input type="password" name="pwd" id="pwd" required>
                <input type="submit" value="Se connecter">
                <p><a href="<?php echo esc_url(wp_lostpassword_url()); ?>">Mot de passe oublié ?</a></p>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    if (!current_user_can('manage_options') && !current_user_can('pm_manage_availability')) {
        return '<p class="pm-message pm-message-error">Accès réservé aux administrateurs ou employés autorisés.</p>';
    }

    // On ajoute le script au footer une seule fois
    static $script_added = false;
    if (!$script_added) {
        add_action('wp_footer', 'pm_admin_calendar_js');
        $script_added = true;
    }

    global $wpdb;
    $employees = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}pm_employees");
    $characteristics = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}pm_characteristics");

    ob_start();
    ?>
    <div class="pm-admin-calendar">
        <div id="pm_message_area" class="pm-message" style="display: none;"></div>
        <div class="pm-filters">
            <div>
                <label for="pm_employee_filter">Filtrer par employé :</label>
                <select id="pm_employee_filter">
                    <option value="all">Tous les employés</option>
                    <?php foreach ($employees as $employee) : ?>
                        <option value="<?php echo esc_attr(absint($employee->id)); ?>">
                            <?php echo esc_html($employee->name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="pm_characteristic_filter">Filtrer par caractéristique :</label>
                <select id="pm_characteristic_filter">
                    <option value="">Toutes les caractéristiques</option>
                    <?php foreach ($characteristics as $char) : ?>
                        <option value="<?php echo esc_attr($char->id); ?>">
                            <?php echo esc_html($char->name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="pm_date_filter">Mois/Année :</label>
                <input type="month" id="pm_date_filter" value="<?php echo esc_attr(date('Y-m')); ?>">
            </div>
        </div>
        <div id="pm_calendar_wrapper">
            <div id="pm_spinner" class="pm-spinner" style="display: none;"></div>
            <div id="pm_admin_calendar"></div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('pm_admin_calendar', 'pm_admin_calendar_shortcode');
add_shortcode('events', 'pm_admin_calendar_shortcode');

function pm_admin_calendar_js() {
    ?>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // S'assurer que le script ne s'exécute que si les éléments existent
            if (typeof FullCalendar === 'undefined' || !document.getElementById('pm_admin_calendar')) {
                return;
            }

            var calendarEl = document.getElementById('pm_admin_calendar');
            var employeeFilter = document.getElementById('pm_employee_filter');
            var characteristicFilter = document.getElementById('pm_characteristic_filter');
            var dateFilter = document.getElementById('pm_date_filter');
            var messageArea = document.getElementById('pm_message_area');
            var spinner = document.getElementById('pm_spinner');

            if (!calendarEl) {
                return;
            }

            function showMessage(message, isError = false) {
                if (!messageArea) return;
                messageArea.style.display = 'block';
                messageArea.className = 'pm-message ' + (isError ? 'pm-message-error' : 'pm-message-success');
                messageArea.textContent = message;
                setTimeout(() => {
                    messageArea.style.display = 'none';
                }, 3000);
            }

            function toggleSpinner(show) {
                if (!spinner) return;
                spinner.style.display = show ? 'flex' : 'none';
            }

            function generateTimeOptions() {
                let options = '<option value="">Sélectionner une heure</option>';
                for (let h = 0; h < 24; h++) {
                    for (let m = 0; m < 60; m += 30) {
                        const time = `${h.toString().padStart(2, '0')}:${m.toString().padStart(2, '0')}`;
                        options += `<option value="${time}">${time}</option>`;
                    }
                }
                return options;
            }

            // Création des boîtes de dialogue
            if (!document.getElementById('pm_reserved_dialog')) {
                var reservedDialog = document.createElement('div');
                reservedDialog.id = 'pm_reserved_dialog';
                reservedDialog.innerHTML = `
                    <h3>Modifier la réservation</h3>
                    <p><strong>Employé :</strong> <span id="pm_reserved_employee"></span></p>
                    <p><strong>Date :</strong> <span id="pm_reserved_date"></span></p>
                    <label for="pm_reserved_status">Statut :</label>
                    <select id="pm_reserved_status">
                        <option value="réservé">Réservé</option>
                        <option value="disponible">Disponible</option>
                        <option value="non-disponible">Non disponible</option>
                        <option value="en attente">En attente</option>
                    </select>
                    <div id="pm_reserved_time_fields" style="display: none;">
                        <label for="pm_reserved_start_time">Heure de début :</label>
                        <select id="pm_reserved_start_time">${generateTimeOptions()}</select>
                        <label for="pm_reserved_end_time">Heure de fin :</label>
                        <select id="pm_reserved_end_time">${generateTimeOptions()}</select>
                    </div>
                    <label for="pm_reserved_remuneration">Rémunération :</label>
                    <input type="number" id="pm_reserved_remuneration" placeholder="Ex: 150" style="width: 100%; padding: 8px; margin-bottom: 10px; border: 1px solid #ccc; border-radius: 4px;">
                    <label for="pm_reserved_comment">Commentaire :</label>
                    <textarea id="pm_reserved_comment" maxlength="1000"></textarea>
                    <div class="pm-button-group">
                        <button id="pm_reserved_save">Enregistrer</button>
                        <button id="pm_reserved_cancel">Annuler</button>
                    </div>
                `;
                document.body.appendChild(reservedDialog);
            }

            if (!document.getElementById('pm_status_dialog')) {
                var dialog = document.createElement('div');
                dialog.id = 'pm_status_dialog';
                dialog.innerHTML = `
                    <h3>Modifier le statut</h3>
                    <p><strong>Date de début :</strong> <input type="date" id="pm_dialog_start_date" readonly style="border:none; background:#f0f0f0;"></p>
                    <p><strong>Date de fin :</strong> <input type="date" id="pm_dialog_end_date"></p>
                    <hr>
                    <select id="pm_status_input">
                        <option value="disponible">Disponible</option>
                        <option value="non-disponible">Non disponible</option>
                        <option value="en attente">Demande de prestation</option>
                    </select>
                    <div id="pm_time_fields" style="display: none;">
                        <p><strong>Heure de début :</strong> <select id="pm_start_time" required>${generateTimeOptions()}</select></p>
                        <p><strong>Heure de fin :</strong> <select id="pm_end_time" required>${generateTimeOptions()}</select></p>
                        <p><strong>Rémunération :</strong></p>
                        <input type="number" id="pm_remuneration" placeholder="Ex: 150" style="width: 100%; padding: 8px; margin-bottom: 10px; border: 1px solid #ccc; border-radius: 4px;">
                        <p><strong>Détails :</strong></p>
                        <textarea id="pm_details" placeholder="Détails (optionnel)" maxlength="1000"></textarea>
                    </div>
                    <div id="pm_status_error" class="pm-message pm-message-error" style="display: none;"></div>
                    <button id="pm_status_save">Enregistrer</button>
                    <button id="pm_status_cancel">Annuler</button>
                `;
                document.body.appendChild(dialog);
            }
            
            var reservedDialog = document.getElementById('pm_reserved_dialog');
            var dialog = document.getElementById('pm_status_dialog');

            document.addEventListener('click', function(event) {
                if (reservedDialog && reservedDialog.style.display === 'block' && !reservedDialog.contains(event.target)) {
                    reservedDialog.style.display = 'none';
                }
                if (dialog && dialog.style.display === 'block' && !dialog.contains(event.target)) {
                    dialog.style.display = 'none';
                }
            });

            var reservedStatusInput = document.getElementById('pm_reserved_status');
            if (reservedStatusInput) {
                reservedStatusInput.addEventListener('change', function() {
                    var timeFields = document.getElementById('pm_reserved_time_fields');
                    var startTime = document.getElementById('pm_reserved_start_time');
                    var endTime = document.getElementById('pm_reserved_end_time');
                    if (this.value === 'en attente') {
                        timeFields.style.display = 'block';
                        startTime.required = true;
                        endTime.required = true;
                    } else {
                        timeFields.style.display = 'none';
                        startTime.required = false;
                        endTime.required = false;
                        startTime.value = '';
                        endTime.value = '';
                    }
                });
            }

            var statusInput = document.getElementById('pm_status_input');
            if (statusInput) {
                statusInput.addEventListener('change', function() {
                    var timeFields = document.getElementById('pm_time_fields');
                    var startTime = document.getElementById('pm_start_time');
                    var endTime = document.getElementById('pm_end_time');
                    if (this.value === 'en attente') {
                        timeFields.style.display = 'block';
                        startTime.required = true;
                        endTime.required = true;
                    } else {
                        timeFields.style.display = 'none';
                        startTime.required = false;
                        endTime.required = false;
                        startTime.value = '';
                        endTime.value = '';
                        document.getElementById('pm_details').value = '';
                        document.getElementById('pm_remuneration').value = '';
                    }
                });
            }

            var calendar = new FullCalendar.Calendar(calendarEl, {
                initialView: 'dayGridMonth',
                locale: 'fr',
                selectable: true,
                selectMirror: true,
                selectOverlap: true,
                selectLongPressDelay: 100,
                eventLongPressDelay: 100,
                headerToolbar: {
                    left: 'prev,next today',
                    center: 'title',
                    right: 'dayGridMonth,timeGridWeek'
                },
                views: {
                    dayGridMonth: { titleFormat: { year: 'numeric', month: 'long' } },
                    timeGridWeek: { titleFormat: { year: 'numeric', month: 'short', day: 'numeric' } },
                    timeGridDay: { titleFormat: { year: 'numeric', month: 'short', day: 'numeric' } }
                },
                eventContent: function(arg) {
                    var icon = arg.event.extendedProps.status_icon || '';
                    var title = arg.event.title;
                    if (arg.event.extendedProps.start_time) {
                        title += ` (${arg.event.extendedProps.start_time} - ${arg.event.extendedProps.end_time})`;
                    }
                    return {
                        html: `<span>${icon} ${title}</span>`
                    };
                },
                eventClick: function(info) {
                    info.jsEvent.stopPropagation();
                    var hasPermission = <?php echo current_user_can('manage_options') ? 'true' : 'false'; ?>;
                    if (!hasPermission) {
                        showMessage('Erreur : accès non autorisé.', true);
                        return;
                    }
                    var event = info.event;
                    if (['réservé', 'réservé - accepté', 'réservé - en attente', 'en attente'].includes(event.extendedProps.status)) {
                        var employeeEl = document.getElementById('pm_reserved_employee');
                        var dateEl = document.getElementById('pm_reserved_date');
                        var statusEl = document.getElementById('pm_reserved_status');
                        var startTimeEl = document.getElementById('pm_reserved_start_time');
                        var endTimeEl = document.getElementById('pm_reserved_end_time');
                        var remunerationEl = document.getElementById('pm_reserved_remuneration');
                        var commentEl = document.getElementById('pm_reserved_comment');
                        var timeFields = document.getElementById('pm_reserved_time_fields');
                        var saveBtn = document.getElementById('pm_reserved_save');
                        var cancelBtn = document.getElementById('pm_reserved_cancel');

                        employeeEl.textContent = event.title || 'Non défini';
                        dateEl.textContent = event.startStr.split('T')[0] || 'Non défini';
                        statusEl.value = event.extendedProps.status;
                        var normalizedStartTime = event.extendedProps.start_time ? event.extendedProps.start_time.replace(/:00$/, '') : '';
                        var normalizedEndTime = event.extendedProps.end_time ? event.extendedProps.end_time.replace(/:00$/, '') : '';
                        startTimeEl.value = normalizedStartTime && normalizedStartTime.match(/^\d{2}:\d{2}$/) ? normalizedStartTime : '';
                        endTimeEl.value = normalizedEndTime && normalizedEndTime.match(/^\d{2}:\d{2}$/) ? normalizedEndTime : '';
                        remunerationEl.value = event.extendedProps.remuneration || '';
                        commentEl.value = event.extendedProps.comment || '';
                        timeFields.style.display = (event.extendedProps.status === 'en attente') ? 'block' : 'none';
                        startTimeEl.required = timeFields.style.display === 'block';
                        endTimeEl.required = timeFields.style.display === 'block';
                        reservedDialog.style.display = 'block';

                        saveBtn.onclick = function() {
                            var selectedStatus = statusEl.value;
                            var startTime = startTimeEl.value;
                            var endTime = endTimeEl.value;
                            var newRemuneration = remunerationEl.value;
                            var newComment = commentEl.value;

                            if (selectedStatus === 'en attente' && (!startTime || !endTime)) {
                                showMessage('Veuillez sélectionner une heure de début et de fin.', true);
                                return;
                            }
                            if (startTime && endTime && startTime >= endTime) {
                                showMessage('L’heure de début doit être antérieure à l’heure de fin.', true);
                                return;
                            }
                            toggleSpinner(true);
                            jQuery.post(pmAjax.ajaxurl, {
                                action: 'pm_update_availability_status',
                                employee_id: event.extendedProps.employeeId,
                                start_date: event.startStr.split('T')[0],
                                end_date: event.startStr.split('T')[0],
                                status: selectedStatus,
                                start_time: startTime,
                                end_time: endTime,
                                remuneration: newRemuneration,
                                comment: newComment,
                                nonce: pmAjax.nonce
                            }, function(response) {
                                toggleSpinner(false);
                                if (response.success) {
                                    calendar.refetchEvents();
                                    reservedDialog.style.display = 'none';
                                } else {
                                    showMessage('Erreur : ' + (response.data || 'Erreur inconnue'), true);
                                }
                            }).fail(function() {
                                toggleSpinner(false);
                                showMessage('Erreur de connexion au serveur.', true);
                            });
                        };
                        cancelBtn.onclick = function() { reservedDialog.style.display = 'none'; };
                    }
                },
                select: function(info) {
                    var hasPermission = <?php echo current_user_can('manage_options') ? 'true' : 'false'; ?>;
                    if (!hasPermission) {
                        showMessage('Erreur : accès non autorisé.', true);
                        calendar.unselect();
                        return;
                    }
                    var employeeId = employeeFilter.value !== 'all' ? employeeFilter.value : null;
                    if (!employeeId) {
                        showMessage('Veuillez sélectionner un employé.', true);
                        calendar.unselect();
                        return;
                    }
                    var endDate = new Date(info.endStr);
                    endDate.setDate(endDate.getDate() - 1);
                    var adjustedEndDate = endDate.toISOString().split('T')[0];

                    document.getElementById('pm_dialog_start_date').value = info.startStr.split('T')[0];
                    document.getElementById('pm_dialog_end_date').value = adjustedEndDate;
                    
                    var statusInput = document.getElementById('pm_status_input');
                    statusInput.value = 'disponible';
                    statusInput.dispatchEvent(new Event('change'));
                    dialog.style.display = 'block';

                    document.getElementById('pm_status_save').onclick = function() {
                        var finalStartDate = document.getElementById('pm_dialog_start_date').value;
                        var finalEndDate = document.getElementById('pm_dialog_end_date').value;
                        
                        if (new Date(finalEndDate) < new Date(finalStartDate)) {
                            finalEndDate = finalStartDate;
                        }

                        var selectedStatus = document.getElementById('pm_status_input').value;
                        var startTime = document.getElementById('pm_start_time').value;
                        var endTime = document.getElementById('pm_end_time').value;
                        var newRemuneration = document.getElementById('pm_remuneration').value;
                        var newDetails = document.getElementById('pm_details').value;
                        
                        if (selectedStatus === 'en attente' && (!startTime || !endTime)) {
                            showMessage('Veuillez sélectionner une heure de début et de fin.', true);
                            return;
                        }
                        if (startTime && endTime && startTime >= endTime) {
                            showMessage('L’heure de début doit être antérieure à l’heure de fin.', true);
                            return;
                        }
                        
                        toggleSpinner(true);
                        jQuery.post(pmAjax.ajaxurl, {
                            action: 'pm_update_availability_status',
                            employee_id: employeeId,
                            start_date: finalStartDate,
                            end_date: finalEndDate,
                            status: selectedStatus,
                            start_time: startTime,
                            end_time: endTime,
                            remuneration: newRemuneration,
                            comment: newDetails,
                            nonce: pmAjax.nonce
                        }, function(response) {
                            toggleSpinner(false);
                            if (response.success) {
                                calendar.refetchEvents();
                                dialog.style.display = 'none';
                                calendar.unselect();
                            } else {
                                showMessage('Erreur : ' + (response.data || 'Erreur inconnue'), true);
                            }
                        }).fail(function() {
                            toggleSpinner(false);
                            showMessage('Erreur de connexion au serveur.', true);
                        });
                    };

                    document.getElementById('pm_status_cancel').onclick = function() {
                        dialog.style.display = 'none';
                        calendar.unselect();
                    };
                },
                events: function(fetchInfo, successCallback, failureCallback) {
                    var employeeId = employeeFilter.value;
                    var characteristicId = characteristicFilter.value;
                    var startDate = fetchInfo.startStr.split('T')[0];
                    var endDate = fetchInfo.endStr.split('T')[0];
                    toggleSpinner(true);
                    jQuery.get(pmAjax.ajaxurl, {
                        action: 'pm_get_admin_availability',
                        employee_id: employeeId,
                        characteristic_id: characteristicId,
                        start_date: startDate,
                        end_date: endDate
                    }, function(response) {
                        toggleSpinner(false);
                        if (response.success) {
                            successCallback(response.data);
                            var start = new Date(fetchInfo.startStr);
                            var end = new Date(fetchInfo.endStr);
                            var midDate = new Date((start.getTime() + end.getTime()) / 2);
                            var yearMonth = midDate.getFullYear() + '-' + String(midDate.getMonth() + 1).padStart(2, '0');
                            dateFilter.value = yearMonth;
                        } else {
                            showMessage('Erreur lors du chargement des disponibilités : ' + (response.data || 'Erreur inconnue'), true);
                            failureCallback(new Error(response.data));
                        }
                    }).fail(function() {
                        toggleSpinner(false);
                        showMessage('Erreur de connexion au serveur.', true);
                        failureCallback(new Error('Erreur de connexion'));
                    });
                }
            });

            var endTimeInput = document.getElementById('pm_end_time');
            if (endTimeInput) {
                endTimeInput.addEventListener('change', function() {
                    var startTime = document.getElementById('pm_start_time').value;
                    var endTime = this.value;
                    if (startTime && endTime && startTime >= endTime) {
                        showMessage('L’heure de fin doit être postérieure à l’heure de début.', true);
                        this.value = '';
                    }
                });
            }

            var reservedEndTimeInput = document.getElementById('pm_reserved_end_time');
            if (reservedEndTimeInput) {
                reservedEndTimeInput.addEventListener('change', function() {
                    var startTime = document.getElementById('pm_reserved_start_time').value;
                    var endTime = this.value;
                    if (startTime && endTime && startTime >= endTime) {
                        showMessage('L’heure de fin doit être postérieure à l’heure de début.', true);
                        this.value = '';
                    }
                });
            }

            try {
                calendar.render();
            } catch (error) {
                showMessage('Erreur lors de l’initialisation du calendrier.', true);
            }

            if (employeeFilter) {
                employeeFilter.addEventListener('change', function() { calendar.refetchEvents(); });
            }
            if (characteristicFilter) {
                characteristicFilter.addEventListener('change', function() { calendar.refetchEvents(); });
            }
            if (dateFilter) {
                dateFilter.addEventListener('change', function() { if (dateFilter.value) { calendar.gotoDate(dateFilter.value + '-01'); } });
            }
        });
    </script>
    <?php
}

// Fonction AJAX pour envoyer la notification par e-mail au guide
add_action('wp_ajax_pm_notify_guide', 'pm_notify_guide_callback');
function pm_notify_guide_callback() {
    check_ajax_referer('pm_notify', 'nonce');

    global $wpdb;
    $employee_id = isset($_POST['employee_id']) ? intval($_POST['employee_id']) : 0;
    $date = isset($_POST['date']) ? sanitize_text_field($_POST['date']) : '';
    $status = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : '';
    $start_time = isset($_POST['start_time']) ? sanitize_text_field($_POST['start_time']) : '';
    $end_time = isset($_POST['end_time']) ? sanitize_text_field($_POST['end_time']) : '';
    $comment = isset($_POST['comment']) ? sanitize_textarea_field($_POST['comment']) : '';

    if (!$employee_id || !$date || !$status) {
        wp_send_json_error('Paramètres manquants.');
    }

    // Récupérer les informations de l'employé
    $employee = $wpdb->get_row($wpdb->prepare(
        "SELECT name, email FROM {$wpdb->prefix}pm_employees WHERE id = %d",
        $employee_id
    ));

    if (!$employee || !is_email($employee->email)) {
        wp_send_json_error('Employé non trouvé ou e-mail invalide.');
    }

    // Formater la date
    $formatted_date = date_i18n('d F Y', strtotime($date));

    // Préparer le contenu de l'e-mail
    $subject = 'Mise à jour de votre réservation';
    $message = "Bonjour {$employee->name},\n\n";
    $message .= "Une modification a été apportée à votre réservation pour le {$formatted_date}.\n\n";
    $message .= "Détails de la mise à jour :\n";
    $message .= "- Statut : " . ucfirst($status) . "\n";
    if ($start_time && $end_time) {
        $message .= "- Horaires : de {$start_time} à {$end_time}\n";
    }
    if ($comment) {
        $message .= "- Commentaire : {$comment}\n";
    }
    $message .= "\nVeuillez vérifier votre calendrier pour plus de détails.\n\n";
    $message .= "Cordialement,\nL'équipe administrative";

    // Envoyer l'e-mail
    $headers = ['Content-Type: text/plain; charset=UTF-8'];
    $sent = wp_mail($employee->email, $subject, $message, $headers);

    if ($sent) {
        wp_send_json_success('Notification envoyée.');
    } else {
        wp_send_json_error('Échec de l\'envoi de l\'e-mail.');
    }
}

// AJAX pour récupérer les disponibilités des employés
function pm_get_employee_availability() {
    if (!is_user_logged_in()) {
        wp_send_json_error('Accès non autorisé.');
    }

    global $wpdb;
    $user = wp_get_current_user();
    $employee = $wpdb->get_row($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}pm_employees WHERE user_id = %d",
        absint($user->ID)
    ));

    if (!$employee) {
        error_log('Personnel Management: Employee not found for user_id: ' . $user->ID);
        wp_send_json_error('Employé non trouvé.');
    }

    error_log('Personnel Management: Fetching availabilities for employee_id: ' . $employee->id);

    $query = $wpdb->prepare(
        "SELECT start_date, end_date, status, comment, start_time, end_time, remuneration FROM {$wpdb->prefix}pm_availabilities WHERE employee_id = %d",
        absint($employee->id)
    );
    error_log('Personnel Management: Executing query: ' . $query);
    $availabilities = $wpdb->get_results($query);

    if ($wpdb->last_error) {
        error_log('Personnel Management SQL Error: ' . $wpdb->last_error);
        wp_send_json_error('Erreur SQL : ' . $wpdb->last_error);
    }

    error_log('Personnel Management: Fetched ' . count($availabilities) . ' availabilities for employee_id: ' . $employee->id);
    foreach ($availabilities as $availability) {
        error_log('Personnel Management: Availability - Start Date: ' . $availability->start_date . ', End Date: ' . ($availability->end_date ?: 'None') . ', Status: ' . $availability->status . ', Start Time: ' . ($availability->start_time ?: 'None') . ', End Time: ' . ($availability->end_time ?: 'None') . ', Comment: ' . ($availability->comment ?: 'None'));
    }

    $events = [];
    foreach ($availabilities as $availability) {
        $status_config = [
            'disponible' => ['color' => '#28a745', 'title' => 'Disponible', 'icon' => '✅'],
            'non-disponible' => ['color' => '#dc3545', 'title' => 'Non disponible', 'icon' => '❌'],
            'en attente' => ['color' => '#ffc107', 'title' => 'Réservé - En attente', 'icon' => '⏳'],
            'réservé' => ['color' => '#007bff', 'title' => 'Réservé - accepté', 'icon' => '✔️'],
            'refusé' => ['color' => '#6c757d', 'title' => 'Refusé', 'icon' => '🚫']
        ];
        $config = $status_config[$availability->status] ?? [
            'color' => '#007bff',
            'title' => $availability->status,
            'icon' => ''
        ];
        
        $end_date_for_calendar = $availability->end_date;
        if ($end_date_for_calendar) {
            $end_date_for_calendar = (new DateTime($end_date_for_calendar))->modify('+1 day')->format('Y-m-d');
        }

        $events[] = [
            'title' => $config['title'],
            'start' => $availability->start_date,
            'end'   => $end_date_for_calendar,
            'backgroundColor' => $config['color'],
            'borderColor' => $config['color'],
            'textColor' => '#ffffff',
            'extendedProps' => [
                'comment' => $availability->comment ?: '',
                'remuneration' => $availability->remuneration ?: '',
                'start_time' => $availability->start_time ?: '',
                'end_time' => $availability->end_time ?: '',
                'status' => $availability->status,
                'status_icon' => $config['icon']
            ]
        ];
    }

    error_log('Personnel Management: Sending ' . count($events) . ' events to client for employee_id: ' . $employee->id);
    wp_send_json_success($events);
}
add_action('wp_ajax_pm_get_employee_availability', 'pm_get_employee_availability');

// AJAX pour mettre à jour les disponibilités
function pm_update_availability() {
    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'pm_availability')) {
        wp_send_json_error('Nonce invalide.');
    }

    if (!is_user_logged_in()) {
        wp_send_json_error('Utilisateur non autorisé.');
    }

    if (!current_user_can('pm_manage_availability')) {
        wp_send_json_error('Permission non autorisée.');
    }

    global $wpdb;
    $user = wp_get_current_user();
    $employee = $wpdb->get_row($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}pm_employees WHERE user_id = %d",
        absint($user->ID)
    ));

    if (!$employee) {
        wp_send_json_error('Employé non trouvé.');
    }

    $start_date = sanitize_text_field(wp_unslash($_POST['start_date']));
    $end_date = sanitize_text_field(wp_unslash($_POST['end_date']));
    $status = sanitize_text_field(wp_unslash($_POST['status']));
    $start_time = sanitize_text_field(wp_unslash($_POST['start_time']));
    $end_time = sanitize_text_field(wp_unslash($_POST['end_time']));

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date) || !in_array($status, ['disponible', 'non-disponible'])) {
        wp_send_json_error('Paramètres invalides.');
    }

    if ($start_time && !preg_match('/^([0-1][0-9]|2[0-3]):[0-5][0-9]$/', $start_time)) {
        wp_send_json_error('Heure de début invalide.');
    }
    if ($end_time && !preg_match('/^([0-1][0-9]|2[0-3]):[0-5][0-9]$/', $end_time)) {
        wp_send_json_error('Heure de fin invalide.');
    }
    if ($start_time && $end_time && $start_time >= $end_time) {
        wp_send_json_error('L’heure de début doit être antérieure à l’heure de fin.');
    }

    error_log('Personnel Management: Updating availability for employee_id: ' . $employee->id . ', start_date: ' . $start_date . ', end_date: ' . $end_date . ', status: ' . $status . ', start_time: ' . ($start_time ?: 'None') . ', end_time: ' . ($end_time ?: 'None'));

    $start = new DateTime($start_date);
    $end = new DateTime($end_date);
    if ($start > $end) {
        wp_send_json_error('La date de début doit être antérieure ou égale à la date de fin.');
    }
    $interval = new DateInterval('P1D');
    $period = new DatePeriod($start, $interval, $end->modify('+1 day'));

    $affected_rows = 0;
    $skipped_dates = [];
    foreach ($period as $date) {
        $current_date = $date->format('Y-m-d');
        // MODIFIÉ : On cherche par 'start_date' au lieu de 'date'
        $availability = $wpdb->get_row($wpdb->prepare(
            "SELECT status, comment FROM {$wpdb->prefix}pm_availabilities WHERE employee_id = %d AND start_date = %s",
            absint($employee->id), $current_date
        ));
        if ($availability && $availability->status === 'réservé') {
            $skipped_dates[] = $current_date . ' (Status: ' . $availability->status . ', Comment: ' . ($availability->comment ?: 'None') . ')';
            continue;
        }

        // MODIFIÉ : On cherche par 'start_date' au lieu de 'date'
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}pm_availabilities WHERE employee_id = %d AND start_date = %s",
            absint($employee->id), $current_date
        ));

        if ($existing) {
            $result = $wpdb->update(
                $wpdb->prefix . 'pm_availabilities',
                [
                    'status' => $status,
                    'start_time' => $start_time ?: null,
                    'end_time' => $end_time ?: null,
                    'comment' => '', // On efface le commentaire admin si le guide reprend la main
                    'end_date' => null // C'est un enregistrement d'un seul jour
                ],
                ['employee_id' => absint($employee->id), 'start_date' => $current_date],
                ['%s', '%s', '%s', '%s', '%s'],
                ['%d', '%s']
            );
            $affected_rows += $result ? 1 : 0;
        } else {
            $result = $wpdb->insert(
                $wpdb->prefix . 'pm_availabilities',
                [
                    'employee_id' => absint($employee->id),
                    'start_date' => $current_date, // MODIFIÉ : 'date' devient 'start_date'
                    'start_time' => $start_time ?: null,
                    'end_time' => $end_time ?: null,
                    'status' => $status
                ],
                ['%d', '%s', '%s', '%s', '%s']
            );
            $affected_rows += $result ? 1 : 0;
        }

        if ($wpdb->last_error) {
            error_log('Personnel Management SQL Error for date ' . $current_date . ': ' . $wpdb->last_error);
            wp_send_json_error('Erreur SQL pour date ' . esc_html($current_date) . ' : ' . $wpdb->last_error);
        }
    }

    error_log('Personnel Management: Updated ' . $affected_rows . ' rows for employee_id: ' . $employee->id);
    if (!empty($skipped_dates)) {
        error_log('Personnel Management: Skipped dates: ' . implode(', ', $skipped_dates));
    }

    if ($affected_rows === 0) {
        $error_message = 'Aucune disponibilité mise à jour ou insérée.';
        if (!empty($skipped_dates)) {
            $error_message .= ' Toutes les dates sélectionnées sont verrouillées : ' . implode(', ', $skipped_dates);
        }
        wp_send_json_error($error_message);
    }

    wp_send_json_success(['affected_rows' => $affected_rows]);
}
add_action('wp_ajax_pm_update_availability', 'pm_update_availability');

// AJAX pour supprimer une disponibilité
function pm_delete_availability() {
    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'pm_availability')) {
        wp_send_json_error('Nonce invalide.');
    }

    if (!is_user_logged_in()) {
        wp_send_json_error('Utilisateur non autorisé.');
    }

    if (!current_user_can('pm_manage_availability')) {
        wp_send_json_error('Permission non autorisée.');
    }

    global $wpdb;
    $user = wp_get_current_user();
    $employee = $wpdb->get_row($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}pm_employees WHERE user_id = %d",
        absint($user->ID)
    ));

    if (!$employee) {
        wp_send_json_error('Employé non trouvé.');
    }

    $start_date = sanitize_text_field(wp_unslash($_POST['start_date']));
    $end_date = sanitize_text_field(wp_unslash($_POST['end_date']));

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
        wp_send_json_error('Dates invalides.');
    }

    $start = new DateTime($start_date);
    $end = new DateTime($end_date);
    if ($start > $end) {
        wp_send_json_error('La date de début doit être antérieure ou égale à la date de fin.');
    }
    $interval = new DateInterval('P1D');
    $period = new DatePeriod($start, $interval, $end->modify('+1 day'));

    $affected_rows = 0;
    $skipped_dates = [];
    foreach ($period as $date) {
        $current_date = $date->format('Y-m-d');
        // MODIFIÉ : On cherche par 'start_date' au lieu de 'date'
        $availability = $wpdb->get_row($wpdb->prepare(
            "SELECT status, comment FROM {$wpdb->prefix}pm_availabilities WHERE employee_id = %d AND start_date = %s",
            absint($employee->id), $current_date
        ));
        if ($availability && ($availability->comment || $availability->status === 'réservé')) {
            $skipped_dates[] = $current_date . ' (Status: ' . $availability->status . ', Comment: ' . ($availability->comment ?: 'None') . ')';
            continue;
        }

        // MODIFIÉ : On cherche par 'start_date' au lieu de 'date'
        $result = $wpdb->delete(
            $wpdb->prefix . 'pm_availabilities',
            ['employee_id' => absint($employee->id), 'start_date' => $current_date],
            ['%d', '%s']
        );
        $affected_rows += $result ? $result : 0;

        if ($wpdb->last_error) {
            wp_send_json_error('Erreur SQL pour date ' . esc_html($current_date) . ' : ' . $wpdb->last_error);
        }
    }

    error_log('Personnel Management: Deleted ' . $affected_rows . ' rows for employee_id: ' . $employee->id);
    if (!empty($skipped_dates)) {
        error_log('Personnel Management: Skipped dates: ' . implode(', ', $skipped_dates));
    }

    wp_send_json_success(['deleted_rows' => $affected_rows]);
}
add_action('wp_ajax_pm_delete_availability', 'pm_delete_availability');

// AJAX pour le calendrier admin
function pm_get_admin_availability() {
    if (!is_user_logged_in() || (!current_user_can('manage_options') && !current_user_can('pm_manage_availability'))) {
        wp_send_json_error('Accès non autorisé.');
    }

    global $wpdb;
    $employee_id = isset($_GET['employee_id']) && $_GET['employee_id'] !== 'all' ? absint($_GET['employee_id']) : null;
    $characteristic_id = isset($_GET['characteristic_id']) && $_GET['characteristic_id'] ? absint($_GET['characteristic_id']) : null;
    $start_date_view = isset($_GET['start_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', sanitize_text_field(wp_unslash($_GET['start_date']))) ? sanitize_text_field(wp_unslash($_GET['start_date'])) : null;
    $end_date_view = isset($_GET['end_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', sanitize_text_field(wp_unslash($_GET['end_date']))) ? sanitize_text_field(wp_unslash($_GET['end_date'])) : null;

    if (!$start_date_view || !$end_date_view) {
        $date = isset($_GET['date']) && preg_match('/^\d{4}-\d{2}$/', sanitize_text_field(wp_unslash($_GET['date']))) ? sanitize_text_field(wp_unslash($_GET['date'])) : date('Y-m');
        $start_date_view = date('Y-m-01', strtotime($date));
        $end_date_view = date('Y-m-t', strtotime($date));
    }

    $query = "SELECT a.start_date, a.end_date, a.status, a.start_time, a.end_time, a.comment, a.remuneration, e.name, e.id AS employee_id
              FROM {$wpdb->prefix}pm_employees e
              LEFT JOIN {$wpdb->prefix}pm_availabilities a ON a.employee_id = e.id";

    $where_clauses = [];
    $params = [];

    $where_clauses[] = "a.start_date IS NOT NULL AND (
        (a.end_date IS NULL AND a.start_date BETWEEN %s AND %s) OR
        (a.end_date IS NOT NULL AND a.start_date <= %s AND a.end_date >= %s)
    )";
    $params[] = $start_date_view;
    $params[] = $end_date_view;
    $params[] = $end_date_view;
    $params[] = $start_date_view;

    if ($employee_id) {
        $where_clauses[] = "e.id = %d";
        $params[] = $employee_id;
    }
    if ($characteristic_id) {
        $where_clauses[] = "e.id IN (
            SELECT employee_id FROM {$wpdb->prefix}pm_employee_characteristics
            WHERE characteristic_id = %d
        )";
        $params[] = $characteristic_id;
    }

    if (!empty($where_clauses)) {
        $query .= " WHERE " . implode(" AND ", $where_clauses);
    }

    $availabilities = $wpdb->get_results($wpdb->prepare($query, $params));

    if ($wpdb->last_error) {
        wp_send_json_error('Erreur SQL : ' . $wpdb->last_error);
    }

    if (!is_array($availabilities)) {
        wp_send_json_success([]);
    }

    $events = [];
    foreach ($availabilities as $availability) {
        if (!$availability->start_date) continue;
        $status_config = [
            'disponible' => ['color' => '#28a745', 'title' => 'Disponible', 'icon' => '✅'],
            'non-disponible' => ['color' => '#dc3545', 'title' => 'Non disponible', 'icon' => '❌'],
            'en attente' => ['color' => '#ffc107', 'title' => 'Réservé - en attente', 'icon' => '⏳'],
            'réservé' => ['color' => '#007bff', 'title' => 'Réservé - accepté', 'icon' => '✔'],
            'refusé' => ['color' => '#6c757d', 'title' => 'Refusé', 'icon' => '🚫']
        ];
        $config = $status_config[$availability->status] ?? [
            'color' => '#007bff',
            'title' => $availability->status,
            'icon' => ''
        ];
        
        $end_date_for_calendar = $availability->end_date;
        if ($end_date_for_calendar) {
            $end_date_for_calendar = (new DateTime($end_date_for_calendar))->modify('+1 day')->format('Y-m-d');
        }

        $events[] = [
            'title' => $availability->name,
            'start' => $availability->start_date,
            'end' => $end_date_for_calendar,
            'backgroundColor' => $config['color'],
            'borderColor' => $config['color'],
            'textColor' => '#ffffff',
            'extendedProps' => [
                'comment' => $availability->comment ?? '',
                'remuneration' => $availability->remuneration ?? '',
                'start_time' => $availability->start_time ?? '',
                'end_time' => $availability->end_time ?? '',
                'employeeId' => absint($availability->employee_id),
                'status' => $availability->status,
                'status_icon' => $config['icon']
            ]
        ];
    }
    wp_send_json_success($events);
}
add_action('wp_ajax_pm_get_admin_availability', 'pm_get_admin_availability');

function pm_update_availability_status() {
    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'pm_status')) {
        wp_send_json_error('Nonce invalide.');
    }

    if (!is_user_logged_in() || !current_user_can('manage_options')) {
        wp_send_json_error('Accès non autorisé.');
    }

    global $wpdb;
    $employee_id = isset($_POST['employee_id']) ? absint($_POST['employee_id']) : 0;
    $date = isset($_POST['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', sanitize_text_field(wp_unslash($_POST['date']))) ? sanitize_text_field(wp_unslash($_POST['date'])) : null;
    $start_date = isset($_POST['start_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', sanitize_text_field(wp_unslash($_POST['start_date']))) ? sanitize_text_field(wp_unslash($_POST['start_date'])) : null;
    $end_date = isset($_POST['end_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', sanitize_text_field(wp_unslash($_POST['end_date']))) ? sanitize_text_field(wp_unslash($_POST['end_date'])) : null;
    $status = isset($_POST['status']) ? sanitize_text_field(wp_unslash($_POST['status'])) : null;
    $start_time = isset($_POST['start_time']) && preg_match('/^([0-1][0-9]|2[0-3]):[0-5][0-9]$/', sanitize_text_field(wp_unslash($_POST['start_time']))) ? sanitize_text_field(wp_unslash($_POST['start_time'])) : null;
    $end_time = isset($_POST['end_time']) && preg_match('/^([0-1][0-9]|2[0-3]):[0-5][0-9]$/', sanitize_text_field(wp_unslash($_POST['end_time']))) ? sanitize_text_field(wp_unslash($_POST['end_time'])) : null;
    $comment = isset($_POST['comment']) ? sanitize_textarea_field(wp_unslash($_POST['comment'])) : '';
    $remuneration = isset($_POST['remuneration']) && is_numeric($_POST['remuneration']) ? sanitize_text_field(wp_unslash($_POST['remuneration'])) : null;

    $mission_start_date = $date ?: $start_date;
    $mission_end_date = ($date) ? null : ($end_date ?: $start_date);
    
    if (!$employee_id || !$mission_start_date || !$status) {
        wp_send_json_error('Paramètres invalides.');
    }

    $final_end_date = ($mission_end_date && $mission_end_date !== $mission_start_date) ? $mission_end_date : null;
    $effective_end_date = $mission_end_date ?: $mission_start_date;

    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->prefix}pm_availabilities
         WHERE employee_id = %d AND (
            (end_date IS NULL AND start_date BETWEEN %s AND %s) OR
            (end_date IS NOT NULL AND start_date <= %s AND end_date >= %s)
         )",
        $employee_id, $mission_start_date, $effective_end_date, $effective_end_date, $mission_start_date
    ));

    $result = $wpdb->insert(
        $wpdb->prefix . 'pm_availabilities',
        [
            'employee_id' => $employee_id,
            'start_date' => $mission_start_date,
            'end_date' => $final_end_date,
            'start_time' => $start_time ?: null,
            'end_time' => $end_time ?: null,
            'status' => $status,
            'comment' => $comment,
            'remuneration' => $remuneration
        ],
        ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
    );

    if ($result === false) {
        wp_send_json_error('Erreur lors de la sauvegarde.');
    }

    if ($status === 'en attente') {
        $employee = $wpdb->get_row($wpdb->prepare("SELECT id, name, user_id FROM {$wpdb->prefix}pm_employees WHERE id = %d", absint($employee_id)));
        if ($employee) {
            $token = bin2hex(random_bytes(32));
            $wpdb->insert(
                $wpdb->prefix . 'pm_notifications',
                [
                    'employee_id' => absint($employee_id),
                    'date' => $mission_start_date,
                    'start_time' => $start_time ?: null,
                    'end_time' => $end_time ?: null,
                    'status' => $status,
                    'comment' => $comment,
                    'remuneration' => $remuneration,
                    'token' => $token,
                    'created_at' => current_time('mysql')
                ],
                ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
            );

            $user = get_userdata($employee->user_id);
            $to = $user ? $user->user_email : '';
            if ($to) {
                $subject = 'Nouvelle demande de guidage';
                $accept_url = esc_url(site_url('/pm-action/accept/' . $token));
                $refuse_url = esc_url(site_url('/pm-action/refuse/' . $token));
                
                $date_display = esc_html($mission_start_date);
                if($final_end_date) {
                    $date_display .= ' au ' . esc_html($final_end_date);
                }

                $message = '
                    <!DOCTYPE html>
                    <html>
                    <head>
                        <meta charset="utf-8">
                        <meta name="viewport" content="width=device-width, initial-scale=1">
                        <title>Nouvelle demande de guidage</title>
                    </head>
                    <body style="font-family: Arial, sans-serif; color: #333; line-height: 1.6; margin: 0; padding: 0; background-color: #f4f4f4;">
                        <table width="100%" cellpadding="0" cellspacing="0" style="max-width: 600px; margin: 20px auto; background-color: #ffffff; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                            <tr>
                                <td style="padding: 20px; text-align: center; background-color: #007bff; color: #ffffff; border-top-left-radius: 8px; border-top-right-radius: 8px;">
                                    <h1 style="margin: 0; font-size: 24px;">Nouvelle demande de guidage</h1>
                                </td>
                            </tr>
                            <tr>
                                <td style="padding: 20px;">
                                    <p style="font-size: 16px; margin: 0 0 10px;">Bonjour ' . esc_html($employee->name) . ',</p>
                                    <p style="font-size: 16px; margin: 0 0 20px;">Vous avez reçu une demande de guidage. Voici les détails :</p>
                                    <table width="100%" cellpadding="0" cellspacing="0" style="font-size: 16px; margin-bottom: 20px;">
                                        <tr>
                                            <td style="padding: 8px 0; font-weight: bold; width: 100px;">Date :</td>
                                            <td style="padding: 8px 0;">' . $date_display . '</td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 8px 0; font-weight: bold; width: 100px;">Heure :</td>
                                            <td style="padding: 8px 0;">' . ($start_time && $end_time ? esc_html($start_time . ' - ' . $end_time) : 'Non spécifié') . '</td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 8px 0; font-weight: bold; width: 100px;">Rémunération :</td>
                                            <td style="padding: 8px 0;">' . ($remuneration ? esc_html($remuneration) : 'Non spécifiée') . '</td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 8px 0; font-weight: bold; vertical-align: top;">Détails :</td>
                                            <td style="padding: 8px 0;"></td>
                                        </tr>
                                        <tr>
                                            <td colspan="2" style="padding: 8px 0;">' . nl2br(esc_html($comment ? $comment : 'Aucun commentaire')) . '</td>
                                        </tr>
                                    </table>
                                    <p style="font-size: 16px; margin: 0 0 20px;">Veuillez confirmer votre disponibilité :</p>
                                    <table width="100%" cellpadding="0" cellspacing="0">
                                        <tr>
                                            <td style="padding: 10px 5px; text-align: center;">
                                                <a href="' . $accept_url . '" style="display: inline-block; padding: 12px 24px; background-color: #28a745; color: #ffffff; text-decoration: none; border-radius: 4px; font-size: 16px; font-weight: bold; transition: background-color 0.3s;">Accepter</a>
                                            </td>
                                            <td style="padding: 10px 5px; text-align: center;">
                                                <a href="' . $refuse_url . '" style="display: inline-block; padding: 12px 24px; background-color: #dc3545; color: #ffffff; text-decoration: none; border-radius: 4px; font-size: 16px; font-weight: bold; transition: background-color 0.3s;">Refuser</a>
                                            </td>
                                        </tr>
                                    </table>
                                </td>
                            </tr>
                            <tr>
                                <td style="padding: 15px; text-align: center; background-color: #f8f9fa; color: #6c757d; font-size: 14px; border-bottom-left-radius: 8px; border-bottom-right-radius: 8px;">
                                    <p style="margin: 0;">Cet email a été envoyé automatiquement. Veuillez ne pas y répondre.</p>
                                </td>
                            </tr>
                        </table>
                    </body>
                    </html>
                ';
                $headers = ['Content-Type: text/html; charset=UTF-8'];
                wp_mail($to, $subject, $message, $headers);
            }
        }
    }
    wp_send_json_success(['affected_rows' => $result]);
}
add_action('wp_ajax_pm_update_availability_status', 'pm_update_availability_status');

remove_action('wp_ajax_nopriv_pm_accept', 'pm_accept_request');
remove_action('wp_ajax_pm_accept', 'pm_accept_request');
remove_action('wp_ajax_nopriv_pm_refuse', 'pm_refuse_request');
remove_action('wp_ajax_pm_refuse', 'pm_refuse_request');

function pm_register_endpoints() {
    // rendre WordPress conscient de ces nouveaux tags
    add_rewrite_tag('%pm_action%', '([^&]+)'); // cf. add_rewrite_tag :contentReference[oaicite:1]{index=1}
    add_rewrite_tag('%pm_token%',  '([^&]+)');
    // créer la règle qui mappe /pm-action/{accept|refuse}/{token} aux query-vars
    add_rewrite_rule(
        '^pm-action/(accept|refuse)/([^/]+)/?$',
        'index.php?pm_action=$matches[1]&pm_token=$matches[2]',
        'top'
    ); // cf. add_rewrite_rule :contentReference[oaicite:2]{index=2}
}
add_action('init', 'pm_register_endpoints');

register_activation_hook(__FILE__, function() {
    pm_register_endpoints();
    flush_rewrite_rules();
});

// Set flag to flush rewrite rules on activation
function pm_activate_flush_rewrite() {
    update_option('pm_flush_rewrite_rules', 1);
}
register_activation_hook(__FILE__, 'pm_activate_flush_rewrite');

function pm_handle_action_requests() {
    global $wpdb;

    $action = get_query_var('pm_action', false);
    $token = get_query_var('pm_token', false);

    if (!preg_match('#^/pm-action/(accept|refuse)/#', $_SERVER['REQUEST_URI'])) {
        return;
    }

    if (is_page('page-guide')) {
        return;
    }

    if (!$action || !$token || !in_array($action, ['accept', 'refuse']) || !preg_match('/^[a-f0-9]{64}$/', $token)) {
        wp_safe_redirect(add_query_arg(['status' => 'error', 'message' => urlencode('Jeton ou action invalide.')], site_url('/page-guide/')), 302);
        exit;
    }

    $notification = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}pm_notifications WHERE token = %s",
        $token
    ));

    if (!$notification) {
        wp_safe_redirect(add_query_arg(['status' => 'error', 'message' => urlencode('Lien invalide ou expiré.')], site_url('/page-guide/')), 302);
        exit;
    }

    $availability = $wpdb->get_row($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}pm_availabilities WHERE employee_id = %d AND start_date = %s",
        absint($notification->employee_id), $notification->date
    ));

    if (!$availability) {
        wp_safe_redirect(add_query_arg(['status' => 'error', 'message' => urlencode('Disponibilité non trouvée.')], site_url('/page-guide/')), 302);
        exit;
    }

    $new_status = $action === 'accept' ? 'réservé' : 'non-disponible';
    $result = $wpdb->update(
        $wpdb->prefix . 'pm_availabilities',
        [
            'status' => $new_status,
            'start_time' => $notification->start_time ?: null,
            'end_time' => $notification->end_time ?: null
        ],
        ['id' => $availability->id],
        ['%s', '%s', '%s'],
        ['%d']
    );

    if ($result === false) {
        wp_safe_redirect(add_query_arg(['status' => 'error', 'message' => urlencode('Erreur lors de la mise à jour.')], site_url('/page-guide/')), 302);
        exit;
    }

    $wpdb->delete(
        $wpdb->prefix . 'pm_notifications',
        ['token' => $token],
        ['%s']
    );

    $message = $action === 'accept' ? 'Demande acceptée avec succès.' : 'Demande refusée avec succès.';
    wp_safe_redirect(add_query_arg(['status' => 'success', 'message' => urlencode($message)], site_url('/page-guide/')), 302);
    exit;
}
add_action('template_redirect', 'pm_handle_action_requests');

function pm_confirmation_page_shortcode() {
    if (!isset($_GET['status']) || !isset($_GET['message'])) {
        return '';
    }

    $status = sanitize_text_field($_GET['status']);
    $message = sanitize_text_field(urldecode($_GET['message']));
    $class = $status === 'success' ? 'pm-message-success' : 'pm-message-error';

    ob_start();
    ?>
    <div class="pm-confirmation">
        <div class="pm-message">
 <?php echo $class; ?>">
            <?php echo esc_html($message); ?>
        </div>
        <p><a href="<?php echo esc_url(home_url()); ?>">Retour à l'accueil</a></p>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('pm_confirmation', 'pm_confirmation_page_shortcode');

function pm_confirmation_page() {
    if (!isset($_GET['status']) || !isset($_GET['message'])) {
        return;
    }

    $status = sanitize_text_field($_GET['status']);
    $message = sanitize_text_field(urldecode($_GET['message']));
    $class = $status === 'success' ? 'pm-message-success' : 'pm-message-error';

    ob_start();
    ?>
    <div class="pm-confirmation">
        <div class="pm-message <?php echo esc_attr($class); ?>">
            <?php echo esc_html($message); ?>
        </div>
        <p><a href="<?php echo esc_url(home_url()); ?>">Retour à l'accueil</a></p>
    </div>
    <?php
    echo ob_get_clean();
}
add_action('wp_footer', 'pm_confirmation_page');


// Fonction pour enregistrer les règles de réécriture
function pm_register_rewrite_rules() {
    add_rewrite_rule(
        '^pm-action/(accept|refuse)/([a-f0-9]{64})/?$',
        'index.php?pm_action=$matches[1]&pm_token=$matches[2]',
        'top'
    );
    error_log('Personnel Management: Rewrite rules registered for pm-action');
}
add_action('init', 'pm_register_rewrite_rules');

// Enregistrer les règles uniquement à l'activation du plugin
function pm_activate_plugin() {
    pm_register_rewrite_rules();
    flush_rewrite_rules();
    error_log('Personnel Management: Plugin activated and rewrite rules flushed');
}
register_activation_hook(__FILE__, 'pm_activate_plugin');

// Nettoyer les règles à la désactivation
function pm_deactivate_plugin() {
    flush_rewrite_rules();
    error_log('Personnel Management: Plugin deactivated and rewrite rules flushed');
}
register_deactivation_hook(__FILE__, 'pm_deactivate_plugin');

// Ajouter les variables de requête
function pm_query_vars($vars) {
    $vars[] = 'pm_action';
    $vars[] = 'pm_token';
    return $vars;
}
add_filter('query_vars', 'pm_query_vars');


use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Font;

/**
 * Gère la génération et le téléchargement de l'export CSV des rémunérations.
 *
 * @param string $month Le mois à exporter au format YYYY-MM.
 */
function pm_handle_remuneration_export($month) {
    // S'assurer que le fichier autoload de Composer existe
    $autoloader = PM_PLUGIN_DIR . 'vendor/autoload.php';
    if (!file_exists($autoloader)) {
        wp_die("La bibliothèque PhpSpreadsheet est manquante. Veuillez lancer 'composer require phpoffice/phpspreadsheet' dans le dossier du plugin.");
    }
    require_once $autoloader;

    global $wpdb;

    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        wp_die('Format de date invalide.');
    }

    $table_employees = $wpdb->prefix . 'pm_employees';
    $table_availabilities = $wpdb->prefix . 'pm_availabilities';

    // Nouvelle requête pour récupérer CHAQUE mission, et non plus le total
    $missions = $wpdb->get_results($wpdb->prepare(
        "SELECT
            e.name AS guide_name,
            a.start_date,
            a.comment,
            a.remuneration
         FROM
            {$table_employees} e
         JOIN
            {$table_availabilities} a ON e.id = a.employee_id
         WHERE
            a.status = 'réservé'
            AND a.remuneration IS NOT NULL
            AND DATE_FORMAT(a.start_date, '%%Y-%%m') = %s
         ORDER BY
            e.name ASC, a.start_date ASC",
        $month
    ));

    // Créer un nouveau classeur Excel
    $spreadsheet = new Spreadsheet();
    $spreadsheet->removeSheetByIndex(0); // Supprimer la feuille créée par défaut

    $guidesData = [];
    foreach ($missions as $mission) {
        $guidesData[$mission->guide_name][] = $mission;
    }

    if (empty($guidesData)) {
        // S'il n'y a aucune donnée, créer une feuille simple avec un message
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Aucune donnée');
        $sheet->setCellValue('A1', 'Aucune mission rémunérée trouvée pour ' . $month);
    } else {
        foreach ($guidesData as $guideName => $guideMissions) {
            // Créer une nouvelle feuille pour chaque guide
            $sheet = $spreadsheet->createSheet();
            // Nettoyer le nom du guide pour le nom de la feuille (max 31 chars, sans certains caractères)
            $sheetName = substr(preg_replace('/[\\\\\/\?\*\[\]]/', '', $guideName), 0, 31);
            $sheet->setTitle($sheetName);

            // Ajouter les en-têtes
            $sheet->setCellValue('A1', 'Date');
            $sheet->setCellValue('B1', 'Détails de la mission');
            $sheet->setCellValue('C1', 'Rémunération');
            $sheet->getStyle('A1:C1')->getFont()->setBold(true); // Mettre en gras

            $row = 2;
            $totalRemuneration = 0;
            foreach ($guideMissions as $mission) {
                $sheet->setCellValue('A' . $row, $mission->start_date);
                $sheet->setCellValue('B' . $row, $mission->comment);
                $sheet->setCellValue('C' . $row, $mission->remuneration);
                $totalRemuneration += (float) $mission->remuneration;
                $row++;
            }

            // Ajouter la ligne de Total
            $sheet->setCellValue('B' . ($row + 1), 'TOTAL');
            $sheet->setCellValue('C' . ($row + 1), $totalRemuneration);
            $sheet->getStyle('B' . ($row + 1) . ':C' . ($row + 1))->getFont()->setBold(true);

            // Ajuster la largeur des colonnes automatiquement
            $sheet->getColumnDimension('A')->setAutoSize(true);
            $sheet->getColumnDimension('B')->setAutoSize(true);
            $sheet->getColumnDimension('C')->setAutoSize(true);
        }
    }


    $filename = 'export-details-remunerations-' . $month . '.xlsx';

    // Définir les en-têtes HTTP pour un fichier .xlsx
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    // Créer l'objet "Writer" et envoyer le fichier au navigateur
    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');

    exit;
}

?>