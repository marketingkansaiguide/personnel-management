<?php
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
        wp_enqueue_style('pm-styles', plugins_url('/css/pm-styles.css', PM_PLUGIN_DIR . 'personnel-management.php'), [], '1.9', 'all');
        // Ajouter le nonce via wp_localize_script
        wp_localize_script('fullcalendar', 'pmAjax', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('pm_status')
        ]);
    }
}
add_action('wp_enqueue_scripts', 'pm_enqueue_scripts');
?>