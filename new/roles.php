<?php
// Créer un rôle personnalisé pour les employés
function pm_add_employee_role() {
    add_role('pm_employee', 'Employé', [
        'read' => true,
        'pm_manage_availability' => true
    ]);
}
register_activation_hook(PM_PLUGIN_DIR . 'personnel-management.php', 'pm_add_employee_role');

// Supprimer le rôle personnalisé lors de la désactivation
function pm_remove_employee_role() {
    remove_role('pm_employee');
}
register_deactivation_hook(PM_PLUGIN_DIR . 'personnel-management.php', 'pm_remove_employee_role');
?>