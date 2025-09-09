<?php
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
        ?>
        <tr data-employee-id="<?php echo esc_attr(absint($employee->id)); ?>">
            <td><?php echo esc_html($employee->name); ?></td>
            <td><?php echo esc_html($user->user_email); ?></td>
            <td>
                <button type="button" onclick="pmEditEmployee(<?php echo esc_attr(absint($employee->id)); ?>, '<?php echo esc_js($employee->name); ?>', '<?php echo esc_js($user->user_email); ?>')">Modifier</button>
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
        "SELECT date, status, comment, start_time, end_time FROM {$wpdb->prefix}pm_availabilities WHERE employee_id = %d",
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
        error_log('Personnel Management: Availability - Date: ' . $availability->date . ', Status: ' . $availability->status . ', Start Time: ' . ($availability->start_time ?: 'None') . ', End Time: ' . ($availability->end_time ?: 'None') . ', Comment: ' . ($availability->comment ?: 'None'));
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
        $events[] = [
            'title' => $config['title'],
            'start' => $availability->date,
            'backgroundColor' => $config['color'],
            'borderColor' => $config['color'],
            'textColor' => '#ffffff',
            'extendedProps' => [
                'comment' => $availability->comment ?: '',
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

    $employee_id = absint($employee->id);
    $start_date = sanitize_text_field(wp_unslash($_POST['start_date']));
    $end_date = sanitize_text_field(wp_unslash($_POST['end_date']));
    $status = sanitize_text_field(wp_unslash($_POST['status']));
    $start_time = !empty($_POST['start_time']) ? sanitize_text_field(wp_unslash($_POST['start_time'])) : null;
    $end_time = !empty($_POST['end_time']) ? sanitize_text_field(wp_unslash($_POST['end_time'])) : null;

    if (empty($start_date) || empty($status)) {
        wp_send_json_error('Date de début et statut requis.');
    }

    if (!in_array($status, ['disponible', 'non-disponible'])) {
        wp_send_json_error('Statut invalide.');
    }

    if (!$end_date) {
        $end_date = $start_date;
    }

    $start = new DateTime($start_date);
    $end = new DateTime($end_date);
    if ($start > $end) {
        wp_send_json_error('La date de début doit être antérieure ou égale à la date de fin.');
    }

    $current_date = clone $start;
    while ($current_date <= $end) {
        $date_str = $current_date->format('Y-m-d');

        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id, status, comment FROM {$wpdb->prefix}pm_availabilities WHERE employee_id = %d AND date = %s",
            $employee_id,
            $date_str
        ));

        if ($existing && ($existing->status === 'réservé' || ($existing->comment && trim($existing->comment) !== '' && $existing->status !== 'disponible' && $existing->status !== 'non-disponible'))) {
            continue;
        }

        if ($existing) {
            $wpdb->update(
                $wpdb->prefix . 'pm_availabilities',
                [
                    'status' => $status,
                    'start_time' => $start_time,
                    'end_time' => $end_time,
                    'comment' => ''
                ],
                [
                    'employee_id' => $employee_id,
                    'date' => $date_str
                ],
                ['%s', '%s', '%s', '%s'],
                ['%d', '%s']
            );
        } else {
            $wpdb->insert(
                $wpdb->prefix . 'pm_availabilities',
                [
                    'employee_id' => $employee_id,
                    'date' => $date_str,
                    'status' => $status,
                    'start_time' => $start_time,
                    'end_time' => $end_time,
                    'comment' => ''
                ],
                ['%d', '%s', '%s', '%s', '%s', '%s']
            );
        }

        $current_date->modify('+1 day');
    }

    wp_send_json_success();
}
add_action('wp_ajax_pm_update_availability', 'pm_update_availability');

// AJAX pour supprimer les disponibilités
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

    $employee_id = absint($employee->id);
    $start_date = sanitize_text_field(wp_unslash($_POST['start_date']));
    $end_date = sanitize_text_field(wp_unslash($_POST['end_date']));

    if (empty($start_date)) {
        wp_send_json_error('Date de début requise.');
    }

    if (!$end_date) {
        $end_date = $start_date;
    }

    $start = new DateTime($start_date);
    $end = new DateTime($end_date);
    if ($start > $end) {
        wp_send_json_error('La date de début doit être antérieure ou égale à la date de fin.');
    }

    $current_date = clone $start;
    while ($current_date <= $end) {
        $date_str = $current_date->format('Y-m-d');

        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT status, comment FROM {$wpdb->prefix}pm_availabilities WHERE employee_id = %d AND date = %s",
            $employee_id,
            $date_str
        ));

        if ($existing && ($existing->status === 'réservé' || ($existing->comment && trim($existing->comment) !== '' && $existing->status !== 'disponible' && $existing->status !== 'non-disponible'))) {
            $current_date->modify('+1 day');
            continue;
        }

        $wpdb->delete(
            $wpdb->prefix . 'pm_availabilities',
            [
                'employee_id' => $employee_id,
                'date' => $date_str
            ],
            ['%d', '%s']
        );

        $current_date->modify('+1 day');
    }

    wp_send_json_success();
}
add_action('wp_ajax_pm_delete_availability', 'pm_delete_availability');

// AJAX pour récupérer les disponibilités pour l'admin
function pm_get_admin_availability() {
    if (!is_user_logged_in() || !current_user_can('manage_options')) {
        wp_send_json_error('Accès non autorisé.');
    }

    global $wpdb;
    $employee_id = isset($_GET['employee_id']) && $_GET['employee_id'] !== 'all' ? absint($_GET['employee_id']) : null;
    $start_date = sanitize_text_field(wp_unslash($_GET['start_date']));
    $end_date = sanitize_text_field(wp_unslash($_GET['end_date']));

    $query = "SELECT a.*, e.name FROM {$wpdb->prefix}pm_availabilities a
              JOIN {$wpdb->prefix}pm_employees e ON a.employee_id = e.id
              WHERE a.date BETWEEN %s AND %s";
    $params = [$start_date, $end_date];

    if ($employee_id) {
        $query .= " AND a.employee_id = %d";
        $params[] = $employee_id;
    }

    $availabilities = $wpdb->get_results($wpdb->prepare($query, $params));

    $events = [];
    $status_config = [
        'disponible' => ['color' => '#28a745', 'title' => 'Disponible', 'icon' => '✅'],
        'non-disponible' => ['color' => '#dc3545', 'title' => 'Non disponible', 'icon' => '❌'],
        'en attente' => ['color' => '#ffc107', 'title' => 'Réservé - En attente', 'icon' => '⏳'],
        'réservé' => ['color' => '#007bff', 'title' => 'Réservé - accepté', 'icon' => '✔️'],
        'refusé' => ['color' => '#6c757d', 'title' => 'Refusé', 'icon' => '🚫']
    ];

    foreach ($availabilities as $availability) {
        $config = $status_config[$availability->status] ?? [
            'color' => '#007bff',
            'title' => $availability->status,
            'icon' => ''
        ];
        $events[] = [
            'title' => $availability->name,
            'start' => $availability->date,
            'backgroundColor' => $config['color'],
            'borderColor' => $config['color'],
            'textColor' => '#ffffff',
            'extendedProps' => [
                'employeeId' => $availability->employee_id,
                'status' => $availability->status,
                'status_icon' => $config['icon'],
                'start_time' => $availability->start_time ?: '',
                'end_time' => $availability->end_time ?: '',
                'comment' => $availability->comment ?: ''
            ]
        ];
    }

    wp_send_json_success($events);
}
add_action('wp_ajax_pm_get_admin_availability', 'pm_get_admin_availability');

// AJAX pour mettre à jour le statut des disponibilités
function pm_update_availability_status() {
    if (empty($_POST['start_date']) && !empty($_POST['date'])) {
        $_POST['start_date'] = $_POST['date'];
    }
    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'pm_availability')) {
        wp_send_json_error('Nonce invalide.');
    }

    if (!current_user_can('manage_options')) {
        wp_send_json_error('Accès non autorisé.');
    }

    $employee_id = absint($_POST['employee_id']);
    $start_date = sanitize_text_field(wp_unslash($_POST['start_date']));
    $end_date = isset($_POST['end_date']) ? sanitize_text_field(wp_unslash($_POST['end_date'])) : $start_date;
    $status = sanitize_text_field(wp_unslash($_POST['status']));
    $start_time = !empty($_POST['start_time']) ? sanitize_text_field(wp_unslash($_POST['start_time'])) : null;
    $end_time = !empty($_POST['end_time']) ? sanitize_text_field(wp_unslash($_POST['end_time'])) : null;
    $comment = !empty($_POST['comment']) ? sanitize_textarea_field(wp_unslash($_POST['comment'])) : '';

    if (empty($employee_id) || empty($start_date) || empty($status)) {
        wp_send_json_error('Paramètres manquants.');
    }

    if (!in_array($status, ['disponible', 'non-disponible', 'réservé'])) {
        wp_send_json_error('Statut invalide.');
    }

    global $wpdb;
    $employee_exists = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}pm_employees WHERE id = %d",
        $employee_id
    ));

    if (!$employee_exists) {
        wp_send_json_error('Employé non trouvé.');
    }

    $start = new DateTime($start_date);
    $end = new DateTime($end_date);
    if ($start > $end) {
        wp_send_json_error('La date de début doit être antérieure ou égale à la date de fin.');
    }

    $current_date = clone $start;
    while ($current_date <= $end) {
        $date_str = $current_date->format('Y-m-d');

        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}pm_availabilities WHERE employee_id = %d AND date = %s",
            $employee_id,
            $date_str
        ));

        $data = [
            'employee_id' => $employee_id,
            'date' => $date_str,
            'status' => $status,
            'start_time' => $start_time,
            'end_time' => $end_time,
            'comment' => $comment
        ];
        $format = ['%d', '%s', '%s', '%s', '%s', '%s'];

        if ($existing) {
            $wpdb->update(
                $wpdb->prefix . 'pm_availabilities',
                $data,
                [
                    'employee_id' => $employee_id,
                    'date' => $date_str
                ],
                $format,
                ['%d', '%s']
            );
        } else {
            $wpdb->insert(
                $wpdb->prefix . 'pm_availabilities',
                $data,
                $format
            );
        }

        $current_date->modify('+1 day');
    }

    wp_send_json_success();
}
add_action('wp_ajax_pm_update_availability_status', 'pm_update_availability_status');

// AJAX pour envoyer une notification au guide
function pm_notify_guide() {
    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'pm_notify')) {
        wp_send_json_error('Nonce invalide.');
    }

    if (!current_user_can('manage_options')) {
        wp_send_json_error('Accès non autorisé.');
    }

    $employee_id = absint($_POST['employee_id']);
    $date = sanitize_text_field(wp_unslash($_POST['date']));
    $status = sanitize_text_field(wp_unslash($_POST['status']));
    $start_time = !empty($_POST['start_time']) ? sanitize_text_field(wp_unslash($_POST['start_time'])) : null;
    $end_time = !empty($_POST['end_time']) ? sanitize_text_field(wp_unslash($_POST['end_time'])) : null;
    $comment = !empty($_POST['comment']) ? sanitize_textarea_field(wp_unslash($_POST['comment'])) : '';

    global $wpdb;
    $employee = $wpdb->get_row($wpdb->prepare(
        "SELECT e.name, u.user_email FROM {$wpdb->prefix}pm_employees e
         JOIN {$wpdb->users} u ON e.user_id = u.ID
         WHERE e.id = %d",
        $employee_id
    ));

    if (!$employee) {
        wp_send_json_error('Employé non trouvé.');
    }

    if ($status !== 'en attente') {
        wp_send_json_success();
        return;
    }

    $token = bin2hex(random_bytes(32));
    $notification_data = [
        'employee_id' => $employee_id,
        'date' => $date,
        'start_time' => $start_time,
        'end_time' => $end_time,
        'status' => $status,
        'comment' => $comment,
        'token' => $token,
        'created_at' => current_time('mysql')
    ];

    $wpdb->insert(
        $wpdb->prefix . 'pm_notifications',
        $notification_data,
        ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
    );

    if ($wpdb->last_error) {
        wp_send_json_error('Erreur SQL : ' . $wpdb->last_error);
    }

    $accept_url = add_query_arg(
        [
            'action' => 'pm_accept',
            'token' => $token
        ],
        home_url('/wp-json/pm/v1/action')
    );

    $reject_url = add_query_arg(
        [
            'action' => 'pm_refuse',
            'token' => $token
        ],
        home_url('/wp-json/pm/v1/action')
    );

    $subject = 'Nouvelle demande de prestation';

    $message = '
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
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
        <p style="font-size: 16px;">Bonjour ' . esc_html($employee->name) . ',</p>
        <p style="font-size: 16px;">Vous avez reçu une demande de guidage. Voici les détails :</p>
        <table width="100%" cellpadding="0" cellspacing="0" style="font-size: 16px; margin-bottom: 20px;">
          <tr><td style="padding: 8px 0; font-weight: bold; width: 100px;">Date :</td><td>' . esc_html($date) . '</td></tr>
          <tr><td style="padding: 8px 0; font-weight: bold;">Heure :</td><td>' . esc_html($start_time) . ' - ' . esc_html($end_time) . '</td></tr>
          <tr><td style="padding: 8px 0; font-weight: bold; vertical-align: top;">Détails :</td><td>' . nl2br(esc_html($comment)) . '</td></tr>
        </table>
        <p style="font-size: 16px;">Veuillez confirmer votre disponibilité :</p>
        <table width="100%" cellpadding="0" cellspacing="0">
          <tr>
            <td style="padding: 10px 5px; text-align: center;">
              <a href="' . esc_url($accept_url) . '" style="display: inline-block; padding: 12px 24px; background-color: #28a745; color: #ffffff; text-decoration: none; border-radius: 4px; font-size: 16px; font-weight: bold;">Accepter</a>
            </td>
            <td style="padding: 10px 5px; text-align: center;">
              <a href="' . esc_url($reject_url) . '" style="display: inline-block; padding: 12px 24px; background-color: #dc3545; color: #ffffff; text-decoration: none; border-radius: 4px; font-size: 16px; font-weight: bold;">Refuser</a>
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
</html>';

    $headers = [
        'Content-Type: text/html; charset=UTF-8'
    ];

    $sent = wp_mail($employee->user_email, $subject, $message, $headers);

    if ($sent) {
        wp_send_json_success();
    } else {
        wp_send_json_error('Échec de l’envoi de l’email.');
    }
}
add_action('wp_ajax_pm_notify_guide', 'pm_notify_guide');

// AJAX pour récupérer un nonce frais
function pm_get_nonce_callback() {
    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'Utilisateur non connecté']);
        wp_die();
    }

    $nonce_action = isset($_GET['nonce_action']) ? sanitize_text_field($_GET['nonce_action']) : '';
    if (!$nonce_action) {
        wp_send_json_error(['message' => 'Action de nonce manquante']);
        wp_die();
    }

    $nonce = wp_create_nonce($nonce_action);
    wp_send_json_success(['nonce' => $nonce]);
    wp_die();
}
add_action('wp_ajax_pm_get_nonce', 'pm_get_nonce_callback');

// REST API pour gérer les actions d'acceptation et de refus
add_action('rest_api_init', function () {
    register_rest_route('pm/v1', '/action', [
        'methods' => 'GET',
        'callback' => 'pm_handle_guide_action',
        'permission_callback' => '__return_true'
    ]);
});

function pm_handle_guide_action(WP_REST_Request $request) {
    global $wpdb;
    $action = sanitize_text_field($request->get_param('action'));
    $token = sanitize_text_field($request->get_param('token'));

    if (empty($action) || empty($token) || !in_array($action, ['pm_accept', 'pm_refuse'])) {
        return new WP_Error('invalid_request', 'Requête invalide.', ['status' => 400]);
    }

    $notification = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}pm_notifications WHERE token = %s",
        $token
    ));

    if (!$notification) {
        return new WP_Error('invalid_token', 'Token invalide ou expiré.', ['status' => 404]);
    }

    $new_status = ($action === 'pm_accept') ? 'réservé' : 'refusé';
    $wpdb->update(
        $wpdb->prefix . 'pm_availabilities',
        [
            'status' => $new_status,
            'start_time' => $notification->start_time,
            'end_time' => $notification->end_time,
            'comment' => $notification->comment
        ],
        [
            'employee_id' => absint($notification->employee_id),
            'date' => $notification->date
        ],
        ['%s', '%s', '%s', '%s'],
        ['%d', '%s']
    );

    if ($wpdb->last_error) {
        return new WP_Error('db_error', 'Erreur SQL : ' . $wpdb->last_error, ['status' => 500]);
    }

    $wpdb->delete(
        $wpdb->prefix . 'pm_notifications',
        ['token' => $token],
        ['%s']
    );

    $confirmation_page_id = get_option('pm_confirmation_page');
    if (!$confirmation_page_id) {
        return new WP_Error('no_confirmation_page', 'Page de confirmation non configurée.', ['status' => 500]);
    }

    $confirmation_url = add_query_arg(
        [
            'pm_action' => $action,
            'status' => $new_status,
            'date' => $notification->date,
            'employee_id' => $notification->employee_id
        ],
        get_permalink($confirmation_page_id)
    );

    wp_redirect($confirmation_url);
    exit;
}
?>