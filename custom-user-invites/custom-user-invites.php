<?php
/**
 * Plugin Name: Custom User Invites
 * Description: Allows generating exclusive invite links for new users,
 *              with the option to mark them as VIP, Partnership, or Wholesale.
 * Version: 1.1
 * Author: Bruno Santos
 * Text Domain: custom-user-invites
 */

// Prevent direct file access
if (!defined('ABSPATH')) exit;

// ============================================================
// ACTIVATION: Create custom table on plugin activation
// ============================================================

function custom_user_invites_activate() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'custom_invites';

    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        invite_key varchar(32) NOT NULL,
        user_type varchar(20) NOT NULL,
        expiration datetime NOT NULL,
        used tinyint(1) NOT NULL DEFAULT 0,
        user_id bigint(20) DEFAULT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY invite_key (invite_key)
    ) ENGINE=InnoDB $charset_collate;"; // Force transactional engine

    // Add composite index
    $wpdb->query("CREATE INDEX idx_invite_status ON $table_name (invite_key, used, expiration)");

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}
register_activation_hook(__FILE__, 'custom_user_invites_activate');

// ============================================================
// ADMIN MENU
// ============================================================

function custom_user_invites_menu() {
    add_users_page(
        'Links de Convite',       // Page title
        'Links de Convite',       // Menu name
        'manage_options',         // User permissions
        'custom-user-invites',    // Menu slug
        'custom_user_invites_page' // Display function
    );
}
add_action('admin_menu', 'custom_user_invites_menu');

// ============================================================
// ADMIN PAGE: Display invite links management page
// ============================================================

function custom_user_invites_page() {
    global $wpdb;
    ?>
    <div class="wrap">
        <h1><?php _e('Gerenciar Links de Convite', 'custom-user-invites'); ?></h1>

        <h2><?php _e('Gerar Novo Link de Convite', 'custom-user-invites'); ?></h2>
        <form method="post">
            <table class="form-table">
                <tr>
                    <th><label for="user_type"><?php _e('Tipo de Usuário:', 'custom-user-invites'); ?></label></th>
                    <td>
                        <select name="user_type" id="user_type">
                            <option value="customer"><?php _e('Cliente Padrão', 'custom-user-invites'); ?></option>
                            <option value="vip"><?php _e('VIP', 'custom-user-invites'); ?></option>
                            <option value="parceria"><?php _e('Parceria', 'custom-user-invites'); ?></option>
                            <option value="atacado"><?php _e('Atacado', 'custom-user-invites'); ?></option>
                        </select>
                    </td>
                </tr>
            </table>
            <p>
                <button type="submit" class="button button-primary"><?php _e('Gerar Link de Convite', 'custom-user-invites'); ?></button>
            </p>
        </form>

        <?php
        $table_name = $wpdb->prefix . 'custom_invites';

        // Process invite link generation
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $user_type   = isset($_POST['user_type']) ? sanitize_text_field($_POST['user_type']) : 'customer';
            $invite_link = generate_invite_link($user_type);

            echo '<h3>' . __('Link de Convite Gerado', 'custom-user-invites') . '</h3>';
            echo '<div class="invite-link-container">';
            echo '<input type="text" id="invite_link" value="' . esc_url($invite_link) . '" readonly style="width: 80%; padding: 10px; font-size: 16px;">';
            echo '<button onclick="copyInviteLink()" class="button button-secondary" style="width: 18%;">' . __('Copiar', 'custom-user-invites') . '</button>';
            echo '</div>';
        }
        ?>

        <h2><?php _e('Links de Convite Gerados', 'custom-user-invites'); ?></h2>
        <?php

        // Fetch last 5 generated invites
        $invites = $wpdb->get_results("SELECT * FROM {$table_name} ORDER BY id DESC LIMIT 5");

        if ($invites) {
            echo '<table class="widefat fixed" cellspacing="0">';
            echo '<thead><tr>';
            echo '<th>' . __('Convite', 'custom-user-invites') . '</th>';
            echo '<th>' . __('Tipo', 'custom-user-invites') . '</th>';
            echo '<th>' . __('Expiração', 'custom-user-invites') . '</th>';
            echo '<th>' . __('Status', 'custom-user-invites') . '</th>';
            echo '</tr></thead><tbody>';

            foreach ($invites as $invite) {
                $status = $invite->used ? __('Usado', 'custom-user-invites') : __('Ativo', 'custom-user-invites');
                echo '<tr>';
                echo '<td>' . $invite->invite_key . '</td>';
                echo '<td>' . $invite->user_type . '</td>';
                echo '<td>' . date_i18n(get_option('date_format'), strtotime($invite->expiration)) . '</td>';
                echo '<td>' . $status . '</td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
        } else {
            echo '<p>' . __('Nenhum convite encontrado.', 'custom-user-invites') . '</p>';
        }
        ?>
    </div>

    <script>
        // Copy invite link to clipboard
        function copyInviteLink() {
            var copyText = document.getElementById("invite_link");
            copyText.select();
            copyText.setSelectionRange(0, 99999); // For mobile devices

            document.execCommand("copy");

            // Show success message
            alert("Link copiado: " + copyText.value);
        }
    </script>
    <?php
}

// ============================================================
// GENERATE INVITE LINK with unique key and user role assignment
// ============================================================

function generate_invite_link($user_type) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'custom_invites';

    $invite_key = md5(uniqid(rand(), true));
    $expiration = date('Y-m-d H:i:s', strtotime('+1 days')); // Expires in 1 day

    $wpdb->insert(
        $table_name,
        array(
            'invite_key' => $invite_key,
            'user_type'  => $user_type,
            'expiration' => $expiration
        ),
        array('%s', '%s', '%s')
    );

    return home_url("/wp-login.php?action=register&invite=$invite_key");
}

// ============================================================
// REDIRECT: Validate invite before showing registration form
// ============================================================

function redirect_to_registration_form() {
    if (is_user_logged_in() || !isset($_GET['invite'])) return;

    global $wpdb;
    $table_name = $wpdb->prefix . 'custom_invites';
    $invite_key = sanitize_text_field($_GET['invite']);

    // Strict verification with row locking
    $wpdb->query("START TRANSACTION");

    $invite = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table_name
         WHERE invite_key = %s
         AND used = 0
         AND expiration > NOW()
         FOR UPDATE",
        $invite_key
    ));

    if (!$invite) {
        $wpdb->query("ROLLBACK");
        wp_die(__('Link de convite inválido ou já utilizado.', 'custom-user-invites'));
    }

    $wpdb->query("COMMIT");

    // Force invite parameter on registration URL
    wp_redirect(esc_url_raw(add_query_arg('invite', $invite_key, wp_registration_url())));
    exit;
}
add_action('template_redirect', 'redirect_to_registration_form');

// ============================================================
// REGISTRATION: Associate invite link to new user
// ============================================================

function process_registration_with_invite($user_id) {
    $invite_key = isset($_REQUEST['invite']) ? sanitize_text_field($_REQUEST['invite']) : '';

    if (empty($invite_key)) {
        error_log('Nenhum convite válido fornecido! Apenas convidados podem se cadastrar neste portal.');
        return;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'custom_invites';

    try {
        // Set reduced timeout for operations
        $wpdb->query("SET innodb_lock_wait_timeout = 3");
        $wpdb->query("START TRANSACTION");

        // Final verification before update
        $invite = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name
             WHERE invite_key = %s
             AND used = 0
             AND expiration > NOW()
             FOR UPDATE",
            $invite_key
        ));

        if (!$invite) {
            throw new Exception(__('Convite inválido ou já utilizado.', 'custom-user-invites'));
        }

        // Immediate update
        $updated = $wpdb->update(
            $table_name,
            array('used' => 1, 'user_id' => $user_id),
            array('id' => $invite->id),
            array('%d', '%d'),
            array('%d')
        );

        // Check for error or no rows affected
        if (false === $updated || 0 === $updated) {
            throw new Exception(__('Erro ao atualizar o status do convite.', 'custom-user-invites'));
        }

        $wpdb->query("COMMIT");

        $user = new WP_User($user_id);
        $user->remove_all_caps();

        switch ($invite->user_type) {
            case 'vip':
                $user->add_role('customer');
                update_user_meta($user_id, '_is_vip', true);
                break;

            case 'parceria':
                $user->add_role('cliente_parceria');
                break;

            case 'atacado':
                $user->add_role('cliente_atacado');
                break;

            default:
                $user->add_role('customer');
        }

    } catch (Exception $e) {
        $wpdb->query("ROLLBACK");
        error_log('Erro no processamento do convite: ' . $e->getMessage());

        if ($user_id && get_userdata($user_id)) {
            wp_delete_user($user_id);
        }

        wp_die(
            $e->getMessage(),
            __('Erro no Registro', 'custom-user-invites'),
            array('response' => 400)
        );
    }
}
add_action('user_register', 'process_registration_with_invite', 20);

// ============================================================
// VALIDATION: Validate invite before registration errors hook
// ============================================================

function validate_invite_before_registration($errors, $sanitized_user_login, $user_email) {
    // Check both GET and POST
    $invite_key = isset($_REQUEST['invite']) ? sanitize_text_field($_REQUEST['invite']) : '';

    if (empty($invite_key)) {
        $errors->add('missing_invite', __('<strong>Erro</strong>: Link de convite inválido.', 'custom-user-invites'));
        return $errors;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'custom_invites';

    try {
        $wpdb->query("START TRANSACTION");

        $invite = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name
             WHERE invite_key = %s
             AND used = 0
             AND expiration > NOW()
             FOR UPDATE", // Exclusive lock
            $invite_key
        ));

        if (!$invite) {
            $errors->add('invalid_invite', __('<strong>Erro</strong>: Convite já utilizado ou expirado.', 'custom-user-invites'));
        }

        $wpdb->query("COMMIT");

    } catch (Exception $e) {
        $wpdb->query("ROLLBACK");
        $errors->add('invite_error', __('<strong>Erro</strong>: Falha ao validar convite.', 'custom-user-invites'));
    }

    return $errors;
}
add_filter('registration_errors', 'validate_invite_before_registration', 10, 3);

// ============================================================
// USER PROFILE: Display VIP field on user profile
// ============================================================

function display_vip_user_field($user) {
    ?>
    <h3><?php _e('Informações de Cliente VIP', 'custom-user-invites'); ?></h3>
    <table class="form-table">
        <tr>
            <th><label for="is_vip"><?php _e('Cliente VIP', 'custom-user-invites'); ?></label></th>
            <td>
                <input type="checkbox" name="is_vip" id="is_vip" value="1"
                    <?php checked(get_user_meta($user->ID, '_is_vip', true), 1); ?> disabled>
                <span class="description"><?php _e('Este usuário foi registrado como VIP.', 'custom-user-invites'); ?></span>
            </td>
        </tr>
    </table>
    <?php
}
add_action('show_user_profile', 'display_vip_user_field');
add_action('edit_user_profile', 'display_vip_user_field');

// ============================================================
// QUERY TIMEOUT: Add global timeout for SELECT queries (debug only)
// ============================================================

add_filter('query', function ($query) {
    if (defined('WP_DEBUG') && WP_DEBUG && strpos($query, 'SELECT') === 0) {
        return $query . ' /*+ MAX_EXECUTION_TIME(1000) */';
    }
    return $query;
});

// ============================================================
// REGISTRATION FORM: Inject hidden invite field
// ============================================================

function add_invite_key_to_registration_form() {
    if (!empty($_GET['invite'])) {
        echo '<input type="hidden" name="invite" value="' . esc_attr($_GET['invite']) . '">';
    }
}
add_action('register_form', 'add_invite_key_to_registration_form');

// ============================================================
// REDIRECT: Force unauthenticated users to login
// ============================================================

function redirecionar_nao_autenticados_para_login() {
    if (!is_user_logged_in()) {
        wp_redirect(wp_login_url()); // Redirect to login page
        exit;
    }
}
add_action('template_redirect', 'redirecionar_nao_autenticados_para_login');
