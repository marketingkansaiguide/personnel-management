<?php
// Gestion des endpoints pour accepter/refuser les demandes
function pm_register_rest_routes() {
    register_rest_route('pm/v1', '/action', [
        'methods' => 'GET',
        'callback' => 'pm_handle_action',
        'permission_callback' => '__return_true'
    ]);
}
add_action('rest_api_init', 'pm_register_rest_routes');

function pm_handle_action(WP_REST_Request $request) {
    global $wpdb;
    $action = sanitize_text_field($request->get_param('action'));
    $token = sanitize_text_field($request->get_param('token'));

    if (empty($action) || empty($token)) {
        return new WP_Error('invalid_request', 'Action ou token manquant.', ['status' => 400]);
    }

    $notification = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}pm_notifications WHERE token = %s",
        $token
    ));

    if (!$notification) {
        return new WP_Error('invalid_token', 'Token invalide ou expiré.', ['status' => 404]);
    }

    $employee_id = absint($notification->employee_id);
    $date = $notification->date;
    $new_status = ($action === 'pm_accept') ? 'réservé' : 'refusé';
    $start_time = $notification->start_time;
    $end_time = $notification->end_time;
    $comment = $notification->comment;

    $existing = $wpdb->get_row($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}pm_availabilities WHERE employee_id = %d AND date = %s",
        $employee_id,
        $date
    ));

    if ($existing) {
        $wpdb->update(
            $wpdb->prefix . 'pm_availabilities',
            [
                'status' => $new_status,
                'start_time' => $start_time,
                'end_time' => $end_time,
                'comment' => $comment
            ],
            [
                'employee_id' => $employee_id,
                'date' => $date
            ],
            ['%s', '%s', '%s', '%s'],
            ['%d', '%s']
        );
    } else {
        $wpdb->insert(
            $wpdb->prefix . 'pm_availabilities',
            [
                'employee_id' => $employee_id,
                'date' => $date,
                'status' => $new_status,
                'start_time' => $start_time,
                'end_time' => $end_time,
                'comment' => $comment
            ],
            ['%d', '%s', '%s', '%s', '%s', '%s']
        );
    }

    $wpdb->delete(
        $wpdb->prefix . 'pm_notifications',
        ['token' => $token],
        ['%s']
    );

    // Récupérer l'ID de la page contenant le shortcode [pm_employee_login]
    $confirmation_page_id = get_option('pm_employee_page_id', 0);
    $confirmation_page = $confirmation_page_id ? get_permalink($confirmation_page_id) : home_url();
    $redirect_url = add_query_arg(
        [
            'pm_status' => 'success',
            'pm_message' => urlencode($action === 'pm_accept' ? 'La demande a été validée avec succès.' : 'La demande a été refusée.')
        ],
        $confirmation_page
    );

    wp_redirect($redirect_url);
    exit;
}

function pm_register_settings() {
    register_setting('pm_options', 'pm_confirmation_page', 'sanitize_text_field');
    register_setting('pm_options', 'pm_employee_page_id', 'absint'); // Nouvelle option
    add_settings_field(
        'pm_confirmation_page',
        'Page de confirmation',
        'pm_confirmation_page_callback',
        'general'
    );
    add_settings_field(
        'pm_employee_page_id',
        'Page des employés (avec [pm_employee_login])',
        'pm_employee_page_callback',
        'general'
    );
}
add_action('admin_init', 'pm_register_settings');

function pm_confirmation_page_callback() {
    $value = get_option('pm_confirmation_page', home_url());
    echo '<input type="text" name="pm_confirmation_page" value="' . esc_attr($value) . '" class="regular-text">';
}

function pm_employee_page_callback() {
    $value = get_option('pm_employee_page_id', 0);
    wp_dropdown_pages([
        'name' => 'pm_employee_page_id',
        'selected' => $value,
        'show_option_none' => __('Sélectionner une page', 'textdomain'),
        'option_none_value' => 0
    ]);
}
?>