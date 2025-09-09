<?php
// Shortcode pour la gestion des employés en front-office
function pm_manage_employees_shortcode() {
    // Code inchangé (non pertinent pour ce problème)
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

    $message = '';
    if (isset($_POST['pm_action']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['pm_nonce'])), 'pm_employee_action')) {
        $name = sanitize_text_field(wp_unslash($_POST['employee_name']));
        $email = sanitize_email(wp_unslash($_POST['employee_email']));
        $password = wp_unslash($_POST['employee_password']);
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
                $message = '<p class="pm-message pm-message-success">Employé mis à jour avec succès.</p>';
            } else {
                $message = '<p class="pm-message pm-message-error">Employé non trouvé.</p>';
            }
        }
        $employees = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}pm_employees");
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
        <h3>Ajouter un employé</h3>
        <form id="pm_add_employee_form" method="post" action="">
            <input type="text" name="employee_name" placeholder="Nom de l'employé" required maxlength="255">
            <input type="email" name="employee_email" placeholder="Email" required>
            <input type="password" name="employee_password" placeholder="Mot de passe" required>
            <input type="hidden" name="pm_action" value="add">
            <?php wp_nonce_field('pm_employee_action', 'pm_nonce'); ?>
            <input type="submit" value="Ajouter">
        </form>

        <h3>Liste des employés</h3>
        <table class="pm-employee-table">
            <thead>
                <tr>
                    <th>Nom</th>
                    <th>Email</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="pm_employee_list">
                <?php foreach ($employees as $employee) :
                    $user_data = get_userdata($employee->user_id);
                    if (!$user_data) continue;
                    ?>
                    <tr data-employee-id="<?php echo esc_attr(absint($employee->id)); ?>">
                        <td><?php echo esc_html($employee->name); ?></td>
                        <td><?php echo esc_html($user_data->user_email); ?></td>
                        <td>
                            <button type="button" onclick="pmEditEmployee(<?php echo esc_attr(absint($employee->id)); ?>, '<?php echo esc_js($employee->name); ?>', '<?php echo esc_js($user_data->user_email); ?>')">Modifier</button>
                            <form method="post" style="display:inline;" class="pm_delete_employee_form">
                                <input type="hidden" name="employee_id" value="<?php echo esc_attr(absint($employee->id)); ?>">
                                <?php wp_nonce_field('pm_delete_employee', 'pm_delete_nonce'); ?>
                                <input type="submit" name="delete_employee" value="Supprimer" onclick="return confirm('Confirmer la suppression ?');">
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <form method="post" action="" id="pm_edit_employee_form" style="display:none;">
            <input type="text" name="employee_name" id="pm_edit_employee_name" required maxlength="255">
            <input type="email" name="employee_email" id="pm_edit_employee_email" required>
            <input type="password" name="employee_password" id="pm_edit_employee_password" placeholder="Nouveau mot de passe (optionnel)">
            <input type="hidden" name="employee_id" id="pm_edit_employee_id">
            <input type="hidden" name="pm_action" value="edit">
            <?php wp_nonce_field('pm_employee_action', 'pm_nonce'); ?>
            <input type="submit" value="Mettre à jour">
        </form>
    </div>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
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
                        url: '<?php echo esc_url(admin_url('admin-ajax.php')); ?>',
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

                                jQuery.get('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {
                                    action: 'pm_get_employees'
                                }, function(resp) {
                                    if (resp.success) {
                                        employeeList.innerHTML = resp.data.html;
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

            window.pmEditEmployee = function(id, name, email) {
                id = parseInt(id, 10);
                if (isNaN(id) || id <= 0) {
                    alert('Identifiant d’employé invalide.');
                    return;
                }
                name = name || '';
                email = email || '';
                document.getElementById('pm_edit_employee_id').value = id;
                document.getElementById('pm_edit_employee_name').value = name;
                document.getElementById('pm_edit_employee_email').value = email;
                document.getElementById('pm_edit_employee_form').style.display = 'block';
            };
        });
    </script>
    <?php
    return ob_get_clean();
}
add_shortcode('pm_manage_employees', 'pm_manage_employees_shortcode');

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
                <p><a href="<?php echo esc_url(wp_lostpassword_url()); ?>">Mot de passe oublié ?</a></p>
            </form>
        </div>
        <?php
        // Gestion de la modale pour les utilisateurs non connectés
        $status = isset($_GET['pm_status']) ? sanitize_text_field($_GET['pm_status']) : '';
        $message = isset($_GET['pm_message']) ? sanitize_text_field(urldecode($_GET['pm_message'])) : '';
        if ($status && $message) {
            ?>
            <div id="pm_confirmation_modal" class="pm-modal">
                <div class="pm-modal-content">
                    <h3>Confirmation</h3>
                    <p class="<?php echo esc_attr($status === 'success' ? 'pm-message-success' : 'pm-message-error'); ?>">
                        <?php echo esc_html($message); ?>
                    </p>
                    <button id="pm_modal_close">Fermer</button>
                </div>
            </div>
            <script>
                document.getElementById('pm_modal_close').addEventListener('click', function() {
                    document.getElementById('pm_confirmation_modal').remove();
                    window.history.replaceState({}, document.title, window.location.pathname);
                });
            </script>
            <?php
        }
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
    <style>
        .pm-modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 1000;
        }
        .pm-modal-content {
            background: white;
            padding: 20px;
            border-radius: 8px;
            max-width: 400px;
            width: 90%;
            text-align: center;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
        }
        .pm-message-success {
            color: #28a745;
            font-weight: bold;
        }
        .pm-message-error {
            color: #dc3545;
            font-weight: bold;
        }
        .pm-modal-content button {
            margin-top: 15px;
            padding: 10px 20px;
            background-color: #007bff;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }
        .pm-modal-content button:hover {
            background-color: #0056b3;
        }
    </style>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            console.log('Personnel Management: Initializing employee calendar for user_id: <?php echo esc_js(absint($user->ID)); ?>, employee_id: <?php echo esc_js(absint($employee->id)); ?>');
            var calendarEl = document.getElementById('pm_employee_calendar');
            var messageArea = document.getElementById('pm_message_area');
            var spinner = document.getElementById('pm_spinner');
            var selectedRange = null;

            // Gestion de la fenêtre de confirmation
            const urlParams = new URLSearchParams(window.location.search);
            const status = urlParams.get('pm_status');
            const message = urlParams.get('pm_message');
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
                    document.getElementById('pm_available_btn').style.display = 'inline-block';
                    document.getElementById('pm_unavailable_btn').style.display = 'inline-block';
                    document.getElementById('pm_delete_btn').style.display = 'none';
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

 function showMessage(message, isError = false)
 {
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
                var buttons = document.querySelectorAll('ElementsByClassName('pm-availability-btn'));
                buttons.forEach(btn => btn.disabled = show);
            }

            function sendAvailabilityRequest(action, status = null) {
                if (!selectedRange) {
                    showMessage('Veuillez sélectionner une plage de dates.', true);
                    return;
                }
                toggleSpinnerAndButtons(show));
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

    global $wpdb;
    $employees = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}pm_employees");

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
                <label for="pm_date_filter">Mois/Année :</label>
                <input type="month" id="pm_date_filter" value="<?php echo esc_attr(date('Y-m')); ?>">
            </div>
        </div>
        <div id="pm_calendar_wrapper">
            <div id="pm_spinner" class="pm-spinner" style="display: none;"></div>
            <div id="pm_admin_calendar"></div>
        </div>
    </div>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            console.log('Personnel Management: Initializing admin calendar');
            var calendarEl = document.getElementById('pm_admin_calendar');
            var employeeFilter = document.getElementById('pm_employee_filter');
            var dateFilter = document.getElementById('pm_date_filter');
            var messageArea = document.getElementById('pm_message_area');
            var spinner = document.getElementById('pm_spinner');

            if (!calendarEl) {
                console.error('Personnel Management: Admin calendar element not found');
                showMessage('Erreur : Impossible de charger le calendrier.', true);
                return;
            }

            function showMessage(message, isError = false) {
                console.log('Personnel Management: Showing message:', message, 'isError:', isError);
                messageArea.style.display = 'block';
                messageArea.className = 'pm-message ' + (isError ? 'pm-message-error' : 'pm-message-success');
                messageArea.textContent = message;
                setTimeout(() => {
                    messageArea.style.display = 'none';
                }, 3000);
            }

            function toggleSpinner(show) {
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

            var reservedDialog = document.createElement('div');
            reservedDialog.id = 'pm_reserved_dialog';
            reservedDialog.innerHTML = `
                <h3>Modifier la réservation</h3>
                <p><strong>Employé :</strong> <span id="pm_reserved_employee"></span></p>
                <p><strong>Date :</strong> <span id="pm_reserved_date"></span></p>
                <label for="pm_reserved_status">Statut :</label>
                <select id="pm_reserved_status">
                    <option value="réservé">Demande de prestation</option>
                    <option value="disponible">Disponible</option>
                    <option value="non-disponible">Non disponible</option>
                </select>
                <div id="pm_reserved_time_fields" style="display: none;">
                    <label for="pm_reserved_start_time">Heure de début :</label>
                    <select id="pm_reserved_start_time">${generateTimeOptions()}</select>
                    <label for="pm_reserved_end_time">Heure de fin :</label>
                    <select id="pm_reserved_end_time">${generateTimeOptions()}</select>
                </div>
                <label for="pm_reserved_comment">Commentaire :</label>
                <textarea id="pm_reserved_comment" maxlength="1000"></textarea>
                <div class="pm-button-group">
                    <button id="pm_reserved_save">Enregistrer</button>
                    <button id="pm_reserved_cancel">Annuler</button>
                </div>
            `;
            document.body.appendChild(reservedDialog);

            var dialog = document.createElement('div');
            dialog.id = 'pm_status_dialog';
            dialog.innerHTML = `
                <h3>Modifier le statut</h3>
                <select id="pm_status_input">
                    <option value="disponible">Disponible</option>
                    <option value="non-disponible">Non disponible</option>
                    <option value="demande-de-prestation">Demande de prestation</option>
                </select>
                <div id="pm_time_fields" style="display: none;">
                    <p><strong>Heure de début :</strong> <select id="pm_start_time" required>${generateTimeOptions()}</select></p>
                    <p><strong>Heure de fin :</strong> <select id="pm_end_time" required>${generateTimeOptions()}</select></p>
                    <p><strong>Détails :</strong></p>
                    <textarea id="pm_details" placeholder="Détails (optionnel)" maxlength="1000"></textarea>
                </div>
                <div id="pm_status_error" class="pm-message pm-message-error" style="display: none;"></div>
                <button id="pm_status_save">Enregistrer</button>
                <button id="pm_status_cancel">Annuler</button>
            `;
            document.body.appendChild(dialog);

            document.addEventListener('click', function(event) {
                var reservedDialog = document.getElementById('pm_reserved_dialog');
                var statusDialog = document.getElementById('pm_status_dialog');
                
                if (reservedDialog && reservedDialog.style.display === 'block' && !reservedDialog.contains(event.target)) {
                    console.log('Personnel Management: Closing reserved dialog due to outside click');
                    reservedDialog.style.display = 'none';
                }
                
                if (statusDialog && statusDialog.style.display === 'block' && !statusDialog.contains(event.target)) {
                    console.log('Personnel Management: Closing status dialog due to outside click');
                    statusDialog.style.display = 'none';
                }
            });

            var reservedStatusInput = document.getElementById('pm_reserved_status');
            if (reservedStatusInput) {
                reservedStatusInput.addEventListener('change', function() {
                    var timeFields = document.getElementById('pm_reserved_time_fields');
                    var startTime = document.getElementById('pm_reserved_start_time');
                    var endTime = document.getElementById('pm_reserved_end_time');
                    if (this.value === 'réservé') {
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
                    if (this.value === 'demande-de-prestation') {
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
                    }
                });
            }

            // Function to fetch a fresh nonce
            function getFreshNonce(action) {
                return new Promise((resolve, reject) => {
                    jQuery.get('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {
                        action: 'pm_get_nonce',
                        nonce_action: action
                    }, function(response) {
                        if (response.success && response.data.nonce) {
                            resolve(response.data.nonce);
                        } else {
                            reject('Failed to fetch nonce');
                        }
                    }).fail(function() {
                        reject('AJAX error while fetching nonce');
                    });
                });
            }

            var calendar = new FullCalendar.Calendar(calendarEl, {
                initialView: 'dayGridMonth',
                locale: 'fr',
                selectable: true,
                selectMirror: 'true',
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
                        title += ' (' + arg.event.extendedProps.start_time + ' - ' + arg.event.extendedProps.end_time + ')';
                    }
                    return {
                        html: `<span>${icon} ${title}</span>`
                    };
                },
                eventClick: function(info) {
                    console.log('Personnel Management: Admin event clicked:', info.event.toJSON());
                    info.jsEvent.stopPropagation();
                    var hasPermission = <?php echo current_user_can('manage_options') ? 'true' : 'false'; ?>;
                    if (!hasPermission) {
                        showMessage('Erreur : accès non autorisé.', true);
                        return;
                    }
                    var event = info.event;
                    if (event.extendedProps.status === 'réservé' || event.extendedProps.status === 'réservé - accepté' || event.extendedProps.status === 'réservé - en attente' || event.extendedProps.status === 'en attente') {
                        console.log('Personnel Management: Opening reserved dialog for event:', event.toJSON());
                        try {
                            var employeeEl = document.getElementById('pm_reserved_employee');
                            var dateEl = document.getElementById('pm_reserved_date');
                            var statusEl = document.getElementById('pm_reserved_status');
                            var startTimeEl = document.getElementById('pm_reserved_start_time');
                            var endTimeEl = document.getElementById('pm_reserved_end_time');
                            var commentEl = document.getElementById('pm_reserved_comment');
                            var timeFields = document.getElementById('pm_reserved_time_fields');
                            var saveBtn = document.getElementById('pm_reserved_save');
                            var cancelBtn = document.getElementById('pm_reserved_cancel');

                            if (!employeeEl || !dateEl || !statusEl || !startTimeEl || !endTimeEl || !commentEl || !timeFields || !saveBtn || !cancelBtn) {
                                console.error('Personnel Management: Error: One or more reserved dialog elements not found');
                                showMessage('Erreur : impossible de charger le formulaire.', true);
                                return;
                            }

                            employeeEl.textContent = event.title || 'Non défini';
                            dateEl.textContent = event.startStr.split('T')[0] || 'Non défini';
                            statusEl.value = event.extendedProps.status === 'réservé - en attente' || event.extendedProps.status === 'réservé' || event.extendedProps.status === 'en attente' ? 'réservé' : 
                                event.extendedProps.status === 'réservé - accepté' ? 'réservé' : 
                                event.extendedProps.status || 'réservé';
                            startTimeEl.value = event.extendedProps.start_time || '';
                            endTimeEl.value = event.extendedProps.end_time || '';
                            commentEl.value = event.extendedProps.comment || '';
                            timeFields.style.display = (event.extendedProps.start_time || event.extendedProps.end_time || statusEl.value === 'réservé') ? 'block' : 'none';
                            startTimeEl.required = timeFields.style.display === 'block';
                            endTimeEl.required = timeFields.style.display === 'block';
                            reservedDialog.style.display = 'block';

                            saveBtn.onclick = async function() {
                                var selectedStatus = statusEl.value;
                                console.log('Personnel Management: Selected status:', selectedStatus);
                                var dbStatus = selectedStatus;
                                var startTime = selectedStatus === 'réservé' ? startTimeEl.value : '';
                                var endTime = selectedStatus === 'réservé' ? endTimeEl.value : '';
                                var newComment = commentEl.value;

                                if (!selectedStatus || !['réservé', 'disponible', 'non-disponible'].includes(selectedStatus)) {
                                    console.error('Personnel Management: Erreur : Statut non valide.');
                                    showMessage('Erreur : Statut non valide.', true);
                                    return;
                                }

                                if (selectedStatus === 'réservé' && (!startTime || !endTime)) {
                                    showMessage('Veuillez sélectionner une heure de début et de fin.', true);
                                    return;
                                }
                                if (startTime && endTime && startTime >= endTime) {
                                    showMessage('L’heure de début doit être antérieure à l’heure de fin.', true);
                                    return;
                                }
                                toggleSpinner(true);
                                try {
                                    var nonce = await getFreshNonce('pm_availability');
                                    console.log('Personnel Management: Sending AJAX request with status:', dbStatus);
                                    jQuery.post('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {
                                        action: 'pm_update_availability_status',
                                        employee_id: event.extendedProps.employeeId,
                                        date: event.startStr.split('T')[0],
                                        status: dbStatus,
                                        start_time: startTime,
                                        end_time: endTime,
                                        comment: newComment,
                                        nonce: nonce
                                    }, function(response) {
                                        toggleSpinner(false);
                                        if (response.success) {
                                            calendar.refetchEvents();
                                            showMessage('Réservations mises à jour.');
                                            reservedDialog.style.display = 'none';
                                            
                                            jQuery.post('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {
                                                action: 'pm_notify_employee',
                                                employee_id: event.extendedProps.employeeId,
                                                date: event.startStr.split('T')[0],
                                                status: selectedStatus,
                                                start_time: startTime,
                                                end_time: endTime,
                                                comment: newComment,
                                                nonce: '<?php echo esc_js(wp_create_nonce('pm_notify')); ?>'
                                            }, function(notifyResponse) {
                                                if (notifyResponse.success) {
                                                    console.log('Personnel Management: Notification envoyée avec succès');
                                                } else {
                                                    console.error('Personnel Management: Échec de l\'envoi de la notification:', notifyResponse.data);
                                                    showMessage('Erreur lors de l\'envoi de la notification.', true);
                                                }
                                            }).fail(function(jqXHR, textStatus, errorThrown) {
                                                console.error('Personnel Management AJAX Error:', textStatus, errorThrown);
                                                showMessage('Erreur de connexion au serveur lors de l’envoi de la notification.', true);
                                            });
                                        } else {
                                            console.error('Personnel Management: Échec de la mise à jour:', response.data);
                                            showMessage('Erreur : ' + (response.data || 'Erreur inconnue'), true);
                                        }
                                    }).fail(function(jqXHR, textStatus, errorThrown) {
                                        console.error('Personnel Management AJAX Error:', textStatus, errorThrown);
                                        toggleSpinner(false);
                                        showMessage('Erreur de connexion au serveur.', true);
                                    });
                                } catch (error) {
                                    console.error('Personnel Management: Error fetching nonce:', error);
                                    toggleSpinner(false);
                                    showMessage('Erreur : Impossible d\'obtenir un nonce valide.', true);
                                }
                            };

                            cancelBtn.onclick = function() {
                                reservedDialog.style.display = 'none';
                            };
                        } catch (error) {
                            console.error('Personnel Management: Erreur lors de l\'ouverture du dialogue réservé:', error);
                            showMessage('Erreur : Impossible d\'afficher le formulaire.', true);
                        }
                    } else {
                        console.log('Personnel Management: Opening status dialog pour l’événement:', event.toJSON());
                        try {
                            var statusInput = document.getElementById('pm_status_input');
                            var startTimeInput = document.getElementById('pm_start_time');
                            var endTimeInput = document.getElementById('pm_end_time');
                            var commentInput = document.getElementById('pm_details');
                            var saveBtn = document.getElementById('pm_status_save');
                            var cancelBtn = document.getElementById('pm_status_cancel');

                            if (!statusInput || !startTimeInput || !endTimeInput || !commentInput || !saveBtn || !cancelBtn) {
                                console.error('Personnel Management: Impossible de trouver un ou plusieurs éléments du dialogue de statut');
                                showMessage('Erreur : Impossible d’afficher le formulaire.', true);
                                return;
                            }

                            statusInput.value = event.extendedProps.status === 'réservé' || event.extendedProps.status === 'réservé - accepté' || event.extendedProps.status === 'réservé - en attente' || event.extendedProps.status === 'en attente' ? 'demande-de-prestation' : 
                                event.extendedProps.status || 'disponible';
                            startTimeInput.value = event.extendedProps.start_time || '';
                            endTimeInput.value = event.extendedProps.end_time || '';
                            commentInput.value = event.extendedProps.comment || '';
                            document.getElementById('pm_time_fields').style.display = (event.extendedProps.start_time || event.extendedProps.end_time || statusInput.value === 'demande-de-prestation') ? 'block' : 'none';
                            startTimeInput.required = document.getElementById('pm_time_fields').style.display === 'block';
                            endTimeInput.required = document.getElementById('pm_time_fields').style.display === 'block';
                            dialog.style.display = 'block';

                            saveBtn.onclick = async function() {
                                var selectedStatus = statusInput.value;
                                var dbStatus = selectedStatus === 'demande-de-prestation' ? 'réservé' : selectedStatus;
                                var date = event.startStr.split('T')[0];
                                var startTime = selectedStatus === 'demande-de-prestation' ? startTimeInput.value : '';
                                var endTime = selectedStatus === 'demande-de-prestation' ? endTimeInput.value : '';
                                var newDetails = commentInput.value;
                                var employeeId = event.extendedProps.employeeId;

                                if (!selectedStatus || !['disponible', 'non-disponible', 'demande-de-prestation'].includes(selectedStatus)) {
                                    console.error('Personnel Management: Statut non valide:', selectedStatus);
                                    showMessage('Erreur : Statut non valide.', true);
                                    return;
                                }

                                if (!employeeId) {
                                    console.error('Personnel Management: Employé non spécifié');
                                    showMessage('Erreur : Employé non spécifié.', true);
                                    return;
                                }

                                if (!event.startStr) {
                                    console.error('Personnel Management: Date de début manquante');
                                    showMessage('Erreur : Date de début manquante.', true);
                                    return;
                                }

                                if (selectedStatus === 'demande-de-prestation' && (!startTime || !endTime)) {
                                    showMessage('Veuillez sélectionner une heure de début et de fin.', true);
                                    return;
                                }
                                if (startTime && endTime && startTime >= endTime) {
                                    showMessage('L’heure de début doit être antérieure à l’heure de fin.', true);
                                    return;
                                }
                                toggleSpinner(true);
                                try {
                                    var nonce = await getFreshNonce('pm_availability');
                                    console.log('Personnel Management: Envoi de la requête AJAX pour pm_status_dialog:', {
                                        action: 'pm_update_availability_status',
                                        employee_id: employeeId,
                                        date: event.startStr.split('T')[0],
                                        status: dbStatus,
                                        start_time: startTime,
                                        end_time: endTime,
                                        comment: newDetails,
                                        nonce: nonce
                                    });
                                    jQuery.post('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {
                                        action: 'pm_update_availability_status',
                                        employee_id: employeeId,
                                        date: event.startStr.split('T')[0],
                                        status: dbStatus,
                                        start_time: startTime,
                                        end_time: endTime,
                                        comment: newDetails,
                                        nonce: nonce
                                    }, function(response) {
                                        toggleSpinner(false);
                                        if (response.success) {
                                            calendar.refetchEvents();
                                            showMessage('Statut et détails mis à jour.');
                                            dialog.style.display = 'none';
                                            if (selectedStatus === 'demande-de-prestation') {
                                                jQuery.post('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {
                                                    action: 'pm_notify_guide',
                                                    employee_id: employeeId,
                                                    date: event.startStr.split('T')[0],
                                                    status: 'en attente',
                                                    start_time: startTime,
                                                    end_time: endTime,
                                                    comment: newDetails,
                                                    nonce: '<?php echo esc_js(wp_create_nonce('pm_notify')); ?>'
                                                }, function(notifyResponse) {
                                                    if (notifyResponse.success) {
                                                        console.log('Personnel Management: Notification envoyée avec succès');
                                                    } else {
                                                        console.error('Personnel Management: Échec de l\'envoi de la notification:', notifyResponse.data);
                                                        showMessage('Erreur lors de l\'envoi de la notification.', true);
                                                    }
                                                }).fail(function(jqXHR, textStatus, errorThrown) {
                                                    console.error('Personnel Management AJAX Error:', textStatus, errorThrown);
                                                    showMessage('Erreur de connexion au serveur lors de l’envoi de la notification.', true);
                                                });
                                            }
                                        } else {
                                            console.error('Personnel Management: Échec de la mise à jour:', response.data);
                                            showMessage('Erreur : ' + (response.data || 'Erreur inconnue'), true);
                                        }
                                    }).fail(function(jqXHR, textStatus, errorThrown) {
                                        console.error('Personnel Management AJAX Error:', textStatus, errorThrown);
                                        toggleSpinner(false);
                                        showMessage('Erreur de connexion au serveur.', true);
                                    });
                                } catch (error) {
                                    console.error('Personnel Management: Error fetching nonce:', error);
                                    toggleSpinner(false);
                                    showMessage('Erreur : Impossible d\'obtenir un nonce valide.', true);
                                }
                            };

                            cancelBtn.onclick = function() {
                                dialog.style.display = 'none';
                            };
                        } catch (error) {
                            console.error('Personnel Management: Erreur lors de l\'ouverture du dialogue de statut:', error);
                            showMessage('Erreur : Impossible d\'afficher le formulaire.', true);
                        }
                    }
                },
                select: function(info) {
                    console.log('Personnel Management: Plage sélectionnée pour l’admin:', info);
                    var hasPermission = <?php echo current_user_can('manage_options') ? 'true' : 'false'; ?>;
                    if (!hasPermission) {
                        showMessage('Erreur : accès non autorisé.', true);
                        calendar.unselect();
                        return;
                    }
                    var employeeId = employeeFilter.value !== 'all' ? employeeFilter.value : null;
                    if (!employeeId) {
                        console.error('Personnel Management: Aucun employé sélectionné');
                        showMessage('Veuillez sélectionner un employé.', true);
                        calendar.unselect();
                        return;
                    }
                    var endDate = new Date(info.endStr.split('T')[0]);
                    endDate.setDate(endDate.getDate() - 1);
                    var adjustedEndDate = endDate.toISOString().split('T')[0];
                    var statusInput = document.getElementById('pm_status_input');
                    var startTimeInput = document.getElementById('pm_start_time');
                    var endTimeInput = document.getElementById('pm_end_time');
                    var detailsInput = document.getElementById('pm_details');
                    if (statusInput && startTimeInput && endTimeInput && detailsInput) {
                        statusInput.value = 'disponible';
                        startTimeInput.value = '';
                        endTimeInput.value = '';
                        detailsInput.value = '';
                        document.getElementById('pm_time_fields').style.display = 'none';
                        startTimeInput.required = false;
                        endTimeInput.required = false;
                        dialog.style.display = 'block';
                    } else {
                        console.error('Personnel Management: Éléments du dialogue de statut non trouvés');
                        showMessage('Erreur : Impossible d\'afficher le formulaire de modification.', true);
                        return;
                    }

                    document.getElementById('pm_status_save').onclick = async function() {
                        var selectedStatus = statusInput.value;
                        var dbStatus = selectedStatus === 'demande-de-prestation' ? 'réservé' : selectedStatus;
                        var newDetails = detailsInput.value;
                        var startTime = selectedStatus === 'demande-de-prestation' ? startTimeInput.value : '';
                        var endTime = selectedStatus === 'demande-de-prestation' ? endTimeInput.value : '';
                        if (!['disponible', 'non-disponible', 'demande-de-prestation'].includes(selectedStatus)) {
                            console.error('Personnel Management: Statut non valide:', selectedStatus);
                            showMessage('Erreur : Statut non valide.', true);
                            return;
                        }
                        if (selectedStatus === 'demande-de-prestation' && (!startTime || !endTime)) {
                            showMessage('Veuillez sélectionner une heure de début et de fin.', true);
                            return;
                        }
                        if (startTime && endTime && startTime >= endTime) {
                            showMessage('L’heure de début doit être antérieure à l’heure de fin.', true);
                            return;
                        }
                        toggleSpinner(true);
                        try {
                            var nonce = await getFreshNonce('pm_availability');
                            console.log('Personnel Management: Envoi de la requête AJAX pour la sélection de plage:', {
                                action: 'pm_update_availability_status',
                                employee_id: employeeId,
                                start_date: info.startStr.split('T')[0],
                                end_date: adjustedEndDate,
                                status: dbStatus,
                                start_time: startTime,
                                end_time: endTime,
                                comment: newDetails,
                                nonce: nonce
                            });
                            jQuery.post('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {
                                action: 'pm_update_availability_status',
                                employee_id: employeeId,
                                start_date: info.startStr.split('T')[0],
                                end_date: adjustedEndDate,
                                status: dbStatus,
                                start_time: startTime,
                                end_time: endTime,
                                comment: newDetails,
                                nonce: nonce
                            }, function(response) {
                                toggleSpinner(false);
                                if (response.success) {
                                    calendar.refetchEvents();
                                    showMessage('Statut et détails mis à jour.');
                                    dialog.style.display = 'none';
                                    calendar.unselect();
                                    if (selectedStatus === 'demande-de-prestation') {
                                        jQuery.post('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {
                                            action: 'pm_notify_guide',
                                            employee_id: employeeId,
                                            date: info.startStr.split('T')[0],
                                            status: 'en attente',
                                            start_time: startTime,
                                            end_time: endTime,
                                            comment: newDetails,
                                            nonce: '<?php echo esc_js(wp_create_nonce('pm_notify')); ?>'
                                        }, function(notifyResponse) {
                                            if (notifyResponse.success) {
                                                console.log('Personnel Management: Notification envoyée avec succès');
                                            } else {
                                                console.error('Personnel Management: Échec de l\'envoi de la notification:', notifyResponse.data);
                                                showMessage('Erreur lors de l\'envoi de la notification.', true);
                                            }
                                        }).fail(function(jqXHR, textStatus, errorThrown) {
                                            console.error('Personnel Management AJAX Error:', textStatus, errorThrown);
                                            showMessage('Erreur de connexion au serveur lors de l’envoi de la notification.', true);
                                        });
                                    }
                                } else {
                                    console.error('Personnel Management: Échec de la mise à jour:', response.data);
                                    showMessage('Erreur : ' + (response.data || 'Erreur inconnue'), true);
                                }
                            }).fail(function(jqXHR, textStatus, errorThrown) {
                                console.error('Personnel Management AJAX Error:', textStatus, errorThrown);
                                toggleSpinner(false);
                                showMessage('Erreur de connexion au serveur.', true);
                            });
                        } catch (error) {
                            console.error('Personnel Management: Error fetching nonce:', error);
                            toggleSpinner(false);
                            showMessage('Erreur : Impossible d\'obtenir un nonce valide.', true);
                        }
                    };

                    document.getElementById('pm_status_cancel').onclick = function() {
                        dialog.style.display = 'none';
                        calendar.unselect();
                    };
                },
                events: function(fetchInfo, successCallback, failureCallback) {
                    var employeeId = employeeFilter.value;
                    var startDate = fetchInfo.startStr.split('T')[0];
                    var endDate = fetchInfo.endStr.split('T')[0];
                    console.log('Personnel Management: Récupération des événements pour', { employeeId, startDate, endDate });
                    toggleSpinner(true);
                    jQuery.get('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {
                        action: 'pm_get_admin_availability',
                        employee_id: employeeId,
                        start_date: startDate,
                        end_date: endDate,
                        nonce: '<?php echo esc_js(wp_create_nonce('pm_get_admin_availability')); ?>'
                    }, function(response) {
                        toggleSpinner(false);
                        if (response.success) {
                            console.log('Personnel Management: Événements chargés', response.data);
                            successCallback(response.data);
                            var start = new Date(fetchInfo.startStr);
                            var end = new Date(fetchInfo.endStr);
                            var midDate = new Date((start.getTime() + end.getTime()) / 2);
                            var yearMonth = midDate.getFullYear() + '-' + String(midDate.getMonth() + 1).padStart(2, '0');
                            dateFilter.value = yearMonth;
                        } else {
                            console.error('Personnel Management: Erreur de chargement des événements :', response.data);
                            showMessage('Erreur lors du chargement des disponibilités : ' + (response.data || 'Erreur inconnue'), true);
                            failureCallback(new Error(response.data));
                        }
                    }).fail(function(jqXHR, textStatus, errorThrown) {
                        console.error('Personnel Management AJAX Error:', textStatus, errorThrown);
                        toggleSpinner(false);
                        showMessage('Erreur de connexion au serveur.', true);
                        failureCallback(new Error(textStatus));
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
                console.log('Personnel Management: Rendu du calendrier admin');
                calendar.render();
            } catch (error) {
                console.error('Personnel Management: Erreur de rendu du calendrier admin :', error);
                showMessage('Erreur lors de l\'initialisation du calendrier.', true);
            }

            if (employeeFilter) {
                employeeFilter.addEventListener('change', function() {
                    console.log('Personnel Management: Filtre des employés changé en', employeeFilter.value);
                    calendar.refetchEvents();
                });
            }

            if (dateFilter) {
                dateFilter.addEventListener('change', function() {
                    console.log('Personnel Management: Filtre de date changé en', dateFilter.value);
                    if (dateFilter.value) {
                        calendar.gotoDate(dateFilter.value + '-01');
                    }
                });
            }
        });
    </script>
    <?php
    return ob_get_clean();
}
add_shortcode('pm_admin_calendar', 'pm_admin_calendar_shortcode');
add_shortcode('events', 'pm_admin_calendar_shortcode');

function pm_confirmation_page_shortcode() {
    $status = isset($_GET['pm_status']) ? sanitize_text_field($_GET['pm_status']) : '';
    $message = isset($_GET['pm_message']) ? sanitize_text_field(urldecode($_GET['pm_message'])) : '';

    if (!$status || !$message) {
        return '';
    }

    ob_start();
    ?>
    <div class="pm-confirmation">
        <div id="pm_confirmation_modal" class="pm-modal">
            <div class="pm-modal-content">
                <h3>Confirmation</h3>
                <p class="<?php echo esc_attr($status === 'success' ? 'pm-message-success' : 'pm-message-error'); ?>">
                    <?php echo esc_html($message); ?>
                </p>
                <button id="pm_modal_close">Fermer</button>
            </div>
        </div>
    </div>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            var closeButton = document.getElementById('pm_modal_close');
            var modal = document.getElementById('pm_confirmation_modal');
            if (closeButton && modal) {
                closeButton.addEventListener('click', function() {
                    modal.remove();
                    window.history.replaceState({}, document.title, window.location.pathname);
                });
            }
        });
    </script>
    <?php
    return ob_get_clean();
}
add_shortcode('pm_confirmation', 'pm_confirmation_page_shortcode');
?>